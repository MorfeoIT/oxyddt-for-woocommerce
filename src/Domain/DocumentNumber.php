<?php
/**
 * The number on the document.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * What a delivery note is called, and the three parts it is made of.
 *
 * The formatted string is what gets printed and searched for — "125/2026",
 * "DDT-2026-00125", "A/125/2026". The three parts underneath are what makes it
 * a number rather than a label: they order the register, they reset in January,
 * and they are what the unique index in the database is built on.
 *
 * A draft has none of it. Assigning the number is the act of issuing, and it
 * happens in one place, in sprint 4, under a lock.
 */
final class DocumentNumber {

	/**
	 * Build a number.
	 *
	 * Public because PHP only allows `new` in a default parameter value when the
	 * constructor is. Prefer none(), assigned() and from_storage(): they say
	 * which of the three cases is meant, and the last one enforces that a number
	 * without a sequence is no number at all.
	 *
	 * @param string   $formatted What is printed on the document.
	 * @param string   $series    Sectional, empty when there is only one.
	 * @param int|null $year      Year the sequence belongs to.
	 * @param int|null $sequence  Position within the series and year.
	 */
	public function __construct(
		public readonly string $formatted = '',
		public readonly string $series = '',
		public readonly ?int $year = null,
		public readonly ?int $sequence = null
	) {
	}

	/**
	 * The absence of a number, which is what a draft has.
	 *
	 * Not an empty string and not zero: the three parts are null, and null is
	 * what lets the database hold a thousand drafts under one unique index while
	 * refusing a second document 125 of 2026.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self();
	}

	/**
	 * A number that has been assigned.
	 *
	 * @param string $formatted What is printed on the document.
	 * @param string $series    Sectional, empty when there is only one.
	 * @param int    $year      Year the sequence belongs to.
	 * @param int    $sequence  Position within the series and year.
	 * @return self
	 */
	public static function assigned( string $formatted, string $series, int $year, int $sequence ): self {
		return new self( $formatted, $series, $year, $sequence );
	}

	/**
	 * Read a number back from stored columns.
	 *
	 * A row whose sequence is null is a draft, whatever else it carries.
	 *
	 * @param string   $formatted Stored formatted number.
	 * @param string   $series    Stored series.
	 * @param int|null $year      Stored year.
	 * @param int|null $sequence  Stored sequence.
	 * @return self
	 */
	public static function from_storage( string $formatted, string $series, ?int $year, ?int $sequence ): self {
		if ( null === $sequence || null === $year ) {
			return self::none();
		}

		return new self( $formatted, $series, $year, $sequence );
	}

	/**
	 * Whether a number has been taken.
	 *
	 * @return bool
	 */
	public function is_assigned(): bool {
		return null !== $this->sequence;
	}

	/**
	 * How it reads.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return $this->formatted;
	}
}
