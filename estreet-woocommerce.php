<?php
/**
 * Plugin Name: Estreet Plastics WooCommerce Utility Functions
 * Description: Utility functions for Estreet Plastics WooCommerce.
 * Author: Thrive Agency
 * Version: 1.0.2b
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}


// Check if WooCommerce is active
include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
if ( !is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . __('Estreet WooCommerce Utility Functions requires WooCommerce to be installed and activated.', 'estreet-woocommerce') . '</p></div>';
    });
    return;
}

// Check if ACF is active
if (!function_exists('get_field')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . __('Estreet WooCommerce Utility Functions requires Advanced Custom Fields (ACF) to be installed and activated.', 'estreet-woocommerce') . '</p></div>';
    });
    return;
}   

// load needed files
require_once plugin_dir_path(__FILE__) . 'includes/woo-orders.php';





