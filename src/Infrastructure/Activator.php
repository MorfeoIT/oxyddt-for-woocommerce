<?php
/**
 * What happens the moment the plugin is switched on.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Infrastructure;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Security\Capabilities;
use Oxysoft\OxyDDT\Settings\Settings;

use const Oxysoft\OxyDDT\MIN_PHP;
use const Oxysoft\OxyDDT\MIN_WP;
use const Oxysoft\OxyDDT\PLUGIN_FILE;

/**
 * Prepares a shop to issue delivery notes.
 *
 * Everything here is safe to run twice, because activation happens again after
 * every reactivation and on every new site of a network.
 */
final class Activator {

	/**
	 * The option recording when OxyDDT was first activated on this site.
	 */
	public const INSTALLED_AT_OPTION = 'oxyddt_installed_at';

	/**
	 * Set the site up.
	 *
	 * @param bool $network_wide Whether the plugin was activated for a whole network.
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		self::guard_requirements();

		if ( $network_wide && is_multisite() ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( (array) $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}

			return;
		}

		self::activate_site();
	}

	/**
	 * Set up the current site.
	 *
	 * @return void
	 */
	private static function activate_site(): void {
		( new Migrator() )->migrate();

		Capabilities::ensure_granted();

		// The settings row is created now, with its defaults, so that the rest of
		// the plugin can read settings without every call site handling "not
		// installed yet".
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::sanitize( array() ) );
		}

		if ( get_option( self::INSTALLED_AT_OPTION ) ) {
			return;
		}

		add_option( self::INSTALLED_AT_OPTION, gmdate( 'Y-m-d H:i:s' ), '', false );

		// The first line of the register, written before there is anything to
		// register. A log that starts at the first document cannot tell anybody
		// when the shop began issuing them.
		( new AuditLog( new SystemClock() ) )->record(
			AuditLog::INSTALLED,
			'OxyDDT was activated on this site.'
		);
	}

	/**
	 * Refuse to activate on an environment that cannot run the plugin.
	 *
	 * WooCommerce is not checked here. WordPress 6.5 and later enforce the
	 * "Requires Plugins" header itself, and a shop that deactivates WooCommerce
	 * for an afternoon must not have its delivery notes plugin refuse to come
	 * back afterwards.
	 *
	 * @return void
	 */
	private static function guard_requirements(): void {
		if ( version_compare( PHP_VERSION, MIN_PHP, '>=' )
			&& version_compare( (string) get_bloginfo( 'version' ), MIN_WP, '>=' ) ) {
			return;
		}

		deactivate_plugins( plugin_basename( PLUGIN_FILE ) );

		wp_die(
			esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: required WordPress version, 3: current PHP version, 4: current WordPress version. */
					__( 'OxyDDT needs PHP %1$s or later and WordPress %2$s or later. This site runs PHP %3$s and WordPress %4$s.', 'oxyddt-for-woocommerce' ),
					MIN_PHP,
					MIN_WP,
					PHP_VERSION,
					(string) get_bloginfo( 'version' )
				)
			),
			esc_html__( 'OxyDDT could not be activated', 'oxyddt-for-woocommerce' ),
			array( 'back_link' => true )
		);
	}
}
