/* RSSphere — app.js v3 */

const API = 'api/index.php';

const CAT_COLORS = {
  "Tech":"#2B5CE6","Actualités":"#F59E0B","Science":"#10B981",
  "Culture":"#7C3AED","Sport":"#22C55E","Économie":"#EA580C",
  "Gaming":"#DB2777","Autre":"#9CA3AF"
};

let DB = { feeds:[], saved:[] };
let ARTICLES = {};
let state = {
  feedFilter:'all', readFilter:'all',
  activeArticle:null, readArticles: new Set(), loading: new Set(),
  savedSet: new Set(),
};

// ── Keyword extraction ────────────────────────────────────────────────────
const STOPWORDS = new Set([
  'alors','après','aussi','avant','avec','bien','chez','comme','dans','depuis',
  'donc','dont','entre','être','faire','fois','leur','leurs','lors','mais','même',
  'moins','nous','notre','pour','puis','quand','sans','selon','cette','ceux','cela',
  'elle','elles','ils','tout','toute','tous','toutes','plus','votre','voire','très',
  'déjà','encore','toujours','jamais','autre','chaque','peut','sont','sera','avoir',
  'plusieurs','quelques','celui','celle','sous','vers','chez','lors','parce','quoi',
  'nouveau','nouvelle','premier','première','dernier','dernière',
  'le','la','les','de','du','des','un','une','et','en','à','au','aux','est',
  'par','sur','dans','avec','sans','que','qui','ce','se','sa','son','ses',
  'mon','ma','mes','ton','ta','tes','leur','lui','eux','vous','ils','elles',
  'the','and','for','are','but','not','you','all','can','had','her','was',
  'one','our','out','get','has','him','his','how','its','may','new','now',
  'old','own','see','two','way','who','did','let','put','say','she','too',
  'use','also','back','been','come','does','each','from','give','have','here',
  'into','just','know','like','look','made','make','many','more','most','much',
  'must','name','need','only','over','part','said','same','some','take','than',
  'that','them','then','they','this','time','very','want','well','went','were',
  'what','when','will','with','word','would','year','your','after','about',
  'before','could','first','their','there','these','think','those','three',
  'which','while','where','other','people','world','great','between','should',
  'through','because','however',
]);
const kwCache = {};
function extractKeywords(id, title, excerpt) {
  if (kwCache[id]) return kwCache[id];
  const text = title + ' ' + excerpt;
  const tokens = text.match(/[a-zA-ZàâäéèêëîïôùûüçæœÀÂÄÉÈÊËÎÏÔÙÛÜÇÆŒ]{4,}/g) || [];
  const freq = {};
  tokens.forEach(w => {
    const k = w.toLowerCase();
    const n = k.normalize('NFD').replace(/[\u0300-\u036f]/g,'');
    if (!STOPWORDS.has(k) && !STOPWORDS.has(n)) freq[k] = (freq[k]||0)+1;
  });
  const titleToks = title.match(/[a-zA-ZàâäéèêëîïôùûüçæœÀÂÄÉÈÊËÎÏÔÙÛÜÇÆŒ]{4,}/g) || [];
  titleToks.forEach(w => { const k=w.toLowerCase(); if(freq[k]) freq[k]+=3; });
  const kws = Object.entries(freq).sort((a,b)=>b[1]-a[1]).slice(0,4)
    .map(([k]) => k.charAt(0).toUpperCase()+k.slice(1));
  return kwCache[id] = kws;
}

// ── Helpers ───────────────────────────────────────────────────────────────
function estimateReadTime(html) {
  const words = html.replace(/<[^>]+>/g,' ').split(/\s+/).filter(Boolean).length;
  const m = Math.max(1, Math.round(words/200));
  return m === 1 ? '1 min de lecture' : `${m} min de lecture`;
}
function cssClass(cat) {
  if (!cat) return 'Autre';
  return cat.normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-zA-Z0-9]/g,'');
}
function escHtml(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function normalizeMediaUrl(url) {
  const raw = String(url || '').trim();
  if (!raw) return '';
  try {
    const u = new URL(raw, window.location.href);
    return `${u.host}${u.pathname}`.toLowerCase();
  } catch (_) {
    return raw.split('#')[0].split('?')[0].toLowerCase();
  }
}

function htmlHasImage(html, imageUrl) {
  if (!html || !imageUrl) return false;
  const marker = normalizeMediaUrl(imageUrl);
  if (!marker) return false;
  const div = document.createElement('div');
  div.innerHTML = html;
  const imgs = div.querySelectorAll('img');
  return Array.from(imgs).some(img => normalizeMediaUrl(img.getAttribute('src')) === marker);
}

function enhanceReaderContent() {
  const root = document.querySelector('.reader-full-content');
  if (!root) return;

  // Make wide tables scroll horizontally without breaking the reading flow.
  root.querySelectorAll('table').forEach(table => {
    if (table.parentElement && table.parentElement.classList.contains('table-scroll-wrap')) return;
    const wrap = document.createElement('div');
    wrap.className = 'table-scroll-wrap';
    table.parentNode.insertBefore(wrap, table);
    wrap.appendChild(table);
  });
}

let toastTimer;
function toast(msg, color) {
  const el = document.getElementById('toast');
  el.textContent = msg; el.style.background = color||'var(--text)';
  el.classList.add('show'); clearTimeout(toastTimer);
  toastTimer = setTimeout(()=>el.classList.remove('show'), 2500);
}

// ── Init ──────────────────────────────────────────────────────────────────
async function init() {
  const res = await fetch(`${API}?action=get_db`);
  DB = await res.json();
  if (!DB.feeds) DB.feeds = [];
  if (!DB.saved)  DB.saved = [];
  state.savedSet = new Set(DB.saved);
  renderSidebar();
  await refreshAllFeeds();
}

// ── Feeds ─────────────────────────────────────────────────────────────────
async function refreshAllFeeds() {
  const btn = document.getElementById('refreshBtn');
  btn.classList.add('spinning');
  document.getElementById('paneSub').textContent = 'Actualisation…';
  await Promise.allSettled(DB.feeds.map(f => fetchFeed(f)));
  btn.classList.remove('spinning');
  renderSidebar(); renderArticles();
  toast('Flux actualisés','#10B981');
}
async function fetchFeed(feed) {
  try {
    const p = new URLSearchParams({action:'fetch_feed',url:feed.url,feedId:feed.id,feedName:feed.name,cat:feed.cat});
    const data = await (await fetch(`${API}?${p}`)).json();
    if (data.articles) {
      ARTICLES[feed.id] = data.articles.map(a => ({
        ...a, unread:!state.readArticles.has(a.id),
        saved: state.savedSet.has(a.id)
      }));
    }
  } catch(e) { console.warn('Feed error', feed.name, e); }
}

// ── Sidebar ───────────────────────────────────────────────────────────────
function renderSidebar() {
  const all = getAllArticles();
  const unread = all.filter(a=>a.unread).length;
  const saved  = all.filter(a=>a.saved).length;
  const badgeAll = document.getElementById('badge-all');
  badgeAll.textContent = unread;
  badgeAll.className = 'item-badge'+(unread>0?' unread':'');
  document.getElementById('badge-saved').textContent = saved;

  const cats = {};
  DB.feeds.forEach(f => {
    const arts = ARTICLES[f.id]||[];
    if (!cats[f.cat]) cats[f.cat]={total:0,unread:0};
    cats[f.cat].total += arts.length;
    cats[f.cat].unread += arts.filter(a=>a.unread).length;
  });
  document.getElementById('cats-list').innerHTML = Object.entries(cats).map(([cat,d]) =>
    `<button class="sidebar-item ${state.feedFilter==='cat:'+cat?'active':''}" onclick="setFeedFilter('cat:${cat}',this)">
      <div class="cat-dot" style="background:${CAT_COLORS[cat]||'#9CA3AF'}"></div>
      ${cat}<span class="item-badge ${d.unread>0?'unread':''}">${d.unread>0?d.unread:d.total}</span>
    </button>`).join('');

  document.getElementById('feeds-sidebar-list').innerHTML = DB.feeds.map(f => {
    const arts = ARTICLES[f.id]||[];
    const u = arts.filter(a=>a.unread).length;
    return `<button class="sidebar-item ${state.feedFilter==='feed:'+f.id?'active':''}" onclick="setFeedFilter('feed:${f.id}',this)">
      <div class="cat-dot" style="background:${CAT_COLORS[f.cat]||'#9CA3AF'}"></div>
      <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${f.name}</span>
      ${u>0?`<span class="item-badge unread">${u}</span>`:''}
    </button>`;
  }).join('');
}

// ── Articles list ─────────────────────────────────────────────────────────
function getAllArticles() {
  return DB.feeds.flatMap(f=>ARTICLES[f.id]||[]).sort((a,b)=>b.timestamp-a.timestamp);
}
function getFilteredArticles() {
  let arts = [];
  if (state.feedFilter==='all') arts = getAllArticles();
  else if (state.feedFilter==='saved') arts = getAllArticles().filter(a=>a.saved);
  else if (state.feedFilter.startsWith('cat:')) { const c=state.feedFilter.slice(4); arts=getAllArticles().filter(a=>a.cat===c); }
  else if (state.feedFilter.startsWith('feed:')) { const id=parseInt(state.feedFilter.slice(5)); arts=(ARTICLES[id]||[]).sort((a,b)=>b.timestamp-a.timestamp); }
  if (state.readFilter==='unread') arts=arts.filter(a=>a.unread);
  return arts;
}

function renderArticles() {
  const arts = getFilteredArticles();
  const list = document.getElementById('articlesList');
  let title = 'Tous les articles';
  if (state.feedFilter==='saved') title='Sauvegardés';
  else if (state.feedFilter.startsWith('cat:')) title=state.feedFilter.slice(4);
  else if (state.feedFilter.startsWith('feed:')) { const f=DB.feeds.find(x=>x.id===parseInt(state.feedFilter.slice(5))); if(f) title=f.name; }
  document.getElementById('paneTitle').textContent = title;
  document.getElementById('paneSub').textContent = `${arts.length} article${arts.length!==1?'s':''}`;

  if (!arts.length) {
    list.innerHTML = `<div class="empty-state">
      <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <p>${!DB.feeds.length?'Ajoutez des flux dans l\'onglet "Gérer"':'Aucun article ici'}</p></div>`;
    return;
  }

  const isReading = state.activeArticle !== null;
  list.innerHTML = arts.map(a => {
    const active = state.activeArticle===a.id;
    const kws = extractKeywords(a.id, a.title, a.excerpt);
    const kwHtml = isReading ? '' : (kws.length
      ? `<div class="article-keywords">${kws.map(k=>`<span class="keyword-tag">#${escHtml(k)}</span>`).join('')}</div>` : '');

    return `<div class="article-card cat-${cssClass(a.cat)} ${a.unread?'unread':''} ${active?'active':''}"
        onclick="openArticle('${a.id}',${a.feedId})">
      <div class="article-card-top">
        <span class="cat-pill"><span class="cat-pill-dot"></span>${escHtml(a.cat)}</span>
        <span class="art-source">${escHtml(a.feedName)}</span>
        <span class="art-date">${a.date}</span>
      </div>
      ${!isReading ? `<div class="article-card-body">
        <div class="article-card-text">
          <div class="art-title">${escHtml(a.title)}</div>
          <div class="art-excerpt">${escHtml(a.excerpt)}</div>
        </div>
        ${a.image?`<img class="art-thumb" src="${escHtml(a.image)}" alt="" loading="lazy" onerror="this.style.display='none'">`:''}
      </div>` : `<div class="art-title art-title-compact">${escHtml(a.title)}</div>`}
      ${kwHtml}
      ${!isReading ? `<div class="article-card-footer">
        <button class="art-action ${a.saved?'saved':''}" onclick="toggleSaved(event,'${a.id}',${a.feedId})">
          <svg viewBox="0 0 24 24" width="12" height="12" fill="${a.saved?'currentColor':'none'}" stroke="currentColor" stroke-width="2">
            <path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
          ${a.saved?'Sauvegardé':'Sauvegarder'}
        </button>
        <button class="art-action" onclick="toggleRead(event,'${a.id}',${a.feedId})">
          <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          ${a.unread?'Marquer lu':'Non lu'}
        </button>
        ${a.unread?'<div class="art-unread-dot"></div>':''}
      </div>` : ''}
    </div>`;
  }).join('');
}

// ── Reader ────────────────────────────────────────────────────────────────
function renderReader(a, kws, fullHtml, isLoading) {
  const panel = document.getElementById('readerPanel');
  const kwHtml = kws.length
    ? `<div class="reader-keywords">${kws.map(k=>`<span class="reader-kw-tag">#${escHtml(k)}</span>`).join('')}</div>` : '';

  let contentHtml;
  if (isLoading) {
    contentHtml = `
      <div class="reader-excerpt-preview"><p>${escHtml(a.excerpt)}</p></div>
      <div class="skeleton-block">
        ${[100,88,95,76,92,84,97,71,89,83,94,78].map((w,i)=>
          `<div class="skel-line" style="width:${w}%;animation-delay:${i*.06}s"></div>`).join('')}
      </div>`;
  } else if (fullHtml) {
    const rt = estimateReadTime(fullHtml);
    contentHtml = `
      <div class="reader-read-time-row">
        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        ${rt}
      </div>
      <div class="reader-full-content">${fullHtml}</div>`;
  } else {
    contentHtml = `
      <div class="reader-excerpt-preview"><p>${escHtml(a.excerpt)}</p></div>
      <div class="reader-unavailable">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        Aperçu RSS — l'article complet n'a pas pu être chargé.
      </div>`;
  }

  const showHero = !!a.image && !(fullHtml && htmlHasImage(fullHtml, a.image));

  panel.innerHTML = `
    <div class="reader-progress-bar"><div class="reader-progress-fill" id="rpFill"></div></div>
    <div class="reader-panel-inner">
      <button class="reader-back" onclick="closeReader()">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5">
          <polyline points="15 18 9 12 15 6"/></svg>
        Tous les articles
      </button>
      <div class="cat-${cssClass(a.cat)}">
        <span class="reader-cat-pill"><span class="cat-pill-dot"></span>${escHtml(a.cat)}</span>
      </div>
      <h1 class="reader-headline">${escHtml(a.title)}</h1>
      <div class="reader-meta-row">
        <span class="reader-source">${escHtml(a.feedName)}</span>
        <span class="reader-sep"></span>
        <span class="reader-date">${a.date}</span>
      </div>
      ${kwHtml}
      ${showHero?`<img class="reader-hero" src="${escHtml(a.image)}" alt="" loading="lazy" onerror="this.style.display='none'">` : ''}
      <div class="reader-content">${contentHtml}</div>
      <div class="reader-actions">
        <a class="reader-btn primary" href="${escHtml(a.link)}" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
            <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
          </svg>
          Source originale
        </a>
        <button class="reader-btn ${a.saved?'saved':''}" id="reader-save-btn"
          onclick="toggleSaved(event,'${a.id}',${a.feedId})">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="${a.saved?'currentColor':'none'}" stroke="currentColor" stroke-width="2">
            <path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
          ${a.saved?'Sauvegardé':'Sauvegarder'}
        </button>
      </div>
    </div>`;

  enhanceReaderContent();

  panel.onscroll = () => {
    const fill = document.getElementById('rpFill');
    if (!fill) return;
    const t = panel.scrollHeight - panel.clientHeight;
    fill.style.width = t > 0 ? (panel.scrollTop/t*100)+'%' : '0%';
  };
}

async function openArticle(articleId, feedId) {
  feedId = parseInt(feedId);
  const a = (ARTICLES[feedId]||[]).find(x=>x.id===articleId);
  if (!a) return;

  a.unread = false;
  state.readArticles.add(articleId);
  state.activeArticle = articleId;

  // Switch to reading mode: hide articles pane by default, show reader
  const articlesPane = document.getElementById('articlesPane');
  const layout = document.querySelector('.reader-layout');
  articlesPane.classList.add('pane-hidden');
  layout.classList.add('reader-mode', 'articles-collapsed');
  document.getElementById('readerPanel').style.display = 'block';
  updateToggleBtn();

  const kws = extractKeywords(a.id, a.title, a.excerpt);
  renderReader(a, kws, null, true);
  updateToggleBtn(); // re-insert button after render
  renderSidebar();
  renderArticles(); // update compact list (hidden but refreshed)

  document.getElementById('readerPanel').scrollTop = 0;

  // Fetch full article
  try {
    const res = await fetch(`${API}?action=fetch_article&url=${encodeURIComponent(a.link)}`);
    const data = await res.json();
    if (state.activeArticle === articleId) { renderReader(a, kws, data.html||null, false); updateToggleBtn(); }
  } catch(e) {
    if (state.activeArticle === articleId) { renderReader(a, kws, null, false); updateToggleBtn(); }
  }
}

function toggleArticlesPane() {
  const layout = document.querySelector('.reader-layout');
  const isCollapsed = layout.classList.contains('articles-collapsed');
  layout.classList.toggle('articles-collapsed', !isCollapsed);
  layout.classList.toggle('articles-visible', isCollapsed);
  updateToggleBtn();
  if (isCollapsed) renderArticles(); // refresh compact list when showing
}

function updateToggleBtn() {
  const readerPanel = document.getElementById('readerPanel');
  let stack = document.getElementById('readerSideActions');
  if (!stack) {
    stack = document.createElement('div');
    stack.id = 'readerSideActions';
    stack.className = 'reader-side-actions';
    readerPanel.insertBefore(stack, readerPanel.firstChild);
  }

  let closeBtn = document.getElementById('closeReaderQuickBtn');
  if (!closeBtn) {
    closeBtn = document.createElement('button');
    closeBtn.id = 'closeReaderQuickBtn';
    closeBtn.className = 'close-reader-btn';
    closeBtn.onclick = closeReader;
    stack.appendChild(closeBtn);
  }

  let btn = document.getElementById('toggleArticlesBtn');
  if (!btn) {
    btn = document.createElement('button');
    btn.id = 'toggleArticlesBtn';
    btn.className = 'toggle-articles-btn';
    btn.onclick = toggleArticlesPane;
    stack.appendChild(btn);
  }

  closeBtn.title = 'Fermer l\'article en cours';
  closeBtn.innerHTML = `<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg><span>Article</span>`;

  const layout = document.querySelector('.reader-layout');
  const isCollapsed = layout.classList.contains('articles-collapsed');
  btn.title = isCollapsed ? 'Afficher la liste des articles' : 'Masquer la liste des articles';
  btn.innerHTML = isCollapsed
    ? `<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6"/></svg><span>Articles</span>`
    : `<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 18l-6-6 6-6"/></svg><span>Masquer</span>`;
}


function closeReader() {
  state.activeArticle = null;
  const articlesPane = document.getElementById('articlesPane');
  const layout = document.querySelector('.reader-layout');
  articlesPane.classList.remove('pane-hidden');
  layout.classList.remove('reader-mode', 'articles-collapsed', 'articles-visible');
  document.getElementById('readerPanel').style.display = '';
  const sideActions = document.getElementById('readerSideActions');
  if (sideActions) sideActions.remove();
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

// ── Actions ───────────────────────────────────────────────────────────────
function setFeedFilter(filter, el) {
  state.feedFilter = filter;
  document.querySelectorAll('.sidebar-item').forEach(b=>b.classList.remove('active'));
  if (el) el.classList.add('active');
  if (state.activeArticle) closeReader(); else renderArticles();
}
function setReadFilter(f) {
  state.readFilter = f;
  document.getElementById('pill-all').className = 'filter-pill'+(f==='all'?' active':'');
  document.getElementById('pill-unread').className = 'filter-pill'+(f==='unread'?' active':'');
  renderArticles();
}

async function toggleSaved(event, articleId, feedId) {
  event.stopPropagation();
  feedId = parseInt(feedId);
  const a = (ARTICLES[feedId]||[]).find(x=>x.id===articleId);
  if (!a) return;
  a.saved = !a.saved;
  if (a.saved) state.savedSet.add(articleId); else state.savedSet.delete(articleId);
  const form = new FormData(); form.append('articleId', articleId);
  await fetch(`${API}?action=toggle_saved`, {method:'POST',body:form});
  renderSidebar(); renderArticles();
  const btn = document.getElementById('reader-save-btn');
  if (btn) {
    btn.className = `reader-btn ${a.saved?'saved':''}`;
    btn.innerHTML = `<svg viewBox="0 0 24 24" width="13" height="13" fill="${a.saved?'currentColor':'none'}" stroke="currentColor" stroke-width="2">
      <path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg> ${a.saved?'Sauvegardé':'Sauvegarder'}`;
  }
  toast(a.saved?'Sauvegardé !':'Retiré des sauvegardés');
}

function toggleRead(event, articleId, feedId) {
  event.stopPropagation();
  feedId = parseInt(feedId);
  const a = (ARTICLES[feedId]||[]).find(x=>x.id===articleId);
  if (!a) return;
  a.unread = !a.unread;
  if (!a.unread) state.readArticles.add(articleId); else state.readArticles.delete(articleId);
  renderSidebar(); renderArticles();
}

// ── Tab switching ─────────────────────────────────────────────────────────
function switchTab(tab) {
  document.querySelectorAll('.nav-tab').forEach(b=>b.classList.toggle('active',b.dataset.tab===tab));
  document.getElementById('tabReader').style.display = tab==='reader' ? 'grid' : 'none';
  document.getElementById('tabManage').style.display = tab==='manage' ? 'block' : 'none';
  if (tab==='manage') renderManage();
}

// ── Manage ────────────────────────────────────────────────────────────────
function renderManage() {
  document.getElementById('feedsCountLabel').textContent = `${DB.feeds.length} flux actif${DB.feeds.length!==1?'s':''}`;
  const body = document.getElementById('feedsList');
  if (!DB.feeds.length) {
    body.innerHTML = `<div style="padding:2rem;text-align:center;color:var(--text-3);font-size:13px">Aucun flux — recherchez ou ajoutez manuellement ci-dessus</div>`;
    return;
  }
  body.innerHTML = `<div class="feeds-list-body">${DB.feeds.map(f=>{
    const arts = ARTICLES[f.id]||[];
    return `<div class="feed-row">
      <div class="feed-icon-box cat-${cssClass(f.cat)}">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5">
          <circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/>
          <path d="M4 11a8 8 0 018 8" stroke-linecap="round"/><path d="M4 5a14 14 0 0114 14" stroke-linecap="round"/>
        </svg></div>
      <div class="feed-row-info">
        <div class="feed-row-name">${escHtml(f.name)}</div>
        <div class="feed-row-url">${escHtml(f.url)}</div>
        <div class="feed-row-tags">
          <span class="cat-pill cat-${cssClass(f.cat)}" style="font-size:10px;padding:1px 7px">
            <span class="cat-pill-dot"></span>${f.cat}</span>
          <span class="feed-count-tag">${arts.length} articles</span>
        </div>
      </div>
      <button class="btn-delete" onclick="deleteFeed(${f.id})">Retirer</button>
    </div>`;
  }).join('')}</div>`;
}
async function deleteFeed(id) {
  if (!confirm('Retirer ce flux ?')) return;
  const form = new FormData(); form.append('id', id);
  await fetch(`${API}?action=delete_feed`,{method:'POST',body:form});
  DB.feeds = DB.feeds.filter(f=>f.id!==id);
  delete ARTICLES[id];
  renderSidebar(); renderArticles(); renderManage(); toast('Flux retiré');
}

// ── Search ────────────────────────────────────────────────────────────────
async function doSearch() {
  const q = document.getElementById('searchInput').value.trim();
  if (!q) return;
  const container = document.getElementById('searchResults');
  container.innerHTML = `<div class="loading-state" style="padding:1rem"><div class="loader"></div><p>Recherche…</p></div>`;
  const data = await (await fetch(`${API}?action=search_feeds&q=${encodeURIComponent(q)}`)).json();
  const existingUrls = new Set(DB.feeds.map(f=>f.url));
  if (!data.results?.length) {
    container.innerHTML = `<div style="padding:1rem 1.25rem;font-size:13px;color:var(--text-3)">Aucun résultat pour "<strong>${escHtml(q)}</strong>".</div>`;
    return;
  }
  container.innerHTML = `<div class="search-results-list">${data.results.map(f=>{
    const added = existingUrls.has(f.url);
    return `<div class="search-result-item cat-${cssClass(f.cat)}">
      <div class="feed-icon-box">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5">
          <circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/>
          <path d="M4 11a8 8 0 018 8" stroke-linecap="round"/><path d="M4 5a14 14 0 0114 14" stroke-linecap="round"/>
        </svg></div>
      <div class="search-res-info">
        <div class="search-res-name">${escHtml(f.name)}
          <span class="cat-pill cat-${cssClass(f.cat)}" style="font-size:10px;padding:1px 7px">
            <span class="cat-pill-dot"></span>${f.cat}</span></div>
        <div class="search-res-desc">${escHtml(f.desc)}</div>
      </div>
      <button class="btn-add-feed ${added?'added':''}"
        id="addbtn-${btoa(f.url).replace(/[^a-zA-Z0-9]/g,'')}"
        onclick="addFeedFromSearch(${JSON.stringify(f).replace(/"/g,'&quot;')})"
        ${added?'disabled':''}>${added?'✓ Ajouté':'+ Ajouter'}</button>
    </div>`;
  }).join('')}</div>`;
}
async function addFeedFromSearch(fd) {
  const form = new FormData();
  form.append('name',fd.name); form.append('url',fd.url); form.append('cat',fd.cat);
  const data = await (await fetch(`${API}?action=add_feed`,{method:'POST',body:form})).json();
  if (data.error) { toast(data.error,'#DC2626'); return; }
  DB.feeds.push(data.feed); await fetchFeed(data.feed);
  renderSidebar(); renderManage();
  toast(`"${fd.name}" ajouté !`,'#10B981');
  const btn = document.getElementById('addbtn-'+btoa(fd.url).replace(/[^a-zA-Z0-9]/g,''));
  if (btn) { btn.textContent='✓ Ajouté'; btn.classList.add('added'); btn.disabled=true; }
}

// ── Manual add ────────────────────────────────────────────────────────────
function openAddModal() { document.getElementById('addModal').style.display='flex'; }
function closeAddModal() { document.getElementById('addModal').style.display='none'; }
async function addManual() {
  const name = document.getElementById('modalName').value.trim();
  const url  = document.getElementById('modalUrl').value.trim();
  const cat  = document.getElementById('modalCat').value;
  if (!name||!url) { toast('Remplissez tous les champs','#DC2626'); return; }
  const form = new FormData();
  form.append('name',name); form.append('url',url); form.append('cat',cat);
  const data = await (await fetch(`${API}?action=add_feed`,{method:'POST',body:form})).json();
  if (data.error) { toast(data.error,'#DC2626'); return; }
  DB.feeds.push(data.feed); await fetchFeed(data.feed);
  closeAddModal(); renderSidebar(); renderManage();
  toast(`"${name}" ajouté !`,'#10B981');
  document.getElementById('modalName').value='';
  document.getElementById('modalUrl').value='';
}

// ── Start ─────────────────────────────────────────────────────────────────
init();
