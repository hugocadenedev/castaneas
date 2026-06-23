<?php

require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/order-store.php';
require_once __DIR__ . '/storage.php';

header('Content-Type: application/json; charset=utf-8');

function castaneas_checkout_response($status, array $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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

function castaneas_checkout_normalize_product_shipping(array $product) {
    $shipping = is_array($product['shipping'] ?? null) ? $product['shipping'] : [];

    return [
        'weightG' => max(0, (int) ($shipping['weightG'] ?? 0)),
        'lengthCm' => max(0.0, (float) ($shipping['lengthCm'] ?? 0)),
        'widthCm' => max(0.0, (float) ($shipping['widthCm'] ?? 0)),
        'heightCm' => max(0.0, (float) ($shipping['heightCm'] ?? 0)),
    ];
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
            'shipping' => castaneas_checkout_normalize_product_shipping($product),
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
    $totalWeightG = 0;
    $maxLength = 0.0;
    $maxWidth = 0.0;
    $maxHeight = 0.0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $shipping = is_array($item['shipping'] ?? null) ? $item['shipping'] : [];
        $bundleQty = max(1, (int) ($item['offerQty'] ?? 1));
        $lineQty = max(1, (int) ($item['qty'] ?? 1)) * $bundleQty;

        $weightG = max(0, (int) ($shipping['weightG'] ?? 0));
        $totalWeightG += $weightG * $lineQty;

        $maxLength = max($maxLength, (float) ($shipping['lengthCm'] ?? 0));
        $maxWidth = max($maxWidth, (float) ($shipping['widthCm'] ?? 0));
        $maxHeight = max($maxHeight, (float) ($shipping['heightCm'] ?? 0));
    }

    if ($totalWeightG <= 0) {
        $totalWeightG = 250;
    }

    $selectedPackaging = null;
    foreach (castaneas_checkout_packagings() as $packaging) {
        $shipping = $packaging['shipping'] ?? [];
        $fitsWeight = (int) ($shipping['maxWeightG'] ?? 0) <= 0 || $totalWeightG <= (int) $shipping['maxWeightG'];
        $fitsSize = ((float) ($shipping['lengthCm'] ?? 0) <= 0 || (float) $shipping['lengthCm'] >= $maxLength)
            && ((float) ($shipping['widthCm'] ?? 0) <= 0 || (float) $shipping['widthCm'] >= $maxWidth)
            && ((float) ($shipping['heightCm'] ?? 0) <= 0 || (float) $shipping['heightCm'] >= $maxHeight);
        if ($fitsWeight && $fitsSize) {
            $selectedPackaging = $packaging;
            break;
        }
    }

    if ($selectedPackaging === null) {
        $allPackagings = castaneas_checkout_packagings();
        $selectedPackaging = $allPackagings ? end($allPackagings) : null;
    }

    $packagingShipping = is_array($selectedPackaging['shipping'] ?? null) ? $selectedPackaging['shipping'] : [];
    $parcel = [
        'weight' => [
            'value' => number_format(max(0.05, ($totalWeightG + (int) ($packagingShipping['tareWeightG'] ?? 0)) / 1000), 3, '.', ''),
            'unit' => 'kg',
        ],
        'dimensions' => [
            'length' => number_format(max(10.0, (float) ($packagingShipping['lengthCm'] ?? $maxLength ?: 10)), 1, '.', ''),
            'width' => number_format(max(8.0, (float) ($packagingShipping['widthCm'] ?? $maxWidth ?: 8)), 1, '.', ''),
            'height' => number_format(max(4.0, (float) ($packagingShipping['heightCm'] ?? $maxHeight ?: 4)), 1, '.', ''),
            'unit' => 'cm',
        ],
    ];

    return [
        'packaging' => $selectedPackaging,
        'productWeightG' => $totalWeightG,
        'parcel' => $parcel,
        'parcels' => [$parcel],
    ];
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
    foreach (($response['data']['data'] ?? []) as $option) {
        if (!is_array($option)) {
            continue;
        }

        $lastMile = (string) ($option['functionalities']['last_mile'] ?? '');
        if ($lastMile === 'home_delivery') {
            $presented = castaneas_checkout_present_shipping_option($option, 'home');
            if ($presented) {
                $home[] = $presented;
            }
        }
        if ($lastMile === 'service_point') {
            $presented = castaneas_checkout_present_shipping_option($option, 'relay');
            if ($presented) {
                $relay[] = $presented;
            }
        }
    }

    usort($home, static function ($left, $right) { return $left['price'] <=> $right['price']; });
    usort($relay, static function ($left, $right) { return $left['price'] <=> $right['price']; });

    return [
        'ok' => true,
        'shipment' => $shipment,
        'home' => array_slice($home, 0, 2),
        'relay' => array_slice($relay, 0, 2),
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

function castaneas_checkout_payment_payload(array $order, array $options = []) {
    if (!empty($options['force_simulate']) || castaneas_payment_simulate()) {
        return [
            'mode' => 'redirect',
            'url' => castaneas_url('payment-return.php?status=paid&ref=' . rawurlencode($order['id']) . '&simulate=1'),
        ];
    }

    if (!castaneas_up2pay_is_ready()) {
        return null;
    }

    $config = castaneas_up2pay_config();
    $time = gmdate('c');
    $fields = [
        'PBX_SITE' => $config['site'],
        'PBX_RANG' => $config['rang'],
        'PBX_IDENTIFIANT' => $config['identifiant'],
        'PBX_TOTAL' => (string) round(((float) $order['total']) * 100),
        'PBX_DEVISE' => $config['currency'],
        'PBX_CMD' => $order['id'],
        'PBX_PORTEUR' => $order['email'],
        'PBX_RETOUR' => 'Mt:M;Ref:R;Auto:A;Erreur:E;Trans:T',
        'PBX_HASH' => strtoupper($config['hash_algo']),
        'PBX_TIME' => $time,
        'PBX_EFFECTUE' => castaneas_url('payment-return.php?status=paid&ref=' . rawurlencode($order['id'])),
        'PBX_REFUSE' => castaneas_url('payment-return.php?status=refused&ref=' . rawurlencode($order['id'])),
        'PBX_ANNULE' => castaneas_url('payment-return.php?status=cancelled&ref=' . rawurlencode($order['id'])),
        'PBX_REPONDRE_A' => castaneas_url('payment-notify.php'),
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

$paymentMethod = trim((string) ($body['payment'] ?? 'card'));
if ($paymentMethod !== 'card') {
    castaneas_checkout_response(400, ['ok' => false, 'error' => 'Mode de paiement non supporté.']);
}

$total = castaneas_checkout_total($items);
$shipping = is_array($body['shipping'] ?? null) ? $body['shipping'] : [];
$forceSimulatePayment = !empty($body['bypassPayment']) || !empty($body['forceSimulatePayment']);
$shippingPrice = round((float) ($shipping['price'] ?? 0), 2);
$selectedServicePoint = is_array($shipping['servicePoint'] ?? null) ? $shipping['servicePoint'] : null;
$selectedShipping = [
    'type' => trim((string) ($shipping['type'] ?? '')),
    'code' => trim((string) ($shipping['code'] ?? '')),
    'name' => trim((string) ($shipping['name'] ?? '')),
    'carrier' => is_array($shipping['carrier'] ?? null) ? $shipping['carrier'] : [],
    'product' => is_array($shipping['product'] ?? null) ? $shipping['product'] : [],
    'selectedFunctionalities' => is_array($shipping['selectedFunctionalities'] ?? null) ? $shipping['selectedFunctionalities'] : [],
    'price' => $shippingPrice,
    'servicePoint' => $selectedServicePoint,
];

$grandTotal = round($total + $shippingPrice, 2);
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
    'total' => $grandTotal,
    'status' => 'pending_payment',
    'billing' => $billing,
    'shipping' => $selectedShipping,
    'payment' => [
        'method' => $paymentMethod,
        'gateway' => $forceSimulatePayment ? 'simulation' : 'up2pay',
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
        'error' => 'Paiement Crédit Agricole non configuré.',
        'code' => 'up2pay_not_configured',
    ]);
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