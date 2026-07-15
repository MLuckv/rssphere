<?php
/**
 * Parsing des flux RSS / Atom / RDF.
 */

function parseFeedXml($body) {
    if (!$body) return null;
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string)$body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    return $xml ?: null;
}

/** Titre / description / site d'un flux, quel que soit son format. */
function feedMeta($xml, $fallbackUrl = '') {
    $meta = ['title' => '', 'desc' => '', 'site' => ''];
    if (!$xml) return $meta;

    $channel = isset($xml->channel) ? $xml->channel : $xml;
    $meta['title'] = cleanText((string)($channel->title ?? ''));
    $meta['desc']  = cleanText((string)($channel->description ?? $channel->subtitle ?? ''));

    $link = (string)($channel->link ?? '');
    if ($link === '' && isset($channel->link['href'])) $link = (string)$channel->link['href'];
    if ($link === '' && isset($xml->link['href'])) $link = (string)$xml->link['href'];
    $meta['site'] = $link;

    if ($meta['title'] === '' && $fallbackUrl !== '') $meta['title'] = hostOf($fallbackUrl);
    if (mb_strlen($meta['desc']) > 220) $meta['desc'] = mb_substr($meta['desc'], 0, 220) . '…';
    return $meta;
}

function cleanText($s) {
    $s = html_entity_decode(strip_tags((string)$s), ENT_QUOTES, 'UTF-8');
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

function hostOf($url) {
    $h = @parse_url((string)$url, PHP_URL_HOST);
    return $h ? preg_replace('/^www\./', '', $h) : (string)$url;
}

/**
 * Identifiant d'article stable, independant du flux qui l'a servi.
 * Un meme article decouvert via "Decouvrir" puis via un abonnement garde
 * le meme id : lu / sauvegarde restent coherents.
 */
function articleId($link, $title, $feedName = '') {
    $link = trim((string)$link);
    if ($link !== '') {
        $p = @parse_url($link);
        if ($p && !empty($p['host'])) {
            $key = strtolower($p['host'] . ($p['path'] ?? ''));
            $key = rtrim($key, '/');
            if (!empty($p['query'])) $key .= '?' . $p['query'];
            return md5($key);
        }
        return md5(strtolower($link));
    }
    return md5(mb_strtolower($feedName . '|' . $title, 'UTF-8'));
}

/**
 * Lit un attribut sans prefixe sur un noeud.
 * Indispensable apres children($ns) : SimpleXML scope alors $node['url'] au
 * namespace courant, et media:thumbnail url="..." devient introuvable.
 */
function attrOf($node, $name) {
    if (!$node) return '';
    $attrs = $node->attributes();
    return $attrs && isset($attrs[$name]) ? (string)$attrs[$name] : '';
}

function isImageUrl($url) {
    return $url !== '' && !preg_match('/\.(mp3|mp4|m4a|ogg|wav|webm|mov|pdf)(\?|$)/i', $url);
}

/** Extrait la premiere image utilisable d'un item. */
function itemImage($item, $descHtml) {
    if (isset($item->enclosure)) {
        $type = attrOf($item->enclosure, 'type');
        $url  = attrOf($item->enclosure, 'url');
        if ($url !== '' && (strpos($type, 'image') !== false || ($type === '' && isImageUrl($url)))) return $url;
    }

    foreach ($item->getNamespaces(true) as $nsUri) {
        $media = $item->children($nsUri);

        if (isset($media->thumbnail)) {
            $url = attrOf($media->thumbnail, 'url');
            if ($url !== '') return $url;
        }
        if (isset($media->content)) {
            foreach ($media->content as $c) {
                $url = attrOf($c, 'url');
                if ($url === '') continue;
                $medium = attrOf($c, 'medium');
                $type   = attrOf($c, 'type');
                if ($medium === 'image' || strpos($type, 'image') === 0 || (($medium . $type) === '' && isImageUrl($url))) {
                    return $url;
                }
            }
        }
        if (isset($media->group->content)) {
            foreach ($media->group->content as $c) {
                $url = attrOf($c, 'url');
                if ($url !== '' && strpos(attrOf($c, 'type'), 'image') === 0) return $url;
            }
        }
    }

    if ($descHtml && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $descHtml, $m)) return $m[1];
    return '';
}

function itemAuthor($item) {
    $author = cleanText((string)($item->author ?? ''));
    if ($author === '' && isset($item->author->name)) $author = cleanText((string)$item->author->name);
    if ($author === '') {
        foreach ($item->getNamespaces(true) as $nsUri) {
            $ch = $item->children($nsUri);
            if (isset($ch->creator)) { $author = cleanText((string)$ch->creator); break; }
        }
    }
    if (preg_match('/^[^@]+@[^@]+\s+\((.+)\)$/', $author, $m)) $author = $m[1];
    return mb_strlen($author) > 60 ? '' : $author;
}

/**
 * Transforme un flux en liste d'articles normalises.
 * $feed : ['id','name','cat','url']
 */
function parseArticles($xml, array $feed, $limit = 25) {
    if (!$xml) return [];
    $articles = [];

    $items = [];
    if (isset($xml->channel->item))      $items = $xml->channel->item;
    elseif (isset($xml->item))           $items = $xml->item;   // RDF / RSS 1.0
    elseif (isset($xml->entry))          $items = $xml->entry;  // Atom

    $count = 0;
    foreach ($items as $item) {
        if ($count++ >= $limit) break;

        $title = cleanText((string)($item->title ?? '')) ?: 'Sans titre';

        $link = (string)($item->link ?? '');
        if ($link === '' && isset($item->link['href'])) $link = (string)$item->link['href'];
        if ($link === '' && isset($item->guid) && filter_var((string)$item->guid, FILTER_VALIDATE_URL)) {
            $link = (string)$item->guid;
        }
        $link = trim($link);

        $dateStr = (string)($item->pubDate ?? $item->published ?? $item->updated ?? '');
        if ($dateStr === '') {
            foreach ($item->getNamespaces(true) as $nsUri) {
                $ch = $item->children($nsUri);
                if (isset($ch->date)) { $dateStr = (string)$ch->date; break; }
            }
        }
        $timestamp = ($dateStr ? strtotime($dateStr) : 0) ?: time();

        $descHtml = '';
        if (isset($item->description))   $descHtml = (string)$item->description;
        elseif (isset($item->summary))   $descHtml = (string)$item->summary;
        elseif (isset($item->content))   $descHtml = (string)$item->content;
        foreach ($item->getNamespaces(true) as $nsUri) {
            $ch = $item->children($nsUri);
            if (isset($ch->encoded) && mb_strlen((string)$ch->encoded) > mb_strlen($descHtml)) {
                $descHtml = (string)$ch->encoded;
                break;
            }
        }

        $image = itemImage($item, $descHtml);
        $desc  = cleanText($descHtml);
        $excerpt = mb_strlen($desc) > 320 ? mb_substr($desc, 0, 320) . '…' : $desc;

        $articles[] = [
            'id'        => articleId($link, $title, $feed['name'] ?? ''),
            'feedId'    => $feed['id'] ?? 0,
            'feedName'  => $feed['name'] ?? hostOf($link),
            'feedUrl'   => $feed['url'] ?? '',
            'cat'       => $feed['cat'] ?? 'Autre',
            'title'     => $title,
            'link'      => $link,
            'author'    => itemAuthor($item),
            'excerpt'   => $excerpt !== '' ? $excerpt : 'Aucun extrait disponible.',
            'image'     => $image,
            'timestamp' => $timestamp,
            'date'      => formatDate($timestamp),
        ];
    }
    return $articles;
}

function formatDate($ts) {
    $diff = time() - $ts;
    if ($diff < 0)      return date('d M Y', $ts);
    if ($diff < 3600)   return 'Il y a ' . max(1, (int)round($diff / 60)) . ' min';
    if ($diff < 86400)  return 'Il y a ' . (int)round($diff / 3600) . ' h';
    if ($diff < 172800) return 'Hier';
    if ($diff < 604800) return 'Il y a ' . (int)round($diff / 86400) . ' jours';
    return date('d M Y', $ts);
}

/** Normalise pour la recherche : minuscules, sans accents. */
function searchNorm($s) {
    $s = mb_strtolower((string)$s, 'UTF-8');
    $map = ['à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','å'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'î'=>'i','ï'=>'i','í'=>'i','ô'=>'o','ö'=>'o','ó'=>'o','õ'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u',
            'ç'=>'c','ñ'=>'n','œ'=>'oe','æ'=>'ae'];
    $s = strtr($s, $map);
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

/** Decoupe une requete en termes normalises. */
function searchTerms($q) {
    $q = searchNorm($q);
    if ($q === '') return [];
    return array_values(array_filter(explode(' ', $q), fn($t) => $t !== ''));
}

/**
 * Score de pertinence d'un article pour une liste de termes.
 * 0 = ne correspond pas (tous les termes doivent apparaitre quelque part).
 */
function scoreArticle(array $a, array $terms) {
    $title = searchNorm($a['title'] ?? '');
    $body  = searchNorm(($a['excerpt'] ?? '') . ' ' . ($a['feedName'] ?? '') . ' ' . ($a['cat'] ?? ''));
    $score = 0;

    foreach ($terms as $t) {
        $inTitle = strpos($title, $t) !== false;
        $inBody  = strpos($body, $t) !== false;
        if (!$inTitle && !$inBody) return 0;
        if ($inTitle) {
            $score += 10;
            if (preg_match('/\b' . preg_quote($t, '/') . '\b/u', $title)) $score += 6;
        }
        if ($inBody) $score += 3;
    }

    // Un peu de fraicheur pour departager.
    $ageDays = max(0, (time() - (int)($a['timestamp'] ?? time())) / 86400);
    if ($ageDays < 1)      $score += 5;
    elseif ($ageDays < 3)  $score += 3;
    elseif ($ageDays < 7)  $score += 1;

    return $score;
}
