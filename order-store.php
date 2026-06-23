<?php

require_once __DIR__ . '/storage.php';

function castaneas_orders_all() {
    $raw = castaneas_storage_read_raw('orders');
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $orders = json_decode($raw, true);

    return is_array($orders) ? array_values($orders) : [];
}

function castaneas_orders_save(array $orders) {
    return castaneas_storage_write_raw('orders', json_encode(array_values($orders), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function castaneas_order_generate_ref() {
    do {
        $ref = 'CAS-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    } while (castaneas_order_find($ref));

    return $ref;
}

function castaneas_order_find($ref) {
    foreach (castaneas_orders_all() as $order) {
        if (($order['id'] ?? '') === $ref) {
            return $order;
        }
    }

    return null;
}

function castaneas_order_upsert(array $order) {
    $orders = castaneas_orders_all();
    $foundIndex = null;

    foreach ($orders as $index => $existing) {
        if (($existing['id'] ?? '') === ($order['id'] ?? '')) {
            $foundIndex = $index;
            break;
        }
    }

    if ($foundIndex === null) {
        $orders[] = $order;
    } else {
        $orders[$foundIndex] = $order;
    }

    if (!castaneas_orders_save($orders)) {
        return false;
    }

    return $order;
}

function castaneas_order_update_status($ref, $status, array $extra = []) {
    $order = castaneas_order_find($ref);
    if (!$order) {
        return null;
    }

    $order['status'] = $status;
    $order['updatedAt'] = gmdate('c');

    foreach ($extra as $key => $value) {
        $order[$key] = $value;
    }

    return castaneas_order_upsert($order);
}

function castaneas_order_public_payload(array $order) {
    return [
        'id' => $order['id'] ?? '',
        'status' => $order['status'] ?? 'pending_payment',
        'total' => $order['total'] ?? 0,
        'customer' => $order['customer'] ?? '',
        'createdAt' => $order['createdAt'] ?? null,
        'paidAt' => $order['paidAt'] ?? null,
        'payment' => [
            'method' => $order['payment']['method'] ?? null,
            'transactionId' => $order['payment']['transactionId'] ?? null,
        ],
        'sucrine' => [
            'sent' => !empty($order['sucrine']['sentAt']),
            'orderId' => $order['sucrine']['orderId'] ?? null,
        ],
        'sendcloud' => [
            'created' => !empty($order['sendcloud']['createdAt']),
            'parcelId' => $order['sendcloud']['parcelId'] ?? null,
            'labelUrl' => $order['sendcloud']['labelUrl'] ?? null,
            'trackingNumber' => $order['sendcloud']['trackingNumber'] ?? null,
        ],
    ];
}