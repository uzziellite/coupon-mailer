<?php
/**
 * Coupon Mailer
 *
 * @package           Coupon Mailer
 * @author            Uzziel Lite
 * @copyright         2024 Uzziel Technologies.
 * @license           GPL-3.0
 *
 *
 * Plugin Name:       Coupon Mailer
 * Description:       Send customer invoice with additional information that contains coupon codes to be used on other purchases of products in the same category
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Uzziel Kibet
 * Author URI:        https://github.com/uzziellite
 * Text Domain:       Coupon Mailer
 * License:           GPL v3 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}


/**
 * Check the version of woocommerce in use after Woocommerce is fully loaded,
 * is active and running.
 */

add_action( 'woocommerce_init', 'coupon_mailer_woocommerce_init' );
function coupon_mailer_woocommerce_init() {
  // Only continue if we have access to version 8.6.0 or higher.
  if ( version_compare( wc()->version, '8.6.0', '<' ) ) {
      add_action('admin_notices', function () {
          echo '<div class="notice notice-error">
                   <p>The plugin <strong>Coupon Mailer</strong> requires Woocommerce version 8.6.0 and above to work properly. Please upgrade to continue.</p>
               </div>';
      });
      return;
  }
}

/**
 * Ensures that the woocommerce data has already loaded successfully and the additional hooks are going to work as expected
 */
add_action('woocommerce_loaded', 'coupon_mailer_woocommerce_initialization');
function coupon_mailer_woocommerce_initialization(){
	add_action( 'woocommerce_email_customer_details', 'add_coupon_code_to_order_email', 40, 4 );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'coupon_mailer_init_settings' );
function coupon_mailer_init_settings($links) {
    $settings_link = [
        'configure' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=coupon_mailer' ) . '">' . __( 'Configure', 'coupon-mailer' ) . '</a>',
    ];

    return array_merge( $settings_link, $links );
}

function add_coupon_code_to_order_email( $order, $sent_to_admin, $plain_text, $email ) {
	// Check if the $order belongs to Woocommerce Order
	if ( ! is_a( $order, 'WC_Order' ) ) return;

	if ( $email->id == 'customer_processing_order' ) {
		//Fetching product categories
		$categories = []; // Product categories
        $images = []; // Store all the images that will be used in the emails
        $product_categories = []; // Store all the product ids here that will be used by the coupon generator
        $category_urls = [];
        $percentage_discount = get_option('coupon_mailer_discount', 5);
        $used_coupons = $order->get_coupon_codes();
        $coupon_used = empty($used_coupons);

        // Delete the used coupons here
        if (!empty($used_coupons) && get_option('coupon_mailer_delete_used_coupons') === 'yes') {
            foreach ($used_coupons as $coupon_code) {
                // Attempt to get the coupon by its code
                $coupon = new WC_Coupon($coupon_code);
                $coupon_id = $coupon->get_id();
                
                // Check if the coupon exists
                if ($coupon_id) {
                    // Delete the coupon
                    wp_delete_post($coupon_id, true);
                }
            }
        }
    
        // Begin Images
        $image_url = wc_placeholder_img_src(); // Default to placeholder image
		foreach ($order->get_items() as $item_id => $item) {
		    $product_id = $item->get_product_id();
            // Push the products into the array
            $terms = get_the_terms($product_id, 'product_cat');
		    
		    if (!empty($terms)) {
		        foreach ($terms as $term) {
                    // Push product categories for each product. Stored as array to show it belongs to a specific product avoids mixing.
                    // Check if the term's parent is '0' (indicating it is a parent category)
                    if ($term->parent == 0) {
                    $product_categories[] = [$term->term_id];  // Retain this category as it is a parent. We only need the parent categories
                        $categories[] = $term->name;
                        // Get the category's featured image URL.
                        $thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
                        if ($thumbnail_id) {
                            $images[] = wp_get_attachment_url($thumbnail_id);
                            
                        }else{
                        // No image set, use the default placeholder
                        $images[] = $image_url;
                    }

                    $category_urls[] = get_term_link($term->term_id, 'product_cat'); // Link for the category pages
                    }
		        }
		    }
		}

        // Generate the necessary coupon codes for the user dynamically
        $product_categories = remove_array_duplicates($product_categories);

    // Loops through all the products and generates coupons for them where necessary when a customer made a purchase without a coupon
    if($coupon_used){
      $coupons_generated = generate_coupon_after_order($email->recipient,$product_categories);
      
      if(isset($coupons_generated['error'])){
        log_errors("Coupon generation error: " . $coupons_generated['error'] . "\n");
      }

      // Fetch the coupon code from WooCommerce settings
      $coupon_codes = coupon_mailer_get_all_woocommerce_coupons($order, $email->recipient);

      //Remove duplicate categories
      $categories = array_unique( $categories );
      
      if ( ! empty($coupon_codes) ) {
          if (!$plain_text) {
              // HTML Email Format
              echo '<h2 style="color: #7f54b3; font-family: \'Helvetica Neue\', Helvetica, Roboto, Arial, sans-serif; font-size: 18px; font-weight: bold; line-height: 130%; margin: 0 0 18px; text-align: left;">Discount On Next Purchase</h2>';

              for($i = 0; $i < count($images); $i++){
                  echo '<style>.background-image' . esc_html($i) . ' {width:100%; height:150px; background-image:url(\'' . esc_html($images[$i]) . '\'); background-size:cover; background-position:center center; position:relative; color:#fff; font-family:Arial, sans-serif}.overlay' . esc_html($i) . '{background-color:rgba(0,0,0,.4); width:100%; height:100%; position:absolute; top:0; left:0; display:flex; justify-content:center; align-items:center; text-align:left}.content' . esc_html($i) . '{z-index:2}.button' . esc_html($i) . '{display:inline-block;margin-top:4px;background-color:#000;color:#fff;text-align:center;padding:8px 16px;text-decoration:none;font-size:16px;border:none;cursor:pointer}</style><table width="100%" border="0" cellspacing="0" cellpadding="0"><tr><td><div class="background-image' . esc_html($i) . '"><div class="overlay' . esc_html($i) . '"><div class="content' . esc_html($i) . '"><p style="font-size:20px; padding:12px;">Use the coupon code <strong>' . esc_html($coupon_codes[$i]) . '</strong> to get a '. esc_html($percentage_discount) . '%' . ' discount on your next purchase when you buy any product in the <strong>' . esc_html($categories[$i]) . '</strong> category</p><button class="button' . esc_html($i) . '" style="width:100%;"><a href="'. esc_html($category_urls[$i]) .'" style="color:white;">Shop Now</a></button></div></div></div></td></tr></table>';
              }

          } else {
              // Plain Text Email Format
              echo "Discount On Next Purchase\n\n";
              for($i = 0; $i < count($coupon_codes); $i++){
                  echo esc_html('Use the coupon code ' . $coupon_codes[$i] . ' to get a '. $percentage_discount .' percent discount on your next purchase when you buy any product in the ' . $categories[$i] . 'category.' . "\n\n". '<a href="'. $category_urls[$i] .'">Shop Now</a>');
              }
          }
      }
    }
  }
}

/**
 * Emails that have been sent are successfully logged but only if debug 
 * mode is enabled as this will log all the emails.
 */

add_action('wp_mail', 'log_sent_emails', 10, 1);
function log_sent_emails($args) {
    if (defined('WP_DEBUG') && WP_DEBUG) {

        $to = $args['to'];
        $subject = $args['subject'];
        $message = $args['message'];
        $headers = $args['headers'];
        $log_entry = "To: $to\nSubject: $subject\nHeaders: $headers\nMessage: $message\n\n";

        $email_log_dir = WP_CONTENT_DIR . '/emails';

        if (!file_exists($email_log_dir)) {
            wp_mkdir_p($email_log_dir);
        }

        $log_file = $email_log_dir . '/email_log.html';
        // Using error_log() for logging emails
        error_log($log_entry, 3, $log_file);
    }
}

// Add logic to delete all expired coupons upon checking for coupon codes
function coupon_mailer_get_all_woocommerce_coupons($order, $customer_email) {
    $valid_coupons = [];

    $args = [
        'posts_per_page' => -1,
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
    ];

    $coupons = get_posts($args);

    if ($coupons) {
        foreach ($coupons as $coupon_post) {
            $coupon = new WC_Coupon($coupon_post->ID);
          
            // Check if coupon is not expired
            if ($coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp() > current_time('timestamp')) {
                // Get the product categories associated with the coupon
                $coupon_product_cats = $coupon->get_product_categories();

                //Coupon does not have a specific product that it belongs to
                if (empty($coupon_product_cats)) {
                    $allowed_emails = $coupon->get_email_restrictions();
                    if (in_array($customer_email, $allowed_emails) || empty($allowed_emails)) {
                        $valid_coupons[] = strtoupper($coupon->get_code());
                    }
                } else {
                    // Get all product IDs in the order
                    $order_items = $order->get_items();
                    foreach ($order_items as $item_id => $item) {
                        $product_id = $item->get_product_id();
                        $product = wc_get_product($product_id);
                        if (!$product) {
                            continue;
                        }
                      
                        // Check if product belongs to any of the coupon's categories
                        $product_cats = $product->get_category_ids();
                        $all_product_cats = $product_cats;

                        // Iterate through each product category
                        foreach ($product_cats as $cat_id) {
                            // Get all child categories of the current category
                            $child_categories = get_child_categories($cat_id);

                            // Merge child categories into the all_product_cats array
                            $all_product_cats = array_merge($all_product_cats, $child_categories);
                        }

                        if (array_intersect($coupon_product_cats, $all_product_cats)) {
                            // Check if customer email is in the allowed emails list of the coupon
                            $allowed_emails = $coupon->get_email_restrictions();
                          
                            if (in_array($customer_email, $allowed_emails) || empty($allowed_emails)) {
                                // If all checks pass, add the coupon code to the valid coupons array
                                $valid_coupons[] = strtoupper($coupon->get_code());
                                break; // No need to check other items once a match is found
                            }
                        }
                    }
                }
            } else {
                // Remove the expired coupon codes from the store
                if(get_option('coupon_mailer_delete_expired_coupons') === 'yes'){
                    wp_delete_post($coupon->get_id(), true);
                }
            }
        }
    }

    return array_reverse($valid_coupons);
}

// Generate random coupon codes for the user
function generate_coupon_code() {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $result = '';
    $charactersLength = strlen($characters);
    for ($i = 0; $i < 8; $i++) {
        $result .= $characters[wp_rand(0, $charactersLength - 1)];
    }
    return $result;
}

/**
 * Create coupon codes dynamically that will be sent to the client together with the order email
 * This function should be called first before the other order delivery functions are called to ensure that the
 * coupons are available to be bundled with the user email
 * 
 */
function generate_coupon_after_order($email,$product_categories) {
  /** 
   * Set the coupon properties
   * Type of discount: fixed_cart, percent, fixed_product, percent_product
   */
    
    $discount_type = 'percent_product';
    $expiry_date = gmdate('Y-m-d', strtotime('+30 days'));
    $data = remove_array_duplicates($product_categories);

    //Since product categories only contains the parent categories, we need the generated coupons to have all the child categories applied and exclude the parent category from the discounts being offered
    foreach($data as $product_category){
        generate_and_save_coupon_code($email,$product_category,$discount_type,$expiry_date);
    }
}

function get_child_categories_with_parent_discounts($parent_category_id) {
    // Fetch child categories
    $child_categories = get_term_children($parent_category_id, 'product_cat');

    //Fetch parent category
    $parent_category = get_term($parent_category_id, 'product_cat');

    //Fetching the parent discount option dynamically from the database
    $option_name = 'coupon_mailer_discount_' . $parent_category_id;
    $global_discount = get_option('coupon_mailer_discount',2);
    $category_discount_value = get_option($option_name, $global_discount); // Default to global discount if the value is not set 

    return [
        $child_categories,
        $category_discount_value,
        $parent_category
    ];
}


/**
 * Generates coupon code and adds it to the database
 */
function generate_and_save_coupon_code($email,$product_category,$discount_type,$expiry_date) {
    // Get child categories of the parent category and also the percentage discounts for the parent categories to be applied across the entire store
    $child_categories = get_child_categories_with_parent_discounts( $product_category[0] );

    //If a parent has no child, then the parent is not eligible for coupon generation
    if( ! empty( $child_categories[0] ) && ! is_wp_error( $child_categories[0] ) ) {

        $coupon = array(
        'post_title' => generate_coupon_code(),
        'post_content' => '',
        'post_status' => 'publish',
        'post_author' => 1,
        'post_type' => 'shop_coupon'
        );
    
        $new_coupon_id = wp_insert_post($coupon);
    
        // Check if wp_insert_post returned a WP_Error object, indicating an error, if so, inform the user and respond accordingly
        if (is_wp_error($new_coupon_id)) {
            echo '<div class="notice notice-error"><p>The plugin <strong>Coupon Mailer</strong> is unable to generate new coupon codes that will be mailed to the user. Please investigate the issue. The error returned from the plugin is: <strong>' . esc_html( $new_coupon_id->get_error_message() ) . '</strong></p></div>';
    
            return;
        }
    
        // Add meta data for the coupon
        update_post_meta($new_coupon_id, 'discount_type', $discount_type);
        update_post_meta($new_coupon_id, 'individual_use', 'no');
        update_post_meta($new_coupon_id, 'product_ids', '');
        update_post_meta($new_coupon_id, 'exclude_product_ids', '');
        update_post_meta($new_coupon_id, 'usage_limit', '1');
        update_post_meta($new_coupon_id, 'usage_limit_per_user', '1');
        update_post_meta($new_coupon_id, 'expiry_date', $expiry_date);
        update_post_meta($new_coupon_id, 'apply_before_tax', 'yes');
        update_post_meta($new_coupon_id, 'free_shipping', 'no');
    
        //Determine if the coupon is limited to the user email or not.
        if(get_option('coupon_mailer_limit_coupon_to_user') === 'yes'){
            update_post_meta($new_coupon_id, 'customer_email', array($email));
        }
    
        // Update coupon metadata with child categories
        update_post_meta($new_coupon_id, 'product_categories', $child_categories[0]);

        //Update the amount (This is the percentage amount for the parent category)
        update_post_meta( $new_coupon_id, 'coupon_amount', $child_categories[1] );

    } else {
        // Handle case where there are no child categories or an error occurred
        // You might want to log an error or set a default category
        // update_post_meta($new_coupon_id, 'product_categories', array($product_category));
        // error_log('No child categories found or an error occurred.');
        // Or do nothing about it
    }
}

/**
 * Remove all the duplicates from the array so that even nested arrays do not contain any duplicates
 */
function remove_array_duplicates(array $array): array {
    // Flatten the array to get all values
    $flattenedArray = [];
    foreach ($array as $subArray) {
        foreach ($subArray as $item) {
            $flattenedArray[] = $item;
        }
    }

    // Count occurrences of each value
    $valueCounts = array_count_values($flattenedArray);

    // Prepare the result array
    $result = [];

    // Iterate over the original array to remove duplicates
    foreach ($array as $subArray) {
        $newSubArray = [];

        foreach ($subArray as $item) {
            // If this is the first occurrence of the item, add it to the new sub-array
            if (isset($valueCounts[$item]) && $valueCounts[$item] > 0) {
                $newSubArray[] = $item;
                // Mark the item as 'added' by setting its count to 0
                $valueCounts[$item] = 0;
            }
        }

        // Add the cleaned sub-array to the result
        $result[] = $newSubArray;
    }

    return $result;
}

// To be removed when plugin is ready. Remember to remove this plugin on the production site
function log_errors($error) {
    $log_dir = WP_CONTENT_DIR . '/logs';

    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }

    $log_file = $log_dir . '/error_log.txt';

    error_log($error . "\n", 3, $log_file);
}

/**
 * Begin Administration interface for Coupon Mailer Plugin
 * 
 */
add_filter('woocommerce_settings_tabs_array', 'add_coupon_mailer_tab', 50);
function add_coupon_mailer_tab($settings_tabs) {
    $settings_tabs['coupon_mailer'] = __('Coupon Mailer', 'woocommerce');
    return $settings_tabs;
}

add_action('woocommerce_settings_coupon_mailer', 'coupon_mailer_settings');
function coupon_mailer_settings() {
  woocommerce_admin_fields(get_coupon_mailer_settings());
}

add_action('woocommerce_update_options_coupon_mailer', 'update_coupon_mailer_settings');
function update_coupon_mailer_settings() {
    woocommerce_update_options(get_coupon_mailer_settings());
}

function get_coupon_mailer_settings() {
    // Define settings array
    $settings = array();

    // Section title
    $settings['section_title'] = array(
        'name' => __('Coupon Mailer Settings', 'woocommerce'),
        'type' => 'title',
        'desc' => 'These settings determine how Coupon Mailer plugin works. Coupon Mailer adds a targeted coupon to a specific customer as a way of rewarding them for the purchases that they have done on the store. They will get a Coupon offering a percentage discount off for the next product that they will purchase that belongs to the same product category. For example after buying a Sofa from the furniture category, a customer gets a coupon to enable them to purchase another product that belongs to the furniture category like sofa pillows or sofa covers.',
        'id'   => 'coupon_mailer_section_title'
    );

    // Global discount
    $settings['coupon_mailer_discount'] = array(
        'name' => __('Global Percentage Discount', 'woocommerce'),
        'type' => 'number',
        'desc' => __('Percentage Discount to be used across the entire store for subsequent product purchases when category discounts have not been set. If this value is blank, the value 2% is used by default', 'woocommerce'),
        'id'   => 'coupon_mailer_discount',
        'custom_attributes' => array(
            'min' => '0',
            'max' => '100'
        )
    );

    // Fetch parent categories
    $args = array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'parent'     => 0  // only get top level categories
    );
    $product_categories = get_terms($args);

    // Add a setting for each parent category
    foreach ($product_categories as $category) {
        if ($category->parent == 0 && $category->name != 'Uncategorized') { // ensuring it's a parent category
            $settings['coupon_mailer_discount_' . $category->term_id] = array(
                /* translators: %s: category name */
                'name' => sprintf(__('Discount for %s', 'woocommerce'), $category->name),
                'type' => 'number',
                /* translators: %s: category name */
                'desc' => sprintf(__('Set the percentage discount for the %s category.', 'woocommerce'), $category->name),
                'id'   => 'coupon_mailer_discount_' . $category->term_id,
                'custom_attributes' => array(
                    'min' => '0',
                    'max' => '100'
                )
            );
        }
    }

    // Delete used coupons
    $settings['coupon_mailer_delete_used_coupons'] = array(
        'name' => __('Delete Used Coupons', 'woocommerce'),
        'type' => 'checkbox',
        'desc' => __('Delete all the coupons that have already been used', 'woocommerce'),
        'id'   => 'coupon_mailer_delete_used_coupons'
    );

    // Delete expired coupons
    $settings['coupon_mailer_delete_expired_coupons'] = array(
        'name' => __('Delete Expired Coupons', 'woocommerce'),
        'type' => 'checkbox',
        'desc' => __('Delete all the coupons that have expired without being used', 'woocommerce'),
        'id'   => 'coupon_mailer_delete_expired_coupons'
    );

    // Limit coupon to user
    $settings['coupon_mailer_limit_coupon_to_user'] = array(
        'name' => __('Limit Coupon To User', 'woocommerce'),
        'type' => 'checkbox',
        'desc' => __('By enabling this option, the coupon can only be used by a specific user. If disabled, the coupon can be used by any one that has it but it can only be used once.', 'woocommerce'),
        'id'   => 'coupon_mailer_limit_coupon_to_user'
    );

    // Section end
    $settings['section_end'] = array(
        'type' => 'sectionend',
        'id'   => 'coupon_mailer_section_end'
    );

    return apply_filters('coupon_mailer_settings', $settings);
}

// Function to get all child categories of a given category
function get_child_categories($parent_cat_id) {
    $child_categories = get_terms(array(
        'taxonomy' => 'product_cat',
        'child_of' => $parent_cat_id,
        'hide_empty' => false,
        'fields' => 'ids'
    ));

    return $child_categories;
}