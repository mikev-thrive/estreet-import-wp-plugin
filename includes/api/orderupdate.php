<?php 
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Orders API
 *
 * @package EStreet_Import_WP_Plugin
 */


// Hook into REST API init with higher priority and check if WooCommerce is active
add_action('rest_api_init', 'register_setorderdate_route', 10);

function register_setorderdate_route() {
  
  // Check if WooCommerce is active
  if (!class_exists('WooCommerce')) {
    error_log('ERROR: WooCommerce is not active or loaded');
    return;
  }
  
  // Define the arguments for the set order date endpoint
  $order_date_args = [
    'order_id' => [
      'required' => true,
      'validate_callback' => function($param) {
        $is_valid = is_numeric($param);
        error_log('ARG VALIDATION: order_id ' . ($is_valid ? 'PASSED' : 'FAILED') . ' validation');
        return $is_valid;
      },
    ],
    'date' => [
      'required' => true,
      'validate_callback' => function($param) {
        $is_valid = strtotime($param) !== false;
        error_log('ARG VALIDATION: date ' . ($is_valid ? 'PASSED' : 'FAILED') . ' validation');
        return $is_valid;
      },
    ],
  ];

  error_log('Registering setorderdate route with namespace thrive/v1');
  
  $route_registered = register_rest_route('thrive/v1', '/setorderdate', [
    'methods' => 'POST',
    'callback' => 'set_order_date',
    'permission_callback' => 'admin_permission_check',
    'args' => $order_date_args
  ]);
  
  if (!$route_registered) {
    error_log('ERROR: Failed to register setorderdate route');
  }
  
  // Debug: List all registered routes
  $server = rest_get_server();
  $routes = $server->get_routes();
  if (isset($routes['/thrive/v1/setorderdate'])) {
    error_log('CONFIRMED: Route /thrive/v1/setorderdate is in routes array');
  } else {
    error_log('ERROR: Route /thrive/v1/setorderdate NOT found in routes array');
  }
}

// Admin permission check function
function admin_permission_check($request) {

  // Check if user is logged in
  if (!is_user_logged_in()) {
    error_log('PERMISSION CHECK: User not logged in');
    return new WP_Error('not_authenticated', 'You must be logged in to access this endpoint', ['status' => 401]);
  }

  $current_user = wp_get_current_user();

  $can_manage = current_user_can('manage_options');

  if (!$can_manage) {
    error_log('PERMISSION CHECK: Access denied - insufficient permissions');
    return new WP_Error('insufficient_permissions', 'You do not have permission to access this endpoint', ['status' => 403]);
  }

  return true;
}

/**
 * Set order date callback function
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function set_order_date($request) {
  error_log('=== SETORDERDATE ENDPOINT CALLED ===');

  // Retrieve and validate parameters
  $order_id = (int) $request->get_param('order_id');
  $date = sanitize_text_field($request->get_param('date'));
  error_log('Order ID: ' . $order_id);
  error_log('Date: ' . $date);

  // Check if order exists
  $order = wc_get_order($order_id);
  if (!$order) {
    error_log('ERROR: Order not found with ID: ' . $order_id);
    return new WP_Error('order_not_found', 'Order not found', ['status' => 404]);
  }

  error_log('Order found: ' . $order->get_id());

  // Parse and normalize the date format
  $timestamp = strtotime($date);
  if ($timestamp === false) {
    error_log('ERROR: Invalid date format: ' . $date);
    return new WP_Error('invalid_date', 'Invalid date format', ['status' => 400]);
  }
  
  // Convert to MySQL datetime format
  $normalized_date = date('Y-m-d H:i:s', $timestamp);
  error_log('Normalized date: ' . $normalized_date);

  // Convert date to GMT
  $gmt = get_gmt_from_date($normalized_date);
  error_log('GMT: ' . $gmt);
  if (!$gmt) {
    error_log('ERROR: Failed to convert to GMT: ' . $normalized_date);
    return new WP_Error('invalid_date', 'Failed to convert date to GMT', ['status' => 400]);
  }

  // Prepare post data for update
  $post_data = [
    'ID' => $order_id,
    'post_date' => $normalized_date,
    'post_date_gmt' => $gmt,
  ];
  error_log('Post data: ' . print_r($post_data, true));

  // Remove WooCommerce default date saving to prevent override
  remove_action('save_post_shop_order', ['WC_Meta_Box_Order_Data', 'save'], 10);

  // Update the post date in the database
  $result = wp_update_post($post_data, true);
  error_log('Update result: ' . print_r($result, true));

  if (is_wp_error($result)) {
    return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
  }

  // Get an instance of the WC_DateTime object
  $date_time = new WC_DateTime($normalized_date);

  // Set it in the order
  $order->set_date_created($date_time);
  $order->save();
  error_log('Order date updated using WC_DateTime to: ' . $date_time->date('Y-m-d H:i:s'));

  // Update WooCommerce paid date using update_meta_data
  $order->update_meta_data('_paid_date', $normalized_date);
  $order->save();
  error_log('WooCommerce paid date explicitly set to: ' . $normalized_date);

  // Set the payment complete date using WooCommerce's method
  $order->set_date_paid($date_time);
  $order->save();
  error_log('WooCommerce payment date set to: ' . $date_time->date('Y-m-d H:i:s'));

  // Verify the updated payment date
  $updated_date_paid = $order->get_date_paid();
  error_log('Verification - Updated date_paid: ' . ($updated_date_paid ? $updated_date_paid->date('Y-m-d H:i:s') : 'null'));

  // Verify the update
  $updated_order = wc_get_order($order_id);
  $updated_post_date = get_post_field('post_date', $order_id);
  error_log('Verification - Updated post_date: ' . $updated_post_date);

  return new WP_REST_Response([
    'success' => true,
    'message' => 'Order date and paid date updated successfully.',
    'order_id' => $order_id,
    'date' => $normalized_date,
    'verified_post_date' => $updated_post_date,
    'verified_paid_date' => $updated_date_paid,
  ]);
}