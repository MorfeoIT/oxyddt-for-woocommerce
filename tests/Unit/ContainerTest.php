<?php
/**
 * The wiring.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Tests\Unit;

use ArrayObject;
use Oxysoft\OxyDDT\Infrastructure\Container;
use Oxysoft\OxyDDT\Infrastructure\ContainerException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The service container.
 */
final class ContainerTest extends TestCase {

	/**
	 * A service is built once and shared.
	 *
	 * @return void
	 */
	public function test_a_service_is_built_once(): void {
		$container = new Container();
		$built     = 0;

		$container->set(
			'thing',
			static function () use ( &$built ): stdClass {
				++$built;

				return new stdClass();
			}
		);

		$first = $container->get( 'thing' );

		$this->assertSame( $first, $container->get( 'thing' ) );
		$this->assertSame( 1, $built );
	}

	/**
	 * Declaring costs nothing until somebody asks.
	 *
	 * @return void
	 */
	public function test_declaring_a_service_does_not_build_it(): void {
		$container = new Container();
		$built     = false;

		$container->set(
			'thing',
			static function () use ( &$built ): stdClass {
				$built = true;

				return new stdClass();
			}
		);

		$this->assertTrue( $container->has( 'thing' ) );
		$this->assertFalse( $built );
	}

	/**
	 * An add-on replaces a service by declaring it again.
	 *
	 * @return void
	 */
	public function test_a_service_can_be_replaced_before_it_is_built(): void {
		$container = new Container();

		$container->set( 'thing', static fn (): stdClass => new stdClass() );
		$container->set( 'thing', static fn (): ArrayObject => new ArrayObject() );

		$this->assertInstanceOf( ArrayObject::class, $container->get( 'thing' ) );
	}

	/**
	 * But not afterwards: two halves of a request holding two different objects
	 * is a bug that surfaces far from its cause.
	 *
	 * @return void
	 */
	public function test_a_service_cannot_be_replaced_once_built(): void {
		$container = new Container();

		$container->set( 'thing', static fn (): stdClass => new stdClass() );
		$container->get( 'thing' );

		$this->expectException( ContainerException::class );

		$container->set( 'thing', static fn (): ArrayObject => new ArrayObject() );
	}

	/**
	 * An unknown identifier is a mistake, not an empty result.
	 *
	 * @return void
	 */
	public function test_an_unknown_service_is_refused(): void {
		$this->expectException( ContainerException::class );

		( new Container() )->get( 'nothing' );
	}

	/**
	 * A cycle is reported with the path that produced it, rather than filling
	 * the stack.
	 *
	 * @return void
	 */
	public function test_a_cycle_is_caught(): void {
		$container = new Container();

		$container->set( 'a', static fn ( Container $c ): object => $c->get( 'b' ) );
		$container->set( 'b', static fn ( Container $c ): object => $c->get( 'a' ) );

		$this->expectException( ContainerException::class );
		$this->expectExceptionMessage( 'depends on itself' );

		$container->get( 'a' );
	}

	/**
	 * A call site that wants a type gets one, or an explanation.
	 *
	 * @return void
	 */
	public function test_a_service_of_the_wrong_type_is_refused(): void {
		$container = new Container();

		$container->set( 'thing', static fn (): stdClass => new stdClass() );

		$this->assertInstanceOf( stdClass::class, $container->get_typed( 'thing', stdClass::class ) );

		$this->expectException( ContainerException::class );

		$container->get_typed( 'thing', ArrayObject::class );
	}

	/**
	 * Identifiers come back in declaration order, because that is the order
	 * services register their hooks in.
	 *
	 * @return void
	 */
	public function test_identifiers_keep_their_order(): void {
		$container = new Container();

		$container->set( 'first', static fn (): stdClass => new stdClass() );
		$container->set( 'second', static fn (): stdClass => new stdClass() );
		$container->set( 'third', static fn (): stdClass => new stdClass() );

		$this->assertSame( array( 'first', 'second', 'third' ), $container->ids() );
	}
}
