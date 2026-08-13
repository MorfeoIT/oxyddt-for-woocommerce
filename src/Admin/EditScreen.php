<?php
/**
 * Preparing a delivery note from an order.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Admin;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\Causals;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentRepositoryInterface;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Domain\Fulfilment;
use Oxysoft\OxyDDT\Domain\Line;
use Oxysoft\OxyDDT\Domain\Transport;
use Oxysoft\OxyDDT\Infrastructure\Registrable;
use Oxysoft\OxyDDT\Issuing\IssueException;
use Oxysoft\OxyDDT\Issuing\Issuer;
use Oxysoft\OxyDDT\Security\Capabilities;
use Oxysoft\OxyDDT\WooCommerce\DocumentFactory;
use Oxysoft\OxyDDT\WooCommerce\OrderFulfilment;
use WC_Order;

/**
 * The screen the product is bought for.
 *
 * One table: what was ordered, what has already gone out, what is left, and how
 * much goes on this document. Everything else on the page is the header a
 * delivery note needs around that table.
 *
 * It opens with the whole remainder filled in, because that is what most people
 * want most of the time, and it is a shorter way to say "all of it" than
 * fifteen keystrokes. Typing over it is the partial shipment.
 */
final class EditScreen implements Registrable {

	/**
	 * The page slug. Registered under WooCommerce and then hidden from the menu:
	 * it is reached from an order, never from a list of menu items.
	 */
	public const SLUG = 'oxyddt-edit';

	/**
	 * The document store.
	 *
	 * @var DocumentRepositoryInterface
	 */
	private DocumentRepositoryInterface $documents;

	/**
	 * The order-to-draft factory.
	 *
	 * @var DocumentFactory
	 */
	private DocumentFactory $drafts;

	/**
	 * What is left of an order.
	 *
	 * @var OrderFulfilment
	 */
	private OrderFulfilment $fulfilment;

	/**
	 * The register.
	 *
	 * @var AuditLog
	 */
	private AuditLog $log;

	/**
	 * Issuing and cancelling.
	 *
	 * @var Issuer
	 */
	private Issuer $issuer;

	/**
	 * Build the screen.
	 *
	 * @param DocumentRepositoryInterface $documents  The document store.
	 * @param DocumentFactory             $drafts     The order-to-draft factory.
	 * @param OrderFulfilment             $fulfilment What is left of an order.
	 * @param AuditLog                    $log        The register.
	 * @param Issuer                      $issuer     Issuing and cancelling.
	 */
	public function __construct(
		DocumentRepositoryInterface $documents,
		DocumentFactory $drafts,
		OrderFulfilment $fulfilment,
		AuditLog $log,
		Issuer $issuer
	) {
		$this->documents  = $documents;
		$this->drafts     = $drafts;
		$this->fulfilment = $fulfilment;
		$this->log        = $log;
		$this->issuer     = $issuer;
	}

	/**
	 * Add the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_oxyddt_save_document', array( $this, 'handle_save' ) );
		add_action( 'admin_post_oxyddt_delete_document', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_oxyddt_cancel_document', array( $this, 'handle_cancel' ) );
	}

	/**
	 * The address of this screen.
	 *
	 * @param int $order_id    The order.
	 * @param int $document_id The document being edited, 0 for a new one.
	 * @return string
	 */
	public static function url( int $order_id, int $document_id = 0 ): string {
		$args = array(
			'page'     => self::SLUG,
			'order_id' => $order_id,
		);

		if ( $document_id > 0 ) {
			$args['document'] = $document_id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::CREATE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to prepare delivery notes.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		// Reading which order to prepare a document for changes nothing, so it
		// needs no nonce. What it must not do is trust the number, which is why
		// the order is loaded through WooCommerce and checked.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$document_id = isset( $_GET['document'] ) ? absint( wp_unslash( $_GET['document'] ) ) : 0;

		$document = $document_id > 0 ? $this->documents->find( $document_id ) : null;

		if ( null !== $document && 0 === $order_id ) {
			$order_ids = $document->all_order_ids();
			$order_id  = (int) ( $order_ids[0] ?? 0 );
		}

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		echo '<div class="wrap">';

		if ( ! $order instanceof WC_Order ) {
			echo '<h1>' . esc_html__( 'Delivery note', 'oxyddt-for-woocommerce' ) . '</h1>';
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'That order does not exist, so there is nothing to prepare a delivery note from.', 'oxyddt-for-woocommerce' )
				. '</p></div></div>';

			return;
		}

		echo '<h1 class="wp-heading-inline">';

		echo null === $document
			? esc_html(
				sprintf(
					/* translators: %s: order number. */
					__( 'New delivery note for order %s', 'oxyddt-for-woocommerce' ),
					$order->get_order_number()
				)
			)
			: esc_html(
				sprintf(
					/* translators: 1: document number or "draft", 2: order number. */
					__( 'Delivery note %1$s for order %2$s', 'oxyddt-for-woocommerce' ),
					'' === $document->number->formatted
						? __( 'draft', 'oxyddt-for-woocommerce' )
						: $document->number->formatted,
					$order->get_order_number()
				)
			);

		echo '</h1>';

		Notices::show();

		if ( null !== $document && ! $document->is_editable() ) {
			$this->render_closed( $document );
			echo '</div>';

			return;
		}

		$fulfilment = $this->fulfilment->for_order( $order, $document_id );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'oxyddt_save_document' );
		echo '<input type="hidden" name="action" value="oxyddt_save_document" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
		echo '<input type="hidden" name="document" value="' . esc_attr( (string) $document_id ) . '" />';

		$this->render_parties( $order, $document );
		$this->render_quantities( $fulfilment, $document );
		$this->render_header( $document );
		$this->render_transport( null === $document ? new Transport() : $document->transport );

		echo '<p class="submit">';
		submit_button( __( 'Save draft', 'oxyddt-for-woocommerce' ), 'secondary', 'save', false );

		if ( current_user_can( Capabilities::ISSUE ) ) {
			echo ' ';
			submit_button( __( 'Save and issue', 'oxyddt-for-woocommerce' ), 'primary', 'issue', false );
			echo '<p class="description">'
				. esc_html__( 'Issuing takes the next number and closes the document. From then on it cannot be changed, only cancelled.', 'oxyddt-for-woocommerce' )
				. '</p>';
		}

		echo '</p>';
		echo '</form>';

		if ( null !== $document ) {
			$this->render_delete_form( $document );
		}

		echo '</div>';
	}

	/**
	 * Save the draft.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		check_admin_referer( 'oxyddt_save_document' );

		if ( ! current_user_can( Capabilities::CREATE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to prepare delivery notes.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$document_id = isset( $_POST['document'] ) ? absint( wp_unslash( $_POST['document'] ) ) : 0;

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			$this->back_to( 0, 0, 'error', __( 'That order does not exist.', 'oxyddt-for-woocommerce' ) );
		}

		$document = $document_id > 0 ? $this->documents->find( $document_id ) : null;

		if ( null !== $document && ! $document->is_editable() ) {
			$this->back_to(
				$order_id,
				$document_id,
				'error',
				__( 'This delivery note has been issued and cannot be changed. Cancel it and prepare another.', 'oxyddt-for-woocommerce' )
			);
		}

		$fulfilment = $this->fulfilment->for_order( $order, $document_id );
		$lines      = $this->lines_from_request( $fulfilment );

		if ( array() === $lines ) {
			$this->back_to(
				$order_id,
				$document_id,
				'error',
				__( 'Nothing was going out: put a quantity against at least one line.', 'oxyddt-for-woocommerce' )
			);
		}

		$exceeding = $fulfilment->exceeding( $lines );

		if ( array() !== $exceeding && ! $this->may_exceed( $order, $lines ) ) {
			$this->back_to( $order_id, $document_id, 'error', $this->describe_excess( $exceeding ) );
		}

		$draft = null === $document ? $this->drafts->draft_from_order( $order ) : $document;

		$draft = $draft
			->with_lines( $lines )
			->with_details( $this->details_from_request() )
			->with_transport( Transport::from_array( $this->transport_from_request() ) );

		$saved = $this->documents->save( $draft );

		$this->log->record(
			null === $document ? AuditLog::DOCUMENT_CREATED : AuditLog::DOCUMENT_UPDATED,
			sprintf( 'Draft delivery note for order %d was saved.', $order_id ),
			array(
				'order_id' => $order_id,
				'lines'    => count( $lines ),
				'quantity' => $saved->total_quantity(),
				'exceeded' => array() !== $exceeding,
			),
			$saved->id
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		if ( isset( $_POST['issue'] ) ) {
			$this->issue( $saved, $order_id );
		}

		$this->back_to(
			$order_id,
			$saved->id,
			array() === $exceeding ? 'success' : 'warning',
			array() === $exceeding
				? __( 'Draft saved.', 'oxyddt-for-woocommerce' )
				: __( 'Draft saved, for more than the order has left. That was allowed because you are permitted to override it.', 'oxyddt-for-woocommerce' )
		);
	}

	/**
	 * Take the next number and close the document.
	 *
	 * @param Document $draft    The saved draft.
	 * @param int      $order_id The order, for the trip back.
	 * @return never
	 */
	private function issue( Document $draft, int $order_id ): never {
		if ( ! current_user_can( Capabilities::ISSUE ) ) {
			$this->back_to(
				$order_id,
				$draft->id,
				'error',
				__( 'Saved as a draft: you are not allowed to issue delivery notes.', 'oxyddt-for-woocommerce' )
			);
		}

		try {
			$issued = $this->issuer->issue( $draft );
		} catch ( IssueException $e ) {
			$this->back_to(
				$order_id,
				$draft->id,
				'error',
				sprintf(
					/* translators: %s: what is missing, as a list. */
					__( 'Saved as a draft, but not issued: %s', 'oxyddt-for-woocommerce' ),
					implode( ', ', self::describe_codes( $e->codes() ) )
				)
			);
		}

		$this->back_to(
			$order_id,
			$issued->id,
			'success',
			sprintf(
				/* translators: %s: the number of the delivery note. */
				__( 'Issued as %s. It cannot be changed now, only cancelled.', 'oxyddt-for-woocommerce' ),
				$issued->number->formatted
			)
		);
	}

	/**
	 * Void an issued document.
	 *
	 * @return void
	 */
	public function handle_cancel(): void {
		check_admin_referer( 'oxyddt_cancel_document' );

		if ( ! current_user_can( Capabilities::CANCEL ) ) {
			wp_die(
				esc_html__( 'You are not allowed to cancel delivery notes.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$document_id = isset( $_POST['document'] ) ? absint( wp_unslash( $_POST['document'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		$document = $this->documents->find( $document_id );

		if ( null === $document ) {
			$this->back_to( $order_id, 0, 'error', __( 'That delivery note does not exist.', 'oxyddt-for-woocommerce' ) );
		}

		try {
			$cancelled = $this->issuer->cancel( $document, $reason );
		} catch ( IssueException $e ) {
			$this->back_to(
				$order_id,
				$document_id,
				'error',
				implode( ', ', self::describe_codes( $e->codes() ) )
			);
		}

		$this->back_to(
			$order_id,
			$cancelled->id,
			'success',
			sprintf(
				/* translators: %s: the number of the delivery note. */
				__( 'Delivery note %s is cancelled. Its quantities are back on the order.', 'oxyddt-for-woocommerce' ),
				$cancelled->number->formatted
			)
		);
	}

	/**
	 * Turn refusal codes into something readable.
	 *
	 * @param list<string> $codes The codes.
	 * @return list<string>
	 */
	private static function describe_codes( array $codes ): array {
		$messages = array(
			'already_issued'   => __( 'it has already been issued', 'oxyddt-for-woocommerce' ),
			'not_issued'       => __( 'only an issued delivery note can be cancelled', 'oxyddt-for-woocommerce' ),
			'reason_missing'   => __( 'a cancellation has to say why', 'oxyddt-for-woocommerce' ),
			'numbering_failed' => __( 'a number could not be settled on — try again', 'oxyddt-for-woocommerce' ),
			'lines_missing'    => __( 'nothing is on it', 'oxyddt-for-woocommerce' ),
			'causal_missing'   => __( 'the reason for transport is missing', 'oxyddt-for-woocommerce' ),
			'date_missing'     => __( 'the date is missing', 'oxyddt-for-woocommerce' ),
		);

		$described = array();

		foreach ( $codes as $code ) {
			if ( isset( $messages[ $code ] ) ) {
				$described[] = $messages[ $code ];

				continue;
			}

			if ( 0 === strpos( $code, 'sender.' ) ) {
				$described[] = __( 'the sender is not complete — see Settings', 'oxyddt-for-woocommerce' );

				continue;
			}

			if ( 0 === strpos( $code, 'recipient.' ) || 0 === strpos( $code, 'destination.' ) ) {
				$described[] = __( 'the recipient or the destination is not complete', 'oxyddt-for-woocommerce' );

				continue;
			}

			$described[] = $code;
		}

		return array_values( array_unique( $described ) );
	}

	/**
	 * Throw a draft away.
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		check_admin_referer( 'oxyddt_delete_document' );

		if ( ! current_user_can( Capabilities::CREATE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to prepare delivery notes.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$document_id = isset( $_POST['document'] ) ? absint( wp_unslash( $_POST['document'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;

		if ( ! $this->documents->delete( $document_id ) ) {
			$this->back_to(
				$order_id,
				$document_id,
				'error',
				__( 'That draft could not be deleted. An issued delivery note is cancelled, never deleted.', 'oxyddt-for-woocommerce' )
			);
		}

		$this->log->record(
			AuditLog::DOCUMENT_DELETED,
			sprintf( 'Draft delivery note %d was deleted.', $document_id ),
			array( 'order_id' => $order_id ),
			$document_id
		);

		$this->back_to( $order_id, 0, 'success', __( 'Draft deleted.', 'oxyddt-for-woocommerce' ) );
	}

	/**
	 * Whether this user may put more on a document than the order has left.
	 *
	 * Refused by default for everybody who is not trusted with the plugin's
	 * configuration, because over-shipping is either a mistake or a decision,
	 * and the two look identical from here. A shop that works differently says
	 * so with the filter.
	 *
	 * @param WC_Order   $order The order.
	 * @param list<Line> $lines What is proposed.
	 * @return bool
	 */
	private function may_exceed( WC_Order $order, array $lines ): bool {
		/**
		 * Filters whether a user may put more on a delivery note than the order
		 * has outstanding.
		 *
		 * @since 0.1.0
		 *
		 * @param bool       $allowed Whether it is allowed.
		 * @param WC_Order   $order   The order.
		 * @param list<Line> $lines   What is being put on the document.
		 */
		return (bool) apply_filters(
			'oxyddt_can_exceed_available',
			current_user_can( Capabilities::MANAGE_SETTINGS ),
			$order,
			$lines
		);
	}

	/**
	 * Say which line asked for too much, and how much was left.
	 *
	 * @param list<array{line: Line, available: float}> $exceeding What was refused.
	 * @return string
	 */
	private function describe_excess( array $exceeding ): string {
		$parts = array();

		foreach ( $exceeding as $excess ) {
			$parts[] = sprintf(
				/* translators: 1: product name, 2: quantity still available. */
				__( '%1$s: %2$s left', 'oxyddt-for-woocommerce' ),
				$excess['line']->name,
				self::quantity( $excess['available'] )
			);
		}

		return sprintf(
			/* translators: %s: list of products and their remaining quantities. */
			__( 'Nothing was saved: the order does not have that much left. %s.', 'oxyddt-for-woocommerce' ),
			implode( '; ', $parts )
		);
	}

	/**
	 * The lines somebody typed, as document lines.
	 *
	 * Quantities are read against what the order says, never against what the
	 * form says: the description, the SKU and the product all come from the
	 * order line the quantity was typed under. A form that could name its own
	 * products would be a form that could put anything on a fiscal document.
	 *
	 * @param Fulfilment $fulfilment What is left of the order.
	 * @return list<Line>
	 */
	private function lines_from_request( Fulfilment $fulfilment ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verified it.
		$posted = isset( $_POST['quantities'] ) && is_array( $_POST['quantities'] )
			? wp_unslash( $_POST['quantities'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Read as numbers below; the caller verified the nonce.
			: array();

		$lines = array();

		foreach ( $fulfilment->lines() as $available ) {
			$key = (string) $available->ordered->order_item_id;

			if ( ! isset( $posted[ $key ] ) || ! is_scalar( $posted[ $key ] ) ) {
				continue;
			}

			$quantity = self::to_quantity( (string) $posted[ $key ] );

			if ( $quantity <= Line::EPSILON ) {
				continue;
			}

			$lines[] = $available->proposal( $quantity );
		}

		return $lines;
	}

	/**
	 * The date, the reason and the notes.
	 *
	 * @return array{causal: string, document_date: string|null, notes: string}
	 */
	private function details_from_request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verified it.
		$date = isset( $_POST['document_date'] ) ? sanitize_text_field( wp_unslash( $_POST['document_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verified it.
		$causal = isset( $_POST['causal'] ) ? sanitize_text_field( wp_unslash( $_POST['causal'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verified it.
		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		return array(
			// A date that is not one is dropped rather than stored: a delivery note
			// dated "yesterday-ish" is a document nobody can file.
			'document_date' => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : null,
			'causal'        => Causals::normalise( $causal ),
			'notes'         => $notes,
		);
	}

	/**
	 * The transport block, as posted.
	 *
	 * @return array<string, mixed>
	 */
	private function transport_from_request(): array {
		$field = static function ( string $key ): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verified it.
			return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] )
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verified it.
				? sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) )
				: '';
		};

		return array(
			'by'               => $field( 'transport_by' ),
			'carrier_name'     => $field( 'carrier_name' ),
			'carriage'         => $field( 'carriage' ),
			'packages'         => $field( 'packages' ),
			'weight_gross'     => self::to_quantity( $field( 'weight_gross' ) ),
			'weight_net'       => self::to_quantity( $field( 'weight_net' ) ),
			'goods_appearance' => $field( 'goods_appearance' ),
		);
	}

	/**
	 * Who the goods are for, as they were read from the order.
	 *
	 * Shown and not editable. What is on a delivery note is a photograph of the
	 * order taken when the draft was started, and a form that let somebody
	 * retype it would be a form that let them address the goods to anybody.
	 *
	 * @param WC_Order      $order    The order.
	 * @param Document|null $document The document, when one exists.
	 * @return void
	 */
	private function render_parties( WC_Order $order, ?Document $document ): void {
		$parties = null === $document
			? $this->drafts->draft_from_order( $order )->parties
			: $document->parties;

		echo '<h2>' . esc_html__( 'Recipient', 'oxyddt-for-woocommerce' ) . '</h2>';
		echo '<p><strong>' . esc_html( $parties->recipient->name ) . '</strong><br />';
		echo esc_html( $parties->recipient->address->single_line() );

		if ( $parties->delivers_elsewhere() ) {
			echo '<br /><em>' . esc_html__( 'Delivered to:', 'oxyddt-for-woocommerce' ) . '</em> '
				. esc_html( $parties->delivery_address()->single_line() );
		}

		echo '</p>';
		echo '<p class="description">'
			. esc_html__( 'Taken from the order when this draft was started, and kept as it was: editing the order afterwards does not change a delivery note.', 'oxyddt-for-woocommerce' )
			. '</p>';
	}

	/**
	 * The table.
	 *
	 * @param Fulfilment    $fulfilment What is left of the order.
	 * @param Document|null $document   The document being edited, when there is one.
	 * @return void
	 */
	private function render_quantities( Fulfilment $fulfilment, ?Document $document ): void {
		echo '<h2>' . esc_html__( 'What is going out', 'oxyddt-for-woocommerce' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th style="text-align:right">' . esc_html__( 'Ordered', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th style="text-align:right">' . esc_html__( 'Already sent', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th style="text-align:right">' . esc_html__( 'On other drafts', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th style="text-align:right">' . esc_html__( 'Available', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th style="text-align:right">' . esc_html__( 'This note', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $fulfilment->lines() as $line ) {
			$item_id = $line->ordered->order_item_id;

			// An existing draft shows what is on it; a new one opens with the whole
			// remainder, which is what most people want most of the time.
			$current = null === $document
				? $line->available()
				: $document->quantity_for( $line->ordered->order_id, $item_id );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $line->ordered->name ) . '</strong>';

			if ( '' !== $line->ordered->sku ) {
				echo '<br /><span class="description">' . esc_html( $line->ordered->sku ) . '</span>';
			}

			echo '</td>';
			echo '<td style="text-align:right">' . esc_html( self::quantity( $line->quantity() ) ) . '</td>';
			echo '<td style="text-align:right">' . esc_html( self::quantity( $line->shipped ) ) . '</td>';
			echo '<td style="text-align:right">' . esc_html( self::quantity( $line->reserved ) ) . '</td>';
			echo '<td style="text-align:right"><strong>' . esc_html( self::quantity( $line->available() ) ) . '</strong></td>';
			echo '<td style="text-align:right">';
			echo '<input type="number" step="0.001" min="0" style="width:8em;text-align:right"'
				. ' name="quantities[' . esc_attr( (string) $item_id ) . ']"'
				. ' value="' . esc_attr( self::quantity( $current ) ) . '" />';

			if ( '' !== $line->ordered->unit ) {
				echo ' ' . esc_html( $line->ordered->unit );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">'
			. esc_html__( 'Leave a line at zero to keep it for a later delivery note.', 'oxyddt-for-woocommerce' )
			. '</p>';
	}

	/**
	 * The date, the reason and the notes.
	 *
	 * @param Document|null $document The document being edited, when there is one.
	 * @return void
	 */
	private function render_header( ?Document $document ): void {
		$date   = null === $document || null === $document->document_date
			? gmdate( 'Y-m-d', (int) current_time( 'timestamp' ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- The document date is a local fact; wp_date() formats, current_time() is what gives the shop's own day.
			: $document->document_date;
		$causal = null === $document || '' === $document->causal ? Causals::SALE : $document->causal;

		echo '<h2>' . esc_html__( 'The document', 'oxyddt-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="oxyddt-date">' . esc_html__( 'Date', 'oxyddt-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="date" id="oxyddt-date" name="document_date" value="' . esc_attr( $date ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="oxyddt-causal">' . esc_html__( 'Reason for transport', 'oxyddt-for-woocommerce' ) . '</label></th><td>';
		echo '<select id="oxyddt-causal" name="causal">';

		$known = Causals::defaults();

		if ( ! in_array( $causal, $known, true ) ) {
			// A reason the shop added itself, or one it has since removed. An issued
			// document keeps whatever it was issued with.
			echo '<option value="' . esc_attr( $causal ) . '" selected>' . esc_html( $causal ) . '</option>';
		}

		foreach ( $known as $code ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $causal, $code, false ) . '>'
				. esc_html( self::causal_label( $code ) ) . '</option>';
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Required on an Italian delivery note.', 'oxyddt-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="oxyddt-notes">' . esc_html__( 'Notes', 'oxyddt-for-woocommerce' ) . '</label></th><td>';
		echo '<textarea id="oxyddt-notes" name="notes" rows="3" class="large-text">'
			. esc_textarea( null === $document ? '' : $document->notes ) . '</textarea>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * The transport block.
	 *
	 * @param Transport $transport How the goods travel.
	 * @return void
	 */
	private function render_transport( Transport $transport ): void {
		echo '<h2>' . esc_html__( 'Transport', 'oxyddt-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="oxyddt-transport-by">' . esc_html__( 'In whose care', 'oxyddt-for-woocommerce' ) . '</label></th><td>';
		echo '<select id="oxyddt-transport-by" name="transport_by">';
		echo '<option value="">' . esc_html__( '— not stated —', 'oxyddt-for-woocommerce' ) . '</option>';

		foreach ( Transport::carriers() as $by ) {
			echo '<option value="' . esc_attr( $by ) . '"' . selected( $transport->by, $by, false ) . '>'
				. esc_html( self::carrier_label( $by ) ) . '</option>';
		}

		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="oxyddt-carrier">' . esc_html__( 'Carrier', 'oxyddt-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" id="oxyddt-carrier" name="carrier_name" class="regular-text" value="'
			. esc_attr( $transport->carrier_name ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="oxyddt-carriage">' . esc_html__( 'Carriage', 'oxyddt-for-woocommerce' ) . '</label></th><td>';
		echo '<select id="oxyddt-carriage" name="carriage">';
		echo '<option value="">' . esc_html__( '— not stated —', 'oxyddt-for-woocommerce' ) . '</option>';

		foreach ( Transport::carriages() as $carriage ) {
			echo '<option value="' . esc_attr( $carriage ) . '"' . selected( $transport->carriage, $carriage, false ) . '>'
				. esc_html( self::carriage_label( $carriage ) ) . '</option>';
		}

		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="oxyddt-packages">' . esc_html__( 'Packages', 'oxyddt-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="number" min="0" step="1" id="oxyddt-packages" name="packages" class="small-text" value="'
			. esc_attr( (string) $transport->packages ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="oxyddt-weight-gross">' . esc_html__( 'Gross weight', 'oxyddt-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="number" min="0" step="0.001" id="oxyddt-weight-gross" name="weight_gross" class="small-text" value="'
			. esc_attr( null === $transport->weight_gross ? '' : self::quantity( $transport->weight_gross ) ) . '" />';
		echo ' <label for="oxyddt-weight-net">' . esc_html__( 'Net weight', 'oxyddt-for-woocommerce' ) . '</label> ';
		echo '<input type="number" min="0" step="0.001" id="oxyddt-weight-net" name="weight_net" class="small-text" value="'
			. esc_attr( null === $transport->weight_net ? '' : self::quantity( $transport->weight_net ) ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="oxyddt-appearance">' . esc_html__( 'Appearance of the goods', 'oxyddt-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" id="oxyddt-appearance" name="goods_appearance" class="regular-text" value="'
			. esc_attr( $transport->goods_appearance ) . '" />';
		echo '<p class="description">' . esc_html__( 'Boxes, pallets, loose — what the load looks like from outside.', 'oxyddt-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * A document that can no longer be changed.
	 *
	 * @param Document $document The document.
	 * @return void
	 */
	private function render_closed( Document $document ): void {
		$cancelled = DocumentStatus::Cancelled === $document->status;

		echo '<div class="notice notice-' . ( $cancelled ? 'error' : 'warning' ) . '"><p>';

		echo $cancelled
			? esc_html(
				sprintf(
					/* translators: %s: the reason it was cancelled. */
					__( 'This delivery note has been cancelled: %s', 'oxyddt-for-woocommerce' ),
					$document->lifecycle->cancel_reason
				)
			)
			: esc_html__( 'This delivery note has been issued. It cannot be changed: cancel it and prepare another.', 'oxyddt-for-woocommerce' );

		echo '</p></div>';

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Number', 'oxyddt-for-woocommerce' ) . '</th><td><code>'
			. esc_html( $document->number->formatted ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Date', 'oxyddt-for-woocommerce' ) . '</th><td>'
			. esc_html( (string) $document->document_date ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Issued', 'oxyddt-for-woocommerce' ) . '</th><td>'
			. esc_html( (string) $document->lifecycle->issued_at ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Recipient', 'oxyddt-for-woocommerce' ) . '</th><td>'
			. esc_html( $document->parties->recipient->name ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th style="text-align:right">' . esc_html__( 'Quantity', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $document->lines as $line ) {
			echo '<tr><td>' . esc_html( $line->name ) . '</td>';
			echo '<td style="text-align:right">' . esc_html( self::quantity( $line->quantity ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( $cancelled || ! current_user_can( Capabilities::CANCEL ) ) {
			return;
		}

		$order_ids = $document->all_order_ids();

		echo '<hr /><h2>' . esc_html__( 'Cancel this delivery note', 'oxyddt-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'It keeps its number and stays in the register, saying why it is void. Its quantities go back on the order.', 'oxyddt-for-woocommerce' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'oxyddt_cancel_document' );
		echo '<input type="hidden" name="action" value="oxyddt_cancel_document" />';
		echo '<input type="hidden" name="document" value="' . esc_attr( (string) $document->id ) . '" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) ( $order_ids[0] ?? 0 ) ) . '" />';
		echo '<p><label for="oxyddt-reason">' . esc_html__( 'Why', 'oxyddt-for-woocommerce' ) . '</label><br />';
		echo '<textarea id="oxyddt-reason" name="reason" rows="2" class="large-text" required></textarea></p>';
		submit_button( __( 'Cancel this delivery note', 'oxyddt-for-woocommerce' ), 'delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * The form that throws a draft away.
	 *
	 * A form of its own rather than a second button in the first: a delete that
	 * shares a form with a save is a delete somebody presses by accident.
	 *
	 * @param Document $document The draft.
	 * @return void
	 */
	private function render_delete_form( Document $document ): void {
		$order_ids = $document->all_order_ids();

		echo '<hr /><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'oxyddt_delete_document' );
		echo '<input type="hidden" name="action" value="oxyddt_delete_document" />';
		echo '<input type="hidden" name="document" value="' . esc_attr( (string) $document->id ) . '" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) ( $order_ids[0] ?? 0 ) ) . '" />';
		echo '<button type="submit" class="button-link delete">'
			. esc_html__( 'Delete this draft', 'oxyddt-for-woocommerce' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Go back to the screen, with something to say.
	 *
	 * @param int    $order_id    The order.
	 * @param int    $document_id The document, 0 for none.
	 * @param string $type        "success", "warning" or "error".
	 * @param string $message     Already translated.
	 * @return never
	 */
	private function back_to( int $order_id, int $document_id, string $type, string $message ): never {
		Notices::remember( $type, $message );

		wp_safe_redirect( self::url( $order_id, $document_id ) );

		exit;
	}

	/**
	 * A quantity, as a person writes it.
	 *
	 * Three decimals, and no trailing zeros: "4", not "4.000".
	 *
	 * @param float $quantity The quantity.
	 * @return string
	 */
	public static function quantity( float $quantity ): string {
		$formatted = number_format( $quantity, 3, '.', '' );

		return false === strpos( $formatted, '.' ) ? $formatted : rtrim( rtrim( $formatted, '0' ), '.' );
	}

	/**
	 * A quantity, as a person typed it.
	 *
	 * The comma is accepted because half of Italy writes 2,5 and a form that
	 * silently reads that as 2 has sent the wrong goods.
	 *
	 * @param string $value As typed.
	 * @return float
	 */
	private static function to_quantity( string $value ): float {
		$clean = str_replace( ',', '.', trim( $value ) );

		return is_numeric( $clean ) ? max( 0.0, (float) $clean ) : 0.0;
	}

	/**
	 * What a reason for transport is called.
	 *
	 * @param string $code The code.
	 * @return string
	 */
	public static function causal_label( string $code ): string {
		$labels = array(
			Causals::SALE              => __( 'Sale', 'oxyddt-for-woocommerce' ),
			Causals::ON_APPROVAL       => __( 'On approval', 'oxyddt-for-woocommerce' ),
			Causals::PROCESSING        => __( 'For processing', 'oxyddt-for-woocommerce' ),
			Causals::REPAIR            => __( 'For repair', 'oxyddt-for-woocommerce' ),
			Causals::RETURNED          => __( 'Return', 'oxyddt-for-woocommerce' ),
			Causals::REPLACEMENT       => __( 'Replacement', 'oxyddt-for-woocommerce' ),
			Causals::GIFT              => __( 'Gift', 'oxyddt-for-woocommerce' ),
			Causals::INTERNAL_TRANSFER => __( 'Internal transfer', 'oxyddt-for-woocommerce' ),
			Causals::OTHER             => __( 'Other', 'oxyddt-for-woocommerce' ),
		);

		return $labels[ $code ] ?? $code;
	}

	/**
	 * What "in whose care" reads as.
	 *
	 * @param string $by Who carries the goods.
	 * @return string
	 */
	private static function carrier_label( string $by ): string {
		$labels = array(
			Transport::BY_SENDER    => __( 'The sender', 'oxyddt-for-woocommerce' ),
			Transport::BY_RECIPIENT => __( 'The recipient', 'oxyddt-for-woocommerce' ),
			Transport::BY_CARRIER   => __( 'A carrier', 'oxyddt-for-woocommerce' ),
		);

		return $labels[ $by ] ?? $by;
	}

	/**
	 * What "carriage" reads as.
	 *
	 * @param string $carriage Who pays.
	 * @return string
	 */
	private static function carriage_label( string $carriage ): string {
		$labels = array(
			Transport::CARRIAGE_PREPAID => __( 'Prepaid (the sender pays)', 'oxyddt-for-woocommerce' ),
			Transport::CARRIAGE_FORWARD => __( 'Forward (the recipient pays)', 'oxyddt-for-woocommerce' ),
		);

		return $labels[ $carriage ] ?? $carriage;
	}
}
