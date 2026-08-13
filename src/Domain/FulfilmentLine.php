<?php
/**
 * One line of an order, and what has become of it.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * Ordered, gone out, spoken for, still available.
 *
 * Four numbers, and the difference between the last two is the one thing a
 * generic packing-slip plugin never gets right.
 *
 * *Shipped* counts issued documents. *Reserved* counts drafts that somebody is
 * preparing right now: they have shipped nothing, but sending the same goods
 * twice because two people had the same order open is exactly the mistake this
 * product exists to prevent. *Available* is what is left after both.
 *
 * *Outstanding* ignores drafts on purpose: it is what the customer is still
 * owed, which is a different question and the one the order screen answers.
 */
final class FulfilmentLine {

	/**
	 * Build the line.
	 *
	 * @param Line  $ordered  The order line, at its full ordered quantity.
	 * @param float $shipped  How much has gone out on issued documents.
	 * @param float $reserved How much is on drafts being prepared.
	 */
	public function __construct(
		public readonly Line $ordered,
		public readonly float $shipped = 0.0,
		public readonly float $reserved = 0.0
	) {
	}

	/**
	 * How much was ordered.
	 *
	 * @return float
	 */
	public function quantity(): float {
		return $this->ordered->quantity;
	}

	/**
	 * How much can go on a new document.
	 *
	 * @return float
	 */
	public function available(): float {
		return max( 0.0, $this->quantity() - $this->shipped - $this->reserved );
	}

	/**
	 * How much the customer is still owed, drafts notwithstanding.
	 *
	 * @return float
	 */
	public function outstanding(): float {
		return max( 0.0, $this->quantity() - $this->shipped );
	}

	/**
	 * Whether this line has been shipped in full.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return $this->shipped + Line::EPSILON >= $this->quantity();
	}

	/**
	 * Whether more has gone out than was ordered.
	 *
	 * Possible, and not always a mistake: a shop can be authorised to send more
	 * than the order says. It is worth being able to see.
	 *
	 * @return bool
	 */
	public function is_over_shipped(): bool {
		return $this->shipped > $this->quantity() + Line::EPSILON;
	}

	/**
	 * The same line with a different quantity proposed for a new document.
	 *
	 * @param float $quantity What is being put on the new document.
	 * @return Line
	 */
	public function proposal( float $quantity ): Line {
		return $this->ordered->with_quantity( $quantity );
	}
}
