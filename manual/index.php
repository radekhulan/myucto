<?php
/**
 * MyÚčto.cz — HTML manuál (route handler).
 *
 * URL: /manual                    → INDEX.html (rozcestník)
 * URL: /manual?ch=01_Uvod         → kapitola
 * URL: /manual?ch=01_Uvod#12-vystavovani-dokladu → kapitola se skokem na sekci
 *
 * Bez auth (manuál je veřejný — není v něm citlivý obsah; pokud chceš
 * auth-gate, doplň session check níže).
 *
 * Vyžaduje vygenerovaný obsah v manual/generated/ (php tools/generateManualHtml.php).
 * Vzhled: manual.css — design tokeny zrcadlí aplikaci (web/src/styles/main.css),
 * light/dark sdílí localStorage klíč `myinvoice-color-scheme` s aplikací.
 */

declare(strict_types=1);

// HTML nechceme cachovat (jinak drží starý ?v= odkaz na CSS); samotné CSS
// se cachuje normálně — verzi řídí query param z filemtime.
header('Cache-Control: no-cache');

$dir     = __DIR__ . '/generated';
$tocFile = $dir . '/_toc.php';
$ch      = isset($_GET['ch']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_GET['ch']) : '';

if (!is_file($tocFile)) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Manuál</title>';
    echo '<h1>Manuál není zatím vygenerovaný.</h1>';
    echo '<p>Spusť:</p>';
    echo '<pre><code>php tools/generateManualHtml.php' . "\n" . 'php tools/exportManualToPdf.php</code></pre>';
    exit;
}

$groups = require $tocFile;

// Resolve aktuální kapitolu
$bodyHtml    = '';
$activeFile  = '';
$activeTitle = 'MyÚčto.cz — manuál';

if ($ch !== '') {
    $f = $dir . '/' . $ch . '.html';
    if (is_file($f)) {
        $bodyHtml   = file_get_contents($f);
        $activeFile = $ch;
        // Najdi titulek z _toc
        foreach ($groups as $g) {
            foreach ($g['items'] as $it) {
                if ($it['file'] === $ch) { $activeTitle = $it['title'] . ' — manuál'; break 2; }
            }
        }
    }
}

if ($bodyHtml === '') {
    // Default landing
    $indexFile  = $dir . '/INDEX.html';
    $bodyHtml   = is_file($indexFile) ? file_get_contents($indexFile) : '<h1>Manuál</h1>';
    $activeFile = 'INDEX';
}

$isLanding = $activeFile === 'INDEX';

// Verze aplikace (root VERSION) — pro patičku sidebaru
$versionFile = dirname(__DIR__) . '/VERSION';
$version     = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : '';

// Accent barvy skupin TOC — pozičně mapované na skupiny v INDEX.md tak, aby
// barvy sekcí odpovídaly menu aplikace (AppLayout ACCENT_CLASSES): Prodej=primary,
// Nákup=warning, Finance=success, Dokumenty=neutral, Daně=danger, Systém=neutral.
// Úvodní blok (Instalace a start) = sky; Systém = indigo, Reference = stone
// (vlastní odstíny, aby se nekryly s neutrální Dokumenty).
// Stejné pořadí drží i pilulky landing page (manual.css .landing h3:nth-of-type).
$ACCENTS = ['sky', 'primary', 'warning', 'success', 'neutral', 'danger', 'indigo', 'stone'];

// Pager: předchozí / další kapitola (flatten TOC)
$flat = [];
foreach ($groups as $g) {
    foreach ($g['items'] as $it) { $flat[] = $it; }
}
$prevCh = $nextCh = null;
if (!$isLanding) {
    foreach ($flat as $i => $it) {
        if ($it['file'] === $activeFile) {
            $prevCh = $flat[$i - 1] ?? null;
            $nextCh = $flat[$i + 1] ?? null;
            break;
        }
    }
}

/** Číslo kapitoly ze jména souboru (01_Uvod → 01, 13a_… → 13a). */
function chapterNum(string $file): string {
    return preg_match('/^(\d+[a-z]?)_/', $file, $m) ? $m[1] : '';
}

$cssVer = (string)@filemtime(__DIR__ . '/manual.css');
$hasPdf = is_file(__DIR__ . '/manual.pdf');

// SVG ikony (Heroicons outline, stroke 2, viewBox 24) — stejné jako ThemeToggle.vue v apce
$ICON_LIGHT = 'M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0z';
$ICON_DARK  = 'M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998z';

?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#3B2D83">
    <title><?= htmlspecialchars($activeTitle, ENT_QUOTES) ?></title>
    <link rel="icon" type="image/svg+xml" href="/styles/logo.svg">
    <link rel="stylesheet" href="/manual/manual.css?v=<?= htmlspecialchars($cssVer, ENT_QUOTES) ?>">
    <script>
      // Anti-FOUC: nastav .dark ještě před prvním renderem. Klíč je sdílený
      // s aplikací (composables/useTheme.ts THEME_STORAGE_KEY), takže manuál
      // automaticky přebírá režim zvolený v aplikaci.
      (function () {
        try {
          var p = (localStorage.getItem('myinvoice-color-scheme') || 'auto').replace(/^"|"$/g, '');
          if (p === 'dark' || (p !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
          }
        } catch (e) {}
      })();
    </script>
</head>
<body>

<!-- ═════════ TOPBAR ═════════ -->
<header class="topbar">
    <a href="/manual" class="brand">
        <img src="/styles/logo.svg" alt="MyÚčto">
        <span class="wordmark">My<b>Účto</b><i>.cz</i></span>
        <span class="brand-badge">Manuál</span>
    </a>
    <div class="topbar-right">
        <button type="button" class="theme-toggle" id="theme-toggle" title="Přepnout barevný režim" aria-label="Přepnout barevný režim">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path id="theme-toggle-icon" stroke-linecap="round" stroke-linejoin="round" d="<?= $ICON_DARK ?>"/></svg>
        </button>
        <?php if ($hasPdf): ?>
        <a href="/manual/manual.pdf" class="btn btn-outline hide-mobile" download>
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
            Stáhnout PDF
        </a>
        <?php endif; ?>
        <a href="/" class="btn btn-primary hide-mobile">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Zpět do Admin
        </a>
        <button type="button" class="hamburger" id="menu-toggle" aria-label="Menu" aria-expanded="false">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
    </div>
</header>

<div class="backdrop" id="backdrop"></div>

<!-- ═════════ SIDEBAR ═════════ -->
<aside class="sidebar" id="sidebar">
    <nav>
        <div class="sidebar-actions">
            <a href="/" class="btn btn-primary">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                Zpět do Admin
            </a>
            <?php if ($hasPdf): ?>
            <a href="/manual/manual.pdf" class="btn btn-outline" download>
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
                PDF
            </a>
            <?php endif; ?>
        </div>
        <div class="search-wrap">
            <svg class="search-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607z"/></svg>
            <input type="search" id="manual-search" placeholder="Hledat v manuálu…" autocomplete="off" />
            <div class="search-results" id="search-results"></div>
        </div>
        <ul class="toc-top">
            <li>
                <a href="/manual/" class="nav-item <?= $isLanding ? 'active' : '' ?>">
                    <span class="num"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path stroke-linecap="round" stroke-linejoin="round" d="M3 11.5 12 4l9 7.5M5.5 9.7V20a1 1 0 0 0 1 1H10v-5.5h4V21h3.5a1 1 0 0 0 1-1V9.7"/></svg></span>
                    Homepage
                </a>
            </li>
        </ul>
        <?php foreach ($groups as $gi => $g): ?>
            <div class="toc-group">
                <div class="nav-pill acc-<?= $ACCENTS[$gi % count($ACCENTS)] ?>"><?= htmlspecialchars($g['title']) ?></div>
                <ul>
                <?php foreach ($g['items'] as $it): ?>
                    <li>
                        <a href="/manual?ch=<?= urlencode($it['file']) ?>"
                           class="nav-item <?= $activeFile === $it['file'] ? 'active' : '' ?>">
                            <span class="num"><?= htmlspecialchars(chapterNum($it['file'])) ?></span>
                            <?= htmlspecialchars($it['title']) ?>
                        </a>
                    </li>
                    <?php if ($activeFile === $it['file'] && !empty($it['sub'])): ?>
                        <?php foreach ($it['sub'] as $s): ?>
                            <li><a class="nav-sub" href="#<?= htmlspecialchars($s['slug']) ?>"><?= htmlspecialchars($s['text']) ?></a></li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <div>MyÚčto.cz<?php if ($version !== ''): ?> <span class="ver">v<?= htmlspecialchars($version) ?></span><?php endif; ?></div>
        <div>Vyvíjí <a href="https://mywebdesign.cz/" target="_blank" rel="noopener">MyWebdesign.cz</a></div>
    </div>
</aside>

<!-- ═════════ OBSAH ═════════ -->
<main class="content<?= $isLanding ? ' landing' : '' ?>">
    <?= $bodyHtml ?>
    <?php if ($prevCh || $nextCh): ?>
    <nav class="pager" aria-label="Další kapitoly">
        <?php if ($prevCh): ?>
        <a class="prev" href="/manual?ch=<?= urlencode($prevCh['file']) ?>">
            <span class="pager-label">← Předchozí</span>
            <span class="pager-title"><?= htmlspecialchars($prevCh['title']) ?></span>
        </a>
        <?php endif; ?>
        <?php if ($nextCh): ?>
        <a class="next" href="/manual?ch=<?= urlencode($nextCh['file']) ?>">
            <span class="pager-label">Další →</span>
            <span class="pager-title"><?= htmlspecialchars($nextCh['title']) ?></span>
        </a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
</main>

<!-- SVG filtr pro .img-auto-dark (viz manual.css): lineární remap kanálů
     černá → #1D1B2A (surface), bílá zůstává — tmavým plochám invertovaných
     screenshotů dá indigo nádech přesně v barvě formulářů aplikace. -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <filter id="dark-surface-tint" color-interpolation-filters="sRGB">
        <feComponentTransfer>
            <feFuncR type="linear" slope="0.8863" intercept="0.1137"/>
            <feFuncG type="linear" slope="0.8941" intercept="0.1059"/>
            <feFuncB type="linear" slope="0.8353" intercept="0.1647"/>
        </feComponentTransfer>
    </filter>
</svg>

<script>
// ── Barevný režim (Light / Dark) — sdílí klíč s apkou (ThemeToggle.vue) ──
(function () {
    const KEY = 'myinvoice-color-scheme';
    const ICON_LIGHT = '<?= $ICON_LIGHT ?>';
    const ICON_DARK = '<?= $ICON_DARK ?>';
    const mq = matchMedia('(prefers-color-scheme: dark)');
    const btn = document.getElementById('theme-toggle');
    const icon = document.getElementById('theme-toggle-icon');
    const get = () => { try { return (localStorage.getItem(KEY) || 'auto').replace(/^"|"$/g, ''); } catch (e) { return 'auto'; } };
    const set = (v) => { try { localStorage.setItem(KEY, v); } catch (e) {} };
    function isDark() {
        const p = get();
        return p === 'dark' || (p !== 'light' && mq.matches);
    }
    function apply() {
        const dark = isDark();
        document.documentElement.classList.toggle('dark', dark);
        icon.setAttribute('d', dark ? ICON_LIGHT : ICON_DARK);
        const label = dark ? 'Přepnout na světlý režim' : 'Přepnout na tmavý režim';
        btn.title = label;
        btn.setAttribute('aria-label', label);
    }
    btn.addEventListener('click', () => { set(isDark() ? 'light' : 'dark'); apply(); });
    mq.addEventListener('change', apply);
    apply();
})();

// ── Mobile drawer ──
(function () {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('backdrop');
    const toggle = document.getElementById('menu-toggle');
    function setOpen(open) {
        sidebar.classList.toggle('open', open);
        backdrop.classList.toggle('show', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('open')));
    backdrop.addEventListener('click', () => setOpen(false));
})();

// ── Aktivní kapitola musí být po otevření vidět v levém obsahu ──
(function () {
    const nav = document.querySelector('#sidebar > nav');
    const active = nav && nav.querySelector('.nav-item.active');
    if (!nav || !active) return;
    requestAnimationFrame(() => {
        const navBox = nav.getBoundingClientRect();
        const activeBox = active.getBoundingClientRect();
        const top = nav.scrollTop + activeBox.top - navBox.top
            - (nav.clientHeight - activeBox.height) / 2;
        nav.scrollTop = Math.max(0, top);
    });
})();

// ── Světlé screenshoty → kandidáti na dark inverzi ──
// Změř průměrný jas (canvas 32×32, same-origin /manual/img/); světlé obrázky
// dostanou .img-auto-dark — samotný filtr aplikuje CSS jen pod .dark, takže
// přepínání témat funguje bez přepočtu.
(function () {
    const mark = (img) => {
        try {
            const c = document.createElement('canvas');
            c.width = 32; c.height = 32;
            const ctx = c.getContext('2d');
            ctx.drawImage(img, 0, 0, 32, 32);
            const d = ctx.getImageData(0, 0, 32, 32).data;
            let sum = 0;
            for (let i = 0; i < d.length; i += 4) sum += 0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2];
            if (sum / (d.length / 4) > 160) img.classList.add('img-auto-dark');
        } catch (e) { /* cross-origin taint apod. — nech bez filtru */ }
    };
    document.querySelectorAll('.content figure.fig img').forEach(img => {
        if (img.complete && img.naturalWidth) mark(img);
        else img.addEventListener('load', () => mark(img), { once: true });
    });
})();

// ── Externí odkazy v obsahu → nový tab ──
document.querySelectorAll('.content a[href^="http"]').forEach(a => {
    a.target = '_blank';
    a.rel = 'noopener';
});

// ── Image DPR scaling — Windows scaling 125% způsobuje, že screenshot 880px se
// vykreslí na 1100 device px (browser upscaluje pro vysoké DPI desktop displeje).
// Image omezíme na natural / dpr, takže 1 source px = 1 device px (1:1 mapping).
// Mobile (typicky dpr 2–3) tohle PŘESKAKUJE — tam jen max-width: 100% (responsive).
(function () {
    const dpr = window.devicePixelRatio || 1;
    if (dpr <= 1) return;
    const apply = (img) => {
        if (img.naturalWidth <= 0) return;
        if (window.innerWidth < 1024) {
            img.style.maxWidth = '100%';
            return;
        }
        const px = Math.round(img.naturalWidth / dpr);
        img.style.maxWidth = `min(${px}px, 100%)`;
    };
    const all = () => document.querySelectorAll('.content figure.fig img').forEach(img => {
        if (img.complete) apply(img); else img.addEventListener('load', () => apply(img));
    });
    all();
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(all, 150);
    });
})();

// ── Klientské vyhledávání přes search-index.json ──
(async function () {
    const input = document.getElementById('manual-search');
    const results = document.getElementById('search-results');
    if (!input) return;
    let index = null;
    async function loadIndex() {
        if (index) return index;
        const r = await fetch('/manual/generated/search-index.json');
        index = await r.json();
        return index;
    }
    function debounce(fn, ms) {
        let t;
        return function (...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), ms); };
    }
    function score(item, query) {
        const q = query.toLowerCase();
        let s = 0;
        if (item.t.toLowerCase().includes(q)) s += 100;
        for (const sec of item.s) if (sec.t.toLowerCase().includes(q)) s += 50;
        if (item.b.toLowerCase().includes(q)) s += 10;
        return s;
    }
    function bestSection(item, query) {
        const q = query.toLowerCase();
        for (const sec of item.s) if (sec.t.toLowerCase().includes(q)) return sec;
        return null;
    }
    function escHtml(s) {
        return String(s).replace(/[<>&"']/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function snippet(text, query) {
        const q = query.toLowerCase();
        const i = text.toLowerCase().indexOf(q);
        if (i < 0) return text.substring(0, 80) + '…';
        const from = Math.max(0, i - 30);
        const to = Math.min(text.length, i + q.length + 50);
        return (from > 0 ? '…' : '') + text.substring(from, to) + (to < text.length ? '…' : '');
    }
    input.addEventListener('input', debounce(async function () {
        const q = input.value.trim();
        if (q.length < 2) { results.classList.remove('active'); results.innerHTML = ''; return; }
        const idx = await loadIndex();
        const matches = idx.map(it => ({ item: it, score: score(it, q) })).filter(x => x.score > 0).sort((a, b) => b.score - a.score).slice(0, 8);
        if (!matches.length) { results.classList.add('active'); results.innerHTML = '<div class="result">Nic nenalezeno.</div>'; return; }
        results.innerHTML = matches.map(m => {
            const sec = bestSection(m.item, q);
            const url = '/manual?ch=' + encodeURIComponent(m.item.f) + (sec ? '#' + sec.a : '');
            const titleHtml = escHtml(m.item.t) + (sec ? ' <span>› ' + escHtml(sec.t) + '</span>' : '');
            return '<a class="result" href="' + escHtml(url) + '"><div class="result-title">' + titleHtml + '</div><div class="result-snip">' + escHtml(snippet(m.item.b, q)) + '</div></a>';
        }).join('');
        results.classList.add('active');
    }, 200));
    // mousedown fires before blur, so navigation isn't cancelled by setTimeout hiding results.
    results.addEventListener('mousedown', (e) => {
        const a = e.target.closest('a.result');
        if (!a) return;
        e.preventDefault();
        location.href = a.getAttribute('href');
    });
    input.addEventListener('blur', () => setTimeout(() => results.classList.remove('active'), 200));
})();
</script>
</body>
</html>
