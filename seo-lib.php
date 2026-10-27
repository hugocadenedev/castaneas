<?php
// ============================================================
//  CASTANEAS — seo-lib.php
//  Rendu serveur des balises SEO des fiches produits et catégories.
//  Les modèles HTML restent identiques : on remplace seulement les valeurs
//  de démonstration (titre, canonical, H1…) par celles du vrai produit.
//  Si le produit ou la catégorie est introuvable, le modèle est renvoyé tel quel.
// ============================================================

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/inline-images.php';
require_once __DIR__ . '/image-optimize.php';

const CASTANEAS_SEO_ORIGIN = 'https://www.castaneas.fr';

function castaneas_seo_esc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Même règle que slugify() dans site-data.js (accents retirés, & → et). */
function castaneas_seo_slugify($value) {
    $value = mb_strtolower((string) $value, 'UTF-8');
    $value = strtr($value, [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
    ]);
    $value = str_replace('&', ' et ', $value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);

    return trim(preg_replace('/-{2,}/', '-', $value), '-');
}

function castaneas_seo_read($key) {
    $raw = castaneas_storage_read_raw($key);
    $data = $raw ? json_decode($raw, true) : null;

    return is_array($data) ? $data : [];
}

/** Produits avec le même slug final que withProductSlugs() dans site-data.js. */
function castaneas_seo_products() {
    static $products = null;
    if ($products !== null) {
        return $products;
    }

    $products = [];
    $used = [];
    foreach (castaneas_apply_converted_images(castaneas_seo_read('products')) as $product) {
        if (!is_array($product) || empty($product['id'])) {
            continue;
        }
        $base = castaneas_seo_slugify($product['slug'] ?? '');
        if ($base === '') {
            $base = implode('-', array_filter([castaneas_seo_slugify($product['name'] ?? ''), castaneas_seo_slugify($product['weight'] ?? '')]));
        }
        if ($base === '') {
            $base = castaneas_seo_slugify($product['id']) ?: 'produit';
        }
        $slug = $base;
        $index = 2;
        while (isset($used[$slug]) && $used[$slug] !== $product['id']) {
            $slug = $base . '-' . $index++;
        }
        $used[$slug] = $product['id'];
        $product['slug'] = $slug;
        $products[] = $product;
    }

    return $products;
}

function castaneas_seo_categories() {
    static $categories = null;
    if ($categories === null) {
        // Bannières intégrées converties en fichiers (sert aussi d'image de partage)
        $categories = array_values(array_filter(castaneas_externalize_inline_images(castaneas_seo_read('categories')), 'is_array'));
    }

    return $categories;
}

function castaneas_seo_category_slug(array $category) {
    return castaneas_seo_slugify(($category['slug'] ?? '') ?: ($category['name'] ?? ''));
}

/** Même règle que getCategoryBySlug() (avec les anciens alias pâte à tartiner). */
function castaneas_seo_find_category_by_slug($slug) {
    $clean = castaneas_seo_slugify($slug);
    foreach (castaneas_seo_categories() as $category) {
        $canonical = castaneas_seo_category_slug($category);
        $aliases = [$canonical];
        if (in_array($canonical, ['pates-a-tartiner', 'pate-a-tartiner'], true)) {
            $aliases = array_merge($aliases, ['pates-tartiner', 'pate-a-tartiner', 'pates-a-tartiner']);
        }
        if ($canonical !== '' && in_array($clean, $aliases, true)) {
            return $category;
        }
    }

    return null;
}

function castaneas_seo_find_category_by_id($id) {
    foreach (castaneas_seo_categories() as $category) {
        if ((string) ($category['id'] ?? '') === (string) $id) {
            return $category;
        }
    }

    return null;
}

function castaneas_seo_is_active(array $item) {
    return ($item['status'] ?? 'active') === 'active';
}

/** URL absolue d'une image du site ; les images intégrées (data:) sont ignorées. */
function castaneas_seo_image_url($path) {
    $path = trim((string) $path);
    if ($path === '' || stripos($path, 'data:') === 0) {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return CASTANEAS_SEO_ORIGIN . '/' . str_replace('%2F', '/', rawurlencode(ltrim($path, '/')));
}

function castaneas_seo_site_path($path) {
    $path = trim((string) $path);
    if ($path === '' || preg_match('#^(data:|https?://|/)#i', $path)) {
        return $path;
    }

    return '/' . $path;
}

function castaneas_seo_text($value, $max = 0) {
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8')));
    if ($max > 0 && mb_strlen($text, 'UTF-8') > $max) {
        $text = rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . '…';
    }

    return $text;
}

/** Prix de l'offre par défaut, mêmes règles que getProductOffers() (coffret = somme des composants). */
function castaneas_seo_product_price(array $product) {
    if (($product['kind'] ?? '') === 'coffret') {
        $total = 0.0;
        foreach (($product['boxItems'] ?? []) as $item) {
            if (is_array($item) && !empty($item['productId']) && is_numeric($item['unitPrice'] ?? null)) {
                $total += (float) $item['unitPrice'];
            }
        }
        if ($total > 0) {
            return round($total, 2);
        }
    }

    return round((float) ($product['price'] ?? 0), 2);
}

function castaneas_seo_price_label($price) {
    return number_format((float) $price, 2, ',', ' ') . '€';
}

function castaneas_seo_json_ld(array $data) {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
}

/** Remplace la valeur d'une balise <meta>/<link> repérée par son attribut d'identification. */
function castaneas_seo_set_attr($html, $tagPattern, $attr, $value) {
    return preg_replace_callback('#<' . $tagPattern . '[^>]*>#i', static function ($match) use ($attr, $value) {
        return preg_replace('#\s' . $attr . '="[^"]*"#', ' ' . $attr . '="' . castaneas_seo_esc($value) . '"', $match[0], 1);
    }, $html, 1);
}

function castaneas_seo_apply_head($html, array $seo) {
    $html = preg_replace('#<title>.*?</title>#s', '<title>' . castaneas_seo_esc($seo['title']) . '</title>', $html, 1);
    $html = castaneas_seo_set_attr($html, 'meta name="description"', 'content', $seo['description']);
    $html = castaneas_seo_set_attr($html, 'link rel="canonical"', 'href', $seo['url']);
    $html = castaneas_seo_set_attr($html, 'meta property="og:title"', 'content', $seo['title']);
    $html = castaneas_seo_set_attr($html, 'meta property="og:description"', 'content', $seo['description']);
    $html = castaneas_seo_set_attr($html, 'meta property="og:url"', 'content', $seo['url']);
    $html = castaneas_seo_set_attr($html, 'meta name="twitter:title"', 'content', $seo['title']);
    $html = castaneas_seo_set_attr($html, 'meta name="twitter:description"', 'content', $seo['description']);
    if (!empty($seo['image'])) {
        $html = castaneas_seo_set_attr($html, 'meta property="og:image"', 'content', $seo['image']);
    }

    return $html;
}

function castaneas_seo_replace_jsonld($html, $id, array $data) {
    return preg_replace_callback('#<script type="application/ld\+json" id="' . preg_quote($id, '#') . '">.*?</script>#s', static function () use ($id, $data) {
        return '<script type="application/ld+json" id="' . $id . '">' . castaneas_seo_json_ld($data) . '</script>';
    }, $html, 1);
}

function castaneas_seo_find_product_by_slug($slug) {
    $clean = castaneas_seo_slugify($slug);
    foreach (castaneas_seo_products() as $product) {
        if ($product['slug'] === $clean && castaneas_seo_is_active($product)) {
            return $product;
        }
    }

    return null;
}

function castaneas_seo_render_product($html, $slug) {
    $product = castaneas_seo_find_product_by_slug($slug);
    if (!$product) {
        return $html;
    }

    $name = trim((string) ($product['name'] ?? ''));
    $weight = trim((string) ($product['weight'] ?? ''));
    $fullName = trim($name . ' ' . $weight);
    $url = CASTANEAS_SEO_ORIGIN . '/produit/' . rawurlencode($product['slug']);
    $description = castaneas_seo_text($product['desc'] ?? '', 155) ?: 'Découvrez ' . $fullName . ', fabriqué à la main par Castaneas dans le Tarn-et-Garonne.';
    $images = array_values(array_unique(array_filter(array_map('castaneas_seo_site_path', array_merge([$product['image'] ?? ''], is_array($product['images'] ?? null) ? $product['images'] : [])), static function ($path) {
        return $path !== '' && stripos($path, 'data:') !== 0;
    })));
    $category = castaneas_seo_find_category_by_id($product['category'] ?? '');
    $price = castaneas_seo_product_price($product);
    $availability = [
        'out_of_stock' => 'https://schema.org/OutOfStock',
        'low_stock' => 'https://schema.org/LimitedAvailability',
    ][$product['stock'] ?? ''] ?? 'https://schema.org/InStock';

    $html = castaneas_seo_apply_head($html, [
        'title' => $fullName . ' · Castaneas',
        'description' => $description,
        'url' => $url,
        'image' => $images ? castaneas_seo_image_url($images[0]) : '',
    ]);

    $productLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $fullName,
        'description' => castaneas_seo_text($product['desc'] ?? '', 5000) ?: $description,
        'brand' => ['@type' => 'Brand', 'name' => 'Castaneas'],
        'url' => $url,
        'offers' => [
            '@type' => 'Offer',
            'priceCurrency' => 'EUR',
            'price' => number_format($price, 2, '.', ''),
            'availability' => $availability,
            'url' => $url,
        ],
    ];
    if ($images) {
        $productLd['image'] = array_map('castaneas_seo_image_url', $images);
    }
    if (!empty($product['sucrineId'])) {
        $productLd['sku'] = (string) $product['sucrineId'];
    }
    if ($category) {
        $productLd['category'] = (string) ($category['name'] ?? '');
    }
    $html = castaneas_seo_replace_jsonld($html, 'seo-jsonld-product', $productLd);

    // Contenu visible : fil d'Ariane, H1, description, prix, galerie (au lieu de la démo « Châtaigne vanillée »)
    $crumbCategory = $category
        ? '<a href="/categorie/' . castaneas_seo_esc(castaneas_seo_category_slug($category)) . '">' . castaneas_seo_esc($category['name'] ?? '') . '</a>'
        : '<a href="/categorie/cremes">Crèmes de châtaigne</a>';
    // Uniquement le fil d'Ariane (le même lien existe aussi dans le pied de page)
    $html = preg_replace('#(<nav class="crumb">.*?)<a href="/categorie/cremes">Crèmes de châtaigne</a>#s', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $crumbCategory), $html, 1);
    $html = str_replace('<span class="current">Châtaigne vanillée · 150g</span>', '<span class="current">' . castaneas_seo_esc($name . ($weight !== '' ? ' · ' . $weight : '')) . '</span>', $html);
    $html = str_replace('<h1 class="info__title">Châtaigne<br><em>vanillée.</em></h1>', '<h1 class="info__title">' . castaneas_seo_esc($name) . '</h1>', $html);
    if (trim((string) ($product['desc'] ?? '')) !== '') {
        $html = preg_replace('#<p class="info__desc">.*?</p>#s', '<p class="info__desc">' . castaneas_seo_esc(castaneas_seo_text($product['desc'])) . '</p>', $html, 1);
    }
    $html = str_replace('<span class="info__price" id="prod-price">7,50€</span>', '<span class="info__price" id="prod-price">' . castaneas_seo_esc(castaneas_seo_price_label($price)) . '</span>', $html);
    $html = str_replace('<span class="info__price-unit" id="prod-unit">Pot de 150g · 5,00€ / 100g</span>', '<span class="info__price-unit" id="prod-unit">' . ($weight !== '' ? castaneas_seo_esc('Pot de ' . $weight) : '') . '</span>', $html);

    if ($images) {
        $alt = castaneas_seo_esc('Castaneas — ' . $fullName);
        $html = str_replace('<img src="/assets/product-huile-noisettes.jpg" alt="Pot Castaneas — Châtaigne vanillée 150g">', '<img src="' . castaneas_seo_esc($images[0]) . '" alt="' . $alt . '">', $html);
        $thumbs = '';
        foreach ($images as $index => $image) {
            $thumbs .= "\n        " . '<button class="gallery__thumb' . ($index === 0 ? ' on' : '') . '"><img src="' . castaneas_seo_esc($image) . '" alt="' . $alt . '" loading="lazy"></button>';
        }
        $html = preg_replace('#(<div class="gallery__thumbs">).*?(\s*</div>)#s', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $thumbs) . '$2', $html, 1);
    }

    return $html;
}

function castaneas_seo_render_category($html, $slug) {
    $category = castaneas_seo_find_category_by_slug($slug);
    if (!$category || !castaneas_seo_is_active($category)) {
        return $html;
    }

    $name = trim((string) ($category['name'] ?? ''));
    $categorySlug = castaneas_seo_category_slug($category);
    $url = CASTANEAS_SEO_ORIGIN . '/categorie/' . rawurlencode($categorySlug);
    $description = castaneas_seo_text($category['desc'] ?? '', 155) ?: $name . ' Castaneas : produits gourmands fabriqués à la main autour de la châtaigne et de la noisette, dans le Tarn-et-Garonne.';
    $banner = castaneas_seo_image_url($category['banner'] ?? '');

    $products = array_values(array_filter(castaneas_seo_products(), static function ($product) use ($category) {
        return castaneas_seo_is_active($product) && (string) ($product['category'] ?? '') === (string) ($category['id'] ?? '');
    }));

    $html = castaneas_seo_apply_head($html, [
        'title' => $name . ' · Castaneas',
        'description' => $description,
        'url' => $url,
        'image' => $banner,
    ]);

    $items = [];
    foreach ($products as $index => $product) {
        $items[] = [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'url' => CASTANEAS_SEO_ORIGIN . '/produit/' . rawurlencode($product['slug']),
            'name' => trim(($product['name'] ?? '') . ' ' . ($product['weight'] ?? '')),
        ];
    }
    $html = castaneas_seo_replace_jsonld($html, 'seo-jsonld-category', [
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => $name,
        'description' => $description,
        'url' => $url,
        'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($items), 'itemListElement' => $items],
    ]);

    return str_replace('<h1 class="cat-banner__title" id="cat-title">Catégorie</h1>', '<h1 class="cat-banner__title" id="cat-title">' . castaneas_seo_esc($name) . '</h1>', $html);
}
