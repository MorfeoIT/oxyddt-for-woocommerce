<?php
/**
 * A delivery note.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/*
 * The exception below is developer-facing: it names a document and a status,
 * both written by this plugin, and it describes an attempt to edit an issued
 * delivery note — a mistake only code can make. It is not escaped because the
 * Domain layer has to stay loadable without WordPress, where esc_html() does not
 * exist, which is the whole reason the model can be tested in a millisecond.
 *
 * Disabled inline rather than in phpcs.xml.dist because the plugin directory's
 * own check ignores this project's ruleset and reads only the code.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * The document itself: what left, for whom, why, and under whose care.
 *
 * Immutable. Every change returns a new instance, and every change is refused
 * outright once the document has been issued. That is not a stylistic
 * preference: "an issued delivery note does not change" is the promise the
 * product is sold on, and a promise enforced by convention is a promise that
 * lasts until the first hurried afternoon.
 *
 * The class knows nothing about WordPress, WooCommerce or a database. What it
 * knows is what a delivery note is, which is why the rules that matter can be
 * proved in a test that runs in a millisecond.
 */
final class Document {

	/**
	 * Build a document.
	 *
	 * @param int            $id           Row identifier, 0 before it is stored.
	 * @param DocumentStatus $status       Draft, issued or cancelled.
	 * @param DocumentNumber $number       The number, absent until it is issued.
	 * @param string|null    $document_date The date printed on it, "Y-m-d".
	 * @param Parties        $parties      Sender, recipient and destination.
	 * @param Transport      $transport    How the goods travel.
	 * @param string         $causal       Why they are moving.
	 * @param list<Line>     $lines        What is going out.
	 * @param list<int>      $order_ids    Orders this document draws on.
	 * @param string         $notes        Anything else printed on it.
	 * @param int            $customer_id  The WordPress user, 0 for a guest.
	 * @param Lifecycle      $lifecycle    When things happened, and who did them.
	 */
	public function __construct(
		public readonly int $id = 0,
		public readonly DocumentStatus $status = DocumentStatus::Draft,
		public readonly DocumentNumber $number = new DocumentNumber(),
		public readonly ?string $document_date = null,
		public readonly Parties $parties = new Parties(),
		public readonly Transport $transport = new Transport(),
		public readonly string $causal = '',
		public readonly array $lines = array(),
		public readonly array $order_ids = array(),
		public readonly string $notes = '',
		public readonly int $customer_id = 0,
		public readonly Lifecycle $lifecycle = new Lifecycle()
	) {
	}

	/**
	 * Whether the document may still be changed.
	 *
	 * @return bool
	 */
	public function is_editable(): bool {
		return $this->status->is_editable();
	}

	/**
	 * Every order this document touches, whether through a line or a link.
	 *
	 * A draft started from an order and not yet filled in is still that order's
	 * document, which is why the link is kept as well as derived.
	 *
	 * @return list<int>
	 */
	public function all_order_ids(): array {
		$ids = $this->order_ids;

		foreach ( $this->lines as $line ) {
			if ( $line->order_id > 0 ) {
				$ids[] = $line->order_id;
			}
		}

		$unique = array_values( array_unique( $ids ) );
		sort( $unique );

		return $unique;
	}

	/**
	 * How much is on the document altogether.
	 *
	 * @return float
	 */
	public function total_quantity(): float {
		$total = 0.0;

		foreach ( $this->lines as $line ) {
			$total += $line->quantity;
		}

		return $total;
	}

	/**
	 * How much of one order line this document takes.
	 *
	 * The question sprint 3 asks of every document of an order, and the reason
	 * order_item_id is carried on every line.
	 *
	 * @param int $order_id      The order.
	 * @param int $order_item_id The line of that order.
	 * @return float
	 */
	public function quantity_for( int $order_id, int $order_item_id ): float {
		$total = 0.0;

		foreach ( $this->lines as $line ) {
			if ( $line->fulfils( $order_id, $order_item_id ) ) {
				$total += $line->quantity;
			}
		}

		return $total;
	}

	/**
	 * The same document with different lines.
	 *
	 * Empty lines are dropped rather than stored: a description with no quantity
	 * is something somebody started typing, not something that left the
	 * warehouse. The rest are renumbered so that the order on screen is the order
	 * on paper.
	 *
	 * @param list<Line> $lines The lines.
	 * @return self
	 *
	 * @throws DocumentException If the document has already been issued.
	 */
	public function with_lines( array $lines ): self {
		$this->refuse_if_closed( 'lines' );

		$kept     = array_values( array_filter( $lines, static fn ( Line $line ): bool => ! $line->is_empty() ) );
		$ordered  = array();
		$position = 0;

		foreach ( $kept as $line ) {
			$ordered[] = $line->with_sort_order( $position );
			++$position;
		}

		return $this->copy( array( 'lines' => $ordered ) );
	}

	/**
	 * The same document with a different header.
	 *
	 * @param Parties $parties Sender, recipient and destination.
	 * @return self
	 *
	 * @throws DocumentException If the document has already been issued.
	 */
	public function with_parties( Parties $parties ): self {
		$this->refuse_if_closed( 'parties' );

		return $this->copy( array( 'parties' => $parties ) );
	}

	/**
	 * The same document with a different transport block.
	 *
	 * @param Transport $transport How the goods travel.
	 * @return self
	 *
	 * @throws DocumentException If the document has already been issued.
	 */
	public function with_transport( Transport $transport ): self {
		$this->refuse_if_closed( 'transport' );

		return $this->copy( array( 'transport' => $transport ) );
	}

	/**
	 * The same document with a different reason, date, notes or customer.
	 *
	 * @param array{causal?: string, document_date?: string|null, notes?: string, customer_id?: int, order_ids?: list<int>} $details What to change.
	 * @return self
	 *
	 * @throws DocumentException If the document has already been issued.
	 */
	public function with_details( array $details ): self {
		$this->refuse_if_closed( 'details' );

		return $this->copy( $details );
	}

	/**
	 * The same document, saved.
	 *
	 * Called by the repository once the row has an identifier and a timestamp.
	 * It is the one change allowed on a closed document, because it records that
	 * the document was written down rather than altering anything on it.
	 *
	 * @param int       $id        Row identifier.
	 * @param Lifecycle $lifecycle Updated timestamps.
	 * @return self
	 */
	public function stored( int $id, Lifecycle $lifecycle ): self {
		return $this->copy(
			array(
				'id'        => $id,
				'lifecycle' => $lifecycle,
			)
		);
	}

	/**
	 * What stands between this document and being issued.
	 *
	 * Sprint 4 is what refuses to issue on the strength of this. Sprint 2 only
	 * has to be able to answer the question, honestly, before anybody has
	 * consumed a number.
	 *
	 * @return list<string> Error codes, empty when the document could be issued.
	 */
	public function errors(): array {
		$errors = $this->parties->errors();

		foreach ( $this->transport->errors() as $code ) {
			$errors[] = 'transport.' . $code;
		}

		if ( array() === $this->lines ) {
			$errors[] = 'lines_missing';
		}

		if ( '' === trim( $this->causal ) ) {
			$errors[] = 'causal_missing';
		}

		if ( null === $this->document_date || '' === $this->document_date ) {
			$errors[] = 'date_missing';
		}

		return $errors;
	}

	/**
	 * Whether the document could be issued as it stands.
	 *
	 * @return bool
	 */
	public function is_ready_to_issue(): bool {
		return array() === $this->errors();
	}

	/**
	 * Refuse to change a document that is no longer open.
	 *
	 * @param string $what What was being changed.
	 * @return void
	 *
	 * @throws DocumentException Always, when the document is not a draft.
	 */
	private function refuse_if_closed( string $what ): void {
		if ( $this->is_editable() ) {
			return;
		}

		throw new DocumentException(
			sprintf(
				'The %1$s of delivery note %2$s cannot be changed: it is %3$s. Cancel it and issue another.',
				$what,
				'' === $this->number->formatted ? (string) $this->id : $this->number->formatted,
				$this->status->value
			)
		);
	}

	/**
	 * The same document with some fields replaced.
	 *
	 * @param array<string, mixed> $changes Fields to replace.
	 * @return self
	 */
	private function copy( array $changes ): self {
		/**
		 * The current values, by name.
		 *
		 * @var array<string, mixed> $current
		 */
		$current = array(
			'id'            => $this->id,
			'status'        => $this->status,
			'number'        => $this->number,
			'document_date' => $this->document_date,
			'parties'       => $this->parties,
			'transport'     => $this->transport,
			'causal'        => $this->causal,
			'lines'         => $this->lines,
			'order_ids'     => $this->order_ids,
			'notes'         => $this->notes,
			'customer_id'   => $this->customer_id,
			'lifecycle'     => $this->lifecycle,
		);

		$values = array_merge( $current, array_intersect_key( $changes, $current ) );

		return new self(
			(int) $values['id'],
			$values['status'] instanceof DocumentStatus ? $values['status'] : DocumentStatus::Draft,
			$values['number'] instanceof DocumentNumber ? $values['number'] : DocumentNumber::none(),
			is_string( $values['document_date'] ) ? $values['document_date'] : null,
			$values['parties'] instanceof Parties ? $values['parties'] : new Parties(),
			$values['transport'] instanceof Transport ? $values['transport'] : new Transport(),
			(string) $values['causal'],
			is_array( $values['lines'] ) ? array_values( $values['lines'] ) : array(),
			is_array( $values['order_ids'] ) ? array_values( array_map( 'intval', $values['order_ids'] ) ) : array(),
			(string) $values['notes'],
			(int) $values['customer_id'],
			$values['lifecycle'] instanceof Lifecycle ? $values['lifecycle'] : new Lifecycle()
		);
	}
}
