/* RSSphere — app.js v4
   Organisation :
   1. Préférences   2. Utilitaires   3. État & index   4. Données
   5. Colonne gauche 6. Liste        7. Lecteur        8. Découvrir
   9. Gérer         10. Clavier      11. Démarrage
*/

const API = 'api/index.php';

/* ── 1. Préférences (localStorage) ─────────────────────────────────────────
   Les réglages de confort restent sur la machine : ils n'ont rien à faire
   dans la base du compte. */
const PREFS_KEY = 'rssphere.prefs';
const DEFAULT_PREFS = { theme: 'auto', readTheme: 'paper', readSize: 17, readFont: 'serif', readMeasure: 68 };
let prefs = { ...DEFAULT_PREFS };

function loadPrefs() {
  try { prefs = { ...DEFAULT_PREFS, ...JSON.parse(localStorage.getItem(PREFS_KEY) || '{}') }; }
  catch (_) { prefs = { ...DEFAULT_PREFS }; }
  applyPrefs();
}
function savePrefs() {
  try { localStorage.setItem(PREFS_KEY, JSON.stringify(prefs)); } catch (_) {}
  applyPrefs();
}
function applyPrefs() {
  const dark = prefs.theme === 'dark'
    || (prefs.theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  const root = document.documentElement.style;
  root.setProperty('--read-size', prefs.readSize + 'px');
  root.setProperty('--read-measure', prefs.readMeasure + 'ch');
  root.setProperty('--read-family', prefs.readFont === 'sans' ? 'var(--font-body)' : 'var(--font-read)');
  const panel = document.getElementById('readerPanel');
  if (panel) panel.dataset.readTheme = prefs.readTheme;
}
function toggleTheme() {
  prefs.theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
  savePrefs();
}

/* ── 2. Utilitaires ────────────────────────────────────────────────────── */
const CAT_LIST = ['Tech', 'Actualités', 'Science', 'Culture', 'Sport', 'Économie', 'Gaming', 'Autre'];

function escHtml(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function cssClass(cat) {
  return String(cat || 'Autre').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-zA-Z0-9]/g, '') || 'Autre';
}
function norm(s) {
  return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim();
}
function looksLikeUrl(s) {
  const t = String(s || '').trim();
  if (/\s/.test(t)) return false;
  return /^https?:\/\//i.test(t) || /^[\w-]+(\.[\w-]+)+(\/\S*)?$/.test(t);
}
function hostOf(url) {
  try { return new URL(url).hostname.replace(/^www\./, ''); } catch (_) { return url; }
}
function estimateReadTime(html) {
  const words = String(html).replace(/<[^>]+>/g, ' ').split(/\s+/).filter(Boolean).length;
  const m = Math.max(1, Math.round(words / 200));
  return `${m} min de lecture`;
}
function dayLabel(ts) {
  const d = new Date(ts * 1000), now = new Date();
  const day = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const diff = Math.round((today - day) / 86400000);
  if (diff <= 0) return "Aujourd'hui";
  if (diff === 1) return 'Hier';
  if (diff < 7) return d.toLocaleDateString('fr-FR', { weekday: 'long' });
  return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' });
}

async function apiGet(params) {
  const res = await fetch(`${API}?${new URLSearchParams(params)}`);
  if (!res.ok) throw new Error('Requête refusée (' + res.status + ')');
  return res.json();
}
async function apiPost(action, fields) {
  const form = new FormData();
  Object.entries(fields).forEach(([k, v]) => form.append(k, v));
  const res = await fetch(`${API}?action=${action}`, { method: 'POST', body: form });
  if (!res.ok) throw new Error('Requête refusée (' + res.status + ')');
  return res.json();
}

let toastTimer;
function toast(msg, kind) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.style.background = kind === 'error' ? 'var(--danger)' : (kind === 'ok' ? 'var(--ok)' : 'var(--ink)');
  el.style.color = kind ? '#fff' : 'var(--paper)';
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 2600);
}

/* Mots-clés : fréquence des mots pleins, avec bonus au titre. */
const STOPWORDS = new Set(('alors apres aussi avant avec bien chez comme dans depuis donc dont entre etre faire fois leur leurs lors mais meme moins nous notre pour puis quand sans selon cette ceux cela elle elles ils tout toute tous toutes plus votre voire tres deja encore toujours jamais autre chaque peut sont sera avoir plusieurs quelques celui celle sous vers parce quoi nouveau nouvelle premier premiere dernier derniere pendant contre ainsi cela dont like also back been come does each from give have here into just know look made make many more most much must name need only over part said same some take than that them then they this time very want well went were what when will with word would year your after about before could first their there these think those three which while where other people world great between should through because however').split(' '));
const kwCache = {};
function extractKeywords(id, title, excerpt) {
  if (kwCache[id]) return kwCache[id];
  const tokenize = t => (String(t).match(/[a-zA-ZàâäéèêëîïôùûüçæœÀÂÄÉÈÊËÎÏÔÙÛÜÇÆŒ]{4,}/g) || []);
  const freq = {};
  tokenize(title + ' ' + excerpt).forEach(w => {
    const k = w.toLowerCase();
    if (!STOPWORDS.has(norm(k))) freq[k] = (freq[k] || 0) + 1;
  });
  tokenize(title).forEach(w => { const k = w.toLowerCase(); if (freq[k]) freq[k] += 3; });
  return kwCache[id] = Object.entries(freq).sort((a, b) => b[1] - a[1]).slice(0, 4)
    .map(([k]) => k.charAt(0).toUpperCase() + k.slice(1));
}

/* ── 3. État & index ───────────────────────────────────────────────────────
   Un article est identifié par son seul id, où qu'il vienne (abonnement,
   recherche, aperçu, sauvegarde). INDEX est la source de vérité. */
let DB = { feeds: [], saved: [], read: [] };
let ARTICLES = {};                 // feedId -> articles
const INDEX = new Map();           // id -> article
let readSet = new Set();
let savedSet = new Set();

const state = {
  view: 'reader',
  feedFilter: 'all',
  readFilter: 'all',
  query: '',
  activeId: null,
  discover: { q: '', scope: 'catalog', cat: '', loading: false, sources: [], results: [], preview: null, error: '', ran: false },
};

function indexArticles(list) {
  list.forEach(a => {
    const prev = INDEX.get(a.id);
    INDEX.set(a.id, prev ? { ...prev, ...a } : a);
  });
}
function getArticle(id) { return INDEX.get(id) || null; }
function isRead(id) { return readSet.has(id); }
function isSaved(id) { return savedSet.has(id); }

/* ── 4. Données ────────────────────────────────────────────────────────── */
async function init() {
  loadPrefs();
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyPrefs);
  bindEvents();

  try {
    DB = await apiGet({ action: 'get_db' });
  } catch (e) {
    toast('Connexion au serveur impossible', 'error');
    return;
  }
  DB.feeds ||= []; DB.saved ||= []; DB.read ||= [];
  readSet = new Set(DB.read);
  savedSet = new Set(DB.saved.map(s => s.id));
  indexArticles(DB.saved.filter(s => !s.partial));

  renderSidebar();
  await refreshAllFeeds(false);
}

/** Un seul aller-retour : le serveur va chercher tous les flux en parallèle. */
async function refreshAllFeeds(force = true) {
  const btn = document.getElementById('refreshBtn');
  btn.classList.add('spinning');
  setPaneSub('Actualisation…');
  try {
    const data = await apiGet({ action: 'fetch_all', force: force ? '1' : '0' });
    (data.feeds || []).forEach(f => {
      ARTICLES[f.feedId] = f.articles || [];
      indexArticles(ARTICLES[f.feedId]);
    });
    if (data.failed?.length) {
      toast(`${data.failed.length} flux injoignable${data.failed.length > 1 ? 's' : ''}`, 'error');
    } else if (force) {
      toast('Flux à jour', 'ok');
    }
  } catch (e) {
    toast('Actualisation impossible', 'error');
  }
  btn.classList.remove('spinning');
  renderSidebar();
  renderArticles();
}

function setPaneSub(txt) {
  const el = document.getElementById('paneSub');
  if (el) el.textContent = txt;
}

/* ── 5. Colonne de gauche ──────────────────────────────────────────────── */
function allArticles() {
  return DB.feeds.flatMap(f => ARTICLES[f.id] || []).sort((a, b) => b.timestamp - a.timestamp);
}
function savedArticles() {
  return DB.saved.map(s => getArticle(s.id) || s).filter(a => a && a.title);
}

function renderSidebar() {
  const all = allArticles();
  const unread = all.filter(a => !isRead(a.id)).length;

  const badgeAll = document.getElementById('badge-all');
  badgeAll.textContent = unread;
  badgeAll.className = 'item-badge' + (unread > 0 ? ' unread' : '');
  document.getElementById('badge-saved').textContent = DB.saved.length;

  const cats = {};
  DB.feeds.forEach(f => {
    const arts = ARTICLES[f.id] || [];
    cats[f.cat] ||= { total: 0, unread: 0 };
    cats[f.cat].total += arts.length;
    cats[f.cat].unread += arts.filter(a => !isRead(a.id)).length;
  });

  document.getElementById('cats-list').innerHTML = Object.entries(cats).map(([cat, d]) => `
    <button class="sidebar-item cat-${cssClass(cat)} ${state.feedFilter === 'cat:' + cat ? 'active' : ''}"
            data-act="filter" data-filter="cat:${escHtml(cat)}">
      <span class="cat-dot"></span>
      <span class="label">${escHtml(cat)}</span>
      <span class="item-badge ${d.unread > 0 ? 'unread' : ''}">${d.unread > 0 ? d.unread : d.total}</span>
    </button>`).join('') || `<p style="padding:4px 8px;font-size:12px;color:var(--ink-3)">—</p>`;

  document.getElementById('feeds-sidebar-list').innerHTML = DB.feeds.map(f => {
    const u = (ARTICLES[f.id] || []).filter(a => !isRead(a.id)).length;
    return `
    <button class="sidebar-item cat-${cssClass(f.cat)} ${state.feedFilter === 'feed:' + f.id ? 'active' : ''}"
            data-act="filter" data-filter="feed:${f.id}" title="${escHtml(f.name)}">
      <span class="cat-dot"></span>
      <span class="label">${escHtml(f.name)}</span>
      ${u > 0 ? `<span class="item-badge unread">${u}</span>` : ''}
    </button>`;
  }).join('') || `<button class="sidebar-item" data-act="goto-discover"><span class="label" style="color:var(--accent)">+ Trouver des sources</span></button>`;
}

function setFeedFilter(filter) {
  state.feedFilter = filter;
  if (state.activeId) closeReader();
  renderSidebar();
  renderArticles();
}
function setReadFilter(f) {
  state.readFilter = f;
  document.getElementById('pill-all').classList.toggle('active', f === 'all');
  document.getElementById('pill-unread').classList.toggle('active', f === 'unread');
  renderArticles();
}

/* ── 6. Liste des articles ─────────────────────────────────────────────── */
function filteredArticles() {
  let arts;
  if (state.feedFilter === 'saved') arts = savedArticles();
  else if (state.feedFilter.startsWith('cat:')) arts = allArticles().filter(a => a.cat === state.feedFilter.slice(4));
  else if (state.feedFilter.startsWith('feed:')) arts = (ARTICLES[parseInt(state.feedFilter.slice(5))] || []).slice().sort((a, b) => b.timestamp - a.timestamp);
  else arts = allArticles();

  if (state.readFilter === 'unread') arts = arts.filter(a => !isRead(a.id));

  const q = norm(state.query);
  if (q) {
    const terms = q.split(' ').filter(Boolean);
    arts = arts.filter(a => {
      const blob = norm(`${a.title} ${a.excerpt} ${a.feedName} ${a.cat} ${extractKeywords(a.id, a.title, a.excerpt).join(' ')}`);
      return terms.every(t => blob.includes(t));
    });
  }
  return arts;
}

function paneTitle() {
  if (state.feedFilter === 'saved') return 'Sauvegardés';
  if (state.feedFilter.startsWith('cat:')) return state.feedFilter.slice(4);
  if (state.feedFilter.startsWith('feed:')) {
    const f = DB.feeds.find(x => x.id === parseInt(state.feedFilter.slice(5)));
    return f ? f.name : 'Flux';
  }
  return 'Tous les articles';
}

function articleCardHtml(a, opts = {}) {
  const compact = opts.compact;
  const saved = isSaved(a.id);
  const unread = !isRead(a.id) && !opts.noUnread;
  const kws = compact ? [] : extractKeywords(a.id, a.title, a.excerpt);

  return `
  <div class="article-card cat-${cssClass(a.cat)} ${unread ? 'unread' : ''} ${state.activeId === a.id ? 'active' : ''}"
       data-act="open" data-id="${escHtml(a.id)}" role="button" tabindex="0">
    <div class="article-card-top">
      <span class="cat-pill"><span class="cat-pill-dot"></span>${escHtml(a.cat)}</span>
      <span class="art-source">${escHtml(a.feedName)}</span>
      <span class="art-date">${escHtml(a.date || '')}</span>
    </div>
    ${compact ? `<div class="art-title-compact">${escHtml(a.title)}</div>` : `
    <div class="article-card-body">
      <div class="article-card-text">
        <div class="art-title">${escHtml(a.title)}</div>
        <div class="art-excerpt">${escHtml(a.excerpt)}</div>
      </div>
      ${a.image ? `<img class="art-thumb" src="${escHtml(a.image)}" alt="" loading="lazy" onerror="this.remove()">` : ''}
    </div>
    ${kws.length ? `<div class="article-keywords">${kws.map(k => `<span class="keyword-tag">#${escHtml(k)}</span>`).join('')}</div>` : ''}
    <div class="article-card-footer">
      <button class="art-action ${saved ? 'saved' : ''}" data-act="save" data-id="${escHtml(a.id)}">
        <svg viewBox="0 0 24 24" width="12" height="12" fill="${saved ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
        ${saved ? 'Sauvegardé' : 'Sauvegarder'}
      </button>
      <button class="art-action" data-act="read" data-id="${escHtml(a.id)}">
        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        ${isRead(a.id) ? 'Marquer non lu' : 'Marquer lu'}
      </button>
      ${opts.follow && a.subscribed === false
        ? `<button class="art-follow" data-act="follow" data-url="${escHtml(a.feedUrl)}" data-name="${escHtml(a.feedName)}" data-cat="${escHtml(a.cat)}">Suivre ${escHtml(a.feedName)}</button>`
        : ''}
    </div>`}
  </div>`;
}

function renderArticles() {
  const input = document.getElementById('articlesSearchInput');
  if (input && input.value !== state.query) input.value = state.query;
  document.getElementById('articlesSearchClear').style.display = state.query.trim() ? 'grid' : 'none';

  const arts = filteredArticles();
  document.getElementById('paneTitle').textContent = paneTitle();
  setPaneSub(`${arts.length} article${arts.length !== 1 ? 's' : ''}`);

  const list = document.getElementById('articlesList');
  if (!arts.length) {
    const q = state.query.trim();
    let msg, cta = '';
    if (!DB.feeds.length && state.feedFilter !== 'saved') {
      msg = 'Aucune source suivie pour l’instant.';
      cta = `<button class="btn-mini primary" data-act="goto-discover">Trouver des sources</button>`;
    } else if (q) {
      msg = `Rien pour « ${escHtml(q)} » dans vos flux.`;
      cta = `<button class="btn-mini primary" data-act="search-web" data-q="${escHtml(q)}">Chercher dans toutes les sources</button>`;
    } else if (state.feedFilter === 'saved') {
      msg = 'Rien de sauvegardé. Le marque-page d’un article l’ajoute ici.';
    } else {
      msg = 'Aucun article ici.';
    }
    list.innerHTML = `<div class="empty-state">
      <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <p>${msg}</p>${cta}</div>`;
    return;
  }

  const compact = state.activeId !== null;
  let html = '';
  let lastDay = null;
  arts.forEach(a => {
    if (!compact) {
      const d = dayLabel(a.timestamp);
      if (d !== lastDay) { html += `<div class="day-sep">${escHtml(d)}</div>`; lastDay = d; }
    }
    html += articleCardHtml(a, { compact });
  });
  list.innerHTML = html;
}

function setArticleSearch(v) { state.query = v; renderArticles(); }
function clearArticleSearch() {
  state.query = '';
  renderArticles();
  document.getElementById('articlesSearchInput').focus();
}

/* ── 7. Lecteur ────────────────────────────────────────────────────────── */
function readerToolbarHtml(a) {
  const saved = isSaved(a.id);
  return `
  <div class="reader-toolbar">
    <button class="rt-btn" data-act="close-reader" title="Fermer (Échap)">
      <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      Retour
    </button>
    <span class="rt-sep"></span>
    <div class="rt-group" title="Papier">
      ${['paper', 'sepia', 'night'].map(t => `<button class="rt-swatch ${prefs.readTheme === t ? 'active' : ''}" data-act="read-theme" data-t="${t}" aria-label="Papier ${t}"></button>`).join('')}
    </div>
    <span class="rt-sep"></span>
    <div class="rt-group">
      <button class="rt-btn" data-act="font-size" data-d="-1" title="Réduire le texte">A−</button>
      <span class="rt-size">${prefs.readSize}px</span>
      <button class="rt-btn" data-act="font-size" data-d="1" title="Agrandir le texte">A+</button>
    </div>
    <button class="rt-btn" data-act="font-family" title="Changer de police">${prefs.readFont === 'serif' ? 'Serif' : 'Sans'}</button>
    <button class="rt-btn" data-act="measure" title="Largeur de la colonne">
      <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6v12"/></svg>
      ${prefs.readMeasure}
    </button>
    <span class="rt-spacer"></span>
    <button class="rt-btn ${saved ? 'saved' : ''}" data-act="save" data-id="${escHtml(a.id)}" title="Sauvegarder (S)">
      <svg viewBox="0 0 24 24" width="13" height="13" fill="${saved ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
    </button>
    <button class="rt-btn" data-act="focus-mode" title="Mode focus (F)">
      <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 00-2 2v3m18 0V5a2 2 0 00-2-2h-3m0 18h3a2 2 0 002-2v-3M3 16v3a2 2 0 002 2h3"/></svg>
    </button>
  </div>`;
}

function renderReader(a, { loading = false, html = null } = {}) {
  const panel = document.getElementById('readerPanel');
  panel.dataset.readTheme = prefs.readTheme;
  const kws = extractKeywords(a.id, a.title, a.excerpt);

  let content;
  if (loading) {
    content = `
      <div class="reader-excerpt-preview"><p>${escHtml(a.excerpt)}</p></div>
      <div class="skeleton-block">
        ${[100, 88, 95, 76, 92, 84, 97, 71, 89, 83].map((w, i) => `<div class="skel-line" style="width:${w}%;animation-delay:${i * .06}s"></div>`).join('')}
      </div>`;
  } else if (html) {
    content = `
      <div class="reader-read-time-row">
        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        ${estimateReadTime(html)}
      </div>
      <div class="reader-full-content">${html}</div>`;
  } else {
    content = `
      <div class="reader-excerpt-preview"><p>${escHtml(a.excerpt)}</p></div>
      <div class="reader-unavailable">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Extrait du flux — l’article complet n’a pas pu être récupéré.
      </div>`;
  }

  const showHero = !!a.image && !(html && html.includes(a.image.split('?')[0]));
  const notFollowed = a.subscribed === false && a.feedUrl;

  panel.innerHTML = `
    <div class="reader-progress-bar"><div class="reader-progress-fill" id="rpFill"></div></div>
    ${readerToolbarHtml(a)}
    <div class="reader-panel-inner cat-${cssClass(a.cat)}">
      ${notFollowed ? `
        <div class="reader-notice">
          <span>Vous ne suivez pas <strong>${escHtml(a.feedName)}</strong> — lecture en aperçu.</span>
          <button class="btn-inline" data-act="follow" data-url="${escHtml(a.feedUrl)}" data-name="${escHtml(a.feedName)}" data-cat="${escHtml(a.cat)}">Suivre cette source</button>
        </div>` : ''}
      <span class="reader-cat-pill"><span class="cat-pill-dot"></span>${escHtml(a.cat)}</span>
      <h1 class="reader-headline">${escHtml(a.title)}</h1>
      <div class="reader-meta-row">
        <span class="reader-source">${escHtml(a.feedName)}</span>
        ${a.author ? `<span class="reader-sep"></span><span class="reader-author">${escHtml(a.author)}</span>` : ''}
        <span class="reader-sep"></span>
        <span class="reader-date">${escHtml(a.date || '')}</span>
      </div>
      ${kws.length ? `<div class="reader-keywords">${kws.map(k => `<span class="reader-kw-tag">#${escHtml(k)}</span>`).join('')}</div>` : ''}
      ${showHero ? `<img class="reader-hero" src="${escHtml(a.image)}" alt="" loading="lazy" onerror="this.remove()">` : ''}
      <div class="reader-content">${content}</div>
      <div class="reader-actions">
        <a class="reader-btn primary" href="${escHtml(a.link)}" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          Ouvrir la source
        </a>
        <button class="reader-btn ${isSaved(a.id) ? 'saved' : ''}" data-act="save" data-id="${escHtml(a.id)}">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="${isSaved(a.id) ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
          ${isSaved(a.id) ? 'Sauvegardé' : 'Sauvegarder'}
        </button>
      </div>
    </div>`;

  enhanceReaderContent();
  mountSideActions();

  panel.onscroll = () => {
    const fill = document.getElementById('rpFill');
    if (!fill) return;
    const t = panel.scrollHeight - panel.clientHeight;
    fill.style.width = t > 0 ? (panel.scrollTop / t * 100) + '%' : '0%';
  };
}

/** Retouches sur le HTML récupéré : tableaux scrollables, liens internes. */
function enhanceReaderContent() {
  const root = document.querySelector('.reader-full-content');
  if (!root) return;
  root.querySelectorAll('table').forEach(t => {
    if (t.parentElement?.classList.contains('table-scroll-wrap')) return;
    const w = document.createElement('div');
    w.className = 'table-scroll-wrap';
    t.parentNode.insertBefore(w, t);
    w.appendChild(t);
  });
  root.querySelectorAll('a[data-open-in-reader="1"]').forEach(link => {
    if (link.dataset.bound) return;
    link.dataset.bound = '1';
    link.addEventListener('click', e => {
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      e.preventDefault();
      openExternalArticle(link.getAttribute('href'), link.getAttribute('data-article-title') || link.textContent.trim());
    });
  });
}

function enterReaderLayout() {
  document.getElementById('articlesPane').classList.add('pane-hidden');
  document.querySelector('.reader-layout').classList.add('reader-mode', 'articles-collapsed');
  document.getElementById('readerPanel').style.display = 'block';
}

async function openArticle(id) {
  const a = getArticle(id);
  if (!a) return;

  if (state.view !== 'reader') switchTab('reader');
  state.activeId = id;
  if (!isRead(id)) markRead(id, true);

  enterReaderLayout();
  renderReader(a, { loading: true });
  renderSidebar();
  renderArticles();
  document.getElementById('readerPanel').scrollTop = 0;

  try {
    const data = await apiGet({ action: 'fetch_article', url: a.link });
    if (state.activeId === id) renderReader(a, { html: data.html || null });
  } catch (_) {
    if (state.activeId === id) renderReader(a, { html: null });
  }
}

/** Ouvre une URL quelconque dans le lecteur (liens « à lire ensuite »). */
async function openExternalArticle(url, titleHint) {
  if (!url) return;
  const id = 'ext:' + url;
  const a = {
    id, feedId: -1, feedName: hostOf(url), cat: 'Autre',
    title: titleHint || hostOf(url), link: url,
    excerpt: 'Chargement du contenu…', image: '', date: 'Web', subscribed: undefined,
  };
  INDEX.set(id, a);
  state.activeId = id;
  enterReaderLayout();
  renderReader(a, { loading: true });
  document.getElementById('readerPanel').scrollTop = 0;
  try {
    const data = await apiGet({ action: 'fetch_article', url });
    if (state.activeId === id) renderReader(a, { html: data.html || null });
  } catch (_) {
    if (state.activeId === id) renderReader(a, { html: null });
  }
}

function closeReader() {
  state.activeId = null;
  document.getElementById('articlesPane').classList.remove('pane-hidden');
  document.querySelector('.reader-layout').classList.remove('reader-mode', 'articles-collapsed', 'articles-visible', 'focus-mode');
  document.getElementById('readerPanel').style.display = '';
  document.getElementById('readerSideActions')?.remove();
  document.getElementById('readerPanel').innerHTML = emptyReaderHtml();
  renderArticles();
}

function emptyReaderHtml() {
  return `<div class="reader-empty">
    <div class="reader-empty-icon">
      <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/></svg>
    </div>
    <p>Choisissez un article à lire.<br><span style="font-size:12px">Navigation : <kbd>J</kbd> <kbd>K</kbd> · Ouvrir : <kbd>Entrée</kbd></span></p>
  </div>`;
}

/* Boutons collés au bord du lecteur */
function mountSideActions() {
  const panel = document.getElementById('readerPanel');
  let stack = document.getElementById('readerSideActions');
  if (!stack) {
    stack = document.createElement('div');
    stack.id = 'readerSideActions';
    stack.className = 'reader-side-actions';
    panel.insertBefore(stack, panel.firstChild);
  }
  const collapsed = document.querySelector('.reader-layout').classList.contains('articles-collapsed');
  stack.innerHTML = `
    <button class="close-reader-btn" data-act="close-reader" title="Fermer l'article">
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg><span>Fermer</span>
    </button>
    <button class="toggle-articles-btn" data-act="toggle-list" title="${collapsed ? 'Afficher la liste' : 'Masquer la liste'}">
      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5"><path d="${collapsed ? 'M9 18l6-6-6-6' : 'M15 18l-6-6 6-6'}"/></svg>
      <span>${collapsed ? 'Articles' : 'Masquer'}</span>
    </button>`;
}

function toggleArticlesPane() {
  const layout = document.querySelector('.reader-layout');
  const collapsed = layout.classList.contains('articles-collapsed');
  layout.classList.toggle('articles-collapsed', !collapsed);
  layout.classList.toggle('articles-visible', collapsed);
  mountSideActions();
  if (collapsed) renderArticles();
}
function toggleFocusMode() {
  document.querySelector('.reader-layout').classList.toggle('focus-mode');
}

/* ── Actions sur un article ────────────────────────────────────────────── */
async function toggleSaved(id) {
  const a = getArticle(id);
  if (!a) return;
  const willSave = !isSaved(id);

  if (willSave) { savedSet.add(id); DB.saved.unshift({ ...a }); }
  else { savedSet.delete(id); DB.saved = DB.saved.filter(s => s.id !== id); }
  renderSidebar(); renderArticles();
  if (state.activeId === id) renderReader(a, { html: currentHtml() });

  try {
    const res = await apiPost('toggle_saved', { articleId: id, article: JSON.stringify(a) });
    if (res.error) throw new Error(res.error);
    toast(willSave ? 'Article sauvegardé' : 'Retiré des sauvegardés');
  } catch (e) {
    // Retour arrière si le serveur refuse.
    if (willSave) { savedSet.delete(id); DB.saved = DB.saved.filter(s => s.id !== id); }
    else { savedSet.add(id); DB.saved.unshift({ ...a }); }
    renderSidebar(); renderArticles();
    toast('Sauvegarde impossible', 'error');
  }
}

function currentHtml() {
  const el = document.querySelector('.reader-full-content');
  return el ? el.innerHTML : null;
}

async function markRead(id, read) {
  if (read) readSet.add(id); else readSet.delete(id);
  renderSidebar();
  try { await apiPost('set_read', { ids: JSON.stringify([id]), read: read ? '1' : '0' }); }
  catch (_) { /* l'état local reste, la prochaine action retentera */ }
}
function toggleRead(id) {
  markRead(id, !isRead(id));
  renderArticles();
}

/* ── 8. Découvrir ─────────────────────────────────────────────────────────
   Le point clé : chercher et lire sans rien enregistrer. */
function renderDiscoverControls() {
  document.querySelectorAll('#discoverScope .scope-pill').forEach(b => {
    b.classList.toggle('active', b.dataset.scope === state.discover.scope);
  });
  const chips = document.getElementById('discoverCats');
  chips.innerHTML = ['', ...CAT_LIST].map(c => `
    <button class="chip ${state.discover.cat === c ? 'active' : ''}" data-act="discover-cat" data-cat="${escHtml(c)}">
      ${c === '' ? 'Toutes' : escHtml(c)}
    </button>`).join('');
}

function sourceCardHtml(f) {
  return `
  <div class="source-card cat-${cssClass(f.cat)}">
    <div class="feed-icon-box">
      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5">
        <circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/>
        <path d="M4 11a8 8 0 018 8" stroke-linecap="round"/><path d="M4 5a14 14 0 0114 14" stroke-linecap="round"/></svg>
    </div>
    <div class="source-card-info">
      <div class="source-card-name">
        ${escHtml(f.name)}
        <span class="cat-pill"><span class="cat-pill-dot"></span>${escHtml(f.cat)}</span>
        ${f.lang ? `<span class="lang-tag">${escHtml(f.lang)}</span>` : ''}
      </div>
      <div class="source-card-desc">${escHtml(f.desc || hostOf(f.url))}</div>
      <div class="source-card-actions">
        <button class="btn-mini" data-act="preview" data-url="${escHtml(f.url)}" data-name="${escHtml(f.name)}" data-cat="${escHtml(f.cat)}">Aperçu</button>
        ${f.subscribed
          ? `<button class="btn-mini done" disabled>✓ Suivi</button>`
          : `<button class="btn-mini primary" data-act="follow" data-url="${escHtml(f.url)}" data-name="${escHtml(f.name)}" data-cat="${escHtml(f.cat)}">Suivre</button>`}
      </div>
    </div>
  </div>`;
}

function renderDiscover() {
  renderDiscoverControls();
  const d = state.discover;
  const box = document.getElementById('discoverResults');

  if (d.loading) {
    box.innerHTML = `<div class="loading-state"><div class="loader"></div><p>${escHtml(d.loadingMsg || 'Recherche en cours…')}</p></div>`;
    return;
  }
  if (d.error) {
    box.innerHTML = `<div class="discover-section"><div class="discover-error">${escHtml(d.error)}</div></div>`;
    return;
  }
  if (!d.ran) {
    box.innerHTML = '';
    return;
  }

  let html = '';

  // Aperçu d'une source précise
  if (d.preview) {
    const p = d.preview;
    html += `
    <div class="discover-section">
      <div class="discover-section-head">
        <span class="discover-section-title">Aperçu · ${escHtml(p.feed.name)}</span>
        <span class="discover-section-sub">${p.articles.length} articles · rien n’est enregistré</span>
        <span style="flex:1"></span>
        ${p.subscribed
          ? `<button class="btn-mini done" disabled>✓ Suivi</button>`
          : `<button class="btn-mini primary" data-act="follow" data-url="${escHtml(p.feed.url)}" data-name="${escHtml(p.feed.name)}" data-cat="${escHtml(p.feed.cat)}">Suivre cette source</button>`}
        <button class="btn-mini" data-act="close-preview">Fermer l’aperçu</button>
      </div>
      ${p.feed.desc ? `<p class="discover-sub" style="margin-bottom:10px">${escHtml(p.feed.desc)}</p>` : ''}
      <div class="result-list">${p.articles.map(a => articleCardHtml(a, { noUnread: !p.subscribed })).join('')}</div>
    </div>`;
    box.innerHTML = html;
    return;
  }

  if (d.sources.length) {
    html += `
    <div class="discover-section">
      <div class="discover-section-head">
        <span class="discover-section-title">Sources</span>
        <span class="discover-section-sub">${d.sources.length} dans le catalogue</span>
      </div>
      <div class="source-grid">${d.sources.map(sourceCardHtml).join('')}</div>
    </div>`;
  }

  if (d.results.length) {
    html += `
    <div class="discover-section">
      <div class="discover-section-head">
        <span class="discover-section-title">Articles</span>
        <span class="discover-section-sub">${d.total} trouvé${d.total > 1 ? 's' : ''} sur ${d.sourcesCount} source${d.sourcesCount > 1 ? 's' : ''} interrogées</span>
      </div>
      <div class="result-list">${d.results.map(a => articleCardHtml(a, { follow: true, noUnread: false })).join('')}</div>
    </div>`;
  }

  if (!html) {
    html = `<div class="empty-state">
      <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <p>Rien pour « ${escHtml(d.q)} ».<br>Essayez un mot plus général, ou collez l’URL d’un site.</p></div>`;
  }
  box.innerHTML = html;
}

async function runDiscover() {
  const q = document.getElementById('discoverInput').value.trim();
  const d = state.discover;
  d.q = q; d.error = ''; d.preview = null; d.ran = true;
  if (!q) { d.sources = []; d.results = []; d.ran = false; renderDiscover(); return; }

  // Une URL collée : on cherche le flux du site tout seul.
  if (looksLikeUrl(q)) {
    d.loading = true; d.loadingMsg = 'Recherche d’un flux sur ce site…';
    renderDiscover();
    try {
      const data = await apiGet({ action: 'discover_feed', url: q });
      d.loading = false;
      if (data.error) { d.error = data.error; renderDiscover(); return; }
      indexArticles(data.articles);
      d.preview = { feed: data.feed, subscribed: data.subscribed, articles: data.articles };
      d.sources = []; d.results = [];
    } catch (e) {
      d.loading = false; d.error = 'Le site n’a pas répondu.';
    }
    renderDiscover();
    return;
  }

  d.loading = true;
  d.loadingMsg = d.scope === 'mine' ? 'Recherche dans vos flux…' : 'Lecture des sources du catalogue…';
  renderDiscover();

  try {
    const [feeds, articles] = await Promise.all([
      d.scope === 'mine' ? Promise.resolve({ results: [] }) : apiGet({ action: 'search_feeds', q, limit: 6 }),
      apiGet({ action: 'search_articles', q, scope: d.scope, cat: d.cat, limit: 40 }),
    ]);
    d.sources = feeds.results || [];
    d.results = articles.results || [];
    d.total = articles.total || 0;
    d.sourcesCount = articles.sources || 0;
    indexArticles(d.results);
    if (articles.failed?.length) console.warn('Flux injoignables :', articles.failed);
  } catch (e) {
    d.error = 'La recherche a échoué. Réessayez.';
  }
  d.loading = false;
  renderDiscover();
}

async function previewSource(url, name, cat) {
  const d = state.discover;
  d.loading = true; d.loadingMsg = `Lecture de ${name}…`; d.error = ''; d.ran = true;
  renderDiscover();
  try {
    const data = await apiGet({ action: 'preview_feed', url, name, cat });
    if (data.error) { d.error = data.error; }
    else {
      indexArticles(data.articles);
      d.preview = { feed: data.feed, subscribed: data.subscribed, articles: data.articles };
    }
  } catch (e) {
    d.error = 'Ce flux n’a pas répondu.';
  }
  d.loading = false;
  renderDiscover();
}

async function followSource(url, name, cat) {
  try {
    const data = await apiPost('add_feed', { url, name, cat: cat || 'Autre' });
    if (data.error) { toast(data.error, 'error'); return; }
    DB.feeds.push(data.feed);
    ARTICLES[data.feed.id] = data.articles || [];
    indexArticles(ARTICLES[data.feed.id]);

    // Ce qui est affiché ailleurs doit refléter le nouvel abonnement.
    [state.discover.results, state.discover.preview?.articles].forEach(list => {
      (list || []).forEach(a => { if (a.feedUrl === url) a.subscribed = true; });
    });
    state.discover.sources.forEach(s => { if (s.url === url) s.subscribed = true; });
    if (state.discover.preview?.feed.url === url) state.discover.preview.subscribed = true;

    toast(`Vous suivez ${data.feed.name}`, 'ok');
    renderSidebar(); renderArticles(); renderDiscover(); renderManage();
    if (state.activeId) {
      const a = getArticle(state.activeId);
      if (a && a.feedUrl === url) { a.subscribed = true; renderReader(a, { html: currentHtml() }); }
    }
  } catch (e) {
    toast('Abonnement impossible', 'error');
  }
}

/* ── 9. Gérer ──────────────────────────────────────────────────────────── */
function renderManage() {
  const label = document.getElementById('feedsCountLabel');
  if (!label) return;
  label.textContent = `${DB.feeds.length} source${DB.feeds.length !== 1 ? 's' : ''} suivie${DB.feeds.length !== 1 ? 's' : ''}`;

  const body = document.getElementById('feedsList');
  if (!DB.feeds.length) {
    body.innerHTML = `<div class="empty-state" style="padding:2rem">
      <p>Aucune source suivie.</p>
      <button class="btn-mini primary" data-act="goto-discover">Aller dans Découvrir</button></div>`;
    return;
  }
  body.innerHTML = `<div class="feeds-list-body">${DB.feeds.map(f => {
    const n = (ARTICLES[f.id] || []).length;
    return `
    <div class="feed-row">
      <div class="feed-icon-box cat-${cssClass(f.cat)}">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5">
          <circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/>
          <path d="M4 11a8 8 0 018 8" stroke-linecap="round"/><path d="M4 5a14 14 0 0114 14" stroke-linecap="round"/></svg>
      </div>
      <div class="feed-row-info">
        <div class="feed-row-name">${escHtml(f.name)}</div>
        <div class="feed-row-url">${escHtml(f.url)}</div>
        <div class="feed-row-tags">
          <select class="feed-cat-select" data-act="change-cat" data-id="${f.id}">
            ${CAT_LIST.map(c => `<option ${c === f.cat ? 'selected' : ''}>${escHtml(c)}</option>`).join('')}
          </select>
          <span class="feed-count-tag">${n} article${n !== 1 ? 's' : ''}</span>
        </div>
      </div>
      <button class="btn-delete" data-act="delete-feed" data-id="${f.id}">Retirer</button>
    </div>`;
  }).join('')}</div>`;
}

async function deleteFeed(id) {
  const f = DB.feeds.find(x => x.id === id);
  if (!f || !confirm(`Ne plus suivre « ${f.name} » ?`)) return;
  await apiPost('delete_feed', { id });
  DB.feeds = DB.feeds.filter(x => x.id !== id);
  delete ARTICLES[id];
  state.discover.sources.forEach(s => { if (s.url === f.url) s.subscribed = false; });
  renderSidebar(); renderArticles(); renderManage(); renderDiscover();
  toast('Source retirée');
}

async function changeCat(id, cat) {
  const res = await apiPost('update_feed', { id, cat });
  if (res.error) { toast(res.error, 'error'); return; }
  const f = DB.feeds.find(x => x.id === id);
  if (f) f.cat = cat;
  (ARTICLES[id] || []).forEach(a => { a.cat = cat; });
  renderSidebar(); renderArticles(); renderManage();
}

function openAddModal() { document.getElementById('addModal').style.display = 'flex'; document.getElementById('modalUrl').focus(); }
function closeAddModal() { document.getElementById('addModal').style.display = 'none'; }
async function addManual() {
  const url = document.getElementById('modalUrl').value.trim();
  const name = document.getElementById('modalName').value.trim();
  const cat = document.getElementById('modalCat').value;
  if (!url) { toast('Indiquez l’URL du flux', 'error'); return; }
  const btn = document.getElementById('modalConfirm');
  btn.disabled = true; btn.textContent = 'Vérification…';
  await followSource(url, name, cat);
  btn.disabled = false; btn.textContent = 'Ajouter';
  closeAddModal();
  document.getElementById('modalName').value = '';
  document.getElementById('modalUrl').value = '';
}

/* ── Onglets ───────────────────────────────────────────────────────────── */
function switchTab(tab) {
  state.view = tab;
  document.querySelectorAll('.nav-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.getElementById('tabReader').style.display = tab === 'reader' ? 'grid' : 'none';
  document.getElementById('tabDiscover').style.display = tab === 'discover' ? 'block' : 'none';
  document.getElementById('tabManage').style.display = tab === 'manage' ? 'block' : 'none';
  if (tab === 'manage') renderManage();
  if (tab === 'discover') { renderDiscover(); document.getElementById('discoverInput').focus(); }
}

function gotoDiscover(q) {
  switchTab('discover');
  if (q) {
    document.getElementById('discoverInput').value = q;
    state.discover.scope = 'catalog';
    runDiscover();
  }
}

/* ── 10. Événements & clavier ──────────────────────────────────────────── */
function bindEvents() {
  document.addEventListener('click', e => {
    const el = e.target.closest('[data-act]');
    if (!el) return;
    const { act, id, url, name, cat, filter, q, t, d } = el.dataset;

    switch (act) {
      case 'open':          openArticle(id); break;
      case 'save':          e.stopPropagation(); toggleSaved(id); break;
      case 'read':          e.stopPropagation(); toggleRead(id); break;
      case 'follow':        e.stopPropagation(); followSource(url, name, cat); break;
      case 'preview':       previewSource(url, name, cat); break;
      case 'close-preview': state.discover.preview = null; runDiscover(); break;
      case 'filter':        setFeedFilter(filter); break;
      case 'goto-discover': gotoDiscover(); break;
      case 'search-web':    gotoDiscover(q); break;
      case 'close-reader':  closeReader(); break;
      case 'toggle-list':   toggleArticlesPane(); break;
      case 'focus-mode':    toggleFocusMode(); break;
      case 'delete-feed':   deleteFeed(parseInt(id)); break;
      case 'discover-cat':  state.discover.cat = cat; if (state.discover.q) runDiscover(); else renderDiscoverControls(); break;
      case 'read-theme':    prefs.readTheme = t; savePrefs(); refreshReaderChrome(); break;
      case 'font-size':     prefs.readSize = Math.min(24, Math.max(14, prefs.readSize + parseInt(d))); savePrefs(); refreshReaderChrome(); break;
      case 'font-family':   prefs.readFont = prefs.readFont === 'serif' ? 'sans' : 'serif'; savePrefs(); refreshReaderChrome(); break;
      case 'measure':       prefs.readMeasure = { 60: 68, 68: 78, 78: 60 }[prefs.readMeasure] || 68; savePrefs(); refreshReaderChrome(); break;
    }
  });

  document.addEventListener('change', e => {
    const el = e.target.closest('[data-act="change-cat"]');
    if (el) changeCat(parseInt(el.dataset.id), el.value);
  });

  document.getElementById('discoverScope').addEventListener('click', e => {
    const b = e.target.closest('.scope-pill');
    if (!b) return;
    state.discover.scope = b.dataset.scope;
    renderDiscoverControls();
    if (state.discover.q) runDiscover();
  });

  document.getElementById('discoverInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') runDiscover();
  });

  document.addEventListener('keydown', e => {
    const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName);
    if (e.key === 'Escape') {
      if (document.getElementById('addModal').style.display === 'flex') return closeAddModal();
      if (typing) return e.target.blur();
      if (state.activeId) return closeReader();
    }
    if (typing || e.metaKey || e.ctrlKey || e.altKey) return;

    if (e.key === '/') { e.preventDefault(); (state.view === 'discover' ? document.getElementById('discoverInput') : document.getElementById('articlesSearchInput')).focus(); }
    if (state.view !== 'reader') return;
    if (e.key === 'j' || e.key === 'k') { e.preventDefault(); stepArticle(e.key === 'j' ? 1 : -1); }
    if (e.key === 's' && state.activeId) toggleSaved(state.activeId);
    if (e.key === 'f' && state.activeId) toggleFocusMode();
    if (e.key === 'r') refreshAllFeeds();
  });
}

/** Redessine le lecteur en gardant le contenu déjà chargé. */
function refreshReaderChrome() {
  if (!state.activeId) return;
  const a = getArticle(state.activeId);
  if (a) renderReader(a, { html: currentHtml() });
}

function stepArticle(dir) {
  const arts = filteredArticles();
  if (!arts.length) return;
  const i = arts.findIndex(a => a.id === state.activeId);
  const next = arts[Math.min(arts.length - 1, Math.max(0, (i < 0 ? -1 : i) + dir))];
  if (next) openArticle(next.id);
}

/* ── 11. Démarrage ─────────────────────────────────────────────────────── */
Object.assign(window, {
  switchTab, refreshAllFeeds, setReadFilter, setArticleSearch, clearArticleSearch,
  openAddModal, closeAddModal, addManual, runDiscover, toggleTheme, gotoDiscover,
});

init();
