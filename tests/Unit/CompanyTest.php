<?php
/**
 * The sender block.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\Address;
use Oxysoft\OxyDDT\Domain\Company;
use PHPUnit\Framework\TestCase;

/**
 * Who is sending the goods.
 */
final class CompanyTest extends TestCase {

	/**
	 * A sender that can appear on a document.
	 *
	 * @return array<string, mixed>
	 */
	private function complete(): array {
		return array(
			'name'       => 'Oxysoft S.r.l.',
			'vat_number' => 'IT 01234567897',
			'address'    => array(
				'street'   => 'Via Roma 1',
				'postcode' => '20121',
				'city'     => 'Milano',
				'province' => 'MI',
				'country'  => 'IT',
			),
		);
	}

	/**
	 * Nothing is wrong with a complete sender.
	 *
	 * @return void
	 */
	public function test_a_complete_sender_is_ready(): void {
		$company = Company::from_array( $this->complete() );

		$this->assertSame( array(), $company->errors() );
		$this->assertTrue( $company->is_ready_to_issue() );
	}

	/**
	 * The VAT number loses its prefix and its spaces on the way in.
	 *
	 * @return void
	 */
	public function test_the_vat_number_is_normalised(): void {
		$this->assertSame( '01234567897', Company::from_array( $this->complete() )->vat_number );
	}

	/**
	 * An empty sender says everything that is missing at once, rather than one
	 * thing at a time.
	 *
	 * @return void
	 */
	public function test_an_empty_sender_names_every_problem(): void {
		$errors = ( new Company() )->errors();

		$this->assertContains( 'name_missing', $errors );
		$this->assertContains( 'tax_id_missing', $errors );
		$this->assertContains( 'address.street_missing', $errors );
		$this->assertContains( 'address.city_missing', $errors );
	}

	/**
	 * One of the two identifiers is enough, and neither in particular.
	 *
	 * @return void
	 */
	public function test_a_tax_code_alone_identifies_the_sender(): void {
		$data               = $this->complete();
		$data['vat_number'] = '';
		$data['tax_code']   = 'RSSMRA85T10A562S';

		$this->assertSame( array(), Company::from_array( $data )->errors() );
	}

	/**
	 * A number that does not add up is worse than none: it will be printed.
	 *
	 * @return void
	 */
	public function test_a_broken_identifier_is_named(): void {
		$data               = $this->complete();
		$data['vat_number'] = '01234567890';

		$this->assertContains( 'vat_invalid', Company::from_array( $data )->errors() );

		$data                = $this->complete();
		$data['tax_code']    = 'RSSMRA85T10A562X';

		$this->assertContains( 'tax_code_invalid', Company::from_array( $data )->errors() );
	}

	/**
	 * An email that is not one.
	 *
	 * @return void
	 */
	public function test_a_broken_email_is_named(): void {
		$data          = $this->complete();
		$data['email'] = 'not an address';

		$this->assertContains( 'email_invalid', Company::from_array( $data )->errors() );
	}

	/**
	 * A blank origin is no origin, not an origin made of empty strings.
	 *
	 * @return void
	 */
	public function test_a_blank_origin_is_dropped(): void {
		$data           = $this->complete();
		$data['origin'] = array(
			'street'  => '',
			'city'    => '',
			'country' => 'IT',
		);

		$company = Company::from_array( $data );

		$this->assertNull( $company->origin );
		$this->assertEquals( $company->address, $company->shipping_origin() );
	}

	/**
	 * A warehouse of its own is printed instead of the registered address.
	 *
	 * @return void
	 */
	public function test_an_origin_replaces_the_registered_address_on_the_document(): void {
		$data           = $this->complete();
		$data['origin'] = array(
			'street'   => 'Via Milano 9',
			'postcode' => '20090',
			'city'     => 'Segrate',
			'province' => 'MI',
			'country'  => 'IT',
		);

		$company = Company::from_array( $data );

		$this->assertInstanceOf( Address::class, $company->origin );
		$this->assertSame( 'Segrate', $company->shipping_origin()->city );
		$this->assertSame( array(), $company->errors() );
	}

	/**
	 * A warehouse that is half written down is a problem worth naming, and one
	 * that belongs to the warehouse rather than to the registered address.
	 *
	 * @return void
	 */
	public function test_a_broken_origin_is_reported_separately(): void {
		$data           = $this->complete();
		$data['origin'] = array( 'street' => 'Via Milano 9' );

		$errors = Company::from_array( $data )->errors();

		$this->assertContains( 'origin.city_missing', $errors );
		$this->assertNotContains( 'address.city_missing', $errors );
	}

	/**
	 * A sender survives a round trip through storage.
	 *
	 * @return void
	 */
	public function test_it_survives_a_round_trip(): void {
		$company = Company::from_array( $this->complete() );

		$this->assertEquals( $company, Company::from_array( $company->to_array() ) );
	}

	/**
	 * A logo that is not an attachment is not a logo.
	 *
	 * @return void
	 */
	public function test_a_nonsense_logo_becomes_no_logo(): void {
		$data            = $this->complete();
		$data['logo_id'] = '-4';

		$this->assertSame( 0, Company::from_array( $data )->logo_id );
	}
}
