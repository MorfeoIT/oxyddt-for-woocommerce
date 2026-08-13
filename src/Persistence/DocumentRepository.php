<?php
/**
 * Documents, in the database.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Persistence;

use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentNumber;
use Oxysoft\OxyDDT\Domain\DocumentQuery;
use Oxysoft\OxyDDT\Domain\DocumentRepositoryInterface;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Domain\Lifecycle;
use Oxysoft\OxyDDT\Domain\Line;
use Oxysoft\OxyDDT\Domain\Parties;
use Oxysoft\OxyDDT\Domain\Transport;
use Oxysoft\OxyDDT\Infrastructure\ClockInterface;
use Oxysoft\OxyDDT\Infrastructure\Migrator;

/*
 * Three tables the plugin owns, and no WordPress API that knows about them.
 * Every query below is prepared where it takes a value; the table names are
 * constants of this plugin behind the site's own prefix, and cannot be
 * placeholders. Caching is deliberately absent: these rows are read when
 * somebody opens a document and written when somebody saves one, and a stale
 * delivery note is not a performance problem worth having.
 *
 * The register's query is built up from pieces because its filters are optional:
 * a WHERE with nine conditions written out nine times would be nine places to
 * make the same mistake. Every *value* still goes through a placeholder — what
 * is concatenated is SQL written here, in this file, and never anything from a
 * request. The sniff cannot follow a variable into prepare() and says so; the
 * rule it is protecting is kept by hand and is worth reading the search() method
 * to confirm.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

/*
 * The exceptions here carry a document identifier and the database's own error
 * text, and they are read by whoever is looking at a log, never printed to a
 * shop's customers. Escaping them would put HTML entities in a stack trace.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Reads and writes delivery notes.
 *
 * The lines and the order links are rewritten wholesale on every save rather
 * than diffed. A document has a handful of lines, the write happens when a
 * person presses a button, and a diff is three more ways for the stored document
 * to stop matching the one on screen.
 */
final class DocumentRepository implements DocumentRepositoryInterface {

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
	 * Write a document, and give it back with its identifier and timestamps.
	 *
	 * @param Document $document The document.
	 * @return Document
	 *
	 * @throws StorageException If the row could not be written.
	 */
	public function save( Document $document ): Document {
		global $wpdb;

		$now       = $this->clock->now()->format( 'Y-m-d H:i:s' );
		$lifecycle = $document->lifecycle->touched( $now, get_current_user_id() );
		$table     = Migrator::table( Migrator::TABLE_DOCUMENTS );

		$data    = $this->row_for( $document, $lifecycle );
		$formats = $this->formats_for( $data );

		if ( $document->id > 0 ) {
			$written = $wpdb->update( $table, $data, array( 'id' => $document->id ), $formats, array( '%d' ) );

			if ( false === $written ) {
				throw new StorageException(
					sprintf( 'Delivery note %d could not be saved: %s', $document->id, $wpdb->last_error )
				);
			}

			$id = $document->id;
		} else {
			$written = $wpdb->insert( $table, $data, $formats );

			if ( false === $written ) {
				throw new StorageException(
					sprintf( 'A new delivery note could not be saved: %s', $wpdb->last_error )
				);
			}

			$id = (int) $wpdb->insert_id;
		}

		$this->write_lines( $id, $document->lines );
		$this->write_order_links( $id, $document->all_order_ids() );

		return $document->stored( $id, $lifecycle );
	}

	/**
	 * One document.
	 *
	 * @param int $id Row identifier.
	 * @return Document|null
	 */
	public function find( int $id ): ?Document {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = Migrator::table( Migrator::TABLE_DOCUMENTS );

		/**
		 * The row, or null.
		 *
		 * @var array<string, mixed>|null $row
		 */
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$lines = $this->lines_for( array( $id ) );
		$links = $this->order_links_for( array( $id ) );

		return $this->from_row( $row, $lines[ $id ] ?? array(), $links[ $id ] ?? array() );
	}

	/**
	 * Every document that draws on an order, oldest first.
	 *
	 * @param int  $order_id          The order.
	 * @param bool $include_cancelled Whether void documents come back too.
	 * @return list<Document>
	 */
	public function for_order( int $order_id, bool $include_cancelled = true ): array {
		global $wpdb;

		if ( $order_id <= 0 ) {
			return array();
		}

		$documents = Migrator::table( Migrator::TABLE_DOCUMENTS );
		$links     = Migrator::table( Migrator::TABLE_ORDERS );

		// Two whole queries rather than one built up in pieces. A SQL string
		// assembled from a variable is a string neither a reader nor the coding
		// standard can check, and this is the one query in the plugin that runs
		// on somebody else's shop with somebody else's data.
		if ( $include_cancelled ) {
			/**
			 * The rows.
			 *
			 * @var list<array<string, mixed>> $rows
			 */
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT d.* FROM {$documents} d
					INNER JOIN {$links} l ON l.document_id = d.id
					WHERE l.order_id = %d
					ORDER BY d.id ASC",
					$order_id
				),
				ARRAY_A
			);
		} else {
			/**
			 * The rows.
			 *
			 * @var list<array<string, mixed>> $rows
			 */
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT d.* FROM {$documents} d
					INNER JOIN {$links} l ON l.document_id = d.id
					WHERE l.order_id = %d AND d.status <> %s
					ORDER BY d.id ASC",
					$order_id,
					DocumentStatus::Cancelled->value
				),
				ARRAY_A
			);
		}

		if ( array() === $rows ) {
			return array();
		}

		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = isset( $row['id'] ) ? (int) $row['id'] : 0;
		}

		// One query for every document's lines, and one for their orders, rather
		// than two per document. An order that has been shipped in fifteen parts
		// is not unusual, and the box on the order screen reads all of them.
		$lines = $this->lines_for( $ids );
		$owned = $this->order_links_for( $ids );

		$documents_found = array();

		foreach ( $rows as $row ) {
			$id                = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$documents_found[] = $this->from_row( $row, $lines[ $id ] ?? array(), $owned[ $id ] ?? array() );
		}

		return $documents_found;
	}

	/**
	 * The register: documents matching a query, and how many there are in all.
	 *
	 * Every value goes in through a placeholder. The pieces of SQL around them
	 * are literals written here — never anything from a request — which is what
	 * makes a query built up in parts safe, and why the sniff is turned off at
	 * the top of this file rather than argued with here.
	 *
	 * @param DocumentQuery $query What is being looked for.
	 * @return array{items: list<Document>, total: int}
	 */
	public function search( DocumentQuery $query ): array {
		global $wpdb;

		$documents = Migrator::table( Migrator::TABLE_DOCUMENTS );
		$links     = Migrator::table( Migrator::TABLE_ORDERS );

		$where = array( '1=1' );
		$args  = array();

		if ( '' !== $query->search ) {
			// Number, customer or order: the three things somebody has in their
			// hand when they come looking. A wildcard on both sides because "125"
			// has to find "A/125/2026".
			$like = '%' . $wpdb->esc_like( $query->search ) . '%';

			$where[] = '( number LIKE %s OR recipient_name LIKE %s OR id IN ( SELECT document_id FROM ' . $links . ' WHERE order_id = %d ) )';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = (int) $query->search;
		}

		if ( null !== $query->year ) {
			$where[] = 'YEAR( document_date ) = %d';
			$args[]  = $query->year;
		}

		if ( null !== $query->month ) {
			$where[] = 'MONTH( document_date ) = %d';
			$args[]  = $query->month;
		}

		if ( $query->order_id > 0 ) {
			$where[] = 'id IN ( SELECT document_id FROM ' . $links . ' WHERE order_id = %d )';
			$args[]  = $query->order_id;
		}

		if ( $query->customer_id > 0 ) {
			$where[] = 'customer_id = %d';
			$args[]  = $query->customer_id;
		}

		if ( '' !== $query->causal ) {
			$where[] = 'causal = %s';
			$args[]  = $query->causal;
		}

		if ( '' !== $query->carrier ) {
			$where[] = 'carrier_name LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $query->carrier ) . '%';
		}

		if ( null !== $query->status ) {
			$where[] = 'status = %s';
			$args[]  = $query->status->value;
		}

		if ( null !== $query->number_from ) {
			$where[] = 'sequence_number >= %d';
			$args[]  = $query->number_from;
		}

		if ( null !== $query->number_to ) {
			$where[] = 'sequence_number <= %d';
			$args[]  = $query->number_to;
		}

		$conditions = implode( ' AND ', $where );

		$order = DocumentQuery::BY_NUMBER === $query->order_by
			? 'sequence_year %1$s, sequence_number %1$s, id %1$s'
			: 'document_date %1$s, id %1$s';

		// The direction is one of two literals chosen here, never a value from a
		// request: DocumentQuery only ever answers ascending or not.
		$order = sprintf( $order, $query->ascending ? 'ASC' : 'DESC' );

		$total = (int) $wpdb->get_var(
			array() === $args
				? "SELECT COUNT(*) FROM {$documents} WHERE {$conditions}"
				: $wpdb->prepare( "SELECT COUNT(*) FROM {$documents} WHERE {$conditions}", $args )
		);

		$sql = "SELECT * FROM {$documents} WHERE {$conditions} ORDER BY {$order} LIMIT %d OFFSET %d";

		/**
		 * The rows.
		 *
		 * @var list<array<string, mixed>> $rows
		 */
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( $sql, array_merge( $args, array( $query->per_page, $query->offset() ) ) ),
			ARRAY_A
		);

		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = isset( $row['id'] ) ? (int) $row['id'] : 0;
		}

		$lines = $this->lines_for( $ids );
		$owned = $this->order_links_for( $ids );

		$items = array();

		foreach ( $rows as $row ) {
			$id      = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$items[] = $this->from_row( $row, $lines[ $id ] ?? array(), $owned[ $id ] ?? array() );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Throw away a draft.
	 *
	 * @param int $id Row identifier.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$document = $this->find( $id );

		if ( null === $document || ! $document->is_editable() ) {
			return false;
		}

		$wpdb->delete( Migrator::table( Migrator::TABLE_ITEMS ), array( 'document_id' => $id ), array( '%d' ) );
		$wpdb->delete( Migrator::table( Migrator::TABLE_ORDERS ), array( 'document_id' => $id ), array( '%d' ) );

		return (bool) $wpdb->delete( Migrator::table( Migrator::TABLE_DOCUMENTS ), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * The archived PDF of a document, if it has one.
	 *
	 * Kept off the Document model on purpose: a delivery note is what it says,
	 * not where a file happens to sit on this server. Moving a site to another
	 * host changes the second and must not be able to change the first.
	 *
	 * @param int $id Row identifier.
	 * @return array{path: string, hash: string}|null
	 */
	public function pdf_of( int $id ): ?array {
		global $wpdb;

		$table = Migrator::table( Migrator::TABLE_DOCUMENTS );

		/**
		 * The row, or null.
		 *
		 * @var array<string, mixed>|null $row
		 */
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT pdf_path, pdf_hash FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) || '' === (string) ( $row['pdf_path'] ?? '' ) ) {
			return null;
		}

		return array(
			'path' => (string) $row['pdf_path'],
			'hash' => (string) ( $row['pdf_hash'] ?? '' ),
		);
	}

	/**
	 * Record where a document's PDF was archived, and what it was.
	 *
	 * @param int    $id   Row identifier.
	 * @param string $path Path relative to the uploads directory.
	 * @param string $hash SHA-256 of the file as it was written.
	 * @return void
	 */
	public function set_pdf( int $id, string $path, string $hash ): void {
		global $wpdb;

		$wpdb->update(
			Migrator::table( Migrator::TABLE_DOCUMENTS ),
			array(
				'pdf_path' => $path,
				'pdf_hash' => $hash,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * The columns of a document row.
	 *
	 * @param Document  $document  The document.
	 * @param Lifecycle $lifecycle Its timestamps, already touched.
	 * @return array<string, mixed>
	 */
	private function row_for( Document $document, Lifecycle $lifecycle ): array {
		$parties   = $document->parties;
		$transport = $document->transport;

		return array(
			'status'               => $document->status->value,
			'number'               => $document->number->formatted,
			'series'               => $document->number->series,
			'sequence_year'        => $document->number->year,
			'sequence_number'      => $document->number->sequence,
			'document_date'        => $document->document_date,
			'causal'               => $document->causal,
			'transport_by'         => $transport->by,
			'carrier_name'         => $transport->carrier_name,
			'carrier_id'           => $transport->carrier_id,
			'carriage'             => $transport->carriage,
			'packages'             => $transport->packages,
			'weight_gross'         => $transport->weight_gross,
			'weight_net'           => $transport->weight_net,
			'goods_appearance'     => $transport->goods_appearance,
			'transport_started_at' => $transport->started_at,
			'notes'                => $document->notes,
			'sender'               => (string) wp_json_encode( $parties->sender->to_array() ),
			'recipient'            => (string) wp_json_encode( $parties->recipient->to_array() ),
			'destination'          => null === $parties->destination
				? null
				: (string) wp_json_encode( $parties->destination->to_array() ),
			// Denormalised on purpose: the register is searched and sorted by
			// customer name, and digging it out of a JSON column would mean
			// reading every row to answer.
			'recipient_name'       => $parties->recipient->name,
			'customer_id'          => $document->customer_id,
			'created_at'           => $lifecycle->created_at,
			'created_by'           => $lifecycle->created_by,
			'updated_at'           => $lifecycle->updated_at,
			'issued_at'            => $lifecycle->issued_at,
			'issued_by'            => $lifecycle->issued_by,
			'cancelled_at'         => $lifecycle->cancelled_at,
			'cancelled_by'         => $lifecycle->cancelled_by,
			'cancel_reason'        => $lifecycle->cancel_reason,
		);
	}

	/**
	 * The placeholder for each column.
	 *
	 * Everything is a string except the counters and the identifiers. Dates and
	 * decimals go in as strings on purpose: %f would turn a null weight into 0.0
	 * and a shop would find every document claiming to weigh nothing.
	 *
	 * @param array<string, mixed> $data The row.
	 * @return list<string>
	 */
	private function formats_for( array $data ): array {
		$integers = array(
			'carrier_id',
			'packages',
			'customer_id',
			'created_by',
			'issued_by',
			'cancelled_by',
			'sequence_year',
			'sequence_number',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $column ) {
			$formats[] = in_array( $column, $integers, true ) ? '%d' : '%s';
		}

		return $formats;
	}

	/**
	 * Replace a document's lines.
	 *
	 * @param int        $document_id The document.
	 * @param list<Line> $lines       Its lines.
	 * @return void
	 *
	 * @throws StorageException If a line could not be written.
	 */
	private function write_lines( int $document_id, array $lines ): void {
		global $wpdb;

		$table = Migrator::table( Migrator::TABLE_ITEMS );

		$wpdb->delete( $table, array( 'document_id' => $document_id ), array( '%d' ) );

		foreach ( $lines as $line ) {
			$written = $wpdb->insert(
				$table,
				array(
					'document_id'   => $document_id,
					'order_id'      => $line->order_id,
					'order_item_id' => $line->order_item_id,
					'product_id'    => $line->product_id,
					'variation_id'  => $line->variation_id,
					'sku'           => $line->sku,
					'code'          => $line->code,
					'name'          => $line->name,
					'quantity'      => number_format( $line->quantity, 3, '.', '' ),
					'unit'          => $line->unit,
					'unit_price'    => null === $line->unit_price ? null : number_format( $line->unit_price, 6, '.', '' ),
					'sort_order'    => $line->sort_order,
				),
				array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);

			if ( false === $written ) {
				throw new StorageException(
					sprintf( 'A line of delivery note %d could not be saved: %s', $document_id, $wpdb->last_error )
				);
			}
		}
	}

	/**
	 * Replace a document's links to orders.
	 *
	 * @param int       $document_id The document.
	 * @param list<int> $order_ids   The orders.
	 * @return void
	 */
	private function write_order_links( int $document_id, array $order_ids ): void {
		global $wpdb;

		$table = Migrator::table( Migrator::TABLE_ORDERS );

		$wpdb->delete( $table, array( 'document_id' => $document_id ), array( '%d' ) );

		foreach ( $order_ids as $order_id ) {
			if ( $order_id <= 0 ) {
				continue;
			}

			$wpdb->insert(
				$table,
				array(
					'document_id' => $document_id,
					'order_id'    => $order_id,
				),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * The lines of several documents, by document.
	 *
	 * @param list<int> $document_ids The documents.
	 * @return array<int, list<Line>>
	 */
	private function lines_for( array $document_ids ): array {
		global $wpdb;

		$ids = $this->id_list( $document_ids );

		if ( '' === $ids ) {
			return array();
		}

		$table = Migrator::table( Migrator::TABLE_ITEMS );

		/**
		 * The rows.
		 *
		 * @var list<array<string, mixed>> $rows
		 */
		$rows = (array) $wpdb->get_results(
			"SELECT * FROM {$table} WHERE document_id IN ({$ids}) ORDER BY document_id ASC, sort_order ASC, id ASC",
			ARRAY_A
		);

		$lines = array();

		foreach ( $rows as $row ) {
			$document_id = isset( $row['document_id'] ) ? (int) $row['document_id'] : 0;

			$lines[ $document_id ][] = Line::from_array( $row );
		}

		return $lines;
	}

	/**
	 * The orders several documents draw on, by document.
	 *
	 * @param list<int> $document_ids The documents.
	 * @return array<int, list<int>>
	 */
	private function order_links_for( array $document_ids ): array {
		global $wpdb;

		$ids = $this->id_list( $document_ids );

		if ( '' === $ids ) {
			return array();
		}

		$table = Migrator::table( Migrator::TABLE_ORDERS );

		/**
		 * The rows.
		 *
		 * @var list<array<string, mixed>> $rows
		 */
		$rows = (array) $wpdb->get_results(
			"SELECT document_id, order_id FROM {$table} WHERE document_id IN ({$ids}) ORDER BY order_id ASC",
			ARRAY_A
		);

		$links = array();

		foreach ( $rows as $row ) {
			$document_id = isset( $row['document_id'] ) ? (int) $row['document_id'] : 0;

			$links[ $document_id ][] = isset( $row['order_id'] ) ? (int) $row['order_id'] : 0;
		}

		return $links;
	}

	/**
	 * A list of identifiers safe to interpolate.
	 *
	 * Every value is cast to an integer and the negatives are dropped, so what
	 * comes out is digits and commas or nothing at all. wpdb has no placeholder
	 * for a list, and building one with prepare() for a variable number of
	 * identifiers is more moving parts than this.
	 *
	 * @param list<int> $ids The identifiers.
	 * @return string
	 */
	private function id_list( array $ids ): string {
		$clean = array();

		foreach ( $ids as $id ) {
			if ( (int) $id > 0 ) {
				$clean[] = (int) $id;
			}
		}

		return implode( ',', array_unique( $clean ) );
	}

	/**
	 * Build a document out of a row, its lines and its orders.
	 *
	 * @param array<string, mixed> $row       The document row.
	 * @param list<Line>           $lines     Its lines.
	 * @param list<int>            $order_ids The orders it draws on.
	 * @return Document
	 */
	private function from_row( array $row, array $lines, array $order_ids ): Document {
		$string = static fn ( string $key ): string =>
			isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '';

		$nullable = static function ( string $key ) use ( $row ): ?string {
			if ( ! isset( $row[ $key ] ) || ! is_scalar( $row[ $key ] ) ) {
				return null;
			}

			$value = (string) $row[ $key ];

			return '' === $value || '0000-00-00' === $value || '0000-00-00 00:00:00' === $value ? null : $value;
		};

		$integer = static fn ( string $key ): ?int =>
			isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ? (int) $row[ $key ] : null;

		$parties = Parties::from_array(
			array(
				'sender'      => $this->decode( $string( 'sender' ) ),
				'recipient'   => $this->decode( $string( 'recipient' ) ),
				'destination' => $this->decode( $string( 'destination' ) ),
			)
		);

		$transport = Transport::from_array(
			array(
				'by'               => $string( 'transport_by' ),
				'carrier_name'     => $string( 'carrier_name' ),
				'carrier_id'       => $integer( 'carrier_id' ),
				'carriage'         => $string( 'carriage' ),
				'packages'         => $integer( 'packages' ),
				'weight_gross'     => $nullable( 'weight_gross' ),
				'weight_net'       => $nullable( 'weight_net' ),
				'goods_appearance' => $string( 'goods_appearance' ),
				'started_at'       => $nullable( 'transport_started_at' ),
			)
		);

		return new Document(
			(int) ( $integer( 'id' ) ?? 0 ),
			DocumentStatus::from_string( $string( 'status' ) ),
			DocumentNumber::from_storage(
				$string( 'number' ),
				$string( 'series' ),
				$integer( 'sequence_year' ),
				$integer( 'sequence_number' )
			),
			$nullable( 'document_date' ),
			$parties,
			$transport,
			$string( 'causal' ),
			$lines,
			$order_ids,
			$string( 'notes' ),
			(int) ( $integer( 'customer_id' ) ?? 0 ),
			Lifecycle::from_array( $row )
		);
	}

	/**
	 * Read one of the snapshot columns.
	 *
	 * A column that cannot be decoded comes back as an empty array rather than
	 * throwing: a document whose recipient block is unreadable should still open,
	 * so that somebody can see what else it says and cancel it.
	 *
	 * @param string $json The stored JSON.
	 * @return array<string, mixed>
	 */
	private function decode( string $json ): array {
		if ( '' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
