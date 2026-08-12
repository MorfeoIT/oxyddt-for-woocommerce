<?php
/**
 * Database schema, and how it moves forward.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Infrastructure;

/**
 * Creates and upgrades the tables OxyDDT owns.
 *
 * Migrations are numbered, ordered and idempotent. They run at activation and,
 * as a safety net, on the first request after an upgrade that raised the schema
 * version, because a plugin updated over FTP or by WP-CLI never fires an
 * activation hook.
 *
 * Delivery notes will live in tables of their own, never in post meta. A shop
 * with four years of shipments has hundreds of thousands of document lines, and
 * "which lines of this order have already gone out" is a question a meta table
 * cannot be asked without reading all of them.
 */
final class Migrator {

	/**
	 * The option holding the schema version applied to this site.
	 */
	public const VERSION_OPTION = 'oxyddt_db_version';

	/**
	 * The schema version this code expects.
	 */
	public const TARGET_VERSION = 1;

	/**
	 * The audit log table, without the WordPress prefix.
	 */
	public const TABLE_LOGS = 'oxyddt_logs';

	/**
	 * Whether the stored schema is behind the code.
	 *
	 * @return bool
	 */
	public function needs_migration(): bool {
		return $this->current_version() < self::TARGET_VERSION;
	}

	/**
	 * The schema version currently applied.
	 *
	 * @return int
	 */
	public function current_version(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Bring the schema up to date.
	 *
	 * Each step records its own version, so a run interrupted halfway resumes
	 * from where it stopped rather than starting again.
	 *
	 * @return void
	 */
	public function migrate(): void {
		$from = $this->current_version();

		foreach ( $this->migrations() as $version => $migration ) {
			if ( $version <= $from ) {
				continue;
			}

			$migration();

			update_option( self::VERSION_OPTION, $version, false );
		}
	}

	/**
	 * The full name of one of OxyDDT's tables.
	 *
	 * @param string $table Table name without the WordPress prefix.
	 * @return string
	 */
	public static function table( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . $table;
	}

	/**
	 * Every table the plugin owns, fully prefixed.
	 *
	 * @return list<string>
	 */
	public static function tables(): array {
		return array( self::table( self::TABLE_LOGS ) );
	}

	/**
	 * The migrations, in order.
	 *
	 * @return array<int, callable(): void>
	 */
	private function migrations(): array {
		// Closures, not [$this, 'method'] pairs: the callable-array form resolves
		// its scope at call time, which makes a private target work here and fail
		// the moment anything else invokes the same array.
		return array(
			1 => function (): void {
				$this->create_logs_table();
			},
		);
	}

	/**
	 * What happened, and who did it.
	 *
	 * The first table the plugin creates, before any document exists, because the
	 * log has to be able to record the settings change that comes before the
	 * first delivery note. A document that can be cancelled and a number that can
	 * never be reused are only defensible if there is a record of who did what,
	 * and when.
	 *
	 * There is deliberately no IP address column. It would be personal data kept
	 * for years, on a document nobody consults for that purpose, and the user and
	 * the timestamp already answer every question this log exists to answer.
	 *
	 * @return void
	 */
	private function create_logs_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table( self::TABLE_LOGS );
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			document_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event varchar(64) NOT NULL DEFAULT '',
			message text NOT NULL,
			context longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY document (document_id,id),
			KEY event (event,created_at),
			KEY created (created_at)
		) {$collate};";

		dbDelta( $sql );
	}
}
