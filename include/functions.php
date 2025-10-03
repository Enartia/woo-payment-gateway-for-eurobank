<?php

function eurobank_message()
{
    $order_id = absint(get_query_var('order-received'));
    $order = new WC_Order($order_id);
    if (method_exists($order, 'get_payment_method')) {
        $payment_method = $order->get_payment_method();
    } else {
        $payment_method = $order->payment_method;
    }
    if (is_order_received_page() && ('eurobank_gateway' == $payment_method)) {
        if (method_exists($order, 'get_meta')) {
            $eurobank_message = $order->get_meta('_eurobank_message', true);
        } else {
            $eurobank_message = get_post_meta($order_id, '_eurobank_message');
        }

        if (!empty($eurobank_message)) {
            $message = $eurobank_message['message'];
            $message_type = $eurobank_message['message_type'];
            if (method_exists($order, 'delete_meta_data')) {
                $order->delete_meta_data('_eurobank_message');
                $order->save_meta_data();
            } else {
                delete_post_meta($order_id, '_eurobank_message');
            }
            wc_add_notice($message, $message_type);
        }
    }
}

/**
 * Add Eurobank Gateway to WC
 * */
function woocommerce_add_eurobank_gateway($methods)
{
    $methods[] = 'WC_Eurobank_Gateway';
    return $methods;
}


