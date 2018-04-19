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

load_plugin_textdomain( 'tranzzo', false, basename(TRANZZO_DIR) . '/languages' );

add_action( 'init', 'tranzzo_endpoint' );
add_action( 'pre_get_posts', 'tranzzo_listen_redirect' );
function tranzzo_endpoint() {
    add_rewrite_endpoint( 'tranzzo-redirect', EP_ROOT );
}

function tranzzo_listen_redirect( $query ) {
    if(($query->get('pagename') == 'tranzzo-redirect') || (strpos($_SERVER['REQUEST_URI'], 'tranzzo-redirect') !== false)) {
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

            $this -> POS_ID = trim($this->get_option('POS_ID'));

            $this -> API_KEY = trim($this->get_option('API_KEY'));

            $this->API_SECRET = trim($this->get_option('API_SECRET'));

            $this->ENDPOINTS_KEY = trim($this->get_option('ENDPOINTS_KEY'));

            $this->icon = apply_filters('woocommerce_tranzzo_icon', plugin_dir_url(__FILE__) . '/images/logo.png');

            if (!$this->supportCurrencyTRANZZO()) {
                $this->enabled = 'no';
            }

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Payment listener/API hook
            add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'check_tranzzo_response'));
        }

        public function admin_options()
        {
            if ($this->supportCurrencyTRANZZO()) { ?>
            <h3><?php _e('TRANZZO', 'tranzzo'); ?></h3>
            <table class="form-table">
                <?php $this->generate_settings_html();?>
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
            global $woocommerce;

            $order = new WC_Order($order_id);
            $data_order = $order->get_data();

            if(!empty($data_order)) {
                require_once(__DIR__ . '/TranzzoApi.php');

                $tranzzo = new TranzzoApi($this->POS_ID, $this->API_KEY, $this->API_SECRET, $this->ENDPOINTS_KEY);

                $params = array();
                $params[TranzzoApi::P_REQ_SERVER_URL] = add_query_arg('wc-api', __CLASS__, home_url('/'));
                $params[TranzzoApi::P_REQ_RESULT_URL] = $this->get_return_url($order);
                $params[TranzzoApi::P_REQ_ORDER] = strval($order_id);
                $params[TranzzoApi::P_REQ_AMOUNT] = TranzzoApi::amountToDouble($data_order['total']);
                $params[TranzzoApi::P_REQ_CURRENCY] = $data_order['currency'];
                $params[TranzzoApi::P_REQ_DESCRIPTION] = "Order #{$order_id}";

                if(!empty($data_order['customer_id']))
                    $params[TranzzoApi::P_REQ_CUSTOMER_ID] = strval($data_order['customer_id']);
                else
                    $params[TranzzoApi::P_REQ_CUSTOMER_ID] = !empty($data_order['billing']['email'])? $data_order['billing']['email'] : 'unregistered';

                $params[TranzzoApi::P_REQ_CUSTOMER_EMAIL] = !empty($data_order['billing']['email']) ? $data_order['billing']['email'] : 'unregistered';

                if(!empty($data_order['billing']['first_name']))
                    $params[TranzzoApi::P_REQ_CUSTOMER_FNAME] = $data_order['billing']['first_name'];

                if(!empty($data_order['billing']['last_name']))
                    $params[TranzzoApi::P_REQ_CUSTOMER_LNAME] = $data_order['billing']['last_name'];

                if(!empty($data_order['billing']['phone']))
                    $params[TranzzoApi::P_REQ_CUSTOMER_PHONE] = $data_order['billing']['phone'];

                $params[TranzzoApi::P_REQ_PRODUCTS] = array();

                if(count($data_order['line_items']) > 0) {
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
//                            'entity_id' => '',
                        );
                    }

                    $params[TranzzoApi::P_REQ_PRODUCTS] = $products;
                }

                $response = $tranzzo->createPaymentHosted($params);

                if(!empty($response['redirect_url'])) {
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

//            self::writeLog(array('$_GET' => $_GET, '$_POST' => $_POST,), 'data check', 'notif');

            $data = $_POST['data'];
            $signature = $_POST['signature'];
            if(empty($data) && empty($signature)) die('LOL! Bad Request!!!');

            require_once(__DIR__ . '/TranzzoApi.php');

            $tranzzo = new TranzzoApi($this->POS_ID, $this->API_KEY, $this->API_SECRET, $this->ENDPOINTS_KEY);
            $data_response = json_decode(TranzzoApi::base64url_decode($data), true);
            $order_id = (int)$data_response[TranzzoApi::P_REQ_ORDER];
            if($tranzzo -> validateSignature($data, $signature) && $order_id) {
                $order = wc_get_order($order_id);
                $amount_payment = TranzzoApi::amountToDouble($data_response[TranzzoApi::P_REQ_AMOUNT]);
                $amount_order = TranzzoApi::amountToDouble($order->get_total());
                if ($data_response[TranzzoApi::P_RES_RESP_CODE] == 1000 && ($amount_payment == $amount_order)) {
                    $order->payment_complete();
                    $order->add_order_note(__('Заказ успешно оплачен через TRANZZO', 'tranzzo'));
                    $order->add_order_note("ID платежа(payment id): " . $data_response[TranzzoApi::P_RES_PAYMENT_ID]);
                    $order->add_order_note("ID транзакции(transaction id): " . $data_response[TranzzoApi::P_RES_TRSACT_ID]);
                    $order->save();

                    exit;
                }

                $order->update_status('failed');
                $order->add_order_note(__('Заказ не оплачен', 'tranzzo'));
                $order->save();
                exit;
            }
            exit;
        }

        static function writeLog($data, $flag = '', $filename = 'info')
        {
            file_put_contents(__DIR__ . "/{$filename}.log", "\n\n" . date('H:i:s') . " - $flag \n" .
                (is_array($data)? json_encode($data, JSON_PRETTY_PRINT):$data)
                , FILE_APPEND);
        }
    }

    function woocommerce_add_tranzzo_gateway($methods)
    {
        $methods[] = 'WC_Gateway_Tranzzo';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_tranzzo_gateway');
}