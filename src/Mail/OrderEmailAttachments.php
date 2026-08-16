<?php
/**
 * Putting the delivery notes of an order onto WooCommerce's own emails.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Mail;

use Oxysoft\OxyDDT\Domain\DocumentRepositoryInterface;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Infrastructure\Registrable;
use Oxysoft\OxyDDT\Pdf\PdfException;
use Oxysoft\OxyDDT\Pdf\PdfService;
use Oxysoft\OxyDDT\Settings\Settings;
use WC_Email;
use WC_Order;

/**
 * The delivery notes an order has already had, attached to the emails a shop
 * chose — and to no others.
 *
 * Off by default, and that is the whole design. Everything else in this plugin
 * that sends anything is a button somebody presses; this is the one place where
 * a file leaves the shop because a status changed, so it does nothing at all
 * until a shop names the emails it wants.
 *
 * Only issued documents are attached. A draft has no number, and a cancelled
 * one is a document that says on its face that it is void: neither belongs in a
 * customer's inbox.
 */
final class OrderEmailAttachments implements Registrable {

	/**
	 * The document store.
	 *
	 * @var DocumentRepositoryInterface
	 */
	private DocumentRepositoryInterface $documents;

	/**
	 * The PDF service.
	 *
	 * @var PdfService
	 */
	private PdfService $pdf;

	/**
	 * The settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Build it.
	 *
	 * @param DocumentRepositoryInterface $documents The document store.
	 * @param PdfService                  $pdf       The PDF service.
	 * @param Settings                    $settings  The settings.
	 */
	public function __construct( DocumentRepositoryInterface $documents, PdfService $pdf, Settings $settings ) {
		$this->documents = $documents;
		$this->pdf       = $pdf;
		$this->settings  = $settings;
	}

	/**
	 * Add the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_email_attachments', array( $this, 'attach' ), 10, 4 );
	}

	/**
	 * The attachments for one of WooCommerce's emails.
	 *
	 * @param mixed         $attachments What is already attached.
	 * @param string        $email_id    Which email this is.
	 * @param mixed         $subject_of  What the email is about; an order, usually.
	 * @param WC_Email|null $email       The email itself.
	 * @return array<int, string>
	 */
	public function attach( $attachments, $email_id, $subject_of, $email = null ): array {
		$attachments = is_array( $attachments ) ? $attachments : array();

		unset( $email );

		if ( ! $subject_of instanceof WC_Order ) {
			return $attachments;
		}

		$wanted = $this->settings->get( 'attach_to_emails', array() );

		if ( ! is_array( $wanted ) || ! in_array( (string) $email_id, $wanted, true ) ) {
			return $attachments;
		}

		foreach ( $this->documents->for_order( $subject_of->get_id(), false ) as $document ) {
			if ( DocumentStatus::Issued !== $document->status ) {
				continue;
			}

			try {
				$attachments[] = $this->pdf->attachment_path( $document );
			} catch ( PdfException $e ) {
				// A missing PDF must not stop the shop's own email. The customer
				// needs to hear that their order shipped whether or not the
				// paperwork rendered, and the failure is already in the log.
				unset( $e );
			}
		}

		return $attachments;
	}

	/**
	 * The emails a shop can choose from.
	 *
	 * Read from WooCommerce rather than listed here, so an email added by
	 * another plugin — a shop's own "goods dispatched", say — is offered too.
	 *
	 * @return array<string, string> Email id to title.
	 */
	public static function available(): array {
		$available = array();

		if ( ! function_exists( 'WC' ) || null === WC()->mailer() ) {
			return $available;
		}

		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( ! $email instanceof WC_Email || ! $email->is_customer_email() ) {
				continue;
			}

			$available[ $email->id ] = $email->get_title();
		}

		return $available;
	}
}
