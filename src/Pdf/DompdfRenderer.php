<?php
/**
 * The bundled PDF engine.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Throwable;

/*
 * The exceptions in this file name paths and library errors, and are read by
 * whoever is looking at a log or a refusal — never printed to a shop's
 * customers. Escaping them would put HTML entities in a stack trace.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
/**
 * The bundled engine, dompdf, configured to stay on this server.
 *
 * Two settings are security decisions rather than preferences, and they are why
 * this class exists instead of a one-liner:
 *
 * `isRemoteEnabled` is **off**. With it on, a document could ask dompdf to fetch
 * a URL while rendering — and the document is built from a customer's own
 * address and product names. That is a server-side request forgery with extra
 * steps.
 *
 * The logo is the one image a delivery note needs, and it is read from disk by
 * the template rather than by URL, so nothing here has to reach the network at
 * all.
 */
final class DompdfRenderer implements PdfRendererInterface {

	/**
	 * Render a page.
	 *
	 * @param string $html What to render.
	 * @return string
	 *
	 * @throws PdfException If dompdf could not produce a PDF.
	 */
	public function render( string $html ): string {
		if ( ! class_exists( Dompdf::class ) ) {
			throw new PdfException(
				'The PDF engine is missing. The plugin was installed without its bundled libraries.'
			);
		}

		$options = new Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isHtml5ParserEnabled', true );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		// Where dompdf keeps its font cache. Inside the uploads directory, which
		// is the one place a WordPress install is sure to be able to write.
		$options->set( 'fontDir', $this->font_directory() );
		$options->set( 'fontCache', $this->font_directory() );
		$options->set( 'tempDir', get_temp_dir() );
		$options->set( 'chroot', \Oxysoft\OxyDDT\plugin_dir() );

		/**
		 * Filters the options handed to the PDF engine.
		 *
		 * Turning isRemoteEnabled back on opens the door to a document fetching
		 * URLs while it renders, on a page built partly from what a customer
		 * typed. Do not.
		 *
		 * @since 0.1.0
		 *
		 * @param Options $options The options.
		 */
		$options = apply_filters( 'oxyddt_pdf_options', $options );

		try {
			$dompdf = new Dompdf( $options instanceof Options ? $options : new Options() );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->render();

			$output = $dompdf->output();
		} catch ( Throwable $e ) {
			throw new PdfException( 'The PDF could not be rendered: ' . $e->getMessage(), 0, $e );
		}

		if ( null === $output || '' === $output ) {
			throw new PdfException( 'The PDF engine produced nothing.' );
		}

		return $output;
	}

	/**
	 * Where the font cache lives.
	 *
	 * @return string
	 */
	private function font_directory(): string {
		$uploads = wp_upload_dir();
		$path    = trailingslashit( (string) $uploads['basedir'] ) . 'oxyddt/fonts';

		wp_mkdir_p( $path );

		return $path;
	}
}
