<?php
/**
 * The record of what was done.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Audit;

use Oxysoft\OxyDDT\Infrastructure\ClockInterface;
use Oxysoft\OxyDDT\Infrastructure\Migrator;

/**
 * Append-only. Nothing in the plugin updates or deletes a row here.
 *
 * The log is what makes an immutable document defensible: a number was taken, a
 * document was issued, another was cancelled and somebody gave a reason. Without
 * it, "the plugin does not let you change an issued note" is a claim rather than
 * a fact anybody can check.
 *
 * It is not an error log. Nothing writes to it that a shop owner would not
 * recognise as something a person did.
 */
final class AuditLog {

	/**
	 * The settings were changed.
	 */
	public const SETTINGS_UPDATED = 'settings_updated';

	/**
	 * The plugin was installed on this site.
	 */
	public const INSTALLED = 'installed';

	/**
	 * A draft was started.
	 */
	public const DOCUMENT_CREATED = 'document_created';

	/**
	 * A draft was changed.
	 */
	public const DOCUMENT_UPDATED = 'document_updated';

	/**
	 * A draft was thrown away.
	 */
	public const DOCUMENT_DELETED = 'document_deleted';

	/**
	 * A document was issued, and a number spent.
	 */
	public const DOCUMENT_ISSUED = 'document_issued';

	/**
	 * An issued document was voided.
	 */
	public const DOCUMENT_CANCELLED = 'document_cancelled';

	/**
	 * Somebody moved the counter.
	 */
	public const SEQUENCE_CHANGED = 'sequence_changed';

	/**
	 * The clock.
	 *
	 * @var ClockInterface
	 */
	private ClockInterface $clock;

	/**
	 * Build the log.
	 *
	 * @param ClockInterface $clock The clock.
	 */
	public function __construct( ClockInterface $clock ) {
		$this->clock = $clock;
	}

	/**
	 * Write an entry.
	 *
	 * Failure is swallowed on purpose. The log exists to explain what the plugin
	 * did; it must never be the reason a shipment cannot go out. A missing table
	 * — an upgrade that has not finished, a restore that dropped it — would
	 * otherwise stop a delivery note from being issued at all.
	 *
	 * @param string               $event       One of the constants above.
	 * @param string               $message     A sentence a shop owner can read.
	 * @param array<string, mixed> $context     Anything worth keeping alongside it.
	 * @param int                  $document_id The document concerned, 0 for none.
	 * @return void
	 */
	public function record( string $event, string $message, array $context = array(), int $document_id = 0 ): void {
		global $wpdb;

		$encoded = array() === $context ? null : wp_json_encode( $context );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; there is no WordPress API for it, and an append-only log has nothing to cache.
		$wpdb->insert(
			Migrator::table( Migrator::TABLE_LOGS ),
			array(
				'created_at'  => $this->clock->now()->format( 'Y-m-d H:i:s' ),
				'user_id'     => get_current_user_id(),
				'document_id' => $document_id,
				'event'       => substr( $event, 0, 64 ),
				'message'     => $message,
				'context'     => false === $encoded ? null : $encoded,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * The most recent entries, newest first.
	 *
	 * @param int $limit      How many at most.
	 * @param int $document_id Only this document's entries, 0 for all.
	 * @return list<array<string, mixed>>
	 */
	public function recent( int $limit = 20, int $document_id = 0 ): array {
		global $wpdb;

		$table = Migrator::table( Migrator::TABLE_LOGS );
		$limit = max( 1, min( 500, $limit ) );

		// The table name cannot be a placeholder, and it is not user input: it is
		// this plugin's own constant behind the site's prefix.
		if ( $document_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE document_id = %d ORDER BY id DESC LIMIT %d",
					$document_id,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? array_values( $rows ) : array();
	}
}
