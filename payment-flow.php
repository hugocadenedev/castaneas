<?php

require_once __DIR__ . '/order-store.php';
require_once __DIR__ . '/sendcloud.php';
require_once __DIR__ . '/sucrine.php';
require_once __DIR__ . '/integrations.php';

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