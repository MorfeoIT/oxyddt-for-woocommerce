<?php
/**
 * How a shop numbers its delivery notes.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * The decisions a shop makes once: where the numbers start, whether they reset
 * in January, what they look like.
 *
 * The one subtlety worth its own method is which year a number belongs to. It
 * is the year of the **document date**, not of the moment somebody presses
 * Issue. A note dated the 31st of December and issued on the 2nd of January
 * belongs to last year's sequence, and a shop that discovers otherwise
 * discovers it in front of an accountant.
 */
final class NumberingPolicy {

	/**
	 * The counter row a shop uses when its numbers do not reset.
	 *
	 * Year zero, which is not a year: one continuous sequence, going up forever.
	 */
	public const CONTINUOUS = 0;

	/**
	 * Build the policy.
	 *
	 * @param string       $series       Sectional, empty when there is only one.
	 * @param int          $start        The first number, for a shop coming from another system.
	 * @param bool         $yearly_reset Whether the count starts again each January.
	 * @param NumberFormat $format       How the number is written.
	 */
	public function __construct(
		public readonly string $series = '',
		public readonly int $start = 1,
		public readonly bool $yearly_reset = true,
		public readonly NumberFormat $format = new NumberFormat()
	) {
	}

	/**
	 * Build the policy from stored settings.
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$series = isset( $data['series'] ) && is_scalar( $data['series'] )
			? strtoupper( trim( (string) $data['series'] ) )
			: '';

		$start = isset( $data['start'] ) && is_numeric( $data['start'] ) ? (int) $data['start'] : 1;

		return new self(
			// Twenty characters, and nothing that would need escaping in a
			// filename or a URL: the series ends up in both.
			substr( (string) preg_replace( '/[^A-Z0-9\-]/', '', $series ), 0, 20 ),
			max( 1, $start ),
			! isset( $data['yearly_reset'] ) || (bool) $data['yearly_reset'],
			NumberFormat::from_array( is_array( $data['format'] ?? null ) ? $data['format'] : array() )
		);
	}

	/**
	 * The policy as a plain array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'series'       => $this->series,
			'start'        => $this->start,
			'yearly_reset' => $this->yearly_reset,
			'format'       => $this->format->to_array(),
		);
	}

	/**
	 * Which counter a document draws on.
	 *
	 * @param string|null $document_date The date on the document, "Y-m-d".
	 * @param int         $fallback_year The year to assume when there is no date.
	 * @return int
	 */
	public function sequence_year( ?string $document_date, int $fallback_year ): int {
		if ( ! $this->yearly_reset ) {
			return self::CONTINUOUS;
		}

		return $this->printed_year( $document_date, $fallback_year );
	}

	/**
	 * Which year goes in the number itself.
	 *
	 * Always the document's own year, even for a shop whose count never resets:
	 * "348/2026" reads the same either way, and the year is what makes a number
	 * findable three years later.
	 *
	 * @param string|null $document_date The date on the document, "Y-m-d".
	 * @param int         $fallback_year The year to assume when there is no date.
	 * @return int
	 */
	public function printed_year( ?string $document_date, int $fallback_year ): int {
		if ( null === $document_date || 1 !== preg_match( '/^(\d{4})-\d{2}-\d{2}$/', $document_date, $matches ) ) {
			return $fallback_year;
		}

		return (int) $matches[1];
	}
}
