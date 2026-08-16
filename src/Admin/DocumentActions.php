<?php
/**
 * Downloading, printing and sending.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Admin;

use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentRepositoryInterface;
use Oxysoft\OxyDDT\Infrastructure\Registrable;
use Oxysoft\OxyDDT\Mail\DocumentMailer;
use Oxysoft\OxyDDT\Mail\MailException;
use Oxysoft\OxyDDT\Pdf\PdfException;
use Oxysoft\OxyDDT\Pdf\PdfService;
use Oxysoft\OxyDDT\Security\Capabilities;

/**
 * The three things somebody does with a finished delivery note.
 *
 * The PDF is never linked to directly. It is streamed through here, and here
 * checks a capability and a nonce first — which is the only defence that
 * actually decides anything, as against the hardened directory and the
 * unguessable filename, which are obstacles.
 *
 * Printing is the same endpoint with a different disposition: the browser opens
 * it instead of saving it, and the shop presses Ctrl+P. A separate print view
 * would be a second layout to keep in step with the first.
 */
final class DocumentActions implements Registrable {

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
	 * The mailer.
	 *
	 * @var DocumentMailer
	 */
	private DocumentMailer $mailer;

	/**
	 * Build the controller.
	 *
	 * @param DocumentRepositoryInterface $documents The document store.
	 * @param PdfService                  $pdf       The PDF service.
	 * @param DocumentMailer              $mailer    The mailer.
	 */
	public function __construct(
		DocumentRepositoryInterface $documents,
		PdfService $pdf,
		DocumentMailer $mailer
	) {
		$this->documents = $documents;
		$this->pdf       = $pdf;
		$this->mailer    = $mailer;
	}

	/**
	 * Add the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_oxyddt_pdf', array( $this, 'handle_pdf' ) );
		add_action( 'admin_post_oxyddt_send_document', array( $this, 'handle_send' ) );
		add_action( 'oxyddt_issued', array( $this->pdf, 'on_issued' ) );
	}

	/**
	 * The address that serves a document's PDF.
	 *
	 * @param Document $document The document.
	 * @param bool     $inline   Whether the browser should open it rather than save it.
	 * @return string
	 */
	public static function pdf_url( Document $document, bool $inline = false ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'oxyddt_pdf',
					'document' => $document->id,
					'inline'   => $inline ? '1' : '0',
				),
				admin_url( 'admin-post.php' )
			),
			'oxyddt_pdf_' . $document->id
		);
	}

	/**
	 * Send the PDF to the browser.
	 *
	 * @return void
	 */
	public function handle_pdf(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line, once the document is known.
		$document_id = isset( $_GET['document'] ) ? absint( wp_unslash( $_GET['document'] ) ) : 0;

		check_admin_referer( 'oxyddt_pdf_' . $document_id );

		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die(
				esc_html__( 'You are not allowed to see delivery notes.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		$document = $this->documents->find( $document_id );

		if ( null === $document ) {
			wp_die(
				esc_html__( 'That delivery note does not exist.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 404 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer() above.
		$inline = isset( $_GET['inline'] ) && '1' === $_GET['inline'];

		try {
			$bytes = $this->pdf->bytes( $document );
		} catch ( PdfException $e ) {
			wp_die(
				esc_html__( 'The PDF could not be produced. The site log has the details.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 500 )
			);
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header(
			sprintf(
				'Content-Disposition: %1$s; filename="%2$s"',
				$inline ? 'inline' : 'attachment',
				$this->pdf->filename( $document )
			)
		);

		// A PDF is bytes, and escaping bytes would corrupt them. Nothing else is
		// printed on this request: the headers above say what it is.
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	/**
	 * A copy field, as somebody pasted it.
	 *
	 * Commas, semicolons, spaces and newlines all separate: which one arrives
	 * depends on the mail client the addresses were copied out of, and asking a
	 * warehouse to remember which one is not a rule anybody will follow.
	 *
	 * @param string $field The field.
	 * @return list<string>
	 */
	private static function split_addresses( string $field ): array {
		$parts = preg_split( '/[,;\s]+/', $field );

		return array_values( array_filter( is_array( $parts ) ? $parts : array() ) );
	}

	/**
	 * Email a delivery note.
	 *
	 * @return void
	 */
	public function handle_send(): void {
		check_admin_referer( 'oxyddt_send_document' );

		if ( ! current_user_can( Capabilities::SEND ) ) {
			wp_die(
				esc_html__( 'You are not allowed to send delivery notes.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$document_id = isset( $_POST['document'] ) ? absint( wp_unslash( $_POST['document'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$cc = isset( $_POST['cc'] ) ? self::split_addresses( sanitize_text_field( wp_unslash( $_POST['cc'] ) ) ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$bcc = isset( $_POST['bcc'] ) ? self::split_addresses( sanitize_text_field( wp_unslash( $_POST['bcc'] ) ) ) : array();

		$document = $this->documents->find( $document_id );

		if ( null === $document ) {
			$this->back( $order_id, 0, 'error', __( 'That delivery note does not exist.', 'oxyddt-for-woocommerce' ) );
		}

		try {
			$sent = $this->mailer->send( $document, $to, $subject, $message, $cc, $bcc );
		} catch ( MailException $e ) {
			$this->back(
				$order_id,
				$document_id,
				'error',
				__( 'Not sent: check the address, and that the delivery note has been issued.', 'oxyddt-for-woocommerce' )
			);
		}

		$this->back(
			$order_id,
			$document_id,
			$sent ? 'success' : 'error',
			$sent
				? sprintf(
					/* translators: %s: the email address. */
					__( 'Sent to %s.', 'oxyddt-for-woocommerce' ),
					$to
				)
				: __( 'This site could not send the email. That is a mail problem, not a delivery note problem: the document is unchanged.', 'oxyddt-for-woocommerce' )
		);
	}

	/**
	 * Go back to the document, with something to say.
	 *
	 * @param int    $order_id    The order.
	 * @param int    $document_id The document.
	 * @param string $type        "success" or "error".
	 * @param string $message     Already translated.
	 * @return never
	 */
	private function back( int $order_id, int $document_id, string $type, string $message ): never {
		Notices::remember( $type, $message );

		wp_safe_redirect( EditScreen::url( $order_id, $document_id ) );

		exit;
	}
}
