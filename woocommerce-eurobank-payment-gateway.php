<?php
/*
Plugin Name: Eurobank WooCommerce Payment Gateway
Plugin URI: https://www.papaki.com
Description: Eurobank Payment Gateway allows you to accept payment through various channels such as Maestro, Mastercard, AMex cards, Diners and Visa cards On your Woocommerce Powered Site.
Version: 2.0.3
Author: Papaki
Author URI: https://www.papaki.com
License: GPL-3.0+
License URI: http://www.gnu.org/licenses/gpl-3.0.txt
WC tested: 8.5.0
Text Domain: woo-payment-gateway-for-eurobank
Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'woocommerce_eurobank_init', 0);

function woocommerce_eurobank_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    add_action('before_woocommerce_init', function () {
        global $wpdb;

        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility($wpdb->prefix . 'eurobank_transactions', __FILE__, true);
        }
    });


    require_once 'classes/WC_Eurobank_Gateway.php';
    require_once 'include/functions.php';

    load_plugin_textdomain(WC_Eurobank_Gateway::PLUGIN_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages/');

    add_action('wp', 'eurobank_message');
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_eurobank_gateway');

    add_filter('plugin_action_links', function ($links, $file) {
        static $this_plugin;

        if (!$this_plugin) {
            $this_plugin = plugin_basename(__FILE__);
        }

        if ($file == $this_plugin) {
            $settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=WC_Eurobank_Gateway">Settings</a>';
            array_unshift($links, $settings_link);
        }
        return $links;
    }, 10, 2);

}
