<?php

require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/storage.php';

function castaneas_products_index() {
    static $index = null;
    if ($index !== null) {
        return $index;
    }

    $index = [];
    $raw = castaneas_storage_read_raw('products');
    if ($raw === null) {
        return $index;
    }

    $products = json_decode($raw, true);
    if (!is_array($products)) {
        return $index;
    }

    foreach ($products as $product) {
        if (!is_array($product) || empty($product['id'])) {
            continue;
        }

        $index[$product['id']] = $product;
    }

    return $index;
}

function castaneas_sucrine_build_items(array $order) {
    $items = [];
    $products = castaneas_products_index();

    foreach (($order['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $product = $products[$item['id'] ?? ''] ?? null;
        $sucrineId = $item['sucrineId'] ?? ($product['sucrineId'] ?? null);
        $qty = (int) ($item['qty'] ?? 0);

        if (!$sucrineId || $qty <= 0) {
            continue;
        }

        if (!isset($items[$sucrineId])) {
            $items[$sucrineId] = ['quantity' => 0];
        }

        $items[$sucrineId]['quantity'] += $qty;
    }

    return $items;
}

function castaneas_sucrine_payload(array $order) {
    $billing = $order['billing'] ?? [];
    $address = [
        'street' => trim(($billing['adresse'] ?? '') . ' ' . ($billing['complement'] ?? '')),
        'zipCode' => $billing['cp'] ?? '',
        'city' => $billing['ville'] ?? '',
        'country' => $billing['pays'] ?? 'FR',
    ];

    return [
        'newContact' => [
            'firstName' => $billing['prenom'] ?? '',
            'lastName' => $billing['nom'] ?? '',
            'email' => $billing['email'] ?? '',
            'phone' => $billing['tel'] ?? '',
        ],
        'advancedCatalogueItems' => castaneas_sucrine_build_items($order),
        'deliveryAddress' => $address,
        'invoicingAddress' => $address,
        'comment' => $billing['note'] ?? '',
        'externalReference' => $order['id'] ?? '',
    ];
}

function castaneas_sucrine_send_order(array $order) {
    if (!castaneas_sucrine_is_ready()) {
        return [
            'ok' => false,
            'code' => 'sucrine_not_configured',
            'message' => 'Configuration Sucrine absente.',
        ];
    }

    $payload = castaneas_sucrine_payload($order);
    if (empty($payload['advancedCatalogueItems'])) {
        return [
            'ok' => false,
            'code' => 'sucrine_missing_products',
            'message' => 'Aucun produit de la commande ne possède de référence Sucrine.',
        ];
    }

    $config = castaneas_sucrine_config();
    $url = rtrim($config['base_url'], '/') . '/professional/customerOrders/order';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'Authorization: ApiKey ' . $config['api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'code' => 'sucrine_transport_error',
            'message' => $error ?: 'Erreur réseau Sucrine.',
        ];
    }

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'code' => 'sucrine_http_error',
            'status' => $status,
            'message' => is_array($decoded) ? ($decoded['message'] ?? $decoded['error'] ?? 'Erreur API Sucrine.') : 'Erreur API Sucrine.',
            'raw' => $decoded ?: $response,
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'data' => is_array($decoded) ? $decoded : ['raw' => $response],
    ];
}