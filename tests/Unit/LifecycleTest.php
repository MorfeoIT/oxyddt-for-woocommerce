<?php
/**
 * The dates a document carries.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\Causals;
use Oxysoft\OxyDDT\Domain\Lifecycle;
use PHPUnit\Framework\TestCase;

/**
 * When things happened, and who did them.
 */
final class LifecycleTest extends TestCase {

	/**
	 * MySQL's zero date is how "never" looks coming out of a column. It is not
	 * a moment in time, and nothing should print it.
	 *
	 * @return void
	 */
	public function test_the_zero_date_is_read_as_never(): void {
		$lifecycle = Lifecycle::from_array(
			array(
				'created_at' => '0000-00-00 00:00:00',
				'issued_at'  => '',
			)
		);

		$this->assertNull( $lifecycle->created_at );
		$this->assertNull( $lifecycle->issued_at );
	}

	/**
	 * Saving a document for the first time is what sets its creation date;
	 * saving it again must not move it.
	 *
	 * @return void
	 */
	public function test_the_creation_date_is_set_once(): void {
		$first  = ( new Lifecycle() )->touched( '2026-08-13 09:00:00', 7 );
		$second = $first->touched( '2026-08-14 11:00:00', 9 );

		$this->assertSame( '2026-08-13 09:00:00', $second->created_at );
		$this->assertSame( 7, $second->created_by, 'the author does not change because somebody else saved' );
		$this->assertSame( '2026-08-14 11:00:00', $second->updated_at );
	}

	/**
	 * Issuing and cancelling are recorded, and survive being saved again.
	 *
	 * @return void
	 */
	public function test_issuing_and_cancelling_are_remembered(): void {
		$lifecycle = new Lifecycle(
			'2026-08-13 09:00:00',
			7,
			'2026-08-13 09:00:00',
			'2026-08-13 09:05:00',
			7,
			'2026-08-20 16:00:00',
			9,
			'Wrong recipient'
		);

		$touched = $lifecycle->touched( '2026-08-21 08:00:00', 3 );

		$this->assertSame( '2026-08-13 09:05:00', $touched->issued_at );
		$this->assertSame( '2026-08-20 16:00:00', $touched->cancelled_at );
		$this->assertSame( 'Wrong recipient', $touched->cancel_reason );
		$this->assertSame( 9, $touched->cancelled_by );
	}

	/**
	 * A round trip through storage.
	 *
	 * @return void
	 */
	public function test_it_survives_a_round_trip(): void {
		$lifecycle = new Lifecycle( '2026-08-13 09:00:00', 7, '2026-08-13 09:00:00' );

		$this->assertEquals( $lifecycle, Lifecycle::from_array( $lifecycle->to_array() ) );
	}

	/**
	 * Reasons for transport: the shop's own are not "unknown", they are simply
	 * not ours, and nothing refuses them.
	 *
	 * @return void
	 */
	public function test_reasons_for_transport_are_open(): void {
		$this->assertTrue( Causals::is_default( Causals::SALE ) );
		$this->assertFalse( Causals::is_default( 'consegna_cantiere' ) );
		$this->assertContains( Causals::INTERNAL_TRANSFER, Causals::defaults() );
	}

	/**
	 * A reason typed by a person becomes something storable.
	 *
	 * @return void
	 */
	public function test_a_typed_reason_is_normalised(): void {
		$this->assertSame( 'conto_lavorazione', Causals::normalise( '  Conto Lavorazione  ' ) );
		$this->assertSame( 'reso_da_cliente', Causals::normalise( 'Reso / da cliente' ) );
		$this->assertSame( 64, strlen( Causals::normalise( str_repeat( 'a', 200 ) ) ) );
	}
}
