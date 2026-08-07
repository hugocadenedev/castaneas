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

function castaneas_blog_is_publicly_visible(array $post, $nowTimestamp = null) {
    if (($post['status'] ?? '') !== 'published') {
        return false;
    }

    $publishedAt = trim((string) ($post['publishedAt'] ?? ''));
    if ($publishedAt === '') {
        return true;
    }

    $publishedTimestamp = strtotime($publishedAt);
    if (!$publishedTimestamp) {
        return true;
    }

    $now = $nowTimestamp ?: time();
    return $publishedTimestamp <= $now;
}

function castaneas_blog_get_published_posts(array $dataset) {
    return array_values(array_filter($dataset['posts'], function ($post) {
        return castaneas_blog_is_publicly_visible($post);
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

function castaneas_blog_sanitize_url($value, array $options = []) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }

    if (!empty($options['allow_mailto']) && preg_match('#^mailto:#i', $value)) {
        return $value;
    }

    if (!empty($options['allow_tel']) && preg_match('#^tel:#i', $value)) {
        return $value;
    }

    if ($value[0] === '/' || $value[0] === '#' || strpos($value, './') === 0 || strpos($value, '../') === 0) {
        return $value;
    }

    return '';
}

function castaneas_blog_render_content($content) {
    $content = trim((string) $content);
    if ($content === '') {
        return '<p>Aucun contenu n\'a encore ete renseigne pour cet article.</p>';
    }

    if (!preg_match('/<[^>]+>/', $content)) {
        $blocks = preg_split('/\n{2,}/', $content);
        $html = [];
        foreach ($blocks as $block) {
            $line = trim((string) $block);
            if ($line === '') {
                continue;
            }
            $html[] = '<p>' . nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        return implode("\n", $html);
    }

    if (!class_exists('DOMDocument')) {
        return strip_tags($content, '<p><br><strong><em><u><blockquote><ul><ol><li><h2><h3><h4><a><img><figure><figcaption><hr>');
    }

    $allowed = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'em' => [],
        'u' => [],
        'blockquote' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'a' => ['href'],
        'img' => ['src', 'alt'],
        'figure' => [],
        'figcaption' => [],
        'hr' => [],
    ];

    $source = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $source->loadHTML('<?xml encoding="utf-8" ?><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $wrapper = $source->getElementsByTagName('div')->item(0);
    $output = new DOMDocument('1.0', 'UTF-8');
    $root = $output->createElement('div');
    $output->appendChild($root);

    $sanitizeNode = function ($node) use (&$sanitizeNode, $output, $allowed) {
        if ($node->nodeType === XML_TEXT_NODE) {
            return $output->createTextNode($node->nodeValue);
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return null;
        }

        $tag = strtolower($node->nodeName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object'], true)) {
            return null;
        }

        if (!isset($allowed[$tag])) {
            $fragment = $output->createDocumentFragment();
            foreach ($node->childNodes as $childNode) {
                $cleanChild = $sanitizeNode($childNode);
                if ($cleanChild) {
                    $fragment->appendChild($cleanChild);
                }
            }
            return $fragment;
        }

        $clean = $output->createElement($tag);

        if ($tag === 'a') {
            $href = castaneas_blog_sanitize_url($node->getAttribute('href'), ['allow_mailto' => true, 'allow_tel' => true]);
            if ($href !== '') {
                $clean->setAttribute('href', $href);
                if (preg_match('#^https?://#i', $href)) {
                    $clean->setAttribute('target', '_blank');
                    $clean->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }

        if ($tag === 'img') {
            $src = castaneas_blog_sanitize_url($node->getAttribute('src'));
            if ($src === '') {
                return null;
            }
            $clean->setAttribute('src', $src);
            $clean->setAttribute('alt', trim((string) $node->getAttribute('alt')));
        }

        foreach ($node->childNodes as $childNode) {
            $cleanChild = $sanitizeNode($childNode);
            if ($cleanChild) {
                $clean->appendChild($cleanChild);
            }
        }

        if ($tag === 'a' && !$clean->hasAttribute('href')) {
            $fragment = $output->createDocumentFragment();
            while ($clean->firstChild) {
                $fragment->appendChild($clean->firstChild);
            }
            return $fragment;
        }

        return $clean;
    };

    if ($wrapper) {
        foreach ($wrapper->childNodes as $childNode) {
            $cleanNode = $sanitizeNode($childNode);
            if ($cleanNode) {
                $root->appendChild($cleanNode);
            }
        }
    }

    return trim((string) $output->saveHTML($root));
}
