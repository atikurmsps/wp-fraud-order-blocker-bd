=== Fraud Order Blocker BD ===
Contributors: arshohel
Tags: woocommerce, fraud, security, bangladesh, phone validation, order blocking
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Blocks WooCommerce orders for fraud phone numbers from Bangladesh. Checks customer information (phone, name, address, products) against a fraud database during checkout.

== Description ==

Fraud Order Blocker BD is a security plugin for WooCommerce that helps protect your store from fraudulent orders by checking customer information against a fraud database in real-time during checkout.

**Key Features:**

* Automatically validates billing and shipping phone numbers during checkout
* Checks customer data: phone number, name, address, and product information
* Blocks orders if customer information is flagged as fraudulent
* Supports Bangladesh phone number format (11 digits starting with 01)
* Real-time API integration with fraud detection service
* Customizable error messages (default Bengali message included)
* Flexible blocking options: block specific shipping methods and payment gateways
* Admin settings page with two-column layout and helpful instructions
* API response caching (5 minutes) for improved performance
* Compatible with both standard WooCommerce checkout and Block-based checkout
* Lightweight and fast - minimal impact on checkout performance
* Full WooCommerce feature compatibility (HPOS, Blocks, etc.)

**How It Works:**

1. Customer enters information during checkout (phone, name, address, products)
2. Plugin validates phone, name, address, products format
3. Customer data (phone number, name, address, product names) is sent to fraud database via API
4. If flagged as fraud, order is blocked with customizable error message
5. Supports blocking based on specific shipping methods and payment gateways

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
4. Go to **Fraud Blocker BD** in the WordPress admin menu to configure settings
5. Enable fraud detection and configure blocking options (optional)
6. The plugin will automatically start checking customer information during checkout

== Frequently Asked Questions ==

= Does this plugin work with international phone numbers? =

No, this plugin is specifically designed for Bangladesh phone numbers only. It validates that phone numbers are in the correct Bangladesh format (11 digits starting with 01) before checking them against the fraud database.

= What happens if the fraud API is down? =

If the fraud API is unavailable, the plugin will log an error but will not block orders. This ensures your store continues to function even if the external service is temporarily unavailable.

= Will this slow down checkout? =

The plugin makes a single API call per phone number (billing and/or shipping). API calls are optimized with a 10-second timeout to ensure checkout remains fast. Most API responses are under 100ms.

= Can I customize the error message? =

Yes! You can customize the error message in the plugin settings page. Go to **Fraud Blocker BD** in the WordPress admin menu, and you'll find an "Error Message" field where you can enter your custom message. If left empty, the default Bengali message will be used.

= Can I block only specific shipping methods or payment gateways? =

Yes! The plugin allows you to selectively block orders based on shipping methods and payment gateways. In the settings page, you can:
* Select specific shipping methods to block for fraud customers
* Select specific payment gateways to block for fraud customers
* If no methods are selected, all orders with fraud information will be blocked

= Does this plugin store any customer data? =

No, this plugin does not store any customer data. It only makes API calls to check customer information and does not save any information locally. API responses are cached for 5 minutes to improve performance.

= Does this work with WooCommerce Blocks checkout? =

Yes! The plugin is fully compatible with both standard WooCommerce checkout and the new Block-based checkout. It uses multiple validation hooks to ensure compatibility with all checkout types.

== Screenshots ==

1. Checkout validation in action
2. Error message displayed to customers

== Changelog ==

= 1.0.0 =
* Initial release
* Billing and shipping phone number validation
* Customer data validation (phone, name, address, products)
* Bangladesh phone number format support (11 digits)
* Real-time fraud API integration
* Admin settings page with two-column layout
* Customizable error messages (default Bengali message)
* Selective blocking by shipping methods
* Selective blocking by payment gateways
* API response caching (5 minutes)
* Full WooCommerce Blocks checkout compatibility
* WooCommerce feature compatibility declarations
* WordPress coding standards compliance
* Extensible with WordPress filters and hooks
* Responsive admin interface (desktop, tablet, mobile)

== Upgrade Notice ==

= 1.0.0 =
Initial release of Fraud Order Blocker BD.

