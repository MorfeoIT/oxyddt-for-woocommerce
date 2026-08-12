<?php
/**
 * The sender block, and the one setting that can lose data.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Admin;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\Address;
use Oxysoft\OxyDDT\Domain\Company;
use Oxysoft\OxyDDT\Infrastructure\Registrable;
use Oxysoft\OxyDDT\Security\Capabilities;
use Oxysoft\OxyDDT\Settings\Settings;

use const Oxysoft\OxyDDT\VERSION;

/**
 * Who is sending the goods, as it will be printed.
 *
 * The screen saves whatever was typed, including a sender that is not yet
 * complete, and says what is still missing. A shop configures this on the day it
 * installs the plugin, often without the accountant in the room, and a form that
 * refuses to save until every field is right is a form people abandon with
 * nothing stored. Refusing to *issue* a document is sprint 4's job, and that is
 * the right place for it: by then the shop is doing something that has to be
 * correct.
 */
final class SettingsScreen implements Registrable {

	/**
	 * The tab identifier.
	 */
	public const TAB = 'settings';

	/**
	 * The settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The page this tab belongs to.
	 *
	 * @var Screen
	 */
	private Screen $screen;

	/**
	 * The register.
	 *
	 * @var AuditLog
	 */
	private AuditLog $log;

	/**
	 * Build the screen.
	 *
	 * @param Settings $settings The settings.
	 * @param Screen   $screen   The page this tab belongs to.
	 * @param AuditLog $log      The register.
	 */
	public function __construct( Settings $settings, Screen $screen, AuditLog $log ) {
		$this->settings = $settings;
		$this->screen   = $screen;
		$this->log      = $log;
	}

	/**
	 * Add the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->screen->add_tab(
			new Tab(
				self::TAB,
				__( 'Settings', 'oxyddt-for-woocommerce' ),
				Capabilities::MANAGE_SETTINGS,
				// A closure, not an arrow function: `fn (): void => …` is a fatal
				// error in PHP, because an arrow function always returns its
				// expression and a void function may not return a value.
				function (): void {
					$this->render();
				}
			)
		);

		add_action( 'admin_post_oxyddt_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Load the media picker, on this screen only.
	 *
	 * @param string $hook_suffix The screen being loaded.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'woocommerce_page_' . Screen::SLUG !== $hook_suffix ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_script(
			'oxyddt-admin',
			\Oxysoft\OxyDDT\plugin_url() . 'assets/js/admin.js',
			array( 'jquery' ),
			VERSION,
			true
		);

		wp_localize_script(
			'oxyddt-admin',
			'oxyddtAdmin',
			array(
				'chooseLogo' => __( 'Choose the logo', 'oxyddt-for-woocommerce' ),
				'useLogo'    => __( 'Use this image', 'oxyddt-for-woocommerce' ),
			)
		);
	}

	/**
	 * Draw the tab.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die(
				esc_html__( 'You are not allowed to change these settings.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		$company  = $this->settings->company();
		$settings = $this->settings->all();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'oxyddt_save_settings' );
		echo '<input type="hidden" name="action" value="oxyddt_save_settings" />';

		echo '<h2>' . esc_html__( 'Sender', 'oxyddt-for-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Printed at the top of every delivery note, and copied into each document as it is issued. Changing it later does not rewrite documents already issued.', 'oxyddt-for-woocommerce' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		self::text_row( 'company[name]', __( 'Registered name', 'oxyddt-for-woocommerce' ), $company->name, 'regular-text' );
		self::text_row( 'company[vat_number]', __( 'VAT number (partita IVA)', 'oxyddt-for-woocommerce' ), $company->vat_number, 'regular-text', __( 'Eleven digits. The country prefix and any spaces are removed when it is saved.', 'oxyddt-for-woocommerce' ) );
		self::text_row( 'company[tax_code]', __( 'Tax code (codice fiscale)', 'oxyddt-for-woocommerce' ), $company->tax_code, 'regular-text', __( 'For most companies this is the same as the VAT number. One of the two is required.', 'oxyddt-for-woocommerce' ) );

		self::address_rows( 'company[address]', $company->address );

		self::text_row( 'company[phone]', __( 'Telephone', 'oxyddt-for-woocommerce' ), $company->phone, 'regular-text' );
		self::text_row( 'company[email]', __( 'Email', 'oxyddt-for-woocommerce' ), $company->email, 'regular-text' );

		$this->logo_row( $company->logo_id );

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Where the goods leave from', 'oxyddt-for-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Only when it is not the registered address. Leave it empty and the registered address is printed. More than one warehouse is a PRO feature.', 'oxyddt-for-woocommerce' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		self::address_rows( 'company[origin]', $company->origin ?? new Address( '', '', '', '', '' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Deleting the plugin', 'oxyddt-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Stored documents', 'oxyddt-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="delete_data_on_uninstall" value="1"'
			. checked( ! empty( $settings['delete_data_on_uninstall'] ), true, false ) . ' /> '
			. esc_html__( 'When OxyDDT is deleted, also delete its delivery notes, numbering and log', 'oxyddt-for-woocommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default, and it should stay off. Delivery notes are accounting records, and deleting a plugin is often just a way of testing something. Switching the plugin off never removes anything, whatever this says.', 'oxyddt-for-woocommerce' ) . '</p>';
		echo '</td></tr></tbody></table>';

		submit_button();
		echo '</form>';

		$this->render_readiness( $company );
	}

	/**
	 * Say plainly whether a document could be issued today.
	 *
	 * @param Company $company The sender as stored.
	 * @return void
	 */
	private function render_readiness( Company $company ): void {
		if ( $company->is_ready_to_issue() ) {
			echo '<p><strong>' . esc_html__( 'The sender is complete.', 'oxyddt-for-woocommerce' ) . '</strong></p>';

			return;
		}

		echo '<div class="notice notice-warning inline"><p><strong>'
			. esc_html__( 'The sender is not complete yet. Delivery notes cannot be issued until it is:', 'oxyddt-for-woocommerce' )
			. '</strong></p><ul style="list-style:disc;margin-left:2em">';

		foreach ( self::describe( $company->errors() ) as $message ) {
			echo '<li>' . esc_html( $message ) . '</li>';
		}

		echo '</ul></div>';
	}

	/**
	 * Save the settings.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		check_admin_referer( 'oxyddt_save_settings' );

		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die(
				esc_html__( 'You are not allowed to change these settings.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above is the verification.
		$raw = isset( $_POST['company'] ) && is_array( $_POST['company'] )
			? wp_unslash( $_POST['company'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised field by field below.
			: array();

		$company = Company::from_array(
			array(
				'name'       => self::clean( $raw, 'name' ),
				'vat_number' => self::clean( $raw, 'vat_number' ),
				'tax_code'   => self::clean( $raw, 'tax_code' ),
				'phone'      => self::clean( $raw, 'phone' ),
				'email'      => sanitize_email( self::clean( $raw, 'email' ) ),
				'logo_id'    => absint( self::clean( $raw, 'logo_id' ) ),
				'address'    => self::clean_address( is_array( $raw['address'] ?? null ) ? $raw['address'] : array() ),
				'origin'     => self::clean_address( is_array( $raw['origin'] ?? null ) ? $raw['origin'] : array() ),
			)
		);

		$this->settings->update(
			array(
				'company'                  => $company->to_array(),
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above is the verification.
				'delete_data_on_uninstall' => isset( $_POST['delete_data_on_uninstall'] ),
			)
		);

		$this->log->record(
			AuditLog::SETTINGS_UPDATED,
			'The sender and plugin settings were changed.',
			array( 'errors' => $company->errors() )
		);

		Notices::remember(
			'success',
			$company->is_ready_to_issue()
				? __( 'Saved.', 'oxyddt-for-woocommerce' )
				: __( 'Saved. The sender is not complete yet, so no delivery note can be issued.', 'oxyddt-for-woocommerce' )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => Screen::SLUG,
					'tab'  => self::TAB,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * One posted value, as text.
	 *
	 * @param array<string, mixed> $source Where to look.
	 * @param string               $key    Which field.
	 * @return string
	 */
	private static function clean( array $source, string $key ): string {
		$value = $source[ $key ] ?? '';

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * A posted address, field by field.
	 *
	 * @param array<string, mixed> $source Where to look.
	 * @return array<string, string>
	 */
	private static function clean_address( array $source ): array {
		return array(
			'street'   => self::clean( $source, 'street' ),
			'postcode' => self::clean( $source, 'postcode' ),
			'city'     => self::clean( $source, 'city' ),
			'province' => self::clean( $source, 'province' ),
			'country'  => self::clean( $source, 'country' ),
		);
	}

	/**
	 * Turn error codes into sentences.
	 *
	 * @param list<string> $codes What Company::errors() returned.
	 * @return list<string>
	 */
	public static function describe( array $codes ): array {
		$messages = array(
			'name_missing'     => __( 'The registered name is missing.', 'oxyddt-for-woocommerce' ),
			'tax_id_missing'   => __( 'Either a VAT number or a tax code is required.', 'oxyddt-for-woocommerce' ),
			'vat_invalid'      => __( 'The VAT number is not a valid partita IVA.', 'oxyddt-for-woocommerce' ),
			'tax_code_invalid' => __( 'The tax code is not a valid codice fiscale.', 'oxyddt-for-woocommerce' ),
			'email_invalid'    => __( 'The email address is not valid.', 'oxyddt-for-woocommerce' ),
			'street_missing'   => __( 'the street is missing', 'oxyddt-for-woocommerce' ),
			'city_missing'     => __( 'the town is missing', 'oxyddt-for-woocommerce' ),
			'postcode_invalid' => __( 'the CAP has to be five digits', 'oxyddt-for-woocommerce' ),
			'province_invalid' => __( 'the province has to be two letters', 'oxyddt-for-woocommerce' ),
			'country_invalid'  => __( 'the country code has to be two letters', 'oxyddt-for-woocommerce' ),
		);

		$described = array();

		foreach ( $codes as $code ) {
			if ( isset( $messages[ $code ] ) ) {
				$described[] = $messages[ $code ];

				continue;
			}

			$parts = explode( '.', $code, 2 );

			if ( 2 !== count( $parts ) || ! isset( $messages[ $parts[1] ] ) ) {
				continue;
			}

			$described[] = 'origin' === $parts[0]
				? sprintf(
					/* translators: %s: what is wrong, for example "the CAP has to be five digits". */
					__( 'Where the goods leave from: %s.', 'oxyddt-for-woocommerce' ),
					$messages[ $parts[1] ]
				)
				: sprintf(
					/* translators: %s: what is wrong, for example "the CAP has to be five digits". */
					__( 'Registered address: %s.', 'oxyddt-for-woocommerce' ),
					$messages[ $parts[1] ]
				);
		}

		return $described;
	}

	/**
	 * One text field, with its label.
	 *
	 * @param string $name        Field name, including any brackets.
	 * @param string $label       Already translated.
	 * @param string $value       Current value.
	 * @param string $class_name  CSS class of the input.
	 * @param string $description Optional help text, already translated.
	 * @return void
	 */
	private static function text_row( string $name, string $label, string $value, string $class_name = 'regular-text', string $description = '' ): void {
		$id = 'oxyddt-' . preg_replace( '/[^a-z0-9]+/', '-', strtolower( $name ) );

		echo '<tr><th scope="row"><label for="' . esc_attr( (string) $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" id="' . esc_attr( (string) $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="' . esc_attr( $class_name ) . '" />';

		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * The five fields of an address.
	 *
	 * @param string  $prefix  Field name prefix, for example "company[address]".
	 * @param Address $address Current values.
	 * @return void
	 */
	private static function address_rows( string $prefix, Address $address ): void {
		self::text_row( $prefix . '[street]', __( 'Street and number', 'oxyddt-for-woocommerce' ), $address->street );
		self::text_row( $prefix . '[postcode]', __( 'CAP', 'oxyddt-for-woocommerce' ), $address->postcode, 'small-text' );
		self::text_row( $prefix . '[city]', __( 'Town', 'oxyddt-for-woocommerce' ), $address->city );
		self::text_row( $prefix . '[province]', __( 'Province', 'oxyddt-for-woocommerce' ), $address->province, 'small-text', __( 'Two letters, for example MI.', 'oxyddt-for-woocommerce' ) );
		self::text_row( $prefix . '[country]', __( 'Country', 'oxyddt-for-woocommerce' ), $address->country, 'small-text', __( 'Two letters, for example IT.', 'oxyddt-for-woocommerce' ) );
	}

	/**
	 * The logo picker.
	 *
	 * @param int $logo_id Attachment ID, 0 when there is none.
	 * @return void
	 */
	private function logo_row( int $logo_id ): void {
		$image = $logo_id > 0 ? wp_get_attachment_image( $logo_id, 'medium', false, array( 'style' => 'max-width:200px;height:auto' ) ) : '';

		echo '<tr><th scope="row">' . esc_html__( 'Logo', 'oxyddt-for-woocommerce' ) . '</th><td>';
		echo '<div class="oxyddt-logo-preview">' . wp_kses_post( $image ) . '</div>';
		echo '<input type="hidden" name="company[logo_id]" id="oxyddt-logo-id" value="' . esc_attr( (string) $logo_id ) . '" />';
		echo '<button type="button" class="button" id="oxyddt-choose-logo">' . esc_html__( 'Choose the logo', 'oxyddt-for-woocommerce' ) . '</button> ';
		echo '<button type="button" class="button-link" id="oxyddt-remove-logo">' . esc_html__( 'Remove', 'oxyddt-for-woocommerce' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Printed at the top left of the document. A wide image reads better than a tall one.', 'oxyddt-for-woocommerce' ) . '</p>';
		echo '</td></tr>';
	}
}
