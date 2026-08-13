<?php
/**
 * What somebody is looking for in the register.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * The filters, cleaned up before they reach a query.
 *
 * Everything here arrives from a query string, which is to say from anybody at
 * all. So it is read once, into a value with fixed shapes: a page that is at
 * least one, a month between one and twelve, a sort column from a list of two.
 * The repository below trusts this object completely, and can only do that
 * because nothing else is allowed to build one from raw input.
 *
 * Plain PHP, so the cleaning up is provable without a database.
 */
final class DocumentQuery {

	/**
	 * How many documents a page holds unless somebody says otherwise.
	 */
	public const PER_PAGE = 20;

	/**
	 * The most a page may hold, however hard somebody asks.
	 */
	public const MAX_PER_PAGE = 200;

	/**
	 * Order by the date on the document.
	 */
	public const BY_DATE = 'date';

	/**
	 * Order by the number, which is not the same thing: a note dated the 31st of
	 * December and issued in January sorts differently under each.
	 */
	public const BY_NUMBER = 'number';

	/**
	 * Build the query.
	 *
	 * @param string              $search      Words to look for in the number, the customer or the order.
	 * @param int|null            $year        Year of the document date.
	 * @param int|null            $month       Month of the document date, 1 to 12.
	 * @param int                 $order_id    Only documents drawing on this order.
	 * @param int                 $customer_id Only documents for this customer account.
	 * @param string              $causal      Only this reason for transport.
	 * @param string              $carrier     Only this carrier, matched loosely.
	 * @param DocumentStatus|null $status      Only documents in this state.
	 * @param int|null            $number_from Lowest number in the range.
	 * @param int|null            $number_to   Highest number in the range.
	 * @param int                 $page        Which page, from one.
	 * @param int                 $per_page    How many per page.
	 * @param string              $order_by    BY_DATE or BY_NUMBER.
	 * @param bool                $ascending   Whether the oldest comes first.
	 */
	private function __construct(
		public readonly string $search = '',
		public readonly ?int $year = null,
		public readonly ?int $month = null,
		public readonly int $order_id = 0,
		public readonly int $customer_id = 0,
		public readonly string $causal = '',
		public readonly string $carrier = '',
		public readonly ?DocumentStatus $status = null,
		public readonly ?int $number_from = null,
		public readonly ?int $number_to = null,
		public readonly int $page = 1,
		public readonly int $per_page = self::PER_PAGE,
		public readonly string $order_by = self::BY_DATE,
		public readonly bool $ascending = false
	) {
	}

	/**
	 * Everything, newest first.
	 *
	 * @return self
	 */
	public static function all(): self {
		return new self();
	}

	/**
	 * Read a query out of whatever a request carried.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return self
	 */
	public static function from_array( array $input ): self {
		$text = static fn ( string $key ): string =>
			isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';

		$number = static function ( string $key ) use ( $input ): ?int {
			if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
				return null;
			}

			$value = (int) $input[ $key ];

			return $value > 0 ? $value : null;
		};

		// Both come back either null or positive, so only the upper bound and the
		// lower bound that is not one are worth asking about.
		$month = $number( 'month' );
		$year  = $number( 'year' );

		$from = $number( 'number_from' );
		$to   = $number( 'number_to' );

		// A range typed backwards is a range: somebody meant 120 to 130 and typed
		// it the other way round. Refusing it would be pedantry.
		if ( null !== $from && null !== $to && $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}

		$per_page = $number( 'per_page' ) ?? self::PER_PAGE;

		return new self(
			$text( 'search' ),
			null !== $year && $year >= 1970 && $year <= 9999 ? $year : null,
			null !== $month && $month <= 12 ? $month : null,
			(int) ( $number( 'order_id' ) ?? 0 ),
			(int) ( $number( 'customer_id' ) ?? 0 ),
			Causals::normalise( $text( 'causal' ) ),
			$text( 'carrier' ),
			'' === $text( 'status' ) ? null : DocumentStatus::tryFrom( $text( 'status' ) ),
			$from,
			$to,
			// "page" belongs to WordPress: it is the admin screen's own slug. The
			// register's page had to be called something else, and a filter named
			// after the thing it does is better than one named after a collision.
			(int) ( $number( 'page_number' ) ?? 1 ),
			min( self::MAX_PER_PAGE, max( 1, $per_page ) ),
			self::BY_NUMBER === $text( 'order_by' ) ? self::BY_NUMBER : self::BY_DATE,
			'asc' === strtolower( $text( 'order_dir' ) )
		);
	}

	/**
	 * The same query, on a different page.
	 *
	 * @param int $page The page.
	 * @return self
	 */
	public function on_page( int $page ): self {
		return new self(
			$this->search,
			$this->year,
			$this->month,
			$this->order_id,
			$this->customer_id,
			$this->causal,
			$this->carrier,
			$this->status,
			$this->number_from,
			$this->number_to,
			max( 1, $page ),
			$this->per_page,
			$this->order_by,
			$this->ascending
		);
	}

	/**
	 * How many rows to skip.
	 *
	 * @return int
	 */
	public function offset(): int {
		return ( $this->page - 1 ) * $this->per_page;
	}

	/**
	 * Whether anything at all was asked for.
	 *
	 * @return bool
	 */
	public function is_filtered(): bool {
		return '' !== $this->search
			|| null !== $this->year
			|| null !== $this->month
			|| $this->order_id > 0
			|| $this->customer_id > 0
			|| '' !== $this->causal
			|| '' !== $this->carrier
			|| null !== $this->status
			|| null !== $this->number_from
			|| null !== $this->number_to;
	}

	/**
	 * The query as query-string arguments, for building links.
	 *
	 * Empty filters are left out, so a link to page two of an unfiltered register
	 * stays short enough to read.
	 *
	 * @return array<string, string>
	 */
	public function to_query_args(): array {
		$args = array(
			'search'      => $this->search,
			'year'        => null === $this->year ? '' : (string) $this->year,
			'month'       => null === $this->month ? '' : (string) $this->month,
			'order_id'    => $this->order_id > 0 ? (string) $this->order_id : '',
			'customer_id' => $this->customer_id > 0 ? (string) $this->customer_id : '',
			'causal'      => $this->causal,
			'carrier'     => $this->carrier,
			'status'      => null === $this->status ? '' : $this->status->value,
			'number_from' => null === $this->number_from ? '' : (string) $this->number_from,
			'number_to'   => null === $this->number_to ? '' : (string) $this->number_to,
			'page_number' => $this->page > 1 ? (string) $this->page : '',
			'order_by'    => self::BY_DATE === $this->order_by ? '' : $this->order_by,
			'order_dir'   => $this->ascending ? 'asc' : '',
		);

		return array_filter( $args, static fn ( string $value ): bool => '' !== $value );
	}
}
