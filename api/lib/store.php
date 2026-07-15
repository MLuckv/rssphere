<?php
/**
 * Base JSON par utilisateur.
 *
 * v2 :
 *  - "saved" contient l'article complet (et plus seulement son id) : on peut donc
 *    sauvegarder un article trouve dans "Decouvrir" sans etre abonne a sa source.
 *  - "read" persiste les articles lus (avant, l'etat vivait en memoire et
 *    disparaissait au rechargement de la page).
 */

const READ_HISTORY_MAX = 3000;

function defaultDB() {
    return ['version' => 2, 'feeds' => [], 'saved' => [], 'read' => []];
}

/** Remet une base dans la forme v2, quelle que soit sa version d'origine. */
function normalizeDB($data) {
    if (!is_array($data)) return defaultDB();

    $db = defaultDB();
    $db['feeds'] = [];

    if (isset($data['feeds']) && is_array($data['feeds'])) {
        foreach ($data['feeds'] as $f) {
            if (!is_array($f) || empty($f['url'])) continue;
            $db['feeds'][] = [
                'id'   => (int)($f['id'] ?? 0),
                'name' => (string)($f['name'] ?? hostOf($f['url'])),
                'url'  => (string)$f['url'],
                'cat'  => (string)($f['cat'] ?? 'Autre'),
            ];
        }
    }

    if (isset($data['saved']) && is_array($data['saved'])) {
        foreach ($data['saved'] as $s) {
            if (is_string($s)) {
                // v1 : on ne connaissait que l'id. On le garde pour ne rien perdre ;
                // il sera re-enrichi au premier passage sur l'article.
                $db['saved'][] = ['id' => $s, 'partial' => true];
            } elseif (is_array($s) && !empty($s['id'])) {
                $db['saved'][] = $s;
            }
        }
    }

    if (isset($data['read']) && is_array($data['read'])) {
        $db['read'] = array_values(array_unique(array_filter(array_map('strval', $data['read']))));
    }

    return $db;
}

function loadDB($file, $legacyFile = '') {
    if (!file_exists($file)) {
        $seed = defaultDB();
        if ($legacyFile !== '' && file_exists($legacyFile)) {
            $legacy = json_decode((string)file_get_contents($legacyFile), true);
            if (is_array($legacy)) $seed = normalizeDB($legacy);
        }
        saveDB($file, $seed);
        return $seed;
    }
    $data = json_decode((string)file_get_contents($file), true);
    $db = normalizeDB($data);
    if (!is_array($data) || ($data['version'] ?? 1) < 2) saveDB($file, $db);
    return $db;
}

function saveDB($file, $db) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $db = normalizeDB($db);
    $db['version'] = 2;
    if (count($db['read']) > READ_HISTORY_MAX) {
        $db['read'] = array_slice($db['read'], -READ_HISTORY_MAX);
    }
    file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $db;
}

function nextFeedId(array $feeds) {
    $max = 0;
    foreach ($feeds as $f) $max = max($max, (int)($f['id'] ?? 0));
    return $max + 1;
}

function findSavedIndex(array $saved, $id) {
    foreach ($saved as $i => $s) {
        if (($s['id'] ?? null) === $id) return $i;
    }
    return -1;
}

/** Ne garde que les champs utiles d'un article a sauvegarder. */
function slimArticle(array $a) {
    $keys = ['id','feedId','feedName','feedUrl','cat','title','link','author','excerpt','image','timestamp','date'];
    $out = [];
    foreach ($keys as $k) if (isset($a[$k])) $out[$k] = $a[$k];
    $out['savedAt'] = time();
    return $out;
}
