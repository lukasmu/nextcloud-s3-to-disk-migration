# Nextcloud S3 to local storage migration script :cloud: :floppy_disk:

At the time of writing this script using S3 based primary storage in [Nextcloud](https://nextcloud.com/) does not work very well.
Still you might have configured your Nextcloud to use S3 based primary storage as indicated [here](https://docs.nextcloud.com/server/17/admin_manual/configuration_files/primary_storage.html). But what if you want to switch to local primary storage?

Officially it is not supported to change the primary storage in Nextcloud. However, it's very well possible and this unofficial script helps you in doing so.
It will transfer files from **S3** based primary storage to a **local** primary storage.

:warning: This script was written in a rather quick & dirty way. It may fail and lead to data loss. Use at your own risk! :warning:

## Links

Related topics on the Nextcloud community are:
- https://help.nextcloud.com/t/migrating-from-s3-primary-storage-to-local-storage/77639
- https://help.nextcloud.com/t/change-s3-primary-object-storage-to-local-storage/89420/2
- https://help.nextcloud.com/t/switching-primary-storage/11915/2

## How to

Just follow the steps below:

1. Run composer install to obtain some dependencies.
2. Make sure that the Nextcloud cron job is disabled.
3. Make sure that you local data is sufficiently large.
4. Setup the three path variables at the top of the [transfer.php](transfer.php) file.
5. Then just run the [transfer.php](transfer.php) file from the command line. Make sure to run the script as the system user that is normally used for running Nextcloud to avoid permission errors and maybe increase the memory limit to avoid nasty errors (e.g. execute ```sudo -u nextclouduser php -d memory_limit=1024M transfer.php```). That's it! :checkered_flag:

## Cleanup

If everything worked you might want to delete the backup folder and S3 instance manually.
Also you probably want to delete this script after running it.

## Notes

This script was tested successfully on Ubuntu 18.04 using a Maria SQL database and [DigitalOcean Spaces](https://www.digitalocean.com/products/spaces/) as S3 object storage.
The Nextcloud version was 17. Please make sure to review the script carefully before you run it to avoid any issues.
Consider it as a prototype and not a finished tool.

## Contributing

If you find this script useful and you make some modifications please consider making a pull request so that others can benefit from it. This would be highly appreciated!

## License

This script is open-sourced software licensed under the MIT license. Please see [LICENSE](LICENSE.md) for details.
