<?php
/**
 * Plugin Name: Easy Digital Downloads Fat Zebra Gateway
 * Plugin URI: https://www.fatzebra.com.au
 * Description: Add support for the Fat Zebra Payment Gateway to Easy Digital Downloads
 * Author: Matthew Savage
 * Author URI: https://www.fatzebra.com.au
 * Version: 1.0.0
 * Text Domain: edd
 * Domain Path: languages
 *
 * @package EDD
 * @category Fat Zebra
 * @author Matthew Savage
 * @version 1.0.0
 */
 define("FZ_EDD_VERSION", "1.0.0");

/* Copyright (C) 2013 Fat Zebra Pty. Ltd.

  Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"),
  to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
  of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
  
  The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
  IN THE SOFTWARE.
 */

  /* Register the gateway */
  add_filter("edd_payment_gateways", "fz_edd_payment_gateways", 10, 2);
  function fz_edd_payment_gateways($gateways) {
    return array_merge($gateways, array("fatzebra" => array("admin_label" => "Fat Zebra", "checkout_label" => "Credit Card")));
  }

  /* Hook the payment gateway settings */
  add_filter("edd_settings_gateways", "fz_edd_settings_gateways", 10, 2);
  function fz_edd_settings_gateways($settings) {
    return array_merge($settings, array(
      'fatzebra' => array(
        'id' => 'fatzebra',
        'name' => '<strong>Fat Zebra Settings</strong>',
        'desc' => 'Configure the Fat Zebra settings',
        'type' => 'header'
      ),
      'fatzebra_username' => array(
        'id' => 'fatzebra_username',
        'name' => 'Username',
        'desc' => 'The Username for your Fat Zebra account',
        'type' => 'text',
        'size' => 'regular' 
      ),
      
      'fatzebra_token' => array(
        'id' => 'fatzebra_token',
        'name' => 'Token',
        'desc' => 'The Token for your Fat Zebra account',
        'type' => 'text',
        'size' => 'regular' 
      )
    ));
  }


  /* Override the possible cards to only show the FZ options plus PayPal */
  add_filter('edd_accepted_payment_icons', 'fz_edd_accepted_payment_icons', 10, 2);
  function fz_edd_accepted_payment_icons($types) {
    return array('mastercard' => 'Mastercard',
                 'visa' => 'Visa',
                 'americanexpress' => 'American Express',
                 'paypal' => 'PayPal');
  } 

  /* The function to handle the payment call */
  add_action('edd_gateway_fatzebra', 'fz_edd_process_fatzebra_purchase', 10, 1);
  function fz_edd_process_fatzebra_purchase($payment_data) {
    global $edd_options;

    $username = $edd_options['fatzebra_username'];
    $token = $edd_options['fatzebra_token'];
    $sandbox_mode = (bool)$edd_options['test_mode'];
    $url = $sandbox_mode ? "https://gateway.sandbox.fatzebra.com.au/v1.0/purchases" : "https://gateway.fatzebra.com.au/v1.0/purchases";

    $data = $payment_data['post_data'];

    $payment = edd_insert_payment(array(
      'price' => $payment_data['price'],
      'date'  => $payment_data['date'],
      'user_email' => $payment_data['user_email'],
      'purchase_key' => $payment_data['purchase_key'],
      'currency' => edd_get_currency(),
      'downloads' => $payment_data['downloads'],
      'user_info' => $payment_data['user_info'],
      'cart_details' => $payment_data['cart_details'],
      'status' => 'pending'
    ));
    
    if (! $payment) {
      // Remove card number and cvv from payment data before logging...
      unset($payment_data['post_data']['card_number']);
      unset($payment_data['post_data']['card_cvc']);
      unset($payment_data['card_info']['card_number']);
      unset($payment_data['card_info']['card_cvc']);

      edd_record_gateway_error("Payment Error", sprintf("Payment creation failed before sending to Fat Zebra. Payment Data: %s", $payment_data));
      edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
      return;
    }

    $request = array('amount' => (int)((float)$payment_data['price'] * 100),
                     'customer_ip' => $_SERVER['REMOTE_ADDR'],
                     'card_holder' => $data['card_name'],
                     'card_number' => $data['card_number'],
		     'card_expiry' => $data['card_exp_month'] . "/" . $data['card_exp_year'],
		     'cvv' => $data['card_cvc'],
		     'reference' => $payment,
		     'currency' => edd_get_currency()
		     );

    // Prepare to make the payment
    $request_args = array(
      'method' => 'POST',
      'body' => json_encode($request),
      'headers' => array(
        'Authorization' => 'Basic ' . base64_encode($username .':'. $token),
	'User-Agent' => 'Easy Digital Downloads Plugin ' . FZ_EDD_VERSION,
	'X-Test-Mode' => $sandbox_mode
      ),
      'timeout' => 30
    );

    try {
      $response = (array)wp_remote_request($url, $request_args);
      // Record status, redirect and smile :)

      if($response["response"]["code"] != 200 && $response["response"]["code"] !== 201) {
        edd_update_payment_status($payment, 'failed');
	edd_record_gateway_error("Payment Failed", "Credit Card Payment Failed: " . $response["response"]["message"]);
	wp_redirect(edd_get_failed_transaction_uri());
	return;
      }

      $response_data = json_decode($response["body"]);
      if(!$response_data->successful) {
        edd_update_payment_status($payment, 'failed');
	edd_record_gateway_error("Payment Failed", "Credit Card Payment Failed: " . $response_data->errors);
	wp_redirect(edd_get_failed_transaction_uri());
	return;
      }
      
      if (! $response_data->response->successful) {
	edd_update_payment_status($payment, 'declined');
	edd_record_gateway_error("Payment Declined", "Credit Card Payment Declined: " . $response_data->response->message . ". Transaction ID: " . $response_data->response->id);
	wp_redirect(edd_get_failed_transaction_uri());
	return;
      }

      if ($response_data->response->successful) {
	edd_update_payment_status($payment, 'completed');
	wp_redirect(get_permalink($edd_options['success_page']));
      }
    } catch(Exception $exception) {
      // Error message, record on payment, then return to error page
      edd_update_payment_status($payment, 'failed');
      edd_record_gateway_error("Payment Failed", "Credit Card Payment Failed with Exception: " . $exception->message);
      wp_redirect(edd_get_failed_transaction_uri());
      return;
    }
  } 

?>
