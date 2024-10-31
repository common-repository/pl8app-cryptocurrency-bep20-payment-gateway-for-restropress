<?php

class pl8app_rpr_Payment {
	public static function check_all_addresses_for_matching_payment($transactionLifetime) {
		$paymentRepo = new pl8app_rpr_Payment_Repo();

		// get a unique list of unpaid "payments" to crypto addresses
		$addressesToCheck = $paymentRepo->get_distinct_unpaid_addresses();

		$cryptos = pl8app_rpr_Cryptocurrencies::get();

		foreach ($addressesToCheck as $record) {
			$address = $record['address'];

			$cryptoId = $record['cryptocurrency'];

			if(isset($cryptos[$cryptoId])) {
                self::check_address_transactions_for_matching_payments($cryptos[$cryptoId], $address, $transactionLifetime);
			}

		}
	}

	private static function check_address_transactions_for_matching_payments($crypto, $address, $transactionLifetime) {
		$paymentRepo = new pl8app_rpr_Payment_Repo();
		$pl8appRprSettings = new pl8app_rpr_Settings(get_option(pl8app_rpr_REDUX_ID));

		$cryptoId = $crypto->get_id();
		$contract_address = pl8app_rpr_Cryptocurrencies::get_erc20_contract($cryptoId);

		pl8app_rpr_Util::log(__FILE__, __LINE__, '===========================================================================');
		pl8app_rpr_Util::log(__FILE__, __LINE__, 'Starting payment verification for: ' . $cryptoId . ' - ' . $address);

		if(empty($contract_address)) return;

        try {
            $transactions = self::get_address_transactions($contract_address, $address);
		}
		catch (\Exception $e) {
			error_log(__FILE__, __LINE__, 'Unable to get transactions for ' . $cryptoId);
			return;
		}

		foreach ($transactions as $transaction) {
			$txHash = $transaction->get_hash();
			$transactionAmount = $transaction->get_amount();

			$requiredConfirmations = $pl8appRprSettings->get_autopay_required_confirmations($cryptoId);
			$txConfirmations = $transaction->get_confirmations();


			if ($txConfirmations < $requiredConfirmations) {
				continue;
			}

			$txTimeStamp = $transaction->get_time_stamp();
			$timeSinceTx = time() - $txTimeStamp;
			error_log(print_r('-----start!', 1));

//			if ($timeSinceTx > $transactionLifetime) {
//				continue;
//			}


			if ($pl8appRprSettings->tx_already_consumed($cryptoId, $address, $txHash)) {
				error_log(print_r('tax?' , 1));
                continue;
            }


			$paymentRecords = $paymentRepo->get_unpaid_for_address($cryptoId, $address);


			$matchingPaymentRecords = [];

			foreach ($paymentRecords as $record) {
				$paymentAmount = $record['order_amount'];

				$TolerancePaymentPercent = apply_filters('pl8app_rpr_autopay_percent', $pl8appRprSettings->get_autopay_processing_percent($cryptoId), $paymentAmount, $cryptoId, $address);

				$percentDifference =  abs($paymentAmount - $transactionAmount ) / $paymentAmount;

				if ( ((float)($TolerancePaymentPercent / 100) - $percentDifference > -0.000000000000001) && ((float)($TolerancePaymentPercent / 100) - $percentDifference < (float) ($TolerancePaymentPercent / 100)) ) {
					$matchingPaymentRecords[] = $record;
				}
			}

			// Transaction does not match any order payment
			if (count($matchingPaymentRecords) == 0) {
				// Do nothing
			}

			if (count($matchingPaymentRecords) > 1) {
				// We have a collision, send admin note to each order
				foreach ($matchingPaymentRecords as $matchingRecord) {
					$orderId = $matchingRecord['order_id'];
					rpress_insert_payment_note($orderId, 'This order has a matching ' . $cryptoId . ' transaction but we cannot verify it due to other orders with similar payment totals. Please reconcile manually. Transaction Hash: ' . $txHash);
				}

				$pl8appRprSettings->add_consumed_tx($cryptoId, $address, $txHash);
			}
			if (count($matchingPaymentRecords) == 1) {

				error_log(print_r($matchingPaymentRecords, 1));
				// We have validated a transaction: update database to paid, update order to processing, add transaction to consumed transactions
				$orderId = $matchingPaymentRecords[0]['order_id'];
				$orderAmount = $matchingPaymentRecords[0]['order_amount'];

				$paymentRepo->set_status($orderId, $orderAmount, 'paid');
				$paymentRepo->set_hash($orderId, $orderAmount, $txHash);

				$payment = new RPRESS_Payment($orderId);
				$orderNote = sprintf(
						'Order payment of %s %s verified at %s. Transaction Hash: %s',
						pl8app_rpr_Cryptocurrencies::get_price_string($crypto->get_id(), $transactionAmount / (10**$crypto->get_round_precision())),
						$cryptoId,
						date('Y-m-d H:i:s', time()),
						apply_filters('pl8app_rpr_order_txhash', $txHash, $cryptoId));

				rpress_update_payment_status($orderId, 'publish');
				rpress_insert_payment_note($orderId, $orderNote);

				rpress_update_payment_meta($orderId, '_rpress_pl8app_transaction_hash', $txHash);

				$pl8appRprSettings->add_consumed_tx($cryptoId, $address, $txHash);
			}
		}
	}

	private static function get_address_transactions($contract_address, $address) {

        $result = pl8app_rpr_Blockchain::get_erc20_address_transactions($contract_address, $address);

		if ($result['result'] === 'error') {
			pl8app_rpr_Util::log(__FILE__, __LINE__, 'BAD API CALL');
			throw new \Exception('Could not reach external service to do auto payment processing.');
		}

		return $result['transactions'];

	}

	public static function cancel_expired_payments() {
		$pl8appRprSettings = new pl8app_rpr_Settings(get_option(pl8app_rpr_REDUX_ID));

		$paymentRepo = new pl8app_rpr_Payment_Repo();
		$unpaidPayments = $paymentRepo->get_unpaid();

		foreach ($unpaidPayments as $paymentRecord) {
			$orderTime = $paymentRecord['ordered_at'];
			$cryptoId = $paymentRecord['cryptocurrency'];

			$paymentCancellationTimeSec = $pl8appRprSettings->get_order_expire_time() * 60;
			$timeSinceOrder = time() - $orderTime;


			if ($timeSinceOrder > $paymentCancellationTimeSec) {
				$orderId = $paymentRecord['order_id'];
				$orderAmount = $paymentRecord['order_amount'];
				$address = $paymentRecord['address'];

				$paymentRepo->set_status($orderId, $orderAmount, 'cancelled');

				$orderNote = sprintf(
					'Your ' . $cryptoId . ' order was <strong>cancelled</strong> because you were unable to pay for %s hour(s). Please do not send any funds to the payment address.',
					round($paymentCancellationTimeSec/3600, 1),
					$address);

				// Make sure to change the payment status here before before
				// triggering pl8app_rpr_trigger_purchase_cancel as a filter is
				// set up for e-mails on an order with a status of pending
				rpress_update_payment_status($orderId, 'abandoned');
				do_action('pl8app_rpr_trigger_purchase_cancel', $orderId);

				rpress_insert_payment_note($orderId, $orderNote);
			}
		}
	}
}

?>
