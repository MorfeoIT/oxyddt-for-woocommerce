<?php
/**
 * The things that must not be possible.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Domain\DocumentQuery;
use Oxysoft\OxyDDT\Infrastructure\SystemClock;
use Oxysoft\OxyDDT\Infrastructure\Templates;
use Oxysoft\OxyDDT\Issuing\Issuer;
use Oxysoft\OxyDDT\Pdf\PdfStore;
use Oxysoft\OxyDDT\Persistence\DocumentRepository;
use Oxysoft\OxyDDT\Persistence\SequenceRepository;
use Oxysoft\OxyDDT\Security\Capabilities;
use Oxysoft\OxyDDT\Settings\Settings;
use Oxysoft\OxyDDT\WooCommerce\DocumentFactory;
use WP_UnitTestCase;

/**
 * Written as attempts rather than as features.
 *
 * Each of these is somebody trying something: climbing out of the uploads
 * directory, reading a template that is not a template, ending a SQL string
 * early, or simply being a customer with a browser. The assertions are what the
 * plugin does instead.
 */
final class SecurityTest extends WP_UnitTestCase {

	use ShopFixtures;

	/**
	 * The archive.
	 *
	 * @var PdfStore
	 */
	private PdfStore $archive;

	/**
	 * The store.
	 *
	 * @var DocumentRepository
	 */
	private DocumentRepository $documents;

	/**
	 * Build what is being attacked.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->archive   = new PdfStore();
		$this->documents = new DocumentRepository( new SystemClock() );
	}

	/**
	 * A path handed to the archive cannot climb out of the uploads directory.
	 *
	 * @return void
	 */
	public function test_the_archive_cannot_be_escaped(): void {
		$uploads = trailingslashit( (string) wp_upload_dir()['basedir'] );

		foreach (
			array(
				'../../../wp-config.php',
				'oxyddt/../../wp-config.php',
				'/etc/passwd',
				'..\\..\\wp-config.php',
				"oxyddt/\0/wp-config.php",
			) as $attempt
		) {
			$resolved = $this->archive->absolute( $attempt );

			$this->assertStringStartsWith( $uploads, $resolved, $attempt );
			$this->assertStringNotContainsString( '..', $resolved, $attempt );
		}
	}

	/**
	 * And a file outside it is not readable through the archive.
	 *
	 * @return void
	 */
	public function test_a_file_outside_the_archive_is_not_read(): void {
		$this->assertFalse( $this->archive->exists( '../../../wp-config.php' ) );
	}

	/**
	 * A template name cannot walk the disk either.
	 *
	 * @return void
	 */
	public function test_a_template_name_cannot_walk_the_disk(): void {
		$templates = new Templates();

		$this->assertSame( '', $templates->locate( '../../../wp-config.php' ) );
		$this->assertSame( '', $templates->render( '../../../wp-config.php' ) );
		$this->assertNotSame( '', $templates->locate( 'pdf/document.php' ), 'and a real one still resolves' );
	}

	/**
	 * A search box is not a way to end a SQL string.
	 *
	 * @return void
	 */
	public function test_the_register_cannot_be_talked_into_anything(): void {
		$this->issue_one();

		foreach (
			array(
				"' OR 1=1 --",
				'"; DROP TABLE wptests_oxyddt_documents; --',
				'%',
				'_',
				'\\',
			) as $attempt
		) {
			$result = $this->documents->search( DocumentQuery::from_array( array( 'search' => $attempt ) ) );

			$this->assertSame( 0, $result['total'], $attempt );
		}

		// And the table is still there afterwards, which is the point of the
		// second one.
		$this->assertSame( 1, $this->documents->search( DocumentQuery::all() )['total'] );
	}

	/**
	 * A sort column comes from the plugin, never from a request.
	 *
	 * @return void
	 */
	public function test_the_sort_order_cannot_be_dictated(): void {
		$this->issue_one();

		$result = $this->documents->search(
			DocumentQuery::from_array(
				array(
					'order_by'  => 'document_date; DROP TABLE wptests_oxyddt_documents',
					'order_dir' => 'ASC; DROP TABLE wptests_oxyddt_documents',
				)
			)
		);

		$this->assertSame( 1, $result['total'] );
	}

	/**
	 * A customer holds nothing. Not the register, not the PDF, not the button.
	 *
	 * @return void
	 */
	public function test_a_customer_holds_nothing(): void {
		foreach ( array( 'subscriber', 'customer', 'contributor', 'author', 'editor' ) as $role ) {
			if ( null === get_role( $role ) ) {
				continue;
			}

			$user = self::factory()->user->create_and_get( array( 'role' => $role ) );

			foreach ( Capabilities::all() as $capability ) {
				$this->assertFalse( $user->has_cap( $capability ), $role . ' / ' . $capability );
			}
		}
	}

	/**
	 * A shop manager ships and does not reconfigure. Stated twice on purpose:
	 * once where the map is written, once here, where it is somebody's account.
	 *
	 * @return void
	 */
	public function test_a_shop_manager_cannot_change_the_numbering(): void {
		if ( null === get_role( 'shop_manager' ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed in this environment.' );
		}

		$user = self::factory()->user->create_and_get( array( 'role' => 'shop_manager' ) );

		$this->assertFalse( $user->has_cap( Capabilities::MANAGE_SEQUENCES ) );
		$this->assertFalse( $user->has_cap( Capabilities::MANAGE_SETTINGS ) );
		$this->assertTrue( $user->has_cap( Capabilities::ISSUE ) );
	}

	/**
	 * The endpoints are registered behind admin-post.php, which is where a nonce
	 * and a capability are checked — and are not reachable from the front of the
	 * site by any other route.
	 *
	 * @return void
	 */
	public function test_the_endpoints_are_admin_post_only(): void {
		$this->assertGreaterThan( 0, has_action( 'admin_post_oxyddt_pdf' ) );
		$this->assertGreaterThan( 0, has_action( 'admin_post_oxyddt_send_document' ) );
		$this->assertGreaterThan( 0, has_action( 'admin_post_oxyddt_save_document' ) );

		// admin_post_nopriv_* would be the same endpoint open to anybody at all.
		$this->assertFalse( has_action( 'admin_post_nopriv_oxyddt_pdf' ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_oxyddt_send_document' ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_oxyddt_save_document' ) );
	}

	/**
	 * The link to a PDF carries a nonce, and one that is specific to that
	 * document rather than to the action.
	 *
	 * @return void
	 */
	public function test_a_pdf_link_is_signed_for_one_document(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$document = $this->issue_one();
		$url      = \Oxysoft\OxyDDT\Admin\DocumentActions::pdf_url( $document );

		$this->assertStringContainsString( '_wpnonce=', $url );

		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame(
			1,
			wp_verify_nonce( (string) ( $query['_wpnonce'] ?? '' ), 'oxyddt_pdf_' . $document->id )
		);

		$this->assertFalse(
			wp_verify_nonce( (string) ( $query['_wpnonce'] ?? '' ), 'oxyddt_pdf_' . ( $document->id + 1 ) ),
			'the same signature does not open the next document'
		);
	}

	/**
	 * Issue one delivery note, for the attempts to be made against.
	 *
	 * @return \Oxysoft\OxyDDT\Domain\Document
	 */
	private function issue_one(): \Oxysoft\OxyDDT\Domain\Document {
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

		$issuer = new Issuer(
			$this->documents,
			new SequenceRepository( $clock ),
			$settings,
			$clock,
			new AuditLog( $clock )
		);

		return $issuer->issue(
			$this->documents->save(
				( new DocumentFactory( $settings, $clock ) )->draft_from_order( $this->an_order() )
			)
		);
	}
}
