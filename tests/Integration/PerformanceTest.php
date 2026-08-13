<?php
/**
 * What the plugin costs a page.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Domain\DocumentQuery;
use Oxysoft\OxyDDT\Infrastructure\SystemClock;
use Oxysoft\OxyDDT\Issuing\Issuer;
use Oxysoft\OxyDDT\Persistence\DocumentRepository;
use Oxysoft\OxyDDT\Persistence\SequenceRepository;
use Oxysoft\OxyDDT\Settings\Settings;
use Oxysoft\OxyDDT\WooCommerce\DocumentFactory;
use Oxysoft\OxyDDT\WooCommerce\OrderFulfilment;
use WP_UnitTestCase;

/**
 * Counting queries, not seconds.
 *
 * A stopwatch on a CI runner measures the runner. What can be measured honestly
 * is how many queries a screen costs, and whether that number grows with the
 * number of documents — which is the failure that actually arrives, quietly,
 * eighteen months in, on the one shop with four years of shipments.
 *
 * The numbers below are ceilings with room in them. They are not a promise about
 * milliseconds; they are a promise that nothing here is N+1.
 */
final class PerformanceTest extends WP_UnitTestCase {

	use ShopFixtures;

	/**
	 * The store.
	 *
	 * @var DocumentRepository
	 */
	private DocumentRepository $documents;

	/**
	 * The issuer.
	 *
	 * @var Issuer
	 */
	private Issuer $issuer;

	/**
	 * The order-to-draft factory.
	 *
	 * @var DocumentFactory
	 */
	private DocumentFactory $drafts;

	/**
	 * Build the services.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$clock    = new SystemClock();
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

		$this->documents = new DocumentRepository( $clock );
		$this->drafts    = new DocumentFactory( $settings, $clock );
		$this->issuer    = new Issuer(
			$this->documents,
			new SequenceRepository( $clock ),
			$settings,
			$clock,
			new AuditLog( $clock )
		);
	}

	/**
	 * How many queries something costs.
	 *
	 * @param callable(): void $work What to measure.
	 * @return int
	 */
	private function queries( callable $work ): int {
		global $wpdb;

		$before = $wpdb->num_queries;

		$work();

		return $wpdb->num_queries - $before;
	}

	/**
	 * Fill the register.
	 *
	 * @param int $how_many How many documents.
	 * @return void
	 */
	private function issue( int $how_many ): void {
		for ( $i = 0; $i < $how_many; $i++ ) {
			$this->issuer->issue(
				$this->documents->save(
					$this->drafts->draft_from_order( $this->an_order( array( 'Product A' => 2.0 ) ) )
				)
			);
		}
	}

	/**
	 * **The one that matters.** A page of the register costs the same whether it
	 * holds five documents or fifty: one count, one page of rows, one query for
	 * all their lines, one for all their orders.
	 *
	 * @return void
	 */
	public function test_a_page_of_the_register_does_not_grow_with_its_contents(): void {
		$this->issue( 5 );

		$small = $this->queries(
			function (): void {
				$this->documents->search( DocumentQuery::from_array( array( 'per_page' => '50' ) ) );
			}
		);

		$this->issue( 25 );

		$large = $this->queries(
			function (): void {
				$this->documents->search( DocumentQuery::from_array( array( 'per_page' => '50' ) ) );
			}
		);

		$this->assertSame( 30, $this->documents->search( DocumentQuery::all() )['total'] );
		$this->assertSame( $small, $large, 'six times the documents, the same number of queries' );
		$this->assertLessThanOrEqual( 6, $large, 'and that number is a handful' );
	}

	/**
	 * The box on an order screen reads every delivery note of that order. A shop
	 * that has shipped an order in fifteen parts must not pay fifteen times.
	 *
	 * @return void
	 */
	public function test_the_order_box_does_not_grow_with_its_documents(): void {
		$order       = $this->an_order( array( 'Product A' => 60.0 ) );
		$outstanding = new OrderFulfilment( $this->documents );

		$this->add_documents_to( $order->get_id(), 3 );

		$few = $this->queries(
			static function () use ( $outstanding, $order ): void {
				$outstanding->for_order( $order );
			}
		);

		$this->add_documents_to( $order->get_id(), 12 );

		$many = $this->queries(
			static function () use ( $outstanding, $order ): void {
				$outstanding->for_order( $order );
			}
		);

		$this->assertCount( 15, $this->documents->for_order( $order->get_id() ) );
		$this->assertLessThanOrEqual( $few, $many, 'five times the documents, no more queries' );
	}

	/**
	 * Reading one document is a fixed handful of queries, however many lines it
	 * has.
	 *
	 * @return void
	 */
	public function test_reading_a_document_is_a_fixed_cost(): void {
		$order = $this->an_order(
			array(
				'Product A' => 1.0,
				'Product B' => 1.0,
				'Product C' => 1.0,
				'Product D' => 1.0,
				'Product E' => 1.0,
			)
		);

		$document = $this->documents->save( $this->drafts->draft_from_order( $order ) );

		$queries = $this->queries(
			function () use ( $document ): void {
				$this->documents->find( $document->id );
			}
		);

		$this->assertLessThanOrEqual( 4, $queries );
	}

	/**
	 * Saving a document writes its row, its lines and its links, and does not
	 * read the world first.
	 *
	 * @return void
	 */
	public function test_saving_a_document_is_a_bounded_cost(): void {
		$draft = $this->drafts->draft_from_order( $this->an_order( array( 'Product A' => 4.0 ) ) );

		$queries = $this->queries(
			function () use ( $draft ): void {
				$this->documents->save( $draft );
			}
		);

		$this->assertLessThanOrEqual( 8, $queries );
	}

	/**
	 * Add delivery notes to an order, a couple of pieces at a time.
	 *
	 * @param int $order_id The order.
	 * @param int $how_many How many notes.
	 * @return void
	 */
	private function add_documents_to( int $order_id, int $how_many ): void {
		$order = wc_get_order( $order_id );

		$this->assertNotFalse( $order );

		$outstanding = new OrderFulfilment( $this->documents );

		for ( $i = 0; $i < $how_many; $i++ ) {
			$fulfilment = $outstanding->for_order( $order );
			$lines      = $fulfilment->lines();
			$first      = $lines[0] ?? null;

			$this->assertNotNull( $first );

			$this->issuer->issue(
				$this->documents->save(
					$this->drafts->draft_from_order( $order )->with_lines( array( $first->proposal( 2.0 ) ) )
				)
			);
		}
	}
}
