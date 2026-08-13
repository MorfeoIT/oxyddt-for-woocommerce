<?php
/**
 * Making a draft out of an order.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\WooCommerce;

use Oxysoft\OxyDDT\Domain\Causals;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentNumber;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Domain\Lifecycle;
use Oxysoft\OxyDDT\Domain\Parties;
use Oxysoft\OxyDDT\Domain\Transport;
use Oxysoft\OxyDDT\Infrastructure\ClockInterface;
use Oxysoft\OxyDDT\Settings\Settings;
use WC_Order;

/**
 * The bridge from an order to a document nobody has typed anything into yet.
 *
 * It fills in everything that can be known without asking: the shop as it is
 * configured today, the customer as the order reads today, every product line at
 * its full ordered quantity, today's date and the obvious reason. What it
 * deliberately does not do is decide how much actually goes out — that is a
 * person's decision, and sprint 3 is the screen where they make it.
 *
 * The draft it returns has no number. Numbers are taken at the moment of issue,
 * under a lock, and never before: a draft that reserved a number and was then
 * abandoned would leave a hole in the register that a shop has to account for.
 */
final class DocumentFactory {

	/**
	 * The settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The clock.
	 *
	 * @var ClockInterface
	 */
	private ClockInterface $clock;

	/**
	 * Build the factory.
	 *
	 * @param Settings       $settings The settings.
	 * @param ClockInterface $clock    The clock.
	 */
	public function __construct( Settings $settings, ClockInterface $clock ) {
		$this->settings = $settings;
		$this->clock    = $clock;
	}

	/**
	 * A draft delivery note for an order.
	 *
	 * @param WC_Order $order The order.
	 * @return Document
	 */
	public function draft_from_order( WC_Order $order ): Document {
		$parties = new Parties(
			$this->settings->company(),
			OrderSnapshot::recipient( $order ),
			OrderSnapshot::destination( $order )
		);

		$draft = new Document(
			0,
			DocumentStatus::Draft,
			DocumentNumber::none(),
			// The document date is a local fact. A note prepared at half past
			// midnight in Rome is dated the day it is dated in Rome, whatever UTC
			// thinks, and that matters on the first of January.
			$this->clock->local()->format( 'Y-m-d' ),
			$parties,
			new Transport(),
			Causals::SALE,
			OrderSnapshot::lines( $order ),
			array( $order->get_id() ),
			'',
			OrderSnapshot::customer_id( $order ),
			new Lifecycle()
		);

		/**
		 * Filters a draft delivery note just built from an order.
		 *
		 * Everything is still open at this point: the document has no number, no
		 * date it is committed to and nothing stored. A shop that always ships
		 * with the same carrier, or always uses a particular reason, sets it here.
		 *
		 * @since 0.1.0
		 *
		 * @param Document $draft The draft.
		 * @param WC_Order $order The order it was built from.
		 */
		$filtered = apply_filters( 'oxyddt_draft_from_order', $draft, $order );

		return $filtered instanceof Document ? $filtered : $draft;
	}
}
