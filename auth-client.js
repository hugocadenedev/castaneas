(function () {
  var SESSION_KEY = 'castaneas_session_v1';

  function normalizeUser(user) {
    if (!user || typeof user !== 'object') return null;
    if (!user.email) return null;
    return {
      id: user.id || null,
      email: String(user.email || ''),
      prenom: String(user.prenom || ''),
      nom: String(user.nom || ''),
      ts: Date.now()
    };
  }

  function readStorage(storage) {
    try {
      var raw = storage.getItem(SESSION_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function writeStorage(storage, value) {
    try {
      if (!value) {
        storage.removeItem(SESSION_KEY);
      } else {
        storage.setItem(SESSION_KEY, JSON.stringify(value));
      }
    } catch (e) {}
  }

  function cacheSession(user) {
    var normalized = normalizeUser(user);
    writeStorage(localStorage, normalized);
    writeStorage(sessionStorage, normalized);
    return normalized;
  }

  function clearSession() {
    writeStorage(localStorage, null);
    writeStorage(sessionStorage, null);
  }

  function getCachedSession() {
    return readStorage(localStorage) || readStorage(sessionStorage) || null;
  }

  async function request(action, options) {
    var response = await fetch('auth.php?action=' + encodeURIComponent(action), Object.assign({
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json'
      }
    }, options || {}));

    var payload = null;
    try {
      payload = await response.json();
    } catch (e) {
      payload = { ok: false, message: 'Réponse serveur invalide.' };
    }

    if (!response.ok || !payload.ok) {
      throw new Error(payload && payload.message ? payload.message : 'Erreur serveur.');
    }

    return payload;
  }

  async function fetchSession() {
    var payload = await request('session', { method: 'GET' });
    if (payload.loggedIn && payload.user) {
      cacheSession(payload.user);
      return getCachedSession();
    }
    clearSession();
    return null;
  }

  async function login(email, password) {
    var payload = await request('login', {
      method: 'POST',
      body: JSON.stringify({ email: email, password: password })
    });
    return cacheSession(payload.user);
  }

  async function register(data) {
    var payload = await request('register', {
      method: 'POST',
      body: JSON.stringify(data)
    });
    return cacheSession(payload.user);
  }

  async function logout() {
    await request('logout', { method: 'POST', body: '{}' });
    clearSession();
  }

  window.CastaneasAuth = {
    SESSION_KEY: SESSION_KEY,
    getCachedSession: getCachedSession,
    cacheSession: cacheSession,
    clearSession: clearSession,
    fetchSession: fetchSession,
    login: login,
    register: register,
    logout: logout
  };
})();
