<?php
// ============================================================
//  CASTANEAS — save.php
//  API back-office : persiste les données dans /data/*.json
//
//  GET  /save.php?key=products          → lit   data/products.json
//  POST /save.php?key=products  + body  → écrit data/products.json
//
//  ⚠️  Changez ADMIN_TOKEN avant de déployer (ou après).
// ============================================================

define('ADMIN_TOKEN', 'cas_srv_9e4f2b8d3a7c1065');

header('Content-Type: application/json; charset=utf-8');

// ── Vérification du token ─────────────────────────────────
$token = isset($_SERVER['HTTP_X_ADMIN_TOKEN']) ? $_SERVER['HTTP_X_ADMIN_TOKEN'] : '';
if ($token !== ADMIN_TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Clé autorisée ─────────────────────────────────────────
$key     = isset($_GET['key']) ? $_GET['key'] : '';
$key     = preg_replace('/[^a-z_]/', '', $key); // sanitize
$allowed = ['products', 'categories', 'orders', 'recipes', 'homepage'];
if (!in_array($key, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid key']);
    exit;
}

// ── Répertoire de données ─────────────────────────────────
$dataDir = __DIR__ . '/data/';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}
$file   = $dataDir . $key . '.json';
$method = $_SERVER['REQUEST_METHOD'];

// ── Lecture ───────────────────────────────────────────────
if ($method === 'GET') {
    if (file_exists($file)) {
        readfile($file);
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

    if (file_put_contents($file, $body, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Write failed']);
        exit;
    }

    echo json_encode(['ok' => true, 'key' => $key]);

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
