<?php
/**
 * A postal address.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * Where something is, written the way it will be printed.
 *
 * Immutable, and plain PHP. A delivery note keeps a copy of an address at the
 * moment it was issued rather than a pointer to one, so the type that carries an
 * address has to be a value: something that cannot be changed under a document
 * that has already been handed to a driver.
 */
final class Address {

	/**
	 * Build an address.
	 *
	 * @param string $street   Street and number, one line.
	 * @param string $postcode CAP, or the local equivalent abroad.
	 * @param string $city     Town or city.
	 * @param string $province Two-letter Italian province, empty elsewhere.
	 * @param string $country  ISO 3166-1 alpha-2 country code.
	 */
	public function __construct(
		public readonly string $street = '',
		public readonly string $postcode = '',
		public readonly string $city = '',
		public readonly string $province = '',
		public readonly string $country = 'IT'
	) {
	}

	/**
	 * Build an address from whatever a form or an import produced.
	 *
	 * Missing keys become empty strings rather than errors: an address is
	 * validated by errors(), in one place, and not by every call site that
	 * happens to construct one.
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$string = static fn ( string $key, string $fallback = '' ): string =>
			isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : $fallback;

		return new self(
			$string( 'street' ),
			$string( 'postcode' ),
			$string( 'city' ),
			strtoupper( $string( 'province' ) ),
			strtoupper( $string( 'country', 'IT' ) )
		);
	}

	/**
	 * The address as a plain array, ready to be stored.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return array(
			'street'   => $this->street,
			'postcode' => $this->postcode,
			'city'     => $this->city,
			'province' => $this->province,
			'country'  => $this->country,
		);
	}

	/**
	 * Whether nothing has been filled in.
	 *
	 * The country does not count. It defaults to IT and would otherwise make
	 * every empty address look half-written.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return '' === $this->street
			&& '' === $this->postcode
			&& '' === $this->city
			&& '' === $this->province;
	}

	/**
	 * Whether the address is in Italy.
	 *
	 * @return bool
	 */
	public function is_italian(): bool {
		return 'IT' === $this->country;
	}

	/**
	 * What is wrong with the address.
	 *
	 * Returns codes, not sentences. This layer knows nothing about WordPress and
	 * therefore cannot translate; the screen that shows the errors turns each
	 * code into a message its user will understand.
	 *
	 * @return list<string> Error codes, empty when the address is usable.
	 */
	public function errors(): array {
		$errors = array();

		if ( '' === $this->street ) {
			$errors[] = 'street_missing';
		}

		if ( '' === $this->city ) {
			$errors[] = 'city_missing';
		}

		if ( 2 !== strlen( $this->country ) || 1 !== preg_match( '/^[A-Z]{2}$/', $this->country ) ) {
			$errors[] = 'country_invalid';
		}

		if ( ! $this->is_italian() ) {
			// Abroad, a postcode can be alphanumeric, six characters long or absent
			// altogether, and there are no provinces. Insisting on the Italian
			// shape would refuse addresses that are perfectly deliverable.
			return $errors;
		}

		if ( 1 !== preg_match( '/^\d{5}$/', $this->postcode ) ) {
			$errors[] = 'postcode_invalid';
		}

		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $this->province ) ) {
			$errors[] = 'province_invalid';
		}

		return $errors;
	}

	/**
	 * Whether the address can be printed on a document.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return array() === $this->errors();
	}

	/**
	 * The address on one line, as it reads on a document.
	 *
	 * @return string
	 */
	public function single_line(): string {
		$locality = trim( $this->postcode . ' ' . $this->city );

		if ( '' !== $this->province ) {
			$locality .= ' (' . $this->province . ')';
		}

		$parts = array_filter(
			array( $this->street, $locality, $this->is_italian() ? '' : $this->country ),
			static fn ( string $part ): bool => '' !== trim( $part )
		);

		return implode( ' – ', $parts );
	}
}
