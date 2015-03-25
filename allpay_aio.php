<?php

include_once('AllPay.Payment.Integration.php');

/*
 * Plugin Name:  歐付寶 - All in one 收款模組
 * Plugin URI: http://www.ecbank.com.tw/module/index.php
 * Description: AllPay Payment ALL Gateway for WooCommerce
 * Version: 1.1.0
 * Author: Kaija <kaija.chang@gmail.com>
 * Author URI: http://www.penroses.co
 */

add_action('plugins_loaded', 'AllPay_ALL_Init', 0);

function AllPay_ALL_Init() {
    // Make sure WooCommerce is setted.
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_ALL extends WC_Payment_Gateway {
        protected $line_items = array();
        protected $one_line_items = array();

        var $log;
        var $method_title;
        var $merchant_id;
        var $hash_key;
        var $hash_iv;
        var $testmode;
        var $testmode_prefix;
        var $order_prefix;
        var $debug;
        var $return_url;
        var $client_back_url;
        var $order_result_url;
        var $payment_info_url;
        var $service_url;

        public function __construct() {
            // Initialize construct properties
            $this->id = "all";
            $this->method_title = __('AllPay (ALL in One)', 'woocommerce');
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
            $this->order_prefix = $this->settings['order_prefix'];
            $this->debug = $this->settings['debug'];

            $this->return_url = add_query_arg('wc-api', 'WC_Gateway_ALL', home_url('/')) . '&callback=return';
            $this->client_back_url = trailingslashit(home_url());
            $this->order_result_url = add_query_arg('wc-api', 'WC_Gateway_ALL', home_url('/'));
            $this->payment_info_url = add_query_arg('wc-api', 'WC_Gateway_ALL', home_url('/')) . '&callback=payment_info';
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
                    'default' => __('AllPay - AIO Payment Gateway', 'woocommerce')
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Pay securely through AllPay Secure Servers.', 'woocommerce')
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
                'order_prefix' => array(
                    'title' => __('Order Prefix', 'woocommerce'),
                    'type' => 'text',
                    'description' => __("Mass Production Order Prefix", 'woocommerce'),
					'default' => 'woo'
                ),
                'testmode_prefix' => array(
                    'title' => __('Test Order Prefix', 'woocommerce'),
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
            echo '<h3>' . __('AllPay - AIO Payment Gateway', 'woocommerce') . '</h3>';
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
        protected function get_order_item_names( $order ) {
            $item_names = array();

            foreach ( $order->get_items() as $item ) {
                $item_names[] = $item['name'] . ' x ' . $item['qty'];
            }
            return implode( ', ', $item_names );
        }
		protected function get_order_item_name( $order, $item ) {
            $item_name = $item['name'];
            $item_meta = new WC_Order_Item_Meta( $item['item_meta'] );

            if ( $meta = $item_meta->display( true, true ) ) {
                $item_name .= ' ( ' . $meta . ' )';
            }
            return $item_name;
        }
        protected function add_line_item( $item_name, $quantity = 1, $amount = 0, $item_number = '' ) {
            $index = ( sizeof( $this->line_items ) / 4 ) + 1;

            if ( ! $item_name || $amount < 0 || $index > 9 ) {
                return false;
            }

            $this->line_items[ 'item_name_' . $index ]   = html_entity_decode( wc_trim_string( $item_name, 127 ), ENT_NOQUOTES, 'UTF-8' );
            $this->line_items[ 'quantity_' . $index ]    = $quantity;
            $this->line_items[ 'amount_' . $index ]      = $amount;
            $this->line_items[ 'item_number_' . $index ] = $item_number;

            return true;
        }

        protected function get_one_line_items()
        {
            return $this->one_line_items;
        }

        protected function del_one_line_items()
        {
            $this->one_line_items = array();
        }
        protected function append_one_line_items($name, $qty, $price)
        {
			$name_only = strtok($name, ' (');
            array_push($this->one_line_items, array('Name' => $name_only, 'Price' => $price, 'Currency' => get_woocommerce_currency(), 'Quantity' => $qty, 'URL' => ''));
        }
        protected function get_line_items(){
            return $this->line_items;
        }
        protected function delete_line_items(){
	        $this->line_items = array();
        }
        protected function prepare_line_items($order){
            $this->delete_line_items();
            $calculated_total = 0;

            // Products
            foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
                if ( 'fee' === $item['type'] ) {
                    $line_item        = $this->add_line_item( $item['name'], 1, $item['line_total'] );
                    $calculated_total += $item['line_total'];
				    $this->append_one_line_items($item['name'], 1, $item['line_total']);
                } else {
                    $product          = $order->get_product_from_item( $item );
                    $line_item        = $this->add_line_item( $this->get_order_item_name( $order, $item ), $item['qty'], $order->get_item_subtotal( $item, false ), $product->get_sku() );
                    $calculated_total += $order->get_item_subtotal( $item, false ) * $item['qty'];
				    $this->append_one_line_items($this->get_order_item_name( $order, $item ), $item['qty'], $order->get_item_subtotal( $item, false ));
                }

                if ( ! $line_item ) {
                    return false;
                }
            }

            // Shipping Cost item - paypal only allows shipping per item, we want to send shipping for the order
            if ( $order->get_total_shipping() > 0 && ! $this->add_line_item( sprintf( __( 'Shipping via %s', 'woocommerce' ), $order->get_shipping_method() ), 1, round( $order->get_total_shipping(), 2 ) ) ) {
                return false;
            }else{
			    $this->append_one_line_items('ship'.$order->get_shipping_method(), 1, $order->get_total_shipping());
		    }

            // Check for mismatched totals
            if ( ( $calculated_total + $order->get_total_tax() + round( $order->get_total_shipping(), 2 ) - round( $order->get_total_discount(), 2 ) ) != $order->get_total() ) {
                return false;
            }

            return true;
	    }
        public function receipt_page($order) {
            global $woocommerce;
            $oOrder = new WC_Order($order);

            if ($oOrder) {
                echo '<p>' . __('Thank you - your order is now pending payment. You should be automatically redirected to AllPay to make payment.', 'woocommerce') . '</p>';

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
					//$oPayment->Send['MerchantTradeNo'] = ('yes' == $this->testmode ? $this->testmode_prefix : '') . $oOrder->id;
					if('yes' == $this->testmode){
                    	$oPayment->Send['MerchantTradeNo'] = $this->testmode_prefix.$oOrder->id;
					}else{
                    	$oPayment->Send['MerchantTradeNo'] = $this->order_prefix.$oOrder->id;
					}
                    $oPayment->Send['MerchantTradeDate'] = date('Y/m/d H:i:s');
                    $oPayment->Send['TotalAmount'] = round($oOrder->get_total());
                    $oPayment->Send['TradeDesc'] = "AllPay_WooCommerce_Module";
                    $oPayment->Send['ChoosePayment'] = PaymentMethod::ALL;
                    $oPayment->Send['Remark'] = '';
                    $oPayment->Send['ChooseSubPayment'] = PaymentMethodItem::None;
                    $oPayment->Send['NeedExtraPaidInfo'] = ExtraPaymentInfo::No;
                    $oPayment->Send['DeviceSource'] = DeviceType::PC;
					$this->del_one_line_items();
					if($this->prepare_line_items( $oOrder )){//Parepare all item
						foreach($this->get_one_line_items() as $value ){
					        //Push to allpay array
                            array_push($oPayment->Send['Items'], $value);
						}
                    }else{
                        array_push($oPayment->Send['Items'], array('Name' => '商品一批', 'Price' => $oPayment->Send['TotalAmount'], 'Currency' => get_woocommerce_currency(), 'Quantity' => 1, 'URL' => ''));
                    }

                    //echo $oPayment->CheckOutString("Pay");
                    //Direct go to allpay page
                    echo $oPayment->CheckOutString(null);
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
                    if($this->testmode){
                        $szOrderID = ($this->testmode ? str_replace($this->testmode_prefix, '', $szOrderID) : $szOrderID);
				    }else{
                        $szOrderID = str_replace($this->order_prefix, '', $szOrderID);
				    }
                    $deTradeAmount = $arFeedback['TradeAmt'];
                    $szReturnCode = $arFeedback['RtnCode'];
                    $szReturnMessgae = $arFeedback['RtnMsg'];
                    // 查詢系統訂單。
                    $oOrder = new WC_Order($szOrderID);
                    $deTotalAmount = $oOrder->get_total();
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
    function woocommerce_add_allpay_aio_plugin($methods) {
        $methods[] = 'WC_Gateway_ALL';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_allpay_aio_plugin');
}
