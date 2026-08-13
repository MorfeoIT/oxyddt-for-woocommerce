<?php
/**
 * Where the next number comes from.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * The counter, described from the Domain's side.
 *
 * There is exactly one operation that matters here and it is `allocate()`: give
 * me a number that nobody else will get. Everything else on this interface
 * exists so that a shop can see what the next one will be and set where it
 * starts.
 *
 * The interface says nothing about how the guarantee is kept, which is the
 * point: PRO replaces the implementation to add sectionals, and the model
 * neither knows nor cares.
 */
interface SequenceRepositoryInterface {

	/**
	 * Take the next number, once.
	 *
	 * Must never hand the same number to two callers, whatever they are doing at
	 * the same moment. A number that comes back from here is spent, whether or
	 * not the caller goes on to use it: holes in a register are explainable,
	 * duplicates are not.
	 *
	 * @param string $series The series, empty when there is only one.
	 * @param int    $year   The year the sequence belongs to.
	 * @param int    $start  Where the sequence begins if it does not exist yet.
	 * @return int
	 */
	public function allocate( string $series, int $year, int $start = 1 ): int;

	/**
	 * What the next number would be, without taking it.
	 *
	 * For screens only. Anything that acts on this rather than on allocate() has
	 * a race in it.
	 *
	 * @param string $series The series.
	 * @param int    $year   The year.
	 * @param int    $start  Where the sequence begins if it does not exist yet.
	 * @return int
	 */
	public function peek( string $series, int $year, int $start = 1 ): int;

	/**
	 * Say where the sequence goes next.
	 *
	 * A shop moving from another system starts at 348 rather than 1. Refused
	 * once documents exist in that series and year, which is the caller's job to
	 * check, not this one's.
	 *
	 * @param string $series The series.
	 * @param int    $year   The year.
	 * @param int    $next   The next number to hand out.
	 * @return void
	 */
	public function set_next( string $series, int $year, int $next ): void;
}
