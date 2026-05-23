/* Castaneas V2 — wrapper qui réutilise renderShell V1 et ajoute la pastille version */
window.renderShellV2 = function (activePage) {
  // 1. injecte la coque V1 (nav + drawer + footer)
  window.renderShell(activePage);

  // 2. réécrit les liens de la nav vers les pages V2
  const navLinks = document.querySelectorAll('.nav-links a');
  navLinks.forEach(a => {
    if (a.getAttribute('href') === 'index.html') a.setAttribute('href', 'index-v2.html');
    if (a.getAttribute('href') === 'boutique.html') a.setAttribute('href', 'boutique-v2.html');
  });
  const logo = document.querySelector('.logo');
  if (logo) logo.setAttribute('href', 'index-v2.html');

  // 3. ajoute la pastille version dans la nav
  const navInner = document.querySelector('.nav-inner');
  if (navInner) {
    const pill = document.createElement('div');
    pill.className = 'version-pill';
    pill.innerHTML = `<span class="dot"></span> V2 · À table · <a href="index.html">voir V1</a>`;
    pill.style.position = 'absolute';
    pill.style.left = '50%';
    pill.style.bottom = '-14px';
    pill.style.transform = 'translateX(-50%)';
    pill.style.background = 'var(--cream-soft)';
    pill.style.zIndex = '5';
    navInner.style.position = 'relative';
    navInner.appendChild(pill);
  }

  // 4. réécrit les liens du footer
  document.querySelectorAll('.footer a').forEach(a => {
    if (a.getAttribute('href') === 'boutique.html') a.setAttribute('href', 'boutique-v2.html');
  });
};
