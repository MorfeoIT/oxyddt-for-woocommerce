# Changelog

Every notable change, newest first. The `readme.txt` changelog is what users
read; this one is for whoever works on the plugin.

## [Unreleased]

### Sprint 6 — the register, and the box on the order

* WooCommerce → DDT opens on the register: number, date, customer, order, place
  of delivery, reason, carrier, state and the PDF, newest first.
* Filters for the questions people actually ask — the delivery notes of March, a
  range of numbers for the accountant, everything sent by one carrier, only the
  cancelled ones — and a search over the three things somebody has in their hand:
  a number, a customer's name, an order.
* The filters are a GET form, so a filtered register is a link somebody can send
  to a colleague. Turning the page keeps them.
* Every filter is read once into a value object that fixes its shape — a page of
  at least one, a month between one and twelve, a sort column from a list of two
  — and the query trusts nothing else.
* The order box now says how many lines are complete as well as how many pieces
  have gone: nine of ten pieces sent can still be four lines short.

### Sprint 5 — the PDF, the download, the email

* A4 delivery note rendered by a bundled dompdf, with the shop's logo embedded
  from disk. Remote fetching is switched off in the engine: the page is built
  partly from what a customer typed, and a document that could fetch URLs while
  rendering is a server-side request forgery with extra steps.
* The PDF is rendered once when the document is issued and archived with its
  SHA-256. Everything afterwards reads that same file, so the shop's copy and
  the customer's are the same copy. A file that has gone missing is rebuilt from
  the snapshot, and the register says so.
* Downloads go through an endpoint that checks a capability and a nonce. The
  archive directory carries an .htaccess, a web.config and an index.php, and
  every filename ends in twenty random characters — but the endpoint is the part
  that actually decides.
* Printing is the same endpoint served inline, not a second layout to keep in
  step with the first.
* Manual email with the PDF attached, addressed to the customer as the document
  froze them, with a subject and body a shop can rewrite through a filter.
  Automatic sending stays out of the free plugin on purpose.
* The whole page comes from `templates/pdf/document.php`, which a theme replaces
  by putting its own at `oxyddt/pdf/document.php`.

### Sprint 4 — numbering, issuing, immutability, cancelling

* Numbers are handed out by the database, not by PHP: one `UPDATE … SET
  next_number = LAST_INSERT_ID(next_number) + 1` per document, which MySQL
  serialises with a row lock. Two people pressing Issue in the same second get
  125 and 126.
* If a number is refused by the unique index anyway — somebody else got there
  first — the whole issue is retried with the next one. Never a duplicate; at
  worst a hole, and a hole is explainable.
* A number belongs to the year printed on the document, not to the day somebody
  pressed the button: one dated the 31st of December and issued on the 2nd of
  January counts against the old year.
* Issuing validates first and numbers second, so a draft that is not ready never
  spends a number.
* Cancelling keeps the number, records who and why, and gives the goods back to
  the order. A cancelled number is never handed out again.
* A numbering tab of its own, behind its own capability: where the count starts,
  how it is written, whether it resets in January, and a preview of the next one.

### Sprint 3 — creating from an order, what is left, partial fulfilment

* The calculation the product is bought for: ordered, already sent, held by
  another draft, still available — per order line, in plain PHP, with a test for
  every branch.
* A cancelled delivery note gives its quantities back to the order. A draft
  holds them without sending them. A draft being edited does not count against
  itself.
* The screen: one table, opening with the whole remainder filled in, and a
  header for the date, the reason for transport and the carrier.
* Asking for more than the order has left is refused, naming the line and what
  was available, unless the user is allowed to override it.
* A box on the order screen — what has gone out, every delivery note so far, and
  the button that starts the next one. It follows whichever screen the shop's
  orders live on, so it works with the high-performance order tables and without.

### Sprint 2 — the document, the orders it draws on, the customer it froze

* The document model: draft, issued, cancelled, and an issued one that refuses
  every change rather than being asked politely not to.
* Three tables. The unique key on (series, year, sequence) makes "never two
  documents with the same number" a fact about the database; the sequence
  columns are nullable so that a shop can hold any number of unnumbered drafts.
* Snapshots of the sender, the recipient and the destination, stored as JSON on
  the document. An order edited afterwards, or a shop that moves, does not
  rewrite a delivery note that has already been printed.
* Lines carry the order and order line they fulfil, which is what sprint 3 sums
  to work out what is still owed.
* A draft built from a WooCommerce order through WooCommerce's own CRUD, with
  filters for the Italian VAT and tax-code fields every checkout plugin stores
  under a different key.

### Sprint 1 — bootstrap, schema, sender, capabilities

* The plugin boots only with WooCommerce 8.2 or later, and says why when it does
  not.
* HPOS and block checkout compatibility are declared to WooCommerce.
* A numbered, idempotent migrator, with the audit log as its first table.
* Seven capabilities, granted per role and re-granted when the map changes.
* The sender: company details, addresses, VAT number and codice fiscale with
  their real check digits, logo, and a separate shipping origin.
* One admin page under WooCommerce, with tabs, holding the settings.
* An append-only audit log, written to from the first settings change.
* Deleting the plugin removes nothing unless the shop asked for it.
