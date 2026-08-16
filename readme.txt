=== OxyDDT – Italian Delivery Notes (DDT) for WooCommerce ===
Contributors: oxysoft
Tags: woocommerce, ddt, delivery note, italy, shipping
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Issue Italian delivery notes (DDT) from WooCommerce orders: partial fulfilment, protected numbering and documents that cannot change.

== Description ==

WooCommerce can print a packing slip. An Italian shop needs a documento di
trasporto: a numbered document, issued in sequence, that says what left the
warehouse, when, in whose care, and against which order.

OxyDDT is built around the part that generic delivery-note plugins leave out —
sending part of an order today and the rest next week, and knowing at a glance
what is still owed.

* Create a delivery note from an order, choosing how much of each line goes out
* More than one note per order, with the remaining quantities worked out for you
* Numbering that cannot produce the same number twice, however many people are
  issuing at once
* An issued document does not change, even if the order or the customer's
  address does afterwards
* A PDF you can print, download or email
* A register of everything issued, searchable and filterable
* Built for WooCommerce's high-performance order storage (HPOS)

= What this plugin does not claim =

OxyDDT is designed around the Italian rules for delivery notes, including the
framework of D.P.R. 472/1996. It is a tool for producing documents, not tax
advice, and no claim is made here that using it makes a shop compliant with
anything. Have your accountant look at the fields before you start issuing.

= What is not in it =

Electronic invoicing, FatturaPA XML, sending to the SdI, bookkeeping, courier
integrations, label printing and digital preservation are all out of scope.

== Installation ==

1. Install and activate WooCommerce 8.2 or later.
2. Install and activate OxyDDT.
3. Go to WooCommerce → DDT → Settings and fill in the sender.

== Frequently Asked Questions ==

= Does it work with the new order storage (HPOS)? =

Yes. The plugin never reads WooCommerce's order tables directly; it goes through
WooCommerce's own API, which is what makes it work either way.

= Can I issue several delivery notes for one order? =

That is what it is for. Each note records what it took, and the order shows what
is still to go out.

= Does deleting the plugin delete my documents? =

No, unless you turn that on in the settings first. Delivery notes are accounting
records and the default is to leave them alone.

== Changelog ==

= 0.1.0 =
* Delivery notes issued from WooCommerce orders through WooCommerce's own API: the plugin never reads its order tables, so it works whichever way a shop stores them.
* Partial fulfilment: several delivery notes per order, with what is ordered, what has gone out, what is held by another draft and what is still available worked out per line.
* A cancelled document ships nothing and gives its quantities back to the order; a draft holds goods without sending them; a draft being edited does not count against itself.
* Numbering handed out by the database, in one statement under a row lock, with a unique index behind it and a retry if a number is ever refused.
* A number belongs to the year printed on the document: one dated the 31st of December and issued on the 2nd of January counts against the old year.
* Your own number format, leading zeros, yearly reset, and a starting number for a shop arriving from another system.
* An issued document refuses every change: correcting one means cancelling it, with a reason, and issuing another. Both stay in the register.
* The sender, the recipient and the place of delivery are copied onto the document as it is issued, so editing the order afterwards does not rewrite a delivery note already printed.
* A4 PDF with the shop's logo, rendered once when the document is issued and archived with its SHA-256, so the shop's copy and the customer's are the same file.
* Downloads and printing go through an endpoint that checks a capability and a nonce; nothing links to the file directly.
* Manual email with the PDF attached, addressed to the customer as the document froze them. Nothing is ever sent automatically.
* A register of everything issued, filtered by year and month, number range, reason, carrier and state, and searched by number, customer or order.
* Seven separate capabilities, granted per role and re-granted when the list changes.
* An append-only log: who issued what, who cancelled what and why, who moved the counter.
* Italian translation included.
* Compatible with WooCommerce high performance order storage: the whole test suite runs twice, once with orders in the posts table and once with HPOS.
* Nothing is removed when the plugin is switched off, and nothing on uninstall unless the shop turns that on first.
* No telemetry: the plugin contacts no server of ours, ever.