<?php
require_once __DIR__ . '/blog-lib.php';

$dataset = castaneas_blog_dataset();
$categorySlug = $_GET['category'] ?? '';
$postSlug = $_GET['slug'] ?? '';
$post = castaneas_blog_find_post($dataset, $categorySlug, $postSlug);

if (!$post) {
    http_response_code(404);
    $pageTitle = 'Article introuvable · Castaneas';
    $pageDescription = 'Cet article Castaneas est introuvable ou n\'est plus disponible.';
} else {
    $pageTitle = $post['metaTitle'] ?: ($post['title'] . ' · Castaneas');
  $pageDescription = $post['metaDescription'] ?: castaneas_blog_plain_excerpt($post['content'], $post['excerpt'] ?: 'Article Castaneas autour de la châtaigne, de la noisette, de la noix et de nos produits gourmands artisanaux.', 155);
}

$relatedPosts = [];
if ($post && !empty($post['primaryCategoryId'])) {
    foreach (castaneas_blog_get_category_posts($dataset, $post['primaryCategoryId']) as $candidate) {
        if ($candidate['id'] === $post['id']) {
            continue;
        }
        $relatedPosts[] = $candidate;
        if (count($relatedPosts) >= 3) {
            break;
        }
    }
}

function castaneas_blog_render_content($content) {
    $content = trim((string) $content);
    if ($content === '') {
        return '<p>Aucun contenu n\'a encore ete renseigne pour cet article.</p>';
    }

    if (preg_match('/<[^>]+>/', $content)) {
        return $content;
    }

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
?>
<!DOCTYPE html>
<html lang="fr" class="site-shell-pending">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
<?php if ($post) { ?>
<link rel="canonical" href="<?php echo htmlspecialchars(castaneas_blog_post_href($post), ENT_QUOTES, 'UTF-8'); ?>">
<?php } ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@400;600&family=Fraunces:ital,opsz,wght,SOFT@0,9..144,300..700,0..100;1,9..144,300..700,0..100&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/styles.css?v=7">
<style>
  :root {
    --serif: 'Fraunces', Georgia, serif;
    --sans: 'Inter', -apple-system, Arial, sans-serif;
    --hand: 'Caveat', cursive;
    --mono: 'JetBrains Mono', ui-monospace, monospace;
  }
  body { font-family: var(--sans); letter-spacing: -.005em; }
  h1,h2,h3,h4 { font-family: var(--serif); letter-spacing: -.02em; }
  .navbar.pill-nav { background: transparent; border-bottom: none; position: sticky; top: 14px; z-index: 50; }
  .navbar.pill-nav .nav-inner { max-width: 1340px; margin: 16px auto 0; background: #fff; border-radius: 999px; padding: 12px 16px 12px 28px; box-shadow: 0 6px 24px -10px rgba(59,31,14,.18); }
  .navbar.pill-nav .nav-links { font-weight: 600; }
  .navbar.pill-nav .nav-links a { color: var(--brown-deep); }
  .navbar.pill-nav .nav-actions { background: transparent; border-radius: 0; padding: 0; gap: 8px; }
  .navbar.pill-nav .icon-btn { width: 40px; height: 40px; background: #EDEFE5; border-radius: 999px; }
  .cart-pill { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px 8px 12px; background: #EDEFE5; color: var(--brown-deep); font-weight: 700; font-size: 13px; border-radius: 999px; border: none; cursor: pointer; }
  .promo-bar.pill { max-width: 1340px; margin: 14px auto 0; border-radius: 999px; }
  .page { max-width: 1240px; margin: 0 auto; padding: 0 24px 100px; }
  .crumb { font-family: var(--mono); font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: var(--muted); font-weight: 700; padding: 28px 0 0; display:flex; gap:8px; flex-wrap:wrap; }
  .crumb a { color: inherit; text-decoration:none; }
  .crumb span { opacity: .6; }
  .article-hero { display:grid; grid-template-columns: minmax(0,1fr) minmax(320px, .9fr); gap: 34px; align-items:start; margin: 28px 0 34px; }
  .article-hero__body { background: linear-gradient(135deg, #fcf7ee 0%, #fff 60%, #eef2e8 100%); border-radius: 34px; padding: 40px 42px; border:1px solid rgba(59,31,14,.08); box-shadow:0 8px 30px -18px rgba(59,31,14,.24); }
  .article-hero__eyebrow, .article-meta, .article-cat, .section-head__eyebrow { font-family: var(--mono); font-size: 11px; letter-spacing: .22em; text-transform: uppercase; font-weight: 700; }
  .article-hero__eyebrow, .section-head__eyebrow { color: var(--brown-chest); display:inline-flex; align-items:center; gap:12px; }
  .article-hero__eyebrow::before, .section-head__eyebrow::before { content:""; width:22px; height:1px; background: var(--brown-chest); opacity:.5; }
  .article-title { margin: 16px 0 18px; font-size: clamp(38px,4.4vw,62px); line-height:1.02; color: var(--brown-deep); }
  .article-excerpt { font-size: 17px; line-height:1.65; color: var(--ink-soft); max-width: 58ch; }
  .article-meta { display:flex; flex-wrap:wrap; gap:12px; color:var(--muted); margin-top: 22px; }
  .article-cats { display:flex; flex-wrap:wrap; gap:8px; margin-top: 18px; }
  .article-cat { display:inline-flex; align-items:center; padding:8px 12px; border-radius:999px; background: var(--green-light); color: var(--green); text-decoration:none; letter-spacing:.14em; }
  .article-cover { position:relative; aspect-ratio: 4 / 4.1; border-radius: 32px; overflow:hidden; background:#efe3d1; border:1px solid rgba(59,31,14,.08); box-shadow:0 8px 30px -18px rgba(59,31,14,.24); }
  .article-cover img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
  .article-layout { display:block; }
  .article-content { background:#fff; border-radius: 32px; padding: 38px 40px; border:1px solid rgba(59,31,14,.08); box-shadow:0 8px 30px -18px rgba(59,31,14,.24); }
  .article-content p, .article-content li { font-size: 16px; line-height: 1.78; color: var(--ink); }
  .article-content p + p { margin-top: 16px; }
  .article-content h2 { font-size: 30px; color: var(--brown-deep); margin: 34px 0 14px; }
  .article-content h3 { font-size: 23px; color: var(--brown-deep); margin: 26px 0 12px; }
  .article-content ul, .article-content ol { padding-left: 22px; margin: 16px 0; }
  .article-content blockquote { margin: 22px 0; padding: 18px 22px; border-left: 3px solid var(--brown-chest); background: var(--cream-soft); color: var(--ink-soft); font-size: 17px; }
  .related { margin-top: 42px; }
  .section-head { display:grid; grid-template-columns:1fr auto; gap:18px; align-items:end; margin-bottom:22px; }
  .section-head__title { font-size: clamp(28px,3vw,40px); line-height:1.08; color:var(--brown-deep); }
  .section-head__title em { color: var(--brown-chest); font-weight:600; }
  .section-head__sub { font-family: var(--hand); font-size: 24px; color: var(--brown-chest); }
  .related-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr)); gap:20px; }
  .related-card { background:#fff; border-radius: 26px; overflow:hidden; border:1px solid rgba(59,31,14,.08); box-shadow:0 8px 30px -18px rgba(59,31,14,.24); text-decoration:none; color:inherit; }
  .related-card__media { position:relative; aspect-ratio:1.15 / 1; background:#efe3d1; }
  .related-card__media img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
  .related-card__body { padding: 20px 20px 22px; }
  .related-card__title { font-size: 22px; line-height:1.15; margin:10px 0 8px; color:var(--brown-deep); }
  .related-card__excerpt { color: var(--ink-soft); font-size:14px; line-height:1.6; }
  .article-empty { background:#fff; border-radius:30px; padding:40px; border:1px solid rgba(59,31,14,.08); text-align:center; color:var(--muted); margin-top: 32px; }
  .footer { background: transparent; margin-top: 0; padding: 0 16px 24px; }
  .footer-inner { max-width: 1340px; margin: 0 auto; background: var(--shell-accent); color: var(--cream); border-radius: 28px; padding: 56px 48px 28px; box-shadow: 0 12px 40px -16px rgba(59,31,14,.35); }
  @media (max-width: 1100px) {
    .article-hero { grid-template-columns: 1fr; }
    .article-cover { aspect-ratio: 16 / 10; }
  }
  @media (max-width: 768px) {
    .navbar.pill-nav { top: 0; }
    .navbar.pill-nav .nav-inner { margin: 0 auto; border-radius: 0 0 22px 22px; padding: 10px 14px 10px 18px; }
    .promo-bar.pill { margin: 0; border-radius: 0; }
    .article-hero__body, .article-content { padding: 28px 24px; border-radius: 24px; }
    .footer-inner { padding: 40px 24px 24px; border-radius: 22px; }
  }
</style>
</head>
<body>
<div class="promo-bar pill">Livraison offerte dès 40€ d'achat — récolte 2025 disponible 🌰</div>
<header class="navbar pill-nav">
  <div class="nav-inner">
    <a href="/accueil" class="brand" aria-label="Castaneas"><img src="/assets/Castaneas-logo (3).svg" alt="Castaneas" class="brand-logo"></a>
    <nav class="nav-links">
      <a href="/accueil">Accueil</a>
      <a href="/recettes">Recettes</a>
      <a href="/actualites" class="active">Actualites</a>
      <a href="/histoire">Notre histoire</a>
    </nav>
    <div class="nav-actions">
      <a href="/connexion" class="icon-btn" aria-label="Mon compte">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8" stroke-linecap="round"/></svg>
      </a>
      <button class="cart-pill" aria-label="Panier" data-cart-toggle>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M5 7h14l-1.5 11.5a2 2 0 0 1-2 1.5h-7a2 2 0 0 1-2-1.5L5 7Z"/><path d="M9 7V5a3 3 0 0 1 6 0v2" stroke-linecap="round"/></svg>
        <span data-cart-count>0</span>
      </button>
    </div>
  </div>
</header>

<main class="page">
  <div class="crumb">
    <a href="/actualites">Actualites</a>
    <?php if ($post && !empty($post['primaryCategory'])) { ?><span>/</span><a href="<?php echo htmlspecialchars(castaneas_blog_category_href($post['primaryCategory']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($post['primaryCategory']['name'], ENT_QUOTES, 'UTF-8'); ?></a><?php } ?>
    <?php if ($post) { ?><span>/</span><strong><?php echo htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?></strong><?php } ?>
  </div>

  <?php if (!$post) { ?>
    <div class="article-empty">Cet article est introuvable. Revenez au <a href="/actualites">blog Castaneas</a>.</div>
  <?php } else { ?>
    <section class="article-hero">
      <div class="article-hero__body">
        <div class="article-hero__eyebrow"><?php echo htmlspecialchars($post['eyebrow'] ?: 'Actualite Castaneas', ENT_QUOTES, 'UTF-8'); ?></div>
        <h1 class="article-title"><?php echo htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="article-excerpt"><?php echo htmlspecialchars($post['excerpt'] ?: castaneas_blog_plain_excerpt($post['content'], '', 190), ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="article-meta">
          <span><?php echo htmlspecialchars(castaneas_blog_format_date($post['publishedAt'] ?: $post['updatedAt']), ENT_QUOTES, 'UTF-8'); ?></span>
          <span><?php echo htmlspecialchars($post['author'], ENT_QUOTES, 'UTF-8'); ?></span>
          <span><?php echo (int) $post['readingMinutes']; ?> min de lecture</span>
        </div>
        <div class="article-cats">
          <?php foreach (($post['categories'] ?? []) as $category) { ?>
            <a class="article-cat" href="<?php echo htmlspecialchars(castaneas_blog_category_href($category), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></a>
          <?php } ?>
        </div>
      </div>
      <div class="article-cover">
        <img src="<?php echo htmlspecialchars(castaneas_blog_public_url($post['coverImage'] ?: 'assets/story-chataigneraie.jpg'), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?>">
      </div>
    </section>

    <div class="article-layout">
      <article class="article-content"><?php echo castaneas_blog_render_content($post['content']); ?></article>
    </div>

    <?php if ($relatedPosts) { ?>
      <section class="related">
        <div class="section-head">
          <div>
            <div class="section-head__eyebrow">Maillage interne</div>
            <h2 class="section-head__title">Articles lies a <em><?php echo htmlspecialchars($post['primaryCategory']['name'] ?? 'ce theme', ENT_QUOTES, 'UTF-8'); ?></em></h2>
          </div>
          <div class="section-head__sub">— a lire ensuite</div>
        </div>
        <div class="related-grid">
          <?php foreach ($relatedPosts as $relatedPost) { ?>
            <a class="related-card" href="<?php echo htmlspecialchars(castaneas_blog_post_href($relatedPost), ENT_QUOTES, 'UTF-8'); ?>">
              <div class="related-card__media"><img src="<?php echo htmlspecialchars(castaneas_blog_public_url($relatedPost['coverImage'] ?: 'assets/story-atelier.jpg'), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($relatedPost['title'], ENT_QUOTES, 'UTF-8'); ?>"></div>
              <div class="related-card__body">
                <div class="article-meta"><?php echo htmlspecialchars(castaneas_blog_format_date($relatedPost['publishedAt'] ?: $relatedPost['updatedAt']), ENT_QUOTES, 'UTF-8'); ?></div>
                <h3 class="related-card__title"><?php echo htmlspecialchars($relatedPost['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                <p class="related-card__excerpt"><?php echo htmlspecialchars(castaneas_blog_plain_excerpt($relatedPost['content'], $relatedPost['excerpt'], 120), ENT_QUOTES, 'UTF-8'); ?></p>
              </div>
            </a>
          <?php } ?>
        </div>
      </section>
    <?php } ?>
  <?php } ?>
</main>

<footer class="footer">
  <div class="footer-inner">
    <div class="footer-grid">
      <div><div class="footer-brand"><img src="/assets/Castaneas-logo (3).svg" alt="Castaneas" class="footer-brand-logo"></div></div>
      <div><h4>Boutique</h4><ul><li><a href="/categorie/cremes">Cremes de chataigne</a></li><li><a href="/coffrets">Coffrets</a></li></ul></div>
      <div><ul><li><a href="/histoire">Notre histoire</a></li><li><a href="/recettes">Recettes</a></li><li><a href="/actualites">Actualites</a></li></ul></div>
      <div><h4>Informations</h4><ul><li><a href="/livraison-retours">Livraison</a></li><li><a href="/cgv">CGV</a></li><li><a href="mailto:contact@castaneas.fr">Nous contacter</a></li></ul></div>
    </div>
  </div>
</footer>
<script src="/data.php?v=2"></script><script src="/site-data.js?v=5"></script>
<script src="/cart.js"></script>
</body>
</html>
