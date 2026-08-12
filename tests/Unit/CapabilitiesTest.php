<?php
/**
 * The capability catalogue.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Security\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * What the plugin defines, before any of it reaches a role.
 *
 * Whether the grants actually land is an integration question and is asked in
 * the integration suite. These are the facts that hold without WordPress.
 */
final class CapabilitiesTest extends TestCase {

	/**
	 * Seven, all distinct.
	 *
	 * @return void
	 */
	public function test_every_capability_is_distinct(): void {
		$all = Capabilities::all();

		$this->assertCount( 7, $all );
		$this->assertSame( $all, array_values( array_unique( $all ) ) );
	}

	/**
	 * Every one of them carries the plugin's prefix.
	 *
	 * A capability without a prefix is one that collides with another plugin's,
	 * and the collision is silent: two plugins would be granting each other's
	 * permissions.
	 *
	 * @return void
	 */
	public function test_every_capability_is_prefixed(): void {
		foreach ( Capabilities::all() as $capability ) {
			$this->assertStringStartsWith( 'oxyddt_', $capability );
		}
	}

	/**
	 * A role cannot be given something that does not exist.
	 *
	 * @return void
	 */
	public function test_no_role_is_granted_an_unknown_capability(): void {
		foreach ( Capabilities::by_role() as $role => $capabilities ) {
			foreach ( $capabilities as $capability ) {
				$this->assertContains(
					$capability,
					Capabilities::all(),
					"{$role} would be granted {$capability}, which the plugin does not define."
				);
			}
		}
	}

	/**
	 * An administrator holds everything.
	 *
	 * @return void
	 */
	public function test_an_administrator_holds_every_capability(): void {
		$this->assertSame( Capabilities::all(), Capabilities::by_role()['administrator'] );
	}

	/**
	 * A shop manager runs the shipping day and does not reconfigure the shop:
	 * changing the numbering or the sender is wrong on every document printed
	 * afterwards.
	 *
	 * @return void
	 */
	public function test_a_shop_manager_can_ship_but_not_reconfigure(): void {
		$shop_manager = Capabilities::by_role()['shop_manager'];

		$this->assertContains( Capabilities::ISSUE, $shop_manager );
		$this->assertContains( Capabilities::CANCEL, $shop_manager );
		$this->assertNotContains( Capabilities::MANAGE_SETTINGS, $shop_manager );
		$this->assertNotContains( Capabilities::MANAGE_SEQUENCES, $shop_manager );
	}
}
