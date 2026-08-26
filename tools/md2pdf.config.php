<?php
/**
 * md2pdf.config.php — MyÚčto.cz uživatelský manuál
 * ===================================================
 *
 * Lokální konfigurace pro sdílený MD2PDF engine (c:\work\MD2PDF). Sloučí všechny
 * kapitoly manuálu (manual/NNN_Nazev.md, pořadí dle manual/INDEX.md) do JEDNOHO
 * PDF — manual/manual.pdf. Nahrazuje původní tools/exportManualToPdf.php.
 *
 * Spuštění (Windows):
 *   pwsh -File tools\export-pdf.ps1
 * nebo přímo přes engine:
 *   php c:\work\MD2PDF\md2pdf.php --config=tools\md2pdf.config.php
 *
 * Cesty přes __DIR__ jsou relativní k UMÍSTĚNÍ tohoto souboru (tools/).
 */

return [

    // --- Vstup / výstup ---------------------------------------------------
    'source_dir' => __DIR__ . '/../manual',     // zdrojové .md (READ-ONLY)
    'output_dir' => __DIR__ . '/../manual',     // manual.pdf vedle zdrojů (jako dřív)
    'glob'       => '[0-9][0-9]*_*.md',          // jen číslované kapitoly (INDEX.md řídí pořadí)

    // --- COMBINE: více .md → jeden PDF ------------------------------------
    'combine' => [
        'enabled'  => true,
        'output'   => 'manual.pdf',              // jeden výsledný PDF
        'index'    => 'INDEX.md',                // pořadí kapitol; soubory mimo index na konec
        'title'    => 'MyÚčto.cz',
        'subtitle' => 'Kompletní moderní účetní systém automatizující vše od dokladů a banky po daně '
                    . 'a uzávěrku. Spojuje chytré křížové kontroly, AI, online reporting a mobilní přístup. '
                    . 'Flexibilní self-hosted řešení lze proměnit ve vlastní účetní software přizpůsobený '
                    . 'procesům, integracím i značce.',
        // Vlastní řádky titulky (HTML/odkazy povoleny; token {date} = datum generování)
        'meta_rows' => [
            ['Dokument', 'MyÚčto.cz — uživatelský manuál'],
            ['Datum',    '{date}'],
            ['Web',      '<a href="https://myucto.cz/" style="color:#fff;">myucto.cz</a>'],
            ['Vyvíjí',   '<a href="https://mywebdesign.cz/" style="color:#fff;">MyWebdesign.cz s.r.o.</a>'],
        ],
    ],

    // Stránkové zlomy: každá kapitola (H1) na nové straně, sekce (H2) plynou dál.
    'chapter_page_break' => true,
    'h2_page_break'      => false,
    // Obsah (TOC): kapitoly (H1) + sekce (H2).
    'toc_levels'         => [1, 2],

    // --- Renderer ---------------------------------------------------------
    // 'mpdf' = čistě PHP (bez Node/Chrome), vhodné i pro Docker build krok.
    'renderer' => 'mpdf',

    // --- Identita / branding ---------------------------------------------
    'author'      => 'MyWebdesign.cz s.r.o.',
    'company'     => 'MyWebdesign.cz s.r.o.',
    'brand'       => 'MyÚčto.cz',
    'doc_kind'    => 'Uživatelský manuál',
    'date_format' => 'j. n. Y',

    // --- Logo (titulka, dole uprostřed) ----------------------------------
    // Bílá knockout varianta (bez gradientového badge pozadí) — to by se na
    // fialové titulce zobrazilo jako světlejší obdélník (hlavně u 'chrome'
    // rendereru, který SVG gradient renderuje věrně; mPDF gradient zahazuje).
    'logo' => [
        'svg' => __DIR__ . '/manual-logo-cover.svg',
        'png' => null,
    ],

    // --- Texty UI (čeština) ----------------------------------------------
    'strings' => [
        'default_title' => 'MyÚčto.cz',
        'toc_title'     => 'Obsah',
        'page_label'    => 'Strana',
        'meta' => [
            'document'  => 'Dokument',
            'version'   => 'Verze',
            'date'      => 'Datum',
            'author'    => 'Autor',
            'company'   => 'Společnost',
            'generated' => 'Vygenerováno',
        ],
        'label_tip'  => 'TIP:',
        'label_note' => 'POZN:',
    ],

    // --- Klíčová slova varovného (oranžového) calloutu -------------------
    'warn_keywords' => ['⚠', 'POZOR', 'Upozorn', 'Pozor', 'Varov'],

    // --- Mermaid (manuál diagramy nepoužívá → vypnuto, žádná Node závislost) -
    'mermaid' => [
        'enabled' => false,
    ],
];
