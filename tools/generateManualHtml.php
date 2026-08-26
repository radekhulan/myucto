<?php
/**
 * Generuje HTML verzi manuálu z manual/*.md.
 *
 * Výstup:
 *  - manual/generated/<NNN_Name>.html      — body fragment per kapitola
 *  - manual/generated/INDEX.html           — landing fragment (z INDEX.md)
 *  - manual/generated/_toc.php             — pole sekcí pro layout
 *  - manual/generated/img/                 — kopie WEBP obrázků
 *  - manual/generated/search-index.json    — JSON pro klientské vyhledávání
 *
 * Použití:
 *   php tools/generateManualHtml.php
 *
 * Výsledek se servíruje přes manual/index.php (route handler) na URL /manual.
 */

$root   = realpath(__DIR__ . '/..');
$srcDir = $root . '/manual';
$dstDir = $root . '/manual/generated';

if (!is_dir($srcDir)) {
    fwrite(STDERR, "Zdroj neexistuje: $srcDir\n");
    exit(1);
}
@mkdir($dstDir, 0755, true);
// generated/img/ se NEVYTVÁŘÍ — HTML referuje na /manual/img/ přímo
// (originální umístění), žádný kopírovaný duplikát.

// ============================================================================
// Mini Markdown → HTML
// ============================================================================

function mdInline(string $s): string {
    $s = htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![A-Za-z0-9])_([^_\n]+)_(?![A-Za-z0-9])/', '<em>$1</em>', $s);
    // Links: [text](url)
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) {
        $href = $m[2];
        // Přepiš odkazy na .md soubory na ?ch=NNN_Name.
        if (preg_match('~^([0-9]{2,3}[a-z]?_[^/]+)\.md(#.+)?$~i', $href, $hm)) {
            $href = '/manual?ch=' . $hm[1] . ($hm[2] ?? '');
        }
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . $m[1] . '</a>';
    }, $s);
    return $s;
}

function mdSlug(string $s): string {
    $s = strtolower(trim($s));
    if (function_exists('iconv')) {
        $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($tr !== false) $s = $tr;
    }
    $s = preg_replace('/[^a-z0-9\s-]/', '', $s);
    $s = preg_replace('/[\s_]+/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-');
}

function mdToHtml(string $md, array &$tocOut, string $imgUrlBase): string {
    $lines = preg_split('/\r\n|\r|\n/', $md);
    $html = '';
    $paragraph = [];
    $listType = null;
    $listItems = [];
    $inBlockquote = false;
    $blockquoteLines = [];
    $inTable = false;
    $tableRows = [];
    $tableAligns = [];
    $inCodeBlock = false;
    $codeBlockLines = [];

    $flushParagraph = function () use (&$paragraph, &$html) {
        if ($paragraph) {
            $html .= '<p>' . mdInline(implode(' ', $paragraph)) . "</p>\n";
            $paragraph = [];
        }
    };
    $flushList = function () use (&$listType, &$listItems, &$html) {
        if ($listType) {
            $html .= "<$listType>\n";
            foreach ($listItems as $li) {
                $html .= '  <li>' . mdInline($li) . "</li>\n";
            }
            $html .= "</$listType>\n";
            $listType = null;
            $listItems = [];
        }
    };
    // GitHub-style callouts (> [!TIP] …) → ikona + barevný box. Ostatní blockquoty beze změny.
    $CALLOUTS = [
        'TIP'       => ['tip',       'Tip',        'M9 18h6m-5 3h4M12 3a6 6 0 0 0-3.6 10.8c.6.45.94 1.17 1 1.95V16h5.2v-.25c.06-.78.4-1.5 1-1.95A6 6 0 0 0 12 3z'],
        'NOTE'      => ['note',      'Poznámka',   'M12 8h.01M11 12h1v4h1M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z'],
        'IMPORTANT' => ['important', 'Důležité',   'M11 5.88V19.24a1.76 1.76 0 0 1-3.42.59l-2.14-6.15M18 13a3 3 0 1 0 0-6M5.44 13.68A4 4 0 0 1 7 6h1.83c4.1 0 7.62-1.23 9.17-3v14c-1.55-1.77-5.07-3-9.17-3H7a4 4 0 0 1-1.56-.32z'],
        'WARNING'   => ['warning',   'Upozornění', 'M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z'],
        'CAUTION'   => ['caution',   'Pozor',      'M12 8v4m0 4h.01M4.93 4.93l14.14 14.14M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z'],
    ];
    $flushBlockquote = function () use (&$inBlockquote, &$blockquoteLines, &$html, $CALLOUTS) {
        if (!$inBlockquote) return;
        $lines = $blockquoteLines;
        $type  = null;
        if (isset($lines[0]) && preg_match('/^\s*\[!(TIP|NOTE|IMPORTANT|WARNING|CAUTION)\]\s*(.*)$/i', $lines[0], $cm)) {
            $type = strtoupper($cm[1]);
            $lines[0] = $cm[2];
            if ($lines[0] === '') array_shift($lines);
        }
        $body = mdInline(implode(' ', $lines));
        if ($type !== null && isset($CALLOUTS[$type])) {
            [$cls, $label, $svg] = $CALLOUTS[$type];
            $icon = '<svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $svg . '"/></svg>';
            $html .= '<div class="callout callout-' . $cls . '">';
            $html .= '<div class="callout-title">' . $icon . $label . '</div>';
            $html .= '<div class="callout-body"><p>' . $body . "</p></div></div>\n";
        } else {
            $html .= "<blockquote>\n<p>" . $body . "</p>\n</blockquote>\n";
        }
        $inBlockquote = false;
        $blockquoteLines = [];
    };
    $flushTable = function () use (&$inTable, &$tableRows, &$tableAligns, &$html) {
        if (!$inTable || count($tableRows) < 2) {
            $inTable = false; $tableRows = []; $tableAligns = [];
            return;
        }
        $header = $tableRows[0];
        $body = array_slice($tableRows, 1);
        $html .= "<table class=\"md-tab\">\n<thead><tr>";
        foreach ($header as $i => $cell) {
            $a = $tableAligns[$i] ?? 'left';
            $html .= '<th style="text-align:' . $a . '">' . mdInline(trim($cell)) . '</th>';
        }
        $html .= "</tr></thead>\n<tbody>\n";
        foreach ($body as $row) {
            $html .= '<tr>';
            foreach ($row as $i => $cell) {
                $a = $tableAligns[$i] ?? 'left';
                $html .= '<td style="text-align:' . $a . '">' . mdInline(trim($cell)) . '</td>';
            }
            $html .= "</tr>\n";
        }
        $html .= "</tbody></table>\n";
        $inTable = false; $tableRows = []; $tableAligns = [];
    };

    foreach ($lines as $line) {
        // Code blocks
        if (preg_match('/^```/', trim($line))) {
            if (!$inCodeBlock) {
                $flushParagraph(); $flushList(); $flushBlockquote(); $flushTable();
                $inCodeBlock = true; $codeBlockLines = [];
            } else {
                $inCodeBlock = false;
                $code = htmlspecialchars(implode("\n", $codeBlockLines), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $html .= "<pre class=\"code-block\"><code>$code</code></pre>\n";
                $codeBlockLines = [];
            }
            continue;
        }
        if ($inCodeBlock) { $codeBlockLines[] = $line; continue; }

        $trim = trim($line);

        if ($trim === '') {
            $flushParagraph(); $flushList(); $flushBlockquote(); $flushTable();
            continue;
        }

        // Horizontal rule
        if (preg_match('/^---+$/', $trim)) {
            $flushParagraph(); $flushList(); $flushBlockquote(); $flushTable();
            $html .= "<hr />\n";
            continue;
        }

        // Image
        if (preg_match('/^!\[([^\]]*)\]\(([^)]+)\)\s*$/', $trim, $m)) {
            $flushParagraph(); $flushList(); $flushBlockquote(); $flushTable();
            $alt = htmlspecialchars($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $src = $m[2];
            if (preg_match('~^img/(.+)$~i', $src, $im)) {
                $src = rtrim($imgUrlBase, '/') . '/' . $im[1];
            }
            $srcHtml = htmlspecialchars($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html .= '<figure class="fig"><img src="' . $srcHtml . '" alt="' . $alt . '" loading="lazy" />';
            if ($alt !== '') $html .= '<figcaption>' . $alt . '</figcaption>';
            $html .= "</figure>\n";
            continue;
        }

        // Headings
        if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
            $flushParagraph(); $flushList(); $flushBlockquote(); $flushTable();
            $level = strlen($m[1]);
            $rawText = $m[2];
            $text  = mdInline($rawText);
            $id    = mdSlug($rawText);
            $idAttr = $id !== '' ? ' id="' . $id . '"' : '';
            $html .= "<h$level$idAttr>$text</h$level>\n";
            if ($level === 1 || $level === 2) {
                $tocOut[] = ['level' => $level, 'text' => $rawText, 'slug' => $id];
            }
            continue;
        }

        // Blockquote
        if (preg_match('/^>\s?(.*)$/', $trim, $m)) {
            $flushParagraph(); $flushList(); $flushTable();
            $inBlockquote = true;
            $blockquoteLines[] = $m[1];
            continue;
        } else {
            $flushBlockquote();
        }

        // GFM tables
        if (strpos($trim, '|') !== false) {
            $stripped = preg_replace('/^\||\|$/', '', $trim);
            $cells = array_map('trim', explode('|', $stripped));
            $isSep = (count($cells) > 0) && !array_filter($cells, function ($c) {
                return !preg_match('/^:?-{3,}:?$/', $c);
            });
            if ($isSep && count($tableRows) === 1) {
                foreach ($cells as $c) {
                    if (preg_match('/^:.*:$/', $c))      $tableAligns[] = 'center';
                    elseif (preg_match('/^.*:$/', $c))   $tableAligns[] = 'right';
                    else                                  $tableAligns[] = 'left';
                }
                $inTable = true;
                continue;
            }
            $flushParagraph(); $flushList();
            $tableRows[] = $cells;
            $inTable = true;
            continue;
        } else {
            $flushTable();
        }

        // Lists
        if (preg_match('/^[-*]\s+(.*)$/', $trim, $m)) {
            $flushParagraph();
            if ($listType !== 'ul') { $flushList(); $listType = 'ul'; }
            $listItems[] = $m[1];
            continue;
        }
        if (preg_match('/^\d+[a-z]?\.\s+(.*)$/', $trim, $m)) {
            $flushParagraph();
            if ($listType !== 'ol') { $flushList(); $listType = 'ol'; }
            $listItems[] = $m[1];
            continue;
        }
        if ($listType && preg_match('/^\s{2,}(.*)$/', $line, $m)) {
            if ($listItems) $listItems[count($listItems) - 1] .= ' ' . trim($m[1]);
            continue;
        }

        $flushList();
        $paragraph[] = $trim;
    }

    $flushParagraph(); $flushList(); $flushBlockquote(); $flushTable();
    return $html;
}

// ============================================================================
// Parse INDEX.md → groups + chapters
// ============================================================================

function parseIndexGroups(string $indexPath): array {
    $groups = [];
    $current = null;
    if (!is_file($indexPath)) return $groups;
    foreach (file($indexPath) as $line) {
        $t = trim($line);
        if (preg_match('/^###\s+(.+)$/', $t, $m)) {
            if ($current) $groups[] = $current;
            $current = ['title' => $m[1], 'items' => []];
        } elseif (preg_match('/^\d+[a-z]?\.\s+\[([^\]]+)\]\(([^)]+\.md)\)/', $t, $m)) {
            if ($current) {
                $base = pathinfo($m[2], PATHINFO_FILENAME);
                $current['items'][] = ['title' => $m[1], 'file' => $base];
            }
        }
    }
    if ($current) $groups[] = $current;
    return $groups;
}

// ============================================================================
// Cleanup orphans
// ============================================================================

foreach (glob($dstDir . '/*.html') as $oldHtml) {
    @unlink($oldHtml);
}

// ============================================================================
// Generate chapter HTMLs
// ============================================================================

// Glob NNN[a-z]?_*.md (e.g. 01_Uvod.md, 58a_Importy.md, 999_Reseni_problemu.md)
$files = glob($srcDir . '/[0-9][0-9]*_*.md');
sort($files, SORT_STRING);

$chapters = [];
foreach ($files as $f) {
    $base = pathinfo($f, PATHINFO_FILENAME);
    $md = file_get_contents($f);
    $localToc = [];
    $body = mdToHtml($md, $localToc, '/manual/img');
    file_put_contents($dstDir . '/' . $base . '.html', $body);
    $h1 = '';
    $subs = [];
    foreach ($localToc as $t) {
        if ($t['level'] === 1 && $h1 === '') $h1 = $t['text'];
        if ($t['level'] === 2) $subs[] = $t;
    }
    $chapters[$base] = ['title' => $h1, 'sub' => $subs];
    echo "  $base.html\n";
}

// INDEX.md → INDEX.html
$indexMdPath = $srcDir . '/INDEX.md';
if (is_file($indexMdPath)) {
    $dummy = [];
    $indexHtml = mdToHtml(file_get_contents($indexMdPath), $dummy, '/manual/img');
    file_put_contents($dstDir . '/INDEX.html', $indexHtml);
}

// Groups for TOC sidebar
$groups = parseIndexGroups($indexMdPath);
foreach ($groups as &$g) {
    foreach ($g['items'] as &$it) {
        if (isset($chapters[$it['file']])) {
            $it['sub'] = $chapters[$it['file']]['sub'] ?? [];
        }
    }
}
unset($g, $it);
file_put_contents($dstDir . '/_toc.php', '<?php return ' . var_export($groups, true) . ';' . "\n");

// ============================================================================
// Search index
// ============================================================================

function stripHtmlForSearch(string $html): string {
    $text = preg_replace('/<[^>]+>/', ' ', $html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}
$searchIndex = [];
foreach ($files as $f) {
    $base = pathinfo($f, PATHINFO_FILENAME);
    $bodyHtml = file_get_contents($dstDir . '/' . $base . '.html');
    $info = $chapters[$base] ?? ['title' => $base, 'sub' => []];
    $sections = [];
    foreach ($info['sub'] as $s) {
        $sections[] = ['t' => $s['text'], 'a' => $s['slug']];
    }
    $searchIndex[] = [
        'f' => $base,
        't' => $info['title'] !== '' ? $info['title'] : $base,
        's' => $sections,
        'b' => stripHtmlForSearch($bodyHtml),
    ];
}
file_put_contents(
    $dstDir . '/search-index.json',
    json_encode($searchIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

// Pozn.: obrázky se NEKOPÍRUJÍ — HTML odkazuje na `/manual/img/...` přímo
// (originální umístění), takže duplicate v `generated/img/` by jen zabíral místo.

echo "\n";
echo "OK\n";
echo "Kapitol HTML: " . count($files) . "\n";
echo "TOC skupin: " . count($groups) . "\n";
