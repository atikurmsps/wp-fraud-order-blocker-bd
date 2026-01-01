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
		
		// Add admin settings menu (only if WooCommerce is active)
		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
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
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Fraud Blocker BD', 'fraud-order-blocker-bd' ),
			__( 'Fraud Blocker BD', 'fraud-order-blocker-bd' ),
			'manage_options',
			'fob-bd-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-shield-alt',
			56
		);
	}
	
	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting( 'fob_bd_settings', 'fob_bd_enabled' );
		register_setting( 'fob_bd_settings', 'fob_bd_blocked_shipping_methods' );
		register_setting( 'fob_bd_settings', 'fob_bd_blocked_payment_gateways' );
		register_setting( 'fob_bd_settings', 'fob_bd_error_message' );
	}
	
	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'toplevel_page_fob-bd-settings' !== $hook ) {
			return;
		}
		
		// Enqueue WooCommerce select2 if available
		if ( class_exists( 'WooCommerce' ) ) {
			wp_enqueue_script( 'selectWoo' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		} else {
			// Fallback to select2 if WooCommerce is not available
			wp_enqueue_script( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array( 'jquery' ), '4.1.0', true );
			wp_enqueue_style( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0' );
		}
	}
	
	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		
		// Check if WooCommerce is active
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'WooCommerce must be installed and activated to use this plugin.', 'fraud-order-blocker-bd' ) . '</p></div></div>';
			return;
		}
		
		// Save settings
		if ( isset( $_POST['fob_bd_save_settings'] ) && check_admin_referer( 'fob_bd_save_settings' ) ) {
			update_option( 'fob_bd_enabled', isset( $_POST['fob_bd_enabled'] ) ? 'yes' : 'no' );
			
			if ( isset( $_POST['fob_bd_blocked_shipping_methods'] ) && is_array( $_POST['fob_bd_blocked_shipping_methods'] ) ) {
				$shipping_methods = array_map( 'sanitize_text_field', $_POST['fob_bd_blocked_shipping_methods'] );
				update_option( 'fob_bd_blocked_shipping_methods', $shipping_methods );
			} else {
				update_option( 'fob_bd_blocked_shipping_methods', array() );
			}
			
			if ( isset( $_POST['fob_bd_blocked_payment_gateways'] ) && is_array( $_POST['fob_bd_blocked_payment_gateways'] ) ) {
				$payment_gateways = array_map( 'sanitize_text_field', $_POST['fob_bd_blocked_payment_gateways'] );
				update_option( 'fob_bd_blocked_payment_gateways', $payment_gateways );
			} else {
				update_option( 'fob_bd_blocked_payment_gateways', array() );
			}
			
			if ( isset( $_POST['fob_bd_error_message'] ) ) {
				$error_message = sanitize_textarea_field( $_POST['fob_bd_error_message'] );
				update_option( 'fob_bd_error_message', $error_message );
			} else {
				update_option( 'fob_bd_error_message', '' );
			}
			
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully!', 'fraud-order-blocker-bd' ) . '</p></div>';
		}
		
		$enabled = $this->is_enabled();
		$blocked_shipping = $this->get_blocked_shipping_methods();
		$blocked_payment = $this->get_blocked_payment_gateways();
		$error_message = get_option( 'fob_bd_error_message', '' );
		$shipping_methods = $this->get_shipping_methods();
		$payment_gateways = $this->get_payment_gateways();
		
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<form method="post" action="">
				<?php wp_nonce_field( 'fob_bd_save_settings' ); ?>
				
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="fob_bd_enabled"><?php esc_html_e( 'Enable Fraud Detection', 'fraud-order-blocker-bd' ); ?></label>
							</th>
							<td>
								<label for="fob_bd_enabled">
									<input type="checkbox" name="fob_bd_enabled" id="fob_bd_enabled" value="yes" <?php checked( $enabled, true ); ?>>
									<?php esc_html_e( 'Enable fraud detection for Bangladesh phone numbers', 'fraud-order-blocker-bd' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'When enabled, the plugin will check phone numbers against the fraud database during checkout.', 'fraud-order-blocker-bd' ); ?></p>
							</td>
						</tr>
						
						<tr>
							<th scope="row">
								<label for="fob_bd_blocked_shipping_methods"><?php esc_html_e( 'Block Shipping Methods', 'fraud-order-blocker-bd' ); ?></label>
							</th>
							<td>
								<select name="fob_bd_blocked_shipping_methods[]" id="fob_bd_blocked_shipping_methods" multiple="multiple" class="wc-enhanced-select" style="width: 400px;">
									<?php foreach ( $shipping_methods as $method_id => $method_name ) : ?>
										<option value="<?php echo esc_attr( $method_id ); ?>" <?php selected( in_array( $method_id, $blocked_shipping, true ), true ); ?>>
											<?php echo esc_html( $method_name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Select shipping methods to block for fraud customers. Leave empty to block all shipping methods.', 'fraud-order-blocker-bd' ); ?>
								</p>
								<p class="description">
									<strong><?php esc_html_e( 'Note:', 'fraud-order-blocker-bd' ); ?></strong>
									<?php esc_html_e( 'If no shipping methods are selected, all orders with fraud phone numbers will be blocked regardless of shipping method.', 'fraud-order-blocker-bd' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row">
								<label for="fob_bd_blocked_payment_gateways"><?php esc_html_e( 'Block Payment Gateways', 'fraud-order-blocker-bd' ); ?></label>
							</th>
							<td>
								<select name="fob_bd_blocked_payment_gateways[]" id="fob_bd_blocked_payment_gateways" multiple="multiple" class="wc-enhanced-select" style="width: 400px;">
									<?php foreach ( $payment_gateways as $gateway_id => $gateway_name ) : ?>
										<option value="<?php echo esc_attr( $gateway_id ); ?>" <?php selected( in_array( $gateway_id, $blocked_payment, true ), true ); ?>>
											<?php echo esc_html( $gateway_name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Select payment gateways to block for fraud customers. Leave empty to block all payment gateways.', 'fraud-order-blocker-bd' ); ?>
								</p>
								<p class="description">
									<strong><?php esc_html_e( 'Note:', 'fraud-order-blocker-bd' ); ?></strong>
									<?php esc_html_e( 'If no payment gateways are selected, all orders with fraud phone numbers will be blocked regardless of payment method.', 'fraud-order-blocker-bd' ); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row">
								<label for="fob_bd_error_message"><?php esc_html_e( 'Error Message', 'fraud-order-blocker-bd' ); ?></label>
							</th>
							<td>
								<textarea name="fob_bd_error_message" id="fob_bd_error_message" rows="3" cols="50" class="large-text"><?php echo esc_textarea( $error_message ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Custom error message to display when a fraud phone number is detected. Leave empty to use the default message.', 'fraud-order-blocker-bd' ); ?>
								</p>
								<p class="description">
									<strong><?php esc_html_e( 'Default:', 'fraud-order-blocker-bd' ); ?></strong>
									<?php esc_html_e( 'Your phone number has been flagged as fraudulent. Please contact customer support.', 'fraud-order-blocker-bd' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				
				<?php submit_button( __( 'Save Settings', 'fraud-order-blocker-bd' ), 'primary', 'fob_bd_save_settings' ); ?>
			</form>
		</div>
		
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			if (typeof $().selectWoo !== 'undefined') {
				$('#fob_bd_blocked_shipping_methods, #fob_bd_blocked_payment_gateways').selectWoo({
					width: '400px'
				});
			} else if (typeof $().select2 !== 'undefined') {
				$('#fob_bd_blocked_shipping_methods, #fob_bd_blocked_payment_gateways').select2({
					width: '400px'
				});
			}
		});
		</script>
		<?php
	}
	
	/**
	 * Get available shipping methods
	 *
	 * @return array
	 */
	private function get_shipping_methods() {
		$shipping_methods = array();

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $shipping_methods;
		}

		try {
			if ( class_exists( 'WC_Shipping' ) ) {
				$shipping = new WC_Shipping();
				$methods  = $shipping->get_shipping_methods();

				if ( is_array( $methods ) ) {
					foreach ( $methods as $method_id => $method ) {
						if ( is_object( $method ) && method_exists( $method, 'get_method_title' ) ) {
							$shipping_methods[ $method_id ] = $method->get_method_title();
						}
					}
				}

				// Also get active shipping zones
				if ( class_exists( 'WC_Shipping_Zones' ) ) {
					$zones = WC_Shipping_Zones::get_zones();
					if ( is_array( $zones ) ) {
						foreach ( $zones as $zone ) {
							if ( ! isset( $zone['zone_id'] ) ) {
								continue;
							}
							try {
								$zone_obj = new WC_Shipping_Zone( $zone['zone_id'] );
								$zone_methods = $zone_obj->get_shipping_methods( true );

								if ( is_array( $zone_methods ) ) {
									foreach ( $zone_methods as $method ) {
										if ( ! is_object( $method ) ) {
											continue;
										}
										$method_id = method_exists( $method, 'get_method_id' ) ? $method->get_method_id() : '';
										$instance_id = method_exists( $method, 'get_instance_id' ) ? $method->get_instance_id() : '';
										$full_id = $method_id . ':' . $instance_id;
										
										if ( ! empty( $method_id ) && ! isset( $shipping_methods[ $method_id ] ) ) {
											$shipping_methods[ $method_id ] = method_exists( $method, 'get_title' ) ? $method->get_title() : $method_id;
										}
										if ( ! empty( $full_id ) ) {
											$zone_name = method_exists( $zone_obj, 'get_zone_name' ) ? $zone_obj->get_zone_name() : '';
											$shipping_methods[ $full_id ] = ( method_exists( $method, 'get_title' ) ? $method->get_title() : $method_id ) . ' (' . $zone_name . ')';
										}
									}
								}
							} catch ( Exception $e ) {
								// Skip this zone if there's an error
								continue;
							}
						}
					}

					// Get default zone methods
					try {
						$default_zone = new WC_Shipping_Zone( 0 );
						$default_methods = $default_zone->get_shipping_methods( true );
						if ( is_array( $default_methods ) ) {
							foreach ( $default_methods as $method ) {
								if ( ! is_object( $method ) ) {
									continue;
								}
								$method_id = method_exists( $method, 'get_method_id' ) ? $method->get_method_id() : '';
								$instance_id = method_exists( $method, 'get_instance_id' ) ? $method->get_instance_id() : '';
								$full_id = $method_id . ':' . $instance_id;
								
								if ( ! empty( $method_id ) && ! isset( $shipping_methods[ $method_id ] ) ) {
									$shipping_methods[ $method_id ] = method_exists( $method, 'get_title' ) ? $method->get_title() : $method_id;
								}
								if ( ! empty( $full_id ) ) {
									$shipping_methods[ $full_id ] = ( method_exists( $method, 'get_title' ) ? $method->get_title() : $method_id ) . ' (Default Zone)';
								}
							}
						}
					} catch ( Exception $e ) {
						// Skip default zone if there's an error
					}
				}
			}
		} catch ( Exception $e ) {
			// Return empty array if there's any error
			return array();
		}

		return $shipping_methods;
	}
	
	/**
	 * Get available payment gateways
	 *
	 * @return array
	 */
	private function get_payment_gateways() {
		$gateways = array();

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			return $gateways;
		}

		try {
			// Ensure WooCommerce is fully loaded
			if ( ! did_action( 'woocommerce_init' ) ) {
				do_action( 'woocommerce_init' );
			}
			
			// Get payment gateways using the standard WooCommerce method
			$wc = WC();
			if ( ! $wc ) {
				return $gateways;
			}
			
			// Initialize payment gateways
			$payment_gateways_instance = $wc->payment_gateways();
			
			if ( ! $payment_gateways_instance || ! is_object( $payment_gateways_instance ) ) {
				return $gateways;
			}
			
			// Get all payment gateways (both enabled and disabled)
			$payment_gateways = $payment_gateways_instance->payment_gateways();
			
			if ( is_array( $payment_gateways ) && ! empty( $payment_gateways ) ) {
				foreach ( $payment_gateways as $gateway_id => $gateway ) {
					if ( is_object( $gateway ) && is_a( $gateway, 'WC_Payment_Gateway' ) ) {
						$title = $this->get_gateway_title( $gateway, $gateway_id );
						if ( ! empty( $title ) ) {
							$gateways[ $gateway_id ] = $title;
						}
					}
				}
			}
			
			// If still empty, try accessing the internal property
			if ( empty( $gateways ) && isset( $payment_gateways_instance->payment_gateways ) ) {
				$internal_gateways = $payment_gateways_instance->payment_gateways;
				
				if ( is_array( $internal_gateways ) ) {
					foreach ( $internal_gateways as $gateway ) {
						if ( is_object( $gateway ) && is_a( $gateway, 'WC_Payment_Gateway' ) ) {
							$gateway_id = '';
							if ( isset( $gateway->id ) ) {
								$gateway_id = $gateway->id;
							} elseif ( method_exists( $gateway, 'get_id' ) ) {
								$gateway_id = $gateway->get_id();
							}
							
							if ( ! empty( $gateway_id ) ) {
								$title = $this->get_gateway_title( $gateway, $gateway_id );
								if ( ! empty( $title ) ) {
									$gateways[ $gateway_id ] = $title;
								}
							}
						}
					}
				}
			}
			
		} catch ( Exception $e ) {
			// Return empty array if there's any error
			return array();
		}

		return $gateways;
	}
	
	/**
	 * Get gateway title with fallbacks
	 *
	 * @param object $gateway Gateway object
	 * @param string $gateway_id Gateway ID
	 * @return string
	 */
	private function get_gateway_title( $gateway, $gateway_id ) {
		if ( method_exists( $gateway, 'get_title' ) ) {
			return $gateway->get_title();
		} elseif ( method_exists( $gateway, 'get_method_title' ) ) {
			return $gateway->get_method_title();
		} elseif ( isset( $gateway->title ) ) {
			return $gateway->title;
		} elseif ( isset( $gateway->method_title ) ) {
			return $gateway->method_title;
		} else {
			return ucfirst( str_replace( '_', ' ', $gateway_id ) );
		}
	}
	
	/**
	 * Check if plugin is enabled
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 'yes' === get_option( 'fob_bd_enabled', 'yes' );
	}
	
	/**
	 * Get blocked shipping methods
	 *
	 * @return array
	 */
	public function get_blocked_shipping_methods() {
		return get_option( 'fob_bd_blocked_shipping_methods', array() );
	}
	
	/**
	 * Get blocked payment gateways
	 *
	 * @return array
	 */
	public function get_blocked_payment_gateways() {
		return get_option( 'fob_bd_blocked_payment_gateways', array() );
	}
	
	/**
	 * Get error message
	 *
	 * @return string
	 */
	public function get_error_message() {
		$message = get_option( 'fob_bd_error_message', '' );
		if ( empty( trim( $message ) ) ) {
			$message = __( 'Your phone number has been flagged as fraudulent. Please contact customer support.', 'fraud-order-blocker-bd' );
		}
		return $message;
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
			$error_message = $this->get_error_message();
			// Use RouteException for proper API error handling
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'fraud_phone_detected',
					$error_message,
					400
				);
			} else {
				throw new \Exception( $error_message );
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
		// Check if plugin is enabled
		if ( ! $this->is_enabled() ) {
			return;
		}
		
		$billing_phone = $order->get_billing_phone();
		$shipping_phone = $order->get_shipping_phone();
		
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
		
		// If fraud detected, check if should block
		if ( $fraud_detected ) {
			$blocked_shipping = $this->get_blocked_shipping_methods();
			$blocked_payment = $this->get_blocked_payment_gateways();
			
			$should_block = false;
			
			// Check shipping methods from order
			if ( ! empty( $blocked_shipping ) ) {
				$shipping_methods = $order->get_shipping_methods();
				foreach ( $shipping_methods as $method ) {
					$method_id = $method->get_method_id();
					if ( in_array( $method_id, $blocked_shipping, true ) ) {
						$should_block = true;
						break;
					}
				}
			}
			
			// Check payment method from order
			if ( ! empty( $blocked_payment ) ) {
				$payment_method = $order->get_payment_method();
				if ( in_array( $payment_method, $blocked_payment, true ) ) {
					$should_block = true;
				}
			}
			
			// If no restrictions, block all
			if ( empty( $blocked_shipping ) && empty( $blocked_payment ) ) {
				$should_block = true;
			}
			
			// If both restrictions set, block if either matches
			if ( ! empty( $blocked_shipping ) && ! empty( $blocked_payment ) ) {
				$block_shipping = false;
				$block_payment = false;
				
				if ( ! empty( $blocked_shipping ) ) {
					$shipping_methods = $order->get_shipping_methods();
					foreach ( $shipping_methods as $method ) {
						$method_id = $method->get_method_id();
						if ( in_array( $method_id, $blocked_shipping, true ) ) {
							$block_shipping = true;
							break;
						}
					}
				}
				
				if ( ! empty( $blocked_payment ) ) {
					$payment_method = $order->get_payment_method();
					if ( in_array( $payment_method, $blocked_payment, true ) ) {
						$block_payment = true;
					}
				}
				
				$should_block = $block_shipping || $block_payment;
			}
			
			if ( $should_block ) {
				$error_message = $this->get_error_message();
				$validation_errors->add(
					'fraud_phone_detected',
					$error_message
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
		// Check if plugin is enabled
		if ( ! $this->is_enabled() ) {
			return;
		}
		
		$fraud_detected = false;
		
		// Check billing phone
		if ( ! empty( $billing_phone ) ) {
			$billing_phone_clean = $this->clean_bangladesh_phone( $billing_phone );
			if ( $billing_phone_clean ) {
				if ( $this->is_fraud_phone( $billing_phone_clean ) ) {
					$fraud_detected = true;
				}
			}
		}
		
		// Check shipping phone (if different from billing)
		if ( ! empty( $shipping_phone ) && $shipping_phone !== $billing_phone ) {
			$shipping_phone_clean = $this->clean_bangladesh_phone( $shipping_phone );
			if ( $shipping_phone_clean ) {
				if ( $this->is_fraud_phone( $shipping_phone_clean ) ) {
					$fraud_detected = true;
				}
			}
		}
		
		// If fraud detected, check shipping and payment methods
		if ( $fraud_detected ) {
			$should_block = $this->should_block_order();
			
			if ( $should_block ) {
				$error_message = $this->get_error_message();
				
				if ( $errors instanceof \WP_Error ) {
					$errors->add( 'fraud_phone_detected', $error_message );
				} else {
					wc_add_notice( $error_message, 'error' );
				}
			}
		}
	}
	
	/**
	 * Check if order should be blocked based on shipping and payment methods
	 *
	 * @return bool True if should block, false otherwise
	 */
	private function should_block_order() {
		$blocked_shipping = $this->get_blocked_shipping_methods();
		$blocked_payment = $this->get_blocked_payment_gateways();
		
		// If no restrictions set, block all orders
		if ( empty( $blocked_shipping ) && empty( $blocked_payment ) ) {
			return true;
		}
		
		$block_shipping = false;
		$block_payment = false;
		
		// Check shipping method
		if ( ! empty( $blocked_shipping ) ) {
			$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', array() );
			foreach ( $chosen_shipping_methods as $method ) {
				// Extract method ID (before colon if present)
				$method_id = explode( ':', $method )[0];
				if ( in_array( $method_id, $blocked_shipping, true ) || in_array( $method, $blocked_shipping, true ) ) {
					$block_shipping = true;
					break;
				}
			}
		}
		
		// Check payment method
		if ( ! empty( $blocked_payment ) ) {
			$chosen_payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( $_POST['payment_method'] ) : '';
			if ( empty( $chosen_payment_method ) && WC()->session ) {
				$chosen_payment_method = WC()->session->get( 'chosen_payment_method', '' );
			}
			if ( in_array( $chosen_payment_method, $blocked_payment, true ) ) {
				$block_payment = true;
			}
		}
		
		// Block if either shipping or payment is in blocked list
		// If only shipping restrictions set, only check shipping
		// If only payment restrictions set, only check payment
		// If both set, block if either matches
		if ( ! empty( $blocked_shipping ) && ! empty( $blocked_payment ) ) {
			return $block_shipping || $block_payment;
		} elseif ( ! empty( $blocked_shipping ) ) {
			return $block_shipping;
		} elseif ( ! empty( $blocked_payment ) ) {
			return $block_payment;
		}
		
		return false;
	}
	
	/**
	 * Check phone numbers for Store API (returns true if fraud detected and should block)
	 *
	 * @param string $billing_phone Billing phone number
	 * @param string $shipping_phone Shipping phone number
	 * @return bool True if fraud detected and should block
	 */
	private function check_phone_numbers_store_api( $billing_phone, $shipping_phone ) {
		// Check if plugin is enabled
		if ( ! $this->is_enabled() ) {
			return false;
		}
		
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
		
		// If fraud detected, check if should block based on shipping/payment
		if ( $fraud_detected ) {
			return $this->should_block_order_store_api();
		}
		
		return false;
	}
	
	/**
	 * Check if order should be blocked for Store API
	 *
	 * @return bool True if should block, false otherwise
	 */
	private function should_block_order_store_api() {
		$blocked_shipping = $this->get_blocked_shipping_methods();
		$blocked_payment = $this->get_blocked_payment_gateways();
		
		// If no restrictions set, block all orders
		if ( empty( $blocked_shipping ) && empty( $blocked_payment ) ) {
			return true;
		}
		
		$block_shipping = false;
		$block_payment = false;
		
		// Check shipping method from session
		if ( ! empty( $blocked_shipping ) && WC()->session ) {
			$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', array() );
			foreach ( $chosen_shipping_methods as $method ) {
				$method_id = explode( ':', $method )[0];
				if ( in_array( $method_id, $blocked_shipping, true ) || in_array( $method, $blocked_shipping, true ) ) {
					$block_shipping = true;
					break;
				}
			}
		}
		
		// Check payment method from session
		if ( ! empty( $blocked_payment ) && WC()->session ) {
			$chosen_payment_method = WC()->session->get( 'chosen_payment_method', '' );
			if ( in_array( $chosen_payment_method, $blocked_payment, true ) ) {
				$block_payment = true;
			}
		}
		
		// Block if either shipping or payment is in blocked list
		if ( ! empty( $blocked_shipping ) && ! empty( $blocked_payment ) ) {
			return $block_shipping || $block_payment;
		} elseif ( ! empty( $blocked_shipping ) ) {
			return $block_shipping;
		} elseif ( ! empty( $blocked_payment ) ) {
			return $block_payment;
		}
		
		return false;
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
		
		// Build API URL with phone and site URL
		$site_url = home_url();
		$api_url = add_query_arg( 
			array(
				'phone' => urlencode( $phone ),
				'site_url' => urlencode( $site_url ),
			),
			FOB_BD_FRAUD_API_URL 
		);
		
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

