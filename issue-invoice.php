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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$body = file_get_contents('php://input');
$payload = json_decode($body, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$orderId = trim((string) ($payload['orderId'] ?? ''));
if ($orderId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'orderId is required.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$result = castaneas_invoice_assign_order($orderId);
if (empty($result['ok'])) {
    http_response_code(400);
    echo json_encode(['error' => $result['error'] ?? 'Invoice issue failed.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok' => true,
    'order' => $result['order'],
    'settings' => $result['settings'],
    'created' => !empty($result['created']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);