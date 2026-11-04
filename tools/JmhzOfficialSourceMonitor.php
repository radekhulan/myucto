<?php

declare(strict_types=1);

namespace MyInvoice\Tooling;

use Closure;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Read-only watcher of official JMHZ documentation indexes.
 *
 * The watcher deliberately records only a normalized document inventory (title,
 * version, URL and byte hash). It never feeds a downloaded document into the
 * codebook updater: a change is an operational alert for a human review, not an
 * approval to install a new legislative source.
 */
final class JmhzOfficialSourceMonitor
{
    public const STATE_SCHEMA_VERSION = 'jmhz-official-source-monitor.v1';

    private const MAX_INDEX_BYTES = 2 * 1024 * 1024;
    private const MAX_DOCUMENT_BYTES = 32 * 1024 * 1024;
    private const MAX_SITE_PAGES = 200;

    private const NEWS_KEY_PREFIX_CHARS = 40;
    private const ADF_INLINE_TYPES = ['text', 'hardBreak', 'mention', 'emoji', 'inlineCard', 'mediaInline', 'date', 'status', 'placeholder'];

    /** @var array<string,array{label:string,index_url:string,index_format:string,document_hosts:list<string>,document_path_prefixes:list<string>,document_extensions:list<string>,api_slug?:string,documentation_title?:string,documentation_titles?:'*'|list<string>,news_titles?:list<string>}> */
    private array $sources;

    /** @var Closure(string,int):string */
    private Closure $fetch;

    /**
     * @param array<mixed> $sources
     * @param null|callable(string,int):string $fetch Test seam; production uses HTTPS only.
     */
    public function __construct(array $sources, ?callable $fetch = null)
    {
        $this->sources = $this->validateSources($sources);
        $this->fetch = $fetch === null
            ? Closure::fromCallable([$this, 'fetchHttps'])
            : Closure::fromCallable($fetch);
    }

    /**
     * Runs one observation and atomically replaces the local metadata state only
     * after every configured source was successfully fetched and parsed.
     *
     * @return array<string,mixed>
     */
    public function monitor(string $statePath, bool $persist = true): array
    {
        $lockPath = $statePath . '.lock';
        $parent = dirname($statePath);
        if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
            throw new RuntimeException("Nelze vytvořit adresář stavu monitoru {$parent}.");
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Monitor oficiálních zdrojů JMHZ už běží.');
        }

        try {
            $previous = $this->loadState($statePath);
            $observed = [];
            foreach ($this->sources as $id => $source) {
                $observed[$id] = $this->observeSource($id, $source);
            }
            $state = [
                'schema_version' => self::STATE_SCHEMA_VERSION,
                'observed_at' => gmdate('c'),
                'sources' => $observed,
            ];
            $report = $this->diff($previous, $state);
            $report['state_path'] = $statePath;
            $report['persisted'] = $persist;

            if ($persist) {
                $this->writeState($statePath, $state);
            }

            return $report;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,array{label:string,index_url:string,index_format:string,document_hosts:list<string>,document_path_prefixes:list<string>,document_extensions:list<string>,api_slug?:string,documentation_title?:string}> */
    private function validateSources(array $sources): array
    {
        if ($sources === []) {
            throw new RuntimeException('Monitor oficiálních zdrojů JMHZ musí mít alespoň jeden zdroj.');
        }
        $validated = [];
        foreach ($sources as $id => $source) {
            if (!is_string($id) || preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $id) !== 1 || !is_array($source)) {
                throw new RuntimeException('Monitor oficiálních zdrojů JMHZ má neplatný zdroj.');
            }
            $label = $source['label'] ?? null;
            $indexUrl = $source['index_url'] ?? null;
            $indexFormat = $source['index_format'] ?? 'html';
            $hosts = $source['document_hosts'] ?? null;
            $prefixes = $source['document_path_prefixes'] ?? null;
            $extensions = $source['document_extensions'] ?? null;
            if (!is_string($label) || $label === '' || !is_string($indexUrl) || !in_array($indexFormat, ['html', 'document_links', 'site_pages', 'mpsv_api', 'mpsv_api_pages', 'article_list', 'epo_structures'], true) || !is_array($hosts) || !is_array($prefixes) || !is_array($extensions)) {
                throw new RuntimeException("Monitor oficiálních zdrojů JMHZ má neplatný zdroj {$id}.");
            }
            $this->assertHttpsUrl($indexUrl, []);
            $validatedHosts = $this->validateStringList($hosts, "Zdroj {$id} má neplatný host dokumentu.", '/\A[a-z0-9.-]+\z/D');
            $validatedPrefixes = $this->validateStringList($prefixes, "Zdroj {$id} má neplatný prefix dokumentu.", '#\A/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*\z#D');
            // Seznam článků příponu nemá a mít nesmí: `isOfficialArticleUrl()`
            // filtruje jen hostitele a prefix cesty. Prázdný výčet by u ostatních
            // formátů znamenal, že neprojde nic, proto je povolený jen tady.
            $validatedExtensions = in_array($indexFormat, ['article_list', 'epo_structures'], true)
                ? []
                : $this->validateStringList($extensions, "Zdroj {$id} má neplatnou příponu dokumentu.", '/\A[a-z0-9]{2,8}\z/D');
            $this->assertHttpsUrl($indexUrl, [$this->host($indexUrl)]);
            $validatedSource = [
                'label' => $label,
                'index_url' => $this->canonicalUrl($indexUrl),
                'index_format' => $indexFormat,
                'document_hosts' => $validatedHosts,
                'document_path_prefixes' => $validatedPrefixes,
                'document_extensions' => $validatedExtensions,
            ];
            if ($indexFormat === 'mpsv_api') {
                $apiSlug = $source['api_slug'] ?? null;
                $documentationTitle = $source['documentation_title'] ?? null;
                if (!is_string($apiSlug) || preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $apiSlug) !== 1 || !is_string($documentationTitle) || trim($documentationTitle) === '') {
                    throw new RuntimeException("Monitor oficiálních zdrojů JMHZ má neplatný MPSV zdroj {$id}.");
                }
                $validatedSource['api_slug'] = $apiSlug;
                $validatedSource['documentation_title'] = trim($documentationTitle);
            }
            if ($indexFormat === 'mpsv_api_pages') {
                $apiSlug = $source['api_slug'] ?? null;
                if (!is_string($apiSlug) || preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $apiSlug) !== 1) {
                    throw new RuntimeException("Monitor oficiálních zdrojů JMHZ má neplatný MPSV zdroj {$id}.");
                }
                $titles = $source['documentation_titles'] ?? (isset($source['documentation_title']) ? [$source['documentation_title']] : null);
                $validatedSource['api_slug'] = $apiSlug;
                $validatedSource['documentation_titles'] = $titles === '*'
                    ? '*'
                    : $this->validateTitleList($titles, "Zdroj {$id} má neplatný výčet stránek dokumentace.", false);
                $validatedSource['news_titles'] = $this->validateTitleList($source['news_titles'] ?? [], "Zdroj {$id} má neplatný výčet stránek s aktualitami.", true);
                if ($validatedSource['documentation_titles'] !== '*' && array_diff($validatedSource['news_titles'], $validatedSource['documentation_titles']) !== []) {
                    throw new RuntimeException("Zdroj {$id} hlídá aktuality na stránce, kterou nesleduje.");
                }
            }
            $validated[$id] = $validatedSource;
        }

        return $validated;
    }

    /** @return list<string> */
    private function validateStringList(array $values, string $message, string $pattern): array
    {
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                throw new RuntimeException($message);
            }
            $result[] = strtolower($value);
        }
        if ($result === [] || count($result) !== count(array_unique($result))) {
            throw new RuntimeException($message);
        }

        return $result;
    }

    /** @return list<string> */
    private function validateTitleList(mixed $values, string $message, bool $allowEmpty): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw new RuntimeException($message);
        }
        $result = [];
        foreach ($values as $value) {
            $title = is_string($value) ? $this->normalizeText($value) : '';
            if ($title === '') {
                throw new RuntimeException($message);
            }
            $result[] = $title;
        }
        if ((!$allowEmpty && $result === []) || count($result) !== count(array_unique($result))) {
            throw new RuntimeException($message);
        }

        return $result;
    }

    /** @param array{label:string,index_url:string,index_format:string,document_hosts:list<string>,document_path_prefixes:list<string>,document_extensions:list<string>,api_slug?:string,documentation_title?:string} $source */
    private function observeSource(string $id, array $source): array
    {
        $index = ($this->fetch)($source['index_url'], self::MAX_INDEX_BYTES);
        if ($source['index_format'] === 'mpsv_api') {
            $pageUrl = $this->mpsvDocumentationPageUrl($index, $source);
            $page = ($this->fetch)($pageUrl, self::MAX_INDEX_BYTES);
            $documents = $this->extractMpsvDocuments($page, $source);
        } elseif ($source['index_format'] === 'mpsv_api_pages') {
            $documents = [];
            foreach ($this->mpsvSelectedPages($index, $source) as $page) {
                $json = ($this->fetch)($page['api_url'], self::MAX_INDEX_BYTES);
                $documents = [...$documents, ...$this->extractMpsvPageDocuments($json, $page, $source)];
            }
            usort($documents, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
        } elseif ($source['index_format'] === 'article_list') {
            $documents = $this->extractArticles($index, $source);
        } elseif ($source['index_format'] === 'epo_structures') {
            $documents = $this->extractEpoStructures($index, $source);
        } elseif ($source['index_format'] === 'document_links') {
            $documents = $this->extractDocumentLinks($index, $source);
        } elseif ($source['index_format'] === 'site_pages') {
            $documents = $this->extractSitePages($index, $source);
        } else {
            $documents = $this->extractDocuments($index, $source);
        }
        if (!in_array($source['index_format'], ['article_list', 'epo_structures', 'mpsv_api_pages', 'document_links'], true)) {
            // Články se po jednom NESTAHUJÍ. Jde o provozní oznámení, kde je
            // signálem to, že přibyla položka — a stránka článku nese menu,
            // patičku a další volatilní obsah, takže by se hlásila změna
            // pokaždé. Otisk se proto počítá z názvu a odkazu.
            // Přílohy všech stránek MPSV mají desítky MB; u nich stačí název
            // a odkaz, obsah hlídá zdroj `mpsv_api` u hlavní dokumentace.
            foreach ($documents as &$document) {
                $contents = ($this->fetch)($document['url'], self::MAX_DOCUMENT_BYTES);
                $document['sha256'] = hash('sha256', $contents);
                $document['byte_length'] = strlen($contents);
            }
            unset($document);
        }

        return [
            'id' => $id,
            'label' => $source['label'],
            'index_url' => $source['index_url'],
            'documents' => $documents,
        ];
    }

    /**
     * Seznam struktur EPO — písemnost a JEJÍ VERZE.
     *
     * ⚠️ Na rozdíl od JMHZ nehlídalo verze písemností finanční správy nic: XSD se
     * stahují jen na vyžádání (`cmd/download-xsd.sh`). Nová verze přitom mění
     * `verzePis` v obálce a podání se starou verzí projde NAŠÍ validací proti
     * starému XSD — odmítne ho až podatelna. Chyba by se tedy projevila až na
     * straně úřadu a u ostrého podání.
     *
     * Verze není v textu odkazu, ale v řádku tabulky vedle něj, takže se bere
     * text celého řádku. Klíčem je zkratka písemnosti: nová verze tedy nevypadá
     * jako přibylá položka, ale jako změna té stávající.
     *
     * @param array{label:string,index_url:string,index_format:string,document_hosts:list<string>,document_path_prefixes:list<string>,document_extensions:list<string>,api_slug?:string,documentation_title?:string} $source
     * @return list<array{key:string,title:string,version:?string,url:string,sha256?:string,byte_length?:int}>
     */
    private function extractEpoStructures(string $html, array $source): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (!$dom->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            )) {
                throw new RuntimeException("Index {$source['index_url']} není platné HTML.");
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        // ⚠️ Řádky NEJSOU odkazy. Tabulka naviguje přes `onclick="location.href='…'"`
        // na `<tr>`, takže XPath na `//a[@href]` nenajde nic — první pokus vrátil
        // nula písemností právě proto.
        $nodes = (new DOMXPath($dom))->query('//tr[@onclick]');
        if ($nodes === false) {
            throw new RuntimeException("Index {$source['index_url']} nelze prohledat.");
        }

        $structures = [];
        foreach ($nodes as $node) {
            $onclick = (string) ($node->attributes?->getNamedItem('onclick')?->nodeValue ?? '');
            if (preg_match("#location\.href='([^']+)'#", $onclick, $link) !== 1) {
                continue;
            }
            $url = $this->resolveUrl($source['index_url'], $link[1]);
            if (!$this->isOfficialArticleUrl($url, $source)) {
                continue;
            }
            // Zkratka se čte ze SUROVÉHO odkazu: `resolveUrl()` query string
            // zahazuje, takže z kanonické adresy už ji vytáhnout nejde.
            if (preg_match('/zkratka=([A-Z0-9]{4,10})/', $link[1], $matches) !== 1) {
                continue;
            }
            $code = $matches[1];
            if (isset($structures[$code])) {
                continue;
            }
            // Buňky se spojují oddělovačem: `textContent` je slepí dohromady
            // („DPZVD6Vyúčtování…09.03.0110.1.2024") a hlášení o změně by pak
            // nešlo přečíst.
            $cells = [];
            foreach ($node->childNodes as $cell) {
                $text = $this->normalizeText((string) $cell->textContent);
                if ($text !== '') {
                    $cells[] = $text;
                }
            }
            $title = implode(' · ', $cells);
            if ($title === '') {
                continue;
            }
            // Bez ``: verze bývá nalepená na název písemnosti, takže hranice
            // slova mezi „R" a „0" nevznikne.
            $version = preg_match('/([0-9]{2}\.[0-9]{2}\.[0-9]{2})/', $title, $found) === 1
                ? $found[1]
                : null;
            $structures[$code] = [
                'key' => $code,
                'title' => $title,
                'version' => $version,
                'url' => $url,
                'sha256' => hash('sha256', $title),
                'byte_length' => strlen($title),
            ];
        }
        ksort($structures, SORT_STRING);

        return array_values($structures);
    }

    /**
     * Seznam ČLÁNKŮ, ne dokumentů.
     *
     * ČSSZ oznamuje vady katalogu kontrol, výpadky, posuny lhůt i nové
     * povinnosti výhradně v aktualitách — 28. 8. 2026 tam takhle přišla vada ve
     * vyhodnocování kontrol 164, 270, 290, 291 a 333 spolu s hromadným
     * přepočtem stavů. V žádném dokumentu na ePortálu ani na vývojářském
     * portálu to není, takže bez téhle větve se o tom nedozvíme.
     *
     * Články nemají příponu, takže `isOfficialDocumentUrl()` je odmítne;
     * filtruje se jen hostitel a prefix cesty.
     *
     * @param array{label:string,index_url:string,index_format:string,document_hosts:list<string>,document_path_prefixes:list<string>,document_extensions:list<string>,api_slug?:string,documentation_title?:string} $source
     * @return list<array{key:string,title:string,version:?string,url:string,sha256?:string,byte_length?:int}>
     */
    private function extractArticles(string $html, array $source): array
    {
        $articles = [];
        foreach ($this->indexLinks($html, $source) as [$url, $title]) {
            if (!$this->isOfficialArticleUrl($url, $source)) {
                continue;
            }
            if ($title === '') {
                continue;
            }
            $key = $url;
            if (isset($articles[$key])) {
                continue;
            }
            $articles[$key] = [
                'key' => $key,
                'title' => $title,
                'version' => null,
                'url' => $url,
                'sha256' => hash('sha256', $title . "
" . $url),
                'byte_length' => strlen($title),
            ];
        }
        ksort($articles, SORT_STRING);

        return array_values($articles);
    }

    /**
     * Stránka ČSSZ „Definice e-Podání" (NEMPRI, HZUPN, ELDP, REGZEC) — seznam
     * ODKAZŮ na dokumenty, ne jejich obsah.
     *
     * Každá stránka nese desítky příloh včetně archivních verzí a vzorů tisku
     * po desítkách MB; stahovat je denně by hlídač zahltil a změnu nijak
     * nezpřesnilo. ČSSZ novou verzi vystavuje jako NOVÝ soubor s novým
     * odkazem (DV_NEMPRI25_v1_20260309.pdf vedle …_20240324.pdf), takže
     * signálem je přibylý nebo změněný odkaz. Otisk je proto z názvu a odkazu.
     *
     * Stejný název se na stránce může opakovat s jiným odkazem (aktuální
     * a archivní verze). Na rozdíl od `html` to není chyba: druhý výskyt dostane
     * pořadovou příponu, aby se žádný z nich neztratil.
     *
     * @param array{label:string,index_url:string,index_format:string,document_hosts:list<string>,document_path_prefixes:list<string>,document_extensions:list<string>} $source
     * @return list<array{key:string,title:string,version:?string,url:string,sha256:string,byte_length:int}>
     */
    private function extractDocumentLinks(string $html, array $source): array
    {
        $documents = [];
        $seenUrls = [];
        foreach ($this->indexLinks($html, $source) as [$url, $title]) {
            if ($url === '' || !$this->isOfficialDocumentUrl($url, $source)) {
                continue;
            }
            $url = $this->canonicalUrl($url);
            if (isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;
            $fileName = rawurldecode($this->documentFileName($url));
            if (in_array(mb_strtolower($title, 'UTF-8'), ['', 'stáhnout', 'download'], true)) {
                $title = $fileName;
            }
            $baseKey = $this->documentKey($title);
            if ($baseKey === '') {
                continue;
            }
            $key = $baseKey;
            for ($n = 2; isset($documents[$key]); $n++) {
                $key = "{$baseKey}:{$n}";
            }
            $fingerprint = $title . "\n" . $url;
            $documents[$key] = [
                'key' => $key,
                'title' => $this->shorten($title),
                'version' => $this->versionFrom($fileName . ' ' . $title),
                'url' => $url,
                'sha256' => hash('sha256', $fingerprint),
                'byte_length' => strlen($fingerprint),
            ];
        }
        if ($documents === []) {
            throw new RuntimeException("Index {$source['index_url']} neobsahuje žádný rozpoznatelný oficiální dokument.");
        }
        ksort($documents, SORT_STRING);

        return array_values($documents);
    }

    /**
     * Statický vícestránkový přehled (např. `/jmhz/2027/`): index odkazuje
     * relativně na stránky pod stejným prefixem. Klíčem je cesta stránky pod
     * prefixem, ne text odkazu, protože stejné názvy kapitol se opakují
     * (Mzda, Meta atributy) a odkazy s fragmentem míří na tutéž stránku.
     * Obsah každé stránky se stahuje a hlídá otiskem, takže se ohlásí i tichá
     * úprava karty změnového požadavku.
     *
     * @param array{label:string,index_url:string,index_format:string,document_hosts:list<string>,document_path_prefixes:list<string>,document_extensions:list<string>} $source
     * @return list<array{key:string,title:string,version:?string,url:string}>
     */
    private function extractSitePages(string $html, array $source): array
    {
        $indexUrl = $this->canonicalUrl($source['index_url']);
        $documents = [
            'index' => [
                'key' => 'index',
                'title' => 'Přehled změn (index)',
                'version' => null,
                'url' => $indexUrl,
            ],
        ];
        foreach ($this->indexLinks($html, $source) as [$url, $title]) {
            if ($url === '' || !$this->isOfficialDocumentUrl($url, $source)) {
                continue;
            }
            $url = $this->canonicalUrl($url);
            $path = (string) parse_url($url, PHP_URL_PATH);
            $key = null;
            foreach ($source['document_path_prefixes'] as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $key = rawurldecode(substr($path, strlen($prefix)));
                    break;
                }
            }
            // `…/index.html` je týž index jako `…/`: portál ho od 11/2026
            // přesměrovává (HTTP 301) a hlídač přesměrování nenásleduje, takže
            // celý běh padal na odkazu „Úvod" v navigaci stránky.
            if ($key === null || $key === '' || $key === 'index.html' || $url === $indexUrl) {
                continue;
            }
            if (count($documents) >= self::MAX_SITE_PAGES) {
                throw new RuntimeException("Index {$source['index_url']} odkazuje na víc než " . self::MAX_SITE_PAGES . ' stránek.');
            }
            if (isset($documents[$key])) {
                continue;
            }
            $documents[$key] = [
                'key' => $key,
                'title' => $this->shorten($title === '' ? $key : $title),
                'version' => null,
                'url' => $url,
            ];
        }
        if (count($documents) < 2) {
            throw new RuntimeException("Index {$source['index_url']} neobsahuje žádnou podstránku.");
        }
        ksort($documents, SORT_STRING);

        return array_values($documents);
    }

    /**
     * Název souboru z odkazu. Liferay ČSSZ ho nese jako předposlední segment
     * (`/documents/20143/2739697/NEMPRI25.xsd/<uuid>`), jinde je poslední.
     */
    private function documentFileName(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#/documents/\d+/\d+/([^/]+)/[0-9a-f-]{36}\z#Di', $path, $matches) === 1) {
            return $matches[1];
        }

        return basename($path);
    }

    /**
     * @param array{index_url:string,document_hosts:list<string>,document_path_prefixes:list<string>} $source
     * @return list<array{0:string,1:string}>
     */
    private function indexLinks(string $html, array $source): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            // ⚠️ `loadHTML()` bez určení kódování předpokládá ISO-8859-1, takže
            // by z „přepočet" udělalo „pÅepoÄet". U dokumentů to nevadí (názvy
            // jsou většinou názvy souborů), u článků ano — název je součástí
            // otisku, takže by se rozbitý text uložil do stavu monitoru.
            if (!$dom->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            )) {
                throw new RuntimeException("Index {$source['index_url']} není platné HTML.");
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $nodes = (new DOMXPath($dom))->query('//a[@href]');
        if ($nodes === false) {
            throw new RuntimeException("Index {$source['index_url']} nelze prohledat.");
        }
        $links = [];
        foreach ($nodes as $node) {
            $href = trim((string) $node->attributes?->getNamedItem('href')?->nodeValue);
            if ($href === '') {
                continue;
            }
            $links[] = [
                $this->resolveUrl($source['index_url'], $href),
                $this->normalizeText((string) $node->textContent),
            ];
        }

        return $links;
    }

    /** @param array{document_hosts:list<string>,document_path_prefixes:list<string>} $source */
    private function isOfficialArticleUrl(string $url, array $source): bool
    {
        $parts = parse_url($url);
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $path = (string) ($parts['path'] ?? '');
        if (($parts['scheme'] ?? null) !== 'https'
            || !in_array($host, $source['document_hosts'], true)
        ) {
            return false;
        }
        foreach ($source['document_path_prefixes'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{label:string,index_url:string,index_format:string,document_hosts:list<string>,document_path_prefixes:list<string>,document_extensions:list<string>,api_slug?:string,documentation_title?:string} $source
     * @return list<array{key:string,title:string,version:?string,url:string,sha256?:string,byte_length?:int}>
     */
    private function extractDocuments(string $html, array $source): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (!$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new RuntimeException("Index {$source['index_url']} není platné HTML.");
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $nodes = (new DOMXPath($dom))->query('//a[@href]');
        if ($nodes === false) {
            throw new RuntimeException("Index {$source['index_url']} nelze prohledat.");
        }

        $documents = [];
        foreach ($nodes as $node) {
            $href = trim((string) $node->attributes?->getNamedItem('href')?->nodeValue);
            if ($href === '') {
                continue;
            }
            $url = $this->resolveUrl($source['index_url'], $href);
            if (!$this->isOfficialDocumentUrl($url, $source)) {
                continue;
            }
            $title = $this->normalizeText((string) $node->textContent);
            if (in_array(mb_strtolower($title, 'UTF-8'), ['', 'stáhnout', 'download'], true)) {
                $title = basename((string) parse_url($url, PHP_URL_PATH));
            }
            $key = $this->documentKey($title);
            if ($key === '') {
                continue;
            }
            $document = [
                'key' => $key,
                'title' => $title,
                // Odkaz ČSSZ může v popisku nést velikost „1,8 MB“; název
                // samotného souboru je pro verzi autoritativnější než tento
                // prezentační údaj.
                'version' => $this->versionFrom(basename((string) parse_url($url, PHP_URL_PATH)) . ' ' . $title),
                'url' => $this->canonicalUrl($url),
            ];
            if (isset($documents[$key])) {
                // Stejný dokument se v navigaci může opakovat. Shodný odkaz není
                // změna, rozporné odkazy by znejasnily, co se má zkontrolovat.
                if ($documents[$key]['url'] !== $document['url']) {
                    throw new RuntimeException("Index {$source['index_url']} uvádí dokument {$title} víceznačně.");
                }
                continue;
            }
            $documents[$key] = $document;
        }
        if ($documents === []) {
            throw new RuntimeException("Index {$source['index_url']} neobsahuje žádný rozpoznatelný oficiální dokument.");
        }
        ksort($documents, SORT_STRING);

        return array_values($documents);
    }

    /**
     * @param array{index_url:string,api_slug?:string,documentation_title?:string} $source
     */
    private function mpsvDocumentationPageUrl(string $json, array $source): string
    {
        $candidates = array_values(array_filter(
            $this->mpsvDocumentationCandidates($json, $source),
            static fn (array $candidate): bool => $candidate['title'] === $source['documentation_title'],
        ));
        if ($candidates === []) {
            throw new RuntimeException("Katalog {$source['index_url']} neobsahuje veřejnou dokumentaci {$source['documentation_title']}.");
        }
        usort($candidates, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $this->mpsvPageApiUrl($source, $candidates[0]['api_id'], $candidates[0]['page_id']);
    }

    /**
     * Stránky dokumentace ze VŠECH schválených verzí API. Z každého názvu se
     * vybírá nejvyšší verze, která ho obsahuje, stejně jako u `mpsv_api`.
     *
     * @param array{index_url:string,api_slug?:string,documentation_titles?:'*'|list<string>,news_titles?:list<string>} $source
     * @return list<array{title:string,slug:string,news:bool,api_url:string,url:string}>
     */
    private function mpsvSelectedPages(string $json, array $source): array
    {
        $wanted = $source['documentation_titles'] ?? '*';
        $selected = [];
        foreach ($this->mpsvDocumentationCandidates($json, $source) as $candidate) {
            if ($candidate['visibility'] !== null && $candidate['visibility'] !== 'PUBLIC') {
                continue;
            }
            $title = $this->normalizeText($candidate['title']);
            if ($title === '' || ($wanted !== '*' && !in_array($title, $wanted, true))) {
                continue;
            }
            if (!isset($selected[$title]) || version_compare($candidate['version'], $selected[$title]['version']) > 0) {
                $selected[$title] = $candidate;
            }
        }
        foreach ($wanted === '*' ? [] : $wanted as $title) {
            if (!isset($selected[$title])) {
                throw new RuntimeException("Katalog {$source['index_url']} neobsahuje veřejnou dokumentaci {$title}.");
            }
        }
        if ($selected === []) {
            throw new RuntimeException("Katalog {$source['index_url']} neobsahuje žádnou veřejnou stránku dokumentace.");
        }

        $pages = [];
        foreach ($selected as $title => $candidate) {
            // Klíčem je název stránky, ne její identifikátor: nová verze API
            // dostává nové identifikátory, a stránka by pak vypadala jako
            // odebraná a znovu přidaná i se všemi přílohami a aktualitami.
            $slug = $this->slug((string) $title);
            if ($slug === '' || isset($pages[$slug])) {
                throw new RuntimeException("Katalog {$source['index_url']} uvádí stránku dokumentace {$title} víceznačně.");
            }
            $host = $this->host($source['index_url']);
            $url = "https://{$host}/api-list/{$source['api_slug']}/documentation/{$candidate['page_id']}";
            $this->assertHttpsUrl($url, [$host]);
            $pages[$slug] = [
                'title' => (string) $title,
                'slug' => $slug,
                'news' => in_array($title, $source['news_titles'] ?? [], true),
                'api_url' => $this->mpsvPageApiUrl($source, $candidate['api_id'], $candidate['page_id']),
                'url' => $this->canonicalUrl($url),
            ];
        }
        ksort($pages, SORT_STRING);

        return array_values($pages);
    }

    /** @param array{index_url:string} $source */
    private function mpsvPageApiUrl(array $source, string $apiId, string $pageId): string
    {
        $host = $this->host($source['index_url']);
        $url = "https://{$host}/api/apiversion/{$apiId}/documentationPage/{$pageId}";
        $this->assertHttpsUrl($url, [$host]);

        return $this->canonicalUrl($url);
    }

    /**
     * @param array{index_url:string,api_slug?:string} $source
     * @return list<array{version:string,api_id:string,page_id:string,title:string,visibility:?string}>
     */
    private function mpsvDocumentationCandidates(string $json, array $source): array
    {
        try {
            $catalog = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("Katalog {$source['index_url']} není platný JSON.", previous: $e);
        }
        $apis = is_array($catalog) ? ($catalog['data'] ?? null) : null;
        if (!is_array($apis)) {
            throw new RuntimeException("Katalog {$source['index_url']} nemá seznam API.");
        }

        $matchingApis = array_values(array_filter(
            $apis,
            static fn (mixed $api): bool => is_array($api) && ($api['slug'] ?? null) === $source['api_slug'],
        ));
        if (count($matchingApis) !== 1) {
            throw new RuntimeException("Katalog {$source['index_url']} neobsahuje právě jedno požadované API {$source['api_slug']}.");
        }

        $candidates = [];
        $versions = $matchingApis[0]['versions'] ?? null;
        foreach (is_array($versions) ? $versions : [] as $version) {
            if (!is_array($version) || ($version['status'] ?? null) !== 'APPROVED') {
                continue;
            }
            $apiId = $version['apiId'] ?? null;
            $versionNumber = $version['version'] ?? null;
            if (!is_string($apiId) || preg_match('/\A[0-9a-f-]{36}\z/D', $apiId) !== 1 || !is_string($versionNumber)) {
                continue;
            }
            // MPSV pole se stránkami dokumentace v katalogu přejmenovalo
            // (`documentationPageItems` → `documentationPages`). Obsah i identifikátory
            // zůstaly stejné, jen pod jiným klíčem — hlídač na to spadl a od 9. 9. 2026
            // hlásil, že dokumentace v katalogu není. Čteme obojí, ať přežijeme i návrat.
            $pages = $version['documentationPages'] ?? $version['documentationPageItems'] ?? null;
            foreach (is_array($pages) ? $pages : [] as $page) {
                if (!is_array($page) || !is_string($page['title'] ?? null)) {
                    continue;
                }
                $pageId = $page['apiVersionDocumentationId'] ?? null;
                if (!is_string($pageId) || preg_match('/\A[0-9a-f-]{36}\z/D', $pageId) !== 1) {
                    continue;
                }
                $candidates[] = [
                    'version' => $versionNumber,
                    'api_id' => $apiId,
                    'page_id' => $pageId,
                    'title' => $page['title'],
                    'visibility' => is_string($page['visibility'] ?? null) ? $page['visibility'] : null,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param array{index_url:string,document_hosts:list<string>,document_path_prefixes:list<string>,document_extensions:list<string>} $source
     * @return list<array{key:string,title:string,version:?string,url:string}>
     */
    private function extractMpsvDocuments(string $json, array $source): array
    {
        try {
            $page = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("Dokumentace {$source['index_url']} není platný JSON.", previous: $e);
        }
        // Druhé přejmenování v témže katalogu: tělo stránky i přílohy se přestěhovaly
        // pod `payload`. Čteme obě podoby ze stejného důvodu jako u `documentationPages`.
        $content = is_array($page) ? ($page['payload'] ?? $page) : null;
        $attachments = is_array($content) ? ($content['attachments'] ?? null) : null;
        $body = is_array($content) ? ($content['body'] ?? null) : null;
        if (!is_array($attachments) || !is_string($body)) {
            throw new RuntimeException("Dokumentace {$source['index_url']} nemá přílohy nebo tělo.");
        }
        try {
            $bodyTree = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("Tělo dokumentace {$source['index_url']} není platný JSON.", previous: $e);
        }
        $referencedMediaIds = [];
        $this->walkAdf($bodyTree, $referencedMediaIds);
        if ($referencedMediaIds === []) {
            throw new RuntimeException("Dokumentace {$source['index_url']} neodkazuje žádné přílohy.");
        }

        $documents = $this->mpsvAttachments($attachments, $referencedMediaIds, $source, "Dokumentace {$source['index_url']}");
        if ($documents === []) {
            throw new RuntimeException("Dokumentace {$source['index_url']} nemá žádnou rozpoznatelnou aktuální přílohu.");
        }
        ksort($documents, SORT_STRING);

        return array_values($documents);
    }

    /**
     * Přílohy, na které tělo stránky SKUTEČNĚ odkazuje. Pole `attachments`
     * nese i historické soubory, které ze stránky dávno zmizely.
     *
     * @param array<mixed> $attachments
     * @param array<string,true> $referencedMediaIds
     * @param array{document_hosts:list<string>,document_path_prefixes:list<string>,document_extensions:list<string>} $source
     * @return array<string,array{key:string,title:string,version:?string,url:string}>
     */
    private function mpsvAttachments(array $attachments, array $referencedMediaIds, array $source, string $context): array
    {
        $documents = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $mediaId = $attachment['mediaId'] ?? null;
            $fileName = $attachment['fileName'] ?? null;
            $downloadLink = $attachment['downloadLink'] ?? null;
            if (!is_string($mediaId) || !isset($referencedMediaIds[strtolower($mediaId)]) || !is_string($fileName) || !is_string($downloadLink)) {
                continue;
            }
            $url = $this->canonicalUrl($downloadLink);
            if (!$this->isOfficialDocumentUrl($url, $source)) {
                continue;
            }
            $title = $this->normalizeText($fileName);
            $key = $this->documentKey($title);
            if ($key === '') {
                continue;
            }
            $document = [
                'key' => $key,
                'title' => $title,
                'version' => $this->versionFrom($title),
                'url' => $url,
            ];
            if (isset($documents[$key])) {
                if ($documents[$key]['url'] !== $document['url']) {
                    throw new RuntimeException("{$context} uvádí dokument {$title} víceznačně.");
                }
                continue;
            }
            $documents[$key] = $document;
        }

        return $documents;
    }

    /**
     * Stránka dokumentace MPSV jako několik sledovaných položek: stránka sama
     * (verzí je `updatedDate`), přílohy (jen název a odkaz), záznamy stránek
     * typu katalog a u stránek s aktualitami jednotlivá oznámení.
     *
     * @param array{title:string,slug:string,news:bool,api_url:string,url:string} $page
     * @param array{document_hosts:list<string>,document_path_prefixes:list<string>,document_extensions:list<string>} $source
     * @return list<array{key:string,title:string,version:?string,url:string,sha256:string,byte_length:int}>
     */
    private function extractMpsvPageDocuments(string $json, array $page, array $source): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("Dokumentace {$page['api_url']} není platný JSON.", previous: $e);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException("Dokumentace {$page['api_url']} nemá očekávaný tvar.");
        }
        $content = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : $decoded;
        $updatedDate = $decoded['updatedDate'] ?? $content['updatedDate'] ?? null;
        $version = is_string($updatedDate) ? $this->normalizeTimestamp($updatedDate) : null;

        $blocks = [];
        $mediaIds = [];
        $body = $content['body'] ?? null;
        if (is_string($body)) {
            try {
                $tree = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new RuntimeException("Tělo dokumentace {$page['api_url']} není platný JSON.", previous: $e);
            }
            $children = is_array($tree) && is_array($tree['content'] ?? null) ? $tree['content'] : [$tree];
            foreach ($children as $child) {
                $blocks[] = [
                    is_array($child) && is_string($child['type'] ?? null) ? $child['type'] : '',
                    $this->normalizeText($this->walkAdf($child, $mediaIds)),
                ];
            }
        }
        $bodyText = implode("\n", array_filter(array_column($blocks, 1), static fn (string $text): bool => $text !== ''));

        $documents = [[
            'key' => "stranka:{$page['slug']}",
            'title' => $page['title'],
            'version' => $version,
            'url' => $page['url'],
            'sha256' => hash('sha256', $bodyText),
            'byte_length' => strlen($json),
        ]];

        $attachments = $content['attachments'] ?? null;
        foreach ($this->mpsvAttachments(is_array($attachments) ? $attachments : [], $mediaIds, $source, "Dokumentace {$page['api_url']}") as $attachment) {
            $fingerprint = $attachment['title'] . "\n" . $attachment['url'];
            $documents[] = [
                ...$attachment,
                'key' => "priloha:{$page['slug']}:{$attachment['key']}",
                'sha256' => hash('sha256', $fingerprint),
                'byte_length' => strlen($fingerprint),
            ];
        }

        $entries = $content['entries'] ?? null;
        $documents = [...$documents, ...$this->mpsvEntries(is_array($entries) ? $entries : [], $page)];
        if ($page['news']) {
            $documents = [...$documents, ...$this->mpsvNews($blocks, $page)];
        }

        return $documents;
    }

    /**
     * Záznamy stránek typu katalog (pokyny k vyplnění, otázky a odpovědi,
     * katalog kontrol). Identitou je jejich vlastní ID, otiskem obsah hodnot;
     * `updatedDate` záznamu se nesleduje, protože ho MPSV přepisuje hromadně
     * při každém importu katalogu.
     *
     * @param array<mixed> $entries
     * @param array{title:string,slug:string,url:string} $page
     * @return list<array{key:string,title:string,version:?string,url:string,sha256:string,byte_length:int}>
     */
    private function mpsvEntries(array $entries, array $page): array
    {
        $documents = [];
        foreach ($entries as $entry) {
            $values = is_array($entry) ? ($entry['values'] ?? null) : null;
            if (!is_array($values)) {
                continue;
            }
            $entryId = $values['id'] ?? $entry['id'] ?? null;
            $entryId = is_string($entryId) || is_int($entryId) ? $this->normalizeText((string) $entryId) : '';
            $entrySlug = $this->slug($entryId);
            if ($entrySlug === '') {
                continue;
            }
            $baseKey = "zaznam:{$page['slug']}:{$entrySlug}";
            $key = $baseKey;
            for ($n = 2; isset($documents[$key]); $n++) {
                $key = "{$baseKey}:{$n}";
            }
            $name = $values['name'] ?? $values['question'] ?? '';
            ksort($values, SORT_STRING);
            $fingerprint = json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $documents[$key] = [
                'key' => $key,
                'title' => $this->shorten("{$page['title']}: {$entryId} " . (is_string($name) ? $this->normalizeText($name) : '')),
                'version' => null,
                'url' => $page['url'],
                'sha256' => hash('sha256', $fingerprint),
                'byte_length' => strlen($fingerprint),
            ];
        }

        return array_values($documents);
    }

    /**
     * Oznámení na stránce aktualit. Každé začíná odstavcem s datem
     * („13.8.2026" i „13. 8. 2026") a pokračuje až k dalšímu datu.
     *
     * Klíčem je datum a začátek textu, otiskem celý text: oprava překlepu dál
     * v textu je tak změnou obsahu, ne novým oznámením. Stejný den může nést
     * více oznámení, proto samotné datum jako klíč nestačí.
     *
     * @param list<array{0:string,1:string}> $blocks
     * @param array{slug:string,url:string} $page
     * @return list<array{key:string,title:string,version:?string,url:string,sha256:string,byte_length:int}>
     */
    private function mpsvNews(array $blocks, array $page): array
    {
        $items = [];
        $current = null;
        foreach ($blocks as [$type, $text]) {
            if ($type === 'paragraph'
                && preg_match('/\A(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})(?!\d)(.*)\z/su', $text, $matches) === 1
                && checkdate((int) $matches[2], (int) $matches[1], (int) $matches[3])
            ) {
                if ($current !== null) {
                    $items[] = $current;
                }
                $current = [
                    'date' => sprintf('%04d-%02d-%02d', (int) $matches[3], (int) $matches[2], (int) $matches[1]),
                    'label' => sprintf('%d.%d.%d', (int) $matches[1], (int) $matches[2], (int) $matches[3]),
                    'parts' => [$matches[4]],
                ];
                continue;
            }
            if ($current !== null && $text !== '') {
                $current['parts'][] = $text;
            }
        }
        if ($current !== null) {
            $items[] = $current;
        }

        $documents = [];
        foreach ($items as $item) {
            $text = $this->normalizeText(implode(' ', $item['parts']));
            $prefix = $this->slug(mb_substr($text, 0, self::NEWS_KEY_PREFIX_CHARS, 'UTF-8'));
            $baseKey = "aktualita:{$page['slug']}:{$item['date']}" . ($prefix === '' ? '' : ":{$prefix}");
            $key = $baseKey;
            for ($n = 2; isset($documents[$key]); $n++) {
                $key = "{$baseKey}:{$n}";
            }
            $fullText = trim("{$item['label']} {$text}");
            $documents[$key] = [
                'key' => $key,
                'title' => $this->shorten($fullText),
                'version' => null,
                'url' => $page['url'],
                'sha256' => hash('sha256', $fullText),
                'byte_length' => strlen($fullText),
            ];
        }

        return array_values($documents);
    }

    /**
     * Jediný průchod stromem Atlassian ADF: vrací text uzlu a zároveň sbírá
     * přílohy, na které uzel odkazuje. Blokové uzly se oddělují mezerou,
     * sousední textové uzly jednoho odstavce ne (formátování dělí i slova).
     *
     * @param array<string,true> $mediaIds
     */
    private function walkAdf(mixed $node, array &$mediaIds): string
    {
        if (!is_array($node)) {
            return '';
        }
        $type = $node['type'] ?? null;
        $attributes = $node['attrs'] ?? null;
        $id = is_array($attributes) ? ($attributes['id'] ?? null) : null;
        if (in_array($type, ['media', 'mediaInline'], true) && is_string($id) && preg_match('/\A[0-9a-f-]{36}\z/Di', $id) === 1) {
            $mediaIds[strtolower($id)] = true;
        }
        if ($type === 'text') {
            return is_string($node['text'] ?? null) ? $node['text'] : '';
        }
        if ($type === 'hardBreak') {
            return ' ';
        }
        $text = '';
        foreach ($node as $child) {
            $text .= $this->walkAdf($child, $mediaIds);
        }

        return is_string($type) && !in_array($type, self::ADF_INLINE_TYPES, true) ? $text . ' ' : $text;
    }

    /** @param array{document_hosts:list<string>,document_path_prefixes:list<string>,document_extensions:list<string>} $source */
    private function isOfficialDocumentUrl(string $url, array $source): bool
    {
        $parts = parse_url($url);
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $path = (string) ($parts['path'] ?? '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '' && preg_match('#\A/documents/\d+/\d+/(.+)/[0-9a-f-]{36}\z#Di', $path, $matches) === 1) {
            $extension = strtolower(pathinfo(rawurldecode($matches[1]), PATHINFO_EXTENSION));
        }
        if (($parts['scheme'] ?? null) !== 'https' || !in_array($host, $source['document_hosts'], true) || !in_array($extension, $source['document_extensions'], true)) {
            return false;
        }
        foreach ($source['document_path_prefixes'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed>|null $previous @param array<string,mixed> $current @return array<string,mixed> */
    private function diff(?array $previous, array $current): array
    {
        $baseline = $previous === null;
        $changes = [];
        $baselineSources = [];
        foreach ($current['sources'] as $sourceId => $source) {
            $oldSource = $previous['sources'][$sourceId] ?? null;
            // Zdroj přidaný do konfigurace po prvním běhu je pro sebe baseline.
            // Jinak by první běh po nasazení nahlásil každou jeho položku jako přidanou.
            $sourceBaseline = $baseline || !is_array($oldSource);
            if ($sourceBaseline && !$baseline) {
                $baselineSources[] = (string) $sourceId;
            }
            $oldDocuments = is_array($oldSource['documents'] ?? null) ? $oldSource['documents'] : [];
            $oldByKey = [];
            foreach ($oldDocuments as $document) {
                if (is_array($document) && is_string($document['key'] ?? null)) {
                    $oldByKey[$document['key']] = $document;
                }
            }
            $newByKey = [];
            foreach ($source['documents'] as $document) {
                $newByKey[$document['key']] = $document;
            }
            foreach ($newByKey as $key => $document) {
                $old = $oldByKey[$key] ?? null;
                if ($old === null) {
                    if (!$sourceBaseline) {
                        $changes[] = $this->change('added', $sourceId, null, $document);
                    }
                    continue;
                }
                if (($old['version'] ?? null) !== $document['version'] || ($old['url'] ?? null) !== $document['url']) {
                    $changes[] = $this->change('version_changed', $sourceId, $old, $document);
                } elseif (($old['sha256'] ?? null) !== $document['sha256']) {
                    $changes[] = $this->change('content_changed', $sourceId, $old, $document);
                }
            }
            if (!$sourceBaseline) {
                foreach ($oldByKey as $key => $old) {
                    if (!isset($newByKey[$key])) {
                        $changes[] = $this->change('removed', $sourceId, $old, null);
                    }
                }
            }
        }
        usort($changes, static fn (array $a, array $b): int => [$a['source_id'], $a['document_key']] <=> [$b['source_id'], $b['document_key']]);

        return [
            'schema_version' => self::STATE_SCHEMA_VERSION,
            'observed_at' => $current['observed_at'],
            'baseline_created' => $baseline,
            'baseline_sources' => $baselineSources,
            'changed' => $changes !== [],
            'change_count' => count($changes),
            'changes' => $changes,
            'sources' => array_map(static fn (array $source): array => [
                'id' => $source['id'],
                'label' => $source['label'],
                'index_url' => $source['index_url'],
                'document_count' => count($source['documents']),
            ], array_values($current['sources'])),
        ];
    }

    /** @param array<string,mixed>|null $old @param array<string,mixed>|null $new @return array<string,mixed> */
    private function change(string $kind, string $sourceId, ?array $old, ?array $new): array
    {
        $document = $new ?? $old;
        return [
            'kind' => $kind,
            'source_id' => $sourceId,
            'document_key' => $document['key'],
            'title' => $document['title'],
            'url' => $document['url'],
            'old_version' => $old['version'] ?? null,
            'new_version' => $new['version'] ?? null,
            'old_sha256' => $old['sha256'] ?? null,
            'new_sha256' => $new['sha256'] ?? null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function loadState(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        try {
            $state = is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException $e) {
            throw new RuntimeException("Stav monitoru {$path} není platný JSON.", previous: $e);
        }
        if (!is_array($state) || ($state['schema_version'] ?? null) !== self::STATE_SCHEMA_VERSION || !is_array($state['sources'] ?? null)) {
            throw new RuntimeException("Stav monitoru {$path} má neznámý formát.");
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function writeState(string $path, array $state): void
    {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $tmp = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path) . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException("Stav monitoru {$path} nelze zapsat.");
        }
        $backup = null;
        try {
            if (is_file($path)) {
                $backup = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path) . '.old-' . bin2hex(random_bytes(8));
                if (!rename($path, $backup)) {
                    throw new RuntimeException("Předchozí stav monitoru {$path} nelze bezpečně přesunout.");
                }
            }
            if (!rename($tmp, $path)) {
                if ($backup !== null) {
                    @rename($backup, $path);
                }
                throw new RuntimeException("Nový stav monitoru {$path} nelze bezpečně nainstalovat.");
            }
            if ($backup !== null && is_file($backup) && !unlink($backup)) {
                throw new RuntimeException("Záložní stav monitoru {$backup} nelze odstranit.");
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Přesměrování se následuje jen v rámci téhož hostitele a nejvýš třikrát.
     * Portál MPSV od 11/2026 přesměrovává stránky `…/x.html` na `…/x` a index
     * `…/index.html` na `…/`; cizí hostitel by znamenal jiný než oficiální zdroj.
     */
    private const MAX_REDIRECTS = 3;

    private function fetchHttps(string $url, int $maxBytes, int $redirects = 0): string
    {
        $this->assertHttpsUrl($url, []);
        $context = stream_context_create(['http' => ['method' => 'GET', 'follow_location' => 0, 'timeout' => 20, 'ignore_errors' => true]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException("Oficiální zdroj {$url} nelze načíst.");
        }
        try {
            $meta = stream_get_meta_data($stream);
            $headers = $meta['wrapper_data'] ?? [];
            $status = is_array($headers) && isset($headers[0]) && preg_match('/\s(\d{3})\s/', (string) $headers[0], $matches) === 1 ? (int) $matches[1] : 0;
            if (in_array($status, [301, 302, 307, 308], true) && $redirects < self::MAX_REDIRECTS) {
                $location = '';
                foreach (is_array($headers) ? $headers : [] as $header) {
                    if (preg_match('/\ALocation:\s*(\S+)/i', (string) $header, $found) === 1) {
                        $location = $found[1];
                    }
                }
                $target = $location === '' ? '' : $this->resolveUrl($url, $location);
                if ($target !== '' && $this->host($target) === $this->host($url)) {
                    return $this->fetchHttps($target, $maxBytes, $redirects + 1);
                }
            }
            if ($status !== 200) {
                throw new RuntimeException("Oficiální zdroj {$url} vrátil HTTP {$status}.");
            }
            $data = '';
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    throw new RuntimeException("Oficiální zdroj {$url} nelze dočíst.");
                }
                $data .= $chunk;
                if (strlen($data) > $maxBytes) {
                    throw new RuntimeException("Oficiální zdroj {$url} překročil limit {$maxBytes} B.");
                }
            }
            return $data;
        } finally {
            fclose($stream);
        }
    }

    private function resolveUrl(string $base, string $href): string
    {
        if (preg_match('#\Ahttps://#i', $href) === 1) {
            return $this->canonicalUrl($href);
        }
        if (str_starts_with($href, '//') || preg_match('#\A[a-z][a-z0-9+.-]*:#i', $href) === 1) {
            return '';
        }
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException("Nelze vyřešit URL indexu {$base}.");
        }
        // Fragment ani dotaz neurčují dokument; odkaz jen na fragment ukazuje
        // na samotnou stránku indexu a žádný další dokument neznamená.
        $hrefPath = (string) preg_replace('/[?#].*\z/sD', '', $href);
        if ($hrefPath === '') {
            return '';
        }
        if (str_starts_with($hrefPath, '/')) {
            return $this->canonicalUrl($parts['scheme'] . '://' . $parts['host'] . $this->removeDotSegments($hrefPath));
        }
        // RFC 3986 5.2.3: relativní odkaz se skládá s ADRESÁŘEM základní cesty.
        // Cesta končící lomítkem (`/jmhz/2027/`) už adresář je; `dirname()` z ní
        // ukrojil poslední adresář a odkazy `mh/…` mířily vedle indexu.
        $basePath = (string) ($parts['path'] ?? '/');
        $directory = str_ends_with($basePath, '/') ? $basePath : substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
        if ($directory === '') {
            $directory = '/';
        }

        return $this->canonicalUrl(
            $parts['scheme'] . '://' . $parts['host'] . $this->removeDotSegments($directory . $hrefPath),
        );
    }

    /** Odstraní segmenty `.` a `..` (i zakódované), aby cesta nemohla opustit prefix dokumentů. */
    private function removeDotSegments(string $path): string
    {
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        $output = [];
        foreach ($segments as $index => $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..') {
                if ($decoded === '..' && count($output) > 1) {
                    array_pop($output);
                }
                if ($index === $last) {
                    $output[] = '';
                }
                continue;
            }
            $output[] = $segment;
        }

        return implode('/', $output);
    }

    private function canonicalUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException("Neplatná URL {$url}.");
        }
        $path = (string) ($parts['path'] ?? '/');
        $encodedPath = implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $path),
        ));
        return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . $encodedPath;
    }

    /** @param list<string> $allowedHosts */
    private function assertHttpsUrl(string $url, array $allowedHosts): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || $host === '' || isset($parts['user'], $parts['pass'], $parts['port']) || ($allowedHosts !== [] && !in_array($host, $allowedHosts, true))) {
            throw new RuntimeException("Monitor odmítl nebezpečnou URL {$url}.");
        }
    }

    private function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new RuntimeException("Neplatná URL {$url}.");
        }
        return strtolower($host);
    }

    private function normalizeText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function documentKey(string $title): string
    {
        $key = mb_strtolower($title, 'UTF-8');
        // Prezentační stránka ČSSZ připojuje k názvu velikost souboru
        // („Pokyny k vyplnění (1,8 MB)“). Ta se může změnit i bez nové verze
        // dokumentu, proto nesmí být součástí identity.
        $key = (string) preg_replace('/\b\d+(?:[.,]\d+)?\s*(?:kb|mb|gb)\b/ui', '', $key);
        $key = (string) preg_replace('/\b(?:verze?|v)\s*\d+(?:\.\d+){1,4}\b/ui', '', $key);
        $key = (string) preg_replace('/\b\d+(?:\.\d+){1,4}\b/u', '', $key);
        return $this->slug($key);
    }

    private function slug(string $value): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($value, 'UTF-8')), '-');
    }

    private function shorten(string $value): string
    {
        return mb_strimwidth($this->normalizeText($value), 0, 200, '…', 'UTF-8');
    }

    /**
     * Zlomek sekundy zapisuje MPSV s proměnným počtem číslic (koncové nuly
     * vypouští). Porovnává se proto na sekundy.
     */
    private function normalizeTimestamp(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d\TH:i:sP');
        } catch (\Exception) {
            return $this->normalizeText($value);
        }
    }

    private function versionFrom(string $value): ?string
    {
        return preg_match('/(?<!\d)(?:v(?:erze)?\s*)?(\d+(?:\.\d+){1,4})(?!\d)/iu', $value, $matches) === 1 ? $matches[1] : null;
    }
}
