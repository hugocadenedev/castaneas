/* Castaneas — shared cart + UI interactions */
(function () {
  const STORAGE_KEY = 'castaneas_cart_v1';

  const state = {
    items: JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'),
  };

  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function save() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state.items));
  }

  function count() {
    return state.items.reduce((n, i) => n + i.qty, 0);
  }

  function total() {
    return state.items.reduce((s, i) => s + i.qty * i.price, 0);
  }

  function add(product) {
    const existing = state.items.find(i => i.id === product.id && i.variant === product.variant);
    if (existing) existing.qty += product.qty || 1;
    else state.items.push({ ...product, qty: product.qty || 1 });
    save(); render();
  }

  function update(id, variant, qty) {
    const it = state.items.find(i => i.id === id && i.variant === variant);
    if (!it) return;
    it.qty = qty;
    if (it.qty <= 0) state.items = state.items.filter(i => !(i.id === id && i.variant === variant));
    save(); render();
  }

  function render() {
    // update cart count badges
    document.querySelectorAll('[data-cart-count]').forEach(el => {
      el.textContent = count();
      el.classList.add('bump');
      setTimeout(() => el.classList.remove('bump'), 300);
    });
    renderDrawer();
  }

  function renderDrawer() {
    const body = document.querySelector('[data-cart-body]');
    const foot = document.querySelector('[data-cart-foot]');
    if (!body) return;

    if (state.items.length === 0) {
      body.innerHTML = `
        <div class="cart-empty">
          <div class="cart-empty-icon">🌰</div>
          <p style="font-family: var(--serif); font-size: 20px; color: var(--brown-deep); margin-bottom:6px;">Votre panier est vide</p>
          <p style="font-size: 13px; color: var(--muted);">Découvrez nos crèmes artisanales du Quercy</p>
          <a href="creme-de-chataigne.html" class="cart-checkout-btn" style="margin-top: 22px; text-decoration:none;">Parcourir la boutique</a>
        </div>`;
      if (foot) foot.style.display = 'none';
      return;
    }

    if (foot) foot.style.display = 'block';
    body.innerHTML = state.items.map(i => `
      <div class="cart-item">
        <div class="cart-item-img" style="background-image:url('${esc(i.image)}')"></div>
        <div>
          <div class="cart-item-name">${esc(i.name)}</div>
          <div class="cart-item-meta">${esc(i.variant || '')} · ${esc(i.weight || '250g')}</div>
          <div class="qty-ctrl">
            <button data-qty="dec" data-id="${esc(i.id)}" data-variant="${esc(i.variant || '')}">−</button>
            <span class="val">${esc(i.qty)}</span>
            <button data-qty="inc" data-id="${esc(i.id)}" data-variant="${esc(i.variant || '')}">+</button>
          </div>
        </div>
        <div class="cart-item-price">${(i.qty * i.price).toFixed(2)}€</div>
      </div>
    `).join('');

    document.querySelector('[data-cart-total]').textContent = total().toFixed(2) + '€';

    body.querySelectorAll('[data-qty]').forEach(btn => {
      btn.onclick = () => {
        const id = btn.dataset.id;
        const variant = btn.dataset.variant;
        const it = state.items.find(i => i.id === id && (i.variant || '') === variant);
        if (!it) return;
        update(id, it.variant, btn.dataset.qty === 'inc' ? it.qty + 1 : it.qty - 1);
      };
    });
  }

  function openDrawer() {
    document.querySelector('.cart-drawer')?.classList.add('open');
    document.querySelector('.cart-backdrop')?.classList.add('open');
  }
  function closeDrawer() {
    document.querySelector('.cart-drawer')?.classList.remove('open');
    document.querySelector('.cart-backdrop')?.classList.remove('open');
  }

  // Fly-to-cart animation
  function flyToCart(fromEl, imageUrl) {
    const cartBtn = document.querySelector('[data-cart-toggle]');
    if (!fromEl || !cartBtn) return;
    const from = fromEl.getBoundingClientRect();
    const to = cartBtn.getBoundingClientRect();
    const dot = document.createElement('div');
    dot.className = 'fly-dot';
    if (imageUrl) {
      dot.style.backgroundImage = `url('${imageUrl}')`;
      dot.style.backgroundSize = 'cover';
      dot.style.backgroundPosition = 'center';
    }
    dot.style.left = (from.left + from.width / 2 - 16) + 'px';
    dot.style.top = (from.top + from.height / 2 - 16) + 'px';
    document.body.appendChild(dot);
    // animate
    const dx = (to.left + to.width / 2) - (from.left + from.width / 2);
    const dy = (to.top + to.height / 2) - (from.top + from.height / 2);
    const midY = dy - 140;
    dot.animate([
      { transform: 'translate(0, 0) scale(1)', opacity: 1 },
      { transform: `translate(${dx * 0.5}px, ${midY}px) scale(0.9)`, opacity: 0.9, offset: 0.55 },
      { transform: `translate(${dx}px, ${dy}px) scale(0.2)`, opacity: 0 },
    ], { duration: 750, easing: 'cubic-bezier(.5, -0.2, .7, 1)' }).onfinish = () => dot.remove();
  }

  function toast(msg) {
    let t = document.querySelector('.toast');
    if (!t) {
      t = document.createElement('div');
      t.className = 'toast';
      document.body.appendChild(t);
    }
    t.innerHTML = `<span class="toast-check">✓</span> ${msg}`;
    requestAnimationFrame(() => t.classList.add('show'));
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('show'), 2200);
  }

  // wire up
  document.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-cart-toggle]');
    const close = e.target.closest('[data-cart-close]');
    const addBtn = e.target.closest('[data-add]');
    if (toggle) { e.preventDefault(); openDrawer(); }
    if (close) closeDrawer();
    if (addBtn) {
      const product = JSON.parse(addBtn.dataset.add);
      add(product);
      flyToCart(addBtn, product.image);
      toast(`${product.name} ajouté au panier`);
    }
  });

  function addToCartUI(product, btn) {
    add(product);
    flyToCart(btn, product.image);
    toast(`${product.name} ajouté au panier`);
  }

  window.Castaneas = { add, addToCartUI, update, open: openDrawer, close: closeDrawer, state, render };

  document.addEventListener('DOMContentLoaded', render);
})();
