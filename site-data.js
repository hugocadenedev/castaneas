/* ============================================================
  CASTANEAS — site-data.js v4 (server-only)
   Sources de données (par priorité) :
     1. window.CASTANEAS_DATA injecté par data.php (serveur)
     2. localStorage (back-office en ligne)
   Aucune donnée hardcodée — si aucune source n'est disponible,
   les tableaux sont vides.
   ============================================================ */
(function () {
  'use strict';

  /* ---------- Clés localStorage (partagées avec le back-office) ---------- */
  var KEYS = {
    products:   'castaneas_admin_products',
    categories: 'castaneas_admin_categories',
    orders:     'castaneas_admin_orders',
    cart:       'castaneas_cart_v1',
    recipes:    'castaneas_admin_recipes',
    homepage:   'castaneas_homepage_v1'
  };

  /* ---------- Helpers de lecture ---------- */
  function loadStorage(key) {
    try {
      var v = localStorage.getItem(key);
      if (!v) return null;
      var parsed = JSON.parse(v);
      return (Array.isArray(parsed) && parsed.length > 0) ? parsed : null;
    } catch (e) { return null; }
  }

  function loadStorageObject(key) {
    try {
      var v = localStorage.getItem(key);
      if (!v) return null;
      var parsed = JSON.parse(v);
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
    } catch (e) { return null; }
  }

  /* Purge les blob URLs corrompues stockées dans cat.href */
  function sanitizeCategories(cats) {
    if (!cats) return cats;
    return cats.map(function (c) {
      if (c.href && (c.href.startsWith('blob:') || c.href.startsWith('http'))) {
        var clean = Object.assign({}, c);
        delete clean.href;
        return clean;
      }
      return c;
    });
  }

  function slugify(value) {
    return String(value || '')
      .toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/&/g, ' et ')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .replace(/-{2,}/g, '-');
  }

  function getCategorySlugAliases(category) {
    var canonical = slugify(category && (category.slug || category.name));
    if (!canonical) return [];

    var aliases = [canonical];
    if (canonical === 'pates-a-tartiner') {
      aliases.push('pates-tartiner', 'pate-a-tartiner');
    }

    return aliases;
  }

  function withProductSlugs(products) {
    var used = {};
    return (products || []).map(function (product) {
      if (!product || !product.id) return product;

      var baseSlug = slugify(product.slug)
        || [slugify(product.name), slugify(product.weight)].filter(Boolean).join('-')
        || slugify(product.id)
        || 'produit';

      var finalSlug = baseSlug;
      var index = 2;
      while (used[finalSlug] && used[finalSlug] !== product.id) {
        finalSlug = baseSlug + '-' + index;
        index += 1;
      }
      used[finalSlug] = product.id;

      return Object.assign({}, product, { slug: finalSlug });
    });
  }

  /* ---------- Données serveur injectées par data.php (priorité max) ---------- */
  var _srv = window.CASTANEAS_DATA || {};

  function productBundleLabel(qty) {
    var labels = {
      1: 'À l\'unité',
      2: 'Par deux',
      3: 'Par trois',
      4: 'Par quatre',
      5: 'Par cinq',
      6: 'Par six'
    };
    return labels[qty] || ('Par ' + qty);
  }

  function getProductOffers(product) {
    if (!product) return [];

    if (product.kind === 'coffret') {
      var boxItems = Array.isArray(product.boxItems) ? product.boxItems.filter(function (item) {
        return item && item.productId && isFinite(item.unitPrice);
      }) : [];
      var total = boxItems.reduce(function (sum, item) { return sum + Number(item.unitPrice || 0); }, 0);
      var count = boxItems.length;
      return [{
        id: product.id + '__coffret',
        label: product.weight || 'Coffret',
        subtitle: count ? count + ' produit' + (count > 1 ? 's' : '') : 'Coffret',
        quantity: count || 1,
        unitPrice: count ? total / count : total,
        totalPrice: total || Number(product.price || 0),
        kind: 'coffret'
      }];
    }

    var offers = [{
      id: product.id + '__unit',
      label: 'À l\'unité',
      subtitle: product.weight ? '1 × ' + product.weight : '1 pièce',
      quantity: 1,
      unitPrice: Number(product.price || 0),
      totalPrice: Number(product.price || 0),
      kind: 'single'
    }];

    (product.quantityOffers || []).forEach(function (offer, index) {
      var qty = parseInt(offer.qty, 10) || 0;
      var unitPrice = Number(offer.unitPrice || 0);
      if (qty <= 1 || !isFinite(unitPrice)) return;
      offers.push({
        id: product.id + '__pack_' + index,
        label: offer.label || productBundleLabel(qty),
        subtitle: product.weight ? qty + ' × ' + product.weight : qty + ' pièces',
        quantity: qty,
        unitPrice: unitPrice,
        totalPrice: qty * unitPrice,
        kind: 'pack'
      });
    });

    return offers.sort(function (a, b) { return a.quantity - b.quantity; });
  }

  function getProductDefaultOffer(product) {
    var offers = getProductOffers(product);
    return offers[0] || null;
  }

  function getProductStartingPrice(product) {
    var offers = getProductOffers(product);
    if (!offers.length) return Number(product && product.price || 0);
    return offers.reduce(function (min, offer) {
      return offer.totalPrice < min ? offer.totalPrice : min;
    }, offers[0].totalPrice);
  }

  function getDefaultPromoMessage() {
    return 'Livraison offerte dès 40€ d\'achat — récolte 2025 disponible 🌰';
  }

  function getPromoMessage(homepage) {
    return (homepage && typeof homepage.promoMessage === 'string' && homepage.promoMessage.trim())
      ? homepage.promoMessage.trim()
      : getDefaultPromoMessage();
  }

  /* ---------- API publique ---------- */
  var SiteData = {
    categories: (function () {
      if (_srv.categories && _srv.categories.length > 0) return sanitizeCategories(_srv.categories);
      var raw = loadStorage(KEYS.categories);
      var clean = sanitizeCategories(raw);
      if (clean && clean !== raw) {
        try { localStorage.setItem(KEYS.categories, JSON.stringify(clean)); } catch (e) {}
      }
      return clean || [];
    }()),
    products: (function () {
      var source = (_srv.products && _srv.products.length > 0) ? _srv.products : (loadStorage(KEYS.products) || []);
      return withProductSlugs(source);
    }()),
    recipes:  (_srv.recipes  && _srv.recipes.length  > 0) ? _srv.recipes  : (loadStorage(KEYS.recipes)  || []),
    homepage: (_srv.homepage && typeof _srv.homepage === 'object') ? _srv.homepage : (loadStorageObject(KEYS.homepage) || {}),

    /** Toutes les catégories visibles dans le header */
    getHeaderCategories: function () {
      return this.categories.filter(function (c) { return c.status === 'active' && c.header; });
    },

    /** Toutes les catégories actives */
    getActiveCategories: function () {
      return this.categories.filter(function (c) { return c.status === 'active'; });
    },

    /** Produits actifs d'une catégorie */
    getProductsByCategory: function (catId) {
      return this.products.filter(function (p) { return p.category === catId && p.status === 'active'; });
    },

    /** Tous les produits actifs */
    getActiveProducts: function () {
      return this.products.filter(function (p) { return p.status === 'active'; });
    },

    /** Catégorie par ID */
    getCategoryById: function (id) {
      return this.categories.find(function (c) { return c.id === id; }) || null;
    },

    /** Catégorie par slug, avec compatibilité sur anciens slugs */
    getCategoryBySlug: function (slug) {
      var cleanSlug = slugify(slug);
      return this.categories.find(function (c) {
        return getCategorySlugAliases(c).indexOf(cleanSlug) !== -1;
      }) || null;
    },

    /** Slug SEO d'une catégorie */
    getCategorySlug: function (catOrId) {
      var category = typeof catOrId === 'string' ? this.getCategoryById(catOrId) : catOrId;
      return slugify(category && (category.slug || category.name));
    },

    /** Produit par ID */
    getProductById: function (id) {
      return this.products.find(function (p) { return p.id === id; }) || null;
    },

    /** Produit par slug */
    getProductBySlug: function (slug) {
      var cleanSlug = slugify(slug);
      return this.products.find(function (p) { return p.slug === cleanSlug; }) || null;
    },

    /** Slug SEO d'un produit */
    getProductSlug: function (productOrId) {
      var product = typeof productOrId === 'string' ? this.getProductById(productOrId) : productOrId;
      return product && product.slug ? product.slug : '';
    },

    /** URL d'un produit — format SEO : /produit/<slug> */
    getProductHref: function (productOrId) {
      var slug = this.getProductSlug(productOrId);
      if (!slug) return 'Fiche Produit.html';
      return '/produit/' + encodeURIComponent(slug);
    },

    getProductOffers: function (product) {
      return getProductOffers(product);
    },

    getProductDefaultOffer: function (product) {
      return getProductDefaultOffer(product);
    },

    getProductStartingPrice: function (product) {
      return getProductStartingPrice(product);
    },

    getPromoMessage: function () {
      return getPromoMessage(this.homepage);
    },

    /** Toutes les recettes publiées */
    getPublishedRecipes: function () {
      return this.recipes.filter(function (r) { return r.status === 'published'; });
    },

    /** Recette par ID */
    getRecipeById: function (id) {
      return this.recipes.find(function (r) { return r.id === id; }) || null;
    },

    /**
     * URL d'une catégorie — format SEO : /categorie/<slug>
     */
    getCategoryHref: function (cat) {
      var slug = this.getCategorySlug(cat);
      return slug ? '/categorie/' + encodeURIComponent(slug) : 'categorie.html';
    },

    /**
     * Enregistre une commande dans castaneas_admin_orders.
     * Retourne la référence générée (ex: "CAS-AB12CD").
     * @param {Array}  items  — articles du panier (id, name, qty, price, image)
     * @param {number} total
     */
    saveOrder: function (items, total) {
      try {
        var orders = loadStorage(KEYS.orders) || [];
        var ref = 'CAS-' + Math.random().toString(36).substr(2, 6).toUpperCase();
        var session = {};
        try { session = JSON.parse(sessionStorage.getItem('castaneas_session_v1') || '{}'); } catch (e) {}
        var order = {
          id:       ref,
          date:     new Date().toISOString().slice(0, 10),
          customer: [session.prenom, session.nom].filter(Boolean).join(' ') || 'Client',
          email:    session.email || 'client@exemple.fr',
          items:    items.map(function (i) {
            return { id: i.id, name: i.name, qty: i.qty, price: i.price, image: i.image || 'assets/product-pate-tartiner.png' };
          }),
          total:    Math.round(total * 100) / 100,
          status:   'pending',
        };
        orders.push(order);
        localStorage.setItem(KEYS.orders, JSON.stringify(orders));
        return ref;
      } catch (e) {
        return 'CAS-' + Math.random().toString(36).substr(2, 6).toUpperCase();
      }
    },
  };

  window.SiteData = SiteData;

  /* SiteData.ready : compatibilité avec ancien code — déjà résolu (données sync via data.php) */
  SiteData.ready = Promise.resolve();

  /* ---------- Injection automatique de la nav ---------- */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      injectNav();
      injectPromoBar();
    });
  } else {
    injectNav();
    injectPromoBar();
  }

  function injectNav() {
    var navEl = document.querySelector('.nav-links');
    if (!navEl) return;

    var cats = SiteData.getHeaderCategories();
    var raw  = decodeURIComponent(location.pathname.split('/').pop()).toLowerCase();
    var page = raw || 'index.html';

    function isActive(href) {
      var h = href.toLowerCase();
      if (h === page) return true;
      // Pour les URLs /categorie/<slug>, comparer le dernier segment
      if (h.split('/').pop() === page) return true;
      return false;
    }

    var html = '<a href="/index.html"' + (isActive('/index.html') ? ' class="active"' : '') + '>Accueil</a>';
    cats.forEach(function (c) {
      var href = SiteData.getCategoryHref(c);
      html += '<a href="' + href + '"' + (isActive(href) ? ' class="active"' : '') + '>' + c.name + '</a>';
    });
    html += '<a href="/recettes.html"' + (isActive('/recettes.html') ? ' class="active"' : '') + '>Recettes</a>';
    html += '<a href="/histoire.html"' + (isActive('/histoire.html') ? ' class="active"' : '') + '>Notre histoire</a>';

    navEl.innerHTML = html;

    /* ---- Hamburger button (mobile only) ---- */
    var actionsEl = document.querySelector('.nav-actions');
    if (actionsEl && !actionsEl.querySelector('.nav-burger')) {
      var burger = document.createElement('button');
      burger.className = 'nav-burger';
      burger.setAttribute('aria-label', 'Ouvrir le menu');
      burger.setAttribute('aria-expanded', 'false');
      burger.innerHTML =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">' +
          '<line x1="3" y1="6" x2="21" y2="6"/>' +
          '<line x1="3" y1="12" x2="21" y2="12"/>' +
          '<line x1="3" y1="18" x2="21" y2="18"/>' +
        '</svg>';
      actionsEl.insertBefore(burger, actionsEl.firstChild);
    }

    /* ---- Mobile nav overlay ---- */
    if (!document.querySelector('.nav-mobile')) {
      var overlay = document.createElement('div');
      overlay.className = 'nav-mobile';
      overlay.setAttribute('aria-hidden', 'true');

      var brandEl = document.querySelector('.brand');
      var brandHtml = brandEl
        ? brandEl.outerHTML
        : '<a href="/index.html" class="brand">Castaneas</a>';

      overlay.innerHTML =
        '<div class="nav-mobile__panel">' +
          '<div class="nav-mobile__head">' +
            brandHtml +
            '<button class="nav-mobile__close" aria-label="Fermer le menu">' +
              '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">' +
                '<line x1="18" y1="6" x2="6" y2="18"/>' +
                '<line x1="6" y1="6" x2="18" y2="18"/>' +
              '</svg>' +
            '</button>' +
          '</div>' +
          '<nav class="nav-mobile__links">' + html + '</nav>' +
        '</div>';

      document.body.appendChild(overlay);

      var burgerBtn = document.querySelector('.nav-burger');

      function openMenu() {
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        if (burgerBtn) burgerBtn.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
      }

      function closeMenu() {
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
        if (burgerBtn) burgerBtn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
      }

      if (burgerBtn) burgerBtn.addEventListener('click', openMenu);
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeMenu();
      });
      overlay.querySelector('.nav-mobile__close').addEventListener('click', closeMenu);
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) closeMenu();
      });
    }
  }

  function injectPromoBar() {
    var promoEls = document.querySelectorAll('.promo-bar');
    if (!promoEls.length) return;

    var message = SiteData.getPromoMessage();
    promoEls.forEach(function (el) {
      el.textContent = message;
    });
  }

})();
