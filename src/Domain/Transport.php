<?php
/**
 * How the goods travel.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * The block of the document nobody reads until something goes missing.
 *
 * Who is carrying the goods, in whose care, in how many packages, weighing what,
 * looking like what. These are the fields an Italian delivery note carries so
 * that a load stopped on the road can be accounted for, and so that a shop can
 * prove what left and when.
 *
 * All of it is optional in the sense that a document without a carrier is still
 * a document. None of it is optional in the sense that a shop which never fills
 * it in has bought the wrong plugin.
 */
final class Transport {

	/**
	 * The sender takes the goods.
	 */
	public const BY_SENDER = 'sender';

	/**
	 * The recipient collects them.
	 */
	public const BY_RECIPIENT = 'recipient';

	/**
	 * A carrier is engaged.
	 */
	public const BY_CARRIER = 'carrier';

	/**
	 * Carriage paid: the sender pays.
	 */
	public const CARRIAGE_PREPAID = 'franco';

	/**
	 * Carriage forward: the recipient pays.
	 */
	public const CARRIAGE_FORWARD = 'assegnato';

	/**
	 * Build the transport block.
	 *
	 * @param string      $by               Who carries the goods.
	 * @param string      $carrier_name     Carrier, as it should be printed.
	 * @param int         $carrier_id       Carrier in the address book, 0 when typed by hand.
	 * @param string      $carriage         Who pays for the carriage.
	 * @param int         $packages         Number of packages.
	 * @param float|null  $weight_gross     Gross weight, when the shop weighs.
	 * @param float|null  $weight_net       Net weight, when the shop weighs.
	 * @param string      $goods_appearance What the load looks like from outside.
	 * @param string|null $started_at       When the transport began, "Y-m-d H:i:s".
	 */
	public function __construct(
		public readonly string $by = '',
		public readonly string $carrier_name = '',
		public readonly int $carrier_id = 0,
		public readonly string $carriage = '',
		public readonly int $packages = 0,
		public readonly ?float $weight_gross = null,
		public readonly ?float $weight_net = null,
		public readonly string $goods_appearance = '',
		public readonly ?string $started_at = null
	) {
	}

	/**
	 * Who may carry the goods.
	 *
	 * @return list<string>
	 */
	public static function carriers(): array {
		return array( self::BY_SENDER, self::BY_RECIPIENT, self::BY_CARRIER );
	}

	/**
	 * Who may pay for the carriage.
	 *
	 * @return list<string>
	 */
	public static function carriages(): array {
		return array( self::CARRIAGE_PREPAID, self::CARRIAGE_FORWARD );
	}

	/**
	 * Build the transport block from stored or posted values.
	 *
	 * Anything outside the known sets becomes empty rather than being stored: a
	 * document that says the goods travel "in whichever way" prints nonsense on
	 * a line somebody may have to justify.
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$string = static fn ( string $key ): string =>
			isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';

		$weight = static function ( string $key ) use ( $data ): ?float {
			return isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) && (float) $data[ $key ] > 0
				? (float) $data[ $key ]
				: null;
		};

		$by       = strtolower( $string( 'by' ) );
		$carriage = strtolower( $string( 'carriage' ) );
		$started  = $string( 'started_at' );

		return new self(
			in_array( $by, self::carriers(), true ) ? $by : '',
			$string( 'carrier_name' ),
			isset( $data['carrier_id'] ) && is_numeric( $data['carrier_id'] ) ? max( 0, (int) $data['carrier_id'] ) : 0,
			in_array( $carriage, self::carriages(), true ) ? $carriage : '',
			isset( $data['packages'] ) && is_numeric( $data['packages'] ) ? max( 0, (int) $data['packages'] ) : 0,
			$weight( 'weight_gross' ),
			$weight( 'weight_net' ),
			$string( 'goods_appearance' ),
			'' === $started ? null : $started
		);
	}

	/**
	 * The transport block as a plain array, ready to be stored.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'by'               => $this->by,
			'carrier_name'     => $this->carrier_name,
			'carrier_id'       => $this->carrier_id,
			'carriage'         => $this->carriage,
			'packages'         => $this->packages,
			'weight_gross'     => $this->weight_gross,
			'weight_net'       => $this->weight_net,
			'goods_appearance' => $this->goods_appearance,
			'started_at'       => $this->started_at,
		);
	}

	/**
	 * What is inconsistent about the transport block.
	 *
	 * Only what is genuinely contradictory, never what is merely absent: this is
	 * the part of the document a shop fills in differently every day, and a
	 * plugin that argues about it is a plugin that gets switched off.
	 *
	 * @return list<string> Error codes, empty when the block holds together.
	 */
	public function errors(): array {
		$errors = array();

		if ( self::BY_CARRIER === $this->by && '' === trim( $this->carrier_name ) && 0 === $this->carrier_id ) {
			$errors[] = 'carrier_missing';
		}

		if ( null !== $this->weight_gross && null !== $this->weight_net && $this->weight_net > $this->weight_gross ) {
			$errors[] = 'weight_inconsistent';
		}

		return $errors;
	}
}
