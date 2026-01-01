=== Fraud Order Blocker BD ===
Contributors: arshohel
Tags: woocommerce, fraud, security, bangladesh, phone validation, order blocking
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Blocks WooCommerce orders for fraud phone numbers from Bangladesh. Checks both billing and shipping phone numbers against a fraud database.

== Description ==

Fraud Order Blocker BD is a security plugin for WooCommerce that helps protect your store from fraudulent orders by checking customer phone numbers against a fraud database.

**Key Features:**

* Automatically validates billing and shipping phone numbers during checkout
* Blocks orders if phone number is flagged as fraudulent
* Supports Bangladesh phone number format (11 digits starting with 01)
* Real-time API integration with fraud detection service
* User-friendly error messages for blocked orders
* Lightweight and fast - minimal impact on checkout performance

**How It Works:**

1. When a customer attempts to place an order, the plugin checks both billing and shipping phone numbers
2. Phone numbers are validated to ensure they're in Bangladesh format (11 digits)
3. Valid phone numbers are checked against the fraud database via API
4. If a phone number is flagged as fraudulent, the order is blocked with a clear error message
5. Customers are notified and can contact support if needed

**Phone Number Format:**

The plugin accepts Bangladesh mobile numbers in the following formats:
* 01753555201 (11 digits starting with 01)
* +8801753555201 (with country code)
* 8801753555201 (without + sign)

All formats are automatically normalized to the 11-digit format for API checking.

**Requirements:**

* WordPress 5.0 or higher
* WooCommerce 3.0 or higher
* PHP 7.2 or higher
* Active internet connection for API calls

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/fraud-order-blocker-bd` directory, or install the plugin through the WordPress plugins screen directly
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Ensure WooCommerce is installed and active
4. The plugin will automatically start checking phone numbers during checkout

== Frequently Asked Questions ==

= Does this plugin work with international phone numbers? =

No, this plugin is specifically designed for Bangladesh phone numbers only. It validates that phone numbers are in the correct Bangladesh format (11 digits starting with 01) before checking them against the fraud database.

= What happens if the fraud API is down? =

If the fraud API is unavailable, the plugin will log an error but will not block orders. This ensures your store continues to function even if the external service is temporarily unavailable.

= Will this slow down checkout? =

The plugin makes a single API call per phone number (billing and/or shipping). API calls are optimized with a 10-second timeout to ensure checkout remains fast. Most API responses are under 100ms.

= Can I customize the error message? =

Currently, the error message is fixed, but you can filter it using WordPress hooks if needed. Future versions may include admin settings for customization.

= Does this plugin store any customer data? =

No, this plugin does not store any customer data. It only makes API calls to check phone numbers and does not save any information locally.

== Screenshots ==

1. Checkout validation in action
2. Error message displayed to customers

== Changelog ==

= 1.0.0 =
* Initial release
* Billing phone number validation
* Shipping phone number validation
* Bangladesh phone number format support
* Fraud API integration
* Error handling and logging

== Upgrade Notice ==

= 1.0.0 =
Initial release of Fraud Order Blocker BD.

