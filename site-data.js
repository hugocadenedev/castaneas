/* ============================================================
   CASTANEAS — site-data.js v3 (server-only)
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

  /* ---------- Données serveur injectées par data.php (priorité max) ---------- */
  var _srv = window.CASTANEAS_DATA || {};

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
    products: (_srv.products && _srv.products.length > 0) ? _srv.products : (loadStorage(KEYS.products) || []),
    recipes:  (_srv.recipes  && _srv.recipes.length  > 0) ? _srv.recipes  : (loadStorage(KEYS.recipes)  || []),

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

    /** Produit par ID */
    getProductById: function (id) {
      return this.products.find(function (p) { return p.id === id; }) || null;
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
      return 'categorie/' + encodeURIComponent(cat.slug);
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
  document.addEventListener('DOMContentLoaded', function () { injectNav(); });

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

    var html = '<a href="index.html"' + (isActive('index.html') ? ' class="active"' : '') + '>Accueil</a>';
    cats.forEach(function (c) {
      var href = SiteData.getCategoryHref(c);
      html += '<a href="' + href + '"' + (isActive(href) ? ' class="active"' : '') + '>' + c.name + '</a>';
    });
    html += '<a href="recettes.html"' + (isActive('recettes.html') ? ' class="active"' : '') + '>Recettes</a>';
    html += '<a href="index.html#histoire">Notre histoire</a>';

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
        : '<a href="index.html" class="brand">Castaneas</a>';

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

})();
