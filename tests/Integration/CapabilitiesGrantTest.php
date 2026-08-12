<?php
/**
 * Whether the grants actually reach the roles.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Security\Capabilities;
use WP_UnitTestCase;

/**
 * The capabilities, as a real user holds them.
 *
 * The unit suite proves the catalogue is coherent. This proves it landed, which
 * is a different question and the one that went wrong on a sibling plugin: the
 * capabilities were defined, the map was right, and nobody held them because the
 * grant only ever ran at activation.
 */
final class CapabilitiesGrantTest extends WP_UnitTestCase {

	/**
	 * An administrator can do everything.
	 *
	 * @return void
	 */
	public function test_an_administrator_holds_every_capability(): void {
		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );

		foreach ( Capabilities::all() as $capability ) {
			$this->assertTrue( $user->has_cap( $capability ), $capability );
		}
	}

	/**
	 * A shop manager ships, and does not reconfigure.
	 *
	 * @return void
	 */
	public function test_a_shop_manager_ships_but_does_not_reconfigure(): void {
		if ( null === get_role( 'shop_manager' ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed in this environment.' );
		}

		$user = self::factory()->user->create_and_get( array( 'role' => 'shop_manager' ) );

		$this->assertTrue( $user->has_cap( Capabilities::VIEW ) );
		$this->assertTrue( $user->has_cap( Capabilities::ISSUE ) );
		$this->assertTrue( $user->has_cap( Capabilities::CANCEL ) );
		$this->assertFalse( $user->has_cap( Capabilities::MANAGE_SETTINGS ) );
		$this->assertFalse( $user->has_cap( Capabilities::MANAGE_SEQUENCES ) );
	}

	/**
	 * Everybody else holds nothing at all.
	 *
	 * @return void
	 */
	public function test_a_customer_holds_nothing(): void {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		foreach ( Capabilities::all() as $capability ) {
			$this->assertFalse( $user->has_cap( $capability ), $capability );
		}
	}

	/**
	 * Granting again is uneventful, which is what lets it run on every request
	 * after an upgrade.
	 *
	 * @return void
	 */
	public function test_granting_again_changes_nothing(): void {
		Capabilities::ensure_granted();
		Capabilities::ensure_granted();

		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );

		$this->assertTrue( $user->has_cap( Capabilities::ISSUE ) );
	}

	/**
	 * A capability added in a later sprint reaches the roles that already exist.
	 *
	 * The grant is versioned for exactly this: without the version, a site keeps
	 * whatever it was given the day it installed the plugin.
	 *
	 * @return void
	 */
	public function test_a_site_that_predates_a_capability_is_regranted(): void {
		$administrator = get_role( 'administrator' );
		$this->assertNotNull( $administrator );

		$administrator->remove_cap( Capabilities::CANCEL );
		delete_option( 'oxyddt_capabilities_version' );

		Capabilities::ensure_granted();

		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );

		$this->assertTrue( $user->has_cap( Capabilities::CANCEL ) );
	}
}
