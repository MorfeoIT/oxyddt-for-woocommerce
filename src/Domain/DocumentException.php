<?php
/**
 * What the document model refuses to do.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

use RuntimeException;

/**
 * An attempt to change a document that is not open to change.
 *
 * Thrown rather than returned false, and never caught to be ignored. Every one
 * of these means something tried to edit an issued or cancelled delivery note,
 * which is the one thing the product promises cannot happen.
 */
final class DocumentException extends RuntimeException {
}
