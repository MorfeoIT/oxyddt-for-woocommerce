<?php
/**
 * Reading a real WooCommerce order.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Domain\Address;
use Oxysoft\OxyDDT\Domain\Line;
use Oxysoft\OxyDDT\WooCommerce\OrderSnapshot;
use WP_UnitTestCase;

/**
 * What the plugin takes from an order, and what it refuses to take.
 */
final class OrderSnapshotTest extends WP_UnitTestCase {

	use ShopFixtures;

	/**
	 * The company name wins: a note addressed to the person when the invoice
	 * says the company is a document somebody has to explain.
	 *
	 * @return void
	 */
	public function test_the_company_is_the_recipient_when_there_is_one(): void {
		$order = $this->an_order();

		$this->assertSame( 'Bianchi S.p.A.', OrderSnapshot::recipient( $order )->name );

		$order->set_billing_company( '' );
		$order->save();

		$this->assertSame( 'Mario Rossi', OrderSnapshot::recipient( wc_get_order( $order->get_id() ) )->name );
	}

	/**
	 * The billing address, as typed.
	 *
	 * @return void
	 */
	public function test_the_recipient_carries_the_billing_address(): void {
		$recipient = OrderSnapshot::recipient( $this->an_order() );

		$this->assertSame( 'Via Torino 5', $recipient->address->street );
		$this->assertSame( '10121', $recipient->address->postcode );
		$this->assertSame( 'TO', $recipient->address->province );
		$this->assertSame( 'IT', $recipient->address->country );
		$this->assertSame( 'ordini@example.test', $recipient->email );
		$this->assertTrue( $recipient->is_valid() );
	}

	/**
	 * There is no standard meta key for an Italian VAT number, so the plugin
	 * tries the ones the popular checkout plugins use.
	 *
	 * @return void
	 */
	public function test_the_vat_number_is_found_wherever_the_checkout_put_it(): void {
		$order = $this->an_order();
		$order->update_meta_data( '_billing_partita_iva', 'IT 012 345 678 97' );
		$order->update_meta_data( '_billing_codice_fiscale', 'RSSMRA85T10A562S' );
		$order->save();

		$recipient = OrderSnapshot::recipient( wc_get_order( $order->get_id() ) );

		$this->assertSame( '01234567897', $recipient->vat_number );
		$this->assertSame( 'RSSMRA85T10A562S', $recipient->tax_code );
	}

	/**
	 * And a shop whose checkout stores it somewhere nobody has heard of adds its
	 * key with a filter rather than editing the plugin.
	 *
	 * @return void
	 */
	public function test_a_shop_can_teach_it_where_to_look(): void {
		$order = $this->an_order();
		$order->update_meta_data( '_our_own_vat_field', '12345678903' );
		$order->save();

		$filter = static function ( array $ids, $order ): array {
			$ids['vat_number'] = (string) $order->get_meta( '_our_own_vat_field' );

			return $ids;
		};

		add_filter( 'oxyddt_order_tax_ids', $filter, 10, 2 );

		$recipient = OrderSnapshot::recipient( wc_get_order( $order->get_id() ) );

		remove_filter( 'oxyddt_order_tax_ids', $filter, 10 );

		$this->assertSame( '12345678903', $recipient->vat_number );
	}

	/**
	 * An order with no shipping address of its own has no separate destination:
	 * null says "the same place as the recipient", where an empty address block
	 * would print nonsense.
	 *
	 * @return void
	 */
	public function test_an_order_without_a_shipping_address_has_no_destination(): void {
		$this->assertNull( OrderSnapshot::destination( $this->an_order() ) );
	}

	/**
	 * And one with a shipping address delivers there.
	 *
	 * @return void
	 */
	public function test_a_shipping_address_becomes_the_destination(): void {
		$order = $this->an_order();
		$order->set_shipping_address_1( 'Via Milano 9' );
		$order->set_shipping_postcode( '20090' );
		$order->set_shipping_city( 'Segrate' );
		$order->set_shipping_state( 'MI' );
		$order->set_shipping_country( 'IT' );
		$order->save();

		$destination = OrderSnapshot::destination( wc_get_order( $order->get_id() ) );

		$this->assertInstanceOf( Address::class, $destination );
		$this->assertSame( 'Segrate', $destination->city );
		$this->assertTrue( $destination->is_valid() );
	}

	/**
	 * Every product line, at its full ordered quantity, carrying what ties it
	 * back to the order.
	 *
	 * @return void
	 */
	public function test_the_lines_carry_what_ties_them_to_the_order(): void {
		$order = $this->an_order(
			array(
				'Product A' => 10.0,
				'Product B' => 5.0,
			)
		);

		$lines = OrderSnapshot::lines( $order );

		$this->assertCount( 2, $lines );
		$this->assertSame( 'Product A', $lines[0]->name );
		$this->assertEqualsWithDelta( 10.0, $lines[0]->quantity, Line::EPSILON );
		$this->assertSame( 'SKU-1', $lines[0]->sku );
		$this->assertSame( $order->get_id(), $lines[0]->order_id );
		$this->assertGreaterThan( 0, $lines[0]->order_item_id );
		$this->assertGreaterThan( 0, $lines[0]->product_id );
		$this->assertSame( 0, $lines[0]->sort_order );
		$this->assertSame( 1, $lines[1]->sort_order );
	}

	/**
	 * Shipping and fees are not goods, and a delivery note lists goods.
	 *
	 * @return void
	 */
	public function test_shipping_and_fees_are_not_goods(): void {
		$order = $this->an_order();

		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( 'Packaging' );
		$fee->set_total( '5.00' );
		$order->add_item( $fee );

		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Courier' );
		$shipping->set_total( '7.00' );
		$order->add_item( $shipping );

		$order->save();

		$this->assertCount( 1, OrderSnapshot::lines( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * A shop can drop a line from the document altogether — a service, a
	 * deposit, anything that is not going in the van.
	 *
	 * @return void
	 */
	public function test_a_shop_can_keep_a_line_off_the_document(): void {
		$order = $this->an_order(
			array(
				'Product A' => 1.0,
				'Assembly'  => 1.0,
			)
		);

		$filter = static function ( $line ) {
			return 'Assembly' === $line->name ? null : $line;
		};

		add_filter( 'oxyddt_order_line', $filter );

		$lines = OrderSnapshot::lines( wc_get_order( $order->get_id() ) );

		remove_filter( 'oxyddt_order_line', $filter );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'Product A', $lines[0]->name );
	}
}
