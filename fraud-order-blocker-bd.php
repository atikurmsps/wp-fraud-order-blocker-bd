<?php
/**
 * Plugin Name: Fraud Order Blocker BD
 * Plugin URI: https://arshohel.com
 * Description: Blocks WooCommerce orders for fraud phone numbers from Bangladesh. Checks both billing and shipping phone numbers against a fraud database.
 * Version: 1.0.0
 * Author: Atikur Rahman Shohel
 * Author URI: https://arshohel.com
 * Text Domain: fraud-order-blocker-bd
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'FOB_BD_VERSION', '1.0.0' );
define( 'FOB_BD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FOB_BD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FOB_BD_FRAUD_API_URL', 'https://steadfastfraud.appcloud.uk/index.php' );

/**
 * Main plugin class
 */
class Fraud_Order_Blocker_BD {
	
	/**
	 * Instance of this class
	 *
	 * @var object
	 */
	private static $instance = null;
	
	/**
	 * Get instance of this class
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Constructor
	 */
	private function __construct() {
		// Declare WooCommerce feature compatibility
		add_action( 'before_woocommerce_init', array( $this, 'declare_feature_compatibility' ) );
		
		// Check if WooCommerce is active
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}
	
	/**
	 * Declare compatibility with WooCommerce features
	 */
	public function declare_feature_compatibility() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}
		
		// Common WooCommerce features that might need compatibility declaration
		// This plugin uses standard WooCommerce hooks and is compatible with all features
		$common_features = array(
			'cart_checkout_blocks',
			'custom_order_tables',
			'analytics',
			'product_block_editor',
			'rate_limit_checkout',
			'marketplace',
			'order_attribution',
			'email_improvements',
			'blueprint',
			'hpos_fts_indexes',
			'hpos_datastore_caching',
			'remote_logging',
			'block_email_editor',
			'point_of_sale',
			'fulfillments',
			'mcp_integration',
			'destroy-empty-sessions',
			'agentic_checkout',
		);
		
		// Declare compatibility with common features
		foreach ( $common_features as $feature_id ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				$feature_id,
				__FILE__,
				true
			);
		}
	}
	
	/**
	 * Initialize plugin
	 */
	public function init() {
		// Check if WooCommerce is active
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}
		
		// Hook into WooCommerce checkout validation (multiple hooks for compatibility)
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_phone_numbers' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_phone_numbers_after' ), 10, 2 );
		add_filter( 'woocommerce_checkout_fields', array( $this, 'add_phone_validation_class' ) );
		
		// Also validate for Store API (Blocks checkout) - hook before order is finalized
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'validate_phone_numbers_store_api' ), 10, 2 );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( $this, 'validate_phone_numbers_store_api_before_payment' ), 10, 2 );
	}
	
	/**
	 * Show notice if WooCommerce is not active
	 */
	public function woocommerce_missing_notice() {
		?>
		<div class="error">
			<p><?php esc_html_e( 'Fraud Order Blocker BD requires WooCommerce to be installed and active.', 'fraud-order-blocker-bd' ); ?></p>
		</div>
		<?php
	}
	
	/**
	 * Add validation class to phone fields
	 *
	 * @param array $fields Checkout fields
	 * @return array
	 */
	public function add_phone_validation_class( $fields ) {
		if ( isset( $fields['billing']['billing_phone'] ) ) {
			$fields['billing']['billing_phone']['class'][] = 'validate-bd-phone';
		}
		if ( isset( $fields['shipping']['shipping_phone'] ) ) {
			$fields['shipping']['shipping_phone']['class'][] = 'validate-bd-phone';
		}
		return $fields;
	}
	
	/**
	 * Validate phone numbers during checkout (standard checkout)
	 */
	public function validate_phone_numbers() {
		$billing_phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( $_POST['billing_phone'] ) : '';
		$shipping_phone = isset( $_POST['shipping_phone'] ) ? sanitize_text_field( $_POST['shipping_phone'] ) : '';
		
		$this->check_phone_numbers( $billing_phone, $shipping_phone );
	}
	
	/**
	 * Validate phone numbers after checkout validation
	 *
	 * @param array $data Posted checkout data
	 * @param WP_Error $errors Validation errors
	 */
	public function validate_phone_numbers_after( $data, $errors ) {
		$billing_phone = isset( $data['billing_phone'] ) ? sanitize_text_field( $data['billing_phone'] ) : '';
		$shipping_phone = isset( $data['shipping_phone'] ) ? sanitize_text_field( $data['shipping_phone'] ) : '';
		
		$this->check_phone_numbers( $billing_phone, $shipping_phone, $errors );
	}
	
	/**
	 * Validate phone numbers for Store API (Blocks checkout)
	 *
	 * @param WC_Order $order Order object
	 * @param WP_REST_Request $request Request object
	 */
	public function validate_phone_numbers_store_api( $order, $request ) {
		$billing_phone = $order->get_billing_phone();
		$shipping_phone = $order->get_shipping_phone();
		
		// For Store API, we need to throw an exception to prevent order creation
		$fraud_detected = $this->check_phone_numbers_store_api( $billing_phone, $shipping_phone );
		
		if ( $fraud_detected ) {
			// Use RouteException for proper API error handling
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'fraud_phone_detected',
					__( 'Your phone number has been flagged as fraudulent. Please contact customer support.', 'fraud-order-blocker-bd' ),
					400
				);
			} else {
				throw new \Exception( __( 'Your phone number has been flagged as fraudulent. Please contact customer support.', 'fraud-order-blocker-bd' ) );
			}
		}
	}
	
	/**
	 * Validate phone numbers before payment in Store API (Blocks checkout)
	 *
	 * @param WC_Order $order Order object
	 * @param WP_Error $validation_errors Validation errors object
	 */
	public function validate_phone_numbers_store_api_before_payment( $order, $validation_errors ) {
		$billing_phone = $order->get_billing_phone();
		$shipping_phone = $order->get_shipping_phone();
		
		// Check billing phone
		if ( ! empty( $billing_phone ) ) {
			$billing_phone_clean = $this->clean_bangladesh_phone( $billing_phone );
			if ( $billing_phone_clean && $this->is_fraud_phone( $billing_phone_clean ) ) {
				$validation_errors->add(
					'fraud_phone_billing',
					sprintf( 
						__( 'Your billing phone number (%s) has been flagged as fraudulent. Please contact customer support.', 'fraud-order-blocker-bd' ),
						esc_html( $billing_phone )
					)
				);
			}
		}
		
		// Check shipping phone (if different from billing)
		if ( ! empty( $shipping_phone ) && $shipping_phone !== $billing_phone ) {
			$shipping_phone_clean = $this->clean_bangladesh_phone( $shipping_phone );
			if ( $shipping_phone_clean && $this->is_fraud_phone( $shipping_phone_clean ) ) {
				$validation_errors->add(
					'fraud_phone_shipping',
					sprintf( 
						__( 'Your shipping phone number (%s) has been flagged as fraudulent. Please contact customer support.', 'fraud-order-blocker-bd' ),
						esc_html( $shipping_phone )
					)
				);
			}
		}
	}
	
	/**
	 * Check phone numbers and add error notices
	 *
	 * @param string $billing_phone Billing phone number
	 * @param string $shipping_phone Shipping phone number
	 * @param WP_Error|null $errors Optional WP_Error object to add errors to
	 */
	private function check_phone_numbers( $billing_phone, $shipping_phone, $errors = null ) {
		// Check billing phone
		if ( ! empty( $billing_phone ) ) {
			$billing_phone_clean = $this->clean_bangladesh_phone( $billing_phone );
			if ( $billing_phone_clean ) {
				if ( $this->is_fraud_phone( $billing_phone_clean ) ) {
					$error_message = sprintf( 
						__( 'Your billing phone number (%s) has been flagged as fraudulent. Please contact customer support.', 'fraud-order-blocker-bd' ),
						esc_html( $billing_phone )
					);
					
					if ( $errors instanceof \WP_Error ) {
						$errors->add( 'fraud_phone_billing', $error_message );
					} else {
						wc_add_notice( $error_message, 'error' );
					}
				}
			}
		}
		
		// Check shipping phone (if different from billing)
		if ( ! empty( $shipping_phone ) && $shipping_phone !== $billing_phone ) {
			$shipping_phone_clean = $this->clean_bangladesh_phone( $shipping_phone );
			if ( $shipping_phone_clean ) {
				if ( $this->is_fraud_phone( $shipping_phone_clean ) ) {
					$error_message = sprintf( 
						__( 'Your shipping phone number (%s) has been flagged as fraudulent. Please contact customer support.', 'fraud-order-blocker-bd' ),
						esc_html( $shipping_phone )
					);
					
					if ( $errors instanceof \WP_Error ) {
						$errors->add( 'fraud_phone_shipping', $error_message );
					} else {
						wc_add_notice( $error_message, 'error' );
					}
				}
			}
		}
	}
	
	/**
	 * Check phone numbers for Store API (returns true if fraud detected)
	 *
	 * @param string $billing_phone Billing phone number
	 * @param string $shipping_phone Shipping phone number
	 * @return bool True if fraud detected
	 */
	private function check_phone_numbers_store_api( $billing_phone, $shipping_phone ) {
		$fraud_detected = false;
		
		// Check billing phone
		if ( ! empty( $billing_phone ) ) {
			$billing_phone_clean = $this->clean_bangladesh_phone( $billing_phone );
			if ( $billing_phone_clean && $this->is_fraud_phone( $billing_phone_clean ) ) {
				$fraud_detected = true;
			}
		}
		
		// Check shipping phone (if different from billing)
		if ( ! empty( $shipping_phone ) && $shipping_phone !== $billing_phone ) {
			$shipping_phone_clean = $this->clean_bangladesh_phone( $shipping_phone );
			if ( $shipping_phone_clean && $this->is_fraud_phone( $shipping_phone_clean ) ) {
				$fraud_detected = true;
			}
		}
		
		return $fraud_detected;
	}
	
	/**
	 * Clean and validate Bangladesh phone number
	 * 
	 * @param string $phone Phone number
	 * @return string|false Cleaned phone number or false if invalid
	 */
	private function clean_bangladesh_phone( $phone ) {
		// Remove all non-digit characters
		$phone = preg_replace( '/[^0-9]/', '', $phone );
		
		// Remove country code if present (+880 or 880)
		if ( preg_match( '/^880(\d{10})$/', $phone, $matches ) ) {
			$phone = '0' . $matches[1];
		}
		
		// Check if it's a valid Bangladesh mobile number (11 digits starting with 01)
		if ( preg_match( '/^01[3-9]\d{8}$/', $phone ) ) {
			return $phone;
		}
		
		return false;
	}
	
	/**
	 * Check if phone number is fraudulent
	 *
	 * @param string $phone Cleaned phone number (11 digits)
	 * @return bool True if fraud, false otherwise
	 */
	private function is_fraud_phone( $phone ) {
		if ( empty( $phone ) ) {
			return false;
		}
		
		// Build API URL
		$api_url = add_query_arg( 'phone', urlencode( $phone ), FOB_BD_FRAUD_API_URL );
		
		// Make API request
		$response = wp_remote_get( $api_url, array(
			'timeout'     => 10,
			'sslverify'  => true,
			'user-agent' => 'Fraud-Order-Blocker-BD/' . FOB_BD_VERSION,
		) );
		
		// Check for errors
		if ( is_wp_error( $response ) ) {
			// Don't block order if API is down
			return false;
		}
		
		// Check HTTP response code
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return false;
		}
		
		// Get response body
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		
		// Check if JSON decode failed
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return false;
		}
		
		// Check if response is valid and fraud is detected
		if ( isset( $data['fraud'] ) && true === $data['fraud'] ) {
			return true;
		}
		
		return false;
	}
}

// Initialize plugin
Fraud_Order_Blocker_BD::get_instance();

