<?php
/**
 * The counter, in the database.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Persistence;

use Oxysoft\OxyDDT\Domain\SequenceRepositoryInterface;
use Oxysoft\OxyDDT\Infrastructure\ClockInterface;
use Oxysoft\OxyDDT\Infrastructure\Migrator;

/*
 * The plugin's own table, one row per series and year. Prepared where it takes
 * a value; the table name is this plugin's constant behind the site prefix.
 * Nothing here may be cached — the whole file exists to read a number that
 * another request may have changed a microsecond ago.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Hands out numbers, one at a time, and never the same one twice.
 *
 * The whole guarantee rests on three lines of SQL, so they are worth reading
 * slowly.
 *
 *     UPDATE …sequences SET next_number = LAST_INSERT_ID(next_number) + 1
 *      WHERE series = %s AND sequence_year = %d
 *
 * MySQL takes a write lock on that row for the duration of the statement, so
 * two requests arriving together are serialised by the database: one waits for
 * the other. `LAST_INSERT_ID(expr)` is the part that makes it usable — it
 * records `expr` for *this connection* and returns it, so the number this caller
 * took can be read back afterwards with `SELECT LAST_INSERT_ID()` without a
 * second look at the table, and therefore without a window in which somebody
 * else could take the same one.
 *
 * The obvious alternative — read the row, add one, write it back — has that
 * window, and it is measured in the milliseconds between two PHP statements.
 * Two people pressing Issue at the same moment is not a rare event in a
 * warehouse at five o'clock.
 *
 * This is the first of two defences. The second is the unique index on
 * (series, year, number) in the documents table, which refuses a duplicate even
 * if something here were ever wrong.
 */
final class SequenceRepository implements SequenceRepositoryInterface {

	/**
	 * The clock.
	 *
	 * @var ClockInterface
	 */
	private ClockInterface $clock;

	/**
	 * Build the store.
	 *
	 * @param ClockInterface $clock The clock.
	 */
	public function __construct( ClockInterface $clock ) {
		$this->clock = $clock;
	}

	/**
	 * Take the next number, once.
	 *
	 * @param string $series The series, empty when there is only one.
	 * @param int    $year   The year the sequence belongs to.
	 * @param int    $start  Where the sequence begins if it does not exist yet.
	 * @return int
	 *
	 * @throws StorageException If the counter could not be read or moved.
	 */
	public function allocate( string $series, int $year, int $start = 1 ): int {
		global $wpdb;

		$table = Migrator::table( Migrator::TABLE_SEQUENCES );
		$start = max( 1, $start );

		// INSERT IGNORE rather than "check then insert": two requests arriving at
		// the very first document of a year would otherwise both find nothing and
		// both insert. The primary key decides, and the loser is ignored.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (series, sequence_year, next_number, updated_at) VALUES (%s, %d, %d, %s)",
				$series,
				$year,
				$start,
				$this->clock->now()->format( 'Y-m-d H:i:s' )
			)
		);

		$moved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
					SET next_number = LAST_INSERT_ID(next_number) + 1, updated_at = %s
					WHERE series = %s AND sequence_year = %d",
				$this->clock->now()->format( 'Y-m-d H:i:s' ),
				$series,
				$year
			)
		);

		if ( false === $moved ) {
			throw new StorageException(
				sprintf( 'The delivery note counter for %d could not be moved on: %s', $year, $wpdb->last_error )
			);
		}

		$allocated = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );

		if ( $allocated <= 0 ) {
			throw new StorageException(
				sprintf( 'The delivery note counter for %d gave back no number.', $year )
			);
		}

		return $allocated;
	}

	/**
	 * What the next number would be, without taking it.
	 *
	 * @param string $series The series.
	 * @param int    $year   The year.
	 * @param int    $start  Where the sequence begins if it does not exist yet.
	 * @return int
	 */
	public function peek( string $series, int $year, int $start = 1 ): int {
		global $wpdb;

		$table = Migrator::table( Migrator::TABLE_SEQUENCES );

		$next = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT next_number FROM {$table} WHERE series = %s AND sequence_year = %d",
				$series,
				$year
			)
		);

		return null === $next ? max( 1, $start ) : (int) $next;
	}

	/**
	 * Say where the sequence goes next.
	 *
	 * @param string $series The series.
	 * @param int    $year   The year.
	 * @param int    $next   The next number to hand out.
	 * @return void
	 */
	public function set_next( string $series, int $year, int $next ): void {
		global $wpdb;

		$table = Migrator::table( Migrator::TABLE_SEQUENCES );
		$next  = max( 1, $next );
		$now   = $this->clock->now()->format( 'Y-m-d H:i:s' );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (series, sequence_year, next_number, updated_at) VALUES (%s, %d, %d, %s)
					ON DUPLICATE KEY UPDATE next_number = VALUES(next_number), updated_at = VALUES(updated_at)",
				$series,
				$year,
				$next,
				$now
			)
		);
	}
}
