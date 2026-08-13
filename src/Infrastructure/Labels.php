<?php
/**
 * What the plugin's codes are called.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Infrastructure;

use Oxysoft\OxyDDT\Domain\Causals;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Domain\FulfilmentStatus;
use Oxysoft\OxyDDT\Domain\Transport;

/**
 * One place where a code becomes a word.
 *
 * The Domain stores codes because a code survives a change of wording and a
 * translation. Everything that shows one to a person comes here — the screens,
 * the register, the PDF and the emails — so that "conto lavorazione" reads the
 * same on paper as on screen.
 */
final class Labels {

	/**
	 * What a reason for transport is called.
	 *
	 * @param string $code The code.
	 * @return string
	 */
	public static function causal( string $code ): string {
		$labels = array(
			Causals::SALE              => __( 'Sale', 'oxyddt-for-woocommerce' ),
			Causals::ON_APPROVAL       => __( 'On approval', 'oxyddt-for-woocommerce' ),
			Causals::PROCESSING        => __( 'For processing', 'oxyddt-for-woocommerce' ),
			Causals::REPAIR            => __( 'For repair', 'oxyddt-for-woocommerce' ),
			Causals::RETURNED          => __( 'Return', 'oxyddt-for-woocommerce' ),
			Causals::REPLACEMENT       => __( 'Replacement', 'oxyddt-for-woocommerce' ),
			Causals::GIFT              => __( 'Gift', 'oxyddt-for-woocommerce' ),
			Causals::INTERNAL_TRANSFER => __( 'Internal transfer', 'oxyddt-for-woocommerce' ),
			Causals::OTHER             => __( 'Other', 'oxyddt-for-woocommerce' ),
		);

		/**
		 * Filters what the reasons for transport are called.
		 *
		 * Where a shop names its own, and where it renames ours.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, string> $labels Code to label.
		 */
		$labels = (array) apply_filters( 'oxyddt_causal_labels', $labels );

		return isset( $labels[ $code ] ) && is_scalar( $labels[ $code ] ) ? (string) $labels[ $code ] : $code;
	}

	/**
	 * What "in whose care" reads as.
	 *
	 * @param string $by Who carries the goods.
	 * @return string
	 */
	public static function carrier( string $by ): string {
		$labels = array(
			Transport::BY_SENDER    => __( 'The sender', 'oxyddt-for-woocommerce' ),
			Transport::BY_RECIPIENT => __( 'The recipient', 'oxyddt-for-woocommerce' ),
			Transport::BY_CARRIER   => __( 'A carrier', 'oxyddt-for-woocommerce' ),
		);

		return $labels[ $by ] ?? $by;
	}

	/**
	 * What "carriage" reads as.
	 *
	 * @param string $carriage Who pays.
	 * @return string
	 */
	public static function carriage( string $carriage ): string {
		$labels = array(
			Transport::CARRIAGE_PREPAID => __( 'Prepaid (the sender pays)', 'oxyddt-for-woocommerce' ),
			Transport::CARRIAGE_FORWARD => __( 'Forward (the recipient pays)', 'oxyddt-for-woocommerce' ),
		);

		return $labels[ $carriage ] ?? $carriage;
	}

	/**
	 * What a document's state is called.
	 *
	 * @param DocumentStatus $status The status.
	 * @return string
	 */
	public static function document_status( DocumentStatus $status ): string {
		switch ( $status ) {
			case DocumentStatus::Issued:
				return __( 'issued', 'oxyddt-for-woocommerce' );
			case DocumentStatus::Cancelled:
				return __( 'cancelled', 'oxyddt-for-woocommerce' );
			default:
				return __( 'draft', 'oxyddt-for-woocommerce' );
		}
	}

	/**
	 * How far an order has been fulfilled, in words.
	 *
	 * @param FulfilmentStatus $status The status.
	 * @return string
	 */
	public static function fulfilment_status( FulfilmentStatus $status ): string {
		switch ( $status ) {
			case FulfilmentStatus::Complete:
				return __( 'Fully sent', 'oxyddt-for-woocommerce' );
			case FulfilmentStatus::Partial:
				return __( 'Partly sent', 'oxyddt-for-woocommerce' );
			default:
				return __( 'Nothing sent yet', 'oxyddt-for-woocommerce' );
		}
	}

	/**
	 * A quantity, as a person writes it.
	 *
	 * Three decimals, and no trailing zeros: "4", not "4.000".
	 *
	 * @param float $quantity The quantity.
	 * @return string
	 */
	public static function quantity( float $quantity ): string {
		$formatted = number_format( $quantity, 3, '.', '' );

		return false === strpos( $formatted, '.' ) ? $formatted : rtrim( rtrim( $formatted, '0' ), '.' );
	}

	/**
	 * A date, the way the shop's own settings write it.
	 *
	 * @param string|null $date A date, "Y-m-d".
	 * @return string
	 */
	public static function date( ?string $date ): string {
		if ( null === $date || '' === $date ) {
			return '';
		}

		// Midday, not midnight: a date formatted across a timezone shift at 00:00
		// can come back as the day before, and a delivery note dated one day
		// earlier than it was issued is a document somebody has to explain.
		$timestamp = strtotime( $date . ' 12:00:00' );

		if ( false === $timestamp ) {
			return $date;
		}

		$formatted = wp_date( (string) get_option( 'date_format', 'd/m/Y' ), $timestamp );

		return false === $formatted ? $date : $formatted;
	}
}
