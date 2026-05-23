/* Castaneas V3 — wrapper qui réutilise renderShell V1 et adapte pour V3 */
window.renderShellV3 = function (activePage) {
  window.renderShell(activePage);

  // Réécrit les liens vers V3
  document.querySelectorAll('.nav-links a').forEach(a => {
    if (a.getAttribute('href') === 'index.html') a.setAttribute('href', 'index-v3.html');
    if (a.getAttribute('href') === 'boutique.html') a.setAttribute('href', 'boutique-v3.html');
  });
  const logo = document.querySelector('.logo');
  if (logo) logo.setAttribute('href', 'index-v3.html');

  // Pastille version V3
  const navInner = document.querySelector('.nav-inner');
  if (navInner) {
    const pill = document.createElement('div');
    pill.className = 'version-pill';
    pill.innerHTML = `<span class="dot"></span> V3 · Cahier de cuisine · <a href="index.html">V1</a> · <a href="index-v2.html">V2</a>`;
    pill.style.position = 'absolute';
    pill.style.left = '50%';
    pill.style.bottom = '-14px';
    pill.style.transform = 'translateX(-50%)';
    pill.style.zIndex = '5';
    navInner.style.position = 'relative';
    navInner.appendChild(pill);
  }

  // Liens du footer
  document.querySelectorAll('.footer a').forEach(a => {
    if (a.getAttribute('href') === 'boutique.html') a.setAttribute('href', 'boutique-v3.html');
  });
};

/* Drag des fiches/post-its sur le "plan de travail" */
window.makeDraggable = function(selector) {
  document.querySelectorAll(selector).forEach((el) => {
    let dragging = false;
    let startX = 0, startY = 0, originX = 0, originY = 0;
    let zCounter = 10;

    const onDown = (e) => {
      dragging = true;
      el.classList.add('dragging');
      el.style.zIndex = ++zCounter;
      const t = e.touches ? e.touches[0] : e;
      startX = t.clientX;
      startY = t.clientY;
      const rect = el.getBoundingClientRect();
      const parentRect = el.offsetParent.getBoundingClientRect();
      originX = rect.left - parentRect.left;
      originY = rect.top - parentRect.top;
      el.style.left = originX + 'px';
      el.style.top = originY + 'px';
      el.style.right = 'auto';
      el.style.bottom = 'auto';
      e.preventDefault();
    };

    const onMove = (e) => {
      if (!dragging) return;
      const t = e.touches ? e.touches[0] : e;
      const dx = t.clientX - startX;
      const dy = t.clientY - startY;
      el.style.left = (originX + dx) + 'px';
      el.style.top = (originY + dy) + 'px';
    };

    const onUp = () => {
      dragging = false;
      el.classList.remove('dragging');
    };

    el.addEventListener('mousedown', onDown);
    el.addEventListener('touchstart', onDown, {passive: false});
    document.addEventListener('mousemove', onMove);
    document.addEventListener('touchmove', onMove, {passive: false});
    document.addEventListener('mouseup', onUp);
    document.addEventListener('touchend', onUp);
  });
};
