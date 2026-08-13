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
	public const TARGET_VERSION = 3;

	/**
	 * The audit log table, without the WordPress prefix.
	 */
	public const TABLE_LOGS = 'oxyddt_logs';

	/**
	 * The delivery notes themselves, without the WordPress prefix.
	 */
	public const TABLE_DOCUMENTS = 'oxyddt_documents';

	/**
	 * The lines of those documents, without the WordPress prefix.
	 */
	public const TABLE_ITEMS = 'oxyddt_items';

	/**
	 * Which documents draw on which orders, without the WordPress prefix.
	 */
	public const TABLE_ORDERS = 'oxyddt_orders';

	/**
	 * The numbering counters, without the WordPress prefix.
	 */
	public const TABLE_SEQUENCES = 'oxyddt_sequences';

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
	 * Children before parents: nothing here declares a foreign key, but the day
	 * something does, an uninstall that drops in this order still works.
	 *
	 * @return list<string>
	 */
	public static function tables(): array {
		return array(
			self::table( self::TABLE_ITEMS ),
			self::table( self::TABLE_ORDERS ),
			self::table( self::TABLE_DOCUMENTS ),
			self::table( self::TABLE_SEQUENCES ),
			self::table( self::TABLE_LOGS ),
		);
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
			2 => function (): void {
				$this->create_documents_table();
				$this->create_items_table();
				$this->create_orders_table();
			},
			3 => function (): void {
				$this->create_sequences_table();
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

	/**
	 * The delivery notes.
	 *
	 * Two things in here are load-bearing.
	 *
	 * The **unique key on (series, sequence_year, sequence_number)** is what makes
	 * "never two documents with the same number" a fact about the database rather
	 * than a hope about the code. Two people pressing Issue in the same second
	 * cannot both win, whatever PHP does. It works because the sequence columns
	 * are nullable and MySQL treats each NULL as distinct: a shop can hold a
	 * thousand unnumbered drafts under that same index.
	 *
	 * The **snapshot columns** hold the sender, the recipient and the destination
	 * as they read on the day of issue, as JSON. They are not normalised on
	 * purpose. A customer who moves house, or an order somebody edits next week,
	 * must not change a document that has already been handed to a driver, and a
	 * foreign key to a customer record is exactly the thing that would.
	 *
	 * @return void
	 */
	private function create_documents_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table( self::TABLE_DOCUMENTS );
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(20) NOT NULL DEFAULT 'draft',
			number varchar(64) NOT NULL DEFAULT '',
			series varchar(20) NOT NULL DEFAULT '',
			sequence_year smallint(5) unsigned DEFAULT NULL,
			sequence_number int(10) unsigned DEFAULT NULL,
			document_date date DEFAULT NULL,
			causal varchar(64) NOT NULL DEFAULT '',
			transport_by varchar(20) NOT NULL DEFAULT '',
			carrier_name varchar(191) NOT NULL DEFAULT '',
			carrier_id bigint(20) unsigned NOT NULL DEFAULT 0,
			carriage varchar(20) NOT NULL DEFAULT '',
			packages int(10) unsigned NOT NULL DEFAULT 0,
			weight_gross decimal(12,3) DEFAULT NULL,
			weight_net decimal(12,3) DEFAULT NULL,
			goods_appearance varchar(191) NOT NULL DEFAULT '',
			transport_started_at datetime DEFAULT NULL,
			notes text NULL,
			sender longtext NULL,
			recipient longtext NULL,
			destination longtext NULL,
			recipient_name varchar(191) NOT NULL DEFAULT '',
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime DEFAULT NULL,
			issued_at datetime DEFAULT NULL,
			issued_by bigint(20) unsigned NOT NULL DEFAULT 0,
			cancelled_at datetime DEFAULT NULL,
			cancelled_by bigint(20) unsigned NOT NULL DEFAULT 0,
			cancel_reason text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY document_number (series,sequence_year,sequence_number),
			KEY status (status,document_date),
			KEY customer (customer_id),
			KEY issued (issued_at),
			KEY recipient_name (recipient_name)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * The lines of those documents.
	 *
	 * `order_id` and `order_item_id` are indexed together because of the one
	 * question the product exists to answer: how much of this order line has
	 * already gone out. Sprint 3 sums this column across every document of an
	 * order, and it has to stay cheap on a shop with four years of shipments.
	 *
	 * Quantities are decimal, not integer: shops sell metres, kilograms and
	 * litres, and a plugin that stores 2.5 as 2 loses a customer's goods.
	 *
	 * @return void
	 */
	private function create_items_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table( self::TABLE_ITEMS );
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			document_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sku varchar(100) NOT NULL DEFAULT '',
			code varchar(100) NOT NULL DEFAULT '',
			name text NOT NULL,
			quantity decimal(12,3) NOT NULL DEFAULT 0.000,
			unit varchar(20) NOT NULL DEFAULT '',
			unit_price decimal(19,6) DEFAULT NULL,
			sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY document (document_id,sort_order),
			KEY order_line (order_id,order_item_id),
			KEY product (product_id)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * The counters.
	 *
	 * One row per series and year, holding the next number to hand out. Tiny,
	 * and the most carefully written table in the plugin: everything about
	 * numbering being safe comes down to two people updating this row and MySQL
	 * making one of them wait.
	 *
	 * The primary key is (series, year), so a shop that resets in January gets a
	 * new row and last year's stays where it was — which is what makes reopening
	 * an old year to correct something possible at all.
	 *
	 * @return void
	 */
	private function create_sequences_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table( self::TABLE_SEQUENCES );
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			series varchar(20) NOT NULL DEFAULT '',
			sequence_year smallint(5) unsigned NOT NULL,
			next_number int(10) unsigned NOT NULL DEFAULT 1,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (series,sequence_year)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Which documents draw on which orders.
	 *
	 * A table rather than a column because the relationship runs both ways and
	 * neither side is single: an order has several delivery notes over the weeks
	 * it takes to fulfil, and one note can gather several orders for the same
	 * customer — which is the PRO feature this schema is already shaped for.
	 *
	 * @return void
	 */
	private function create_orders_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table( self::TABLE_ORDERS );
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			document_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (document_id,order_id),
			KEY order_id (order_id)
		) {$collate};";

		dbDelta( $sql );
	}
}
