<?php
/**
 * The uninstallation routine.
 * For security reasons, checks to ensure that the code is running under uninstallation mode
 * and that the current user has the ability to manage plugins.
 * The plugin then deletes all the options that it created from the database to clean its own trash,
 * leaving WordPress squeaky clean.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!current_user_can('activate_plugins')) {
    return;
}

// Basic plugin options to delete
$options = array(
    'coupon_mailer_section_title',
    'coupon_mailer_discount',
    'coupon_mailer_delete_used_coupons',
    'coupon_mailer_delete_expired_coupons',
    'coupon_mailer_limit_coupon_to_user',
    'coupon_mailer_section_end'
);

// Fetch parent categories to construct keys for category-specific settings
$args = array(
    'taxonomy'   => 'product_cat',
    'hide_empty' => false,
    'parent'     => 0  // only get top level categories
);
$product_categories = get_terms($args);

// Check if get_terms returns WP_Term objects or WP_Error
if (!is_wp_error($product_categories)) {
    foreach ($product_categories as $category) {
        if ($category->parent == 0 && $category->name != 'Uncategorized') {
            $options[] = 'coupon_mailer_discount_' . $category->term_id;
        }
    }
}

// Delete each option from the database
foreach ($options as $option) {
    delete_option($option);
}
