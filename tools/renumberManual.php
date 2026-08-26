<?php
/**
 * Přečísluje kapitoly v `manual/*.md` na souvislou řadu, přepíše headings, §-refy
 * a cross-linky v MD souborech, INDEXu, manual/README.md a rootovém README.md.
 *
 * Logika číslování:
 *  - Sort podle natural sort filename (`05_*`, `05a_*`, `06_*`, …).
 *  - Sekvenčně přečísluje na 01..NNN; FAQ s prefixem 999 zůstává na konci.
 *  - Headings `# N. Title`, `## N.X.Y …` i in-text `§ N.X` se přepíší na nová čísla.
 *  - Anchory `(file.md#NNN-...)` se přepíší (chapter prefix v anchor slugu).
 *  - Apply se zablokuje, pokud by rozbil písmennou rodinu nebo vytvořil kolizi čísla.
 *
 * Použití:
 *   php tools/renumberManual.php           — DRY RUN, vypíše plánované změny
 *   php tools/renumberManual.php --apply   — provede přejmenování + edits
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$manualDir = $root . '/manual';
$dryRun = !in_array('--apply', $argv, true);

if (!is_dir($manualDir)) {
    fwrite(STDERR, "Manual directory not found: $manualDir\n");
    exit(1);
}

// 1. Discover MD files matching NNN[a-z]?_*.md
$files = glob($manualDir . '/[0-9][0-9]*.md') ?: [];
usort($files, fn ($a, $b) => strnatcmp(basename($a), basename($b)));

// 2. Build rename map
$nextNum = 1;
$map = [];  // old_basename => ['new_base', 'old_label', 'new_label']
foreach ($files as $f) {
    $base = basename($f);
    if (!preg_match('/^(\d+[a-z]*)_(.+)\.md$/i', $base, $m)) continue;
    $oldPrefix = $m[1];
    $stem = $m[2];
    // FAQ má file prefix '999' i label '999.' a drží si rezervní koncovou pozici
    // nezávisle na počtu regulérních kapitol před ní.
    if ($oldPrefix === '999') {
        $newPrefix = '999';
    } else {
        $newPrefix = sprintf('%02d', $nextNum++);
    }
    // Old label = chapter číslo z H1 (v textu) — může se lišit od file prefixu
    // (např. `13a_Importy.md` má `# 14.`). Pro rewrite headingů, §-refů a cross-link labels
    // používáme tu hodnotu, která reálně v textu/INDEXu figuruje.
    $h1Label = preg_match('/^#\s+(\d+[a-z]*)\./mu', file_get_contents($f), $hm) ? $hm[1] : (ltrim($oldPrefix, '0') ?: '0');
    $map[$base] = [
        'new_base'   => $newPrefix . '_' . $stem . '.md',
        'old_prefix' => $oldPrefix,
        'new_prefix' => $newPrefix,
        'old_label'  => $h1Label,
        'new_label'  => ltrim($newPrefix, '0') ?: '0',
    ];
}

// Safety guard: stabilní písmenné rodiny (např. 58a–58s) nesmějí být tiše
// rozbaleny na samostatná čísla a rezervované číslo 999 nesmí dostat další kapitola.
// Dry run problém ukáže, --apply skončí dřív, než se dotkne jediného souboru.
$guardProblems = [];
$flattenedFamilies = [];
$targetsByPrefix = [];
foreach ($map as $old => $info) {
    if (preg_match('/^\d+[a-z]+$/i', $info['old_prefix']) && $info['old_prefix'] !== $info['new_prefix']) {
        $flattenedFamilies[] = $info['old_prefix'];
    }
    $targetsByPrefix[$info['new_prefix']][] = $old;
}
if ($flattenedFamilies !== []) {
    $guardProblems[] = 'písmenné kapitoly by ztratily stabilní příponu: ' . implode(', ', $flattenedFamilies);
}
foreach ($targetsByPrefix as $prefix => $owners) {
    if (count($owners) > 1) {
        $guardProblems[] = "cílové číslo $prefix má více kapitol: " . implode(', ', $owners);
    }
}
if ($guardProblems !== []) {
    $message = "BEZPEČNOSTNÍ BLOKACE PŘEČÍSLOVÁNÍ:\n- "
        . implode("\n- ", $guardProblems)
        . "\nNástroj nic nezměnil. Stabilní písmenné rodiny je nutné zachovat a cílová čísla musí být jednoznačná.\n\n";
    fwrite(STDERR, $message);
    if (!$dryRun) {
        exit(2);
    }
}

echo ($dryRun ? "DRY RUN (no files modified). Use --apply to commit.\n" : "APPLY MODE — files will be renamed and edited.\n") . "\n";
echo str_pad('Old', 32) . " →  New\n";
echo str_repeat('-', 64) . "\n";
foreach ($map as $old => $info) {
    $changed = ($old !== $info['new_base']);
    printf("%-32s →  %s%s\n", $old, $info['new_base'], $changed ? '   *' : '');
}
echo "\n";

// 3. Process each manual MD file: rewrite + rename
foreach ($map as $old => $info) {
    $oldPath = $manualDir . '/' . $old;
    if (!file_exists($oldPath)) continue;
    $orig = file_get_contents($oldPath);
    $content = rewriteContent($orig, $info, $map);
    $newPath = $manualDir . '/' . $info['new_base'];

    $changes = [];
    if ($content !== $orig) $changes[] = 'content';
    if ($newPath !== $oldPath) $changes[] = 'renamed';
    if (empty($changes)) continue;

    if (!$dryRun) {
        file_put_contents($newPath, $content);
        if ($newPath !== $oldPath) unlink($oldPath);
    }
    echo "  · " . $old . " (" . implode(', ', $changes) . ")\n";
}

// 4. Update auxiliary files (INDEX.md, manual/README.md, root README.md)
processAuxFile($manualDir . '/INDEX.md',  $map, $dryRun, true);
processAuxFile($manualDir . '/README.md', $map, $dryRun, true);
processAuxFile($root . '/README.md',      $map, $dryRun, false);

echo "\n" . ($dryRun
    ? "Dry run complete. Run with --apply to actually rename."
    : "Done. Don't forget to regenerate:\n  php tools/generateManualHtml.php\n  php tools/exportManualToPdf.php"
) . "\n";

// ============================================================================
// Helpers

/**
 * Přepíše obsah jedné kapitoly: vlastní headings + linky/refy na ostatní kapitoly.
 */
function rewriteContent(string $content, array $self, array $map): string {
    $oldL = preg_quote($self['old_label'], '/');
    $newL = $self['new_label'];

    // H1: `# 5a. Title` → `# 6. Title`
    $content = preg_replace('/^# ' . $oldL . '\./mu', '# ' . $newL . '.', $content);

    // H2..H6 s číslem sekce: `## 5a.4 Title` → `## 6.4 Title`, vč. víceúrovňových `5a.4.1`
    $content = preg_replace('/^(##+) ' . $oldL . '(\.\d+)/mu', '$1 ' . $newL . '$2', $content);

    // In-text §-ref: `§ 5a.4` → `§ 6.4`
    $content = preg_replace('/§\s*' . $oldL . '(\.\d+)/u', '§ ' . $newL . '$1', $content);

    // Cross-linky na ostatní kapitoly (i sebe-link, kdyby existoval)
    foreach ($map as $obase => $oi) {
        $content = rewriteLinksToFile($content, $obase, $oi);
    }

    // Same-file (in-page) kotvy `](#NNN-...)` — odkaz uvnitř TÉŽE kapitoly nemá název
    // souboru, takže ho rewriteLinksToFile nechytí, ale číslo kapitoly je v kotvě zapečené
    // (např. `3.8` → `#38-...`). Přečíslujeme prefix kotvy podle vlastního labelu.
    $oldAnchorPrefix = strtolower(preg_replace('/[^a-z0-9]/i', '', $self['old_label']));
    $newAnchorPrefix = strtolower(preg_replace('/[^a-z0-9]/i', '', $self['new_label']));
    if ($oldAnchorPrefix !== '' && $oldAnchorPrefix !== $newAnchorPrefix) {
        // `](#26` i `](#26-`/`](#268-…` (sekce) — leading číslo kapitoly nahradíme.
        // Lookahead [-\d] zajistí, že měníme jen prefix kotvy s číslem kapitoly.
        $content = preg_replace(
            '/\]\(#' . preg_quote($oldAnchorPrefix, '/') . '(?=[-\d])/u',
            '](#' . $newAnchorPrefix,
            $content
        );
    }

    return $content;
}

/**
 * Přepíše všechny markdown linky `[label](path[#anchor])` cílící na konkrétní kapitolu.
 */
function rewriteLinksToFile(string $content, string $oldBase, array $oi): string {
    $oldBaseQ = preg_quote($oldBase, '/');
    $oldL = preg_quote($oi['old_label'], '/');
    $newBase = $oi['new_base'];
    $newL = $oi['new_label'];

    // a) Path replacement uvnitř `(file.md...)` vč. anchor rewrite.
    //    Volitelný path prefix (např. `manual/`) v rootu README.md.
    $content = preg_replace_callback(
        '/\(((?:[\w.\/-]+\/)?)' . $oldBaseQ . '(#[^)]+)?\)/u',
        function ($m) use ($oi, $newBase) {
            $prefix = $m[1] ?? '';
            $anchor = $m[2] ?? '';
            if ($anchor !== '' && $oi['old_label'] !== $oi['new_label']) {
                $oldAnchorPrefix = strtolower(preg_replace('/[^a-z0-9]/i', '', $oi['old_label']));
                $newAnchorPrefix = strtolower(preg_replace('/[^a-z0-9]/i', '', $oi['new_label']));
                if ($oldAnchorPrefix !== '' && $oldAnchorPrefix !== $newAnchorPrefix) {
                    $anchor = preg_replace(
                        '/^#' . preg_quote($oldAnchorPrefix, '/') . '(\d*)/',
                        '#' . $newAnchorPrefix . '$1',
                        $anchor
                    );
                }
            }
            return '(' . $prefix . $newBase . $anchor . ')';
        },
        $content
    );

    if ($oi['old_label'] === $oi['new_label']) return $content;

    $newBaseQ = preg_quote($newBase, '/');

    // b) Label `[5a. Title](newpath)` → `[6. Title](newpath)`
    $content = preg_replace(
        '/\[' . $oldL . '\.(\s+[^\]]+)\]\((' . $newBaseQ . '(?:#[^)]*)?)\)/u',
        '[' . $newL . '.$1]($2)',
        $content
    );

    // c) `[§ 5a.X.Y Title](newpath)` → `[§ 6.X.Y Title](newpath)`
    $content = preg_replace(
        '/\[§\s*' . $oldL . '((?:\.\d+)+)([^\]]*)\]\((' . $newBaseQ . '(?:#[^)]*)?)\)/u',
        '[§ ' . $newL . '$1$2]($3)',
        $content
    );

    return $content;
}

/**
 * Updatne pomocný soubor (INDEX, README) — replace path + (volitelně) přepíše label čísla.
 */
function processAuxFile(string $path, array $map, bool $dryRun, bool $rewriteLabels): void {
    if (!file_exists($path)) return;
    $orig = file_get_contents($path);
    $content = $orig;

    foreach ($map as $obase => $oi) {
        $content = rewriteLinksToFile($content, $obase, $oi);

        if (!$rewriteLabels) continue;
        if ($oi['old_label'] === $oi['new_label']) continue;

        $oldL = preg_quote($oi['old_label'], '/');
        $newBaseQ = preg_quote($oi['new_base'], '/');
        // INDEX.md numbered list — PATH-AWARE, jinak by cascade přepisovalo cizí řádky
        // (`5a. [Fakturujeme...](06_Fakturujeme.md)` → `6. [Fakturujeme...](...)`).
        $content = preg_replace(
            '/^(\s*)' . $oldL . '(\.\s+\[[^\]]*\]\(' . $newBaseQ . '(?:#[^)]*)?\))/mu',
            '${1}' . $oi['new_label'] . '${2}',
            $content
        );
    }

    if ($content === $orig) return;

    if (!$dryRun) {
        file_put_contents($path, $content);
    }
    echo "  · " . str_replace($GLOBALS['root'] . DIRECTORY_SEPARATOR, '', $path) . " (updated)\n";
}
