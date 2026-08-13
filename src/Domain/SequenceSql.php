<?php
/**
 * The three statements the numbering rests on.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * The SQL of the counter, written once and used from two places.
 *
 * The repository runs these through `$wpdb->prepare()`. The concurrency check in
 * `scripts/` runs the same three strings through mysqli, from a dozen processes
 * at once, and proves that no two of them ever come back with the same number.
 *
 * They live here, in a class that knows nothing about WordPress, precisely so
 * that the thing being proved is the thing being used. A check that tested its
 * own copy of the SQL would keep passing after somebody changed the real one.
 *
 * Why this shape, in one paragraph: `LAST_INSERT_ID(expr)` records `expr` for
 * the current connection and returns it, so the UPDATE both moves the counter
 * and remembers what it took — and MySQL holds a write lock on that row for the
 * duration of the statement, which is what makes two simultaneous callers take
 * turns. Reading it back with `SELECT LAST_INSERT_ID()` touches no table and so
 * cannot see anybody else's value.
 */
final class SequenceSql {

	/**
	 * Create the counter if this is the first document of the year.
	 *
	 * IGNORE rather than "check, then insert": two requests arriving together at
	 * the very first document would otherwise both find nothing and both insert.
	 * The primary key decides, and the loser is ignored rather than fatal.
	 *
	 * Placeholders: series, year, first number, timestamp.
	 *
	 * @param string $table The fully prefixed table name.
	 * @return string
	 */
	public static function create( string $table ): string {
		return "INSERT IGNORE INTO {$table} (series, sequence_year, next_number, updated_at) VALUES (%s, %d, %d, %s)";
	}

	/**
	 * Take the next number.
	 *
	 * Placeholders: timestamp, series, year.
	 *
	 * @param string $table The fully prefixed table name.
	 * @return string
	 */
	public static function allocate( string $table ): string {
		return "UPDATE {$table}
			SET next_number = LAST_INSERT_ID(next_number) + 1, updated_at = %s
			WHERE series = %s AND sequence_year = %d";
	}

	/**
	 * Read back what this connection just took.
	 *
	 * @return string
	 */
	public static function allocated(): string {
		return 'SELECT LAST_INSERT_ID()';
	}

	/**
	 * What the next number would be, without taking it.
	 *
	 * Placeholders: series, year.
	 *
	 * @param string $table The fully prefixed table name.
	 * @return string
	 */
	public static function peek( string $table ): string {
		return "SELECT next_number FROM {$table} WHERE series = %s AND sequence_year = %d";
	}

	/**
	 * Move the counter to where a shop says it should be.
	 *
	 * Placeholders: series, year, next number, timestamp.
	 *
	 * @param string $table The fully prefixed table name.
	 * @return string
	 */
	public static function set_next( string $table ): string {
		return "INSERT INTO {$table} (series, sequence_year, next_number, updated_at) VALUES (%s, %d, %d, %s)
			ON DUPLICATE KEY UPDATE next_number = VALUES(next_number), updated_at = VALUES(updated_at)";
	}
}
