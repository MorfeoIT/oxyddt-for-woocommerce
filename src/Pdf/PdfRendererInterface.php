<?php
/**
 * Turning HTML into a PDF.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Pdf;

/**
 * One method, so that the engine can be replaced.
 *
 * The plugin bundles dompdf, which is a decision about weight and licence
 * rather than about architecture. A shop on a host with wkhtmltopdf, or a PRO
 * add-on with a nicer engine, swaps this in the container and nothing above it
 * changes.
 */
interface PdfRendererInterface {

	/**
	 * Render a page.
	 *
	 * @param string $html What to render, a whole HTML document.
	 * @return string The PDF, as bytes.
	 *
	 * @throws PdfException If the engine could not produce a PDF.
	 */
	public function render( string $html ): string;
}
