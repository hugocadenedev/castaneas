<?php

require_once __DIR__ . '/order-store.php';
require_once __DIR__ . '/sendcloud.php';
require_once __DIR__ . '/sucrine.php';
require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/invoice-lib.php';

function castaneas_payment_request_data() {
    $data = $_REQUEST;
    if (!is_array($data)) {
        $data = [];
    }

    return $data;
}

function castaneas_payment_resolve_ref(array $data) {
    $candidates = [
        $data['ref'] ?? null,
        $data['Ref'] ?? null,
        $data['reference'] ?? null,
        $data['PBX_CMD'] ?? null,
        $data['cmd'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function castaneas_payment_resolve_status(array $data) {
    $status = strtolower(trim((string) ($data['status'] ?? '')));
    if (in_array($status, ['paid', 'refused', 'cancelled', 'failed'], true)) {
        return $status;
    }

    $errorCode = trim((string) ($data['Erreur'] ?? $data['error'] ?? ''));
    if ($errorCode === '' || $errorCode === '00000') {
        return 'paid';
    }

    return 'failed';
}

function castaneas_payment_transaction_id(array $data) {
    $candidates = [
        $data['transaction_id'] ?? null,
        $data['Trans'] ?? null,
        $data['Auto'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return null;
}

function castaneas_payment_is_simulated(array $data) {
    return !empty($data['simulate']) && trim((string) $data['simulate']) === '1';
}

function castaneas_payment_notify_is_authorized(array $data) {
    $config = castaneas_up2pay_config();
    $expected = trim((string) ($config['callback_secret'] ?? ''));
    if ($expected === '') {
        return true;
    }

    $provided = trim((string) ($data['token'] ?? ''));

    return $provided !== '' && hash_equals($expected, $provided);
}

function castaneas_payment_increment_promo_usage(array $order) {
    $promo = is_array($order['promo'] ?? null) ? $order['promo'] : [];
    $code = strtoupper(trim((string) ($promo['code'] ?? '')));
    if ($code === '') {
        return;
    }

    $raw = castaneas_storage_read_raw('promo_codes');
    $codes = $raw ? json_decode($raw, true) : [];
    if (!is_array($codes)) {
        return;
    }

    $updated = false;
    foreach ($codes as &$promoCode) {
        if (!is_array($promoCode)) {
            continue;
        }
        if (strtoupper(trim((string) ($promoCode['code'] ?? ''))) !== $code) {
            continue;
        }
        $usedByOrders = is_array($promoCode['usedByOrders'] ?? null) ? $promoCode['usedByOrders'] : [];
        if (in_array((string) ($order['id'] ?? ''), $usedByOrders, true)) {
            return;
        }
        $usedByOrders[] = (string) ($order['id'] ?? '');
        $promoCode['usedByOrders'] = $usedByOrders;
        $promoCode['usedCount'] = count($usedByOrders);
        $updated = true;
        break;
    }
    unset($promoCode);

    if ($updated) {
        castaneas_storage_write_raw('promo_codes', json_encode(array_values($codes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

function castaneas_payment_finalize_order($ref, $status, array $payload) {
    $order = castaneas_order_find($ref);
    if (!$order) {
        return null;
    }

    $mappedStatus = [
        'paid' => 'paid',
        'refused' => 'payment_refused',
        'cancelled' => 'cancelled',
        'failed' => 'payment_failed',
    ][$status] ?? 'payment_failed';

    $payment = is_array($order['payment'] ?? null) ? $order['payment'] : [];
    $payment['transactionId'] = castaneas_payment_transaction_id($payload);
    $payment['gatewayReturn'] = $payload;
    $payment['updatedAt'] = gmdate('c');

    $extra = [
        'payment' => $payment,
    ];

    if ($mappedStatus === 'paid') {
        $extra['paidAt'] = gmdate('c');
    }

    $order = castaneas_order_update_status($ref, $mappedStatus, $extra);
    if (!$order || $mappedStatus !== 'paid') {
        return $order;
    }

    $invoiceResult = castaneas_invoice_assign_order($ref, $extra['paidAt'] ?? null);
    if (!empty($invoiceResult['ok']) && !empty($invoiceResult['order'])) {
        $order = $invoiceResult['order'];
    }

    castaneas_payment_increment_promo_usage($order);

    if (empty($order['sendcloud']['createdAt'])) {
        $sendcloudResult = castaneas_sendcloud_send_order($order);
        $sendcloud = is_array($order['sendcloud'] ?? null) ? $order['sendcloud'] : [];
        $sendcloud['lastAttemptAt'] = gmdate('c');
        $sendcloud['lastResult'] = $sendcloudResult;
        if (!empty($sendcloudResult['ok'])) {
            $parcel = $sendcloudResult['data']['parcel'] ?? [];
            $sendcloud['createdAt'] = gmdate('c');
            $sendcloud['parcelId'] = $parcel['id'] ?? null;
            $sendcloud['trackingNumber'] = $parcel['tracking_number'] ?? null;
            $sendcloud['labelUrl'] = $parcel['label']['label_printer'] ?? ($parcel['label']['normal_printer'][0] ?? null);
        }

        $order = castaneas_order_update_status($ref, $mappedStatus, ['sendcloud' => $sendcloud]);
        if (!$order) {
            return null;
        }
    }

    if (!empty($order['sucrine']['sentAt'])) {
        return $order;
    }

    $sucrineResult = castaneas_sucrine_send_order($order);
    $sucrine = is_array($order['sucrine'] ?? null) ? $order['sucrine'] : [];
    $sucrine['lastAttemptAt'] = gmdate('c');
    $sucrine['lastResult'] = $sucrineResult;
    if (!empty($sucrineResult['ok'])) {
        $sucrine['sentAt'] = gmdate('c');
        $sucrine['orderId'] = $sucrineResult['data']['id'] ?? $sucrineResult['data']['orderId'] ?? null;
    }

    return castaneas_order_update_status($ref, $mappedStatus, ['sucrine' => $sucrine]);
}