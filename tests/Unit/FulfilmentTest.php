<?php
/**
 * The arithmetic a shop's money depends on.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentNumber;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Domain\Fulfilment;
use Oxysoft\OxyDDT\Domain\FulfilmentStatus;
use Oxysoft\OxyDDT\Domain\Line;
use Oxysoft\OxyDDT\Domain\Parties;
use Oxysoft\OxyDDT\Domain\Transport;
use PHPUnit\Framework\TestCase;

/**
 * Ordered, shipped, reserved, available.
 *
 * The worked example throughout is the one from the specification: order 123
 * for ten of product A and five of product B, shipped in two parts.
 */
final class FulfilmentTest extends TestCase {

	/**
	 * The order: 10 × A, 5 × B.
	 *
	 * @return list<Line>
	 */
	private function ordered(): array {
		return array(
			new Line( 'Product A', 10.0, 'A-1', '', 'pz', 123, 11 ),
			new Line( 'Product B', 5.0, 'B-1', '', 'pz', 123, 12 ),
		);
	}

	/**
	 * A document of the given lines.
	 *
	 * @param DocumentStatus $status   Draft, issued or cancelled.
	 * @param list<Line>     $lines    What is on it.
	 * @param int            $id       Its identifier.
	 * @param int            $sequence Its number, for the issued ones.
	 * @return Document
	 */
	private function document( DocumentStatus $status, array $lines, int $id = 1, int $sequence = 1 ): Document {
		return new Document(
			$id,
			$status,
			DocumentStatus::Draft === $status
				? DocumentNumber::none()
				: DocumentNumber::assigned( $sequence . '/2026', '', 2026, $sequence ),
			'2026-08-13',
			new Parties(),
			new Transport(),
			'sale',
			$lines,
			array( 123 )
		);
	}

	/**
	 * Nothing shipped yet: everything is available, and the order has not been
	 * touched.
	 *
	 * @return void
	 */
	public function test_an_untouched_order_has_everything_available(): void {
		$fulfilment = Fulfilment::for_order( $this->ordered(), array() );

		$this->assertSame( FulfilmentStatus::None, $fulfilment->status() );
		$this->assertEqualsWithDelta( 10.0, $fulfilment->lines()[0]->available(), Line::EPSILON );
		$this->assertEqualsWithDelta( 5.0, $fulfilment->lines()[1]->available(), Line::EPSILON );
		$this->assertEqualsWithDelta( 15.0, $fulfilment->total_ordered(), Line::EPSILON );
		$this->assertEqualsWithDelta( 0.0, $fulfilment->total_shipped(), Line::EPSILON );
	}

	/**
	 * The specification's own example: six of A and all five of B go out, and
	 * four of A remain.
	 *
	 * @return void
	 */
	public function test_a_partial_shipment_leaves_the_rest_available(): void {
		$first = $this->document(
			DocumentStatus::Issued,
			array(
				new Line( 'Product A', 6.0, 'A-1', '', 'pz', 123, 11 ),
				new Line( 'Product B', 5.0, 'B-1', '', 'pz', 123, 12 ),
			)
		);

		$fulfilment = Fulfilment::for_order( $this->ordered(), array( $first ) );

		$this->assertSame( FulfilmentStatus::Partial, $fulfilment->status() );

		$a = $fulfilment->line( 11 );
		$b = $fulfilment->line( 12 );

		$this->assertNotNull( $a );
		$this->assertNotNull( $b );
		$this->assertEqualsWithDelta( 6.0, $a->shipped, Line::EPSILON );
		$this->assertEqualsWithDelta( 4.0, $a->available(), Line::EPSILON );
		$this->assertEqualsWithDelta( 4.0, $a->outstanding(), Line::EPSILON );
		$this->assertFalse( $a->is_complete() );
		$this->assertEqualsWithDelta( 0.0, $b->available(), Line::EPSILON );
		$this->assertTrue( $b->is_complete() );
		$this->assertSame( 1, $fulfilment->completed_lines() );
	}

	/**
	 * And the second document finishes the order.
	 *
	 * @return void
	 */
	public function test_the_second_document_completes_the_order(): void {
		$documents = array(
			$this->document(
				DocumentStatus::Issued,
				array(
					new Line( 'Product A', 6.0, 'A-1', '', 'pz', 123, 11 ),
					new Line( 'Product B', 5.0, 'B-1', '', 'pz', 123, 12 ),
				),
				1,
				1
			),
			$this->document(
				DocumentStatus::Issued,
				array( new Line( 'Product A', 4.0, 'A-1', '', 'pz', 123, 11 ) ),
				2,
				2
			),
		);

		$fulfilment = Fulfilment::for_order( $this->ordered(), $documents );

		$this->assertSame( FulfilmentStatus::Complete, $fulfilment->status() );
		$this->assertFalse( $fulfilment->has_anything_available() );
		$this->assertEqualsWithDelta( 15.0, $fulfilment->total_shipped(), Line::EPSILON );
		$this->assertSame( 2, $fulfilment->completed_lines() );
	}

	/**
	 * A cancelled document ships nothing. Its quantities go back to the order,
	 * because that is what cancelling means.
	 *
	 * @return void
	 */
	public function test_a_cancelled_document_gives_the_quantities_back(): void {
		$documents = array(
			$this->document(
				DocumentStatus::Cancelled,
				array( new Line( 'Product A', 10.0, 'A-1', '', 'pz', 123, 11 ) ),
				1,
				1
			),
		);

		$fulfilment = Fulfilment::for_order( $this->ordered(), $documents );

		$this->assertSame( FulfilmentStatus::None, $fulfilment->status() );
		$this->assertEqualsWithDelta( 10.0, $fulfilment->line( 11 )->available(), Line::EPSILON );
	}

	/**
	 * A draft has shipped nothing, but the goods on it are spoken for: two
	 * people with the same order open must not both send the last four.
	 *
	 * @return void
	 */
	public function test_a_draft_reserves_without_shipping(): void {
		$draft = $this->document(
			DocumentStatus::Draft,
			array( new Line( 'Product A', 4.0, 'A-1', '', 'pz', 123, 11 ) ),
			5
		);

		$fulfilment = Fulfilment::for_order( $this->ordered(), array( $draft ) );
		$line       = $fulfilment->line( 11 );

		$this->assertNotNull( $line );
		$this->assertEqualsWithDelta( 0.0, $line->shipped, Line::EPSILON );
		$this->assertEqualsWithDelta( 4.0, $line->reserved, Line::EPSILON );
		$this->assertEqualsWithDelta( 6.0, $line->available(), Line::EPSILON );
		$this->assertEqualsWithDelta( 10.0, $line->outstanding(), Line::EPSILON, 'the customer is still owed all ten' );
		$this->assertSame( FulfilmentStatus::None, $fulfilment->status() );
	}

	/**
	 * Reopening a draft must not count it against itself, or the four pieces
	 * already on it would look unavailable.
	 *
	 * @return void
	 */
	public function test_the_document_being_edited_does_not_count_against_itself(): void {
		$draft = $this->document(
			DocumentStatus::Draft,
			array( new Line( 'Product A', 4.0, 'A-1', '', 'pz', 123, 11 ) ),
			5
		);

		$fulfilment = Fulfilment::for_order( $this->ordered(), array( $draft ), 5 );

		$this->assertEqualsWithDelta( 10.0, $fulfilment->line( 11 )->available(), Line::EPSILON );
	}

	/**
	 * Excluding a document leaves every other one counting.
	 *
	 * @return void
	 */
	public function test_excluding_one_document_leaves_the_others_alone(): void {
		$documents = array(
			$this->document(
				DocumentStatus::Issued,
				array( new Line( 'Product A', 6.0, 'A-1', '', 'pz', 123, 11 ) ),
				1,
				1
			),
			$this->document(
				DocumentStatus::Draft,
				array( new Line( 'Product A', 2.0, 'A-1', '', 'pz', 123, 11 ) ),
				5
			),
		);

		$fulfilment = Fulfilment::for_order( $this->ordered(), $documents, 5 );

		$this->assertEqualsWithDelta( 6.0, $fulfilment->line( 11 )->shipped, Line::EPSILON );
		$this->assertEqualsWithDelta( 4.0, $fulfilment->line( 11 )->available(), Line::EPSILON );
	}

	/**
	 * The same order line can appear twice on one document — two pallets listed
	 * separately — and the two count together.
	 *
	 * @return void
	 */
	public function test_two_lines_for_the_same_product_add_up(): void {
		$document = $this->document(
			DocumentStatus::Issued,
			array(
				new Line( 'Product A, first pallet', 6.0, 'A-1', '', 'pz', 123, 11 ),
				new Line( 'Product A, second pallet', 2.0, 'A-1', '', 'pz', 123, 11 ),
			)
		);

		$fulfilment = Fulfilment::for_order( $this->ordered(), array( $document ) );

		$this->assertEqualsWithDelta( 8.0, $fulfilment->line( 11 )->shipped, Line::EPSILON );
		$this->assertEqualsWithDelta( 2.0, $fulfilment->line( 11 )->available(), Line::EPSILON );
	}

	/**
	 * What the create screen offers before anybody types: the whole remainder.
	 *
	 * @return void
	 */
	public function test_it_proposes_everything_that_is_left(): void {
		$first = $this->document(
			DocumentStatus::Issued,
			array(
				new Line( 'Product A', 6.0, 'A-1', '', 'pz', 123, 11 ),
				new Line( 'Product B', 5.0, 'B-1', '', 'pz', 123, 12 ),
			)
		);

		$proposal = Fulfilment::for_order( $this->ordered(), array( $first ) )->everything_available();

		$this->assertCount( 1, $proposal, 'the finished line is not offered' );
		$this->assertSame( 11, $proposal[0]->order_item_id );
		$this->assertEqualsWithDelta( 4.0, $proposal[0]->quantity, Line::EPSILON );
		$this->assertSame( 'Product A', $proposal[0]->name );
	}

	/**
	 * Asking for more than is left is refused — with the line and the figure, so
	 * the screen can say which one and by how much.
	 *
	 * @return void
	 */
	public function test_asking_for_more_than_is_left_is_refused(): void {
		$first = $this->document(
			DocumentStatus::Issued,
			array( new Line( 'Product A', 6.0, 'A-1', '', 'pz', 123, 11 ) )
		);

		$fulfilment = Fulfilment::for_order( $this->ordered(), array( $first ) );

		$this->assertSame(
			array(),
			$fulfilment->exceeding( array( new Line( 'Product A', 4.0, 'A-1', '', 'pz', 123, 11 ) ) ),
			'four is exactly what is left'
		);

		$exceeding = $fulfilment->exceeding( array( new Line( 'Product A', 5.0, 'A-1', '', 'pz', 123, 11 ) ) );

		$this->assertCount( 1, $exceeding );
		$this->assertSame( 11, $exceeding[0]['line']->order_item_id );
		$this->assertEqualsWithDelta( 4.0, $exceeding[0]['available'], Line::EPSILON );
	}

	/**
	 * Two lines that each fit, and together do not.
	 *
	 * @return void
	 */
	public function test_two_lines_that_fit_separately_can_still_be_too_much(): void {
		$fulfilment = Fulfilment::for_order( $this->ordered(), array() );

		$exceeding = $fulfilment->exceeding(
			array(
				new Line( 'Product A', 6.0, 'A-1', '', 'pz', 123, 11 ),
				new Line( 'Product A', 6.0, 'A-1', '', 'pz', 123, 11 ),
			)
		);

		$this->assertCount( 1, $exceeding, 'reported once, for the order line, not once per document line' );
	}

	/**
	 * A line the order never had cannot be fulfilled by anybody.
	 *
	 * @return void
	 */
	public function test_a_line_that_is_not_on_the_order_is_refused(): void {
		$fulfilment = Fulfilment::for_order( $this->ordered(), array() );

		$exceeding = $fulfilment->exceeding( array( new Line( 'Something else', 1.0, 'X-1', '', 'pz', 123, 99 ) ) );

		$this->assertCount( 1, $exceeding );
		$this->assertEqualsWithDelta( 0.0, $exceeding[0]['available'], Line::EPSILON );
	}

	/**
	 * More going out than was ordered is visible rather than hidden: it happens,
	 * and it is not always a mistake.
	 *
	 * @return void
	 */
	public function test_over_shipping_is_visible(): void {
		$document = $this->document(
			DocumentStatus::Issued,
			array( new Line( 'Product A', 12.0, 'A-1', '', 'pz', 123, 11 ) )
		);

		$line = Fulfilment::for_order( $this->ordered(), array( $document ) )->line( 11 );

		$this->assertTrue( $line->is_over_shipped() );
		$this->assertTrue( $line->is_complete() );
		$this->assertEqualsWithDelta( 0.0, $line->available(), Line::EPSILON, 'never negative' );
	}

	/**
	 * Decimals survive the arithmetic. A shop selling by the metre gets 2.5
	 * back, not 2.
	 *
	 * @return void
	 */
	public function test_decimal_quantities_survive(): void {
		$ordered  = array( new Line( 'Cable', 7.5, 'C-1', '', 'm', 123, 21 ) );
		$document = $this->document(
			DocumentStatus::Issued,
			array( new Line( 'Cable', 2.25, 'C-1', '', 'm', 123, 21 ) )
		);

		$line = Fulfilment::for_order( $ordered, array( $document ) )->line( 21 );

		$this->assertEqualsWithDelta( 5.25, $line->available(), Line::EPSILON );
		$this->assertFalse( $line->is_complete() );
	}

	/**
	 * An order with no lines is not half-fulfilled, it is nothing.
	 *
	 * @return void
	 */
	public function test_an_empty_order_is_not_fulfilled(): void {
		$fulfilment = Fulfilment::for_order( array(), array() );

		$this->assertSame( FulfilmentStatus::None, $fulfilment->status() );
		$this->assertFalse( $fulfilment->has_anything_available() );
		$this->assertNull( $fulfilment->line( 11 ) );
	}

	/**
	 * Documents of another order do not touch this one, even when the order
	 * lines happen to share an identifier.
	 *
	 * @return void
	 */
	public function test_another_orders_documents_are_ignored(): void {
		$elsewhere = $this->document(
			DocumentStatus::Issued,
			array( new Line( 'Product A', 10.0, 'A-1', '', 'pz', 456, 11 ) )
		);

		$fulfilment = Fulfilment::for_order( $this->ordered(), array( $elsewhere ) );

		$this->assertEqualsWithDelta( 10.0, $fulfilment->line( 11 )->available(), Line::EPSILON );
	}
}
