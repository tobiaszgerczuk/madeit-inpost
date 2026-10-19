=== MadeIT InPost ===
Contributors: wcolabs
Tags: woocommerce, inpost, parcel locker, paczkomat, shipping
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An InPost parcel locker shipping method for WooCommerce, with a map to choose the pickup point in the classic and the block checkout.

== Description ==

MadeIT InPost adds the "InPost Parcel Locker" shipping method to WooCommerce. When the customer picks it, a button opens the official InPost map and the chosen point is saved in the order, shown on the order page, in the emails and on the order screen in the admin.

**Features**

* "InPost Parcel Locker" shipping method for the WooCommerce shipping zones, with a cost and an optional free shipping threshold.
* Parcel locker map (InPost Geowidget) in the classic checkout and in the WooCommerce Checkout block. The picker appears right under the shipping options.
* Without a map token the customer types the point code, so the method also works before you set up the map.
* Choosing a point can be required before the order is placed.
* The point is saved in the order and shown to the customer and the shop manager.
* Compatible with High-Performance Order Storage.
* Fully translatable.

**MadeIT InPost Pro**

An optional add-on from WCO Labs (sold separately at [labs.made-it.pl/en](https://labs.made-it.pl/en/plugins/madeit-inpost-pro)) creates InPost shipments from the order screen through the ShipX API, downloads PDF labels, passes cash on delivery, and lets you edit the texts and the accent colour. The free plugin is complete on its own.

== External services ==

When you enter a Geowidget token on the Map tab, the checkout loads InPost's map script and stylesheet from `geowidget.inpost.pl` (or `sandbox-geowidget.inpost.pl` in test mode) and shows InPost's map inside the page. This service is provided by InPost Sp. z o.o. Terms of use and privacy policy: https://inpost.pl/. Nothing is loaded from InPost until a token is set, and the script is requested when a customer opens the map.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the plugin to `/wp-content/plugins/` or install it from the Plugins screen, then activate it.
3. In WooCommerce → Settings → Shipping, add "InPost Parcel Locker (MadeIT)" to a shipping zone and set the cost.
4. Open MadeIT InPost → Map and enter your Geowidget token from the InPost Parcel Manager (optional).

== Frequently Asked Questions ==

= Do I need an InPost account? =

For the map you need a Geowidget token, which you get in the InPost Parcel Manager. Without it, customers type the point code, such as WAW01M.

= Does it work with the WooCommerce Checkout block? =

Yes. The picker is added to the block checkout and the chosen point is sent with the order.

= Can I create shipments and labels? =

Not in the free plugin. The Pro add-on creates shipments and PDF labels through InPost ShipX.

= Does it send customer data anywhere? =

No customer data is sent by the plugin. When a map token is set, the visitor's browser loads InPost's map from InPost.

== Screenshots ==

1. The parcel locker picker in the block checkout.
2. The settings screen with the methods found in shipping zones.
3. The map settings.
4. The InPost box on the order screen.

== Changelog ==

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.0 =
First public release.
