<?php
/**
 * How a number reads.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * The pattern that turns a series, a year and a position into "125/2026".
 *
 * Separate from the sequence on purpose. The sequence decides *which* number a
 * document gets, once, under a lock; this decides how that number is written,
 * and can be changed by a shop that wants "DDT-2026-00125" instead without
 * anything else in the plugin noticing.
 *
 * Plain PHP, so every shape a shop might want is a test rather than a support
 * ticket.
 */
final class NumberFormat {

	/**
	 * The default: 125/2026, which is what most Italian shops write.
	 */
	public const DEFAULT_PATTERN = '{number}/{year}';

	/**
	 * Build the format.
	 *
	 * @param string $pattern Placeholders {number}, {year}, {year2} and {series}.
	 * @param int    $padding How many digits the number is padded to, 0 for none.
	 */
	public function __construct(
		public readonly string $pattern = self::DEFAULT_PATTERN,
		public readonly int $padding = 0
	) {
	}

	/**
	 * Build the format from stored settings.
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$pattern = isset( $data['pattern'] ) && is_scalar( $data['pattern'] )
			? trim( (string) $data['pattern'] )
			: '';

		$padding = isset( $data['padding'] ) && is_numeric( $data['padding'] )
			? (int) $data['padding']
			: 0;

		return new self(
			// A pattern with nowhere to put the number is not a pattern. Falling
			// back is kinder than issuing a shop's documents all called "DDT".
			false === strpos( $pattern, '{number}' ) ? self::DEFAULT_PATTERN : $pattern,
			max( 0, min( 12, $padding ) )
		);
	}

	/**
	 * The format as a plain array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'pattern' => $this->pattern,
			'padding' => $this->padding,
		);
	}

	/**
	 * Write a number out.
	 *
	 * @param string $series   The series, empty when there is only one.
	 * @param int    $year     The year the sequence belongs to.
	 * @param int    $sequence The position within that series and year.
	 * @return string
	 */
	public function format( string $series, int $year, int $sequence ): string {
		$number = $this->padding > 0
			? str_pad( (string) $sequence, $this->padding, '0', STR_PAD_LEFT )
			: (string) $sequence;

		$written = strtr(
			$this->pattern,
			array(
				'{number}' => $number,
				'{year}'   => (string) $year,
				'{year2}'  => substr( (string) $year, -2 ),
				'{series}' => $series,
			)
		);

		// A shop with one series leaves {series} empty, and the separator around
		// it would otherwise survive: "/125/2026". Cleaning up the doubles is what
		// makes one pattern work for both cases.
		$written = (string) preg_replace( '#([/\-.])\1+#', '$1', $written );

		return trim( $written, '/-. ' );
	}

	/**
	 * What the next number will look like, for the settings screen.
	 *
	 * @param string $series The series.
	 * @param int    $year   The year.
	 * @param int    $next   The next position.
	 * @return string
	 */
	public function preview( string $series, int $year, int $next ): string {
		return $this->format( $series, $year, $next );
	}
}
