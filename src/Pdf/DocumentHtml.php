<?php
/**
 * The page a delivery note is printed from.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Pdf;

use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Infrastructure\Templates;
use Oxysoft\OxyDDT\Settings\Settings;

/*
 * The exceptions in this file name paths and library errors, and are read by
 * whoever is looking at a log or a refusal — never printed to a shop's
 * customers. Escaping them would put HTML entities in a stack trace.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
/**
 * Builds the HTML, and hands it to a template a shop may have replaced.
 *
 * Everything the template is given comes from the document's own snapshot —
 * never from the order, never from the settings as they read today. A delivery
 * note printed a year from now has to come out of the printer saying exactly
 * what it said the day it was issued, and the only way to be sure of that is
 * for nothing else to be in the room.
 */
final class DocumentHtml {

	/**
	 * The template loader.
	 *
	 * @var Templates
	 */
	private Templates $templates;

	/**
	 * The settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Build the builder.
	 *
	 * @param Templates $templates The template loader.
	 * @param Settings  $settings  The settings.
	 */
	public function __construct( Templates $templates, Settings $settings ) {
		$this->templates = $templates;
		$this->settings  = $settings;
	}

	/**
	 * The page for a document.
	 *
	 * @param Document $document The document.
	 * @return string
	 *
	 * @throws PdfException If there is no template to render.
	 */
	public function for_document( Document $document ): string {
		/*
		 * Two things here are read now rather than taken from the snapshot: the
		 * logo file, and whether prices are printed. Both are decisions about
		 * printing rather than about what happened, and both are settled once —
		 * the PDF is rendered at the moment of issue and archived, and the
		 * archived file is what everything afterwards reads. A shop that turns
		 * prices on tomorrow does not change a delivery note printed today.
		 */
		$html = $this->templates->render(
			'pdf/document.php',
			array(
				'document'    => $document,
				'logo'        => $this->logo( $document ),
				'show_prices' => $this->settings->shows_prices(),
			)
		);

		if ( '' === trim( $html ) ) {
			throw new PdfException( 'The delivery note template produced nothing.' );
		}

		/**
		 * Filters the HTML a delivery note is rendered from.
		 *
		 * The last chance to change what appears on the paper. What comes back is
		 * what gets archived, so a filter that breaks the layout breaks documents
		 * that cannot be reissued.
		 *
		 * @since 0.1.0
		 *
		 * @param string   $html     The page.
		 * @param Document $document The document it was built from.
		 */
		return (string) apply_filters( 'oxyddt_pdf_html', $html, $document );
	}

	/**
	 * The logo, as something a PDF engine can embed without leaving the server.
	 *
	 * Read from disk and inlined rather than linked. The renderer has remote
	 * fetching switched off on purpose, and a URL would simply come out blank.
	 *
	 * @param Document $document The document, carrying the sender as it was.
	 * @return string A data URI, or an empty string.
	 */
	private function logo( Document $document ): string {
		$attachment_id = $document->parties->sender->logo_id;

		if ( $attachment_id <= 0 ) {
			return '';
		}

		$path = get_attached_file( $attachment_id );

		if ( ! is_string( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$type = wp_check_filetype( $path );
		$mime = is_string( $type['type'] ?? null ) ? $type['type'] : '';

		if ( 0 !== strpos( $mime, 'image/' ) ) {
			return '';
		}

		// A logo is a few tens of kilobytes and is read once per document. Loading
		// it whole is what lets the renderer stay offline.
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file the site owns; WP_Filesystem would need credentials on some hosts for a read the PDF cannot do without.

		if ( false === $bytes || '' === $bytes ) {
			return '';
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A data URI is base64 by definition.
	}
}
