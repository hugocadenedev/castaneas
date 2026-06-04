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

$configDir = getenv('CASTANEAS_DATA_DIR');
$dataDirs = [
    $configDir ? rtrim($configDir, '/\\') : null,
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'castaneas-data',
    __DIR__ . '/data',
    __DIR__ . '/uploads/data',
];
$output  = [];

foreach (['products', 'categories', 'recipes', 'homepage'] as $key) {
    foreach ($dataDirs as $dataDir) {
        if (!$dataDir) {
            continue;
        }
        $file = $dataDir . '/' . $key . '.json';
        if (!file_exists($file)) {
            continue;
        }

        $raw  = file_get_contents($file);
        $data = json_decode($raw);
        if ($data !== null) {
            $output[$key] = $data;
            break;
        }
    }
}

echo 'window.CASTANEAS_DATA=' . json_encode($output, JSON_UNESCAPED_UNICODE) . ';';
