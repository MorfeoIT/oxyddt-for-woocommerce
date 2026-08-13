<?php
/**
 * How the goods travel.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\Transport;
use PHPUnit\Framework\TestCase;

/**
 * The transport block.
 */
final class TransportTest extends TestCase {

	/**
	 * An empty block is not an error. Most shops fill this in differently every
	 * day, and a plugin that argues about it gets switched off.
	 *
	 * @return void
	 */
	public function test_an_empty_block_is_acceptable(): void {
		$this->assertSame( array(), ( new Transport() )->errors() );
	}

	/**
	 * Saying a carrier is taking the goods without saying which carrier is a
	 * contradiction, and it is the one thing worth refusing here.
	 *
	 * @return void
	 */
	public function test_a_carrier_without_a_name_is_a_contradiction(): void {
		$this->assertContains( 'carrier_missing', ( new Transport( Transport::BY_CARRIER ) )->errors() );
		$this->assertSame( array(), ( new Transport( Transport::BY_CARRIER, 'DHL' ) )->errors() );
		$this->assertSame( array(), ( new Transport( Transport::BY_CARRIER, '', 12 ) )->errors() );
	}

	/**
	 * A load cannot weigh more empty than full.
	 *
	 * @return void
	 */
	public function test_net_weight_cannot_exceed_gross(): void {
		$this->assertContains(
			'weight_inconsistent',
			( new Transport( '', '', 0, '', 0, 10.0, 12.0 ) )->errors()
		);

		$this->assertSame(
			array(),
			( new Transport( '', '', 0, '', 0, 12.0, 10.0 ) )->errors()
		);
	}

	/**
	 * A value outside the known set is stored as nothing rather than printed on
	 * a line somebody may have to justify.
	 *
	 * @return void
	 */
	public function test_unknown_values_are_refused_quietly(): void {
		$transport = Transport::from_array(
			array(
				'by'       => 'whoever',
				'carriage' => 'somehow',
			)
		);

		$this->assertSame( '', $transport->by );
		$this->assertSame( '', $transport->carriage );
	}

	/**
	 * What the shop meant, however it was typed.
	 *
	 * @return void
	 */
	public function test_known_values_are_read_case_insensitively(): void {
		$transport = Transport::from_array(
			array(
				'by'       => 'CARRIER',
				'carriage' => 'Assegnato',
			)
		);

		$this->assertSame( Transport::BY_CARRIER, $transport->by );
		$this->assertSame( Transport::CARRIAGE_FORWARD, $transport->carriage );
	}

	/**
	 * A weight nobody entered is null, not zero. Zero is a claim.
	 *
	 * @return void
	 */
	public function test_an_unweighed_load_has_no_weight(): void {
		$transport = Transport::from_array( array( 'weight_gross' => '' ) );

		$this->assertNull( $transport->weight_gross );
		$this->assertNull( Transport::from_array( array( 'weight_gross' => '0' ) )->weight_gross );
		$this->assertEqualsWithDelta( 12.5, (float) Transport::from_array( array( 'weight_gross' => '12.5' ) )->weight_gross, 0.001 );
	}

	/**
	 * A round trip through storage.
	 *
	 * @return void
	 */
	public function test_it_survives_a_round_trip(): void {
		$transport = new Transport(
			Transport::BY_CARRIER,
			'Bartolini',
			3,
			Transport::CARRIAGE_PREPAID,
			4,
			120.5,
			110.0,
			'Scatole',
			'2026-08-13 14:30:00'
		);

		$this->assertEquals( $transport, Transport::from_array( $transport->to_array() ) );
	}
}
