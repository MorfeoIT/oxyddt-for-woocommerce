<?php
/**
 * How much of an order has gone out.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * Not shipped, partly shipped, shipped.
 *
 * Read from issued documents only. A draft somebody is preparing has not
 * shipped anything, and a cancelled document has un-shipped what it once said.
 */
enum FulfilmentStatus: string {

	/**
	 * Nothing has gone out.
	 */
	case None = 'none';

	/**
	 * Some of it has.
	 */
	case Partial = 'partial';

	/**
	 * All of it has.
	 */
	case Complete = 'complete';
}
