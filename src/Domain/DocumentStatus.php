<?php
/**
 * Where a document is in its life.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * Draft, issued, cancelled — and nothing else in the free plugin.
 *
 * The three are not labels on the same thing. A draft is a working document
 * that has taken no number and can still be changed. An issued one has consumed
 * a number that will never be used again, and is finished: correcting it means
 * cancelling it and writing another. A cancelled one stays in the register,
 * because a number that vanished is worse than a number that says why it is
 * void.
 *
 * PRO adds prepared, delivered and signed. Those are stages of a shipment and
 * sit alongside these rather than replacing them.
 */
enum DocumentStatus: string {

	/**
	 * Being prepared. No number, and every field still open.
	 */
	case Draft = 'draft';

	/**
	 * Issued. Numbered, dated and closed.
	 */
	case Issued = 'issued';

	/**
	 * Void, with a reason, and still in the register.
	 */
	case Cancelled = 'cancelled';

	/**
	 * Read a status that came from the database or a request.
	 *
	 * Anything unrecognised is a draft: the only status that grants nothing.
	 *
	 * @param string $value Stored value.
	 * @return self
	 */
	public static function from_string( string $value ): self {
		return self::tryFrom( $value ) ?? self::Draft;
	}

	/**
	 * Whether the document may still be changed.
	 *
	 * @return bool
	 */
	public function is_editable(): bool {
		return self::Draft === $this;
	}

	/**
	 * Whether the document has taken a number.
	 *
	 * @return bool
	 */
	public function is_numbered(): bool {
		return self::Draft !== $this;
	}

	/**
	 * Whether the document counts towards what has been shipped.
	 *
	 * A cancelled document ships nothing: its quantities go back to the order's
	 * outstanding balance. This is the single most consequential line in the
	 * plugin, and sprint 3 leans on it.
	 *
	 * @return bool
	 */
	public function counts_as_shipped(): bool {
		return self::Cancelled !== $this;
	}
}
