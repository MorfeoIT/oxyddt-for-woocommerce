<?php
/**
 * Getting hold of a document's PDF.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Pdf;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentRepositoryInterface;

/*
 * The exceptions in this file name paths and library errors, and are read by
 * whoever is looking at a log or a refusal — never printed to a shop's
 * customers. Escaping them would put HTML entities in a stack trace.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
/**
 * One copy, kept, and regenerated only if the copy is gone.
 *
 * An issued document is rendered once and archived with its hash. Everything
 * afterwards — downloading, printing, attaching it to an email — reads that
 * same file. The alternative, rendering on demand every time, would mean a
 * delivery note that quietly changed the day somebody edited a template, and
 * the customer's copy and the shop's would stop matching.
 *
 * If the archived file is missing — a restore that skipped uploads, a move
 * between hosts — it is rendered again from the document's own snapshot, which
 * produces the same page. The new hash is recorded and the log says it happened,
 * because "this file is not the one that was issued" is worth being able to see.
 *
 * A draft has no archive. It is rendered fresh every time, because it changes.
 */
final class PdfService {

	/**
	 * The engine.
	 *
	 * @var PdfRendererInterface
	 */
	private PdfRendererInterface $renderer;

	/**
	 * The archive.
	 *
	 * @var PdfStore
	 */
	private PdfStore $store;

	/**
	 * The page builder.
	 *
	 * @var DocumentHtml
	 */
	private DocumentHtml $html;

	/**
	 * The document store.
	 *
	 * @var DocumentRepositoryInterface
	 */
	private DocumentRepositoryInterface $documents;

	/**
	 * The register.
	 *
	 * @var AuditLog
	 */
	private AuditLog $log;

	/**
	 * Build the service.
	 *
	 * @param PdfRendererInterface        $renderer  The engine.
	 * @param PdfStore                    $store     The archive.
	 * @param DocumentHtml                $html      The page builder.
	 * @param DocumentRepositoryInterface $documents The document store.
	 * @param AuditLog                    $log       The register.
	 */
	public function __construct(
		PdfRendererInterface $renderer,
		PdfStore $store,
		DocumentHtml $html,
		DocumentRepositoryInterface $documents,
		AuditLog $log
	) {
		$this->renderer  = $renderer;
		$this->store     = $store;
		$this->html      = $html;
		$this->documents = $documents;
		$this->log       = $log;
	}

	/**
	 * Archive a document's PDF, now.
	 *
	 * Called when a document is issued, and again only if the archived file has
	 * gone missing.
	 *
	 * @param Document $document The document.
	 * @return array{path: string, hash: string}
	 *
	 * @throws PdfException If it could not be rendered or written.
	 */
	public function archive( Document $document ): array {
		$archived = $this->store->store( $document, $this->render( $document ) );

		$this->documents->set_pdf( $document->id, $archived['path'], $archived['hash'] );

		return $archived;
	}

	/**
	 * The document's PDF, whatever it takes.
	 *
	 * @param Document $document The document.
	 * @return string
	 *
	 * @throws PdfException If it could not be produced.
	 */
	public function bytes( Document $document ): string {
		// A draft is not archived: it changes, and a file that lags behind what is
		// on screen is worse than no file.
		if ( ! $document->status->is_numbered() || $document->id <= 0 ) {
			return $this->render( $document );
		}

		$archived = $this->documents->pdf_of( $document->id );

		if ( null !== $archived && $this->store->exists( $archived['path'] ) ) {
			return $this->store->read( $archived['path'] );
		}

		$rebuilt = $this->archive( $document );

		$this->log->record(
			AuditLog::DOCUMENT_ISSUED,
			sprintf( 'The archived PDF of delivery note %s was missing and has been rebuilt.', $document->number->formatted ),
			array(
				'was'  => null === $archived ? '' : $archived['hash'],
				'now'  => $rebuilt['hash'],
				'path' => $rebuilt['path'],
			),
			$document->id
		);

		return $this->store->read( $rebuilt['path'] );
	}

	/**
	 * Whether a document has an archived PDF that is still on disk.
	 *
	 * @param Document $document The document.
	 * @return bool
	 */
	public function is_archived( Document $document ): bool {
		if ( $document->id <= 0 ) {
			return false;
		}

		$archived = $this->documents->pdf_of( $document->id );

		return null !== $archived && $this->store->exists( $archived['path'] );
	}

	/**
	 * Archive the PDF of a document that has just been issued.
	 *
	 * Hooked to `oxyddt_issued`. A failure here must not undo the issue: the
	 * number is spent and the document exists, and the PDF can be rebuilt from
	 * the snapshot at any time. So it is recorded and swallowed.
	 *
	 * @param Document $document The document.
	 * @return void
	 */
	public function on_issued( Document $document ): void {
		try {
			$this->archive( $document );
		} catch ( PdfException $e ) {
			$this->log->record(
				AuditLog::DOCUMENT_ISSUED,
				sprintf( 'Delivery note %s was issued, but its PDF could not be archived.', $document->number->formatted ),
				array( 'error' => $e->getMessage() ),
				$document->id
			);
		}
	}

	/**
	 * What the file is called when somebody downloads it.
	 *
	 * @param Document $document The document.
	 * @return string
	 */
	public function filename( Document $document ): string {
		// The slash in "125/2026" would be stripped rather than replaced, and
		// "ddt-1252026.pdf" is a file nobody can find again.
		$name = '' === $document->number->formatted
			? 'ddt-draft-' . $document->id
			: 'ddt-' . str_replace( '/', '-', $document->number->formatted );

		/**
		 * Filters the name of the file a delivery note is downloaded as.
		 *
		 * @since 0.1.0
		 *
		 * @param string   $name     The name, without an extension.
		 * @param Document $document The document.
		 */
		$name = (string) apply_filters( 'oxyddt_pdf_filename', $name, $document );

		return sanitize_file_name( $name ) . '.pdf';
	}

	/**
	 * Somewhere on disk a mail attachment can be read from.
	 *
	 * @param Document $document The document.
	 * @return string Absolute path.
	 *
	 * @throws PdfException If there is no archived file and none could be made.
	 */
	public function attachment_path( Document $document ): string {
		$archived = $this->documents->pdf_of( $document->id );

		if ( null === $archived || ! $this->store->exists( $archived['path'] ) ) {
			$archived = $this->archive( $document );
		}

		return $this->store->absolute( $archived['path'] );
	}

	/**
	 * Render the page.
	 *
	 * @param Document $document The document.
	 * @return string
	 *
	 * @throws PdfException If it could not be rendered.
	 */
	private function render( Document $document ): string {
		return $this->renderer->render( $this->html->for_document( $document ) );
	}
}
