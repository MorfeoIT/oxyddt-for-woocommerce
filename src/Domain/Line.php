<?php
/**
 * One line of a delivery note.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * What is being sent, how much of it, and where it came from.
 *
 * Two identifiers matter beyond the description. `order_id` and `order_item_id`
 * are what tie the line back to the order it is fulfilling, and they are what
 * sprint 3 sums to answer the question the whole product exists for: how much
 * of this order has already gone out.
 *
 * The description is a copy, like everything else on an issued document. A
 * product renamed next year does not rename itself on a note printed today.
 */
final class Line {

	/**
	 * How close two quantities have to be to count as the same.
	 *
	 * Quantities are decimal because shops sell metres and kilograms, and a
	 * decimal that has been through a database and a form is never exactly the
	 * decimal that went in. Three places is what the column stores.
	 */
	public const EPSILON = 0.0005;

	/**
	 * Build a line.
	 *
	 * @param string     $name          Description, as it will be printed.
	 * @param float      $quantity      How much is going out.
	 * @param string     $sku           Product SKU, when it has one.
	 * @param string     $code          The shop's own code, when different from the SKU.
	 * @param string     $unit          Unit of measure.
	 * @param int        $order_id      Order this line fulfils, 0 when there is none.
	 * @param int        $order_item_id Order line this line fulfils.
	 * @param int        $product_id    Product, for later reporting.
	 * @param int        $variation_id  Variation, when the product has one.
	 * @param float|null $unit_price    Price, only when the shop asks for prices.
	 * @param int        $sort_order    Position on the document.
	 */
	public function __construct(
		public readonly string $name = '',
		public readonly float $quantity = 0.0,
		public readonly string $sku = '',
		public readonly string $code = '',
		public readonly string $unit = '',
		public readonly int $order_id = 0,
		public readonly int $order_item_id = 0,
		public readonly int $product_id = 0,
		public readonly int $variation_id = 0,
		public readonly ?float $unit_price = null,
		public readonly int $sort_order = 0
	) {
	}

	/**
	 * Build a line from stored or posted values.
	 *
	 * @param array<string, mixed> $data Raw values.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$string = static fn ( string $key ): string =>
			isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';

		$int = static fn ( string $key ): int =>
			isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ? max( 0, (int) $data[ $key ] ) : 0;

		$price = isset( $data['unit_price'] ) && is_numeric( $data['unit_price'] )
			? (float) $data['unit_price']
			: null;

		return new self(
			$string( 'name' ),
			isset( $data['quantity'] ) && is_numeric( $data['quantity'] ) ? (float) $data['quantity'] : 0.0,
			$string( 'sku' ),
			$string( 'code' ),
			$string( 'unit' ),
			$int( 'order_id' ),
			$int( 'order_item_id' ),
			$int( 'product_id' ),
			$int( 'variation_id' ),
			$price,
			$int( 'sort_order' )
		);
	}

	/**
	 * The line as a plain array, ready to be stored.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'name'          => $this->name,
			'quantity'      => $this->quantity,
			'sku'           => $this->sku,
			'code'          => $this->code,
			'unit'          => $this->unit,
			'order_id'      => $this->order_id,
			'order_item_id' => $this->order_item_id,
			'product_id'    => $this->product_id,
			'variation_id'  => $this->variation_id,
			'unit_price'    => $this->unit_price,
			'sort_order'    => $this->sort_order,
		);
	}

	/**
	 * The same line, for a different quantity.
	 *
	 * @param float $quantity The new quantity.
	 * @return self
	 */
	public function with_quantity( float $quantity ): self {
		return new self(
			$this->name,
			$quantity,
			$this->sku,
			$this->code,
			$this->unit,
			$this->order_id,
			$this->order_item_id,
			$this->product_id,
			$this->variation_id,
			$this->unit_price,
			$this->sort_order
		);
	}

	/**
	 * The same line, in a different place on the document.
	 *
	 * @param int $sort_order The new position.
	 * @return self
	 */
	public function with_sort_order( int $sort_order ): self {
		return new self(
			$this->name,
			$this->quantity,
			$this->sku,
			$this->code,
			$this->unit,
			$this->order_id,
			$this->order_item_id,
			$this->product_id,
			$this->variation_id,
			$this->unit_price,
			$sort_order
		);
	}

	/**
	 * Whether this line fulfils a particular line of a particular order.
	 *
	 * @param int $order_id      The order.
	 * @param int $order_item_id The line of that order.
	 * @return bool
	 */
	public function fulfils( int $order_id, int $order_item_id ): bool {
		return $this->order_id === $order_id && $this->order_item_id === $order_item_id;
	}

	/**
	 * Whether the line says anything.
	 *
	 * A line for nothing at all is not a line: it would print a description and
	 * a zero, and a driver would be asked to sign for it.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return '' === trim( $this->name ) || $this->quantity <= self::EPSILON;
	}
}
