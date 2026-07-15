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
 * Cherche l'URL d'un flux RSS/Atom dans une page HTML.
 * Retourne l'URL absolue ou null.
 */
function discoverFeedInHtml($html, $baseUrl) {
    if (!preg_match_all('/<link\b[^>]*>/i', $html, $m)) return null;
    foreach ($m[0] as $tag) {
        if (!preg_match('/rel\s*=\s*["\']?[^"\'>]*alternate/i', $tag)) continue;
        if (!preg_match('#type\s*=\s*["\']?(application/(rss|atom)\+xml|application/xml|text/xml)#i', $tag)) continue;
        if (!preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $h)) continue;
        $href = html_entity_decode(trim($h[1]), ENT_QUOTES, 'UTF-8');
        $abs = absoluteUrl($href, $baseUrl);
        if ($abs) return $abs;
    }
    return null;
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
