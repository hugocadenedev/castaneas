<?php

require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/order-store.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/shipping-lib.php';
require_once __DIR__ . '/paypal-lib.php';

header('Content-Type: application/json; charset=utf-8');

function castaneas_checkout_response($status, array $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function castaneas_checkout_require_storage(array $keys) {
    $failures = [];

    foreach ($keys as $key) {
        $status = castaneas_storage_key_status($key);
        if ($status['error'] !== null) {
            $failures[] = [
                'key' => $key,
                'error' => $status['error'],
            ];
        }
    }

    if ($failures) {
        castaneas_checkout_response(503, [
            'ok' => false,
            'error' => 'MySQL storage unavailable for checkout.',
            'code' => 'storage_unavailable',
            'details' => $failures,
        ]);
    }
}

function castaneas_checkout_json_body() {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

function castaneas_checkout_products_index() {
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

function castaneas_checkout_packagings() {
    static $packagings = null;
    if ($packagings !== null) {
        return $packagings;
    }

    $packagings = [];
    $raw = castaneas_storage_read_raw('packagings');
    if ($raw === null) {
        return $packagings;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $packagings;
    }

    foreach ($decoded as $index => $packaging) {
        if (!is_array($packaging)) {
            continue;
        }

        $shipping = is_array($packaging['shipping'] ?? null) ? $packaging['shipping'] : $packaging;
        $packagings[] = [
            'id' => (string) ($packaging['id'] ?? ('pkg-' . ($index + 1))),
            'name' => (string) ($packaging['name'] ?? ''),
            'code' => (string) ($packaging['code'] ?? ''),
            'shipping' => [
                'lengthCm' => max(0.0, (float) ($shipping['lengthCm'] ?? 0)),
                'widthCm' => max(0.0, (float) ($shipping['widthCm'] ?? 0)),
                'heightCm' => max(0.0, (float) ($shipping['heightCm'] ?? 0)),
                'tareWeightG' => max(0, (int) ($shipping['tareWeightG'] ?? 0)),
                'maxWeightG' => max(0, (int) ($shipping['maxWeightG'] ?? 0)),
            ],
        ];
    }

    usort($packagings, static function ($left, $right) {
        $leftMax = (int) ($left['shipping']['maxWeightG'] ?? 0);
        $rightMax = (int) ($right['shipping']['maxWeightG'] ?? 0);
        if ($leftMax !== $rightMax) {
            return $leftMax <=> $rightMax;
        }

        $leftVolume = ((float) ($left['shipping']['lengthCm'] ?? 0)) * ((float) ($left['shipping']['widthCm'] ?? 0)) * ((float) ($left['shipping']['heightCm'] ?? 0));
        $rightVolume = ((float) ($right['shipping']['lengthCm'] ?? 0)) * ((float) ($right['shipping']['widthCm'] ?? 0)) * ((float) ($right['shipping']['heightCm'] ?? 0));

        return $leftVolume <=> $rightVolume;
    });

    return $packagings;
}

function castaneas_checkout_product_offer_qty(array $product, $offerId) {
    $offerId = trim((string) $offerId);
    if ($offerId === '') {
        return 1;
    }

    foreach (($product['quantityOffers'] ?? []) as $index => $offer) {
        if (!is_array($offer)) {
            continue;
        }

        $candidateId = (string) ($product['id'] ?? '') . '__pack_' . $index;
        if ($candidateId === $offerId) {
            return max(1, (int) ($offer['qty'] ?? 1));
        }
    }

    if ($offerId === ((string) ($product['id'] ?? '') . '__coffret')) {
        return 1;
    }

    return 1;
}

function castaneas_checkout_parse_weight_g($rawWeight) {
    $rawWeight = trim((string) $rawWeight);
    if ($rawWeight === '') {
        return 0;
    }

    $normalized = mb_strtolower(str_replace(',', '.', $rawWeight));
    if (preg_match('/(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)/u', $normalized, $matches)) {
        $value = (float) $matches[1] * (float) $matches[2];
    } elseif (preg_match('/(\d+(?:\.\d+)?)/', $normalized, $matches)) {
        $value = (float) $matches[1];
    } else {
        return 0;
    }

    if (strpos($normalized, 'kg') !== false) {
        return (int) round($value * 1000);
    }

    return (int) round($value);
}

function castaneas_checkout_normalize_product_shipping(array $product) {
    $shipping = is_array($product['shipping'] ?? null) ? $product['shipping'] : [];

    return [
        'weightG' => max(0, (int) ($shipping['weightG'] ?? 0)),
        'lengthCm' => max(0.0, (float) ($shipping['lengthCm'] ?? 0)),
        'widthCm' => max(0.0, (float) ($shipping['widthCm'] ?? 0)),
        'heightCm' => max(0.0, (float) ($shipping['heightCm'] ?? 0)),
    ];
}

function castaneas_checkout_resolve_product_shipping(array $product, array $products, array $seen = []) {
    $productId = (string) ($product['id'] ?? '');
    if ($productId !== '') {
        if (isset($seen[$productId])) {
            return [
                'weightG' => 0,
                'lengthCm' => 0.0,
                'widthCm' => 0.0,
                'heightCm' => 0.0,
            ];
        }

        $seen[$productId] = true;
    }

    $resolved = castaneas_checkout_normalize_product_shipping($product);
    if ($resolved['weightG'] > 0 && $resolved['lengthCm'] > 0 && $resolved['widthCm'] > 0 && $resolved['heightCm'] > 0) {
        return $resolved;
    }

    $boxItems = is_array($product['boxItems'] ?? null) ? $product['boxItems'] : [];
    foreach ($boxItems as $boxItem) {
        if (!is_array($boxItem) || empty($boxItem['productId'])) {
            continue;
        }

        $child = $products[(string) $boxItem['productId']] ?? null;
        if (!is_array($child)) {
            continue;
        }

        $childShipping = castaneas_checkout_resolve_product_shipping($child, $products, $seen);
        $resolved['weightG'] += max(0, (int) ($childShipping['weightG'] ?? 0));
        $resolved['lengthCm'] = max($resolved['lengthCm'], (float) ($childShipping['lengthCm'] ?? 0));
        $resolved['widthCm'] = max($resolved['widthCm'], (float) ($childShipping['widthCm'] ?? 0));
        $resolved['heightCm'] += max(0.0, (float) ($childShipping['heightCm'] ?? 0));
    }

    if ($resolved['weightG'] <= 0) {
        $resolved['weightG'] = castaneas_checkout_parse_weight_g($product['weight'] ?? '');
    }

    return $resolved;
}

function castaneas_checkout_resolve_item_shipping(array $item, array $product, array $products) {
    $resolved = is_array($product)
        ? castaneas_checkout_resolve_product_shipping($product, $products)
        : ['weightG' => 0, 'lengthCm' => 0.0, 'widthCm' => 0.0, 'heightCm' => 0.0];

    if ($resolved['weightG'] <= 0) {
        foreach ([$item['weight'] ?? '', $item['variant'] ?? ''] as $rawWeight) {
            $parsedWeight = castaneas_checkout_parse_weight_g($rawWeight);
            if ($parsedWeight > 0) {
                $resolved['weightG'] = $parsedWeight;
                break;
            }
        }
    }

    return $resolved;
}

function castaneas_checkout_normalize_items(array $items) {
    $products = castaneas_checkout_products_index();
    $normalized = [];

    foreach ($items as $item) {
        if (!is_array($item) || empty($item['id'])) {
            continue;
        }

        $qty = (int) ($item['qty'] ?? 0);
        $price = round((float) ($item['price'] ?? 0), 2);
        if ($qty <= 0 || $price < 0) {
            continue;
        }

        $product = $products[$item['id']] ?? [];
        $offerId = isset($item['offerId']) ? (string) $item['offerId'] : '';
        $normalized[] = [
            'id' => (string) $item['id'],
            'name' => (string) ($item['name'] ?? ($product['name'] ?? 'Produit')),
            'qty' => $qty,
            'price' => $price,
            'image' => (string) ($item['image'] ?? ($product['image'] ?? 'assets/product-pate-tartiner.png')),
            'variant' => isset($item['variant']) ? (string) $item['variant'] : '',
            'weight' => isset($item['weight']) ? (string) $item['weight'] : (string) ($product['weight'] ?? ''),
            'offerId' => $offerId,
            'offerQty' => castaneas_checkout_product_offer_qty($product, $offerId),
            'shipping' => castaneas_checkout_resolve_item_shipping($item, $product, $products),
            'sucrineId' => $product['sucrineId'] ?? null,
        ];
    }

    return $normalized;
}

function castaneas_checkout_split_address($rawAddress) {
    $rawAddress = trim((string) $rawAddress);
    if ($rawAddress === '') {
        return ['street' => '', 'house_number' => ''];
    }

    if (preg_match('/^(\d+[\w\/-]*)\s+(.*)$/u', $rawAddress, $matches)) {
        return [
            'street' => trim($matches[2]),
            'house_number' => trim($matches[1]),
        ];
    }

    if (preg_match('/^(.*?)[,\s]+(\d+[\w\/-]*)$/u', $rawAddress, $matches)) {
        return [
            'street' => trim($matches[1]),
            'house_number' => trim($matches[2]),
        ];
    }

    return ['street' => $rawAddress, 'house_number' => ''];
}

function castaneas_checkout_address_payload(array $billing) {
    $split = castaneas_checkout_split_address(($billing['adresse'] ?? '') . ' ' . ($billing['complement'] ?? ''));

    $addressLine = trim(implode(' ', array_filter([$split['street'], $split['house_number']])));
    if ($addressLine === '') {
        $addressLine = trim((string) ($billing['adresse'] ?? ''));
    }

    return [
        'country_code' => strtoupper(trim((string) ($billing['pays'] ?? 'FR'))),
        'postal_code' => trim((string) ($billing['cp'] ?? '')),
        'city' => trim((string) ($billing['ville'] ?? '')),
        'address_line_1' => $addressLine,
        'state_province_code' => trim((string) ($billing['state_province_code'] ?? '')),
    ];
}

function castaneas_checkout_estimate_shipment(array $items) {
    return castaneas_shipping_estimate_items($items);
}

function castaneas_sendcloud_v3_base_url() {
    $config = castaneas_sendcloud_config();
    $base = trim((string) ($config['base_url'] ?? ''));
    if ($base === '') {
        return 'https://panel.sendcloud.sc/api/v3';
    }

    if (strpos($base, '/api/v2') !== false) {
        return preg_replace('#/api/v2/?$#', '/api/v3', rtrim($base, '/'));
    }

    if (strpos($base, '/api/v3') !== false) {
        return rtrim($base, '/');
    }

    return rtrim($base, '/') . '/api/v3';
}

function castaneas_sendcloud_v3_request($method, $path, array $payload = null, array $query = []) {
    $config = castaneas_sendcloud_config();
    $url = rtrim(castaneas_sendcloud_v3_base_url(), '/') . '/' . ltrim($path, '/');
    if ($query) {
        $parts = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $item);
                }
                continue;
            }

            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
        $url .= '?' . implode('&', $parts);
    }

    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERPWD => $config['public_key'] . ':' . $config['secret_key'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => 25,
    ];

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    if (!empty($config['ca_bundle'])) {
        $options[CURLOPT_CAINFO] = $config['ca_bundle'];
    }
    if (!empty($config['skip_ssl_verify'])) {
        $options[CURLOPT_SSL_VERIFYPEER] = false;
        $options[CURLOPT_SSL_VERIFYHOST] = 0;
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'status' => 0, 'error' => $error ?: 'Erreur réseau Sendcloud.'];
    }

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $message = 'Erreur API Sendcloud.';
        if (is_array($decoded) && !empty($decoded['errors'][0]['detail'])) {
            $message = (string) $decoded['errors'][0]['detail'];
        }

        return ['ok' => false, 'status' => $status, 'error' => $message, 'raw' => $decoded ?: $response];
    }

    return ['ok' => true, 'status' => $status, 'data' => is_array($decoded) ? $decoded : []];
}

function castaneas_checkout_sender_address() {
    static $sender = null;
    if ($sender !== null) {
        return $sender;
    }

    $configured = trim((string) (castaneas_sendcloud_config()['sender_address'] ?? ''));
    $response = castaneas_sendcloud_v3_request('GET', 'addresses/sender-addresses', null, ['page_size' => 100]);
    if (!$response['ok']) {
        return null;
    }

    $sender = null;
    foreach (($response['data']['data'] ?? []) as $address) {
        if (!is_array($address)) {
            continue;
        }

        $idMatches = $configured !== '' && (string) ($address['id'] ?? '') === $configured;
        $isActive = !array_key_exists('is_active', $address) || !empty($address['is_active']);
        if ($idMatches || ($sender === null && $isActive)) {
            $sender = [
                'id' => $address['id'] ?? null,
                'country_code' => strtoupper(trim((string) ($address['country_code'] ?? 'FR'))),
                'postal_code' => trim((string) ($address['postal_code'] ?? '')),
                'city' => trim((string) ($address['city'] ?? '')),
                'address_line_1' => trim(implode(' ', array_filter([
                    (string) ($address['address_line_1'] ?? ''),
                    (string) ($address['house_number'] ?? ''),
                ]))),
                'state_province_code' => trim((string) ($address['state_province_code'] ?? '')),
            ];
            if ($idMatches) {
                break;
            }
        }
    }

    return $sender;
}

function castaneas_checkout_quote_amount(array $option) {
    $quotes = $option['quotes'] ?? [];
    if (!is_array($quotes) || empty($quotes[0]['price']['total']['value'])) {
        return null;
    }

    return round((float) $quotes[0]['price']['total']['value'], 2);
}

function castaneas_checkout_selected_functionalities(array $option) {
    $selected = [];
    $functionalities = is_array($option['functionalities'] ?? null) ? $option['functionalities'] : [];

    foreach ($functionalities as $key => $value) {
        if (!is_scalar($value) || $value === null || $value === false || $value === '') {
            continue;
        }

        $selected[(string) $key] = $value;
    }

    return $selected;
}

function castaneas_checkout_shipping_option_token($value) {
    $value = mb_strtolower(trim((string) $value), 'UTF-8');
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = strtolower((string) $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

    return trim((string) $value);
}

function castaneas_checkout_shipping_preferences() {
    static $preferences = null;
    if ($preferences !== null) {
        return $preferences;
    }

    $preferences = [
        [
            'type' => 'relay',
            'contains' => ['mondial', 'relay', 'locker'],
            'label' => 'Mondial Relay Locker',
            'priority' => 10,
        ],
        [
            'type' => 'relay',
            'contains' => ['chrono', 'shop2shop'],
            'label' => 'Chronopost',
            'priority' => 20,
        ],
        [
            'type' => 'relay',
            'contains' => ['mondial', 'relay', 'point', 'relais'],
            'label' => 'Mondial Relay',
            'priority' => 30,
        ],
        [
            'type' => 'home',
            'contains' => ['mondial', 'relay', 'home'],
            'label' => 'Mondial Relay',
            'priority' => 10,
        ],
        [
            'type' => 'home',
            'contains' => ['colissimo', 'home'],
            'label' => 'Colissimo',
            'priority' => 20,
        ],
    ];

    return $preferences;
}

function castaneas_checkout_shipping_preference_match(array $option, $type) {
    $haystack = implode(' ', array_filter([
        castaneas_checkout_shipping_option_token($option['code'] ?? ''),
        castaneas_checkout_shipping_option_token($option['name'] ?? ''),
        castaneas_checkout_shipping_option_token($option['carrier']['code'] ?? ''),
        castaneas_checkout_shipping_option_token($option['carrier']['name'] ?? ''),
        castaneas_checkout_shipping_option_token($option['product']['code'] ?? ''),
        castaneas_checkout_shipping_option_token($option['product']['name'] ?? ''),
    ]));

    foreach (castaneas_checkout_shipping_preferences() as $preference) {
        if (($preference['type'] ?? '') !== $type) {
            continue;
        }

        $matches = true;
        foreach (($preference['contains'] ?? []) as $token) {
            if (strpos($haystack, castaneas_checkout_shipping_option_token($token)) === false) {
                $matches = false;
                break;
            }
        }

        if ($matches) {
            return $preference;
        }
    }

    return null;
}

function castaneas_checkout_curate_presented_option(array $presented, array $rawOption, $type) {
    $preference = castaneas_checkout_shipping_preference_match($rawOption, $type);
    if ($preference === null) {
        $presented['preferred'] = false;
        $presented['displayPriority'] = 999;
        return $presented;
    }

    if (!empty($preference['label'])) {
        $presented['name'] = (string) $preference['label'];
    }
    $presented['preferred'] = true;
    $presented['displayPriority'] = (int) ($preference['priority'] ?? 999);

    return $presented;
}

function castaneas_checkout_finalize_presented_options(array $options) {
    if (!$options) {
        return [];
    }

    $preferred = array_values(array_filter($options, static function ($option) {
        return !empty($option['preferred']);
    }));

    if (!$preferred) {
        return [];
    }

    $pool = $preferred;
    usort($pool, static function ($left, $right) {
        $leftPriority = (int) ($left['displayPriority'] ?? 999);
        $rightPriority = (int) ($right['displayPriority'] ?? 999);
        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        return ((float) ($left['price'] ?? 0)) <=> ((float) ($right['price'] ?? 0));
    });

    return array_slice(array_values($pool), 0, 2);
}

function castaneas_checkout_option_is_usable(array $option) {
    $directContractOnly = !empty($option['functionalities']['direct_contract_only']);
    if (!$directContractOnly) {
        return true;
    }

    return !empty($option['contract']['id']);
}

function castaneas_checkout_present_shipping_option(array $option, $type) {
    $price = castaneas_checkout_quote_amount($option);
    if ($price === null) {
        return null;
    }

    return [
        'type' => $type,
        'code' => (string) ($option['code'] ?? ''),
        'name' => (string) ($option['name'] ?? 'Livraison'),
        'carrier' => [
            'code' => (string) ($option['carrier']['code'] ?? ''),
            'name' => (string) ($option['carrier']['name'] ?? ''),
        ],
        'contract' => [
            'id' => isset($option['contract']['id']) ? (int) $option['contract']['id'] : null,
            'carrierCode' => (string) ($option['contract']['carrier_code'] ?? ''),
            'name' => (string) ($option['contract']['name'] ?? ''),
        ],
        'product' => [
            'code' => (string) ($option['product']['code'] ?? ''),
            'name' => (string) ($option['product']['name'] ?? ''),
        ],
        'selectedFunctionalities' => castaneas_checkout_selected_functionalities($option),
        'price' => $price,
        'currency' => (string) ($option['quotes'][0]['price']['total']['currency'] ?? 'EUR'),
        'leadTimeHours' => isset($option['quotes'][0]['lead_time']) ? (int) $option['quotes'][0]['lead_time'] : null,
        'requiresServicePoint' => !empty($option['requirements']['is_service_point_required']),
        'requirements' => array_values(array_filter($option['requirements']['fields'] ?? [], 'is_string')),
        'lastMile' => (string) ($option['functionalities']['last_mile'] ?? ''),
        'directContractOnly' => !empty($option['functionalities']['direct_contract_only']),
    ];
}

function castaneas_checkout_shipping_options(array $items, array $billing) {
    $senderAddress = castaneas_checkout_sender_address();
    if ($senderAddress === null) {
        return ['ok' => false, 'error' => 'Adresse expéditeur Sendcloud introuvable.'];
    }

    $shipment = castaneas_checkout_estimate_shipment($items);
    $response = castaneas_sendcloud_v3_request('POST', 'shipping-options', [
        'from_address' => $senderAddress,
        'to_address' => castaneas_checkout_address_payload($billing),
        'parcels' => $shipment['parcels'],
        'calculate_quotes' => true,
    ]);

    if (!$response['ok']) {
        return ['ok' => false, 'error' => $response['error'] ?? 'Impossible de récupérer les options de livraison.'];
    }

    $home = [];
    $relay = [];
    $skippedDirectContractOnly = 0;
    foreach (($response['data']['data'] ?? []) as $option) {
        if (!is_array($option)) {
            continue;
        }
        if (!castaneas_checkout_option_is_usable($option)) {
            $skippedDirectContractOnly++;
            continue;
        }

        $lastMile = (string) ($option['functionalities']['last_mile'] ?? '');
        if ($lastMile === 'home_delivery') {
            $presented = castaneas_checkout_present_shipping_option($option, 'home');
            if ($presented) {
                $home[] = castaneas_checkout_curate_presented_option($presented, $option, 'home');
            }
        }
        if ($lastMile === 'service_point') {
            $presented = castaneas_checkout_present_shipping_option($option, 'relay');
            if ($presented) {
                $relay[] = castaneas_checkout_curate_presented_option($presented, $option, 'relay');
            }
        }
    }

    $home = castaneas_checkout_finalize_presented_options($home);
    $relay = castaneas_checkout_finalize_presented_options($relay);

    if (!$home && !$relay && $skippedDirectContractOnly > 0) {
        return [
            'ok' => false,
            'error' => 'Les options de livraison retournées par Sendcloud exigent un contrat transporteur direct non configuré pour ce compte.',
            'code' => 'sendcloud_direct_contract_required',
        ];
    }

    return [
        'ok' => true,
        'shipment' => $shipment,
        'home' => $home,
        'relay' => $relay,
    ];
}

function castaneas_checkout_service_points(array $billing, array $carrierCodes) {
    $carrierCodes = array_values(array_filter(array_map(static function ($value) {
        return trim((string) $value);
    }, $carrierCodes)));
    if (!$carrierCodes) {
        return ['ok' => false, 'error' => 'Transporteur relais manquant.'];
    }

    $split = castaneas_checkout_split_address((string) ($billing['adresse'] ?? ''));
    $query = [
        'country_code' => strtoupper(trim((string) ($billing['pays'] ?? 'FR'))),
        'address_street' => $split['street'],
        'address_house_number' => $split['house_number'],
        'address_postal_code' => trim((string) ($billing['cp'] ?? '')),
        'address_city' => trim((string) ($billing['ville'] ?? '')),
        'limit' => 8,
    ];
    foreach ($carrierCodes as $carrierCode) {
        $query['carrier_code'][] = $carrierCode;
    }

    $response = castaneas_sendcloud_v3_request('GET', 'service-points', null, $query);
    if ($response['ok'] && (($response['data']['data']['geocoding']['status'] ?? '') === 'not_found')) {
        unset($query['address_street'], $query['address_house_number']);
        $fallbackResponse = castaneas_sendcloud_v3_request('GET', 'service-points', null, $query);
        if ($fallbackResponse['ok']) {
            $response = $fallbackResponse;
        }
    }
    if (!$response['ok']) {
        return ['ok' => false, 'error' => $response['error'] ?? 'Impossible de récupérer les points relais.'];
    }

    $results = [];
    foreach (($response['data']['data']['results'] ?? []) as $servicePoint) {
        if (!is_array($servicePoint)) {
            continue;
        }

        $results[] = [
            'id' => (string) ($servicePoint['id'] ?? ''),
            'name' => (string) ($servicePoint['name'] ?? 'Point relais'),
            'carrier' => [
                'code' => (string) ($servicePoint['carrier']['code'] ?? ''),
                'name' => (string) ($servicePoint['carrier']['name'] ?? ''),
                'iconUrl' => (string) ($servicePoint['carrier']['icon_url'] ?? ''),
            ],
            'carrierServicePointId' => (string) ($servicePoint['carrier_service_point_id'] ?? ''),
            'address' => [
                'street' => (string) ($servicePoint['address']['street'] ?? ''),
                'houseNumber' => (string) ($servicePoint['address']['house_number'] ?? ''),
                'postalCode' => (string) ($servicePoint['address']['postal_code'] ?? ''),
                'city' => (string) ($servicePoint['address']['city'] ?? ''),
                'countryCode' => (string) ($servicePoint['address']['country_code'] ?? ''),
            ],
            'distanceMeters' => isset($servicePoint['distance']) ? (int) $servicePoint['distance'] : null,
            'isOpenTomorrow' => !empty($servicePoint['is_open_tomorrow']),
            'nextOpenAt' => $servicePoint['next_open_at'] ?? null,
        ];
    }

    return [
        'ok' => true,
        'results' => $results,
        'geocoding' => $response['data']['data']['geocoding'] ?? null,
    ];
}

function castaneas_checkout_total(array $items) {
    $total = 0.0;
    foreach ($items as $item) {
        $total += ((float) ($item['price'] ?? 0)) * ((int) ($item['qty'] ?? 0));
    }

    return round($total, 2);
}

function castaneas_up2pay_hmac_key($value) {
    $value = trim((string) $value);
    if ($value !== '' && ctype_xdigit($value) && strlen($value) % 2 === 0) {
        $bin = @hex2bin($value);
        if ($bin !== false) {
            return $bin;
        }
    }

    return $value;
}

function castaneas_up2pay_signature(array $fields, $algo, $key) {
    $pairs = [];
    foreach ($fields as $field => $value) {
        $pairs[] = $field . '=' . $value;
    }

    return strtoupper(hash_hmac(strtolower($algo), implode('&', $pairs), castaneas_up2pay_hmac_key($key)));
}

function castaneas_up2pay_xml_escape($value) {
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function castaneas_up2pay_limit($value, $maxLength) {
    $value = trim((string) $value);
    if ($maxLength <= 0 || $value === '') {
        return $value;
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return substr($value, 0, $maxLength);
}

function castaneas_up2pay_country_numeric_code($value) {
    $value = strtoupper(trim((string) $value));
    if ($value === '') {
        return '250';
    }

    if (ctype_digit($value)) {
        return str_pad(substr($value, 0, 3), 3, '0', STR_PAD_LEFT);
    }

    $map = [
        'FR' => '250',
        'FRA' => '250',
        'BE' => '056',
        'BEL' => '056',
        'CH' => '756',
        'CHE' => '756',
        'DE' => '276',
        'DEU' => '276',
        'ES' => '724',
        'ESP' => '724',
        'GB' => '826',
        'GBR' => '826',
        'IE' => '372',
        'IRL' => '372',
        'IT' => '380',
        'ITA' => '380',
        'LU' => '442',
        'LUX' => '442',
        'MC' => '492',
        'MCO' => '492',
        'NL' => '528',
        'NLD' => '528',
        'PT' => '620',
        'PRT' => '620',
    ];

    return $map[$value] ?? '250';
}

function castaneas_up2pay_phone_parts($value, $country) {
    $value = trim((string) $value);
    if ($value === '') {
        return [null, null];
    }

    $countryCode = '+';
    if (preg_match('/^\+\d{1,3}/', $value, $matches)) {
        $countryCode = $matches[0];
        $digits = preg_replace('/\D+/', '', substr($value, strlen($matches[0])));
    } else {
        $digits = preg_replace('/\D+/', '', $value);
        $countryCode = castaneas_up2pay_country_numeric_code($country) === '250' ? '+33' : null;
    }

    if ($digits === '') {
        return [null, null];
    }

    if ($countryCode === '+33' && strncmp($digits, '33', 2) === 0) {
        $digits = substr($digits, 2);
    }
    if ($countryCode === '+33' && strncmp($digits, '0', 1) === 0) {
        $digits = substr($digits, 1);
    }

    $digits = substr($digits, 0, 10);
    if ($digits === '') {
        return [null, null];
    }

    return [$countryCode, $digits];
}

function castaneas_up2pay_billing_xml(array $order) {
    $billing = is_array($order['billing'] ?? null) ? $order['billing'] : [];
    $country = castaneas_up2pay_country_numeric_code($billing['pays'] ?? 'FR');
    $address1 = trim((string) ($billing['adresse'] ?? ''));
    $address2 = trim((string) ($billing['complement'] ?? ''));
    [$phoneCountryCode, $phoneDigits] = castaneas_up2pay_phone_parts($billing['tel'] ?? '', $billing['pays'] ?? 'FR');

    $xml = [
        '<?xml version="1.0" encoding="utf-8"?>',
        '<Billing>',
        '<Address>',
        '<FirstName>' . castaneas_up2pay_xml_escape(castaneas_up2pay_limit($billing['prenom'] ?? '', 22)) . '</FirstName>',
        '<LastName>' . castaneas_up2pay_xml_escape(castaneas_up2pay_limit($billing['nom'] ?? '', 22)) . '</LastName>',
        '<Address1>' . castaneas_up2pay_xml_escape(castaneas_up2pay_limit($address1, 50)) . '</Address1>',
    ];

    if ($address2 !== '') {
        $xml[] = '<Address2>' . castaneas_up2pay_xml_escape(castaneas_up2pay_limit($address2, 50)) . '</Address2>';
    }

    $xml[] = '<ZipCode>' . castaneas_up2pay_xml_escape(castaneas_up2pay_limit($billing['cp'] ?? '', 16)) . '</ZipCode>';
    $xml[] = '<City>' . castaneas_up2pay_xml_escape(castaneas_up2pay_limit($billing['ville'] ?? '', 50)) . '</City>';
    $xml[] = '<CountryCode>' . $country . '</CountryCode>';

    if ($phoneCountryCode !== null && $phoneDigits !== null) {
        $xml[] = '<MobilePhone>' . castaneas_up2pay_xml_escape($phoneDigits) . '</MobilePhone>';
        $xml[] = '<CountryCodeMobilePhone>' . castaneas_up2pay_xml_escape($phoneCountryCode) . '</CountryCodeMobilePhone>';
    }

    $xml[] = '</Address>';
    $xml[] = '</Billing>';

    return implode('', $xml);
}

function castaneas_up2pay_shopping_cart_xml(array $order) {
    $totalQuantity = 0;
    foreach (($order['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $lineQty = max(1, (int) ($item['qty'] ?? 1)) * max(1, (int) ($item['offerQty'] ?? 1));
        $totalQuantity += $lineQty;
    }

    $totalQuantity = max(1, min(99, $totalQuantity));

    return '<?xml version="1.0" encoding="utf-8"?><shoppingcart><total><totalQuantity>'
        . $totalQuantity
        . '</totalQuantity></total></shoppingcart>';
}

function castaneas_checkout_payment_payload(array $order, array $options = []) {
    if (!empty($options['force_simulate']) || castaneas_payment_simulate()) {
        return [
            'mode' => 'redirect',
            'url' => castaneas_url('payment-return.php?status=paid&ref=' . rawurlencode($order['id']) . '&simulate=1'),
        ];
    }

    $gateway = trim((string) ($order['payment']['gateway'] ?? 'up2pay'));
    if ($gateway === 'paypal') {
        $paypalPayload = castaneas_paypal_checkout_payload($order);
        if ($paypalPayload === null) {
            return null;
        }
        if (empty($paypalPayload['ok'])) {
            return [
                'mode' => 'error',
                'provider' => 'paypal',
                'error' => $paypalPayload['message'] ?? 'Impossible d\'initialiser PayPal.',
                'code' => $paypalPayload['code'] ?? 'paypal_error',
                'details' => $paypalPayload,
            ];
        }

        return [
            'mode' => 'redirect',
            'url' => $paypalPayload['url'],
            'provider' => 'paypal',
            'paypalOrderId' => $paypalPayload['paypalOrderId'] ?? null,
            'payload' => $paypalPayload['payload'] ?? [],
        ];
    }

    if (!castaneas_up2pay_is_ready()) {
        return null;
    }

    $config = castaneas_up2pay_config();
    $notifyUrl = castaneas_url('payment-notify.php');
    if (!empty($config['callback_secret'])) {
        $notifyUrl .= '?token=' . rawurlencode($config['callback_secret']);
    }
    $time = gmdate('c');
    $fields = [
        'PBX_SITE' => $config['site'],
        'PBX_RANG' => $config['rang'],
        'PBX_IDENTIFIANT' => $config['identifiant'],
        'PBX_TOTAL' => (string) round(((float) $order['total']) * 100),
        'PBX_DEVISE' => $config['currency'],
        'PBX_CMD' => $order['id'],
        'PBX_PORTEUR' => $order['email'],
        'PBX_RETOUR' => 'Mt:M;Ref:R;Auto:A;Erreur:E;Trans:T;Auth3DS:F;Garant3DS:G;Enrole3DS:O;Proto3DS:v',
        'PBX_HASH' => strtoupper($config['hash_algo']),
        'PBX_TIME' => $time,
        'PBX_BILLING' => castaneas_up2pay_billing_xml($order),
        'PBX_SHOPPINGCART' => castaneas_up2pay_shopping_cart_xml($order),
        'PBX_EFFECTUE' => castaneas_url('payment-return.php?status=paid&ref=' . rawurlencode($order['id'])),
        'PBX_REFUSE' => castaneas_url('payment-return.php?status=refused&ref=' . rawurlencode($order['id'])),
        'PBX_ANNULE' => castaneas_url('payment-return.php?status=cancelled&ref=' . rawurlencode($order['id'])),
        'PBX_REPONDRE_A' => $notifyUrl,
        'PBX_LANGUE' => $config['language'],
    ];
    $fields['PBX_HMAC'] = castaneas_up2pay_signature($fields, $config['hash_algo'], $config['hmac_key']);

    return [
        'mode' => 'form',
        'action' => $config['gateway_url'],
        'method' => 'POST',
        'fields' => $fields,
    ];
}

$action = $_GET['action'] ?? 'status';

if ($action === 'status') {
    castaneas_checkout_require_storage(['orders']);

    $ref = trim((string) ($_GET['ref'] ?? ''));
    if ($ref === '') {
        castaneas_checkout_response(400, ['ok' => false, 'error' => 'Référence manquante.']);
    }

    $order = castaneas_order_find($ref);
    if (!$order) {
        castaneas_checkout_response(404, ['ok' => false, 'error' => 'Commande introuvable.']);
    }

    castaneas_checkout_response(200, ['ok' => true, 'order' => castaneas_order_public_payload($order)]);
}

if ($action === 'quotes') {
    castaneas_checkout_require_storage(['products']);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        castaneas_checkout_response(405, ['ok' => false, 'error' => 'Méthode non autorisée.']);
    }
    if (!castaneas_sendcloud_is_ready()) {
        castaneas_checkout_response(503, ['ok' => false, 'error' => 'Livraison Sendcloud non configurée.']);
    }

    $body = castaneas_checkout_json_body();
    if ($body === null) {
        castaneas_checkout_response(400, ['ok' => false, 'error' => 'JSON invalide.']);
    }

    $items = castaneas_checkout_normalize_items($body['items'] ?? []);
    $billing = is_array($body['billing'] ?? null) ? $body['billing'] : [];
    if (!$items) {
        castaneas_checkout_response(400, ['ok' => false, 'error' => 'Panier vide.']);
    }

    $quotes = castaneas_checkout_shipping_options($items, $billing);
    if (!$quotes['ok']) {
        castaneas_checkout_response(502, ['ok' => false, 'error' => $quotes['error']]);
    }

    castaneas_checkout_response(200, $quotes);
}

if ($action === 'service_points') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        castaneas_checkout_response(405, ['ok' => false, 'error' => 'Méthode non autorisée.']);
    }
    if (!castaneas_sendcloud_is_ready()) {
        castaneas_checkout_response(503, ['ok' => false, 'error' => 'Livraison Sendcloud non configurée.']);
    }

    $body = castaneas_checkout_json_body();
    if ($body === null) {
        castaneas_checkout_response(400, ['ok' => false, 'error' => 'JSON invalide.']);
    }

    $billing = is_array($body['billing'] ?? null) ? $body['billing'] : [];
    $carrierCodes = is_array($body['carrierCodes'] ?? null) ? $body['carrierCodes'] : [];
    $servicePoints = castaneas_checkout_service_points($billing, $carrierCodes);
    if (!$servicePoints['ok']) {
        castaneas_checkout_response(502, ['ok' => false, 'error' => $servicePoints['error']]);
    }

    castaneas_checkout_response(200, $servicePoints);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    castaneas_checkout_response(405, ['ok' => false, 'error' => 'Méthode non autorisée.']);
}

if ($action !== 'create') {
    castaneas_checkout_response(400, ['ok' => false, 'error' => 'Action invalide.']);
}

castaneas_checkout_require_storage(['products', 'orders']);

$body = castaneas_checkout_json_body();
if ($body === null) {
    castaneas_checkout_response(400, ['ok' => false, 'error' => 'JSON invalide.']);
}

$items = castaneas_checkout_normalize_items($body['items'] ?? []);
if (!$items) {
    castaneas_checkout_response(400, ['ok' => false, 'error' => 'Panier vide.']);
}

$billing = is_array($body['billing'] ?? null) ? $body['billing'] : [];
$required = ['prenom', 'nom', 'email', 'adresse', 'cp', 'ville'];
foreach ($required as $field) {
    if (trim((string) ($billing[$field] ?? '')) === '') {
        castaneas_checkout_response(400, ['ok' => false, 'error' => 'Champ obligatoire manquant: ' . $field]);
    }
}

$paymentMethod = trim((string) ($body['payment'] ?? ''));
if (!in_array($paymentMethod, ['card', 'paypal'], true)) {
    castaneas_checkout_response(400, ['ok' => false, 'error' => 'Mode de paiement non supporté.']);
}

$promo = is_array($body['promo'] ?? null) ? $body['promo'] : [];
$promoCode = strtoupper(trim((string) ($promo['code'] ?? '')));
$promoDiscount = round((float) ($promo['discount'] ?? 0), 2);
$promoDeliveryMode = trim((string) ($promo['deliveryMode'] ?? ''));
$promoPickupLabel = trim((string) ($promo['pickupLabel'] ?? ''));
$promoPickupAddress = trim((string) ($promo['pickupAddress'] ?? ''));

$total = castaneas_checkout_total($items);
$shipping = is_array($body['shipping'] ?? null) ? $body['shipping'] : [];
$forceSimulatePayment = !empty($body['bypassPayment']) || !empty($body['forceSimulatePayment']);
$shippingType = trim((string) ($shipping['type'] ?? ''));
$shippingPrice = $shippingType === 'pickup' ? 0.0 : round((float) ($shipping['price'] ?? 0), 2);
$selectedServicePoint = is_array($shipping['servicePoint'] ?? null) ? $shipping['servicePoint'] : null;
$selectedShipping = [
    'type' => $shippingType,
    'code' => trim((string) ($shipping['code'] ?? '')),
    'name' => trim((string) ($shipping['name'] ?? '')),
    'carrier' => is_array($shipping['carrier'] ?? null) ? $shipping['carrier'] : [],
    'contract' => is_array($shipping['contract'] ?? null) ? $shipping['contract'] : [],
    'product' => is_array($shipping['product'] ?? null) ? $shipping['product'] : [],
    'selectedFunctionalities' => is_array($shipping['selectedFunctionalities'] ?? null) ? $shipping['selectedFunctionalities'] : [],
    'price' => $shippingPrice,
    'servicePoint' => $selectedServicePoint,
    'pickupAddress' => trim((string) ($shipping['pickupAddress'] ?? '')),
];

$grandTotal = round(max(0, $total - $promoDiscount) + $shippingPrice, 2);
$ref = castaneas_order_generate_ref();
$order = [
    'id' => $ref,
    'date' => gmdate('Y-m-d'),
    'createdAt' => gmdate('c'),
    'updatedAt' => gmdate('c'),
    'customer' => trim(($billing['prenom'] ?? '') . ' ' . ($billing['nom'] ?? '')),
    'email' => trim((string) ($billing['email'] ?? '')),
    'items' => $items,
    'subtotal' => $total,
    'discount' => $promoDiscount,
    'promo' => [
        'code' => $promoCode,
        'type' => trim((string) ($promo['type'] ?? '')),
        'label' => trim((string) ($promo['label'] ?? '')),
        'deliveryMode' => $promoDeliveryMode,
        'pickupLabel' => $promoPickupLabel,
        'pickupAddress' => $promoPickupAddress,
        'discount' => $promoDiscount,
    ],
    'total' => $grandTotal,
    'status' => 'pending_payment',
    'fulfillmentStatus' => 'pending',
    'billing' => $billing,
    'shipping' => $selectedShipping,
    'payment' => [
        'method' => $paymentMethod,
        'gateway' => $forceSimulatePayment ? 'simulation' : ($paymentMethod === 'paypal' ? 'paypal' : 'up2pay'),
        'createdAt' => gmdate('c'),
    ],
    'sucrine' => [
        'sentAt' => null,
        'orderId' => null,
    ],
    'sendcloud' => [
        'createdAt' => null,
        'parcelId' => null,
        'trackingNumber' => null,
        'labelUrl' => null,
    ],
];

$paymentPayload = castaneas_checkout_payment_payload($order, ['force_simulate' => $forceSimulatePayment]);
if ($paymentPayload === null) {
    castaneas_checkout_response(503, [
        'ok' => false,
        'error' => $paymentMethod === 'paypal'
            ? 'Paiement PayPal non configure.'
            : 'Paiement Crédit Agricole non configuré.',
        'code' => $paymentMethod === 'paypal' ? 'paypal_not_configured' : 'up2pay_not_configured',
    ]);
}

if (($paymentPayload['mode'] ?? '') === 'error') {
    castaneas_checkout_response(502, [
        'ok' => false,
        'error' => (string) ($paymentPayload['error'] ?? 'Impossible d\'initialiser le paiement.'),
        'code' => (string) ($paymentPayload['code'] ?? 'payment_gateway_error'),
    ]);
}

if (($paymentPayload['provider'] ?? '') === 'paypal' && !empty($paymentPayload['paypalOrderId'])) {
    $order['payment']['paypalOrderId'] = $paymentPayload['paypalOrderId'];
}

$order['payment']['request'] = $paymentPayload;
$savedOrder = castaneas_order_upsert($order);
if (!$savedOrder) {
    castaneas_checkout_response(500, ['ok' => false, 'error' => 'Impossible d’enregistrer la commande.']);
}

castaneas_checkout_response(200, [
    'ok' => true,
    'orderRef' => $ref,
    'payment' => $paymentPayload,
]);