<?php
/**
 * The PDF, produced and archived for real.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Infrastructure\SystemClock;
use Oxysoft\OxyDDT\Infrastructure\Templates;
use Oxysoft\OxyDDT\Issuing\Issuer;
use Oxysoft\OxyDDT\Pdf\DocumentHtml;
use Oxysoft\OxyDDT\Pdf\DompdfRenderer;
use Oxysoft\OxyDDT\Pdf\PdfService;
use Oxysoft\OxyDDT\Pdf\PdfStore;
use Oxysoft\OxyDDT\Persistence\DocumentRepository;
use Oxysoft\OxyDDT\Persistence\SequenceRepository;
use Oxysoft\OxyDDT\Settings\Settings;
use Oxysoft\OxyDDT\WooCommerce\DocumentFactory;
use WP_UnitTestCase;

/**
 * From a document to bytes on disk.
 *
 * The engine is the bundled one, running for real: a test that mocks the PDF
 * library proves that the plugin can call a method, which was never the part in
 * doubt.
 */
final class PdfTest extends WP_UnitTestCase {

	use ShopFixtures;

	/**
	 * The store.
	 *
	 * @var DocumentRepository
	 */
	private DocumentRepository $documents;

	/**
	 * The PDF service.
	 *
	 * @var PdfService
	 */
	private PdfService $pdf;

	/**
	 * The archive.
	 *
	 * @var PdfStore
	 */
	private PdfStore $archive;

	/**
	 * The issuer.
	 *
	 * @var Issuer
	 */
	private Issuer $issuer;

	/**
	 * The order-to-draft factory.
	 *
	 * @var DocumentFactory
	 */
	private DocumentFactory $drafts;

	/**
	 * The page builder.
	 *
	 * @var DocumentHtml
	 */
	private DocumentHtml $html;

	/**
	 * Configure a shop that could issue, and build the services.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$clock    = new SystemClock();
		$settings = new Settings();

		$settings->update_company(
			Company::from_array(
				array(
					'name'       => 'Oxysoft S.r.l.',
					'vat_number' => '01234567897',
					'address'    => array(
						'street'   => 'Via Roma 1',
						'postcode' => '20121',
						'city'     => 'Milano',
						'province' => 'MI',
					),
				)
			)
		);

		$this->documents = new DocumentRepository( $clock );
		$this->archive   = new PdfStore();
		$this->html      = new DocumentHtml( new Templates(), $settings );
		$this->drafts    = new DocumentFactory( $settings, $clock );

		$this->pdf = new PdfService(
			new DompdfRenderer(),
			$this->archive,
			$this->html,
			$this->documents,
			new AuditLog( $clock )
		);

		$this->issuer = new Issuer(
			$this->documents,
			new SequenceRepository( $clock ),
			$settings,
			$clock,
			new AuditLog( $clock )
		);
	}

	/**
	 * An issued document.
	 *
	 * @return Document
	 */
	private function issued(): Document {
		$draft = $this->documents->save(
			$this->drafts->draft_from_order( $this->an_order( array( 'Product A' => 3.0 ) ) )
				->with_details( array( 'notes' => 'Handle with care' ) )
		);

		return $this->issuer->issue( $draft );
	}

	/**
	 * Prices are off unless a shop asks, because a delivery note is not an
	 * invoice and most of them are handed to a courier.
	 *
	 * @return void
	 */
	public function test_prices_are_not_printed_unless_the_shop_asks(): void {
		$document = $this->issued();

		$this->assertStringNotContainsString( 'Unit price', $this->html->for_document( $document ) );

		( new Settings() )->update( array( 'show_prices' => true ) );

		$html = $this->html->for_document( $document );

		$this->assertStringContainsString( 'Unit price', $html );
		$this->assertStringContainsString( 'Total, net of tax', $html );
	}

	/**
	 * The page carries what the document says, and nothing from anywhere else.
	 *
	 * @return void
	 */
	public function test_the_page_says_what_the_document_says(): void {
		$document = $this->issued();
		$html     = $this->html->for_document( $document );

		$this->assertStringContainsString( $document->number->formatted, $html );
		$this->assertStringContainsString( 'Oxysoft S.r.l.', $html );
		$this->assertStringContainsString( 'Bianchi S.p.A.', $html );
		$this->assertStringContainsString( 'Via Torino 5', $html );
		$this->assertStringContainsString( 'Product A', $html );
		$this->assertStringContainsString( 'Handle with care', $html );
	}

	/**
	 * A cancelled document says so on its face.
	 *
	 * @return void
	 */
	public function test_a_cancelled_document_is_marked_on_the_page(): void {
		$cancelled = $this->issuer->cancel( $this->issued(), 'Wrong recipient' );

		$html = $this->html->for_document( $cancelled );

		$this->assertStringContainsString( 'Wrong recipient', $html );
		$this->assertStringContainsString( 'CANCELLED', $html );
	}

	/**
	 * The engine produces something a PDF reader would open.
	 *
	 * @return void
	 */
	public function test_it_produces_a_pdf(): void {
		$bytes = $this->pdf->bytes( $this->issued() );

		$this->assertStringStartsWith( '%PDF', $bytes );
		$this->assertGreaterThan( 1000, strlen( $bytes ), 'a one-page delivery note is not 200 bytes' );
	}

	/**
	 * Archiving records where the file went and what it was.
	 *
	 * @return void
	 */
	public function test_archiving_records_the_file_and_its_hash(): void {
		$document = $this->issued();

		$archived = $this->pdf->archive( $document );

		$this->assertTrue( $this->archive->exists( $archived['path'] ) );
		$this->assertSame( 64, strlen( $archived['hash'] ) );
		$this->assertSame( $archived['hash'], hash( 'sha256', $this->archive->read( $archived['path'] ) ) );
		$this->assertTrue( $this->pdf->is_archived( $document ) );

		$stored = $this->documents->pdf_of( $document->id );

		$this->assertIsArray( $stored );
		$this->assertSame( $archived['path'], $stored['path'] );
	}

	/**
	 * Downloading the same document twice gives the same file, byte for byte.
	 * The copy that was issued is the copy that is kept.
	 *
	 * @return void
	 */
	public function test_the_archived_copy_is_what_comes_back(): void {
		$document = $this->issued();

		$this->pdf->archive( $document );

		$first  = $this->pdf->bytes( $document );
		$second = $this->pdf->bytes( $document );

		$this->assertSame( $first, $second );
	}

	/**
	 * A file that has gone missing — a restore that skipped uploads — is rebuilt
	 * from the snapshot rather than leaving a shop with nothing to hand over.
	 *
	 * @return void
	 */
	public function test_a_missing_file_is_rebuilt(): void {
		$document = $this->issued();
		$archived = $this->pdf->archive( $document );

		wp_delete_file( $this->archive->absolute( $archived['path'] ) );

		$this->assertFalse( $this->pdf->is_archived( $document ) );

		$bytes = $this->pdf->bytes( $document );

		$this->assertStringStartsWith( '%PDF', $bytes );
		$this->assertTrue( $this->pdf->is_archived( $document ), 'and archived again' );

		$now = $this->documents->pdf_of( $document->id );

		$this->assertIsArray( $now );
		$this->assertNotSame( $archived['path'], $now['path'], 'a new file, recorded as such' );
	}

	/**
	 * A draft is rendered fresh and never archived: it changes.
	 *
	 * @return void
	 */
	public function test_a_draft_is_not_archived(): void {
		$draft = $this->documents->save( $this->drafts->draft_from_order( $this->an_order() ) );

		$bytes = $this->pdf->bytes( $draft );

		$this->assertStringStartsWith( '%PDF', $bytes );
		$this->assertFalse( $this->pdf->is_archived( $draft ) );
		$this->assertNull( $this->documents->pdf_of( $draft->id ) );
	}

	/**
	 * The archive directory refuses to be browsed, on whichever server this is.
	 *
	 * @return void
	 */
	public function test_the_archive_directory_is_closed(): void {
		$this->pdf->archive( $this->issued() );

		foreach ( array( '.htaccess', 'web.config', 'index.php' ) as $guard ) {
			$this->assertFileExists( $this->archive->absolute( PdfStore::DIRECTORY . '/' . $guard ) );
		}
	}

	/**
	 * Two documents never share a filename, and neither is guessable from the
	 * other.
	 *
	 * @return void
	 */
	public function test_filenames_are_not_guessable(): void {
		$first  = $this->pdf->archive( $this->issued() );
		$second = $this->pdf->archive( $this->issued() );

		$this->assertNotSame( $first['path'], $second['path'] );
		$this->assertMatchesRegularExpression( '#^oxyddt/\d{4}/ddt-[a-z0-9-]+-[A-Za-z0-9]{20}\.pdf$#', $first['path'] );
	}

	/**
	 * What the file is called when somebody downloads it.
	 *
	 * @return void
	 */
	public function test_the_download_is_named_after_the_document(): void {
		$document = $this->issued();

		$this->assertSame(
			'ddt-' . str_replace( '/', '-', $document->number->formatted ) . '.pdf',
			$this->pdf->filename( $document )
		);
	}
}
