<?php
require_once __DIR__ . '/blog-lib.php';

$dataset = castaneas_blog_dataset();
$categories = array_values(array_filter($dataset['categories'], function ($category) {
    return ($category['status'] ?? '') === 'active';
}));
$currentCategory = isset($_GET['category']) ? castaneas_blog_find_category($dataset, $_GET['category']) : null;
$posts = $currentCategory
    ? castaneas_blog_get_category_posts($dataset, $currentCategory['id'])
    : castaneas_blog_get_published_posts($dataset);
$featuredPost = !$currentCategory && !empty($posts) ? $posts[0] : null;
$pageTitle = $currentCategory
    ? (($currentCategory['metaTitle'] ?: ('Actualites ' . $currentCategory['name'] . ' · Castaneas')))
    : 'Actualites · Castaneas';
$pageDescription = $currentCategory
  ? ($currentCategory['metaDescription'] ?: castaneas_blog_plain_excerpt($currentCategory['description'], 'Découvrez les actualités Castaneas autour de la châtaigne, de la noisette, de la noix, du terroir et de nos produits gourmands artisanaux.', 155))
  : ($featuredPost
    ? ($featuredPost['metaDescription'] ?: castaneas_blog_plain_excerpt($featuredPost['content'], $featuredPost['excerpt'] ?: ('Découvrez les actualités Castaneas, nos conseils, nos coulisses et nos idées gourmandes autour de la châtaigne, de la noisette et de la noix.'), 155))
    : 'Découvrez les actualités Castaneas, nos conseils, nos coulisses et nos idées gourmandes autour de la châtaigne, de la noisette et de la noix.');
$canonicalPath = $currentCategory ? castaneas_blog_category_href($currentCategory) : '/actualites';

function castaneas_blog_listing_count(array $dataset, $categoryId) {
    return count(array_values(array_filter(castaneas_blog_get_published_posts($dataset), function ($post) use ($categoryId) {
        return in_array($categoryId, $post['categoryIds'] ?? [], true);
    })));
}
?>
<!DOCTYPE html>
<html lang="fr" class="site-shell-pending">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="canonical" href="<?php echo htmlspecialchars($canonicalPath, ENT_QUOTES, 'UTF-8'); ?>">
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
  .page { max-width: 1340px; margin: 0 auto; padding: 0 24px 100px; }
  .blog-hero__eyebrow, .section-head__eyebrow, .blog-meta, .blog-side-title {
    font-family: var(--mono);
    font-size: 11px;
    letter-spacing: .24em;
    text-transform: uppercase;
    font-weight: 700;
  }
  .blog-hero, .blog-card, .blog-empty, .blog-side-card { background:#fff; border:1px solid rgba(59,31,14,.08); box-shadow:0 8px 30px -18px rgba(59,31,14,.24); }
  .blog-hero {
    background: var(--sauge);
    border-radius: 32px;
    padding: 80px 64px;
    margin: 40px 0 48px;
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: end;
    gap: 32px;
  }
  .blog-hero__eyebrow, .section-head__eyebrow { color: var(--brown-chest); display:inline-flex; align-items:center; gap:12px; }
  .blog-hero__eyebrow::before, .section-head__eyebrow::before { content:""; width:22px; height:1px; background:var(--brown-chest); opacity:.5; }
  .blog-hero__title { margin: 18px 0 16px; font-size: clamp(40px, 5vw, 72px); line-height: 1.02; color: var(--brown-deep); }
  .blog-hero__title em { color: var(--brown-chest); font-weight: 600; }
  .blog-hero__lede { max-width: 480px; font-size: 16px; line-height: 1.55; color: var(--ink-soft); font-weight: 500; }
  .blog-hero__aside { font-family: var(--hand); font-size: 22px; color: var(--brown-chest); white-space: nowrap; }
  .blog-layout { display:grid; grid-template-columns: minmax(0,1fr) 320px; gap: 28px; align-items:start; }
  .blog-meta { color: var(--muted); letter-spacing:.14em; }
  .blog-link { display:inline-flex; align-items:center; gap:8px; margin-top: 18px; font-size: 13px; font-weight: 800; color: var(--brown-deep); text-decoration:none; }
  .section-head { display:grid; grid-template-columns:1fr auto; gap:18px; align-items:end; margin-bottom:22px; }
  .section-head__title { font-size: clamp(28px,3vw,40px); line-height:1.08; color:var(--brown-deep); }
  .section-head__title em { color: var(--brown-chest); font-weight: 600; }
  .section-head__sub { font-family: var(--hand); font-size: 24px; color: var(--brown-chest); }
  .blog-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(280px,1fr)); gap: 20px; }
  .blog-card { border-radius: 28px; overflow:hidden; display:flex; flex-direction:column; }
  .blog-card__media { position:relative; aspect-ratio: 1.2 / 1; background:#efe4d0; }
  .blog-card__media img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
  .blog-card__body { padding: 22px 22px 24px; display:flex; flex-direction:column; gap:12px; flex:1; }
  .blog-card__title { margin:0; font-size: 25px; line-height:1.12; color:var(--brown-deep); }
  .blog-card__excerpt { color: var(--ink-soft); font-size: 14px; line-height:1.6; flex:1; }
  .blog-card__cats { display:flex; flex-wrap:wrap; gap:8px; }
  .blog-card__cats a { font-size:11px; font-weight:700; color:var(--green); background:var(--green-light); padding:6px 10px; border-radius:999px; text-decoration:none; }
  .blog-side-stack { display:flex; flex-direction:column; gap:18px; position:sticky; top: 130px; }
  .blog-side-card { border-radius: 26px; padding: 24px; }
  .blog-side-title { color: var(--muted); margin-bottom: 14px; }
  .blog-side-list { display:flex; flex-direction:column; gap:12px; }
  .blog-side-item { display:block; text-decoration:none; color:inherit; padding-bottom:12px; border-bottom:1px solid rgba(59,31,14,.08); }
  .blog-side-item:last-child { border-bottom:none; padding-bottom:0; }
  .blog-side-item strong { display:block; font-size:16px; line-height:1.35; color:var(--brown-deep); margin-bottom:5px; }
  .blog-side-item span { color: var(--muted); font-size:12px; }
  .blog-empty { border-radius: 30px; padding: 38px; color: var(--muted); text-align:center; }
  .footer { background: transparent; margin-top: 0; padding: 0 16px 24px; }
  .footer-inner { max-width: 1340px; margin: 0 auto; background: var(--shell-accent); color: var(--cream); border-radius: 28px; padding: 56px 48px 28px; box-shadow: 0 12px 40px -16px rgba(59,31,14,.35); }
  @media (max-width: 1100px) {
    .blog-layout { grid-template-columns: 1fr; }
    .blog-side-stack { position: static; }
  }
  @media (max-width: 900px) {
    .blog-hero { grid-template-columns: 1fr; }
  }
  @media (max-width: 768px) {
    .navbar.pill-nav { top: 0; }
    .navbar.pill-nav .nav-inner { margin: 0 auto; border-radius: 0 0 22px 22px; padding: 10px 14px 10px 18px; }
    .promo-bar.pill { margin: 0; border-radius: 0; }
    .blog-hero { padding: 40px 24px; margin: 24px 0 40px; }
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
  <section class="blog-hero">
    <div>
      <?php if ($currentCategory) { ?>
      <div class="blog-hero__eyebrow" id="blog-hero-eyebrow"><?php echo htmlspecialchars($currentCategory['name'], ENT_QUOTES, 'UTF-8'); ?></div>
      <h1 class="blog-hero__title"><span id="blog-hero-title">Les actualites</span> <em id="blog-hero-title-em"><?php echo htmlspecialchars($currentCategory['name'], ENT_QUOTES, 'UTF-8'); ?></em></h1>
      <p class="blog-hero__lede" id="blog-hero-sub"><?php echo htmlspecialchars($currentCategory['description'] ?: 'Tous les articles lies a cette rubrique Castaneas, avec une vraie logique de maillage et de lecture par theme.', ENT_QUOTES, 'UTF-8'); ?></p>
      <?php } else { ?>
      <h1 class="blog-hero__title"><span id="blog-hero-title">Les actualites</span></h1>
      <?php } ?>
    </div>
    <?php if ($currentCategory) { ?>
    <div class="blog-hero__aside" id="blog-hero-aside">— actualités de la ferme</div>
    <?php } ?>
  </section>

  <div class="blog-layout">
    <div>
      <section>
        <div class="section-head">
          <div>
            <div class="section-head__eyebrow" id="blog-list-eyebrow"><?php echo $currentCategory ? 'Categorie active' : 'Tous les articles'; ?></div>
            <h2 class="section-head__title"><?php if ($currentCategory) { ?><span id="blog-list-title">Articles sur</span> <em id="blog-list-title-em"><?php echo htmlspecialchars($currentCategory['name'], ENT_QUOTES, 'UTF-8'); ?></em><?php } else { ?><span id="blog-list-title">Toutes les</span> <em id="blog-list-title-em">actualites.</em><?php } ?></h2>
          </div>
          <div class="section-head__sub">— <?php echo count($posts); ?> article<?php echo count($posts) > 1 ? 's' : ''; ?></div>
        </div>

        <?php if (!$posts) { ?>
          <div class="blog-empty" id="blog-empty-text">Aucun article publie pour le moment dans cette rubrique.</div>
        <?php } else { ?>
          <div class="blog-grid">
            <?php foreach ($posts as $post) { ?>
              <article class="blog-card">
                <a class="blog-card__media" href="<?php echo htmlspecialchars(castaneas_blog_post_href($post), ENT_QUOTES, 'UTF-8'); ?>">
                  <img src="<?php echo htmlspecialchars(castaneas_blog_public_url($post['coverImage'] ?: 'assets/story-atelier.jpg'), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?>">
                </a>
                <div class="blog-card__body">
                  <div class="blog-meta"><?php echo htmlspecialchars(castaneas_blog_format_date($post['publishedAt'] ?: $post['updatedAt']) . ' · ' . (int) $post['readingMinutes'] . ' min', ENT_QUOTES, 'UTF-8'); ?></div>
                  <h3 class="blog-card__title"><?php echo htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                  <p class="blog-card__excerpt"><?php echo htmlspecialchars(castaneas_blog_plain_excerpt($post['content'], $post['excerpt'], 155), ENT_QUOTES, 'UTF-8'); ?></p>
                  <div class="blog-card__cats">
                    <?php foreach (($post['categories'] ?? []) as $category) { ?>
                      <a href="<?php echo htmlspecialchars(castaneas_blog_category_href($category), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php } ?>
                  </div>
                  <a class="blog-link" href="<?php echo htmlspecialchars(castaneas_blog_post_href($post), ENT_QUOTES, 'UTF-8'); ?>">Lire l'article →</a>
                </div>
              </article>
            <?php } ?>
          </div>
        <?php } ?>
      </section>
    </div>

    <aside class="blog-side-stack">
      <div class="blog-side-card">
        <div class="blog-side-title" id="blog-cats-title">Categories</div>
        <div class="blog-side-list">
          <a class="blog-side-item" href="/actualites">
            <strong>Toutes les actualites</strong>
            <span><?php echo count(castaneas_blog_get_published_posts($dataset)); ?> article<?php echo count(castaneas_blog_get_published_posts($dataset)) > 1 ? 's' : ''; ?></span>
          </a>
          <?php foreach ($categories as $category) { ?>
            <a class="blog-side-item" href="<?php echo htmlspecialchars(castaneas_blog_category_href($category), ENT_QUOTES, 'UTF-8'); ?>">
              <strong><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
              <span><?php echo castaneas_blog_listing_count($dataset, $category['id']); ?> article<?php echo castaneas_blog_listing_count($dataset, $category['id']) > 1 ? 's' : ''; ?></span>
            </a>
          <?php } ?>
        </div>
      </div>
    </aside>
  </div>
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
<script>
(function () {
  if (typeof SiteData === 'undefined') return;

  function setText(id, value) {
    var el = document.getElementById(id);
    if (el && value) el.textContent = value;
  }

  var pageCfg = (SiteData.homepage && SiteData.homepage.actualitesPage) || {};
  var heroCfg = pageCfg.hero || {};
  var listingCfg = pageCfg.listing || {};
  var categoriesCfg = pageCfg.categories || {};
  var isCategoryPage = <?php echo $currentCategory ? 'true' : 'false'; ?>;

  if (!isCategoryPage) {
    setText('blog-hero-eyebrow', heroCfg.eyebrow);
    setText('blog-hero-title', heroCfg.title);
    setText('blog-hero-title-em', heroCfg.titleEm);
    setText('blog-hero-sub', heroCfg.subtitle);
    setText('blog-hero-aside', heroCfg.aside);
    setText('blog-list-eyebrow', listingCfg.eyebrow);
    setText('blog-list-title', listingCfg.title);
    setText('blog-list-title-em', listingCfg.titleEm);
  }

  setText('blog-cats-title', categoriesCfg.title);
  setText('blog-empty-text', pageCfg.emptyText);
})();
</script>
</body>
</html>
