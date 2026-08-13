# Changelog

Every notable change, newest first. The `readme.txt` changelog is what users
read; this one is for whoever works on the plugin.

## [Unreleased]

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
