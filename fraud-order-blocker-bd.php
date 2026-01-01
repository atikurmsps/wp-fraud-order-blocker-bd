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
define( 'FOB_BD_FRAUD_API_URL', 'https://steadfastfraud.appcloud.uk/public.php' );

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
			// Verify user capabilities
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'fraud-order-blocker-bd' ) );
			}
			
			// Sanitize and save enabled option
			$enabled = isset( $_POST['fob_bd_enabled'] ) ? 'yes' : 'no';
			update_option( 'fob_bd_enabled', $enabled );
			
			// Sanitize and save blocked shipping methods
			if ( isset( $_POST['fob_bd_blocked_shipping_methods'] ) && is_array( $_POST['fob_bd_blocked_shipping_methods'] ) ) {
				$shipping_methods = array_map( 'sanitize_text_field', wp_unslash( $_POST['fob_bd_blocked_shipping_methods'] ) );
				update_option( 'fob_bd_blocked_shipping_methods', $shipping_methods );
			} else {
				update_option( 'fob_bd_blocked_shipping_methods', array() );
			}
			
			// Sanitize and save blocked payment gateways
			if ( isset( $_POST['fob_bd_blocked_payment_gateways'] ) && is_array( $_POST['fob_bd_blocked_payment_gateways'] ) ) {
				$payment_gateways = array_map( 'sanitize_text_field', wp_unslash( $_POST['fob_bd_blocked_payment_gateways'] ) );
				update_option( 'fob_bd_blocked_payment_gateways', $payment_gateways );
			} else {
				update_option( 'fob_bd_blocked_payment_gateways', array() );
			}
			
			// Sanitize and save error message
			if ( isset( $_POST['fob_bd_error_message'] ) ) {
				$error_message = sanitize_textarea_field( wp_unslash( $_POST['fob_bd_error_message'] ) );
				update_option( 'fob_bd_error_message', $error_message );
			} else {
				update_option( 'fob_bd_error_message', '' );
			}
			
			// Clear cache when settings are saved
			$this->clear_fraud_cache();
			
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully!', 'fraud-order-blocker-bd' ) . '</p></div>';
		}
		
		$enabled = $this->is_enabled();
		$blocked_shipping = $this->get_blocked_shipping_methods();
		$blocked_payment = $this->get_blocked_payment_gateways();
		$error_message = get_option( 'fob_bd_error_message', '' );
		$shipping_methods = $this->get_shipping_methods();
		$payment_gateways = $this->get_payment_gateways();
		
		?>
		<style>
			.fob-bd-settings-wrapper {
				display: flex;
				gap: 20px;
				margin-top: 20px;
			}
			.fob-bd-settings-main {
				flex: 1;
				min-width: 0;
				position: relative;
				z-index: 1;
			}
			.fob-bd-settings-sidebar {
				width: 400px;
				flex-shrink: 0;
				position: relative;
				z-index: 2;
			}
			.fob-bd-sidebar-box {
				background: #fff;
				border: 1px solid #ccd0d4;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
				margin-bottom: 20px;
				position: relative;
				z-index: 1;
			}
			/* Ensure select dropdowns appear above sidebar */
			.select2-container {
				z-index: 9999 !important;
			}
			.select2-dropdown {
				z-index: 10000 !important;
			}
			.selectWoo-dropdown,
			.selectWoo-menu {
				z-index: 10000 !important;
			}
			.fob-bd-sidebar-box h3 {
				margin-top: 0;
				padding-bottom: 10px;
				border-bottom: 1px solid #eee;
			}
			.fob-bd-sidebar-box ul {
				margin: 10px 0;
				padding-left: 20px;
			}
			.fob-bd-sidebar-box ul li {
				margin-bottom: 8px;
			}
			.fob-bd-sidebar-box code {
				background: #f0f0f1;
				padding: 2px 6px;
				border-radius: 3px;
				font-size: 13px;
			}
			/* Tablet styles */
			@media (min-width: 783px) and (max-width: 1024px) {
				.fob-bd-settings-wrapper {
					gap: 15px;
				}
				.fob-bd-settings-sidebar {
					width: 350px;
				}
				.fob-bd-sidebar-box {
					padding: 15px;
				}
				.fob-bd-sidebar-box h3 {
					font-size: 14px;
				}
				.fob-bd-sidebar-box ul,
				.fob-bd-sidebar-box ol {
					padding-left: 18px;
				}
				.fob-bd-sidebar-box ul li,
				.fob-bd-sidebar-box ol li {
					margin-bottom: 6px;
					font-size: 13px;
				}
			}
			/* Mobile styles */
			@media (max-width: 782px) {
				.fob-bd-settings-wrapper {
					flex-direction: column;
					gap: 15px;
				}
				.fob-bd-settings-sidebar {
					width: 100%;
				}
				.fob-bd-sidebar-box {
					padding: 15px;
				}
			}
		</style>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<div class="fob-bd-settings-wrapper">
				<div class="fob-bd-settings-main">
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
										<select name="fob_bd_blocked_shipping_methods[]" id="fob_bd_blocked_shipping_methods" multiple="multiple" class="wc-enhanced-select" style="width: 100%; max-width: 400px;">
											<?php foreach ( $shipping_methods as $method_id => $method_name ) : ?>
												<option value="<?php echo esc_attr( $method_id ); ?>" <?php selected( in_array( $method_id, $blocked_shipping, true ), true ); ?>>
													<?php echo esc_html( $method_name ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<p class="description">
											<?php esc_html_e( 'Select shipping methods to block for fraud customers. Leave empty to block all shipping methods.', 'fraud-order-blocker-bd' ); ?>
										</p>
									</td>
								</tr>
								
								<tr>
									<th scope="row">
										<label for="fob_bd_blocked_payment_gateways"><?php esc_html_e( 'Block Payment Gateways', 'fraud-order-blocker-bd' ); ?></label>
									</th>
									<td>
										<select name="fob_bd_blocked_payment_gateways[]" id="fob_bd_blocked_payment_gateways" multiple="multiple" class="wc-enhanced-select" style="width: 100%; max-width: 400px;">
											<?php foreach ( $payment_gateways as $gateway_id => $gateway_name ) : ?>
												<option value="<?php echo esc_attr( $gateway_id ); ?>" <?php selected( in_array( $gateway_id, $blocked_payment, true ), true ); ?>>
													<?php echo esc_html( $gateway_name ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<p class="description">
											<?php esc_html_e( 'Select payment gateways to block for fraud customers. Leave empty to block all payment gateways.', 'fraud-order-blocker-bd' ); ?>
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
									</td>
								</tr>
							</tbody>
						</table>
						
						<?php submit_button( __( 'Save Settings', 'fraud-order-blocker-bd' ), 'primary', 'fob_bd_save_settings' ); ?>
					</form>
					
					<div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
						<h3><?php esc_html_e( 'Cache Management', 'fraud-order-blocker-bd' ); ?></h3>
						<p class="description">
							<?php esc_html_e( 'Clear the fraud check cache if you need to re-check phone numbers immediately. Cache is automatically cleared when settings are saved.', 'fraud-order-blocker-bd' ); ?>
						</p>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=fob-bd-settings&fob_bd_clear_cache=1' ), 'fob_bd_clear_cache' ) ); ?>" class="button">
							<?php esc_html_e( 'Clear Cache', 'fraud-order-blocker-bd' ); ?>
						</a>
					</div>
				</div>
				
				<div class="fob-bd-settings-sidebar">
					<div class="fob-bd-sidebar-box">
						<h3><?php esc_html_e( 'How It Works', 'fraud-order-blocker-bd' ); ?></h3>
						<p><?php esc_html_e( 'This plugin automatically checks customer information during checkout against a fraud database.', 'fraud-order-blocker-bd' ); ?></p>
						<ol>
							<li><?php esc_html_e( 'Customer enters information during checkout (phone, name, address, products)', 'fraud-order-blocker-bd' ); ?></li>
							<li><?php esc_html_e( 'Plugin validates phone, name, address, products format', 'fraud-order-blocker-bd' ); ?></li>
							<li><?php esc_html_e( 'Customer data (phone number, name, address, product names) is sent to fraud database via API', 'fraud-order-blocker-bd' ); ?></li>
							<li><?php esc_html_e( 'If flagged as fraud, order is blocked with error message', 'fraud-order-blocker-bd' ); ?></li>
						</ol>
					</div>
					
					<div class="fob-bd-sidebar-box">
						<h3><?php esc_html_e( 'Settings Guide', 'fraud-order-blocker-bd' ); ?></h3>
						<ul>
							<li>
								<strong><?php esc_html_e( 'Enable Fraud Detection:', 'fraud-order-blocker-bd' ); ?></strong><br>
								<?php esc_html_e( 'Turn on/off the fraud detection feature.', 'fraud-order-blocker-bd' ); ?>
							</li>
							<li>
								<strong><?php esc_html_e( 'Block Shipping Methods:', 'fraud-order-blocker-bd' ); ?></strong><br>
								<?php esc_html_e( 'Select specific shipping methods to block. If empty, all methods are blocked for fraud customers.', 'fraud-order-blocker-bd' ); ?>
							</li>
							<li>
								<strong><?php esc_html_e( 'Block Payment Gateways:', 'fraud-order-blocker-bd' ); ?></strong><br>
								<?php esc_html_e( 'Select specific payment gateways to block. If empty, all gateways are blocked for fraud customers.', 'fraud-order-blocker-bd' ); ?>
							</li>
							<li>
								<strong><?php esc_html_e( 'Error Message:', 'fraud-order-blocker-bd' ); ?></strong><br>
								<?php esc_html_e( 'Customize the error message shown to customers. Leave empty to use default Bengali message.', 'fraud-order-blocker-bd' ); ?>
							</li>
						</ul>
					</div>
					
					<div class="fob-bd-sidebar-box">
						<h3><?php esc_html_e( 'Important Notes', 'fraud-order-blocker-bd' ); ?></h3>
						<ul>
							<li><?php esc_html_e( 'If no shipping methods are selected, all orders with fraud numbers are blocked.', 'fraud-order-blocker-bd' ); ?></li>
							<li><?php esc_html_e( 'If no payment gateways are selected, all orders with fraud numbers are blocked.', 'fraud-order-blocker-bd' ); ?></li>
							<li><?php esc_html_e( 'API responses are cached for 5 minutes to improve performance.', 'fraud-order-blocker-bd' ); ?></li>
							<li><?php esc_html_e( 'If the API is unavailable, orders are not blocked to prevent store disruption.', 'fraud-order-blocker-bd' ); ?></li>
						</ul>
					</div>
				</div>
			</div>
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
			$message = __( 'আপনার অর্ডারটি আমরা ক্যাশ অন ডেলিভারিতে গ্রহণ করতে পারছি না । দয়া করে অন্য পেমেন্ট মেথড সিলেক্ট করুন অথবা কল করুন আমাদের সাপোর্ট নাম্বারে । ধন্যবাদ ।', 'fraud-order-blocker-bd' );
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
		$billing_phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
		$shipping_phone = isset( $_POST['shipping_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_phone'] ) ) : '';
		
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
		$fraud_detected = $this->check_phone_numbers_store_api( $billing_phone, $shipping_phone, $order );
		
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
		
		// Get customer data from order
		$customer_data = $this->get_customer_data_from_order( $order );
		
		// Check billing phone
		if ( ! empty( $billing_phone ) ) {
			$billing_phone_clean = $this->clean_bangladesh_phone( $billing_phone );
			if ( $billing_phone_clean && $this->is_fraud_phone( 
				$billing_phone_clean,
				$customer_data['name'],
				$customer_data['address'],
				$customer_data['product_name'],
				$customer_data['amount']
			) ) {
				$fraud_detected = true;
			}
		}
		
		// Check shipping phone (if different from billing)
		if ( ! empty( $shipping_phone ) && $shipping_phone !== $billing_phone ) {
			$shipping_phone_clean = $this->clean_bangladesh_phone( $shipping_phone );
			if ( $shipping_phone_clean && $this->is_fraud_phone( 
				$shipping_phone_clean,
				$customer_data['name'],
				$customer_data['address'],
				$customer_data['product_name'],
				$customer_data['amount']
			) ) {
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
		
		// Get customer data from POST or session
		$customer_data = $this->get_customer_data_from_checkout();
		
		$fraud_detected = false;
		
		// Check billing phone
		if ( ! empty( $billing_phone ) ) {
			$billing_phone_clean = $this->clean_bangladesh_phone( $billing_phone );
			if ( $billing_phone_clean ) {
				$is_fraud = $this->is_fraud_phone( 
					$billing_phone_clean,
					$customer_data['name'],
					$customer_data['address'],
					$customer_data['product_name'],
					$customer_data['amount']
				);
				if ( $is_fraud ) {
					$fraud_detected = true;
				}
			}
		}
		
		// Check shipping phone (if different from billing)
		if ( ! empty( $shipping_phone ) && $shipping_phone !== $billing_phone ) {
			$shipping_phone_clean = $this->clean_bangladesh_phone( $shipping_phone );
			if ( $shipping_phone_clean ) {
				$is_fraud = $this->is_fraud_phone( 
					$shipping_phone_clean,
					$customer_data['name'],
					$customer_data['address'],
					$customer_data['product_name'],
					$customer_data['amount']
				);
				if ( $is_fraud ) {
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
			$chosen_payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
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
	 * @param WC_Order|null $order Order object (optional)
	 * @return bool True if fraud detected and should block
	 */
	private function check_phone_numbers_store_api( $billing_phone, $shipping_phone, $order = null ) {
		// Check if plugin is enabled
		if ( ! $this->is_enabled() ) {
			return false;
		}
		
		// Get customer data from order
		$customer_data = $this->get_customer_data_from_order( $order );
		
		$fraud_detected = false;
		
		// Check billing phone
		if ( ! empty( $billing_phone ) ) {
			$billing_phone_clean = $this->clean_bangladesh_phone( $billing_phone );
			if ( $billing_phone_clean && $this->is_fraud_phone( 
				$billing_phone_clean,
				$customer_data['name'],
				$customer_data['address'],
				$customer_data['product_name'],
				$customer_data['amount']
			) ) {
				$fraud_detected = true;
			}
		}
		
		// Check shipping phone (if different from billing)
		if ( ! empty( $shipping_phone ) && $shipping_phone !== $billing_phone ) {
			$shipping_phone_clean = $this->clean_bangladesh_phone( $shipping_phone );
			if ( $shipping_phone_clean && $this->is_fraud_phone( 
				$shipping_phone_clean,
				$customer_data['name'],
				$customer_data['address'],
				$customer_data['product_name'],
				$customer_data['amount']
			) ) {
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
	 * Ensures phone number is exactly 11 digits starting with 01xx
	 * Removes country code (+880 or 880) if present
	 * 
	 * @param string $phone Phone number
	 * @return string|false Cleaned phone number (11 digits) or false if invalid
	 */
	private function clean_bangladesh_phone( $phone ) {
		if ( empty( $phone ) ) {
			return false;
		}
		
		// Remove all non-digit characters (spaces, dashes, plus signs, etc.)
		$phone = preg_replace( '/[^0-9]/', '', $phone );
		
		// Remove country code if present (+880 or 880)
		// Bangladesh country code is 880, so if number starts with 880 and has 13 digits total, remove it
		if ( preg_match( '/^880(\d{10})$/', $phone, $matches ) ) {
			$phone = '0' . $matches[1]; // Add leading 0 to make it 11 digits
		}
		
		// Validate: Must be exactly 11 digits starting with 01
		// Format: 01xxxxxxxxx (where x can be 0-9)
		if ( preg_match( '/^01\d{9}$/', $phone ) ) {
			// Double check it's exactly 11 digits
			if ( strlen( $phone ) === 11 ) {
				return $phone;
			}
		}
		
		return false;
	}
	
	/**
	 * Check if phone number is fraudulent
	 *
	 * @param string $phone Cleaned phone number (11 digits)
	 * @param string $name Customer name (optional)
	 * @param string $address Customer address (optional)
	 * @param string $product_name Product name (optional)
	 * @param float $amount Order total amount (optional)
	 * @return bool True if fraud, false otherwise
	 */
	private function is_fraud_phone( $phone, $name = '', $address = '', $product_name = '', $amount = 0 ) {
		if ( empty( $phone ) ) {
			return false;
		}
		
		// Check cache first (cache for 2 minutes to avoid stale data)
		// Cache based on phone number only - same phone = same fraud status regardless of other data
		$cache_key = 'fob_bd_fraud_' . md5( $phone );
		$cached_result = get_transient( $cache_key );
		if ( false !== $cached_result ) {
			return (bool) $cached_result;
		}
		
		// Get site URL without protocol and www
		$site_url = $this->get_site_url_clean();
		
		// Build API URL with query parameters
		$api_params = array(
			'phone'    => $phone,
			'type'     => 'wp',
			'siteurl'  => $site_url,
		);
		
		// Add customer name if provided
		if ( ! empty( $name ) ) {
			$api_params['name'] = sanitize_text_field( $name );
		}
		
		// Add customer address if provided
		if ( ! empty( $address ) ) {
			$api_params['address'] = sanitize_textarea_field( $address );
		}
		
		// Add product name if provided
		if ( ! empty( $product_name ) ) {
			$api_params['product_name'] = sanitize_text_field( $product_name );
		}
		
		// Add order total amount if provided
		if ( ! empty( $amount ) && is_numeric( $amount ) ) {
			$api_params['amount'] = number_format( floatval( $amount ), 2, '.', '' );
		}
		
		// Allow filtering of API parameters
		$api_params = apply_filters( 'fob_bd_api_params', $api_params, $phone );
		
		// URL encode all parameters
		$api_params_encoded = array_map( 'urlencode', $api_params );
		
		// Build API URL
		$api_url = add_query_arg( $api_params_encoded, FOB_BD_FRAUD_API_URL );
		
		// Allow filtering of API URL
		$api_url = apply_filters( 'fob_bd_api_url', $api_url, $api_params );
		
		// Make API request using GET method
		$timeout = apply_filters( 'fob_bd_api_timeout', 10 );
		$response = wp_remote_get( $api_url, array(
			'timeout'     => $timeout,
			'sslverify'  => apply_filters( 'fob_bd_api_sslverify', true ),
			'user-agent' => 'Fraud-Order-Blocker-BD/' . FOB_BD_VERSION,
		) );
		
		// Check for errors
		if ( is_wp_error( $response ) ) {
			// Don't block order if API is down - allow filter to override
			$default_on_error = apply_filters( 'fob_bd_block_on_api_error', false );
			// Don't cache error responses
			return $default_on_error;
		}
		
		// Check HTTP response code
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			// Don't cache error responses
			return false;
		}
		
		// Get response body
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		
		// Check if JSON decode failed
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Don't cache error responses
			return false;
		}
		
		// Allow filtering of API response
		$data = apply_filters( 'fob_bd_api_response', $data, $phone );
		
		// Check if response is valid and fraud is detected
		$is_fraud = false;
		if ( isset( $data['fraud'] ) && true === $data['fraud'] ) {
			$is_fraud = true;
		}
		
		// Only cache successful API responses (not errors)
		
		// Cache the result for 2 minutes (reduced from 5 to prevent stale fraud data)
		set_transient( $cache_key, $is_fraud ? 1 : 0, 2 * MINUTE_IN_SECONDS );
		
		// Allow filtering of final result
		return apply_filters( 'fob_bd_is_fraud_phone', $is_fraud, $phone, $data );
	}
	
	/**
	 * Get customer data from checkout (POST data)
	 *
	 * @return array Customer data (name, address, product_name)
	 */
	private function get_customer_data_from_checkout() {
		$data = array(
			'name'         => '',
			'address'      => '',
			'product_name' => '',
			'amount'       => 0,
		);
		
		// Get customer name
		if ( isset( $_POST['billing_first_name'] ) && isset( $_POST['billing_last_name'] ) ) {
			$first_name = sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) );
			$last_name = sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) );
			$data['name'] = trim( $first_name . ' ' . $last_name );
		} elseif ( isset( $_POST['billing_first_name'] ) ) {
			$data['name'] = sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) );
		}
		
		// Get customer address
		$address_parts = array();
		if ( isset( $_POST['billing_address_1'] ) ) {
			$address_parts[] = sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ) );
		}
		if ( isset( $_POST['billing_address_2'] ) ) {
			$address_parts[] = sanitize_text_field( wp_unslash( $_POST['billing_address_2'] ) );
		}
		if ( isset( $_POST['billing_city'] ) ) {
			$address_parts[] = sanitize_text_field( wp_unslash( $_POST['billing_city'] ) );
		}
		if ( isset( $_POST['billing_state'] ) ) {
			$address_parts[] = sanitize_text_field( wp_unslash( $_POST['billing_state'] ) );
		}
		if ( isset( $_POST['billing_postcode'] ) ) {
			$address_parts[] = sanitize_text_field( wp_unslash( $_POST['billing_postcode'] ) );
		}
		if ( isset( $_POST['billing_country'] ) ) {
			$address_parts[] = sanitize_text_field( wp_unslash( $_POST['billing_country'] ) );
		}
		$data['address'] = implode( ', ', array_filter( $address_parts ) );
		
		// Get product name(s) from cart
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$product_names = array();
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( isset( $cart_item['data'] ) && is_a( $cart_item['data'], 'WC_Product' ) ) {
					$product_names[] = $cart_item['data']->get_name();
				}
			}
			$data['product_name'] = implode( ', ', $product_names );
			
			// Get cart total amount
			$data['amount'] = WC()->cart->get_total( 'edit' );
		}
		
		return $data;
	}
	
	/**
	 * Get customer data from order object
	 *
	 * @param WC_Order|null $order Order object
	 * @return array Customer data (name, address, product_name)
	 */
	private function get_customer_data_from_order( $order = null ) {
		$data = array(
			'name'         => '',
			'address'      => '',
			'product_name' => '',
			'amount'       => 0,
		);
		
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return $data;
		}
		
		// Get customer name
		$first_name = $order->get_billing_first_name();
		$last_name = $order->get_billing_last_name();
		if ( ! empty( $first_name ) || ! empty( $last_name ) ) {
			$data['name'] = trim( $first_name . ' ' . $last_name );
		}
		
		// Get customer address
		$address_parts = array();
		$address_1 = $order->get_billing_address_1();
		$address_2 = $order->get_billing_address_2();
		$city = $order->get_billing_city();
		$state = $order->get_billing_state();
		$postcode = $order->get_billing_postcode();
		$country = $order->get_billing_country();
		
		if ( ! empty( $address_1 ) ) {
			$address_parts[] = $address_1;
		}
		if ( ! empty( $address_2 ) ) {
			$address_parts[] = $address_2;
		}
		if ( ! empty( $city ) ) {
			$address_parts[] = $city;
		}
		if ( ! empty( $state ) ) {
			$address_parts[] = $state;
		}
		if ( ! empty( $postcode ) ) {
			$address_parts[] = $postcode;
		}
		if ( ! empty( $country ) ) {
			$address_parts[] = $country;
		}
		$data['address'] = implode( ', ', array_filter( $address_parts ) );
		
		// Get product name(s) from order
		$product_names = array();
		foreach ( $order->get_items() as $item ) {
			if ( is_a( $item, 'WC_Order_Item_Product' ) ) {
				$product = $item->get_product();
				if ( $product ) {
					$product_names[] = $product->get_name();
				}
			}
		}
		$data['product_name'] = implode( ', ', $product_names );
		
		// Get order total amount
		$data['amount'] = $order->get_total();
		
		return $data;
	}
	
	/**
	 * Get clean site URL without protocol and www
	 *
	 * @return string Clean site URL
	 */
	private function get_site_url_clean() {
		$site_url = home_url();
		
		// Remove protocol (http:// or https://)
		$site_url = preg_replace( '#^https?://#', '', $site_url );
		
		// Remove www.
		$site_url = preg_replace( '#^www\.#', '', $site_url );
		
		// Remove trailing slash
		$site_url = rtrim( $site_url, '/' );
		
		return $site_url;
	}
	
	/**
	 * Clear fraud check cache
	 *
	 * @return void
	 */
	private function clear_fraud_cache() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_fob_bd_fraud_' ) . '%'
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_timeout_fob_bd_fraud_' ) . '%'
			)
		);
	}
}

// Initialize plugin
Fraud_Order_Blocker_BD::get_instance();

