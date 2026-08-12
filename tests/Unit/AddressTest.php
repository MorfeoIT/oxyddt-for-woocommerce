<?php
/**
 * The address that gets printed.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\Address;
use PHPUnit\Framework\TestCase;

/**
 * Addresses.
 */
final class AddressTest extends TestCase {

	/**
	 * A complete Italian address has nothing wrong with it.
	 *
	 * @return void
	 */
	public function test_a_complete_italian_address_is_valid(): void {
		$address = new Address( 'Via Roma 1', '20121', 'Milano', 'MI', 'IT' );

		$this->assertSame( array(), $address->errors() );
		$this->assertTrue( $address->is_valid() );
		$this->assertFalse( $address->is_empty() );
	}

	/**
	 * What is missing is named, one code per problem.
	 *
	 * @return void
	 */
	public function test_an_italian_address_is_checked_field_by_field(): void {
		$address = new Address( '', '2012', '', 'Milano', 'IT' );

		$this->assertSame(
			array( 'street_missing', 'city_missing', 'postcode_invalid', 'province_invalid' ),
			$address->errors()
		);
	}

	/**
	 * Abroad there is no CAP and no province, and insisting on them would refuse
	 * addresses that are perfectly deliverable.
	 *
	 * @return void
	 */
	public function test_a_foreign_address_is_not_held_to_the_italian_shape(): void {
		$address = new Address( '10 Downing Street', 'SW1A 2AA', 'London', '', 'GB' );

		$this->assertSame( array(), $address->errors() );
		$this->assertFalse( $address->is_italian() );
	}

	/**
	 * A country code that is not one.
	 *
	 * @return void
	 */
	public function test_the_country_has_to_be_two_letters(): void {
		$this->assertContains( 'country_invalid', ( new Address( 'Via Roma 1', '20121', 'Milano', 'MI', 'ITA' ) )->errors() );
		$this->assertContains( 'country_invalid', ( new Address( 'Via Roma 1', '20121', 'Milano', 'MI', '' ) )->errors() );
	}

	/**
	 * The country alone does not make an address written-in.
	 *
	 * @return void
	 */
	public function test_an_address_with_only_a_country_is_empty(): void {
		$this->assertTrue( ( new Address() )->is_empty() );
		$this->assertTrue( ( new Address( '', '', '', '', 'FR' ) )->is_empty() );
	}

	/**
	 * Missing keys are not errors here; errors() is what judges an address.
	 *
	 * @return void
	 */
	public function test_it_is_built_from_whatever_a_form_produced(): void {
		$address = Address::from_array(
			array(
				'street'   => '  Via Roma 1 ',
				'city'     => 'Milano',
				'province' => 'mi',
			)
		);

		$this->assertSame( 'Via Roma 1', $address->street );
		$this->assertSame( 'MI', $address->province );
		$this->assertSame( 'IT', $address->country, 'the country defaults to Italy' );
		$this->assertSame( '', $address->postcode );
	}

	/**
	 * A value object survives a round trip through storage.
	 *
	 * @return void
	 */
	public function test_it_survives_a_round_trip(): void {
		$address = new Address( 'Via Roma 1', '20121', 'Milano', 'MI', 'IT' );

		$this->assertEquals( $address, Address::from_array( $address->to_array() ) );
	}

	/**
	 * How it reads on a document.
	 *
	 * @return void
	 */
	public function test_it_prints_on_one_line(): void {
		$this->assertSame(
			'Via Roma 1 – 20121 Milano (MI)',
			( new Address( 'Via Roma 1', '20121', 'Milano', 'MI', 'IT' ) )->single_line()
		);

		$this->assertSame(
			'10 Downing Street – SW1A 2AA London – GB',
			( new Address( '10 Downing Street', 'SW1A 2AA', 'London', '', 'GB' ) )->single_line()
		);
	}
}
