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

function castaneas_sucrine_contact_name(array $billing) {
    return trim(implode(' ', array_filter([
        trim((string) ($billing['prenom'] ?? '')),
        trim((string) ($billing['nom'] ?? '')),
    ])));
}

function castaneas_sucrine_address_payload(array $billing) {
    return [
        'address' => trim((string) ($billing['adresse'] ?? '')),
        'addressExtra' => trim((string) ($billing['complement'] ?? '')),
        'city' => trim((string) ($billing['ville'] ?? '')),
        'company' => trim((string) ($billing['societe'] ?? '')),
        'name' => castaneas_sucrine_contact_name($billing),
        'country' => strtoupper(trim((string) ($billing['pays'] ?? 'FR'))),
        'zipcode' => trim((string) ($billing['cp'] ?? '')),
    ];
}

function castaneas_sucrine_delivery_point(array $order, array $config) {
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $servicePoint = is_array($shipping['servicePoint'] ?? null) ? $shipping['servicePoint'] : [];
    $type = trim((string) ($shipping['type'] ?? ''));
    $candidates = [];

    if (!empty($config['delivery_point'])) {
        $candidates[] = $config['delivery_point'];
    }
    if ($type === 'home' && !empty($config['delivery_point_home'])) {
        $candidates[] = $config['delivery_point_home'];
    }
    if ($type === 'relay' && !empty($config['delivery_point_relay'])) {
        $candidates[] = $config['delivery_point_relay'];
    }
    if (!empty($servicePoint['carrierServicePointId'])) {
        $candidates[] = $servicePoint['carrierServicePointId'];
    }
    if (!empty($shipping['code'])) {
        $candidates[] = $shipping['code'];
    }
    if (!empty($shipping['product']['code'])) {
        $candidates[] = $shipping['product']['code'];
    }

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function castaneas_sucrine_payload(array $order) {
    $billing = $order['billing'] ?? [];
    $config = castaneas_sucrine_config();
    $address = castaneas_sucrine_address_payload($billing);
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $shippingLabel = trim((string) (($shipping['name'] ?? '') ?: ($shipping['product']['name'] ?? '') ?: ($shipping['type'] ?? 'Livraison')));
    $shippingAmount = round((float) ($shipping['price'] ?? 0), 2);

    return [
        'orderType' => 'order',
        'customerOrderReference' => (string) ($order['id'] ?? ''),
        'orderSource' => trim((string) ($config['order_source'] ?? 'castaneas')),
        'newContact' => [
            'name' => castaneas_sucrine_contact_name($billing),
            'email' => trim((string) ($billing['email'] ?? '')),
            'phone' => trim((string) ($billing['tel'] ?? '')),
        ],
        'advancedCatalogueItems' => castaneas_sucrine_build_items($order),
        'skipPreciseSupplyCheck' => !array_key_exists('skip_precise_supply_check', $config) || !empty($config['skip_precise_supply_check']),
        'deliveryPoint' => castaneas_sucrine_delivery_point($order, $config),
        'delivery' => [
            'description' => $shippingLabel,
            'amount' => $shippingAmount,
            'dfAmount' => $shippingAmount,
            'vatRate' => 0,
            'vatAmount' => 0,
        ],
        'deliveryAddress' => $address,
        'invoicingAddress' => $address,
        'message' => trim((string) ($billing['note'] ?? '')),
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
    if (trim((string) ($payload['deliveryPoint'] ?? '')) === '') {
        return [
            'ok' => false,
            'code' => 'sucrine_missing_delivery_point',
            'message' => 'Mode de distribution Sucrine manquant. Configurez sucrine.delivery_point ou les variantes home/relay.',
            'payload' => $payload,
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