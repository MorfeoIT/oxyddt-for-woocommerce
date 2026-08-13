<?php
/**
 * Loading a template a theme may have replaced.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Infrastructure;

/**
 * The plugin's own templates, and where a shop may override them.
 *
 * A theme that puts a file at `oxyddt/pdf/document.php` replaces the plugin's,
 * which is how WooCommerce has always let shops change what a document looks
 * like without touching a plugin they will later update.
 *
 * Templates are given their data explicitly. They never see the container, and
 * nothing they are handed is a live object they could save.
 */
final class Templates {

	/**
	 * The directory a theme puts its overrides in.
	 */
	public const THEME_DIRECTORY = 'oxyddt';

	/**
	 * Render a template and return what it printed.
	 *
	 * @param string               $template Path within templates/, for example "pdf/document.php".
	 * @param array<string, mixed> $data    Variables the template may use, by name.
	 * @return string
	 */
	public function render( string $template, array $data = array() ): string {
		$path = $this->locate( $template );

		if ( '' === $path ) {
			return '';
		}

		ob_start();

		// The template reads $data and nothing else. Extracting into the local
		// scope would let a stray key overwrite $path halfway through rendering.
		$this->include_template( $path, $data );

		return (string) ob_get_clean();
	}

	/**
	 * Where a template lives.
	 *
	 * @param string $template Path within templates/.
	 * @return string Absolute path, or an empty string when there is none.
	 */
	public function locate( string $template ): string {
		$template = ltrim( str_replace( array( '..', "\0" ), '', $template ), '/' );

		$override = locate_template( array( self::THEME_DIRECTORY . '/' . $template ) );

		if ( '' !== $override && is_readable( $override ) ) {
			return $override;
		}

		$own = \Oxysoft\OxyDDT\plugin_dir() . 'templates/' . $template;

		/**
		 * Filters where a template is loaded from.
		 *
		 * @since 0.1.0
		 *
		 * @param string $own      The path the plugin resolved.
		 * @param string $template The template that was asked for.
		 */
		$path = (string) apply_filters( 'oxyddt_template_path', $own, $template );

		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Include a template with its data, and nothing else in scope.
	 *
	 * @param string               $path The template.
	 * @param array<string, mixed> $data Its data.
	 * @return void
	 */
	private function include_template( string $path, array $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $data is what the included template reads; the include is its only user.
		include $path;
	}
}
