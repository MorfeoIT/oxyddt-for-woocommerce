<?php
/**
 * The filters, before they reach a query.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\DocumentQuery;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Everything here arrives from a query string, which is to say from anybody.
 */
final class DocumentQueryTest extends TestCase {

	/**
	 * Nothing asked for is everything, newest first.
	 *
	 * @return void
	 */
	public function test_an_empty_query_asks_for_everything(): void {
		$query = DocumentQuery::from_array( array() );

		$this->assertFalse( $query->is_filtered() );
		$this->assertSame( 1, $query->page );
		$this->assertSame( 0, $query->offset() );
		$this->assertSame( DocumentQuery::PER_PAGE, $query->per_page );
		$this->assertSame( DocumentQuery::BY_DATE, $query->order_by );
		$this->assertFalse( $query->ascending );
		$this->assertSame( array(), $query->to_query_args() );
	}

	/**
	 * A page is at least one, whatever was asked for.
	 *
	 * @return void
	 */
	public function test_a_page_is_never_below_one(): void {
		$this->assertSame( 1, DocumentQuery::from_array( array( 'page_number' => '0' ) )->page );
		$this->assertSame( 1, DocumentQuery::from_array( array( 'page_number' => '-4' ) )->page );
		$this->assertSame( 1, DocumentQuery::from_array( array( 'page_number' => 'seven' ) )->page );
		$this->assertSame( 3, DocumentQuery::from_array( array( 'page_number' => '3' ) )->page );
	}

	/**
	 * And a page holds a bounded number of rows: a register is not a way to ask
	 * a site to read its whole documents table into memory.
	 *
	 * @return void
	 */
	public function test_a_page_holds_a_bounded_number_of_rows(): void {
		$this->assertSame( DocumentQuery::MAX_PER_PAGE, DocumentQuery::from_array( array( 'per_page' => '100000' ) )->per_page );
		$this->assertSame( 50, DocumentQuery::from_array( array( 'per_page' => '50' ) )->per_page );
		$this->assertSame( 1, DocumentQuery::from_array( array( 'per_page' => '1' ) )->per_page );

		// Nonsense is the default rather than one: somebody who asked for zero
		// rows wanted a page, not an empty screen.
		$this->assertSame( DocumentQuery::PER_PAGE, DocumentQuery::from_array( array( 'per_page' => '0' ) )->per_page );
		$this->assertSame( DocumentQuery::PER_PAGE, DocumentQuery::from_array( array( 'per_page' => 'lots' ) )->per_page );
	}

	/**
	 * The offset follows from the page and the page size.
	 *
	 * @return void
	 */
	public function test_the_offset_follows_the_page(): void {
		$query = DocumentQuery::from_array(
			array(
				'page_number' => '3',
				'per_page'    => '20',
			)
		);

		$this->assertSame( 40, $query->offset() );
		$this->assertSame( 0, $query->on_page( 1 )->offset() );
	}

	/**
	 * A month is a month.
	 *
	 * @return void
	 */
	public function test_a_month_is_between_one_and_twelve(): void {
		$this->assertSame( 3, DocumentQuery::from_array( array( 'month' => '3' ) )->month );
		$this->assertNull( DocumentQuery::from_array( array( 'month' => '13' ) )->month );
		$this->assertNull( DocumentQuery::from_array( array( 'month' => '0' ) )->month );
		$this->assertNull( DocumentQuery::from_array( array( 'month' => 'March' ) )->month );
	}

	/**
	 * A year is a year somebody could have issued a document in.
	 *
	 * @return void
	 */
	public function test_a_year_is_plausible(): void {
		$this->assertSame( 2026, DocumentQuery::from_array( array( 'year' => '2026' ) )->year );
		$this->assertNull( DocumentQuery::from_array( array( 'year' => '12' ) )->year );
		$this->assertNull( DocumentQuery::from_array( array( 'year' => '99999' ) )->year );
	}

	/**
	 * A range typed backwards is a range: somebody meant 120 to 130 and typed it
	 * the other way round. Refusing it would be pedantry.
	 *
	 * @return void
	 */
	public function test_a_backwards_range_is_read_the_right_way_round(): void {
		$query = DocumentQuery::from_array(
			array(
				'number_from' => '130',
				'number_to'   => '120',
			)
		);

		$this->assertSame( 120, $query->number_from );
		$this->assertSame( 130, $query->number_to );
	}

	/**
	 * Only the two sort columns the register offers, and only two directions.
	 *
	 * @return void
	 */
	public function test_sorting_comes_from_a_short_list(): void {
		$this->assertSame( DocumentQuery::BY_NUMBER, DocumentQuery::from_array( array( 'order_by' => 'number' ) )->order_by );
		$this->assertSame(
			DocumentQuery::BY_DATE,
			DocumentQuery::from_array( array( 'order_by' => 'recipient_name; DROP TABLE' ) )->order_by,
			'anything else is the default, not an error to pass on to SQL'
		);

		$this->assertTrue( DocumentQuery::from_array( array( 'order_dir' => 'ASC' ) )->ascending );
		$this->assertFalse( DocumentQuery::from_array( array( 'order_dir' => 'sideways' ) )->ascending );
	}

	/**
	 * A state that is not one of the three is no filter at all.
	 *
	 * @return void
	 */
	public function test_an_unknown_state_is_not_a_filter(): void {
		$this->assertSame( DocumentStatus::Issued, DocumentQuery::from_array( array( 'status' => 'issued' ) )->status );
		$this->assertNull( DocumentQuery::from_array( array( 'status' => 'lost' ) )->status );
		$this->assertNull( DocumentQuery::from_array( array( 'status' => '' ) )->status );
	}

	/**
	 * A reason for transport is normalised the same way it was when it was
	 * stored, or the filter would never match.
	 *
	 * @return void
	 */
	public function test_a_reason_is_normalised_like_a_stored_one(): void {
		$this->assertSame( 'conto_lavorazione', DocumentQuery::from_array( array( 'causal' => 'Conto Lavorazione' ) )->causal );
	}

	/**
	 * Anything typed in is a filter.
	 *
	 * @return void
	 */
	public function test_it_knows_when_something_was_asked_for(): void {
		$this->assertTrue( DocumentQuery::from_array( array( 'search' => 'Bianchi' ) )->is_filtered() );
		$this->assertTrue( DocumentQuery::from_array( array( 'order_id' => '123' ) )->is_filtered() );
		$this->assertFalse( DocumentQuery::from_array( array( 'page_number' => '4' ) )->is_filtered(), 'a page is not a filter' );
	}

	/**
	 * A filtered register is a link somebody can send to a colleague, and short
	 * enough to read: empty filters are left out.
	 *
	 * @return void
	 */
	public function test_it_rebuilds_itself_as_a_link(): void {
		$args = DocumentQuery::from_array(
			array(
				'search'      => 'Bianchi',
				'year'        => '2026',
				'status'      => 'issued',
				'page_number' => '2',
				'month'       => '',
			)
		)->to_query_args();

		$this->assertSame(
			array(
				'search'      => 'Bianchi',
				'year'        => '2026',
				'status'      => 'issued',
				'page_number' => '2',
			),
			$args
		);
	}

	/**
	 * Turning the page keeps the filters. A register that forgot them on page
	 * two would be a register nobody trusted.
	 *
	 * @return void
	 */
	public function test_turning_the_page_keeps_the_filters(): void {
		$query = DocumentQuery::from_array(
			array(
				'search' => 'Bianchi',
				'year'   => '2026',
			)
		)->on_page( 4 );

		$this->assertSame( 'Bianchi', $query->search );
		$this->assertSame( 2026, $query->year );
		$this->assertSame( 4, $query->page );
	}

	/**
	 * Everything, in one call, for whoever wants the lot.
	 *
	 * @return void
	 */
	public function test_all_asks_for_everything(): void {
		$this->assertFalse( DocumentQuery::all()->is_filtered() );
	}
}
