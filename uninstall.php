<?php
/**
 * Visionati Uninstall
 *
 * Removes all plugin options from the database when the plugin is deleted
 * (not just deactivated). This ensures no orphaned data remains.
 *
 * @package Visionati
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// All option keys created by the plugin.
$options = array(
	'visionati_api_key',
	'visionati_backends',
	'visionati_language',
	'visionati_role_alt_text',
	'visionati_role_caption',
	'visionati_role_description',
	'visionati_role_woocommerce',
	'visionati_backend_alt_text',
	'visionati_backend_caption',
	'visionati_backend_description',
	'visionati_backend_woocommerce',
	'visionati_prompt_alt_text',
	'visionati_prompt_caption',
	'visionati_prompt_description',
	'visionati_prompt_woocommerce',
	'visionati_auto_generate_fields',
	'visionati_overwrite_fields',
	'visionati_woo_include_context',
	'visionati_debug',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clean up any lingering bulk queue transients.
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_visionati\_%'
	    OR option_name LIKE '\_transient\_timeout\_visionati\_%'"
);