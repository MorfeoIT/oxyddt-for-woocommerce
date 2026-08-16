<?php
/**
 * The plugin's own settings.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Settings;

use Oxysoft\OxyDDT\Domain\Causals;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Domain\NumberingPolicy;

/**
 * Reads and writes the single option OxyDDT stores its configuration in.
 *
 * One option rather than a dozen: the sender block is read together, in full,
 * every time a document is prepared or printed, and one autoloaded row is
 * cheaper than fifteen.
 *
 * Numbering is deliberately not here. A sequence is a row that two requests
 * update at the same moment, and an option is exactly the wrong place for that;
 * sprint 4 gives it a table with a lock.
 */
final class Settings {

	/**
	 * The option name.
	 */
	public const OPTION = 'oxyddt_settings';

	/**
	 * Defaults, which are also the full list of recognised keys.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// The sender printed at the top of every document.
			'company'                  => ( new Company() )->to_array(),

			// How the shop numbers its delivery notes. The counter itself is not
			// here: a sequence is a row two requests update at the same moment, and
			// an option is exactly the wrong place for that.
			'numbering'                => ( new NumberingPolicy() )->to_array(),

			// Prices are off by default because a delivery note is not an invoice
			// and a shop that hands one to a courier rarely wants the amounts on
			// it. The figures are stored on every line whatever this says, so
			// turning it on is a decision about printing, not about recording.
			'show_prices'              => false,

			// Reasons for transport a shop added to the nine built in. Code to
			// label; the codes end up on issued documents, so they are kept even
			// after somebody deletes the reason from this list.
			'custom_causals'           => array(),

			// Carriers a shop uses often, offered as a list beside the field
			// somebody types in. A name, nothing more: the full address book is
			// PRO.
			'carriers'                 => array(),

			// WooCommerce emails the issued delivery notes of an order are
			// attached to. Empty by default: nothing leaves a shop because a
			// status changed unless somebody asked for it.
			'attach_to_emails'         => array(),

			// Deleting the plugin does not delete a shop's delivery notes. An
			// administrator who wants that turns this on first, and means it.
			// These are accounting records: the default cannot be to destroy them.
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Every setting, defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// Unknown keys are dropped rather than carried: a setting removed in an
		// upgrade should not linger in the row forever.
		return self::sanitize( array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) ) );
	}

	/**
	 * One setting.
	 *
	 * @param string $key           Setting name.
	 * @param mixed  $default_value Returned when the setting is not recognised.
	 * @return mixed
	 */
	public function get( string $key, $default_value = null ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * The sender, as an object rather than an array.
	 *
	 * @return Company
	 */
	public function company(): Company {
		$stored = $this->all()['company'];

		return Company::from_array( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Store the sender.
	 *
	 * @param Company $company The sender.
	 * @return void
	 */
	public function update_company( Company $company ): void {
		$this->update( array( 'company' => $company->to_array() ) );
	}

	/**
	 * How the shop numbers its delivery notes.
	 *
	 * @return NumberingPolicy
	 */
	public function numbering(): NumberingPolicy {
		$stored = $this->all()['numbering'];

		return NumberingPolicy::from_array( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Store the numbering policy.
	 *
	 * @param NumberingPolicy $numbering The policy.
	 * @return void
	 */
	public function update_numbering( NumberingPolicy $numbering ): void {
		$this->update( array( 'numbering' => $numbering->to_array() ) );
	}

	/**
	 * Write settings, merging over what is already stored.
	 *
	 * @param array<string, mixed> $changes Settings to change.
	 * @return void
	 */
	public function update( array $changes ): void {
		update_option( self::OPTION, self::sanitize( array_merge( $this->all(), $changes ) ) );
	}

	/**
	 * Coerce a settings array into the shape the plugin expects.
	 *
	 * Pure by design: it takes an array and returns an array, calls nothing, and
	 * is therefore the one part of the settings that can be tested without
	 * WordPress. Anything not recognised is discarded, which is what stops a
	 * crafted import from planting keys of its own.
	 *
	 * It normalises, it does not escape. Values are escaped where they are
	 * printed, which is the only place that knows whether they are going into
	 * HTML, an attribute or a PDF.
	 *
	 * @param array<string, mixed> $input Raw settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$company   = $input['company'] ?? array();
		$numbering = $input['numbering'] ?? array();

		return array(
			// Round-tripping through the value object is the normalisation: the VAT
			// number loses its spaces and its "IT", the province becomes uppercase,
			// an origin left blank becomes no origin at all.
			'company'                  => Company::from_array( is_array( $company ) ? $company : array() )->to_array(),
			'numbering'                => NumberingPolicy::from_array( is_array( $numbering ) ? $numbering : array() )->to_array(),
			'show_prices'              => ! empty( $input['show_prices'] ),
			'custom_causals'           => Causals::clean_custom( is_array( $input['custom_causals'] ?? null ) ? $input['custom_causals'] : array() ),
			'carriers'                 => self::clean_list( $input['carriers'] ?? array() ),
			'attach_to_emails'         => self::clean_list( $input['attach_to_emails'] ?? array() ),
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
		);
	}

	/**
	 * The reasons for transport this shop offers: the built-in ones and its own.
	 *
	 * @return array<string, string> Code to label.
	 */
	public function causals(): array {
		$stored = $this->all()['custom_causals'];

		return Causals::all( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * The carriers a shop uses often.
	 *
	 * @return list<string>
	 */
	public function carriers(): array {
		$stored = $this->all()['carriers'];

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
	}

	/**
	 * Whether prices are printed on the paper.
	 *
	 * @return bool
	 */
	public function shows_prices(): bool {
		return (bool) $this->all()['show_prices'];
	}

	/**
	 * A list of strings, trimmed, without blanks or repeats.
	 *
	 * @param mixed $input Raw value.
	 * @return list<string>
	 */
	private static function clean_list( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$clean = array();

		foreach ( $input as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );

			if ( '' === $value || in_array( $value, $clean, true ) ) {
				continue;
			}

			$clean[] = $value;
		}

		return $clean;
	}
}
