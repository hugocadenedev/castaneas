<?php
// Page catégorie : modèle « categorie.html » avec titre, canonical, H1 et liste des
// produits (données structurées) générés côté serveur (voir seo-lib.php).
require_once __DIR__ . '/seo-lib.php';

$template = file_get_contents(__DIR__ . '/categorie.html');
try {
    echo castaneas_seo_render_category($template, (string) ($_GET['cat'] ?? ''));
} catch (Throwable $error) {
    // En cas de problème de données, on sert la page telle qu'avant (rendu par JavaScript)
    error_log('castaneas categorie.php: ' . $error->getMessage());
    echo $template;
}
