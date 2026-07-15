# RSSphere — Lecteur RSS local

Lisez d'abord, abonnez-vous ensuite.

## Prérequis
- PHP 7.4+ (testé sur 8.3), avec `curl`, `dom`, `simplexml`, `mbstring`
- À défaut de `curl`, l'app retombe sur `file_get_contents` (`allow_url_fopen = On`)

## Lancement

```bash
cd rssphere
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080
```

Puis http://localhost:8080

> `PHP_CLI_SERVER_WORKERS` n'est pas obligatoire mais évite que le serveur de dev,
> mono-thread par défaut, sérialise les requêtes.

## Ce qui a changé

### 1. Chercher et lire sans rien enregistrer (onglet « Découvrir »)
- **Recherche d'articles à travers 58 sources du catalogue** sans en suivre aucune.
  Le serveur interroge tous les flux en parallèle (`curl_multi`), filtre les articles
  sur un score (titre > extrait, bonus de fraîcheur) et dédoublonne.
- **Portée réglable** : Catalogue / Mes flux / Les deux, plus un filtre par catégorie.
- **Aperçu d'une source** : voir tous ses articles, les lire, les sauvegarder — sans abonnement.
- **Coller l'URL d'un site** (`lemonde.fr`) : le flux RSS est trouvé tout seul
  (balise `<link rel="alternate">`, sinon `/feed`, `/rss.xml`, `/atom.xml`…).
- « Suivre » reste un geste explicite, proposé partout où la source n'est pas encore suivie.

### 2. Lecture
- **Trois papiers** — Papier, Sépia, Nuit — indépendants du thème de l'interface.
- **Réglages** : taille du texte (14→24 px), police serif/sans, largeur de colonne (60/68/78 caractères).
  Tout est mémorisé sur la machine (`localStorage`).
- Police de lecture **Newsreader**, dessinée pour le texte long (DM Serif Display reste pour les titres).
- Chapô mis en avant, colonne mesurée en `ch`, barre de progression, **mode focus**.
- Auteur affiché, temps de lecture, cartes « à lire ensuite » cliquables dans le lecteur.

### 3. Interface
- **Thème sombre** complet (auto / clair / sombre), sans flash au chargement.
- Couleurs de catégorie dérivées d'une seule teinte via `color-mix()` → cohérentes en clair comme en sombre.
- Liste groupée par jour, cartes retravaillées, états vides qui proposent l'action suivante.
- Raccourcis clavier, focus visible, `prefers-reduced-motion` respecté, responsive jusqu'au mobile.

### 4. Sous le capot
- **Cache disque** : flux 10 min, articles 24 h → la 2ᵉ recherche est instantanée.
- **Un seul aller-retour** pour actualiser tous les flux (`fetch_all`) au lieu d'un par flux.
- **État de lecture persisté** côté serveur (avant : perdu à chaque rechargement).
- **Sauvegardes = article complet** (avant : un simple id), donc on peut sauvegarder
  un article venu d'une source non suivie.
- **Identifiants d'articles stables** (basés sur l'URL) : un même article gardé lu/sauvegardé
  qu'il arrive par un abonnement ou par la recherche.
- `add_feed` **valide le flux** avant de l'enregistrer et récupère son titre automatiquement.
- Correction : `media:thumbnail` / `media:content` ne remontaient jamais d'image
  (après `children($ns)`, SimpleXML scope les attributs au namespace — il faut `attributes()`).
- Parsing RSS 1.0/RDF, Atom, `guid` comme lien de repli, `dc:creator`, `content:encoded`.

## Raccourcis

| Touche | Action |
|---|---|
| `J` / `K` | Article suivant / précédent |
| `Échap` | Fermer le lecteur |
| `S` | Sauvegarder l'article ouvert |
| `F` | Mode focus |
| `R` | Actualiser les flux |
| `/` | Aller à la recherche |

## Structure

```
rssphere/
├── index.php           # Interface (Flux / Découvrir / Gérer)
├── auth.php            # Connexion / inscription
├── auth_utils.php      # Comptes, sessions, cookies
├── api/
│   ├── index.php       # Routeur JSON
│   ├── catalog.php     # Catalogue de 58 flux proposés
│   └── lib/
│       ├── http.php    # curl_multi, cache disque, découverte de flux
│       ├── feed.php    # Parsing RSS/Atom/RDF, ids stables, scoring
│       ├── store.php   # Base JSON par compte (+ migration v1→v2)
│       └── extract.php # Extraction du contenu lisible d'une page
├── assets/css/style.css
├── assets/js/app.js
└── data/
    ├── users.json      # Comptes
    ├── user_dbs/       # Une base par compte
    └── cache/          # Cache des flux et articles (jetable)
```

## Actions de l'API

| Action | Rôle |
|---|---|
| `get_db` | Sources, sauvegardes, état de lecture |
| `fetch_all` | Tous les flux suivis, en parallèle |
| `fetch_feed` / `preview_feed` | Un flux, avec ou sans abonnement |
| `discover_feed` | Trouve le flux d'un site à partir de son URL |
| `search_feeds` / `catalog` | Sources du catalogue |
| `search_articles` | **Recherche d'articles** (`scope` = catalog / mine / all) |
| `fetch_article` | Contenu lisible d'une page |
| `add_feed` / `delete_feed` / `update_feed` | Abonnements |
| `toggle_saved` / `set_read` | Sauvegardes et lecture |
| `clear_cache` | Vide le cache |

## Droits

```bash
chmod 664 data/users.json
chmod -R 775 data/user_dbs data/cache
```

## Migration

La base de chaque compte passe en v2 au premier chargement (automatique, sans perte).
Les articles sauvegardés en v1 n'étaient que des identifiants calculés autrement :
ils sont conservés mais peuvent ne plus correspondre à un article affiché.
Le cache (`data/cache/`) est jetable : le supprimer ne fait que ralentir la prochaine lecture.
