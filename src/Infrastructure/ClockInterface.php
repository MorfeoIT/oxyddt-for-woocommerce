<?php
/**
 * What time it is.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Infrastructure;

use DateTimeImmutable;

/**
 * The source of "now".
 *
 * A delivery note carries a date, a numbering sequence resets on a year
 * boundary, and an audit entry is worth nothing without a timestamp. All three
 * have to be testable at a chosen instant — the 31st of December at 23:59 above
 * all — so nothing in the plugin calls time() directly.
 */
interface ClockInterface {

	/**
	 * The current moment, in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	public function now(): DateTimeImmutable;

	/**
	 * The current moment in the shop's own timezone.
	 *
	 * The document date is a local fact. A note issued at 00:30 in Rome on the
	 * 2nd of January belongs to the new year's sequence even though UTC still
	 * says the 1st, and a shop that discovers otherwise discovers it in front of
	 * an accountant.
	 *
	 * @return DateTimeImmutable
	 */
	public function local(): DateTimeImmutable;
}
