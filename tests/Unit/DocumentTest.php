<?php
/**
 * The promise the product is sold on.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\Address;
use Oxysoft\OxyDDT\Domain\Causals;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentException;
use Oxysoft\OxyDDT\Domain\DocumentNumber;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Domain\Lifecycle;
use Oxysoft\OxyDDT\Domain\Line;
use Oxysoft\OxyDDT\Domain\Parties;
use Oxysoft\OxyDDT\Domain\Party;
use Oxysoft\OxyDDT\Domain\Transport;
use PHPUnit\Framework\TestCase;

/**
 * Delivery notes: what they add up to, and what they refuse.
 */
final class DocumentTest extends TestCase {

	/**
	 * A shop that could issue.
	 *
	 * @return Company
	 */
	private function sender(): Company {
		return Company::from_array(
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
		);
	}

	/**
	 * A customer who could receive.
	 *
	 * @return Party
	 */
	private function recipient(): Party {
		return new Party(
			'Bianchi S.p.A.',
			new Address( 'Via Torino 5', '10121', 'Torino', 'TO', 'IT' )
		);
	}

	/**
	 * A draft that could be issued as it stands.
	 *
	 * @return Document
	 */
	private function draft(): Document {
		return new Document(
			0,
			DocumentStatus::Draft,
			DocumentNumber::none(),
			'2026-08-13',
			new Parties( $this->sender(), $this->recipient() ),
			new Transport(),
			Causals::SALE,
			array(
				new Line( 'Product A', 6.0, 'A-1', '', 'pz', 123, 11 ),
				new Line( 'Product B', 5.0, 'B-1', '', 'pz', 123, 12 ),
			),
			array( 123 )
		);
	}

	/**
	 * The same document, issued.
	 *
	 * @return Document
	 */
	private function issued(): Document {
		return new Document(
			7,
			DocumentStatus::Issued,
			DocumentNumber::assigned( '125/2026', '', 2026, 125 ),
			'2026-08-13',
			new Parties( $this->sender(), $this->recipient() ),
			new Transport(),
			Causals::SALE,
			array( new Line( 'Product A', 6.0, 'A-1', '', 'pz', 123, 11 ) ),
			array( 123 ),
			'',
			0,
			new Lifecycle( '2026-08-13 09:00:00', 1, '2026-08-13 09:00:00', '2026-08-13 09:05:00', 1 )
		);
	}

	/**
	 * A draft is open.
	 *
	 * @return void
	 */
	public function test_a_draft_can_be_changed(): void {
		$this->assertTrue( $this->draft()->is_editable() );
	}

	/**
	 * An issued document is not, and neither is a cancelled one.
	 *
	 * @return void
	 */
	public function test_an_issued_document_is_closed(): void {
		$this->assertFalse( $this->issued()->is_editable() );
	}

	/**
	 * The whole promise, in one assertion: an issued delivery note refuses to
	 * change its lines.
	 *
	 * @return void
	 */
	public function test_an_issued_document_refuses_new_lines(): void {
		$this->expectException( DocumentException::class );
		$this->expectExceptionMessage( '125/2026' );

		$this->issued()->with_lines( array( new Line( 'Something else', 1.0 ) ) );
	}

	/**
	 * And its header.
	 *
	 * @return void
	 */
	public function test_an_issued_document_refuses_a_new_recipient(): void {
		$this->expectException( DocumentException::class );

		$this->issued()->with_parties( new Parties( $this->sender(), new Party( 'Somebody Else' ) ) );
	}

	/**
	 * And its transport block, and its details.
	 *
	 * @return void
	 */
	public function test_an_issued_document_refuses_everything_else(): void {
		$refusals = 0;

		try {
			$this->issued()->with_transport( new Transport( Transport::BY_CARRIER, 'DHL' ) );
		} catch ( DocumentException $e ) {
			++$refusals;
		}

		try {
			$this->issued()->with_details( array( 'notes' => 'Changed my mind' ) );
		} catch ( DocumentException $e ) {
			++$refusals;
		}

		$this->assertSame( 2, $refusals );
	}

	/**
	 * Changing a draft leaves the original alone: nothing here is edited in
	 * place, which is what makes an accidental shared reference harmless.
	 *
	 * @return void
	 */
	public function test_changing_a_draft_returns_a_new_document(): void {
		$draft   = $this->draft();
		$changed = $draft->with_details( array( 'notes' => 'Fragile' ) );

		$this->assertSame( '', $draft->notes );
		$this->assertSame( 'Fragile', $changed->notes );
		$this->assertNotSame( $draft, $changed );
	}

	/**
	 * Recording that a document was stored is allowed even when it is closed:
	 * it says the document was written down, not that it changed.
	 *
	 * @return void
	 */
	public function test_an_issued_document_can_still_be_recorded_as_stored(): void {
		$stored = $this->issued()->stored( 99, new Lifecycle( '2026-08-13 09:00:00', 1, '2026-08-13 10:00:00' ) );

		$this->assertSame( 99, $stored->id );
		$this->assertSame( '2026-08-13 10:00:00', $stored->lifecycle->updated_at );
	}

	/**
	 * A line for nothing is not stored, and what remains is numbered in the
	 * order it will be printed.
	 *
	 * @return void
	 */
	public function test_empty_lines_are_dropped_and_the_rest_renumbered(): void {
		$document = $this->draft()->with_lines(
			array(
				new Line( 'Product A', 3.0, 'A-1', '', 'pz', 123, 11, 0, 0, null, 9 ),
				new Line( 'Product B', 0.0, 'B-1', '', 'pz', 123, 12 ),
				new Line( '', 4.0 ),
				new Line( 'Product C', 1.0, 'C-1', '', 'pz', 123, 13, 0, 0, null, 4 ),
			)
		);

		$this->assertCount( 2, $document->lines );
		$this->assertSame( 'Product A', $document->lines[0]->name );
		$this->assertSame( 0, $document->lines[0]->sort_order );
		$this->assertSame( 'Product C', $document->lines[1]->name );
		$this->assertSame( 1, $document->lines[1]->sort_order );
	}

	/**
	 * What the document adds up to.
	 *
	 * @return void
	 */
	public function test_it_adds_up_its_quantities(): void {
		$this->assertEqualsWithDelta( 11.0, $this->draft()->total_quantity(), Line::EPSILON );
	}

	/**
	 * The question sprint 3 is built on: how much of this order line does this
	 * document take.
	 *
	 * @return void
	 */
	public function test_it_says_how_much_of_an_order_line_it_takes(): void {
		$document = $this->draft()->with_lines(
			array(
				new Line( 'Product A', 4.0, 'A-1', '', 'pz', 123, 11 ),
				new Line( 'Product A, second pallet', 2.0, 'A-1', '', 'pz', 123, 11 ),
				new Line( 'Product B', 5.0, 'B-1', '', 'pz', 123, 12 ),
			)
		);

		$this->assertEqualsWithDelta( 6.0, $document->quantity_for( 123, 11 ), Line::EPSILON );
		$this->assertEqualsWithDelta( 5.0, $document->quantity_for( 123, 12 ), Line::EPSILON );
		$this->assertEqualsWithDelta( 0.0, $document->quantity_for( 123, 99 ), Line::EPSILON );
		$this->assertEqualsWithDelta( 0.0, $document->quantity_for( 999, 11 ), Line::EPSILON );
	}

	/**
	 * A document gathering two orders knows about both, whether it was told or
	 * worked it out from its lines.
	 *
	 * @return void
	 */
	public function test_it_knows_every_order_it_touches(): void {
		$document = new Document(
			0,
			DocumentStatus::Draft,
			DocumentNumber::none(),
			'2026-08-13',
			new Parties( $this->sender(), $this->recipient() ),
			new Transport(),
			Causals::SALE,
			array(
				new Line( 'Product A', 1.0, '', '', '', 123, 11 ),
				new Line( 'Product B', 1.0, '', '', '', 456, 21 ),
			),
			array( 123, 789 )
		);

		$this->assertSame( array( 123, 456, 789 ), $document->all_order_ids() );
	}

	/**
	 * A draft that has everything it needs.
	 *
	 * @return void
	 */
	public function test_a_complete_draft_could_be_issued(): void {
		$this->assertSame( array(), $this->draft()->errors() );
		$this->assertTrue( $this->draft()->is_ready_to_issue() );
	}

	/**
	 * A document with nothing on it is not a document.
	 *
	 * @return void
	 */
	public function test_a_document_without_lines_cannot_be_issued(): void {
		$empty = $this->draft()->with_lines( array() );

		$this->assertContains( 'lines_missing', $empty->errors() );
		$this->assertFalse( $empty->is_ready_to_issue() );
	}

	/**
	 * The reason for transport is not optional on an Italian delivery note, and
	 * neither is the date.
	 *
	 * @return void
	 */
	public function test_the_reason_and_the_date_are_required(): void {
		$errors = $this->draft()->with_details(
			array(
				'causal'        => '',
				'document_date' => null,
			)
		)->errors();

		$this->assertContains( 'causal_missing', $errors );
		$this->assertContains( 'date_missing', $errors );
	}

	/**
	 * Problems with the parties come back saying which party they belong to.
	 *
	 * @return void
	 */
	public function test_problems_with_the_parties_are_attributed(): void {
		$errors = $this->draft()->with_parties(
			new Parties( new Company(), new Party() )
		)->errors();

		$this->assertContains( 'sender.name_missing', $errors );
		$this->assertContains( 'recipient.name_missing', $errors );
	}

	/**
	 * A transport block that contradicts itself is reported under its own name.
	 *
	 * @return void
	 */
	public function test_a_contradictory_transport_block_is_reported(): void {
		$errors = $this->draft()->with_transport(
			new Transport( Transport::BY_CARRIER )
		)->errors();

		$this->assertContains( 'transport.carrier_missing', $errors );
	}
}
