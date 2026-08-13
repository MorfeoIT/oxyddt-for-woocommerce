<?php
/**
 * Why a document was not issued.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Issuing;

use RuntimeException;

/**
 * A refusal to issue or to cancel, with the reasons attached.
 *
 * Carries error codes as well as a message, because the screen that shows this
 * needs to say which field is missing, and a sentence cannot be asked that.
 */
final class IssueException extends RuntimeException {

	/**
	 * What was wrong.
	 *
	 * @var list<string>
	 */
	private array $codes;

	/**
	 * Build the refusal.
	 *
	 * @param string       $message A sentence for the log.
	 * @param list<string> $codes   The error codes.
	 */
	public function __construct( string $message, array $codes = array() ) {
		parent::__construct( $message );

		$this->codes = array_values( $codes );
	}

	/**
	 * What was wrong.
	 *
	 * @return list<string>
	 */
	public function codes(): array {
		return $this->codes;
	}
}
