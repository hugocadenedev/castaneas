<?php
// Fiche produit : modèle « Fiche Produit.html » avec titre, canonical, H1, prix et
// données structurées du vrai produit générés côté serveur (voir seo-lib.php).
require_once __DIR__ . '/seo-lib.php';

$template = file_get_contents(__DIR__ . '/Fiche Produit.html');
try {
    echo castaneas_seo_render_product($template, (string) ($_GET['slug'] ?? ''));
} catch (Throwable $error) {
    // En cas de problème de données, on sert la page telle qu'avant (rendu par JavaScript)
    error_log('castaneas produit.php: ' . $error->getMessage());
    echo $template;
}
