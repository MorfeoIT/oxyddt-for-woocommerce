<?php
/**
 * Where issued PDFs are kept.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Pdf;

use Oxysoft\OxyDDT\Domain\Document;

/*
 * The exceptions in this file name paths and library errors, and are read by
 * whoever is looking at a log or a refusal — never printed to a shop's
 * customers. Escaping them would put HTML entities in a stack trace.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
/**
 * The archive, inside the uploads directory, and not reachable from outside.
 *
 * Three things stand between a delivery note and the open web, because one is
 * not enough:
 *
 * The directory carries an `.htaccess`, a `web.config` and an `index.php`, so a
 * server that reads any of the three refuses to list or serve it.
 *
 * Every filename carries twenty random characters, so a host that ignores all
 * three still cannot be walked: `ddt-14-....pdf` is not guessable from
 * `ddt-13-....pdf`.
 *
 * And nothing ever links to the file. Downloads go through an endpoint that
 * checks a capability and a nonce first, which is the only defence that is
 * actually a decision rather than an obstacle.
 */
final class PdfStore {

	/**
	 * The directory inside uploads.
	 */
	public const DIRECTORY = 'oxyddt';

	/**
	 * Write a document's PDF and say where it went.
	 *
	 * @param Document $document The document.
	 * @param string   $bytes    The PDF.
	 * @return array{path: string, hash: string} Path relative to uploads, and the file's hash.
	 *
	 * @throws PdfException If the file could not be written.
	 */
	public function store( Document $document, string $bytes ): array {
		if ( '' === $bytes ) {
			throw new PdfException( 'Refusing to archive an empty PDF.' );
		}

		$year      = null === $document->document_date ? 'undated' : substr( $document->document_date, 0, 4 );
		$directory = self::DIRECTORY . '/' . $year;

		$this->harden( $this->absolute( self::DIRECTORY ) );

		$absolute = $this->absolute( $directory );

		if ( ! wp_mkdir_p( $absolute ) ) {
			throw new PdfException( sprintf( 'The archive directory could not be created: %s', $absolute ) );
		}

		$relative = $directory . '/' . $this->filename( $document );

		if ( ! $this->filesystem()->put_contents( $this->absolute( $relative ), $bytes, FS_CHMOD_FILE ) ) {
			throw new PdfException( sprintf( 'The PDF could not be written to %s', $relative ) );
		}

		return array(
			'path' => $relative,
			'hash' => hash( 'sha256', $bytes ),
		);
	}

	/**
	 * Read an archived PDF.
	 *
	 * @param string $relative Path relative to uploads.
	 * @return string
	 *
	 * @throws PdfException If the file is missing or unreadable.
	 */
	public function read( string $relative ): string {
		$absolute = $this->absolute( $relative );

		if ( ! $this->exists( $relative ) ) {
			throw new PdfException( sprintf( 'The archived PDF is not there: %s', $relative ) );
		}

		$bytes = $this->filesystem()->get_contents( $absolute );

		if ( false === $bytes || '' === $bytes ) {
			throw new PdfException( sprintf( 'The archived PDF could not be read: %s', $relative ) );
		}

		return $bytes;
	}

	/**
	 * Whether an archived PDF is still there.
	 *
	 * @param string $relative Path relative to uploads.
	 * @return bool
	 */
	public function exists( string $relative ): bool {
		return '' !== $relative && is_readable( $this->absolute( $relative ) );
	}

	/**
	 * The absolute path of something inside the uploads directory.
	 *
	 * @param string $relative Path relative to uploads.
	 * @return string
	 */
	public function absolute( string $relative ): string {
		$uploads = wp_upload_dir();

		// Nothing may climb out of uploads, whatever it was handed.
		$clean = ltrim( str_replace( array( '..', "\0", '\\' ), '', $relative ), '/' );

		return trailingslashit( (string) $uploads['basedir'] ) . $clean;
	}

	/**
	 * The name a document's file gets.
	 *
	 * Readable enough to recognise in a directory listing, random enough not to
	 * be guessable from its neighbour.
	 *
	 * @param Document $document The document.
	 * @return string
	 */
	private function filename( Document $document ): string {
		$number = '' === $document->number->formatted
			? 'draft-' . $document->id
			: $document->number->formatted;

		$readable = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', $number ) );

		return 'ddt-' . trim( $readable, '-' ) . '-' . wp_generate_password( 20, false, false ) . '.pdf';
	}

	/**
	 * Make the archive directory refuse to be browsed.
	 *
	 * @param string $absolute The directory.
	 * @return void
	 */
	private function harden( string $absolute ): void {
		if ( ! wp_mkdir_p( $absolute ) ) {
			return;
		}

		$files = array(
			'.htaccess'  => "Order Allow,Deny\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n",
			'web.config' => "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Nothing to see here.\n",
		);

		$filesystem = $this->filesystem();

		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $absolute ) . $name;

			if ( file_exists( $path ) ) {
				continue;
			}

			$filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
		}
	}

	/**
	 * WordPress's own way of touching the disk.
	 *
	 * @return \WP_Filesystem_Base
	 */
	private function filesystem(): \WP_Filesystem_Base {
		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			WP_Filesystem();
		}

		return $wp_filesystem;
	}
}
