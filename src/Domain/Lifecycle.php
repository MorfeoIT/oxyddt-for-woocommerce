<?php
/**
 * When things happened to a document, and who did them.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * The dates a document carries, kept together and never edited in place.
 *
 * Times are stored in UTC, as WordPress does. The *document date* is not here:
 * that one is a local fact printed on the paper, and it lives on the document
 * itself.
 */
final class Lifecycle {

	/**
	 * Build the lifecycle.
	 *
	 * @param string|null $created_at    When the draft was started, "Y-m-d H:i:s" UTC.
	 * @param int         $created_by    Who started it.
	 * @param string|null $updated_at    When it was last changed.
	 * @param string|null $issued_at     When it was issued.
	 * @param int         $issued_by     Who issued it.
	 * @param string|null $cancelled_at  When it was cancelled.
	 * @param int         $cancelled_by  Who cancelled it.
	 * @param string      $cancel_reason Why, in their words.
	 */
	public function __construct(
		public readonly ?string $created_at = null,
		public readonly int $created_by = 0,
		public readonly ?string $updated_at = null,
		public readonly ?string $issued_at = null,
		public readonly int $issued_by = 0,
		public readonly ?string $cancelled_at = null,
		public readonly int $cancelled_by = 0,
		public readonly string $cancel_reason = ''
	) {
	}

	/**
	 * Build the lifecycle from stored values.
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$time = static function ( string $key ) use ( $data ): ?string {
			if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
				return null;
			}

			$value = trim( (string) $data[ $key ] );

			// MySQL's zero date is how "never" looks coming back out of a column
			// that cannot be null. It is not a moment in time.
			return '' === $value || '0000-00-00 00:00:00' === $value ? null : $value;
		};

		$user = static fn ( string $key ): int =>
			isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ? max( 0, (int) $data[ $key ] ) : 0;

		return new self(
			$time( 'created_at' ),
			$user( 'created_by' ),
			$time( 'updated_at' ),
			$time( 'issued_at' ),
			$user( 'issued_by' ),
			$time( 'cancelled_at' ),
			$user( 'cancelled_by' ),
			isset( $data['cancel_reason'] ) && is_scalar( $data['cancel_reason'] )
				? trim( (string) $data['cancel_reason'] )
				: ''
		);
	}

	/**
	 * The lifecycle as a plain array, ready to be stored.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'created_at'    => $this->created_at,
			'created_by'    => $this->created_by,
			'updated_at'    => $this->updated_at,
			'issued_at'     => $this->issued_at,
			'issued_by'     => $this->issued_by,
			'cancelled_at'  => $this->cancelled_at,
			'cancelled_by'  => $this->cancelled_by,
			'cancel_reason' => $this->cancel_reason,
		);
	}

	/**
	 * The same lifecycle, touched now.
	 *
	 * @param string $now     The moment, "Y-m-d H:i:s" UTC.
	 * @param int    $user_id Who is saving.
	 * @return self
	 */
	public function touched( string $now, int $user_id = 0 ): self {
		return new self(
			$this->created_at ?? $now,
			0 === $this->created_by ? $user_id : $this->created_by,
			$now,
			$this->issued_at,
			$this->issued_by,
			$this->cancelled_at,
			$this->cancelled_by,
			$this->cancel_reason
		);
	}
}
