<?php

require_once __DIR__ . '/payment-flow.php';

$payload = castaneas_payment_request_data();
$ref = castaneas_payment_resolve_ref($payload);
$gateway = strtolower(trim((string) ($payload['gateway'] ?? '')));

if ($gateway === 'paypal' || !empty($payload['token']) || !empty($payload['PayerID'])) {
    $paypalOrderId = trim((string) ($payload['token'] ?? ''));
    $status = strtolower(trim((string) ($payload['status'] ?? '')));
    $paymentStatus = $status !== '' ? $status : 'failed';

    if ($status !== 'cancelled' && $paypalOrderId !== '') {
        $capture = castaneas_paypal_capture_attempt_payload($paypalOrderId, castaneas_paypal_capture_order($paypalOrderId));
        if (!empty($capture['ok'])) {
            $paymentStatus = trim((string) ($capture['status'] ?? ''));
            if ($paymentStatus === '') {
                $paymentStatus = 'paid';
            }
            if ($ref === '') {
                $ref = castaneas_paypal_extract_reference_from_order($capture['payload']);
            }
            $payload = array_merge($payload, $capture['payload'], [
                'transaction_id' => castaneas_paypal_extract_capture_id($capture['payload']),
                'status' => $paymentStatus,
            ]);
        } else {
            $payload = array_merge($payload, [
                'status' => 'failed',
                'error' => (string) ($capture['message'] ?? 'Capture PayPal impossible.'),
            ]);
            $paymentStatus = 'failed';
        }
    }

    if ($ref !== '') {
        castaneas_payment_finalize_order($ref, $paymentStatus, $payload);
    }

    $redirect = '/confirmation.html';
    if ($ref !== '') {
        $redirect .= '?ref=' . rawurlencode($ref) . '&payment_status=' . rawurlencode($paymentStatus);
    }

    header('Location: ' . $redirect, true, 302);
    exit;
}

$paymentStatus = castaneas_payment_resolve_status($payload);
if ($ref !== '' && (castaneas_payment_is_simulated($payload) || $paymentStatus !== 'paid')) {
    castaneas_payment_finalize_order($ref, $paymentStatus, $payload);
}

$redirect = '/confirmation.html';
if ($ref !== '') {
    $redirect .= '?ref=' . rawurlencode($ref) . '&payment_status=' . rawurlencode($paymentStatus);
}

header('Location: ' . $redirect, true, 302);
exit;