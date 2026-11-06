<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceInboxDismissalRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use PDO;
use MyInvoice\Infrastructure\Database\Connection;

/**
 * Scan adresáře s PDF / ISDOC pro automatické vytváření přijatých faktur.
 *
 * Postup:
 *   1. Načti inbox_dir z config; pokud prázdné → vrať [skipped: 'inbox not configured'].
 *   2. Rekurzivně projdi adresář, filtruj přípony z allowed_exts.
 *   3. Seskup soubory do ZÁSILEK podle základu jména ({@see InboxFileGrouper}) — dvojice
 *      `faktura.isdoc` + `faktura.pdf` je jeden doklad, ne dvě nezávislé věci.
 *   4. Per zásilku: spočti SHA-256 každého členu; když některý z nich už v DB je
 *      (`pdf_hash` NEBO `source_hash`), celou zásilku přeskoč (dedup). Stejně tak,
 *      když uživatel koncept z některého členu smazal ({@see PurchaseInvoiceInboxDismissalRepository}).
 *   5. Data ber vždy ze strojového originálu, je-li v zásilce:
 *      `.isdoc`/`.xml` → IsdocParser, `.isdocx` → rozbal a parsuj.
 *      Jen PDF → embedded ISDOC (PDF/A-3), jinak AI extrakce.
 *      Nečitelný ISDOC se sourozeneckým PDF → spadni na PDF větev (nikdy nebrickovat import).
 *   6. Z parsovaných dat:
 *      - Najdi/vytvoř vendor (matchuj přes IČ; pokud chybí, vytvoř nový clients řádek s is_vendor=1).
 *      - Vytvoř purchase_invoice draft.
 *      - Insertni items + recompute totals.
 *      - Archivuj čitelné PDF (sourozenecké > vnitřní z isdocx) a strojový originál.
 *   7. Vrať souhrn { created: int, skipped: int, failed: int, details: [{file, status, reason, purchase_invoice_id?}] }.
 *
 * PROČ ZÁSILKY: dřív se šlo soubor po souboru, takže PDF vedle ISDOC šlo na placenou AI
 * extrakci i s přesnými daty po ruce a při sebemenším rozdílu vznikl druhý koncept —
 * a když vyhrál ISDOC, doklad zůstal bez čitelné podoby. Že to většinou dopadlo dobře,
 * drželo jen abecední pořadí (`.isdoc` < `.pdf`) a unikátní klíče v DB; u `.xml`
 * (> `.pdf`) nedrželo vůbec.
 *
 * Security:
 *   - Realpath check: každý file musí být uvnitř configured inbox_dir (ochrana symlinks).
 *   - Max file size 20 MiB per soubor.
 *   - Max 500 souborů per run (proti DoS na large dirs).
 */
final class PurchaseInvoiceInboxScanner
{
    private const MAX_FILE_SIZE = 20 * 1024 * 1024;
    private const MAX_FILES_PER_RUN = 500;

    /** Mapa `source_format` pro strojový originál podle přípony datového souboru. */
    private const SOURCE_FORMAT = ['isdoc' => 'isdoc', 'xml' => 'isdoc', 'isdocx' => 'isdocx'];

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly PurchaseInvoiceRepository $purchaseRepo,
        private readonly ClientRepository $clients,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly InvoiceExtractionRouter $router,
        private readonly IsdocToPurchaseInvoiceMapper $mapper,
        private readonly AiPdfExtractor $aiExtractor,
        private readonly PurchaseInvoicePdfArchiver $pdfArchiver,
        private readonly InboxPairVerifier $pairVerifier,
        private readonly PurchaseInvoiceInboxDismissalRepository $dismissals,
    ) {}

    /**
     * @return array{
     *     created: int,
     *     skipped: int,
     *     failed: int,
     *     dry_run: bool,
     *     inbox_dir: string,
     *     details: list<array<string,mixed>>
     * }
     */
    /**
     * @param callable|null $progress Optional callback(array $event) fired for each
     *        per-group event. Events have shape:
     *          - ['phase' => 'start',  'file' => abs, 'index' => 1-based, 'total' => N]
     *          - ['phase' => 'result', 'file' => abs, 'status' => ..., 'reason' => ...]
     *        `index`/`total` počítají ZÁSILKY, ne soubory (dvojice pdf+isdoc = jedna položka).
     *        Použito v cron skriptu pro live progress výpis do konzole/logu.
     */
    public function scan(int $supplierId, int $userId, bool $dryRun = false, ?callable $progress = null): array
    {
        $inboxDir = (string) $this->config->get('purchase_invoice.inbox_dir', '');
        if ($inboxDir === '') {
            return $this->emptyResult($inboxDir, $dryRun, [['file' => '', 'status' => 'config_missing', 'reason' => 'purchase_invoice.inbox_dir není nastaveno v cfg.php']]);
        }

        $inboxReal = realpath($inboxDir);
        if ($inboxReal === false || !is_dir($inboxReal)) {
            // Diagnostika: PHP user (z Apache/IIS) nemusí mít přístup ke cestě.
            // Vrátíme všechny relevantní info aby user věděl, kde grantnout práva.
            $phpUser = function_exists('posix_getpwuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
                : (getenv('USERNAME') ?: get_current_user() ?: 'unknown');
            $sapi = php_sapi_name();
            $exists = file_exists($inboxDir);
            $readable = $exists && is_readable($inboxDir);

            // Testuj postupně subdir-by-subdir kde se to láme (pomáhá najít chybějící práva)
            $segments = preg_split('@[\\\\/]+@', trim($inboxDir, "\\/"));
            $brokenAt = null;
            $build = (str_starts_with($inboxDir, '/') ? '/' : '');
            foreach ($segments ?: [] as $seg) {
                $build .= $seg . DIRECTORY_SEPARATOR;
                if (!file_exists($build)) {
                    $brokenAt = rtrim($build, DIRECTORY_SEPARATOR);
                    break;
                }
            }

            $reason = "Inbox adresář nelze otevřít z PHP procesu (SAPI: {$sapi}, user: {$phpUser}). ";
            if (!$exists) {
                $reason .= "Cesta neexistuje pro tohoto usera";
                if ($brokenAt !== null) $reason .= " — selhalo na: {$brokenAt}";
                $reason .= '. ';
            } elseif (!$readable) {
                $reason .= 'Cesta existuje, ale není čitelná. ';
            }
            $reason .= "Řešení (PowerShell jako Admin): " .
                "icacls \"{$inboxDir}\" /grant \"{$phpUser}:(OI)(CI)R\" /T " .
                "— NEBO přesuň složku pod webroot (C:\\inetpub\\wwwroot\\myinvoice.cz\\inbox).";

            return $this->emptyResult($inboxDir, $dryRun, [[
                'file' => $inboxDir,
                'status' => 'inbox_missing',
                'reason' => $reason,
            ]]);
        }

        $recursive = (bool) $this->config->get('purchase_invoice.inbox_recursive', true);
        $allowedExts = (array) $this->config->get('purchase_invoice.allowed_exts', ['pdf', 'isdoc', 'isdocx', 'xml']);
        $allowedExts = array_map('strtolower', $allowedExts);

        $details = [];
        $counters = ['created' => 0, 'skipped' => 0, 'failed' => 0];

        $groups = InboxFileGrouper::group($this->listFiles($inboxReal, $recursive, $allowedExts));
        $totalGroups = count($groups);

        // Helper closure — wrap detail push + počítadlo + progress callback (pokud existuje).
        // Počítadlo se odvozuje ze `status`, ať se souhrn nikdy nerozejde s detaily.
        $emit = function (array $detail) use (&$details, &$counters, $progress): void {
            $details[] = $detail;
            $bucket = match ((string) ($detail['status'] ?? '')) {
                'created', 'imported' => 'created',
                'skipped'             => 'skipped',
                'failed', 'rejected'  => 'failed',
                default               => null,
            };
            if ($bucket !== null) {
                $counters[$bucket]++;
            }
            if ($progress !== null) {
                ($progress)(['phase' => 'result'] + $detail);
            }
        };

        $filesProcessed = 0;
        foreach ($groups as $idx => $group) {
            $primary = $group->primary();
            if ($progress !== null) {
                ($progress)([
                    'phase' => 'start',
                    'file'  => $primary,
                    'index' => $idx + 1,
                    'total' => $totalGroups,
                ]);
            }
            if ($filesProcessed >= self::MAX_FILES_PER_RUN) {
                $emit(['file' => $primary, 'status' => 'limit_reached', 'reason' => 'Maximální počet souborů per run dosažen']);
                break;
            }

            // 1) Ověř a ohashuj členy zásilky. Vada PRIMÁRNÍHO souboru shodí celou
            //    zásilku; nečitelný sourozenec se jen vypustí (data jsou přednější).
            /** @var array<string, array{real:string, sha:string, size:int}> $members */
            $members = [];
            $primaryBroken = null;
            foreach ($group->members() as $path) {
                $info = $this->inspect($path, $inboxReal);
                if (isset($info['error'])) {
                    if ($path === $primary) {
                        $primaryBroken = $info;
                        break;
                    }
                    continue;
                }
                /** @var array{real:string, sha:string, size:int} $info */
                $members[$path] = $info;
            }
            if ($primaryBroken !== null) {
                $emit(['file' => $primaryBroken['real'], 'status' => 'rejected', 'reason' => (string) $primaryBroken['error']]);
                continue;
            }
            $filesProcessed += count($members);

            // 2) Dedup — stačí, aby už systém znal KTERÝKOLIV soubor zásilky.
            $known = null;
            foreach ($members as $info) {
                $existingId = $this->purchaseRepo->findIdByPdfHash($supplierId, $info['sha'], activeOnly: true)
                    ?? $this->purchaseRepo->findIdBySourceHash($supplierId, $info['sha'], activeOnly: true);
                if ($existingId !== null) {
                    $known = ['file' => $info['real'], 'id' => $existingId];
                    break;
                }
            }
            if ($known !== null) {
                $emit([
                    'file'   => $members[$primary]['real'] ?? $known['file'],
                    'status' => 'skipped',
                    'reason' => 'Již importováno',
                    'purchase_invoice_id' => $known['id'],
                ]);
                $this->emitExtras($group, $members, $emit, 'Zásilka už byla importována');
                continue;
            }

            // 2b) Koncept z téhle zásilky uživatel smazal — soubor v inboxu zůstal,
            //     ale znovu se nezakládá (issue #118).
            $dismissedFile = null;
            foreach ($members as $info) {
                if ($this->dismissals->isDismissed($supplierId, $info['sha'])) {
                    $dismissedFile = $info['real'];
                    break;
                }
            }
            if ($dismissedFile !== null) {
                $emit([
                    'file'   => $members[$primary]['real'] ?? $dismissedFile,
                    'status' => 'skipped',
                    'reason' => 'Koncept z tohoto souboru byl smazán',
                ]);
                $this->emitExtras($group, $members, $emit, 'Koncept z této zásilky byl smazán');
                continue;
            }

            $dataPath = $group->data;
            $pdfPath  = ($group->pdf !== null && isset($members[$group->pdf])) ? $group->pdf : null;

            // 3) Data ze strojového originálu, je-li v zásilce.
            $isdocError = null;
            if ($dataPath !== null) {
                $handled = $this->processDataFile(
                    $members, $dataPath, $pdfPath, $supplierId, $userId, $dryRun, $emit, $isdocError,
                );
                if ($handled) {
                    $this->emitExtras($group, $members, $emit, 'Duplicitní sourozenec téže zásilky');
                    continue;
                }
                // ISDOC nečitelný. Bez PDF končíme, s PDF zkusíme obraz.
                if ($pdfPath === null) {
                    $emit([
                        'file'   => $members[$dataPath]['real'],
                        'status' => 'failed',
                        'reason' => 'ISDOC se nepodařilo naparsovat: ' . ($isdocError ?? 'neznámá chyba'),
                    ]);
                    $this->emitExtras($group, $members, $emit, 'Duplicitní sourozenec téže zásilky');
                    continue;
                }
            }

            // 4) PDF větev — samostatné PDF, nebo záchrana po nečitelném ISDOC.
            if ($pdfPath !== null) {
                $this->processPdfFile(
                    $members[$pdfPath], $supplierId, $userId, $dryRun, $emit,
                    $isdocError !== null
                        ? 'ISDOC vedle PDF se nepodařilo naparsovat (' . $isdocError . '), zkouším samotné PDF. '
                        : '',
                );
            }

            $this->emitExtras($group, $members, $emit, 'Duplicitní sourozenec téže zásilky');
        }

        return [
            'created'   => $counters['created'],
            'skipped'   => $counters['skipped'],
            'failed'    => $counters['failed'],
            'dry_run'   => $dryRun,
            'inbox_dir' => $inboxReal,
            'details'   => $details,
        ];
    }

    /**
     * Zpracuje strojový originál zásilky. Vrací true, když je zásilka vyřízená
     * (draft vytvořen, dry-run, nebo tvrdá chyba mapperu); false znamená „ISDOC
     * nečitelný, zkus PDF" a důvod předá v `$isdocError`.
     *
     * @param array<string, array{real:string, sha:string, size:int}> $members
     */
    private function processDataFile(
        array $members,
        string $dataPath,
        ?string $pdfPath,
        int $supplierId,
        int $userId,
        bool $dryRun,
        callable $emit,
        ?string &$isdocError,
    ): bool {
        $real  = $members[$dataPath]['real'];
        $bytes = @file_get_contents($real);
        if ($bytes === false) {
            $emit(['file' => $real, 'status' => 'rejected', 'reason' => 'Nelze načíst obsah souboru']);
            return true;
        }

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        // ISDOC-first rozhodnutí (F7 §3.9) — sdílený router (stejná logika jako upload).
        $decision = $this->router->decide($bytes, $ext);
        if ($decision->useLlm) {
            // Datový soubor, který se nepodařilo přečíst jako ISDOC. AI na něj nepouštíme
            // (AI umí jen PDF) — rozhodnutí předáme volajícímu.
            $isdocError = $decision->isdocPresent
                ? ($decision->parseError ?? 'neznámá chyba')
                : 'soubor nelze parsovat jako ISDOC';
            return false;
        }

        $parsed   = (array) $decision->parsed;
        $invoices = (array) $parsed['invoices'];

        if ($dryRun) {
            $emit([
                'file'   => $real,
                'status' => 'skipped',
                'reason' => 'dry-run — nezapisuji do DB'
                    . ($pdfPath !== null ? ' (spárováno s ' . basename($pdfPath) . ')' : ''),
                'isdoc_invoice_count' => count($invoices),
                'supplier_ic'         => $parsed['supplier_ic'] ?? null,
                'paired_pdf'          => $pdfPath !== null ? basename($pdfPath) : null,
            ]);
            return true;
        }

        // Ověření dvojice je MĚKKÉ (jen varování) a jen u jednofakturového ISDOC —
        // u víc faktur v souboru se součet z PDF s jednotlivou fakturou logicky nepotká.
        $warning = null;
        if ($pdfPath !== null && count($invoices) === 1) {
            $pdfBytes = @file_get_contents($members[$pdfPath]['real']);
            if ($pdfBytes !== false) {
                $warning = $this->pairVerifier->verify($pdfBytes, (array) $invoices[0]);
            }
        }

        foreach ($invoices as $inv) {
            try {
                $result = $this->mapper->map((array) $inv, $supplierId, $userId);
                $invoiceId = (int) $result['purchase_invoice_id'];

                // Čitelný obraz: sourozenecké PDF má přednost před tím vytaženým
                // z nitra .isdocx (dodavatelův originál > náš rozbalený render).
                if ($pdfPath !== null) {
                    $this->archivePdf(
                        $invoiceId, $supplierId,
                        $members[$pdfPath]['real'], $members[$pdfPath]['sha'], $members[$pdfPath]['size'],
                    );
                } elseif ($ext === 'isdocx') {
                    // ISDOCX nese čitelné PDF uvnitř → archivuj ho pro náhled.
                    // pdf_hash = hash celého .isdocx (= klíč scannerova dedupu nahoře),
                    // ať se re-scan téhož souboru přeskočí.
                    $this->archiveIsdocxInnerPdf($invoiceId, $supplierId, $real, $members[$dataPath]['sha']);
                }

                // Strojový originál do `sources/` — vedle auditní stopy je jeho
                // `source_hash` JEDINÝ dedup klíč holého .isdoc (žádné PDF nemá).
                $this->archiveSource($invoiceId, $supplierId, $bytes, $real, $ext);

                $reason = $result['vendor_created']
                    ? 'vytvořen vendor + draft přijaté faktury'
                    : 'draft přijaté faktury (vendor reuse)';
                if ($pdfPath !== null) {
                    $reason .= ', PDF ' . basename($pdfPath) . ' spárováno podle jména (AI se nevolala)';
                }

                $detail = [
                    'file'   => $real,
                    'status' => 'created',
                    'reason' => $reason,
                    'purchase_invoice_id' => $invoiceId,
                    'paired_pdf' => $pdfPath !== null ? basename($pdfPath) : null,
                ];
                if ($warning !== null) {
                    $detail['warning'] = $warning;
                }
                $emit($detail);
            } catch (\InvalidArgumentException $e) {
                $emit(['file' => $real, 'status' => 'rejected', 'reason' => $e->getMessage()]);
            } catch (\Throwable $e) {
                $emit(['file' => $real, 'status' => 'failed', 'reason' => 'Mapper error: ' . $e->getMessage()]);
            }
        }

        return true;
    }

    /**
     * Samostatné PDF: embedded ISDOC (PDF/A-3) → deterministický import, jinak AI extrakce.
     *
     * @param array{real:string, sha:string, size:int} $pdf
     */
    private function processPdfFile(
        array $pdf,
        int $supplierId,
        int $userId,
        bool $dryRun,
        callable $emit,
        string $reasonPrefix,
    ): void {
        $real  = $pdf['real'];
        $bytes = @file_get_contents($real);
        if ($bytes === false) {
            $emit(['file' => $real, 'status' => 'rejected', 'reason' => $reasonPrefix . 'Nelze načíst obsah souboru']);
            return;
        }

        $decision = $this->router->decide($bytes, 'pdf');

        // LLM signalizován: buď žádný ISDOC (source=ai), nebo přítomný ISDOC selhal
        // parse (isdocPresent=true; router už chybu zalogoval → nikdy nebrickovat).
        // AI fallback jde jen pro nakonfigurovaného tenanta a mimo dry-run.
        if ($decision->useLlm) {
            if (!$dryRun && $this->isAiConfigured($supplierId)) {
                $aiResult = $this->aiExtractor->extractAndCreate(
                    $supplierId, $userId, $bytes, null, basename($real),
                );
                if (!empty($aiResult['ok']) && !empty($aiResult['purchase_invoice_id'])) {
                    $emit([
                        'file'   => $real,
                        'status' => 'imported',
                        'reason' => $reasonPrefix . 'AI extract',
                        'purchase_invoice_id' => $aiResult['purchase_invoice_id'],
                        'vendor_id'           => $aiResult['vendor_id'] ?? null,
                        'source'              => $aiResult['source'] ?? 'ai',
                    ]);
                    return;
                }
                // AI selhalo — pokračujeme do skipped s AI error msg.
                $emit([
                    'file'   => $real,
                    'status' => 'skipped',
                    'reason' => $reasonPrefix . 'AI extrakce selhala: ' . ($aiResult['error'] ?? 'unknown'),
                ]);
                return;
            }

            // AI nedostupné (dry-run / nenakonfigurováno). Rozliš fyzicky přítomný,
            // ale nevalidní ISDOC (→ failed) od žádného ISDOC (→ skipped).
            if ($decision->isdocPresent) {
                $emit([
                    'file'   => $real,
                    'status' => 'failed',
                    'reason' => $reasonPrefix . 'ISDOC se nepodařilo naparsovat: ' . ($decision->parseError ?? 'neznámá chyba'),
                ]);
            } else {
                $emit([
                    'file'   => $real,
                    'status' => 'skipped',
                    'reason' => $reasonPrefix . 'PDF neobsahuje ISDOC. Pro AI extrakci nakonfiguruj Anthropic Claude v Externí integrace → AI.',
                ]);
            }
            return;
        }

        // Deterministický ISDOC vytažený z PDF/A-3.
        $parsed = (array) $decision->parsed;

        if ($dryRun) {
            $emit([
                'file'   => $real,
                'status' => 'skipped',
                'reason' => $reasonPrefix . 'dry-run — nezapisuji do DB',
                'isdoc_invoice_count' => count((array) $parsed['invoices']),
                'supplier_ic'         => $parsed['supplier_ic'] ?? null,
            ]);
            return;
        }

        foreach ((array) $parsed['invoices'] as $inv) {
            try {
                $result = $this->mapper->map((array) $inv, $supplierId, $userId);
                $this->archivePdf((int) $result['purchase_invoice_id'], $supplierId, $real, $pdf['sha'], $pdf['size']);
                $emit([
                    'file'   => $real,
                    'status' => 'created',
                    'reason' => $reasonPrefix . ($result['vendor_created']
                        ? 'vytvořen vendor + draft přijaté faktury'
                        : 'draft přijaté faktury (vendor reuse)'),
                    'purchase_invoice_id' => $result['purchase_invoice_id'],
                ]);
            } catch (\InvalidArgumentException $e) {
                $emit(['file' => $real, 'status' => 'rejected', 'reason' => $reasonPrefix . $e->getMessage()]);
            } catch (\Throwable $e) {
                $emit(['file' => $real, 'status' => 'failed', 'reason' => $reasonPrefix . 'Mapper error: ' . $e->getMessage()]);
            }
        }
    }

    /**
     * Sourozenci nad rámec dvojice data+PDF (druhý datový formát téhož základu jména).
     * Nezpracovávají se, ale patří do reportu — jinak by se ztratili beze stopy.
     *
     * @param array<string, array{real:string, sha:string, size:int}> $members
     */
    private function emitExtras(InboxFileGroup $group, array $members, callable $emit, string $reason): void
    {
        foreach ($group->extras as $extra) {
            if (!isset($members[$extra])) {
                continue;
            }
            $emit(['file' => $members[$extra]['real'], 'status' => 'skipped', 'reason' => $reason]);
        }
    }

    /**
     * Bezpečnostní a čitelnostní kontrola jednoho souboru + jeho SHA-256.
     *
     * @return array{real:string, sha:string, size:int}|array{real:string, error:string}
     */
    private function inspect(string $absPath, string $inboxReal): array
    {
        // Realpath check — file MUSÍ být uvnitř inboxReal.
        // POZOR: Windows je case-insensitive FS, ale realpath() vrací path s casing
        // dle prvního použití (může se lišit mezi inboxReal a per-file real).
        // Na Linuxu je FS case-sensitive — porovnáváme striktně.
        $real = realpath($absPath);
        if ($real === false) {
            return ['real' => $absPath, 'error' => 'Nelze resolvovat realpath'];
        }
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $needle    = ($isWindows ? strtolower($inboxReal) : $inboxReal) . DIRECTORY_SEPARATOR;
        $haystack  = $isWindows ? strtolower($real) : $real;
        if (!str_starts_with($haystack, $needle)) {
            return ['real' => $real, 'error' => 'Path traversal'];
        }

        $size = @filesize($real);
        if ($size === false || $size === 0) {
            return ['real' => $real, 'error' => 'Prázdný nebo nečitelný'];
        }
        if ($size > self::MAX_FILE_SIZE) {
            return ['real' => $real, 'error' => 'Soubor větší než 20 MiB'];
        }

        $sha = hash_file('sha256', $real);
        if ($sha === false) {
            return ['real' => $real, 'error' => 'Nelze spočítat hash'];
        }

        return ['real' => $real, 'sha' => $sha, 'size' => (int) $size];
    }

    /**
     * @return list<string>
     */
    private function listFiles(string $dir, bool $recursive, array $allowedExts): array
    {
        $out = [];
        $stack = [$dir];
        while ($stack !== []) {
            $current = array_pop($stack);
            $entries = @scandir($current);
            if ($entries === false) continue;
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $path = $current . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($path)) {
                    if ($recursive) $stack[] = $path;
                    continue;
                }
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExts, true)) {
                    $out[] = $path;
                }
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Zkopíruje PDF z inboxu do archivu (mimo webroot) a uloží metadata na fakturu.
     * Dedup: pokud už existuje soubor se stejným SHA-256 v archivu, jen reuse path.
     * Sdílená archivace přes {@see PurchaseInvoicePdfArchiver}.
     */
    private function archivePdf(int $purchaseInvoiceId, int $supplierId, string $sourcePath, string $sha256, int $size): void
    {
        $this->pdfArchiver->archiveFile($purchaseInvoiceId, $supplierId, $sourcePath, basename($sourcePath), $sha256, $size);
    }

    /**
     * Archivuje čitelné PDF vytažené z ISDOCX balíčku. Na disk jdou vnitřní PDF bajty
     * (pro náhled), ale `pdf_hash` = hash celého `.isdocx` (= klíč, kterým scanner
     * deduplikuje při příštím běhu).
     */
    private function archiveIsdocxInnerPdf(int $purchaseInvoiceId, int $supplierId, string $sourcePath, string $isdocxSha256): void
    {
        $bytes = @file_get_contents($sourcePath);
        if ($bytes === false) return;
        $pkg = (new IsdocxExtractor())->unwrap($bytes);
        if ($pkg === null || $pkg['pdf'] === null) return; // balíček bez vnitřního PDF
        $this->pdfArchiver->archiveBytes(
            $purchaseInvoiceId, $supplierId, $pkg['pdf'], basename($sourcePath), $isdocxSha256,
        );
    }

    /** Uloží strojově čitelný originál (ISDOC/ISDOCX) do `sources/` + zapíše `source_hash`. */
    private function archiveSource(int $purchaseInvoiceId, int $supplierId, string $bytes, string $sourcePath, string $ext): void
    {
        $format = self::SOURCE_FORMAT[$ext] ?? null;
        if ($format === null) {
            return;
        }
        $this->pdfArchiver->archiveSourceBytes($purchaseInvoiceId, $supplierId, $bytes, basename($sourcePath), $format);
    }

    private function emptyResult(string $inboxDir, bool $dryRun, array $details): array
    {
        return [
            'created'   => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'dry_run'   => $dryRun,
            'inbox_dir' => $inboxDir,
            'details'   => $details,
        ];
    }

    /**
     * Zda má tenant nakonfigurovanou Anthropic API key pro AI extract.
     *
     * Credentials uloženy v supplier.anthropic_api_key_enc (varbinary, encrypted).
     * Pokud sloupec neexistuje (legacy install před fází 2c), vrátí false.
     */
    private function isAiConfigured(int $supplierId): bool
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT 1 FROM supplier WHERE id = ? AND anthropic_api_key_enc IS NOT NULL LIMIT 1'
            );
            $stmt->execute([$supplierId]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
