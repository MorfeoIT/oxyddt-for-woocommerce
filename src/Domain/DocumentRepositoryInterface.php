<?php
/**
 * Where documents are kept.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT\Domain;

/**
 * The store, described from the Domain's side.
 *
 * Declared here and implemented in Persistence so that everything above it can
 * be tested against a store that lives in an array, and so that PRO can put a
 * different one in the container — a store that reads from more than one shop,
 * for instance — without the model knowing.
 */
interface DocumentRepositoryInterface {

	/**
	 * Write a document, and give it back with its identifier and timestamps.
	 *
	 * @param Document $document The document.
	 * @return Document
	 */
	public function save( Document $document ): Document;

	/**
	 * One document.
	 *
	 * @param int $id Row identifier.
	 * @return Document|null Null when there is no such document.
	 */
	public function find( int $id ): ?Document;

	/**
	 * Every document that draws on an order, oldest first.
	 *
	 * @param int  $order_id          The order.
	 * @param bool $include_cancelled Whether void documents come back too.
	 * @return list<Document>
	 */
	public function for_order( int $order_id, bool $include_cancelled = true ): array;

	/**
	 * The register: documents matching a query, and how many there are in all.
	 *
	 * The count is of everything that matched, not of what came back, because
	 * the screen has to say "page 2 of 7" without asking a second time.
	 *
	 * @param DocumentQuery $query What is being looked for.
	 * @return array{items: list<Document>, total: int}
	 */
	public function search( DocumentQuery $query ): array;

	/**
	 * The archived PDF of a document, if it has one.
	 *
	 * @param int $id Row identifier.
	 * @return array{path: string, hash: string}|null
	 */
	public function pdf_of( int $id ): ?array;

	/**
	 * Record where a document's PDF was archived, and what it was.
	 *
	 * @param int    $id   Row identifier.
	 * @param string $path Path relative to the uploads directory.
	 * @param string $hash SHA-256 of the file as it was written.
	 * @return void
	 */
	public function set_pdf( int $id, string $path, string $hash ): void;

	/**
	 * Throw away a draft.
	 *
	 * Only a draft. An issued document is cancelled, never deleted: a number
	 * that vanished is worse than a number that says why it is void.
	 *
	 * @param int $id Row identifier.
	 * @return bool Whether anything was deleted.
	 */
	public function delete( int $id ): bool;
}
