<?php

require_once __DIR__ . '/storage.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

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

function castaneas_repair_bool_flag($name) {
    if (!isset($_GET[$name])) {
        return false;
    }

    $value = strtolower(trim((string) $_GET[$name]));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function castaneas_repair_decode_products($raw) {
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function castaneas_repair_normalize_image_list($images) {
    if (!is_array($images)) {
        return [];
    }

    $normalized = [];
    foreach ($images as $image) {
        if (!is_string($image)) {
            continue;
        }

        $image = trim($image);
        if ($image === '') {
            continue;
        }

        $normalized[$image] = true;
    }

    return array_keys($normalized);
}

function castaneas_repair_public_path_exists($path) {
    if (!is_string($path)) {
        return false;
    }

    $path = trim($path);
    if ($path === '' || preg_match('#^(https?:)?//#i', $path)) {
        return false;
    }

    $fullPath = __DIR__ . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    return is_file($fullPath);
}

function castaneas_repair_legacy_sources() {
    $sources = [];
    foreach (castaneas_json_directories() as $dir) {
        $file = $dir . DIRECTORY_SEPARATOR . 'products.json';
        if (!is_file($file)) {
            continue;
        }

        $products = castaneas_repair_decode_products(file_get_contents($file));
        if (!$products) {
            continue;
        }

        $byId = [];
        foreach ($products as $product) {
            if (!is_array($product) || empty($product['id'])) {
                continue;
            }

            $byId[(string) $product['id']] = $product;
        }

        if ($byId) {
            $sources[] = [
                'dir' => $dir,
                'file' => $file,
                'products' => $byId,
            ];
        }
    }

    return $sources;
}

$apply = castaneas_repair_bool_flag('apply');
$currentRaw = castaneas_storage_read_raw('products');
$currentProducts = castaneas_repair_decode_products($currentRaw);
$legacySources = castaneas_repair_legacy_sources();

$updatedProducts = $currentProducts;
$report = [];
$changedCount = 0;

foreach ($currentProducts as $index => $product) {
    if (!is_array($product) || empty($product['id'])) {
        continue;
    }

    $productId = (string) $product['id'];
    $currentImage = isset($product['image']) && is_string($product['image']) ? trim($product['image']) : '';
    $currentImages = castaneas_repair_normalize_image_list($product['images'] ?? []);

    $candidate = null;
    foreach ($legacySources as $source) {
        if (!isset($source['products'][$productId])) {
            continue;
        }

        $legacy = $source['products'][$productId];
        $legacyImage = isset($legacy['image']) && is_string($legacy['image']) ? trim($legacy['image']) : '';
        $legacyImages = castaneas_repair_normalize_image_list($legacy['images'] ?? []);
        $existingLegacyImage = $legacyImage !== '' && castaneas_repair_public_path_exists($legacyImage);
        $existingLegacyImages = array_values(array_filter($legacyImages, 'castaneas_repair_public_path_exists'));

        $isDifferent = $legacyImage !== $currentImage || json_encode($legacyImages) !== json_encode($currentImages);
        if (!$isDifferent) {
            continue;
        }

        if (!$existingLegacyImage && !$existingLegacyImages) {
            continue;
        }

        $candidate = [
            'source_file' => $source['file'],
            'legacy_image' => $legacyImage,
            'legacy_images' => $legacyImages,
            'existing_legacy_image' => $existingLegacyImage,
            'existing_legacy_images' => $existingLegacyImages,
        ];
        break;
    }

    $entry = [
        'id' => $productId,
        'name' => $product['name'] ?? '',
        'current_image' => $currentImage,
        'current_image_exists' => castaneas_repair_public_path_exists($currentImage),
        'current_images' => $currentImages,
        'current_existing_images' => array_values(array_filter($currentImages, 'castaneas_repair_public_path_exists')),
        'candidate' => $candidate,
        'updated' => false,
    ];

    if ($apply && $candidate) {
        if ($candidate['existing_legacy_image']) {
            $updatedProducts[$index]['image'] = $candidate['legacy_image'];
        }
        if ($candidate['existing_legacy_images']) {
            $updatedProducts[$index]['images'] = $candidate['existing_legacy_images'];
        }
        $entry['updated'] = true;
        $changedCount++;
    }

    $report[] = $entry;
}

$writeOk = null;
if ($apply && $changedCount > 0) {
    $writeOk = castaneas_storage_write_raw(
        'products',
        json_encode($updatedProducts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

$payload = [
    'ok' => true,
    'mode' => $apply ? 'apply' : 'audit',
    'backend' => castaneas_storage_backend(),
    'legacy_sources' => array_map(function ($source) {
        return $source['file'];
    }, $legacySources),
    'changed_count' => $changedCount,
    'write_ok' => $writeOk,
    'report' => $report,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);