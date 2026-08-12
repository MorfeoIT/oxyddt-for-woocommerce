<?php
/**
 * The shop, as it appears on a delivery note.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * Who is sending the goods.
 *
 * This is the sender block printed at the top of every document. It is
 * configured once, in the settings, and copied into each delivery note as it is
 * issued: a shop that changes its address next year must not find last year's
 * documents rewritten.
 *
 * The plugin holds one of these. Multiple senders and multiple warehouses are a
 * PRO feature, and the shape here is already the shape they will reuse.
 */
final class Company {

	/**
	 * Build the sender.
	 *
	 * @param string       $name       Registered name.
	 * @param Address      $address    Registered address.
	 * @param string       $vat_number Partita IVA, digits only.
	 * @param string       $tax_code   Codice fiscale.
	 * @param string       $phone      Telephone, as it should be printed.
	 * @param string       $email      Contact address.
	 * @param int          $logo_id    Attachment ID of the logo, 0 when there is none.
	 * @param Address|null $origin     Where goods actually leave from, when that is
	 *                                 not the registered address.
	 */
	public function __construct(
		public readonly string $name = '',
		public readonly Address $address = new Address(),
		public readonly string $vat_number = '',
		public readonly string $tax_code = '',
		public readonly string $phone = '',
		public readonly string $email = '',
		public readonly int $logo_id = 0,
		public readonly ?Address $origin = null
	) {
	}

	/**
	 * Build the sender from whatever a form or an import produced.
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$string = static fn ( string $key ): string =>
			isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';

		$address = Address::from_array( is_array( $data['address'] ?? null ) ? $data['address'] : array() );
		$origin  = Address::from_array( is_array( $data['origin'] ?? null ) ? $data['origin'] : array() );

		return new self(
			$string( 'name' ),
			$address,
			ItalianTaxId::normalise_vat( $string( 'vat_number' ) ),
			ItalianTaxId::normalise_tax_code( $string( 'tax_code' ) ),
			$string( 'phone' ),
			$string( 'email' ),
			isset( $data['logo_id'] ) && is_numeric( $data['logo_id'] ) && (int) $data['logo_id'] > 0
				? (int) $data['logo_id']
				: 0,
			// An origin that was left blank is not an origin. Storing an empty one
			// would put a lone country code where a warehouse should be.
			$origin->is_empty() ? null : $origin
		);
	}

	/**
	 * The sender as a plain array, ready to be stored.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'name'       => $this->name,
			'address'    => $this->address->to_array(),
			'vat_number' => $this->vat_number,
			'tax_code'   => $this->tax_code,
			'phone'      => $this->phone,
			'email'      => $this->email,
			'logo_id'    => $this->logo_id,
			'origin'     => null === $this->origin ? null : $this->origin->to_array(),
		);
	}

	/**
	 * Where the goods leave from.
	 *
	 * The registered address unless a separate one was given, which is what every
	 * document that has no warehouse of its own should print.
	 *
	 * @return Address
	 */
	public function shipping_origin(): Address {
		return $this->origin ?? $this->address;
	}

	/**
	 * What is wrong with the sender.
	 *
	 * Codes rather than sentences, for the same reason as Address: this layer has
	 * no way to translate anything. Address problems come back prefixed, so that
	 * a screen can put each message next to the field it belongs to.
	 *
	 * @return list<string> Error codes, empty when the sender is usable.
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

		if ( '' === $this->vat_number && '' === $this->tax_code ) {
			// One of the two is what identifies the sender on the document. Which
			// one depends on what the sender is, so the plugin asks for either and
			// insists on neither in particular.
			$errors[] = 'tax_id_missing';
		}

		if ( '' !== $this->email && false === filter_var( $this->email, FILTER_VALIDATE_EMAIL ) ) {
			$errors[] = 'email_invalid';
		}

		if ( null !== $this->origin ) {
			foreach ( $this->origin->errors() as $code ) {
				$errors[] = 'origin.' . $code;
			}
		}

		return $errors;
	}

	/**
	 * Whether a delivery note may be issued in this sender's name.
	 *
	 * Sprint 4 is what refuses to issue when this is false. Sprint 1 only has to
	 * be able to answer the question, and to say so on the settings screen while
	 * there is still time to fix it.
	 *
	 * @return bool
	 */
	public function is_ready_to_issue(): bool {
		return array() === $this->errors();
	}
}
