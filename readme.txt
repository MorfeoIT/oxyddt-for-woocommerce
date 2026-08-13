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
* First development version: sender configuration, capabilities, schema and
  audit log.
