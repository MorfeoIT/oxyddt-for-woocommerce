<?php
/**
 * Delivery notes riding along on WooCommerce's own emails.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Infrastructure\SystemClock;
use Oxysoft\OxyDDT\Infrastructure\Templates;
use Oxysoft\OxyDDT\Issuing\Issuer;
use Oxysoft\OxyDDT\Mail\OrderEmailAttachments;
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
 * The one place in the plugin where a file leaves a shop because a status
 * changed rather than because somebody pressed a button.
 *
 * So the tests are mostly about when it does nothing.
 */
final class OrderEmailAttachmentsTest extends WP_UnitTestCase {

	use ShopFixtures;

	/**
	 * The store.
	 *
	 * @var DocumentRepository
	 */
	private DocumentRepository $documents;

	/**
	 * The subject.
	 *
	 * @var OrderEmailAttachments
	 */
	private OrderEmailAttachments $attachments;

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
	 * The settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Build a shop that can issue.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$clock          = new SystemClock();
		$this->settings = new Settings();

		$this->settings->update_company(
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
		$this->drafts    = new DocumentFactory( $this->settings, $clock );

		$pdf = new PdfService(
			new DompdfRenderer(),
			new PdfStore(),
			new DocumentHtml( new Templates(), $this->settings ),
			$this->documents,
			new AuditLog( $clock )
		);

		$this->issuer = new Issuer(
			$this->documents,
			new SequenceRepository( $clock ),
			$this->settings,
			$clock,
			new AuditLog( $clock )
		);

		$this->attachments = new OrderEmailAttachments( $this->documents, $pdf, $this->settings );
	}

	/**
	 * Nothing is attached until a shop names an email. This is the default, and
	 * the reason the feature is safe to ship.
	 *
	 * @return void
	 */
	public function test_nothing_is_attached_until_a_shop_asks(): void {
		$order = $this->an_order();

		$this->issuer->issue( $this->documents->save( $this->drafts->draft_from_order( $order ) ) );

		$this->assertSame(
			array(),
			$this->attachments->attach( array(), 'customer_completed_order', $order )
		);
	}

	/**
	 * The email a shop named gets the issued delivery notes; the others get
	 * nothing, however many documents the order has.
	 *
	 * @return void
	 */
	public function test_only_the_chosen_email_carries_the_notes(): void {
		$order = $this->an_order();

		$this->issuer->issue( $this->documents->save( $this->drafts->draft_from_order( $order ) ) );

		$this->settings->update( array( 'attach_to_emails' => array( 'customer_completed_order' ) ) );

		$chosen = $this->attachments->attach( array(), 'customer_completed_order', $order );
		$other  = $this->attachments->attach( array(), 'customer_invoice', $order );

		$this->assertCount( 1, $chosen );
		$this->assertFileExists( $chosen[0] );
		$this->assertSame( array(), $other );
	}

	/**
	 * A draft has no number and a cancelled note says on its face that it is
	 * void. Neither belongs in a customer's inbox.
	 *
	 * @return void
	 */
	public function test_drafts_and_cancelled_notes_are_not_attached(): void {
		$order = $this->an_order();

		$this->documents->save( $this->drafts->draft_from_order( $order ) );

		$issued = $this->issuer->issue(
			$this->documents->save( $this->drafts->draft_from_order( $order ) )
		);

		$this->issuer->cancel( $issued, 'Wrong address' );

		$this->settings->update( array( 'attach_to_emails' => array( 'customer_completed_order' ) ) );

		$this->assertSame(
			array(),
			$this->attachments->attach( array(), 'customer_completed_order', $order )
		);
	}

	/**
	 * What is already attached stays attached: WooCommerce's own invoice does
	 * not disappear because a delivery note joined it.
	 *
	 * @return void
	 */
	public function test_it_adds_to_what_is_already_there(): void {
		$order = $this->an_order();

		$this->issuer->issue( $this->documents->save( $this->drafts->draft_from_order( $order ) ) );
		$this->settings->update( array( 'attach_to_emails' => array( 'customer_completed_order' ) ) );

		$attached = $this->attachments->attach(
			array( '/tmp/somebody-elses.pdf' ),
			'customer_completed_order',
			$order
		);

		$this->assertCount( 2, $attached );
		$this->assertSame( '/tmp/somebody-elses.pdf', $attached[0] );
	}

	/**
	 * An email about something that is not an order — a password reset, a note
	 * to the admin — is left alone.
	 *
	 * @return void
	 */
	public function test_an_email_that_is_not_about_an_order_is_left_alone(): void {
		$this->settings->update( array( 'attach_to_emails' => array( 'customer_completed_order' ) ) );

		$this->assertSame(
			array(),
			$this->attachments->attach( array(), 'customer_completed_order', null )
		);
	}
}
