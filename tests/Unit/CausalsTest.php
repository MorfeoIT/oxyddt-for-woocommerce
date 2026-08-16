<?php
/**
 * The reasons for transport a shop may add to ours.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use Oxysoft\OxyDDT\Domain\Causals;
use PHPUnit\Framework\TestCase;

/**
 * A reason is required on an Italian delivery note and it is stored as a code,
 * so what a shop is allowed to add — and what happens to a document when the
 * shop later changes its mind — is worth proving without a database.
 */
final class CausalsTest extends TestCase {

	/**
	 * The nine come first and carry no label: only the layer above can
	 * translate them, and a shop's own reason is never translated.
	 *
	 * @return void
	 */
	public function test_the_built_in_reasons_come_first_and_unlabelled(): void {
		$all = Causals::all();

		$this->assertSame( Causals::defaults(), array_keys( $all ) );
		$this->assertSame( '', $all[ Causals::SALE ] );
	}

	/**
	 * A shop's own reason keeps the words somebody typed. Those words are what
	 * gets printed, so nothing here rewrites them.
	 *
	 * @return void
	 */
	public function test_a_shop_adds_its_own(): void {
		$all = Causals::all( array( 'conto_deposito' => 'Conto deposito' ) );

		$this->assertSame( 'Conto deposito', $all['conto_deposito'] );
		$this->assertCount( count( Causals::defaults() ) + 1, $all );
	}

	/**
	 * A shop cannot redefine one of ours by reusing its code: the register
	 * filters by code, and two meanings behind one code is a filter that
	 * silently returns the wrong documents.
	 *
	 * @return void
	 */
	public function test_a_built_in_code_cannot_be_taken_over(): void {
		$all = Causals::all( array( Causals::SALE => 'Vendita nostra' ) );

		$this->assertSame( '', $all[ Causals::SALE ] );
	}

	/**
	 * A code is worked out from what was typed, and it has to survive being put
	 * in a query string and a filter.
	 *
	 * @return void
	 */
	public function test_a_code_is_kept_to_what_is_safe(): void {
		$clean = Causals::clean_custom( array( ' Conto Deposito! ' => 'Conto deposito' ) );

		$this->assertSame( array( 'conto_deposito' => 'Conto deposito' ), $clean );
	}

	/**
	 * A reason with no words is a code somebody has to decode. Printing the
	 * code is worse than nothing, but it is better than an empty cell where the
	 * law wants a reason.
	 *
	 * @return void
	 */
	public function test_a_reason_without_words_falls_back_to_its_code(): void {
		$clean = Causals::clean_custom( array( 'conto_deposito' => '   ' ) );

		$this->assertSame( array( 'conto_deposito' => 'conto_deposito' ), $clean );
	}

	/**
	 * The same reason twice is one reason, and the first spelling wins.
	 *
	 * @return void
	 */
	public function test_the_same_reason_twice_is_one(): void {
		$clean = Causals::clean_custom(
			array(
				'conto_deposito' => 'Conto deposito',
				'Conto deposito' => 'Deposito',
			)
		);

		$this->assertSame( array( 'conto_deposito' => 'Conto deposito' ), $clean );
	}
}
