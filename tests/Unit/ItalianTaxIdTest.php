<?php
/**
 * The two numbers that get printed on a fiscal document.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\ItalianTaxId;
use PHPUnit\Framework\TestCase;

/**
 * Partita IVA and codice fiscale.
 */
final class ItalianTaxIdTest extends TestCase {

	/**
	 * However it is typed, one value is stored.
	 *
	 * @return void
	 */
	public function test_a_vat_number_is_normalised(): void {
		$this->assertSame( '01234567897', ItalianTaxId::normalise_vat( 'IT 012 345 678 97' ) );
		$this->assertSame( '01234567897', ItalianTaxId::normalise_vat( "\tit01234567897 " ) );
		$this->assertSame( '', ItalianTaxId::normalise_vat( 'IT' ) );
	}

	/**
	 * The check digit is what makes a VAT number more than eleven digits.
	 *
	 * @return void
	 */
	public function test_a_well_formed_vat_number_is_accepted(): void {
		$this->assertTrue( ItalianTaxId::is_valid_vat( '01234567897' ) );
		$this->assertTrue( ItalianTaxId::is_valid_vat( '12345678903' ) );
		$this->assertTrue( ItalianTaxId::is_valid_vat( 'IT 12345678903' ) );
	}

	/**
	 * And what makes a wrong one refusable.
	 *
	 * @return void
	 */
	public function test_a_broken_vat_number_is_refused(): void {
		$this->assertFalse( ItalianTaxId::is_valid_vat( '01234567890' ), 'wrong check digit' );
		$this->assertFalse( ItalianTaxId::is_valid_vat( '1234567890' ), 'ten digits' );
		$this->assertFalse( ItalianTaxId::is_valid_vat( '123456789031' ), 'twelve digits' );
		$this->assertFalse( ItalianTaxId::is_valid_vat( '' ) );
	}

	/**
	 * Eleven zeros pass the checksum and belong to nobody.
	 *
	 * @return void
	 */
	public function test_eleven_zeros_are_refused(): void {
		$this->assertFalse( ItalianTaxId::is_valid_vat( '00000000000' ) );
	}

	/**
	 * The sixteenth character of a codice fiscale.
	 *
	 * @return void
	 */
	public function test_the_check_character_is_computed(): void {
		$this->assertSame( 'S', ItalianTaxId::tax_code_check_character( 'RSSMRA85T10A562' ) );
		$this->assertSame( '', ItalianTaxId::tax_code_check_character( 'TOO-SHORT' ) );
	}

	/**
	 * A whole codice fiscale.
	 *
	 * @return void
	 */
	public function test_a_well_formed_tax_code_is_accepted(): void {
		$this->assertTrue( ItalianTaxId::is_valid_tax_code( 'RSSMRA85T10A562S' ) );
		$this->assertTrue( ItalianTaxId::is_valid_tax_code( 'rssmra85t10a562s' ), 'case does not matter' );
		$this->assertTrue( ItalianTaxId::is_valid_tax_code( 'RSS MRA 85T10 A562S' ), 'nor do spaces' );
	}

	/**
	 * Omocodia is not a typo. Refusing it refuses real people.
	 *
	 * @return void
	 */
	public function test_a_substituted_tax_code_is_accepted(): void {
		$this->assertTrue( ItalianTaxId::is_valid_tax_code( 'RSSMRA85T10A56NH' ) );
	}

	/**
	 * A wrong check character, a wrong length, a wrong month letter.
	 *
	 * @return void
	 */
	public function test_a_broken_tax_code_is_refused(): void {
		$this->assertFalse( ItalianTaxId::is_valid_tax_code( 'RSSMRA85T10A562X' ), 'wrong check character' );
		$this->assertFalse( ItalianTaxId::is_valid_tax_code( 'RSSMRA85T10A562' ), 'fifteen characters' );
		$this->assertFalse( ItalianTaxId::is_valid_tax_code( 'RSSMRA85Z10A562S' ), 'Z is not a month' );
		$this->assertFalse( ItalianTaxId::is_valid_tax_code( '' ) );
	}

	/**
	 * A company's codice fiscale is usually its VAT number.
	 *
	 * @return void
	 */
	public function test_eleven_digits_are_read_as_a_company_tax_code(): void {
		$this->assertTrue( ItalianTaxId::is_valid_tax_code( '01234567897' ) );
		$this->assertFalse( ItalianTaxId::is_valid_tax_code( '01234567890' ) );
	}
}
