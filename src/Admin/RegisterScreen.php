<?php
/**
 * The register of delivery notes.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Admin;

use Oxysoft\OxyDDT\Domain\Causals;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentQuery;
use Oxysoft\OxyDDT\Domain\DocumentRepositoryInterface;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Infrastructure\Labels;
use Oxysoft\OxyDDT\Infrastructure\Registrable;
use Oxysoft\OxyDDT\Security\Capabilities;

/**
 * Everything that has been issued, and a way to find one of them again.
 *
 * A register is not a list of recent activity. It is what a shop opens when the
 * accountant asks for the delivery notes of March, or when a customer rings up
 * about "the one from last month with the pallets". So it filters by year and
 * month, by number range, by reason and carrier, and it searches the three
 * things somebody has in their hand: a number, a customer's name, an order.
 *
 * Reading it needs no nonce. It changes nothing, and a register that asked for
 * one would be a register nobody could link to.
 */
final class RegisterScreen implements Registrable {

	/**
	 * The tab identifier.
	 */
	public const TAB = 'register';

	/**
	 * The document store.
	 *
	 * @var DocumentRepositoryInterface
	 */
	private DocumentRepositoryInterface $documents;

	/**
	 * The page this tab belongs to.
	 *
	 * @var Screen
	 */
	private Screen $screen;

	/**
	 * Build the screen.
	 *
	 * @param DocumentRepositoryInterface $documents The document store.
	 * @param Screen                      $screen    The page this tab belongs to.
	 */
	public function __construct( DocumentRepositoryInterface $documents, Screen $screen ) {
		$this->documents = $documents;
		$this->screen    = $screen;
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
				__( 'All delivery notes', 'oxyddt-for-woocommerce' ),
				Capabilities::VIEW,
				function (): void {
					$this->render();
				}
			)
		);
	}

	/**
	 * Draw the tab.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die(
				esc_html__( 'You are not allowed to see delivery notes.', 'oxyddt-for-woocommerce' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading a list changes nothing, and DocumentQuery is the sanitiser: it is the only thing allowed to read raw input, and it fixes every value's shape.
		$query  = DocumentQuery::from_array( wp_unslash( $_GET ) );
		$result = $this->documents->search( $query );

		$this->render_filters( $query );

		if ( array() === $result['items'] ) {
			echo '<p>';
			echo $query->is_filtered()
				? esc_html__( 'Nothing matches those filters.', 'oxyddt-for-woocommerce' )
				: esc_html__( 'No delivery note has been prepared yet. They start from an order.', 'oxyddt-for-woocommerce' );
			echo '</p>';

			return;
		}

		$this->render_table( $result['items'] );
		$this->render_pagination( $query, $result['total'] );
	}

	/**
	 * The filters.
	 *
	 * A GET form, so that a filtered register is a link somebody can send to a
	 * colleague or keep in a bookmark.
	 *
	 * @param DocumentQuery $query What was asked for.
	 * @return void
	 */
	private function render_filters( DocumentQuery $query ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin:1em 0">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Screen::SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::TAB ) . '" />';

		echo '<p>';
		echo '<input type="search" name="search" value="' . esc_attr( $query->search ) . '" class="regular-text" placeholder="'
			. esc_attr__( 'Number, customer or order', 'oxyddt-for-woocommerce' ) . '" /> ';

		echo '<input type="number" name="year" min="1970" max="9999" step="1" class="small-text" placeholder="'
			. esc_attr__( 'Year', 'oxyddt-for-woocommerce' ) . '" value="'
			. esc_attr( null === $query->year ? '' : (string) $query->year ) . '" /> ';

		echo '<select name="month"><option value="">' . esc_html__( 'Any month', 'oxyddt-for-woocommerce' ) . '</option>';

		for ( $month = 1; $month <= 12; $month++ ) {
			$name = wp_date( 'F', (int) mktime( 12, 0, 0, $month, 1, 2026 ) );

			echo '<option value="' . esc_attr( (string) $month ) . '"' . selected( $query->month, $month, false ) . '>'
				. esc_html( false === $name ? (string) $month : $name ) . '</option>';
		}

		echo '</select> ';

		echo '<select name="status"><option value="">' . esc_html__( 'Any state', 'oxyddt-for-woocommerce' ) . '</option>';

		foreach ( DocumentStatus::cases() as $status ) {
			echo '<option value="' . esc_attr( $status->value ) . '"'
				. selected( null === $query->status ? '' : $query->status->value, $status->value, false ) . '>'
				. esc_html( Labels::document_status( $status ) ) . '</option>';
		}

		echo '</select> ';

		echo '<select name="causal"><option value="">' . esc_html__( 'Any reason', 'oxyddt-for-woocommerce' ) . '</option>';

		foreach ( Causals::defaults() as $causal ) {
			echo '<option value="' . esc_attr( $causal ) . '"' . selected( $query->causal, $causal, false ) . '>'
				. esc_html( Labels::causal( $causal ) ) . '</option>';
		}

		echo '</select>';
		echo '</p>';

		echo '<p>';
		echo '<input type="text" name="carrier" value="' . esc_attr( $query->carrier ) . '" class="regular-text" placeholder="'
			. esc_attr__( 'Carrier', 'oxyddt-for-woocommerce' ) . '" /> ';
		echo '<label>' . esc_html__( 'Numbers from', 'oxyddt-for-woocommerce' ) . ' ';
		echo '<input type="number" name="number_from" min="1" step="1" class="small-text" value="'
			. esc_attr( null === $query->number_from ? '' : (string) $query->number_from ) . '" /></label> ';
		echo '<label>' . esc_html__( 'to', 'oxyddt-for-woocommerce' ) . ' ';
		echo '<input type="number" name="number_to" min="1" step="1" class="small-text" value="'
			. esc_attr( null === $query->number_to ? '' : (string) $query->number_to ) . '" /></label> ';

		submit_button( __( 'Filter', 'oxyddt-for-woocommerce' ), 'secondary', '', false );

		if ( $query->is_filtered() ) {
			echo ' <a href="' . esc_url(
				add_query_arg(
					array(
						'page' => Screen::SLUG,
						'tab'  => self::TAB,
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'Clear', 'oxyddt-for-woocommerce' ) . '</a>';
		}

		echo '</p>';
		echo '</form>';
	}

	/**
	 * The table.
	 *
	 * @param list<Document> $documents What was found.
	 * @return void
	 */
	private function render_table( array $documents ): void {
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Number', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Customer', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Order', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Place of delivery', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Carrier', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'PDF', 'oxyddt-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $documents as $document ) {
			$orders = $document->all_order_ids();
			$first  = (int) ( $orders[0] ?? 0 );

			echo '<tr>';

			echo '<td><strong><a href="' . esc_url( EditScreen::url( $first, $document->id ) ) . '">';
			echo esc_html(
				'' === $document->number->formatted
					? __( '(draft)', 'oxyddt-for-woocommerce' )
					: $document->number->formatted
			);
			echo '</a></strong></td>';

			echo '<td>' . esc_html( Labels::date( $document->document_date ) ) . '</td>';
			echo '<td>' . esc_html( $document->parties->recipient->name ) . '</td>';

			echo '<td>';

			foreach ( $orders as $order_id ) {
				$link = get_edit_post_link( $order_id );
				$url  = is_string( $link ) && '' !== $link
					? $link
					: admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );

				echo '<a href="' . esc_url( $url ) . '">#' . esc_html( (string) $order_id ) . '</a> ';
			}

			echo '</td>';

			echo '<td>' . esc_html( $document->parties->delivery_address()->single_line() ) . '</td>';
			echo '<td>' . esc_html( Labels::causal( $document->causal ) ) . '</td>';
			echo '<td>' . esc_html( $document->transport->carrier_name ) . '</td>';
			echo '<td>' . esc_html( Labels::document_status( $document->status ) ) . '</td>';

			echo '<td>';

			if ( $document->status->is_numbered() ) {
				echo '<a href="' . esc_url( DocumentActions::pdf_url( $document ) ) . '">'
					. esc_html__( 'Download', 'oxyddt-for-woocommerce' ) . '</a>';
			} else {
				echo '—';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The pages.
	 *
	 * @param DocumentQuery $query What was asked for.
	 * @param int           $total How many matched in all.
	 * @return void
	 */
	private function render_pagination( DocumentQuery $query, int $total ): void {
		$pages = (int) ceil( $total / $query->per_page );

		echo '<p class="tablenav-pages" style="margin-top:1em">';
		echo '<span class="displaying-num">';
		echo esc_html(
			sprintf(
				/* translators: %s: how many delivery notes matched. */
				_n( '%s delivery note', '%s delivery notes', $total, 'oxyddt-for-woocommerce' ),
				number_format_i18n( $total )
			)
		);
		echo '</span> ';

		if ( $pages > 1 ) {
			$base = add_query_arg(
				array_merge(
					array(
						'page' => Screen::SLUG,
						'tab'  => self::TAB,
					),
					$query->to_query_args(),
					array( 'page_number' => '%#%' )
				),
				admin_url( 'admin.php' )
			);

			$links = paginate_links(
				array(
					'base'      => $base,
					'format'    => '',
					'current'   => $query->page,
					'total'     => $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			);

			if ( is_string( $links ) ) {
				echo wp_kses_post( $links );
			}
		}

		echo '</p>';
	}
}
