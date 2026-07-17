<?php

require_once __DIR__ . '/payment-flow.php';

header('Content-Type: application/json; charset=utf-8');

$payload = castaneas_payment_request_data();
$isPaypalWebhook = strpos(strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? ''))), 'application/json') !== false
    && !empty($_SERVER['HTTP_PAYPAL_TRANSMISSION_ID']);

if ($isPaypalWebhook) {
    $raw = castaneas_payment_raw_body();
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Payload PayPal invalide.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $verification = castaneas_paypal_verify_webhook(castaneas_paypal_webhook_headers(), $decoded);
    if (empty($verification['ok'])) {
        http_response_code(($verification['code'] ?? '') === 'paypal_webhook_not_configured' ? 503 : 403);
        echo json_encode(['ok' => false, 'error' => (string) ($verification['message'] ?? 'Notification PayPal non autorisee.')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $eventType = strtoupper(trim((string) ($decoded['event_type'] ?? '')));
    $paypalOrderId = trim((string) (
        $decoded['resource']['supplementary_data']['related_ids']['order_id']
        ?? $decoded['resource']['id']
        ?? ''
    ));

    if ($eventType === 'CHECKOUT.ORDER.APPROVED' && $paypalOrderId !== '') {
        $capture = castaneas_paypal_capture_attempt_payload($paypalOrderId, castaneas_paypal_capture_order($paypalOrderId));
        if (empty($capture['ok'])) {
            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => (string) ($capture['message'] ?? 'Capture PayPal impossible.')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $decoded = array_merge($decoded, [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => $capture['payload'],
            'transaction_id' => castaneas_paypal_extract_capture_id($capture['payload']),
        ]);
    } elseif ($paypalOrderId !== '') {
        $paypalOrder = castaneas_paypal_get_order($paypalOrderId);
        if (!empty($paypalOrder['ok'])) {
            $decoded['paypalOrder'] = $paypalOrder['data'];
        }
    }

    $ref = castaneas_payment_resolve_ref($decoded);
    if ($ref === '' && !empty($decoded['paypalOrder']) && is_array($decoded['paypalOrder'])) {
        $ref = castaneas_paypal_extract_reference_from_order($decoded['paypalOrder']);
    }
    if ($ref === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Référence PayPal introuvable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $status = castaneas_payment_resolve_status($decoded);
    if ($status === 'pending_payment' || $status === '') {
        echo json_encode(['ok' => true, 'ignored' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $order = castaneas_payment_finalize_order($ref, $status, $decoded);
    if (!$order) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Commande introuvable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(['ok' => true, 'order' => castaneas_order_public_payload($order)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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