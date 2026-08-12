<?php
/**
 * The plugin's single admin page.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Admin;

/**
 * Holds the tabs and draws whichever one was asked for.
 *
 * Tabs are added by the services that own them, not listed here, so that a
 * sprint which adds a screen adds one file instead of editing three. PRO adds
 * its own the same way.
 *
 * The tab bar is hidden while there is only one tab. A row of tabs containing a
 * single tab tells the reader nothing and looks like something failed to load.
 */
final class Screen {

	/**
	 * The page slug, and the one the WooCommerce menu entry points at.
	 */
	public const SLUG = 'oxyddt';

	/**
	 * The tabs, in the order they were added.
	 *
	 * @var list<Tab>
	 */
	private array $tabs = array();

	/**
	 * Add a tab.
	 *
	 * @param Tab $tab The tab.
	 * @return void
	 */
	public function add_tab( Tab $tab ): void {
		$this->tabs[] = $tab;
	}

	/**
	 * The tabs the current user may open.
	 *
	 * @return list<Tab>
	 */
	public function visible_tabs(): array {
		return array_values(
			array_filter(
				$this->tabs,
				static fn ( Tab $tab ): bool => current_user_can( $tab->capability )
			)
		);
	}

	/**
	 * Draw the page.
	 *
	 * @return void
	 */
	public function render(): void {
		$tabs = $this->visible_tabs();

		if ( array() === $tabs ) {
			wp_die(
				esc_html__( 'You are not allowed to see delivery notes.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		$current = $this->current( $tabs );

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Delivery notes (DDT)', 'oxyddt-for-woocommerce' ) . '</h1>';

		Notices::show();

		if ( count( $tabs ) > 1 ) {
			echo '<nav class="nav-tab-wrapper">';

			foreach ( $tabs as $tab ) {
				printf(
					'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
					esc_url(
						add_query_arg(
							array(
								'page' => self::SLUG,
								'tab'  => $tab->id,
							),
							admin_url( 'admin.php' )
						)
					),
					$tab->id === $current->id ? ' nav-tab-active' : '',
					esc_html( $tab->label )
				);
			}

			echo '</nav>';
		}

		$current->render();

		echo '</div>';
	}

	/**
	 * Which tab was asked for.
	 *
	 * Reading the tab from the query string needs no nonce: it chooses what to
	 * look at and changes nothing. What it must not do is trust the value, which
	 * is why an unknown one falls back to the first tab rather than being used.
	 *
	 * @param list<Tab> $tabs The tabs the user may open.
	 * @return Tab
	 */
	private function current( array $tabs ): Tab {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which tab to read is not a state change.
		$asked = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		foreach ( $tabs as $tab ) {
			if ( $tab->id === $asked ) {
				return $tab;
			}
		}

		return $tabs[0];
	}
}
