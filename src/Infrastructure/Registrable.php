<?php
/**
 * The contract for anything that attaches itself to WordPress.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Infrastructure;

/**
 * A service that adds its own hooks.
 *
 * Every service the container holds that implements this is registered at boot,
 * in declaration order. A service that does not implement it is still available
 * to others, it simply does not hook into anything by itself.
 */
interface Registrable {

	/**
	 * Add the hooks this service needs.
	 *
	 * @return void
	 */
	public function register(): void;
}
