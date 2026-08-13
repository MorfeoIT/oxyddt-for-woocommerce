<?php
/**
 * How numbers are written, and which year they belong to.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\NumberFormat;
use Oxysoft\OxyDDT\Domain\NumberingPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The pattern and the policy.
 */
final class NumberingTest extends TestCase {

	/**
	 * The three shapes the specification asks for.
	 *
	 * @return void
	 */
	public function test_it_writes_the_usual_shapes(): void {
		$this->assertSame(
			'125/2026',
			( new NumberFormat( '{number}/{year}' ) )->format( '', 2026, 125 )
		);

		$this->assertSame(
			'DDT-2026-00125',
			( new NumberFormat( 'DDT-{year}-{number}', 5 ) )->format( '', 2026, 125 )
		);

		$this->assertSame(
			'A/125/2026',
			( new NumberFormat( '{series}/{number}/{year}' ) )->format( 'A', 2026, 125 )
		);
	}

	/**
	 * A shop with one series leaves the placeholder empty, and the separator
	 * around it must not survive as "/125/2026".
	 *
	 * @return void
	 */
	public function test_an_empty_series_leaves_no_stray_separators(): void {
		$this->assertSame(
			'125/2026',
			( new NumberFormat( '{series}/{number}/{year}' ) )->format( '', 2026, 125 )
		);
	}

	/**
	 * Two digits of year, for shops that write 125/26.
	 *
	 * @return void
	 */
	public function test_the_year_can_be_two_digits(): void {
		$this->assertSame(
			'125/26',
			( new NumberFormat( '{number}/{year2}' ) )->format( '', 2026, 125 )
		);
	}

	/**
	 * A pattern with nowhere to put the number is not a pattern. Falling back is
	 * kinder than issuing a shop's documents all called "DDT".
	 *
	 * @return void
	 */
	public function test_a_pattern_without_a_number_is_refused(): void {
		$format = NumberFormat::from_array( array( 'pattern' => 'DDT-{year}' ) );

		$this->assertSame( NumberFormat::DEFAULT_PATTERN, $format->pattern );
	}

	/**
	 * Padding is bounded: nobody needs a forty-digit delivery note number.
	 *
	 * @return void
	 */
	public function test_padding_is_bounded(): void {
		$this->assertSame( 12, NumberFormat::from_array( array( 'padding' => 40 ) )->padding );
		$this->assertSame( 0, NumberFormat::from_array( array( 'padding' => -3 ) )->padding );
	}

	/**
	 * A format survives a round trip through the settings.
	 *
	 * @return void
	 */
	public function test_a_format_survives_a_round_trip(): void {
		$format = new NumberFormat( 'DDT-{year}-{number}', 5 );

		$this->assertEquals( $format, NumberFormat::from_array( $format->to_array() ) );
	}

	/**
	 * **The one that matters.** A note dated the 31st of December and issued on
	 * the 2nd of January belongs to the old year's sequence.
	 *
	 * @return void
	 */
	public function test_a_document_belongs_to_the_year_printed_on_it(): void {
		$policy = new NumberingPolicy();

		$this->assertSame( 2025, $policy->sequence_year( '2025-12-31', 2026 ) );
		$this->assertSame( 2025, $policy->printed_year( '2025-12-31', 2026 ) );
		$this->assertSame( 2026, $policy->sequence_year( '2026-01-02', 2026 ) );
	}

	/**
	 * With no date at all, today's year is the only answer available.
	 *
	 * @return void
	 */
	public function test_without_a_date_it_falls_back_to_this_year(): void {
		$policy = new NumberingPolicy();

		$this->assertSame( 2026, $policy->sequence_year( null, 2026 ) );
		$this->assertSame( 2026, $policy->sequence_year( 'not a date', 2026 ) );
	}

	/**
	 * A shop whose count never resets draws on one continuous counter, but still
	 * prints the document's own year.
	 *
	 * @return void
	 */
	public function test_a_continuous_sequence_uses_one_counter(): void {
		$policy = new NumberingPolicy( '', 1, false );

		$this->assertSame( NumberingPolicy::CONTINUOUS, $policy->sequence_year( '2025-12-31', 2026 ) );
		$this->assertSame( NumberingPolicy::CONTINUOUS, $policy->sequence_year( '2026-01-02', 2026 ) );
		$this->assertSame( 2025, $policy->printed_year( '2025-12-31', 2026 ), 'the year still comes from the document' );
	}

	/**
	 * The series ends up in a number, a filename and a URL, so it is kept to
	 * characters that are safe in all three.
	 *
	 * @return void
	 */
	public function test_the_series_is_kept_simple(): void {
		$this->assertSame( 'A1', NumberingPolicy::from_array( array( 'series' => ' a/1 ' ) )->series );
		$this->assertSame( '', NumberingPolicy::from_array( array( 'series' => '../..' ) )->series );
		$this->assertSame( 20, strlen( NumberingPolicy::from_array( array( 'series' => str_repeat( 'A', 40 ) ) )->series ) );
	}

	/**
	 * A shop coming from another system starts at 348, not at one.
	 *
	 * @return void
	 */
	public function test_a_shop_can_say_where_the_count_starts(): void {
		$this->assertSame( 348, NumberingPolicy::from_array( array( 'start' => '348' ) )->start );
		$this->assertSame( 1, NumberingPolicy::from_array( array( 'start' => '0' ) )->start );
		$this->assertSame( 1, NumberingPolicy::from_array( array( 'start' => '-5' ) )->start );
	}

	/**
	 * The policy survives a round trip through the settings.
	 *
	 * @return void
	 */
	public function test_a_policy_survives_a_round_trip(): void {
		$policy = new NumberingPolicy( 'A', 348, false, new NumberFormat( '{series}/{number}/{year}', 4 ) );

		$this->assertEquals( $policy, NumberingPolicy::from_array( $policy->to_array() ) );
	}

	/**
	 * Yearly reset is on unless a shop turns it off, because that is what most
	 * Italian shops do.
	 *
	 * @return void
	 */
	public function test_yearly_reset_is_the_default(): void {
		$this->assertTrue( NumberingPolicy::from_array( array() )->yearly_reset );
		$this->assertFalse( NumberingPolicy::from_array( array( 'yearly_reset' => false ) )->yearly_reset );
	}
}
