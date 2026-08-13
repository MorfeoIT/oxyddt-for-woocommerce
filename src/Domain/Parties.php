<?php
/**
 * The three parties on the header of a delivery note.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * Sender, recipient, and where the goods are actually going.
 *
 * The destination is separate from the recipient on purpose, and it is the
 * distinction that makes the document useful: goods invoiced to a company's
 * registered office are routinely delivered to a shop, a site or a warehouse.
 * When they are the same the destination is simply the recipient's address, and
 * the document says so.
 *
 * All three are snapshots. Once a document is issued, nothing here follows the
 * customer, the order or the settings anywhere.
 */
final class Parties {

	/**
	 * Build the header.
	 *
	 * @param Company      $sender      The shop, as configured on the day.
	 * @param Party        $recipient   The customer, as they read on the day.
	 * @param Address|null $destination Where the goods go, when not the recipient's address.
	 */
	public function __construct(
		public readonly Company $sender = new Company(),
		public readonly Party $recipient = new Party(),
		public readonly ?Address $destination = null
	) {
	}

	/**
	 * Build the header from stored values.
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$destination = Address::from_array( is_array( $data['destination'] ?? null ) ? $data['destination'] : array() );

		return new self(
			Company::from_array( is_array( $data['sender'] ?? null ) ? $data['sender'] : array() ),
			Party::from_array( is_array( $data['recipient'] ?? null ) ? $data['recipient'] : array() ),
			$destination->is_empty() ? null : $destination
		);
	}

	/**
	 * The header as a plain array, ready to be stored.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'sender'      => $this->sender->to_array(),
			'recipient'   => $this->recipient->to_array(),
			'destination' => null === $this->destination ? null : $this->destination->to_array(),
		);
	}

	/**
	 * Where the goods are going.
	 *
	 * @return Address
	 */
	public function delivery_address(): Address {
		return $this->destination ?? $this->recipient->address;
	}

	/**
	 * Whether the goods go somewhere other than the recipient's own address.
	 *
	 * @return bool
	 */
	public function delivers_elsewhere(): bool {
		return null !== $this->destination
			&& $this->destination->to_array() !== $this->recipient->address->to_array();
	}

	/**
	 * The same header with a different recipient.
	 *
	 * @param Party $recipient The recipient.
	 * @return self
	 */
	public function with_recipient( Party $recipient ): self {
		return new self( $this->sender, $recipient, $this->destination );
	}

	/**
	 * What is wrong with the header.
	 *
	 * Codes, prefixed by the party they belong to, so a screen can put each
	 * message where its user will look for it.
	 *
	 * @return list<string>
	 */
	public function errors(): array {
		$errors = array();

		foreach ( $this->sender->errors() as $code ) {
			$errors[] = 'sender.' . $code;
		}

		foreach ( $this->recipient->errors() as $code ) {
			$errors[] = 'recipient.' . $code;
		}

		if ( null !== $this->destination ) {
			foreach ( $this->destination->errors() as $code ) {
				$errors[] = 'destination.' . $code;
			}
		}

		return $errors;
	}
}
