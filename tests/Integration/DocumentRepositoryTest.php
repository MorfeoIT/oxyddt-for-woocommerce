<?php
/**
 * Documents, through a real database.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Domain\Address;
use Oxysoft\OxyDDT\Domain\Causals;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentNumber;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Domain\Lifecycle;
use Oxysoft\OxyDDT\Domain\Line;
use Oxysoft\OxyDDT\Domain\Parties;
use Oxysoft\OxyDDT\Domain\Party;
use Oxysoft\OxyDDT\Domain\Transport;
use Oxysoft\OxyDDT\Infrastructure\SystemClock;
use Oxysoft\OxyDDT\Persistence\DocumentRepository;
use Oxysoft\OxyDDT\Persistence\StorageException;
use WP_UnitTestCase;

/**
 * What is written is what comes back.
 */
final class DocumentRepositoryTest extends WP_UnitTestCase {

	/**
	 * The store under test.
	 *
	 * @var DocumentRepository
	 */
	private DocumentRepository $documents;

	/**
	 * Build one per test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->documents = new DocumentRepository( new SystemClock() );
	}

	/**
	 * A draft with two lines against one order.
	 *
	 * @return Document
	 */
	private function draft(): Document {
		return new Document(
			0,
			DocumentStatus::Draft,
			DocumentNumber::none(),
			'2026-08-13',
			new Parties(
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
				),
				new Party( 'Bianchi S.p.A.', new Address( 'Via Torino 5', '10121', 'Torino', 'TO', 'IT' ), '01234567897' ),
				new Address( 'Via Milano 9', '20090', 'Segrate', 'MI', 'IT' )
			),
			new Transport( Transport::BY_CARRIER, 'Bartolini', 0, Transport::CARRIAGE_PREPAID, 4, 120.5, 110.0, 'Scatole' ),
			Causals::SALE,
			array(
				new Line( 'Product A', 6.0, 'A-1', '', 'pz', 123, 11 ),
				new Line( 'Product B', 2.5, 'B-1', '', 'kg', 123, 12, 0, 0, 9.9 ),
			),
			array( 123 )
		);
	}

	/**
	 * Saving gives the document an identifier and a creation date.
	 *
	 * @return void
	 */
	public function test_a_new_document_is_given_an_identity(): void {
		$saved = $this->documents->save( $this->draft() );

		$this->assertGreaterThan( 0, $saved->id );
		$this->assertNotNull( $saved->lifecycle->created_at );
		$this->assertNotNull( $saved->lifecycle->updated_at );
	}

	/**
	 * Everything on the document comes back as it went in — including the parts
	 * that live in JSON columns, which is where a snapshot could quietly rot.
	 *
	 * @return void
	 */
	public function test_a_document_comes_back_whole(): void {
		$saved  = $this->documents->save( $this->draft() );
		$loaded = $this->documents->find( $saved->id );

		$this->assertInstanceOf( Document::class, $loaded );
		$this->assertSame( '2026-08-13', $loaded->document_date );
		$this->assertSame( Causals::SALE, $loaded->causal );
		$this->assertSame( 'Oxysoft S.r.l.', $loaded->parties->sender->name );
		$this->assertSame( 'Bianchi S.p.A.', $loaded->parties->recipient->name );
		$this->assertSame( '01234567897', $loaded->parties->recipient->vat_number );
		$this->assertSame( 'Segrate', $loaded->parties->delivery_address()->city );
		$this->assertSame( 'Bartolini', $loaded->transport->carrier_name );
		$this->assertSame( 4, $loaded->transport->packages );
		$this->assertEqualsWithDelta( 120.5, (float) $loaded->transport->weight_gross, 0.001 );
		$this->assertSame( array( 123 ), $loaded->all_order_ids() );
	}

	/**
	 * Lines keep their order, their decimals and the order line they fulfil.
	 *
	 * @return void
	 */
	public function test_lines_come_back_in_order_and_intact(): void {
		$saved  = $this->documents->save( $this->draft() );
		$loaded = $this->documents->find( $saved->id );

		$this->assertInstanceOf( Document::class, $loaded );
		$this->assertCount( 2, $loaded->lines );
		$this->assertSame( 'Product A', $loaded->lines[0]->name );
		$this->assertSame( 11, $loaded->lines[0]->order_item_id );
		$this->assertEqualsWithDelta( 2.5, $loaded->lines[1]->quantity, Line::EPSILON );
		$this->assertSame( 'kg', $loaded->lines[1]->unit );
		$this->assertEqualsWithDelta( 9.9, (float) $loaded->lines[1]->unit_price, 0.0001 );
		$this->assertNull( $loaded->lines[0]->unit_price, 'a line with no price still has none' );
	}

	/**
	 * Saving again replaces the lines rather than adding to them. Doing it wrong
	 * is how a document ends up shipping twice what it says.
	 *
	 * @return void
	 */
	public function test_saving_again_replaces_the_lines(): void {
		$saved   = $this->documents->save( $this->draft() );
		$changed = $this->documents->save( $saved->with_lines( array( new Line( 'Product A', 1.0, 'A-1', '', 'pz', 123, 11 ) ) ) );

		$loaded = $this->documents->find( $changed->id );

		$this->assertInstanceOf( Document::class, $loaded );
		$this->assertSame( $saved->id, $changed->id );
		$this->assertCount( 1, $loaded->lines );
		$this->assertEqualsWithDelta( 1.0, $loaded->total_quantity(), Line::EPSILON );
	}

	/**
	 * Every document of an order, oldest first, and the void ones only when
	 * asked for.
	 *
	 * @return void
	 */
	public function test_the_documents_of_an_order_can_be_listed(): void {
		$first  = $this->documents->save( $this->draft() );
		$second = $this->documents->save( $this->draft() );

		$this->assertGreaterThan( $first->id, $second->id );

		$found = $this->documents->for_order( 123 );

		$this->assertCount( 2, $found );
		$this->assertSame( $first->id, $found[0]->id );
		$this->assertSame( $second->id, $found[1]->id );
		$this->assertSame( array(), $this->documents->for_order( 999 ) );
	}

	/**
	 * A cancelled document can be left out, which is what sprint 3 does when it
	 * works out what is still owed.
	 *
	 * @return void
	 */
	public function test_cancelled_documents_can_be_left_out(): void {
		$this->documents->save( $this->draft() );

		$cancelled = new Document(
			0,
			DocumentStatus::Cancelled,
			DocumentNumber::assigned( '9/2026', '', 2026, 9 ),
			'2026-08-13',
			$this->draft()->parties,
			new Transport(),
			Causals::SALE,
			array( new Line( 'Product A', 1.0, 'A-1', '', 'pz', 123, 11 ) ),
			array( 123 ),
			'',
			0,
			new Lifecycle( null, 0, null, '2026-08-13 09:00:00', 1, '2026-08-13 10:00:00', 1, 'Wrong recipient' )
		);

		$this->documents->save( $cancelled );

		$this->assertCount( 2, $this->documents->for_order( 123 ) );
		$this->assertCount( 1, $this->documents->for_order( 123, false ) );
	}

	/**
	 * The database itself refuses a second document with the same number. Not
	 * the code: the database. Sprint 4 leans its whole numbering on this.
	 *
	 * @return void
	 */
	public function test_the_database_refuses_a_duplicate_number(): void {
		global $wpdb;

		$numbered = $this->draft()->with_details( array() );

		$first = new Document(
			0,
			DocumentStatus::Issued,
			DocumentNumber::assigned( '125/2026', '', 2026, 125 ),
			'2026-08-13',
			$numbered->parties,
			new Transport(),
			Causals::SALE,
			$numbered->lines,
			array( 123 )
		);

		$this->documents->save( $first );

		// The refusal comes from MySQL, and WordPress prints database errors to
		// the page while testing. Suppressed here only, and restored: a test that
		// leaves them off hides the next failure.
		$previous = $wpdb->suppress_errors( true );

		try {
			$this->documents->save( $first );

			$this->fail( 'A second document numbered 125/2026 was accepted.' );
		} catch ( StorageException $e ) {
			$this->assertStringContainsString( 'Duplicate', $e->getMessage() );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * And allows any number of drafts, because a draft has no number at all.
	 *
	 * @return void
	 */
	public function test_drafts_do_not_collide(): void {
		$this->documents->save( $this->draft() );
		$this->documents->save( $this->draft() );
		$this->documents->save( $this->draft() );

		$this->assertCount( 3, $this->documents->for_order( 123 ) );
	}

	/**
	 * A draft can be thrown away.
	 *
	 * @return void
	 */
	public function test_a_draft_can_be_deleted(): void {
		$saved = $this->documents->save( $this->draft() );

		$this->assertTrue( $this->documents->delete( $saved->id ) );
		$this->assertNull( $this->documents->find( $saved->id ) );
		$this->assertSame( array(), $this->documents->for_order( 123 ) );
	}

	/**
	 * An issued one cannot: it is cancelled, never deleted. A number that
	 * vanished is worse than a number that says why it is void.
	 *
	 * @return void
	 */
	public function test_an_issued_document_cannot_be_deleted(): void {
		$issued = new Document(
			0,
			DocumentStatus::Issued,
			DocumentNumber::assigned( '126/2026', '', 2026, 126 ),
			'2026-08-13',
			$this->draft()->parties,
			new Transport(),
			Causals::SALE,
			array( new Line( 'Product A', 1.0, 'A-1', '', 'pz', 123, 11 ) ),
			array( 123 )
		);

		$saved = $this->documents->save( $issued );

		$this->assertFalse( $this->documents->delete( $saved->id ) );
		$this->assertInstanceOf( Document::class, $this->documents->find( $saved->id ) );
	}

	/**
	 * Nothing is found where there is nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_document_is_not_found(): void {
		$this->assertNull( $this->documents->find( 987654 ) );
		$this->assertNull( $this->documents->find( 0 ) );
		$this->assertNull( $this->documents->find( -1 ) );
	}
}
