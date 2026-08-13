<?php
/**
 * Taking a number, and never taking it twice.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentNumber;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Domain\FulfilmentStatus;
use Oxysoft\OxyDDT\Domain\NumberFormat;
use Oxysoft\OxyDDT\Domain\NumberingPolicy;
use Oxysoft\OxyDDT\Infrastructure\SystemClock;
use Oxysoft\OxyDDT\Issuing\IssueException;
use Oxysoft\OxyDDT\Issuing\Issuer;
use Oxysoft\OxyDDT\Persistence\DocumentRepository;
use Oxysoft\OxyDDT\Persistence\SequenceRepository;
use Oxysoft\OxyDDT\Settings\Settings;
use Oxysoft\OxyDDT\WooCommerce\DocumentFactory;
use Oxysoft\OxyDDT\WooCommerce\OrderFulfilment;
use WC_Order;
use WP_UnitTestCase;

/**
 * The numbering, the emission and the cancellation, against a real database.
 *
 * The specification is blunt about what must never happen here, and so is this
 * file: two documents numbered 125.
 */
final class IssuingTest extends WP_UnitTestCase {

	use ShopFixtures;

	/**
	 * The store.
	 *
	 * @var DocumentRepository
	 */
	private DocumentRepository $documents;

	/**
	 * The counter.
	 *
	 * @var SequenceRepository
	 */
	private SequenceRepository $sequences;

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
	 * The settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Configure a shop that could issue.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$clock = new SystemClock();

		$this->settings = new Settings();
		$this->settings->update_company(
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
		$this->sequences = new SequenceRepository( $clock );
		$this->drafts    = new DocumentFactory( $this->settings, $clock );
		// The real register, not a stand-in: writing to it is part of issuing, and
		// a log that has never been written to in a test is a log nobody has
		// proved works.
		$this->issuer = new Issuer( $this->documents, $this->sequences, $this->settings, $clock, new AuditLog( $clock ) );
	}

	/**
	 * A saved draft for an order, dated whenever we say.
	 *
	 * @param string|null $date The document date.
	 * @return Document
	 */
	private function draft( ?string $date = null ): Document {
		$order = $this->an_order( array( 'Product A' => 10.0 ) );
		$draft = $this->drafts->draft_from_order( $order );

		if ( null !== $date ) {
			$draft = $draft->with_details( array( 'document_date' => $date ) );
		}

		return $this->documents->save( $draft );
	}

	/**
	 * The order behind a draft.
	 *
	 * @param Document $document The document.
	 * @return WC_Order
	 */
	private function order_of( Document $document ): WC_Order {
		$ids   = $document->all_order_ids();
		$order = wc_get_order( (int) ( $ids[0] ?? 0 ) );

		$this->assertInstanceOf( WC_Order::class, $order );

		return $order;
	}

	/**
	 * The first document of the year is number one, and it says so.
	 *
	 * @return void
	 */
	public function test_the_first_document_is_number_one(): void {
		$issued = $this->issuer->issue( $this->draft( '2026-08-13' ) );

		$this->assertSame( DocumentStatus::Issued, $issued->status );
		$this->assertTrue( $issued->number->is_assigned() );
		$this->assertSame( 1, $issued->number->sequence );
		$this->assertSame( 2026, $issued->number->year );
		$this->assertSame( '1/2026', $issued->number->formatted );
		$this->assertNotNull( $issued->lifecycle->issued_at );
	}

	/**
	 * And the next one is two. Every time.
	 *
	 * @return void
	 */
	public function test_numbers_go_up_one_at_a_time(): void {
		$first  = $this->issuer->issue( $this->draft( '2026-08-13' ) );
		$second = $this->issuer->issue( $this->draft( '2026-08-13' ) );
		$third  = $this->issuer->issue( $this->draft( '2026-08-13' ) );

		$this->assertSame( array( 1, 2, 3 ), array( $first->number->sequence, $second->number->sequence, $third->number->sequence ) );
	}

	/**
	 * A hundred allocations, a hundred different numbers, with no gaps.
	 *
	 * This is the counter on its own, without the rest of the plugin: if it can
	 * ever hand the same number out twice, everything above it is wrong too.
	 *
	 * @return void
	 */
	public function test_the_counter_never_repeats_itself(): void {
		$taken = array();

		for ( $i = 0; $i < 100; $i++ ) {
			$taken[] = $this->sequences->allocate( '', 2026, 1 );
		}

		$this->assertCount( 100, array_unique( $taken ) );
		$this->assertSame( range( 1, 100 ), $taken );
		$this->assertSame( 101, $this->sequences->peek( '', 2026 ) );
	}

	/**
	 * **The scenario the specification names.** Somebody else takes the number
	 * between our allocation and our write — which is what a real race looks
	 * like from inside one request. The database refuses the duplicate and the
	 * issuer takes the next number instead.
	 *
	 * Result: 125 and 126. Never 125 twice.
	 *
	 * @return void
	 */
	public function test_a_number_taken_by_somebody_else_is_worked_around(): void {
		global $wpdb;

		$this->sequences->set_next( '', 2026, 125 );

		// The other request, arriving first and writing document 125.
		$theirs = $this->documents->save(
			new Document(
				0,
				DocumentStatus::Issued,
				DocumentNumber::assigned( '125/2026', '', 2026, 125 ),
				'2026-08-13',
				$this->draft( '2026-08-13' )->parties,
				$this->draft( '2026-08-13' )->transport,
				'sale',
				$this->draft( '2026-08-13' )->lines,
				array()
			)
		);

		$previous = $wpdb->suppress_errors( true );

		try {
			$ours = $this->issuer->issue( $this->draft( '2026-08-13' ) );
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		$this->assertSame( 125, $theirs->number->sequence );
		$this->assertSame( 126, $ours->number->sequence );
		$this->assertNotSame( $theirs->number->formatted, $ours->number->formatted );
	}

	/**
	 * A shop coming from another system starts where it left off.
	 *
	 * @return void
	 */
	public function test_a_shop_can_start_at_its_own_number(): void {
		$this->settings->update_numbering( new NumberingPolicy( '', 348 ) );

		$issued = $this->issuer->issue( $this->draft( '2026-08-13' ) );

		$this->assertSame( 348, $issued->number->sequence );
		$this->assertSame( '348/2026', $issued->number->formatted );
	}

	/**
	 * The shape of the number is the shop's to choose.
	 *
	 * @return void
	 */
	public function test_the_number_is_written_the_shops_way(): void {
		$this->settings->update_numbering(
			new NumberingPolicy( 'A', 1, true, new NumberFormat( '{series}-{year}-{number}', 5 ) )
		);

		$issued = $this->issuer->issue( $this->draft( '2026-08-13' ) );

		$this->assertSame( 'A-2026-00001', $issued->number->formatted );
		$this->assertSame( 'A', $issued->number->series );
	}

	/**
	 * A note dated the 31st of December, issued in January, counts against the
	 * old year — and the new year starts again at one.
	 *
	 * @return void
	 */
	public function test_the_year_of_the_document_decides_the_sequence(): void {
		$old = $this->issuer->issue( $this->draft( '2025-12-31' ) );
		$new = $this->issuer->issue( $this->draft( '2026-01-02' ) );

		$this->assertSame( 1, $old->number->sequence );
		$this->assertSame( 2025, $old->number->year );
		$this->assertSame( '1/2025', $old->number->formatted );

		$this->assertSame( 1, $new->number->sequence, 'the new year starts again' );
		$this->assertSame( '1/2026', $new->number->formatted );
	}

	/**
	 * Unless the shop said its numbers never reset.
	 *
	 * @return void
	 */
	public function test_a_continuous_sequence_carries_across_the_new_year(): void {
		$this->settings->update_numbering( new NumberingPolicy( '', 1, false ) );

		$old = $this->issuer->issue( $this->draft( '2025-12-31' ) );
		$new = $this->issuer->issue( $this->draft( '2026-01-02' ) );

		$this->assertSame( 1, $old->number->sequence );
		$this->assertSame( 2, $new->number->sequence );
		$this->assertSame( '2/2026', $new->number->formatted, 'the year printed is still the document own' );
	}

	/**
	 * An issued document is closed. The model refuses to change it, and so does
	 * the screen that would have to ask.
	 *
	 * @return void
	 */
	public function test_an_issued_document_cannot_be_issued_again(): void {
		$issued = $this->issuer->issue( $this->draft( '2026-08-13' ) );

		$this->expectException( IssueException::class );

		$this->issuer->issue( $issued );
	}

	/**
	 * A draft that is not ready does not consume a number.
	 *
	 * @return void
	 */
	public function test_an_unready_draft_does_not_spend_a_number(): void {
		$draft = $this->documents->save(
			$this->drafts->draft_from_order( $this->an_order() )->with_details( array( 'causal' => '' ) )
		);

		$before = $this->sequences->peek( '', 2026, 1 );

		try {
			$this->issuer->issue( $draft );

			$this->fail( 'A draft with no reason for transport was issued.' );
		} catch ( IssueException $e ) {
			$this->assertContains( 'causal_missing', $e->codes() );
		}

		$this->assertSame( $before, $this->sequences->peek( '', 2026, 1 ), 'no number was taken' );
	}

	/**
	 * A shop that has not filled in its own details cannot issue, and is told
	 * which part is missing.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_shop_cannot_issue(): void {
		$this->settings->update_company( new Company() );

		$draft = $this->documents->save( $this->drafts->draft_from_order( $this->an_order() ) );

		$this->expectException( IssueException::class );

		$this->issuer->issue( $draft );
	}

	/**
	 * Cancelling keeps the number and says why.
	 *
	 * @return void
	 */
	public function test_cancelling_keeps_the_number_and_the_reason(): void {
		$issued    = $this->issuer->issue( $this->draft( '2026-08-13' ) );
		$cancelled = $this->issuer->cancel( $issued, 'Wrong recipient' );

		$this->assertSame( DocumentStatus::Cancelled, $cancelled->status );
		$this->assertSame( $issued->number->formatted, $cancelled->number->formatted );
		$this->assertSame( 'Wrong recipient', $cancelled->lifecycle->cancel_reason );
		$this->assertSame( $issued->lifecycle->issued_at, $cancelled->lifecycle->issued_at );

		$loaded = $this->documents->find( $issued->id );

		$this->assertInstanceOf( Document::class, $loaded );
		$this->assertSame( DocumentStatus::Cancelled, $loaded->status );
	}

	/**
	 * And gives the goods back to the order.
	 *
	 * @return void
	 */
	public function test_cancelling_gives_the_goods_back(): void {
		$draft  = $this->draft( '2026-08-13' );
		$order  = $this->order_of( $draft );
		$issued = $this->issuer->issue( $draft );

		$outstanding = new OrderFulfilment( $this->documents );

		$this->assertSame( FulfilmentStatus::Complete, $outstanding->for_order( $order )->status() );

		$this->issuer->cancel( $issued, 'Sent to the wrong address' );

		$this->assertSame( FulfilmentStatus::None, $outstanding->for_order( $order )->status() );
		$this->assertTrue( $outstanding->for_order( $order )->has_anything_available() );
	}

	/**
	 * A cancellation has to say why.
	 *
	 * @return void
	 */
	public function test_a_cancellation_has_to_say_why(): void {
		$issued = $this->issuer->issue( $this->draft( '2026-08-13' ) );

		try {
			$this->issuer->cancel( $issued, '   ' );

			$this->fail( 'A delivery note was cancelled without a reason.' );
		} catch ( IssueException $e ) {
			$this->assertContains( 'reason_missing', $e->codes() );
		}
	}

	/**
	 * A draft is deleted, not cancelled: there is no number to account for.
	 *
	 * @return void
	 */
	public function test_a_draft_cannot_be_cancelled(): void {
		$this->expectException( IssueException::class );

		$this->issuer->cancel( $this->draft( '2026-08-13' ), 'Changed my mind' );
	}

	/**
	 * A cancelled document is not cancelled twice.
	 *
	 * @return void
	 */
	public function test_a_cancelled_document_cannot_be_cancelled_again(): void {
		$cancelled = $this->issuer->cancel(
			$this->issuer->issue( $this->draft( '2026-08-13' ) ),
			'Wrong recipient'
		);

		$this->expectException( IssueException::class );

		$this->issuer->cancel( $cancelled, 'Again' );
	}

	/**
	 * Cancelling does not put the number back in the pot. The next document
	 * gets the next number, and the register keeps a void 1/2026 that says why.
	 *
	 * @return void
	 */
	public function test_a_cancelled_number_is_not_reused(): void {
		$first = $this->issuer->issue( $this->draft( '2026-08-13' ) );
		$this->issuer->cancel( $first, 'Wrong recipient' );

		$second = $this->issuer->issue( $this->draft( '2026-08-13' ) );

		$this->assertSame( 2, $second->number->sequence );
	}

	/**
	 * What the settings screen shows before anybody issues anything.
	 *
	 * @return void
	 */
	public function test_the_next_number_can_be_previewed_without_taking_it(): void {
		$before = $this->issuer->next_number_preview();

		$this->assertSame( $before, $this->issuer->next_number_preview(), 'previewing twice takes nothing' );

		$issued = $this->issuer->issue( $this->draft( gmdate( 'Y' ) . '-08-13' ) );

		$this->assertSame( $before, $issued->number->formatted );
	}
}
