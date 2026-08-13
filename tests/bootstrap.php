<?php
/**
 * PHPUnit bootstrap.
 *
 * Serves two kinds of test from one file.
 *
 * The **unit** suite runs without WordPress: the Domain layer — addresses, tax
 * identifiers, the sender, and later the document and its quantities — is plain
 * PHP by design, so it loads through Composer's autoloader alone and runs
 * anywhere, in a fraction of a second.
 *
 * The **integration** suite needs a real WordPress, a real WooCommerce and a
 * real database. It is switched on by WP_PHPUNIT__TESTS_CONFIG, which points at
 * a wp-tests-config.php. Without it this bootstrap does nothing more, so
 * `composer test` keeps working on a machine with no database at all.
 *
 * The switch is deliberately *not* WP_PHPUNIT__DIR. That variable looks like the
 * obvious one and is useless for the purpose: the wp-phpunit package sets it
 * itself, from a Composer autoload file, the moment the autoloader runs.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

$oxyddt_autoload = __DIR__ . '/../vendor/autoload.php';

if ( ! file_exists( $oxyddt_autoload ) ) {
	fwrite( STDERR, "Run 'composer install' before the test suite.\n" );
	exit( 1 );
}

require $oxyddt_autoload;

$oxyddt_config = getenv( 'WP_PHPUNIT__TESTS_CONFIG' );

if ( false === $oxyddt_config || '' === $oxyddt_config ) {
	// No WordPress asked for. The unit suite is all that will run, and it needs
	// nothing else.
	return;
}

if ( ! is_readable( $oxyddt_config ) ) {
	fwrite( STDERR, "WP_PHPUNIT__TESTS_CONFIG does not point at a readable file: {$oxyddt_config}" . PHP_EOL );
	exit( 1 );
}

$oxyddt_wp_tests = (string) getenv( 'WP_PHPUNIT__DIR' );

if ( ! is_readable( $oxyddt_wp_tests . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WP_PHPUNIT__DIR does not point at the WordPress test library: {$oxyddt_wp_tests}\n" );
	exit( 1 );
}

require_once $oxyddt_wp_tests . '/includes/functions.php';

/**
 * Load WooCommerce, then the plugin, before WordPress finishes starting.
 *
 * muplugins_loaded is early enough that both plugins' own `plugins_loaded` hooks
 * still fire, so the object graph is built exactly as it is on a real shop
 * rather than by hand in a test.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$woocommerce = (string) getenv( 'WP_WOOCOMMERCE_DIR' );

		if ( '' !== $woocommerce && is_readable( $woocommerce . '/woocommerce.php' ) ) {
			require_once $woocommerce . '/woocommerce.php';
		}

		require dirname( __DIR__ ) . '/oxyddt-for-woocommerce.php';
	}
);

/**
 * Install what activation would have installed.
 *
 * The test suite installs WordPress from scratch and never runs an activation
 * hook, so anything that happens only on activation has to be asked for here.
 *
 * On `setup_theme`, which is what WooCommerce's own test bootstrap uses, and not
 * on `wp_install`. Two things go wrong on `wp_install`: WooCommerce's tables do
 * not exist yet when `init` queries them, which fills the log with database
 * errors, and its roles do not exist yet either — so the capability grant finds
 * no shop_manager, declines to write its version option so that a real site
 * retries on the next request, and in a test run there is no next request.
 *
 * setup_theme runs after plugins_loaded and before init, in the main process:
 * late enough that WooCommerce is installed, early enough that nothing has
 * asked it a question yet.
 */
tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();

			// Roles live in an option, and WP_Roles read that option before
			// WooCommerce wrote it. Without rebuilding it, get_role( 'shop_manager' )
			// keeps answering with what was true a moment ago.
			$GLOBALS['wp_roles'] = null;
			wp_roles();
		}

		( new Oxysoft\OxyDDT\Infrastructure\Migrator() )->migrate();

		Oxysoft\OxyDDT\Security\Capabilities::ensure_granted();
	}
);

require $oxyddt_wp_tests . '/includes/bootstrap.php';
