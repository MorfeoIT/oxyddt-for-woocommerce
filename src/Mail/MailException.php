<?php
/**
 * When a delivery note cannot be sent.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Mail;

use RuntimeException;

/**
 * The email was not attempted, and the screen has to say why.
 *
 * Distinct from wp_mail() returning false, which means it was attempted and the
 * host would not take it. Both are worth telling a person about, and they are
 * different problems.
 */
final class MailException extends RuntimeException {
}
