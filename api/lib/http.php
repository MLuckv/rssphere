<?php
/**
 * Couche reseau + cache disque.
 *
 * Objectif : pouvoir interroger 40 flux d'un coup sans faire tomber le serveur
 * de dev PHP (mono-thread) ni attendre 40 allers-retours en serie.
 * -> curl_multi cote serveur = 1 requete navigateur, N fetch en parallele.
 */

const HTTP_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

function cacheDir() {
    $dir = __DIR__ . '/../../data/cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function cacheKey($kind, $url) {
    return $kind . '_' . md5((string)$url);
}

/** Lit une entree de cache si elle est encore fraiche, sinon null. */
function cacheGet($key, $ttl) {
    $file = cacheDir() . '/' . $key . '.json';
    if (!is_file($file)) return null;
    if ($ttl > 0 && (time() - (int)filemtime($file)) > $ttl) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function cacheSet($key, $data) {
    $file = cacheDir() . '/' . $key . '.json';
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $data;
}

/** Supprime les entrees de cache plus vieilles que $maxAge secondes. */
function cachePrune($maxAge = 86400) {
    $files = @glob(cacheDir() . '/*.json') ?: [];
    $now = time();
    foreach ($files as $f) {
        if (($now - (int)@filemtime($f)) > $maxAge) @unlink($f);
    }
}

/** Filtre minimal : on ne suit que du http(s) explicite. */
function isFetchableUrl($url) {
    $url = trim((string)$url);
    if ($url === '') return false;
    if (!preg_match('#^https?://#i', $url)) return false;
    return (bool)filter_var($url, FILTER_VALIDATE_URL);
}

function httpDefaultHeaders() {
    return [
        'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, text/html;q=0.8, */*;q=0.5',
        'Accept-Language: fr,en;q=0.8',
        'Cache-Control: no-cache',
    ];
}

/**
 * Telecharge une URL. Retourne ['body'=>string, 'url'=>url finale, 'status'=>int]
 * ou null en cas d'echec.
 */
function httpGet($url, $timeout = 12) {
    if (!isFetchableUrl($url)) return null;

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, httpCurlOptions($url, $timeout));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        if ($body === false || $body === '' || $status >= 400) return null;
        return ['body' => $body, 'url' => $final ?: $url, 'status' => $status];
    }

    // Repli si l'extension curl est absente.
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'follow_location' => 1,
            'max_redirects' => 5,
            'user_agent' => HTTP_UA,
            'header' => implode("\r\n", httpDefaultHeaders()) . "\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || $body === '') return null;
    return ['body' => $body, 'url' => $url, 'status' => 200];
}

function httpCurlOptions($url, $timeout) {
    return [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => HTTP_UA,
        CURLOPT_HTTPHEADER => httpDefaultHeaders(),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXFILESIZE => 8 * 1024 * 1024,
    ];
}

/**
 * Telecharge plusieurs URLs en parallele.
 * $urls : tableau [cle => url]. Retour : [cle => ['body'=>..,'url'=>..,'status'=>..]]
 * Les echecs sont simplement absents du resultat.
 */
function httpGetMulti(array $urls, $timeout = 12, $concurrency = 12) {
    $out = [];
    if (!$urls) return $out;

    if (!function_exists('curl_multi_init')) {
        foreach ($urls as $key => $url) {
            $r = httpGet($url, $timeout);
            if ($r) $out[$key] = $r;
        }
        return $out;
    }

    $mh = curl_multi_init();
    $handles = [];
    $queue = $urls;

    $addOne = function () use (&$queue, &$handles, $mh, $timeout) {
        while ($queue) {
            $key = array_key_first($queue);
            $url = $queue[$key];
            unset($queue[$key]);
            if (!isFetchableUrl($url)) continue;
            $ch = curl_init();
            curl_setopt_array($ch, httpCurlOptions($url, $timeout));
            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = ['key' => $key, 'handle' => $ch];
            return true;
        }
        return false;
    };

    for ($i = 0; $i < $concurrency; $i++) {
        if (!$addOne()) break;
    }

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 0.5);

        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $id = (int)$ch;
            if (isset($handles[$id])) {
                $key = $handles[$id]['key'];
                $body = curl_multi_getcontent($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                if ($body !== '' && $body !== false && $code < 400) {
                    $out[$key] = ['body' => $body, 'url' => $final ?: $urls[$key], 'status' => $code];
                }
                unset($handles[$id]);
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $addOne();
        }
    } while ($running > 0 || $handles);

    curl_multi_close($mh);
    return $out;
}

/**
 * Tous les flux RSS/Atom declares dans une page HTML (<link rel="alternate">).
 * Retour : [['url' => absolue, 'title' => titre declare], ...], flux de commentaires en dernier.
 */
function discoverFeedsInHtml($html, $baseUrl) {
    if (!preg_match_all('/<link\b[^>]*>/i', $html, $m)) return [];
    $found = [];
    foreach ($m[0] as $tag) {
        if (!preg_match('/rel\s*=\s*["\']?[^"\'>]*alternate/i', $tag)) continue;
        if (!preg_match('#type\s*=\s*["\']?(application/(rss|atom)\+xml|application/xml|text/xml)#i', $tag)) continue;
        if (!preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $h)) continue;
        $abs = absoluteUrl(html_entity_decode(trim($h[1]), ENT_QUOTES, 'UTF-8'), $baseUrl);
        if (!$abs) continue;
        $title = preg_match('/title\s*=\s*["\']([^"\']*)["\']/i', $tag, $t)
            ? trim(html_entity_decode($t[1], ENT_QUOTES, 'UTF-8')) : '';
        $k = feedUrlKey($abs);
        if (isset($found[$k])) {
            if ($found[$k]['title'] === '') $found[$k]['title'] = $title;
            continue;
        }
        $found[$k] = ['url' => $abs, 'title' => $title];
    }
    $list = array_values($found);
    usort($list, fn($a, $b) => isCommentsFeed($a) <=> isCommentsFeed($b));
    return $list;
}

function isCommentsFeed(array $f) {
    return (int)(bool)preg_match('/comment/i', $f['url'] . ' ' . $f['title']);
}

/** Compatibilite : premier flux declare, ou null. */
function discoverFeedInHtml($html, $baseUrl) {
    $all = discoverFeedsInHtml($html, $baseUrl);
    return $all ? $all[0]['url'] : null;
}

/**
 * Recherche de flux sur tout le web via l'annuaire public de Feedly.
 * $locale oriente le classement (fr, en…) ; le filtre strict par langue se fait cote client.
 * Retour : liste normalisee, ou null si l'annuaire ne repond pas.
 */
function searchFeedDirectory($q, $count = 20, $locale = 'fr') {
    $q = trim((string)$q);
    if ($q === '') return [];
    $locale = preg_match('/^[a-z]{2}$/', (string)$locale) ? $locale : 'fr';
    $key = cacheKey('dir', mb_strtolower($q, 'UTF-8') . '|' . $count . '|' . $locale);
    $hit = cacheGet($key, 6 * 3600);
    if ($hit && isset($hit['results'])) return $hit['results'];

    $res = httpGet('https://cloud.feedly.com/v3/search/feeds?' . http_build_query([
        'query' => $q, 'count' => $count, 'locale' => $locale,
    ]), 8);
    if (!$res) return null;
    $data = json_decode($res['body'], true);
    if (!is_array($data) || !isset($data['results'])) return null;

    $out = [];
    foreach ($data['results'] as $r) {
        if (($r['valid'] ?? true) === false) continue;
        $url = preg_replace('#^feed/#', '', (string)($r['feedId'] ?? $r['id'] ?? ''));
        if (!isFetchableUrl($url)) continue;
        $title = cleanText($r['title'] ?? '') ?: hostOf($url);
        $desc  = cleanText($r['description'] ?? '');
        // L'annuaire ne filtre rien : « histoire » remonte aussi des récits pour adultes.
        if (preg_match('/\b(porn\w*|xxx|sex[ey]?|érotique\w*|erotique\w*|erotic\w*|nsfw|escort\w*|hentai)\b/iu', $title . ' ' . $desc . ' ' . $url)) continue;
        if (mb_strlen($desc) > 200) $desc = mb_substr($desc, 0, 200) . '…';
        $out[] = [
            'name'        => $title,
            'url'         => $url,
            'desc'        => $desc,
            'site'        => (string)($r['website'] ?? ''),
            'icon'        => (string)($r['iconUrl'] ?? ($r['visualUrl'] ?? '')),
            'lang'        => substr((string)($r['language'] ?? ''), 0, 2),
            'subscribers' => (int)($r['subscribers'] ?? 0),
            'updated'     => (int)(($r['lastUpdated'] ?? $r['updated'] ?? 0) / 1000),
            'dormant'     => ($r['state'] ?? '') === 'dormant',
            'cat'         => guessCategory((array)($r['topics'] ?? []), $title . ' ' . $desc),
            'source'      => 'web',
        ];
    }
    cacheSet($key, ['results' => $out]);
    return $out;
}

/** Devine une categorie de l'app a partir des sujets Feedly et du texte. */
function guessCategory(array $topics, $text = '') {
    $map = [
        'Gaming'     => ['gaming', 'games', 'jeux video', 'jeu video', 'video games', 'esport', 'nintendo', 'playstation', 'xbox', 'steam'],
        'Histoire'   => ['history', 'histoire', 'historique', 'archeology', 'archaeology', 'archeologie', 'patrimoine', 'heritage', 'genealogy', 'genealogie', 'medieval', 'moyen age', 'antiquite', 'ancient history', 'military history'],
        'Tech'       => ['tech', 'technology', 'technologie', 'programming', 'developer', 'developpement', 'software', 'web', 'security', 'securite', 'ai', 'ia', 'intelligence artificielle', 'apple', 'android', 'linux', 'startups', 'gadgets', 'numerique'],
        'Science'    => ['science', 'sciences', 'space', 'espace', 'astronomie', 'physics', 'physique', 'biology', 'climate', 'climat', 'environnement', 'sante', 'health', 'medecine'],
        'Économie'   => ['economy', 'economie', 'business', 'finance', 'bourse', 'marketing', 'entrepreneur', 'investing', 'crypto'],
        'Sport'      => ['sport', 'sports', 'football', 'foot', 'rugby', 'tennis', 'f1', 'formule 1', 'cyclisme', 'nba', 'basket'],
        'Culture'    => ['culture', 'cinema', 'film', 'films', 'movies', 'music', 'musique', 'books', 'livres', 'litterature', 'art', 'photo', 'photography', 'series', 'tv', 'bd', 'manga', 'cuisine', 'food', 'recettes', 'mode', 'design'],
        'Actualités' => ['news', 'actualite', 'actualites', 'actu', 'info', 'politique', 'politics', 'world', 'monde', 'france', 'international'],
    ];
    $topics = array_map('searchNorm', $topics);
    foreach ($map as $cat => $words) {
        foreach ($words as $w) if (in_array($w, $topics, true)) return $cat;
    }
    $text = ' ' . searchNorm($text) . ' ';
    foreach ($map as $cat => $words) {
        foreach ($words as $w) if (strlen($w) > 3 && strpos($text, ' ' . $w . ' ') !== false) return $cat;
    }
    return 'Autre';
}

/** Cle de comparaison d'URL de flux : sans schema, www ni slash final. */
function feedUrlKey($url) {
    $u = strtolower(trim((string)$url));
    $u = preg_replace('#^https?://(www\.)?#', '', $u);
    return rtrim($u, '/');
}

/** Resout une URL potentiellement relative contre une base. */
function absoluteUrl($href, $baseUrl) {
    $href = trim((string)$href);
    if ($href === '') return null;
    if (preg_match('#^https?://#i', $href)) return $href;

    $b = @parse_url($baseUrl);
    if (!$b || empty($b['host'])) return null;
    $scheme = $b['scheme'] ?? 'https';
    $host = $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

    if (strpos($href, '//') === 0) return $scheme . ':' . $href;
    if (strpos($href, '/') === 0)  return $scheme . '://' . $host . $href;

    $path = $b['path'] ?? '/';
    $dir = rtrim(substr($path, 0, (int)strrpos($path, '/') + 1), '/');
    return $scheme . '://' . $host . $dir . '/' . ltrim($href, '/');
}

/** Le corps telecharge ressemble-t-il a un flux RSS/Atom ? */
function looksLikeFeed($body) {
    $head = substr(ltrim((string)$body), 0, 1200);
    return (bool)preg_match('/<(rss|feed|rdf:RDF)\b/i', $head);
}

/** Chemins classiques testes si la page ne declare aucun flux. */
function commonFeedPaths($baseUrl) {
    $p = @parse_url($baseUrl);
    if (!$p || empty($p['host'])) return [];
    $root = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $paths = ['/feed', '/rss', '/feed.xml', '/rss.xml', '/atom.xml', '/index.xml', '/feeds/posts/default', '/blog/feed'];
    $out = [];
    foreach ($paths as $path) $out[$path] = $root . $path;
    return $out;
}
