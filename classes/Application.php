<?php

namespace Papaki\Eurobank\WooCommerce;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Application {
    public const PLUGIN_TITLE = 'Payment Gateway – Credit card via Eurobank';
    public const TEXT_DOMAIN  = 'woo-payment-gateway-for-eurobank';

    private $entrypoint_path;

    public function __construct( $entrypoint ) {
        $this->entrypoint_path = $entrypoint;

        // add_action( 'plugins_loaded', [ $this, 'init' ], 0 );
        // we're in a plugins_loaded hook already, @see woocommerce-eurobank-payment-gateway.php
        $this->init();

        add_action( 'init', [ $this, 'load_languages' ] );
        add_action( 'before_woocommerce_init', [ $this, 'declare_transactions' ] );

        $checkout_block = new Checkout_Block( $entrypoint );
        $checkout_block->init();
    }

    public function init() {
        add_action( 'wp', [ $this, 'eurobank_message' ] );
        add_filter( 'woocommerce_payment_gateways', [ $this, 'woocommerce_add_eurobank_gateway' ] );
        add_filter( 'plugin_action_links', [ $this, 'eurobank_plugin_action_links' ], 10, 2 );
    }

    public function load_languages() {
        load_plugin_textdomain( static::TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/../languages/' );
    }

    public function declare_transactions() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $this->entrypoint_path, true );
        }
    }

    public function eurobank_message() {
        $order_id = absint( get_query_var( 'order-received' ) );
        $order    = new \WC_Order( $order_id );
        if ( method_exists( $order, 'get_payment_method' ) ) {
            $payment_method = $order->get_payment_method();
        } else {
            $payment_method = $order->payment_method;
        }
        if ( is_order_received_page() && ( 'eurobank_gateway' == $payment_method ) ) {
            $eurobank_message = $order->get_meta( '_eurobank_message', true );

            if ( ! empty( $eurobank_message ) ) {
                $message      = $eurobank_message['message'];
                $message_type = $eurobank_message['message_type'];
                $order->delete_meta_data( '_eurobank_message' );
                $order->save_meta_data();
                wc_add_notice( $message, $message_type );
            }
        }
    }

    /**
     * Add Eurobank Gateway to WC
     *
     * @param array $methods
     * @return array
     * */
    public function woocommerce_add_eurobank_gateway( $methods ) {
        $methods[] = '\Papaki\Eurobank\WooCommerce\WC_Eurobank_Gateway';
        return $methods;
    }

    /**
     * @param $links
     * @param $file
     * @return mixed
     */
    public function eurobank_plugin_action_links( $links, $file ) {
        static $this_plugin;

        if ( ! $this_plugin ) {
            $this_plugin = $this->entrypoint_path;
        }

        if ( $file == $this_plugin ) {
            $settings_link = '<a href="' . get_bloginfo( 'wpurl' ) . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=WC_Eurobank_Gateway">Settings</a>';
            array_unshift( $links, $settings_link );
        }
        return $links;
    }
}
