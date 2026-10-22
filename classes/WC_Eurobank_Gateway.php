<?php

namespace Papaki\Eurobank\WooCommerce;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @property string $notify_url
 * @property string|null $redirect_page_id
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
class WC_Eurobank_Gateway extends \WC_Payment_Gateway {
    const PLUGIN_TITLE          = Application::PLUGIN_TITLE;
    const TEXT_DOMAIN           = Application::TEXT_DOMAIN;
    public const ENCRYPTION_METHOD     = 'aes-128-cbc';
    public const ENCRYPTION_KEY_LENGTH = 32; /* 32-byte NONCE_KEY slice; cipher stays AES-128 — matches the released 2.0.x storage format. Do NOT switch to AES-256 (breaks stored secrets). See .docs/adr/0001. */

    /**
     * Currency the plugin sends to the bank and expects back.
     */
    public const CURRENCY = 'EUR';

    /**
     * Response fields the bank signs, in the order the digest is computed over.
     */
    public const RESPONSE_DIGEST_FIELDS = array( 'version', 'mid', 'orderid', 'status', 'orderAmount', 'currency', 'paymentTotal', 'message', 'riskScore', 'payMethod', 'txId', 'paymentRef' );

    /**
     * Payment method IDs whose orders the bank-return handler may update.
     */
    public const OWN_PAYMENT_METHODS = array( 'eurobank_gateway' );

    /**
     * Tags and attributes the redirect form may contain when it is echoed through wp_kses().
     */
    public const FORM_ALLOWED_HTML = array(
        'form'  => array( 'action' => true, 'method' => true, 'id' => true, 'target' => true, 'accept-charset' => true ),
        'input' => array( 'type' => true, 'id' => true, 'name' => true, 'value' => true ),
    );

    protected string $notify_url;
    protected ?string $redirect_page_id;
    protected string $eb_PayMerchantId;
    protected string $eb_PayMerchantKey;
    protected int $pb_installments;
    protected string $test_mode;
    protected string $eb_installments_variation;
    protected string $eb_transactionType;
    protected string $eb_render_logo;
    protected string $eb_enable_log;
    protected string $eb_order_note;

    public function __construct() {
        $this->id                 = 'eurobank_gateway';
        $this->has_fields         = true;
        $this->notify_url         = WC()->api_request_url( 'WC_Eurobank_Gateway' );
        $this->method_description = sprintf(
            /* translators: %s: payment method title */
            __( '%s allows you to accept payment through various channels such as Maestro, Mastercard, AMex cards, Diners and Visa cards On your Woocommerce Powered Site.', 'woo-payment-gateway-for-eurobank' ),
            static::PLUGIN_TITLE
        );
        $this->redirect_page_id   = $this->get_option( 'redirect_page_id' );
        $this->method_title       = static::PLUGIN_TITLE;

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Define user set variables
        $this->title                     = sanitize_text_field( $this->get_option( 'title' ) );
        $this->description               = sanitize_text_field( $this->get_option( 'description' ) );
        $this->eb_PayMerchantId          = sanitize_text_field( $this->get_option( 'eb_PayMerchantId' ) );
        $this->eb_PayMerchantKey         = sanitize_text_field( $this->get_option( 'eb_PayMerchantKey' ) );
        $this->pb_installments           = absint( $this->get_option( 'pb_installments' ) );
        $this->test_mode                 = sanitize_text_field( $this->get_option( 'test_mode' ) );
        $this->eb_installments_variation = sanitize_text_field( $this->get_option( 'eb_installments_variation' ) );
        $this->eb_transactionType        = sanitize_text_field( $this->get_option( 'eb_transactionType' ) );
        $this->eb_render_logo            = sanitize_text_field( $this->get_option( 'eb_render_logo' ) );
        $this->eb_enable_log             = sanitize_text_field( $this->get_option( 'eb_enable_log' ) );
        $this->eb_order_note             = sanitize_text_field( $this->get_option( 'eb_order_note' ) );
        //Actions
        add_action( 'woocommerce_receipt_eurobank_gateway', array( $this, 'receipt_page' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        // Payment listener/API hook
        add_action( 'woocommerce_api_wc_eurobank_gateway', array( $this, 'check_eurobank_response' ) );

        if ( $this->eb_render_logo === "yes" ) {
            $this->icon = apply_filters( 'eurobank_icon', plugins_url( '../img/eurobank.svg', __FILE__ ) );
        }
    }

    /**
     * Hide the gateway at checkout until the merchant ID and merchant key are saved.
     *
     * Without both, the bank rejects the request and the plugin cannot verify the response.
     * A stored key that fails to decrypt reaches the constructor as '' and counts as missing.
     *
     * @return bool
     */
    public function is_available() {
        if ( $this->eb_PayMerchantId === '' || $this->eb_PayMerchantKey === '' ) {
            return false;
        }

        return parent::is_available();
    }

    /**
     * @return void
     */
    public function admin_options() {
        echo '<h3>' . esc_html( static::PLUGIN_TITLE ) . '</h3>';
        echo '<p>' . esc_html(
            sprintf(
                /* translators: %s: payment method title */
                __( '%s allows you to accept payment through various channels such as Maestro, Mastercard, AMex cards, Diners  and Visa cards.', 'woo-payment-gateway-for-eurobank' ),
                static::PLUGIN_TITLE
            )
        ) . '</p>';

        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    /**
     * @return void
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'                   => array(
                'title'       => __( 'Enable/Disable', 'woo-payment-gateway-for-eurobank' ),
                'type'        => 'checkbox',
                'label'       => sprintf(
                    /* translators: %s: payment method title */
                    __( 'Enable %s', 'woo-payment-gateway-for-eurobank' ),
                    static::PLUGIN_TITLE
                ),
                'description' => __( 'Enable or disable the gateway.', 'woo-payment-gateway-for-eurobank' ),
                'desc_tip'    => true,
                'default'     => 'yes',
            ),
            'test_mode'                 => array(
                'title'       => __( 'Test Environment', 'woo-payment-gateway-for-eurobank' ),
                'type'        => 'checkbox',
                'label'       => sprintf(
                    /* translators: %s: payment method title */
                    __( 'Enable %s test environment', 'woo-payment-gateway-for-eurobank' ),
                    static::PLUGIN_TITLE
                ),
                'description' => __( 'Enable or disable the test environment.', 'woo-payment-gateway-for-eurobank' ),
                'desc_tip'    => true,
                'default'     => 'no',
            ),
            'title'                     => array(
                'title'       => __( 'Title', 'woo-payment-gateway-for-eurobank' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'woo-payment-gateway-for-eurobank' ),
                'desc_tip'    => false,
                'default'     => __( 'Credit card via Eurobank', 'woo-payment-gateway-for-eurobank' ),
            ),
            'description'               => array(
                'title'       => __( 'Description', 'woo-payment-gateway-for-eurobank' ),
                'type'        => 'textarea',
                'description' => __( 'This controls the description which the user sees during checkout.', 'woo-payment-gateway-for-eurobank' ),
                'default'     => __( 'Pay Via Eurobank: Accepts  Mastercard, Visa cards and etc.', 'woo-payment-gateway-for-eurobank' ),
            ),
            'eb_render_logo'            => array(
                'title'       => __( 'Display the logo of EuroBank', 'woo-payment-gateway-for-eurobank' ),
                'type'        => 'checkbox',
                'description' => __( 'Enable to display the logo of EuroBank next to the title which the user sees during checkout.', 'woo-payment-gateway-for-eurobank' ),
                'default'     => 'yes',
            ),
            'eb_PayMerchantId'          => array(
                'title'       => __( 'Eurobank Merchant ID', 'woo-payment-gateway-for-eurobank' ),
                'type'        => 'text',
                'description' => __( 'Enter Your Eurobank Merchant ID', 'woo-payment-gateway-for-eurobank' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'eb_PayMerchantKey'         => array(
                'title'       => __( 'Eurobank Merchant KEY', 'woo-payment-gateway-for-eurobank' ),
                'type'        => 'password',
                'description' => __( 'Enter Your Eurobank Merchant KEY', 'woo-payment-gateway-for-eurobank' ),
                'default'     => '',
                'desc_tip'    => false,
            ),
            'redirect_page_id'          => array(
                'title'       => __( 'Return page URL <br />(Successful or Failed Transactions)', 'woo-payment-gateway-for-eurobank' ),
                'type'        => 'select',
                'options'     => $this->pb_get_pages( 'Select Page' ),
                'description' => __( 'We recommend you to select the default “Thank You Page”, in order to automatically serve both successful and failed transactions, with the latter also offering the option to try the payment again.<br /> If you select a different page, you will have to handle failed payments yourself by adding custom code.', 'woo-payment-gateway-for-eurobank' ),
                'default'     => "-1",
            ),
            'pb_installments'           => array(
                'title'       => __( 'Maximum number of installments regardless of the total order amount', 'woo-payment-gateway-for-eurobank' ),
                'type'        => 'select',
                'options'     => $this->pb_get_installments(),
                'description' => __( '1 to 24 Installments,1 for one time payment. You must contact Eurobank first<br /> If you have filled the "Max Number of installments depending on the total order amount"', 'woo-payment-gateway-for-eurobank' ),
            ),
            'eb_installments_variation' => array(
                'title'       => __( 'Maximum number of installments depending on the total order amount', 'woo-payment-gateway-for-eurobank' ),
                'type'        => 'text',
                'description' => __( 'Example 80:2, 160:4, 300:8</br> total order greater or equal to 80 -> allow 2 installments, total order greater or equal to 160 -> allow 4 installments, total order greater or equal to 300 -> allow 8 installments</br> Leave the field blank if you do not want to limit the number of installments depending on the amount of the order.', 'woo-payment-gateway-for-eurobank' ),
            ),
            'eb_transactionType'        => array(
                'title'   => __( 'Pre-Authorize', 'woo-payment-gateway-for-eurobank' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable to capture preauthorized payments', 'woo-payment-gateway-for-eurobank' ),
                'default' => 'no',
            ),
            'eb_enable_log'             => array(
                'title'       => __( 'Enable Debug mode', 'woo-payment-gateway-for-eurobank' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enabling this will log certain information', 'woo-payment-gateway-for-eurobank' ),
                'default'     => 'no',
                'description' => __( 'Enabling this (and the debug mode from your wp-config file) will log information, e.g. bank responses, which will help in debugging issues.', 'woo-payment-gateway-for-eurobank' ),
            ),
            'eb_order_note'             => array(
                'title'       => __( 'Enable 2nd “payment received” email', 'woo-payment-gateway-for-eurobank' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable sending Customer order note with transaction details', 'woo-payment-gateway-for-eurobank' ),
                'default'     => 'no',
                'description' => __( 'Enabling this will send an email with the support reference id and transaction id to the customer, after the transaction has been completed (either on success or failure)', 'woo-payment-gateway-for-eurobank' ),
            ),
        );
    }

    /**
     * @param string $key
     * @param string|null $empty_value
     * @return false|string
     * @throws Exception
     */
    public function get_option( $key, $empty_value = null ) {
        $option_value = parent::get_option( $key, $empty_value );
        if ( $key === 'eb_PayMerchantKey' ) {
            $decrypted    = self::decrypt( base64_decode( $option_value ), substr( NONCE_KEY, 0, self::ENCRYPTION_KEY_LENGTH ) );
            $option_value = $decrypted;
        }
        return $option_value;
    }

    /**
     * @return void
     */
    public function payment_fields() {
        global $woocommerce;

        $amount = 0;

        if ( absint( get_query_var( 'order-pay' ) ) ) {
            $order_id = absint( get_query_var( 'order-pay' ) );
            $order    = new \WC_Order( $order_id );
            $amount   = $order->get_total();
        } elseif ( ! $woocommerce->cart->is_empty() ) {
            $amount = $woocommerce->cart->total;
        }

        if ( $description = $this->get_description() ) {
            echo wp_kses_post( wpautop( wptexturize( $description ) ) );
        }

        $max_installments       = $this->pb_installments;
        $installments_variation = $this->eb_installments_variation;

        if ( ! empty( $installments_variation ) ) {
            $max_installments   = 1; // initialize the max installments
            $installments_split = explode( ',', $installments_variation );
            foreach ($installments_split as $value) {
                $installment = explode( ':', $value );
                if ( ( is_array( $installment ) && count( $installment ) !== 2 ) ||
                    ( ! is_numeric( $installment[0] ) || ! is_numeric( $installment[1] ) ) ) {
                    // not valid rule for installments
                    continue;
                }

                if ( $amount >= ( $installment[0] ) ) {
                    $max_installments = $installment[1];
                }
            }
        }

        if ( $max_installments > 1 ) {
            $doseis_field = '<p class="form-row "><label for="' . esc_attr( $this->id ) . '-card-doseis">' .
                esc_html__( 'Choose Installments', 'woo-payment-gateway-for-eurobank' ) . ' <span class="required">*</span></label><select id="' .
                esc_attr( $this->id ) . '-card-doseis" name="' . esc_attr( $this->id ) . '-card-doseis" ' .
                'class="input-select wc-credit-card-form-card-doseis">';
            for ( $i = 1; $i <= $max_installments; $i++ ) {
                $doseis_field  .= '<option value="' . absint( $i ) . '">' . ( $i === 1 ? esc_html__( 'Without installments', 'woo-payment-gateway-for-eurobank' ) : absint( $i ) ) . '</option>';
            }
            $doseis_field  .= '</select></p>';

            echo wp_kses(
                $doseis_field,
                array(
                    'p'      => array( 'class' => true ),
                    'label'  => array( 'for' => true ),
                    'span'   => array( 'class' => true ),
                    'select' => array( 'id' => true, 'name' => true, 'class' => true ),
                    'option' => array( 'value' => true ),
                )
            );
        }
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return string
     * @throws \Exception
     */
    public function validate_eb_PayMerchantKey_field( $key, $value ) {
        $encrypted = self::encrypt( $value, substr( NONCE_KEY, 0, self::ENCRYPTION_KEY_LENGTH ) );
        return base64_encode( $encrypted );
    }

    /**
     * @return array
     */
    public function pb_get_installments() {
        for ( $i = 1; $i <= 24; $i++ ) {
            $installment_list[ $i ] = $i;
        }
        return $installment_list;
    }

    /**
     * @param string|bool $title
     * @param bool $indent
     * @return array
     */
    public function pb_get_pages( $title = false, $indent = true ) {
        $wp_pages  = get_pages( 'sort_column=menu_order' );
        $page_list = array();

        if ( $title ) {
            $page_list[] = $title;
        }

        foreach ($wp_pages as $page) {
            $prefix = '';

            if ( $indent ) {
                $has_parent = $page->post_parent;
                while ( $has_parent ) {
                    $prefix     .= ' - ';
                    $next_page   = get_post( $has_parent );
                    $has_parent  = $next_page->post_parent;
                }
            }

            $page_list[ $page->ID ] = $prefix . $page->post_title;
        }

        $page_list[ -1 ] = __( 'Thank you page', 'woo-payment-gateway-for-eurobank' );
        return $page_list;
    }

    /**
     * @param string $input
     * @return string
     */
    private function calculate_digest( $input ) {
        return base64_encode( hash( 'sha256', ( $input ), true ) );
    }

    /**
     * @param int $order_id
     * @return string
     */
    public function generate_eurobank_form( $order_id ) {
        global $wpdb;

        $availableLocales = array(
            'en'    => 'en',
            'en_US' => 'en',
            'en_AU' => 'en',
            'en_CA' => 'en',
            'en_GB' => 'en',
            'en_NZ' => 'en',
            'en_ZA' => 'en',
            'el'    => 'el',
        );

        $version  = 2;
        $currency = static::CURRENCY;
        $locale   = get_locale();

        $lang = $availableLocales[ $locale ] ?? 'en';

        $order = new \WC_Order( $order_id );

        $installments = $order->get_meta( '_doseis' );
        if ( $installments === '' ) {
            $installments = 1;
        }

        $trType = $this->eb_transactionType === 'yes' ? 2 : 1;

        //  store TranTicket in table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete from the plugin's own table; WordPress has no API for it.
        $wpdb->delete( $wpdb->prefix . 'eurobank_transactions', array( 'merch_ref' => $order_id ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert into the plugin's own table; WordPress has no API for it.
        $wpdb->insert( $wpdb->prefix . 'eurobank_transactions', array( 'trans_ticket' => $order_id, 'merch_ref' => $order_id, 'timestamp' => current_time( 'mysql', 1 ) ) );

        //redirect to payment
        $country    = sanitize_text_field( $order->get_billing_country() );
        $state_code = sanitize_text_field( $order->get_billing_state() );

        wc_enqueue_js( '
             $.blockUI({
             message: "' . esc_js( __( 'Thank you for your order. We are now redirecting you to Eurobank to make payment.', 'woo-payment-gateway-for-eurobank' ) ) . '",
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
             lineHeight:        "24px",
             }
             });
             jQuery("#eb_payment_form").submit();
             ' );

        $_SESSION['order_id'] = $order_id;
        WC()->session->set( 'eb_order_id', $order_id );

        $form_data_array = [
            'version'        => $version,
            'mid'            => $this->eb_PayMerchantId,
            'lang'           => $lang,
            'deviceCategory' => '0',
            'orderid'        => $order_id . 'at' . wp_date( 'Ymdhisu' ),
            'orderDesc'      => 'Order #' . $order_id,
            'orderAmount'    => $order->get_total(),
            'currency'       => $currency,
            'payerEmail'     => $order->get_billing_email(),
            'billCountry'    => $country,
            'billState'      => $state_code,
            'billZip'        => $order->get_billing_postcode(),
            'billCity'       => $order->get_billing_city(),
            'billAddress'    => $order->get_billing_address_1(),
            'trType'         => $trType,
            'extInstallmentoffset' => null,
            'extInstallmentperiod' => null,
            'confirmUrl'     => get_site_url() . "/?wc-api=WC_eurobank_Gateway&result=success",
            'cancelUrl'      => get_site_url() . "/?wc-api=WC_eurobank_Gateway&result=failure",
            'var2'           => $order_id,
        ];

        if ( $installments > 1 ) {
            $form_data_array['extInstallmentoffset'] = 0;
            $form_data_array['extInstallmentperiod'] = $installments;
        }

        if ( strtolower( $country ) === 'gr' ) {
            unset( $form_data_array['billState'] );
        }

        $form_secret = $this->eb_PayMerchantKey;
        $form_data   = iconv( 'utf-8', 'utf-8//IGNORE', implode( "", $form_data_array ) ) . $form_secret;
        $digest      = $this->calculate_digest( $form_data );

        // A09-2021 Security Logging and Monitoring Failures fix:
        // if ($this->eb_enable_log === 'yes') {
        //     error_log('---- Eurobank Transaction digest -----');
        //     error_log('Data: ');
        //     error_log(print_r($form_data, true));
        //     error_log('Digest: ');
        //     error_log(print_r($digest, true));
        //     error_log('---- End of Eurobank Transaction digest ----');
        // }
        $this->safe_log( '---- Eurobank Transaction digest -----' );
        $this->safe_log( 'Data: ', $form_data );
        $this->safe_log( 'Digest: ', $digest );
        $this->safe_log( '---- End of Eurobank Transaction digest ----' );

        $send_it_2 = $this->test_mode !== "yes" ? "https://vpos.eurocommerce.gr/vpos/shophandlermpi" : "https://eurocommerce-test.cardlink.gr/vpos/shophandlermpi";

        $html = '<form action="' . esc_url( $send_it_2 ) . '" method="post" id="eb_payment_form" target="_top" accept-charset="UTF-8">';

        foreach ( $form_data_array as $key => $value ) {
            $html  .= '<input type="hidden" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( iconv( 'utf-8', 'utf-8//IGNORE', (string) $value ) ) . '"/>';
        }

        $html  .= '<input type="hidden" id="digest" name="digest" value="' . esc_attr( $digest ) . '"/>';
        $html  .= '</form>';

        return $html;
    }

    /**
     * @param int $order_id
     * @return array
     */
    public function process_payment( $order_id ) {
        $order  = new \WC_Order( $order_id );
        $k      = $this->id . '-card-doseis';
        $doseis = isset( $_POST[ $k ] ) ? absint( wp_unslash( $_POST[ $k ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout or Store API nonce before it calls process_payment().

        if ( $doseis > 0 ) {
            $this->generic_add_meta( $order_id, '_doseis', $doseis );
        }

        return array(
            'result'   => 'success',
            'redirect' => add_query_arg( 'order-pay', $order->get_id(), add_query_arg( 'key', $order->get_order_key(), wc_get_page_permalink( 'checkout' ) ) ),
        );
    }

    /**
     * @param int $order_id
     * @return void
     */
    public function receipt_page( $order_id ) {
        echo '<p>' . esc_html__( 'Thank you - your order is now pending payment. You should be automatically redirected to Eurobank Paycenter to make payment.', 'woo-payment-gateway-for-eurobank' ) . '</p>';
        echo wp_kses( $this->generate_eurobank_form( $order_id ), static::FORM_ALLOWED_HTML );
    }

    /**
     * Authenticate the bank's return POST and update the order it names.
     *
     * Runs on the public woocommerce_api_wc_eurobank_gateway endpoint, so the request is untrusted
     * until authenticate_response() accepts it. A rejected request changes no order: it gets a generic
     * notice and a redirect to checkout. An accepted request may change the order status, add order
     * notes, write _eurobank_message meta and empty the cart.
     *
     * @return void
     */
    public function check_eurobank_response() {
        $response = $this->read_response();
        $this->safe_log( 'Eurobank response', $response );

        $order = $this->authenticate_response( $response );
        if ( ! $order ) {
            wc_add_notice( __( 'A technical problem occurred. <br />The transaction wasn\'t successful, payment wasn\'t received.', 'woo-payment-gateway-for-eurobank' ), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        $redirect_url = $this->get_response_redirect_url( $order );

        if ( ! $order->needs_payment() ) {
            $this->safe_log( 'Eurobank: response ignored, the order no longer needs payment', array( 'order_id' => $order->get_id(), 'status' => $response['status'] ) );
            wp_safe_redirect( $redirect_url );
            exit;
        }

        $status     = sanitize_text_field( $response['status'] );
        $message    = sanitize_text_field( $response['message'] );
        $paymentRef = sanitize_text_field( $response['paymentRef'] );

        if ( ( $status === 'CAPTURED' || $status === 'AUTHORIZED' ) && ! $this->response_matches_order( $response, $order ) ) {
            $order->update_status(
                'on-hold',
                sprintf(
                    /* translators: 1: amount reported by the bank, 2: currency, 3: merchant ID, 4: bank transaction ID */
                    __( 'Eurobank reported a payment that does not match this order (amount %1$s %2$s, merchant ID %3$s, transaction ID %4$s). Check it in the Eurobank back office before fulfilling the order.', 'woo-payment-gateway-for-eurobank' ),
                    sanitize_text_field( $response['orderAmount'] ),
                    sanitize_text_field( $response['currency'] ),
                    sanitize_text_field( $response['mid'] ),
                    $paymentRef
                )
            );
            wp_safe_redirect( $redirect_url );
            exit;
        }

        if ( $status === 'CAPTURED' || $status === 'AUTHORIZED' ) {
            $order->payment_complete( $paymentRef );

            if ( $order->get_status() === 'processing' ) {
                $order->add_order_note( __( 'Payment Via Eurobank<br />Transaction ID: ', 'woo-payment-gateway-for-eurobank' ) . $paymentRef );
                $message = __( 'Thank you for shopping with us.<br />Your transaction was successful, payment was received.<br />Your order is currently being processed.', 'woo-payment-gateway-for-eurobank' );

                if ( $this->eb_order_note === 'yes' ) {
                    $order->add_order_note( __( 'Payment Received.<br />Your order is currently being processed.<br />We will be shipping your order to you soon.<br />Eurobank Transaction ID: ', 'woo-payment-gateway-for-eurobank' ) . $paymentRef, 1 );
                }
            } elseif ( $order->get_status() === 'completed' ) {
                $message = __( 'Thank you for shopping with us.<br />Your transaction was successful, payment was received.<br />Your order is now complete.', 'woo-payment-gateway-for-eurobank' );
                if ( $this->eb_order_note === 'yes' ) {
                    $order->add_order_note( __( 'Payment Received.<br />Your order is now complete.<br />Eurobank Transaction ID: ', 'woo-payment-gateway-for-eurobank' ) . $paymentRef, 1 );
                }
            }

            $this->updateStatus( $message, 'success', $order );
            WC()->cart->empty_cart();
        } elseif ( $status === 'CANCELED' ) {
            $this->updateStatus( __( 'Thank you for shopping with us. <br />However, the transaction wasn\'t successful, payment was cancelled.', 'woo-payment-gateway-for-eurobank' ), 'notice', $order, 'failed', 'ERROR ' . $message );
        } elseif ( $status === 'REFUSED' ) {
            $this->updateStatus( __( 'Thank you for shopping with us. <br />However, the transaction wasn\'t successful, payment wasn\'t received.', 'woo-payment-gateway-for-eurobank' ), 'error', $order, 'failed', 'REFUSED ' . $message );
        } elseif ( $status === 'ERROR' ) {
            $this->updateStatus( __( 'Thank you for shopping with us. <br />However, the transaction wasn\'t successful, payment wasn\'t received.', 'woo-payment-gateway-for-eurobank' ), 'error', $order, 'failed', 'ERROR ' . $message );
        } else {
            $this->updateStatus( __( 'Thank you for shopping with us. <br />However, the transaction wasn\'t successful, payment wasn\'t received.', 'woo-payment-gateway-for-eurobank' ), 'error', $order, 'failed', 'Unknown: ' . $message );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Read the bank's signed fields and digest from the POST body, unslashed but otherwise raw.
     *
     * The digest is computed over the exact values the bank sent, so nothing is sanitized here;
     * callers sanitize each value before storing or displaying it.
     *
     * @return array<string,string> Field name => raw value, '' when absent or not a string.
     */
    protected function read_response() {
        $response = array();
        foreach ( array_merge( static::RESPONSE_DIGEST_FIELDS, array( 'digest' ) ) as $field ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The bank's POST cannot carry a WordPress nonce; authenticate_response() verifies its digest instead. Raw values are required to recompute that digest.
            $value              = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
            $response[ $field ] = is_string( $value ) ? $value : '';
        }

        return $response;
    }

    /**
     * Return the order a bank response belongs to, or false when it must not touch any order.
     *
     * Accepts only when a merchant key is configured, version and mid are digits, mid equals the
     * saved merchant ID, orderid has the "{id}at{timestamp}" form that generate_eurobank_form()
     * sends, the digest matches in constant time, the order exists, and it was placed with one of
     * OWN_PAYMENT_METHODS. Logs the reason for a rejection when debug logging is on.
     *
     * @param array<string,string> $response Output of read_response().
     * @return \WC_Order|false
     */
    protected function authenticate_response( array $response ) {
        if ( $this->eb_PayMerchantKey === '' ) {
            $this->safe_log( 'Eurobank: response rejected, no merchant key is configured' );
            return false;
        }

        // The request digest (printed on the order-pay page) and the response digest are both
        // SHA-256 of plain concatenated fields plus the same shared secret, with no separators. A
        // shopper controls part of the request fields (billing address, city, zip, email), so they
        // could place a response-shaped string in them and replay the page's digest as a "signed"
        // response. Accept only the shape a genuine response has: digits for version and mid, then
        // "{digits}at{digits}" for orderid. A genuine response concatenation therefore runs digits
        // then "at", while every request concatenation runs digits (version, mid) then the language
        // code ("el" or "en"), so the two can never be equal.
        foreach ( array( 'version', 'mid' ) as $digits_only_field ) {
            if ( 1 !== preg_match( '/^\d+\z/', $response[ $digits_only_field ] ) ) {
                $this->safe_log( 'Eurobank: response rejected, unexpected ' . $digits_only_field . ' format' );
                return false;
            }
        }

        // Without separators, digits can also move between mid and orderid without changing the
        // digest: a genuine response for order 1234 re-sent as mid "{mid}12", orderid "34at…" would
        // name order 34. Pinning mid to the saved merchant ID fixes where orderid starts.
        if ( $response['mid'] !== $this->eb_PayMerchantId ) {
            $this->safe_log( 'Eurobank: response rejected, merchant ID does not match the saved one' );
            return false;
        }

        if ( 1 !== preg_match( '/^(\d+)at\d+\z/', $response['orderid'], $matches ) ) {
            $this->safe_log( 'Eurobank: response rejected, unexpected orderid format' );
            return false;
        }

        $concat = '';
        foreach ( static::RESPONSE_DIGEST_FIELDS as $field ) {
            $concat .= $response[ $field ];
        }
        $computed_digest = $this->calculate_digest( iconv( 'UTF-8', 'UTF-8//IGNORE', $concat ) . $this->eb_PayMerchantKey );

        if ( ! hash_equals( $computed_digest, $response['digest'] ) ) {
            $this->safe_log( 'Eurobank: response rejected, digest mismatch' );
            return false;
        }

        $order = wc_get_order( absint( $matches[1] ) );
        if ( ! $order || ! in_array( $order->get_payment_method(), static::OWN_PAYMENT_METHODS, true ) ) {
            $this->safe_log( 'Eurobank: response rejected, order not found or not paid with Eurobank' );
            return false;
        }

        return $order;
    }

    /**
     * Check that a signed response is for this merchant, in the plugin's currency, for the order total.
     *
     * @param array<string,string> $response Output of read_response().
     * @param \WC_Order            $order    Order returned by authenticate_response().
     * @return bool
     */
    protected function response_matches_order( array $response, \WC_Order $order ) {
        return $response['mid'] === $this->eb_PayMerchantId
            && $response['currency'] === static::CURRENCY
            && wc_format_decimal( $response['orderAmount'], 2 ) === wc_format_decimal( $order->get_total(), 2 );
    }

    /**
     * Resolve where the shopper lands after the bank returns: the configured page, else the thank-you page.
     *
     * @param \WC_Order $order Order the response belongs to.
     * @return string
     */
    protected function get_response_redirect_url( \WC_Order $order ) {
        return in_array( (string) $this->redirect_page_id, array( '', '0', '-1' ), true ) ? $this->get_return_url( $order ) : get_permalink( $this->redirect_page_id );
    }

    /**
     * @param int $order_id
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function generic_add_meta( $order_id, $key, $value ) {
        $order = new \WC_Order( $order_id );
        $order->add_meta_data( $key, $value, true );
        $order->save_meta_data();
    }

    /**
     * @param string $client_message Message shown to the shopper, already translated by the caller.
     * @param string $message_type
     * @param WC_Order $order
     * @param string $status
     * @param string $message
     * @return void
     */
    protected function updateStatus( $client_message, $message_type, $order, $status = null, $message = null ) {
        $eb_message = array( 'message' => $client_message, 'message_type' => $message_type );
        $this->generic_add_meta( $order->get_id(), '_eurobank_message', $eb_message );

        if ( $status !== null ) {
            $order->update_status( $status, $message );
        }
    }

    /**
     * @param string $message
     * @param string $key
     * @return string
     * @throws Exception
     */
    private static function encrypt( $message, $key ) {
        if ( mb_strlen( $key, '8bit' ) !== self::ENCRYPTION_KEY_LENGTH ) {
            throw new \Exception( esc_html( sprintf( 'Needs a %d-bit key! Got %d bytes', self::ENCRYPTION_KEY_LENGTH * 8, mb_strlen( $key, '8bit' ) ) ) );
        }
        $ivsize = openssl_cipher_iv_length( self::ENCRYPTION_METHOD );
        $iv     = openssl_random_pseudo_bytes( $ivsize );

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
    private static function decrypt( $message, $key ) {
        if ( mb_strlen( $key, '8bit' ) !== self::ENCRYPTION_KEY_LENGTH ) {
            throw new \Exception( esc_html( sprintf( 'Needs a %d-bit key! Got %d bytes', self::ENCRYPTION_KEY_LENGTH * 8, mb_strlen( $key, '8bit' ) ) ) );
        }
        $ivsize     = openssl_cipher_iv_length( self::ENCRYPTION_METHOD );
        $iv         = mb_substr( $message, 0, $ivsize, '8bit' );
        $ciphertext = mb_substr( $message, $ivsize, null, '8bit' );

        return openssl_decrypt(
            $ciphertext,
            self::ENCRYPTION_METHOD,
            $key,
            0,
            $iv
        );
    }

    /**
     * Write a debug line to WooCommerce > Status > Logs, source "eurobank", when debug logging is on.
     *
     * Data goes through sanitize_log_data() first, so the digest and the merchant key never reach the log.
     *
     * @param string $message Log line.
     * @param mixed  $data    Optional context, printed below the message after redaction.
     * @return void
     */
    private function safe_log( $message, $data = null ) {
        if ( $this->eb_enable_log !== 'yes' ) {
            return;
        }

        $line = $message;
        if ( $data !== null ) {
            $line .= "\n" . wc_print_r( $this->sanitize_log_data( $data ), true );
        }

        wc_get_logger()->debug( $line, array( 'source' => 'eurobank' ) );
    }

    /**
     * Remove sensitive information from data before logging
     *
     * @param mixed $data
     * @return mixed
     */
    private function sanitize_log_data( $data ) {
        if ( is_string( $data ) ) {
            // Replace merchant key with redacted text
            $data = str_replace( $this->eb_PayMerchantKey, '[REDACTED]', $data );
        } elseif ( is_array( $data ) ) {
            // Sanitize sensitive keys in arrays
            $sensitive_keys = [ 'eb_PayMerchantKey', 'PayMerchantKey', 'digest' ];
            foreach ($data as $key => $value) {
                if ( in_array( $key, $sensitive_keys ) ) {
                    $data[ $key ] = '[REDACTED]';
                } else {
                    $data[ $key ] = $this->sanitize_log_data( $value );
                }
            }
        }

        return $data;
    }
}
