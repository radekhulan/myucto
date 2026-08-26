<?php
declare(strict_types=1);
/**
 * Sjednotí číslování podnadpisů manuálu (H2/H3) na konvenci `NN.M` / `NN.M.K`,
 * kterou používá většina kapitol.
 *
 * Proč: nečíslované podnadpisy jsou u generických názvů („Účel", „Časté chyby",
 * „Předpoklady a oprávnění") napříč kapitolami IDENTICKÉ, takže jim mdSlug()
 * vyrobí stejnou kotvu. V HTML to znamená duplicitní `id`, v PDF se to projeví
 * jako desítky opakovaných řádků obsahu se shodným číslem stránky.
 *
 * Skript zároveň přepíše všechny odkazy na přečíslované kotvy (v manuálu i mimo
 * něj), aby po zásahu nezůstal ani jeden rozbitý.
 *
 * Použití:
 *   php tools/numberManualSubheadings.php            # dry-run, jen výpis
 *   php tools/numberManualSubheadings.php --apply    # zapíše změny
 */

$ROOT    = 'C:/inetpub/wwwroot/myucto.cz';
$APPLY   = in_array('--apply', $argv, true);
$MANUAL  = $ROOT . '/manual';

/** Shodná implementace jako mdSlug() v tools/generateManualHtml.php. */
function slug(string $s): string {
    $s = trim($s);
    $s = mb_strtolower($s, 'UTF-8');
    if (function_exists('iconv')) {
        $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($tr !== false) $s = $tr;
    }
    $s = (string) preg_replace('/[^a-z0-9\s-]/', '', $s);
    $s = (string) preg_replace('/[\s_]+/', '-', $s);
    $s = (string) preg_replace('/-+/', '-', $s);
    return trim($s, '-');
}

$files = glob($MANUAL . '/*.md') ?: [];
$files = array_values(array_filter($files, static fn ($f) => preg_match('/^\d+_/', basename($f)) === 1));
usort($files, static fn ($a, $b) => ((int) basename($a)) <=> ((int) basename($b)));

/** @var array<string, array<string,string>> file => [oldSlug => newSlug] */
$renames  = [];
$newBody  = [];
$stats    = ['files' => 0, 'headings' => 0, 'renamed' => 0];

foreach ($files as $file) {
    $chapter = (int) basename($file);
    $lines   = explode("\n", (string) file_get_contents($file));
    $h2 = 0;
    $h3 = 0;
    $inFence = false;
    $changedHere = false;

    foreach ($lines as $i => $line) {
        // Nadpisy uvnitř code fence nejsou nadpisy.
        if (preg_match('/^\s*```/', $line) === 1) {
            $inFence = !$inFence;
            continue;
        }
        if ($inFence) continue;

        if (preg_match('/^(#{2,3})\s+(.*?)\s*$/', $line, $m) !== 1) continue;

        $level = strlen($m[1]);
        $text  = $m[2];
        $stats['headings']++;

        // Existující číslo (v jakékoliv hloubce) odstřihni — pořadí určuje dokument.
        $bare = (string) preg_replace('/^\d+(?:\.\d+)*\s+/', '', $text);

        if ($level === 2) {
            $h2++;
            $h3 = 0;
            $num = $chapter . '.' . $h2;
        } else {
            if ($h2 === 0) { $h2 = 1; }   // H3 bez nadřazeného H2
            $h3++;
            $num = $chapter . '.' . $h2 . '.' . $h3;
        }

        $newText = $num . ' ' . $bare;
        if ($newText === $text) continue;

        $oldSlug = slug($text);
        $newSlug = slug($newText);
        if ($oldSlug !== '' && $newSlug !== '' && $oldSlug !== $newSlug) {
            $renames[basename($file)][$oldSlug] = $newSlug;
            $stats['renamed']++;
        }
        $lines[$i]   = $m[1] . ' ' . $newText;
        $changedHere = true;
    }

    if ($changedHere) {
        $stats['files']++;
        $newBody[$file] = implode("\n", $lines);
    }
}

printf(
    "Kapitol ke změně: %d | nadpisů prohlédnuto: %d | kotev k přesměrování: %d\n",
    $stats['files'], $stats['headings'], $stats['renamed'],
);

// Kontrola kolizí: po přečíslování musí být kotvy v rámci kapitoly unikátní
// a napříč manuálem taky (jinak by problém jen změnil podobu).
$all = [];
foreach ($newBody as $file => $body) {
    foreach (explode("\n", $body) as $line) {
        if (preg_match('/^#{2,3}\s+(.*?)\s*$/', $line, $m) === 1) {
            $all[basename($file)][] = slug($m[1]);
        }
    }
}
$collisions = 0;
foreach ($all as $f => $slugs) {
    $dupes = array_filter(array_count_values($slugs), static fn ($n) => $n > 1);
    foreach ($dupes as $s => $n) {
        echo "  KOLIZE v $f: '$s' ×$n\n";
        $collisions++;
    }
}
echo $collisions === 0 ? "Kolize kotev: žádné.\n" : "Kolize kotev: $collisions — NEAPLIKUJI.\n";
if ($collisions > 0) exit(1);

if (!$APPLY) {
    echo "\nDRY-RUN. Spusť s --apply pro zápis.\n";
    exit(0);
}

// ── zápis kapitol ────────────────────────────────────────────────────────────
foreach ($newBody as $file => $body) {
    file_put_contents($file, $body);
}

// ── přepis odkazů ────────────────────────────────────────────────────────────
// Cíle: markdown v manual/, plus kód, kde se na kotvy odkazuje napřímo.
$targets = array_merge(
    glob($MANUAL . '/*.md') ?: [],
    glob($ROOT . '/web/src/**/*.{ts,vue}', GLOB_BRACE) ?: [],
    glob($ROOT . '/api/src/**/*.php', GLOB_BRACE) ?: [],
    [$ROOT . '/README.md'],
);
$rewritten = 0;
foreach ($targets as $t) {
    if (!is_file($t)) continue;
    $src  = (string) file_get_contents($t);
    $orig = $src;

    foreach ($renames as $chapterFile => $map) {
        $stem = preg_replace('/\.md$/', '', $chapterFile);
        foreach ($map as $old => $new) {
            // Odkaz s uvedeným souborem — funguje odkudkoliv.
            $src = str_replace("$chapterFile#$old", "$chapterFile#$new", $src);
            $src = str_replace("$stem#$old", "$stem#$new", $src);
            // Holá kotva se počítá jen uvnitř té samé kapitoly.
            if (basename($t) === $chapterFile) {
                $src = (string) preg_replace('/\(#' . preg_quote($old, '/') . '\)/', "(#$new)", $src);
            }
        }
    }
    if ($src !== $orig) {
        file_put_contents($t, $src);
        $rewritten++;
    }
}
printf("Zapsáno kapitol: %d | souborů s přepsanými odkazy: %d\n", count($newBody), $rewritten);
