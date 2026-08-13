<?php
/**
 * What is left of an order.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * The calculation the whole product turns on.
 *
 * Given what was ordered and every delivery note made from that order, it says
 * for each line how much has gone out, how much is spoken for by a draft, and
 * how much can still go on a new document. Nothing about WordPress, nothing
 * about WooCommerce, nothing about a database: the arithmetic that a shop's
 * money depends on is a few dozen lines of plain PHP with a test for every
 * branch, and that is deliberate.
 *
 * Two rules that are easy to get wrong and expensive to get wrong:
 *
 * A **cancelled** document ships nothing. Its quantities go back to the order,
 * because that is what cancelling means.
 *
 * A document being **edited** must not count against itself. Reopening a draft
 * for four of the six remaining pieces would otherwise show two available and
 * refuse the four that are already on it.
 */
final class Fulfilment {

	/**
	 * The lines.
	 *
	 * @var list<FulfilmentLine>
	 */
	private array $lines;

	/**
	 * Build the calculation.
	 *
	 * @param list<FulfilmentLine> $lines The lines.
	 */
	public function __construct( array $lines = array() ) {
		$this->lines = array_values( $lines );
	}

	/**
	 * Work out what is left of an order.
	 *
	 * @param list<Line>     $ordered   The order's lines, at their ordered quantities.
	 * @param list<Document> $documents Every delivery note made from that order.
	 * @param int            $excluding The document being edited, 0 for a new one.
	 * @return self
	 */
	public static function for_order( array $ordered, array $documents, int $excluding = 0 ): self {
		$lines = array();

		foreach ( $ordered as $line ) {
			$shipped  = 0.0;
			$reserved = 0.0;

			foreach ( $documents as $document ) {
				if ( $excluding > 0 && $document->id === $excluding ) {
					continue;
				}

				if ( ! $document->status->counts_as_shipped() ) {
					continue;
				}

				$quantity = $document->quantity_for( $line->order_id, $line->order_item_id );

				if ( $document->status->is_numbered() ) {
					$shipped += $quantity;

					continue;
				}

				$reserved += $quantity;
			}

			$lines[] = new FulfilmentLine( $line, $shipped, $reserved );
		}

		return new self( $lines );
	}

	/**
	 * The lines, in the order they were given.
	 *
	 * @return list<FulfilmentLine>
	 */
	public function lines(): array {
		return $this->lines;
	}

	/**
	 * One line, by the order line it belongs to.
	 *
	 * @param int $order_item_id The order line.
	 * @return FulfilmentLine|null
	 */
	public function line( int $order_item_id ): ?FulfilmentLine {
		foreach ( $this->lines as $line ) {
			if ( $line->ordered->order_item_id === $order_item_id ) {
				return $line;
			}
		}

		return null;
	}

	/**
	 * How far the order has been fulfilled.
	 *
	 * Drafts do not count. Somebody preparing a document has shipped nothing,
	 * and an order that reports itself fulfilled because of a draft nobody ever
	 * issued is an order a shop stops chasing.
	 *
	 * @return FulfilmentStatus
	 */
	public function status(): FulfilmentStatus {
		if ( array() === $this->lines ) {
			return FulfilmentStatus::None;
		}

		$shipped  = 0.0;
		$complete = true;

		foreach ( $this->lines as $line ) {
			$shipped += $line->shipped;

			if ( ! $line->is_complete() ) {
				$complete = false;
			}
		}

		if ( $shipped <= Line::EPSILON ) {
			return FulfilmentStatus::None;
		}

		return $complete ? FulfilmentStatus::Complete : FulfilmentStatus::Partial;
	}

	/**
	 * Whether anything at all can still go out.
	 *
	 * @return bool
	 */
	public function has_anything_available(): bool {
		foreach ( $this->lines as $line ) {
			if ( $line->available() > Line::EPSILON ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * How much was ordered altogether.
	 *
	 * @return float
	 */
	public function total_ordered(): float {
		$total = 0.0;

		foreach ( $this->lines as $line ) {
			$total += $line->quantity();
		}

		return $total;
	}

	/**
	 * How much has gone out altogether.
	 *
	 * @return float
	 */
	public function total_shipped(): float {
		$total = 0.0;

		foreach ( $this->lines as $line ) {
			$total += $line->shipped;
		}

		return $total;
	}

	/**
	 * How many lines have been shipped in full.
	 *
	 * @return int
	 */
	public function completed_lines(): int {
		$complete = 0;

		foreach ( $this->lines as $line ) {
			if ( $line->is_complete() ) {
				++$complete;
			}
		}

		return $complete;
	}

	/**
	 * A document made of everything that can still go out.
	 *
	 * What the create screen offers before anybody types anything: the whole
	 * remainder, which is what most people want most of the time.
	 *
	 * @return list<Line>
	 */
	public function everything_available(): array {
		$lines = array();

		foreach ( $this->lines as $line ) {
			if ( $line->available() <= Line::EPSILON ) {
				continue;
			}

			$lines[] = $line->proposal( $line->available() );
		}

		return $lines;
	}

	/**
	 * Which proposed lines ask for more than is available.
	 *
	 * Returns the offending lines rather than a yes or no, because the screen
	 * has to say *which* line is the problem and by how much. An empty result
	 * means the proposal fits.
	 *
	 * @param list<Line> $proposed What somebody wants to put on the document.
	 * @return list<array{line: Line, available: float}>
	 */
	public function exceeding( array $proposed ): array {
		$wanted = array();

		// Summed first: the same order line can appear twice on one document —
		// two pallets of the same product, listed separately — and each one on
		// its own can fit while together they do not.
		foreach ( $proposed as $line ) {
			$key = $line->order_item_id;

			$wanted[ $key ] = ( $wanted[ $key ] ?? 0.0 ) + $line->quantity;
		}

		$exceeding = array();

		foreach ( $proposed as $line ) {
			$available = $this->available_for( $line->order_item_id );

			if ( ( $wanted[ $line->order_item_id ] ?? 0.0 ) <= $available + Line::EPSILON ) {
				continue;
			}

			$exceeding[ $line->order_item_id ] = array(
				'line'      => $line,
				'available' => $available,
			);
		}

		return array_values( $exceeding );
	}

	/**
	 * How much of one order line can still go out.
	 *
	 * A line the order does not have has nothing available: a document cannot
	 * fulfil something that was never ordered, and answering zero here is what
	 * makes the check above refuse it.
	 *
	 * @param int $order_item_id The order line.
	 * @return float
	 */
	private function available_for( int $order_item_id ): float {
		$line = $this->line( $order_item_id );

		return null === $line ? 0.0 : $line->available();
	}
}
