=== Eurobank WooCommerce Payment Gateway ===
Contributors: enartia,g.georgopoulos,georgekapsalakis,akatopodis
Author URI: https://www.papaki.com
Tags: ecommerce, woocommerce, payment gateway
Requires at least: 6.4.2
Stable tag: 2.0.3
WC tested: 8.5.0
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html
PHP: 7.4

== Important Notice ==

The plugin currently does not support the blocks system but still uses legacy.

== Description ==
This plugin adds Eurobank paycenter as a payment gateway for WooCommerce. A contract between you and the Bank must be previously signed.
It uses the redirect method, and SSL is not required.

== Features ==
Provides pre-auth transactions and free instalments.

== Installation ==

Just follow the standard [WordPress plugin installation procedure](http://codex.wordpress.org/Managing_Plugins).

Provide EuroBank the following information, in order to provide you with test account information. 
PERMALINKS ACTIVE
* Website url :  http(s)://www.yourdomain.gr/
* Referrer url : http(s)://www.yourdomain.gr/checkout/
* Success page :  http(s)://www.yourdomain.gr/wc-api/WC_EuroBank_Gateway?result=success
* Cancel/Failure page : http(s)://www.yourdomain.gr/wc-api/WC_EuroBank_Gateway?result=failure

PERMALINKS INACTIVE (MODE=SIMPLE)
* Website url :  http(s)://www.yourdomain.gr/
* Referrer url : http(s)://www.yourdomain.gr/checkout/
* Success page :  http(s)://www.yourdomain.gr/?wc-api=WC_EuroBank_Gateway&result=success
* Cancel/Failure page : http(s)://www.yourdomain.gr/?wc-api=WC_EuroBank_Gateway&result=failure

* Response method : POST
* Your's server IP Address 


== Frequently asked questions ==

= Does it work? =

= Does it work? =
Since version 2.0 we have removed a lot of old code, this also includes some code that made the application backward compatible
with 10+ year old versions of WordPress. The cleaned up codebase has been thoroughly tested with WordPress 6.4.2 and
WooCommerce 8.5.0. It's probably safe to assume that it works in most of the "recent" releases of both WooCommerce and WordPress,
but not versions from 10+ years ago.

== Changelog ==
= 2.0.3 =
Support IRIS payments

= 2.0.0 =
Major code cleanup
Removed backward compatibilities to make future development easier
Added extra layer of validation after submitting payment form
Compatibility updates regarding 3dsecure

= 1.8.7.1 =
Updated Texts and compatibility with Woocommerce 6.2.0

= 1.8.7 = 
Add option to enable/disable for the 2nd payment email with transaction details
Add debugging mode, to log certain information

= 1.8.6 = 
Update compatibility with WooCommerce 5.0.0

= 1.8.5 = 
Exclude billing state info for 3DS if country is Greece

= 1.8.4 = 
Change test url for post requests

= 1.8.3 = 
Updated Texts and compatibility with Woocommerce 4.6.1

= 1.8.2 = 
Sanitize Data
fix a warning in messages in logs
Add Pre-authorization transactions option

= 1.8.1 = 
Update compatibility with WooCommerce 4.1.0

= 1.8.0 = 
Fix an issue with wc session and the id of the order 

= 1.7.2 =
Update translations

= 1.7.1 =
For downloadable products, don't auto mark the order as completed, unless all the products are downloadable
Update translations
Added option to display or not EuroBank's logo in checkout page.


= 1.7.0 =
Plugin is now compliant with new EMV 3D Secure specifications

= 1.6.1 = 

Fixes the issue where in test environment Eurobank needs more fields required, due to the upcoming PSD 2.

= 1.6.0 =
You can now have instalments either deeping on order total amount or not.

= 1.5.0 = 
Support for english language in redirect page.
Toggler for test/production environment
Pay again if a transaction is failed
Fix duplicate message after transactions

= 1.4.0 =
Instalments capability

= 1.0.2 =
WooCommerce 3.0 compatible

= 1.0.1 =
Initial Release
Bug Fixes

= 1.0.0 =
Initial Release

