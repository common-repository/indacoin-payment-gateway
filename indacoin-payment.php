<?php
/*
 * Plugin Name: Indacoin Payment Gateway
 * Plugin URI: https://github.com/IndacoinOrg/woocommerce-plugin
 * Description: Take credit card payments on your store.
 * Author: Indacoin
 * Author URI: http://indacoin.com
 * Version: 1.0.1
 */

if (!defined('ABSPATH')) exit;

// register plugin
add_action('plugins_loaded', 'Indacoin', 0);


function Indacoin ()
{
    // if not exists main class
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }


    function wc_add_indacoin_payment($methods)
    {
        $methods[] = 'WC_Indacoin';
        return $methods;
    }

    // register indacoin payment
    add_filter('woocommerce_payment_gateways', 'wc_add_indacoin_payment');

    class WC_Indacoin extends WC_Payment_Gateway
    {
        const AVAILABLE_CURRENCIES = array(
            'usd' => 'US Dollar (USD)',
            'rub' => 'Russian Ruble (RUB)',
            'eur' => 'EURO (EUR)',
            'gbp' => 'British Pound (GBP)',
            'aud' => 'Australian Dollar (AUD)',
            'sek' => 'Swedish Krona (SEK)',
            'cad' => 'Canada Dollar (CAD)',
            'chf' => 'Swiss Frank (CHF)',
            'dkk' => 'Denmark Krone (DKK)',
            'pln' => 'Polish Zloty (PLN)',
            'czk' => 'Czech Republic Koruna (CZK)',
            'nok' => 'Norway Krone (NOK)',
        );

        const DEFAULT_FIAT_CURRENCY = 'usd';

        const DEFAULT_CRYPTO_CURRENCY = 'btc';

        const DEFAULT_TEST_CRYPTO_CURRENCY = 'intt';

        const API_URL = 'https://indacoin.com';

        const API_NONCE = 100000;

        // Payment callback statuses list
        const STATUS_FINISHED = 'Finished';
        const STATUS_FUNDS_SENT = 'FundsSent';
        const STATUS_DECLINED = 'Declined';
        const STATUS_CANCELED = 'Cancelled';
        const STATUS_FAILED = 'Failed';
        const STATUS_CHARGE_BACK = 'Chargeback';
        const STATUS_PAID = 'Paid';

        // settings
        private $testMode = null;
        private $successUrl = null;
        private $failUrl = null;
        private $partnerName = null;
        private $secretKey = null;
        private $currency = null;
        private $wallet = null;
        private $sharedSec = null;

        /**
         * Contruct method (now we init plugin options)
         *
         * @return void
         */
        public function __construct()
        {

            // init payment method options
            $this->id                 = 'indacoin';
            $this->has_fields         = true;
            $this->icon               = plugin_dir_url(__FILE__) . 'icon.jpg';
            $this->title              = 'Indacoin';
            $this->method_title       = 'Indacoin';
            $this->method_description = 'Indacoin Payment method';
            $this->supports           = array('products', 'pre-orders');
            $this->enabled            = $this->get_option('enabled');

            // init settings data
            $this->init_form_fields();
            $this->init_settings();
            $this->generateSharedSec();

            // Define user set variables
            $this->title              = $this->get_option('title');
            $this->description        = $this->get_option('description');
            $this->testMode           = $this->get_option('testMode') === 'yes';
            $this->successUrl         = $this->get_option('successUrl');
            $this->failUrl            = $this->get_option('failUrl');
            $this->partnerName        = $this->get_option('partnerName');
            $this->secretKey          = $this->get_option('secretKey');
            $this->currency           = $this->get_option('currency');
            $this->wallet             = $this->get_option('wallet');
            $this->sharedSec          = $this->get_option('sharedSec');

            add_action('wc_add_indacoin_payment', array($this, 'payment_page'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this,'process_admin_options'));
            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'callbackHandler'));
        }

        /**
         * Generate shared sec
         */
        private function generateSharedSec() {
            if (!$this->get_option('sharedSec')) {
                $this->update_option('sharedSec', $this->generateRandomString(32));
            }
        }

        /**
         * Generate random string
         *
         * @param int $length
         * @return string
         */
        private  function generateRandomString($length = 10) {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $charactersLength = strlen($characters);
            $randomString = '';
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[rand(0, $charactersLength - 1)];
            }
            return $randomString;
        }

        /**
         * Get enabled
         *
         * @return bool
         */
        private function getEnabled()
        {
            return $this->enabled === 'yes';
        }

        /**
         * Get testMode
         *
         * @return bool
         */
        private function getTestMode()
        {
            return $this->testMode;
        }

        /**
         * Success url
         *
         * @return string
         */
        private function getSuccessUrl() {
            return $this->successUrl;
        }

        /**
         * Fail url
         *
         * @return string
         */
        private function getFailUrl() {
            return $this->failUrl;
        }

        /**
         * Partner name
         *
         * @return string
         */
        private function getPartnerName() {
            return $this->partnerName;
        }

        /**
         * Partner name
         *
         * @return string
         */
        private function getSecretKey() {
            return $this->secretKey;
        }

        /**
         * Currency
         *
         * @return string
         */
        private function getCurrency() {
            return $this->currency;
        }

        /**
         * Wallet
         *
         * @return string
         */
        private function getWallet() {
            return $this->wallet;
        }
        /**
         * Get sharedSec
         *
         * @return string
         */
        private function getSharedSec() {
            return  $this->sharedSec;
        }

        /**
         * Get default callback url
         *
         * @return string | null
         */
        private function getDefaultCallbackUrl()
        {
            return home_url('/wc-api/'.strtolower(get_class($this)));
        }

        /**
         * @inheritDoc
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'woocommerce'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable Indacoin', 'woocommerce'),
                    'default' => 'no',
                ),
                'testMode' => array(
                    'title'   => __('Test Mode', 'woocommerce'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable Test Mode', 'woocommerce'),
                    'default' => 'no',
                ),
                'title' => array(
                    'title'       => __('Title', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Title on the payment page.', 'woocommerce' ),
                    'default'     => __('Indacoin', 'woocommerce'),
                    'desc_tip'    => false,
                ),
                'description' => array(
                    'title'       => __('Description', 'woocommerce' ),
                    'type'        => 'textarea',
                    'description' => __('Description of the payment parameter on the payment page.', 'woocommerce' ),
                    'default'     => __('Indacoin Payment Method', 'woocommerce'),
                    'desc_tip'    => false,
                ),
                'wallet' => array(
                    'title'       => __('BTC/INTT (TestMode Only) Wallet', 'woocommerce' ),
                    'type'        => 'text',
                    'description' => __('Your wallet in Indacoin', 'woocommerce' ),
                    'default'     => __('', 'woocommerce'),
                    'desc_tip'    => false,
                ),
                'secretKey' => array(
                    'title'       => __('Secret Key', 'woocommerce' ),
                    'type'        => 'text',
                    'description' => __('Your secret_key in Indacoin', 'woocommerce' ),
                    'default'     => __('', 'woocommerce'),
                    'desc_tip'    => false,
                ),
                'partnerName' => array(
                    'title'       => __('Partner Name', 'woocommerce' ),
                    'type'        => 'text',
                    'description' => __('Your partner_name in Indacoin', 'woocommerce' ),
                    'default'     => __('', 'woocommerce'),
                    'desc_tip'    => false,
                ),
                'currency' => array(
                    'title'       => __('Shop Currency', 'woocommerce' ),
                    'type'        => 'select',
                    'description' => __('Your sho currency', 'woocommerce' ),
                    'default'     => __(self::DEFAULT_FIAT_CURRENCY, 'woocommerce'),
                    'options' => self::AVAILABLE_CURRENCIES,
                    'desc_tip'    => false,
                ),
                'successUrl' => array(
                    'title'       => __('Success URL', 'woocommerce' ),
                    'type'        => 'text',
                    'description' => __('Success URL to go after successfully payment (success_url). Leave it blank if you don’t know what it is.', 'woocommerce' ),
                    'default'     => __('', 'woocommerce'),
                    'desc_tip'    => false,
                ),
                'failUrl' => array(
                    'title'       => __('Fail URL', 'woocommerce' ),
                    'type'        => 'text',
                    'description' => __('Fail URL to go after fail payment (fail_url). Leave it blank if you don’t know what it is.', 'woocommerce' ),
                    'default'     => __('', 'woocommerce'),
                    'desc_tip'    => false,
                ),
            );
        }

        /**
         * @inheritdoc
         */
        public function admin_options()
        {
            parent::admin_options();

            $cbUrl = $this->getDefaultCallbackUrl();

            echo "
                <p>
                    <strong>Callback Url - URL to confirm the transaction: </strong>
                    $cbUrl 
                </p>
            ";
        }


        /**
         * @inheritdoc
         */
        public function process_payment($order_id)
        {
            $order = new WC_Order( $order_id );

            // Mark as on-hold
            $order->update_status('pending');
            $order->add_order_note(__( 'Pending Payment (Indacoin)<br\>', 'woocommerce' ));

            // Remove cart
            WC()->cart->empty_cart();

            // generate url
            return array(
                'result' => 'success',
                'redirect' => $this->generatePaymentUrl($order),
            );
        }

        /**
         * Generate payment url
         *
         * @param WC_Order $order
         *
         * @return string
         */
        private function generatePaymentUrl(WC_Order $order)
        {

            $params = array (
                'user_id' => '',
                'cur_in' => '',
                'cur_out' => '',
                'target_address' => '',
                'amount_in' => '',
                'success_url' => '',
                'extra_info' => [],
            );

            if (!$this->getPartnerName() || !$this->getSecretKey()) {
                return '';
            }

            // set user_id
            if ($order->get_billing_email()) {
                $params['user_id'] = $order->get_billing_email();
            } elseif (WC()->customer->get_email()) {
                $params['user_id'] = WC()->customer->get_email();
            } else {
                $params['user_id'] = $this->getPartnerName() . '_' . $order->get_id();
            }

            // set cur_in
            if (array_key_exists($this->getCurrency(), self::AVAILABLE_CURRENCIES)) {
                $params['cur_in'] = $this->getCurrency();
            } else {
                $params['cur_in'] = self::DEFAULT_FIAT_CURRENCY;
            }

            // set cur_out
            $params['cur_out'] = $this->getTestMode() ? self::DEFAULT_TEST_CRYPTO_CURRENCY : self::DEFAULT_CRYPTO_CURRENCY;

            // set target_address
            if (!$this->getWallet()) {
                return '';
            }

            $params['target_address'] = $this->getWallet();

            // set amount_it
            $params['amount_in'] = (float)$order->get_total() - (float)$order->get_shipping_total();

            // set success_url
            $params['success_url'] = $this->getSuccessUrl();

            if (!$params['success_url']) {
                $params['success_url'] = $this->get_return_url($order);
            }

            // set fail_url
            if ($this->getFailUrl()) {
                $params['fail_url'] = $this->getFailUrl();
            }

            // set extra info
            $params['extra_info'] = array(
                'orderId' => $order->get_id(),
                'sign' => $this->generatePaymentSign($order)
            );


            $string = $this->getPartnerName() . '_' . self::API_NONCE;
            $sig = base64_encode(hash_hmac('sha256', $string, $this->getSecretKey(), true));

            $headers = array();
            array_push($headers, 'Content-Type: application/json');
            array_push($headers, 'gw-partner: ' . $this->getPartnerName());
            array_push($headers, 'gw-nonce: ' . self::API_NONCE);
            array_push($headers, 'gw-sign: ' . $sig);

            $context = array(
                'http' => array(
                    'header' => implode("\r\n", $headers),
                    'method' => 'POST',
                    'content' => json_encode($params)
                )
            );

            $response = file_get_contents(
                self::API_URL . '/api/exgw_createTransaction',
                false,
                stream_context_create($context)
            );

            if (!$response) {
                return '';
            }

            $string= $this->getPartnerName() ."_". $response;
            $sig = base64_encode(base64_encode(hash_hmac('sha256', $string, $this->getSecretKey(), true)));

            return self::API_URL . '/gw/payment_form?transaction_id=' . $response . '&partner=' . $this->getPartnerName() . '&cnfhash=' . $sig;
        }

        /**
         * Generate payment sign
         *
         * @param WC_Order $order
         * @return string
         */
        private function generatePaymentSign(WC_Order $order)
        {
            return md5($order->get_id() . '-' . $order->get_total() . '-' . $this->getSharedSec());
        }

        /**
         * Callback handler
         */
        public function callbackHandler() {
            $response = array (
                'status' => 'fail'
            );

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $response['error'] = 'Not supported';
                echo json_encode($response);
                exit;
            }

            if (!$body = json_decode(file_get_contents('php://input'), true)) {
                $response['error'] = 'Bad body';
                echo json_encode($response);
                exit;
            }

            if (
                !isset($_SERVER['HTTP_GW_SIGN']) || !isset($_SERVER['HTTP_GW_NONCE']) ||
                !isset($body['userId']) || !isset($body['transactionId']) ||
                !isset($body['status']) || !isset($body['extra_info']) ||
                !isset($body['extra_info']['orderId']) || !isset($body['extra_info']['sign'])
            ) {
                $response['error'] = 'Missing params';
                echo json_encode($response);
                exit;
            }

            $headerSign = $_SERVER['HTTP_GW_SIGN'];
            $nonce = $_SERVER['HTTP_GW_NONCE'];
            $orderId = $body['extra_info']['orderId'];
            $paymentSign = $body['extra_info']['sign'];
            $status = $body['status'];

            $sign = base64_encode(base64_encode(hash_hmac(
                'sha256',
                $this->getPartnerName() . '_' .
                $body['userId'] . '_' .
                $nonce .  '_' .
                $body['transactionId'],
                $this->getSecretKey(),
                true
            )));

            if ($headerSign !== $sign) {
                $response['error'] = 'Bad sign';
                echo json_encode($response);
                exit;
            }

            // check order
            try {
                // get order
                $order = new WC_Order($orderId);
            } catch (\Exception $e) {
                $response['error'] = 'Order not exists';
                echo json_encode($response);
                exit;
            }

            if ($this->generatePaymentSign($order) !== $paymentSign) {
                $response['error'] = 'Bad payment sign';
                echo json_encode($response);
                exit;
            }

            $response['status'] = 'ok';
            $note = __( 'Payment status: ' . $status . ' (Indacoin)<br/>', 'woocommerce' );

            switch ($status) {
                case self::STATUS_FUNDS_SENT:
                case self::STATUS_FINISHED:
                    if ($order->get_status() !== 'processing') {
                        $order->update_status('processing', $note);
                    } else {
                        $order->add_order_note($note);
                    }
                    break;

                case self::STATUS_DECLINED:
                case self::STATUS_FAILED:
                    if ($order->get_status() !== 'failed') {
                        $order->update_status('failed', $note);
                    } else {
                        $order->add_order_note($note);
                    }
                    break;


                case self::STATUS_CANCELED:
                    $order->update_status('cancelled', $note);
                    break;

                case self::STATUS_CHARGE_BACK:
                    $order->update_status('refunded', $note);
                    break;

                case self::STATUS_PAID:
                    $order->update_status('on-hold', $note);
                    break;

                default:
                    $response['status'] = 'fail';
                    $response['error'] = 'Unsupported status';
            }

            echo json_encode($response);
            exit;
        }

    }
}
