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

//START TEMP FUNCTIONS
//
//Custom Rest endpoint
add_action('rest_api_init', function () {
    error_log('Registering custom REST endpoint /wp-json/custom/v1/set-order-date');
    
    $result = register_rest_route('custom/v1', '/set-order-date', [
        'methods'             => 'POST',
        'callback'            => 'custom_set_order_post_date',
        'permission_callback' => function ($request) {
            // Get authorization header
            $auth_header = $request->get_header('authorization');
            
            if (!$auth_header) {
                error_log('Custom endpoint: No authorization header found');
                return false;
            }
            
            // Parse Basic Auth
            if (strpos($auth_header, 'Basic ') !== 0) {
                error_log('Custom endpoint: Authorization header is not Basic auth');
                return false;
            }
            
            $credentials = base64_decode(substr($auth_header, 6));
            $parts = explode(':', $credentials, 2);
            
            if (count($parts) !== 2) {
                error_log('Custom endpoint: Invalid credentials format');
                return false;
            }
            
            list($consumer_key, $consumer_secret) = $parts;
            
            // Validate against WooCommerce API keys
            global $wpdb;
            
            $key_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
                wc_api_hash($consumer_key)
            ));
            
            if (!$key_row) {
                error_log('Custom endpoint: API key not found in database');
                return false;
            }
            
            // Verify the secret
            if (!hash_equals($key_row->consumer_secret, $consumer_secret)) {
                error_log('Custom endpoint: API secret mismatch');
                return false;
            }
            
            // Check if key has read/write permissions
            $has_permission = in_array($key_row->permissions, ['read_write', 'write']);
            error_log('Custom endpoint: Permission check result: ' . ($has_permission ? 'granted' : 'denied'));
            
            return $has_permission;
        },
        'args' => [
            'order_id' => [
                'required' => true,
                'type'     => 'integer',
            ],
            'date_created' => [
                'required' => true,
                'type'     => 'string',
            ],
        ],
    ]);
    
    if ($result) {
        error_log('Successfully registered custom REST endpoint');
    } else {
        error_log('Failed to register custom REST endpoint');
    }
    
    // Also register a test endpoint to verify registration is working
    register_rest_route('custom/v1', '/test', [
        'methods'             => 'GET',
        'callback'            => function() {
            return new WP_REST_Response(['message' => 'Custom endpoint is working'], 200);
        },
        'permission_callback' => '__return_true',
    ]);
    
}, 10); // Lower priority to ensure it runs after WooCommerce is loaded

function custom_set_order_post_date($request) {
    $order_id = $request['order_id'];
    $date     = wc_clean($request['date_created']);
    
    error_log("Custom endpoint called for order $order_id with date: $date");
    
    if (!get_post($order_id)) {
        error_log("Order $order_id not found");
        return new WP_Error('invalid_order', 'Order not found', ['status' => 404]);
    }
    
    // Validate that this is actually a WooCommerce order
    $order = wc_get_order($order_id);
    if (!$order) {
        error_log("Order $order_id is not a valid WooCommerce order");
        return new WP_Error('invalid_order', 'Not a valid WooCommerce order', ['status' => 400]);
    }
    
    try {
        // Parse the date - try different formats
        $date_obj = null;
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i:s.v\Z',
            'Y-m-d',
            'm/d/Y H:i:s A',
            'm/d/Y'
        ];
        
        foreach ($formats as $format) {
            $parsed = DateTime::createFromFormat($format, $date);
            if ($parsed !== false) {
                $date_obj = $parsed;
                break;
            }
        }
        
        if (!$date_obj) {
            // Fallback: try strtotime
            $timestamp = strtotime($date);
            if ($timestamp !== false) {
                $date_obj = new DateTime();
                $date_obj->setTimestamp($timestamp);
            }
        }
        
        if (!$date_obj) {
            error_log("Failed to parse date: $date");
            return new WP_Error('invalid_date', 'Could not parse date format', ['status' => 400]);
        }
        
        $formatted_date = $date_obj->format('Y-m-d H:i:s');
        $gmt_date = get_gmt_from_date($formatted_date);
        
        error_log("Parsed date: $formatted_date, GMT: $gmt_date");
        
        // Method 1: Use WooCommerce order object methods (preferred)
        $order->set_date_created($formatted_date);
        $order->set_date_paid($formatted_date);
        
        // Save the order (this should update caches properly)
        $result = $order->save();
        
        if (is_wp_error($result)) {
            error_log("WooCommerce order save failed: " . $result->get_error_message());
            return new WP_Error('save_failed', 'Failed to save order: ' . $result->get_error_message(), ['status' => 500]);
        }
        
        // Method 2: Also update the post date directly in database as backup
        global $wpdb;
        
        $post_update = $wpdb->update(
            $wpdb->posts,
            [
                'post_date'     => $formatted_date,
                'post_date_gmt' => $gmt_date,
            ],
            ['ID' => $order_id],
            ['%s', '%s'],
            ['%d']
        );
        
        if ($post_update === false) {
            error_log("Failed to update post date for order $order_id");
        }
        
        // Method 3: Force clear ALL caches related to this order
        wp_cache_delete($order_id, 'posts');
        wp_cache_delete($order_id, 'post_meta');
        clean_post_cache($order_id);
        
        // Clear WooCommerce specific caches
        wc_delete_shop_order_transients($order_id);
        
        // Clear object cache
        wp_cache_delete('wc_order_' . $order_id);
        wp_cache_delete($order_id, 'wc_orders');
        
        // Clear any other potential caches
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('woocommerce');
        }
        
        error_log("Successfully updated order $order_id date to $formatted_date");
        
        return new WP_REST_Response([
            'success' => true,
            'order_id' => $order_id,
            'date_created' => $formatted_date,
            'date_created_gmt' => $gmt_date,
            'message' => 'Order date updated successfully'
        ], 200);
        
    } catch (Exception $e) {
        error_log("Exception updating order date: " . $e->getMessage());
        return new WP_Error('update_error', 'Error updating order date: ' . $e->getMessage(), ['status' => 500]);
    }
}