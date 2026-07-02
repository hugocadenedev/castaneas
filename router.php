<?php

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rawurldecode($uri ?: '/');
$fullPath = __DIR__ . $path;

$pageRoutes = [
    '/accueil' => 'index.html',
    '/connexion' => 'login.html',
    '/inscription' => 'register.html',
    '/mon-compte' => 'mon-compte.html',
    '/panier' => 'panier.html',
    '/facturation' => 'facturation.html',
    '/confirmation' => 'confirmation.html',
    '/recettes' => 'recettes.html',
    '/recette' => 'recette.html',
    '/actualites' => 'actualites.php',
    '/histoire' => 'histoire.html',
    '/cgv' => 'cgv.html',
    '/livraison-retours' => 'livraison-retours.html',
    '/maintenance' => 'maintenance.html',
    '/back-office' => 'back-office.html',
];

$legacyRedirects = [
    '/index.html' => '/accueil',
    '/login.html' => '/connexion',
    '/register.html' => '/inscription',
    '/mon-compte.html' => '/mon-compte',
    '/panier.html' => '/panier',
    '/facturation.html' => '/facturation',
    '/confirmation.html' => '/confirmation',
    '/recettes.html' => '/recettes',
    '/recette.html' => '/recette',
    '/actualites.html' => '/actualites',
    '/histoire.html' => '/histoire',
    '/cgv.html' => '/cgv',
    '/livraison-retours.html' => '/livraison-retours',
    '/maintenance.html' => '/maintenance',
    '/back-office.html' => '/back-office',
    '/creme-de-chataigne.html' => '/categorie/cremes',
    '/pates-tartiner.html' => '/categorie/pates-tartiner',
    '/huiles.html' => '/categorie/huiles',
    '/coffrets.html' => '/categorie/coffrets',
];

if (isset($legacyRedirects[$path])) {
    header('Location: ' . $legacyRedirects[$path], true, 302);
    exit;
}

if ($path !== '/' && is_file($fullPath)) {
    return false;
}

if ($path === '/sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    exit;
}

if (isset($pageRoutes[$path])) {
    require __DIR__ . '/' . $pageRoutes[$path];
    exit;
}

if (preg_match('#^/categorie/([^/]+)/?$#', $path, $matches)) {
    $_GET['cat'] = $matches[1];
    $_SERVER['QUERY_STRING'] = http_build_query($_GET);
    require __DIR__ . '/categorie.html';
    exit;
}

if (preg_match('#^/actualites/categorie/([^/]+)/?$#', $path, $matches)) {
    $_GET['category'] = $matches[1];
    $_SERVER['QUERY_STRING'] = http_build_query($_GET);
    require __DIR__ . '/actualites.php';
    exit;
}

if (preg_match('#^/actualites/([^/]+)/([^/]+)/?$#', $path, $matches)) {
    $_GET['category'] = $matches[1];
    $_GET['slug'] = $matches[2];
    $_SERVER['QUERY_STRING'] = http_build_query($_GET);
    require __DIR__ . '/actualite.php';
    exit;
}

if (preg_match('#^/produit/([^/]+)/?$#', $path, $matches)) {
    $_GET['slug'] = $matches[1];
    $_SERVER['QUERY_STRING'] = http_build_query($_GET);
    require __DIR__ . '/Fiche Produit.html';
    exit;
}

if ($path === '/' || $path === '') {
    require __DIR__ . '/index.html';
    exit;
}

$fallback = __DIR__ . '/index.html';
if (is_file($fallback)) {
    require $fallback;
    exit;
}

http_response_code(404);
echo 'Not Found';