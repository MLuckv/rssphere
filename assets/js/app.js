/* RSSphere — app.js */

const API = 'api/index.php';

const CAT_COLORS = {
  "Tech": "#2B5CE6", "Actualités": "#F59E0B", "Science": "#10B981",
  "Culture": "#7C3AED", "Sport": "#22C55E", "Économie": "#EA580C",
  "Gaming": "#DB2777", "Autre": "#9CA3AF"
};

let DB = { feeds: [], saved: [] };
let ARTICLES = {};    // feedId -> articles[]
let state = {
  feedFilter: 'all',  // 'all' | 'saved' | 'cat:X' | 'feed:N'
  readFilter: 'all',  // 'all' | 'unread'
  activeArticle: null,
  readArticles: new Set(),
  loading: new Set(),
};

/* ── Init ─────────────────────────────────────────────────────────────── */
async function init() {
  const res = await fetch(`${API}?action=get_db`);
  DB = await res.json();
  if (!DB.feeds) DB.feeds = [];
  if (!DB.saved) DB.saved = [];
  state.savedSet = new Set(DB.saved);
  renderSidebar();
  await refreshAllFeeds();
}

/* ── Fetch feeds ─────────────────────────────────────────────────────── */
async function refreshAllFeeds() {
  const btn = document.getElementById('refreshBtn');
  btn.classList.add('spinning');
  document.getElementById('paneSub').textContent = 'Actualisation…';

  const promises = DB.feeds.map(f => fetchFeed(f));
  await Promise.allSettled(promises);

  btn.classList.remove('spinning');
  renderSidebar();
  renderArticles();
  toast('Flux actualisés', '#10B981');
}

async function fetchFeed(feed) {
  try {
    const params = new URLSearchParams({
      action: 'fetch_feed',
      url: feed.url,
      feedId: feed.id,
      feedName: feed.name,
      cat: feed.cat
    });
    const res = await fetch(`${API}?${params}`);
    const data = await res.json();
    if (data.articles) {
      // Préserver l'état lu/sauvegardé
      ARTICLES[feed.id] = data.articles.map(a => ({
        ...a,
        unread: !state.readArticles.has(a.id),
        saved: state.savedSet ? state.savedSet.has(a.id) : false
      }));
    }
  } catch(e) {
    console.warn('Erreur flux', feed.name, e);
  }
}

/* ── Sidebar ─────────────────────────────────────────────────────────── */
function renderSidebar() {
  const allArts = getAllArticles();
  const totalUnread = allArts.filter(a => a.unread).length;
  const totalSaved = allArts.filter(a => a.saved).length;

  const badgeAll = document.getElementById('badge-all');
  badgeAll.textContent = totalUnread;
  badgeAll.className = 'item-badge' + (totalUnread > 0 ? ' unread' : '');

  document.getElementById('badge-saved').textContent = totalSaved;

  // Catégories
  const cats = {};
  DB.feeds.forEach(f => {
    const arts = ARTICLES[f.id] || [];
    if (!cats[f.cat]) cats[f.cat] = { total: 0, unread: 0 };
    cats[f.cat].total += arts.length;
    cats[f.cat].unread += arts.filter(a => a.unread).length;
  });

  document.getElementById('cats-list').innerHTML = Object.entries(cats).map(([cat, d]) => `
    <button class="sidebar-item ${state.feedFilter === 'cat:' + cat ? 'active' : ''}"
      onclick="setFeedFilter('cat:${cat}', this)">
      <div class="cat-dot" style="background:${CAT_COLORS[cat] || '#9CA3AF'}"></div>
      ${cat}
      <span class="item-badge ${d.unread > 0 ? 'unread' : ''}">${d.unread > 0 ? d.unread : d.total}</span>
    </button>`).join('');

  // Flux individuels
  document.getElementById('feeds-sidebar-list').innerHTML = DB.feeds.map(f => {
    const arts = ARTICLES[f.id] || [];
    const unread = arts.filter(a => a.unread).length;
    const loading = state.loading.has(f.id);
    return `<button class="sidebar-item ${state.feedFilter === 'feed:' + f.id ? 'active' : ''}"
      onclick="setFeedFilter('feed:${f.id}', this)">
      <div class="cat-dot" style="background:${CAT_COLORS[f.cat] || '#9CA3AF'}"></div>
      <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${f.name}</span>
      ${loading ? '<div class="loader" style="width:12px;height:12px;border-width:1.5px"></div>' :
        unread > 0 ? `<span class="item-badge unread">${unread}</span>` : ''}
    </button>`;
  }).join('');
}

/* ── Articles ─────────────────────────────────────────────────────────── */
function getAllArticles() {
  return DB.feeds.flatMap(f => ARTICLES[f.id] || [])
    .sort((a, b) => b.timestamp - a.timestamp);
}

function getFilteredArticles() {
  let arts = [];

  if (state.feedFilter === 'all') {
    arts = getAllArticles();
  } else if (state.feedFilter === 'saved') {
    arts = getAllArticles().filter(a => a.saved);
  } else if (state.feedFilter.startsWith('cat:')) {
    const cat = state.feedFilter.slice(4);
    arts = getAllArticles().filter(a => a.cat === cat);
  } else if (state.feedFilter.startsWith('feed:')) {
    const id = parseInt(state.feedFilter.slice(5));
    arts = (ARTICLES[id] || []).sort((a, b) => b.timestamp - a.timestamp);
  }

  if (state.readFilter === 'unread') arts = arts.filter(a => a.unread);
  return arts;
}

function renderArticles() {
  const arts = getFilteredArticles();
  const list = document.getElementById('articlesList');
  const allArts = getAllArticles();

  // Update title
  let title = 'Tous les articles';
  if (state.feedFilter === 'saved') title = 'Sauvegardés';
  else if (state.feedFilter.startsWith('cat:')) title = state.feedFilter.slice(4);
  else if (state.feedFilter.startsWith('feed:')) {
    const f = DB.feeds.find(x => x.id === parseInt(state.feedFilter.slice(5)));
    if (f) title = f.name;
  }
  document.getElementById('paneTitle').textContent = title;
  document.getElementById('paneSub').textContent = `${arts.length} article${arts.length !== 1 ? 's' : ''}`;

  if (arts.length === 0) {
    const noFeed = DB.feeds.length === 0;
    list.innerHTML = `<div class="empty-state">
      <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5">
        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
      </svg>
      <p>${noFeed ? 'Ajoutez des flux dans l\'onglet "Gérer"' : 'Aucun article dans cette vue'}</p>
    </div>`;
    return;
  }

  list.innerHTML = arts.map(a => {
    const isActive = state.activeArticle === a.id;
    return `<div class="article-card cat-${cssClass(a.cat)} ${a.unread ? 'unread' : ''} ${isActive ? 'active' : ''}"
      onclick="openArticle('${a.id}', '${a.feedId}')">
      <div class="article-card-top">
        <span class="cat-pill"><span class="cat-pill-dot"></span>${a.cat}</span>
        <span class="art-source">${escHtml(a.feedName)}</span>
        <span class="art-date">${a.date}</span>
      </div>
      <div class="article-card-body">
        <div class="article-card-text">
          <div class="art-title">${escHtml(a.title)}</div>
          <div class="art-excerpt">${escHtml(a.excerpt)}</div>
        </div>
        ${a.image ? `<img class="art-thumb" src="${escHtml(a.image)}" alt="" loading="lazy" onerror="this.style.display='none'">` : ''}
      </div>
      <div class="article-card-footer">
        <button class="art-action ${a.saved ? 'saved' : ''}" onclick="toggleSaved(event,'${a.id}','${a.feedId}')">
          <svg viewBox="0 0 24 24" width="12" height="12" fill="${a.saved ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2">
            <path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/>
          </svg>
          ${a.saved ? 'Sauvegardé' : 'Sauvegarder'}
        </button>
        <button class="art-action" onclick="toggleRead(event,'${a.id}','${a.feedId}')">
          <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
          </svg>
          ${a.unread ? 'Marquer lu' : 'Non lu'}
        </button>
        ${a.unread ? '<div class="art-unread-dot"></div>' : ''}
      </div>
    </div>`;
  }).join('');
}

/* ── Open article (reader) ─────────────────────────────────────────────── */
function openArticle(articleId, feedId) {
  feedId = parseInt(feedId);
  const arts = ARTICLES[feedId] || [];
  const a = arts.find(x => x.id === articleId);
  if (!a) return;

  // Mark as read
  a.unread = false;
  state.readArticles.add(articleId);
  state.activeArticle = articleId;

  const panel = document.getElementById('readerPanel');
  panel.innerHTML = `<div class="reader-panel-inner">
    <button class="reader-back" onclick="closeReader()">
      <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      Retour
    </button>
    <div class="cat-${cssClass(a.cat)}">
      <span class="reader-cat-pill"><span class="cat-pill-dot"></span>${a.cat}</span>
    </div>
    <h1 class="reader-headline">${escHtml(a.title)}</h1>
    <div class="reader-meta-row">
      <span class="reader-source">${escHtml(a.feedName)}</span>
      <span class="reader-sep"></span>
      <span class="reader-date">${a.date}</span>
    </div>
    ${a.image ? `<img class="reader-image" src="${escHtml(a.image)}" alt="" onerror="this.style.display='none'">` : ''}
    <div class="reader-body">
      <p>${escHtml(a.excerpt)}</p>
      <p style="color:var(--text-3);font-size:13px;font-style:italic">Cet extrait provient du flux RSS. Cliquez sur le lien ci-dessous pour lire l'article complet.</p>
    </div>
    <div class="reader-actions">
      <a class="reader-btn primary" href="${escHtml(a.link)}" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5">
          <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
          <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
        </svg>
        Lire l'article complet
      </a>
      <button class="reader-btn ${a.saved ? 'saved' : ''}" id="reader-save-btn" onclick="toggleSaved(event,'${articleId}','${feedId}')">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="${a.saved ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2">
          <path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/>
        </svg>
        ${a.saved ? 'Sauvegardé' : 'Sauvegarder'}
      </button>
    </div>
  </div>`;

  renderSidebar();
  renderArticles();
}

function closeReader() {
  state.activeArticle = null;
  document.getElementById('readerPanel').innerHTML = `
    <div class="reader-panel-inner">
      <div class="reader-empty">
        <div class="reader-empty-icon">
          <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/>
            <path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/>
          </svg>
        </div>
        <p>Sélectionnez un article à lire</p>
      </div>
    </div>`;
  renderArticles();
}

/* ── Actions ─────────────────────────────────────────────────────────────── */
function setFeedFilter(filter, el) {
  state.feedFilter = filter;
  document.querySelectorAll('.sidebar-item').forEach(b => b.classList.remove('active'));
  if (el) el.classList.add('active');
  closeReader();
  renderArticles();
}

function setReadFilter(f) {
  state.readFilter = f;
  document.getElementById('pill-all').className = 'filter-pill' + (f === 'all' ? ' active' : '');
  document.getElementById('pill-unread').className = 'filter-pill' + (f === 'unread' ? ' active' : '');
  renderArticles();
}

async function toggleSaved(event, articleId, feedId) {
  event.stopPropagation();
  feedId = parseInt(feedId);
  const arts = ARTICLES[feedId] || [];
  const a = arts.find(x => x.id === articleId);
  if (!a) return;
  a.saved = !a.saved;
  if (a.saved) state.savedSet.add(articleId); else state.savedSet.delete(articleId);

  // Persist
  const form = new FormData();
  form.append('articleId', articleId);
  await fetch(`${API}?action=toggle_saved`, { method: 'POST', body: form });

  renderSidebar();
  renderArticles();
  if (state.activeArticle === articleId) openArticle(articleId, feedId);
  toast(a.saved ? 'Article sauvegardé' : 'Supprimé des sauvegardés');
}

function toggleRead(event, articleId, feedId) {
  event.stopPropagation();
  feedId = parseInt(feedId);
  const arts = ARTICLES[feedId] || [];
  const a = arts.find(x => x.id === articleId);
  if (!a) return;
  a.unread = !a.unread;
  if (!a.unread) state.readArticles.add(articleId); else state.readArticles.delete(articleId);
  renderSidebar();
  renderArticles();
}

/* ── Tab switching ──────────────────────────────────────────────────────── */
function switchTab(tab) {
  document.querySelectorAll('.nav-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.getElementById('tabReader').style.display = tab === 'reader' ? 'flex' : 'none';
  document.getElementById('tabManage').style.display = tab === 'manage' ? 'block' : 'none';
  if (tab === 'manage') renderManage();
}

/* ── Manage ──────────────────────────────────────────────────────────────── */
function renderManage() {
  document.getElementById('feedsCountLabel').textContent = `${DB.feeds.length} flux actif${DB.feeds.length !== 1 ? 's' : ''}`;

  const body = document.getElementById('feedsList');
  if (DB.feeds.length === 0) {
    body.innerHTML = `<div style="padding:2rem;text-align:center;color:var(--text-3);font-size:13px">
      Aucun flux — recherchez ou ajoutez manuellement ci-dessus
    </div>`;
    return;
  }
  body.innerHTML = `<div class="feeds-list-body">${DB.feeds.map(f => {
    const arts = ARTICLES[f.id] || [];
    const col = CAT_COLORS[f.cat] || '#9CA3AF';
    return `<div class="feed-row">
      <div class="feed-icon-box cat-${cssClass(f.cat)}">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5">
          <circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/>
          <path d="M4 11a8 8 0 018 8" stroke-linecap="round"/>
          <path d="M4 5a14 14 0 0114 14" stroke-linecap="round"/>
        </svg>
      </div>
      <div class="feed-row-info">
        <div class="feed-row-name">${escHtml(f.name)}</div>
        <div class="feed-row-url">${escHtml(f.url)}</div>
        <div class="feed-row-tags">
          <span class="cat-pill cat-${cssClass(f.cat)}" style="font-size:10px;padding:1px 7px">
            <span class="cat-pill-dot"></span>${f.cat}
          </span>
          <span class="feed-count-tag">${arts.length} articles</span>
        </div>
      </div>
      <button class="btn-delete" onclick="deleteFeed(${f.id})">Retirer</button>
    </div>`;
  }).join('')}</div>`;
}

async function deleteFeed(id) {
  if (!confirm('Retirer ce flux ?')) return;
  const form = new FormData();
  form.append('id', id);
  await fetch(`${API}?action=delete_feed`, { method: 'POST', body: form });
  DB.feeds = DB.feeds.filter(f => f.id !== id);
  delete ARTICLES[id];
  renderSidebar();
  renderArticles();
  renderManage();
  toast('Flux retiré');
}

/* ── Search ──────────────────────────────────────────────────────────────── */
async function doSearch() {
  const q = document.getElementById('searchInput').value.trim();
  if (!q) return;
  const container = document.getElementById('searchResults');
  container.innerHTML = `<div class="loading-state" style="padding:1rem"><div class="loader"></div><p>Recherche en cours…</p></div>`;
  const res = await fetch(`${API}?action=search_feeds&q=${encodeURIComponent(q)}`);
  const data = await res.json();
  const existingUrls = new Set(DB.feeds.map(f => f.url));

  if (!data.results || data.results.length === 0) {
    container.innerHTML = `<div style="padding:1rem 1.25rem;font-size:13px;color:var(--text-3)">
      Aucun résultat pour "<strong>${escHtml(q)}</strong>". Essayez un autre terme ou ajoutez manuellement.
    </div>`;
    return;
  }

  container.innerHTML = `<div class="search-results-list">${data.results.map(f => {
    const added = existingUrls.has(f.url);
    return `<div class="search-result-item cat-${cssClass(f.cat)}">
      <div class="feed-icon-box">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5">
          <circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/>
          <path d="M4 11a8 8 0 018 8" stroke-linecap="round"/>
          <path d="M4 5a14 14 0 0114 14" stroke-linecap="round"/>
        </svg>
      </div>
      <div class="search-res-info">
        <div class="search-res-name">
          ${escHtml(f.name)}
          <span class="cat-pill cat-${cssClass(f.cat)}" style="font-size:10px;padding:1px 7px">
            <span class="cat-pill-dot"></span>${f.cat}
          </span>
        </div>
        <div class="search-res-desc">${escHtml(f.desc)} — ${escHtml(f.url)}</div>
      </div>
      <button class="btn-add-feed ${added ? 'added' : ''}" 
        id="addbtn-${btoa(f.url).replace(/[^a-zA-Z0-9]/g,'')}"
        onclick="addFeedFromSearch(${JSON.stringify(f).replace(/"/g,'&quot;')})" 
        ${added ? 'disabled' : ''}>
        ${added ? '✓ Ajouté' : '+ Ajouter'}
      </button>
    </div>`;
  }).join('')}</div>`;
}

async function addFeedFromSearch(feedData) {
  const form = new FormData();
  form.append('name', feedData.name);
  form.append('url', feedData.url);
  form.append('cat', feedData.cat);
  const res = await fetch(`${API}?action=add_feed`, { method: 'POST', body: form });
  const data = await res.json();
  if (data.error) { toast(data.error, '#DC2626'); return; }
  DB.feeds.push(data.feed);
  // Fetch articles for new feed
  await fetchFeed(data.feed);
  renderSidebar();
  renderManage();
  toast(`"${feedData.name}" ajouté !`, '#10B981');
  // Update button
  const btnId = 'addbtn-' + btoa(feedData.url).replace(/[^a-zA-Z0-9]/g,'');
  const btn = document.getElementById(btnId);
  if (btn) { btn.textContent = '✓ Ajouté'; btn.classList.add('added'); btn.disabled = true; }
}

/* ── Manual add ──────────────────────────────────────────────────────────── */
function openAddModal() { document.getElementById('addModal').style.display = 'flex'; }
function closeAddModal() { document.getElementById('addModal').style.display = 'none'; }

async function addManual() {
  const name = document.getElementById('modalName').value.trim();
  const url = document.getElementById('modalUrl').value.trim();
  const cat = document.getElementById('modalCat').value;
  if (!name || !url) { toast('Remplissez tous les champs', '#DC2626'); return; }
  const form = new FormData();
  form.append('name', name); form.append('url', url); form.append('cat', cat);
  const res = await fetch(`${API}?action=add_feed`, { method: 'POST', body: form });
  const data = await res.json();
  if (data.error) { toast(data.error, '#DC2626'); return; }
  DB.feeds.push(data.feed);
  await fetchFeed(data.feed);
  closeAddModal();
  renderSidebar(); renderManage();
  toast(`"${name}" ajouté !`, '#10B981');
  document.getElementById('modalName').value = '';
  document.getElementById('modalUrl').value = '';
}

/* ── Helpers ─────────────────────────────────────────────────────────────── */
function cssClass(cat) {
  return (cat || 'Autre').replace(/[éèê]/g,'e').replace(/[àâ]/g,'a').replace(/[ô]/g,'o').replace(/[^a-zA-Z0-9]/g,'');
}

function escHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

let toastTimer;
function toast(msg, color) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.style.background = color || 'var(--text)';
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 2500);
}

/* ── Start ───────────────────────────────────────────────────────────────── */
init();
