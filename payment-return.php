<?php

require_once __DIR__ . '/payment-flow.php';

$payload = castaneas_payment_request_data();
$ref = castaneas_payment_resolve_ref($payload);
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