=== Snapshoter: Free Unlimited Backup and Restore ===
Contributors: ismartinjose
Tags: backup, restore, migration, export, snapshot
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backup and restore your WordPress site as a single .smartin archive. Unlimited local backups, limited only by your host.

== Description ==

Snapshoter exports your WordPress site (database and files) into one `.smartin` file and restores it with automatic URL rewriting. Use it for backups, rollbacks, and migrations.

= Features =

* Full-site `.smartin` backups
* Restore and migrate with URL rewrite
* Database-only or files-only export
* Chunked processing for lower-memory hosts
* Automatic cleanup of old local archives

Limits come from your host (memory, timeouts, disk) and site size, not from a plugin quota.

Local backup and restore on your WordPress server. No purchase required.

Source code: https://github.com/smartinjos/snapshoter-wordpress-backup

== Installation ==

1. Upload to `/wp-content/plugins/snapshoter/` or install via Plugins → Add New
2. Activate Snapshoter
3. Open Snapshoter in the admin menu to export or restore

== Frequently Asked Questions ==

= What is a .smartin file? =

A ZIP-based full-site archive with your database, files, and metadata. You can restore it on the same site or another domain.

= Is Free unlimited? =

Yes. Snapshoter does not impose a size quota. Large sites still need enough disk, memory, and time on the host.

= Can I migrate to a new domain? =

Yes. Import the `.smartin` on the destination site; URLs are updated automatically.

= Does Snapshoter support WordPress Multisite? =

No. Snapshoter only works on single-site WordPress installs. Activation is blocked on Multisite networks.

= What happens when I delete the plugin? =

Plugin settings, cron events, job temp files, and restore working files are removed. Your backup archives under `wp-content/uploads/snapshoter/snapshots/` are kept on purpose (same idea as other backup plugins) so you do not lose archives by uninstalling. To delete those archives too, add `define( 'SNAPSHOTER_DELETE_DATA_ON_UNINSTALL', true );` to `wp-config.php` before deleting the plugin.

= Are backup files private on the server? =

Snapshoter writes Apache/LiteSpeed `.htaccess` and IIS `web.config` rules that deny direct web access to `wp-content/uploads/snapshoter/`. Download archives from the Snapshoter admin UI. On nginx (or hosts that ignore `.htaccess`), add a deny rule for that folder, for example:

`location ^~ /wp-content/uploads/snapshoter/ { deny all; return 403; }`

You can also point storage elsewhere with `define( 'SNAPSHOTER_STORAGE', '/path/outside/webroot/snapshoter' );` in `wp-config.php` before activating.

== Privacy Policy ==

Snapshoter runs on your WordPress site. Backups and restores are stored and processed on your server under `wp-content/uploads/snapshoter/`.

This Free plugin does not send your site content, backups, or personal data to Snapshoter servers. Optional links to snapshoter.app open only if you choose them.

Archive files may contain your full site (database and files), including personal data from your site. Protect download access and delete archives you no longer need. On uninstall, archives are kept unless `SNAPSHOTER_DELETE_DATA_ON_UNINSTALL` is enabled (see FAQ).

== Screenshots ==

1. WordPress backup dashboard: full site, database, or files
2. Stored snapshots list on the server
3. Restore / migrate: upload a .smartin archive

== Changelog ==

= 1.0 =
* Initial release.

== Upgrade Notice ==

= 1.0 =
Initial release.
