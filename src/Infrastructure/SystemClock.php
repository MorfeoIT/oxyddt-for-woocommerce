<?php
/**
 * The real clock.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;

/**
 * "Now", as WordPress understands it.
 *
 * The local time comes from wp_timezone() rather than from PHP's default
 * timezone, which WordPress leaves at UTC on purpose. Reading date_default_
 * timezone_get() here would date every document in London.
 */
final class SystemClock implements ClockInterface {

	/**
	 * The current moment, in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * The current moment in the shop's own timezone.
	 *
	 * @return DateTimeImmutable
	 */
	public function local(): DateTimeImmutable {
		return $this->now()->setTimezone( wp_timezone() );
	}
}
