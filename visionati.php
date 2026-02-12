<?php
/**
 * Plugin Name: Visionati
 * Plugin URI:  https://visionati.com
 * Description: AI-powered image alt text, captions, and product descriptions using multiple AI models.
 * Version:     1.0.0
 * Author:      Visionati
 * Author URI:  https://visionati.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: visionati
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VISIONATI_VERSION', '1.0.0' );
define( 'VISIONATI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VISIONATI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VISIONATI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'VISIONATI_API_BASE', 'https://api.visionati.com' );

require_once VISIONATI_PLUGIN_DIR . 'includes/class-visionati-api.php';
require_once VISIONATI_PLUGIN_DIR . 'includes/class-visionati-admin.php';
require_once VISIONATI_PLUGIN_DIR . 'includes/class-visionati-media.php';

/**
 * Load WooCommerce integration after all plugins are loaded,
 * so we can reliably check for WooCommerce.
 */
function visionati_load_woo_integration() {
	if ( class_exists( 'WooCommerce' ) ) {
		require_once VISIONATI_PLUGIN_DIR . 'includes/class-visionati-woo.php';
		new Visionati_Woo();
	}
}
add_action( 'plugins_loaded', 'visionati_load_woo_integration' );

/**
 * Initialize the plugin.
 */
function visionati_init() {
	load_plugin_textdomain( 'visionati', false, dirname( VISIONATI_PLUGIN_BASENAME ) . '/languages' );

	if ( is_admin() ) {
		new Visionati_Admin();
	}
	new Visionati_Media();
}
add_action( 'init', 'visionati_init' );

/**
 * Add Settings link to the plugins list page.
 *
 * @param array $links Existing plugin action links.
 * @return array Modified plugin action links.
 */
function visionati_plugin_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=visionati' ) ),
		esc_html__( 'Settings', 'visionati' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . VISIONATI_PLUGIN_BASENAME, 'visionati_plugin_action_links' );

/**
 * Show an admin notice if no API key is configured.
 */
function visionati_admin_notice_no_key() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$api_key = get_option( 'visionati_api_key', '' );
	if ( ! empty( $api_key ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( $screen && 'settings_page_visionati' === $screen->id ) {
		return;
	}

	printf(
		'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
		esc_html__( 'Visionati: No API key configured.', 'visionati' ),
		esc_url( admin_url( 'options-general.php?page=visionati' ) ),
		esc_html__( 'Add your API key in Settings.', 'visionati' )
	);
}
add_action( 'admin_notices', 'visionati_admin_notice_no_key' );

/**
 * Plugin activation hook.
 */
function visionati_activate() {
	$defaults = array(
		'visionati_api_key'            => '',
		'visionati_backends'           => 'gemini',
		'visionati_role_alt_text'      => 'alttext',
		'visionati_role_caption'       => 'caption',
		'visionati_role_woocommerce'   => 'ecommerce',
		'visionati_role_description'   => 'general',
		'visionati_backend_alt_text'   => '',
		'visionati_backend_caption'    => '',
		'visionati_backend_description' => '',
		'visionati_backend_woocommerce' => '',
		'visionati_prompt_alt_text'    => '',
		'visionati_prompt_caption'     => '',
		'visionati_prompt_woocommerce' => '',
		'visionati_prompt_description' => '',
		'visionati_language'           => 'English',
		'visionati_auto_generate_fields' => array(),
		'visionati_overwrite_fields'   => array(),
		'visionati_woo_include_context' => true,
		'visionati_debug'              => false,
	);

	foreach ( $defaults as $option => $value ) {
		if ( false === get_option( $option ) ) {
			add_option( $option, $value );
		}
	}
}
register_activation_hook( __FILE__, 'visionati_activate' );

/**
 * Plugin deactivation hook.
 */
function visionati_deactivate() {
	// Clean up any lingering bulk queue transients with a single query
	// instead of loading every user ID in the system.
	// Options are preserved so users don't lose settings.
	global $wpdb;
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_visionati\_bulk\_queue\_%'
		    OR option_name LIKE '\_transient\_timeout\_visionati\_bulk\_queue\_%'
		    OR option_name LIKE '\_transient\_visionati\_woo\_bulk\_queue\_%'
		    OR option_name LIKE '\_transient\_timeout\_visionati\_woo\_bulk\_queue\_%'"
	);
}
register_deactivation_hook( __FILE__, 'visionati_deactivate' );