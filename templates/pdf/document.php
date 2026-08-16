<?php
/**
 * The delivery note, as it is printed.
 *
 * Override it by copying this file to `oxyddt/pdf/document.php` in your theme.
 * It is given three things and sees nothing else:
 *
 * @var array{document: \Oxysoft\OxyDDT\Domain\Document, logo: string, show_prices: bool} $data
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Infrastructure\Labels;

defined( 'ABSPATH' ) || exit;

/*
 * The variables below look like globals to a static analyser and are not: this
 * file is included from inside a method, so they live and die there. Prefixing
 * them would make a template that shops copy into their theme harder to read for
 * no gain, which is the trade WooCommerce's own templates make too.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

/**
 * The document being printed.
 *
 * @var \Oxysoft\OxyDDT\Domain\Document $document
 */
$document = $data['document'];

/**
 * The sender's logo, as a data URI.
 *
 * @var string $logo
 */
$logo = (string) ( $data['logo'] ?? '' );

/**
 * Whether the amounts are printed.
 *
 * A delivery note is not an invoice and most shops hand one to a courier, so
 * this is off unless somebody turned it on. The figures are net of tax and
 * after any discount: they are what the order charged, not a fresh price list.
 *
 * @var bool $show_prices
 */
$show_prices = ! empty( $data['show_prices'] );

// Only when there is something to print. A column of empty cells on every line
// is worse than no column: it reads as a shop that forgot to fill it in.
foreach ( $document->lines as $line ) {
	if ( null === $line->unit_price ) {
		$show_prices = false;
		break;
	}
}

$total = 0.0;

if ( $show_prices ) {
	foreach ( $document->lines as $line ) {
		$total += $line->quantity * (float) $line->unit_price;
	}
}

$sender      = $document->parties->sender;
$recipient   = $document->parties->recipient;
$destination = $document->parties->delivery_address();
$transport   = $document->transport;
$cancelled   = DocumentStatus::Cancelled === $document->status;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
	<meta charset="utf-8" />
	<title><?php echo esc_html( $document->number->formatted ); ?></title>
	<style>
		@page { margin: 14mm 12mm; }
		body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #111; }
		h1 { font-size: 14pt; margin: 0 0 2mm; }
		table { width: 100%; border-collapse: collapse; }
		td, th { vertical-align: top; }
		.head td { width: 50%; padding: 0; }
		.logo { max-height: 22mm; }
		.box { border: 0.4pt solid #444; padding: 2mm; }
		.box + .box { margin-top: 2mm; }
		.label { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.4pt; color: #555; }
		.number { font-size: 12pt; font-weight: bold; }
		.lines { margin-top: 3mm; }
		.lines th { background: #f0f0f0; border-bottom: 0.6pt solid #444; padding: 1.5mm; text-align: left; font-size: 8pt; }
		.lines td { border-bottom: 0.3pt solid #bbb; padding: 1.5mm; }
		.right { text-align: right; }
		.small { font-size: 7.5pt; color: #555; }
		.foot { margin-top: 4mm; }
		.sign { height: 16mm; border: 0.4pt solid #444; padding: 1.5mm; }
		.void { color: #a00; border: 1pt solid #a00; padding: 2mm; margin-bottom: 3mm; font-weight: bold; }
	</style>
</head>
<body>

<?php if ( $cancelled ) : ?>
	<div class="void">
		<?php
		printf(
			/* translators: %s: the reason it was cancelled. */
			esc_html__( 'CANCELLED — %s', 'oxyddt-for-woocommerce' ),
			esc_html( $document->lifecycle->cancel_reason )
		);
		?>
	</div>
<?php endif; ?>

<table class="head">
	<tr>
		<td>
			<?php if ( '' !== $logo ) : ?>
				<img src="<?php echo esc_attr( $logo ); ?>" class="logo" alt="" /><br />
			<?php endif; ?>
			<strong><?php echo esc_html( $sender->name ); ?></strong><br />
			<?php echo esc_html( $sender->address->single_line() ); ?><br />
			<?php if ( '' !== $sender->vat_number ) : ?>
				<?php echo esc_html__( 'VAT no.', 'oxyddt-for-woocommerce' ) . ' ' . esc_html( $sender->vat_number ); ?><br />
			<?php endif; ?>
			<?php if ( '' !== $sender->tax_code && $sender->tax_code !== $sender->vat_number ) : ?>
				<?php echo esc_html__( 'Tax code', 'oxyddt-for-woocommerce' ) . ' ' . esc_html( $sender->tax_code ); ?><br />
			<?php endif; ?>
			<?php if ( '' !== $sender->phone ) : ?>
				<span class="small"><?php echo esc_html( $sender->phone ); ?></span>
			<?php endif; ?>
		</td>
		<td>
			<div class="box">
				<h1><?php echo esc_html__( 'Delivery note', 'oxyddt-for-woocommerce' ); ?></h1>
				<span class="number"><?php echo esc_html( $document->number->formatted ); ?></span><br />
				<span class="label"><?php echo esc_html__( 'Date', 'oxyddt-for-woocommerce' ); ?></span>
				<?php echo esc_html( Labels::date( $document->document_date ) ); ?><br />
				<span class="label"><?php echo esc_html__( 'Reason for transport', 'oxyddt-for-woocommerce' ); ?></span>
				<?php echo esc_html( Labels::causal( $document->causal ) ); ?>
				<?php if ( array() !== $document->all_order_ids() ) : ?>
					<br /><span class="label"><?php echo esc_html__( 'Order', 'oxyddt-for-woocommerce' ); ?></span>
					<?php echo esc_html( implode( ', ', array_map( 'strval', $document->all_order_ids() ) ) ); ?>
				<?php endif; ?>
			</div>
		</td>
	</tr>
</table>

<table class="head" style="margin-top:3mm">
	<tr>
		<td style="padding-right:2mm">
			<div class="box">
				<span class="label"><?php echo esc_html__( 'Recipient', 'oxyddt-for-woocommerce' ); ?></span><br />
				<strong><?php echo esc_html( $recipient->name ); ?></strong><br />
				<?php echo esc_html( $recipient->address->single_line() ); ?>
				<?php if ( '' !== $recipient->vat_number ) : ?>
					<br /><?php echo esc_html__( 'VAT no.', 'oxyddt-for-woocommerce' ) . ' ' . esc_html( $recipient->vat_number ); ?>
				<?php endif; ?>
				<?php if ( '' !== $recipient->tax_code ) : ?>
					<br /><?php echo esc_html__( 'Tax code', 'oxyddt-for-woocommerce' ) . ' ' . esc_html( $recipient->tax_code ); ?>
				<?php endif; ?>
			</div>
		</td>
		<td>
			<div class="box">
				<span class="label"><?php echo esc_html__( 'Place of delivery', 'oxyddt-for-woocommerce' ); ?></span><br />
				<?php echo esc_html( $destination->single_line() ); ?>
			</div>
		</td>
	</tr>
</table>

<table class="lines">
	<thead>
		<tr>
			<th style="width:22mm"><?php echo esc_html__( 'Code', 'oxyddt-for-woocommerce' ); ?></th>
			<th><?php echo esc_html__( 'Description', 'oxyddt-for-woocommerce' ); ?></th>
			<th class="right" style="width:20mm"><?php echo esc_html__( 'Quantity', 'oxyddt-for-woocommerce' ); ?></th>
			<th style="width:14mm"><?php echo esc_html__( 'Unit', 'oxyddt-for-woocommerce' ); ?></th>
			<?php if ( $show_prices ) : ?>
				<th class="right" style="width:22mm"><?php echo esc_html__( 'Unit price', 'oxyddt-for-woocommerce' ); ?></th>
				<th class="right" style="width:24mm"><?php echo esc_html__( 'Amount', 'oxyddt-for-woocommerce' ); ?></th>
			<?php endif; ?>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $document->lines as $line ) : ?>
			<tr>
				<td><?php echo esc_html( '' !== $line->code ? $line->code : $line->sku ); ?></td>
				<td><?php echo esc_html( $line->name ); ?></td>
				<td class="right"><?php echo esc_html( Labels::quantity( $line->quantity ) ); ?></td>
				<td><?php echo esc_html( $line->unit ); ?></td>
				<?php if ( $show_prices ) : ?>
					<td class="right"><?php echo esc_html( Labels::money( (float) $line->unit_price ) ); ?></td>
					<td class="right"><?php echo esc_html( Labels::money( $line->quantity * (float) $line->unit_price ) ); ?></td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
	</tbody>
	<?php if ( $show_prices ) : ?>
		<tfoot>
			<tr>
				<td colspan="5" class="right">
					<?php echo esc_html__( 'Total, net of tax', 'oxyddt-for-woocommerce' ); ?>
				</td>
				<td class="right"><strong><?php echo esc_html( Labels::money( $total ) ); ?></strong></td>
			</tr>
		</tfoot>
	<?php endif; ?>
</table>

<?php if ( $show_prices ) : ?>
	<p class="small">
		<?php echo esc_html__( 'Amounts are net of tax and are stated for the goods listed above. This document is not an invoice.', 'oxyddt-for-woocommerce' ); ?>
	</p>
<?php endif; ?>

<table class="foot">
	<tr>
		<td style="width:50%;padding-right:2mm">
			<div class="box">
				<span class="label"><?php echo esc_html__( 'Transport', 'oxyddt-for-woocommerce' ); ?></span><br />
				<?php if ( '' !== $transport->by ) : ?>
					<?php echo esc_html__( 'In the care of:', 'oxyddt-for-woocommerce' ); ?>
					<?php echo esc_html( Labels::carrier( $transport->by ) ); ?><br />
				<?php endif; ?>
				<?php if ( '' !== $transport->carrier_name ) : ?>
					<?php echo esc_html__( 'Carrier:', 'oxyddt-for-woocommerce' ); ?>
					<?php echo esc_html( $transport->carrier_name ); ?><br />
				<?php endif; ?>
				<?php if ( '' !== $transport->carriage ) : ?>
					<?php echo esc_html__( 'Carriage:', 'oxyddt-for-woocommerce' ); ?>
					<?php echo esc_html( Labels::carriage( $transport->carriage ) ); ?><br />
				<?php endif; ?>
				<?php if ( '' !== $transport->goods_appearance ) : ?>
					<?php echo esc_html__( 'Appearance of the goods:', 'oxyddt-for-woocommerce' ); ?>
					<?php echo esc_html( $transport->goods_appearance ); ?><br />
				<?php endif; ?>
				<?php if ( $transport->packages > 0 ) : ?>
					<?php echo esc_html__( 'Packages:', 'oxyddt-for-woocommerce' ); ?>
					<?php echo esc_html( (string) $transport->packages ); ?><br />
				<?php endif; ?>
				<?php if ( null !== $transport->weight_gross ) : ?>
					<?php echo esc_html__( 'Gross weight:', 'oxyddt-for-woocommerce' ); ?>
					<?php echo esc_html( Labels::quantity( $transport->weight_gross ) ); ?><br />
				<?php endif; ?>
				<?php if ( null !== $transport->weight_net ) : ?>
					<?php echo esc_html__( 'Net weight:', 'oxyddt-for-woocommerce' ); ?>
					<?php echo esc_html( Labels::quantity( $transport->weight_net ) ); ?>
				<?php endif; ?>
			</div>
		</td>
		<td style="width:50%">
			<div class="box">
				<span class="label"><?php echo esc_html__( 'Notes', 'oxyddt-for-woocommerce' ); ?></span><br />
				<?php echo nl2br( esc_html( $document->notes ) ); ?>
			</div>
		</td>
	</tr>
</table>

<table class="foot">
	<tr>
		<td style="width:33%;padding-right:2mm">
			<div class="sign">
				<span class="label"><?php echo esc_html__( 'Driver', 'oxyddt-for-woocommerce' ); ?></span>
			</div>
		</td>
		<td style="width:34%;padding-right:2mm">
			<div class="sign">
				<span class="label"><?php echo esc_html__( 'Carrier', 'oxyddt-for-woocommerce' ); ?></span>
			</div>
		</td>
		<td style="width:33%">
			<div class="sign">
				<span class="label"><?php echo esc_html__( 'Received by', 'oxyddt-for-woocommerce' ); ?></span>
			</div>
		</td>
	</tr>
</table>

</body>
</html>
