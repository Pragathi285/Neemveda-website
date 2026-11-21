<?php
/**
 * SMS for phone OTP. Providers: MSG91 (OTP API with an approved template) or 2Factor.
 * Phone numbers are Indian 10-digit mobiles; we send to 91XXXXXXXXXX.
 */
defined('ABSPATH') || exit;

function nvc_sms_ready() {
	$p = nvc_get('nv_sms_provider');
	if ($p === 'msg91') return nvc_get('nv_msg91_authkey') && nvc_get('nv_msg91_template');
	if ($p === '2factor') return (bool) nvc_get('nv_2factor_key');
	return false;
}

/** "+91 98765-43210" → "9876543210", or '' if it isn't a valid Indian mobile number. */
function nvc_normalise_phone($raw) {
	$d = preg_replace('/\D+/', '', (string) $raw);
	if (strlen($d) === 12 && str_starts_with($d, '91')) $d = substr($d, 2);
	if (strlen($d) === 11 && str_starts_with($d, '0')) $d = substr($d, 1);
	return preg_match('/^[6-9]\d{9}$/', $d) ? $d : '';
}

/** Sends the OTP by SMS. Returns true or a WP_Error. */
function nvc_send_sms_otp($phone, $code) {
	$provider = nvc_get('nv_sms_provider');
	if ($provider === 'msg91') {
		$url = add_query_arg([
			'template_id' => nvc_get('nv_msg91_template'),
			'mobile'      => '91' . $phone,
			'otp'         => $code,
		], 'https://control.msg91.com/api/v5/otp');
		$res = wp_remote_post($url, ['timeout' => 15, 'headers' => ['authkey' => nvc_get('nv_msg91_authkey'), 'Content-Type' => 'application/json'], 'body' => '{}']);
		if (is_wp_error($res)) return $res;
		$body = json_decode(wp_remote_retrieve_body($res), true);
		return (is_array($body) && ($body['type'] ?? '') === 'success') ? true : new WP_Error('sms', 'MSG91: ' . ($body['message'] ?? 'send failed'));
	}
	if ($provider === '2factor') {
		$tpl = nvc_get('nv_2factor_template');
		$url = 'https://2factor.in/API/V1/' . rawurlencode(nvc_get('nv_2factor_key')) . '/SMS/' . rawurlencode('91' . $phone) . '/' . rawurlencode($code) . ($tpl ? '/' . rawurlencode($tpl) : '');
		$res = wp_remote_get($url, ['timeout' => 15]);
		if (is_wp_error($res)) return $res;
		$body = json_decode(wp_remote_retrieve_body($res), true);
		return (is_array($body) && ($body['Status'] ?? '') === 'Success') ? true : new WP_Error('sms', '2Factor: ' . ($body['Details'] ?? 'send failed'));
	}
	return new WP_Error('sms', 'SMS is not set up.');
}
