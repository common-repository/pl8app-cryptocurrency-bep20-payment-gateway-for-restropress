<?php

class pl8app_rpr_Gateway {
    private $cryptos;
    private $gapLimit;

    public $gateway_id = 'pl8app';

    public function __construct() {


        $cryptoArray = pl8app_rpr_Cryptocurrencies::get();

        $pl8appRprSettings = new pl8app_rpr_Settings(get_option(pl8app_rpr_REDUX_ID));

        $this->cryptos = $cryptoArray;
        $this->gapLimit = 2;

        $this->id = 'pl8app_rpr_pro_gateway';
        $this->title = $pl8appRprSettings->get_customer_gateway_message();
        $this->has_fields = true;
        $this->method_title = 'pl8app Crypto Payments';
        $this->method_description = 'Allow customers to pay using cryptocurrency';

        add_filter('rpress_purchase_receipt', array($this, 'additional_email_details'), NULL, 3);

        add_action('wp_enqueue_scripts', array($this, 'thank_you_register_script'));

        add_action('rpress_pl8app_cc_form', array($this, 'payment_fields'));
        add_action('rpress_gateway_pl8app', array($this, 'process_payment'));
        add_action('rpress_payment_receipt_after_table', array($this, 'thank_you_page'));
        // Emails are fired after we process the purchase, so hook additional email details here
        add_action('pl8app_rpr_trigger_purchase_receipt', array($this, 'trigger_purchase_receipt'), NULL, 1);
    }

    // This runs when the user hits the checkout page
    // We load our crypto select with valid crypto currencies
    public function payment_fields() {
        ob_start();

        do_action( 'rpress_before_cc_fields' ); ?>

        <fieldset id="rpress_cc_fields" class="rpress-do-validate">
            <legend><?php echo 'Pay with BEP20 cryptocurrency'; ?></legend>
            <?php if( is_ssl() ) : ?>
                <div id="rpress_secure_site_wrapper">
                    <span class="padlock">
                        <svg class="rpress-icon rpress-icon-lock" xmlns="http://www.w3.org/2000/svg" width="18" height="28" viewBox="0 0 18 28" aria-hidden="true">
                            <path d="M5 12h8V9c0-2.203-1.797-4-4-4S5 6.797 5 9v3zm13 1.5v9c0 .828-.672 1.5-1.5 1.5h-15C.672 24 0 23.328 0 22.5v-9c0-.828.672-1.5 1.5-1.5H2V9c0-3.844 3.156-7 7-7s7 3.156 7 7v3h.5c.828 0 1.5.672 1.5 1.5z"/>
                        </svg>
                    </span>
                    <span><?php echo 'This is a secure SSL encrypted payment.'; ?></span>
                </div>
            <?php endif; ?>
            <p id="pl8app-rpress-cryptocurrency-wrap">
                <label for="pl8app_rpr_currency_id" class="rpress-label">
                    <?php echo 'Choose a cryptocurrency'; ?>
                    <span class="rpress-required-indicator">*</span>
                    <span class="card-type"></span>
                </label>
                <select name="pl8app_rpr_currency_id" id="pl8app_rpr_currency_id" data-nonce="<?php echo wp_create_nonce( 'rpress-pl8app-currency-id-field-nonce' ); ?>" class="pl8app_rpr_currency_id rpress-select<?php if( rpress_field_is_required( 'pl8app_rpr_currency_id' ) ) { echo ' required'; } ?>"<?php if( rpress_field_is_required( 'pl8app_rpr_currency_id' ) ) {  echo ' required '; } ?>>
                    <?php

                    $pl8appRprSettings = new pl8app_rpr_Settings(get_option(pl8app_rpr_REDUX_ID));

                    $validCryptos = $pl8appRprSettings->get_valid_selected_cryptos();

                    foreach ($validCryptos as $crypto) {
                        $cryptoId = $crypto->get_id();

                        if ($pl8appRprSettings->hd_enabled($cryptoId)) {

                            $mpk = $pl8appRprSettings->get_mpk($cryptoId);
                            $hdMode = $pl8appRprSettings->get_hd_mode($cryptoId);
                            $hdRepo = new pl8app_rpr_Hd_Repo($cryptoId, $mpk, $hdMode);

                            $count = $hdRepo->count_ready();

                            if ($count < 1) {
                                try {
                                    pl8app_rpr_Hd::force_new_address($cryptoId, $mpk, $hdMode);
                                }
                                catch ( \Exception $e) {
                                    pl8app_rpr_Util::log(__FILE__, __LINE__, 'UNABLE TO GENERATE HD ADDRESS FOR ' . $crypto->get_name() . ' ADMIN MUST BE NOTIFIED. REMOVING CRYPTO FROM PAYMENT OPTIONS' . $e->getTraceAsString());
                                    unset($validCryptos[$cryptoId]);
                                }
                            }
                        }
                    }

                    $selectOptions = $this->get_select_options_for_valid_cryptos($validCryptos);
                    $selectedCrypto = array_key_first($selectOptions);

                    foreach( $selectOptions as $value => $label ) {
                        echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $selectedCrypto, false ) . '>' . esc_html($label) . '</option>';
                    }

                    ?>
                </select>
            </p>
            <?php do_action( 'rpress_after_cc_expiration' ); ?>
        </fieldset>

        <?php
            do_action( 'rpress_after_cc_fields' );

            echo ob_get_clean();
    }



    // This is called when the user clicks Place Order, after validate_fields
    public function process_payment($purchase_data) {
        // handle different RestroPress currencies and get the order total in USD
        $curr = rpress_get_currency();

        $payment_data = array(
            'price'         => $purchase_data['price'],
            'date'          => $purchase_data['date'],
            'user_email'    => $purchase_data['user_email'],
            'purchase_key'  => $purchase_data['purchase_key'],
            'currency'      => $curr,
            'fooditems'     => $purchase_data['fooditems'],
            'user_info'     => $purchase_data['user_info'],
            'cart_details'  => $purchase_data['cart_details'],
            'gateway'       => $this->gateway_id,
            'status'        => 'pending',
        );

        $payment_id = rpress_insert_payment( $payment_data );

        $pl8appRprSettings = new pl8app_rpr_Settings(get_option(pl8app_rpr_REDUX_ID));

        $selectedCryptoId = sanitize_text_field($purchase_data['post_data']['pl8app_rpr_currency_id']);
        RPRESS()->session->set('chosen_crypto_id', $selectedCryptoId);
        $crypto = $this->cryptos[$selectedCryptoId];
        $cryptoId = $crypto->get_id();

        rpress_update_payment_meta($payment_id, '_rpress_pl8app_crypto_type_id', $cryptoId);

        $cryptoMarkupPercent = $pl8appRprSettings->get_markup($cryptoId);

        if (!is_numeric($cryptoMarkupPercent)) {
            $cryptoMarkupPercent = 0.0;
        }

        // get current price of crypto

        $bnb_flat_price = pl8app_rpr_Exchange::get_bnb_flat_price($curr, 60);

        $pl8app_bnb_rate = pl8app_rpr_Exchange::get_pl8app_bnb_price($cryptoId, $crypto->get_update_interval());

        $pl8app_cryptoTotal = (float) $purchase_data['price'] / $bnb_flat_price / $pl8app_bnb_rate;

        $cryptoMarkup = $cryptoMarkupPercent / 100.0;
        $cryptoPriceRatio = 1.0 + $cryptoMarkup;
        $cryptoTotalPreMarkup = round($pl8app_cryptoTotal, $crypto->get_round_precision(), PHP_ROUND_HALF_UP);
        $cryptoTotal = $cryptoTotalPreMarkup * $cryptoPriceRatio;


        //error_log('cryptoTotal post-dust: ' . $cryptoTotal);

        // format the crypto amount based on crypto
        $formattedCryptoTotal = pl8app_rpr_Cryptocurrencies::get_price_string($cryptoId, $cryptoTotal);

        rpress_update_payment_meta($payment_id, '_rpress_pl8app_crypto_amount', $formattedCryptoTotal);

        pl8app_rpr_Util::log(__FILE__, __LINE__, 'Crypto total: ' . $cryptoTotal . ' Formatted Total: ' . $formattedCryptoTotal);

        // if hd is enabled we have stuff to do
        if ($pl8appRprSettings->hd_enabled($cryptoId)) {
            $mpk = $pl8appRprSettings->get_mpk($cryptoId);
            $hdMode = $pl8appRprSettings->get_hd_mode($cryptoId);
            $hdRepo = new pl8app_rpr_Hd_Repo($cryptoId, $mpk, $hdMode);

            // get fresh hd wallet
            $orderWalletAddress = $hdRepo->get_oldest_ready();

            // if we couldnt find a fresh one, force a new one
            if (!$orderWalletAddress) {

                try {
                    pl8app_rpr_Hd::force_new_address($cryptoId, $mpk, $hdMode);
                    $orderWalletAddress = $hdRepo->get_oldest_ready();
                }
                catch ( \Exception $e) {
                    throw new \Exception('Unable to get payment address for order. This order has been cancelled. Please try again or contact the site administrator... Inner Exception: ' . $e->getMessage());
                }

            }

            // set hd wallet address to get later
            RPRESS()->session->set('hd_wallet_address', $orderWalletAddress);

            // update the database
            $hdRepo->set_status($orderWalletAddress, 'assigned');
            $hdRepo->set_order_id($orderWalletAddress, $payment_id);
            $hdRepo->set_order_amount($orderWalletAddress, $formattedCryptoTotal);

            $orderNote = sprintf(
                'Privacy Mode (HD wallet) address %s is awaiting payment of %s %s.',
                $orderWalletAddress,
                $formattedCryptoTotal,
                $cryptoId);

        }
        // HD is not enabled, just handle static wallet or carousel mode
        else {

            $orderWalletAddress = $pl8appRprSettings->get_next_carousel_address($cryptoId);

            // handle payment verification feature
            if ($pl8appRprSettings->autopay_enabled($cryptoId)) {
                $paymentRepo = new pl8app_rpr_Payment_Repo();

                $paymentRepo->insert($orderWalletAddress, $cryptoId, $payment_id, $formattedCryptoTotal, 'unpaid');
            }

            $orderNote = sprintf(
                'Awaiting payment of %s %s to payment address %s.',
                $formattedCryptoTotal,
                $cryptoId,
                $orderWalletAddress);
        }

        // For email
        RPRESS()->session->set($cryptoId . '_amount', $formattedCryptoTotal);

        // For customer reference and to handle refresh of thank you page
        rpress_update_payment_meta($payment_id, '_rpress_pl8app_wallet_address', $orderWalletAddress);

        do_action('pl8app_rpr_trigger_purchase_receipt', $payment_id);

        rpress_send_to_success_page();
    }

    public function thank_you_register_script() {
        wp_register_script(
            'pl8app-rpr-thank-you-page',
            pl8app_rpr_PLUGIN_DIR . '/assets/js/pl8app-rpr-thank-you-page.js',
            ['jquery'],
            NULL,
            true
        );

        wp_register_script(
            'web3',
            pl8app_rpr_PLUGIN_DIR . '/assets/js/web3.min.js',
            NULL,
            NULL,
            true
        );

        wp_register_script(
            'bnblib',
            pl8app_rpr_PLUGIN_DIR . '/assets/js/bnblib.js',
            ['jquery', 'jquery-ui-dialog', 'web3'],
            NULL,
            true
        );

        wp_register_script(
            'pl8app-rpr-pay-with-binance',
            pl8app_rpr_PLUGIN_DIR . '/assets/js/pl8app-rpr-pay-with-binance.js',
            ['bnblib'],
            NULL,
            true
        );

        wp_register_script(
            'pl8app-rpr-pay-with-metamask',
            pl8app_rpr_PLUGIN_DIR . '/assets/js/pl8app-rpr-pay-with-metamask.js',
            ['bnblib'],
            NULL,
            true
        );
    }

    // This is called after process payment, when the customer places the order
    public function thank_you_page() {
        global $rpress_receipt_args;

        $cssPath = pl8app_rpr_PLUGIN_DIR . '/assets/css/pl8app-rpr-thank-you-page.css';
        $fontcssPath = pl8app_rpr_PLUGIN_DIR . '/assets/css/all.min.css';
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_style('pl8app-rpr-styles', $cssPath);
        wp_enqueue_style('pl8app-rpr-fontawesome-styles', $fontcssPath);

        wp_enqueue_script('pl8app-rpr-thank-you-page');
        wp_enqueue_script('pl8app-rpr-pay-with-binance');
        wp_enqueue_script('pl8app-rpr-pay-with-metamask');

        $payment = get_post($rpress_receipt_args['id']);

        $cryptoId = rpress_get_payment_meta($payment->ID, '_rpress_pl8app_crypto_type_id', true);
        $crypto = $this->cryptos[$cryptoId];

        $orderWalletAddress = rpress_get_payment_meta($payment->ID, '_rpress_pl8app_wallet_address', true);

        $formattedCryptoTotal = rpress_get_payment_meta($payment->ID, '_rpress_pl8app_crypto_amount', true);

        try {
            // Output additional thank you page html
            $this->output_thank_you_html($crypto, $orderWalletAddress, $formattedCryptoTotal, $payment->ID);
        } catch ( \Exception $e ) {
            // cancel order if something went wrong
            rpress_update_payment_status($payment->ID, 'failed');
            rpress_insert_payment_note($payment->ID, $e->getMessage());

            pl8app_rpr_Util::log(__FILE__, __LINE__, 'Something went wrong during checkout: ' . $e->getMessage());

            echo '<div>';
            echo '<ul>';
            echo '<li>';
            echo 'Something went wrong.<br>';
            echo esc_html($e->getMessage());
            echo '</li>';
            echo '</ul>';
            echo '</div>';

        }
    }

    public function additional_email_details($email_body, $payment_id, $payment_data) {
        $payment = get_post($payment_id);

        if ('abandoned' === rpress_get_payment_status($payment_id)) {
            ob_start(); ?>
            <p>Dear {name},</p>

            <p>Your recent purchase on {sitename} has been cancelled automatically, due to the payment window expiring</p>

            <p>Feel free to resubmit your order</p>
            <?php

            return ob_get_clean();
        } else if ('pending' === rpress_get_payment_status($payment_id)) {
            $chosenCrypto = RPRESS()->session->get('chosen_crypto_id');
            $crypto =  $this->cryptos[$chosenCrypto];
            $orderCryptoTotal = RPRESS()->session->get($crypto->get_id() . '_amount');
            $orderWalletAddress = rpress_get_payment_meta($payment->ID, '_rpress_pl8app_wallet_address', true);
            $contract_address = pl8app_rpr_Cryptocurrencies::get_erc20_contract($crypto->get_id());
            $qrCode = $this->get_qr_code($crypto, $orderWalletAddress, $orderCryptoTotal);

            ob_start(); ?>
            <p>Dear {name},</p>

            <p>Thank you for your order. Use the QR Code below to make payment.</p>

            <h2>Additional Details</h2>
            <p>QR Code Payment: </p>
            <div style="margin-bottom:12px;">
                <img  src=<?php echo esc_attr($qrCode); ?> />
            </div>
            <p>
                Send Payment to this Wallet Address: <?php echo esc_html($orderWalletAddress); ?>
            </p>
            <p>
                <?php echo esc_html($crypto->get_name());?> Token Address: <?php echo esc_html($contract_address); ?>
            </p>
            <p>
                Currency: <?php echo '<img src="' . esc_attr($crypto->get_logo_file_path()) . '" alt="" />' . esc_html($crypto->get_name()); ?>
            </p>
            <p>
                Total:
                <?php
                    if ($crypto->get_symbol() === '') {
                        echo esc_html(pl8app_rpr_Cryptocurrencies::get_price_string($crypto->get_id(), $orderCryptoTotal) . ' ' . $crypto->get_id());
                    }
                    else {
                        echo esc_html($crypto->get_symbol() . pl8app_rpr_Cryptocurrencies::get_price_string($crypto->get_id(), $orderCryptoTotal));
                    }
                ?>
            </p>

            <p>{sitename}</p>
            <?php

            return ob_get_clean();
        } else {
            return $email_body;
        }
    }

    // convert array of cryptos to option array
    private function get_select_options_for_valid_cryptos() {
        $selectOptionArray = array();

        $pl8appRprSettings = new pl8app_rpr_Settings(get_option(pl8app_rpr_REDUX_ID));

        foreach (pl8app_rpr_Cryptocurrencies::get_alpha() as $crypto) {

            if ($pl8appRprSettings->crypto_selected_and_valid($crypto->get_id())) {
                $selectOptionArray[$crypto->get_id()] = $crypto->get_name();
            }
        }

        return $selectOptionArray;
    }

    private function get_qr_prefix($crypto) {
        return strtolower(str_replace(' ', '', $crypto->get_name()));
    }

    private function get_qr_code($crypto, $walletAddress, $cryptoTotal) {
        $dirWrite = pl8app_rpr_ABS_PATH . '/assets/img/';

        $formattedName = $this->get_qr_prefix($crypto);

        $qrData = $formattedName . ':' . $walletAddress . '?amount=' . $cryptoTotal;

        try {
            QRcode::png($qrData, $dirWrite . 'tmp_qrcode.png', QR_ECLEVEL_H);
        }
        catch (\Exception $e) {
            pl8app_rpr_Util::log(__FILE__, __LINE__, 'QR code generation failed, falling back...');
            $endpoint = 'https://api.qrserver.com/v1/create-qr-code/?data=';
            return $endpoint . $qrData;
        }
        $dirRead = pl8app_rpr_PLUGIN_DIR . '/assets/img/';
        return $dirRead . 'tmp_qrcode.png';
    }

    private function output_thank_you_html($crypto, $orderWalletAddress, $cryptoTotal, $orderId) {
        $formattedPrice = pl8app_rpr_Cryptocurrencies::get_price_string($crypto->get_id(), $cryptoTotal);
        $pl8appRprSettings = new pl8app_rpr_Settings(get_option(pl8app_rpr_REDUX_ID));

        $customerMessage = apply_filters('pl8app_rpr_customer_message', $pl8appRprSettings->get_customer_payment_message($crypto), $crypto, $orderId, $formattedPrice, $orderWalletAddress);
        $contract_address = pl8app_rpr_Cryptocurrencies::get_erc20_contract($crypto->get_id());
        $qrCode = $this->get_qr_code($crypto, $orderWalletAddress, $cryptoTotal);

        echo esc_html($customerMessage);
        ?>

        <h2>Cryptocurrency payment details</h2>
        <ul class="order_details">
            <li>
                <p style="word-wrap: break-word;">QR Code payment:</p>
                <div class="qr-code-container">
                    <img style="margin-top:3px;" src=<?php echo esc_attr($qrCode); ?> />
                </div>
                <div class="Pay-metamask-button hidden"></div>
                <div class="Pay-binance-button hidden">
                    <img src="<?php print(pl8app_rpr_PLUGIN_DIR . '/assets/img/bsc-icon-logo-1-1.svg'); ?>" />
                    <p>Pay With Binance</p>
                </div>
            </li>
            <li>
                <p style="word-wrap: break-word;">Send Payment to this Wallet Address:
                    <strong>
                        <span class="amount">
                            <?php echo '<span class="all-copy ">' . esc_html($orderWalletAddress) . ' <span class="storewalletaddress clipboard far fa-copy" title="Copy to clipboard" data-value="' . esc_html($orderWalletAddress) . ' "></span></span>' ?>
                        </span>
                    </strong>
                </p>
                <p>Currency:
                    <strong>
                        <?php
                        echo '<img style="display:inline;height:23px;width:23px;vertical-align:middle;" src="' . esc_attr($crypto->get_logo_file_path()) . '" />';
                        ?>
                        <span style="padding-left: 4px; vertical-align: middle;" class="amount" style="vertical-align: middle;">
                            <?php echo esc_html($crypto->get_name()); ?>
                        </span>
                    </strong>
                </p>

                <p style="word-wrap: break-word;">Total:
                    <strong>
                        <span class="amount">
                            <?php
                            if ($crypto->get_symbol() === '') {
                                echo '<span class="all-copy">' . esc_html($formattedPrice) . '</span><span class="no-copy">&nbsp;' . esc_html($crypto->get_id()) . '</span> <span class="walletamount clipboard far fa-copy" title="Copy to clipboard" data-value="' . esc_html($formattedPrice) . '"></span>';
                            }
                            else {
                                echo '<span class="no-copy">' . esc_html($crypto->get_symbol()) . '</span>' . '<span class="all-copy">&nbsp;' . esc_html($formattedPrice) . ' <span class="walletamount clipboard far fa-copy" title="Copy to clipboard" data-value="' . esc_html($formattedPrice) . '"></span></span>';
                            }
                            ?>
                        </span>
                    </strong>
                </p>

                <p style="word-wrap: break-word;"><?php echo esc_html($crypto->get_name());?> Token Address:
                    <strong>
                        <span class="amount">
                            <?php echo '<span class="all-copy">' . esc_html($contract_address) . ' <span class="tokenaddress clipboard far fa-copy" title="Copy to clipboard" data-value="' . esc_html($contract_address) . ' "></span></span>' ?>
                        </span>
                    </strong>
                </p>
            </li>
        </ul>
        <div id="dialog" title="Pay with Binance Wallet">
            <div class="dialog-step dialog-step-1 hidden">
                <p>Please allow the Binance Wallet browser extension to connect.</p>
            </div>
            <div class="dialog-step dialog-step-2 hidden">
                <p>Please select the account you'd like to pay from:</p>
                <ul class="account-list"></ul>
                <input class="rpress-submit blue button disabled" type="submit" value="Next">
            </div>
            <div class="dialog-step dialog-step-3 hidden">
                <p>Please confirm the transaction using the Binance Wallet browser extension.</p>
            </div>
            <div class="dialog-step dialog-step-4 hidden">
                <p>Transaction is successful!</p>
                <p>Transaction Hash: <a class="transaction-hash" rel="noreferrer noopener" target="_blank" title="View in BscScan"></a><i class="fas fa-external-link-alt"></i></p>
                <input class="rpress-submit blue button" type="submit" value="Close">
            </div>
            <div class="dialog-step dialog-step-5 hidden">
                <p class="rpress-alert rpress-alert-error">The following error occurred during payment:</p>
                <p class="transaction-error"></p>
                <input class="rpress-submit blue button" type="submit" value="Close">
            </div>
        </div>
            <?php
    }

    private function handle_thank_you_refresh($chosenCrypto, $orderWalletAddress, $cryptoTotal, $orderId) {
        $this->output_thank_you_html($this->cryptos[$chosenCrypto], $orderWalletAddress, $cryptoTotal, $orderId);
    }

    // this function hits all the crypto exchange APIs that the user selected, then averages them and returns a conversion rate for USD
    // if the user has selected no exchanges to fetch data from it instead takes the average from all of them
    private function get_crypto_value_in_usd($cryptoId, $updateInterval) {

        $prices = array();
        $reduxSettings = get_option(pl8app_rpr_REDUX_ID);
        if (!array_key_exists('selected_price_apis', $reduxSettings)) {
            throw new \Exception('No price API selected. Please contact plug-in support.');
        }

        $selectedPriceApis = $reduxSettings['selected_price_apis'];

        if (in_array('0', $selectedPriceApis)) {
            $ccPrice = pl8app_rpr_Exchange::get_cryptocompare_price($cryptoId, $updateInterval);

            if ($ccPrice > 0) {
                $prices[] = $ccPrice;
            }
        }

        if (in_array('1', $selectedPriceApis)) {
            $hitbtcPrice = pl8app_rpr_Exchange::get_hitbtc_price($cryptoId, $updateInterval);

            if ($hitbtcPrice > 0) {
                $prices[] = $hitbtcPrice;
            }
        }

        if (in_array('2', $selectedPriceApis)) {
            $gateioPrice = pl8app_rpr_Exchange::get_gateio_price($cryptoId, $updateInterval);

            if ($gateioPrice > 0) {
                $prices[] = $gateioPrice;
            }
        }

        if (in_array('3', $selectedPriceApis)) {
            $bittrexPrice = pl8app_rpr_Exchange::get_bittrex_price($cryptoId, $updateInterval);

            if ($bittrexPrice > 0) {
                $prices[] = $bittrexPrice;
            }
        }

        if (in_array('4', $selectedPriceApis)) {
            $poloniexPrice = pl8app_rpr_Exchange::get_poloniex_price($cryptoId, $updateInterval);

            // if there were no trades do not use this pricing method
            if ($poloniexPrice > 0) {
                $prices[] = $poloniexPrice;
            }
        }

        $sum = 0;
        $count = count($prices);

        if ($count === 0) {
            throw new \Exception('No cryptocurrency exchanges could be reached, please try again.');
        }

        foreach ($prices as $price) {
            $sum += $price;
        }

        $average_price = $sum / $count;

        return $average_price;
    }

    public function trigger_purchase_receipt($payment_id) {
        $payment = new RPRESS_Payment($payment_id);

        rpress_email_purchase_receipt($payment_id, false, '', $payment);
    }
}

/**
 * Load pl8app_rpr_Gateway
 *
 * @return object pl8app_rpr_Gateway
 */
function pl8app_rpr() {
	return new pl8app_rpr_Gateway();
}

pl8app_rpr();
