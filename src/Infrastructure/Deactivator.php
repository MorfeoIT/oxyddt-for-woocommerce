<?php
/**
 * What happens when the plugin is switched off.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Infrastructure;

/**
 * Almost nothing, and that is the point.
 *
 * Switching a plugin off is how people test whether it is causing something. It
 * must not cost a shop its delivery notes, its numbering position or the
 * capabilities it handed out. Everything that removes data lives in uninstall.php,
 * behind a setting that is off by default.
 */
final class Deactivator {

	/**
	 * Tidy up what only makes sense while the plugin runs.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Nothing scheduled yet. Sprint 5 adds the email queue and sprint 7 the
		// housekeeping event, and both unschedule themselves here.
	}
}
