<?php
/**
 * Extraction du contenu lisible d'une page d'article.
 * (Bloc repris du routeur d'origine, isole ici pour garder api/index.php lisible.)
 */

// ── Improved article extractor ──────────────────────────────────────────────

function isDecorative($src) {
    // Icônes sociales, pixels de suivi, avatars, boutons de partage.
    // Les mots-clés sont des segments de chemin entiers : « ads » ne doit pas
    // attraper « wp-content/uploads/ » (c'était le cas, et toutes les images
    // WordPress disparaissaient).
    $patterns = [
        '#twitter\.com/images#', '#facebook\.com/tr#', '#linkedin\.com/li#',
        '#instagram\.com/static#', '#youtube\.com/s/#', '#gravatar\.com#',
        '#(^|[/_.-])1x1([/_.-]|$)#', '#/(blank|pixel|spacer)\.gif#i', '#/transparent\.png#i',
        '#/[\w-]*(logo|icon)\.(png|gif|svg|ico)(\?|$)#i',
        '#/(favicons?|apple-touch-icon[^/]*)(/|\.|$)#i',
        '#/(share|sharing|social|avatars?|badges?|buttons?|widgets?|ads|adserver|emojis?)/#i',
        '#doubleclick\.net|google-analytics\.com|googletagmanager\.com|fb\.com/#',
        '#twimg\.com/profile#', '#s\.w\.org/images#', '#/wp-includes/images/#',
        '#syndication\.twitter|platform\.twitter#', '#/register-impression#',
    ];
    foreach ($patterns as $p) { if (preg_match($p, $src)) return true; }
    return false;
}

function canonicalMediaKey($src) {
    $src = trim((string)$src);
    if ($src === '') return '';
    $parts = @parse_url($src);
    if (!$parts) return strtolower((string)preg_replace('/[#?].*$/', '', $src));
    $host = strtolower($parts['host'] ?? '');
    $path = strtolower($parts['path'] ?? '');
    if ($host !== '' || $path !== '') return $host.$path;
    return strtolower((string)preg_replace('/[#?].*$/', '', $src));
}

function pruneLowValueNodes($xpath, $content) {
    $nodes = $xpath->query('.//section|.//div|.//p|.//aside|.//h2|.//h3|.//h4', $content);
    if (!$nodes) return;

    $relatedNeedles = [
        'you may also be interested', 'you might also like', 'related article', 'related articles',
        'recommended', 'read next', 'keep exploring', 'more from nasa',
        'a lire aussi', 'à lire aussi', 'vous pourriez aussi', 'recommande',
    ];

    $toRemove = [];
    foreach ($nodes as $node) {
        if (!$node || !$node->parentNode) continue;

        $text = html_entity_decode((string)$node->textContent, ENT_QUOTES, 'UTF-8');
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        if ($text === '') continue;
        $lower = mb_strtolower($text, 'UTF-8');

        $len = mb_strlen($text);
        $links = $xpath->query('.//a', $node);
        $linkCount = $links ? $links->length : 0;
        $linkTextLen = 0;
        if ($links) {
            foreach ($links as $a) {
                $lt = trim((string)preg_replace('/\s+/u', ' ', (string)$a->textContent));
                $linkTextLen += mb_strlen($lt);
            }
        }

        $ratio = $len > 0 ? ($linkTextLen / $len) : 0;
        $isSectionLabel = preg_match('/^\d{1,2}\s*[—\-]\s*[\p{L}][^.!?]{2,80}$/u', $text);
        $hasArrow = preg_match('/[↗→➜]/u', $text);
        $isLinkCluster = $linkCount >= 2 && $len <= 220 && ($ratio >= 0.62 || $hasArrow);
        $isOrphanCardLabel = preg_match('/^(article|articles|keep exploring)$/iu', $text);

        $isRelatedHeading = false;
        foreach ($relatedNeedles as $needle) {
            if (strpos($lower, $needle) !== false) { $isRelatedHeading = true; break; }
        }

        if ($isRelatedHeading && $len <= 140) {
            $container = $node;
            for ($depth = 0; $depth < 5; $depth++) {
                if (!$container->parentNode || $container->parentNode->nodeType !== XML_ELEMENT_NODE) break;
                $candidate = $container->parentNode;
                $candText = trim((string)preg_replace('/\s+/u', ' ', (string)$candidate->textContent));
                $candLinks = $xpath->query('.//a', $candidate);
                $candLinkCount = $candLinks ? $candLinks->length : 0;
                if ($candLinkCount >= 2 && mb_strlen($candText) <= 3200) {
                    $container = $candidate;
                    continue;
                }
                break;
            }
            $toRemove[] = $container;
            continue;
        }

        if ($isSectionLabel || $isLinkCluster || $isOrphanCardLabel) $toRemove[] = $node;
    }

    $seen = [];
    foreach ($toRemove as $n) {
        $k = spl_object_hash($n);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        if ($n->parentNode) $n->parentNode->removeChild($n);
    }
}

function compactText($text) {
    $text = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');
    $text = (string)preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function isLikelyArticleHref($href) {
    $href = trim((string)$href);
    if ($href === '') return false;
    if (!preg_match('/^https?:\/\//i', $href)) return false;
    if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|avif|pdf)(\?|$)/i', $href)) return false;
    return true;
}

function extractRelatedCardData($xpath, $node) {
    $links = $xpath->query('.//a[@href]', $node);
    if (!$links || $links->length === 0) return null;

    $href = '';
    $title = '';
    foreach ($links as $a) {
        $h = trim((string)$a->getAttribute('href'));
        if ($href === '' && isLikelyArticleHref($h)) $href = $h;

        $txt = compactText($a->textContent);
        if ($txt !== '' && mb_strlen($txt) >= 8 && mb_strlen($txt) <= 140) {
            if (mb_strlen($txt) > mb_strlen($title)) $title = $txt;
        }
    }
    if ($href === '' || $title === '') return null;

    $img = '';
    $imgs = $xpath->query('.//img[@src]', $node);
    if ($imgs && $imgs->length > 0) $img = trim((string)$imgs->item(0)->getAttribute('src'));
    if ($img === '') return null;

    $excerpt = '';
    $paragraphs = $xpath->query('.//p', $node);
    if ($paragraphs && $paragraphs->length > 0) {
        foreach ($paragraphs as $p) {
            $pt = compactText($p->textContent);
            if (mb_strlen($pt) >= 24) { $excerpt = $pt; break; }
        }
    }
    if ($excerpt !== '' && mb_strlen($excerpt) > 180) $excerpt = mb_substr($excerpt, 0, 180).'...';

    $allText = compactText($node->textContent);
    $meta = '';
    if (preg_match('/\b\d+\s*min\s*read\b/i', $allText, $m)) $meta = $m[0];

    return [
        'href' => $href,
        'title' => $title,
        'image' => $img,
        'meta' => $meta,
        'excerpt' => $excerpt,
    ];
}

function transformRelatedLinksToCards($dom, $xpath, $content) {
    $containers = $xpath->query('.//div[count(./div) >= 2 and count(./div) <= 8]', $content);
    if (!$containers || $containers->length === 0) return;

    $list = [];
    foreach ($containers as $c) $list[] = $c;

    // Process from deepest nodes first.
    for ($i = count($list)-1; $i >= 0; $i--) {
        $container = $list[$i];
        if (!$container || !$container->parentNode) continue;

        $childDivs = $xpath->query('./div', $container);
        if (!$childDivs || $childDivs->length < 2) continue;

        $cards = [];
        foreach ($childDivs as $child) {
            $card = extractRelatedCardData($xpath, $child);
            if ($card) $cards[] = $card;
        }

        $childCount = $childDivs->length;
        if (count($cards) < 2) continue;
        if (count($cards) < (int)ceil($childCount * 0.6)) continue;

        $containerText = mb_strtolower(compactText($container->textContent), 'UTF-8');
        $hasRelatedHint = strpos($containerText, 'min read') !== false
            || strpos($containerText, 'keep exploring') !== false
            || strpos($containerText, 'interested') !== false
            || strpos($containerText, 'read next') !== false
            || strpos($containerText, 'a lire aussi') !== false
            || strpos($containerText, 'à lire aussi') !== false;
        if (!$hasRelatedHint && count($cards) < 3) continue;

        $wrap = $dom->createElement('div');
        $wrap->setAttribute('data-related-cards', '1');

        $head = $dom->createElement('div');
        $head->setAttribute('data-related-heading', '1');
        $head->appendChild($dom->createTextNode('A lire ensuite'));
        $wrap->appendChild($head);

        foreach ($cards as $card) {
            $a = $dom->createElement('a');
            $a->setAttribute('href', $card['href']);
            $a->setAttribute('target', '_blank');
            $a->setAttribute('rel', 'noopener noreferrer');
            $a->setAttribute('data-related-card', '1');
            $a->setAttribute('data-open-in-reader', '1');
            $a->setAttribute('data-article-title', $card['title']);

            $fig = $dom->createElement('figure');
            $fig->setAttribute('data-card-image', '1');
            $img = $dom->createElement('img');
            $img->setAttribute('src', $card['image']);
            $img->setAttribute('alt', '');
            $img->setAttribute('loading', 'lazy');
            $fig->appendChild($img);
            $a->appendChild($fig);

            $body = $dom->createElement('div');
            $body->setAttribute('data-card-body', '1');

            $title = $dom->createElement('span');
            $title->setAttribute('data-card-title', '1');
            $title->appendChild($dom->createTextNode($card['title']));
            $body->appendChild($title);

            if (!empty($card['meta'])) {
                $meta = $dom->createElement('span');
                $meta->setAttribute('data-card-meta', '1');
                $meta->appendChild($dom->createTextNode($card['meta']));
                $body->appendChild($meta);
            }

            if (!empty($card['excerpt'])) {
                $excerpt = $dom->createElement('span');
                $excerpt->setAttribute('data-card-excerpt', '1');
                $excerpt->appendChild($dom->createTextNode($card['excerpt']));
                $body->appendChild($excerpt);
            }

            $a->appendChild($body);
            $wrap->appendChild($a);
        }

        $container->parentNode->replaceChild($wrap, $container);
    }
}


// ── Images ──────────────────────────────────────────────────────────────────

/**
 * Choisit l'URL d'un srcset : la plus petite d'au moins 1000px de large,
 * sinon la plus grande. Net sur un écran haute densité sans charger un 4K.
 */
function pickFromSrcset($srcset) {
    $srcset = trim((string)$srcset);
    if ($srcset === '') return '';
    if (!preg_match_all('/\s*(\S+)\s+(\d+w|\d+(?:\.\d+)?x)\s*(?:,|$)/', $srcset, $m, PREG_SET_ORDER)) {
        $first = preg_split('/[\s,]+/', $srcset)[0] ?? '';
        return strpos($first, 'data:') === 0 ? '' : $first;
    }
    $cands = [];
    foreach ($m as $c) {
        if (strpos($c[1], 'data:') === 0) continue;
        $d = $c[2];
        $w = substr($d, -1) === 'w' ? (int)$d : (int)round((float)$d * 800);
        $cands[] = [$w, $c[1]];
    }
    if (!$cands) return '';
    usort($cands, fn($a, $b) => $a[0] <=> $b[0]);
    foreach ($cands as $c) if ($c[0] >= 1000) return $c[1];
    return end($cands)[1];
}

function isPlaceholderSrc($src) {
    $src = trim((string)$src);
    return $src === '' || strpos($src, 'data:') === 0
        || preg_match('/1x1|blank\.(gif|png)|pixel\.(gif|png)|spacer|transparent\.(gif|png)|placeholder|lazy[-_]?load|loading\.gif|grey\.gif/i', $src);
}

/** La vraie source d'une image, en tenant compte du lazy-loading et des srcset. */
function bestImageSrc($img) {
    foreach (['data-srcset', 'data-lazy-srcset', 'srcset'] as $a) {
        $s = pickFromSrcset($img->getAttribute($a));
        if ($s !== '') return $s;
    }
    $src = trim($img->getAttribute('src'));
    if (!isPlaceholderSrc($src)) return $src;
    foreach (['data-src', 'data-lazy-src', 'data-original', 'data-lazy', 'data-url', 'data-full-src',
              'data-hi-res-src', 'data-delayed-url', 'data-cfsrc', 'data-image'] as $a) {
        $v = trim($img->getAttribute($a));
        if ($v !== '' && !isPlaceholderSrc($v)) return $v;
    }
    return '';
}

/** <picture> : on garde une seule <img> avec la meilleure source. */
function flattenPictures($dom, $xpath) {
    foreach (iterator_to_array($xpath->query('//picture')) as $pic) {
        if (!$pic->parentNode) continue;
        $img = $xpath->query('.//img', $pic)->item(0);
        $src = $img ? bestImageSrc($img) : '';
        if ($src === '') {
            foreach ($xpath->query('.//source', $pic) as $s) {
                $src = pickFromSrcset($s->getAttribute('srcset') ?: $s->getAttribute('data-srcset'));
                if ($src !== '') break;
            }
        }
        if ($src === '') { $pic->parentNode->removeChild($pic); continue; }
        $new = $img ? $img->cloneNode(false) : $dom->createElement('img');
        $new->setAttribute('src', $src);
        foreach (['srcset', 'data-srcset', 'data-src'] as $a) $new->removeAttribute($a);
        $pic->parentNode->replaceChild($new, $pic);
    }
}

/** Les <noscript> contiennent souvent l'image réelle d'un lazy-load : on les déballe. */
function unwrapNoscriptImages($xpath) {
    foreach (iterator_to_array($xpath->query('//noscript')) as $ns) {
        if (!$ns->parentNode) continue;
        $imgs = $xpath->query('.//img', $ns);
        if (!$imgs->length) continue;
        // L'image lazy voisine fait doublon : on la retire si elle n'a pas de vraie source.
        $prev = $ns->previousSibling;
        while ($prev && $prev->nodeType !== XML_ELEMENT_NODE) $prev = $prev->previousSibling;
        if ($prev && $prev->nodeName === 'img' && bestImageSrc($prev) === '') $prev->parentNode->removeChild($prev);
        foreach (iterator_to_array($imgs) as $img) $ns->parentNode->insertBefore($img, $ns);
        $ns->parentNode->removeChild($ns);
    }
}

// ── Vidéos ──────────────────────────────────────────────────────────────────

/** URL d'intégration sûre pour les hébergeurs vidéo connus, sinon ''. */
function embedVideoSrc($src) {
    $src = trim((string)$src);
    if (strpos($src, '//') === 0) $src = 'https:' . $src;
    if (preg_match('#^https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([\w-]{6,})#i', $src, $m)) return 'https://www.youtube-nocookie.com/embed/' . $m[1];
    if (preg_match('#^https?://player\.vimeo\.com/video/(\d+)#i', $src, $m)) return 'https://player.vimeo.com/video/' . $m[1];
    if (preg_match('#^https?://(?:www\.)?dailymotion\.com/embed/video/(\w+)#i', $src, $m)) return 'https://www.dailymotion.com/embed/video/' . $m[1];
    if (preg_match('#^https?://geo\.dailymotion\.com/player[^?]*\?(?:.*&)?video=(\w+)#i', $src, $m)) return 'https://www.dailymotion.com/embed/video/' . $m[1];
    return '';
}

function keepVideoEmbeds($dom, $xpath) {
    foreach (iterator_to_array($xpath->query('//iframe')) as $f) {
        if (!$f->parentNode) continue;
        $src = embedVideoSrc($f->getAttribute('src') ?: $f->getAttribute('data-src'));
        if ($src === '') { $f->parentNode->removeChild($f); continue; }
        $wrap = $dom->createElement('div');
        $wrap->setAttribute('data-embed', 'video');
        $frame = $dom->createElement('iframe');
        $frame->setAttribute('src', $src);
        $frame->setAttribute('loading', 'lazy');
        $frame->setAttribute('allowfullscreen', 'allowfullscreen');
        $frame->setAttribute('allow', 'encrypted-media; picture-in-picture; fullscreen');
        $frame->setAttribute('title', 'Vidéo');
        $wrap->appendChild($frame);
        $f->parentNode->replaceChild($wrap, $f);
    }
}

// ── Choix du bloc principal ─────────────────────────────────────────────────

function paragraphTextLen($xpath, $node) {
    $len = 0;
    foreach ($xpath->query('.//p', $node) as $p) $len += mb_strlen(compactText($p->textContent));
    return $len;
}

/** Façon Readability : le parent qui réunit le plus de texte en paragraphes. */
function densestContainer($xpath) {
    $scores = [];
    $nodes = [];
    foreach ($xpath->query('//p') as $p) {
        $len = mb_strlen(compactText($p->textContent));
        if ($len < 60) continue;
        $parent = $p->parentNode;
        if (!$parent || $parent->nodeType !== XML_ELEMENT_NODE) continue;
        $k = spl_object_id($parent);
        $scores[$k] = ($scores[$k] ?? 0) + $len;
        $nodes[$k] = $parent;
        $gp = $parent->parentNode;
        if ($gp && $gp->nodeType === XML_ELEMENT_NODE && $gp->nodeName !== 'body') {
            $kg = spl_object_id($gp);
            $scores[$kg] = ($scores[$kg] ?? 0) + $len / 2;
            $nodes[$kg] = $gp;
        }
    }
    if (!$scores) return null;
    arsort($scores);
    return $nodes[array_key_first($scores)];
}

function findMainContent($xpath) {
    $selectors = [
        "//*[@itemprop='articleBody']",
        "//*[@id='article-body']", "//*[@id='article-content']", "//*[@id='post-content']",
        "//*[@id='entry-content']", "//*[@id='story-body']",
        "//*[contains(@class,'article-body')]", "//*[contains(@class,'article__body')]",
        "//*[contains(@class,'article-content')]", "//*[contains(@class,'article__content')]",
        "//*[contains(@class,'post-content')]", "//*[contains(@class,'entry-content')]",
        "//*[contains(@class,'story-body')]", "//*[contains(@class,'article__text')]",
        "//*[contains(@class,'articleBody')]", "//*[contains(@class,'post-body')]",
        "//*[contains(@class,'content-body')]", "//*[contains(@class,'rich-text')]",
        "//article[not(ancestor::aside)][not(ancestor::nav)]",
        "//main", "//*[@role='main']", "//*[@id='main-content']",
    ];
    $chosen = null;
    $chosenLen = 0;
    foreach ($selectors as $sel) {
        $nodes = $xpath->query($sel);
        if (!$nodes || !$nodes->length) continue;
        $parts = [];
        foreach ($nodes as $n) {
            $len = paragraphTextLen($xpath, $n);
            if ($len >= 200) $parts[] = [$n, $len];
            if ($len > $chosenLen) { $chosenLen = $len; $chosen = $n; }
        }
        // Article découpé en plusieurs blocs (Ars Technica…) : on prend leur ancêtre commun
        // s'il n'apporte pas surtout du texte étranger.
        if (count($parts) >= 2) {
            $lca = commonAncestor(array_column($parts, 0));
            $sum = array_sum(array_column($parts, 1));
            if ($lca && $lca->nodeName !== 'body') {
                $lcaLen = paragraphTextLen($xpath, $lca);
                if ($lcaLen <= $sum * 1.4) { $chosen = $lca; $chosenLen = $lcaLen; }
            }
        }
        if ($chosenLen >= 400) break;
    }

    $dense = densestContainer($xpath);
    if ($dense) {
        $denseLen = paragraphTextLen($xpath, $dense);
        // Le sélecteur a raté le corps (bloc d'accroche, carte de une…) : on suit la densité.
        if (!$chosen || $denseLen > $chosenLen * 1.6) { $chosen = $dense; $chosenLen = $denseLen; }
    }

    if (!$chosen || $chosenLen < 150) {
        // Sites sans <p> : le div le plus riche en texte.
        $bestScore = 0;
        foreach ($xpath->query('//div') as $div) {
            $score = $div->getElementsByTagName('p')->length * 30 + min(strlen(trim($div->textContent)), 8000);
            if ($score > $bestScore) { $bestScore = $score; $chosen = $div; }
        }
    }
    return $chosen;
}

/** Métadonnées utiles avant nettoyage : image de partage, langue, corps JSON-LD. */
function extractPageMeta($xpath) {
    $meta = ['image' => '', 'ldBody' => ''];
    foreach (["//meta[@property='og:image']", "//meta[@name='twitter:image']", "//meta[@property='og:image:url']"] as $q) {
        $n = $xpath->query($q)->item(0);
        if ($n && trim($n->getAttribute('content')) !== '') { $meta['image'] = trim($n->getAttribute('content')); break; }
    }

    foreach ($xpath->query("//script[@type='application/ld+json']") as $s) {
        $data = json_decode(trim($s->textContent), true);
        if (!is_array($data)) continue;
        $stack = [$data];
        while ($stack) {
            $cur = array_pop($stack);
            if (!is_array($cur)) continue;
            if (!empty($cur['articleBody']) && is_string($cur['articleBody']) && mb_strlen($cur['articleBody']) > mb_strlen($meta['ldBody'])) {
                $meta['ldBody'] = $cur['articleBody'];
            }
            foreach ($cur as $v) if (is_array($v)) $stack[] = $v;
        }
    }
    return $meta;
}

// ── Nettoyage ───────────────────────────────────────────────────────────────

/**
 * Classes/ids de bruit, comparés comme des mots entiers : « share » attrape
 * « c-share__btn » mais « toc » n'attrape plus « photo-credit », ni « nav » « canvas ».
 */
const NOISE_RE = '/(?<![a-z0-9])(?:comments?|related|share|shares|sharing|social|recommend(?:ed|ations?)?|cookies?|consent|popup|modal|newsletter|subscribe|subscription|adverts?|advertisement|advertising|ads?|adslot|ad-slot|dfp|teads|sidebar|widgets?|promo|promos|promoted|taboola|outbrain|disqus|banner|paywall|interstitial|masthead|site-header|breadcrumbs?|pagination|pager|tags|tag-list|tags-list|keywords|byline|meta|author-bio|bio|avatar|toc|table-of-contents|recirc|signup|sign-up|login|register|follow|footer|copyright|legal|menu|nav|navbar|navigation|sitemap|search|sponsor(?:ed)?|partners?|affiliate|native-ad|reading-time|read-time|progress|sr-only|visually-hidden|screen-reader-text|more-stories|most-read|read-also|read-more|lire-aussi|a-lire|rebond)(?![a-z0-9])/';

function removeNoise($xpath, $content) {
    $total = max(1, paragraphTextLen($xpath, $content));
    $rem = [];
    foreach ($xpath->query('.//*[@class or @id or @hidden or @style or @aria-hidden]', $content) as $n) {
        $style = strtolower(str_replace(' ', '', $n->getAttribute('style')));
        $hidden = $n->hasAttribute('hidden') || strpos($style, 'display:none') !== false
            || ($n->getAttribute('aria-hidden') === 'true' && trim($n->textContent) === '' && $n->nodeName !== 'img');
        $label = strtolower($n->getAttribute('class') . ' ' . $n->getAttribute('id'));
        if (!$hidden && !preg_match(NOISE_RE, $label)) continue;
        // Garde-fou : une classe trompeuse ne doit pas emporter l'article avec elle.
        if (!$hidden && paragraphTextLen($xpath, $n) > $total * 0.4) continue;
        $rem[] = $n;
    }
    foreach ($rem as $n) if ($n->parentNode) $n->parentNode->removeChild($n);
}

const INLINE_TAGS = ['a','b','strong','i','em','span','br','code','sup','sub','small','mark','u','s','abbr','cite','q','time','font','#text'];

function renameNode($dom, $node, $tag) {
    $new = $dom->createElement($tag);
    while ($node->firstChild) $new->appendChild($node->firstChild);
    $node->parentNode->replaceChild($new, $node);
    return $new;
}

/** Un <div> qui ne contient que du texte est un paragraphe déguisé. */
function divsToParagraphs($dom, $xpath, $content) {
    foreach (array_reverse(iterator_to_array($xpath->query('.//div', $content))) as $div) {
        if (!$div->parentNode || $div->attributes->length) continue;
        if (trim($div->textContent) === '') continue;
        $inline = true;
        foreach ($div->childNodes as $c) {
            if ($c->nodeType === XML_COMMENT_NODE) continue;
            if (!in_array($c->nodeName, INLINE_TAGS, true)) { $inline = false; break; }
        }
        if ($inline) renameNode($dom, $div, 'p');
    }
}

/** Retire les coquilles vides laissées par le nettoyage. */
function removeEmptyNodes($xpath, $content) {
    $keep = ['img','iframe','video','br','hr','td','th'];
    for ($pass = 0; $pass < 3; $pass++) {
        $removed = 0;
        foreach (array_reverse(iterator_to_array($xpath->query('.//*', $content))) as $n) {
            if (!$n->parentNode || in_array($n->nodeName, $keep, true)) continue;
            if (trim(str_replace("\xC2\xA0", ' ', $n->textContent)) !== '') continue;
            if ($xpath->query('.//img|.//iframe|.//video', $n)->length) continue;
            $n->parentNode->removeChild($n);
            $removed++;
        }
        if (!$removed) break;
    }
}

function textToParagraphsHtml($text) {
    $out = '';
    foreach (preg_split('/\n\s*\n|\r\n\s*\r\n|\n/', trim($text)) as $para) {
        $para = trim($para);
        if ($para !== '') $out .= '<p>' . htmlspecialchars($para, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    return $out;
}

// ── Point d'entrée ──────────────────────────────────────────────────────────

/**
 * Extrait le contenu lisible d'une page.
 * Retour : ['html' => string|null, 'image' => image de partage, 'lang' => 'fr'|'en'|…]
 */
function extractArticle($raw, $pageUrl = '') {
    $out = ['html' => null, 'image' => '', 'lang' => ''];

    // Encodage déclaré dans l'en-tête de la page seulement (pas dans le texte de l'article).
    if (preg_match('/<meta[^>]+charset=["\']?\s*([a-zA-Z0-9_\-]+)/i', substr($raw, 0, 4096), $cm)) {
        $enc = strtoupper(trim($cm[1]));
        if ($enc !== 'UTF-8' && $enc !== 'UTF8') {
            $conv = @iconv($enc, 'UTF-8//TRANSLIT//IGNORE', $raw);
            if ($conv) $raw = $conv;
        }
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<meta charset="utf-8">' . $raw);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $meta = extractPageMeta($xpath);
    // Langue lue dans la source : la balise <meta> ajoutée plus haut fait ignorer <html lang> à libxml.
    if (preg_match('/<html\b[^>]*\blang=["\']?([a-zA-Z]{2})/i', substr($raw, 0, 4096), $lm)) $out['lang'] = strtolower($lm[1]);
    if ($meta['image'] !== '') $out['image'] = absoluteUrl($meta['image'], $pageUrl) ?: '';

    unwrapNoscriptImages($xpath);
    flattenPictures($dom, $xpath);
    keepVideoEmbeds($dom, $xpath);

    foreach (['script','style','noscript','object','embed','svg','canvas','template','link','meta','base',
              'button','form','input','select','textarea','nav','footer','aside','dialog'] as $tag) {
        foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $n) {
            if ($n->parentNode) $n->parentNode->removeChild($n);
        }
    }
    // Les <header> de page partent ; celui de l'article garde son chapô.
    foreach (iterator_to_array($dom->getElementsByTagName('header')) as $n) {
        if ($n->parentNode && !$xpath->query('ancestor::article', $n)->length) $n->parentNode->removeChild($n);
    }

    $content = findMainContent($xpath);
    if (!$content) return $out;

    removeNoise($xpath, $content);
    pruneLowValueNodes($xpath, $content);

    // Corps trop maigre mais JSON-LD complet (sites qui hydratent en JS) : on prend le JSON-LD.
    if (paragraphTextLen($xpath, $content) < 300 && mb_strlen($meta['ldBody']) > 600) {
        $out['html'] = textToParagraphsHtml($meta['ldBody']);
        return $out;
    }

    // Le titre est déjà affiché par le lecteur : on retire le <h1> s'il ouvre l'article.
    // (Le front retire aussi un <h2> identique au titre.)
    $firstH = $xpath->query('.//h1', $content)->item(0);
    if ($firstH) {
        $precedingText = 0;
        foreach ($xpath->query('preceding::p', $firstH) as $p) {
            if (isDescendant($p, $content)) $precedingText += mb_strlen(compactText($p->textContent));
        }
        if ($precedingText < 40 && $firstH->parentNode) $firstH->parentNode->removeChild($firstH);
    }

    // ── Images
    $seen = [];
    foreach (iterator_to_array($content->getElementsByTagName('img')) as $img) {
        if (!$img->parentNode) continue;
        $src = bestImageSrc($img);
        $src = $src !== '' ? (absoluteUrl(html_entity_decode($src, ENT_QUOTES, 'UTF-8'), $pageUrl) ?: '') : '';
        $key = canonicalMediaKey($src);

        $w = (int)$img->getAttribute('width');
        $h = (int)$img->getAttribute('height');
        $tiny = ($w > 0 && $w < 80) || ($h > 0 && $h < 80);

        if ($src === '' || $tiny || isDecorative($src) || ($key !== '' && isset($seen[$key]))) {
            // Seulement l'image : sa <figure> peut contenir la bonne (lazy + <noscript>).
            // Les coquilles vides partent avec removeEmptyNodes().
            $img->parentNode->removeChild($img);
            continue;
        }
        if ($key !== '') $seen[$key] = true;

        $alt = trim($img->getAttribute('alt'));
        foreach (iterator_to_array($img->attributes) as $attr) $img->removeAttribute($attr->name);
        $img->setAttribute('src', $src);
        $img->setAttribute('alt', $alt);
        $img->setAttribute('loading', 'lazy');
        $img->setAttribute('decoding', 'async');
        // Dimensions connues : place réservée, pas de saut pendant le chargement.
        if ($w >= 80 && $h >= 80) { $img->setAttribute('width', (string)$w); $img->setAttribute('height', (string)$h); }

        // Une image sans <figure> en gagne une ; un lien autour d'une image ne sert à rien en lecture.
        $parent = $img->parentNode;
        if ($parent->nodeName === 'a' && trim($parent->textContent) === '' && $parent->parentNode) {
            $parent->parentNode->replaceChild($img, $parent);
            $parent = $img->parentNode;
        }
        $figure = $parent->nodeName === 'figure' ? $parent : null;
        if (!$figure) {
            $figure = $dom->createElement('figure');
            $parent->insertBefore($figure, $img);
            $figure->appendChild($img);
        }
        // Petite image (logo, schéma) : affichée à sa taille, centrée, plutôt qu'étirée et floue.
        if ($w >= 80 && $w < 420) $figure->setAttribute('data-size', 'small');

        if ($figure->getElementsByTagName('figcaption')->length === 0 && mb_strlen($alt) > 20 && mb_strlen($alt) < 300) {
            $cap = $dom->createElement('figcaption');
            $cap->appendChild($dom->createTextNode($alt));
            $figure->appendChild($cap);
            $img->setAttribute('alt', '');
        }
    }

    // ── Liens
    foreach ($content->getElementsByTagName('a') as $a) {
        $href = trim($a->getAttribute('href'));
        if ($href === '' || preg_match('/^(javascript|data|vbscript):/i', $href)) { $a->removeAttribute('href'); continue; }
        if ($href[0] !== '#' && stripos($href, 'mailto:') !== 0) $a->setAttribute('href', absoluteUrl($href, $pageUrl) ?: $href);
        $a->setAttribute('target', '_blank');
        $a->setAttribute('rel', 'noopener noreferrer');
    }

    divsToParagraphs($dom, $xpath, $content);
    transformRelatedLinksToCards($dom, $xpath, $content);
    removeEmptyNodes($xpath, $content);

    // ── Attributs : liste blanche, et iframes limitées aux lecteurs vidéo connus.
    $safe = [
        'src','href','alt','title','target','rel','loading','decoding','width','height','colspan','rowspan',
        'datetime','lang','scope','start','reversed','allow','allowfullscreen','poster','controls',
        'data-size','data-embed','data-related-cards','data-related-heading','data-related-card',
        'data-open-in-reader','data-article-title','data-card-image','data-card-body','data-card-title',
        'data-card-meta','data-card-excerpt',
    ];
    foreach (iterator_to_array($xpath->query('.//*', $content)) as $node) {
        if ($node->nodeName === 'iframe' && embedVideoSrc($node->getAttribute('src')) === '') {
            if ($node->parentNode) $node->parentNode->removeChild($node);
            continue;
        }
        foreach (iterator_to_array($node->attributes) as $attr) {
            if (!in_array($attr->name, $safe, true)) $node->removeAttribute($attr->name);
        }
        if ($node->nodeName !== 'img' && $node->nodeName !== 'iframe') { $node->removeAttribute('width'); $node->removeAttribute('height'); }
    }
    foreach (iterator_to_array($content->attributes) as $attr) $content->removeAttribute($attr->name);

    $html = $dom->saveHTML($content);
    $html = preg_replace('/<p>\s*(<br\s*\/?>\s*)*<\/p>/i', '', $html);
    $html = preg_replace('/(<br\s*\/?>\s*){3,}/i', '<br><br>', $html);
    $html = preg_replace('/\n\s*\n\s*\n/', "\n\n", $html);
    $html = preg_replace('/<(p|div|h2|h3|h4)>\s*\d{1,2}\s*[—\-]\s*[^<]{2,90}\s*<\/\1>/u', '', $html);

    $out['html'] = trim((string)$html) !== '' ? $html : null;
    return $out;
}

function commonAncestor(array $nodes) {
    $chain = [];
    for ($n = $nodes[0]; $n; $n = $n->parentNode) $chain[] = $n;
    foreach ($chain as $cand) {
        $all = true;
        foreach ($nodes as $other) if ($other !== $cand && !isDescendant($other, $cand)) { $all = false; break; }
        if ($all) return $cand->nodeType === XML_ELEMENT_NODE ? $cand : null;
    }
    return null;
}

function isDescendant($node, $ancestor) {
    for ($n = $node->parentNode; $n; $n = $n->parentNode) if ($n === $ancestor) return true;
    return false;
}

/** Compatibilité : ancienne signature. */
function extractArticleHtml($raw, $base = '') {
    return extractArticle($raw, $base)['html'];
}
