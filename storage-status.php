<?php

require_once __DIR__ . '/storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$token = isset($_SERVER['HTTP_X_ADMIN_TOKEN']) ? $_SERVER['HTTP_X_ADMIN_TOKEN'] : '';
if ($token !== castaneas_admin_token()) {
	http_response_code(401);
	echo json_encode(['error' => 'Unauthorized']);
	exit;
}

echo json_encode(castaneas_storage_status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);