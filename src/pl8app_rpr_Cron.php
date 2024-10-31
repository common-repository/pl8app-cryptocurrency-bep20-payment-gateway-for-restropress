<?php

function pl8app_rpr_do_cron_job() {
	global $wpdb;

	$pl8appRprSettings = new pl8app_rpr_Settings(get_option(pl8app_rpr_REDUX_ID));
	// Number of clean addresses in the database at all times for faster thank you page load times
	$hdBufferAddressCount = 4;

	// Only look at transactions in the past two hours
	$autoPaymentTransactionLifetimeSec = 3 * 60 * 60;

	$startTime = time();

	pl8app_rpr_Carousel_Repo::init();
	foreach (pl8app_rpr_Cryptocurrencies::get() as $crypto) {
		$cryptoId = $crypto->get_id();

		if ($pl8appRprSettings->hd_enabled($cryptoId)) {
			pl8app_rpr_Util::log(__FILE__, __LINE__, 'Starting Hd stuff for: ' . $cryptoId);
			$mpk = $pl8appRprSettings->get_mpk($cryptoId);
			$hdMode = $pl8appRprSettings->get_hd_mode($cryptoId);
			$hdPercentToVerify = $pl8appRprSettings->get_hd_processing_percent($cryptoId);
			$hdRequiredConfirmations = $pl8appRprSettings->get_hd_required_confirmations($cryptoId);
			$hdOrderCancellationTimeHr = $pl8appRprSettings->get_hd_cancellation_time($cryptoId);
			$hdOrderCancellationTimeSec = round($hdOrderCancellationTimeHr * 60 * 60, 0);

			pl8app_rpr_Hd::check_all_pending_addresses_for_payment($cryptoId, $mpk, $hdRequiredConfirmations, $hdPercentToVerify, $hdMode);

			pl8app_rpr_Hd::buffer_ready_addresses($cryptoId, $mpk, $hdBufferAddressCount, $hdMode);
			pl8app_rpr_Hd::cancel_expired_addresses($cryptoId, $mpk, $hdOrderCancellationTimeSec, $hdMode);
		}
	}

	pl8app_rpr_Payment::check_all_addresses_for_matching_payment($autoPaymentTransactionLifetimeSec);
	pl8app_rpr_Payment::cancel_expired_payments();

}

function pl8app_rpr_get_time_passed($startTime) {
	return time() - $startTime;
}

?>
