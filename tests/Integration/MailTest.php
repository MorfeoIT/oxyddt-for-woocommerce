<?php
/**
 * Sending a delivery note, through WordPress's own mailer.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Infrastructure\Migrator;
use Oxysoft\OxyDDT\Infrastructure\SystemClock;
use Oxysoft\OxyDDT\Infrastructure\Templates;
use Oxysoft\OxyDDT\Issuing\Issuer;
use Oxysoft\OxyDDT\Mail\DocumentMailer;
use Oxysoft\OxyDDT\Mail\MailException;
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
 * The email, with the PDF on it.
 */
final class MailTest extends WP_UnitTestCase {

	use ShopFixtures;

	/**
	 * The mailer.
	 *
	 * @var DocumentMailer
	 */
	private DocumentMailer $mailer;

	/**
	 * The store.
	 *
	 * @var DocumentRepository
	 */
	private DocumentRepository $documents;

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
	 * Build the services, and a clean mailbox.
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
		$this->drafts    = new DocumentFactory( $settings, $clock );

		$this->issuer = new Issuer(
			$this->documents,
			new SequenceRepository( $clock ),
			$settings,
			$clock,
			new AuditLog( $clock )
		);

		$this->mailer = new DocumentMailer(
			new PdfService(
				new DompdfRenderer(),
				new PdfStore(),
				new DocumentHtml( new Templates(), $settings ),
				$this->documents,
				new AuditLog( $clock )
			),
			new AuditLog( $clock )
		);

		reset_phpmailer_instance();
	}

	/**
	 * Put the mailbox back for whoever runs next.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		reset_phpmailer_instance();

		parent::tear_down();
	}

	/**
	 * An issued document.
	 *
	 * @return Document
	 */
	private function issued(): Document {
		return $this->issuer->issue(
			$this->documents->save( $this->drafts->draft_from_order( $this->an_order() ) )
		);
	}

	/**
	 * The email goes out with the delivery note attached.
	 *
	 * @return void
	 */
	public function test_it_sends_the_note_as_an_attachment(): void {
		$document = $this->issued();

		$sent = $this->mailer->send(
			$document,
			'ordini@example.test',
			$this->mailer->default_subject( $document ),
			$this->mailer->default_message( $document )
		);

		$this->assertTrue( $sent );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertNotFalse( $mail );
		$this->assertSame( 'ordini@example.test', $mail->to[0][0] );
		$this->assertStringContainsString( $document->number->formatted, $mail->subject );
		$this->assertStringContainsString( 'Bianchi S.p.A.', $mail->body );

		$attachments = tests_retrieve_phpmailer_instance()->getAttachments();

		$this->assertCount( 1, $attachments );
		$this->assertStringEndsWith( '.pdf', (string) $attachments[0][1] );
		$this->assertFileExists( (string) $attachments[0][0] );
	}

	/**
	 * Copies go where they were addressed, and the blind ones stay blind.
	 *
	 * @return void
	 */
	public function test_copies_are_addressed_and_blind_copies_are_not_named(): void {
		$document = $this->issued();

		$this->mailer->send(
			$document,
			'ordini@example.test',
			'Subject',
			'Message',
			array( 'magazzino@example.test', 'not an address', 'magazzino@example.test' ),
			array( 'archivio@example.test' )
		);

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertNotFalse( $mail );

		// Once, not twice: the same address pasted twice is one copy, and what
		// was not an address at all is dropped rather than refused — a typo in a
		// copy is no reason to stop the customer getting their delivery note.
		$this->assertSame( 1, substr_count( $mail->header, 'Cc: magazzino@example.test' ) );
		$this->assertStringNotContainsString( 'not an address', $mail->header );

		$log = $GLOBALS['wpdb']->get_var(
			$GLOBALS['wpdb']->prepare(
				'SELECT context FROM ' . Migrator::table( Migrator::TABLE_LOGS ) . ' WHERE document_id = %d AND event = %s ORDER BY id DESC LIMIT 1',
				$document->id,
				AuditLog::DOCUMENT_SENT
			)
		);

		// The log counts the blind copies and does not name them: writing them
		// into a register several people can read is one way of un-blinding them.
		$this->assertStringNotContainsString( 'archivio@example.test', (string) $log );
		$this->assertStringContainsString( '"bcc":1', (string) $log );
	}

	/**
	 * Sending archives the PDF if nobody had yet, so what the customer receives
	 * is the copy the shop keeps.
	 *
	 * @return void
	 */
	public function test_sending_archives_the_same_copy_the_shop_keeps(): void {
		$document = $this->issued();

		$this->mailer->send( $document, 'ordini@example.test', 'Subject', 'Message' );

		$archived = $this->documents->pdf_of( $document->id );

		$this->assertIsArray( $archived );

		$attachments = tests_retrieve_phpmailer_instance()->getAttachments();

		$this->assertStringEndsWith( $archived['path'], str_replace( '\\', '/', (string) $attachments[0][0] ) );
	}

	/**
	 * A draft is not sent. It has no number, and a delivery note without one is
	 * not a document.
	 *
	 * @return void
	 */
	public function test_a_draft_is_not_sent(): void {
		$draft = $this->documents->save( $this->drafts->draft_from_order( $this->an_order() ) );

		$this->expectException( MailException::class );

		$this->mailer->send( $draft, 'ordini@example.test', 'Subject', 'Message' );
	}

	/**
	 * Neither is anything sent to something that is not an address.
	 *
	 * @return void
	 */
	public function test_it_refuses_a_bad_address(): void {
		$this->expectException( MailException::class );

		$this->mailer->send( $this->issued(), 'not an address', 'Subject', 'Message' );
	}

	/**
	 * A shop can rewrite the whole email without touching the plugin.
	 *
	 * @return void
	 */
	public function test_a_shop_can_rewrite_the_email(): void {
		$filter = static function ( array $email ): array {
			$email['subject'] = 'La vostra merce è in viaggio';

			return $email;
		};

		add_filter( 'oxyddt_email', $filter );

		$this->mailer->send( $this->issued(), 'ordini@example.test', 'Subject', 'Message' );

		remove_filter( 'oxyddt_email', $filter );

		$this->assertSame( 'La vostra merce è in viaggio', tests_retrieve_phpmailer_instance()->get_sent()->subject );
	}

	/**
	 * What was sent, and to whom, is in the register.
	 *
	 * @return void
	 */
	public function test_sending_is_recorded(): void {
		$document = $this->issued();

		$this->mailer->send( $document, 'ordini@example.test', 'Subject', 'Message' );

		$entries = ( new AuditLog( new SystemClock() ) )->recent( 5, $document->id );

		$this->assertNotEmpty( $entries );
		$this->assertSame( AuditLog::DOCUMENT_SENT, $entries[0]['event'] );
		$this->assertStringContainsString( 'ordini@example.test', (string) $entries[0]['message'] );
	}
}
