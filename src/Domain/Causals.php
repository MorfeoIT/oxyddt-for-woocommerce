<?php
/**
 * Why the goods are moving.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * The reason for transport, which is not optional on an Italian delivery note.
 *
 * Codes here, never labels: this layer cannot translate, and a code that
 * survives a change of wording is what lets a shop filter its register by
 * "conto lavorazione" three years later.
 *
 * The list is the usual one and it is not closed. A shop adds its own from the
 * screen that creates documents, and a stored document keeps whatever code it
 * was issued with even if the shop later deletes that reason — an issued
 * document does not change because a setting did.
 */
final class Causals {

	/**
	 * Sale. What most delivery notes are.
	 */
	public const SALE = 'sale';

	/**
	 * Goods sent for the customer to look at.
	 */
	public const ON_APPROVAL = 'on_approval';

	/**
	 * Goods sent to be worked on.
	 */
	public const PROCESSING = 'processing';

	/**
	 * Goods sent to be repaired.
	 */
	public const REPAIR = 'repair';

	/**
	 * Goods coming back.
	 */
	public const RETURNED = 'return';

	/**
	 * Goods replacing something.
	 */
	public const REPLACEMENT = 'replacement';

	/**
	 * Goods given.
	 */
	public const GIFT = 'gift';

	/**
	 * Goods moving between the shop's own places.
	 */
	public const INTERNAL_TRANSFER = 'internal_transfer';

	/**
	 * Something else, said in the notes.
	 */
	public const OTHER = 'other';

	/**
	 * The reasons the plugin ships with.
	 *
	 * @return list<string>
	 */
	public static function defaults(): array {
		return array(
			self::SALE,
			self::ON_APPROVAL,
			self::PROCESSING,
			self::REPAIR,
			self::RETURNED,
			self::REPLACEMENT,
			self::GIFT,
			self::INTERNAL_TRANSFER,
			self::OTHER,
		);
	}

	/**
	 * Every reason a shop can choose from: the built-in ones and its own.
	 *
	 * Built-in codes come back with an empty label because only the layer above
	 * can translate them; a shop's own reasons carry the words somebody typed,
	 * which are the words that belong on the paper and are never translated.
	 *
	 * A shop cannot redefine a built-in code by adding one with the same name:
	 * the register filters by code, and two meanings behind one code is a filter
	 * that quietly returns the wrong documents.
	 *
	 * @param array<string, string> $custom The shop's own reasons, code to label.
	 * @return array<string, string> Code to label, built-in first.
	 */
	public static function all( array $custom = array() ): array {
		$all = array();

		foreach ( self::defaults() as $code ) {
			$all[ $code ] = '';
		}

		foreach ( self::clean_custom( $custom ) as $code => $label ) {
			$all[ $code ] = $label;
		}

		return $all;
	}

	/**
	 * A shop's own reasons, in the shape they are stored in.
	 *
	 * @param array<string|int, mixed> $custom As typed or as stored.
	 * @return array<string, string> Code to label.
	 */
	public static function clean_custom( array $custom ): array {
		$clean = array();

		foreach ( $custom as $code => $label ) {
			$code = self::normalise( (string) $code );

			if ( '' === $code || self::is_default( $code ) || isset( $clean[ $code ] ) ) {
				continue;
			}

			$label = is_scalar( $label ) ? trim( (string) $label ) : '';

			// A reason with no words is a code somebody has to decode. Falling back
			// to the code itself at least prints something a human wrote.
			$clean[ $code ] = '' === $label ? $code : $label;
		}

		return $clean;
	}

	/**
	 * Whether a code is one of the plugin's own.
	 *
	 * A shop's own reasons are not "unknown", they are simply not ours, which is
	 * why nothing here refuses a code it does not recognise.
	 *
	 * @param string $code The code.
	 * @return bool
	 */
	public static function is_default( string $code ): bool {
		return in_array( $code, self::defaults(), true );
	}

	/**
	 * Turn whatever was typed into something storable.
	 *
	 * @param string $code As typed.
	 * @return string Lowercase, underscores, at most 64 characters.
	 */
	public static function normalise( string $code ): string {
		$clean = strtolower( trim( $code ) );
		$clean = (string) preg_replace( '/[^a-z0-9_-]+/', '_', $clean );

		return substr( trim( $clean, '_' ), 0, 64 );
	}
}
