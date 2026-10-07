=== ACS Courier for WooCommerce ===
Contributors: hikhakk
Tags: woocommerce, shipping, courier, greece, cyprus
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.4.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create ACS Courier vouchers and track shipments directly from WooCommerce. Supports Greece and Cyprus.

== Description ==

Connects your WooCommerce store to ACS Courier so you can turn a paid order into a
shipment without leaving WordPress.

**Free and complete.** Every feature in this plugin is available to everyone. There is
no paid tier, no licence key, no usage limit and no upsell.

= Features =

* Create an ACS voucher from any order, in one click
* Validation before sending, so an order ACS would reject never costs an API call
* Greek and Cypriot addresses, with the correct postcode rules for each (5 digits GR, 4 digits CY)
* Automatic weight conversion from your store's unit (kg, g, lbs, oz)
* Duplicate protection, so a double click can never create a second parcel
* Errors from ACS are written to the order notes verbatim, in ACS's own wording
* Print labels in thermal or A4 laser format
* Issue and print the ACS pickup list, with unprinted vouchers blocked before they break
* Track shipments, with non-delivery reasons in plain English
* Let customers collect from an ACS store or Smartpoint locker at checkout
* Live shipping prices for Greece, and a rate table for Cyprus
* Cash on delivery, with the amount taken from the order
* Works with both High-Performance Order Storage (HPOS) and the legacy order tables

= Requirements =

You need an ACS Courier business account. ACS issues you a Company ID, Company Password,
User ID, User Password, API key and billing code. This plugin does not create accounts and
is not affiliated with or endorsed by ACS Courier.

== External services ==

This plugin connects to the ACS Courier Web Services API to create and manage shipments.
It is required for the plugin to function: without it, no voucher can be created.

**Service:** ACS Courier Web Services
**Endpoint:** https://webservices.acscourier.net/ACSRestServices/api/ACSAutoRest

**What is sent, and when.** Data is sent only when a shop manager explicitly creates a
voucher for an order, or when the plugin looks up reference data such as ACS stations and
content types. Nothing is sent automatically on the front end, and nothing is sent when a
customer merely browses your store.

When you create a voucher, the following is transmitted to ACS:

* Recipient name, company, address, postcode, region and country
* Recipient telephone number and email address
* Order number, shipment weight and parcel count
* Your ACS credentials, billing code and sender name

ACS Courier is the data controller for the information it receives. Review their terms and
privacy policy before use:

* Terms: https://www.acscourier.net/en/terms-of-use/
* Privacy: https://www.acscourier.net/en/privacy-policy/

This plugin sends no data anywhere else. It contains no analytics, no telemetry and no
usage tracking, and it never contacts the plugin author.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/acs-courier-for-woocommerce`, or install it through the Plugins screen.
2. Activate it through the Plugins screen.
3. Go to WooCommerce > Settings > Shipping > ACS Courier and enter the credentials ACS issued you.

For production sites you can keep credentials out of the database entirely by defining them
in `wp-config.php`, which takes precedence over the settings screen:

`define( 'ACS_WC_COMPANY_ID', '...' );`
`define( 'ACS_WC_COMPANY_PASSWORD', '...' );`
`define( 'ACS_WC_USER_ID', '...' );`
`define( 'ACS_WC_USER_PASSWORD', '...' );`
`define( 'ACS_WC_API_KEY', '...' );`

== Frequently Asked Questions ==

= Do I need an ACS account? =

Yes. This plugin talks to ACS on your behalf using credentials ACS issues to you.

= Which countries are supported? =

Greece and Cyprus. The ACS API does not support creating vouchers to other countries.

= Why does it ask for a content type for Cyprus? =

Cypriot customs require the shipment contents to be declared. ACS rejects Cyprus shipments
without one, and undeclared parcels can be delayed or fined at Larnaca customs.

= Does it charge me anything? =

No. The plugin is free and unrestricted. Your shipping costs are billed by ACS under your
own contract; this plugin does not process payments.

= Is any of my data sent to the plugin author? =

No. See the External services section above.

= Can you set it up for me? =

Yes. The team that wrote the plugin can install and configure it, connect it to your ACS account, or adapt it to how you ship. See https://kdvassiliougroup.com/products/acs-courier-for-woocommerce/.

== Screenshots ==

1. The ACS panel on the WooCommerce order screen.
2. The settings screen under WooCommerce > Settings > Shipping.

== Changelog ==

= 0.4.3 =
* Fixed: vouchers created between midnight and 03:00 store time were rejected with "Invalid pickup
  date value." The pickup date was taken in UTC, which still reads as yesterday in Greece and Cyprus.

= 0.4.2 =
* Fixed: deliveries to an ACS store in Cyprus were rejected with "Shipment product value REC is not
  valid." Cyprus has no REC product; a store there is addressed by station and branch, like a locker.

= 0.4.1 =
* Initial release: voucher creation from WooCommerce orders, Greece and Cyprus support.

== Upgrade Notice ==

= 0.4.1 =
First release.
