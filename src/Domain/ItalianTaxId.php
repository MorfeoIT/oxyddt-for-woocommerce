<?php
/**
 * The two Italian tax identifiers a delivery note can carry.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * Partita IVA and codice fiscale: normalising them, and checking they add up.
 *
 * Plain PHP with no WordPress in sight, so every branch below is covered by the
 * unit suite. That matters more here than anywhere else in the plugin: these two
 * numbers are printed on a fiscal document, and a shop that discovers a wrong
 * one discovers it from its accountant, months later, on paper it has already
 * handed to a customer.
 *
 * A checksum only proves the number is well formed. It does not prove the number
 * was ever issued, and nothing here should be described to a user as if it did.
 */
final class ItalianTaxId {

	/**
	 * Odd-position values for the codice fiscale check character.
	 *
	 * Straight from the specification, and no more derivable from the character
	 * than a lookup table ever is: the letters are not in alphabetical order of
	 * value on purpose.
	 */
	private const ODD = array(
		'0' => 1,
		'1' => 0,
		'2' => 5,
		'3' => 7,
		'4' => 9,
		'5' => 13,
		'6' => 15,
		'7' => 17,
		'8' => 19,
		'9' => 21,
		'A' => 1,
		'B' => 0,
		'C' => 5,
		'D' => 7,
		'E' => 9,
		'F' => 13,
		'G' => 15,
		'H' => 17,
		'I' => 19,
		'J' => 21,
		'K' => 2,
		'L' => 4,
		'M' => 18,
		'N' => 20,
		'O' => 11,
		'P' => 3,
		'Q' => 6,
		'R' => 8,
		'S' => 12,
		'T' => 14,
		'U' => 16,
		'V' => 10,
		'W' => 22,
		'X' => 25,
		'Y' => 24,
		'Z' => 23,
	);

	/**
	 * Strip a VAT number down to the eleven digits that carry meaning.
	 *
	 * People type "IT 012 345 678 90", and every one of those spellings has to
	 * become the same stored value, because the whole point of storing it is to
	 * compare and print it.
	 *
	 * @param string $value As typed.
	 * @return string Eleven digits, or whatever digits were there.
	 */
	public static function normalise_vat( string $value ): string {
		$upper = strtoupper( trim( $value ) );

		if ( 0 === strpos( $upper, 'IT' ) ) {
			$upper = substr( $upper, 2 );
		}

		return (string) preg_replace( '/\D/', '', $upper );
	}

	/**
	 * Whether a partita IVA is well formed.
	 *
	 * Eleven digits, the last of which is a check digit over the other ten
	 * (the Luhn variant the Agenzia delle Entrate uses: every second digit
	 * doubled, nines cast out).
	 *
	 * @param string $value As typed or already normalised.
	 * @return bool
	 */
	public static function is_valid_vat( string $value ): bool {
		$digits = self::normalise_vat( $value );

		if ( 1 !== preg_match( '/^\d{11}$/', $digits ) ) {
			return false;
		}

		// A run of eleven zeros satisfies the checksum and has never been issued
		// to anybody. It is what an empty form field looks like when somebody has
		// been told the field is required.
		if ( '00000000000' === $digits ) {
			return false;
		}

		$sum = 0;

		for ( $i = 0; $i < 10; $i++ ) {
			$digit = (int) $digits[ $i ];

			if ( 1 === $i % 2 ) {
				$digit *= 2;

				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}

			$sum += $digit;
		}

		$check = ( 10 - $sum % 10 ) % 10;

		return $check === (int) $digits[10];
	}

	/**
	 * Strip a codice fiscale down to its sixteen characters.
	 *
	 * @param string $value As typed.
	 * @return string
	 */
	public static function normalise_tax_code( string $value ): string {
		return (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( trim( $value ) ) );
	}

	/**
	 * Whether a codice fiscale is well formed.
	 *
	 * Accepts both shapes a delivery note actually carries: the sixteen-character
	 * personal code, and the eleven digits that a company's codice fiscale is,
	 * which for most companies is the same number as their partita IVA.
	 *
	 * The letters allowed where digits belong are not a mistake: they are
	 * omocodia, the substitution the tax office applies when two people would
	 * otherwise share a code. A validator that rejects them rejects real people.
	 *
	 * @param string $value As typed or already normalised.
	 * @return bool
	 */
	public static function is_valid_tax_code( string $value ): bool {
		$code = self::normalise_tax_code( $value );

		if ( 11 === strlen( $code ) ) {
			return self::is_valid_vat( $code );
		}

		$pattern = '/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/';

		if ( 1 !== preg_match( $pattern, $code ) ) {
			return false;
		}

		return substr( $code, -1 ) === self::tax_code_check_character( substr( $code, 0, 15 ) );
	}

	/**
	 * The sixteenth character of a codice fiscale.
	 *
	 * @param string $first_fifteen The first fifteen characters.
	 * @return string One letter, or an empty string if the input is not fifteen
	 *                usable characters.
	 */
	public static function tax_code_check_character( string $first_fifteen ): string {
		if ( 15 !== strlen( $first_fifteen ) ) {
			return '';
		}

		$sum = 0;

		for ( $i = 0; $i < 15; $i++ ) {
			$character = $first_fifteen[ $i ];

			// Odd and even count from one, as the specification does, so position
			// zero of the string is an odd position.
			if ( 0 === $i % 2 ) {
				if ( ! isset( self::ODD[ $character ] ) ) {
					return '';
				}

				$sum += self::ODD[ $character ];

				continue;
			}

			$even = self::even_value( $character );

			if ( null === $even ) {
				return '';
			}

			$sum += $even;
		}

		return chr( 65 + $sum % 26 );
	}

	/**
	 * The value of a character in an even position.
	 *
	 * Digits are worth themselves, letters their distance from A.
	 *
	 * @param string $character One character.
	 * @return int|null Null when the character is neither a digit nor A-Z.
	 */
	private static function even_value( string $character ): ?int {
		if ( 1 === preg_match( '/^\d$/', $character ) ) {
			return (int) $character;
		}

		if ( 1 === preg_match( '/^[A-Z]$/', $character ) ) {
			return ord( $character ) - 65;
		}

		return null;
	}
}
