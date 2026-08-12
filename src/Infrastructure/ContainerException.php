<?php
/**
 * What the container throws.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Infrastructure;

use RuntimeException;

/**
 * A mistake in how the plugin is wired.
 *
 * Never caused by a request, never shown to a shop's customers: every one of
 * these means a developer registered a service wrongly.
 */
final class ContainerException extends RuntimeException {
}
