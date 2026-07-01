<?php

function castaneas_blog_slugify($value) {
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($normalized) && $normalized !== '') {
            $value = $normalized;
        }
    }

    $value = preg_replace('/&/', ' et ', $value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string) $value, '-');
    $value = preg_replace('/-{2,}/', '-', $value);

    return $value;
}

function castaneas_blog_read_key($key) {
    require_once __DIR__ . '/storage.php';

    $raw = castaneas_storage_read_raw($key);
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function castaneas_blog_normalize_categories(array $categories) {
    $normalized = [];

    foreach ($categories as $category) {
        if (!is_array($category)) {
            continue;
        }

        $slug = castaneas_blog_slugify($category['slug'] ?? '') ?: castaneas_blog_slugify($category['name'] ?? '');
        $id = trim((string) ($category['id'] ?? ''));
        if ($id === '' || $slug === '') {
            continue;
        }

        $normalized[] = [
            'id' => $id,
            'name' => trim((string) ($category['name'] ?? '')),
            'slug' => $slug,
            'description' => trim((string) ($category['description'] ?? '')),
            'metaTitle' => trim((string) ($category['metaTitle'] ?? '')),
            'metaDescription' => trim((string) ($category['metaDescription'] ?? '')),
            'status' => trim((string) ($category['status'] ?? 'active')) ?: 'active',
        ];
    }

    usort($normalized, function ($left, $right) {
        return strcasecmp($left['name'], $right['name']);
    });

    return $normalized;
}

function castaneas_blog_normalize_posts(array $posts, array $categoriesById) {
    $normalized = [];
    $usedSlugs = [];

    foreach ($posts as $post) {
        if (!is_array($post)) {
            continue;
        }

        $id = trim((string) ($post['id'] ?? ''));
        $title = trim((string) ($post['title'] ?? ''));
        if ($id === '' || $title === '') {
            continue;
        }

        $slugBase = castaneas_blog_slugify($post['slug'] ?? '') ?: castaneas_blog_slugify($title);
        if ($slugBase === '') {
            $slugBase = 'article';
        }

        $slug = $slugBase;
        $index = 2;
        while (isset($usedSlugs[$slug]) && $usedSlugs[$slug] !== $id) {
            $slug = $slugBase . '-' . $index;
            $index += 1;
        }
        $usedSlugs[$slug] = $id;

        $categoryIds = array_values(array_filter(array_map('strval', is_array($post['categoryIds'] ?? null) ? $post['categoryIds'] : [])));
        $primaryCategoryId = trim((string) ($post['primaryCategoryId'] ?? ''));
        if ($primaryCategoryId === '' && isset($categoryIds[0])) {
            $primaryCategoryId = $categoryIds[0];
        }
        if ($primaryCategoryId !== '' && !in_array($primaryCategoryId, $categoryIds, true)) {
            array_unshift($categoryIds, $primaryCategoryId);
        }

        $status = trim((string) ($post['status'] ?? 'draft')) ?: 'draft';
        $publishedAt = trim((string) ($post['publishedAt'] ?? ''));
        $updatedAt = trim((string) ($post['updatedAt'] ?? ''));

        $postCategories = [];
        foreach ($categoryIds as $categoryId) {
            if (isset($categoriesById[$categoryId]) && $categoriesById[$categoryId]['status'] === 'active') {
                $postCategories[] = $categoriesById[$categoryId];
            }
        }

        $primaryCategory = isset($categoriesById[$primaryCategoryId]) ? $categoriesById[$primaryCategoryId] : (isset($postCategories[0]) ? $postCategories[0] : null);

        $normalized[] = [
            'id' => $id,
            'title' => $title,
            'slug' => $slug,
            'eyebrow' => trim((string) ($post['eyebrow'] ?? 'Actualite')),
            'excerpt' => trim((string) ($post['excerpt'] ?? '')),
            'content' => (string) ($post['content'] ?? ''),
            'coverImage' => trim((string) ($post['coverImage'] ?? '')),
            'author' => trim((string) ($post['author'] ?? 'Equipe Castaneas')),
            'status' => $status,
            'featured' => !empty($post['featured']),
            'metaTitle' => trim((string) ($post['metaTitle'] ?? '')),
            'metaDescription' => trim((string) ($post['metaDescription'] ?? '')),
            'publishedAt' => $publishedAt,
            'updatedAt' => $updatedAt,
            'readingMinutes' => max(1, (int) ($post['readingMinutes'] ?? 0)),
            'categoryIds' => $categoryIds,
            'primaryCategoryId' => $primaryCategory ? $primaryCategory['id'] : '',
            'categories' => $postCategories,
            'primaryCategory' => $primaryCategory,
        ];
    }

    usort($normalized, function ($left, $right) {
        $leftDate = strtotime($left['publishedAt'] ?: $left['updatedAt'] ?: '1970-01-01');
        $rightDate = strtotime($right['publishedAt'] ?: $right['updatedAt'] ?: '1970-01-01');
        if ($leftDate === $rightDate) {
            return strcasecmp($left['title'], $right['title']);
        }
        return $rightDate <=> $leftDate;
    });

    return $normalized;
}

function castaneas_blog_dataset() {
    $categories = castaneas_blog_normalize_categories(castaneas_blog_read_key('blog_categories'));
    $categoriesById = [];
    $categoriesBySlug = [];
    foreach ($categories as $category) {
        $categoriesById[$category['id']] = $category;
        $categoriesBySlug[$category['slug']] = $category;
    }

    $posts = castaneas_blog_normalize_posts(castaneas_blog_read_key('blog_posts'), $categoriesById);

    return [
        'categories' => $categories,
        'categoriesById' => $categoriesById,
        'categoriesBySlug' => $categoriesBySlug,
        'posts' => $posts,
    ];
}

function castaneas_blog_get_published_posts(array $dataset) {
    return array_values(array_filter($dataset['posts'], function ($post) {
        return ($post['status'] ?? '') === 'published';
    }));
}

function castaneas_blog_find_category(array $dataset, $slug) {
    $cleanSlug = castaneas_blog_slugify($slug);
    if ($cleanSlug === '') {
        return null;
    }

    return $dataset['categoriesBySlug'][$cleanSlug] ?? null;
}

function castaneas_blog_find_post(array $dataset, $categorySlug, $postSlug) {
    $cleanCategorySlug = castaneas_blog_slugify($categorySlug);
    $cleanPostSlug = castaneas_blog_slugify($postSlug);

    foreach (castaneas_blog_get_published_posts($dataset) as $post) {
        if (($post['slug'] ?? '') !== $cleanPostSlug) {
            continue;
        }

        $primaryCategory = $post['primaryCategory'] ?? null;
        if ($primaryCategory && ($primaryCategory['slug'] ?? '') === $cleanCategorySlug) {
            return $post;
        }
    }

    return null;
}

function castaneas_blog_get_category_posts(array $dataset, $categoryId) {
    return array_values(array_filter(castaneas_blog_get_published_posts($dataset), function ($post) use ($categoryId) {
        return in_array($categoryId, $post['categoryIds'] ?? [], true);
    }));
}

function castaneas_blog_post_href(array $post) {
    $categorySlug = $post['primaryCategory']['slug'] ?? 'general';
    return '/actualites/' . rawurlencode($categorySlug) . '/' . rawurlencode($post['slug'] ?? 'article');
}

function castaneas_blog_category_href(array $category) {
    return '/actualites/categorie/' . rawurlencode($category['slug'] ?? '');
}

function castaneas_blog_format_date($value) {
    $timestamp = strtotime((string) $value);
    if (!$timestamp) {
        return '';
    }

    $months = [1 => 'janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];
    $day = date('j', $timestamp);
    $month = $months[(int) date('n', $timestamp)] ?? '';
    $year = date('Y', $timestamp);

    return $day . ' ' . $month . ' ' . $year;
}

function castaneas_blog_plain_excerpt($content, $fallback = '', $maxLength = 180) {
    $source = trim((string) $fallback);
    if ($source === '') {
        $source = trim(strip_tags((string) $content));
    }

    $source = preg_replace('/\s+/u', ' ', $source);
    $length = function_exists('mb_strlen') ? mb_strlen($source) : strlen($source);
    if ($length <= $maxLength) {
        return $source;
    }

    $slice = function_exists('mb_substr') ? mb_substr($source, 0, $maxLength - 1) : substr($source, 0, $maxLength - 1);
    return rtrim($slice) . '…';
}

function castaneas_blog_public_url($path) {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^(?:https?:)?//#i', $path) || strpos($path, 'data:') === 0 || strpos($path, 'mailto:') === 0 || strpos($path, '#') === 0) {
        return $path;
    }

    return '/' . ltrim($path, '/');
}
