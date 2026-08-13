<?php
/**
 * How the shop numbers its delivery notes.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Admin;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\NumberFormat;
use Oxysoft\OxyDDT\Domain\NumberingPolicy;
use Oxysoft\OxyDDT\Domain\SequenceRepositoryInterface;
use Oxysoft\OxyDDT\Infrastructure\ClockInterface;
use Oxysoft\OxyDDT\Infrastructure\Registrable;
use Oxysoft\OxyDDT\Security\Capabilities;
use Oxysoft\OxyDDT\Settings\Settings;

/**
 * A tab of its own, behind a capability of its own.
 *
 * Numbering is the one setting that is wrong on every document printed after
 * somebody changes it, and unwinding it means cancelling documents. A shop
 * manager runs the shipping day; this is not part of the shipping day.
 */
final class NumberingScreen implements Registrable {

	/**
	 * The tab identifier.
	 */
	public const TAB = 'numbering';

	/**
	 * The settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The counter.
	 *
	 * @var SequenceRepositoryInterface
	 */
	private SequenceRepositoryInterface $sequences;

	/**
	 * The page this tab belongs to.
	 *
	 * @var Screen
	 */
	private Screen $screen;

	/**
	 * The clock.
	 *
	 * @var ClockInterface
	 */
	private ClockInterface $clock;

	/**
	 * The register.
	 *
	 * @var AuditLog
	 */
	private AuditLog $log;

	/**
	 * Build the screen.
	 *
	 * @param Settings                    $settings  The settings.
	 * @param SequenceRepositoryInterface $sequences The counter.
	 * @param Screen                      $screen    The page this tab belongs to.
	 * @param ClockInterface              $clock     The clock.
	 * @param AuditLog                    $log       The register.
	 */
	public function __construct(
		Settings $settings,
		SequenceRepositoryInterface $sequences,
		Screen $screen,
		ClockInterface $clock,
		AuditLog $log
	) {
		$this->settings  = $settings;
		$this->sequences = $sequences;
		$this->screen    = $screen;
		$this->clock     = $clock;
		$this->log       = $log;
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
				__( 'Numbering', 'oxyddt-for-woocommerce' ),
				Capabilities::MANAGE_SEQUENCES,
				function (): void {
					$this->render();
				}
			)
		);

		add_action( 'admin_post_oxyddt_save_numbering', array( $this, 'handle_save' ) );
	}

	/**
	 * Draw the tab.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SEQUENCES ) ) {
			wp_die(
				esc_html__( 'You are not allowed to change how delivery notes are numbered.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		$policy = $this->settings->numbering();
		$year   = (int) $this->clock->local()->format( 'Y' );
		$next   = $this->sequences->peek( $policy->series, $policy->sequence_year( null, $year ), $policy->start );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'oxyddt_save_numbering' );
		echo '<input type="hidden" name="action" value="oxyddt_save_numbering" />';

		echo '<h2>' . esc_html__( 'Numbering', 'oxyddt-for-woocommerce' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'The next delivery note will be:', 'oxyddt-for-woocommerce' ) . '</strong> <code>'
			. esc_html( $policy->format->preview( $policy->series, $year, $next ) ) . '</code></p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="oxyddt-next">' . esc_html__( 'Next number', 'oxyddt-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="number" min="1" step="1" id="oxyddt-next" name="next_number" class="small-text" value="'
			. esc_attr( (string) $next ) . '" />';
		echo '<p class="description">'
			. esc_html__( 'Set this before you issue anything, if you are coming from another system and your last delivery note was number 347. Lowering it below a number already used will be refused when that number comes round again: the register will not hold the same number twice.', 'oxyddt-for-woocommerce' )
			. '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="oxyddt-pattern">' . esc_html__( 'How it is written', 'oxyddt-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" id="oxyddt-pattern" name="pattern" class="regular-text" value="'
			. esc_attr( $policy->format->pattern ) . '" />';
		echo '<p class="description">'
			. esc_html__( 'Use {number}, {year}, {year2} and {series}. For example {number}/{year} gives 125/2026, and DDT-{year}-{number} gives DDT-2026-125.', 'oxyddt-for-woocommerce' )
			. '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="oxyddt-padding">' . esc_html__( 'Leading zeros', 'oxyddt-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="number" min="0" max="12" step="1" id="oxyddt-padding" name="padding" class="small-text" value="'
			. esc_attr( (string) $policy->format->padding ) . '" />';
		echo '<p class="description">' . esc_html__( 'Five gives 00125. Zero gives 125.', 'oxyddt-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="oxyddt-series">' . esc_html__( 'Series', 'oxyddt-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" id="oxyddt-series" name="series" class="small-text" value="'
			. esc_attr( $policy->series ) . '" />';
		echo '<p class="description">'
			. esc_html__( 'Leave this empty unless you know you need it. One shop, one series. Several series at once is a PRO feature.', 'oxyddt-for-woocommerce' )
			. '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Each January', 'oxyddt-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="yearly_reset" value="1"'
			. checked( $policy->yearly_reset, true, false ) . ' /> '
			. esc_html__( 'Start counting again from one', 'oxyddt-for-woocommerce' ) . '</label>';
		echo '<p class="description">'
			. esc_html__( 'On by default, which is what most Italian shops do. A delivery note belongs to the year printed on it, not to the day it was issued: one dated the 31st of December and issued on the 2nd of January counts against the old year.', 'oxyddt-for-woocommerce' )
			. '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button();
		echo '</form>';
	}

	/**
	 * Save the numbering.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		check_admin_referer( 'oxyddt_save_numbering' );

		if ( ! current_user_can( Capabilities::MANAGE_SEQUENCES ) ) {
			wp_die(
				esc_html__( 'You are not allowed to change how delivery notes are numbered.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		$field = static function ( string $key ): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
			return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] )
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
				? sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) )
				: '';
		};

		$next = (int) $field( 'next_number' );

		$policy = new NumberingPolicy(
			$field( 'series' ),
			max( 1, $next ),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
			isset( $_POST['yearly_reset'] ),
			NumberFormat::from_array(
				array(
					'pattern' => $field( 'pattern' ),
					'padding' => $field( 'padding' ),
				)
			)
		);

		// Read back through from_array(), so what is stored is what a fresh read
		// would produce: the series uppercased and stripped, the pattern replaced
		// if it had nowhere to put the number.
		$policy = NumberingPolicy::from_array( $policy->to_array() );

		$this->settings->update_numbering( $policy );

		$year = (int) $this->clock->local()->format( 'Y' );

		// The stored policy only says where a *new* counter starts. A shop that
		// has already issued this year has a counter row, and changing the
		// setting alone would do nothing to it — which is exactly the surprise
		// this line avoids.
		$this->sequences->set_next( $policy->series, $policy->sequence_year( null, $year ), max( 1, $next ) );

		$this->log->record(
			AuditLog::SEQUENCE_CHANGED,
			sprintf( 'The numbering was changed: next number %d.', max( 1, $next ) ),
			$policy->to_array()
		);

		Notices::remember(
			'success',
			sprintf(
				/* translators: %s: the next number, as it will be printed. */
				__( 'Saved. The next delivery note will be %s.', 'oxyddt-for-woocommerce' ),
				$policy->format->preview( $policy->series, $year, max( 1, $next ) )
			)
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
}
