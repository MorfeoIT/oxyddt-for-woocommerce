<?php
/**
 * Sending a delivery note.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Mail;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Infrastructure\Labels;
use Oxysoft\OxyDDT\Pdf\PdfException;
use Oxysoft\OxyDDT\Pdf\PdfService;

/*
 * The exceptions in this file name paths and library errors, and are read by
 * whoever is looking at a log or a refusal — never printed to a shop's
 * customers. Escaping them would put HTML entities in a stack trace.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
/**
 * One email, sent when somebody asks for it.
 *
 * Manual only in the free plugin: a person decides, presses send, and sees what
 * happened. Automatic sending on an order status is a PRO feature, and it is
 * the right thing to keep out of the free one — a shop that discovers its
 * plugin has been emailing customers by itself has lost something it cannot get
 * back.
 *
 * Only issued documents are sent. A draft is not a document yet, and emailing
 * one would put a delivery note with no number in a customer's inbox.
 */
final class DocumentMailer {

	/**
	 * The PDF service.
	 *
	 * @var PdfService
	 */
	private PdfService $pdf;

	/**
	 * The register.
	 *
	 * @var AuditLog
	 */
	private AuditLog $log;

	/**
	 * Build the mailer.
	 *
	 * @param PdfService $pdf The PDF service.
	 * @param AuditLog   $log The register.
	 */
	public function __construct( PdfService $pdf, AuditLog $log ) {
		$this->pdf = $pdf;
		$this->log = $log;
	}

	/**
	 * Send a delivery note.
	 *
	 * @param Document     $document The document.
	 * @param string       $to       Where to.
	 * @param string       $subject  The subject.
	 * @param string       $message  The body, as plain text.
	 * @param list<string> $cc       Copies, openly.
	 * @param list<string> $bcc      Copies nobody else sees.
	 * @return bool Whether WordPress accepted it for delivery.
	 *
	 * @throws MailException If the document cannot be sent at all.
	 */
	public function send( Document $document, string $to, string $subject, string $message, array $cc = array(), array $bcc = array() ): bool {
		if ( ! $document->status->is_numbered() ) {
			throw new MailException( 'A draft has no number and is not sent.' );
		}

		$to = trim( $to );

		if ( ! is_email( $to ) ) {
			throw new MailException( 'That is not an email address.' );
		}

		$cc  = self::addresses( $cc );
		$bcc = self::addresses( $bcc );

		try {
			$attachment = $this->pdf->attachment_path( $document );
		} catch ( PdfException $e ) {
			throw new MailException( 'The delivery note has no PDF to attach: ' . $e->getMessage(), 0, $e );
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		foreach ( $cc as $address ) {
			$headers[] = 'Cc: ' . $address;
		}

		foreach ( $bcc as $address ) {
			$headers[] = 'Bcc: ' . $address;
		}

		/**
		 * Filters the email a delivery note is sent in.
		 *
		 * @since 0.1.0
		 *
		 * @param array{to: string, subject: string, message: string, headers: list<string>} $email    The email.
		 * @param Document                                                                   $document The document.
		 */
		$email = (array) apply_filters(
			'oxyddt_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'message' => $message,
				'headers' => $headers,
			),
			$document
		);

		$sent = wp_mail(
			isset( $email['to'] ) && is_scalar( $email['to'] ) ? (string) $email['to'] : $to,
			isset( $email['subject'] ) && is_scalar( $email['subject'] ) ? (string) $email['subject'] : $subject,
			isset( $email['message'] ) && is_scalar( $email['message'] ) ? (string) $email['message'] : $message,
			isset( $email['headers'] ) && is_array( $email['headers'] ) ? $email['headers'] : $headers,
			array( $attachment )
		);

		$this->log->record(
			AuditLog::DOCUMENT_SENT,
			sprintf(
				'Delivery note %1$s was %2$s to %3$s.',
				$document->number->formatted,
				$sent ? 'sent' : 'not sent',
				$to
			),
			array(
				'to'   => $to,
				'cc'   => $cc,
				// The blind copies are counted, not named: writing them into a log
				// several people can read is one way of un-blinding them.
				'bcc'  => count( $bcc ),
				'sent' => $sent,
			),
			$document->id
		);

		/**
		 * Fires after a delivery note has been emailed.
		 *
		 * Whether it left is what `$sent` says: WordPress accepting a message for
		 * delivery is not the same as a customer receiving it.
		 *
		 * @since 0.1.0
		 *
		 * @param Document $document The document.
		 * @param string   $to       Where it was sent.
		 * @param bool     $sent     Whether WordPress accepted it.
		 */
		do_action( 'oxyddt_sent', $document, $to, $sent );

		return $sent;
	}

	/**
	 * The addresses in a list that are addresses.
	 *
	 * A copy field is where somebody pastes three addresses separated by
	 * whatever their mail client used, so what is not an address is dropped
	 * rather than refused: the delivery note still has to reach the customer,
	 * and a typo in a copy is not a reason to stop that.
	 *
	 * @param list<string> $addresses As typed.
	 * @return list<string>
	 */
	private static function addresses( array $addresses ): array {
		$clean = array();

		foreach ( $addresses as $address ) {
			$address = trim( (string) $address );

			if ( '' === $address || ! is_email( $address ) || in_array( $address, $clean, true ) ) {
				continue;
			}

			$clean[] = $address;
		}

		return $clean;
	}

	/**
	 * The subject a shop would write if it did not have to.
	 *
	 * @param Document $document The document.
	 * @return string
	 */
	public function default_subject( Document $document ): string {
		return sprintf(
			/* translators: 1: shop name, 2: delivery note number. */
			__( '%1$s — delivery note %2$s', 'oxyddt-for-woocommerce' ),
			$document->parties->sender->name,
			$document->number->formatted
		);
	}

	/**
	 * The message a shop would write if it did not have to.
	 *
	 * @param Document $document The document.
	 * @return string
	 */
	public function default_message( Document $document ): string {
		return sprintf(
			/* translators: 1: recipient name, 2: delivery note number, 3: date, 4: shop name. */
			__(
				"Dear %1\$s,\n\nattached is delivery note %2\$s of %3\$s for the goods on their way to you.\n\nKind regards,\n%4\$s",
				'oxyddt-for-woocommerce'
			),
			$document->parties->recipient->name,
			$document->number->formatted,
			Labels::date( $document->document_date ),
			$document->parties->sender->name
		);
	}
}
