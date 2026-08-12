<?php
/**
 * One tab of the DDT screen.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Admin;

/**
 * A section of the plugin's single admin page.
 *
 * WooCommerce puts everything it owns under one menu entry with tabs inside, and
 * a plugin that adds four entries of its own to that menu is a plugin people
 * uninstall. The register, the carriers and the settings are tabs.
 */
final class Tab {

	/**
	 * Build a tab.
	 *
	 * @param string           $id         Used in the URL; lowercase, no spaces.
	 * @param string           $label      Already translated.
	 * @param string           $capability What a user must hold to open it.
	 * @param \Closure(): void $renderer  Draws the tab's contents.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $capability,
		public readonly \Closure $renderer
	) {
	}

	/**
	 * Draw the tab.
	 *
	 * @return void
	 */
	public function render(): void {
		( $this->renderer )();
	}
}
