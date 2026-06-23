<?php

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/integrations.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function castaneas_sitemap_slugify($value) {
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return '';
    }

    $map = [
        'a' => '/[àáâãäå]/u',
        'ae' => '/æ/u',
        'c' => '/[ç]/u',
        'e' => '/[èéêë]/u',
        'i' => '/[ìíîï]/u',
        'n' => '/[ñ]/u',
        'o' => '/[òóôõö]/u',
        'oe' => '/œ/u',
        'u' => '/[ùúûü]/u',
        'y' => '/[ýÿ]/u',
    ];
    foreach ($map as $replacement => $pattern) {
        $value = preg_replace($pattern, $replacement, $value);
    }

    $value = str_replace('&', ' et ', $value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string) $value, '-');
    $value = preg_replace('/-{2,}/', '-', $value);

    return $value;
}

function castaneas_sitemap_load_key($key) {
    $raw = castaneas_storage_read_raw($key);
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function castaneas_sitemap_product_urls(array $products, $baseUrl) {
    $urls = [];
    $used = [];

    foreach ($products as $product) {
        if (!is_array($product) || ($product['status'] ?? '') !== 'active') {
            continue;
        }

        $baseSlug = castaneas_sitemap_slugify($product['slug'] ?? '')
            ?: trim(implode('-', array_filter([
                castaneas_sitemap_slugify($product['name'] ?? ''),
                castaneas_sitemap_slugify($product['weight'] ?? ''),
            ])), '-')
            ?: castaneas_sitemap_slugify($product['id'] ?? '')
            ?: 'produit';

        $finalSlug = $baseSlug;
        $index = 2;
        while (isset($used[$finalSlug]) && $used[$finalSlug] !== ($product['id'] ?? '')) {
            $finalSlug = $baseSlug . '-' . $index;
            $index += 1;
        }
        $used[$finalSlug] = $product['id'] ?? $finalSlug;

        $urls[] = [
            'loc' => $baseUrl . '/produit/' . rawurlencode($finalSlug),
            'lastmod' => gmdate('Y-m-d'),
            'changefreq' => 'weekly',
            'priority' => '0.80',
        ];
    }

    return $urls;
}

function castaneas_sitemap_category_urls(array $categories, $baseUrl) {
    $urls = [];

    foreach ($categories as $category) {
        if (!is_array($category) || ($category['status'] ?? '') !== 'active') {
            continue;
        }

        $slug = castaneas_sitemap_slugify($category['slug'] ?? '') ?: castaneas_sitemap_slugify($category['name'] ?? '');
        if ($slug === '') {
            continue;
        }

        $urls[] = [
            'loc' => $baseUrl . '/categorie/' . rawurlencode($slug),
            'lastmod' => gmdate('Y-m-d'),
            'changefreq' => 'weekly',
            'priority' => '0.70',
        ];
    }

    return $urls;
}

$baseUrl = rtrim(castaneas_base_url(), '/');
$products = castaneas_sitemap_load_key('products');
$categories = castaneas_sitemap_load_key('categories');

$urls = [
    ['loc' => $baseUrl . '/', 'lastmod' => gmdate('Y-m-d'), 'changefreq' => 'weekly', 'priority' => '1.00'],
    ['loc' => $baseUrl . '/recettes.html', 'lastmod' => gmdate('Y-m-d'), 'changefreq' => 'weekly', 'priority' => '0.60'],
    ['loc' => $baseUrl . '/histoire.html', 'lastmod' => gmdate('Y-m-d'), 'changefreq' => 'monthly', 'priority' => '0.50'],
    ['loc' => $baseUrl . '/cgv.html', 'lastmod' => gmdate('Y-m-d'), 'changefreq' => 'yearly', 'priority' => '0.30'],
];

$urls = array_merge($urls, castaneas_sitemap_category_urls($categories, $baseUrl));
$urls = array_merge($urls, castaneas_sitemap_product_urls($products, $baseUrl));

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach ($urls as $url) {
    echo '<url>';
    echo '<loc>' . htmlspecialchars($url['loc'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</loc>';
    echo '<lastmod>' . htmlspecialchars($url['lastmod'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</lastmod>';
    echo '<changefreq>' . htmlspecialchars($url['changefreq'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</changefreq>';
    echo '<priority>' . htmlspecialchars($url['priority'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</priority>';
    echo '</url>';
}
echo '</urlset>';