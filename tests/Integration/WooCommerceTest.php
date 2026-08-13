<?php
/**
 * What the plugin assumes about WooCommerce.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Admin\Screen;
use Oxysoft\OxyDDT\Infrastructure\WooCommerce;
use WP_UnitTestCase;

/**
 * The environment, checked rather than assumed.
 */
final class WooCommerceTest extends WP_UnitTestCase {

	/**
	 * The suite is worth very little without a real WooCommerce, so it says so
	 * out loud rather than passing quietly.
	 *
	 * @return void
	 */
	public function test_woocommerce_is_present_and_usable(): void {
		$this->assertTrue( class_exists( 'WooCommerce' ), 'WooCommerce is not loaded in this environment.' );
		$this->assertNotSame( '0', WooCommerce::version() );
		$this->assertTrue( WooCommerce::is_usable() );
	}

	/**
	 * The plugin booted, which is what puts the hooks in place.
	 *
	 * @return void
	 */
	public function test_the_plugin_booted(): void {
		$this->assertGreaterThan( 0, did_action( 'oxyddt_init' ) );
	}

	/**
	 * Asking whether orders live in the dedicated tables must never fatal,
	 * whichever way the shop is configured.
	 *
	 * @return void
	 */
	public function test_the_order_storage_can_be_asked_about(): void {
		$this->assertIsBool( WooCommerce::hpos_enabled() );
	}

	/**
	 * The environment is the one it was asked to be.
	 *
	 * Without this, the "hpos" leg of the matrix could quietly be testing the
	 * posts table for a second time — a green pipeline proving half of what it
	 * claims, which is worse than a red one.
	 *
	 * @return void
	 */
	public function test_the_order_storage_is_the_one_this_run_asked_for(): void {
		$wanted = (string) getenv( 'WP_WOOCOMMERCE_HPOS' );

		if ( '' === $wanted ) {
			$this->assertFalse(
				WooCommerce::hpos_enabled(),
				'This run did not ask for the high-performance order tables, but they are on.'
			);

			return;
		}

		$this->assertTrue(
			WooCommerce::hpos_enabled(),
			'This run asked for the high-performance order tables and did not get them.'
		);
	}

	/**
	 * The page slug is what every link in the plugin is built from, and what a
	 * later sprint would break by renaming.
	 *
	 * @return void
	 */
	public function test_the_admin_page_slug_is_stable(): void {
		$this->assertSame( 'oxyddt', Screen::SLUG );
	}
}
