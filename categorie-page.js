/* ============================================================
   CASTANEAS — categorie-page.js v3 (sync via data.php)
   Rendu dynamique des pages catégorie boutique.
   Lit window.CAT_SLUG, trouve la catégorie dans SiteData,
   affiche les produits. Référencé par *.html de catégorie.
   ============================================================ */
(function () {
  'use strict';

  var slug = window.CAT_SLUG;
  if (!slug || typeof SiteData === 'undefined') return;

  /* -- Trouver la catégorie -- */
  var cat = SiteData.categories.find(function (c) { return c.slug === slug; });
  if (!cat) {
    document.getElementById('cat-title').textContent = 'Catégorie introuvable';
    document.getElementById('result-count').textContent = '0 produit';
    return;
  }

  /* -- Mise à jour du titre et du breadcrumb -- */
  document.title = cat.name + ' · Castaneas';
  document.getElementById('cat-title').textContent = cat.name;
  document.getElementById('bc-name').textContent    = cat.name;

  var descEl = document.getElementById('cat-desc');
  if (descEl) descEl.textContent = cat.desc || 'Fabriqué à la main, en petites quantités';

  /* -- Bandeau : image de fond + teinte couleur -- */
  var SLUG_TO_BANNER = {
    'cremes':         'assets/banniere-cremes.png',
    'pates-tartiner': 'assets/story-atelier.jpg',
    'huiles':         'assets/story-chataigneraie.jpg',
    'coffrets':       'assets/story-atelier.jpg'
  };
  var bannerImg = document.getElementById('cat-banner-img');
  if (bannerImg) {
    var bannerSrc = cat.banner || SLUG_TO_BANNER[slug] || '';
    if (bannerSrc) bannerImg.src = bannerSrc;
  }

  var bannerBg = document.getElementById('cat-banner-bg');
  if (bannerBg && cat.color) {
    var _hex = cat.color.replace('#', '');
    var _r = parseInt(_hex.substring(0, 2), 16);
    var _g = parseInt(_hex.substring(2, 4), 16);
    var _b = parseInt(_hex.substring(4, 6), 16);
    bannerBg.style.background = 'rgba(' + _r + ',' + _g + ',' + _b + ',0.25)';
  }

  /* -- Onglets de catégorie (filter-bar) -- */
  var SLUG_TO_PAGE = {
    'cremes':         'categorie/cremes',
    'pates-tartiner': 'categorie/pates-tartiner',
    'huiles':         'categorie/huiles',
    'coffrets':       'categorie/coffrets'
  };

  /* -- Données produits -- */
  var bgClasses   = ['bg-terracotta', 'bg-dore', 'bg-sauge', 'bg-rose', 'bg-cream'];
  var allProducts = SiteData.getActiveProducts ? SiteData.getActiveProducts() : (SiteData.products || []).filter(function (p) { return p.status === 'active'; });
  var catProducts = SiteData.getProductsByCategory(cat.id);
  var grid        = document.getElementById('product-grid');
  var countEl     = document.getElementById('result-count');
  var sortOrder   = null;

  function getBg(p) {
    var idx = SiteData.categories.findIndex(function (c) { return c.id === p.category; });
    return bgClasses[(idx >= 0 ? idx : 0) % bgClasses.length];
  }

  function renderCard(p) {
    var bg    = getBg(p);
    var badge = p.badge ? '<span class="badge badge--brown">' + p.badge + '</span>' : '';
    var offers = SiteData.getProductOffers ? SiteData.getProductOffers(p) : [];
    var defaultOffer = SiteData.getProductDefaultOffer ? SiteData.getProductDefaultOffer(p) : null;
    var startingPrice = SiteData.getProductStartingPrice ? SiteData.getProductStartingPrice(p) : p.price;
    var priceFrom = offers.length > 1;
    var stock = '';
    if (p.stock === 'out_of_stock') {
      stock = '<span class="badge badge--corail" style="position:absolute;bottom:12px;left:12px;top:auto;">Rupture</span>';
    } else if (p.stock === 'low_stock') {
      stock = '<span class="badge badge--cream" style="position:absolute;bottom:12px;left:12px;top:auto;">Dernières unités</span>';
    }
    var addData = JSON.stringify({
      id: p.id,
      name: p.name,
      price: defaultOffer ? defaultOffer.totalPrice : p.price,
      image: p.image,
      variant: defaultOffer ? defaultOffer.label : p.weight,
      weight: p.weight,
      offerId: defaultOffer ? defaultOffer.id : null
    }).replace(/'/g, '&#39;');
    return '<article class="product-card" data-price="' + startingPrice + '" style="cursor:pointer;">'
      + '<div class="product-img ' + bg + '">'
      + badge
      + '<button type="button" class="fav-btn" aria-label="Ajouter aux favoris">'
      + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">'
      + '<path d="M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.5-7 10-7 10Z" stroke-linejoin="round"/></svg>'
      + '</button>'
      + '<img class="jar" src="' + p.image + '" alt="' + p.name + '" loading="lazy" style="object-fit:cover;" onerror="this.onerror=null;this.src=\'assets/product-pate-tartiner.png\'">'
      + stock
      + '</div>'
      + '<div class="product-body">'
      + '<span class="product-weight">' + (p.weight || '') + '</span>'
      + '<h3 class="product-name">' + p.name + '</h3>'
      + '<p class="product-desc">' + (p.desc || '') + '</p>'
      + '<div class="product-foot">'
      + '<span class="product-price">' + (priceFrom ? 'À partir de ' : '') + startingPrice.toFixed(2).replace('.', ',') + '\u20ac</span>'
      + '<button class="add-btn" data-add=\'' + addData + '\'>Ajouter '
      + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5v14" stroke-linecap="round"/></svg>'
      + '</button>'
      + '</div></div></article>';
  }

  function renderGrid(products) {
    sortOrder = null;
    if (document.getElementById('sort-label')) document.getElementById('sort-label').textContent = 'Trier par prix';
    countEl.textContent = products.length + ' produit' + (products.length !== 1 ? 's' : '');
    if (!products.length) {
      grid.innerHTML = '<div class="cat-empty"><h3>Aucun produit pour l\'instant.</h3><p>Revenez bientôt — de nouvelles références arrivent !</p></div>';
      return;
    }
    grid.innerHTML = products.map(renderCard).join('');
  }

  /* -- Construction des onglets -- */
  var filterTabs = document.getElementById('filter-tabs');
  if (filterTabs && SiteData.categories) {
    var tabsHtml = '<button type="button" class="filter-btn" id="tab-tous">Tous <span class="filter-count">' + allProducts.length + '</span></button>';
    tabsHtml += SiteData.categories.filter(function (c) { return c.status === 'active'; }).map(function (c) {
      var cnt  = SiteData.getProductsByCategory(c.id).length;
      var href = SiteData.getCategoryHref ? SiteData.getCategoryHref(c) : (SLUG_TO_PAGE[c.slug] || '#');
      var cls  = 'filter-btn' + (c.slug === slug ? ' active' : '');
      return '<a href="' + href + '" class="' + cls + '">'
        + (c.emoji ? c.emoji + '\u00A0' : '') + c.name
        + ' <span class="filter-count">' + cnt + '</span></a>';
    }).join('');
    filterTabs.innerHTML = tabsHtml;
    document.getElementById('tab-tous').addEventListener('click', function () {
      document.querySelectorAll('#filter-tabs .filter-btn').forEach(function (b) { b.classList.remove('active'); });
      this.classList.add('active');
      renderGrid(allProducts);
    });
  }

  /* -- Rendu initial -- */
  renderGrid(catProducts);

  /* -- Délégation sur la grille (fav + clic carte) -- */
  grid.addEventListener('click', function (e) {
    var fav = e.target.closest('.fav-btn');
    if (fav) {
      e.stopPropagation();
      fav.classList.toggle('active');
      fav.querySelector('svg').setAttribute('fill', fav.classList.contains('active') ? 'currentColor' : 'none');
      return;
    }
    var card = e.target.closest('.product-card');
    if (card && !e.target.closest('button, a')) {
      var addBtn = card.querySelector('[data-add]');
      var pid = '';
      if (addBtn) { try { pid = JSON.parse(addBtn.dataset.add).id; } catch (x) {} }
      window.location.href = pid ? 'Fiche Produit.html#' + pid : 'Fiche Produit.html';
    }
  });

  /* -- Tri par prix -- */
  window.toggleSort = function () {
    if (!sortOrder || sortOrder === 'desc') {
      sortOrder = 'asc';
      document.getElementById('sort-label').textContent = 'Prix croissant';
    } else {
      sortOrder = 'desc';
      document.getElementById('sort-label').textContent = 'Prix décroissant';
    }
    var cards = Array.from(grid.querySelectorAll('.product-card'));
    cards.sort(function (a, b) {
      var pa = parseFloat(a.dataset.price), pb = parseFloat(b.dataset.price);
      return sortOrder === 'asc' ? pa - pb : pb - pa;
    });
    cards.forEach(function (c) { grid.appendChild(c); });
    countEl.textContent = cards.length + ' produit' + (cards.length !== 1 ? 's' : '');
  };

})();
