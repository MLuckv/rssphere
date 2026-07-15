<?php
/**
 * Extraction du contenu lisible d'une page d'article.
 * (Bloc repris du routeur d'origine, isole ici pour garder api/index.php lisible.)
 */

// ── Improved article extractor ──────────────────────────────────────────────

function isDecorative($src) {
    // Social icons, tracking pixels, avatars, share buttons, tiny images
    $patterns = [
        '/twitter\.com\/images/', '/facebook\.com\/tr/', '/linkedin\.com\/li/',
        '/instagram\.com\/static/', '/youtube\.com\/s\//', '/gravatar\.com/',
        '/1x1/', '/blank\.gif/', '/pixel\.gif/', '/spacer\.gif/', '/transparent\.png/',
        '/logo\.(png|gif|svg|ico)/i', '/icon\.(png|gif|svg|ico)/i',
        '/favicon/', '/apple-touch/', '/share/', '/social/', '/avatar/',
        '/badge/', '/button/', '/widget/', '/ads/', '/doubleclick/',
        '/google-analytics/', '/googletagmanager/', '/fb\.com\//',
        '/twimg\.com\/profile/', '/pbs\.twimg\.com\/profile/',
        '/s\.w\.org\/images/', '/emoji/', '/wp-includes\/images/',
        '/syndication\.twitter/', '/platform\.twitter/',
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

function extractArticleHtml($raw, $base = '') {
    // Encoding
    if (preg_match('/charset=["\']?\s*([a-zA-Z0-9\-]+)/i', $raw, $cm)) {
        $enc = strtoupper(trim($cm[1]));
        if ($enc !== 'UTF-8') { $conv = @iconv($enc,'UTF-8//TRANSLIT//IGNORE',$raw); if ($conv) $raw = $conv; }
    }

    $dom = new DOMDocument('1.0','UTF-8');
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<meta charset="utf-8">'.$raw);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // ── Strip useless tags entirely
    foreach (['script','style','noscript','iframe','object','embed','svg','canvas',
              'button','form','input','select','textarea','nav','footer','header',
              'aside','figure:empty'] as $tag) {
        $nodes = $dom->getElementsByTagName($tag);
        for ($i = $nodes->length-1; $i >= 0; $i--) {
            $n = $nodes->item($i); if ($n->parentNode) $n->parentNode->removeChild($n);
        }
    }

    // ── Strip by class/id noise patterns
    $noisePatterns = [
        'comment','related','sharing','social','recommend','cookie','popup','modal',
        'newsletter','subscribe','advertisement','advertise','sidebar','widget','promo',
        'taboola','outbrain','disqus','banner','paywall','overlay','interstitial',
        'header','topbar','masthead','breadcrumb','breadcrumbs','pagination','pager',
        'tag-list','tags','meta','byline','author-bio','bio','profile','avatar',
        'toc','table-of-contents','summary','abstract','pullquote','recirc',
        'signup','login','register','subscribe','follow','tweet','share','print',
        'footer','copyright','legal','disclaimer','policy','terms',
        'toolbar','menu','nav','navigation','sitemap','search',
        'advert','sponsor','partner','affiliate','promoted','native-ad',
        'reading-time','read-time','progress','reading-progress',
    ];
    foreach ($noisePatterns as $p) {
        foreach (['class','id'] as $attr) {
            $q = "//*[contains(translate(@{$attr},'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'{$p}')]";
            $nodes = $xpath->query($q);
            if ($nodes) { $rem=[]; foreach ($nodes as $n) $rem[]=$n; foreach ($rem as $n) if($n->parentNode) $n->parentNode->removeChild($n); }
        }
    }

    // ── Find best content node
    $selectors = [
        "//article[not(ancestor::aside)][not(ancestor::nav)]",
        "//*[@id='article-body']","//*[@id='article-content']","//*[@id='post-content']",
        "//*[@id='entry-content']","//*[@id='main-content']","//*[@id='story-body']",
        "//*[contains(@class,'article-body')]","//*[contains(@class,'article__body')]",
        "//*[contains(@class,'article-content')]","//*[contains(@class,'post-content')]",
        "//*[contains(@class,'entry-content')]","//*[contains(@class,'story-body')]",
        "//*[contains(@class,'article__text')]","//*[contains(@class,'articleBody')]",
        "//*[contains(@class,'post-body')]","//*[contains(@class,'content-body')]",
        "//main","//*[@role='main']","//*[@itemprop='articleBody']",
    ];
    $content = null;
    foreach ($selectors as $sel) {
        $nodes = $xpath->query($sel);
        if (!$nodes || !$nodes->length) continue;
        $best = null; $bestLen = 0;
        for ($i=0; $i<$nodes->length; $i++) {
            $n = $nodes->item($i); $len = strlen(trim($n->textContent));
            if ($len > $bestLen) { $bestLen = $len; $best = $n; }
        }
        if ($best && $bestLen > 150) { $content = $best; break; }
    }
    // Fallback: richest div
    if (!$content) {
        $divs = $xpath->query('//div'); $bestScore = 0;
        if ($divs) foreach ($divs as $div) {
            $ps = $div->getElementsByTagName('p');
            $score = $ps->length*30 + min(strlen(trim($div->textContent)),8000);
            if ($score > $bestScore) { $bestScore = $score; $content = $div; }
        }
    }
    if (!$content) return null;

    // Remove link-heavy CTA clusters and numbered section labels often found in landing pages.
    pruneLowValueNodes($xpath, $content);

    // ── Remove the first heading if it duplicates the article title (already shown in reader UI)
    $headings = $xpath->query('.//h1|.//h2', $content);
    if ($headings && $headings->length > 0) {
        $firstH = $headings->item(0);
        // Only remove if it's among the first 3 nodes of content
        $siblings = $content->childNodes; $pos = 0;
        foreach ($siblings as $child) {
            if ($child === $firstH) break;
            if ($child->nodeType === XML_ELEMENT_NODE) $pos++;
        }
        if ($pos < 3 && $firstH->parentNode) $firstH->parentNode->removeChild($firstH);
    }

    // ── Deduplicate images (keep first occurrence of each src)
    $seenSrcs = [];
    $imgs = $content->getElementsByTagName('img');
    $imgList = [];
    for ($i=0; $i<$imgs->length; $i++) $imgList[] = $imgs->item($i);

    foreach ($imgList as $img) {
        // Resolve lazy-loaded src
        $src = $img->getAttribute('src');
        if (empty($src) || strpos($src,'data:')===0 || preg_match('/1x1|blank|pixel|spacer|transparent/i',$src)) {
            foreach (['data-src','data-lazy-src','data-original','data-lazy','data-delayed-url','data-cfsrc','data-image'] as $la) {
                $lazy = $img->getAttribute($la);
                if ($lazy && preg_match('/^https?:/',$lazy)) { $src = $lazy; $img->setAttribute('src',$src); break; }
            }
        }
        // Fix relative URLs
        if ($src && !preg_match('/^https?:/',$src) && !preg_match('/^data:/',$src))
            $img->setAttribute('src', rtrim($base,'/').'/'.$src);

        $src = $img->getAttribute('src');
        $srcKey = canonicalMediaKey($src);

        // Remove decorative / social / duplicate images
        if (empty($src) || isDecorative($src) || (!empty($srcKey) && isset($seenSrcs[$srcKey]))) {
            if ($img->parentNode) {
                // If wrapped in figure, remove figure
                $parent = $img->parentNode;
                if ($parent->nodeName === 'figure' || $parent->nodeName === 'picture') {
                    if ($parent->parentNode) $parent->parentNode->removeChild($parent);
                } else {
                    $img->parentNode->removeChild($img);
                }
            }
            continue;
        }
        if (!empty($srcKey)) $seenSrcs[$srcKey] = true;

        // Check dimensions — skip tiny images (icons < 80px)
        $w = (int)$img->getAttribute('width');
        $h = (int)$img->getAttribute('height');
        if (($w > 0 && $w < 80) || ($h > 0 && $h < 80)) {
            if ($img->parentNode) $img->parentNode->removeChild($img);
            continue;
        }

        // Clean attributes
        foreach (['class','id','style','srcset','sizes','width','height',
                  'data-src','data-lazy-src','data-original','data-lazy',
                  'data-cfsrc','data-image','onclick','onload','onerror'] as $rm)
            $img->removeAttribute($rm);
        $img->setAttribute('loading','lazy');
        $img->setAttribute('alt', $img->getAttribute('alt') ?: '');

        // Wrap in figure and annotate image layout for frontend styling.
        $figure = null;
        $parent = $img->parentNode;
        if ($parent && $parent->nodeName === 'figure') {
            $figure = $parent;
        } elseif ($parent) {
            $figure = $dom->createElement('figure');
            $parent->insertBefore($figure, $img);
            $figure->appendChild($img);
        }

        if ($figure) {
            $alt = trim($img->getAttribute('alt'));
            if (!empty($alt) && mb_strlen($alt) > 20) {
                $caps = $figure->getElementsByTagName('figcaption');
                if ($caps->length === 0) {
                    $cap = $dom->createElement('figcaption');
                    $cap->textContent = $alt;
                    $figure->appendChild($cap);
                }
                $figure->setAttribute('data-has-caption', '1');
                $img->setAttribute('alt','');
            } else {
                $figure->setAttribute('data-layout', 'aside');
            }
        }
    }

    // ── Fix links
    $links = $content->getElementsByTagName('a');
    for ($i=0; $i<$links->length; $i++) {
        $a = $links->item($i);
        $href = $a->getAttribute('href');
        if ($href && !preg_match('/^https?:/',$href) && strpos($href,'#')!==0 && strpos($href,'mailto:')!==0)
            $a->setAttribute('href', rtrim($base,'/').'/'.$href);
        $a->setAttribute('target','_blank');
        $a->setAttribute('rel','noopener noreferrer');
    }

    // Turn related-link clusters into structured cards for better rendering.
    transformRelatedLinksToCards($dom, $xpath, $content);

    // ── Sanitize all attributes
    $safe = [
        'src','href','alt','title','target','rel','loading','colspan','rowspan','datetime','lang','scope',
        'data-layout','data-has-caption','data-related-cards','data-related-heading','data-related-card',
        'data-open-in-reader','data-article-title','data-card-image','data-card-body','data-card-title',
        'data-card-meta','data-card-excerpt'
    ];
    $allNodes = $xpath->query('.//*', $content);
    if ($allNodes) foreach ($allNodes as $node) {
        $attrNames = [];
        foreach ($node->attributes as $attr) $attrNames[] = $attr->name;
        foreach ($attrNames as $an) if (!in_array($an,$safe)) $node->removeAttribute($an);
    }

    // ── Serialize and clean
    $html = $dom->saveHTML($content);
    // Remove empty paragraphs
    $html = preg_replace('/<p>\s*(<br\s*\/?>\s*)*<\/p>/i', '', $html);
    // Remove multiple consecutive <br>
    $html = preg_replace('/(<br\s*\/?>\s*){3,}/i', '<br><br>', $html);
    // Remove lines that are just whitespace-text nodes
    $html = preg_replace('/\n\s*\n\s*\n/', "\n\n", $html);
    // Remove noisy section labels like "01 — What this enables" if they survived DOM pruning.
    $html = preg_replace('/<(p|div|h2|h3|h4)>\s*\d{1,2}\s*[—\-]\s*[^<]{2,90}\s*<\/\1>/u', '', $html);

    return $html ?: null;
}
