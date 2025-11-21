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

/**
 * @property string $notify_url
 * @property false|string $redirect_page_id
 * @property string $eb_order_note
 * @property string $eb_enable_log
 * @property string $eb_render_logo
 * @property string $eb_transactionType
 * @property string $eb_installments_variation
 * @property string $test_mode
 * @property int $pb_installments
 * @property string $eb_PayMerchantKey
 * @property string $eb_PayMerchantId
 */
class WC_Eurobank_Gateway extends WC_Payment_Gateway
{
    public const PLUGIN_DOMAIN = 'woo-payment-gateway-for-eurobank';
    public const ENCRYPTION_METHOD = 'aes-128-cbc';

    public function __construct()
    {
        global $wpdb;

        $this->id = 'eurobank_gateway';
        $this->has_fields = true;
        $this->notify_url = WC()->api_request_url('WC_Eurobank_Gateway');
        $this->method_description = __('Eurobank Payment Gateway allows you to accept payment through various channels such as Maestro, Mastercard, AMex cards, Diners and Visa cards On your Woocommerce Powered Site.', self::PLUGIN_DOMAIN);
        $this->redirect_page_id = $this->get_option('redirect_page_id');
        $this->method_title = __('Credit card via Eurobank', self::PLUGIN_DOMAIN);

        // Load the form fields.
        $this->init_form_fields();

        $tableCheck = $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->prefix . "eurobank_transactions'");
        if ($tableCheck !== $wpdb->prefix . 'eurobank_transactions') {
            $wpdb->query('CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . 'eurobank_transactions (id int(11) unsigned NOT NULL AUTO_INCREMENT, merch_ref varchar(50) not null, trans_ticket varchar(32) not null , timestamp datetime default null, PRIMARY KEY (id))');
        }

        // Load the settings.
        $this->init_settings();

        // Define user set variables
        $this->title = sanitize_text_field($this->get_option('title'));
        $this->description = sanitize_text_field($this->get_option('description'));
        $this->eb_PayMerchantId = sanitize_text_field($this->get_option('eb_PayMerchantId'));
        $this->eb_PayMerchantKey = sanitize_text_field($this->get_option('eb_PayMerchantKey'));
        $this->pb_installments = absint($this->get_option('pb_installments'));
        $this->test_mode = sanitize_text_field($this->get_option('test_mode'));
        $this->eb_installments_variation = sanitize_text_field($this->get_option('eb_installments_variation'));
        $this->eb_transactionType = sanitize_text_field($this->get_option('eb_transactionType'));
        $this->eb_render_logo = sanitize_text_field($this->get_option('eb_render_logo'));
        $this->eb_enable_log = sanitize_text_field($this->get_option('eb_enable_log'));
        $this->eb_order_note = sanitize_text_field($this->get_option('eb_order_note'));
        //Actions
        add_action('woocommerce_receipt_eurobank_gateway', array($this, 'receipt_page'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Payment listener/API hook
        add_action('woocommerce_api_wc_eurobank_gateway', array($this, 'check_eurobank_response'));

        if ($this->eb_render_logo === "yes") {
            $this->icon = apply_filters('eurobank_icon', plugins_url('../img/eurobank.svg', __FILE__));
        }
    }

    /**
     * @return void
     */
    public function admin_options()
    {
        echo '<h3>' . __('Eurobank Gateway', self::PLUGIN_DOMAIN) . '</h3>';
        echo '<p>' . __('Eurobank Gateway allows you to accept payment through various channels such as Maestro, Mastercard, AMex cards, Diners  and Visa cards.', self::PLUGIN_DOMAIN) . '</p>';

        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    /**
     * @return void
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', self::PLUGIN_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Enable Eurobank Gateway', self::PLUGIN_DOMAIN),
                'description' => __('Enable or disable the gateway.', self::PLUGIN_DOMAIN),
                'desc_tip' => true,
                'default' => 'yes',
            ),
            'test_mode' => array(
                'title' => __('Test Environment', self::PLUGIN_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Enable Eurobank Test Environment', self::PLUGIN_DOMAIN),
                'description' => __('Enable or disable the test environment.', self::PLUGIN_DOMAIN),
                'desc_tip' => true,
                'default' => 'no',
            ),
            'title' => array('
                    title' => __('Title', self::PLUGIN_DOMAIN),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', self::PLUGIN_DOMAIN), 'desc_tip' => false,
                'default' => __('Credit card via Eurobank', self::PLUGIN_DOMAIN)
            ),
            'description' => array(
                'title' => __('Description', self::PLUGIN_DOMAIN),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', self::PLUGIN_DOMAIN),
                'default' => __('Pay Via Eurobank: Accepts  Mastercard, Visa cards and etc.', self::PLUGIN_DOMAIN)
            ),
            'eb_render_logo' => array(
                'title' => __('Display the logo of EuroBank', self::PLUGIN_DOMAIN),
                'type' => 'checkbox',
                'description' => __('Enable to display the logo of EuroBank next to the title which the user sees during checkout.', self::PLUGIN_DOMAIN),
                'default' => 'yes'
            ),
            'eb_PayMerchantId' => array(
                'title' => __('Eurobank Merchant ID', self::PLUGIN_DOMAIN),
                'type' => 'text',
                'description' => __('Enter Your Eurobank Merchant ID', self::PLUGIN_DOMAIN),
                'default' => '',
                'desc_tip' => true
            ),
            'eb_PayMerchantKey' => array(
                'title' => __('Eurobank Merchant KEY', self::PLUGIN_DOMAIN),
                'type' => 'password',
                'description' => __('Enter Your Eurobank Merchant KEY', self::PLUGIN_DOMAIN),
                'default' => '',
                'desc_tip' => false
            ),
            'redirect_page_id' => array(
                'title' => __('Return page URL <br />(Successful or Failed Transactions)', self::PLUGIN_DOMAIN),
                'type' => 'select',
                'options' => $this->pb_get_pages('Select Page'),
                'description' => __('We recommend you to select the default “Thank You Page”, in order to automatically serve both successful and failed transactions, with the latter also offering the option to try the payment again.<br /> If you select a different page, you will have to handle failed payments yourself by adding custom code.', self::PLUGIN_DOMAIN),
                'default' => "-1"
            ),
            'pb_installments' => array(
                'title' => __('Maximum number of installments regardless of the total order amount', self::PLUGIN_DOMAIN),
                'type' => 'select',
                'options' => $this->pb_get_installments(),
                'description' => __('1 to 24 Installments,1 for one time payment. You must contact Eurobank first<br /> If you have filled the "Max Number of installments depending on the total order amount"', self::PLUGIN_DOMAIN),
            ),
            'eb_installments_variation' => array(
                'title' => __('Maximum number of installments depending on the total order amount', self::PLUGIN_DOMAIN),
                'type' => 'text',
                'description' => __('Example 80:2, 160:4, 300:8</br> total order greater or equal to 80 -> allow 2 installments, total order greater or equal to 160 -> allow 4 installments, total order greater or equal to 300 -> allow 8 installments</br> Leave the field blank if you do not want to limit the number of installments depending on the amount of the order.', self::PLUGIN_DOMAIN)
            ),
            'eb_transactionType' => array(
                'title' => __('Pre-Authorize', self::PLUGIN_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Enable to capture preauthorized payments', self::PLUGIN_DOMAIN),
                'default' => 'no'
            ),
            'eb_enable_log' => array(
                'title' => __('Enable Debug mode', self::PLUGIN_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Enabling this will log certain information', self::PLUGIN_DOMAIN),
                'default' => 'no',
                'description' => __('Enabling this (and the debug mode from your wp-config file) will log information, e.g. bank responses, which will help in debugging issues.', self::PLUGIN_DOMAIN)
            ),
            'eb_order_note' => array(
                'title' => __('Enable 2nd “payment received” email', self::PLUGIN_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Enable sending Customer order note with transaction details', self::PLUGIN_DOMAIN),
                'default' => 'no',
                'description' => __('Enabling this will send an email with the support reference id and transaction id to the customer, after the transaction has been completed (either on success or failure)', self::PLUGIN_DOMAIN)
            )
        );
    }

    /**
     * @param string $key
     * @param string|null $empty_value
     * @return false|string
     * @throws Exception
     */
    public function get_option($key, $empty_value = null)
    {
        $option_value = parent::get_option($key, $empty_value);
        if ($key === 'eb_PayMerchantKey') {
            $decrypted = self::decrypt(base64_decode($option_value), substr(NONCE_KEY, 0, 32));
            $option_value = $decrypted;
        }
        return $option_value;
    }

    /**
     * @return void
     */
    public function payment_fields()
    {
        global $woocommerce;

        $amount = 0;

        if (absint(get_query_var('order-pay'))) {
            $order_id = absint(get_query_var('order-pay'));
            $order = new WC_Order($order_id);
            $amount = $order->get_total();
        } elseif (!$woocommerce->cart->is_empty()) {
            $amount = $woocommerce->cart->total;
        }

        if ($description = $this->get_description()) {
            echo wpautop(wptexturize($description));
        }

        $max_installments = $this->pb_installments;
        $installments_variation = $this->eb_installments_variation;

        if (!empty($installments_variation)) {
            $max_installments = 1; // initialize the max installments
            $installments_split = explode(',', $installments_variation);
            foreach ($installments_split as $value) {
                $installment = explode(':', $value);
                if ((is_array($installment) && count($installment) !== 2) ||
                    (!is_numeric($installment[0]) || !is_numeric($installment[1]))) {
                    // not valid rule for installments
                    continue;
                }

                if ($amount >= ($installment[0])) {
                    $max_installments = $installment[1];
                }
            }
        }

        if ($max_installments > 1) {
            $doseis_field = '<p class="form-row "><label for="' . esc_attr($this->id) . '-card-doseis">' .
                __('Choose Installments', self::PLUGIN_DOMAIN) . ' <span class="required">*</span></label><select id="' .
                esc_attr($this->id) . '-card-doseis" name="' . esc_attr($this->id) . '-card-doseis" ' .
                'class="input-select wc-credit-card-form-card-doseis">';
            for ($i = 1; $i <= $max_installments; $i++) {
                $doseis_field .= '<option value="' . $i . '">' . ($i === 1 ? __('Without installments', self::PLUGIN_DOMAIN) : $i) . '</option>';
            }
            $doseis_field .= '</select></p>';

            echo $doseis_field;
        }
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return string
     * @throws Exception
     */
    public function validate_eb_PayMerchantKey_field($key, $value)
    {
        $encrypted = self::encrypt($value, substr(NONCE_KEY, 0, 32));
        return base64_encode($encrypted);
    }

    /**
     * @return array
     */
    public function pb_get_installments()
    {
        for ($i = 1; $i <= 24; $i++) {
            $installment_list[$i] = $i;
        }
        return $installment_list;
    }

    /**
     * @param string|bool $title
     * @param bool $indent
     * @return array
     */
    public function pb_get_pages($title = false, $indent = true)
    {
        $wp_pages = get_pages('sort_column=menu_order');
        $page_list = array();

        if ($title) {
            $page_list[] = $title;
        }

        foreach ($wp_pages as $page) {
            $prefix = '';

            if ($indent) {
                $has_parent = $page->post_parent;
                while ($has_parent) {
                    $prefix .= ' - ';
                    $next_page = get_post($has_parent);
                    $has_parent = $next_page->post_parent;
                }
            }

            $page_list[$page->ID] = $prefix . $page->post_title;
        }

        $page_list[-1] = __('Thank you page', self::PLUGIN_DOMAIN);
        return $page_list;
    }

    /**
     * @param string $input
     * @return string
     */
    private function calculate_digest($input)
    {
        return base64_encode(hash('sha256', ($input), true));
    }

    /**
     * @param int $order_id
     * @return string
     */
    public function generate_eurobank_form($order_id)
    {
        global $wpdb;

        $availableLocales = array(
            'en' => 'en',
            'en_US' => 'en',
            'en_AU' => 'en',
            'en_CA' => 'en',
            'en_GB' => 'en',
            'en_NZ' => 'en',
            'en_ZA' => 'en',
            'el' => 'el'
        );

        $version = 2;
        $currency = 'EUR';
        $locale = get_locale();

        $lang = $availableLocales[$locale] ?? 'en';

        $order = new WC_Order($order_id);

        if (method_exists($order, 'get_meta')) {
            $installments = $order->get_meta('_doseis');
            if ($installments === '') {
                $installments = 1;
            }
        } else {
            $installments = get_post_meta($order_id, '_doseis', 1);
        }

        $trType = $this->eb_transactionType === 'yes' ? 2 : 1;

        //  store TranTicket in table
        $wpdb->delete($wpdb->prefix . 'eurobank_transactions', array('merch_ref' => $order_id));
        $wpdb->insert($wpdb->prefix . 'eurobank_transactions', array('trans_ticket' => $order_id, 'merch_ref' => $order_id, 'timestamp' => current_time('mysql', 1)));

        //redirect to payment
        $country = sanitize_text_field($order->get_billing_country());
        $state_code = sanitize_text_field($order->get_billing_state());

        wc_enqueue_js('
             $.blockUI({
             message: "' . esc_js(__('Thank you for your order. We are now redirecting you to Eurobank to make payment.', self::PLUGIN_DOMAIN)) . '",
             baseZ: 99999,
             overlayCSS:
             {
             background: "#fff",
             opacity: 0.6
             },
             css: {
             padding:        "20px",
             zindex:         "9999999",
             textAlign:      "center",
             color:          "#555",
             border:         "3px solid #aaa",
             backgroundColor:"#fff",
             cursor:         "wait",
             lineHeight:		"24px",
             }
             });
             jQuery("#eb_payment_form").submit();
             ');

        $_SESSION['order_id'] = $order_id;
        WC()->session->set('eb_order_id', $order_id);

        $form_data_array = [
            'version' => $version,
            'mid' => esc_attr($this->eb_PayMerchantId),
            'lang' => $lang,
            'deviceCategory' => '0',
            'orderid' => $order_id . 'at' . date('Ymdhisu'),
            'orderDesc' => 'Order #' . $order_id,
            'orderAmount' => $order->get_total(),
            'currency' => $currency,
            'payerEmail' => $order->get_billing_email(),
            'billCountry' => $country,
            'billState' => $state_code,
            'billZip' => $order->get_billing_postcode(),
            'billCity' => $order->get_billing_city(),
            'billAddress' => $order->get_billing_address_1(),
            'trType' => $trType,
            'confirmUrl' => get_site_url() . "/?wc-api=WC_eurobank_Gateway&result=success",
            'cancelUrl' => get_site_url() . "/?wc-api=WC_eurobank_Gateway&result=failure",
            'var2' => $order_id,
        ];

        if ($installments > 1) {
            $form_data_array['extInstallmentoffset'] = 0;
            $form_data_array['extInstallmentperiod'] = $installments;
        }

        if (strtolower($country) === 'gr') {
            unset($form_data_array['billState']);
        }

        $form_secret = $this->eb_PayMerchantKey;
        $form_data = iconv('utf-8', 'utf-8//IGNORE', implode("", $form_data_array)) . $form_secret;
        $digest = $this->calculate_digest($form_data);

        if ($this->eb_enable_log === 'yes') {
            error_log('---- Eurobank Transaction digest -----');
            error_log('Data: ');
            error_log(print_r($form_data, true));
            error_log('Digest: ');
            error_log(print_r($digest, true));
            error_log('---- End of Eurobank Transaction digest ----');
        }

        $send_it_2 = $this->test_mode !== "yes" ? "https://vpos.eurocommerce.gr/vpos/shophandlermpi" : "https://eurocommerce-test.cardlink.gr/vpos/shophandlermpi";

        $html = '<form action="' . esc_url($send_it_2) . '" method="post" id="eb_payment_form" target="_top" accept-charset="UTF-8">';

        foreach ($form_data_array as $key => $value) {
            $html .= '<input type="hidden" id="' . $key . '" name="' . $key . '" value="' . iconv('utf-8', 'utf-8//IGNORE', $value) . '"/>';
        }

        $html .= '<input type="hidden" id="digest" name="digest" value="' . esc_attr($digest) . '"/>';
        $html .= '</form>';

        return $html;
    }

    /**
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id)
    {
        $order = new WC_Order($order_id);
        $doseis = (int)$_POST[esc_attr($this->id) . '-card-doseis'];

        if ($doseis > 0) {
            $this->generic_add_meta($order_id, '_doseis', $doseis);
        }

        return array(
            'result' => 'success',
            'redirect' => add_query_arg('order-pay', $order->get_id(), add_query_arg('key', $order->get_order_key(), wc_get_page_permalink('checkout')))
        );
    }

    /**
     * @param int $order_id
     * @return void
     */
    public function receipt_page($order_id)
    {
        echo '<p>' . __('Thank you - your order is now pending payment. You should be automatically redirected to Eurobank Paycenter to make payment.', self::PLUGIN_DOMAIN) . '</p>';
        echo $this->generate_eurobank_form($order_id);
    }

    /**
     * @return void
     */
    public function check_eurobank_response()
    {
        if ($this->eb_enable_log === 'yes') {
            error_log('---- Eurobank Response -----');
            error_log(print_r($_POST, true));
            error_log('---- End of Eurobank Response ----');
        }

        $orderid_session = WC()->session->get('eb_order_id');
        $orderid_post = filter_var($_POST['orderid'], FILTER_SANITIZE_STRING);

        preg_match('/^(.*?)at/', $orderid_post, $matches);

        $orderid = !empty($matches) ? $matches[1] : $orderid_session;

        if ($orderid === '') {
            $orderid = $orderid_post;
            error_log("Eurobank: something went wrong with order id ");
            error_log(print_r($_POST, true));
            error_log(print_r($matches, true));
            error_log($orderid_session);
        }

        $status = filter_var($_POST['status'], FILTER_SANITIZE_STRING);
        $message = isset($_POST['message']) ? filter_var($_POST['message'], FILTER_SANITIZE_STRING) : '';
        $paymentRef = isset($_POST['paymentRef']) ? filter_var($_POST['paymentRef'], FILTER_SANITIZE_STRING) : '';
        $digest = isset($_POST['digest']) ? (string) $_POST['digest'] : '';

        // Build digest deterministically using the exact field order and unslashed raw values
        $fields_for_digest = array(
            'version',
            'mid',
            'orderid',
            'status',
            'orderAmount',
            'currency',
            'paymentTotal',
            'message',
            'riskScore',
            'payMethod',
            'txId',
            'paymentRef'
        );

        $concat = '';
        foreach ($fields_for_digest as $f) {
            $concat .= isset($_POST[$f]) ? (string) wp_unslash($_POST[$f]) : '';
        }

        // Keep encoding normalization consistent with request-side hashing
        $form_data = iconv('UTF-8', 'UTF-8//IGNORE', $concat) . $this->eb_PayMerchantKey;
        $computed_digest = $this->calculate_digest($form_data);

        if ($this->eb_enable_log === 'yes') {
            error_log('---- Eurobank Debug Logs Before Comparison -----');
            error_log('Fields order: ' . implode(',', $fields_for_digest));
            error_log('Concat: ' . $concat);
            error_log('Form data used for digest: ' . $form_data);
            error_log('computed_digest: ' . $computed_digest);
            error_log('digest: ' . $digest);
            error_log('---- End of Eurobank Debug Logs Before Comparison ----');
        }

        $order = new WC_Order($orderid);

        if ($digest !== $computed_digest) {
            $message = __('A technical problem occurred. <br />The transaction wasn\'t successful, payment wasn\'t received.', self::PLUGIN_DOMAIN);
            $message_type = 'error';
            $eb_message = array('message' => $message, 'message_type' => $message_type);
            $this->generic_add_meta($orderid, '_eurobank_message', $eb_message);
            $order->update_status('failed', 'DIGEST');
            $checkout_url = wc_get_checkout_url();
            wp_redirect($checkout_url);
            exit;
        }

        if ($status === 'CAPTURED' || $status === 'AUTHORIZED') {
            $order->payment_complete($paymentRef);

            if ($order->get_status() === 'processing') {
                $order->add_order_note(__('Payment Via Eurobank<br />Transaction ID: ', self::PLUGIN_DOMAIN) . $paymentRef);
                $message = __('Thank you for shopping with us.<br />Your transaction was successful, payment was received.<br />Your order is currently being processed.', self::PLUGIN_DOMAIN);

                if ($this->eb_order_note === 'yes') {
                    $order->add_order_note(__('Payment Received.<br />Your order is currently being processed.<br />We will be shipping your order to you soon.<br />Eurobank Transaction ID: ', self::PLUGIN_DOMAIN) . $paymentRef, 1);
                }
            } elseif ($order->get_status() === 'completed') {
                $message = __('Thank you for shopping with us.<br />Your transaction was successful, payment was received.<br />Your order is now complete.', self::PLUGIN_DOMAIN);
                if ($this->eb_order_note === 'yes') {
                    $order->add_order_note(__('Payment Received.<br />Your order is now complete.<br />Eurobank Transaction ID: ', self::PLUGIN_DOMAIN) . $paymentRef, 1);
                }
            }

            $this->updateStatus($message, 'success', $order);
            WC()->cart->empty_cart();
        } elseif ($status === 'CANCELED') {
            $this->updateStatus('Thank you for shopping with us. <br />However, the transaction wasn\'t successful, payment was cancelled.', 'notice', $order, 'failed', 'ERROR ' . $message);
        } elseif ($status === 'REFUSED') {
            $this->updateStatus('Thank you for shopping with us. <br />However, the transaction wasn\'t successful, payment wasn\'t received.', 'error', $order, 'failed', 'REFUSED ' . $message);
        } elseif ($status === 'ERROR') {
            $this->updateStatus('Thank you for shopping with us. <br />However, the transaction wasn\'t successful, payment wasn\'t received.', 'error', $order, 'failed', 'ERROR ' . $message);
        } else {
            $this->updateStatus('Thank you for shopping with us. <br />However, the transaction wasn\'t successful, payment wasn\'t received.', 'error', $order, 'failed', 'Unknown: ' . $message);
        }

        $redirect_url = in_array((string)$this->redirect_page_id, ['', '0', '-1']) ? $this->get_return_url($order) : get_permalink($this->redirect_page_id);
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * @param int $order_id
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function generic_add_meta($order_id, $key, $value)
    {
        $order = new WC_Order($order_id);
        if (method_exists($order, 'add_meta_data') && method_exists($order, 'save_meta_data')) {
            $order->add_meta_data($key, $value, true);
            $order->save_meta_data();
        } else {
            update_post_meta($order_id, $key, $value);
        }
    }

    /**
     * @param string $client_message
     * @param string $message_type
     * @param WC_Order $order
     * @param string $status
     * @param string $message
     * @return void
     */
    protected function updateStatus($client_message, $message_type, $order, $status = null, $message = null)
    {
        $eb_message = array('message' => __($client_message, self::PLUGIN_DOMAIN), 'message_type' => $message_type);
        $this->generic_add_meta($order->get_id(), '_eurobank_message', $eb_message);

        if ($status !== null) {
            $order->update_status($status, $message);
        }
    }

    /**
     * @param string $message
     * @param string $key
     * @return string
     * @throws Exception
     */
    private static function encrypt($message, $key)
    {
        if (mb_strlen($key, '8bit') !== 32) {
            throw new Exception("Needs a 256-bit key! ".mb_strlen($key, '8bit'));
        }
        $ivsize = openssl_cipher_iv_length(self::ENCRYPTION_METHOD);
        $iv = openssl_random_pseudo_bytes($ivsize);

        $ciphertext = openssl_encrypt(
            $message,
            self::ENCRYPTION_METHOD,
            $key,
            0,
            $iv
        );

        return $iv . $ciphertext;
    }

    /**
     * @param string $message
     * @param string $key
     * @return false|string
     * @throws Exception
     */
    private static function decrypt($message, $key)
    {
        if (mb_strlen($key, '8bit') !== 32) {
            throw new Exception("Needs a 256-bit key! ".mb_strlen($key, '8bit'));
        }
        $ivsize = openssl_cipher_iv_length(self::ENCRYPTION_METHOD);
        $iv = mb_substr($message, 0, $ivsize, '8bit');
        $ciphertext = mb_substr($message, $ivsize, null, '8bit');

        return openssl_decrypt(
            $ciphertext,
            self::ENCRYPTION_METHOD,
            $key,
            0,
            $iv
        );
    }
}
