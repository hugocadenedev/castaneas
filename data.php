<?php
/*
 * CASTANEAS — data.php
 * Injecte les données produits/catégories/recettes comme variable JS globale.
 * Appelé via <script src="data.php"></script> avant site-data.js.
 * Revalidé à chaque page (no-cache + ETag) : réponse 304 sans contenu tant que les données ne changent pas.
 */
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/inline-images.php';
require_once __DIR__ . '/image-optimize.php';

$keys = ['products', 'categories', 'recipes', 'homepage', 'packagings', 'promo_codes', 'blog_posts', 'blog_categories'];
$storageFailures = [];
foreach ($keys as $key) {
    $status = castaneas_storage_key_status($key);
    if ($status['error'] !== null) {
        $storageFailures[] = [
            'key' => $key,
            'error' => $status['error'],
        ];
    }
}

if ($storageFailures) {
    http_response_code(503);
    echo 'window.CASTANEAS_DATA={};';
    echo 'window.CASTANEAS_DATA_ERROR=' . json_encode([
        'code' => 'storage_unavailable',
        'message' => 'MySQL storage is unavailable for critical storefront data.',
        'details' => $storageFailures,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
    echo 'throw new Error("CASTANEAS critical storage unavailable");';
    exit;
}

$output  = [];

foreach ($keys as $key) {
    $raw = castaneas_storage_read_raw($key);
    if ($raw === null) {
        continue;
    }

    $data = json_decode($raw);
    if ($data !== null) {
        // Images intégrées (bannières, accueil) servies en fichiers : data.php passe de ~4 Mo à ~150 Ko
        // + photos PNG servies dans leur version JPEG allégée (voir optimize-images.php)
        $output[$key] = castaneas_apply_converted_images(castaneas_externalize_inline_images($data));
    }
}

$body = 'window.CASTANEAS_DATA=' . json_encode($output, JSON_UNESCAPED_UNICODE) . ';';
$etag = '"' . md5($body) . '"';
header('ETag: ' . $etag);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

echo $body;
