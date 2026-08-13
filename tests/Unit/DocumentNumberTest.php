<?php
/**
 * Numbers, and the absence of one.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\DocumentNumber;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use PHPUnit\Framework\TestCase;

/**
 * What a delivery note is called.
 */
final class DocumentNumberTest extends TestCase {

	/**
	 * A draft has no number, and its parts are null rather than zero. That is
	 * what lets a thousand drafts sit under one unique index.
	 *
	 * @return void
	 */
	public function test_a_draft_has_no_number(): void {
		$number = DocumentNumber::none();

		$this->assertFalse( $number->is_assigned() );
		$this->assertNull( $number->sequence );
		$this->assertNull( $number->year );
		$this->assertSame( '', $number->formatted );
	}

	/**
	 * An issued document has all three parts and something to print.
	 *
	 * @return void
	 */
	public function test_an_issued_document_has_a_number(): void {
		$number = DocumentNumber::assigned( 'A/125/2026', 'A', 2026, 125 );

		$this->assertTrue( $number->is_assigned() );
		$this->assertSame( 'A', $number->series );
		$this->assertSame( 2026, $number->year );
		$this->assertSame( 125, $number->sequence );
		$this->assertSame( 'A/125/2026', (string) $number );
	}

	/**
	 * A row with a formatted number but no sequence is not a numbered document,
	 * whatever the text column says. The sequence is the number; the rest is
	 * how it reads.
	 *
	 * @return void
	 */
	public function test_a_row_without_a_sequence_is_not_numbered(): void {
		$number = DocumentNumber::from_storage( '125/2026', '', 2026, null );

		$this->assertFalse( $number->is_assigned() );
		$this->assertSame( '', $number->formatted );
	}

	/**
	 * What comes out of the database goes back in unchanged.
	 *
	 * @return void
	 */
	public function test_it_reads_back_what_was_stored(): void {
		$number = DocumentNumber::from_storage( 'DDT-2026-00125', 'DDT', 2026, 125 );

		$this->assertTrue( $number->is_assigned() );
		$this->assertSame( 'DDT-2026-00125', $number->formatted );
	}

	/**
	 * Statuses: which of them may still be changed, which count as shipped.
	 *
	 * A cancelled document ships nothing, and that single answer is what gives
	 * an order its outstanding quantities back.
	 *
	 * @return void
	 */
	public function test_statuses_say_what_they_allow(): void {
		$this->assertTrue( DocumentStatus::Draft->is_editable() );
		$this->assertFalse( DocumentStatus::Issued->is_editable() );
		$this->assertFalse( DocumentStatus::Cancelled->is_editable() );

		$this->assertFalse( DocumentStatus::Draft->is_numbered() );
		$this->assertTrue( DocumentStatus::Issued->is_numbered() );

		$this->assertTrue( DocumentStatus::Draft->counts_as_shipped() );
		$this->assertTrue( DocumentStatus::Issued->counts_as_shipped() );
		$this->assertFalse( DocumentStatus::Cancelled->counts_as_shipped() );
	}

	/**
	 * Anything unrecognised is a draft: the status that grants nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_status_is_read_as_a_draft(): void {
		$this->assertSame( DocumentStatus::Draft, DocumentStatus::from_string( 'whatever' ) );
		$this->assertSame( DocumentStatus::Issued, DocumentStatus::from_string( 'issued' ) );
	}
}
