<?php
// ============================================================
//  CASTANEAS — save.php
//  API back-office : persiste les données JSON hors dossier déployé quand possible.
//
//  GET  /save.php?key=products          → lit   <data-dir>/products.json
//  POST /save.php?key=products  + body  → écrit <data-dir>/products.json
//
//  ⚠️  Changez ADMIN_TOKEN avant de déployer (ou après).
// ============================================================

require_once __DIR__ . '/storage.php';

header('Content-Type: application/json; charset=utf-8');

// ── Vérification du token ─────────────────────────────────
$token = isset($_SERVER['HTTP_X_ADMIN_TOKEN']) ? $_SERVER['HTTP_X_ADMIN_TOKEN'] : '';
if ($token !== castaneas_admin_token()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Clé autorisée ─────────────────────────────────────────
$key     = isset($_GET['key']) ? $_GET['key'] : '';
$key     = preg_replace('/[^a-z_]/', '', $key); // sanitize
$allowed = castaneas_allowed_keys();
if (!in_array($key, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid key']);
    exit;
}

$storageStatus = castaneas_storage_key_status($key);
if ($storageStatus['error'] !== null) {
    http_response_code(503);
    echo json_encode([
        'error' => 'Critical storage unavailable',
        'code' => 'storage_unavailable',
        'key' => $key,
        'details' => $storageStatus,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ── Lecture ───────────────────────────────────────────────
if ($method === 'GET') {
    $raw = castaneas_storage_read_raw($key);
    if ($raw !== null) {
        echo $raw;
    } else {
        echo 'null';
    }

// ── Écriture ──────────────────────────────────────────────
} elseif ($method === 'POST') {
    $body = file_get_contents('php://input');

    if ($body === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Empty body']);
        exit;
    }

    json_decode($body);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }

    if (!castaneas_storage_write_raw($key, $body)) {
        http_response_code(500);
        echo json_encode(['error' => 'Storage write failed']);
        exit;
    }

    echo json_encode(['ok' => true, 'key' => $key, 'backend' => castaneas_storage_backend()]);

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
