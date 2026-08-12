<?php
/**
 * Where the plugin appears in the admin.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Admin;

use Oxysoft\OxyDDT\Infrastructure\Registrable;
use Oxysoft\OxyDDT\Security\Capabilities;

/**
 * One entry, under WooCommerce, called DDT.
 *
 * Under WooCommerce rather than at the top level because that is where somebody
 * looking for something to do with an order will look, and because a shop's
 * sidebar belongs to the shop.
 */
final class Menu implements Registrable {

	/**
	 * The page.
	 *
	 * @var Screen
	 */
	private Screen $screen;

	/**
	 * Build the menu.
	 *
	 * @param Screen $screen The page.
	 */
	public function __construct( Screen $screen ) {
		$this->screen = $screen;
	}

	/**
	 * Add the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
	}

	/**
	 * Add the page.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Delivery notes (DDT)', 'oxyddt-for-woocommerce' ),
			__( 'DDT', 'oxyddt-for-woocommerce' ),
			// Whoever may see a delivery note may open the page; each tab checks
			// its own capability again, because the page is not the permission.
			Capabilities::VIEW,
			Screen::SLUG,
			array( $this->screen, 'render' )
		);
	}
}
