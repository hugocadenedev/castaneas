<?php

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/invoice-lib.php';

header('Content-Type: application/json; charset=utf-8');

$token = isset($_SERVER['HTTP_X_ADMIN_TOKEN']) ? $_SERVER['HTTP_X_ADMIN_TOKEN'] : '';
if ($token !== castaneas_admin_token()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $settings = castaneas_billing_settings_load();
    echo json_encode([
        'format' => $settings['format'],
        'nextNumber' => $settings['nextNumber'],
        'maxIssuedSequence' => castaneas_invoice_max_issued_sequence(),
        'preview' => castaneas_invoice_render_number($settings['format'], $settings['nextNumber']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = file_get_contents('php://input');
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $settings = castaneas_billing_settings_normalize($payload);
    $validationError = castaneas_billing_settings_validate($settings);
    if ($validationError !== null) {
        http_response_code(400);
        echo json_encode(['error' => $validationError], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!castaneas_billing_settings_save($settings)) {
        http_response_code(500);
        echo json_encode(['error' => 'Storage write failed.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'settings' => $settings,
        'maxIssuedSequence' => castaneas_invoice_max_issued_sequence(),
        'preview' => castaneas_invoice_render_number($settings['format'], $settings['nextNumber']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);