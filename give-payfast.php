<?php
/**
 * Plugin Name: LSX Payfast Gateway for Give
 * Plugin URI: https://www.lsdev.biz/product/givewp-payfast-integration-addon/
 * Description: The LSX Payfast Gateway for GiveWP is the only way to use the powerful Give plugin for WordPress to accept Rands in South Africa. Give is a flexible, robust, and simple WordPress plugin for accepting donations directly on your website.
 * Author: LightSpeed
 * Version: 1.3.0
 * Author URI: https://www.lsdev.biz/products/
 * License: GPL3+
 * Text Domain: payfast_give
 * Domain Path: /languages/

 * @package lsx-give-payfast-gateway
 **/

/**
 * Includes the Payfast recurring class, if the recurring addon is active
 */

$f = dirname(__FILE__);
require_once "$f/classes/PayfastCommon.php";

// Check if Give - Donation Plugin is inactive
if ( ! is_plugin_active( 'give/give.php' ) ) {
	deactivate_plugins( plugin_basename( __FILE__ ) );
	wp_die( "<strong>LSX Payfast Gateway for Give</strong> requires <strong>Give - Donation</strong> plugin to work normally. Please activate it or install it from <a href=\"http://wordpress.org/plugins/give/\" target=\"_blank\">here</a>.<br /><br />Back to the WordPress <a href='" . get_admin_url( null, 'plugins.php' ) . "'>Plugins page</a>." );
}

use Payfast\PayfastCommon\PayfastCommon;

add_action( 'give_gateway_payfast', 'payfast_process_payment' );

/**
 * Registers the Gateway with the recurring classes.
 *
 * @param  array $gateways
 * @return array
 */
function give_payfast_register_gateway( $gateways ) {
	if ( class_exists( 'Give_Recurring' ) ) {
		include_once plugin_dir_path( __FILE__ ) . 'classes/class-give-recurring-payfast.php';
		$give_recurring_payfast = new Give_Recurring_Payfast();
		$gateways['payfast']    = 'Give_Recurring_Payfast';
	}
	return $gateways;
}
add_action( 'give_recurring_available_gateways', 'give_payfast_register_gateway' );

/**
 * Payfast does not need a CC form, so remove it.
 */
add_action( 'give_payfast_cc_form', '__return_false' );

/**
 *    Registers our text domain with WP
 */
function give_payfast_load_textdomain() {
	load_plugin_textdomain( 'payfast_give', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'give_payfast_load_textdomain' );

/**
 * Registers the gateway
 */
function payfast_register_gateway( $gateways ) {
	$gateways['payfast'] = array(
		'admin_label'    => 'Payfast',
		'checkout_label' => __( 'Payfast', 'payfast_give' ),
	);
	return $gateways;
}
add_filter( 'give_payment_gateways', 'payfast_register_gateway' );

/**
 * Processes the order and redirect to the Payfast Merchant page
 */
function payfast_process_payment( $purchase_data, $recurring = false ) {
	$give_options = give_get_settings();

	// check there is a gateway name.
	if ( ! isset( $purchase_data['post_data']['give-gateway'] ) ) {
		return;
	}

	// collect payment data.
	$payment_data = array(
		'price'           => $purchase_data['price'],
		'give_form_title' => $purchase_data['post_data']['give-form-title'],
		'give_form_id'    => $purchase_data['post_data']['give-form-id'],
		'date'            => $purchase_data['date'],
		'user_email'      => $purchase_data['user_email'],
		'purchase_key'    => $purchase_data['purchase_key'],
		'currency'        => give_get_currency(),
		'user_info'       => $purchase_data['user_info'],
		'status'          => 'pending',
		'gateway'         => 'payfast',
	);
	$required     = array(
		'give_first' => __( 'First Name is not entered.', 'payfast_give' ),
		'give_last'  => __( 'Last Name is not entered.', 'payfast_give' ),
	);

	foreach ( $required as $field => $error ) {
		if ( ! $purchase_data['post_data'][ $field ] ) {
			give_set_error( 'billing_error', $error );
		}
	}

	$errors = give_get_errors();

	if ( $errors ) {
		// problems? send back.
		give_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['give-gateway'] );
	} else {

		// not a recurring- do payment insert.
		if ( false === $recurring ) {
			// record the pending payment.
			$payment = give_insert_payment( $payment_data );
			// check payment.
			if ( ! $payment ) {
				// problems? send back.
				give_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['give-gateway'] );
			}
		} else {

			$payment = $recurring->parent_payment_id;
		}

		$total = $purchase_data['price'];

		$seckey = $give_options['payfast_customer_id'] . $give_options['payfast_key'] . $total;
		$seckey = md5( $seckey );

		$payfast_url = give_is_test_mode() ? 'https://sandbox.payfast.co.za/eng/process' : 'https://www.payfast.co.za/eng/process';
		payfast_process_payment_stepB( $redirect, $give_options );
		payfast_process_payment_stepC( $give_options, $purchase_data, $payment, $total, $seckey, $recurring, $payfast_url );
	}
}

function payfast_process_payment_stepB( $redirect, $give_options ) {
	$redirect     = get_permalink( $give_options['success_page'] );
	$query_string = null;

	$cancelurl = give_get_failed_transaction_uri();

	if ( give_is_test_mode() ) {
		give_insert_payment_note( $payment, $cancelurl );
	}
}

function payfast_process_payment_stepC( $give_options, $purchase_data, $payment, $total, $seckey, $recurring, $payfast_url ) {
	$payfast_args  = 'merchant_id=' . $give_options['payfast_customer_id'];
	$payfast_args .= '&merchant_key=' . $give_options['payfast_key'];
	$payfast_args .= '&return_url=' . urlencode( give_get_success_page_uri() );
	$payfast_args .= '&cancel_url=' . urlencode( give_get_failed_transaction_uri() );
	$payfast_args .= '&notify_url=' . urlencode( trailingslashit(home_url() ) );
	$payfast_args .= '&name_first=' . urlencode( $purchase_data['post_data']['give_first'] );
	$payfast_args .= '&name_last=' . urlencode( $purchase_data['post_data']['give_last'] );
	$payfast_args .= '&email_address=' . urlencode( $purchase_data['post_data']['give_email'] );
	$payfast_args .= '&m_payment_id=' . $payment;
	$payfast_args .= '&amount=' . $total;
	$payfast_args .= '&item_name=' . urlencode( $purchase_data['post_data']['give-form-title'] ) . $payment;
	$payfast_args .= '&custom_int1=' . give_is_test_mode() ? 1 : 0;
	$payfast_args .= '&custom_str1=' . $seckey;

	if ( false !== $recurring ) {
		$payfast_args .= '&custom_str2=' . $recurring->profile_id;
		$payfast_args .= '&subscription_type=1';
		switch ( $purchase_data['period'] ) {
			case 'month':
				$frequency = 3;
				break;
			case 'year':
				$frequency = 6;
				break;
			default:
				break;
		}
		$payfast_args .= '&frequency=' . $frequency;
		$payfast_args .= '&cycles=' . $purchase_data['times'];

	}

	if ( isset( $give_options['payfast_pass_phrase'] ) ) {
		$pass_phrase = trim( $give_options['payfast_pass_phrase'] );
	}
	$signature_str = $payfast_args;
	if ( ! empty( $pass_phrase ) ) {
		$signature_str .= '&passphrase=' . urlencode( $pass_phrase );
	}

	$payfast_args .= '&signature=' . md5( $signature_str );

	if ( give_is_test_mode() && function_exists( 'give_record_log' ) ) {
		give_record_log( 'Payfast - #' . $payment, $payfast_args, 0, 'api_requests' );
		give_insert_payment_note( $payment, $payfast_args );
	}

	wp_redirect( $payfast_url . '?' . $payfast_args );
	exit();

}

/**
 * Processes the order and redirect to the Payfast Merchant page
 */

function payfast_get_realip() {
	$client  = wp_unslash( $_SERVER['HTTP_CLIENT_IP'] );
	$forward = wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	$remote  = wp_unslash( $_SERVER['REMOTE_ADDR'] );

	if ( filter_var( $client, FILTER_VALIDATE_IP ) ) {
		$ip = $client;
	} elseif ( filter_var( $forward, FILTER_VALIDATE_IP ) ) {
		$ip = $forward;
	} else {
		$ip = $remote;
	}

	return $ip;
}

/**
 * An action that handles the call from Payfast to tell Give the order was Completed
 */
function payfast_ipn() {
	$give_options  = give_get_settings();
	$payfastCommon = new PayfastCommon( 'yes' === $give_options['payfast_debug_log'] );
	$payfastCommon->pflog( 'ITN request received. Starting to process...' );
	if ( function_exists( 'give_get_settings' ) ) {
		if ( isset( $_REQUEST['m_payment_id'] ) ) {
			give_insert_payment_note( $_REQUEST['m_payment_id'], 'ITN callback has been triggered.' );

			$pfError		 = false;
			$pf_param_string = '';
			$pfDone		  = false;
			$pfData		  = $payfastCommon->pfGetData();
			$pfErrMsg		= '';
			$payfastCommon->pflog( 'Payfast ITN call received' );

			$payfastCommon->pflog( 'Payfast ITN call received' );

			if ( ! $pfError && ! $pfDone ) {
				// Notify Payfast that information has been received
				header( 'HTTP/1.0 200 OK' );
				flush();
				// Get data sent by Payfast
				$payfastCommon->pflog( 'Get posted data' );
				// Posted variables from ITN
				$payfastCommon->pflog( 'Payfast Data: ' . print_r( $pfData, true ) );

				if ( false === $pfData ) {
					$pfError  = true;
					$pfErrMsg = $payfastCommon->PF_ERR_BAD_ACCESS;
				}
			}

			// Verify security signature
			if ( ! $pfError && ! $pfDone ) {
				$payfastCommon->pflog( 'Verify security signature' );
				give_insert_payment_note( $_REQUEST['m_payment_id'], 'Verify security signature' );

				$passPhrase   = $give_options['payfast_pass_phrase'];
				$pfPassPhrase = empty( $passPhrase ) ? null : $passPhrase;
				give_insert_payment_note( $_REQUEST['m_payment_id'], 'Signature Verified' );

				// If signature different, log for debugging
				if ( ! $payfastCommon->pfValidSignature( $pfData, $pfParamString, $pfPassPhrase ) ) {
					$pfError  = true;
					$pfErrMsg = $payfastCommon->PF_ERR_INVALID_SIGNATURE;
					give_insert_payment_note( $_REQUEST['m_payment_id'], 'Signature verification failed' . $pfErrMsg );
				}
			}

			// Verify data received
			verifyDataReceived( $pfError, $payfastCommon, $pfParamString );

			// Update donationa status
			updateDonationStatus( $pfData['payment_status'] );
		}
	}
}

/**
 * @param bool $pfError
 * @param PayfastCommon $payfastCommon
 * @param $pfParamString
 *
 * @return void
 */
function verifyDataReceived( bool $pfError, PayfastCommon $payfastCommon, $pfParamString ): void {
	if ( ! $pfError ) {
		give_insert_payment_note( $_REQUEST['m_payment_id'], 'Verify data received' );

		$pfHost = 'www.payfast.co.za';

		if ( give_is_test_mode() == 1 ) {
			$pfHost = 'sandbox.payfast.co.za';
		}

		$moduleInfo = [
			"pfSoftwareName"	   => 'Give - Donation',
			"pfSoftwareVer"		=> '3.12.0',
			"pfSoftwareModuleName" => 'Payfast-Give',
			"pfModuleVer"		  => '1.3.0',
		];

		$pfValid = $payfastCommon->pfValidData( $moduleInfo, $pfHost, $pfParamString );
		if ( $pfValid ) {
			give_insert_payment_note( $_REQUEST['m_payment_id'], 'ITN message successfully verified by Payfast' );
		} else {
			$pfError  = true;
			$pfErrMsg = $payfastCommon->PF_ERR_BAD_ACCESS;
			give_insert_payment_note( $_REQUEST['m_payment_id'], 'Verify data failed' . $pfErrMsg );
		}
	}
}

/**
 * @param $payment_status
 *
 * @return void
 */
function updateDonationStatus( $payment_status ): void {
	if ( 'COMPLETE' == $payment_status ) {
		if ( ! empty( $_POST['custom_str2'] ) ) {
			$subscription = new Give_Subscription( $_POST['custom_str2'], true );
			// Retrieve pending subscription from database and update it's status to active and set proper profile ID.
			$subscription->update(
				array(
					'profile_id' => $_POST['token'],
					'status'     => 'active',
				)
			);
		}
		give_set_payment_transaction_id( $_POST['m_payment_id'], $_POST['pf_payment_id'] );
		// translators:
		give_insert_payment_note( $_POST['m_payment_id'], sprintf( __( 'Payfast Payment Completed. The Transaction Id is %s.', 'payfast_give' ), $_POST['pf_payment_id'] ) );
		give_update_payment_status( $_POST['m_payment_id'], 'publish' );

	} else {
		// translators:
		give_insert_payment_note( $_POST['m_payment_id'], sprintf( __( 'Payfast Payment Failed. The Response is %s.', 'payfast_give' ), print_r( $payment_status, true ) ) );
	}
}
add_action( 'wp_head', 'payfast_ipn' );

/**
 * Registers our Payfast setting with Give.
 *
 * @param  $settings
 * @return array
 */
function payfast_add_settings( $settings ) {

	$payfast_settings = array(

		array(
			'id'   => 'payfast_settings',
			'name' => __( 'Payfast Settings', 'payfast_give' ),
			'type' => 'give_title',
		),
		array(
			'id'   => 'payfast_customer_id',
			'name' => __( 'Payfast Merchant ID', 'payfast_give' ),
			'desc' => __( 'Please enter your Payfast Merchant Id; this is needed in order to take payment.', 'payfast_give' ),
			'type' => 'text',
			'size' => 'regular',
		),
		array(
			'id'   => 'payfast_key',
			'name' => __( 'Payfast Key', 'payfast_give' ),
			'desc' => __( 'Please enter your Payfast Key; this is needed in order to take payment.', 'payfast_give' ),
			'type' => 'text',
			'size' => 'regular',
		),
		array(
			'id'   => 'payfast_pass_phrase',
			'name' => __( 'Account Passphrase', 'payfast_give' ),
			'desc' => __( 'This is set by yourself in the "Settings" section of the logged in area of the Payfast Dashboard.', 'payfast_give' ),
			'type' => 'text',
			'size' => 'regular',
		),
		array(
			'id'	  => 'payfast_debug_log',
			'name'	=> __( 'Debug to log server-to-server communication:', 'payfast_give' ),
			'desc'	=> __( 'Enable Debug to log the server-to-server communication.', 'payfast_give' ),
			'type'	=> 'radio', // Change type to 'radio'
			'options' => array( 'yes' => __( 'Enable', 'payfast_give' ), 'no'  => __( 'Disable', 'payfast_give' ) ),
			'default' => 'no', // Set default option to 'no' (disabled)
		),
	);

	return array_merge( $settings, $payfast_settings );
}
add_filter( 'give_settings_gateways', 'payfast_add_settings' );
