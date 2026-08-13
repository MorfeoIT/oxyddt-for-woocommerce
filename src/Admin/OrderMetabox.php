<?php
/**
 * The delivery notes of an order, on the order screen.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Domain\FulfilmentStatus;
use Oxysoft\OxyDDT\Infrastructure\Registrable;
use Oxysoft\OxyDDT\Security\Capabilities;
use Oxysoft\OxyDDT\WooCommerce\OrderFulfilment;
use WC_Order;
use WP_Post;

/**
 * A box saying what has gone out, and a button to send some more.
 *
 * This is where the plugin is actually used. Somebody looking at an order wants
 * two facts — what has already been sent, and what is left — and one button.
 *
 * Registered against whichever screen the shop's orders live on, which is not
 * the same screen with the high-performance order tables switched on as
 * without. Asking WooCommerce is the only way to be right in both cases.
 */
final class OrderMetabox implements Registrable {

	/**
	 * What is left of an order.
	 *
	 * @var OrderFulfilment
	 */
	private OrderFulfilment $fulfilment;

	/**
	 * Build the box.
	 *
	 * @param OrderFulfilment $fulfilment What is left of an order.
	 */
	public function __construct( OrderFulfilment $fulfilment ) {
		$this->fulfilment = $fulfilment;
	}

	/**
	 * Add the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
	}

	/**
	 * Add the box.
	 *
	 * @return void
	 */
	public function add(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}

		add_meta_box(
			'oxyddt-documents',
			__( 'Delivery notes (DDT)', 'oxyddt-for-woocommerce' ),
			array( $this, 'render' ),
			self::screen_id(),
			'side',
			'default'
		);
	}

	/**
	 * Draw the box.
	 *
	 * @param WP_Post|WC_Order $subject Whatever WordPress or WooCommerce passed in.
	 * @return void
	 */
	public function render( $subject ): void {
		$order = $subject instanceof WC_Order ? $subject : wc_get_order( $subject instanceof WP_Post ? $subject->ID : 0 );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$fulfilment = $this->fulfilment->for_order( $order );
		$documents  = $this->fulfilment->documents_for( $order );

		echo '<p><strong>' . esc_html( self::status_label( $fulfilment->status() ) ) . '</strong><br />';
		echo esc_html(
			sprintf(
				/* translators: 1: quantity sent, 2: quantity ordered. */
				__( '%1$s of %2$s sent', 'oxyddt-for-woocommerce' ),
				EditScreen::quantity( $fulfilment->total_shipped() ),
				EditScreen::quantity( $fulfilment->total_ordered() )
			)
		);
		echo '</p>';

		if ( array() === $documents ) {
			echo '<p class="description">' . esc_html__( 'Nothing has been prepared for this order yet.', 'oxyddt-for-woocommerce' ) . '</p>';
		} else {
			echo '<ul style="margin-left:1em;list-style:disc">';

			foreach ( $documents as $document ) {
				echo '<li>' . self::document_line( $document ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in document_line().
			}

			echo '</ul>';
		}

		if ( ! current_user_can( Capabilities::CREATE ) ) {
			return;
		}

		echo '<p><a class="button button-primary" href="' . esc_url( EditScreen::url( $order->get_id() ) ) . '">'
			. esc_html__( 'New delivery note', 'oxyddt-for-woocommerce' ) . '</a></p>';

		if ( $fulfilment->has_anything_available() ) {
			return;
		}

		echo '<p class="description">'
			. esc_html__( 'Everything on this order is either sent or on a draft.', 'oxyddt-for-woocommerce' )
			. '</p>';
	}

	/**
	 * One document, as a line in the list.
	 *
	 * @param Document $document The document.
	 * @return string Escaped HTML.
	 */
	private static function document_line( Document $document ): string {
		$order_ids = $document->all_order_ids();

		$name = $document->number->is_assigned()
			? $document->number->formatted
			: __( 'Draft', 'oxyddt-for-woocommerce' );

		$label = sprintf(
			'%1$s — %2$s — %3$s',
			$name,
			null === $document->document_date ? '—' : $document->document_date,
			self::document_status_label( $document->status )
		);

		$link = '<a href="' . esc_url( EditScreen::url( (int) ( $order_ids[0] ?? 0 ), $document->id ) ) . '">'
			. esc_html( $label ) . '</a>';

		return DocumentStatus::Cancelled === $document->status ? '<del>' . $link . '</del>' : $link;
	}

	/**
	 * Which admin screen the shop's orders are on.
	 *
	 * @return string
	 */
	private static function screen_id(): string {
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return wc_get_page_screen_id( 'shop-order' );
		}

		return 'shop_order';
	}

	/**
	 * How far the order has been fulfilled, in words.
	 *
	 * @param FulfilmentStatus $status The status.
	 * @return string
	 */
	private static function status_label( FulfilmentStatus $status ): string {
		switch ( $status ) {
			case FulfilmentStatus::Complete:
				return __( 'Fully sent', 'oxyddt-for-woocommerce' );
			case FulfilmentStatus::Partial:
				return __( 'Partly sent', 'oxyddt-for-woocommerce' );
			default:
				return __( 'Nothing sent yet', 'oxyddt-for-woocommerce' );
		}
	}

	/**
	 * What a document's state is called.
	 *
	 * @param DocumentStatus $status The status.
	 * @return string
	 */
	private static function document_status_label( DocumentStatus $status ): string {
		switch ( $status ) {
			case DocumentStatus::Issued:
				return __( 'issued', 'oxyddt-for-woocommerce' );
			case DocumentStatus::Cancelled:
				return __( 'cancelled', 'oxyddt-for-woocommerce' );
			default:
				return __( 'draft', 'oxyddt-for-woocommerce' );
		}
	}
}
