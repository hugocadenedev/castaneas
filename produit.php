<?php
// Fiche produit : modèle « Fiche Produit.html » avec titre, canonical, H1, prix et
// données structurées du vrai produit générés côté serveur (voir seo-lib.php).
require_once __DIR__ . '/seo-lib.php';

$template = file_get_contents(__DIR__ . '/Fiche Produit.html');
try {
    $slug = (string) ($_GET['slug'] ?? '');
    // Produit inconnu (catalogue bien chargé) : vraie 404, non indexée ; le script affiche « Produit introuvable »
    if ($slug !== '' && castaneas_seo_products() && !castaneas_seo_find_product_by_slug($slug)) {
        http_response_code(404);
        echo str_replace('<meta name="robots" content="index,follow">', '<meta name="robots" content="noindex,follow">', $template);
        exit;
    }
    echo castaneas_seo_render_product($template, $slug);
} catch (Throwable $error) {
    // En cas de problème de données, on sert la page telle qu'avant (rendu par JavaScript)
    error_log('castaneas produit.php: ' . $error->getMessage());
    echo $template;
}
