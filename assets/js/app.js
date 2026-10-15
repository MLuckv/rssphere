/* RSSphere — app.js v5
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
const DEFAULT_PREFS = {
  theme: 'auto', readTheme: 'paper', readSize: 18, readFont: 'serif',
  readMeasure: 68, readLeading: 1.7, listView: 'list', discoverLang: '',
};
const READ_FONTS = {
  serif: { label: 'Serif', css: 'var(--font-read)' },
  sans: { label: 'Sans', css: 'var(--font-body)' },
  legible: { label: 'Lisible', css: 'var(--font-legible)' },
};
const READ_MEASURES = [[60, 'Étroite'], [68, 'Normale'], [78, 'Large']];
const READ_LEADINGS = [[1.5, 'Serré'], [1.7, 'Normal'], [1.9, 'Aéré']];
const READ_PAPERS = [['paper', 'Papier'], ['sepia', 'Sépia'], ['night', 'Nuit']];
let prefs = { ...DEFAULT_PREFS };

function loadPrefs() {
  try { prefs = { ...DEFAULT_PREFS, ...JSON.parse(localStorage.getItem(PREFS_KEY) || '{}') }; }
  catch (_) { prefs = { ...DEFAULT_PREFS }; }
  if (!READ_FONTS[prefs.readFont]) prefs.readFont = 'serif';
  state.discover.lang = prefs.discoverLang || '';
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
  root.setProperty('--read-leading', prefs.readLeading);
  root.setProperty('--read-family', READ_FONTS[prefs.readFont].css);
  const panel = document.getElementById('readerPanel');
  if (panel) panel.dataset.readTheme = prefs.readTheme;
}
function toggleTheme() {
  prefs.theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
  savePrefs();
}

/* ── 2. Utilitaires ────────────────────────────────────────────────────── */
const CAT_LIST = ['Tech', 'Actualités', 'Science', 'Culture', 'Histoire', 'Sport', 'Économie', 'Gaming', 'Autre'];
const DISCOVER_SUGGESTIONS = ['Intelligence artificielle', 'Jeux vidéo', 'Cuisine', 'Cinéma', 'Astronomie', 'Moyen Âge', 'Photographie', 'Football', 'Startups'];
const DISCOVER_LANGS = [['', 'Toutes langues'], ['fr', 'Français'], ['en', 'Anglais'], ['other', 'Autres']];

/** Filtre de langue de Découvrir : '' (toutes), 'fr', 'en' ou 'other'. */
function langMatches(lang, want) {
  if (!want) return true;
  const l = String(lang || '').slice(0, 2).toLowerCase();
  return want === 'other' ? (l !== '' && l !== 'fr' && l !== 'en') : l === want;
}

function escHtml(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function cssClass(cat) {
  return String(cat || 'Autre').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-zA-Z0-9]/g, '') || 'Autre';
}
function norm(s) {
  return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim();
}
function looksLikeUrl(s) {
  const t = String(s || '').trim();
  if (/\s/.test(t)) return false;
  return /^https?:\/\//i.test(t) || /^[\w-]+(\.[\w-]+)+(\/\S*)?$/.test(t);
}
function hostOf(url) {
  try { return new URL(url).hostname.replace(/^www\./, ''); } catch (_) { return url; }
}
/** Icône d'un site. Les sous-domaines de flux (feeds., rss.…) n'en ont pas : on remonte au site. */
function faviconUrl(url) {
  const h = hostOf(url).replace(/^(feeds?|rss|flux|syndication)\d*\./, '');
  return h ? `https://icons.duckduckgo.com/ip3/${encodeURIComponent(h)}.ico` : '';
}
function faviconHtml(url, cls = '') {
  const src = faviconUrl(url);
  return src ? `<img class="favicon ${cls}" src="${escHtml(src)}" alt="" loading="lazy" onerror="this.classList.add('broken')">` : '';
}
function wordCount(html) {
  return String(html || '').replace(/<[^>]+>/g, ' ').split(/\s+/).filter(Boolean).length;
}
function readMinutes(words) { return Math.max(1, Math.round(words / 230)); }
function formatCount(n) {
  if (n >= 1e6) return (n / 1e6).toFixed(1).replace('.0', '') + ' M';
  if (n >= 1e3) return Math.round(n / 1e3) + ' k';
  return String(n);
}
function relativeDays(ts) {
  if (!ts) return '';
  const d = Math.floor((Date.now() / 1000 - ts) / 86400);
  if (d <= 0) return "aujourd'hui";
  if (d === 1) return 'hier';
  if (d < 30) return `il y a ${d} j`;
  if (d < 365) return `il y a ${Math.round(d / 30)} mois`;
  return `il y a ${Math.round(d / 365)} an${d >= 730 ? 's' : ''}`;
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
/** Petit message. `action` = { label, fn } ajoute un bouton (ex. Annuler). */
function toast(msg, kind, action) {
  const el = document.getElementById('toast');
  el.className = 'toast' + (kind ? ' toast-' + kind : '');
  el.innerHTML = `<span>${escHtml(msg)}</span>${action ? `<button class="toast-action">${escHtml(action.label)}</button>` : ''}`;
  if (action) el.querySelector('.toast-action').onclick = () => { el.classList.remove('show'); action.fn(); };
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), action ? 5000 : 2600);
}

/* Mots-clés : servent au filtre de la liste (pas affichés : trop bruités). */
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
  return kwCache[id] = Object.entries(freq).sort((a, b) => b[1] - a[1]).slice(0, 4).map(([k]) => k);
}

const ICON = {
  bookmark: f => `<svg viewBox="0 0 24 24" width="14" height="14" fill="${f ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>`,
  check: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg>',
  dot: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/></svg>',
  external: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
  back: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>',
  next: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>',
  up: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>',
  down: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>',
  list: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>',
  focus: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 00-2 2v3m18 0V5a2 2 0 00-2-2h-3m0 18h3a2 2 0 002-2v-3M3 16v3a2 2 0 002 2h3"/></svg>',
  clock: '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
  search: '<svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>',
  rss: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/><path d="M4 11a8 8 0 018 8" stroke-linecap="round"/><path d="M4 5a14 14 0 0114 14" stroke-linecap="round"/></svg>',
};

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
  returnTo: 'reader',              // onglet à retrouver en fermant le lecteur
  keepVisible: new Set(),          // lus pendant la session : restent visibles dans « Non lus »
  reader: null,                    // { id, status: loading|ok|fail, html, image, lang, words }
  settingsOpen: false,
  discover: {
    q: '', scope: 'catalog', cat: '', lang: '', tab: 'all', ran: false, seq: 0,
    sources: [], sourcesLoading: false, webError: false,
    results: [], articlesLoading: false, total: 0, sourcesCount: 0, articlesError: '',
    preview: null, previewLoading: false, previewMsg: '', error: '',
    catalog: null,
  },
};

function indexArticles(list) {
  (list || []).forEach(a => {
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
  renderViewToggle();

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
  if (!DB.feeds.length) {
    // Premier lancement : rien à lire, on montre où trouver des sources.
    renderArticles();
    gotoDiscover();
    return;
  }
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
  document.querySelectorAll('.sidebar-item[data-filter="all"], .sidebar-item[data-filter="saved"]').forEach(b => {
    b.classList.toggle('active', state.feedFilter === b.dataset.filter);
  });

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
    </button>`).join('') || `<p class="sidebar-empty">—</p>`;

  document.getElementById('feeds-sidebar-list').innerHTML = DB.feeds.map(f => {
    const u = (ARTICLES[f.id] || []).filter(a => !isRead(a.id)).length;
    return `
    <button class="sidebar-item cat-${cssClass(f.cat)} ${state.feedFilter === 'feed:' + f.id ? 'active' : ''}"
            data-act="filter" data-filter="feed:${f.id}" title="${escHtml(f.name)}">
      <span class="feed-ico"><span class="cat-dot"></span>${faviconHtml(f.url)}</span>
      <span class="label">${escHtml(f.name)}</span>
      ${u > 0 ? `<span class="item-badge unread">${u}</span>` : ''}
    </button>`;
  }).join('') || `<button class="sidebar-item" data-act="goto-discover"><span class="label" style="color:var(--accent)">+ Trouver des sources</span></button>`;
}

function setFeedFilter(filter) {
  state.feedFilter = filter;
  state.keepVisible.clear();
  if (state.activeId) closeReader();
  closeSidebarDrawer();
  if (state.view !== 'reader') switchTab('reader');
  renderSidebar();
  renderArticles();
  document.getElementById('articlesPane').scrollTop = 0;
}
function setReadFilter(f) {
  state.readFilter = f;
  state.keepVisible.clear();
  document.getElementById('pill-all').classList.toggle('active', f === 'all');
  document.getElementById('pill-unread').classList.toggle('active', f === 'unread');
  renderArticles();
}
function setListView(v) {
  prefs.listView = v;
  savePrefs();
  renderViewToggle();
  renderArticles();
}
function renderViewToggle() {
  document.querySelectorAll('[data-act="list-view"]').forEach(b => b.classList.toggle('active', b.dataset.v === prefs.listView));
}

function toggleSidebarDrawer() { document.getElementById('app').classList.toggle('sidebar-open'); }
function closeSidebarDrawer() { document.getElementById('app').classList.remove('sidebar-open'); }

/* ── 6. Liste des articles ─────────────────────────────────────────────── */
function filteredArticles() {
  let arts;
  if (state.feedFilter === 'saved') arts = savedArticles();
  else if (state.feedFilter.startsWith('cat:')) arts = allArticles().filter(a => a.cat === state.feedFilter.slice(4));
  else if (state.feedFilter.startsWith('feed:')) arts = (ARTICLES[parseInt(state.feedFilter.slice(5))] || []).slice().sort((a, b) => b.timestamp - a.timestamp);
  else arts = allArticles();

  if (state.readFilter === 'unread') arts = arts.filter(a => !isRead(a.id) || state.keepVisible.has(a.id));

  const q = norm(state.query);
  if (q) {
    const terms = q.split(' ').filter(Boolean);
    arts = arts.filter(a => {
      const blob = norm(`${a.title} ${a.excerpt} ${a.feedName} ${a.cat} ${a.author || ''} ${extractKeywords(a.id, a.title, a.excerpt).join(' ')}`);
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

function cardActionsHtml(a, opts) {
  const saved = isSaved(a.id);
  const read = isRead(a.id);
  return `
    <div class="card-actions">
      <button class="card-act ${saved ? 'on saved' : ''}" data-act="save" data-id="${escHtml(a.id)}"
              title="${saved ? 'Retirer des sauvegardés' : 'Sauvegarder'} (S)" aria-label="${saved ? 'Retirer des sauvegardés' : 'Sauvegarder'}">${ICON.bookmark(saved)}</button>
      ${opts.noUnread ? '' : `<button class="card-act" data-act="read" data-id="${escHtml(a.id)}"
              title="${read ? 'Marquer non lu' : 'Marquer lu'} (M)" aria-label="${read ? 'Marquer non lu' : 'Marquer lu'}">${read ? ICON.dot : ICON.check}</button>`}
      ${opts.follow && a.subscribed === false && a.feedUrl
        ? `<button class="art-follow" data-act="follow" data-url="${escHtml(a.feedUrl)}" data-name="${escHtml(a.feedName)}" data-cat="${escHtml(a.cat)}">+ Suivre</button>`
        : ''}
    </div>`;
}

function articleCardHtml(a, opts = {}) {
  const read = isRead(a.id);
  const unread = !read && !opts.noUnread;
  const active = state.activeId === a.id;
  const cls = `article-card cat-${cssClass(a.cat)} ${unread ? 'unread' : ''} ${read ? 'is-read' : ''} ${active ? 'active' : ''}`;
  const attrs = `data-act="open" data-id="${escHtml(a.id)}" data-from="${opts.from || 'reader'}" role="button" tabindex="0"`;
  const source = `<span class="art-source">${faviconHtml(a.feedUrl || a.link)}<span>${escHtml(a.feedName)}</span></span>`;
  const date = `<span class="art-date">${escHtml(a.date || '')}</span>`;
  const img = a.image ? `<img class="art-thumb" src="${escHtml(a.image)}" alt="" loading="lazy" decoding="async" onerror="this.closest('.article-card').classList.add('no-img');this.remove()">` : '';

  if (opts.compact) {
    return `<div class="${cls} compact" ${attrs}>
      <div class="article-card-top">${source}${date}</div>
      <div class="art-title-compact">${escHtml(a.title)}</div>
    </div>`;
  }

  if (opts.layout === 'cards') {
    return `<div class="${cls} tile ${a.image ? '' : 'no-img'}" ${attrs}>
      <div class="tile-media">${img}<span class="tile-fallback">${escHtml((a.feedName || '?').trim().charAt(0))}</span></div>
      <div class="tile-body">
        <div class="article-card-top">${source}${date}</div>
        <h3 class="art-title">${escHtml(a.title)}</h3>
        <p class="art-excerpt">${escHtml(a.excerpt)}</p>
        <div class="article-card-footer">
          <span class="cat-pill"><span class="cat-pill-dot"></span>${escHtml(a.cat)}</span>
          ${cardActionsHtml(a, opts)}
        </div>
      </div>
    </div>`;
  }

  return `<div class="${cls}" ${attrs}>
    <div class="article-card-main">
      <div class="article-card-top">${source}${date}</div>
      <h3 class="art-title">${escHtml(a.title)}</h3>
      <p class="art-excerpt">${escHtml(a.excerpt)}</p>
      <div class="article-card-footer">
        <span class="cat-pill"><span class="cat-pill-dot"></span>${escHtml(a.cat)}</span>
        ${cardActionsHtml(a, opts)}
      </div>
    </div>
    ${img}
  </div>`;
}

function renderArticles() {
  const input = document.getElementById('articlesSearchInput');
  if (input && input.value !== state.query) input.value = state.query;
  document.getElementById('articlesSearchClear').style.display = state.query.trim() ? 'grid' : 'none';

  const arts = filteredArticles();
  document.getElementById('paneTitle').textContent = paneTitle();
  const unread = arts.filter(a => !isRead(a.id)).length;
  setPaneSub(`${arts.length} article${arts.length !== 1 ? 's' : ''}${unread && state.readFilter === 'all' ? ` · ${unread} non lu${unread > 1 ? 's' : ''}` : ''}`);

  const list = document.getElementById('articlesList');
  const compact = state.activeId !== null;
  const layout = compact ? 'list' : prefs.listView;
  list.classList.toggle('layout-cards', layout === 'cards');
  list.classList.toggle('layout-compact', compact);
  document.getElementById('articlesPane').classList.toggle('pane-cards', layout === 'cards');

  if (!arts.length) {
    const q = state.query.trim();
    let msg, cta = '';
    if (!DB.feeds.length && state.feedFilter !== 'saved') {
      msg = 'Aucune source suivie pour l’instant.<br>Trouvez des sites, blogs et journaux à suivre dans Découvrir.';
      cta = `<button class="btn-mini primary" data-act="goto-discover">Trouver des sources</button>`;
    } else if (q) {
      msg = `Rien pour « ${escHtml(q)} » dans vos flux.`;
      cta = `<button class="btn-mini primary" data-act="search-web" data-q="${escHtml(q)}">Chercher partout</button>`;
    } else if (state.feedFilter === 'saved') {
      msg = 'Rien de sauvegardé.<br>Le marque-page d’un article l’ajoute ici pour plus tard.';
    } else if (state.readFilter === 'unread') {
      msg = 'Tout est lu. Bravo !';
      cta = `<button class="btn-mini" onclick="setReadFilter('all')">Voir tous les articles</button>`;
    } else {
      msg = 'Aucun article ici.';
    }
    list.innerHTML = `<div class="empty-state">${ICON.search}<p>${msg}</p>${cta}</div>`;
    return;
  }

  let html = '';
  let lastDay = null;
  arts.forEach(a => {
    if (!compact && state.feedFilter !== 'saved') {
      const d = dayLabel(a.timestamp);
      if (d !== lastDay) { html += `<div class="day-sep">${escHtml(d)}</div>`; lastDay = d; }
    }
    html += articleCardHtml(a, { compact, layout });
  });
  list.innerHTML = html;
}

function setArticleSearch(v) { state.query = v; renderArticles(); }
function clearArticleSearch() {
  state.query = '';
  renderArticles();
  document.getElementById('articlesSearchInput').focus();
}

/** « Tout marquer lu » sur la liste affichée, avec annulation. */
async function markAllRead() {
  const ids = filteredArticles().filter(a => !isRead(a.id)).map(a => a.id);
  if (!ids.length) { toast('Rien à marquer : tout est déjà lu'); return; }
  ids.forEach(id => readSet.add(id));
  renderSidebar(); renderArticles();
  toast(`${ids.length} article${ids.length > 1 ? 's' : ''} marqué${ids.length > 1 ? 's' : ''} comme lu${ids.length > 1 ? 's' : ''}`, null, {
    label: 'Annuler',
    fn: () => {
      ids.forEach(id => readSet.delete(id));
      renderSidebar(); renderArticles();
      apiPost('set_read', { ids: JSON.stringify(ids), read: '0' }).catch(() => {});
    },
  });
  try { await apiPost('set_read', { ids: JSON.stringify(ids), read: '1' }); }
  catch (_) { toast('Enregistrement impossible', 'error'); }
}

/* ── 7. Lecteur ────────────────────────────────────────────────────────── */
const contentCache = new Map();    // url -> Promise<{html,image,lang}>

/** Contenu lisible d'une page, mémorisé : revenir sur un article est instantané. */
function fetchArticleContent(url) {
  if (!contentCache.has(url)) {
    const p = apiGet({ action: 'fetch_article', url })
      .then(d => {
        if (!d || !d.html) contentCache.delete(url);   // un échec pourra être retenté
        return d || { html: null };
      })
      .catch(() => { contentCache.delete(url); return { html: null }; });
    contentCache.set(url, p);
  }
  return contentCache.get(url);
}

/** Liste dans laquelle J/K et « article suivant » naviguent. */
function navList() {
  if (state.returnTo === 'discover') {
    const d = state.discover;
    return (d.preview ? d.preview.articles : d.results).map(a => getArticle(a.id)).filter(Boolean);
  }
  return filteredArticles();
}
function neighbors(id) {
  const list = navList();
  const i = list.findIndex(a => a.id === id);
  if (i < 0) return { prev: null, next: null };
  return { prev: list[i - 1] || null, next: list[i + 1] || null };
}

function readerToolbarHtml(a) {
  const saved = isSaved(a.id);
  const { prev, next } = neighbors(a.id);
  const listShown = document.querySelector('.reader-layout')?.classList.contains('articles-visible');
  return `
  <div class="reader-toolbar" id="readerToolbar">
    <button class="rt-btn" data-act="close-reader" title="Fermer (Échap)">${ICON.back}<span class="rt-label">Retour</span></button>
    ${state.returnTo === 'reader' ? `<button class="rt-btn rt-hide-sm ${listShown ? 'on' : ''}" data-act="toggle-list" title="${listShown ? 'Masquer' : 'Afficher'} la liste">${ICON.list}</button>` : ''}
    <span class="rt-remaining" id="rtRemain"></span>
    <span class="rt-spacer"></span>
    <button class="rt-btn" data-act="step" data-d="-1" title="Article précédent (K)" ${prev ? '' : 'disabled'}>${ICON.up}</button>
    <button class="rt-btn" data-act="step" data-d="1" title="Article suivant (J)" ${next ? '' : 'disabled'}>${ICON.down}</button>
    <span class="rt-sep"></span>
    <div class="rt-pop-wrap">
      <button class="rt-btn rt-aa ${state.settingsOpen ? 'on' : ''}" data-act="toggle-settings" title="Réglages de lecture" aria-expanded="${state.settingsOpen}">Aa</button>
      ${state.settingsOpen ? settingsPopoverHtml() : ''}
    </div>
    <button class="rt-btn ${saved ? 'saved' : ''}" data-act="save" data-id="${escHtml(a.id)}" title="${saved ? 'Retirer des sauvegardés' : 'Sauvegarder'} (S)">${ICON.bookmark(saved)}</button>
    <a class="rt-btn" href="${escHtml(a.link)}" target="_blank" rel="noopener" title="Ouvrir sur le site (O)">${ICON.external}</a>
    <button class="rt-btn rt-hide-sm" data-act="focus-mode" title="Mode focus (F)">${ICON.focus}</button>
    <div class="reader-progress-bar" aria-hidden="true"><div class="reader-progress-fill" id="rpFill"></div></div>
  </div>`;
}

function settingsPopoverHtml() {
  const seg = (act, items, cur) => `<div class="seg">${items.map(([v, label]) =>
    `<button class="${String(v) === String(cur) ? 'active' : ''}" data-act="${act}" data-v="${v}">${label}</button>`).join('')}</div>`;
  return `
  <div class="rt-pop" role="dialog" aria-label="Réglages de lecture">
    <div class="rt-pop-row">
      <span class="rt-pop-label">Fond</span>
      <div class="rt-papers">${READ_PAPERS.map(([v, label]) => `
        <button class="rt-paper ${prefs.readTheme === v ? 'active' : ''}" data-act="read-theme" data-v="${v}" data-t="${v}">
          <span class="rt-paper-swatch">Aa</span>${label}</button>`).join('')}
      </div>
    </div>
    <div class="rt-pop-row">
      <span class="rt-pop-label">Taille du texte</span>
      <div class="rt-size-row">
        <button class="rt-size-btn" data-act="font-size" data-d="-1" aria-label="Réduire">A−</button>
        <input type="range" min="14" max="26" step="1" value="${prefs.readSize}" data-act="font-size-range" aria-label="Taille du texte">
        <button class="rt-size-btn big" data-act="font-size" data-d="1" aria-label="Agrandir">A+</button>
        <span class="rt-size-val">${prefs.readSize}</span>
      </div>
    </div>
    <div class="rt-pop-row"><span class="rt-pop-label">Police</span>
      ${seg('font-family', Object.entries(READ_FONTS).map(([k, f]) => [k, f.label]), prefs.readFont)}</div>
    <div class="rt-pop-row"><span class="rt-pop-label">Largeur</span>${seg('measure', READ_MEASURES, prefs.readMeasure)}</div>
    <div class="rt-pop-row"><span class="rt-pop-label">Interligne</span>${seg('leading', READ_LEADINGS, prefs.readLeading)}</div>
  </div>`;
}

/** Ne redessine que la barre d'outils : le texte et les images ne bougent pas. */
function refreshReaderToolbar() {
  const a = state.activeId && getArticle(state.activeId);
  const bar = document.getElementById('readerToolbar');
  if (!a || !bar) return;
  bar.outerHTML = readerToolbarHtml(a);
  updateReadingProgress();
}

function nextPrevHtml(a) {
  const { prev, next } = neighbors(a.id);
  if (!prev && !next) return '';
  const card = (x, dir) => x ? `
    <button class="np-card ${dir}" data-act="open" data-id="${escHtml(x.id)}" data-from="${state.returnTo}">
      <span class="np-dir">${dir === 'next' ? 'Article suivant' : 'Article précédent'}</span>
      <span class="np-title">${escHtml(x.title)}</span>
      <span class="np-src">${escHtml(x.feedName)}</span>
    </button>` : '<span></span>';
  return `<nav class="reader-nextprev" aria-label="Autres articles">${card(prev, 'prev')}${card(next, 'next')}</nav>`;
}

function renderReader() {
  const r = state.reader;
  const a = r && getArticle(r.id);
  if (!a) return;
  const panel = document.getElementById('readerPanel');
  panel.dataset.readTheme = prefs.readTheme;

  let content;
  if (r.status === 'loading') {
    content = `
      <p class="reader-lead">${escHtml(a.excerpt)}</p>
      <div class="skeleton-block">
        ${[100, 92, 97, 84, 95, 88, 99, 73].map((w, i) => `<div class="skel-line" style="width:${w}%;animation-delay:${i * .06}s"></div>`).join('')}
      </div>`;
  } else if (r.status === 'ok') {
    content = `<div class="reader-full-content" ${r.lang ? `lang="${escHtml(r.lang)}"` : ''}>${r.html}</div>`;
  } else {
    content = `
      <p class="reader-lead">${escHtml(a.excerpt)}</p>
      <div class="reader-unavailable">
        <p><strong>Le texte complet n’a pas pu être récupéré.</strong><br>
        Le site bloque peut-être la lecture hors de ses pages, ou l’article est réservé aux abonnés.</p>
        <div class="reader-unavailable-actions">
          <a class="reader-btn primary" href="${escHtml(a.link)}" target="_blank" rel="noopener">${ICON.external} Lire sur ${escHtml(hostOf(a.link))}</a>
          <button class="reader-btn" data-act="retry-article">Réessayer</button>
        </div>
      </div>`;
  }

  // Image d'en-tête : celle du flux, sinon celle de partage de la page — sauf si le texte l'affiche déjà.
  const hero = a.image || r.image || '';
  const heroKey = hero.split('?')[0].split('/').pop();
  const showHero = hero && !(r.status === 'ok' && heroKey && r.html.includes(heroKey));
  const notFollowed = a.subscribed === false && a.feedUrl;
  const words = r.status === 'ok' ? r.words : 0;

  panel.innerHTML = `
    ${readerToolbarHtml(a)}
    <article class="reader-article cat-${cssClass(a.cat)}">
      ${notFollowed ? `
        <div class="reader-notice">
          <span>Vous ne suivez pas <strong>${escHtml(a.feedName)}</strong> : lecture en aperçu.</span>
          <button class="btn-inline" data-act="follow" data-url="${escHtml(a.feedUrl)}" data-name="${escHtml(a.feedName)}" data-cat="${escHtml(a.cat)}">Suivre cette source</button>
        </div>` : ''}
      <div class="reader-kicker">
        <span class="reader-cat-pill"><span class="cat-pill-dot"></span>${escHtml(a.cat)}</span>
        <span class="reader-source">${faviconHtml(a.feedUrl || a.link)}${escHtml(a.feedName)}</span>
      </div>
      <h1 class="reader-headline ${a.title.length > 90 ? 'long' : ''}">${escHtml(a.title)}</h1>
      <div class="reader-meta-row">
        ${a.author ? `<span class="reader-author">${escHtml(a.author)}</span><span class="reader-sep"></span>` : ''}
        <span class="reader-date">${escHtml(a.date || '')}</span>
        ${words ? `<span class="reader-sep"></span><span class="reader-time">${ICON.clock}${readMinutes(words)} min de lecture</span>` : ''}
      </div>
      ${showHero ? `<figure class="reader-hero"><img src="${escHtml(hero)}" alt="" decoding="async" onerror="this.parentNode.remove()"></figure>` : ''}
      <div class="reader-content">${content}</div>
      ${r.status === 'ok' ? `
      <div class="reader-end">
        <span class="reader-end-mark" aria-hidden="true">❧</span>
        <div class="reader-actions">
          <a class="reader-btn" href="${escHtml(a.link)}" target="_blank" rel="noopener">${ICON.external} Lire sur ${escHtml(hostOf(a.link))}</a>
          <button class="reader-btn ${isSaved(a.id) ? 'saved' : ''}" data-act="save" data-id="${escHtml(a.id)}">
            ${ICON.bookmark(isSaved(a.id))} ${isSaved(a.id) ? 'Sauvegardé' : 'Sauvegarder'}
          </button>
        </div>
      </div>` : ''}
      ${nextPrevHtml(a)}
    </article>`;

  enhanceReaderContent(a);
  panel.onscroll = updateReadingProgress;
  updateReadingProgress();
}

/** Barre de progression et temps restant. */
function updateReadingProgress() {
  const panel = document.getElementById('readerPanel');
  const fill = document.getElementById('rpFill');
  if (!panel || !fill) return;
  const t = panel.scrollHeight - panel.clientHeight;
  const p = t > 0 ? Math.min(1, panel.scrollTop / t) : 0;
  fill.style.width = (p * 100) + '%';
  const remain = document.getElementById('rtRemain');
  const words = state.reader?.status === 'ok' ? state.reader.words : 0;
  if (remain) {
    if (!words) remain.textContent = '';
    else if (p > 0.97) remain.textContent = 'Terminé';
    else {
      const m = Math.ceil(readMinutes(words) * (1 - p));
      remain.textContent = p < 0.02 ? `${readMinutes(words)} min` : `${m} min restante${m > 1 ? 's' : ''}`;
    }
  }
}

/** Retouches sur le HTML récupéré : titre en double, images, tableaux, liens. */
function enhanceReaderContent(a) {
  const root = document.querySelector('.reader-full-content');
  if (!root) return;

  // Titre répété en tête de l'article.
  const first = root.querySelector('h1, h2');
  if (first && norm(first.textContent) === norm(a.title)) first.remove();

  root.querySelectorAll('img').forEach(img => {
    const fig = img.closest('figure') || img;
    const done = () => {
      // Pixel espion ou icône passés entre les mailles : on retire.
      if (img.naturalWidth && img.naturalWidth < 48 && img.naturalHeight < 48) { fig.remove(); return; }
      if (img.naturalWidth && img.naturalWidth < 360) fig.classList?.add('is-small');
      img.classList.add('loaded');
    };
    if (img.complete && img.naturalWidth) done();
    else {
      img.addEventListener('load', done, { once: true });
      img.addEventListener('error', () => fig.remove(), { once: true });
    }
  });

  root.querySelectorAll('table').forEach(t => {
    if (t.parentElement?.classList.contains('table-scroll-wrap')) return;
    const w = document.createElement('div');
    w.className = 'table-scroll-wrap';
    t.parentNode.insertBefore(w, t);
    w.appendChild(t);
  });

  root.querySelectorAll('a[data-open-in-reader="1"]').forEach(link => {
    link.addEventListener('click', e => {
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      e.preventDefault();
      openExternalArticle(link.getAttribute('href'), link.getAttribute('data-article-title') || link.textContent.trim());
    });
  });
}

function openLightbox(src, caption) {
  const box = document.getElementById('lightbox');
  document.getElementById('lightboxImg').src = src;
  document.getElementById('lightboxCap').textContent = caption || '';
  box.hidden = false;
}
function closeLightbox() {
  const box = document.getElementById('lightbox');
  box.hidden = true;
  document.getElementById('lightboxImg').removeAttribute('src');
}

function enterReaderLayout() {
  const layout = document.querySelector('.reader-layout');
  document.getElementById('articlesPane').classList.add('pane-hidden');
  layout.classList.add('reader-mode');
  if (!layout.classList.contains('articles-visible')) layout.classList.add('articles-collapsed');
  document.getElementById('readerPanel').classList.add('open');
}

async function openArticle(id, from) {
  const a = getArticle(id);
  if (!a) return;

  if (from) state.returnTo = from;
  if (state.view !== 'reader') switchTab('reader');
  state.activeId = id;
  state.settingsOpen = false;
  state.keepVisible.add(id);
  if (!isRead(id)) markRead(id, true);

  state.reader = { id, status: 'loading' };
  enterReaderLayout();
  renderReader();
  renderSidebar();
  renderArticles();
  document.getElementById('readerPanel').scrollTop = 0;
  document.querySelector(`.articles-list [data-id="${CSS.escape(id)}"]`)?.scrollIntoView({ block: 'nearest' });

  const data = await fetchArticleContent(a.link);
  if (state.activeId !== id) return;
  state.reader = {
    id, status: data.html ? 'ok' : 'fail',
    html: data.html, image: data.image || '', lang: data.lang || '', words: wordCount(data.html),
  };
  renderReader();
  prefetchNext(id);
}

/** L'article suivant est chargé en tâche de fond : J l'affiche sans attente. */
function prefetchNext(id) {
  const { next } = neighbors(id);
  if (next?.link) setTimeout(() => fetchArticleContent(next.link), 600);
}

/** Ouvre une URL quelconque dans le lecteur (liens « à lire ensuite »). */
async function openExternalArticle(url, titleHint) {
  if (!url) return;
  const id = 'ext:' + url;
  INDEX.set(id, {
    id, feedId: -1, feedName: hostOf(url), cat: 'Autre',
    title: titleHint || hostOf(url), link: url,
    excerpt: '', image: '', date: 'Web', subscribed: undefined,
  });
  state.activeId = id;
  state.reader = { id, status: 'loading' };
  enterReaderLayout();
  renderReader();
  document.getElementById('readerPanel').scrollTop = 0;
  const data = await fetchArticleContent(url);
  if (state.activeId !== id) return;
  state.reader = { id, status: data.html ? 'ok' : 'fail', html: data.html, image: data.image || '', lang: data.lang || '', words: wordCount(data.html) };
  renderReader();
}

function closeReader() {
  const from = state.returnTo;
  state.activeId = null;
  state.reader = null;
  state.settingsOpen = false;
  document.getElementById('articlesPane').classList.remove('pane-hidden');
  document.querySelector('.reader-layout').classList.remove('reader-mode', 'articles-collapsed', 'articles-visible', 'focus-mode');
  const panel = document.getElementById('readerPanel');
  panel.classList.remove('open');
  panel.onscroll = null;
  panel.innerHTML = emptyReaderHtml();
  renderArticles();
  state.returnTo = 'reader';
  if (from === 'discover') switchTab('discover');
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

function toggleArticlesPane() {
  const layout = document.querySelector('.reader-layout');
  const collapsed = layout.classList.contains('articles-collapsed');
  layout.classList.toggle('articles-collapsed', !collapsed);
  layout.classList.toggle('articles-visible', collapsed);
  refreshReaderToolbar();
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
  const apply = save => {
    if (save) { savedSet.add(id); DB.saved = [{ ...a }, ...DB.saved.filter(s => s.id !== id)]; }
    else { savedSet.delete(id); DB.saved = DB.saved.filter(s => s.id !== id); }
    renderSidebar(); renderArticles(); renderDiscover();
    if (state.activeId === id) {
      refreshReaderToolbar();
      document.querySelectorAll('.reader-actions [data-act="save"]').forEach(b => {
        b.classList.toggle('saved', save);
        b.innerHTML = `${ICON.bookmark(save)} ${save ? 'Sauvegardé' : 'Sauvegarder'}`;
      });
    }
  };

  apply(willSave);
  try {
    const res = await apiPost('toggle_saved', { articleId: id, article: JSON.stringify(a) });
    if (res.error) throw new Error(res.error);
    toast(willSave ? 'Article sauvegardé' : 'Retiré des sauvegardés');
  } catch (e) {
    apply(!willSave);   // retour arrière si le serveur refuse
    toast('Sauvegarde impossible', 'error');
  }
}

async function markRead(id, read) {
  if (read) readSet.add(id); else readSet.delete(id);
  renderSidebar();
  try { await apiPost('set_read', { ids: JSON.stringify([id]), read: read ? '1' : '0' }); }
  catch (_) { /* l'état local reste, la prochaine action retentera */ }
}
function toggleRead(id) {
  markRead(id, !isRead(id));
  state.keepVisible.add(id);
  renderArticles();
}

/* ── 8. Découvrir ─────────────────────────────────────────────────────────
   Chercher des sources (catalogue + annuaire du web) et des articles,
   lire sans rien enregistrer, suivre d'un geste. */
function renderDiscoverControls() {
  const d = state.discover;
  document.getElementById('discoverCats').innerHTML = ['', ...CAT_LIST].map(c => `
    <button class="chip cat-${cssClass(c || 'Autre')} ${d.cat === c ? 'active' : ''}" data-act="discover-cat" data-cat="${escHtml(c)}">
      ${c ? '<span class="cat-dot"></span>' : ''}${c === '' ? 'Toutes catégories' : escHtml(c)}
    </button>`).join('');
  document.getElementById('discoverLangs').innerHTML = `
    <span class="discover-suggest-label">Langue :</span>
    <div class="seg seg-sm">${DISCOVER_LANGS.map(([v, label]) =>
      `<button class="${d.lang === v ? 'active' : ''}" data-act="discover-lang" data-v="${v}" aria-pressed="${d.lang === v}">${label}</button>`).join('')}</div>`;
}

function setDiscoverLang(lang) {
  const d = state.discover;
  d.lang = lang;
  prefs.discoverLang = lang;
  savePrefs();
  // Les sources du web sont reclassées pour la langue ; les articles relus sur les bons flux.
  if (d.ran) runDiscover(); else renderDiscover();
}

function sourceCardHtml(f) {
  // L'icône couvre le pictogramme RSS ; si elle ne charge pas, le pictogramme reste.
  const icon = `<span class="src-icon-box">${f.icon
    ? `<img class="favicon src-favicon full" src="${escHtml(f.icon)}" alt="" loading="lazy" onerror="this.classList.add('broken')">`
    : faviconHtml(f.site || f.url, 'src-favicon')}${ICON.rss}</span>`;
  const meta = f.source === 'web'
    ? [f.subscribers ? `${formatCount(f.subscribers)} lecteurs` : '', f.updated ? relativeDays(f.updated) : ''].filter(Boolean).join(' · ')
    : hostOf(f.site || f.url).replace(/^(feeds?|rss|syndication)\./, '');
  return `
  <div class="source-card cat-${cssClass(f.cat)}" data-act="preview" data-url="${escHtml(f.url)}" data-name="${escHtml(f.name)}" data-cat="${escHtml(f.cat)}"
       role="button" tabindex="0" title="Aperçu de ${escHtml(f.name)}">
    ${icon}
    <div class="source-card-info">
      <div class="source-card-name">
        <span class="source-card-title">${escHtml(f.name)}</span>
        ${f.lang ? `<span class="lang-tag">${escHtml(f.lang)}</span>` : ''}
        ${f.dormant ? `<span class="lang-tag warn" title="Ce flux ne publie presque plus">inactif</span>` : ''}
      </div>
      <div class="source-card-desc">${escHtml(f.desc || hostOf(f.site || f.url))}</div>
      <div class="source-card-foot">
        <span class="cat-pill"><span class="cat-pill-dot"></span>${escHtml(f.cat)}</span>
        <span class="source-card-meta" ${f.updated ? `title="Dernier article : ${escHtml(relativeDays(f.updated))}"` : ''}>${escHtml(meta)}</span>
        ${f.subscribed
          ? `<button class="btn-mini done" disabled>✓ Suivi</button>`
          : `<button class="btn-mini primary" data-act="follow" data-url="${escHtml(f.url)}" data-name="${escHtml(f.name)}" data-cat="${escHtml(f.cat)}">Suivre</button>`}
      </div>
    </div>
  </div>`;
}

function skeletonCards(n, cls = 'source-card') {
  return Array.from({ length: n }, () => `<div class="${cls} skeleton"><div class="skel-line" style="width:60%"></div><div class="skel-line" style="width:90%"></div></div>`).join('');
}

function renderDiscover() {
  renderDiscoverControls();
  const d = state.discover;
  const box = document.getElementById('discoverResults');
  // En recherche, l'accroche se fait discrète pour laisser la place aux résultats.
  document.getElementById('tabDiscover').classList.toggle('searching', !!(d.ran || d.preview || d.previewLoading || d.error));

  if (d.previewLoading) {
    box.innerHTML = `<div class="loading-state"><div class="loader"></div><p>${escHtml(d.previewMsg || 'Lecture du flux…')}</p></div>`;
    return;
  }
  if (d.error) {
    box.innerHTML = `<div class="discover-section"><div class="discover-error">${escHtml(d.error)}</div>
      <button class="btn-link" data-act="close-preview" style="margin-top:10px">${ICON.back} Retour</button></div>`;
    return;
  }
  if (d.preview) { box.innerHTML = previewHtml(d.preview); return; }
  if (!d.ran) { box.innerHTML = discoverLandingHtml(); return; }

  const sources = d.sources.filter(s => (!d.cat || s.cat === d.cat) && langMatches(s.lang, d.lang));
  const tabs = `
    <div class="discover-tabs" role="tablist">
      ${[['all', 'Tout'], ['sources', `Sources${d.sourcesLoading ? '' : ` <span class="tab-count">${sources.length}</span>`}`], ['articles', `Articles${d.articlesLoading ? '' : ` <span class="tab-count">${d.results.length}</span>`}`]]
        .map(([k, label]) => `<button role="tab" class="discover-tab ${d.tab === k ? 'active' : ''}" data-act="discover-tab" data-tab="${k}" aria-selected="${d.tab === k}">${label}</button>`).join('')}
    </div>`;

  let html = tabs;

  if (d.tab !== 'articles') {
    const shown = d.tab === 'all' ? sources.slice(0, 6) : sources;
    html += `<div class="discover-section">
      <div class="discover-section-head">
        <span class="discover-section-title">Sources</span>
        <span class="discover-section-sub">${d.sourcesLoading ? 'Recherche…' : `${sources.length} flux trouvé${sources.length > 1 ? 's' : ''}${d.webError ? ' · annuaire du web injoignable, catalogue seul' : ''}`}</span>
        <span class="spacer"></span>
        ${d.tab === 'all' && sources.length > shown.length ? `<button class="btn-mini" data-act="discover-tab" data-tab="sources">Voir les ${sources.length} →</button>` : ''}
      </div>
      ${d.sourcesLoading ? `<div class="source-grid">${skeletonCards(4)}</div>`
        : shown.length ? `<div class="source-grid">${shown.map(sourceCardHtml).join('')}</div>`
        : `<p class="discover-none">Aucun flux pour « ${escHtml(d.q)} »${d.cat ? ` dans ${escHtml(d.cat)}` : ''}${d.lang ? ` en ${escHtml(DISCOVER_LANGS.find(l => l[0] === d.lang)[1].toLowerCase())}` : ''}. Essayez un terme plus large, une autre langue, ou l’adresse du site.</p>`}
    </div>`;
  }

  if (d.tab !== 'sources') {
    const scope = `<div class="seg seg-sm">${[['catalog', 'Catalogue'], ['mine', 'Mes flux'], ['all', 'Les deux']]
      .map(([k, l]) => `<button class="${d.scope === k ? 'active' : ''}" data-act="discover-scope" data-scope="${k}">${l}</button>`).join('')}</div>`;
    html += `<div class="discover-section">
      <div class="discover-section-head">
        <span class="discover-section-title">Articles</span>
        <span class="discover-section-sub">${d.articlesLoading ? 'Lecture des flux en direct…'
          : d.articlesError ? escHtml(d.articlesError)
          : `${d.total} trouvé${d.total > 1 ? 's' : ''} dans ${d.sourcesCount} source${d.sourcesCount > 1 ? 's' : ''}`}</span>
        <span class="spacer"></span>${scope}
      </div>
      ${d.articlesLoading ? `<div class="result-list">${skeletonCards(3, 'article-card')}</div>`
        : d.results.length ? `<div class="result-list">${d.results.map(a => articleCardHtml(a, { follow: true, from: 'discover' })).join('')}</div>`
        : d.articlesError ? '' : `<p class="discover-none">Aucun article récent ne parle de « ${escHtml(d.q)} » dans ${d.scope === 'mine' ? 'vos flux' : 'ces sources'}.</p>`}
    </div>`;
  }
  box.innerHTML = html;
}

function discoverLandingHtml() {
  const d = state.discover;
  const sugg = `<div class="discover-suggest">
    <span class="discover-suggest-label">Idées :</span>
    ${DISCOVER_SUGGESTIONS.map(s => `<button class="chip" data-act="search-web" data-q="${escHtml(s)}">${escHtml(s)}</button>`).join('')}
  </div>`;
  if (!d.catalog) {
    return sugg + `<div class="discover-section"><div class="source-grid">${skeletonCards(6)}</div></div>`;
  }
  const list = d.catalog.filter(f => (!d.cat || f.cat === d.cat) && langMatches(f.lang, d.lang));
  return sugg + `
    <div class="discover-section">
      <div class="discover-section-head">
        <span class="discover-section-title">Catalogue</span>
        <span class="discover-section-sub">${list.length} source${list.length > 1 ? 's' : ''} choisie${list.length > 1 ? 's' : ''}${d.cat ? ` · ${escHtml(d.cat)}` : ''} — cliquez pour un aperçu</span>
      </div>
      <div class="source-grid">${list.map(sourceCardHtml).join('')}</div>
    </div>`;
}

function previewHtml(p) {
  const d = state.discover;
  return `
  <div class="discover-section">
    <button class="btn-link" data-act="close-preview">${ICON.back} ${d.ran ? 'Retour aux résultats' : 'Retour au catalogue'}</button>
    <div class="preview-head cat-${cssClass(p.feed.cat)}">
      <span class="src-icon-box big">${faviconHtml(p.feed.site || p.feed.url, 'src-favicon')}${ICON.rss}</span>
      <div class="preview-head-info">
        <h2 class="preview-title">${escHtml(p.feed.name)}</h2>
        ${p.feed.desc ? `<p class="preview-desc">${escHtml(p.feed.desc)}</p>` : ''}
        <p class="preview-url">${escHtml(p.feed.url)}</p>
      </div>
      <div class="preview-head-actions">
        ${p.subscribed
          ? `<button class="btn-mini done" disabled>✓ Suivi</button>`
          : `<button class="btn-mini primary big" data-act="follow" data-url="${escHtml(p.feed.url)}" data-name="${escHtml(p.feed.name)}" data-cat="${escHtml(p.feed.cat)}">+ Suivre cette source</button>`}
      </div>
    </div>
    ${p.alternatives?.length ? `
      <div class="preview-alts">
        <span class="discover-suggest-label">Autres flux de ce site :</span>
        ${p.alternatives.map(f => `<button class="chip ${f.url === p.feed.url ? 'active' : ''}" data-act="preview" data-url="${escHtml(f.url)}" data-name="${escHtml(f.title || '')}" data-cat="${escHtml(p.feed.cat)}">${escHtml(f.title || hostOf(f.url))}</button>`).join('')}
      </div>` : ''}
    <div class="discover-section-head" style="margin-top:1.2rem">
      <span class="discover-section-title">Derniers articles</span>
      <span class="discover-section-sub">${p.articles.length} · rien n’est enregistré tant que vous ne suivez pas</span>
    </div>
    <div class="result-list">${p.articles.map(a => articleCardHtml(a, { noUnread: !p.subscribed, from: 'discover' })).join('')
      || '<p class="discover-none">Ce flux ne contient aucun article pour le moment.</p>'}</div>
  </div>`;
}

async function loadCatalog() {
  const d = state.discover;
  if (d.catalog) return;
  try {
    const data = await apiGet({ action: 'catalog' });
    d.catalog = data.catalog || [];
  } catch (_) { d.catalog = []; }
  if (!d.ran && !d.preview) renderDiscover();
}

async function runDiscover() {
  const q = document.getElementById('discoverInput').value.trim();
  const d = state.discover;
  const seq = ++d.seq;
  d.q = q; d.error = ''; d.preview = null; d.previewLoading = false;
  if (!q) { d.ran = false; d.sources = []; d.results = []; renderDiscover(); return; }

  // Une URL collée : on cherche le flux du site tout seul.
  if (looksLikeUrl(q)) {
    d.ran = false;
    d.previewLoading = true; d.previewMsg = 'Recherche d’un flux sur ce site…';
    renderDiscover();
    try {
      const data = await apiGet({ action: 'discover_feed', url: q });
      if (seq !== d.seq) return;
      if (data.error) d.error = data.error;
      else {
        indexArticles(data.articles);
        d.preview = { feed: data.feed, subscribed: data.subscribed, articles: data.articles, alternatives: data.alternatives || [] };
      }
    } catch (e) {
      if (seq !== d.seq) return;
      d.error = 'Le site n’a pas répondu.';
    }
    d.previewLoading = false;
    renderDiscover();
    return;
  }

  d.ran = true;
  d.sourcesLoading = true; d.articlesLoading = true; d.articlesError = '';
  renderDiscover();

  // Les sources (rapides) s'affichent sans attendre les articles (lus en direct).
  apiGet({ action: 'search_feeds', q, limit: 8, lang: d.lang })
    .then(data => {
      if (seq !== d.seq) return;
      d.sources = data.results || [];
      d.webError = !!data.webError;
    })
    .catch(() => { if (seq === d.seq) { d.sources = []; d.webError = true; } })
    .finally(() => { if (seq === d.seq) { d.sourcesLoading = false; renderDiscover(); } });

  searchDiscoverArticles(seq);
}

async function searchDiscoverArticles(seq = state.discover.seq) {
  const d = state.discover;
  d.articlesLoading = true; d.articlesError = '';
  renderDiscover();
  try {
    const data = await apiGet({ action: 'search_articles', q: d.q, scope: d.scope, cat: d.cat, lang: d.lang, limit: 40 });
    if (seq !== d.seq) return;
    d.results = data.results || [];
    d.total = data.total || 0;
    d.sourcesCount = data.sources || 0;
    indexArticles(d.results);
  } catch (e) {
    if (seq !== d.seq) return;
    d.results = [];
    d.articlesError = 'La recherche d’articles a échoué.';
  }
  d.articlesLoading = false;
  renderDiscover();
}

async function previewSource(url, name, cat) {
  const d = state.discover;
  const seq = ++d.seq;
  const keepAlts = d.preview?.alternatives?.some(f => f.url === url) ? d.preview.alternatives : [];
  d.previewLoading = true; d.previewMsg = `Lecture de ${name || hostOf(url)}…`; d.error = '';
  renderDiscover();
  document.getElementById('tabDiscover').scrollTop = 0;
  try {
    const data = await apiGet({ action: 'preview_feed', url, name, cat });
    if (seq !== d.seq) return;
    if (data.error) d.error = data.error;
    else {
      indexArticles(data.articles);
      d.preview = { feed: data.feed, subscribed: data.subscribed, articles: data.articles, alternatives: keepAlts };
    }
  } catch (e) {
    if (seq !== d.seq) return;
    d.error = 'Ce flux n’a pas répondu.';
  }
  d.previewLoading = false;
  renderDiscover();
}

function closePreview() {
  const d = state.discover;
  d.seq++;
  d.preview = null; d.error = ''; d.previewLoading = false;
  if (looksLikeUrl(d.q)) { d.q = ''; document.getElementById('discoverInput').value = ''; d.ran = false; }
  renderDiscover();
}

async function followSource(url, name, cat, { quiet = false } = {}) {
  try {
    const data = await apiPost('add_feed', { url, name: name || '', cat: cat || 'Autre' });
    if (data.error) { if (!quiet) toast(data.error, 'error'); return false; }
    DB.feeds.push(data.feed);
    ARTICLES[data.feed.id] = data.articles || [];
    indexArticles(ARTICLES[data.feed.id]);

    // Ce qui est affiché ailleurs doit refléter le nouvel abonnement.
    const d = state.discover;
    [d.results, d.preview?.articles].forEach(list => {
      (list || []).forEach(a => { if (a.feedUrl === url) a.subscribed = true; });
    });
    [d.sources, d.catalog || [], d.preview?.alternatives || []].forEach(list => {
      list.forEach(s => { if (s.url === url) s.subscribed = true; });
    });
    if (d.preview?.feed.url === url) d.preview.subscribed = true;
    INDEX.forEach(a => { if (a.feedUrl === url) a.subscribed = true; });

    if (!quiet) toast(`Vous suivez ${data.feed.name}`, 'ok');
    renderSidebar(); renderArticles(); renderDiscover(); renderManage();
    if (state.reader && state.activeId) {
      const a = getArticle(state.activeId);
      if (a && a.feedUrl === url) renderReader();
    }
    return true;
  } catch (e) {
    if (!quiet) toast('Abonnement impossible', 'error');
    return false;
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
      <span class="src-icon-box cat-${cssClass(f.cat)}">${faviconHtml(f.url, 'src-favicon')}${ICON.rss}</span>
      <div class="feed-row-info">
        <div class="feed-row-name">${escHtml(f.name)}</div>
        <div class="feed-row-url">${escHtml(f.url)}</div>
        <div class="feed-row-tags">
          <select class="feed-cat-select" data-act="change-cat" data-id="${f.id}" aria-label="Catégorie">
            ${CAT_LIST.map(c => `<option ${c === f.cat ? 'selected' : ''}>${escHtml(c)}</option>`).join('')}
          </select>
          <span class="feed-count-tag">${n} article${n !== 1 ? 's' : ''}</span>
          <button class="btn-link small" data-act="filter" data-filter="feed:${f.id}">Lire →</button>
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
  const d = state.discover;
  [d.sources, d.catalog || []].forEach(list => list.forEach(s => { if (s.url === f.url) s.subscribed = false; }));
  if (state.feedFilter === 'feed:' + id) state.feedFilter = 'all';
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
  const ok = await followSource(url, name, cat);
  btn.disabled = false; btn.textContent = 'Ajouter';
  if (!ok) return;
  closeAddModal();
  document.getElementById('modalName').value = '';
  document.getElementById('modalUrl').value = '';
}

/* OPML : le format d'échange de tous les lecteurs RSS. */
function exportOpml() {
  if (!DB.feeds.length) { toast('Aucune source à exporter'); return; }
  const outlines = DB.feeds.map(f =>
    `    <outline type="rss" text="${escHtml(f.name)}" title="${escHtml(f.name)}" xmlUrl="${escHtml(f.url)}" category="${escHtml(f.cat)}"/>`).join('\n');
  const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<opml version="2.0">\n  <head><title>Sources RSSphere</title><dateCreated>${new Date().toUTCString()}</dateCreated></head>\n  <body>\n${outlines}\n  </body>\n</opml>\n`;
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([xml], { type: 'text/x-opml' }));
  a.download = 'rssphere.opml';
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

async function importOpml(file) {
  const doc = new DOMParser().parseFromString(await file.text(), 'text/xml');
  const known = new Set(DB.feeds.map(f => f.url));
  const todo = [...doc.querySelectorAll('outline[xmlUrl]')].map(o => {
    // Catégorie : attribut, sinon dossier parent si son nom correspond à une des nôtres.
    const parentName = o.parentElement?.getAttribute?.('text') || '';
    const guess = [o.getAttribute('category'), parentName].map(c => CAT_LIST.find(x => norm(x) === norm(c))).find(Boolean);
    return { url: o.getAttribute('xmlUrl'), name: o.getAttribute('title') || o.getAttribute('text') || '', cat: guess || 'Autre' };
  }).filter(f => f.url && !known.has(f.url));

  if (!todo.length) { toast('Aucune nouvelle source dans ce fichier'); return; }
  let ok = 0;
  for (const [i, f] of todo.entries()) {
    toast(`Import ${i + 1}/${todo.length} : ${f.name || hostOf(f.url)}…`);
    if (await followSource(f.url, f.name, f.cat, { quiet: true })) ok++;
  }
  toast(`${ok} source${ok > 1 ? 's' : ''} importée${ok > 1 ? 's' : ''}${ok < todo.length ? ` · ${todo.length - ok} illisible${todo.length - ok > 1 ? 's' : ''}` : ''}`, ok ? 'ok' : 'error');
}

/* ── Onglets ───────────────────────────────────────────────────────────── */
function switchTab(tab) {
  state.view = tab;
  closeSidebarDrawer();
  document.querySelectorAll('.nav-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.getElementById('tabReader').style.display = tab === 'reader' ? 'grid' : 'none';
  document.getElementById('tabDiscover').style.display = tab === 'discover' ? 'block' : 'none';
  document.getElementById('tabManage').style.display = tab === 'manage' ? 'block' : 'none';
  if (tab === 'manage') renderManage();
  if (tab === 'discover') {
    loadCatalog();
    renderDiscover();
    if (window.matchMedia('(pointer: fine)').matches) document.getElementById('discoverInput').focus();
  }
}

function gotoDiscover(q) {
  if (state.activeId) closeReader();
  switchTab('discover');
  if (q) {
    document.getElementById('discoverInput').value = q;
    state.discover.tab = 'all';
    runDiscover();
  }
}

/* ── 10. Événements & clavier ──────────────────────────────────────────── */
function bindEvents() {
  document.addEventListener('click', e => {
    // Image de l'article : agrandissement.
    const img = e.target.closest('.reader-full-content img');
    if (img && !img.closest('a')) {
      openLightbox(img.currentSrc || img.src, img.closest('figure')?.querySelector('figcaption')?.textContent || img.alt);
      return;
    }

    // Fermer le panneau de réglages en cliquant ailleurs.
    if (state.settingsOpen && !e.target.closest('.rt-pop-wrap')) {
      state.settingsOpen = false;
      refreshReaderToolbar();
    }

    const el = e.target.closest('[data-act]');
    if (!el) return;
    const { act, id, url, name, cat, filter, q, v, d, from } = el.dataset;

    switch (act) {
      case 'open':            openArticle(id, from); break;
      case 'save':            e.stopPropagation(); toggleSaved(id); break;
      case 'read':            e.stopPropagation(); toggleRead(id); break;
      case 'follow':          e.stopPropagation(); followSource(url, name, cat); break;
      case 'preview':         previewSource(url, name, cat); break;
      case 'close-preview':   closePreview(); break;
      case 'filter':          setFeedFilter(filter); break;
      case 'goto-discover':   e.preventDefault(); gotoDiscover(); break;
      case 'search-web':      gotoDiscover(q); break;
      case 'close-reader':    closeReader(); break;
      case 'toggle-list':     toggleArticlesPane(); break;
      case 'toggle-sidebar':  toggleSidebarDrawer(); break;
      case 'focus-mode':      toggleFocusMode(); break;
      case 'step':            stepArticle(parseInt(d)); break;
      case 'retry-article':   { const a = getArticle(state.activeId); if (a) { contentCache.delete(a.link); openArticle(a.id); } break; }
      case 'list-view':       setListView(v); break;
      case 'mark-all-read':   markAllRead(); break;
      case 'delete-feed':     deleteFeed(parseInt(id)); break;
      case 'import-opml':     document.getElementById('opmlInput').click(); break;
      case 'export-opml':     exportOpml(); break;
      case 'close-lightbox':  closeLightbox(); break;
      case 'discover-cat':
        state.discover.cat = el.dataset.cat;
        if (state.discover.ran) searchDiscoverArticles(); else renderDiscover();
        break;
      case 'discover-tab':    state.discover.tab = el.dataset.tab; renderDiscover(); break;
      case 'discover-lang':   setDiscoverLang(v); break;
      case 'discover-scope':  state.discover.scope = el.dataset.scope; searchDiscoverArticles(); break;
      case 'toggle-settings': e.stopPropagation(); state.settingsOpen = !state.settingsOpen; refreshReaderToolbar(); break;
      case 'read-theme':      prefs.readTheme = v; savePrefs(); refreshReaderToolbar(); break;
      case 'font-size':       setReadSize(prefs.readSize + parseInt(d)); break;
      case 'font-family':     prefs.readFont = v; savePrefs(); refreshReaderToolbar(); break;
      case 'measure':         prefs.readMeasure = parseInt(v); savePrefs(); refreshReaderToolbar(); break;
      case 'leading':         prefs.readLeading = parseFloat(v); savePrefs(); refreshReaderToolbar(); break;
    }
  });

  document.addEventListener('input', e => {
    if (e.target.matches('[data-act="font-size-range"]')) {
      prefs.readSize = parseInt(e.target.value);
      savePrefs();
      const val = document.querySelector('.rt-size-val');
      if (val) val.textContent = prefs.readSize;
    }
  });

  document.addEventListener('change', e => {
    const el = e.target.closest('[data-act="change-cat"]');
    if (el) changeCat(parseInt(el.dataset.id), el.value);
    if (e.target.id === 'opmlInput' && e.target.files[0]) {
      importOpml(e.target.files[0]);
      e.target.value = '';
    }
  });

  document.getElementById('discoverInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') { state.discover.tab = 'all'; runDiscover(); }
  });

  document.addEventListener('keydown', e => {
    const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) && e.target.type !== 'range';

    if (e.key === 'Escape') {
      if (!document.getElementById('lightbox').hidden) return closeLightbox();
      if (document.getElementById('addModal').style.display === 'flex') return closeAddModal();
      if (state.settingsOpen) { state.settingsOpen = false; return refreshReaderToolbar(); }
      if (typing) return e.target.blur();
      if (document.getElementById('app').classList.contains('sidebar-open')) return closeSidebarDrawer();
      if (state.activeId) return closeReader();
      if (state.view === 'discover' && state.discover.preview) return closePreview();
    }
    if (typing || e.metaKey || e.ctrlKey || e.altKey) return;

    // Entrée / espace sur une carte focalisée = l'ouvrir.
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('[role="button"][data-act]')) {
      e.preventDefault();
      e.target.click();
      return;
    }

    if (e.key === '/') {
      e.preventDefault();
      (state.view === 'discover' ? document.getElementById('discoverInput') : document.getElementById('articlesSearchInput')).focus();
      return;
    }
    if (state.view !== 'reader') return;

    const k = e.key.toLowerCase();
    if (k === 'j' || k === 'k') { e.preventDefault(); stepArticle(k === 'j' ? 1 : -1); }
    if (k === 'r') refreshAllFeeds();
    if (!state.activeId) return;
    if (k === 's') toggleSaved(state.activeId);
    if (k === 'f') toggleFocusMode();
    if (k === 'm') { toggleRead(state.activeId); }
    if (k === 'o') { const a = getArticle(state.activeId); if (a) window.open(a.link, '_blank', 'noopener'); }
    if (e.key === '+' || e.key === '=') setReadSize(prefs.readSize + 1);
    if (e.key === '-') setReadSize(prefs.readSize - 1);
  });
}

function setReadSize(n) {
  prefs.readSize = Math.min(26, Math.max(14, n));
  savePrefs();
  refreshReaderToolbar();
}

function stepArticle(dir) {
  const arts = navList();
  if (!arts.length) return;
  const i = arts.findIndex(a => a.id === state.activeId);
  const next = arts[Math.min(arts.length - 1, Math.max(0, (i < 0 ? -1 : i) + dir))];
  if (next && next.id !== state.activeId) openArticle(next.id);
}

/* ── 11. Démarrage ─────────────────────────────────────────────────────── */
Object.assign(window, {
  switchTab, refreshAllFeeds, setReadFilter, setArticleSearch, clearArticleSearch,
  openAddModal, closeAddModal, addManual, runDiscover, toggleTheme, gotoDiscover,
});

init();
