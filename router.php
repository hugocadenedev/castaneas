<?php

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rawurldecode($uri ?: '/');
$fullPath = __DIR__ . $path;

if ($path !== '/' && is_file($fullPath)) {
    return false;
}

$legacyRedirects = [
    '/creme-de-chataigne.html' => '/categorie/cremes',
    '/pates-tartiner.html' => '/categorie/pates-tartiner',
    '/huiles.html' => '/categorie/huiles',
    '/coffrets.html' => '/categorie/coffrets',
];

if (isset($legacyRedirects[$path])) {
    header('Location: ' . $legacyRedirects[$path], true, 302);
    exit;
}

if ($path === '/sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    exit;
}

if (preg_match('#^/categorie/([^/]+)/?$#', $path, $matches)) {
    $_GET['cat'] = $matches[1];
    $_SERVER['QUERY_STRING'] = http_build_query($_GET);
    require __DIR__ . '/categorie.html';
    exit;
}

if (preg_match('#^/produit/([^/]+)/?$#', $path, $matches)) {
    $_GET['slug'] = $matches[1];
    $_SERVER['QUERY_STRING'] = http_build_query($_GET);
    require __DIR__ . '/Fiche Produit.html';
    exit;
}

if ($path === '/' || $path === '') {
    require __DIR__ . '/maintenance.html';
    exit;
}

$fallback = __DIR__ . '/index.html';
if (is_file($fallback)) {
    require $fallback;
    exit;
}

http_response_code(404);
echo 'Not Found';