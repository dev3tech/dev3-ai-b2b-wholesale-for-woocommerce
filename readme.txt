=== DEV3 AI B2B Wholesale & Quote Requests for WooCommerce ===
Contributors: dev3tech
Tags: wholesale, b2b, request a quote, tiered pricing, quick order
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 11.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Wholesale roles, tiered B2B pricing, quick order and request-a-quote for WooCommerce, with DEV3 AI quote drafts from free-text requests.

== Description ==

One plugin for both halves of B2B selling: wholesale pricing and priced quotes.

**Wholesale**

* Any number of wholesale roles (tiers) with a default discount, minimum order value and quantity, tax exemption, allowed payment and shipping methods, a free-shipping threshold and their own price label.
* Registration form (shortcode + block) with manual or automatic approval and WooCommerce emails for every step.
* Pricing rules for everyone, every wholesale role or one role, by product or category, with quantity breaks (percent off, amount off or fixed unit price), priorities and start/end dates.
* Product-level prices and breaks per role, wholesale-only or retail-only products, minimum quantity and pack size.
* Hide prices from guests, struck-through retail prices, a "Buy more, save more" table on product pages.
* Payment and shipping methods per role (classic and block checkout).
* Quick-order table (shortcode + block) with search, categories, role prices, quantity tiers and a "paste a list" box.

**Request a quote**

* "Add to quote" on product pages and in the quick-order table, "Request a quote for this cart" on the cart, a quote page with a free-text request.
* Admin quote editor: lines priced by your rules, overrides, shipping, discount, tax mode, validity, terms, messages and a timeline.
* PDF quotes, email with the PDF, a private customer link (or My Account → Quotes), Accept / Decline / message.
* Accept creates a pending WooCommerce order with exactly the quoted prices and a pay link (HPOS compatible).
* Automatic reminders and expiry.

**DEV3 AI (optional, your own Google Gemini key)**

* Draft a quote from the customer's free-text request: real catalogue products only, prices always from your rules.
* Write cover, follow-up, reply, decline and expiry emails.
* Screen wholesale applications with plain-language signals.
* Let shoppers turn a pasted list into products (rate-limited, cached, with a non-AI fallback).

Every AI result is a draft that you accept, edit or reject. The plugin is fully usable without AI.

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload, then activate it (WooCommerce must be active).
2. Open **DEV3 B2B & Quotes → Settings** and create the quote, quick-order and registration pages with one click.
3. Review the default roles in **Roles & tiers** and add pricing rules.
4. Optional: add a Google Gemini API key in **API Config** to turn on DEV3 AI.

== Frequently Asked Questions ==

= Does it work with the Cart and Checkout blocks? =
Yes. Prices, minimums, tax exemption and payment/shipping rules apply to both the classic and the block checkout.

= Is it compatible with HPOS? =
Yes. Orders are only created and read through the WooCommerce CRUD API.

= Can the AI invent prices or products? =
No. The AI only picks from catalogue products the plugin found for the request; any other id is dropped, and every price comes from your pricing rules.

= What data is sent to Google? =
Only when you press a DEV3 AI button: the request text and a list of matching product names/SKUs, or quote facts without addresses, or application details. Storefront list matching sends the shopper's list and product names.

== Screenshots ==

1. Dashboard with KPIs and the setup checklist.
2. A quote drafted by DEV3 AI from a free-text request.
3. Wholesale application with DEV3 AI screening.
4. Quick-order table with role prices and quantity tiers.
5. The customer's quote view with Accept and PDF.
6. Product page with the tier price table and "Add to quote".

== Changelog ==

= 1.0.1 (unreleased) =
* Fix: category and product names containing "&" (e.g. "Mugs & Drinkware") showed as HTML entities in the pricing rules table, rule editor, quick order filter and DEV3 AI prompts. Names are now sent as plain text, including names that were stored escaped twice.

= 1.0.0 =
* Initial release.
