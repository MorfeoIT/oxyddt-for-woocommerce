<?php
/**
 * What to say after a form has been handled.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Admin;

/**
 * A message that survives one redirect, for one user.
 *
 * Saving a form ends in a redirect, so the outcome cannot simply be printed. It
 * is stored for the person who did it and shown once, which also means two
 * people saving different screens at the same moment do not read each other's
 * confirmations.
 */
final class Notices {

	/**
	 * How long a message may wait to be read.
	 */
	private const TTL = 60;

	/**
	 * Keep a message for the current user.
	 *
	 * @param string $type    "success", "error" or "warning".
	 * @param string $message Already translated, and safe to print as text.
	 * @return void
	 */
	public static function remember( string $type, string $message ): void {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return;
		}

		$stored   = self::stored( $user_id );
		$stored[] = array(
			'type'    => in_array( $type, array( 'success', 'error', 'warning' ), true ) ? $type : 'success',
			'message' => $message,
		);

		set_transient( self::key( $user_id ), $stored, self::TTL );
	}

	/**
	 * Print and forget every message waiting for the current user.
	 *
	 * @return void
	 */
	public static function show(): void {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return;
		}

		$stored = self::stored( $user_id );

		if ( array() === $stored ) {
			return;
		}

		delete_transient( self::key( $user_id ) );

		foreach ( $stored as $notice ) {
			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				esc_attr( (string) $notice['type'] ),
				esc_html( (string) $notice['message'] )
			);
		}
	}

	/**
	 * The transient name.
	 *
	 * @param int $user_id The user.
	 * @return string
	 */
	private static function key( int $user_id ): string {
		return 'oxyddt_notices_' . $user_id;
	}

	/**
	 * What is waiting for a user.
	 *
	 * @param int $user_id The user.
	 * @return list<array{type: string, message: string}>
	 */
	private static function stored( int $user_id ): array {
		$stored = get_transient( self::key( $user_id ) );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clean = array();

		foreach ( $stored as $notice ) {
			if ( is_array( $notice ) && isset( $notice['type'], $notice['message'] ) ) {
				$clean[] = array(
					'type'    => (string) $notice['type'],
					'message' => (string) $notice['message'],
				);
			}
		}

		return $clean;
	}
}
