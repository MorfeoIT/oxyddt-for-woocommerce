<?php
/**
 * Turning an order into the parts of a delivery note.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\WooCommerce;

use Oxysoft\OxyDDT\Domain\Address;
use Oxysoft\OxyDDT\Domain\Line;
use Oxysoft\OxyDDT\Domain\Party;
use WC_Order;
use WC_Order_Item_Product;

/**
 * The one place that reads an order.
 *
 * Everything here goes through WooCommerce's own CRUD — `$order->get_*()`,
 * `$item->get_*()` — and never touches wp_posts, wp_postmeta or the HPOS tables.
 * That is what makes the plugin work whichever way a shop stores its orders,
 * and it is the rule the specification puts in capitals.
 *
 * What comes out are Domain values: a Party, an Address, a list of Lines. They
 * are copies, taken now. Once they are on an issued document, this class is
 * never consulted about that document again — which is precisely the point,
 * because an order can be edited afterwards and a delivery note cannot.
 */
final class OrderSnapshot {

	/**
	 * Meta keys the Italian VAT number is commonly stored under.
	 *
	 * There is no standard: every Italian checkout plugin invented its own. The
	 * list is what the popular ones use, tried in order, and a shop with a
	 * plugin nobody has heard of adds its key with the filter below rather than
	 * editing this file.
	 *
	 * @return list<string>
	 */
	public static function vat_meta_keys(): array {
		return array(
			'_billing_vat',
			'_billing_vat_number',
			'_billing_piva',
			'_billing_partita_iva',
			'_billing_wc_piva_cf_piva',
			'_vat_number',
			'vat_number',
		);
	}

	/**
	 * Meta keys the codice fiscale is commonly stored under.
	 *
	 * @return list<string>
	 */
	public static function tax_code_meta_keys(): array {
		return array(
			'_billing_cf',
			'_billing_codice_fiscale',
			'_billing_fiscal_code',
			'_billing_wc_piva_cf_cf',
			'_cf',
		);
	}

	/**
	 * Who the goods are for.
	 *
	 * The company name wins over the person's when there is one: a delivery note
	 * addressed to "Mario Rossi" when the invoice says "Rossi S.r.l." is a
	 * document somebody will have to explain.
	 *
	 * @param WC_Order $order The order.
	 * @return Party
	 */
	public static function recipient( WC_Order $order ): Party {
		$company = trim( $order->get_billing_company() );
		$person  = trim( $order->get_formatted_billing_full_name() );

		$tax_ids = self::tax_ids( $order );

		$recipient = new Party(
			'' !== $company ? $company : $person,
			new Address(
				trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
				$order->get_billing_postcode(),
				$order->get_billing_city(),
				strtoupper( $order->get_billing_state() ),
				strtoupper( $order->get_billing_country() )
			),
			$tax_ids['vat_number'],
			$tax_ids['tax_code'],
			$order->get_billing_email(),
			$order->get_billing_phone()
		);

		/**
		 * Filters the recipient taken from an order.
		 *
		 * The last chance to correct what a shop's own checkout stores in places
		 * this plugin cannot guess. Whatever comes back is what gets frozen onto
		 * the document.
		 *
		 * @since 0.1.0
		 *
		 * @param Party    $recipient The recipient as read from the order.
		 * @param WC_Order $order     The order.
		 */
		$filtered = apply_filters( 'oxyddt_order_recipient', $recipient, $order );

		return $filtered instanceof Party ? $filtered : $recipient;
	}

	/**
	 * Where the goods are actually going.
	 *
	 * Null when the order has no shipping address of its own, which is how the
	 * document says "the same place as the recipient" rather than printing an
	 * address block made of empty strings.
	 *
	 * @param WC_Order $order The order.
	 * @return Address|null
	 */
	public static function destination( WC_Order $order ): ?Address {
		$street = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );

		$destination = '' === $street && '' === trim( $order->get_shipping_city() )
			? null
			: new Address(
				$street,
				$order->get_shipping_postcode(),
				$order->get_shipping_city(),
				strtoupper( $order->get_shipping_state() ),
				strtoupper( $order->get_shipping_country() )
			);

		/**
		 * Filters where the goods are going.
		 *
		 * @since 0.1.0
		 *
		 * @param Address|null $destination The destination, or null for the recipient's own address.
		 * @param WC_Order     $order       The order.
		 */
		$filtered = apply_filters( 'oxyddt_order_destination', $destination, $order );

		if ( $filtered instanceof Address || null === $filtered ) {
			return $filtered;
		}

		// A filter that returned something else has said nothing usable. Dropping
		// the destination there would silently ship the goods to the billing
		// address, so what was read from the order stands.
		return $destination;
	}

	/**
	 * The order's lines, as document lines, at their full ordered quantity.
	 *
	 * Full quantity because this is the raw material: what actually goes on a
	 * document is what the person preparing it types, against what is still
	 * outstanding. Sprint 3 is what works that out.
	 *
	 * Only product lines. Shipping, fees and taxes are not goods, and a delivery
	 * note lists goods.
	 *
	 * @param WC_Order $order The order.
	 * @return list<Line>
	 */
	public static function lines( WC_Order $order ): array {
		$lines    = array();
		$position = 0;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$sku     = $product instanceof \WC_Product ? (string) $product->get_sku() : '';

			$line = new Line(
				(string) $item->get_name(),
				(float) $item->get_quantity(),
				$sku,
				'',
				'',
				$order->get_id(),
				(int) $item_id,
				$item->get_product_id(),
				$item->get_variation_id(),
				// The unit price net of tax and after any discount: the figure a
				// shop that chooses to print prices expects to see.
				(float) $order->get_item_total( $item, false, false ),
				$position
			);

			/**
			 * Filters one line taken from an order.
			 *
			 * Where a shop teaches the plugin about units of measure, its own
			 * product codes, or lines it does not want on a delivery note at all —
			 * return null and the line is left out.
			 *
			 * @since 0.1.0
			 *
			 * @param Line                  $line  The line as read from the order.
			 * @param WC_Order_Item_Product $item  The order line.
			 * @param WC_Order              $order The order.
			 */
			$filtered = apply_filters( 'oxyddt_order_line', $line, $item, $order );

			if ( ! $filtered instanceof Line ) {
				continue;
			}

			$lines[] = $filtered;
			++$position;
		}

		/**
		 * Filters the whole list of lines taken from an order.
		 *
		 * @since 0.1.0
		 *
		 * @param list<Line> $lines The lines.
		 * @param WC_Order   $order The order.
		 */
		$all = apply_filters( 'oxyddt_order_lines', $lines, $order );

		return is_array( $all ) ? array_values( array_filter( $all, static fn ( $line ): bool => $line instanceof Line ) ) : $lines;
	}

	/**
	 * The customer account behind the order, 0 for a guest.
	 *
	 * @param WC_Order $order The order.
	 * @return int
	 */
	public static function customer_id( WC_Order $order ): int {
		return (int) $order->get_customer_id();
	}

	/**
	 * The two Italian tax identifiers, wherever the checkout put them.
	 *
	 * @param WC_Order $order The order.
	 * @return array{vat_number: string, tax_code: string}
	 */
	private static function tax_ids( WC_Order $order ): array {
		$ids = array(
			'vat_number' => self::first_meta( $order, self::vat_meta_keys() ),
			'tax_code'   => self::first_meta( $order, self::tax_code_meta_keys() ),
		);

		/**
		 * Filters the customer's tax identifiers taken from an order.
		 *
		 * @since 0.1.0
		 *
		 * @param array{vat_number: string, tax_code: string} $ids   The identifiers.
		 * @param WC_Order                                    $order The order.
		 */
		$filtered = apply_filters( 'oxyddt_order_tax_ids', $ids, $order );

		return array(
			'vat_number' => isset( $filtered['vat_number'] ) && is_scalar( $filtered['vat_number'] )
				? (string) $filtered['vat_number']
				: $ids['vat_number'],
			'tax_code'   => isset( $filtered['tax_code'] ) && is_scalar( $filtered['tax_code'] )
				? (string) $filtered['tax_code']
				: $ids['tax_code'],
		);
	}

	/**
	 * The first of several meta keys that holds anything.
	 *
	 * @param WC_Order     $order The order.
	 * @param list<string> $keys  The keys, in order of preference.
	 * @return string
	 */
	private static function first_meta( WC_Order $order, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = $order->get_meta( $key );

			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}

		return '';
	}
}
