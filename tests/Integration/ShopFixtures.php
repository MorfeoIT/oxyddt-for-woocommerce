<?php
/**
 * A small shop to test against.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use WC_Order;
use WC_Product_Simple;

/**
 * Products and orders, built through WooCommerce's own API.
 *
 * Built rather than mocked, and built the way a shop builds them. A test that
 * hands the plugin an array shaped like an order proves the plugin can read that
 * array; it proves nothing about WooCommerce.
 */
trait ShopFixtures {

	/**
	 * A product on the shelf.
	 *
	 * @param string $name  What it is called.
	 * @param string $sku   Its code.
	 * @param string $price What it costs.
	 * @return WC_Product_Simple
	 */
	protected function a_product( string $name = 'Product A', string $sku = 'A-1', string $price = '10.00' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_regular_price( $price );
		$product->save();

		return $product;
	}

	/**
	 * An order for a company in Turin.
	 *
	 * @param array<string, float> $quantities Product name to quantity.
	 * @return WC_Order
	 */
	protected function an_order( array $quantities = array( 'Product A' => 10.0 ) ): WC_Order {
		$order = new WC_Order();

		$order->set_billing_company( 'Bianchi S.p.A.' );
		$order->set_billing_first_name( 'Mario' );
		$order->set_billing_last_name( 'Rossi' );
		$order->set_billing_address_1( 'Via Torino 5' );
		$order->set_billing_postcode( '10121' );
		$order->set_billing_city( 'Torino' );
		$order->set_billing_state( 'TO' );
		$order->set_billing_country( 'IT' );
		$order->set_billing_email( 'ordini@example.test' );
		$order->set_billing_phone( '+39 011 1234567' );

		$sku = 1;

		foreach ( $quantities as $name => $quantity ) {
			$order->add_product( $this->a_product( (string) $name, 'SKU-' . $sku ), (int) $quantity );
			++$sku;
		}

		$order->save();

		return $order;
	}
}
