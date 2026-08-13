<?php
/**
 * The header of the document.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\Address;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Domain\Parties;
use Oxysoft\OxyDDT\Domain\Party;
use PHPUnit\Framework\TestCase;

/**
 * Sender, recipient, destination.
 */
final class PartiesTest extends TestCase {

	/**
	 * A recipient, at their own address.
	 *
	 * @return Party
	 */
	private function recipient(): Party {
		return new Party(
			'Bianchi S.p.A.',
			new Address( 'Via Torino 5', '10121', 'Torino', 'TO', 'IT' )
		);
	}

	/**
	 * With no separate destination, the goods go to the recipient.
	 *
	 * @return void
	 */
	public function test_without_a_destination_the_goods_go_to_the_recipient(): void {
		$parties = new Parties( new Company(), $this->recipient() );

		$this->assertSame( 'Torino', $parties->delivery_address()->city );
		$this->assertFalse( $parties->delivers_elsewhere() );
	}

	/**
	 * The distinction that makes the document useful: invoiced to the registered
	 * office, delivered to the warehouse.
	 *
	 * @return void
	 */
	public function test_the_goods_can_go_somewhere_else(): void {
		$parties = new Parties(
			new Company(),
			$this->recipient(),
			new Address( 'Via Milano 9', '20090', 'Segrate', 'MI', 'IT' )
		);

		$this->assertSame( 'Segrate', $parties->delivery_address()->city );
		$this->assertTrue( $parties->delivers_elsewhere() );
	}

	/**
	 * A destination that repeats the recipient's own address is not a different
	 * place, and the document should not print it twice.
	 *
	 * @return void
	 */
	public function test_a_destination_equal_to_the_recipient_is_not_elsewhere(): void {
		$parties = new Parties(
			new Company(),
			$this->recipient(),
			new Address( 'Via Torino 5', '10121', 'Torino', 'TO', 'IT' )
		);

		$this->assertFalse( $parties->delivers_elsewhere() );
	}

	/**
	 * Problems say which party they belong to, so a screen can put each one
	 * where its user will look.
	 *
	 * @return void
	 */
	public function test_problems_are_attributed_to_a_party(): void {
		$errors = ( new Parties( new Company(), new Party(), new Address( 'Via Milano 9', '2009', 'Segrate', 'M' ) ) )->errors();

		$this->assertContains( 'sender.name_missing', $errors );
		$this->assertContains( 'recipient.name_missing', $errors );
		$this->assertContains( 'destination.postcode_invalid', $errors );
		$this->assertContains( 'destination.province_invalid', $errors );
	}

	/**
	 * A blank destination is no destination.
	 *
	 * @return void
	 */
	public function test_a_blank_destination_is_dropped(): void {
		$parties = Parties::from_array(
			array(
				'recipient'   => $this->recipient()->to_array(),
				'destination' => array( 'country' => 'IT' ),
			)
		);

		$this->assertNull( $parties->destination );
	}

	/**
	 * The header survives a round trip, which is what the snapshot columns are.
	 *
	 * @return void
	 */
	public function test_it_survives_a_round_trip(): void {
		$parties = new Parties(
			Company::from_array(
				array(
					'name'       => 'Oxysoft S.r.l.',
					'vat_number' => '01234567897',
				)
			),
			$this->recipient(),
			new Address( 'Via Milano 9', '20090', 'Segrate', 'MI', 'IT' )
		);

		$this->assertEquals( $parties, Parties::from_array( $parties->to_array() ) );
	}
}
