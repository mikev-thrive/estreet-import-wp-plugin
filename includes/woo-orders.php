<?php 

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
} 

add_action('admin_enqueue_scripts', function($hook) {
    global $post;

    if ($hook === 'post.php' && isset($post) && $post->post_type === 'shop_order') {
        wp_enqueue_style(
            'estreet-order-notes-style',
            plugin_dir_url(__DIR__) . 'includes/css/estreet-order-notes.css',
            [],
            filemtime(plugin_dir_path(__DIR__) . 'includes/css/estreet-order-notes.css')
        );
    }
});

function estreet_woocommerce_show_user_notes_on_orders($order) {
  $customer_id = $order->get_customer_id();
  if (!$customer_id) {
    return; // No customer ID, nothing to show
  } 

  // Get the ACF field 'user_notes' for the customer
  if (!function_exists('get_field')) {
    error_log('ACF function get_field does not exist. Please ensure ACF is installed and activated.');
    return; // ACF is not available
  }
  $user_notes = get_field('user_notes', 'user_' . $customer_id);

  error_log("User notes for customer ID $customer_id: " . print_r($user_notes, true));
  if (empty($user_notes)) {
    return; // No user notes to display
  }

  ?>
  <div class="estreet-user-notes-box">
    <h4>User Notes</h4>
    <div class="notes">
      <p><?= $user_notes ?></p>
    </div>
  </div>
  <?php

  }
add_action('woocommerce_admin_order_data_after_order_details', 'estreet_woocommerce_show_user_notes_on_orders');

// Create sequential order numbers
function estreet_assign_sequential_order_number($order_id) {
    // Only assign if it doesn't already have a custom order number (keeps wp_all_import from overwriting it)
    if (get_post_meta($order_id, '_order_number', true)) {
        error_log("Order ID $order_id already has a custom order number. Skipping assignment.");
        return; // Order number already set
    }

    global $wpdb;
    $lock_key = 'estreet_order_number_lock';
    $lock_acquired = false;
    $max_retries = 5;

    // Try 5 times to acquire the lock
    $attempts = 0;
    while ($attempts < $max_retries) {
        $lock = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lock_key, 10)); // 10 seconds timeout
        if ($lock === '1') {
            $lock_acquired = true;
            break; // Lock acquired successfully
        }
        $attempts++;
        error_log("Attempt $attempts to acquire lock for sequential order number failed.");
        sleep(1); // Wait before retrying
    }

    if ($lock_acquired) {
        error_log("Lock acquired for sequential order number assignment.");
        $last_order_number = (int) get_option('estreet_last_sequential_order_number', 0);
        $current_order_number = $last_order_number + 1;

        // Increment the next order number
        update_option('estreet_last_sequential_order_number', $current_order_number);
        update_post_meta($order_id, '_order_number', $current_order_number);
        error_log("Assigned sequential order number $current_order_number to order ID $order_id.");

        // Release the lock
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_key));
        error_log("Lock released for sequential order number assignment.");
    } else {
        // Fallback — assign timestamp-based ID to avoid duplication
        $fallback_order_number = time();
        update_post_meta($order_id, '_order_number', $fallback_order_number);
        error_log("Failed to acquire lock. Assigned fallback order number $fallback_order_number to order ID $order_id.");
    }
}

//TODO enable this for live
//add_action('woocommerce_new_order', 'estreet_assign_sequential_order_number');

// Ensure the last order number is set on plugin activation
function estreet_woocommerce_activate() {
    $last_order_number = get_option('estreet_last_sequential_order_number', 0);
    if (!is_numeric($last_order_number) || (int)$last_order_number < 0) {
        update_option('estreet_last_sequential_order_number', 0);
        error_log("Reset last sequential order number to 0 on plugin activation.");
    }
}

// Set proper order number for emails an in admin
add_filter('woocommerce_order_number', function($order_number, $order) {
    $custom = $order->get_meta('_order_number');
    return $custom ? $custom : $order_number;
}, 10, 2);


// Add custom column
add_filter('manage_edit-shop_order_columns', function($columns) {
    $new_columns = [];

    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;
        if ($key === 'order_number') {
            $new_columns['estreet_order_number'] = 'Order #';
        }
    }

    return $new_columns;
});

// Display custom order number
add_action('manage_shop_order_posts_custom_column', function($column, $post_id) {
    if ($column === 'estreet_order_number') {
        echo esc_html(get_post_meta($post_id, '_order_number', true));
    }
}, 10, 2);

// Make the custom order number column sortable
add_filter('manage_edit-shop_order_sortable_columns', function($columns) {
    $columns['estreet_order_number'] = 'estreet_order_number';
    return $columns;
});


// Add custom order Statuses ('Shipped','Returned','Partially Returned')

add_action( 'init', 'estreet_register_custom_order_statuses' );

function estreet_register_custom_order_statuses() {
	register_post_status( 'wc-shipped', array(
		'label'                     => _x( 'Shipped', 'Order status', 'estreet-woocommerce' ),
		'public'                    => true,
		'exclude_from_search'       => false,
		'show_in_admin_all_list'    => true,
		'show_in_admin_status_list' => true,
		'label_count'               => 'Shipped <span class="count">(%s)</span>'
	) );

	register_post_status( 'wc-returned', array(
		'label'                     => _x( 'Returned', 'Order status', 'estreet-woocommerce' ),
		'public'                    => true,
		'exclude_from_search'       => false,
		'show_in_admin_all_list'    => true,
		'show_in_admin_status_list' => true,
		'label_count'               => 'Returned <span class="count">(%s)</span>'
	) );

	register_post_status( 'wc-partially-returned', array(
		'label'                     => _x( 'Partially Returned', 'Order status', 'estreet-woocommerce' ),
		'public'                    => true,
		'exclude_from_search'       => false,
		'show_in_admin_all_list'    => true,
		'show_in_admin_status_list' => true,
		'label_count'               => 'Partially Returned <span class="count">(%s)</span>'
	) );
}

add_filter( 'wc_order_statuses', 'estreet_add_custom_order_statuses' );
function estreet_add_custom_order_statuses( $order_statuses ) {
	$order_statuses['wc-shipped'] = _x( 'Shipped', 'Order status', 'estreet-woocommerce' );
	$order_statuses['wc-returned'] = _x( 'Returned', 'Order status', 'estreet-woocommerce' );
	$order_statuses['wc-partially-returned'] = _x( 'Partially Returned', 'Order status', 'estreet-woocommerce' );

	return $order_statuses;
}