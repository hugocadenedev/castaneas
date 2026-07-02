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

function castaneas_sitemap_lastmod_from_value($value, $fallbackTimestamp = null) {
    if (is_numeric($value)) {
        $timestamp = (int) $value;
        if ($timestamp > 0) {
            return gmdate('Y-m-d', $timestamp);
        }
    }

    if (is_string($value)) {
        $value = trim($value);
        if ($value !== '') {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return gmdate('Y-m-d', $timestamp);
            }
        }
    }

    if ($fallbackTimestamp !== null && $fallbackTimestamp > 0) {
        return gmdate('Y-m-d', $fallbackTimestamp);
    }

    return gmdate('Y-m-d');
}

function castaneas_sitemap_record_lastmod(array $record, $fallbackTimestamp = null) {
    foreach (['updatedAt', 'publishedAt', 'createdAt', 'date'] as $field) {
        if (!empty($record[$field])) {
            return castaneas_sitemap_lastmod_from_value($record[$field], $fallbackTimestamp);
        }
    }

    return castaneas_sitemap_lastmod_from_value(null, $fallbackTimestamp);
}

function castaneas_sitemap_key_lastmod($key) {
    $pdo = castaneas_db();
    if ($pdo) {
        castaneas_db_init_schema($pdo);
        $stmt = $pdo->prepare('SELECT updated_at FROM content_store WHERE store_key = :store_key LIMIT 1');
        $stmt->execute(['store_key' => $key]);
        $value = $stmt->fetchColumn();
        if ($value) {
            $timestamp = strtotime((string) $value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }
    }

    foreach (castaneas_json_directories() as $dir) {
        $file = $dir . '/' . $key . '.json';
        if (is_file($file)) {
            $timestamp = filemtime($file);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }
    }

    return null;
}

function castaneas_sitemap_file_lastmod($relativePath) {
    $file = __DIR__ . '/' . ltrim($relativePath, '/');
    if (!is_file($file)) {
        return gmdate('Y-m-d');
    }

    $timestamp = filemtime($file);
    return castaneas_sitemap_lastmod_from_value(null, $timestamp !== false ? $timestamp : null);
}

function castaneas_sitemap_product_urls(array $products, $baseUrl, $fallbackTimestamp = null) {
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
            'lastmod' => castaneas_sitemap_record_lastmod($product, $fallbackTimestamp),
            'changefreq' => 'weekly',
            'priority' => '0.80',
        ];
    }

    return $urls;
}

function castaneas_sitemap_category_urls(array $categories, $baseUrl, $fallbackTimestamp = null) {
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
            'lastmod' => castaneas_sitemap_record_lastmod($category, $fallbackTimestamp),
            'changefreq' => 'weekly',
            'priority' => '0.70',
        ];
    }

    return $urls;
}

function castaneas_sitemap_blog_category_urls(array $categories, $baseUrl, $fallbackTimestamp = null) {
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
            'loc' => $baseUrl . '/actualites/categorie/' . rawurlencode($slug),
            'lastmod' => castaneas_sitemap_record_lastmod($category, $fallbackTimestamp),
            'changefreq' => 'weekly',
            'priority' => '0.65',
        ];
    }

    return $urls;
}

function castaneas_sitemap_recipe_urls(array $recipes, $baseUrl, $fallbackTimestamp = null) {
    $urls = [];

    foreach ($recipes as $recipe) {
        if (!is_array($recipe) || ($recipe['status'] ?? '') !== 'published') {
            continue;
        }

        $recipeId = trim((string) ($recipe['id'] ?? ''));
        if ($recipeId === '') {
            continue;
        }

        $urls[] = [
            'loc' => $baseUrl . '/recette?id=' . rawurlencode($recipeId),
            'lastmod' => castaneas_sitemap_record_lastmod($recipe, $fallbackTimestamp),
            'changefreq' => 'monthly',
            'priority' => '0.58',
        ];
    }

    return $urls;
}

function castaneas_sitemap_blog_post_urls(array $posts, array $blogCategoriesById, $baseUrl) {
    $urls = [];
    $usedSlugs = [];

    foreach ($posts as $post) {
        if (!is_array($post) || ($post['status'] ?? '') !== 'published') {
            continue;
        }

        $postId = (string) ($post['id'] ?? '');
        $baseSlug = castaneas_sitemap_slugify($post['slug'] ?? '') ?: castaneas_sitemap_slugify($post['title'] ?? '');
        if ($baseSlug === '') {
            $baseSlug = 'article';
        }

        $finalSlug = $baseSlug;
        $index = 2;
        while (isset($usedSlugs[$finalSlug]) && $usedSlugs[$finalSlug] !== $postId) {
            $finalSlug = $baseSlug . '-' . $index;
            $index += 1;
        }
        $usedSlugs[$finalSlug] = $postId;

        $categoryId = (string) ($post['primaryCategoryId'] ?? '');
        if ($categoryId === '' && !empty($post['categoryIds'][0])) {
            $categoryId = (string) $post['categoryIds'][0];
        }

        $category = $blogCategoriesById[$categoryId] ?? null;
        if (!is_array($category) || ($category['status'] ?? '') !== 'active') {
            continue;
        }

        $categorySlug = castaneas_sitemap_slugify($category['slug'] ?? '') ?: castaneas_sitemap_slugify($category['name'] ?? '');
        if ($categorySlug === '') {
            continue;
        }

        $lastmod = '';
        if (!empty($post['updatedAt'])) {
            $lastmod = gmdate('Y-m-d', strtotime((string) $post['updatedAt']));
        } elseif (!empty($post['publishedAt'])) {
            $lastmod = gmdate('Y-m-d', strtotime((string) $post['publishedAt']));
        } else {
            $lastmod = gmdate('Y-m-d');
        }

        $urls[] = [
            'loc' => $baseUrl . '/actualites/' . rawurlencode($categorySlug) . '/' . rawurlencode($finalSlug),
            'lastmod' => $lastmod,
            'changefreq' => 'monthly',
            'priority' => '0.72',
        ];
    }

    return $urls;
}

$baseUrl = rtrim(castaneas_base_url(), '/');
$products = castaneas_sitemap_load_key('products');
$categories = castaneas_sitemap_load_key('categories');
$recipes = castaneas_sitemap_load_key('recipes');
$blogCategories = castaneas_sitemap_load_key('blog_categories');
$blogPosts = castaneas_sitemap_load_key('blog_posts');
$homepageLastmod = castaneas_sitemap_key_lastmod('homepage');
$productsLastmod = castaneas_sitemap_key_lastmod('products');
$categoriesLastmod = castaneas_sitemap_key_lastmod('categories');
$recipesLastmod = castaneas_sitemap_key_lastmod('recipes');
$blogCategoriesLastmod = castaneas_sitemap_key_lastmod('blog_categories');
$blogPostsLastmod = castaneas_sitemap_key_lastmod('blog_posts');
$blogCategoriesById = [];
foreach ($blogCategories as $blogCategory) {
    if (!is_array($blogCategory) || empty($blogCategory['id'])) {
        continue;
    }
    $blogCategoriesById[(string) $blogCategory['id']] = $blogCategory;
}

$urls = [
    ['loc' => $baseUrl . '/', 'lastmod' => castaneas_sitemap_lastmod_from_value(null, $homepageLastmod), 'changefreq' => 'weekly', 'priority' => '1.00'],
    ['loc' => $baseUrl . '/recettes', 'lastmod' => castaneas_sitemap_lastmod_from_value(null, $recipesLastmod), 'changefreq' => 'weekly', 'priority' => '0.60'],
    ['loc' => $baseUrl . '/actualites', 'lastmod' => castaneas_sitemap_lastmod_from_value(null, $blogPostsLastmod ?: $blogCategoriesLastmod), 'changefreq' => 'weekly', 'priority' => '0.70'],
    ['loc' => $baseUrl . '/histoire', 'lastmod' => castaneas_sitemap_file_lastmod('histoire.html'), 'changefreq' => 'monthly', 'priority' => '0.50'],
    ['loc' => $baseUrl . '/cgv', 'lastmod' => castaneas_sitemap_file_lastmod('cgv.html'), 'changefreq' => 'yearly', 'priority' => '0.30'],
    ['loc' => $baseUrl . '/livraison-retours', 'lastmod' => castaneas_sitemap_file_lastmod('livraison-retours.html'), 'changefreq' => 'monthly', 'priority' => '0.40'],
];

$urls = array_merge($urls, castaneas_sitemap_category_urls($categories, $baseUrl, $categoriesLastmod));
$urls = array_merge($urls, castaneas_sitemap_product_urls($products, $baseUrl, $productsLastmod));
$urls = array_merge($urls, castaneas_sitemap_recipe_urls($recipes, $baseUrl, $recipesLastmod));
$urls = array_merge($urls, castaneas_sitemap_blog_category_urls($blogCategories, $baseUrl, $blogCategoriesLastmod));
$urls = array_merge($urls, castaneas_sitemap_blog_post_urls($blogPosts, $blogCategoriesById, $baseUrl));

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