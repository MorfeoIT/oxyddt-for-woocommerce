<?php
/**
 * What reaches the option row.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The pure half of the settings.
 *
 * Settings::sanitize() calls nothing and returns what will be stored, which is
 * why it can be examined here rather than through a database.
 */
final class SettingsSanitizeTest extends TestCase {

	/**
	 * An empty input produces the defaults, not an empty row.
	 *
	 * @return void
	 */
	public function test_an_empty_input_produces_the_defaults(): void {
		$clean = Settings::sanitize( array() );

		$this->assertSame( array_keys( Settings::defaults() ), array_keys( $clean ) );
		$this->assertFalse( $clean['delete_data_on_uninstall'] );
		$this->assertIsArray( $clean['company'] );
	}

	/**
	 * A key nobody declared does not get stored. It is how a crafted import would
	 * otherwise plant options of its own.
	 *
	 * @return void
	 */
	public function test_unknown_keys_are_discarded(): void {
		$clean = Settings::sanitize(
			array(
				'company'     => array( 'name' => 'Oxysoft S.r.l.' ),
				'evil_option' => 'whatever',
			)
		);

		$this->assertArrayNotHasKey( 'evil_option', $clean );
		$this->assertSame( 'Oxysoft S.r.l.', $clean['company']['name'] );
	}

	/**
	 * Storing goes through the value object, so what is stored is normalised.
	 *
	 * @return void
	 */
	public function test_the_sender_is_normalised_on_the_way_in(): void {
		$clean = Settings::sanitize(
			array(
				'company' => array(
					'name'       => 'Oxysoft S.r.l.',
					'vat_number' => 'IT 012 345 678 97',
					'address'    => array(
						'street'   => 'Via Roma 1',
						'province' => 'mi',
					),
				),
			)
		);

		$this->assertSame( '01234567897', $clean['company']['vat_number'] );
		$this->assertSame( 'MI', $clean['company']['address']['province'] );
		$this->assertNull( $clean['company']['origin'], 'an origin nobody filled in is not an origin' );
	}

	/**
	 * The one setting that can destroy something is a boolean, and false unless
	 * it is genuinely set.
	 *
	 * @return void
	 */
	public function test_the_destructive_setting_is_a_boolean(): void {
		$this->assertTrue( Settings::sanitize( array( 'delete_data_on_uninstall' => '1' ) )['delete_data_on_uninstall'] );
		$this->assertFalse( Settings::sanitize( array( 'delete_data_on_uninstall' => '0' ) )['delete_data_on_uninstall'] );
		$this->assertFalse( Settings::sanitize( array( 'delete_data_on_uninstall' => '' ) )['delete_data_on_uninstall'] );
	}

	/**
	 * Sanitising twice changes nothing the second time.
	 *
	 * @return void
	 */
	public function test_it_is_idempotent(): void {
		$once = Settings::sanitize(
			array(
				'company'                  => array(
					'name'       => 'Oxysoft S.r.l.',
					'vat_number' => 'IT01234567897',
				),
				'delete_data_on_uninstall' => true,
			)
		);

		$this->assertSame( $once, Settings::sanitize( $once ) );
	}
}
