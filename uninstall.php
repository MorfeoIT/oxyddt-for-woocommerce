<?php
/**
 * What deleting the plugin does.
 *
 * Nothing, unless the shop asked for it in the settings. Delivery notes are
 * accounting records: a plugin that takes them away because somebody clicked
 * "delete" while testing is a plugin that has destroyed something it was trusted
 * with.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/oxyddt-for-woocommerce.php';

use Oxysoft\OxyDDT\Infrastructure\Migrator;
use Oxysoft\OxyDDT\Security\Capabilities;
use Oxysoft\OxyDDT\Settings\Settings;

/**
 * Remove everything OxyDDT owns from one site.
 *
 * @return void
 */
function oxyddt_uninstall_site(): void {
	$settings = get_option( Settings::OPTION );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		// The capabilities go regardless: they name a plugin that is no longer
		// installed, and leaving them behind puts dead entries in every role
		// editor on the site. Nothing a shop can lose is stored in them.
		Capabilities::remove_all();

		return;
	}

	global $wpdb;

	foreach ( Migrator::tables() as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names cannot be placeholders; these are the plugin's own constants behind the site prefix. Dropping the plugin's own tables is what uninstalling means.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	delete_option( Settings::OPTION );
	delete_option( Migrator::VERSION_OPTION );
	delete_option( 'oxyddt_installed_at' );

	Capabilities::remove_all();
}

if ( is_multisite() ) {
	$oxyddt_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $oxyddt_sites as $oxyddt_site_id ) {
		switch_to_blog( (int) $oxyddt_site_id );
		oxyddt_uninstall_site();
		restore_current_blog();
	}
} else {
	oxyddt_uninstall_site();
}
