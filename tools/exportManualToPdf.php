<?php
/**
 * Export manual/*.md → manual/manual.pdf
 *
 * Vlastní mini markdown konverter (h1–h6, p, ul, ol, blockquote, hr,
 * obrázky, **bold**, *italic*, `code`, [link](url), GFM tabulky, fenced
 * code bloky), výstup přes mPDF.
 *
 * Konvence:
 *  - Pořadí kapitol řídí manual/INDEX.md (### skupiny + číslované odkazy
 *    [název](NNN_Name.md)). Soubory mimo INDEX.md (např. 999_Reseni_problemu)
 *    se připojí na konec.
 *  - Každá kapitola začíná na nové stránce (H1 page-break-before).
 *  - Cross-chapter linky (.md soubory) se přepisují na interní PDF anchory.
 *
 * Použití:
 *   php tools/exportManualToPdf.php
 *
 * Volá se i z Dockerfile build kroku, aby image měl PDF napečený.
 */

declare(strict_types=1);

// Manuál postupně roste (51+ kapitol) — mPDF prochází celé sloučené HTML
// regexy (AdjustHTML) a defaultní pcre.backtrack_limit (1 000 000) na to
// přestal stačit ("HTML code size is larger than pcre.backtrack_limit").
// Zvedáme jen pro tento CLI běh, ne globálně v php.ini.
ini_set('pcre.backtrack_limit', '10000000');

require __DIR__ . '/../api/vendor/autoload.php';

$root     = realpath(__DIR__ . '/..');
$srcDir   = $root . '/manual';
$dstPath  = $srcDir . '/manual.pdf';
$logoPath = $root . '/styles/logo.svg';

if (!is_dir($srcDir)) {
    fwrite(STDERR, "Zdrojový adresář neexistuje: {$srcDir}\n");
    exit(1);
}

// ---------- Mapa: NNN_Name.md → ch-NNN_name (interní PDF anchor) ----------
function chapterAnchorId(string $base): string
{
    return 'ch-' . strtolower(preg_replace('/[^A-Za-z0-9_-]/', '-', $base));
}

// ---------- Inline (bold, italic, code, links) ----------
function mdInline(string $s): string
{
    $s = htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // 1) Extrahuj `code` spany do placeholderů (aby další markdown regexy nesahaly
    //    dovnitř a neporušily obsah kódu).
    $codeStore = [];
    $s = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codeStore) {
        $i           = count($codeStore);
        $codeStore[] = $m[1];
        return "\x01CODE{$i}\x02";
    }, $s);

    // 2) **bold**
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    // 3) *italic* (single hvězdička mimo zbytek slova)
    $s = preg_replace('/(?<![\*\w])\*([^*\n]+)\*(?!\w)/', '<em>$1</em>', $s);
    // 4) _italic_ (mezi non-alphanumeric)
    $s = preg_replace('/(?<![A-Za-z0-9])_([^_\n]+)_(?![A-Za-z0-9])/', '<em>$1</em>', $s);

    // 5) [text](url) — cross-chapter .md linky přepiš na interní anchor
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) {
        $text = $m[1];
        $href = $m[2];
        if (preg_match('~^([0-9]{2,3}[a-z]?_[^/]+|README)\.md(#(.+))?$~i', $href, $hm)) {
            $href = '#' . (isset($hm[3]) ? $hm[3] : chapterAnchorId($hm[1]));
        }
        return '<a href="' . $href . '">' . $text . '</a>';
    }, $s);

    // 6) Vrať code spany zpět (bez formátování uvnitř)
    $s = preg_replace_callback('/\x01CODE(\d+)\x02/', function ($m) use ($codeStore) {
        return '<code>' . $codeStore[(int) $m[1]] . '</code>';
    }, $s);

    return $s;
}

// ---------- Slugify pro klikatelné anchor linky ----------
function mdSlug(string $s): string
{
    $s = trim($s);
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    if (function_exists('iconv')) {
        $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($tr !== false) {
            $s = $tr;
        }
    }
    $s = preg_replace('/[^a-z0-9\s-]/', '', $s);
    $s = preg_replace('/[\s_]+/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-');
}

// ---------- Markdown → HTML (line-by-line) + sběr TOC ----------
function mdToHtml(string $md, bool $firstChapter, array &$toc, string $imgBaseDir): string
{
    $lines           = preg_split('/\r\n|\r|\n/', $md);
    $html            = '';
    $paragraph       = [];
    // Nested lists — stack of ['indent' => int, 'type' => 'ul'|'ol']
    $listStack       = [];
    $pendingLiClose  = false;
    $inBlockquote    = false;
    $blockquoteLines = [];
    $inTable         = false;
    $tableRows       = [];
    $tableAligns     = [];
    $inCodeBlock     = false;
    $codeBlockLines  = [];

    $flushParagraph = function () use (&$paragraph, &$html) {
        if ($paragraph) {
            $html       .= '<p>' . mdInline(implode(' ', $paragraph)) . "</p>\n";
            $paragraph   = [];
        }
    };

    // Stack-based list emitter — podporuje nested ul/ol s libovolnou hloubkou.
    // Volá se s indent (počet leading mezer) + typem ul/ol + textem položky.
    $emitListItem = function (int $indent, string $type, string $text) use (&$listStack, &$html, &$pendingLiClose) {
        // Zavři hlubší úrovně, dokud nedostaneme top.indent <= $indent
        while ($listStack && end($listStack)['indent'] > $indent) {
            if ($pendingLiClose) {
                $html .= "</li>\n";
                $pendingLiClose = false;
            }
            $top   = array_pop($listStack);
            $html .= "</{$top['type']}>\n";
            // Po zavření vnořené listy stále existuje rodičovská <li>, kterou
            // teď uzavřeme při dalším sourozenci nebo finálním flushAllLists.
            if ($listStack) {
                $pendingLiClose = true;
            }
        }
        $top = $listStack ? end($listStack) : null;
        if ($top && $top['indent'] === $indent) {
            // Stejná hloubka
            if ($top['type'] !== $type) {
                // Typ se mění (ul ↔ ol) → zavři starou, otevři novou
                if ($pendingLiClose) {
                    $html .= "</li>\n";
                }
                array_pop($listStack);
                $html .= "</{$top['type']}>\n<{$type}>\n";
                $listStack[] = ['indent' => $indent, 'type' => $type];
            } else {
                // Sourozenec — zavři předchozí <li>
                if ($pendingLiClose) {
                    $html .= "</li>\n";
                }
            }
        } else {
            // Nový list — buď úplně první, nebo hlubší úroveň (nested)
            $html       .= "<{$type}>\n";
            $listStack[] = ['indent' => $indent, 'type' => $type];
        }
        $html         .= '  <li>' . mdInline($text);
        $pendingLiClose = true;
    };

    $flushAllLists = function () use (&$listStack, &$html, &$pendingLiClose) {
        while ($listStack) {
            if ($pendingLiClose) {
                $html .= "</li>\n";
                $pendingLiClose = false;
            }
            $top   = array_pop($listStack);
            $html .= "</{$top['type']}>\n";
            $pendingLiClose = $listStack ? true : false;
        }
        $pendingLiClose = false;
    };

    $flushList = $flushAllLists; // alias for backward-compat snippet calls
    $flushBlockquote = function () use (&$inBlockquote, &$blockquoteLines, &$html) {
        if ($inBlockquote) {
            $html             .= "<blockquote>\n";
            $html             .= '<p>' . mdInline(implode(' ', $blockquoteLines)) . "</p>\n";
            $html             .= "</blockquote>\n";
            $inBlockquote      = false;
            $blockquoteLines   = [];
        }
    };
    $flushTable = function () use (&$inTable, &$tableRows, &$tableAligns, &$html) {
        if (!$inTable || count($tableRows) < 2) {
            $inTable     = false;
            $tableRows   = [];
            $tableAligns = [];
            return;
        }
        $header = $tableRows[0];
        $body   = array_slice($tableRows, 1);
        $html  .= "<table class=\"md-tab\">\n<thead><tr>";
        foreach ($header as $i => $cell) {
            $a     = $tableAligns[$i] ?? 'left';
            $html .= '<th style="text-align:' . $a . '">' . mdInline(trim($cell)) . '</th>';
        }
        $html .= "</tr></thead>\n<tbody>\n";
        foreach ($body as $row) {
            $html .= '<tr>';
            foreach ($row as $i => $cell) {
                $a     = $tableAligns[$i] ?? 'left';
                $html .= '<td style="text-align:' . $a . '">' . mdInline(trim($cell)) . '</td>';
            }
            $html .= "</tr>\n";
        }
        $html       .= "</tbody></table>\n";
        $inTable     = false;
        $tableRows   = [];
        $tableAligns = [];
    };

    $headingCounter = 0;
    foreach ($lines as $line) {
        // Fenced code block
        if (preg_match('/^```/', trim($line))) {
            if (!$inCodeBlock) {
                $flushParagraph();
                $flushList();
                $flushBlockquote();
                $flushTable();
                $inCodeBlock    = true;
                $codeBlockLines = [];
            } else {
                $inCodeBlock = false;
                $code        = htmlspecialchars(implode("\n", $codeBlockLines), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $html       .= "<pre class=\"code-block\"><code>{$code}</code></pre>\n";
                $codeBlockLines = [];
            }
            continue;
        }
        if ($inCodeBlock) {
            $codeBlockLines[] = $line;
            continue;
        }

        $trim = trim($line);

        // Blank line → flush
        if ($trim === '') {
            $flushParagraph();
            $flushList();
            $flushBlockquote();
            $flushTable();
            continue;
        }

        // Horizontal rule
        if (preg_match('/^---+$/', $trim)) {
            $flushParagraph();
            $flushList();
            $flushBlockquote();
            $flushTable();
            $html .= "<hr />\n";
            continue;
        }

        // Image: ![alt](path)
        if (preg_match('/^!\[([^\]]*)\]\(([^)]+)\)\s*$/', $trim, $m)) {
            $flushParagraph();
            $flushList();
            $flushBlockquote();
            $flushTable();
            $alt = htmlspecialchars($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $src = $m[2];
            // Relativní cesta (img/foo.webp) → absolutní filesystem path pro mPDF
            if (!preg_match('~^([a-z]+:|/)~i', $src)) {
                $src = $imgBaseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $src);
            }
            $srcHtml = htmlspecialchars($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html   .= '<div class="fig"><img src="' . $srcHtml . '" alt="' . $alt . '" />';
            if ($alt !== '') {
                $html .= '<div class="fig-caption">' . $alt . '</div>';
            }
            $html .= "</div>\n";
            continue;
        }

        // Headings
        if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
            $flushParagraph();
            $flushList();
            $flushBlockquote();
            $flushTable();
            $level   = strlen($m[1]);
            $rawText = $m[2];
            $text    = mdInline($rawText);
            $id      = mdSlug($rawText);
            $cls = '';
            // H1 vždy začíná novou stranou — kromě úplně prvního H1 prvního
            // souboru (TOC už má page-break-after, takže další break by
            // generoval prázdnou stránku mezi TOC a první kapitolou).
            if ($level === 1 && !($firstChapter && $headingCounter === 0)) {
                $cls = ' class="pb"';
            }
            $headingCounter++;
            $idAttr = $id !== '' ? ' id="' . $id . '"' : '';
            $anchor = $id !== '' ? '<a name="' . $id . '"></a>' : '';
            $html  .= "<h{$level}{$idAttr}{$cls}>{$anchor}{$text}</h{$level}>\n";
            // TOC: H1 a H2
            if ($level === 1 || $level === 2) {
                $toc[] = ['level' => $level, 'text' => $rawText, 'slug' => $id];
            }
            continue;
        }

        // Blockquote
        if (preg_match('/^>\s?(.*)$/', $trim, $m)) {
            $flushParagraph();
            $flushList();
            $flushTable();
            $inBlockquote      = true;
            $blockquoteLines[] = $m[1];
            continue;
        } else {
            $flushBlockquote();
        }

        // GFM tabulka
        if (strpos($trim, '|') !== false) {
            $stripped = preg_replace('/^\||\|$/', '', $trim);
            $cells    = array_map('trim', explode('|', $stripped));
            $isSep    = (count($cells) > 0) && !array_filter($cells, function ($c) {
                return !preg_match('/^:?-{3,}:?$/', $c);
            });
            if ($isSep && count($tableRows) === 1) {
                foreach ($cells as $c) {
                    if (preg_match('/^:.*:$/', $c)) {
                        $tableAligns[] = 'center';
                    } elseif (preg_match('/^.*:$/', $c)) {
                        $tableAligns[] = 'right';
                    } else {
                        $tableAligns[] = 'left';
                    }
                }
                $inTable = true;
                continue;
            }
            $flushParagraph();
            $flushList();
            $tableRows[] = $cells;
            $inTable     = true;
            continue;
        } else {
            $flushTable();
        }

        // Unordered nebo nested list: leading whitespace + - / *
        if (preg_match('/^(\s*)[-*]\s+(.*)$/', $line, $m)) {
            $flushParagraph();
            $indent = strlen($m[1]);
            $emitListItem($indent, 'ul', $m[2]);
            continue;
        }

        // Ordered list (akceptuje i 15a. styl) + nested
        if (preg_match('/^(\s*)\d+[a-z]?\.\s+(.*)$/', $line, $m)) {
            $flushParagraph();
            $indent = strlen($m[1]);
            $emitListItem($indent, 'ol', $m[2]);
            continue;
        }

        // Pokračování list itemu (indentovaný text bez bullet/number)
        if ($listStack && $pendingLiClose && preg_match('/^\s{2,}(.*)$/', $line, $m)) {
            $html .= ' ' . mdInline(trim($m[1]));
            continue;
        }

        // Běžný odstavec
        $flushAllLists();
        $paragraph[] = $trim;
    }

    $flushParagraph();
    $flushList();
    $flushBlockquote();
    $flushTable();

    return $html;
}

// ---------- Pořadí kapitol z INDEX.md ----------
function parseIndexOrder(string $indexPath): array
{
    $groups  = [];
    $current = null;
    if (!is_file($indexPath)) {
        return $groups;
    }
    foreach (file($indexPath) as $line) {
        $t = trim($line);
        if (preg_match('/^###\s+(.+)$/', $t, $m)) {
            if ($current) {
                $groups[] = $current;
            }
            $current = ['title' => $m[1], 'items' => []];
        } elseif (preg_match('/^\d+[a-z]?\.\s+\[([^\]]+)\]\(([^)]+\.md)\)/', $t, $m)) {
            if ($current) {
                $base               = pathinfo($m[2], PATHINFO_FILENAME);
                $current['items'][] = ['title' => $m[1], 'file' => $base];
            }
        }
    }
    if ($current) {
        $groups[] = $current;
    }
    return $groups;
}

// ---------- Najdi soubory ----------
$allFiles = glob($srcDir . '/[0-9][0-9]*_*.md');
usort($allFiles, fn ($a, $b) => strnatcmp(basename($a), basename($b)));

$groups          = parseIndexOrder($srcDir . '/INDEX.md');
$orderedBases    = [];
foreach ($groups as $g) {
    foreach ($g['items'] as $it) {
        $orderedBases[] = $it['file'];
    }
}

// Doplň soubory, které nejsou v INDEX.md (např. 999_*) na konec
$allBases   = array_map(fn ($f) => pathinfo($f, PATHINFO_FILENAME), $allFiles);
$missing    = array_diff($allBases, $orderedBases);
foreach ($missing as $base) {
    $orderedBases[] = $base;
}

// ---------- Zpracuj kapitoly ----------
$body  = '';
$toc   = [];
$first = true;
foreach ($orderedBases as $base) {
    $f = $srcDir . '/' . $base . '.md';
    if (!is_file($f)) {
        continue;
    }
    $md = file_get_contents($f);
    // Před kapitolu vlož unikátní anchor pro cross-chapter linky
    $body .= '<a name="' . chapterAnchorId($base) . '"></a>';
    $body .= mdToHtml($md, $first, $toc, $srcDir);
    $first = false;
}

// ---------- Emoji → textové ekvivalenty (DejaVu nemá emoji glyfy) ----------
$emojiMap = [
    '💡'  => '<strong class="lbl lbl-tip">TIP:</strong>',
    '🛈'  => '<strong class="lbl lbl-info">POZN:</strong>',
    'ℹ️' => '<strong class="lbl lbl-info">POZN:</strong>',
    'ℹ'  => '<strong class="lbl lbl-info">POZN:</strong>',
    '⚠️' => '<strong class="lbl lbl-warn">POZOR:</strong>',
    '⚠'  => '<strong class="lbl lbl-warn">POZOR:</strong>',
    '✅' => '✓',
    '❌' => '✗',
    '📥' => '⬇',
    '📦' => '[balík]',
    '🌐' => '[web]',
    '⭐' => '★',
    '🟢' => '<span class="dot dot-green">●</span>',
    '🟡' => '<span class="dot dot-amber">●</span>',
    '🔴' => '<span class="dot dot-red">●</span>',
    '⚪' => '<span class="dot dot-gray">○</span>',
    '↩'  => '←',
];
$body = strtr($body, $emojiMap);

// Striktní očista zbylých „pictographic" emoji (DejaVu nemá glyfy pro Unicode 1F000+)
$body = preg_replace_callback('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]/u', function ($m) {
    $keep = ['⚠', '✓', '✗', '←', '→', '↑', '↓', '⬇', '★', '●', '○'];
    return in_array($m[0], $keep, true) ? $m[0] : '';
}, $body);

// ---------- TOC HTML ----------
$tocHtml = '<div class="toc"><h1 class="toc-title">Obsah</h1>' . "\n";
foreach ($toc as $item) {
    $cls   = $item['level'] === 1 ? 'toc-h1' : 'toc-h2';
    $text  = htmlspecialchars($item['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $tocHtml .= '<div class="' . $cls . '"><a href="#' . $item['slug'] . '">' . $text . '</a></div>' . "\n";
}
$tocHtml .= "</div>\n";

// ---------- Titulní strana ----------
$today    = date('j. n. Y');
$logoSrc  = is_file($logoPath) ? htmlspecialchars($logoPath, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
$logoTag  = $logoSrc !== '' ? '<img src="' . $logoSrc . '" alt="MyÚčto.cz logo" />' : '';

$cover = <<<HTML
<div class="cover">
  <div class="cover-inner">
    <div class="cover-logo">{$logoTag}</div>
    <div class="cover-eyebrow">Uživatelský manuál</div>
    <h1 class="cover-title">MyÚčto.cz</h1>
    <div class="cover-subtitle">Český účetní systém pro freelancery,<br/>OSVČ a malé firmy — instalace, vystavování<br/>dokladů, banka, účetnictví, sklad, exporty, multi-supplier</div>
    <table class="cover-meta" cellspacing="0" cellpadding="0">
      <tr><td class="cover-meta-label">Dokument</td><td class="cover-meta-value">MyÚčto.cz — uživatelský manuál</td></tr>
      <tr><td class="cover-meta-label">Datum</td><td class="cover-meta-value">{$today}</td></tr>
      <tr><td class="cover-meta-label">Web</td><td class="cover-meta-value"><a href="https://myucto.cz/" class="cover-link">myucto.cz</a></td></tr>
      <tr><td class="cover-meta-label">GitHub</td><td class="cover-meta-value"><a href="https://github.com/radekhulan/myucto" class="cover-link">github.com/radekhulan/myucto</a></td></tr>
      <tr><td class="cover-meta-label">Vyvíjí</td><td class="cover-meta-value"><a href="https://mywebdesign.cz/" class="cover-link">MyWebdesign.cz s.r.o.</a></td></tr>
    </table>
  </div>
</div>
HTML;

// ---------- CSS — MyÚčto purple branding ----------
$css = <<<CSS
body {
  font-family: montserrat, sans-serif;
  font-size: 10.5pt;
  color: #1f2937;
  line-height: 1.55;
}

/* Cover — dark purple s gradient-like vrstvením */
.cover {
  height: 245mm;
  background: #4c1d95;
  color: #fff;
  padding: 22mm 18mm 14mm 18mm;
  page-break-after: always;
}
.cover-inner {
  border-top: 4px solid #ede9fe;
  padding-top: 10mm;
}
.cover-logo {
  margin-bottom: 10mm;
  text-align: left;
}
.cover-logo img {
  width: 24mm;
  height: 24mm;
}
.cover-eyebrow {
  font-size: 10pt;
  letter-spacing: 4px;
  text-transform: uppercase;
  color: #c4b5fd;
  margin-bottom: 6mm;
}
.cover-title {
  font-size: 36pt;
  font-weight: 700;
  line-height: 1.1;
  color: #fff;
  margin: 0 0 10mm 0;
  padding: 0;
  border: none;
}
.cover-subtitle {
  font-size: 12pt;
  color: #e9d5ff;
  line-height: 1.5;
  margin-bottom: 18mm;
}
.cover-meta {
  font-size: 9pt;
  color: #e9d5ff;
  width: 100%;
  border-collapse: collapse;
}
.cover-meta td {
  padding: 6pt 0;
  border-top: 1px solid rgba(255,255,255,0.25);
  vertical-align: middle;
}
.cover-meta tr:last-child td {
  border-bottom: 1px solid rgba(255,255,255,0.25);
}
.cover-meta-label {
  width: 38mm;
  color: #c4b5fd;
  letter-spacing: 2px;
  text-transform: uppercase;
  font-size: 8pt;
}
.cover-meta-value {
  padding-left: 6mm;
}
.cover-link {
  color: #fff;
  text-decoration: underline;
}

/* TOC */
.toc {
  page-break-after: always;
}
.toc-title {
  color: #4c1d95;
  font-size: 22pt;
  margin: 8mm 0 8mm 0;
  padding-bottom: 3mm;
  border-bottom: 2px solid #6c5ce7;
}
.toc-h1 {
  margin: 5mm 0 1mm 0;
  font-size: 11.5pt;
  font-weight: 700;
}
.toc-h1 a {
  color: #4c1d95;
  text-decoration: none;
}
.toc-h2 {
  margin: 1mm 0 1mm 6mm;
  font-size: 10pt;
  color: #4b5563;
}
.toc-h2 a {
  color: #4b5563;
  text-decoration: none;
}

/* Headings */
h1, h2, h3, h4, h5, h6 {
  color: #4c1d95;
  font-weight: 700;
  line-height: 1.25;
  page-break-after: avoid;
}
h1 {
  font-size: 22pt;
  margin: 8mm 0 5mm 0;
  padding-bottom: 3mm;
  border-bottom: 2px solid #6c5ce7;
}
h1.pb { page-break-before: always; }
h2 {
  font-size: 16pt;
  margin: 10mm 0 3mm 0;
  padding-bottom: 2mm;
  border-bottom: 1px solid #d1d5db;
}
h3 {
  font-size: 12.5pt;
  margin: 7mm 0 2mm 0;
  color: #5b21b6;
}
h4, h5, h6 {
  font-size: 11pt;
  margin: 5mm 0 2mm 0;
  color: #6d28d9;
}

p {
  margin: 0 0 3mm 0;
  text-align: justify;
}

ul, ol {
  margin: 0 0 4mm 0;
  padding-left: 6mm;
}
li {
  margin-bottom: 1.5mm;
  line-height: 1.5;
}

strong { color: #4c1d95; }
em { font-style: italic; }

code {
  font-family: geistmono, monospace;
  font-size: 9pt;
  background: #f3f0ff;
  border: 1px solid #e0d7fa;
  border-radius: 2pt;
  padding: 0 4pt;
  color: #5b21b6;
}

a {
  color: #6c5ce7;
  text-decoration: underline;
}

blockquote {
  margin: 4mm 0;
  padding: 3mm 5mm;
  background: #f3f0ff;
  border-left: 3pt solid #6c5ce7;
  color: #4c1d95;
}
blockquote p { margin: 0; }

hr {
  border: none;
  border-top: 1px solid #d1d5db;
  margin: 6mm 0;
}

/* Tabulky */
table.md-tab {
  border-collapse: collapse;
  width: 100%;
  margin: 3mm 0 5mm 0;
  font-size: 9.5pt;
  page-break-inside: avoid;
}
table.md-tab th {
  background: #4c1d95;
  color: #fff;
  font-weight: 700;
  padding: 2mm 3mm;
  border: 0.5pt solid #4c1d95;
}
table.md-tab td {
  padding: 1.8mm 3mm;
  border: 0.5pt solid #d1d5db;
  vertical-align: top;
}
table.md-tab tr:nth-child(even) td {
  background: #faf8ff;
}

/* Figures */
.fig {
  margin: 4mm 0 5mm 0;
  page-break-inside: avoid;
  text-align: center;
}
.fig img {
  max-width: 100%;
  max-height: 126mm;
  border: 1pt solid #d1d5db;
  border-radius: 3pt;
  padding: 2mm;
  background: #fff;
  box-shadow: 0 0 4pt rgba(0,0,0,0.06);
}
.fig-caption {
  margin-top: 2mm;
  font-size: 8.5pt;
  color: #6b7280;
  font-style: italic;
}

/* Štítky pro emoji-substituty */
.lbl {
  padding: 0 4pt;
  border-radius: 2pt;
  font-size: 9pt;
}
.lbl-tip  { color: #6c5ce7; }
.lbl-info { color: #4c1d95; }
.lbl-warn { color: #c62828; }

/* Stavové puntíky */
.dot { font-size: 11pt; line-height: 1; }
.dot-green { color: #16a34a; }
.dot-amber { color: #d97706; }
.dot-red   { color: #dc2626; }
.dot-gray  { color: #9ca3af; }

/* Fenced code bloky */
pre.code-block {
  background: #1e1e2e;
  color: #cdd6f4;
  border-radius: 3pt;
  padding: 3mm 4mm;
  margin: 3mm 0 4mm 0;
  font-family: geistmono, monospace;
  font-size: 9pt;
  line-height: 1.45;
  page-break-inside: avoid;
  white-space: pre;
}
pre.code-block code {
  background: transparent;
  border: none;
  padding: 0;
  color: inherit;
  font-family: inherit;
  font-size: inherit;
}
CSS;

$html = $cover . $tocHtml . $body;

// ---------- mPDF ----------
$tmpDir = sys_get_temp_dir() . '/mpdf-myinvoice';
@mkdir($tmpDir, 0755, true);

// Stejné fonty jako faktury: Montserrat (text) + Geist Mono (kód), DejaVu Sans
// jen jako backupSubsFont pro symboly. PDF/A pro manuál nezapínáme (jen font klíče).
$fontOpts = \MyInvoice\Service\Pdf\MpdfFontConfig::options();
unset($fontOpts['PDFA'], $fontOpts['PDFAversion'], $fontOpts['PDFAauto']);

$mpdf = new \Mpdf\Mpdf(array_merge([
    'mode'              => 'utf-8',
    'format'            => 'A4',
    'margin_left'       => 18,
    'margin_right'      => 18,
    'margin_top'        => 22,
    'margin_bottom'     => 24,
    'margin_header'     => 8,
    'margin_footer'     => 10,
    'default_font_size' => 10.5,
    'tempDir'           => $tmpDir,
], $fontOpts));

$mpdf->SetTitle('MyÚčto.cz — uživatelský manuál');
$mpdf->SetAuthor('MyWebdesign.cz s.r.o.');
$mpdf->SetSubject('MyÚčto.cz — účetní systém, uživatelská dokumentace');
$mpdf->SetCreator('MyÚčto.cz exportManualToPdf.php');

$mpdf->defaultfooterline = 0;

$mpdf->SetHTMLHeader(
    '<div style="border-bottom:1px solid #d1d5db;padding-bottom:2mm;color:#6b7280;font-size:8pt;">'
    . '<span style="color:#4c1d95;font-weight:700;">MyÚčto.cz</span>'
    . ' &nbsp;·&nbsp; Uživatelský manuál'
    . '</div>'
);
$mpdf->SetHTMLFooter(
    '<div style="font-size:8pt;color:#6b7280;border-top:0.3pt solid #d1d5db;padding-top:2mm;">'
    . '<a href="https://mywebdesign.cz/" style="color:#4c1d95;font-weight:700;text-decoration:none;">MyWebdesign.cz s.r.o.</a>'
    . ' &nbsp;·&nbsp; Strana {PAGENO} / {nbpg}'
    . ' &nbsp;·&nbsp; <a href="https://myucto.cz/" style="color:#6c5ce7;text-decoration:none;">myucto.cz</a>'
    . '</div>'
);

$mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
$mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

$mpdf->Output($dstPath, \Mpdf\Output\Destination::FILE);

$kb = number_format(filesize($dstPath) / 1024, 1);
echo "OK: {$dstPath} ({$kb} kB, " . count($orderedBases) . " kapitol, " . count($toc) . " TOC položek)\n";
