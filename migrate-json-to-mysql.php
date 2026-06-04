<?php

require_once __DIR__ . '/storage.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');

    $token = isset($_SERVER['HTTP_X_ADMIN_TOKEN']) ? $_SERVER['HTTP_X_ADMIN_TOKEN'] : '';
    if ($token === '' && isset($_GET['token'])) {
        $token = (string) $_GET['token'];
    }

    if ($token !== castaneas_admin_token()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$pdo = castaneas_db();
if (!$pdo) {
    http_response_code(500);
    echo json_encode([
        'error' => 'MySQL connection unavailable',
        'hint' => 'Configure db-config.local.php or CASTANEAS_DB_* environment variables first.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

castaneas_db_init_schema($pdo);

$report = [];
foreach (castaneas_allowed_keys() as $key) {
    $raw = castaneas_json_read_raw($key);
    if ($raw === null) {
        $report[] = [
            'key' => $key,
            'imported' => false,
            'reason' => 'source json not found',
        ];
        continue;
    }

    $decoded = json_decode($raw, true);
    $count = is_array($decoded) ? count($decoded) : ($decoded === null ? 0 : 1);

    $ok = castaneas_db_write_raw($key, $raw);
    $report[] = [
        'key' => $key,
        'imported' => (bool) $ok,
        'items' => $count,
    ];
}

$payload = [
    'ok' => true,
    'backend' => castaneas_storage_backend(),
    'report' => $report,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);