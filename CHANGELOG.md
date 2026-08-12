# Changelog

Every notable change, newest first. The `readme.txt` changelog is what users
read; this one is for whoever works on the plugin.

## [Unreleased]

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
