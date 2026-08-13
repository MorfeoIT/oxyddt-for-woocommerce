<?php
/**
 * Who receives the goods, frozen at the moment of issue.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * A photograph of a customer, not a pointer to one.
 *
 * This is the whole reason the class exists. A customer who moves house next
 * month, or an order somebody edits next week, must not change a delivery note
 * that was printed and handed to a driver. So the document keeps a copy of the
 * name, the address and the tax identifiers as they read on the day, and never
 * looks the customer up again.
 *
 * The name is already resolved: whoever built the snapshot decided whether the
 * company name or the person's name goes on the document. The Domain does not
 * know what a WooCommerce order looks like.
 */
final class Party {

	/**
	 * Build a party.
	 *
	 * @param string  $name       What is printed as the recipient.
	 * @param Address $address    Their address.
	 * @param string  $vat_number Partita IVA, digits only.
	 * @param string  $tax_code   Codice fiscale.
	 * @param string  $email      Contact address, for sending the document.
	 * @param string  $phone      Telephone, useful to a driver.
	 */
	public function __construct(
		public readonly string $name = '',
		public readonly Address $address = new Address(),
		public readonly string $vat_number = '',
		public readonly string $tax_code = '',
		public readonly string $email = '',
		public readonly string $phone = ''
	) {
	}

	/**
	 * Build a party from stored or posted values.
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$string = static fn ( string $key ): string =>
			isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';

		return new self(
			$string( 'name' ),
			Address::from_array( is_array( $data['address'] ?? null ) ? $data['address'] : array() ),
			ItalianTaxId::normalise_vat( $string( 'vat_number' ) ),
			ItalianTaxId::normalise_tax_code( $string( 'tax_code' ) ),
			$string( 'email' ),
			$string( 'phone' )
		);
	}

	/**
	 * The party as a plain array, ready to be stored.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'name'       => $this->name,
			'address'    => $this->address->to_array(),
			'vat_number' => $this->vat_number,
			'tax_code'   => $this->tax_code,
			'email'      => $this->email,
			'phone'      => $this->phone,
		);
	}

	/**
	 * Whether there is anybody here at all.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return '' === $this->name && $this->address->is_empty();
	}

	/**
	 * What is wrong with the recipient.
	 *
	 * Deliberately gentler than the sender. A shop knows its own VAT number; it
	 * does not always know its customer's, and a private customer has no reason
	 * to have one. What a delivery note cannot do without is somebody's name and
	 * somewhere to take the goods.
	 *
	 * @return list<string> Error codes, empty when the party is usable.
	 */
	public function errors(): array {
		$errors = array();

		if ( '' === $this->name ) {
			$errors[] = 'name_missing';
		}

		foreach ( $this->address->errors() as $code ) {
			$errors[] = 'address.' . $code;
		}

		if ( '' !== $this->vat_number && ! ItalianTaxId::is_valid_vat( $this->vat_number ) ) {
			$errors[] = 'vat_invalid';
		}

		if ( '' !== $this->tax_code && ! ItalianTaxId::is_valid_tax_code( $this->tax_code ) ) {
			$errors[] = 'tax_code_invalid';
		}

		return $errors;
	}

	/**
	 * Whether the goods can be addressed to this party.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return array() === $this->errors();
	}
}
