<?php
/**
 * Lines of a delivery note.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\Line;
use PHPUnit\Framework\TestCase;

/**
 * What is going out, and where it came from.
 */
final class LineTest extends TestCase {

	/**
	 * A line survives a round trip through storage.
	 *
	 * @return void
	 */
	public function test_it_survives_a_round_trip(): void {
		$line = new Line( 'Product A', 2.5, 'A-1', 'INT-1', 'kg', 123, 11, 44, 55, 12.5, 3 );

		$this->assertEquals( $line, Line::from_array( $line->to_array() ) );
	}

	/**
	 * Quantities are decimal. A shop that sells metres and gets whole numbers
	 * back has lost something.
	 *
	 * @return void
	 */
	public function test_quantities_keep_their_decimals(): void {
		$line = Line::from_array(
			array(
				'name'     => 'Cable',
				'quantity' => '2.500',
			)
		);

		$this->assertEqualsWithDelta( 2.5, $line->quantity, Line::EPSILON );
	}

	/**
	 * A price that is not there is null, not zero: zero is a price, and it would
	 * print.
	 *
	 * @return void
	 */
	public function test_an_absent_price_stays_absent(): void {
		$this->assertNull( Line::from_array( array( 'name' => 'Product A' ) )->unit_price );
		$this->assertSame( 0.0, Line::from_array( array( 'unit_price' => '0' ) )->unit_price );
	}

	/**
	 * A line knows which order line it fulfils.
	 *
	 * @return void
	 */
	public function test_it_knows_what_it_fulfils(): void {
		$line = new Line( 'Product A', 1.0, '', '', '', 123, 11 );

		$this->assertTrue( $line->fulfils( 123, 11 ) );
		$this->assertFalse( $line->fulfils( 123, 12 ) );
		$this->assertFalse( $line->fulfils( 456, 11 ) );
	}

	/**
	 * A line for nothing, or for nothing named, is not a line.
	 *
	 * @return void
	 */
	public function test_a_line_that_says_nothing_is_empty(): void {
		$this->assertTrue( ( new Line( 'Product A', 0.0 ) )->is_empty() );
		$this->assertTrue( ( new Line( '   ', 3.0 ) )->is_empty() );
		$this->assertTrue( ( new Line( 'Product A', -1.0 ) )->is_empty(), 'a negative quantity is not a shipment' );
		$this->assertFalse( ( new Line( 'Product A', 0.001 ) )->is_empty() );
	}

	/**
	 * Changing a quantity leaves everything else where it was.
	 *
	 * @return void
	 */
	public function test_changing_the_quantity_changes_nothing_else(): void {
		$line    = new Line( 'Product A', 6.0, 'A-1', 'INT-1', 'pz', 123, 11, 44, 55, 9.9, 2 );
		$changed = $line->with_quantity( 4.0 );

		$this->assertEqualsWithDelta( 6.0, $line->quantity, Line::EPSILON );
		$this->assertEqualsWithDelta( 4.0, $changed->quantity, Line::EPSILON );
		$this->assertEquals( $line->to_array(), $changed->with_quantity( 6.0 )->to_array() );
	}

	/**
	 * Negative identifiers are somebody's mistake, not a row to look up.
	 *
	 * @return void
	 */
	public function test_negative_identifiers_become_none(): void {
		$line = Line::from_array(
			array(
				'name'     => 'Product A',
				'quantity' => 1,
				'order_id' => '-5',
			)
		);

		$this->assertSame( 0, $line->order_id );
	}
}
