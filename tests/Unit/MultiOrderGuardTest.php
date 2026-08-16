<?php
/**
 * The guard that stops one screen from eating a document made of several orders.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Admin\EditScreen;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\Line;
use PHPUnit\Framework\TestCase;

/**
 * The editing screen rebuilds a document's lines from one order's outstanding
 * quantities. A document that gathers several orders, saved from there, would
 * keep that order's goods and silently drop everybody else's — a successful
 * save that loses data, which is the worst kind.
 *
 * The schema has always allowed such documents: `order_ids` is a list, and a
 * filter can produce one. So the guard is not about any add-on; it is about
 * what that screen can honestly do.
 */
final class MultiOrderGuardTest extends TestCase {

	/**
	 * A document built from one order is the ordinary case and is editable.
	 *
	 * @return void
	 */
	public function test_one_order_is_not_guarded(): void {
		$document = new Document( 0 );
		$document = $document->with_details( array( 'order_ids' => array( 42 ) ) );

		$this->assertFalse( EditScreen::spans_several_orders( $document ) );
	}

	/**
	 * Two orders on the link, and the screen stands aside.
	 *
	 * @return void
	 */
	public function test_two_orders_are_guarded(): void {
		$document = ( new Document( 0 ) )->with_details( array( 'order_ids' => array( 42, 43 ) ) );

		$this->assertTrue( EditScreen::spans_several_orders( $document ) );
	}

	/**
	 * And when the link says one order but the lines came from two — which is
	 * what a filter that adds lines produces — the lines win. The question is
	 * "whose goods are on this document", not "what does the link say".
	 *
	 * @return void
	 */
	public function test_lines_from_another_order_count_too(): void {
		$document = ( new Document( 0 ) )->with_lines(
			array(
				new Line( 'Panel', 6.0, 'SKU-1', '', '', 42, 100 ),
				new Line( 'Cable', 100.0, 'SKU-2', '', '', 43, 101 ),
			)
		);

		$this->assertTrue( EditScreen::spans_several_orders( $document ) );
	}
}
