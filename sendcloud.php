<?php

require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/shipping-lib.php';

function castaneas_sendcloud_apply_ssl_options($ch, array $config) {
    if (!empty($config['ca_bundle'])) {
        curl_setopt($ch, CURLOPT_CAINFO, $config['ca_bundle']);
    }
    if (!empty($config['skip_ssl_verify'])) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
}

function castaneas_sendcloud_v2_base_url() {
    $config = castaneas_sendcloud_config();
    $baseUrl = trim((string) ($config['base_url'] ?? ''));
    if ($baseUrl === '') {
        return 'https://panel.sendcloud.sc/api/v2';
    }

    return rtrim($baseUrl, '/');
}

function castaneas_sendcloud_v3_base_url() {
    $config = castaneas_sendcloud_config();
    $baseUrl = trim((string) ($config['base_url'] ?? ''));
    if ($baseUrl === '') {
        return 'https://panel.sendcloud.sc/api/v3';
    }

    if (preg_match('#/api/v2/?$#', $baseUrl)) {
        return preg_replace('#/api/v2/?$#', '/api/v3', rtrim($baseUrl, '/'));
    }

    return rtrim($baseUrl, '/') . '/v3';
}

function castaneas_sendcloud_v2_request($method, $path, array $payload = null, array $headers = []) {
    $config = castaneas_sendcloud_config();
    $url = castaneas_sendcloud_v2_base_url() . '/' . ltrim($path, '/');
    $requestHeaders = array_merge(['Accept: application/json'], $headers);

    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_USERPWD => $config['public_key'] . ':' . $config['secret_key'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => 25,
    ];

    if ($payload !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $requestHeaders;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);
    castaneas_sendcloud_apply_ssl_options($ch, $config);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'code' => 'sendcloud_transport_error',
            'status' => 0,
            'message' => $error ?: 'Erreur réseau Sendcloud.',
        ];
    }

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'code' => 'sendcloud_http_error',
            'status' => $status,
            'message' => is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['message'] ?? 'Erreur API Sendcloud.') : 'Erreur API Sendcloud.',
            'raw' => $decoded ?: $response,
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'contentType' => $contentType,
        'data' => is_array($decoded) ? $decoded : ['raw' => $response],
        'raw' => $response,
    ];
}

function castaneas_sendcloud_v3_query_string(array $params) {
    $pairs = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
    }

    return implode('&', $pairs);
}

function castaneas_sendcloud_v3_request($method, $path, array $payload = null, array $query = [], array $headers = []) {
    $config = castaneas_sendcloud_config();
    $url = castaneas_sendcloud_v3_base_url() . '/' . ltrim($path, '/');
    $queryString = castaneas_sendcloud_v3_query_string($query);
    if ($queryString !== '') {
        $url .= '?' . $queryString;
    }

    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_USERPWD => $config['public_key'] . ':' . $config['secret_key'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => 25,
    ];

    if ($payload !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $requestHeaders;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);
    castaneas_sendcloud_apply_ssl_options($ch, $config);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'code' => 'sendcloud_transport_error',
            'status' => 0,
            'message' => $error ?: 'Erreur réseau Sendcloud.',
        ];
    }

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'code' => 'sendcloud_http_error',
            'status' => $status,
            'message' => castaneas_sendcloud_error_message($decoded) ?: 'Erreur API Sendcloud.',
            'raw' => $decoded ?: $response,
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'contentType' => $contentType,
        'data' => is_array($decoded) ? $decoded : [],
        'raw' => $response,
    ];
}

function castaneas_sendcloud_v3_document_request($parcelId, $type, array $query = [], array $headers = []) {
    $config = castaneas_sendcloud_config();
    $url = castaneas_sendcloud_v3_base_url() . '/parcels/' . rawurlencode((string) $parcelId) . '/documents/' . rawurlencode((string) $type);
    $queryString = castaneas_sendcloud_v3_query_string($query);
    if ($queryString !== '') {
        $url .= '?' . $queryString;
    }

    $requestHeaders = array_merge(['Accept: application/pdf'], $headers);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_USERPWD => $config['public_key'] . ':' . $config['secret_key'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => 25,
    ]);
    castaneas_sendcloud_apply_ssl_options($ch, $config);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'code' => 'sendcloud_transport_error',
            'status' => 0,
            'message' => $error ?: 'Erreur réseau Sendcloud.',
        ];
    }

    if ($status < 200 || $status >= 300) {
        $decoded = json_decode($response, true);
        return [
            'ok' => false,
            'code' => 'sendcloud_http_error',
            'status' => $status,
            'message' => castaneas_sendcloud_error_message($decoded) ?: 'Erreur API Sendcloud.',
            'raw' => $decoded ?: $response,
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'contentType' => $contentType,
        'raw' => $response,
    ];
}

function castaneas_sendcloud_download_file_url($url, $accept = 'application/pdf') {
    $config = castaneas_sendcloud_config();
    $url = trim((string) $url);
    if ($url === '') {
        return [
            'ok' => false,
            'code' => 'sendcloud_missing_label_url',
            'message' => 'URL de document Sendcloud manquante.',
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
        CURLOPT_USERPWD => $config['public_key'] . ':' . $config['secret_key'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => 25,
    ]);
    castaneas_sendcloud_apply_ssl_options($ch, $config);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'code' => 'sendcloud_transport_error',
            'status' => 0,
            'message' => $error ?: 'Erreur réseau Sendcloud.',
        ];
    }

    if ($status < 200 || $status >= 300) {
        $decoded = json_decode($response, true);
        return [
            'ok' => false,
            'code' => 'sendcloud_http_error',
            'status' => $status,
            'message' => castaneas_sendcloud_error_message($decoded) ?: 'Erreur API Sendcloud.',
            'raw' => $decoded ?: $response,
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'contentType' => $contentType,
        'raw' => $response,
    ];
}

function castaneas_sendcloud_error_message($value) {
    if (is_string($value)) {
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    if (!is_array($value)) {
        return null;
    }

    foreach (['message', 'detail', 'error_description', 'title'] as $key) {
        if (!empty($value[$key]) && is_scalar($value[$key])) {
            return trim((string) $value[$key]);
        }
    }

    if (!empty($value['error']) && is_array($value['error'])) {
        $resolved = castaneas_sendcloud_error_message($value['error']);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    if (!empty($value['errors']) && is_array($value['errors'])) {
        foreach ($value['errors'] as $error) {
            $resolved = castaneas_sendcloud_error_message($error);
            if ($resolved !== null) {
                return $resolved;
            }
        }
    }

    return null;
}

function castaneas_sendcloud_extract_parcel(array $data) {
    if (isset($data['parcel']) && is_array($data['parcel'])) {
        $parcel = $data['parcel'];
        if (array_is_list($parcel)) {
            return is_array($parcel[0] ?? null) ? $parcel[0] : null;
        }

        return $parcel;
    }

    if (isset($data['parcels']) && is_array($data['parcels'])) {
        return is_array($data['parcels'][0] ?? null) ? $data['parcels'][0] : null;
    }

    return null;
}

function castaneas_sendcloud_label_url_from_parcel(array $parcel) {
    if (!empty($parcel['documents']) && is_array($parcel['documents'])) {
        foreach ($parcel['documents'] as $document) {
            $documentType = (string) ($document['document_type'] ?? ($document['type'] ?? ''));
            if ($documentType === 'label' && !empty($document['link'])) {
                return $document['link'];
            }
        }
    }

    if (!empty($parcel['label']['label_printer'])) {
        return $parcel['label']['label_printer'];
    }

    if (!empty($parcel['label']['normal_printer'][0])) {
        return $parcel['label']['normal_printer'][0];
    }

    if (!empty($parcel['documents']) && is_array($parcel['documents'])) {
        foreach ($parcel['documents'] as $document) {
            if (($document['type'] ?? '') === 'label' && !empty($document['link'])) {
                return $document['link'];
            }
        }
    }

    return null;
}

function castaneas_sendcloud_sender_address_id() {
    static $senderAddressId = null;
    if ($senderAddressId !== null) {
        return $senderAddressId;
    }

    $configured = trim((string) (castaneas_sendcloud_config()['sender_address'] ?? ''));
    if ($configured !== '' && ctype_digit($configured)) {
        return $senderAddressId = (int) $configured;
    }

    $response = castaneas_sendcloud_v3_request('GET', 'addresses/sender-addresses', null, ['page_size' => 100]);
    if (!$response['ok']) {
        return $senderAddressId = 0;
    }

    foreach (($response['data']['data'] ?? []) as $address) {
        if (!is_array($address)) {
            continue;
        }
        if (empty($address['is_active']) && array_key_exists('is_active', $address)) {
            continue;
        }
        $id = (int) ($address['id'] ?? 0);
        if ($id > 0) {
            return $senderAddressId = $id;
        }
    }

    return $senderAddressId = 0;
}

function castaneas_sendcloud_parcel_dimensions(array $order) {
    $shipment = castaneas_shipping_estimate_order($order);
    $dimensions = $shipment['parcel']['dimensions'] ?? null;

    return is_array($dimensions) ? $dimensions : null;
}

function castaneas_sendcloud_v3_to_address(array $order) {
    $billing = is_array($order['billing'] ?? null) ? $order['billing'] : [];
    $address = castaneas_sendcloud_split_address(($billing['adresse'] ?? '') . ' ' . ($billing['complement'] ?? ''));

    return [
        'name' => trim((string) ($order['customer'] ?? 'Client Castaneas')),
        'address_line_1' => $address['address'],
        'house_number' => $address['house_number'],
        'postal_code' => trim((string) ($billing['cp'] ?? '')),
        'city' => trim((string) ($billing['ville'] ?? '')),
        'country_code' => strtoupper(trim((string) ($billing['pays'] ?? 'FR'))),
        'phone_number' => trim((string) ($billing['tel'] ?? '')),
        'email' => trim((string) ($order['email'] ?? '')),
    ];
}

function castaneas_sendcloud_v3_service_point(array $order) {
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $servicePoint = is_array($shipping['servicePoint'] ?? null) ? $shipping['servicePoint'] : [];
    $carrierServicePointId = trim((string) ($servicePoint['carrierServicePointId'] ?? ''));
    if ($carrierServicePointId !== '') {
        return ['carrier_service_point_id' => $carrierServicePointId];
    }

    $servicePointId = trim((string) ($servicePoint['id'] ?? ''));
    if ($servicePointId !== '') {
        return ['id' => $servicePointId];
    }

    return null;
}

function castaneas_sendcloud_v3_parcels(array $order) {
    $shipment = castaneas_shipping_estimate_order($order);
    $parcel = $shipment['parcel'];
    $parcel['parcel_items'] = array_map(static function (array $item) {
            return [
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'weight' => [
                    'value' => (float) ($item['weight'] ?? 0.001),
                    'unit' => 'kg',
                ],
                'price' => [
                    'value' => number_format((float) ($item['value'] ?? 0), 2, '.', ''),
                    'currency' => 'EUR',
                ],
                'sku' => (string) ($item['sku'] ?? ''),
                'origin_country' => (string) ($item['origin_country'] ?? 'FR'),
            ];
            }, castaneas_sendcloud_parcel_items($order));

    $labelNotes = [];
    $orderReference = trim((string) ($order['id'] ?? ''));
    if ($orderReference !== '') {
        $labelNotes[] = 'Commande ' . $orderReference;
    }

    $billing = is_array($order['billing'] ?? null) ? $order['billing'] : [];
    if (!empty($billing['note'])) {
        $labelNotes[] = trim((string) $billing['note']);
    }

    if ($labelNotes !== []) {
        $parcel['label_notes'] = $labelNotes;
    }

    return [$parcel];
}

function castaneas_sendcloud_v3_shipping_properties(array $order) {
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $shippingOptionCode = trim((string) ($shipping['code'] ?? ''));
    if ($shippingOptionCode === '') {
        return null;
    }

    $properties = [
        'shipping_option_code' => $shippingOptionCode,
    ];

    $contract = is_array($shipping['contract'] ?? null) ? $shipping['contract'] : [];
    $contractId = (int) ($contract['id'] ?? 0);
    if ($contractId > 0) {
        $properties['contract_id'] = $contractId;
    }

    return $properties;
}

function castaneas_sendcloud_v3_payload(array $order) {
    $senderAddressId = castaneas_sendcloud_sender_address_id();
    if ($senderAddressId <= 0) {
        return null;
    }

    $shippingProperties = castaneas_sendcloud_v3_shipping_properties($order);
    if ($shippingProperties === null) {
        return null;
    }

    $orderReference = trim((string) ($order['id'] ?? ''));

    $payload = [
        'label_details' => [
            'mime_type' => 'application/pdf',
            'dpi' => 72,
        ],
        'from_address' => [
            'sender_address_id' => $senderAddressId,
        ],
        'to_address' => castaneas_sendcloud_v3_to_address($order),
        'ship_with' => [
            'type' => 'shipping_option_code',
            'properties' => $shippingProperties,
        ],
        'order_number' => $orderReference,
        'reference' => $orderReference,
        'total_order_price' => [
            'value' => number_format((float) ($order['total'] ?? 0), 2, '.', ''),
            'currency' => 'EUR',
        ],
        'parcels' => castaneas_sendcloud_v3_parcels($order),
    ];

    $servicePoint = castaneas_sendcloud_v3_service_point($order);
    if ($servicePoint !== null) {
        $payload['to_service_point'] = $servicePoint;
    }

    return $payload;
}

function castaneas_sendcloud_first_announcement_error(array $shipmentData) {
    foreach (($shipmentData['errors'] ?? []) as $error) {
        $message = castaneas_sendcloud_error_message($error);
        if ($message !== null) {
            return $message;
        }
    }

    foreach (($shipmentData['parcels'] ?? []) as $parcel) {
        if (!is_array($parcel)) {
            continue;
        }
        $message = castaneas_sendcloud_error_message($parcel['errors'] ?? null);
        if ($message !== null) {
            return $message;
        }
        $status = strtoupper(trim((string) ($parcel['status']['code'] ?? '')));
        if ($status === 'ANNOUNCEMENT_FAILED') {
            return trim((string) ($parcel['status']['message'] ?? 'Announcement Failed'));
        }
    }

    return null;
}

function castaneas_sendcloud_send_order_v3(array $order) {
    $payload = castaneas_sendcloud_v3_payload($order);
    if ($payload === null) {
        return [
            'ok' => false,
            'code' => 'sendcloud_missing_shipping_option',
            'message' => 'Option de livraison Sendcloud introuvable ou adresse expéditeur invalide.',
        ];
    }

    $response = castaneas_sendcloud_v3_request('POST', 'shipments/announce', $payload);
    if (!$response['ok']) {
        $response['payload'] = $payload;
        return $response;
    }

    $shipment = is_array($response['data']['data'] ?? null) ? $response['data']['data'] : null;
    if ($shipment === null) {
        return [
            'ok' => false,
            'code' => 'sendcloud_missing_shipment',
            'message' => 'Réponse Sendcloud incomplète.',
            'payload' => $payload,
            'raw' => $response['data'],
        ];
    }

    $message = castaneas_sendcloud_first_announcement_error($shipment);
    if ($message !== null) {
        return [
            'ok' => false,
            'code' => 'sendcloud_announcement_failed',
            'message' => $message,
            'payload' => $payload,
            'raw' => $response['data'],
            'data' => [
                'shipment' => $shipment,
                'parcel' => is_array($shipment['parcels'][0] ?? null) ? $shipment['parcels'][0] : null,
                'apiVersion' => 'v3',
            ],
        ];
    }

    $parcel = is_array($shipment['parcels'][0] ?? null) ? $shipment['parcels'][0] : null;
    if ($parcel === null) {
        return [
            'ok' => false,
            'code' => 'sendcloud_missing_parcel',
            'message' => 'Colis Sendcloud introuvable dans la réponse.',
            'payload' => $payload,
            'raw' => $response['data'],
        ];
    }

    return [
        'ok' => true,
        'status' => $response['status'],
        'data' => [
            'shipment' => $shipment,
            'parcel' => $parcel,
            'apiVersion' => 'v3',
        ],
        'raw' => $response['data'],
    ];
}

function castaneas_sendcloud_refresh_parcel($parcelId) {
    $parcelId = (int) $parcelId;
    if ($parcelId <= 0) {
        return [
            'ok' => false,
            'code' => 'sendcloud_invalid_parcel_id',
            'message' => 'Identifiant de colis Sendcloud invalide.',
        ];
    }

    $response = castaneas_sendcloud_v2_request('GET', 'parcels/' . $parcelId);
    if (!$response['ok']) {
        return $response;
    }

    $parcel = castaneas_sendcloud_extract_parcel($response['data']);
    if (!$parcel) {
        return [
            'ok' => false,
            'code' => 'sendcloud_missing_parcel',
            'message' => 'Colis Sendcloud introuvable dans la réponse.',
            'raw' => $response['data'],
        ];
    }

    return [
        'ok' => true,
        'status' => $response['status'],
        'data' => ['parcel' => $parcel],
    ];
}

function castaneas_sendcloud_request_existing_label($parcelId) {
    $parcelId = (int) $parcelId;
    if ($parcelId <= 0) {
        return [
            'ok' => false,
            'code' => 'sendcloud_invalid_parcel_id',
            'message' => 'Identifiant de colis Sendcloud invalide.',
        ];
    }

    $response = castaneas_sendcloud_v2_request('PUT', 'parcels', [
        'parcel' => [
            'id' => $parcelId,
            'request_label' => true,
        ],
    ]);
    if (!$response['ok']) {
        return $response;
    }

    $parcel = castaneas_sendcloud_extract_parcel($response['data']);
    if (!$parcel) {
        return [
            'ok' => false,
            'code' => 'sendcloud_missing_parcel',
            'message' => 'Colis Sendcloud introuvable dans la réponse.',
            'raw' => $response['data'],
        ];
    }

    return [
        'ok' => true,
        'status' => $response['status'],
        'data' => ['parcel' => $parcel],
    ];
}

function castaneas_sendcloud_download_label_pdf($parcelId) {
    $parcelId = (int) $parcelId;
    if ($parcelId <= 0) {
        return [
            'ok' => false,
            'code' => 'sendcloud_invalid_parcel_id',
            'message' => 'Identifiant de colis Sendcloud invalide.',
        ];
    }

    $response = castaneas_sendcloud_v2_request('GET', 'parcels/' . $parcelId . '/documents/label', null, ['Accept: application/pdf']);
    if (!$response['ok']) {
        return $response;
    }

    return [
        'ok' => true,
        'status' => $response['status'],
        'contentType' => $response['contentType'] ?: 'application/pdf',
        'raw' => $response['raw'],
    ];
}

function castaneas_sendcloud_query_string(array $params) {
    $pairs = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
    }

    return implode('&', $pairs);
}

function castaneas_sendcloud_selected_functionalities(array $shipping) {
    return is_array($shipping['selectedFunctionalities'] ?? null) ? $shipping['selectedFunctionalities'] : [];
}

function castaneas_sendcloud_method_matches(array $method, array $shipping, $weightG) {
    $properties = is_array($method['properties'] ?? null) ? $method['properties'] : [];
    $minWeight = (int) ($properties['min_weight'] ?? 0);
    $maxWeight = (int) ($properties['max_weight'] ?? 0);
    if ($weightG > 0 && (($minWeight > 0 && $weightG < $minWeight) || ($maxWeight > 0 && $weightG > $maxWeight))) {
        return false;
    }

    $selected = castaneas_sendcloud_selected_functionalities($shipping);
    $functionalities = is_array($method['functionalities'] ?? null) ? $method['functionalities'] : [];
    foreach ($selected as $key => $value) {
        if (!array_key_exists($key, $functionalities)) {
            continue;
        }

        if ((string) $functionalities[$key] !== (string) $value) {
            return false;
        }
    }

    return true;
}

function castaneas_sendcloud_find_shipping_method(array $order) {
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $product = is_array($shipping['product'] ?? null) ? $shipping['product'] : [];
    $productCode = trim((string) ($product['code'] ?? ''));
    if ($productCode === '') {
        return null;
    }

    $billing = is_array($order['billing'] ?? null) ? $order['billing'] : [];
    $weightG = max(1, (int) round(castaneas_sendcloud_total_weight_kg($order) * 1000));
    $query = castaneas_sendcloud_query_string([
        'from_country' => 'FR',
        'to_country' => (string) ($billing['pays'] ?? 'FR'),
        'carrier' => (string) (($shipping['carrier']['code'] ?? '')),
        'weight' => $weightG,
        'weight_unit' => 'gram',
    ]);

    $response = castaneas_sendcloud_v2_request('GET', 'shipping-products' . ($query !== '' ? '?' . $query : ''));
    if (!$response['ok']) {
        return null;
    }

    foreach (($response['data'] ?? []) as $shippingProduct) {
        if (!is_array($shippingProduct) || (string) ($shippingProduct['code'] ?? '') !== $productCode) {
            continue;
        }

        $fallback = null;
        foreach (($shippingProduct['methods'] ?? []) as $method) {
            if (!is_array($method)) {
                continue;
            }

            if ($fallback === null) {
                $fallback = $method;
            }

            if (castaneas_sendcloud_method_matches($method, $shipping, $weightG)) {
                return [
                    'id' => (int) ($method['id'] ?? 0),
                    'name' => (string) ($method['name'] ?? ($shipping['name'] ?? '')),
                ];
            }
        }

        if (is_array($fallback)) {
            return [
                'id' => (int) ($fallback['id'] ?? 0),
                'name' => (string) ($fallback['name'] ?? ($shipping['name'] ?? '')),
            ];
        }
    }

    return null;
}

function castaneas_sendcloud_resolve_shipment(array $order, array $config) {
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];

    if ($config['shipping_method_id'] !== '' && $config['shipping_method_name'] !== '') {
        return [
            'id' => (int) $config['shipping_method_id'],
            'name' => (string) $config['shipping_method_name'],
        ];
    }

    if (!empty($shipping['method']['id'])) {
        return [
            'id' => (int) $shipping['method']['id'],
            'name' => (string) ($shipping['method']['name'] ?? $shipping['name'] ?? ''),
        ];
    }

    return castaneas_sendcloud_find_shipping_method($order);
}

function castaneas_sendcloud_split_address($rawAddress) {
    $rawAddress = trim((string) $rawAddress);
    if ($rawAddress === '') {
        return ['address' => '', 'house_number' => '1'];
    }

    if (preg_match('/^(.*?)[,\s]+(\d+[\w\/-]*)$/u', $rawAddress, $matches)) {
        return [
            'address' => trim($matches[1]),
            'house_number' => trim($matches[2]),
        ];
    }

    return ['address' => $rawAddress, 'house_number' => '1'];
}

function castaneas_sendcloud_products_index() {
    static $index = null;
    if ($index !== null) {
        return $index;
    }

    $index = [];
    $raw = castaneas_storage_read_raw('products');
    if ($raw === null || trim($raw) === '') {
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

        $index[(string) $product['id']] = $product;
    }

    return $index;
}

function castaneas_sendcloud_parse_weight_kg($rawWeight) {
    $rawWeight = trim((string) $rawWeight);
    if ($rawWeight === '') {
        return 0.0;
    }

    $normalized = mb_strtolower(str_replace(',', '.', $rawWeight));
    if (preg_match('/(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)/u', $normalized, $matches)) {
        $value = (float) $matches[1] * (float) $matches[2];
    } elseif (preg_match('/(\d+(?:\.\d+)?)/', $normalized, $matches)) {
        $value = (float) $matches[1];
    } else {
        return 0.0;
    }

    if (strpos($normalized, 'kg') !== false) {
        return $value;
    }

    return $value / 1000;
}

function castaneas_sendcloud_product_weight_kg(array $product, array $productsIndex, array $seen = []) {
    $productId = (string) ($product['id'] ?? '');
    if ($productId !== '') {
        if (isset($seen[$productId])) {
            return 0.0;
        }

        $seen[$productId] = true;
    }

    $shipping = is_array($product['shipping'] ?? null) ? $product['shipping'] : [];
    $weightG = max(0, (int) ($shipping['weightG'] ?? 0));
    if ($weightG > 0) {
        return $weightG / 1000;
    }

    $boxItems = is_array($product['boxItems'] ?? null) ? $product['boxItems'] : [];
    $boxWeightKg = 0.0;
    foreach ($boxItems as $boxItem) {
        if (!is_array($boxItem) || empty($boxItem['productId'])) {
            continue;
        }

        $child = $productsIndex[(string) $boxItem['productId']] ?? null;
        if (!is_array($child)) {
            continue;
        }

        $boxWeightKg += castaneas_sendcloud_product_weight_kg($child, $productsIndex, $seen);
    }
    if ($boxWeightKg > 0) {
        return $boxWeightKg;
    }

    return castaneas_sendcloud_parse_weight_kg($product['weight'] ?? '');
}

function castaneas_sendcloud_item_weight_kg(array $item) {
    $shipping = is_array($item['shipping'] ?? null) ? $item['shipping'] : [];
    $weightG = max(0, (int) ($shipping['weightG'] ?? 0));
    $bundleQty = max(1, (int) ($item['offerQty'] ?? 1));
    if ($weightG > 0) {
        return ($weightG * $bundleQty) / 1000;
    }

    $productsIndex = castaneas_sendcloud_products_index();
    $product = $productsIndex[(string) ($item['id'] ?? '')] ?? null;
    if (is_array($product)) {
        $productWeightKg = castaneas_sendcloud_product_weight_kg($product, $productsIndex);
        if ($productWeightKg > 0) {
            return $productWeightKg * $bundleQty;
        }
    }

    $fallbacks = [
        $item['weight'] ?? '',
        $item['variant'] ?? '',
    ];
    foreach ($fallbacks as $rawWeight) {
        $parsed = castaneas_sendcloud_parse_weight_kg($rawWeight);
        if ($parsed > 0) {
            return $parsed * $bundleQty;
        }
    }

    return 0.0;
}

function castaneas_sendcloud_min_item_weight_kg() {
    return 0.001;
}

function castaneas_sendcloud_total_weight_kg(array $order) {
    $shipment = castaneas_shipping_estimate_order($order);

    return round(((int) ($shipment['productWeightG'] ?? 250)) / 1000, 3);
}

function castaneas_sendcloud_parcel_items(array $order) {
    $items = [];

    foreach (($order['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemWeightKg = castaneas_sendcloud_item_weight_kg($item);
        if ($itemWeightKg < castaneas_sendcloud_min_item_weight_kg()) {
            $itemWeightKg = castaneas_sendcloud_min_item_weight_kg();
        }

        $items[] = [
            'description' => (string) ($item['name'] ?? 'Produit Castaneas'),
            'quantity' => max(1, (int) ($item['qty'] ?? 0)),
            'value' => number_format((float) ($item['price'] ?? 0), 2, '.', ''),
            'weight' => number_format($itemWeightKg, 3, '.', ''),
            'sku' => (string) ($item['id'] ?? ''),
            'origin_country' => 'FR',
        ];
    }

    return $items;
}

function castaneas_sendcloud_payload(array $order) {
    $billing = $order['billing'] ?? [];
    $address = castaneas_sendcloud_split_address(($billing['adresse'] ?? '') . ' ' . ($billing['complement'] ?? ''));
    $config = castaneas_sendcloud_config();
    $orderReference = trim((string) ($order['id'] ?? ''));

    $parcel = [
        'name' => trim((string) ($order['customer'] ?? 'Client Castaneas')),
        'company_name' => 'Castaneas',
        'email' => (string) ($order['email'] ?? ''),
        'telephone' => (string) ($billing['tel'] ?? ''),
        'address' => $address['address'],
        'house_number' => $address['house_number'],
        'address_2' => '',
        'city' => (string) ($billing['ville'] ?? ''),
        'postal_code' => (string) ($billing['cp'] ?? ''),
        'country' => (string) ($billing['pays'] ?? 'FR'),
        'quantity' => 1,
        'order_number' => $orderReference,
        'reference' => $orderReference,
        'weight' => number_format(castaneas_sendcloud_total_weight_kg($order), 3, '.', ''),
        'parcel_items' => castaneas_sendcloud_parcel_items($order),
        'total_order_value' => number_format((float) ($order['total'] ?? 0), 2, '.', ''),
        'total_order_value_currency' => 'EUR',
        'request_label' => !empty($config['request_label']),
        'apply_shipping_rules' => !empty($config['apply_shipping_rules']),
    ];

    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    if (!empty($shipping['name'])) {
        $parcel['shipping_method_checkout_name'] = (string) $shipping['name'];
    }
    if (!empty($billing['note'])) {
        $parcel['note'] = (string) $billing['note'];
    }
    $servicePoint = is_array($shipping['servicePoint'] ?? null) ? $shipping['servicePoint'] : [];
    $servicePointId = trim((string) ($servicePoint['id'] ?? ''));
    $carrierServicePointId = trim((string) ($servicePoint['carrierServicePointId'] ?? ''));
    if ($servicePointId !== '') {
        $parcel['to_service_point'] = $servicePointId;
    } elseif ($carrierServicePointId !== '') {
        $parcel['to_service_point'] = $carrierServicePointId;
    }

    if ($config['sender_address'] !== '') {
        $parcel['sender_address'] = is_numeric($config['sender_address']) ? (int) $config['sender_address'] : $config['sender_address'];
    }

    $shipment = castaneas_sendcloud_resolve_shipment($order, $config);
    if (!empty($shipment['id'])) {
        $parcel['shipment'] = [
            'id' => (int) $shipment['id'],
            'name' => (string) ($shipment['name'] ?? ''),
        ];
    }

    return ['parcel' => $parcel];
}

function castaneas_sendcloud_send_order(array $order) {
    if (!castaneas_sendcloud_is_ready()) {
        return [
            'ok' => false,
            'code' => 'sendcloud_not_configured',
            'message' => 'Configuration Sendcloud absente.',
        ];
    }

    $shippingCode = trim((string) ($order['shipping']['code'] ?? ''));
    if ($shippingCode !== '') {
        return castaneas_sendcloud_send_order_v3($order);
    }

    $payload = castaneas_sendcloud_payload($order);
    if (empty($payload['parcel']['shipment']['id'])) {
        return [
            'ok' => false,
            'code' => 'sendcloud_missing_shipping_method',
            'message' => 'Aucune methode de livraison Sendcloud exploitable n\'a ete trouvee pour cette commande.',
            'payload' => $payload,
        ];
    }

    return castaneas_sendcloud_v2_request('POST', 'parcels', $payload);
}