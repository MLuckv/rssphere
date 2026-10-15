<?php
require_once __DIR__ . '/auth_utils.php';
auth_boot();

$currentUser = auth_current_user();
if (!$currentUser) {
  header('Location: auth.php');
  exit;
}
$username = htmlspecialchars($currentUser['username'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<!-- Sans Referer, la plupart des sites acceptent d'afficher leurs images ailleurs (anti-hotlink). -->
<meta name="referrer" content="no-referrer">
<title>RSSphere — Lecteur RSS</title>
<script>
  // Applique le thème avant le premier rendu : évite le flash blanc au chargement.
  try {
    var p = JSON.parse(localStorage.getItem('rssphere.prefs') || '{}');
    var dark = p.theme === 'dark' || ((!p.theme || p.theme === 'auto') && matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  } catch (e) {}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&family=Newsreader:opsz,wght@6..72,400;6..72,500;6..72,600;6..72,700&family=JetBrains+Mono:wght@400;500&family=Atkinson+Hyperlegible:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body>

<div class="app" id="app">

  <!-- TOPBAR -->
  <header class="topbar">
    <button class="action-btn icon-only menu-btn" data-act="toggle-sidebar" aria-label="Menu" title="Menu">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg>
    </button>
    <div class="topbar-brand">
      <div class="brand-icon">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5">
          <circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/>
          <path d="M4 11a8 8 0 018 8" stroke-linecap="round"/>
          <path d="M4 5a14 14 0 0114 14" stroke-linecap="round"/>
        </svg>
      </div>
      <span class="brand-name">RSSphere</span>
    </div>

    <nav class="topbar-nav">
      <button class="nav-tab active" data-tab="reader" onclick="switchTab('reader')">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        <span>Flux</span>
      </button>
      <button class="nav-tab" data-tab="discover" onclick="switchTab('discover')">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <span>Découvrir</span>
      </button>
      <button class="nav-tab" data-tab="manage" onclick="switchTab('manage')">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        <span>Gérer</span>
      </button>
    </nav>

    <div class="topbar-actions">
      <button class="action-btn" id="refreshBtn" onclick="refreshAllFeeds()" title="Actualiser tous les flux (R)">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
          <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
        </svg>
        <span>Actualiser</span>
      </button>

      <button class="action-btn icon-only" onclick="toggleTheme()" title="Clair / sombre" aria-label="Changer de thème">
        <svg class="icon-moon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
        <svg class="icon-sun" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4l1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
      </button>

      <div class="user-chip" title="Connecté en tant que <?php echo $username; ?>">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21a8 8 0 10-16 0"/><circle cx="12" cy="7" r="4"/></svg>
        <span><?php echo $username; ?></span>
      </div>

      <a class="action-btn danger icon-only" href="logout.php" title="Se déconnecter" aria-label="Se déconnecter">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
      </a>
    </div>
  </header>

  <!-- ═══ FLUX ═══ -->
  <div class="reader-layout" id="tabReader" style="display:grid">
    <div class="sidebar-backdrop" data-act="toggle-sidebar"></div>
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-inner">
        <div class="sidebar-section">
          <p class="sidebar-label">Navigation</p>
          <button class="sidebar-item active" data-act="filter" data-filter="all">
            <span class="item-icon"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg></span>
            <span class="label">Tous les articles</span>
            <span class="item-badge unread" id="badge-all">0</span>
          </button>
          <button class="sidebar-item" data-act="filter" data-filter="saved">
            <span class="item-icon"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg></span>
            <span class="label">Sauvegardés</span>
            <span class="item-badge" id="badge-saved">0</span>
          </button>
          <button class="sidebar-item" data-act="goto-discover">
            <span class="item-icon"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
            <span class="label">Découvrir</span>
          </button>
        </div>
        <div class="sidebar-section">
          <p class="sidebar-label">Catégories</p>
          <div id="cats-list"></div>
        </div>
        <div class="sidebar-section">
          <p class="sidebar-label">Mes sources</p>
          <div id="feeds-sidebar-list"></div>
        </div>
      </div>
    </aside>

    <main class="articles-pane" id="articlesPane">
      <div class="pane-header">
        <div>
          <h1 class="pane-title" id="paneTitle">Tous les articles</h1>
          <p class="pane-sub" id="paneSub">Chargement…</p>
        </div>
        <div class="pane-tools">
          <div class="seg" role="group" aria-label="Filtre de lecture">
            <button class="active" id="pill-all" onclick="setReadFilter('all')">Tout</button>
            <button id="pill-unread" onclick="setReadFilter('unread')">Non lus</button>
          </div>
          <div class="seg seg-icons" role="group" aria-label="Affichage">
            <button data-act="list-view" data-v="list" title="Liste" aria-label="Affichage en liste">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="9" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="9" y1="18" x2="21" y2="18"/><rect x="3" y="4" width="3" height="4" rx="1"/><rect x="3" y="10" width="3" height="4" rx="1"/><rect x="3" y="16" width="3" height="4" rx="1"/></svg>
            </button>
            <button data-act="list-view" data-v="cards" title="Cartes" aria-label="Affichage en cartes">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="9" rx="1.5"/><rect x="3" y="15" width="7" height="6" rx="1.5"/><rect x="14" y="15" width="7" height="6" rx="1.5"/></svg>
            </button>
          </div>
          <button class="icon-btn" data-act="mark-all-read" title="Tout marquer comme lu" aria-label="Tout marquer comme lu">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><polyline points="2 13 6 17 14 8"/><polyline points="10 15 12 17 22 7"/></svg>
          </button>
        </div>
      </div>

      <div class="pane-search-row">
        <div class="pane-search-box">
          <svg class="pane-search-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" id="articlesSearchInput" class="pane-search-input"
                 placeholder="Filtrer mes articles… (touche /)" oninput="setArticleSearch(this.value)">
          <button class="pane-search-clear" id="articlesSearchClear" onclick="clearArticleSearch()" title="Effacer">×</button>
        </div>
      </div>

      <div class="articles-list" id="articlesList">
        <div class="loading-state"><div class="loader"></div><p>Chargement des articles…</p></div>
      </div>
    </main>

    <aside class="reader-panel" id="readerPanel" data-read-theme="paper">
      <div class="reader-empty">
        <div class="reader-empty-icon">
          <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/>
          </svg>
        </div>
        <p>Choisissez un article à lire.<br><span style="font-size:12px">Navigation : <kbd>J</kbd> <kbd>K</kbd> · Ouvrir : <kbd>Entrée</kbd></span></p>
      </div>
    </aside>
  </div>

  <!-- ═══ DÉCOUVRIR ═══ -->
  <div class="discover-layout" id="tabDiscover" style="display:none">
    <div class="discover-container">
      <div class="discover-hero">
        <h1 class="discover-title">Trouvez de quoi lire</h1>
        <p class="discover-sub">Cherchez un sujet, un média ou un blog : des milliers de flux RSS du web, et les articles des sources du catalogue.
          Collez aussi l’adresse d’un site pour trouver son flux. Rien n’est suivi sans votre accord.</p>
      </div>

      <div class="discover-search">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--ink-3);flex-shrink:0">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" id="discoverInput" placeholder="Ex : cuisine végétarienne, astronomie, ou lemonde.fr" autocomplete="off">
        <button class="discover-go" onclick="runDiscover()">Chercher</button>
      </div>

      <div class="discover-controls">
        <div id="discoverCats" class="discover-cats"></div>
        <div id="discoverLangs" class="discover-langs" role="group" aria-label="Langue des sources"></div>
      </div>

      <div id="discoverResults"></div>
    </div>
  </div>

  <!-- ═══ GÉRER ═══ -->
  <div class="manage-layout" id="tabManage" style="display:none">
    <div class="manage-container">
      <div class="manage-card">
        <div class="card-header">
          <div class="card-icon">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/><path d="M4 11a8 8 0 018 8" stroke-linecap="round"/><path d="M4 5a14 14 0 0114 14" stroke-linecap="round"/></svg>
          </div>
          <div>
            <h2 class="card-title">Mes sources</h2>
            <p class="card-sub" id="feedsCountLabel">0 source suivie</p>
          </div>
          <span class="spacer"></span>
          <button class="btn-mini" data-act="import-opml" title="Importer un fichier OPML d’un autre lecteur RSS">Importer</button>
          <button class="btn-mini" data-act="export-opml" title="Exporter vos sources au format OPML">Exporter</button>
          <button class="btn-mini primary" onclick="openAddModal()">+ Ajouter</button>
          <input type="file" id="opmlInput" accept=".opml,.xml,text/xml,application/xml" hidden>
        </div>
        <div id="feedsList"></div>
      </div>
    </div>
  </div>

</div>

<!-- Ajout manuel -->
<div class="modal-overlay" id="addModal" style="display:none" onclick="if(this===event.target)closeAddModal()">
  <div class="modal">
    <h3 class="modal-title">Ajouter une source</h3>
    <p class="modal-sub">L’adresse d’un flux RSS. Vous ne la connaissez pas ? Cherchez le site dans <a href="#" data-act="goto-discover" onclick="closeAddModal()">Découvrir</a>.</p>
    <div class="form-row">
      <label class="form-label" for="modalUrl">URL du flux</label>
      <input class="form-input" id="modalUrl" placeholder="https://exemple.fr/feed.xml" type="url">
    </div>
    <div class="form-row">
      <label class="form-label" for="modalName">Nom (optionnel)</label>
      <input class="form-input" id="modalName" placeholder="Repris du flux si vide">
    </div>
    <div class="form-row">
      <label class="form-label" for="modalCat">Catégorie</label>
      <select class="form-select" id="modalCat">
        <option>Tech</option><option>Actualités</option><option>Science</option>
        <option>Culture</option><option>Sport</option><option>Économie</option>
        <option>Gaming</option><option>Histoire</option><option selected>Autre</option>
      </select>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeAddModal()">Annuler</button>
      <button class="btn-confirm" id="modalConfirm" onclick="addManual()">Ajouter</button>
    </div>
  </div>
</div>

<div class="lightbox" id="lightbox" hidden data-act="close-lightbox">
  <img id="lightboxImg" alt="">
  <p class="lightbox-cap" id="lightboxCap"></p>
  <button class="lightbox-close" aria-label="Fermer">×</button>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script src="assets/js/app.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app.js'); ?>"></script>
</body>
</html>
