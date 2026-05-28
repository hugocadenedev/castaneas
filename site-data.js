/* ============================================================
   CASTANEAS — site-data.js
   Pont entre le back-office (admin.html) et les pages du site.
   • Lit les données depuis localStorage (écrites par admin.html)
   • Fournit window.SiteData avec produits, catégories, helpers
   • Injecte la navigation dynamiquement au chargement
   • Expose SiteData.saveOrder() pour confirmation.html
   ============================================================ */
(function () {
  'use strict';

  /* ---------- Clés localStorage (partagées avec admin.html) ---------- */
  var KEYS = {
    products:   'castaneas_admin_products',
    categories: 'castaneas_admin_categories',
    orders:     'castaneas_admin_orders',
    cart:       'castaneas_cart_v1',
    recipes:    'castaneas_admin_recipes',
  };

  /* ---------- Données par défaut (miroir de admin.html) ---------- */
  var DEFAULT_CATS = [
    { id: 'cat-cremes',   name: 'Crèmes de châtaigne', slug: 'cremes',         desc: 'Nos crèmes de châtaigne artisanales',    emoji: '🌰', color: '#F2E8D9', header: true, status: 'active' },
    { id: 'cat-pates',   name: 'Pâtes à tartiner',     slug: 'pates-tartiner', desc: 'Pâtes à tartiner noisette et châtaigne', emoji: '🧈', color: '#E8C9A0', header: true, status: 'active' },
    { id: 'cat-huiles',  name: 'Huiles',               slug: 'huiles',         desc: 'Huiles artisanales pressées à froid',    emoji: '🫙', color: '#E5D5A8', header: true, status: 'active' },
    { id: 'cat-coffrets',name: 'Coffrets cadeaux',     slug: 'coffrets',       desc: 'Nos coffrets cadeaux gourmands',         emoji: '🎁', color: '#E8C4B8', header: true, status: 'active' },
  ];

  var _COMP_NATURE   = { heading: 'Trois ingrédients, pas un de plus.', ings: [ { pct: 72, origin: 'Albias · Quercy', name: 'Châtaigne', desc: 'Variété « Marigoule », ramassée à la main, cuite à l\'eau, épluchée à la veillée.' }, { pct: 26, origin: 'Île de la Réunion', name: 'Sucre roux', desc: 'Sucre de canne non raffiné, à la mélasse intacte. Cuisson lente.' }, { pct: 2, origin: 'France · eau de source', name: 'Eau', desc: 'Eau de source, rien d\'autre.' } ], restDesc: 'Sans gluten · sans huile de palme · sans pectine · sans conservateur.' };
  var _COMP_VANILLEE = { heading: 'Trois ingrédients, pas un de plus.', ings: [ { pct: 65, origin: 'Albias · Quercy', name: 'Châtaigne', desc: 'Variété « Marigoule », cueillie à la main, cuite doucement.' }, { pct: 27, origin: 'Île de la Réunion', name: 'Sucre roux', desc: 'Sucre de canne non raffiné, à la mélasse intacte. Cuisson lente.' }, { pct: 2, origin: 'Madagascar', name: 'Vanille Bourbon', desc: 'Gousse fendue à la main, infusée à froid pendant 48h dans le sirop.' } ], restDesc: 'Sans gluten · sans huile de palme · sans pectine · sans conservateur.' };
  var _COMP_NOISETTE = { heading: 'Deux ingrédients, pas un de plus.', ings: [ { pct: 60, origin: 'Piémont · Italie', name: 'Noisettes', desc: 'Torréfiées à la main, 30 min à sec, sans huile ajoutée.' }, { pct: 40, origin: 'Albias · Quercy', name: 'Châtaigne', desc: 'Châtaignes confites, cuites doucement au sucre roux.' } ], restDesc: 'Sans gluten · sans huile de palme · sans sucre ajouté · sans conservateur.' };
  var _COMP_PATE     = { heading: 'Deux ingrédients, pas un de plus.', ings: [ { pct: 99, origin: 'Piémont · Italie', name: 'Noisettes', desc: 'Torréfiées à la main, 30 min à sec, sans huile ajoutée.' }, { pct: 1, origin: 'Guérande · Bretagne', name: 'Sel de Guérande', desc: 'Sel gris non raffiné, récolté à la main.' } ], restDesc: 'Sans gluten · sans huile de palme · sans sucre ajouté · sans conservateur.' };
  var _COMP_COFFRET  = { heading: 'Trois crèmes signatures,', ings: [ { pct: 100, origin: 'Quercy · France', name: 'Crèmes artisanales', desc: 'Sélection de 3 pots fabriqués à Albias.' } ], restDesc: 'Boîte bois gravée · emballage cadeau inclus · livraison soignée.' };

  var _USAGES_DEFAULT = {
    heading: 'Quatre petites idées pour le pot.',
    sub: '— et la cuillère bien sûr',
    cards: [
      { icon: 'tartine',  title: 'Sur tartine',   desc: 'Pain au levain grillé, beurre demi-sel, une grosse cuillère.' },
      { icon: 'yaourt',   title: 'En yaourt',     desc: 'Deux cuillères dans un fromage blanc, miel et noisettes.' },
      { icon: 'dessert',  title: 'En Mont-Blanc', desc: 'Vermicelles sur meringue + crème fouettée. Express.' },
      { icon: 'cuillere', title: 'À la cuillère', desc: 'Directement dans le pot. On ne juge pas.' },
    ],
  };

  var DEFAULT_PRODUCTS = [
    { id: 'creme-nature-150',  name: 'Crème de châtaigne nature', category: 'cat-cremes',   price: 6.90,  weight: '150g',  stock: 'in_stock',  desc: "L'originelle. Châtaignes confites au sucre roux.",             image: 'assets/product-pate-tartiner.png',    badge: 'Bestseller',     status: 'active', composition: _COMP_NATURE,   usages: _USAGES_DEFAULT },
    { id: 'creme-nature-300',  name: 'Crème de châtaigne nature', category: 'cat-cremes',   price: 11.90, weight: '300g',  stock: 'in_stock',  desc: 'Le format famille pour les gourmands assumés.',                image: 'assets/product-huile-noisettes.png',  badge: '',               status: 'active', composition: _COMP_NATURE,   usages: _USAGES_DEFAULT },
    { id: 'creme-vanillee-150',name: 'Châtaigne vanillée',        category: 'cat-cremes',   price: 7.50,  weight: '150g',  stock: 'in_stock',  desc: 'Vanille Bourbon de Madagascar, infusée à froid 48h.',         image: 'assets/product-noisettes-crues.png',  badge: 'Coup de cœur',   status: 'active', composition: _COMP_VANILLEE, usages: _USAGES_DEFAULT },
    { id: 'creme-vanillee-300',name: 'Châtaigne vanillée',        category: 'cat-cremes',   price: 12.90, weight: '300g',  stock: 'low_stock', desc: 'La douceur de la vanille en format généreux.',                image: 'assets/product-pate-tartiner.png',    badge: '',               status: 'active', composition: _COMP_VANILLEE, usages: _USAGES_DEFAULT },
    { id: 'creme-noisette-150',name: 'Crème châtaigne & noisette',category: 'cat-cremes',   price: 7.90,  weight: '150g',  stock: 'in_stock',  desc: 'Noisettes du Piémont torréfiées doucement.',                  image: 'assets/product-huile-noisettes.png',  badge: '',               status: 'active', composition: _COMP_NOISETTE, usages: _USAGES_DEFAULT },
    { id: 'creme-noisette-300',name: 'Crème châtaigne & noisette',category: 'cat-cremes',   price: 13.50, weight: '300g',  stock: 'in_stock',  desc: "La pâte à tartiner artisanale qu'on attendait.",              image: 'assets/product-noisettes-crues.png',  badge: '',               status: 'active', composition: _COMP_NOISETTE, usages: _USAGES_DEFAULT },
    { id: 'pate-noisette-200', name: 'Pâte de noisette',          category: 'cat-pates',    price: 9.90,  weight: '200g',  stock: 'in_stock',  desc: 'Noisettes du Piémont torréfiées à la main.',                  image: 'assets/product-noisettes-crues.png',  badge: 'Nouveau',        status: 'active', composition: _COMP_PATE,    usages: _USAGES_DEFAULT },
    { id: 'coffret-quercy',    name: 'Coffret Quercy',            category: 'cat-coffrets', price: 29.90, weight: '3×150g',stock: 'in_stock',  desc: 'Trois pots + boîte bois gravée.',                             image: 'assets/product-pate-tartiner.png',    badge: '🎁 Coffret',     status: 'active', composition: _COMP_COFFRET, usages: _USAGES_DEFAULT },
    { id: 'coffret-prestige',  name: 'Coffret Prestige',          category: 'cat-coffrets', price: 34.90, weight: '3×300g',stock: 'low_stock', desc: 'Notre écrin bois signé à la main. 200 exemplaires.',          image: 'assets/product-huile-noisettes.png',  badge: 'Édition limitée',status: 'active', composition: _COMP_COFFRET, usages: _USAGES_DEFAULT },
  ];

  var DEFAULT_RECIPES = [
    {
      id: 'recipe-tartine-automne',
      title: 'Tartine', titleEm: "d'automne",
      time: '5 min', servings: 'Sans cuisson',
      intro: "Le classique revisité. Simple, direct, inratable.",
      desc: "Pain au levain grillé, beurre demi-sel, une grosse cuillère de châtaigne vanillée.",
      image: 'assets/product-pate-tartiner.png',
      products: ['creme-vanillee-150', 'creme-nature-150'],
      steps: [
        { step: 1, text: "Faites griller deux tranches de pain au levain jusqu'à ce qu'elles soient bien dorées." },
        { step: 2, text: "Beurrez généreusement avec du beurre demi-sel encore froid." },
        { step: 3, text: "Ajoutez une généreuse cuillère de crème de châtaigne vanillée." },
      ],
      status: 'published',
    },
    {
      id: 'recipe-mont-blanc-express',
      title: 'Mont-Blanc', titleEm: 'express',
      time: '25 min', servings: '6 personnes',
      intro: "Le dessert de grand-mère, revisité en 3 gestes.",
      desc: "Meringues, crème fouettée, vermicelles de châtaigne vanillée par-dessus.",
      image: 'assets/product-noisettes-crues.png',
      products: ['creme-vanillee-300', 'creme-nature-300'],
      steps: [
        { step: 1, text: "Posez une meringue dans chaque assiette." },
        { step: 2, text: "Fouettez la crème entière bien froide en chantilly légère." },
        { step: 3, text: "Déposez une quenelle de chantilly sur la meringue." },
        { step: 4, text: "Passez la crème de châtaigne au presse-purée pour former les vermicelles." },
        { step: 5, text: "Servez immédiatement, bien frais." },
      ],
      status: 'published',
    },
  ];

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

  /* ---------- API publique ---------- */
  var SiteData = {
    categories: (function () {
      var raw = loadStorage(KEYS.categories);
      var clean = sanitizeCategories(raw);
      if (clean && clean !== raw) {
        try { localStorage.setItem(KEYS.categories, JSON.stringify(clean)); } catch (e) {}
      }
      return clean || DEFAULT_CATS;
    }()),
    products:   loadStorage(KEYS.products)   || DEFAULT_PRODUCTS,
    recipes:    loadStorage(KEYS.recipes)    || DEFAULT_RECIPES,

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
     * URL d'une catégorie.
     * Utilise cat.href si défini, sinon cremes → creme-de-chataigne.html, autres → slug + .html.
     */
    getCategoryHref: function (cat) {
      if (cat.href && !cat.href.startsWith('blob:') && !cat.href.startsWith('http')) return cat.href;
      if (cat.slug === 'cremes') return 'creme-de-chataigne.html';
      var staticSlugs = ['pates-tartiner', 'huiles', 'coffrets'];
      if (staticSlugs.indexOf(cat.slug) !== -1) return cat.slug + '.html';
      return 'categorie.html#' + encodeURIComponent(cat.slug);
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

  /* ---------- Chargement serveur (persistance cross-navigateur) ---------- */
  SiteData.ready = (function () {
    var serverFiles = {
      products:   '/data/products.json',
      categories: '/data/categories.json',
      recipes:    '/data/recipes.json',
    };
    var localMap = {
      products:   KEYS.products,
      categories: KEYS.categories,
      recipes:    KEYS.recipes,
    };
    var fetches = Object.keys(serverFiles).map(function (key) {
      return fetch(serverFiles[key], { cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .catch(function () { return null; })
        .then(function (data) {
          if (data !== null && (Array.isArray(data) ? data.length > 0 : true)) {
            SiteData[key] = data;
            try { localStorage.setItem(localMap[key], JSON.stringify(data)); } catch (e) {}
          }
        });
    });
    return Promise.all(fetches).then(function () {
      window.dispatchEvent(new CustomEvent('sitedataready'));
    });
  }());

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
      if (h === 'creme-de-chataigne.html' && page === 'castaneas.html') return true;
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
