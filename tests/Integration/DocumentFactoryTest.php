<?php
/**
 * A draft from an order, and what happens to it afterwards.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Integration;

use Oxysoft\OxyDDT\Domain\Causals;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\Line;
use Oxysoft\OxyDDT\Infrastructure\SystemClock;
use Oxysoft\OxyDDT\Persistence\DocumentRepository;
use Oxysoft\OxyDDT\Settings\Settings;
use Oxysoft\OxyDDT\WooCommerce\DocumentFactory;
use WP_UnitTestCase;

/**
 * The snapshot, which is the whole point of sprint 2.
 */
final class DocumentFactoryTest extends WP_UnitTestCase {

	use ShopFixtures;

	/**
	 * The factory under test.
	 *
	 * @var DocumentFactory
	 */
	private DocumentFactory $factory;

	/**
	 * The store.
	 *
	 * @var DocumentRepository
	 */
	private DocumentRepository $documents;

	/**
	 * Configure a shop that could issue, and build the two services.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

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

		$this->factory   = new DocumentFactory( $settings, new SystemClock() );
		$this->documents = new DocumentRepository( new SystemClock() );
	}

	/**
	 * A draft arrives filled in with everything that can be known without
	 * asking anybody.
	 *
	 * @return void
	 */
	public function test_a_draft_arrives_ready_to_work_on(): void {
		$order = $this->an_order(
			array(
				'Product A' => 10.0,
				'Product B' => 5.0,
			)
		);

		$draft = $this->factory->draft_from_order( $order );

		$this->assertTrue( $draft->is_editable() );
		$this->assertFalse( $draft->number->is_assigned(), 'a draft must not reserve a number' );
		$this->assertSame( current_time( 'Y-m-d' ), $draft->document_date );
		$this->assertSame( Causals::SALE, $draft->causal );
		$this->assertSame( 'Oxysoft S.r.l.', $draft->parties->sender->name );
		$this->assertSame( 'Bianchi S.p.A.', $draft->parties->recipient->name );
		$this->assertCount( 2, $draft->lines );
		$this->assertSame( array( $order->get_id() ), $draft->all_order_ids() );
		$this->assertTrue( $draft->is_ready_to_issue() );
	}

	/**
	 * The lines start at the full ordered quantity. What actually goes out is a
	 * person's decision, and sprint 3 is where they make it.
	 *
	 * @return void
	 */
	public function test_the_lines_start_at_the_full_ordered_quantity(): void {
		$draft = $this->factory->draft_from_order( $this->an_order( array( 'Product A' => 10.0 ) ) );

		$this->assertEqualsWithDelta( 10.0, $draft->total_quantity(), Line::EPSILON );
	}

	/**
	 * A shop with an incomplete sender gets a draft that says so, rather than a
	 * refusal at the point where it is holding the goods.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_shop_gets_a_draft_that_says_what_is_missing(): void {
		$settings = new Settings();
		$settings->update_company( new Company() );

		$draft = ( new DocumentFactory( $settings, new SystemClock() ) )
			->draft_from_order( $this->an_order() );

		$this->assertFalse( $draft->is_ready_to_issue() );
		$this->assertContains( 'sender.name_missing', $draft->errors() );
	}

	/**
	 * **The test this sprint exists for.**
	 *
	 * A document that has been stored does not follow the order it came from.
	 * The customer changes their address, the shop edits the order, somebody
	 * removes a line — and the delivery note that was already printed says
	 * exactly what it said before.
	 *
	 * @return void
	 */
	public function test_a_stored_document_does_not_follow_the_order(): void {
		$order = $this->an_order( array( 'Product A' => 10.0 ) );
		$saved = $this->documents->save( $this->factory->draft_from_order( $order ) );

		$order = wc_get_order( $order->get_id() );
		$order->set_billing_company( 'Bianchi S.r.l., formerly S.p.A.' );
		$order->set_billing_city( 'Genova' );
		$order->set_billing_address_1( 'Via Genova 1' );

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$item->set_quantity( 1 );
			$item->set_name( 'Something else entirely' );
			$item->save();
		}

		$order->save();

		$loaded = $this->documents->find( $saved->id );

		$this->assertInstanceOf( Document::class, $loaded );
		$this->assertSame( 'Bianchi S.p.A.', $loaded->parties->recipient->name );
		$this->assertSame( 'Torino', $loaded->parties->recipient->address->city );
		$this->assertSame( 'Via Torino 5', $loaded->parties->recipient->address->street );
		$this->assertCount( 1, $loaded->lines );
		$this->assertSame( 'Product A', $loaded->lines[0]->name );
		$this->assertEqualsWithDelta( 10.0, $loaded->lines[0]->quantity, Line::EPSILON );
	}

	/**
	 * Nor does it follow the shop. A company that changes its address next year
	 * does not rewrite last year's delivery notes.
	 *
	 * @return void
	 */
	public function test_a_stored_document_does_not_follow_the_settings(): void {
		$saved = $this->documents->save( $this->factory->draft_from_order( $this->an_order() ) );

		$settings = new Settings();
		$settings->update_company(
			Company::from_array(
				array(
					'name'       => 'Oxysoft S.p.A.',
					'vat_number' => '12345678903',
					'address'    => array(
						'street'   => 'Via Nuova 2',
						'postcode' => '20122',
						'city'     => 'Milano',
						'province' => 'MI',
					),
				)
			)
		);

		$loaded = $this->documents->find( $saved->id );

		$this->assertInstanceOf( Document::class, $loaded );
		$this->assertSame( 'Oxysoft S.r.l.', $loaded->parties->sender->name );
		$this->assertSame( '01234567897', $loaded->parties->sender->vat_number );
		$this->assertSame( 'Via Roma 1', $loaded->parties->sender->address->street );
	}

	/**
	 * An order that has been shipped in parts finds all of its documents, and
	 * the link survives the order being edited.
	 *
	 * @return void
	 */
	public function test_an_order_finds_every_document_made_from_it(): void {
		$order = $this->an_order( array( 'Product A' => 10.0 ) );

		$first  = $this->documents->save( $this->factory->draft_from_order( $order ) );
		$second = $this->documents->save( $this->factory->draft_from_order( $order ) );

		$found = $this->documents->for_order( $order->get_id() );

		$this->assertCount( 2, $found );
		$this->assertSame( array( $first->id, $second->id ), array( $found[0]->id, $found[1]->id ) );
	}

	/**
	 * A shop can set its own defaults on every draft — the usual carrier, the
	 * usual reason — without touching the plugin.
	 *
	 * @return void
	 */
	public function test_a_shop_can_set_its_own_defaults(): void {
		$filter = static fn ( Document $draft ): Document => $draft->with_details( array( 'causal' => Causals::ON_APPROVAL ) );

		add_filter( 'oxyddt_draft_from_order', $filter );

		$draft = $this->factory->draft_from_order( $this->an_order() );

		remove_filter( 'oxyddt_draft_from_order', $filter );

		$this->assertSame( Causals::ON_APPROVAL, $draft->causal );
	}
}
