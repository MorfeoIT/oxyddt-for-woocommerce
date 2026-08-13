<?php
/**
 * The customer, frozen.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\Address;
use Oxysoft\OxyDDT\Domain\Party;
use PHPUnit\Framework\TestCase;

/**
 * Recipients.
 */
final class PartyTest extends TestCase {

	/**
	 * A private customer with an address is a perfectly good recipient, with no
	 * VAT number and no codice fiscale.
	 *
	 * @return void
	 */
	public function test_a_private_customer_needs_no_tax_number(): void {
		$party = new Party( 'Mario Rossi', new Address( 'Via Torino 5', '10121', 'Torino', 'TO', 'IT' ) );

		$this->assertSame( array(), $party->errors() );
		$this->assertTrue( $party->is_valid() );
	}

	/**
	 * That is where the recipient is gentler than the sender: a shop knows its
	 * own VAT number and must state one, a customer need not.
	 *
	 * @return void
	 */
	public function test_the_recipient_is_gentler_than_the_sender(): void {
		$party = new Party( 'Mario Rossi', new Address( 'Via Torino 5', '10121', 'Torino', 'TO', 'IT' ) );

		$this->assertNotContains( 'tax_id_missing', $party->errors() );
	}

	/**
	 * What a delivery note cannot do without.
	 *
	 * @return void
	 */
	public function test_a_recipient_needs_a_name_and_somewhere_to_go(): void {
		$errors = ( new Party() )->errors();

		$this->assertContains( 'name_missing', $errors );
		$this->assertContains( 'address.street_missing', $errors );
		$this->assertContains( 'address.city_missing', $errors );
	}

	/**
	 * A tax number that is there and wrong is worse than one that is absent: it
	 * will be printed.
	 *
	 * @return void
	 */
	public function test_a_broken_tax_number_is_reported(): void {
		$party = new Party(
			'Bianchi S.p.A.',
			new Address( 'Via Torino 5', '10121', 'Torino', 'TO', 'IT' ),
			'01234567890'
		);

		$this->assertContains( 'vat_invalid', $party->errors() );
	}

	/**
	 * However the checkout stored it, one value is kept.
	 *
	 * @return void
	 */
	public function test_tax_numbers_are_normalised(): void {
		$party = Party::from_array(
			array(
				'name'       => 'Bianchi S.p.A.',
				'vat_number' => 'IT 012 345 678 97',
				'tax_code'   => ' rssmra85t10a562s ',
			)
		);

		$this->assertSame( '01234567897', $party->vat_number );
		$this->assertSame( 'RSSMRA85T10A562S', $party->tax_code );
	}

	/**
	 * A party survives a round trip through storage, which is what the snapshot
	 * columns depend on.
	 *
	 * @return void
	 */
	public function test_it_survives_a_round_trip(): void {
		$party = new Party(
			'Bianchi S.p.A.',
			new Address( 'Via Torino 5', '10121', 'Torino', 'TO', 'IT' ),
			'01234567897',
			'',
			'ordini@example.test',
			'+39 011 1234567'
		);

		$this->assertEquals( $party, Party::from_array( $party->to_array() ) );
	}

	/**
	 * Nobody at all.
	 *
	 * @return void
	 */
	public function test_an_empty_party_is_empty(): void {
		$this->assertTrue( ( new Party() )->is_empty() );
		$this->assertFalse( ( new Party( 'Mario Rossi' ) )->is_empty() );
	}
}
