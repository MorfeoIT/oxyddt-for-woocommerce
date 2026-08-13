<?php
/**
 * When there is no PDF.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Pdf;

use RuntimeException;

/**
 * The PDF could not be produced, written or read.
 *
 * Never swallowed into an empty file. A zero-byte delivery note attached to an
 * email is worse than an error, because nobody notices until the customer does.
 */
final class PdfException extends RuntimeException {
}
