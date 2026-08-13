<?php
/**
 * Issuing and cancelling.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Issuing;

use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\Document;
use Oxysoft\OxyDDT\Domain\DocumentNumber;
use Oxysoft\OxyDDT\Domain\DocumentRepositoryInterface;
use Oxysoft\OxyDDT\Domain\DocumentStatus;
use Oxysoft\OxyDDT\Domain\SequenceRepositoryInterface;
use Oxysoft\OxyDDT\Infrastructure\ClockInterface;
use Oxysoft\OxyDDT\Persistence\StorageException;
use Oxysoft\OxyDDT\Settings\Settings;

/*
 * The exceptions here are read by whoever is looking at a refusal or a log, and
 * they carry error codes and a database message written by this plugin — never
 * anything from a request. The screen that shows them to a person turns the
 * codes into sentences and escapes those.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * The moment a draft becomes a document.
 *
 * Everything irreversible in the plugin happens in this class, which is why it
 * is small and does its steps in an order that is worth stating: **validate,
 * take a number, write it down.** A number taken for a document that then fails
 * to save leaves a hole in the register, and a hole is explainable. Writing a
 * document and numbering it afterwards would leave a document with no number,
 * which is not.
 *
 * If the write is refused because that number already exists — which the unique
 * index guarantees it will be, rather than silently accepting a duplicate — the
 * whole thing is tried again with the next number. That is the second defence
 * behind the row lock in the counter, and the two together are why "never two
 * documents numbered 125" is a fact rather than an intention.
 */
final class Issuer {

	/**
	 * How many times a refused number is retried before giving up.
	 *
	 * Each retry means somebody else took the number between our allocation and
	 * our insert, which is possible but not repeatable: five is far past the
	 * point where a further attempt would tell us anything new.
	 */
	private const ATTEMPTS = 5;

	/**
	 * The document store.
	 *
	 * @var DocumentRepositoryInterface
	 */
	private DocumentRepositoryInterface $documents;

	/**
	 * The counter.
	 *
	 * @var SequenceRepositoryInterface
	 */
	private SequenceRepositoryInterface $sequences;

	/**
	 * The settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The clock.
	 *
	 * @var ClockInterface
	 */
	private ClockInterface $clock;

	/**
	 * The register.
	 *
	 * @var AuditLog
	 */
	private AuditLog $log;

	/**
	 * Build the issuer.
	 *
	 * @param DocumentRepositoryInterface $documents The document store.
	 * @param SequenceRepositoryInterface $sequences The counter.
	 * @param Settings                    $settings  The settings.
	 * @param ClockInterface              $clock     The clock.
	 * @param AuditLog                    $log       The register.
	 */
	public function __construct(
		DocumentRepositoryInterface $documents,
		SequenceRepositoryInterface $sequences,
		Settings $settings,
		ClockInterface $clock,
		AuditLog $log
	) {
		$this->documents = $documents;
		$this->sequences = $sequences;
		$this->settings  = $settings;
		$this->clock     = $clock;
		$this->log       = $log;
	}

	/**
	 * Issue a draft.
	 *
	 * @param Document $draft The draft.
	 * @return Document The issued document, with its number.
	 *
	 * @throws IssueException If the draft is not ready, or a number could not be taken.
	 */
	public function issue( Document $draft ): Document {
		if ( ! $draft->is_editable() ) {
			throw new IssueException(
				'This delivery note has already been issued.',
				array( 'already_issued' )
			);
		}

		$errors = $draft->errors();

		if ( array() !== $errors ) {
			throw new IssueException(
				'This delivery note is not ready to be issued.',
				$errors
			);
		}

		// Saved first, so that a number is never taken for something that does
		// not yet exist as a row. If this fails, nothing has been spent.
		$saved = $draft->id > 0 ? $draft : $this->documents->save( $draft );

		$policy    = $this->settings->numbering();
		$now       = $this->clock->now()->format( 'Y-m-d H:i:s' );
		$this_year = (int) $this->clock->local()->format( 'Y' );

		$sequence_year = $policy->sequence_year( $saved->document_date, $this_year );
		$printed_year  = $policy->printed_year( $saved->document_date, $this_year );

		$last_error = null;

		for ( $attempt = 0; $attempt < self::ATTEMPTS; $attempt++ ) {
			$sequence = $this->sequences->allocate( $policy->series, $sequence_year, $policy->start );

			$issued = new Document(
				$saved->id,
				DocumentStatus::Issued,
				DocumentNumber::assigned(
					$policy->format->format( $policy->series, $printed_year, $sequence ),
					$policy->series,
					$printed_year,
					$sequence
				),
				$saved->document_date,
				$saved->parties,
				$saved->transport,
				$saved->causal,
				$saved->lines,
				$saved->order_ids,
				$saved->notes,
				$saved->customer_id,
				$saved->lifecycle->issued( $now, get_current_user_id() )
			);

			try {
				$written = $this->documents->save( $issued );
			} catch ( StorageException $e ) {
				// The unique index refused it: somebody took that number between our
				// allocation and our write. Take the next one and try again — which
				// is the correct outcome, and the one the specification asks for:
				// 125 and 126, never 125 twice.
				$last_error = $e;

				continue;
			}

			$this->log->record(
				AuditLog::DOCUMENT_ISSUED,
				sprintf( 'Delivery note %s was issued.', $written->number->formatted ),
				array(
					'number'   => $written->number->formatted,
					'series'   => $policy->series,
					'year'     => $printed_year,
					'sequence' => $sequence,
					'orders'   => $written->all_order_ids(),
					'attempt'  => $attempt + 1,
				),
				$written->id
			);

			/**
			 * Fires when a delivery note has been issued.
			 *
			 * The number is spent and the document cannot change: this is the
			 * moment to print it, email it, or tell another system about it.
			 *
			 * @since 0.1.0
			 *
			 * @param Document $written The issued document.
			 */
			do_action( 'oxyddt_issued', $written );

			return $written;
		}

		throw new IssueException(
			sprintf(
				'A number could not be settled on after %1$d attempts: %2$s',
				self::ATTEMPTS,
				null === $last_error ? 'unknown' : $last_error->getMessage()
			),
			array( 'numbering_failed' )
		);
	}

	/**
	 * Void an issued document.
	 *
	 * The number stays with it. A number that vanished is worse than a number
	 * that says why it is void, and the register has to be able to account for
	 * every one it ever handed out.
	 *
	 * @param Document $document The document.
	 * @param string   $reason   Why, in the words of whoever decided.
	 * @return Document
	 *
	 * @throws IssueException If the document is not one that can be cancelled.
	 */
	public function cancel( Document $document, string $reason ): Document {
		if ( DocumentStatus::Issued !== $document->status ) {
			throw new IssueException(
				'Only an issued delivery note can be cancelled.',
				array( 'not_issued' )
			);
		}

		$reason = trim( $reason );

		if ( '' === $reason ) {
			throw new IssueException(
				'A cancellation has to say why.',
				array( 'reason_missing' )
			);
		}

		$cancelled = $this->documents->save(
			new Document(
				$document->id,
				DocumentStatus::Cancelled,
				$document->number,
				$document->document_date,
				$document->parties,
				$document->transport,
				$document->causal,
				$document->lines,
				$document->order_ids,
				$document->notes,
				$document->customer_id,
				$document->lifecycle->cancelled(
					$this->clock->now()->format( 'Y-m-d H:i:s' ),
					get_current_user_id(),
					$reason
				)
			)
		);

		$this->log->record(
			AuditLog::DOCUMENT_CANCELLED,
			sprintf( 'Delivery note %s was cancelled.', $cancelled->number->formatted ),
			array(
				'number' => $cancelled->number->formatted,
				'reason' => $reason,
				'orders' => $cancelled->all_order_ids(),
			),
			$cancelled->id
		);

		/**
		 * Fires when a delivery note has been cancelled.
		 *
		 * Its quantities are back on the order from this moment.
		 *
		 * @since 0.1.0
		 *
		 * @param Document $cancelled The cancelled document.
		 * @param string   $reason    Why it was cancelled.
		 */
		do_action( 'oxyddt_cancelled', $cancelled, $reason );

		return $cancelled;
	}

	/**
	 * What the next number will look like.
	 *
	 * For the settings screen, and for nothing else: anything that acts on this
	 * rather than on the allocation has a race in it.
	 *
	 * @return string
	 */
	public function next_number_preview(): string {
		$policy = $this->settings->numbering();
		$year   = (int) $this->clock->local()->format( 'Y' );

		return $policy->format->preview(
			$policy->series,
			$year,
			$this->sequences->peek( $policy->series, $policy->sequence_year( null, $year ), $policy->start )
		);
	}
}
