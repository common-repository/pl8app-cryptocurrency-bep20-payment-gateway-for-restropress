<?php

function pl8app_rpr_change_cancelled_email_note_subject_line($subject, $payment_id) {
	$subject = 'Order ' . $payment_id . ' has been cancelled due to non-payment';

	return $subject;

}

function pl8app_rpr_change_cancelled_email_heading($heading, $payment_id, $order) {
	$heading = "Your order has been cancelled. Do not send any cryptocurrency to the payment address.";

	return $heading;
}

function pl8app_rpr_change_partial_email_note_subject_line($subject, $payment_id) {
	$subject = 'Partial payment received for Order ' . $payment_id;

	return $subject;
}

function pl8app_rpr_change_partial_email_heading($heading, $payment_id, $order) {
	$heading = 'Partial payment received for Order ' . $payment_id;

	return $heading;
}


function pl8app_rpr_update_database_when_admin_changes_order_status($payment_id, $new_status, $old_status) {

	$paymentAmount = 0.0;

	$paymentAmount = rpress_payment_amount($payment_id);

	// this order was not made by us
	if ($paymentAmount === 0.0 || !$paymentAmount) {

		return;
  }


	$paymentRepo = new pl8app_rpr_Payment_Repo();

	// If admin updates from needs-payment to has-payment, stop looking for matching transactions
	if ($old_status === 'pending' && $new_status === 'processing') {
		$paymentRepo->set_status($payment_id, $paymentAmount, 'paid');
	}
	if ($old_status === 'pending' && $new_status === 'completed') {
		$paymentRepo->set_status($payment_id, $paymentAmount, 'paid');
	}

	// If admin updates from has-payment to needs-payment, start looking for matching transactions
	if ($old_status === 'processing' && $new_status === 'pending') {
		$paymentRepo->set_status($payment_id, $paymentAmount, 'unpaid');
	}
	if ($old_status === 'completed' && $new_status === 'pending') {
		$paymentRepo->set_status($payment_id, $paymentAmount, 'unpaid');
	}

	// If admin updates from needs-payment to cancelled, stop looking for matching transactions
	if ($old_status === 'pending' && $new_status === 'cancelled') {
		$paymentRepo->set_status($payment_id, $paymentAmount, 'cancelled');
	}
	if ($old_status === 'pending' && $new_status === 'failed') {
		$paymentRepo->set_status($payment_id, $paymentAmount, 'cancelled');
	}

	// If admin updates from cancelled to needs-payment, start looking for matching transactions
	if ($old_status === 'cancelled' && $new_status === 'pending') {
		$paymentRepo->set_status($payment_id, $paymentAmount, 'unpaid');
		$paymentRepo->set_ordered_at($payment_id, $paymentAmount, time());
	}
	if ($old_status === 'failed' && $new_status === 'pending') {
		$paymentRepo->set_status($payment_id, $paymentAmount, 'unpaid');
		$paymentRepo->set_ordered_at($payment_id, $paymentAmount, time());
	}
}

function pl8app_rpr_add_flash_notice($notice = "", $type = "error", $dismissible = true) {
    // Here we return the notices saved on our option, if there are not notices, then an empty array is returned
    $notices = get_option( "my_flash_notices", array() );

    $dismissible_text = ( $dismissible ) ? "is-dismissible" : "";

    // We add our new notice.
    array_push( $notices, array(
            "notice" => $notice,
            "type" => $type,
            "dismissible" => $dismissible_text
        ) );

    // Then we update the option with our notices array
    update_option("my_flash_notices", $notices );
}

/**
 * Function executed when the 'admin_notices' action is called, here we check if there are notices on
 * our database and display them, after that, we remove the option to prevent notices being displayed forever.
 * @return void
 */
function pl8app_rpr_display_flash_notices() {
    $notices = get_option( "my_flash_notices", array() );

    // Iterate through our notices to be displayed and print them.
    foreach ( $notices as $notice ) {
        printf('<div class="notice notice-%1$s %2$s"><p>%3$s</p></div>',
            esc_attr($notice['type']),
            esc_attr($notice['dismissible']),
            esc_attr($notice['notice'])
        );
    }

    // Now we reset our options to prevent notices being displayed forever.
    if( ! empty( $notices ) ) {
        delete_option( "my_flash_notices", array() );
    }

}

function pl8app_rpr_load_redux_css($stuff) {

    $cssPath = pl8app_rpr_PLUGIN_DIR . '/assets/css/pl8app-rpr-redux-settings.css';
    wp_enqueue_style('pl8app-rpr-styles', $cssPath, array(), pl8app_rpr_VERSION);

}

function pl8app_rpr_load_js_css($stuff) {
    $cssPath = pl8app_rpr_PLUGIN_DIR . '/assets/css/pl8app-rpr-custom-admin.css';
    wp_enqueue_style('pl8app-rpr-icon-styles', $cssPath, array(), pl8app_rpr_VERSION);
	if (!is_array($_GET)) {
		return;
	}

	if (!array_key_exists('page', $_GET)) {
		return;
	}

	$page = sanitize_text_field(trim($_GET['page']));

	if ($page === 'pl8app_rpr_pro_options') {
		$jsPath = pl8app_rpr_PLUGIN_DIR . '/assets/js/pl8app-rpr-redux-mpk.js';

		if (pl8app_rpr_Util::p_enabled()) {
			wp_enqueue_script('pl8app-rpr-scripts', $jsPath, array( 'jquery', 'pl8app-rpr-admin-scripts' ), pl8app_rpr_VERSION);
        }
        else {
        	wp_enqueue_script('pl8app-rpr-scripts', $jsPath, array( 'jquery' ), pl8app_rpr_VERSION);
        }
	}

	if($page === 'pl8app_rpr_crypto_payment_settings'){
        $cssPath = pl8app_rpr_PLUGIN_DIR . '/assets/css/pl8app-rpr-crypto-admin.css';
        wp_enqueue_style('pl8app-rpr-styles', $cssPath, array(), pl8app_rpr_VERSION);

        $jsPath = pl8app_rpr_PLUGIN_DIR . '/assets/js/pl8app-rpr-crypto-admin.js';
        wp_enqueue_script('pl8app-rpr-scripts', $jsPath, array( 'jquery' ), pl8app_rpr_VERSION);

    }

}

function pl8app_rpr_first_mpk_address_ajax() {

		if (!isset($_POST) || !is_array($_POST) || !array_key_exists('mpk', $_POST) || !array_key_exists('cryptoId', $_POST)) {
			return;
		}

		$mpk = sanitize_text_field($_POST['mpk']);
		$cryptoId = sanitize_text_field($_POST['cryptoId']);
		$hdMode = sanitize_text_field($_POST['hdMode']);

		if (!pl8app_rpr_Hd::is_valid_mpk($cryptoId, $mpk)) {
			return;
		}

		if (!pl8app_rpr_Util::p_enabled() && (pl8app_rpr_Hd::is_valid_ypub($mpk) || pl8app_rpr_Hd::is_valid_zpub($mpk))) {
			$message = 'You have entered a valid Segwit MPK.';
			$message2 = '<a href="https://nomiddlemancrypto.io/extensions/segwit" target="_blank">Segwit MPKs are coming soon!</a>';

			echo esc_html(json_encode([$message, $message2, '']));
			wp_die();
		}
		else {
			$firstAddress = pl8app_rpr_Hd::create_hd_address($cryptoId, $mpk, 0, $hdMode);
			$secondAddress = pl8app_rpr_Hd::create_hd_address($cryptoId, $mpk, 1, $hdMode);
			$thirdAddress = pl8app_rpr_Hd::create_hd_address($cryptoId, $mpk, 2, $hdMode);

		 	wp_send_json_success([$firstAddress, $secondAddress, $thirdAddress]);
		}
}

function pl8app_rpr_filter_gateways($gateways){
	$pl8appGateway = array(
		'admin_label'    => __( 'pl8app BEP20 Cryptocurrency Gateway', 'pl8app-cryptocurrency-bep20-payment-gateway-for-restropress' ),
		'checkout_label' => __( 'pl8app BEP20 Cryptocurrency Gateway', 'pl8app-cryptocurrency-bep20-payment-gateway-for-restropress' ),
		'supports'       => array( 'buy_now' )
	);

    $pl8appRprSettings = new pl8app_rpr_Settings(get_option(pl8app_rpr_REDUX_ID));

    foreach (pl8app_rpr_Cryptocurrencies::get() as $crypto) {
        if ($pl8appRprSettings->crypto_selected_and_valid($crypto->get_id())) {
        	$gateways['pl8app'] = $pl8appGateway;
            return $gateways;
        }
    }


    if (rpress_is_checkout()) {
	    unset($gateways['pl8app']);
	}
	else {
		$gateways['pl8app'] = $pl8appGateway;
	}

    return $gateways;
}

/**
 * Show row meta on the plugin screen.
 *
 * @param mixed $links Plugin Row Meta.
 * @param mixed $file  Plugin Base file.
 *
 * @return array
 */

function PCBPGFW_plugin_row_meta( $links, $file){

    if ( 'pl8app-cryptocurrency-bep20-payment-gateway-for-restropress/pl8app-cryptocurrency-bep20-payment-gateway-for-restropress.php' !== $file ) {
        return $links;
    }

    $row_meta = array(
        'support' => '<a
            href="' . esc_url( 'https://token.pl8app.co.uk' ) . '"
            target="_blank"
            aria-label="' . esc_attr__( 'Visit pl8app support', 'pl8app-cryptocurrency-bep20-payment-gateway-for-restropress' ) . '"
        >' . esc_html__( 'Support', 'pl8app-cryptocurrency-bep20-payment-gateway-for-restropress' ) . '</a>',
    );

    return array_merge( $links, $row_meta );
}

add_filter( 'plugin_row_meta',  'PCBPGFW_plugin_row_meta' , 10, 2 );

function pl8app_rpr_trigger_purchase_cancel($payment_id) {
	$payment = new RPRESS_Payment($payment_id);

	add_filter('rpress_purchase_subject', 'pl8app_rpr_change_cancelled_email_note_subject_line', 1, 2);
	add_filter('rpress_purchase_heading', 'pl8app_rpr_change_cancelled_email_heading', 1, 3);

	rpress_email_purchase_receipt($payment_id, false, '', $payment);
}

add_action('pl8app_rpr_trigger_purchase_cancel', 'pl8app_rpr_trigger_purchase_cancel', NULL, 1);


?>
