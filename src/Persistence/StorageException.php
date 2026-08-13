<?php
/**
 * What the store throws.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Persistence;

use RuntimeException;

/**
 * A write that did not happen.
 *
 * Never swallowed. A delivery note the shop believes it has issued, and which is
 * not in the database, is worse than an error message: the number is gone, the
 * goods have left, and nobody knows until the accountant asks.
 *
 * Sprint 4 catches one particular case of this — the unique index refusing a
 * duplicate number — and turns it into "somebody else took that number, here is
 * the next one".
 */
final class StorageException extends RuntimeException {
}
