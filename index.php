<?php
// Initialisation de la BDD JSON
$dataFile = __DIR__ . '/data/db.json';
if (!file_exists($dataFile)) {
    $default = [
        "feeds" => [
            ["id" => 1, "name" => "Hacker News", "url" => "https://news.ycombinator.com/rss", "cat" => "Tech"],
            ["id" => 2, "name" => "Le Monde", "url" => "https://www.lemonde.fr/rss/une.xml", "cat" => "Actualités"],
            ["id" => 3, "name" => "NASA", "url" => "https://www.nasa.gov/rss/dyn/breaking_news.rss", "cat" => "Science"]
        ],
        "saved" => []
    ];
    file_put_contents($dataFile, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RSSphere — Lecteur RSS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="app" id="app">

  <!-- TOPBAR -->
  <header class="topbar">
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
        Flux
      </button>
      <button class="nav-tab" data-tab="manage" onclick="switchTab('manage')">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        Gérer
      </button>
    </nav>
    <div class="topbar-actions">
      <button class="action-btn" id="refreshBtn" onclick="refreshAllFeeds()" title="Actualiser tous les flux">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
          <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
        </svg>
        <span>Actualiser</span>
      </button>
    </div>
  </header>

  <!-- VUE LECTEUR -->
  <div class="reader-layout" id="tabReader">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-inner">
        <div class="sidebar-section">
          <p class="sidebar-label">Navigation</p>
          <button class="sidebar-item active" data-filter="all" onclick="setFeedFilter('all', this)">
            <span class="item-icon">
              <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
            </span>
            Tous les articles
            <span class="item-badge unread" id="badge-all">0</span>
          </button>
          <button class="sidebar-item" data-filter="saved" onclick="setFeedFilter('saved', this)">
            <span class="item-icon">
              <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
            </span>
            Sauvegardés
            <span class="item-badge" id="badge-saved">0</span>
          </button>
        </div>
        <div class="sidebar-section" id="cats-section">
          <p class="sidebar-label">Catégories</p>
          <div id="cats-list"></div>
        </div>
        <div class="sidebar-section" id="feeds-section">
          <p class="sidebar-label">Mes flux</p>
          <div id="feeds-sidebar-list"></div>
        </div>
      </div>
    </aside>

    <!-- Liste articles -->
    <main class="articles-pane" id="articlesPane">
      <div class="pane-header">
        <div class="pane-header-left">
          <h1 class="pane-title" id="paneTitle">Tous les articles</h1>
          <p class="pane-sub" id="paneSub">Chargement…</p>
        </div>
        <div class="pane-filters">
          <button class="filter-pill active" id="pill-all" onclick="setReadFilter('all')">Tout</button>
          <button class="filter-pill" id="pill-unread" onclick="setReadFilter('unread')">Non lus</button>
        </div>
      </div>
      <div class="articles-list" id="articlesList">
        <div class="loading-state">
          <div class="loader"></div>
          <p>Chargement des articles…</p>
        </div>
      </div>
    </main>

    <!-- Panneau lecture -->
    <aside class="reader-panel" id="readerPanel">
      <div class="reader-panel-inner" id="readerPanelContent">
        <div class="reader-empty">
          <div class="reader-empty-icon">
            <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/>
            </svg>
          </div>
          <p>Sélectionnez un article à lire</p>
        </div>
      </div>
    </aside>
  </div>

  <!-- VUE GÉRER -->
  <div class="manage-layout" id="tabManage" style="display:none">
    <div class="manage-container">

      <div class="manage-search-card">
        <div class="card-header">
          <div class="card-icon teal">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          </div>
          <div>
            <h2 class="card-title">Rechercher un flux RSS</h2>
            <p class="card-sub">Trouvez et ajoutez des flux directement depuis l'application</p>
          </div>
        </div>
        <div class="search-box">
          <input type="text" id="searchInput" class="search-input" placeholder="Rechercher un site, un sujet… (ex: Le Monde, NASA, Tech)" onkeydown="if(event.key==='Enter') doSearch()">
          <button class="search-btn" onclick="doSearch()">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            Rechercher
          </button>
        </div>
        <div id="searchResults"></div>
      </div>

      <div class="manage-feeds-card">
        <div class="card-header">
          <div class="card-icon blue">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/><path d="M4 11a8 8 0 018 8" stroke-linecap="round"/><path d="M4 5a14 14 0 0114 14" stroke-linecap="round"/></svg>
          </div>
          <div>
            <h2 class="card-title">Mes flux abonnés</h2>
            <p class="card-sub" id="feedsCountLabel">0 flux actifs</p>
          </div>
          <button class="btn-add-manual" onclick="openAddModal()">+ Ajouter manuellement</button>
        </div>
        <div id="feedsList"></div>
      </div>

    </div>
  </div>

</div>

<!-- Modal ajout manuel -->
<div class="modal-overlay" id="addModal" style="display:none" onclick="if(this===event.target)closeAddModal()">
  <div class="modal">
    <h3 class="modal-title">Ajouter un flux RSS</h3>
    <div class="form-row">
      <label class="form-label">Nom du flux</label>
      <input class="form-input" id="modalName" placeholder="Ex: Le Monde">
    </div>
    <div class="form-row">
      <label class="form-label">URL du flux RSS</label>
      <input class="form-input" id="modalUrl" placeholder="https://…/feed.xml">
    </div>
    <div class="form-row">
      <label class="form-label">Catégorie</label>
      <select class="form-select" id="modalCat">
        <option>Tech</option><option>Actualités</option><option>Science</option>
        <option>Culture</option><option>Sport</option><option>Économie</option>
        <option>Gaming</option><option>Autre</option>
      </select>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeAddModal()">Annuler</button>
      <button class="btn-confirm" onclick="addManual()">Ajouter</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script src="assets/js/app.js"></script>
</body>
</html>
