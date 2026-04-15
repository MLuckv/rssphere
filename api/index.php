<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';
$dataFile = __DIR__ . '/../data/db.json';

function loadDB($file) {
    if (!file_exists($file)) return ['feeds' => [], 'saved' => []];
    return json_decode(file_get_contents($file), true) ?: ['feeds' => [], 'saved' => []];
}

function saveDB($file, $db) {
    file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function fetchRSS($url) {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'RSSphere/1.0 (+https://github.com/rssphere)',
            'follow_location' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);
    $content = @file_get_contents($url, false, $ctx);
    if (!$content) return null;
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml) return null;
    return $xml;
}

function parseArticles($xml, $feedId, $feedName, $cat, $limit = 20) {
    $articles = [];
    $items = [];

    // RSS 2.0
    if (isset($xml->channel->item)) {
        $items = $xml->channel->item;
    }
    // Atom
    elseif (isset($xml->entry)) {
        $items = $xml->entry;
    }

    $count = 0;
    foreach ($items as $item) {
        if ($count >= $limit) break;
        $count++;

        // Titre
        $title = html_entity_decode(strip_tags((string)($item->title ?? 'Sans titre')), ENT_QUOTES, 'UTF-8');

        // Lien
        $link = (string)($item->link ?? '');
        if (empty($link) && isset($item->link['href'])) $link = (string)$item->link['href'];

        // Date
        $dateStr = (string)($item->pubDate ?? $item->published ?? $item->updated ?? '');
        $timestamp = $dateStr ? strtotime($dateStr) : time();
        if (!$timestamp) $timestamp = time();

        // Description
        $desc = '';
        if (isset($item->description)) $desc = (string)$item->description;
        elseif (isset($item->summary)) $desc = (string)$item->summary;
        elseif (isset($item->content)) $desc = (string)$item->content;
        // Namespaced content
        $ns = $item->getNamespaces(true);
        foreach ($ns as $prefix => $nsUri) {
            $children = $item->children($nsUri);
            if (isset($children->encoded)) { $desc = (string)$children->encoded; break; }
        }

        $desc = strip_tags($desc);
        $desc = html_entity_decode($desc, ENT_QUOTES, 'UTF-8');
        $desc = trim(preg_replace('/\s+/', ' ', $desc));
        $excerpt = mb_strlen($desc) > 300 ? mb_substr($desc, 0, 300) . '…' : $desc;

        // Image
        $image = '';
        if (isset($item->enclosure['url']) && strpos((string)$item->enclosure['type'], 'image') !== false) {
            $image = (string)$item->enclosure['url'];
        }
        if (empty($image)) {
            foreach ($ns as $prefix => $nsUri) {
                $media = $item->children($nsUri);
                if (isset($media->thumbnail['url'])) { $image = (string)$media->thumbnail['url']; break; }
                if (isset($media->content['url'])) {
                    $t = (string)($media->content['medium'] ?? '');
                    if ($t === 'image') { $image = (string)$media->content['url']; break; }
                }
            }
        }
        if (empty($image)) {
            preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $desc, $m);
            if (!empty($m[1])) $image = $m[1];
        }

        $articles[] = [
            'id' => md5($feedId . $link . $title),
            'feedId' => $feedId,
            'feedName' => $feedName,
            'cat' => $cat,
            'title' => $title,
            'link' => $link,
            'excerpt' => $excerpt ?: 'Aucun extrait disponible.',
            'image' => $image,
            'timestamp' => $timestamp,
            'date' => formatDate($timestamp),
            'unread' => true,
        ];
    }
    return $articles;
}

function formatDate($ts) {
    $diff = time() - $ts;
    if ($diff < 3600) return 'Il y a ' . max(1, round($diff/60)) . ' min';
    if ($diff < 86400) return 'Il y a ' . round($diff/3600) . 'h';
    if ($diff < 172800) return 'Hier';
    if ($diff < 604800) return 'Il y a ' . round($diff/86400) . ' jours';
    return date('d/m/Y', $ts);
}

// ─── Actions ─────────────────────────────────────────────────────────────────

switch ($action) {

    case 'get_db':
        echo json_encode(loadDB($dataFile));
        break;

    case 'fetch_feed':
        $url = $_GET['url'] ?? '';
        $feedId = (int)($_GET['feedId'] ?? 0);
        $feedName = $_GET['feedName'] ?? '';
        $cat = $_GET['cat'] ?? 'Autre';
        if (!$url) { echo json_encode(['error' => 'URL manquante']); break; }
        $xml = fetchRSS($url);
        if (!$xml) { echo json_encode(['error' => 'Impossible de lire le flux', 'articles' => []]); break; }
        $articles = parseArticles($xml, $feedId, $feedName, $cat);
        echo json_encode(['articles' => $articles, 'count' => count($articles)]);
        break;

    case 'search_feeds':
        $q = strtolower(trim($_GET['q'] ?? ''));
        if (strlen($q) < 2) { echo json_encode(['results' => []]); break; }

        // Base de flux connus
        $catalog = [
            // Tech
            ['name'=>'Hacker News','url'=>'https://news.ycombinator.com/rss','cat'=>'Tech','desc'=>'Actualités tech & startups'],
            ['name'=>'The Verge','url'=>'https://www.theverge.com/rss/index.xml','cat'=>'Tech','desc'=>'Tech & culture numérique'],
            ['name'=>'Ars Technica','url'=>'https://feeds.arstechnica.com/arstechnica/index','cat'=>'Tech','desc'=>'Science, tech & culture'],
            ['name'=>'Wired','url'=>'https://www.wired.com/feed/rss','cat'=>'Tech','desc'=>'Tech & société'],
            ['name'=>'Numerama','url'=>'https://www.numerama.com/feed/','cat'=>'Tech','desc'=>'Tech française'],
            ['name'=>'01net','url'=>'https://www.01net.com/rss/news/','cat'=>'Tech','desc'=>'Actualité numérique FR'],
            ['name'=>'MIT Technology Review','url'=>'https://www.technologyreview.com/feed/','cat'=>'Tech','desc'=>'Innovation & IA'],
            ['name'=>'TechCrunch','url'=>'https://techcrunch.com/feed/','cat'=>'Tech','desc'=>'Startups & tech US'],
            // Actualités
            ['name'=>'Le Monde','url'=>'https://www.lemonde.fr/rss/une.xml','cat'=>'Actualités','desc'=>'Journal français de référence'],
            ['name'=>'Le Figaro','url'=>'https://www.lefigaro.fr/rss/figaro_actualites.xml','cat'=>'Actualités','desc'=>'Actualité française'],
            ['name'=>'Libération','url'=>'https://www.liberation.fr/arc/outboundfeeds/rss-all/?outputType=xml','cat'=>'Actualités','desc'=>'Journal français'],
            ['name'=>'France Info','url'=>'https://www.francetvinfo.fr/titres.rss','cat'=>'Actualités','desc'=>'Info en continu FR'],
            ['name'=>'RFI','url'=>'https://www.rfi.fr/fr/rss','cat'=>'Actualités','desc'=>'Radio internationale'],
            ['name'=>'BBC News','url'=>'http://feeds.bbci.co.uk/news/rss.xml','cat'=>'Actualités','desc'=>'International anglais'],
            ['name'=>'Reuters','url'=>'https://feeds.reuters.com/reuters/topNews','cat'=>'Actualités','desc'=>'Agence de presse mondiale'],
            ['name'=>'Al Jazeera','url'=>'https://www.aljazeera.com/xml/rss/all.xml','cat'=>'Actualités','desc'=>'Info internationale'],
            // Science
            ['name'=>'NASA Breaking News','url'=>'https://www.nasa.gov/rss/dyn/breaking_news.rss','cat'=>'Science','desc'=>'Agence spatiale US'],
            ['name'=>'Sciences et Avenir','url'=>'https://www.sciencesetavenir.fr/rss.xml','cat'=>'Science','desc'=>'Vulgarisation scientifique'],
            ['name'=>'Pour la Science','url'=>'https://www.pourlascience.fr/rss.xml','cat'=>'Science','desc'=>'Science magazine FR'],
            ['name'=>'Nature','url'=>'https://www.nature.com/nature.rss','cat'=>'Science','desc'=>'Revue scientifique mondiale'],
            ['name'=>'Futura Sciences','url'=>'https://www.futura-sciences.com/rss/actualites.xml','cat'=>'Science','desc'=>'Science & tech FR'],
            ['name'=>'Science Daily','url'=>'https://www.sciencedaily.com/rss/all.xml','cat'=>'Science','desc'=>'Recherche scientifique'],
            // Gaming
            ['name'=>'Eurogamer','url'=>'https://www.eurogamer.net/feed','cat'=>'Gaming','desc'=>'Jeux vidéo Europe'],
            ['name'=>'Jeuxvideo.com','url'=>'https://www.jeuxvideo.com/rss/rss-news.xml','cat'=>'Gaming','desc'=>'Jeux vidéo FR'],
            ['name'=>'Gamekult','url'=>'https://www.gamekult.com/feed.xml','cat'=>'Gaming','desc'=>'Gaming français'],
            ['name'=>'IGN France','url'=>'https://fr.ign.com/feed.xml','cat'=>'Gaming','desc'=>'Gaming & pop culture'],
            // Culture
            ['name'=>'Arte','url'=>'https://www.arte.tv/guide/fr/plus7/feeds/','cat'=>'Culture','desc'=>'Chaîne culturelle FR-DE'],
            ['name'=>'Télérama','url'=>'https://www.telerama.fr/rss/','cat'=>'Culture','desc'=>'Cinéma, séries, musique'],
            ['name'=>'Pitchfork','url'=>'https://pitchfork.com/rss/news/','cat'=>'Culture','desc'=>'Critique musicale'],
            // Économie
            ['name'=>'Les Échos','url'=>'https://syndication.lesechos.fr/rss/rss_la_une.xml','cat'=>'Économie','desc'=>'Finance & business FR'],
            ['name'=>'BFM Business','url'=>'https://bfmbusiness.bfmtv.com/rss/info/flux-rss/flux-toutes-les-actualites/','cat'=>'Économie','desc'=>'Économie française'],
            ['name'=>'L\'Usine Nouvelle','url'=>'https://www.usinenouvelle.com/rss/all','cat'=>'Économie','desc'=>'Industrie & innovation'],
            // Sport
            ['name'=>"L'Équipe",'url'=>'https://www.lequipe.fr/rss/actu_rss.xml','cat'=>'Sport','desc'=>'Sports français'],
            ['name'=>'Eurosport','url'=>'https://www.eurosport.fr/rss.xml','cat'=>'Sport','desc'=>'Sports Europe'],
        ];

        $results = array_values(array_filter($catalog, function($f) use ($q) {
            return strpos(strtolower($f['name']), $q) !== false
                || strpos(strtolower($f['cat']), $q) !== false
                || strpos(strtolower($f['desc']), $q) !== false;
        }));
        echo json_encode(['results' => array_slice($results, 0, 12)]);
        break;

    case 'add_feed':
        $db = loadDB($dataFile);
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $cat = trim($_POST['cat'] ?? 'Autre');
        if (!$name || !$url) { echo json_encode(['error' => 'Champs requis']); break; }
        // Vérif doublon
        foreach ($db['feeds'] as $f) {
            if ($f['url'] === $url) { echo json_encode(['error' => 'Ce flux existe déjà']); break 2; }
        }
        $maxId = 0;
        foreach ($db['feeds'] as $f) $maxId = max($maxId, $f['id']);
        $db['feeds'][] = ['id' => $maxId+1, 'name' => $name, 'url' => $url, 'cat' => $cat];
        saveDB($dataFile, $db);
        echo json_encode(['ok' => true, 'feed' => end($db['feeds'])]);
        break;

    case 'delete_feed':
        $db = loadDB($dataFile);
        $id = (int)($_POST['id'] ?? 0);
        $db['feeds'] = array_values(array_filter($db['feeds'], fn($f) => $f['id'] !== $id));
        saveDB($dataFile, $db);
        echo json_encode(['ok' => true]);
        break;

    case 'toggle_saved':
        $db = loadDB($dataFile);
        $articleId = $_POST['articleId'] ?? '';
        if (!isset($db['saved'])) $db['saved'] = [];
        $idx = array_search($articleId, $db['saved']);
        if ($idx !== false) {
            array_splice($db['saved'], $idx, 1);
            $saved = false;
        } else {
            $db['saved'][] = $articleId;
            $saved = true;
        }
        saveDB($dataFile, $db);
        echo json_encode(['ok' => true, 'saved' => $saved]);
        break;

    default:
        echo json_encode(['error' => 'Action inconnue']);
}
