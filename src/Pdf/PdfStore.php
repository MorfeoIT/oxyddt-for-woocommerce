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
 * The archive, inside the uploads directory.
 *
 * What actually protects an archived delivery note, stated in the order the
 * protections are worth — because the first draft of this comment overstated it
 * and a bench on a real host proved it.
 *
 * **The filename.** Twenty random alphanumeric characters, which is where the
 * protection really lives: `ddt-14-….pdf` cannot be derived from
 * `ddt-13-….pdf`, and a directory of them cannot be walked.
 *
 * **The endpoint.** Nothing ever links to the file. Downloads go through
 * admin-post.php, which checks a capability and a nonce first. This is the only
 * one that is a decision rather than an obstacle — and it is the only one that
 * governs who may ask for a document by its number.
 *
 * **The directory guards, where they apply.** The `.htaccess`, `web.config` and
 * `index.php` written here stop Apache and IIS from listing or serving the
 * directory. On a host that puts **nginx in front** — which is most managed
 * hosting, and was the bench this was tested on — nginx serves static files
 * itself and never reads any of them. So on those hosts the guards do nothing
 * for the PDF, and anybody holding the URL can fetch it without being logged in.
 * A host that wants to close that adds a rule of its own; see
 * `docs/02-architettura.md`. A shop that would rather keep the archive somewhere
 * else entirely moves it with the `oxyddt_archive_directory` filter.
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

		// The year gets its own guards as well. Apache reads a parent .htaccess,
		// but a host with AllowOverride limited to the directory does not, and the
		// file costs nothing.
		$this->harden( $absolute );

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
		// Nothing may climb out of the archive, whatever it was handed.
		$clean = ltrim( str_replace( array( '..', "\0", '\\' ), '', $relative ), '/' );

		return trailingslashit( $this->base_directory() ) . $clean;
	}

	/**
	 * Where the archive lives.
	 *
	 * The uploads directory by default, because it is the one place a WordPress
	 * install is sure to be able to write. A host that can offer a directory
	 * outside the document root should: it is the only arrangement in which a
	 * static file cannot be served to somebody holding its URL, whatever web
	 * server is in front.
	 *
	 * @return string Absolute path, without a trailing slash.
	 */
	public function base_directory(): string {
		$uploads = wp_upload_dir();

		/**
		 * Filters where archived delivery notes are kept.
		 *
		 * Whatever comes back has to be writable by the site and had better not be
		 * inside a directory the shop serves. Moving it does not move the files
		 * already there: the path of each is recorded on its document.
		 *
		 * @since 0.1.0
		 *
		 * @param string $directory Absolute path, without a trailing slash.
		 */
		$directory = (string) apply_filters(
			'oxyddt_archive_directory',
			untrailingslashit( (string) $uploads['basedir'] )
		);

		return '' === trim( $directory ) ? untrailingslashit( (string) $uploads['basedir'] ) : untrailingslashit( $directory );
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
