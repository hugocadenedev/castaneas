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

function castaneas_sucrine_delivery_point_candidates(array $order, array $config) {
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
    if (!empty($shipping['carrier']['code'])) {
        $candidates[] = $shipping['carrier']['code'];
    }
    if (!empty($shipping['name'])) {
        $candidates[] = $shipping['name'];
    }
    if (!empty($shipping['product']['name'])) {
        $candidates[] = $shipping['product']['name'];
    }
    if ($type !== '') {
        $candidates[] = $type;
    }

    $normalized = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }
        $normalized[] = $candidate;

        $lower = strtolower($candidate);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $lower);
        $slug = trim((string) $slug, '-');
        if ($slug !== '' && $slug !== $candidate) {
            $normalized[] = $slug;
        }

        $compact = preg_replace('/[^a-z0-9]+/', '_', $lower);
        $compact = trim((string) $compact, '_');
        if ($compact !== '' && $compact !== $candidate && $compact !== $slug) {
            $normalized[] = $compact;
        }
    }

    $unique = [];
    foreach ($normalized as $candidate) {
        if (!in_array($candidate, $unique, true)) {
            $unique[] = $candidate;
        }
    }

    return $unique;
}

function castaneas_sucrine_delivery_point(array $order, array $config) {
    $candidates = castaneas_sucrine_delivery_point_candidates($order, $config);

    return $candidates ? $candidates[0] : '';
}

function castaneas_sucrine_payload(array $order, $deliveryPoint = null) {
    $billing = $order['billing'] ?? [];
    $config = castaneas_sucrine_config();
    $address = castaneas_sucrine_address_payload($billing);
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $shippingLabel = trim((string) (($shipping['name'] ?? '') ?: ($shipping['product']['name'] ?? '') ?: ($shipping['type'] ?? 'Livraison')));
    $shippingAmount = round((float) ($shipping['price'] ?? 0), 2);
    $resolvedDeliveryPoint = $deliveryPoint !== null ? trim((string) $deliveryPoint) : castaneas_sucrine_delivery_point($order, $config);

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
        'deliveryPoint' => $resolvedDeliveryPoint,
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

function castaneas_sucrine_error_message($value) {
    if (is_string($value)) {
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    if (!is_array($value)) {
        return null;
    }

    $candidates = [
        $value['message'] ?? null,
        $value['error'] ?? null,
        $value['detail'] ?? null,
        $value['title'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $resolved = castaneas_sucrine_error_message($candidate);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    if (!empty($value['errors']) && is_array($value['errors'])) {
        $messages = [];
        foreach ($value['errors'] as $error) {
            $resolved = castaneas_sucrine_error_message($error);
            if ($resolved !== null) {
                $messages[] = $resolved;
            }
        }
        if ($messages) {
            return implode(' | ', array_values(array_unique($messages)));
        }
    }

    if (!empty($value['violations']) && is_array($value['violations'])) {
        $messages = [];
        foreach ($value['violations'] as $violation) {
            $resolved = castaneas_sucrine_error_message($violation);
            if ($resolved !== null) {
                $messages[] = $resolved;
            }
        }
        if ($messages) {
            return implode(' | ', array_values(array_unique($messages)));
        }
    }

    $flat = [];
    array_walk_recursive($value, static function ($item) use (&$flat) {
        if (is_scalar($item) && trim((string) $item) !== '') {
            $flat[] = trim((string) $item);
        }
    });

    return $flat ? implode(' | ', array_values(array_unique($flat))) : null;
}

function castaneas_sucrine_send_order(array $order) {
    if (!castaneas_sucrine_is_ready()) {
        return [
            'ok' => false,
            'code' => 'sucrine_not_configured',
            'message' => 'Configuration Sucrine absente.',
        ];
    }

    $items = castaneas_sucrine_build_items($order);
    if (empty($items)) {
        return [
            'ok' => false,
            'code' => 'sucrine_missing_products',
            'message' => 'Aucun produit de la commande ne possède de référence Sucrine.',
        ];
    }
    $config = castaneas_sucrine_config();
    $deliveryPointCandidates = castaneas_sucrine_delivery_point_candidates($order, $config);
    if (!$deliveryPointCandidates) {
        return [
            'ok' => false,
            'code' => 'sucrine_missing_delivery_point',
            'message' => 'Mode de distribution Sucrine manquant. Configurez sucrine.delivery_point ou les variantes home/relay.',
            'payload' => castaneas_sucrine_payload($order),
        ];
    }

    $url = rtrim($config['base_url'], '/') . '/professional/customerOrders/order';
    $attemptErrors = [];

    foreach ($deliveryPointCandidates as $deliveryPoint) {
        $payload = castaneas_sucrine_payload($order, $deliveryPoint);
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
                'deliveryPoint' => $deliveryPoint,
            ];
        }

        $decoded = json_decode($response, true);
        if ($status >= 200 && $status < 300) {
            return [
                'ok' => true,
                'status' => $status,
                'deliveryPoint' => $deliveryPoint,
                'data' => is_array($decoded) ? $decoded : ['raw' => $response],
            ];
        }

        $message = castaneas_sucrine_error_message($decoded);
        if ($message === null && is_string($response) && trim($response) !== '') {
            $message = trim($response);
        }

        $attemptErrors[] = [
            'deliveryPoint' => $deliveryPoint,
            'status' => $status,
            'message' => $message ?: 'Erreur API Sucrine.',
            'raw' => $decoded ?: $response,
        ];
    }

    $last = end($attemptErrors) ?: null;
    return [
        'ok' => false,
        'code' => 'sucrine_http_error',
        'status' => $last['status'] ?? 500,
        'message' => ($last['message'] ?? 'Erreur API Sucrine.') . ' | deliveryPoint essayé: ' . implode(', ', $deliveryPointCandidates),
        'raw' => $attemptErrors,
    ];
}