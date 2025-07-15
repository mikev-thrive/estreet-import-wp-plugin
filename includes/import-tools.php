<?php 

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

//disable and log all emails
add_filter( 'wp_mail', function( $args ) {

	// Optionally Log To WooCommerce
	if( function_exists( 'wc_get_logger' ) ) {
		$logger = wc_get_logger();
		$logger->info( print_r( $args, true ), [ 'source' => 'wp_mail' ] );
	}

	// Disable The Email
	if( isset( $args['message'] ) ) {
		$args['message'] = '';
	}

	// Return
	return $args;

} );

// Force-add the Custom Fields metabox
add_action('add_meta_boxes', function () {
    add_meta_box(
        'postcustom',
        __('Custom Fields'),
        'post_custom_meta_box',
        'shop_order',
        'normal',
        'default'
    );
}, 100); // Priority > 10 to override WC

// Allow protected meta keys to be shown (optional, shows fields with _prefix)
add_filter('is_protected_meta', function ($protected, $meta_key, $meta_type) {
    if ($meta_type === 'post') {
        return false; // Show everything
    }
    return $protected;
}, 10, 3);

// Force it to be always visible, even if screen options are missing
add_filter('default_hidden_meta_boxes', function ($hidden, $screen) {
    if ($screen->id === 'shop_order') {
        return array_diff($hidden, ['postcustom']);
    }
    return $hidden;
}, 10, 2);

//disable emails on new ordres, and stock for import (REMOVE THIS FOR PRODUCTION)
add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );
