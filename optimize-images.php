<?php
// ============================================================
//  CASTANEAS — optimize-images.php
//  Compresse par lots les photos déjà présentes dans uploads/ (back-office,
//  bouton « Optimiser les images »). Chaque original est copié dans
//  uploads/_originals/ avant remplacement ; même nom, même format.
//
//  POST /optimize-images.php   (session admin)
//  → { processed, remaining, savedBytes, results[] }
// ============================================================

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/image-optimize.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

function castaneas_optimize_response($status, array $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!castaneas_admin_authorized()) {
    castaneas_optimize_response(401, ['error' => 'Unauthorized']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    castaneas_optimize_response(405, ['error' => 'Méthode non autorisée.']);
}
if (!castaneas_image_optimizer_available()) {
    castaneas_optimize_response(503, ['error' => 'Extension GD indisponible sur le serveur : optimisation impossible.']);
}

$uploadDir = __DIR__ . '/uploads/';
$backupDir = $uploadDir . '_originals';
$doneFile = $backupDir . '/.optimized.json';
$done = is_file($doneFile) ? (json_decode((string) file_get_contents($doneFile), true) ?: []) : [];

$pending = [];
foreach (glob($uploadDir . '*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE) ?: [] as $file) {
    if (!isset($done[basename($file)])) {
        $pending[] = $file;
    }
}

@set_time_limit(60);
$started = microtime(true);
$results = [];
$saved = 0;
foreach ($pending as $file) {
    if (microtime(true) - $started > 20) {
        break;
    }
    if (preg_match('/\.opt\.jpg$/i', $file)) {
        $done[basename($file)] = 'generated';
        continue;
    }
    $result = castaneas_optimize_image_file($file, $backupDir);
    // Photo en PNG : version JPEG à côté (le PNG d'origine reste intact)
    if (in_array($result['status'], ['skipped', 'unchanged'], true) && preg_match('/\.png$/i', $file)) {
        $converted = castaneas_convert_png_photo($file);
        if ($converted['status'] === 'converted' && castaneas_record_converted_image($file, $converted['target'])) {
            $result = $converted;
        }
    }
    $done[basename($file)] = $result['status'];
    $saved += max(0, $result['before'] - $result['after']);
    $results[] = ['file' => 'uploads/' . basename($file)] + $result;
}

if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}
@file_put_contents($doneFile, json_encode($done, JSON_UNESCAPED_SLASHES));

castaneas_optimize_response(200, [
    'ok' => true,
    'processed' => count($results),
    'remaining' => max(0, count($pending) - count($results)),
    'savedBytes' => $saved,
    'results' => $results,
]);
