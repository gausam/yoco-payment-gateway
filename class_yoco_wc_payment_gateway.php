<?php

if ( !defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

require_once 'class_yoco_wc_error_logging.php';

class class_yoco_wc_payment_gateway extends WC_Payment_Gateway {
    const INLINE_SDK_ENDPOINT = 'https://js.yoco.com/sdk/v1/yoco-sdk-web.js';
    const THRIVE_POPUP_SDK_ENDPOINT = 'https://online.yoco.com/v1/popup.js';
    const THRIVE_CREATE_CHARGE_ENDPOINT = 'https://online.yoco.com/v1/charges/';
    const WC_API_TOKEN_ENDPOINT = '/?wc-api=store_yoco_token';
    const WC_API_ADMIN_ENDPOINT = '/?wc-api=plugin_health_check';
    const YOCO_ALLOWED_CURRENCY = 'ZAR';
    const THRIVE_ALLOWED_HTTP_STATUS = [200, 201, 202];
    private $yoco_wc_customer_error_msg;
    private $yoco_logging;
    private $yoco_system_message = '<br><span style="color: red !important; font-size: smaller !important;">YOCO_SYSTEM_MESSAGE</span>';


    function __construct() {
        $this->id = 'class_yoco_wc_payment_gateway';
        $this->icon = plugins_url( 'assets/images/yoco/yoco_small.png', __FILE__ );
        $this->method_title = 'Yoco Payment Gateway';
        $this->method_description = 'Pay via Yoco';
        $this->inline_mode = $this->get_option( 'inline_mode' ) === 'yes';
        $this->has_fields = $this->inline_mode;
        $this->supports = array(
            'products',
            'tokenization'

        );

        $this->init_form_fields();
        $this->init_settings();


        $this->title = $this->get_option( 'title' );

        $yoco_system_message = class_yoco_wc_error_logging::getYocoSystemMessages();
        if ($yoco_system_message == '') {
            $this->yoco_system_message = '';
        } else {
            $this->yoco_system_message = str_replace('YOCO_SYSTEM_MESSAGE', $yoco_system_message, $this->yoco_system_message);
        }

        $this->description = ($this->yoco_system_message != '') ? $this->get_option( 'description' ). "<br>".$this->yoco_system_message : $this->get_option( 'description' );

        $this->enabled = $this->get_option( 'enabled' );
        $this->testmode = 'test' === $this->get_option( 'mode' );
        $this->private_key = $this->testmode ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'live_secret_key' );
        $this->publishable_key = $this->testmode ? $this->get_option( 'test_public_key' ) : $this->get_option( 'live_public_key' );
        $this->yoco_wc_customer_error_msg = (!empty($this->get_option( 'customer_error_message' ))) ? trim($this->get_option( 'customer_error_message' )) : "Payment Gateway Error";


        $this->perform_plugin_checks();
        $this->yoco_logging = new class_yoco_wc_error_logging($this->enabled, $this->testmode, $this->private_key, $this->publishable_key);
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );


        /**
         * Pay For Order Receipting Hook
         */
        add_action( 'woocommerce_receipt_' . $this->id, array(
            $this,
            'pay_for_order'
        ) );

        add_action('admin_enqueue_scripts', array( $this,'yoco_admin_load_scripts'));
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );


        /**
         * Ajax Token Store
         */
        add_action('woocommerce_api_store_yoco_token', array($this,'store_yoco_token'));



    }

    /**
     * Frontend payment fields for Yoco Inline.
     */
    public function payment_fields() {
        global $wp;
        $user                 = wp_get_current_user();
        $total                = WC()->cart->total;

        // When paying from an order, get the total from order - not the cart.
        if ( isset( $_GET['pay_for_order'] ) && ! empty( $_GET['key'] ) ) {
            $order      = wc_get_order( wc_clean( $wp->query_vars['order-pay'] ) );
            $total      = $order->get_total();
            $user_email = $order->get_billing_email();
            $billing_first_name = $order->get_billing_first_name();
            $billing_last_name  = $order->get_billing_last_name();
        } else {
            if ( $user->ID ) {
            $user_email = get_user_meta( $user->ID, 'billing_email', true );
            $user_email = $user_email ? $user_email : $user->user_email;
            $billing_first_name = get_user_meta( $user->ID, 'billing_first_name', true );
            $billing_last_name  = get_user_meta( $user->ID, 'billing_last_name', true );
            }
        }

        ob_start();

        echo '<div
            id="yoco-payment-data"
                data-currency="' . esc_attr( get_woocommerce_currency() ) . '"
                data-amount-in-cents="' . esc_attr( $this->convert_to_cents( $total ) ) . '"
                data-client-email="' . esc_attr( $user_email ) . '"
                data-client-fn="' . esc_attr( $billing_first_name ) . '"
                data-client-ln="' . esc_attr( $billing_last_name ) . '"
            >';

        ?>
            <div class="one-liner">
                <div id="card-frame">
                <!-- Yoco Inline form will be added here -->
                </div>
            </div>
            <div id="yoco-wc-payment-gateway-errors" role="alert"></div>
            <br />

        <?php
        ob_end_flush();
    }

    public function admin_options() {
        if ( $this->is_currency_valid_for_use() ) {
            parent::admin_options();
        } else {
            ?>
            <div class="inline error">
                <p>
                    <strong><?php esc_html_e( 'Gateway disabled', 'woocommerce' ); ?></strong>: <?php _e( 'Currency not supported by by Yoco plugin, you can change the currency of the store <a href="/wp-admin/admin.php?page=wc-settings">here</a>', 'woocommerce' ); ?>
                </p>
            </div>
            <?php
        }
    }
    /**
     * @return array
     *
     * Perform Plugin Health Check
     */
    private function perform_plugin_checks() {
        $health = array('SSL' => false,'KEYS' => false,'CURRENCY' => false);

        if ( empty( $this->private_key ) || empty( $this->publishable_key ) ) {
            $this->enabled = 'no';

        } else {
            $health['KEYS'] = true;
        }

        if ( !$this->testmode && !is_ssl() ) {
            $this->enabled = 'no';
        } else {
            $health['SSL'] = true;
        }


        if (get_woocommerce_currency() !== self::YOCO_ALLOWED_CURRENCY) {
            $this->enabled = 'no';
        } else {
            $health['CURRENCY'] = true;
        }

        return $health;

    }

    /**
     * Yoco Admin Load Scripts
     */

    static function is__payments_admin_page() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : '';
        $section = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : '';

        return ($tab === 'checkout' && $section === 'class_yoco_wc_payment_gateway');
    }
    public function yoco_admin_load_scripts() {
        wp_enqueue_style('admin_styles', plugins_url( 'assets/css/admin/admin.css', __FILE__ ) );
        if (class_yoco_wc_payment_gateway::is__payments_admin_page()) {
            wp_enqueue_script('admin_js', plugins_url('assets/js/admin/admin.js', __FILE__));
        }
        wp_localize_script( 'admin_js', 'yoco_params', array(
            'url' => get_site_url().self::WC_API_ADMIN_ENDPOINT,
            'nonce' => wp_create_nonce('nonce_yoco_admin'),
        ) );
    }

    /**
     * Woocommerce Currency Validator
     * @return bool
     */
    public function is_currency_valid_for_use() {
        return in_array(
            get_woocommerce_currency(),
            apply_filters(
                'woocommerce_yoco_supported_currencies',
                array( 'ZAR')
            ),
            true
        );
    }


    /**
     * Woocommerce Init Form Fields
     */
    public function init_form_fields(){

        add_action( 'woocommerce_api_plugin_health_check', array($this,'plugin_health_check'));

        $this->form_fields = array(
            'enabled' => array(
                'title'       => 'Enable/Disable',
                'label'       => 'Enable Yoco Gateway',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'This controls the title which the user sees during checkout.',
                'default'     => 'Yoco - debit/credit card',
            ),

            'description' => array(
                'title'       => 'Description',
                'type'        => 'textarea',
                'description' => 'This controls the description which the user sees during checkout.',
                'default'     => 'Pay securely with your card via Yoco',
            ),

            'customer_error_message' => array(
                'title'       => 'Customer Error Message',
                'type'        => 'textarea',
                'description' => 'What the client sees when a payment processing error occurs',
                'default'     => 'Your order could not be processed by Yoco - please try again later',
            ),

            'mode' => array(
                'title'       => 'Mode',
                'label'       => 'Mode',
                'type'        => 'select',
                'description' => 'Test mode allow you to test the plugin without processing money, make sure you set the plugin to live mode and click on "Save changes" for real customers to use it',
                'default'     => 'Test',
                'options' => array(
                    'live' => 'Live',
                    'test' => 'Test'
                )
            ),
            'inline_mode'   => array(
              'title'       => 'Inline Payments',
              'label'       => 'Enable Yoco Inline Payments',
              'type'        => 'checkbox',
              'description' => 'Inline integrates into your existing checkout page to create a seamless customer experience.',
              'default'     => 'no'
            ),

            'live_secret_key' => array(
                'title'       => 'Live Secret Key',
                'type'        => 'password',
                'description' => 'Live Secret Key',
            ),
            'live_public_key' => array(
                'title'       => 'Live Public Key',
                'type'        => 'password',
                'description' => 'Live Public Key',
            ),

            'test_secret_key' => array(
                'title'       => 'Test Secret Key',
                'type'        => 'text',
                'description' => 'Test Secret Key',
            ),
            'test_public_key' => array(
                'title'       => 'Test Public Key',
                'type'        => 'text',
                'description' => 'Test Public Key',
            ),

        );

    }

    /**
     * Get order total formatted as cents.
     * 
     * @return int
     */
    private function getOrderTotal() {
        return $this->convert_to_cents( WC_Payment_Gateway::get_order_total() );
    }

    /**
     * Convert regular amount to cents.
     * 
     * @param mixed $amount Amount to be expressed in cents.
     * @return int
     */
    private function convert_to_cents($amount) {
        return absint( wc_format_decimal( ( $amount * 100 ), wc_get_price_decimals() ) );
    }

    /***
     * @param $token
     * @param $amount_in_cents
     * @return mixed
     * Send token to Yoco
     */
    private function sendtoYoco($token, $amount_in_cents) {

        $body = [
            'token' => $token,
            'amountInCents' => $amount_in_cents,
            'currency' => get_woocommerce_currency()
            ];

        $args = [
            'method'      => 'POST',
            'sslverify'   => true,
            'headers'     => [
                'Authorization' => 'Basic '.base64_encode($this->private_key.':'),
                'Cache-Control' => 'no-cache',
                'Content-Type'  => 'application/json',
            ],
            'body'        => json_encode($body),
        ];
        $request = wp_remote_post( self::THRIVE_CREATE_CHARGE_ENDPOINT, $args );
        if ( is_wp_error( $request ) || !in_array( wp_remote_retrieve_response_code( $request ), self::THRIVE_ALLOWED_HTTP_STATUS) ) {
            class_yoco_wc_error_logging::logError("CHARGE", wp_remote_retrieve_response_code( $request ), wp_remote_retrieve_response_message($request), $args);
        }

        $response = wp_remote_retrieve_body( $request );
        if (json_decode($response, true) !== null) {
            class_yoco_wc_error_logging::logError("CHARGE", wp_remote_retrieve_response_code( $request ), base64_encode($response), $args);
            return json_decode($response, true);
        }
        return $response;

    }

    /**
     * Woocommerce Payment Scripts
     */
    public function payment_scripts() {

        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
            return;
        }


        if ( 'no' === $this->enabled ) {
            return;
        }

        if ( empty( $this->private_key ) || empty( $this->publishable_key ) ) {
            return;
        }

        if ( ! $this->testmode && ! is_ssl() ) {
            return;
        }

        $woocommerce_yoco_handle = $this->inline_mode ? 'woocommerce_yoco_inline' : 'woocommerce_yoco';
        $woocommerce_yoco_path = $this->inline_mode ?  'assets/js/yoco/yoco_inline.js' : 'assets/js/yoco/yoco.js';
        $woocommerce_yoco_deps = $this->inline_mode ? 'yoco_web_js' : 'yoco_js';

        if ( $this->inline_mode ) {
            wp_enqueue_script( 'yoco_web_js', self::INLINE_SDK_ENDPOINT );
        } else {
            wp_enqueue_script( 'yoco_js', self::THRIVE_POPUP_SDK_ENDPOINT );
        }
        wp_register_script(
            $woocommerce_yoco_handle,
            plugins_url( $woocommerce_yoco_path, __FILE__ ),
            array( 'jquery', $woocommerce_yoco_deps ),
            '1.0.0',
            false
        );
        wp_enqueue_style('orderpay_styles', plugins_url( 'assets/css/frontend/orderpay.css', __FILE__ ) );

        $yoco_params = array(
            'publicKey' => $this->publishable_key,
            'currency' => 'ZAR',
            'url' => get_site_url().self::WC_API_TOKEN_ENDPOINT,
            'nonce' => wp_create_nonce( 'nonce_store_yoco_token' ),
            'is_checkout' => ( is_checkout() && empty( $_GET['pay_for_order'] ) ) ? 'yes' : 'no',
            'is_order_payment' => 'no'
        );

        if ( is_wc_endpoint_url( 'order-pay' ) ) {
            $order_id = $this->get_order_id_order_pay_yoco();
            $order = wc_get_order($order_id);
            $order_data = $order->get_data();

            $yoco_params['is_order_payment'] = 'yes';
            $yoco_params['amountInCents'] = $this->getOrderTotal();
            $yoco_params['triggerElement'] = '#yoco_pay_now';
            $yoco_params['order_id'] = $order_id;
            $yoco_params['client_email'] = $order_data['billing']['email'];
            $yoco_params['client_fn'] = $order_data['billing']['first_name'];
            $yoco_params['client_ln'] = $order_data['billing']['last_name'];
        }

        wp_localize_script( $woocommerce_yoco_handle, 'yoco_params', $yoco_params );
        wp_enqueue_script( $woocommerce_yoco_handle );
    }

    /**
     * Get Order ID from Order-Pay endpoint
     */
    public function get_order_id_order_pay_yoco() {

        global $wp;
        $order_id = absint( $wp->query_vars['order-pay'] );
        if ( empty( $order_id ) || $order_id == 0 ) {
            return;
        }
        return $order_id;
    }

    /**
     * @param $order
     * Process a successful payment and redirect to order received
     */
    private function process_success($order) {
        global $woocommerce;
        $order->update_status('processing', sprintf(__('%s %s was successfully processed through Yoco, you can see this payment in Yoco transaction history (on the yoco app our on the desktop portal)', 'woocommerce-my-payment-gateway'), get_woocommerce_currency(), $order->get_total()));
        $order->payment_complete();
        wc_reduce_stock_levels( $order->get_id() );

        $woocommerce->cart->empty_cart();

        if ( ! $this->inline_mode ) {
            wp_redirect($order->get_checkout_order_received_url());
            exit;
        }
        
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url( $order )
        );
    }

    /***
     * @param $order
     * @param $message
     *
     * Process a failed payment and redirect to checkout url
     */
    private function process_failure($order, $message) {
        // Cancel Order
        $order->update_status('cancelled', sprintf( __( '%s payment cancelled! Transaction ID: %d', 'woocommerce' ), get_woocommerce_currency().' '.$order->get_total(), $order->get_id()));
        // Add WC Notice
        wc_add_notice( $this->yoco_wc_customer_error_msg, 'error' );
        $this->set_wc_admin_notice('Yoco Payment Gateway Error [Order# '.$order->get_id().']: '.$message);
        if ( ! $this->inline_mode ) {
            // Redirect to Cart Page
            wp_redirect(wc_get_checkout_url());
            exit;
        }
    }

    /***
     * @param $order
     * @param $code
     * @param $message
     *
     * Process a failed payment from Yoco Endpoint and redirect to checkout url
     */
    private function process_yoco_failure($order, $code, $message) {
        // Cancel Order
        $order->update_status('cancelled', sprintf( __( '%s payment cancelled! Transaction ID: %d', 'woocommerce' ), get_woocommerce_currency().' '.$order->get_total(), $order->get_id()));
        // Add WC Notice
        wc_add_notice( $message, 'error' );
        $this->set_wc_admin_notice('Yoco Payment Gateway Error [Order# '.$order->get_id().']: '.$code);
        class_yoco_wc_error::save_yoco_customer_order_error($order->get_id(), $code, $message);
        if ( ! $this->inline_mode ) {
            // Redirect to Cart Page
            wp_redirect(wc_get_checkout_url());
            exit;
        }
    }

    /**
     * @param $order
     * Process a successful test transaction, cancel order and redirect to checkout url
     */
    private function process_test($order) {
        // Cancel Order
        $order->update_status('cancelled', sprintf( __( '%s payment cancelled! Transaction ID: %d', 'woocommerce' ), get_woocommerce_currency().' '.$order->get_total(), $order->get_id()));
        // Add WC Notice
        wc_add_notice( "Yoco Payment Gateway Test Transaction [SUCCESS] - Order Cancelled", 'error' );
        $this->set_wc_admin_notice('Yoco Payment Gateway Test Transaction [SUCCESS] [Order# '.$order->get_id().']: Cancelled');
        // Redirect to Cart Page
        wp_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * @param $message
     * Displays a Woocommerce Admin Notice to the backend
     */
    private function set_wc_admin_notice($message) {
        $html = __("<h2 class='yoco_pg_admin_notice'>$message</h2>", 'yoco_payment_gateway');
        WC_Admin_Notices::add_custom_notice('yoco_payment_gateway', $html);
    }

    /**
     * Processes charge API response and returns appropriate response.
     *
     * @param  string   $yoco_result Result from invocation of sendtoYoco method.
     * @param  WC_Order $order       Order data.
     * @return mixed
     */
    public function process_result($yoco_result, $order) {
        if (is_string($yoco_result)) {
            return $this->process_failure($order, $yoco_result);
        }

        if ( is_array( $yoco_result ) && array_key_exists( 'status', $yoco_result ) ) {
            switch ($yoco_result['status']) {
                case "successful":
                    if ($this->testmode) {
                        return $this->process_success($order);
                    } else {
                        return $this->process_success($order);
                    }
                    break;
                default:
                    return $this->process_failure($order, $yoco_result['message']);
                    break;

            }
        }

        if ( is_array( $yoco_result ) && array_key_exists( 'errorCode' , $yoco_result ) ) {
            return $this->process_yoco_failure(
                $order,
                $yoco_result['errorCode'],
                $yoco_result['displayMessage']
            );
        }
    }

    /**
     * @param $order_id
     * @return bool
     *
     * Pay For Yoco Order
     */
    public function pay_for_order( $order_id ) {
        $order = wc_get_order($order_id);
        if (get_transient('yoco_pg_error_order_'.$order_id)) {
            $message = get_transient('yoco_pg_error_order_'.$order_id);
            delete_transient('yoco_pg_error_order_'.$order_id);
            $this->process_failure($order, $message);
        }
        if (!empty($order->get_payment_tokens())) {
            $token = WC_Payment_Tokens::get( $order->get_payment_tokens()[0] );
            if ($token->get_token()) {
                $yoco_result = $this->sendtoYoco(
                    $token->get_token(),
                    $this->getOrderTotal()
                );
                $this->process_result( $yoco_result, $order );
            }
        }

        ?>
        <a href="#yoco_pay_now" id="yoco_pay_now" role="button">Enter Credit Card Details</a>
        <?php
        return true;
    }

    /**
     * Validate frontend fields.
     *
     * Validate payment fields on the frontend.
     *
     * @return bool
     */
    public function validate_fields() {
        if ( ! $this->inline_mode ) {
            return true;
        }

        if ( ! isset( $_POST['yoco_create_token_result'] ) ) {
            wc_add_notice(
                __( 'Payment error: ', 'woothemes' ) . __( "Invalid request", 'yoco_payment_gateway' ),
                'error'
            );
            return false;
        }

        $yoco_result = json_decode( html_entity_decode(
            stripslashes ( $_POST['yoco_create_token_result'] )
        ) );

        if (json_last_error() !== JSON_ERROR_NONE) {
            wc_add_notice(
                __('Payment error: ', 'woothemes') . __("Invalid request", 'yoco_payment_gateway'),
                'error'
            );
            return false;
        }

        return true;
    }

    /**
     * Process Payment.
     *
     * @param int $order_id Order ID.
     * @return mixed
     */
    public function process_payment( $order_id ) {
        $order = new WC_Order( $order_id );
        if ( ! $this->inline_mode ) {
            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url( true )
            );
        }

        if ( ! $this->validate_fields() ) {
            return;
        }

        $yoco_create_token_result = json_decode(
          html_entity_decode( stripslashes (
            $_POST['yoco_create_token_result']
          )), true );

        $yoco_result = $this->sendtoYoco(
            $yoco_create_token_result['id'],
            $this->convert_to_cents( $order->get_total() )
        );

        $this->add_token_to_order($order, $yoco_create_token_result);
        return $this->process_result( $yoco_result, $order );
    }


    /**
     * @param $source
     * @return mixed
     *
     * Filters the post data from ajax call
     */
    private function filter_source($source) {
        $args = array(
            'brand' => FILTER_SANITIZE_STRING,
            'country' => FILTER_SANITIZE_STRING,
            'expiryMonth' => FILTER_SANITIZE_NUMBER_INT,
            'expiryYear' => FILTER_SANITIZE_NUMBER_INT,
            'fingerprint' => FILTER_SANITIZE_STRING,
            'id'  => FILTER_SANITIZE_STRING,
            'maskedCard'  => FILTER_SANITIZE_STRING,
            'object'  => FILTER_SANITIZE_STRING
        );

        return filter_var_array($source, $args);
    }

    /**
     * Add payment token to order.
     * Returns false on failure or error.
     * 
     * @param  WC_Order $order         Order data.
     * @param  mixed    $request_token Token payload.
     * 
     * mixed|false
     */
    public function add_token_to_order($order, $request_token) {
        // No token from js request
        if ( ! array_key_exists( 'id' , $request_token ) ) {
            $error_message = 'Yoco - No Token Response';
            set_transient( 'yoco_pg_error_order_' . $order->ID, $error_message );
            class_yoco_wc_error_logging::logError(
                "WC_TOKEN",
                -1,
                $error_message,
                ['order_id' => $order->ID]
            );
            return false;
        }

        $token_id = filter_var( $request_token['id'], FILTER_SANITIZE_STRING );

        try {
            $source = $this->filter_source( $request_token['source'] );
            $token = new WC_Payment_Token_CC();
            $token->set_token( $token_id );
            $token->set_gateway_id( $this->id );
            $token->set_card_type( $source['brand'] );
            $token->set_last4(
            substr(
                $source['maskedCard'],
                strlen( $source['maskedCard'] ) - 4
            ),
            strlen( $source['maskedCard'] )
            );
            $token->set_expiry_month( $source['expiryMonth'] );
            $token->set_expiry_year( $source['expiryYear'] );

            $token->set_user_id( get_current_user_id() );

            $res = $token->save();
            $order->add_payment_token( $token );
            return $res;
        } catch (Exception $e) {
            wc_add_notice( $e->getMessage(), 'error' );
            set_transient( 'yoco_pg_error_order_'.$order->ID, $e->getMessage() );
            class_yoco_wc_error_logging::logError(
                "WC_TOKEN",
                0,
                $e->getMessage(),
                ['order_id' => $order->ID]
            );
            return false;
        }
    }

    /**
     * Ajax Store Yoco Token
     */
    public function store_yoco_token() {

        check_ajax_referer( 'nonce_store_yoco_token', 'nonce' );
        if (isset($_POST['token'])) {
            $order_id = filter_var($_POST['order_id'], FILTER_SANITIZE_NUMBER_INT);
            $order = wc_get_order($order_id);
            $res = $this->add_token_to_order($order, $_POST['token']);
            wp_send_json(array('status' => $res, 'post' => $_POST));

            if (array_key_exists('error', $_POST['token'])) {
                $order_id = filter_var($_POST['order_id'], FILTER_SANITIZE_NUMBER_INT);
                $message = filter_var($_POST['token']['error']['message'], FILTER_SANITIZE_STRING);
                $status = filter_var($_POST['token']['error']['status'], FILTER_SANITIZE_STRING);
                set_transient('yoco_pg_error_order_'.$order_id, $message." | STATUS: ".$status);
                class_yoco_wc_error_logging::logError("WC_TOKEN", 0, $message." | STATUS: ".$status, ['order_id' => $order_id]);
                wp_send_json(array('status' => false));
            }

            $order_id = filter_var($_POST['order_id'], FILTER_SANITIZE_NUMBER_INT);
            $error_message = 'Unspecified error';
            set_transient('yoco_pg_error_order_'.$order_id, $error_message);
            class_yoco_wc_error_logging::logError("WC_TOKEN", 0, $error_message, ['order_id' => $order_id]);
            wp_send_json(array('status' => false));
        } else {
            $order_id = filter_var($_POST['order_id'], FILTER_SANITIZE_NUMBER_INT);
            $error_message = 'Unspecified error';
            set_transient('yoco_pg_error_order_'.$order_id, $error_message);
            class_yoco_wc_error_logging::logError("WC_TOKEN", 0, $error_message, ['order_id' => $order_id]);
            wp_send_json(array('status' => false));
        }
        wp_die();
    }

    /**
     * Ajax Admin Plugin Health Check
     */
    public function plugin_health_check() {
        check_ajax_referer( 'nonce_yoco_admin', 'nonce' );
        $health = $this->perform_plugin_checks(true);
        wp_send_json($health);
        wp_die();
    }


}
