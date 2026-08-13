<?php
/**
 * What is left of an order, for a real WooCommerce order.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\WooCommerce;

use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentRepositoryInterface;
use Oxysoft\OxyDDT\Domain\Fulfilment;
use WC_Order;

/**
 * Puts the order and its documents in front of the calculation.
 *
 * The calculation itself knows nothing about WooCommerce and is tested without
 * it. This is the thin piece that fetches the two lists and hands them over, so
 * that the screens have one thing to call and no arithmetic of their own.
 */
final class OrderFulfilment {

	/**
	 * The document store.
	 *
	 * @var DocumentRepositoryInterface
	 */
	private DocumentRepositoryInterface $documents;

	/**
	 * Build the service.
	 *
	 * @param DocumentRepositoryInterface $documents The document store.
	 */
	public function __construct( DocumentRepositoryInterface $documents ) {
		$this->documents = $documents;
	}

	/**
	 * What is left of an order.
	 *
	 * @param WC_Order $order     The order.
	 * @param int      $excluding The document being edited, 0 for a new one.
	 * @return Fulfilment
	 */
	public function for_order( WC_Order $order, int $excluding = 0 ): Fulfilment {
		return Fulfilment::for_order(
			OrderSnapshot::lines( $order ),
			$this->documents_for( $order ),
			$excluding
		);
	}

	/**
	 * Every delivery note made from an order, oldest first.
	 *
	 * Cancelled ones included: they belong to the order's history, and the box
	 * on the order screen shows them struck through rather than hiding them.
	 *
	 * @param WC_Order $order The order.
	 * @return list<Document>
	 */
	public function documents_for( WC_Order $order ): array {
		return $this->documents->for_order( $order->get_id() );
	}
}
