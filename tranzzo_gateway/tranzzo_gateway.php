<?php
/*
Plugin Name: TRANZZO Gateway
Description: Платежный шлюз "TRANZZO" для сайтов на WordPress.
Version: 1.0
Lat Update: 01.03.2018
Author: TRANZZO
Author URI: https://tranzzo.com
*/

if (!defined('ABSPATH')) exit;

define('TRANZZO_DIR', plugin_dir_path(__FILE__));

add_action('plugins_loaded', 'tranzzo_init', 0);

load_plugin_textdomain('tranzzo', false, basename(TRANZZO_DIR) . '/languages');

add_action('init', 'tranzzo_endpoint');
add_action('pre_get_posts', 'tranzzo_listen_redirect');

function tranzzo_endpoint()
{
    add_rewrite_endpoint('tranzzo-redirect', EP_ROOT);
}

//
function tranzzo_listen_redirect($query)
{
    if (($query->get('pagename') == 'tranzzo-redirect') || (strpos($_SERVER['REQUEST_URI'], 'tranzzo-redirect') !== false)) {
        (new WC_Gateway_Tranzzo)->generate_form($_REQUEST['order_id']);
        exit;
    }
}

function tranzzo_init()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Gateway_Tranzzo extends WC_Payment_Gateway
    {
        public function __construct()
        {
            global $woocommerce;
            self::writeLog('__construct', '1');
            $this->id = 'tranzzo';
            $this->has_fields = false;
            $this->method_title = 'TRANZZO';
            $this->method_description = __('TRANZZO', 'tranzzo');
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');

            $this->language = $this->get_option('language');
            $this->paymenttime = $this->get_option('paymenttime');
            $this->payment_method = $this->get_option('payment_method');


//            $this->TEST_MODE = ($this->get_option('TEST_MODE') == 'yes') ? 1 : 0;

            //new
            $this->type_payment = ($this->get_option('type_payment') == 'yes') ? 1 : 0;
            //new

            $this->POS_ID = trim($this->get_option('POS_ID'));

            $this->API_KEY = trim($this->get_option('API_KEY'));

            $this->API_SECRET = trim($this->get_option('API_SECRET', 'PAY ONLINE'));

            $this->ENDPOINTS_KEY = trim($this->get_option('ENDPOINTS_KEY'));

            $this->icon = apply_filters('woocommerce_tranzzo_icon', plugin_dir_url(__FILE__) . '/images/logo.png');

            if (!$this->supportCurrencyTRANZZO()) {
                $this->enabled = 'no';
            }
            $this->supports[] = 'refunds';

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Payment listener/API hook
            add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'check_tranzzo_response'));

            //new при изменении статуса на Обработка ! делать
            add_action('woocommerce_order_status_on-hold_to_processing', array($this, 'capture_payment'));
            //new

        }

        public function admin_options()
        {
            if ($this->supportCurrencyTRANZZO()) { ?>
                <h3><?php _e('TRANZZO', 'tranzzo'); ?></h3>
                <table class="form-table">
                    <?php $this->generate_settings_html(); ?>
                </table>
                <?php
            } else { ?>
                <div class="inline error">
                    <p>
                        <strong><?php _e('Платежный шлюз отключен.', 'tranzzo'); ?></strong>: <?php _e('TRANZZO не поддерживает валюту Вашего магазина!', 'tranzzo'); ?>
                    </p>
                </div>
                <?php
            }
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Вкл. / Выкл.', 'tranzzo'),
                    'type' => 'checkbox',
                    'label' => __('Включить', 'tranzzo'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Заголовок', 'tranzzo'),
                    'type' => 'text',
                    'description' => __('Заголовок, который отображается на странице оформления заказа', 'tranzzo'),
                    'default' => 'TRANZZO',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Описание', 'tranzzo'),
                    'type' => 'textarea',
                    'description' => __('Описание, которое отображается в процессе выбора формы оплаты', 'tranzzo'),
                    'default' => __('Оплатить через платежную систему TRANZZO', 'tranzzo'),
                ),
                //new
                'type_payment' => array(
                    'title' => __('Блокировка средств', 'tranzzo'),
                    'type' => 'checkbox',
                    'label' => __('Включить', 'tranzzo'),
                    'default' => 'no'
                ),
                //new
                'POS_ID' => array(
                    'title' => 'POS_ID',
                    'type' => 'text',
                    'description' => __('POS_ID TRANZZO', 'tranzzo'),
                ),
                'API_KEY' => array(
                    'title' => 'API_KEY',
                    'type' => 'password',
                    'description' => __('API_KEY TRANZZO', 'tranzzo'),
                ),
                'API_SECRET' => array(
                    'title' => 'API_SECRET',
                    'type' => 'password',
                    'description' => __('API_SECRET TRANZZO', 'tranzzo'),
                ),
                'ENDPOINTS_KEY' => array(
                    'title' => 'ENDPOINTS_KEY',
                    'type' => 'password',
                    'description' => __('ENDPOINTS_KEY TRANZZO', 'tranzzo'),
                ),
            );
        }

        function supportCurrencyTRANZZO()
        {
            if (!in_array(get_option('woocommerce_currency'), array('USD', 'EUR', 'UAH', 'RUB'))) {
                return false;
            }

            return true;
        }

        function process_payment($order_id)
        {
            return array(
                'result' => 'success',
                'redirect' => add_query_arg('order_id', $order_id, home_url('tranzzo-redirect'))
            );
        }

        public function generate_form($order_id)
        {
            self::writeLog('generate_form', '1');
            global $woocommerce;
            $order = new WC_Order($order_id);
            $data_order = $order->get_data();

            if (!empty($data_order)) {
                require_once(__DIR__ . '/TranzzoApi.php');

                $tranzzo = new TranzzoApi($this->POS_ID, $this->API_KEY, $this->API_SECRET, $this->ENDPOINTS_KEY);
                $tranzzo->setServerUrl(add_query_arg('wc-api', __CLASS__, home_url('/')));
                $tranzzo->setResultUrl($this->get_return_url($order));
                $tranzzo->setOrderId($order_id);
                $tranzzo->setAmount($data_order['total']);
                $tranzzo->setCurrency($data_order['currency']);
                $tranzzo->setDescription("Order #{$order_id}");

                if (!empty($data_order['customer_id']))
                    $tranzzo->setCustomerId($data_order['customer_id']);
                else
                    $tranzzo->setCustomerId($data_order['billing']['email']);

                $tranzzo->setCustomerEmail($data_order['billing']['email']);
                $tranzzo->setCustomerFirstName($data_order['billing']['first_name']);
                $tranzzo->setCustomerLastName($data_order['billing']['last_name']);
                $tranzzo->setCustomerPhone($data_order['billing']['phone']);
                $tranzzo->setProducts();

                if (count($data_order['line_items']) > 0) {
                    $products = array();
                    foreach ($data_order['line_items'] as $item) {
                        $product = new WC_Order_Item_Product($item);
                        $products[] = array(
                            'id' => strval($product->get_id()),
                            'name' => $product->get_name(),
                            'url' => $product->get_product()->get_permalink(),
                            'currency' => $data_order['currency'],
                            'amount' => TranzzoApi::amountToDouble($product->get_total()),
//                            'price_type' => 'gross', // net | gross
//                            'vat' => 0,
                            'qty' => $product->get_quantity(),
                        );
                    }

                    $tranzzo->setProducts($products);
                }

                //new
                //$response = $tranzzo->createPaymentHosted();
                $response = $tranzzo->createPaymentHosted($this->type_payment);
                self::writeLog(array('response2' => $response));
                //new

                if (!empty($response['redirect_url'])) {
                    $woocommerce->cart->empty_cart();
                    wp_redirect($response['redirect_url']);
                    exit;
                }

                wp_redirect($order->get_cancel_order_url());
            }

            wp_redirect(home_url('/'));
        }

        public function check_tranzzo_response()
        {

            global $woocommerce;
            self::writeLog('check', '2');

            //new
            //serialize_precision for json_encode
            if (version_compare(phpversion(), '7.1', '>=')) {
                ini_set('serialize_precision', -1);
            }
            //new

            $data = $_POST['data'];
            $signature = $_POST['signature'];
            if (empty($data) && empty($signature)) die('LOL! Bad Request!!!');

            require_once(__DIR__ . '/TranzzoApi.php');

            $tranzzo = new TranzzoApi($this->POS_ID, $this->API_KEY, $this->API_SECRET, $this->ENDPOINTS_KEY);
            $data_response = TranzzoApi::parseDataResponse($data);

            //new
            $method_response = $data_response[TranzzoApi::P_REQ_METHOD];
            self::writeLog('$method_response', $method_response);
            self::writeLog(array('$data_response' => $data_response));

            if ($method_response == TranzzoApi::P_METHOD_AUTH || $method_response == TranzzoApi::P_METHOD_PURCHASE) {
                $order_id = (int)$data_response[TranzzoApi::P_RES_PROV_ORDER];
                $tranzzo_order_id = (int)$data_response[TranzzoApi::P_RES_ORDER];
            } else {
                $tranzzo_order_id = (int)$data_response[TranzzoApi::P_RES_ORDER];
                global $wpdb;
                $order_id = $wpdb->get_var("SELECT post_id as count FROM $wpdb->postmeta WHERE meta_key = 'tranzzo_order_id' AND meta_value = $tranzzo_order_id");
            }

            self::writeLog('$order_id', $order_id);
            self::writeLog('$tranzzo_order_id', $tranzzo_order_id);
            //new

            if ($tranzzo->validateSignature($data, $signature) && $order_id) {
                $order = wc_get_order($order_id);
//                self::writeLog(array('$order' => (array)$order));

                $amount_payment = TranzzoApi::amountToDouble($data_response[TranzzoApi::P_RES_AMOUNT]);
                $amount_order = TranzzoApi::amountToDouble($order->get_total());

//                self::writeLog('CODE', $data_response[TranzzoApi::P_RES_RESP_CODE]);
                if ($data_response[TranzzoApi::P_RES_RESP_CODE] == 1000 && ($amount_payment >= $amount_order)) {
                    self::writeLog('Pay', 'ok');
                    $order->set_transaction_id($data_response[TranzzoApi::P_RES_TRSACT_ID]);
                    $order->payment_complete();
                    $order->add_order_note(__('Заказ успешно оплачен через TRANZZO', 'tranzzo'));
                    $order->add_order_note("ID платежа(payment id): " . $data_response[TranzzoApi::P_RES_PAYMENT_ID]);
                    $order->add_order_note("ID транзакции(transaction id): " . $data_response[TranzzoApi::P_RES_TRSACT_ID]);
                    $order->save();
                    update_post_meta($order_id, 'tranzzo_response', json_encode($data_response));
                    update_post_meta($order_id, 'tranzzo_order_id', $tranzzo_order_id);
                    //???? $tranzzo_order_id
                    return;
                    exit;
                }
                //new
                // for auth
                elseif ($data_response[TranzzoApi::P_RES_RESP_CODE] == 1002 && ($amount_payment >= $amount_order)) {
                    self::writeLog('pay', 'auth');
                    $order->set_transaction_id($data_response[TranzzoApi::P_RES_TRSACT_ID]);
                    // сумма забронированна, но не проведенна
                    $order->update_status('on-hold', __('TRANZZO', 'woothemes'));
//                    $order->payment_complete(); для завершения платежа
                    //
                    $order->add_order_note("ID платежа(payment id): " . $data_response[TranzzoApi::P_RES_PAYMENT_ID]);
                    $order->add_order_note("ID транзакции(transaction id): " . $data_response[TranzzoApi::P_RES_TRSACT_ID]);
                    $order->add_order_note(__('Сумма платежа зарезервированна через TRANZZO, необходимо изменить статус заказа на "Обработка" для зачисления средств', 'tranzzo'));
                    $order->save();
                    update_post_meta($order_id, 'tranzzo_response', json_encode($data_response));
                    update_post_meta($order_id, 'tranzzo_order_id', $tranzzo_order_id);
                    return;
                    exit;
                } // for void
                elseif ($method_response == TranzzoApi::U_METHOD_VOID && $data_response[TranzzoApi::P_RES_STATUS] == TranzzoApi::P_TRZ_ST_SUCCESS) {
                    self::writeLog('!!!!!!!!! void', '');
                    $order->update_status('cancelled', __('TRANZZO', 'woothemes'));
//                    $order->add_order_note("ID платежа(payment id): " . $data_response[TranzzoApi::P_RES_PAYMENT_ID]);
//                    $order->add_order_note("ID транзакции(transaction id): " . $data_response[TranzzoApi::P_RES_TRSACT_ID]);
                    $order->add_order_note(__('Сумма платежа успешно возвращена через TRANZZO', 'tranzzo'));
                    $order->save();
                    update_post_meta($order_id, 'tranzzo_response', json_encode($data_response));
                    return;
                    exit;
                } // for capture
                elseif ($method_response == TranzzoApi::U_METHOD_CAPTURE && $data_response[TranzzoApi::P_RES_STATUS] == TranzzoApi::P_TRZ_ST_SUCCESS) {
                    self::writeLog('!!!!!!!!! capture', '');
                    $order->add_order_note(__('Зарезервированная сумма платежа зачисленна через TRANZZO', 'tranzzo'));
                    $order->save();
                    update_post_meta($order_id, 'tranzzo_response', json_encode($data_response));
                    return;
                    exit;
                } //elseif ($data_response[TranzzoApi::P_RES_RESP_CODE] == 1004) {
                elseif ($method_response == TranzzoApi::U_METHOD_REFUND && $data_response[TranzzoApi::P_RES_STATUS] == TranzzoApi::P_TRZ_ST_SUCCESS) {
                    //new
//                    self::writeLog('P_RES_TRSACT_ID', $data_response[TranzzoApi::P_RES_TRSACT_ID]);
                    $order->add_order_note(__('Заказ успешно возвращен через TRANZZO', 'tranzzo'));
                    $order->add_order_note("ID платежа(payment id): " . $data_response[TranzzoApi::P_RES_PAYMENT_ID]);
//                    $order->add_order_note("ID транзакции(transaction id): " . $data_response[TranzzoApi::P_RES_TRSACT_ID]);
                    $order->save();
                    update_post_meta($order_id, 'tranzzo_response', json_encode($data_response));
                    return;
                } elseif ($order->get_status() == "pending") {
                    self::writeLog('pen', 'Заказ в ожидании оплаты');
//                    self::writeLog('pen', 'Заказ не оплачен');
//                    $order->update_status('failed');
                    $order->add_order_note(__('Заказ в ожидании оплаты', 'tranzzo'));
                    $order->save();
                }


                exit;
            }
            exit;
        }

        //public function transaction_id($order_id, $amount = null, $reason = ''){ // ????
        public function process_refund($order_id, $amount = null, $reason = '')
        {
            self::writeLog('process_refund', '');
            self::writeLog('$order_id', $order_id);
            $order = wc_get_order($order_id);
            self::writeLog(array('$order' => (array)$order));
//            self::writeLog(array('$order' => var_dump($order)));

            if (!$order || !$order->get_transaction_id()) {
                return new WP_Error('tranzzo_refund_error', __('Refund Error: Payment for this order has not been determined.', 'tranzzo'));
            }
            if (0 == $amount || null == $amount) {
                return new WP_Error('tranzzo_refund_error', __('Refund Error: You need to specify a refund amount.', 'tranzzo'));
            }

            $old_wc = version_compare(WC_VERSION, '3.0', '<');
//            $order_amount = $order->get_total();
//            self::writeLog(array('$order_amount' => $order_amount));
            if ($old_wc) {
                $order_currency = get_post_meta($order_id, '_order_currency', true);
            } else {
                $order_currency = $order->get_currency();
            }
            require_once(__DIR__ . '/TranzzoApi.php');
            $tranzzo_response = get_post_custom_values('tranzzo_response', $order_id);
            $tranzzo_response = json_decode($tranzzo_response[0], true);

            //new
            $order_total = get_post_meta($order_id, '_order_total', true);
            if ($amount < $order_total) {
                return new WP_Error('tranzzo_refund_error', __('Refund Error: You need to specify the total refund amount - '.$order_total.' '.$order_currency.'.', 'tranzzo'));
            }
            //new
            $tranzzo = new TranzzoApi($this->POS_ID, $this->API_KEY, $this->API_SECRET, $this->ENDPOINTS_KEY);
            $data = [

                'order_currency' => $order_currency,
                'refund_date' => date('Y-m-d H:i:s'),
                //new
                'order_id' => strval($tranzzo_response['order_id']),
//                'order_id' => strval($tranzzo_response['provider_order_id']),
//                'order_amount' => $tranzzo->amountToDouble($order_amount),
//                'amount' => $tranzzo->amountToDouble($amount),
                'order_amount' => strval($tranzzo_response['amount']),
                'provider_order_id' => strval($tranzzo_response['provider_order_id']),
                //new
                'server_url' => add_query_arg('wc-api', __CLASS__, home_url('/')),
            ];
//            //new for partial refund
//            if ($amount < $tranzzo_response['amount']) {
//                $data['refund_amount'] = strval($amount);
//            }
//            //new
            self::writeLog(array('data' => $data));

            //new
            switch ($tranzzo_response['method']) {
                case TranzzoApi::P_METHOD_AUTH:
                    $response = $tranzzo->createVoid($data);
                    break;
                case TranzzoApi::P_METHOD_CAPTURE:
                    $response = $tranzzo->createRefund($data);
                    break;
                case TranzzoApi::P_METHOD_PURCHASE:
                    $response = $tranzzo->createRefund($data);
                    break;
            }
            //new

            self::writeLog(array('response' => $response));
            self::writeLog('status', $response['status']);

            if ($response['status'] != 'success') {
                return new WP_Error('tranzzo_refund_error', __($response['message'], 'tranzzo'));
            } else {
                self::writeLog('success', '');
                $refund_message = sprintf(__('Refunded %1$s - Reason: %3$s', 'tranzzo'), $amount, $reason);
                self::writeLog('$refund_message', $refund_message);
                //new
//                $order->update_status('failed');
//                $order->update_status('cancelled', __('Awaiting cheque payment', 'woothemes'));
                //new
                $order->add_order_note($refund_message);
                $order->save();
                self::writeLog(array('$order222' => (array)$order));
                return true;
            }
        }


        //new
        public function capture_payment($order_id)
        {
            self::writeLog('capture', $order_id);
            //401 class wc gateway paypal
            $order = wc_get_order($order_id);
            require_once(__DIR__ . '/TranzzoApi.php');
            $tranzzo_response = get_post_custom_values('tranzzo_response', $order_id);
            $tranzzo_response = json_decode($tranzzo_response[0], true);


            if ($this->id === $order->get_payment_method() && TranzzoApi::P_METHOD_AUTH === $tranzzo_response['method'] && $order->get_transaction_id()) {
//                self::writeLog(array('this' => (array)$this));

                $old_wc = version_compare(WC_VERSION, '3.0', '<');
                if ($old_wc) {
                    $order_currency = get_post_meta($order_id, '_order_currency', true);
                } else {
                    $order_currency = $order->get_currency();
                }

                $tranzzo = new TranzzoApi($this->POS_ID, $this->API_KEY, $this->API_SECRET, $this->ENDPOINTS_KEY);
                $data = [

                    'order_currency' => $order_currency,
                    'refund_date' => date('Y-m-d H:i:s'),
                    'order_id' => strval($tranzzo_response['order_id']),
                    'order_amount' => strval($tranzzo_response['amount']),
                    'provider_order_id' => strval($tranzzo_response['provider_order_id']),
                    'server_url' => add_query_arg('wc-api', __CLASS__, home_url('/')),
                ];
                $response = $tranzzo->createCapture($data);

                self::writeLog(array('response' => $response));
                self::writeLog('status', $response['status']);

                if ($response['status'] != 'success') {
                    return new WP_Error('tranzzo_refund_error', __($response['message'], 'tranzzo'));
                } else {
                    self::writeLog('success', '');
//                    $refund_message = sprintf(__('Refunded %1$s - Reason: %3$s', 'tranzzo'), $amount, $reason);
//                    self::writeLog('$refund_message', $refund_message);
                    //new
//                $order->update_status('failed');
//                $order->update_status('cancelled', __('Awaiting cheque payment', 'woothemes'));
                    //new
//                    $order->add_order_note($refund_message);
//                    $order->save();
                    self::writeLog(array('$order222' => (array)$order));
                    return true;
                }
            }
//            self::writeLog(array('$tranzzo_response' => (array)$tranzzo_response));

        }

        //new


        static function writeLog($data, $flag = '', $filename = 'info')
        {
        	$show = false;
        	if ($show) {
            	file_put_contents(__DIR__ . "/{$filename}.log", "\n\n" . date('H:i:s') . " - $flag \n" .
                (is_array($data) ? json_encode($data, JSON_PRETTY_PRINT) : $data)
                , FILE_APPEND);
            }
        }
    }

    function woocommerce_add_tranzzo_gateway($methods)
    {
        $methods[] = 'WC_Gateway_Tranzzo';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_tranzzo_gateway');


}