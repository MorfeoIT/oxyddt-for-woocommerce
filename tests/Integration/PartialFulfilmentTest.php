<?php
/**
 * Shipping an order in parts, end to end.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentNumber;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Domain\FulfilmentStatus;
use Oxysoft\OxyDDT\Domain\Line;
use Oxysoft\OxyDDT\Infrastructure\SystemClock;
use Oxysoft\OxyDDT\Persistence\DocumentRepository;
use Oxysoft\OxyDDT\Settings\Settings;
use Oxysoft\OxyDDT\WooCommerce\DocumentFactory;
use Oxysoft\OxyDDT\WooCommerce\OrderFulfilment;
use WC_Order;
use WP_UnitTestCase;

/**
 * The specification's own example, against a real order and a real database.
 *
 * Order: ten of product A, five of product B.
 * First note: six of A, all five of B.
 * Second note: the remaining four of A.
 *
 * The unit suite proves the arithmetic. This proves that the arithmetic is
 * being fed the right numbers — the order's lines through WooCommerce, the
 * documents through the store — which is a different question and the one that
 * goes wrong in practice.
 */
final class PartialFulfilmentTest extends WP_UnitTestCase {

	use ShopFixtures;

	/**
	 * The store.
	 *
	 * @var DocumentRepository
	 */
	private DocumentRepository $documents;

	/**
	 * The order-to-draft factory.
	 *
	 * @var DocumentFactory
	 */
	private DocumentFactory $drafts;

	/**
	 * What is left of an order.
	 *
	 * @var OrderFulfilment
	 */
	private OrderFulfilment $outstanding;

	/**
	 * Configure a shop that could issue.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$settings = new Settings();
		$settings->update_company(
			Company::from_array(
				array(
					'name'       => 'Oxysoft S.r.l.',
					'vat_number' => '01234567897',
					'address'    => array(
						'street'   => 'Via Roma 1',
						'postcode' => '20121',
						'city'     => 'Milano',
						'province' => 'MI',
					),
				)
			)
		);

		$this->documents   = new DocumentRepository( new SystemClock() );
		$this->drafts      = new DocumentFactory( $settings, new SystemClock() );
		$this->outstanding = new OrderFulfilment( $this->documents );
	}

	/**
	 * The order from the specification.
	 *
	 * @return WC_Order
	 */
	private function the_order(): WC_Order {
		return $this->an_order(
			array(
				'Product A' => 10.0,
				'Product B' => 5.0,
			)
		);
	}

	/**
	 * The order line identifiers, by product name.
	 *
	 * @param WC_Order $order The order.
	 * @return array<string, int>
	 */
	private function item_ids( WC_Order $order ): array {
		$ids = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$ids[ $item->get_name() ] = (int) $item_id;
		}

		return $ids;
	}

	/**
	 * Issue a draft, as sprint 4 will.
	 *
	 * @param Document $draft    The draft.
	 * @param int      $sequence Its number.
	 * @return Document
	 */
	private function issue( Document $draft, int $sequence ): Document {
		return $this->documents->save(
			new Document(
				$draft->id,
				DocumentStatus::Issued,
				DocumentNumber::assigned( $sequence . '/2026', '', 2026, $sequence ),
				$draft->document_date,
				$draft->parties,
				$draft->transport,
				$draft->causal,
				$draft->lines,
				$draft->order_ids,
				$draft->notes,
				$draft->customer_id,
				$draft->lifecycle
			)
		);
	}

	/**
	 * Nothing sent yet: the whole order is available.
	 *
	 * @return void
	 */
	public function test_an_untouched_order_has_everything_available(): void {
		$order      = $this->the_order();
		$fulfilment = $this->outstanding->for_order( $order );

		$this->assertSame( FulfilmentStatus::None, $fulfilment->status() );
		$this->assertEqualsWithDelta( 15.0, $fulfilment->total_ordered(), Line::EPSILON );
		$this->assertTrue( $fulfilment->has_anything_available() );
		$this->assertCount( 2, $fulfilment->everything_available() );
	}

	/**
	 * Six of A and all of B go out. Four of A remain, and the order says so.
	 *
	 * @return void
	 */
	public function test_a_first_delivery_note_leaves_the_rest_outstanding(): void {
		$order = $this->the_order();
		$items = $this->item_ids( $order );

		$first = $this->outstanding->for_order( $order );

		$draft = $this->drafts->draft_from_order( $order )->with_lines(
			array(
				$first->line( $items['Product A'] )->proposal( 6.0 ),
				$first->line( $items['Product B'] )->proposal( 5.0 ),
			)
		);

		$this->issue( $this->documents->save( $draft ), 1 );

		$after = $this->outstanding->for_order( $order );

		$this->assertSame( FulfilmentStatus::Partial, $after->status() );
		$this->assertEqualsWithDelta( 11.0, $after->total_shipped(), Line::EPSILON );
		$this->assertEqualsWithDelta( 4.0, $after->line( $items['Product A'] )->available(), Line::EPSILON );
		$this->assertEqualsWithDelta( 0.0, $after->line( $items['Product B'] )->available(), Line::EPSILON );
		$this->assertTrue( $after->line( $items['Product B'] )->is_complete() );
	}

	/**
	 * And the second note finishes it.
	 *
	 * @return void
	 */
	public function test_a_second_delivery_note_completes_the_order(): void {
		$order = $this->the_order();
		$items = $this->item_ids( $order );

		$first = $this->outstanding->for_order( $order );
		$this->issue(
			$this->documents->save(
				$this->drafts->draft_from_order( $order )->with_lines(
					array(
						$first->line( $items['Product A'] )->proposal( 6.0 ),
						$first->line( $items['Product B'] )->proposal( 5.0 ),
					)
				)
			),
			1
		);

		$second = $this->outstanding->for_order( $order );
		$this->issue(
			$this->documents->save(
				$this->drafts->draft_from_order( $order )->with_lines(
					array( $second->line( $items['Product A'] )->proposal( 4.0 ) )
				)
			),
			2
		);

		$after = $this->outstanding->for_order( $order );

		$this->assertSame( FulfilmentStatus::Complete, $after->status() );
		$this->assertFalse( $after->has_anything_available() );
		$this->assertCount( 2, $this->outstanding->documents_for( $order ) );
		$this->assertSame( array(), $after->everything_available() );
	}

	/**
	 * A draft holds the goods without sending them: two people with the same
	 * order open do not both send the last four.
	 *
	 * @return void
	 */
	public function test_a_draft_holds_the_goods_without_sending_them(): void {
		$order = $this->the_order();
		$items = $this->item_ids( $order );

		$fulfilment = $this->outstanding->for_order( $order );

		$this->documents->save(
			$this->drafts->draft_from_order( $order )->with_lines(
				array( $fulfilment->line( $items['Product A'] )->proposal( 4.0 ) )
			)
		);

		$after = $this->outstanding->for_order( $order );
		$line  = $after->line( $items['Product A'] );

		$this->assertSame( FulfilmentStatus::None, $after->status(), 'nothing has been sent' );
		$this->assertEqualsWithDelta( 4.0, $line->reserved, Line::EPSILON );
		$this->assertEqualsWithDelta( 6.0, $line->available(), Line::EPSILON );
		$this->assertEqualsWithDelta( 10.0, $line->outstanding(), Line::EPSILON );
	}

	/**
	 * Reopening that draft shows the four back on the table, not missing from it.
	 *
	 * @return void
	 */
	public function test_reopening_a_draft_does_not_count_it_against_itself(): void {
		$order = $this->the_order();
		$items = $this->item_ids( $order );

		$saved = $this->documents->save(
			$this->drafts->draft_from_order( $order )->with_lines(
				array( $this->outstanding->for_order( $order )->line( $items['Product A'] )->proposal( 4.0 ) )
			)
		);

		$editing = $this->outstanding->for_order( $order, $saved->id );

		$this->assertEqualsWithDelta( 10.0, $editing->line( $items['Product A'] )->available(), Line::EPSILON );
	}

	/**
	 * Asking for more than is left is refused, and says by how much.
	 *
	 * @return void
	 */
	public function test_asking_for_more_than_is_left_is_refused(): void {
		$order = $this->the_order();
		$items = $this->item_ids( $order );

		$first = $this->outstanding->for_order( $order );
		$this->issue(
			$this->documents->save(
				$this->drafts->draft_from_order( $order )->with_lines(
					array( $first->line( $items['Product A'] )->proposal( 6.0 ) )
				)
			),
			1
		);

		$after     = $this->outstanding->for_order( $order );
		$exceeding = $after->exceeding( array( $after->line( $items['Product A'] )->proposal( 5.0 ) ) );

		$this->assertCount( 1, $exceeding );
		$this->assertEqualsWithDelta( 4.0, $exceeding[0]['available'], Line::EPSILON );
		$this->assertSame( array(), $after->exceeding( array( $after->line( $items['Product A'] )->proposal( 4.0 ) ) ) );
	}

	/**
	 * Cancelling a delivery note gives the order its quantities back.
	 *
	 * @return void
	 */
	public function test_cancelling_a_note_gives_the_quantities_back(): void {
		$order = $this->the_order();
		$items = $this->item_ids( $order );

		$issued = $this->issue(
			$this->documents->save(
				$this->drafts->draft_from_order( $order )->with_lines(
					array( $this->outstanding->for_order( $order )->line( $items['Product A'] )->proposal( 10.0 ) )
				)
			),
			1
		);

		$this->assertSame(
			FulfilmentStatus::Partial,
			$this->outstanding->for_order( $order )->status()
		);

		$this->documents->save(
			new Document(
				$issued->id,
				DocumentStatus::Cancelled,
				$issued->number,
				$issued->document_date,
				$issued->parties,
				$issued->transport,
				$issued->causal,
				$issued->lines,
				$issued->order_ids,
				$issued->notes,
				$issued->customer_id,
				$issued->lifecycle
			)
		);

		$after = $this->outstanding->for_order( $order );

		$this->assertSame( FulfilmentStatus::None, $after->status() );
		$this->assertEqualsWithDelta( 10.0, $after->line( $items['Product A'] )->available(), Line::EPSILON );
		$this->assertCount( 1, $this->outstanding->documents_for( $order ), 'it stays in the history' );
	}

	/**
	 * Two delivery notes prepared for the same order draw on the same pool of
	 * goods, whichever order they were prepared in.
	 *
	 * @return void
	 */
	public function test_two_drafts_share_one_pool_of_goods(): void {
		$order = $this->the_order();
		$items = $this->item_ids( $order );

		$this->documents->save(
			$this->drafts->draft_from_order( $order )->with_lines(
				array( $this->outstanding->for_order( $order )->line( $items['Product A'] )->proposal( 7.0 ) )
			)
		);

		$second = $this->outstanding->for_order( $order );

		$this->assertEqualsWithDelta( 3.0, $second->line( $items['Product A'] )->available(), Line::EPSILON );
		$this->assertCount(
			1,
			$second->exceeding( array( $second->line( $items['Product A'] )->proposal( 4.0 ) ) )
		);
	}
}
