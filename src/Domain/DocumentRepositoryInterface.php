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
