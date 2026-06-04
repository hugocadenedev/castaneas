<?php
/*
 * CASTANEAS — data.php
 * Injecte les données produits/catégories/recettes comme variable JS globale.
 * Appelé via <script src="data.php"></script> avant site-data.js.
 * Jamais mis en cache (no-store).
 */
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/storage.php';

$output  = [];

foreach (['products', 'categories', 'recipes', 'homepage'] as $key) {
    $raw = castaneas_storage_read_raw($key);
    if ($raw === null) {
        continue;
    }

    $data = json_decode($raw);
    if ($data !== null) {
        $output[$key] = $data;
    }
}

echo 'window.CASTANEAS_DATA=' . json_encode($output, JSON_UNESCAPED_UNICODE) . ';';
