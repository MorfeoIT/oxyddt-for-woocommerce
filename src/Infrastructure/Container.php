<?php
/**
 * The service container.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Infrastructure;

/*
 * Every exception below is developer-facing: the messages carry service
 * identifiers and class names written by us, never anything from a request, and
 * they describe mistakes only a plugin author can make or fix. They are not
 * escaped for two reasons. The values cannot contain markup. And the container
 * has to stay loadable without WordPress so that it can be tested without one,
 * which means esc_html() does not exist here.
 *
 * The rule is disabled inline rather than in phpcs.xml.dist because the plugin
 * directory's own check ignores this project's ruleset and reads only the code.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * A small registry of lazily built, shared services.
 *
 * Deliberately not a PSR-11 implementation with autowiring: OxyDDT needs a place
 * where PRO can swap one object for another — the numbering sequence, the PDF
 * renderer, the document repository — not a reflection engine. Every service is
 * a singleton within a request, because that is what a WordPress plugin's
 * services are.
 */
final class Container {

	/**
	 * Factories, by identifier.
	 *
	 * @var array<string, callable(Container): object>
	 */
	private array $factories = array();

	/**
	 * Services already built, by identifier.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Identifiers currently being resolved, to catch cycles.
	 *
	 * @var array<string, true>
	 */
	private array $resolving = array();

	/**
	 * Declare a service.
	 *
	 * Calling this twice with the same identifier replaces the factory, which is
	 * how an add-on substitutes its own implementation. Replacing a service that
	 * has already been built is refused rather than silently ignored: two halves
	 * of a request holding two different objects is a bug that surfaces far from
	 * its cause.
	 *
	 * @param string                      $id      Service identifier.
	 * @param callable(Container): object $factory Builds the service.
	 * @return void
	 *
	 * @throws ContainerException If the service has already been built.
	 */
	public function set( string $id, callable $factory ): void {
		if ( isset( $this->instances[ $id ] ) ) {
			throw new ContainerException(
				sprintf( 'The OxyDDT service "%s" has already been built and cannot be replaced.', $id )
			);
		}

		$this->factories[ $id ] = $factory;
	}

	/**
	 * Whether a service is declared.
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Every declared identifier, in declaration order.
	 *
	 * @return list<string>
	 */
	public function ids(): array {
		return array_keys( $this->factories );
	}

	/**
	 * Get a service, building it the first time it is asked for.
	 *
	 * @param string $id Service identifier.
	 * @return object
	 *
	 * @throws ContainerException If the service is unknown, depends on itself, or
	 *                            its factory does not return an object.
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new ContainerException(
				sprintf( 'The OxyDDT service "%s" is not registered.', $id )
			);
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new ContainerException(
				sprintf(
					'The OxyDDT service "%s" depends on itself, through: %s.',
					$id,
					implode( ' -> ', array_keys( $this->resolving ) )
				)
			);
		}

		$this->resolving[ $id ] = true;

		try {
			$service = ( $this->factories[ $id ] )( $this );
		} finally {
			unset( $this->resolving[ $id ] );
		}

		if ( ! is_object( $service ) ) {
			throw new ContainerException(
				sprintf( 'The factory for the OxyDDT service "%s" did not return an object.', $id )
			);
		}

		$this->instances[ $id ] = $service;

		return $service;
	}

	/**
	 * Get a service and insist on what it is.
	 *
	 * Since get() can only promise an object, every call site that wants a
	 * particular type has to prove it. Doing that here once keeps the proof in
	 * one place and lets static analysis check the whole object graph, including
	 * the moment an add-on replaces a service with something that does not fit.
	 *
	 * @template T of object
	 *
	 * @param string          $id         Service identifier.
	 * @param class-string<T> $class_name The class or interface it must satisfy.
	 * @return T
	 *
	 * @throws ContainerException If the service is not of the expected type.
	 */
	public function get_typed( string $id, string $class_name ): object {
		$service = $this->get( $id );

		if ( ! $service instanceof $class_name ) {
			throw new ContainerException(
				sprintf(
					'The OxyDDT service "%s" should be a %s but is a %s.',
					$id,
					$class_name,
					get_class( $service )
				)
			);
		}

		return $service;
	}
}
