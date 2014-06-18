<?php
/*
Plugin Name: Easy Digital Downloads - Pagar.me Gateway (Boleto)
Plugin URL: http://easydigitaldownloads.com/extension/pagarme-gateway
Description: Pagar.me gateway for Easy Digital Downloads (Boleto)
Version: 1.0
Author: Fabiano Arruda
Author URI: http://twitter.com/fabianoarruda
Contributors:
*/

// Don't forget to load the text domain here. Sample text domain is pw_edd

session_start();

if(!class_exists('PagarMe'))
require("pagarme-php/Pagarme.php");

// registers the gateway
function register_pagarme_boleto_gateway( $gateways ) {
	$gateways['pagarme_boleto_gateway'] = array( 'admin_label' => 'Pagar.me Gateway (Boleto)', 'checkout_label' => 'Boleto' );
	return $gateways;
}
add_filter( 'edd_payment_gateways', 'register_pagarme_boleto_gateway' );


// Remove this if you want a credit card form
add_action( 'edd_pagarme_boleto_gateway_cc_form', '__return_false' );


// processes the payment
function process_boleto_payment( $purchase_data ) {

	global $edd_options;

	/**********************************
	* set transaction mode
	**********************************/

	if ( edd_is_test_mode() ) {
		// set test credentials here
		Pagarme::setApiKey($edd_options["test_api_key"]);
	} else {
		// set live credentials here
		Pagarme::setApiKey($edd_options["live_api_key"]);
	}

	/**********************************
	* check for errors here
	**********************************/


	/*/ errors can be set like this
	if( ! isset($_POST['card_number'] ) ) {
		// error code followed by error message
		edd_set_error('empty_card', __('You must enter a card number', 'edd'));
	}*/



	/**********************************
	* Purchase data comes in like this:

    $purchase_data = array(
        'downloads'     => array of download IDs,
        'tax' 			=> taxed amount on shopping cart
        'fees' 			=> array of arbitrary cart fees
        'discount' 		=> discounted amount, if any
        'subtotal'		=> total price before tax
        'price'         => total price of cart contents after taxes,
        'purchase_key'  =>  // Random key
        'user_email'    => $user_email,
        'date'          => date( 'Y-m-d H:i:s' ),
        'user_id'       => $user_id,
        'post_data'     => $_POST,
        'user_info'     => array of user's information and used discount code
        'cart_details'  => array of cart details,
     );
    */

	// check for any stored errors
	$errors = edd_get_errors();
	if ( ! $errors ) {

		$purchase_summary = edd_get_purchase_summary( $purchase_data );

		/****************************************
		* setup the payment details to be stored
		****************************************/

		$payment = array(
			'price'        => $purchase_data['price'],
			'date'         => $purchase_data['date'],
			'user_email'   => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'currency'     => $edd_options['currency'],
			'downloads'    => $purchase_data['downloads'],
			'cart_details' => $purchase_data['cart_details'],
			'user_info'    => $purchase_data['user_info'],
			'status'       => 'pending'
		);

		// record the pending payment
		$payment = edd_insert_payment( $payment );

		$merchant_payment_confirmed = false;

		/**********************************
		* Process the credit card here.
		* If not using a credit card
		* then redirect to merchant
		* and verify payment with an IPN
		**********************************/

		//die(print_r($purchase_data['purchase_key'], true ));

		$customer = array(
			"email" => $purchase_data['user_info']['email'],
			"name" => $purchase_data['user_info']['first_name'] . " " . $purchase_data['user_info']['last_name']
		);

		$metadata = array(
			"campain_id" => $purchase_data['cart_details'][0]['id'],
			"campain_name" => $purchase_data['cart_details'][0]['name'],
			"edd_purchase_key" => $purchase_data['purchase_key']

		);

		$transaction = new PagarMe_Transaction(array(
		    "amount" => number_format( $purchase_data['price'], 2, '', '' ), // amount in cents - 1000 = R$ 10,00
		    "payment_method" => "boleto", // payment mode
		    "postback_url" => get_site_url() . "/?edd-listener=pagarme_boleto", // This additional parameter tells Wordpress which plugin should receive the postback to be processed.
				"customer" => $customer,
				"metadata" => $metadata
		));




		$transaction->charge();

		$_SESSION['boleto_url'] = $transaction->getBoletoUrl();



		// once a transaction is successful, set the purchase to complete
		edd_update_payment_status( $payment, 'pending' );

		// record transaction ID, or any other notes you need
		edd_insert_payment_note( $payment, ' ID Transação Pagar.me: ' . $transaction[id] );
		edd_insert_payment_note( $payment, ' URL do Boleto: ' . $transaction->getBoletoUrl() );


		// go to the success page
		edd_send_to_success_page();



	} else {
		$fail = true; // errors were detected
	}

	if ( $fail !== false ) {
		// if errors are present, send the user back to the purchase page so they can be corrected
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	}
}
add_action( 'edd_gateway_pagarme_boleto_gateway', 'process_boleto_payment' );


/**
 * Listens for Pagar.me postback requests and then sends to the processing function
 *
 * @since 1.0
 * @global $edd_options Array of all the EDD Options
 * @return void
 */
function edd_listen_for_pagarme_boleto_postback() {
	global $edd_options;

	// Pagar.me postback is captured here
	if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'pagarme_boleto' ) {
		do_action( 'edd_verify_pagarme_boleto_postback' );
	}
}
add_action( 'init', 'edd_listen_for_pagarme_boleto_postback' );


/**
 * Process Pagar.me postback
 *
 * @since 1.0
 * @global $edd_options Array of all the EDD Options
 * @return void
 */
function edd_process_pagarme_boleto_postback() {
	global $edd_options;

	if ( edd_is_test_mode() ) {
		// set test credentials here
		Pagarme::setApiKey($edd_options["test_api_key"]);
	} else {
		// set live credentials here
		Pagarme::setApiKey($edd_options["live_api_key"]);
	}

	//Check the request method is POST
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] != 'POST' ) {
		return;
	}



	$id = $_POST['id'];
	$current_status = $_POST['current_status'];
	$old_status = $_POST['old_status'];
	$fingerprint = $_POST['fingerprint'];

	if(PagarMe::validateFingerprint($id, $fingerprint)) {
		if($current_status == 'paid') {
			//Pago...
			$transaction = PagarMe_Transaction::findById($id);
			$edd_purchase = edd_get_purchase_id_by_key($transaction['metadata']['edd_purchase_key']);
			edd_update_payment_status( $edd_purchase, 'publish' );



		} else {
			// Não funcionou...
			return;
		}
	}


}


add_action( 'edd_verify_pagarme_boleto_postback', 'edd_process_pagarme_boleto_postback' );


// adds the settings to the Payment Gateways section
function pagarme_boleto_add_settings( $settings ) {

	$sample_gateway_settings = array(
		array(
			'id' => 'pagarme_card_gateway_settings',
			'name' => '<strong>' . __( 'Configurções Pagar.me (Cartão)', 'pw_edd' ) . '</strong>',
			'desc' => __( 'Configurações do Gateway do Pagar.me', 'pw_edd' ),
			'type' => 'header'
		),
		array(
			'id' => 'live_api_key',
			'name' => __( 'Live API Key', 'pw_edd' ),
			'desc' => __( 'Adicione a live API key, que pode ser encontrada nas configurações do seu dashboard Pagar.me', 'pw_edd' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'test_api_key',
			'name' => __( 'Test API Key', 'pw_edd' ),
			'desc' => __( 'Adicione a test API key, que pode ser encontrada nas configurações do seu dashboard Pagar.me', 'pw_edd' ),
			'type' => 'text',
			'size' => 'regular'
		)
	);

	return array_merge( $settings, $sample_gateway_settings );
}
add_filter( 'edd_settings_gateways', 'pagarme_boleto_add_settings' );
