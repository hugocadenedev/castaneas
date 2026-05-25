/* Castaneas — shared layout partials (nav, footer, drawer) */
window.renderShell = function (activePage) {
  const nav = `
  <div class="announce">
    <div class="announce-track">
      <span>Livraison offerte dès 49€</span>
      <span>Récolte 2025 · Noisettes du Tarn-et-Garonne</span>
      <span>100% local · Fermier · Albias (82)</span>
      <span>Livraison offerte dès 49€</span>
      <span>Récolte 2025 · Noisettes du Tarn-et-Garonne</span>
      <span>100% local · Fermier · Albias (82)</span>
    </div>
  </div>
  <header class="nav">
    <div class="nav-inner">
      <a href="index.html" class="logo">
        <img src="assets/castaneas-logo.svg" alt="Castaneas" style="height:44px;width:auto;">
      </a>
      <nav class="nav-links">
        <a href="index.html" ${activePage === 'home' ? 'class="active"' : ''}>Accueil</a>
        <a href="boutique.html" ${activePage === 'shop' ? 'class="active"' : ''}>Boutique</a>
        <a href="#" >Notre ferme</a>
        <a href="#">Recettes</a>
        <a href="#">Contact</a>
      </nav>
      <div class="nav-actions">
        <button class="nav-btn" aria-label="Recherche">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.35-4.35"/></svg>
        </button>
        <button class="nav-btn" aria-label="Compte">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
        </button>
        <button class="nav-btn cart" data-cart-toggle>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
          Panier
          <span class="cart-count" data-cart-count>0</span>
        </button>
      </div>
    </div>
  </header>
  <nav class="mobile-cats" aria-label="Catégories">
    <a href="creme-de-chataigne.html" class="mobile-cats__pill ${activePage === 'cremes' ? 'active' : ''}">🌰 Crèmes de châtaigne</a>
    <a href="pates-tartiner.html" class="mobile-cats__pill ${activePage === 'pates' ? 'active' : ''}">🧈 Pâtes à tartiner</a>
    <a href="huiles.html" class="mobile-cats__pill ${activePage === 'huiles' ? 'active' : ''}">🫙 Huiles</a>
    <a href="coffrets.html" class="mobile-cats__pill ${activePage === 'coffrets' ? 'active' : ''}">🎁 Coffrets</a>
  </nav>`;

  const drawer = `
  <div class="cart-backdrop" data-cart-close></div>
  <aside class="cart-drawer" aria-hidden="true">
    <div class="cart-head">
      <h3>Votre panier</h3>
      <button class="cart-close" data-cart-close aria-label="Fermer">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="cart-body" data-cart-body></div>
    <div class="cart-foot" data-cart-foot>
      <div class="cart-total">
        <span class="cart-total-label">Sous-total</span>
        <span class="cart-total-val" data-cart-total>0€</span>
      </div>
      <button class="btn btn-accent" style="width:100%;padding:16px;">
        Passer commande
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </button>
      <p style="text-align:center; margin-top:14px; font-size:12px; color:var(--muted); font-family:var(--mono);">
        Livraison offerte dès 49€ · Paiement sécurisé
      </p>
    </div>
  </aside>`;

  const footer = `
  <footer class="footer">
    <div class="footer-inner">
      <div class="footer-brand">
        <img src="assets/castaneas-logo.svg" alt="Castaneas" style="height:60px;width:auto;filter:invert(1) brightness(1.05);">
        <p>Du verger d'Albias à votre tartine. Pâtes à tartiner artisanales, noisettes, noix et châtaignes récoltées par la famille sur nos terres du Tarn-et-Garonne.</p>
      </div>
      <div>
        <h4>Boutique</h4>
        <ul>
          <li><a href="boutique.html">Pâtes à tartiner</a></li>
          <li><a href="#">Noisettes entières</a></li>
          <li><a href="#">Coffrets cadeaux</a></li>
          <li><a href="#">Abonnement</a></li>
        </ul>
      </div>
      <div>
        <h4>La ferme</h4>
        <ul>
          <li><a href="#">Notre histoire</a></li>
          <li><a href="#">Le verger</a></li>
          <li><a href="#">Savoir-faire</a></li>
          <li><a href="#">Journal</a></li>
        </ul>
      </div>
      <div>
        <h4>Aide</h4>
        <ul>
          <li><a href="#">Livraison</a></li>
          <li><a href="#">Retours</a></li>
          <li><a href="#">Contact</a></li>
          <li><a href="#">CGV</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© 2026 Castaneas · Ferme familiale · Albias, 82350</span>
      <span>Site imaginé avec ❤ dans le Tarn-et-Garonne</span>
    </div>
  </footer>`;

  document.body.insertAdjacentHTML('afterbegin', nav);
  document.body.insertAdjacentHTML('beforeend', drawer);
  document.body.insertAdjacentHTML('beforeend', footer);
};
