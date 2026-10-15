<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../auth_utils.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/feed.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/extract.php';

auth_boot();
$authUser = auth_require_login_json();

const FEED_TTL    = 600;    // 10 min : relire un flux plus souvent n'apporte rien
const ARTICLE_TTL = 86400;  // 24 h : le corps d'un article ne bouge plus

$action         = $_GET['action'] ?? '';
$legacyDataFile = __DIR__ . '/../data/db.json';
$dataDir        = __DIR__ . '/../data/user_dbs';
if (!is_dir($dataDir)) @mkdir($dataDir, 0775, true);

$safeUserId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($authUser['id'] ?? ''));
if ($safeUserId === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Identifiant utilisateur invalide']);
    exit;
}
$dataFile = $dataDir . '/db_' . $safeUserId . '.json';

function jsonOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Applique l'identite d'un flux a des articles venus du cache. */
function decorateArticles(array $articles, array $feed) {
    foreach ($articles as &$a) {
        $a['feedId']   = $feed['id'] ?? 0;
        $a['feedName'] = $feed['name'] ?? ($a['feedName'] ?? '');
        $a['feedUrl']  = $feed['url'] ?? ($a['feedUrl'] ?? '');
        $a['cat']      = $feed['cat'] ?? ($a['cat'] ?? 'Autre');
        $a['date']     = formatDate((int)($a['timestamp'] ?? time()));
    }
    unset($a);
    return $articles;
}

/**
 * Recupere plusieurs flux d'un coup : cache d'abord, curl_multi pour le reste.
 * Retour : ['byId' => [id => articles], 'meta' => [id => meta], 'failed' => [urls]]
 */
function loadFeeds(array $feeds, $ttl = FEED_TTL, $force = false) {
    $byId = [];
    $meta = [];
    $failed = [];
    $toFetch = [];

    foreach ($feeds as $feed) {
        if (empty($feed['url'])) continue;
        $hit = $force ? null : cacheGet(cacheKey('feed', $feed['url']), $ttl);
        if ($hit && isset($hit['articles'])) {
            $byId[$feed['id']] = decorateArticles($hit['articles'], $feed);
            $meta[$feed['id']] = $hit['meta'] ?? [];
        } else {
            $toFetch[$feed['id']] = $feed['url'];
        }
    }

    if ($toFetch) {
        $responses = httpGetMulti($toFetch, 12);
        foreach ($feeds as $feed) {
            $id = $feed['id'];
            if (!isset($toFetch[$id])) continue;
            if (!isset($responses[$id])) { $failed[] = $feed['url']; continue; }

            $xml = parseFeedXml($responses[$id]['body']);
            if (!$xml) { $failed[] = $feed['url']; continue; }

            $m = feedMeta($xml, $feed['url']);
            $articles = parseArticles($xml, $feed);
            cacheSet(cacheKey('feed', $feed['url']), ['meta' => $m, 'articles' => $articles, 'ts' => time()]);
            $byId[$id] = $articles;
            $meta[$id] = $m;
        }
    }

    return ['byId' => $byId, 'meta' => $meta, 'failed' => $failed];
}

/** Un seul flux, par URL, sans passer par les abonnements. */
function loadOneFeed($url, array $identity = [], $ttl = FEED_TTL, $force = false) {
    $feed = array_merge(['id' => 0, 'name' => '', 'url' => $url, 'cat' => 'Autre'], $identity);
    $hit = $force ? null : cacheGet(cacheKey('feed', $url), $ttl);

    if ($hit && isset($hit['articles'])) {
        $m = $hit['meta'] ?? [];
        if (($feed['name'] ?? '') === '') $feed['name'] = $m['title'] ?? hostOf($url);
        return ['meta' => $m, 'articles' => decorateArticles($hit['articles'], $feed)];
    }

    $res = httpGet($url, 12);
    if (!$res) return null;
    $xml = parseFeedXml($res['body']);
    if (!$xml) return null;

    $m = feedMeta($xml, $url);
    if (($feed['name'] ?? '') === '') $feed['name'] = $m['title'] ?: hostOf($url);
    $articles = parseArticles($xml, $feed);
    cacheSet(cacheKey('feed', $url), ['meta' => $m, 'articles' => $articles, 'ts' => time()]);
    return ['meta' => $m, 'articles' => $articles];
}

/** $want : 'fr', 'en' ou 'other' (toute langue connue hors fr/en). */
function langMatches($lang, $want) {
    $lang = strtolower(substr((string)$lang, 0, 2));
    if ($want === 'other') return $lang !== '' && $lang !== 'fr' && $lang !== 'en';
    return $lang === $want;
}

/** Le catalogue vu comme des flux "virtuels" (id negatif = non abonne). */
function catalogAsFeeds() {
    $feeds = [];
    $i = 0;
    foreach (feedCatalog() as $c) {
        $i++;
        $feeds[] = ['id' => -$i, 'name' => $c['name'], 'url' => $c['url'], 'cat' => $c['cat'], 'lang' => $c['lang'] ?? ''];
    }
    return $feeds;
}

// ─── Router ─────────────────────────────────────────────────────────────────

switch ($action) {

    // ── Base utilisateur ────────────────────────────────────────────────────
    case 'get_db': {
        $db = loadDB($dataFile, $legacyDataFile);
        jsonOut([
            'feeds' => $db['feeds'],
            'saved' => $db['saved'],
            'read'  => $db['read'],
            'catalogCount' => count(feedCatalog()),
        ]);
    }

    // ── Tous les flux abonnes, en parallele, en une seule requete ──────────
    case 'fetch_all': {
        $db = loadDB($dataFile, $legacyDataFile);
        $force = ($_GET['force'] ?? '') === '1';
        if (!$db['feeds']) jsonOut(['feeds' => [], 'failed' => []]);

        $res = loadFeeds($db['feeds'], FEED_TTL, $force);
        $out = [];
        foreach ($db['feeds'] as $f) {
            $out[] = ['feedId' => $f['id'], 'articles' => $res['byId'][$f['id']] ?? []];
        }
        cachePrune();
        jsonOut(['feeds' => $out, 'failed' => $res['failed']]);
    }

    case 'fetch_feed': {
        $url = trim($_GET['url'] ?? '');
        if (!isFetchableUrl($url)) jsonOut(['error' => 'URL invalide', 'articles' => []]);
        $res = loadOneFeed($url, [
            'id'   => (int)($_GET['feedId'] ?? 0),
            'name' => trim($_GET['feedName'] ?? ''),
            'cat'  => trim($_GET['cat'] ?? 'Autre'),
        ], FEED_TTL, ($_GET['force'] ?? '') === '1');
        if (!$res) jsonOut(['error' => 'Flux illisible', 'articles' => []]);
        jsonOut(['articles' => $res['articles'], 'meta' => $res['meta']]);
    }

    // ── Apercu d'une source SANS abonnement ────────────────────────────────
    case 'preview_feed': {
        $url = trim($_GET['url'] ?? '');
        if (!isFetchableUrl($url)) jsonOut(['error' => 'URL invalide']);

        $db = loadDB($dataFile, $legacyDataFile);
        $subscribed = false;
        foreach ($db['feeds'] as $f) if ($f['url'] === $url) $subscribed = true;

        $res = loadOneFeed($url, [
            'id'   => 0,
            'name' => trim($_GET['name'] ?? ''),
            'cat'  => trim($_GET['cat'] ?? 'Autre'),
        ]);
        if (!$res) jsonOut(['error' => "Ce flux n'a pas pu être lu."]);

        jsonOut([
            'feed' => [
                'url'  => $url,
                'name' => trim($_GET['name'] ?? '') ?: ($res['meta']['title'] ?? hostOf($url)),
                'cat'  => trim($_GET['cat'] ?? 'Autre'),
                'desc' => $res['meta']['desc'] ?? '',
                'site' => $res['meta']['site'] ?? '',
            ],
            'subscribed' => $subscribed,
            'articles'   => $res['articles'],
        ]);
    }

    // ── Coller l'URL d'un site : on trouve le flux tout seul ───────────────
    case 'discover_feed': {
        $url = trim($_GET['url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
        if (!isFetchableUrl($url)) jsonOut(['error' => 'URL invalide']);

        $res = httpGet($url, 12);
        if (!$res) jsonOut(['error' => 'Page inaccessible']);

        $alternatives = [];
        if (looksLikeFeed($res['body'])) {
            $feedUrl = $res['url'];
        } else {
            // Un site declare souvent plusieurs flux (une, rubriques, commentaires) : on les garde tous.
            $alternatives = discoverFeedsInHtml($res['body'], $res['url']);
            $feedUrl = $alternatives[0]['url'] ?? null;
            if (!$feedUrl) {
                // Rien de declare dans le HTML : on teste les chemins habituels.
                foreach (httpGetMulti(commonFeedPaths($res['url']), 8) as $r) {
                    if (looksLikeFeed($r['body'])) { $feedUrl = $r['url']; break; }
                }
            }
        }
        if (empty($feedUrl)) jsonOut(['error' => "Aucun flux RSS trouvé sur ce site."]);

        $feed = loadOneFeed($feedUrl);
        if (!$feed) jsonOut(['error' => 'Flux trouvé mais illisible : ' . $feedUrl]);

        $db = loadDB($dataFile, $legacyDataFile);
        $mine = [];
        foreach ($db['feeds'] as $f) $mine[feedUrlKey($f['url'])] = true;
        foreach ($alternatives as &$alt) $alt['subscribed'] = isset($mine[feedUrlKey($alt['url'])]);
        unset($alt);

        jsonOut([
            'feed' => [
                'url'  => $feedUrl,
                'name' => $feed['meta']['title'] ?: hostOf($feedUrl),
                'cat'  => 'Autre',
                'desc' => $feed['meta']['desc'] ?? '',
                'site' => $feed['meta']['site'] ?? '',
            ],
            'subscribed'   => isset($mine[feedUrlKey($feedUrl)]),
            'articles'     => $feed['articles'],
            'alternatives' => count($alternatives) > 1 ? $alternatives : [],
        ]);
    }

    // ── Recherche de sources dans le catalogue ─────────────────────────────
    case 'search_feeds': {
        $q = (string)($_GET['q'] ?? '');
        $terms = searchTerms($q);
        $db = loadDB($dataFile, $legacyDataFile);
        $mine = [];
        foreach ($db['feeds'] as $f) $mine[feedUrlKey($f['url'])] = true;

        $results = [];
        $catalogKeys = [];
        foreach (feedCatalog() as $f) {
            $catalogKeys[feedUrlKey($f['url'])] = true;
            $hay = searchNorm($f['name'] . ' ' . $f['cat'] . ' ' . $f['desc'] . ' ' . hostOf($f['url']));
            $score = 0;
            if (!$terms) {
                $score = 1;
            } else {
                foreach ($terms as $t) {
                    if (strpos($hay, $t) === false) { $score = 0; break; }
                    $score += (strpos(searchNorm($f['name']), $t) !== false) ? 8 : 3;
                }
            }
            if ($score > 0) {
                $f['score'] = $score;
                $f['source'] = 'catalog';
                $f['subscribed'] = isset($mine[feedUrlKey($f['url'])]);
                $results[] = $f;
            }
        }
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        $results = array_slice($results, 0, (int)($_GET['limit'] ?? 12));

        // Au-dela du catalogue : l'annuaire de Feedly couvre des millions de flux.
        $webError = false;
        if ($terms && ($_GET['web'] ?? '1') === '1') {
            $lang = (string)($_GET['lang'] ?? '');
            $dir = searchFeedDirectory($q, min(40, max(5, (int)($_GET['webLimit'] ?? 30))), $lang === 'en' ? 'en' : 'fr');
            if ($dir === null) {
                $webError = true;
            } else {
                foreach ($dir as $f) {
                    $k = feedUrlKey($f['url']);
                    if (isset($catalogKeys[$k])) continue;
                    $f['subscribed'] = isset($mine[$k]);
                    $results[] = $f;
                }
            }
        }
        jsonOut(['results' => $results, 'webError' => $webError]);
    }

    case 'catalog': {
        $db = loadDB($dataFile, $legacyDataFile);
        $mine = [];
        foreach ($db['feeds'] as $f) $mine[$f['url']] = true;
        $out = [];
        foreach (feedCatalog() as $f) {
            $f['subscribed'] = isset($mine[$f['url']]);
            $f['source'] = 'catalog';
            $out[] = $f;
        }
        jsonOut(['catalog' => $out, 'categories' => catalogCategories()]);
    }

    // ── Le point central : chercher des ARTICLES sans rien enregistrer ─────
    case 'search_articles': {
        $terms = searchTerms($_GET['q'] ?? '');
        if (!$terms) jsonOut(['results' => [], 'sources' => 0, 'failed' => []]);

        $scope = $_GET['scope'] ?? 'catalog';   // catalog | mine | all
        $cat   = trim($_GET['cat'] ?? '');
        $limit = min(60, max(5, (int)($_GET['limit'] ?? 40)));

        $db = loadDB($dataFile, $legacyDataFile);
        $mineUrls = [];
        foreach ($db['feeds'] as $f) $mineUrls[$f['url']] = true;

        $feeds = [];
        if ($scope === 'mine' || $scope === 'all') {
            foreach ($db['feeds'] as $f) $feeds[$f['url']] = $f;
        }
        if ($scope === 'catalog' || $scope === 'all') {
            foreach (catalogAsFeeds() as $f) {
                if (!isset($feeds[$f['url']])) $feeds[$f['url']] = $f;
            }
        }
        if ($cat !== '') $feeds = array_filter($feeds, fn($f) => $f['cat'] === $cat);
        // Langue : seuls les flux du catalogue la connaissent ; ceux de l'abonne sont gardes.
        $lang = (string)($_GET['lang'] ?? '');
        if ($lang !== '') $feeds = array_filter($feeds, fn($f) => !isset($f['lang']) || langMatches($f['lang'], $lang));
        $feeds = array_values($feeds);
        if (!$feeds) jsonOut(['results' => [], 'sources' => 0, 'failed' => []]);

        $res = loadFeeds($feeds, FEED_TTL);

        $hits = [];
        foreach ($feeds as $f) {
            foreach ($res['byId'][$f['id']] ?? [] as $a) {
                $score = scoreArticle($a, $terms);
                if ($score <= 0) continue;
                $a['score'] = $score;
                $a['subscribed'] = isset($mineUrls[$a['feedUrl']]);
                $hits[] = $a;
            }
        }

        // Dedoublonnage : le meme article peut arriver par deux sources.
        $seen = [];
        $unique = [];
        foreach ($hits as $h) {
            if (isset($seen[$h['id']])) continue;
            $seen[$h['id']] = true;
            $unique[] = $h;
        }
        usort($unique, fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($b['timestamp'] <=> $a['timestamp']));

        jsonOut([
            'results' => array_slice($unique, 0, $limit),
            'total'   => count($unique),
            'sources' => count($feeds),
            'failed'  => $res['failed'],
        ]);
    }

    // ── Contenu lisible d'un article ───────────────────────────────────────
    case 'fetch_article': {
        $url = trim($_GET['url'] ?? '');
        if (!isFetchableUrl($url)) jsonOut(['html' => null, 'error' => 'URL invalide']);

        // « article2 » : les entrées de l'ancien extracteur (images perdues) ne sont pas réutilisées.
        $key = cacheKey('article2', $url);
        $hit = cacheGet($key, ARTICLE_TTL);
        if ($hit && array_key_exists('html', $hit)) jsonOut($hit + ['cached' => true]);

        $res = httpGet($url, 14);
        if (!$res) jsonOut(['html' => null, 'error' => 'Page inaccessible']);

        $page = extractArticle($res['body'], $res['url']);
        $out = ['html' => $page['html'], 'image' => $page['image'], 'lang' => $page['lang']];
        if ($page['html']) cacheSet($key, $out);
        jsonOut($out);
    }

    // ── Abonnements ────────────────────────────────────────────────────────
    case 'add_feed': {
        $db   = loadDB($dataFile, $legacyDataFile);
        $name = trim($_POST['name'] ?? '');
        $url  = trim($_POST['url'] ?? '');
        $cat  = trim($_POST['cat'] ?? 'Autre');

        if (!isFetchableUrl($url)) jsonOut(['error' => "L'URL du flux n'est pas valide."]);
        foreach ($db['feeds'] as $f) {
            if ($f['url'] === $url) jsonOut(['error' => 'Vous suivez déjà cette source.']);
        }

        $probe = loadOneFeed($url, ['name' => $name, 'cat' => $cat]);
        if (!$probe) jsonOut(['error' => "Ce flux n'a pas pu être lu. Vérifiez l'URL."]);
        if ($name === '') $name = $probe['meta']['title'] ?: hostOf($url);

        $feed = ['id' => nextFeedId($db['feeds']), 'name' => $name, 'url' => $url, 'cat' => $cat ?: 'Autre'];
        $db['feeds'][] = $feed;
        saveDB($dataFile, $db);
        jsonOut(['ok' => true, 'feed' => $feed, 'articles' => decorateArticles($probe['articles'], $feed)]);
    }

    case 'delete_feed': {
        $db = loadDB($dataFile, $legacyDataFile);
        $id = (int)($_POST['id'] ?? 0);
        $db['feeds'] = array_values(array_filter($db['feeds'], fn($f) => (int)$f['id'] !== $id));
        saveDB($dataFile, $db);
        jsonOut(['ok' => true]);
    }

    case 'update_feed': {
        $db   = loadDB($dataFile, $legacyDataFile);
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $cat  = trim($_POST['cat'] ?? '');
        $found = null;
        foreach ($db['feeds'] as &$f) {
            if ((int)$f['id'] !== $id) continue;
            if ($name !== '') $f['name'] = $name;
            if ($cat !== '')  $f['cat']  = $cat;
            $found = $f;
        }
        unset($f);
        if (!$found) jsonOut(['error' => 'Flux introuvable']);
        saveDB($dataFile, $db);
        jsonOut(['ok' => true, 'feed' => $found]);
    }

    // ── Sauvegardes : on stocke l'article, donc pas besoin d'etre abonne ───
    case 'toggle_saved': {
        $db = loadDB($dataFile, $legacyDataFile);
        $article = json_decode((string)($_POST['article'] ?? ''), true);
        $articleId = (string)($_POST['articleId'] ?? ($article['id'] ?? ''));
        if ($articleId === '') jsonOut(['error' => 'Article manquant']);

        $idx = findSavedIndex($db['saved'], $articleId);
        if ($idx >= 0) {
            array_splice($db['saved'], $idx, 1);
            $saved = false;
        } else {
            if (!is_array($article)) jsonOut(['error' => 'Article manquant']);
            $article['id'] = $articleId;
            array_unshift($db['saved'], slimArticle($article));
            $saved = true;
        }
        saveDB($dataFile, $db);
        jsonOut(['ok' => true, 'saved' => $saved, 'count' => count($db['saved'])]);
    }

    // ── Etat de lecture, persiste cote serveur ─────────────────────────────
    case 'set_read': {
        $db  = loadDB($dataFile, $legacyDataFile);
        $ids = json_decode((string)($_POST['ids'] ?? ''), true);
        if (!is_array($ids)) $ids = array_filter([(string)($_POST['articleId'] ?? '')]);
        $read = ($_POST['read'] ?? '1') !== '0';

        $set = array_flip($db['read']);
        foreach ($ids as $id) {
            $id = (string)$id;
            if ($id === '') continue;
            if ($read) $set[$id] = true; else unset($set[$id]);
        }
        $db['read'] = array_keys($set);
        saveDB($dataFile, $db);
        jsonOut(['ok' => true, 'count' => count($db['read'])]);
    }

    case 'clear_cache': {
        $files = @glob(cacheDir() . '/*.json') ?: [];
        foreach ($files as $f) @unlink($f);
        jsonOut(['ok' => true, 'cleared' => count($files)]);
    }

    default:
        jsonOut(['error' => 'Action inconnue'], 400);
}
