<?php

require_once __DIR__ . '/storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

echo json_encode(castaneas_storage_status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);