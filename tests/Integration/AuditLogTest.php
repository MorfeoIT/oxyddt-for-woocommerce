<?php
/**
 * The register, against a real table.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Infrastructure\SystemClock;
use WP_UnitTestCase;

/**
 * Entries go in, come back newest first, and never come out.
 */
final class AuditLogTest extends WP_UnitTestCase {

	/**
	 * The log under test.
	 *
	 * @var AuditLog
	 */
	private AuditLog $log;

	/**
	 * Build one per test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->log = new AuditLog( new SystemClock() );
	}

	/**
	 * What was written is what comes back.
	 *
	 * @return void
	 */
	public function test_an_entry_is_written_and_read(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->log->record( AuditLog::SETTINGS_UPDATED, 'The sender was changed.', array( 'field' => 'vat_number' ) );

		$entries = $this->log->recent( 5 );

		$this->assertNotEmpty( $entries );
		$this->assertSame( AuditLog::SETTINGS_UPDATED, $entries[0]['event'] );
		$this->assertSame( 'The sender was changed.', $entries[0]['message'] );
		$this->assertSame( $user_id, (int) $entries[0]['user_id'] );
		$this->assertSame( array( 'field' => 'vat_number' ), json_decode( (string) $entries[0]['context'], true ) );
	}

	/**
	 * Newest first, because that is what anybody opening a log wants.
	 *
	 * @return void
	 */
	public function test_entries_come_back_newest_first(): void {
		$this->log->record( 'first', 'One.' );
		$this->log->record( 'second', 'Two.' );

		$entries = $this->log->recent( 5 );

		$this->assertSame( 'second', $entries[0]['event'] );
		$this->assertSame( 'first', $entries[1]['event'] );
	}

	/**
	 * A document's own history is its own.
	 *
	 * @return void
	 */
	public function test_entries_can_be_read_for_one_document(): void {
		$this->log->record( 'issued', 'Issued 1/2026.', array(), 11 );
		$this->log->record( 'issued', 'Issued 2/2026.', array(), 22 );

		$entries = $this->log->recent( 5, 22 );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'Issued 2/2026.', $entries[0]['message'] );
	}

	/**
	 * An entry with no context stores none, rather than an empty object.
	 *
	 * @return void
	 */
	public function test_an_entry_without_context_stores_null(): void {
		$this->log->record( 'plain', 'Nothing to add.' );

		$this->assertNull( $this->log->recent( 1 )[0]['context'] );
	}

	/**
	 * However much is asked for, the log does not hand back the whole table.
	 *
	 * @return void
	 */
	public function test_the_number_of_entries_is_bounded(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->log->record( 'many', 'Entry.' );
		}

		$this->assertCount( 2, $this->log->recent( 2 ) );
		$this->assertLessThanOrEqual( 500, count( $this->log->recent( 100000 ) ) );
	}
}
