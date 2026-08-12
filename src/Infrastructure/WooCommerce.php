<?php
/**
 * What OxyDDT needs from WooCommerce, and what it promises back.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Infrastructure;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

use const Oxysoft\OxyDDT\MIN_WC;
use const Oxysoft\OxyDDT\PLUGIN_FILE;

/**
 * The one place that knows WooCommerce exists at all.
 *
 * Everything else in the plugin talks to orders through WooCommerce's own CRUD.
 * Nothing reads wp_posts or wp_postmeta for order data, and nothing may start
 * to: with HPOS those tables are not where orders live, and a shop that switches
 * storage would silently lose half its delivery notes.
 */
final class WooCommerce {

	/**
	 * Whether WooCommerce is present and new enough.
	 *
	 * @return bool
	 */
	public static function is_usable(): bool {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		return version_compare( self::version(), MIN_WC, '>=' );
	}

	/**
	 * The running WooCommerce version, or 0 when there is none.
	 *
	 * @return string
	 */
	public static function version(): string {
		return defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : '0';
	}

	/**
	 * Whether the shop keeps its orders in the dedicated tables.
	 *
	 * Nothing in the plugin should branch on this. It is here for the diagnostics
	 * screen, so that a support question can be answered without asking the shop
	 * owner to go and look.
	 *
	 * @return bool
	 */
	public static function hpos_enabled(): bool {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Tell WooCommerce which of its features this plugin is built for.
	 *
	 * Without this declaration WooCommerce warns the shop owner that the plugin
	 * is incompatible with HPOS and, on some settings, refuses to let them turn
	 * HPOS on at all.
	 *
	 * @return void
	 */
	public static function declare_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', PLUGIN_FILE, true );
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PLUGIN_FILE, true );
	}

	/**
	 * Say why nothing happened.
	 *
	 * @return void
	 */
	public static function missing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$message = class_exists( 'WooCommerce' )
			? sprintf(
				/* translators: 1: required WooCommerce version, 2: the WooCommerce version running on this site. */
				__( 'OxyDDT needs WooCommerce %1$s or later. This site runs %2$s, so OxyDDT has not been started.', 'oxyddt-for-woocommerce' ),
				MIN_WC,
				self::version()
			)
			: __( 'OxyDDT needs WooCommerce, which is not active. No delivery note can be issued until it is.', 'oxyddt-for-woocommerce' );

		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
}
