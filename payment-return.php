<?php

require_once __DIR__ . '/payment-flow.php';

$payload = castaneas_payment_request_data();
$ref = castaneas_payment_resolve_ref($payload);
if ($ref !== '') {
    castaneas_payment_finalize_order($ref, castaneas_payment_resolve_status($payload), $payload);
}

$redirect = '/confirmation.html';
if ($ref !== '') {
    $redirect .= '?ref=' . rawurlencode($ref);
}

header('Location: ' . $redirect, true, 302);
exit;