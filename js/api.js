// Fill this in after deploying api/api.php (see SETUP.md).
const CONFIG = {
  API_URL: "https://seniorfamily.org/reading-api/api.php",
};

function isConfigured() {
  return CONFIG.API_URL && !CONFIG.API_URL.includes("YOUR_");
}

// ---- Login (My Apps Hub SSO token, or a manual fallback) ----
// Every action except login/logout/whoAmI checks this token against the shared
// sessions table plus an app_access grant for 'reading-list'.

const TOKEN_KEY = 'rlToken';
function getToken() { return localStorage.getItem(TOKEN_KEY) || ''; }
function saveToken(t) { localStorage.setItem(TOKEN_KEY, t); }
function clearToken() { localStorage.removeItem(TOKEN_KEY); }
function authHeaders() {
  const t = getToken();
  return t ? { Authorization: `Bearer ${t}` } : {};
}

async function fetchData(action) {
  if (!isConfigured()) throw new Error('not-configured');
  const res = await fetch(`${CONFIG.API_URL}?action=${encodeURIComponent(action)}`, { headers: authHeaders() });
  if (res.status === 401 || res.status === 403) throw new Error('not-authorized');
  if (!res.ok) throw new Error(`Request failed: ${res.status}`);
  return res.json();
}

async function postAction(action, payload) {
  if (!isConfigured()) throw new Error('not-configured');
  const res = await fetch(CONFIG.API_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'text/plain', ...authHeaders() },
    body: JSON.stringify({ action, ...(payload || {}) }),
  });
  if (res.status === 401 || res.status === 403) throw new Error('not-authorized');
  if (!res.ok) throw new Error(`Request failed: ${res.status}`);
  return res.json();
}

function escapeHtml(str) {
  const d = document.createElement('div');
  d.textContent = str ?? '';
  return d.innerHTML;
}

// Renders a login-required / access-denied card into `container`. "no-token"
// includes a manual login form for a bookmarked visit that skipped the Hub's
// SSO handoff -- normally you never see it. "denied" means the login is fine
// but has no app_access grant for Reading List yet.
function renderGate(container, reason) {
  if (reason === 'denied') {
    container.innerHTML = `<div class="card"><p class="note">Your login isn't set up for Reading List yet. Ask to be granted the app in My Apps Hub, or open it again from there.</p></div>`;
    return;
  }
  container.innerHTML = `
    <div class="card">
      <h3>Log In</h3>
      <p class="note">Normally you won't see this — open Reading List from My Apps Hub and it logs you in automatically.</p>
      <label for="g-email">Email</label>
      <input type="email" id="g-email" autocomplete="username">
      <label for="g-pw">Password</label>
      <input type="password" id="g-pw" autocomplete="current-password">
      <p><button type="button" class="btn" id="g-btn">Log In</button></p>
      <div id="g-msg"></div>
    </div>`;
  const email = document.getElementById('g-email');
  const pw = document.getElementById('g-pw');
  const msg = document.getElementById('g-msg');
  const btn = document.getElementById('g-btn');
  async function attempt() {
    const username = email.value.trim();
    const password = pw.value;
    if (!username || !password) return;
    btn.disabled = true;
    msg.innerHTML = '';
    try {
      const res = await fetch(CONFIG.API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify({ action: 'login', username, password }),
      });
      const data = await res.json();
      if (!res.ok || !data.token) {
        msg.innerHTML = `<p class="note">${escapeHtml(data.error || 'Incorrect email or password.')}</p>`;
        btn.disabled = false;
        return;
      }
      saveToken(data.token);
      window.location.reload();
    } catch (e) {
      msg.innerHTML = `<p class="note">Something went wrong. Please try again.</p>`;
      btn.disabled = false;
    }
  }
  btn.addEventListener('click', attempt);
  pw.addEventListener('keydown', ev => { if (ev.key === 'Enter') attempt(); });
}

function handleError(container, err) {
  const m = err && err.message;
  if (m === 'not-authorized') renderGate(container, getToken() ? 'denied' : 'no-token');
  else if (m === 'not-configured') container.innerHTML = `<p class="note">App isn't configured yet.</p>`;
  else container.innerHTML = `<p class="note">Couldn't load right now — check your connection and refresh.</p>`;
}

// My Apps Hub launches this app with ?token=... -- adopt it as the login and
// strip it from the URL. Run-once.
let _ssoDone = null;
function captureSso() {
  if (_ssoDone) return _ssoDone;
  _ssoDone = (async () => {
    const token = new URLSearchParams(window.location.search).get('token');
    if (!token) return;
    window.history.replaceState({}, document.title, window.location.pathname);
    saveToken(token);
  })();
  return _ssoDone;
}

// Rolled-up status for a group of books:
//  all Finished -> Finished ; all Not Started -> Not Started ; anything else -> In Progress
function rollupStatus(books) {
  if (!books.length) return 'Not Started';
  const allFinished = books.every(b => b.Status === 'Finished');
  if (allFinished) return 'Finished';
  const allNotStarted = books.every(b => b.Status === 'Not Started' || !b.Status);
  if (allNotStarted) return 'Not Started';
  return 'In Progress';
}

function statusClass(s) {
  return 'st-' + String(s).toLowerCase().replace(/[^a-z]+/g, '-');
}
