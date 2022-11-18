<?php /** @noinspection SqlNoDataSourceInspection */

use Aws\S3\S3Client;
use ProgressBar\Manager;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

// Note: Preferably use absolute path without trailing directory separators
$PATH_NEXTCLOUD =  '/var/www/nextcloud/public'; // Path of the main Nextcloud directory
$PATH_DATA = '/var/www/nextcloud/private'; // Path of the new Nextcloud data directory
$PATH_BACKUP = '~/nextcloud-backup'; // Path for backup of MySQL database

echo "Setting everything up started...\n";

// Autoload
require_once(dirname(__FILE__).'/vendor/autoload.php');

// Activate maintenance mode
$process = new Process(['php', $PATH_NEXTCLOUD . DIRECTORY_SEPARATOR . 'occ', 'maintenance:mode', '--on']);
$process->run();
if (!$process->isSuccessful()) {
    throw new ProcessFailedException($process);
}

// First load the nextcloud config
include($PATH_NEXTCLOUD.'/config/config.php');

// Database setup
$mysqli = new mysqli($CONFIG['dbhost'], $CONFIG['dbuser'], $CONFIG['dbpassword'], $CONFIG['dbname']);
if ($CONFIG['mysql.utf8mb4']) {
	$mysqli->set_charset('utf8mb4');
}

// Database backup
$process = Process::fromShellCommandline('mysqldump --host='.$CONFIG['dbhost'].' --user='.$CONFIG['dbuser'].' --password='.$CONFIG['dbpassword'].' '.$CONFIG['dbname'].' > '.$PATH_BACKUP . DIRECTORY_SEPARATOR . 'backup-'.time().'.sql');
$process->run();
if (!$process->isSuccessful()) {
    throw new ProcessFailedException($process);
}

//  Backup config
copy($PATH_NEXTCLOUD.'/config/config.php', $PATH_BACKUP.'/config.php');

// S3 setup
$s3 = new S3Client([
    'version' => 'latest',
    'endpoint' => 'https://'.$CONFIG['objectstore']['arguments']['bucket'].'.'.$CONFIG['objectstore']['arguments']['hostname'],
    'bucket_endpoint' => true,
    'region'  => $CONFIG['objectstore']['arguments']['region'],
    'credentials' => [
        'key' => $CONFIG['objectstore']['arguments']['key'],
        'secret' => $CONFIG['objectstore']['arguments']['secret'],
    ],
]);

// Check that new Nextcloud data directory is empty
if (count(scandir($PATH_DATA)) != 2) {
	echo "The new Nextcloud data directory is not empty\n";
	echo "Aborting script\n";
	die;
}

echo "Setting everything up finished\n";

echo "Copying existing data started...\n";

if ($PATH_DATA != $CONFIG['datadirectory']) {
	foreach (
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($CONFIG['datadirectory'], \RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST) as $item
	) {
		// Recursively copy files from existing datadirectory
		if ($item->isDir()) {
			mkdir($PATH_DATA . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
		} else {
			copy($item, $PATH_DATA . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
		}
	}
}

echo "Copying existing data finished\n";

echo "Creating folder structure started...\n";

if ($result = $mysqli->query("SELECT st.id, fc.fileid, fc.path, fc.storage_mtime FROM oc_filecache as fc, oc_storages as st, oc_mimetypes as mt WHERE st.numeric_id = fc.storage AND st.id LIKE 'object::%' AND fc.mimetype = mt.id AND mt.mimetype = 'httpd/unix-directory'")) {
	$progress_bar = new Manager(0, $result->num_rows);
	while ($row = $result->fetch_assoc()) {
		try {
			// Determine correct path
			if (substr($row['id'], 0, 13) != 'object::user:') {
				$path = $PATH_DATA . DIRECTORY_SEPARATOR . $row['path'];
			} else {
				$path = $PATH_DATA . DIRECTORY_SEPARATOR . substr($row['id'], 13) . DIRECTORY_SEPARATOR . $row['path'];
			}
			// Create folder (if it doesn't already exist)
			if (!file_exists($path)) {
				mkdir($path, 0777, true);
			}
			// Update progress bar
			// TODO: Find out why progress bar is duplicated (probably something todo with exception?) Maybe touching fails?
			touch($path, $row['storage_mtime']);
		} catch (Exception $e) {
			echo "    Failed to create: $row[path] (".$e->getMessage().")\n";
			$flag = false;
		}
		$progress_bar->advance();
	}
  $result->free_result();
}

echo "Creating folder structure finished\n";

echo "Copying files started...\n";

$flag = true;

if ($result = $mysqli->query("SELECT st.id, fc.fileid, fc.path, fc.storage_mtime FROM oc_filecache as fc, oc_storages as st, oc_mimetypes as mt  WHERE st.numeric_id = fc.storage AND st.id LIKE 'object::%' AND fc.mimetype = mt.id AND mt.mimetype != 'httpd/unix-directory'")) {
	$progress_bar = new Manager(0, $result->num_rows);
	while ($row = $result->fetch_assoc()) {
		try {
			// Determine correct path
			if (substr($row['id'], 0, 13) != 'object::user:') {
				$path = $PATH_DATA . DIRECTORY_SEPARATOR . $row['path'];
			} else {
				$path = $PATH_DATA . DIRECTORY_SEPARATOR . substr($row['id'], 13) . DIRECTORY_SEPARATOR . $row['path'];
			}
			// Download file from S3
			$s3->getObject(array(
				'Bucket' => $CONFIG['objectstore']['arguments']['bucket'],
				'Key'    => 'urn:oid:'.$row['fileid'],
				'SaveAs' => $path,
			));
			// Also set modification time
			touch($path, $row['storage_mtime']);
		} catch (Exception $e) {
			echo "    Failed to transfer: $row[fileid] (".$e->getMessage().")\n";
			$flag = false;
		}
		// Update progress bar
		$progress_bar->advance();
	}
	$result->free_result();
}

if (!$flag) {
	echo "Copying files failed\n";
	echo "Aborting script\n";
	die;
}

echo "Copying files finished\n";

echo "Modifying database started...\n";

$mysqli->query("UPDATE oc_storages SET id=CONCAT('home::', SUBSTRING_INDEX(oc_storages.id,':',-1)) WHERE oc_storages.id LIKE 'object::user:%'");
$mysqli->query("UPDATE oc_storages SET id='local::$PATH_DATA/' WHERE oc_storages.id LIKE object::store:%'");

echo "Modifying database finished\n";

echo "Doing final adjustments started...\n";

// Update config file
$process = new Process(['php', $PATH_NEXTCLOUD . DIRECTORY_SEPARATOR . 'occ', 'config:system:set', 'datadirectory', '--value="'.$PATH_DATA.'"']);
$process->run();
if (!$process->isSuccessful()) {
    throw new ProcessFailedException($process);
}
$process = new Process(['php', $PATH_NEXTCLOUD . DIRECTORY_SEPARATOR . 'occ', 'config:system:delete', 'objectstore']);
$process->run();
if (!$process->isSuccessful()) {
    throw new ProcessFailedException($process);
}

// Running cleanup (should not be necessary but cannot hurt)
$process = new Process(['php', $PATH_NEXTCLOUD . DIRECTORY_SEPARATOR . 'occ', 'files:cleanup']);
$process->run();
if (!$process->isSuccessful()) {
    throw new ProcessFailedException($process);
}

// Running scan (should not be necessary but cannot hurt)
$process = new Process(['php', $PATH_NEXTCLOUD . DIRECTORY_SEPARATOR . 'occ', 'files:scan', '--all']);
$process->run();
if (!$process->isSuccessful()) {
    throw new ProcessFailedException($process);
}

// Deactivate maintenance mode
$process = new Process(['php', $PATH_NEXTCLOUD . DIRECTORY_SEPARATOR . 'occ', 'maintenance:mode', '--off']);
$process->run();
if (!$process->isSuccessful()) {
    throw new ProcessFailedException($process);
}

echo "Doing final adjustments finished\n";

echo "You are good to go!\n";
