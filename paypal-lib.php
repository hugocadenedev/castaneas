<?php

require_once __DIR__ . '/integrations.php';

function castaneas_paypal_apply_ssl_options($ch, array $config) {
    if (!empty($config['ca_bundle'])) {
        curl_setopt($ch, CURLOPT_CAINFO, $config['ca_bundle']);
    }
    if (!empty($config['skip_ssl_verify'])) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
}

function castaneas_paypal_base_url() {
    $config = castaneas_paypal_config();
    $baseUrl = trim((string) ($config['base_url'] ?? ''));
    if ($baseUrl === '') {
        return 'https://api-m.sandbox.paypal.com';
    }

    return rtrim($baseUrl, '/');
}

function castaneas_paypal_format_amount($value) {
    return number_format(round((float) $value, 2), 2, '.', '');
}

function castaneas_paypal_token() {
    static $cache = [];

    $config = castaneas_paypal_config();
    $cacheKey = sha1(json_encode([
        $config['client_id'] ?? '',
        $config['secret'] ?? '',
        $config['base_url'] ?? '',
    ]));
    if (isset($cache[$cacheKey]) && ($cache[$cacheKey]['expires_at'] ?? 0) > time() + 30) {
        return ['ok' => true, 'token' => $cache[$cacheKey]['token']];
    }

    $url = castaneas_paypal_base_url() . '/v1/oauth2/token';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => trim((string) ($config['client_id'] ?? '')) . ':' . trim((string) ($config['secret'] ?? '')),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: fr_FR',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 25,
    ]);
    castaneas_paypal_apply_ssl_options($ch, $config);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'message' => $error !== '' ? $error : 'Erreur reseau PayPal.',
            'code' => 'paypal_transport_error',
        ];
    }

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        return [
            'ok' => false,
            'message' => trim((string) ($decoded['error_description'] ?? $decoded['error'] ?? 'Impossible d\'obtenir un token PayPal.')),
            'status' => $status,
            'code' => 'paypal_auth_error',
            'raw' => $decoded ?: $response,
        ];
    }

    $token = trim((string) ($decoded['access_token'] ?? ''));
    if ($token === '') {
        return [
            'ok' => false,
            'message' => 'Token PayPal vide.',
            'code' => 'paypal_auth_error',
            'raw' => $decoded,
        ];
    }

    $cache[$cacheKey] = [
        'token' => $token,
        'expires_at' => time() + max(60, (int) ($decoded['expires_in'] ?? 300)),
    ];

    return ['ok' => true, 'token' => $token];
}

function castaneas_paypal_request($method, $path, $payload = null, array $headers = []) {
    $tokenResult = castaneas_paypal_token();
    if (empty($tokenResult['ok'])) {
        return $tokenResult;
    }

    $config = castaneas_paypal_config();
    $requestHeaders = array_merge([
        'Accept: application/json',
        'Authorization: Bearer ' . $tokenResult['token'],
    ], $headers);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_TIMEOUT => 30,
    ];

    if ($payload !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $requestHeaders;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $url = castaneas_paypal_base_url() . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    castaneas_paypal_apply_ssl_options($ch, $config);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'message' => $error !== '' ? $error : 'Erreur reseau PayPal.',
            'code' => 'paypal_transport_error',
        ];
    }

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $message = trim((string) ($decoded['message'] ?? $decoded['error_description'] ?? $decoded['name'] ?? 'Erreur API PayPal.'));
        if ($message === '' && is_string($response)) {
            $message = trim($response);
        }

        return [
            'ok' => false,
            'message' => $message !== '' ? $message : 'Erreur API PayPal.',
            'status' => $status,
            'code' => 'paypal_http_error',
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

function castaneas_paypal_extract_link(array $links, $rel) {
    foreach ($links as $link) {
        if (!is_array($link)) {
            continue;
        }
        if (trim((string) ($link['rel'] ?? '')) !== $rel) {
            continue;
        }

        return trim((string) ($link['href'] ?? ''));
    }

    return '';
}

function castaneas_paypal_build_order_payload(array $order) {
    $config = castaneas_paypal_config();

    return [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => (string) ($order['id'] ?? ''),
            'custom_id' => (string) ($order['id'] ?? ''),
            'description' => 'Commande Castaneas ' . (string) ($order['id'] ?? ''),
            'amount' => [
                'currency_code' => trim((string) ($config['currency'] ?? 'EUR')),
                'value' => castaneas_paypal_format_amount($order['total'] ?? 0),
            ],
        ]],
        'payment_source' => [
            'paypal' => [
                'experience_context' => [
                    'brand_name' => trim((string) ($config['brand_name'] ?? 'Castaneas')),
                    'locale' => trim((string) ($config['locale'] ?? 'fr-FR')),
                    'landing_page' => 'LOGIN',
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                    'return_url' => castaneas_url('payment-return.php?gateway=paypal&ref=' . rawurlencode((string) ($order['id'] ?? ''))),
                    'cancel_url' => castaneas_url('payment-return.php?gateway=paypal&status=cancelled&ref=' . rawurlencode((string) ($order['id'] ?? ''))),
                ],
            ],
        ],
    ];
}

function castaneas_paypal_checkout_payload(array $order) {
    if (!castaneas_paypal_is_ready()) {
        return null;
    }

    $result = castaneas_paypal_request(
        'POST',
        '/v2/checkout/orders',
        castaneas_paypal_build_order_payload($order),
        ['Prefer: return=representation']
    );
    if (empty($result['ok'])) {
        return $result;
    }

    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $links = is_array($data['links'] ?? null) ? $data['links'] : [];
    $approvalUrl = castaneas_paypal_extract_link($links, 'approve');
    if ($approvalUrl === '') {
        $approvalUrl = castaneas_paypal_extract_link($links, 'payer-action');
    }
    if ($approvalUrl === '') {
        return [
            'ok' => false,
            'message' => 'URL d\'approbation PayPal introuvable.',
            'code' => 'paypal_missing_approval_url',
            'raw' => $data,
        ];
    }

    return [
        'ok' => true,
        'mode' => 'redirect',
        'url' => $approvalUrl,
        'provider' => 'paypal',
        'paypalOrderId' => trim((string) ($data['id'] ?? '')),
        'payload' => $data,
    ];
}

function castaneas_paypal_get_order($paypalOrderId) {
    $paypalOrderId = trim((string) $paypalOrderId);
    if ($paypalOrderId === '') {
        return [
            'ok' => false,
            'message' => 'Identifiant de commande PayPal manquant.',
            'code' => 'paypal_order_missing',
        ];
    }

    return castaneas_paypal_request('GET', '/v2/checkout/orders/' . rawurlencode($paypalOrderId));
}

function castaneas_paypal_capture_order($paypalOrderId) {
    $paypalOrderId = trim((string) $paypalOrderId);
    if ($paypalOrderId === '') {
        return [
            'ok' => false,
            'message' => 'Identifiant de commande PayPal manquant.',
            'code' => 'paypal_order_missing',
        ];
    }

    return castaneas_paypal_request(
        'POST',
        '/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture',
        null,
        ['Prefer: return=representation']
    );
}

function castaneas_paypal_capture_attempt_payload($paypalOrderId, array $captureResult) {
    if (!empty($captureResult['ok']) && is_array($captureResult['data'] ?? null)) {
        return [
            'ok' => true,
            'payload' => $captureResult['data'],
            'status' => castaneas_paypal_capture_status($captureResult['data']),
        ];
    }

    $paypalOrder = castaneas_paypal_get_order($paypalOrderId);
    if (empty($paypalOrder['ok']) || !is_array($paypalOrder['data'] ?? null)) {
        return [
            'ok' => false,
            'message' => (string) ($captureResult['message'] ?? $paypalOrder['message'] ?? 'Capture PayPal impossible.'),
            'code' => (string) ($captureResult['code'] ?? 'paypal_capture_error'),
        ];
    }

    $status = castaneas_paypal_capture_status($paypalOrder['data']);
    if ($status === 'paid') {
        return [
            'ok' => true,
            'payload' => $paypalOrder['data'],
            'status' => $status,
        ];
    }

    return [
        'ok' => false,
        'message' => (string) ($captureResult['message'] ?? 'Capture PayPal impossible.'),
        'code' => (string) ($captureResult['code'] ?? 'paypal_capture_error'),
        'payload' => $paypalOrder['data'],
        'status' => $status,
    ];
}

function castaneas_paypal_extract_reference_from_order(array $paypalOrder) {
    foreach (($paypalOrder['purchase_units'] ?? []) as $unit) {
        if (!is_array($unit)) {
            continue;
        }

        $candidates = [
            $unit['reference_id'] ?? null,
            $unit['custom_id'] ?? null,
            $unit['invoice_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }

    return '';
}

function castaneas_paypal_extract_capture_id(array $payload) {
    foreach (($payload['purchase_units'] ?? []) as $unit) {
        if (!is_array($unit)) {
            continue;
        }
        $captures = $unit['payments']['captures'] ?? [];
        if (!is_array($captures)) {
            continue;
        }
        foreach ($captures as $capture) {
            if (!is_array($capture)) {
                continue;
            }
            $captureId = trim((string) ($capture['id'] ?? ''));
            if ($captureId !== '') {
                return $captureId;
            }
        }
    }

    $resourceId = trim((string) ($payload['id'] ?? ''));

    return $resourceId !== '' ? $resourceId : null;
}

function castaneas_paypal_capture_status(array $payload) {
    foreach (($payload['purchase_units'] ?? []) as $unit) {
        if (!is_array($unit)) {
            continue;
        }
        foreach (($unit['payments']['captures'] ?? []) as $capture) {
            if (!is_array($capture)) {
                continue;
            }
            $status = strtoupper(trim((string) ($capture['status'] ?? '')));
            if ($status === 'COMPLETED') {
                return 'paid';
            }
            if (in_array($status, ['DECLINED', 'DENIED', 'FAILED'], true)) {
                return 'failed';
            }
            if ($status === 'PENDING') {
                return 'pending_payment';
            }
        }
    }

    $orderStatus = strtoupper(trim((string) ($payload['status'] ?? '')));
    if ($orderStatus === 'COMPLETED') {
        return 'paid';
    }
    if (in_array($orderStatus, ['VOIDED', 'FAILED'], true)) {
        return 'failed';
    }

    return '';
}

function castaneas_paypal_webhook_headers() {
    $map = [
        'paypal-auth-algo' => 'HTTP_PAYPAL_AUTH_ALGO',
        'paypal-cert-url' => 'HTTP_PAYPAL_CERT_URL',
        'paypal-transmission-id' => 'HTTP_PAYPAL_TRANSMISSION_ID',
        'paypal-transmission-sig' => 'HTTP_PAYPAL_TRANSMISSION_SIG',
        'paypal-transmission-time' => 'HTTP_PAYPAL_TRANSMISSION_TIME',
    ];

    $headers = [];
    foreach ($map as $headerName => $serverKey) {
        $value = trim((string) ($_SERVER[$serverKey] ?? ''));
        if ($value !== '') {
            $headers[$headerName] = $value;
        }
    }

    return $headers;
}

function castaneas_paypal_verify_webhook(array $headers, array $payload) {
    $config = castaneas_paypal_config();
    $webhookId = trim((string) ($config['webhook_id'] ?? ''));
    if ($webhookId === '') {
        return [
            'ok' => false,
            'message' => 'Webhook PayPal non configure.',
            'code' => 'paypal_webhook_not_configured',
        ];
    }

    $required = [
        'paypal-auth-algo',
        'paypal-cert-url',
        'paypal-transmission-id',
        'paypal-transmission-sig',
        'paypal-transmission-time',
    ];
    foreach ($required as $header) {
        if (trim((string) ($headers[$header] ?? '')) === '') {
            return [
                'ok' => false,
                'message' => 'Headers webhook PayPal incomplets.',
                'code' => 'paypal_webhook_headers_missing',
            ];
        }
    }

    $verification = castaneas_paypal_request('POST', '/v1/notifications/verify-webhook-signature', [
        'auth_algo' => $headers['paypal-auth-algo'],
        'cert_url' => $headers['paypal-cert-url'],
        'transmission_id' => $headers['paypal-transmission-id'],
        'transmission_sig' => $headers['paypal-transmission-sig'],
        'transmission_time' => $headers['paypal-transmission-time'],
        'webhook_id' => $webhookId,
        'webhook_event' => $payload,
    ]);
    if (empty($verification['ok'])) {
        return $verification;
    }

    $status = strtoupper(trim((string) ($verification['data']['verification_status'] ?? '')));
    if ($status !== 'SUCCESS') {
        return [
            'ok' => false,
            'message' => 'Signature webhook PayPal invalide.',
            'code' => 'paypal_webhook_invalid',
            'raw' => $verification['data'] ?? [],
        ];
    }

    return ['ok' => true];
}
