<?php
/**
 * Plugin Name:       OxyDDT – Italian Delivery Notes (DDT) for WooCommerce
 * Plugin URI:        https://oxywp.com/plugins/oxyddt-for-woocommerce/
 * Description:       Issue Italian delivery notes (documenti di trasporto) from WooCommerce orders: partial fulfilment, protected numbering, immutable issued documents and PDF.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.2
 * WC tested up to:   11.0
 * Author:            Oxysoft
 * Author URI:        https://oxysoft.it/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       oxyddt-for-woocommerce
 * Domain Path:       /languages
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT;

use Oxysoft\OxyDDT\Infrastructure\Activator;
use Oxysoft\OxyDDT\Infrastructure\Deactivator;
use Oxysoft\OxyDDT\Infrastructure\WooCommerce;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;
const MIN_PHP     = '8.1';
const MIN_WP      = '6.5';

/**
 * The oldest WooCommerce whose order CRUD and HPOS behaviour this plugin relies on.
 */
const MIN_WC = '8.2';

/**
 * Version of the extension contract, not of the plugin.
 *
 * An add-on — OxyDDT PRO first of all — needs to say "I require the OxyDDT API"
 * without pinning itself to a release. What this number covers is everything an
 * add-on is invited to rely on: the `oxyddt_*` actions and filters, the
 * interfaces under `Domain`, `Numbering` and `Infrastructure`, the service
 * container's identifiers, and the shape of the values passed to and returned
 * from all of them.
 *
 * Raise the major when something in that list is removed or changes meaning, so
 * that an add-on built against the old contract refuses to load rather than
 * failing halfway through issuing a document. Raise the minor when something is
 * added and everything already written keeps working.
 */
const API_VERSION = '1.0';

/**
 * Absolute path to the plugin directory, with a trailing slash.
 *
 * @return string
 */
function plugin_dir(): string {
	return plugin_dir_path( __FILE__ );
}

/**
 * Public URL of the plugin directory, with a trailing slash.
 *
 * @return string
 */
function plugin_url(): string {
	return plugin_dir_url( __FILE__ );
}

/**
 * Load the bundled libraries.
 *
 * One of them, dompdf, which turns a delivery note into a PDF without asking
 * anybody's server for anything. It is shipped inside the plugin rather than
 * fetched, because a shop must be able to print a document it has already
 * issued on a day when nothing else works.
 *
 * Missing only when the plugin was assembled by hand from the repository. The
 * PDF engine says so plainly in that case rather than failing halfway through a
 * document.
 *
 * @return void
 */
function load_libraries(): void {
	$autoloader = plugin_dir() . 'vendor/autoload.php';

	if ( is_readable( $autoloader ) ) {
		require_once $autoloader;
	}
}
load_libraries();

/**
 * Minimal PSR-4 autoloader.
 *
 * The plugin's own classes load through this rather than through Composer's, so
 * that the two are interchangeable and a mistake in the bundled autoloader can
 * never take the plugin down with it.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function autoload( string $class_name ): void {
	$prefix = __NAMESPACE__ . '\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$path     = plugin_dir() . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_readable( $path ) ) {
		require_once $path;
	}
}
spl_autoload_register( __NAMESPACE__ . '\\autoload' );

/**
 * Whether PHP and WordPress are new enough.
 *
 * WooCommerce is checked separately: it can be deactivated on a running site at
 * any moment, and that is a different message from an environment that was never
 * suitable.
 *
 * @return bool
 */
function requirements_met(): bool {
	return version_compare( PHP_VERSION, MIN_PHP, '>=' )
		&& version_compare( (string) get_bloginfo( 'version' ), MIN_WP, '>=' );
}

/**
 * Explain, once, why the plugin is not running.
 *
 * Only people who can activate plugins see it: telling a shop assistant that the
 * delivery note plugin failed to start is information they cannot act on.
 *
 * @return void
 */
function requirements_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required PHP version, 2: required WordPress version. */
				__( 'OxyDDT needs PHP %1$s or later and WordPress %2$s or later. It has not been started.', 'oxyddt-for-woocommerce' ),
				MIN_PHP,
				MIN_WP
			)
		)
	);
}

/**
 * Boot the plugin.
 *
 * No load_plugin_textdomain() call: WordPress has loaded translations for
 * directory-hosted plugins by itself since 4.6, and since 6.7 calling it this
 * early is what produces the "loaded too early" notice.
 *
 * @return void
 */
function bootstrap(): void {
	if ( ! requirements_met() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\requirements_notice' );

		return;
	}

	if ( ! WooCommerce::is_usable() ) {
		add_action( 'admin_notices', array( WooCommerce::class, 'missing_notice' ) );

		return;
	}

	( new Plugin() )->boot();
}
// Priority 20: WooCommerce loads on plugins_loaded at the default priority, and
// asking whether it is there has to happen after it has had the chance to be.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap', 20 );

// Declared at file load time, not inside a hook of ours: WooCommerce fires this
// while it boots, which is earlier than anything the plugin does afterwards.
add_action( 'before_woocommerce_init', array( WooCommerce::class, 'declare_compatibility' ) );

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );

// Deactivation removes nothing. A shop whose plugin is switched off for an
// afternoon must find every issued document, number and log entry intact when it
// comes back on — these are accounting records, not preferences.
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );
