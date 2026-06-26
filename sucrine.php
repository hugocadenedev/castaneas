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
        $qty = max(0, (int) ($item['qty'] ?? 0));
        $offerQty = max(1, (int) ($item['offerQty'] ?? 1));
        $lineQty = $qty * $offerQty;

        if ($sucrineId && $lineQty > 0) {
            if (!isset($items[$sucrineId])) {
                $items[$sucrineId] = ['quantity' => 0];
            }

            $items[$sucrineId]['quantity'] += $lineQty;
        }

        $boxItems = is_array($product['boxItems'] ?? null) ? $product['boxItems'] : [];
        if (!$boxItems || $qty <= 0) {
            continue;
        }

        foreach ($boxItems as $boxItem) {
            if (!is_array($boxItem) || empty($boxItem['productId'])) {
                continue;
            }

            $child = $products[$boxItem['productId']] ?? null;
            $childSucrineId = $child['sucrineId'] ?? null;
            if (!$childSucrineId) {
                continue;
            }

            $childQty = max(1, (int) ($boxItem['qty'] ?? 1)) * $qty;
            if (!isset($items[$childSucrineId])) {
                $items[$childSucrineId] = ['quantity' => 0];
            }

            $items[$childSucrineId]['quantity'] += $childQty;
        }
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

function castaneas_sucrine_shipping_lookup_keys(array $order) {
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $product = is_array($shipping['product'] ?? null) ? $shipping['product'] : [];
    $carrier = is_array($shipping['carrier'] ?? null) ? $shipping['carrier'] : [];
    $selectedFunctionalities = is_array($shipping['selectedFunctionalities'] ?? null) ? $shipping['selectedFunctionalities'] : [];
    $type = trim((string) ($shipping['type'] ?? ''));
    $carrierCode = trim((string) ($carrier['code'] ?? ''));
    $productCode = trim((string) ($product['code'] ?? ''));
    $shippingCode = trim((string) ($shipping['code'] ?? ''));
    $lastMile = trim((string) ($selectedFunctionalities['last_mile'] ?? ''));

    $keys = array_filter([
        $shippingCode,
        $productCode,
        $carrierCode !== '' && $productCode !== '' ? $carrierCode . ':' . $productCode : '',
        $carrierCode !== '' && $type !== '' ? $carrierCode . ':' . $type : '',
        $carrierCode !== '' && $lastMile !== '' ? $carrierCode . ':' . $lastMile : '',
        $carrierCode,
        $type,
        $lastMile,
    ]);

    return array_values(array_unique(array_map('strval', $keys)));
}

function castaneas_sucrine_configured_delivery_points(array $order, array $config) {
    $mapping = is_array($config['delivery_points'] ?? null) ? $config['delivery_points'] : [];
    if (!$mapping) {
        return [];
    }

    $points = [];
    foreach (castaneas_sucrine_shipping_lookup_keys($order) as $key) {
        $mapped = $mapping[$key] ?? null;
        if (is_string($mapped) && trim($mapped) !== '') {
            $points[] = trim($mapped);
        }
    }

    return array_values(array_unique($points));
}

function castaneas_sucrine_delivery_address(array $order, array $billing) {
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $servicePoint = is_array($shipping['servicePoint'] ?? null) ? $shipping['servicePoint'] : [];
    $servicePointAddress = is_array($servicePoint['address'] ?? null) ? $servicePoint['address'] : [];
    $type = trim((string) ($shipping['type'] ?? ''));

    if ($type !== 'relay' || !$servicePoint) {
        return castaneas_sucrine_address_payload($billing);
    }

    $street = trim(implode(' ', array_filter([
        (string) ($servicePointAddress['street'] ?? ''),
        (string) ($servicePointAddress['houseNumber'] ?? ''),
    ])));

    return [
        'address' => $street,
        'addressExtra' => '',
        'city' => trim((string) ($servicePointAddress['city'] ?? '')),
        'company' => trim((string) ($servicePoint['carrier']['name'] ?? '')),
        'name' => trim((string) ($servicePoint['name'] ?? castaneas_sucrine_contact_name($billing))),
        'country' => strtoupper(trim((string) ($servicePointAddress['countryCode'] ?? ($billing['pays'] ?? 'FR')))),
        'zipcode' => trim((string) ($servicePointAddress['postalCode'] ?? '')),
    ];
}

function castaneas_sucrine_delivery_description(array $order) {
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $servicePoint = is_array($shipping['servicePoint'] ?? null) ? $shipping['servicePoint'] : [];

    $parts = array_filter([
        trim((string) ($shipping['name'] ?? '')),
        trim((string) ($shipping['carrier']['name'] ?? '')),
    ]);

    $description = $parts ? implode(' - ', array_values(array_unique($parts))) : trim((string) ($shipping['type'] ?? 'Livraison'));
    if (!empty($servicePoint['name'])) {
        $description .= ' - ' . trim((string) $servicePoint['name']);
    }

    return $description;
}

function castaneas_sucrine_message(array $order, array $billing) {
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $servicePoint = is_array($shipping['servicePoint'] ?? null) ? $shipping['servicePoint'] : [];
    $notes = [];

    $rawNote = trim((string) ($billing['note'] ?? ''));
    if ($rawNote !== '') {
        $notes[] = $rawNote;
    }

    if ($servicePoint) {
        $servicePointAddress = is_array($servicePoint['address'] ?? null) ? $servicePoint['address'] : [];
        $location = trim(implode(' ', array_filter([
            (string) ($servicePointAddress['postalCode'] ?? ''),
            (string) ($servicePointAddress['city'] ?? ''),
        ])));
        $relayNote = 'Point relais: ' . trim((string) ($servicePoint['name'] ?? ''));
        if ($location !== '') {
            $relayNote .= ' (' . $location . ')';
        }
        if (!empty($servicePoint['carrierServicePointId'])) {
            $relayNote .= ' [code transporteur: ' . trim((string) $servicePoint['carrierServicePointId']) . ']';
        }
        $notes[] = $relayNote;
    }

    return implode(' | ', $notes);
}

function castaneas_sucrine_delivery_point_candidates(array $order, array $config) {
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $servicePoint = is_array($shipping['servicePoint'] ?? null) ? $shipping['servicePoint'] : [];
    $type = trim((string) ($shipping['type'] ?? ''));
    $candidates = castaneas_sucrine_configured_delivery_points($order, $config);

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
    $deliveryAddress = castaneas_sucrine_delivery_address($order, $billing);
    $invoiceAddress = castaneas_sucrine_address_payload($billing);
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $shippingLabel = castaneas_sucrine_delivery_description($order);
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
        'deliveryAddress' => $deliveryAddress,
        'invoicingAddress' => $invoiceAddress,
        'message' => castaneas_sucrine_message($order, $billing),
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
        $lookupKeys = castaneas_sucrine_shipping_lookup_keys($order);
        return [
            'ok' => false,
            'code' => 'sucrine_missing_delivery_point',
            'message' => 'Mode de distribution Sucrine manquant. Configurez sucrine.delivery_point ou les variantes home/relay.',
            'lookupKeys' => $lookupKeys,
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
            'payload' => $payload,
            'raw' => $decoded ?: $response,
        ];
    }

    $last = end($attemptErrors) ?: null;
    return [
        'ok' => false,
        'code' => 'sucrine_http_error',
        'status' => $last['status'] ?? 500,
        'message' => ($last['message'] ?? 'Erreur API Sucrine.') . ' | deliveryPoint essayé: ' . implode(', ', $deliveryPointCandidates),
        'lookupKeys' => castaneas_sucrine_shipping_lookup_keys($order),
        'raw' => $attemptErrors,
    ];
}