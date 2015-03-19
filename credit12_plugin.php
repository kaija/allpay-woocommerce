<?php

include_once('AllPay.Payment.Integration.php');

/*
 * Plugin Name:  歐付寶 - Credit Card 分 12 期收款模組
 * Plugin URI: http://www.ecbank.com.tw/module/index.php
 * Description: AllPay Payment Gateway by Credit Card in 12 Installments for WooCommerce
 * Version: 1.0
 * Author: AllPay Financial Information Service Co., Ltd.
 * Author URI: http://www.allpay.com.tw
 */

add_action('plugins_loaded', 'AllPay_Credit12_Plugin_Init', 0);

function AllPay_Credit12_Plugin_Init() {
    // Make sure WooCommerce is setted.
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Credit12 extends WC_Payment_Gateway {

        var $log;
        var $method_title;
        var $merchant_id;
        var $hash_key;
        var $hash_iv;
        var $testmode;
        var $testmode_prefix;
        var $debug;
        var $return_url;
        var $client_back_url;
        var $order_result_url;
        var $payment_info_url;
        var $service_url;

        public function __construct() {
            // Initialize construct properties
            $this->id = "credit12";
            $this->method_title = __('AllPay (信用卡 12 期)', 'woocommerce');
            $this->icon = apply_filters('woocommerce_allpay_icon', plugins_url('icon/allpay.png', __FILE__));
            $this->has_fields = false;
            // Load the settings
            $this->init_form_fields();
            $this->init_settings();
            // Define uset set variables
            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->merchant_id = $this->settings['merchant_id'];
            $this->hash_key = $this->settings['hash_key'];
            $this->hash_iv = $this->settings['hash_iv'];
            $this->testmode = $this->settings['testmode'];
            $this->testmode_prefix = $this->settings['testmode_prefix'];
            $this->debug = $this->settings['debug'];

            $this->return_url = add_query_arg('wc-api', 'WC_Gateway_Credit12', home_url('/')) . '&callback=return';
            $this->client_back_url = trailingslashit(home_url());
            $this->order_result_url = add_query_arg('wc-api', 'WC_Gateway_Credit12', home_url('/'));
            $this->payment_info_url = add_query_arg('wc-api', 'WC_Gateway_Credit12', home_url('/')) . '&callback=payment_info';
            // Logs
            if ('yes' == $this->debug) {
                $this->log = new WC_Logger();
            }
            // Test Mode
            if ('yes' == $this->testmode) {
                $this->service_url = 'http://payment-stage.allpay.com.tw/Cashier/AioCheckOut';
            } else {
                $this->service_url = 'https://payment.allpay.com.tw/Cashier/AioCheckOut';
            }
            // Register actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
            // Payment listener/API hook
            add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'receive_response'));
        }

        function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable', 'woocommerce'),
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default' => __('AllPay - Credit Card in 12 installments Payment Gateway', 'woocommerce')
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Pay securely by Credit Card through AllPay Secure Servers.', 'woocommerce')
                ),
                'merchant_id' => array(
                    'title' => __('Merchant ID', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Your Merchant ID', 'woocommerce'),
                    'default' => '2000132'
                ),
                'hash_key' => array(
                    'title' => __('Hash Key', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Your Hash Key', 'woocommerce'),
                    'default' => '5294y06JbISpM5x9'
                ),
                'hash_iv' => array(
                    'title' => __('Hash IV', 'woocommerce'),
                    'type' => 'text',
                    'description' => __("Your Hash IV", 'woocommerce'),
                    'default' => 'v77hoKGq4kWxNNIS'
                ),
                'testmode' => array(
                    'title' => __('Test Mode', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable', 'woocommerce'),
                    'default' => 'yes'
                ),
                'testmode_prefix' => array(
                    'title' => __('Order Prefix', 'woocommerce'),
                    'type' => 'text',
                    'description' => __("Only for Test Mode", 'woocommerce')
                ),
                'debug' => array(
                    'title' => __('Debug Log', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable logging', 'woocommerce'),
                    'default' => 'no'
                )
            );
        }

        public function admin_options() {
            echo '<h3>' . __('AllPay - Credit Card Payment Gateway', 'woocommerce') . '</h3>';
            echo '<p>' . __('AllPay is most popular payment gateway for online shopping in Taiwan') . '</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }

        /**
         * Process the payment and return the result
         * */
        function process_payment($order_id) {
            global $woocommerce;
            $oOrder = new WC_Order($order_id);

            if ($woocommerce->version > '2.1') {
                return array(
                    'result' => 'success',
                    'redirect' => $oOrder->get_checkout_payment_url(true)
                );
            } else {
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('order', $oOrder->id, add_query_arg('key', $oOrder->order_key, get_permalink(woocommerce_get_page_id('pay'))))
                );
            }
        }

        public function receipt_page($order) {
            global $woocommerce;
            $oOrder = new WC_Order($order);

            if ($oOrder) {
                echo '<p>' . __('Thank you - your order is now pending payment. You should be automatically redirected to PayPal to make payment.', 'woocommerce') . '</p>';

                if ('yes' == $this->debug) {
                    $this->log->add('allpay/' . $this->id . '_plugin', 'Generating payment form for order ' . $oOrder->get_order_number() .
                            '. Service URL: ' . $this->service_url .
                            '. Client Back URL: ' . $this->client_back_url .
                            '. Order Result URL: ' . $this->order_result_url .
                            '. Payment Info URL: ' . $this->payment_info_url .
                            '. Return URL: ' . $this->return_url);
                }

                try {
                    $oPayment = new AllInOne();
                    $oPayment->ServiceURL = $this->service_url;
                    $oPayment->HashKey = $this->hash_key;
                    $oPayment->HashIV = $this->hash_iv;
                    $oPayment->MerchantID = $this->merchant_id;

                    $oPayment->Send['ReturnURL'] = $this->return_url;
                    $oPayment->Send['ClientBackURL'] = $this->client_back_url;
                    $oPayment->Send['OrderResultURL'] = $this->order_result_url;
                    $oPayment->Send['MerchantTradeNo'] = ('yes' == $this->testmode ? $this->testmode_prefix : '') . $oOrder->id;
                    $oPayment->Send['MerchantTradeDate'] = date('Y/m/d H:i:s');
                    $oPayment->Send['TotalAmount'] = round($oOrder->get_order_total());
                    $oPayment->Send['TradeDesc'] = "AllPay_WooCommerce_Module";
                    $oPayment->Send['ChoosePayment'] = PaymentMethod::Credit;
                    $oPayment->Send['Remark'] = '';
                    $oPayment->Send['ChooseSubPayment'] = PaymentMethodItem::None;
                    $oPayment->Send['NeedExtraPaidInfo'] = ExtraPaymentInfo::No;
                    $oPayment->Send['DeviceSource'] = DeviceType::PC;

                    array_push($oPayment->Send['Items'], array('Name' => '商品一批', 'Price' => $oPayment->Send['TotalAmount'], 'Currency' => get_woocommerce_currency(), 'Quantity' => 1, 'URL' => ''));

                    $oPayment->SendExtend['CreditInstallment'] = 12;
                    $oPayment->SendExtend['InstallmentAmount'] = $oPayment->Send['TotalAmount'];
                    $oPayment->SendExtend['Redeem'] = false;
                    $oPayment->SendExtend['UnionPay'] = false;

                    echo $oPayment->CheckOutString("Pay");
                    $woocommerce->cart->empty_cart();
                } catch (Exception $e) {
                    echo '<font color="red">' . $e->getMessage() . '</font>';
                }
            }
        }

        public function receive_response() {
            $oPayment = new AllInOne();
            $oPayment->ServiceURL = ($this->testmode ? 'http://payment-stage.allpay.com.tw/Cashier/QueryTradeInfo' : 'https://payment.allpay.com.tw/Cashier/QueryTradeInfo');
            $oPayment->HashKey = $this->hash_key;
            $oPayment->HashIV = $this->hash_iv;

            try {
                // 取得回傳參數。
                $arFeedback = $oPayment->CheckOutFeedback();
                // 檢核與變更訂單狀態。
                if (sizeof($arFeedback) > 0) {
                    $szOrderID = $arFeedback['MerchantTradeNo'];
                    $szOrderID = ($this->testmode ? str_replace($this->testmode_prefix, '', $szOrderID) : $szOrderID);
                    $deTradeAmount = $arFeedback['TradeAmt'];
                    $szReturnCode = $arFeedback['RtnCode'];
                    $szReturnMessgae = $arFeedback['RtnMsg'];
                    // 查詢系統訂單。
                    $oOrder = new WC_Order($szOrderID);
                    $deTotalAmount = $oOrder->get_order_total();
                    $szOrderStatus = $oOrder->status;
                    // 核對訂單金額。
                    if ($deTradeAmount == $deTotalAmount) {
                        // 當訂單回傳狀態為無異常，更新訂單資料與新增訂單歷程。
                        if ($szReturnCode == 1 || $szReturnCode == 800) {
                            // 更新訂單資料與新增訂單歷程。
                            if ($szOrderStatus == 'pending') {
                                $oOrder->payment_complete();
                            } else {
                                // 訂單已處理，無須再處理。
                            }
                            $szMessage = '1|OK';
                        } else {
                            $szMessage = "0|Order '$szOrderID' Exception.($szReturnCode: $szReturnMessgae)";
                        }
                    } else {
                        $szMessage = '0|Compare "' . $szOrderID . '" Order Amount Fail.';
                    }
                } else {
                    $szMessage = '0|"' . $szOrderID . '" Order Not Found at AllPay.';
                }
            } catch (Exception $e) {
                $szMessage = '0|' . $e->getMessage();
            }

            if ('yes' == $this->debug && $szMessage != '1|OK') {
                $this->log->add('allpay/' . $this->id . '_plugin', $szMessage);
            }

            if (!isset($_GET[callback])) {
                if ($szMessage == '1|OK') {
                    header("Location: " . $this->get_return_url($oOrder));
                } else {
                    wp_die($szMessage, '');
                }
            } else {
                echo $szMessage;
                exit;
            }
        }

        public function thankyou_page() {
            
        }

    }

    /**
     * Add the Gateway Plugin to WooCommerce
     * */
    function woocommerce_add_allpay_credit12_plugin($methods) {
        $methods[] = 'WC_Gateway_Credit12';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_allpay_credit12_plugin');
}
