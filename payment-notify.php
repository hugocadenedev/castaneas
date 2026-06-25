<?php

require_once __DIR__ . '/payment-flow.php';

header('Content-Type: application/json; charset=utf-8');

$payload = castaneas_payment_request_data();
if (!castaneas_payment_notify_is_authorized($payload)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Notification non autorisee.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$ref = castaneas_payment_resolve_ref($payload);
if ($ref === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Référence manquante.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$order = castaneas_payment_finalize_order($ref, castaneas_payment_resolve_status($payload), $payload);
if (!$order) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Commande introuvable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok' => true, 'order' => castaneas_order_public_payload($order)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);