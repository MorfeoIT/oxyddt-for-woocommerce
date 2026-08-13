<?php
/**
 * Finding a delivery note again.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\Causals;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentQuery;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Domain\Transport;
use Oxysoft\OxyDDT\Infrastructure\SystemClock;
use Oxysoft\OxyDDT\Issuing\Issuer;
use Oxysoft\OxyDDT\Persistence\DocumentRepository;
use Oxysoft\OxyDDT\Persistence\SequenceRepository;
use Oxysoft\OxyDDT\Settings\Settings;
use Oxysoft\OxyDDT\WooCommerce\DocumentFactory;
use WP_UnitTestCase;

/**
 * The register, against a real database.
 *
 * The unit suite proves the filters are cleaned up. This proves they select what
 * they say they select, which is the half that involves SQL.
 */
final class RegisterTest extends WP_UnitTestCase {

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
	 * A document, issued, with the details a filter might look for.
	 *
	 * @param string $date    The document date.
	 * @param string $causal  The reason for transport.
	 * @param string $carrier The carrier.
	 * @return Document
	 */
	private function issued( string $date, string $causal = Causals::SALE, string $carrier = '' ): Document {
		$draft = $this->drafts->draft_from_order( $this->an_order() )
			->with_details(
				array(
					'document_date' => $date,
					'causal'        => $causal,
				)
			)
			->with_transport( new Transport( '' === $carrier ? '' : Transport::BY_CARRIER, $carrier ) );

		return $this->issuer->issue( $this->documents->save( $draft ) );
	}

	/**
	 * Everything comes back, newest first.
	 *
	 * @return void
	 */
	public function test_the_register_lists_everything_newest_first(): void {
		$older = $this->issued( '2026-03-01' );
		$newer = $this->issued( '2026-08-13' );

		$result = $this->documents->search( DocumentQuery::all() );

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( $newer->id, $result['items'][0]->id );
		$this->assertSame( $older->id, $result['items'][1]->id );
	}

	/**
	 * The accountant's question: the delivery notes of March.
	 *
	 * @return void
	 */
	public function test_it_filters_by_year_and_month(): void {
		$this->issued( '2026-03-01' );
		$this->issued( '2026-03-28' );
		$this->issued( '2026-08-13' );
		$this->issued( '2025-03-04' );

		$march = $this->documents->search(
			DocumentQuery::from_array(
				array(
					'year'  => '2026',
					'month' => '3',
				)
			)
		);

		$this->assertSame( 2, $march['total'] );

		$year = $this->documents->search( DocumentQuery::from_array( array( 'year' => '2026' ) ) );

		$this->assertSame( 3, $year['total'] );
	}

	/**
	 * The customer's question: the one with that number.
	 *
	 * @return void
	 */
	public function test_it_searches_by_number(): void {
		$first = $this->issued( '2026-08-13' );
		$this->issued( '2026-08-13' );

		$found = $this->documents->search(
			DocumentQuery::from_array( array( 'search' => $first->number->formatted ) )
		);

		$this->assertSame( 1, $found['total'] );
		$this->assertSame( $first->id, $found['items'][0]->id );
	}

	/**
	 * And by the customer's name, which is what somebody actually remembers.
	 *
	 * @return void
	 */
	public function test_it_searches_by_customer_name(): void {
		$this->issued( '2026-08-13' );

		$found = $this->documents->search( DocumentQuery::from_array( array( 'search' => 'Bianchi' ) ) );

		$this->assertSame( 1, $found['total'] );

		$missing = $this->documents->search( DocumentQuery::from_array( array( 'search' => 'Verdi' ) ) );

		$this->assertSame( 0, $missing['total'] );
		$this->assertSame( array(), $missing['items'] );
	}

	/**
	 * And by order, both as a search and as a filter.
	 *
	 * @return void
	 */
	public function test_it_finds_the_documents_of_an_order(): void {
		$document = $this->issued( '2026-08-13' );
		$this->issued( '2026-08-13' );

		$order_id = (int) ( $document->all_order_ids()[0] ?? 0 );

		$this->assertSame(
			1,
			$this->documents->search( DocumentQuery::from_array( array( 'order_id' => (string) $order_id ) ) )['total']
		);

		$this->assertSame(
			1,
			$this->documents->search( DocumentQuery::from_array( array( 'search' => (string) $order_id ) ) )['total']
		);
	}

	/**
	 * By reason, by carrier, by state.
	 *
	 * @return void
	 */
	public function test_it_filters_by_reason_carrier_and_state(): void {
		$this->issued( '2026-08-13', Causals::SALE, 'Bartolini' );
		$this->issued( '2026-08-13', Causals::REPAIR, 'DHL' );
		$cancelled = $this->issued( '2026-08-13' );

		$this->issuer->cancel( $cancelled, 'Wrong recipient' );

		$this->assertSame(
			1,
			$this->documents->search( DocumentQuery::from_array( array( 'causal' => 'repair' ) ) )['total']
		);

		$this->assertSame(
			1,
			$this->documents->search( DocumentQuery::from_array( array( 'carrier' => 'bartol' ) ) )['total'],
			'a carrier is matched loosely: nobody types the whole name'
		);

		$this->assertSame(
			2,
			$this->documents->search( DocumentQuery::from_array( array( 'status' => 'issued' ) ) )['total']
		);

		$this->assertSame(
			1,
			$this->documents->search( DocumentQuery::from_array( array( 'status' => 'cancelled' ) ) )['total']
		);
	}

	/**
	 * A range of numbers, which is how a shop hands over a period to its
	 * accountant.
	 *
	 * @return void
	 */
	public function test_it_filters_by_a_range_of_numbers(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->issued( '2026-08-13' );
		}

		$range = $this->documents->search(
			DocumentQuery::from_array(
				array(
					'number_from' => '2',
					'number_to'   => '4',
				)
			)
		);

		$this->assertSame( 3, $range['total'] );

		$sequences = array_map(
			static fn ( Document $document ): ?int => $document->number->sequence,
			$range['items']
		);

		sort( $sequences );

		$this->assertSame( array( 2, 3, 4 ), $sequences );
	}

	/**
	 * Pages: the count is of everything that matched, not of what came back.
	 *
	 * @return void
	 */
	public function test_it_pages_without_losing_the_count(): void {
		for ( $i = 0; $i < 7; $i++ ) {
			$this->issued( '2026-08-13' );
		}

		$first = $this->documents->search(
			DocumentQuery::from_array(
				array(
					'per_page'    => '3',
					'page_number' => '1',
				)
			)
		);

		$third = $this->documents->search(
			DocumentQuery::from_array(
				array(
					'per_page'    => '3',
					'page_number' => '3',
				)
			)
		);

		$this->assertSame( 7, $first['total'] );
		$this->assertCount( 3, $first['items'] );
		$this->assertSame( 7, $third['total'] );
		$this->assertCount( 1, $third['items'] );
	}

	/**
	 * Sorting by number is not the same as sorting by date, and the register
	 * offers both.
	 *
	 * @return void
	 */
	public function test_it_can_sort_by_number(): void {
		$this->issued( '2026-08-13' );
		$this->issued( '2026-03-01' );

		$by_number = $this->documents->search(
			DocumentQuery::from_array(
				array(
					'order_by'  => 'number',
					'order_dir' => 'asc',
				)
			)
		);

		$this->assertSame( 1, $by_number['items'][0]->number->sequence );
		$this->assertSame( 2, $by_number['items'][1]->number->sequence );

		$by_date = $this->documents->search(
			DocumentQuery::from_array( array( 'order_dir' => 'asc' ) )
		);

		$this->assertSame( '2026-03-01', $by_date['items'][0]->document_date );
	}

	/**
	 * A quoted apostrophe in a customer's name is a name, not an escape.
	 *
	 * @return void
	 */
	public function test_a_search_is_not_an_injection(): void {
		$this->issued( '2026-08-13' );

		$result = $this->documents->search(
			DocumentQuery::from_array( array( 'search' => "' OR 1=1 --" ) )
		);

		$this->assertSame( 0, $result['total'], 'it matched nothing, rather than everything' );
	}

	/**
	 * Drafts are in the register too, marked as such: they are what somebody is
	 * working on, and hiding them is how two people prepare the same shipment.
	 *
	 * @return void
	 */
	public function test_drafts_are_in_the_register(): void {
		$this->documents->save( $this->drafts->draft_from_order( $this->an_order() ) );
		$this->issued( '2026-08-13' );

		$this->assertSame( 2, $this->documents->search( DocumentQuery::all() )['total'] );
		$this->assertSame(
			1,
			$this->documents->search( DocumentQuery::from_array( array( 'status' => DocumentStatus::Draft->value ) ) )['total']
		);
	}
}
