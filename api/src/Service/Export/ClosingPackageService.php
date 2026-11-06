<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\Reports\AccrualsReportService;
use MyInvoice\Service\Accounting\Reports\AssetDepreciationCardReportService;
use MyInvoice\Service\Accounting\Reports\BalanceInventoryService;
use MyInvoice\Service\Accounting\Reports\AssetInventoryReportService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\GeneralLedgerService;
use MyInvoice\Service\Accounting\Reports\JournalExportService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Accounting\Reports\SmallAssetReportService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Import\CancelledException;
use MyInvoice\Service\Pdf\AssetDepreciationCardPdfRenderer;
use MyInvoice\Service\Pdf\AssetInventoryPdfRenderer;
use MyInvoice\Service\Pdf\BalanceInventoryPdfRenderer;
use MyInvoice\Service\Pdf\BalanceSheetPdfRenderer;
use MyInvoice\Service\Pdf\DphBookPdfRenderer;
use MyInvoice\Service\Pdf\GeneralLedgerPdfRenderer;
use MyInvoice\Service\Pdf\IncomeStatementPdfRenderer;
use MyInvoice\Service\Pdf\IncomeTaxAdvanceNoticePdfRenderer;
use MyInvoice\Service\Pdf\JournalPdfRenderer;
use MyInvoice\Service\Pdf\SaldoPdfRenderer;
use MyInvoice\Service\Pdf\SmallAssetInventoryPdfRenderer;
use MyInvoice\Service\Pdf\TrialBalancePdfRenderer;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\IncomeTaxAdvanceNoticeReportService;
use MyInvoice\Service\Tax\Return\TaxReturnReportService;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use ZipArchive;

/**
 * Uzávěrkový balíček — sbalí VŠECHNY sestavy k uzávěrce zvoleného účetního období do
 * jednoho ZIPu s pojmenovanými soubory (obdoba MonthlyExportService, ale pro účetní
 * sestavy uzávěrky, ne pro doklady měsíce/kvartálu). Běží jako background job
 * (import_jobs, source='closing_package'), protože sestavení všech sestav najednou
 * (zejm. Kniha DPH per měsíc) může přesáhnout web timeout.
 *
 * Obsah ZIPu (v1 = PDF, volitelně + XLSX u sestav, které to podporují):
 *   Rozvaha/rozvaha-<rok>.pdf(.xlsx)
 *   Vysledovka/vysledovka-<rok>.pdf(.xlsx)
 *   Hlavni-kniha/hlavni-kniha-<rok>.pdf(.xlsx)
 *   Ucetni-denik/ucetni-denik-<rok>.pdf(.xlsx)
 *   Obratova-predvaha/obratova-predvaha-<rok>.pdf(.xlsx)
 *   Kniha-DPH/kniha-dph-<rok>-<měsíc>.pdf  (jeden soubor per kalendářní měsíc období)
 *   Dan-z-prijmu/dpfdp7-<rok>.xml | dppdp9-<rok>.xml
 *   Dan-z-prijmu/dpfdp7-<rok>-sestava.pdf | dppdp9-<rok>-sestava.pdf (pracovní PDF sestava přiznání)
 *   Dan-a-zalohy/predpis-zaloh-38a-<rok>.pdf (jen PO)
 *   Inventarizace-majetku/dlouhodoby-majetek-<rok>.pdf(.xlsx) + drobny-majetek-<rok>.pdf(.xlsx)
 *   Inventarizace-majetku/karty/karta-<N>.pdf (inventární karta na majetek, §29–30 ZoÚ)
 *   Saldokonta-nad-1-rok/saldo-nad-1-rok-<rok>.pdf(.xlsx)
 *   Inventarizace-uctu/inventarizace-uctu-<rok>.pdf(.xlsx) (vyplněný protokol s podpisy)
 *   Inventarizace-uctu/otevrene-polozky-<rok>.pdf(.xlsx) (261, 335, 395 a zúčtovací účty)
 *   Casova-rozliseni/casove-rozliseni-<rok>.pdf(.xlsx)
 *   Priloha-k-zaverce/priloha-<rok>.pdf + .txt (§ 18/1/c ZoÚ)
 *   README.txt
 *
 * Dostupné jen pro firmy vedené v podvojném účetnictví (guard v Action) — sestavy
 * čerpají z accounting_periods / journal_entries, ne z daňové evidence.
 */
final class ClosingPackageService
{
    /** Všechny podporované sestavy (a zároveň default, když uživatel nic nezvolí). */
    public const ALL_PARTS = [
        'balance_sheet', 'income_statement', 'general_ledger',
        'trial_balance', 'journal', 'balance_inventory', 'dph_book', 'income_tax', 'income_tax_report', 'income_tax_advances',
        'asset_inventory', 'saldo_over_1y', 'accruals', 'statement_notes',
        'cash_flow', 'equity_changes', 'open_items',
    ];

    /**
     * EP-6: POVINNÉ jádro balíčku — části, které musí vzniknout, jinak je balíček `failed`
     * (nesmí být „hotovo" jen proto, že vznikl aspoň jeden soubor). Jde o sestavy, které se
     * dají sestavit nad každým podvojným obdobím a bez nichž závěrka není úplná (§18 ZoÚ) +
     * inventarizace rozvahových účtů (§29–30 ZoÚ). Ostatní části jsou DOPLŇKOVÉ: jejich
     * selhání/přeskočení dá stav `completed_with_warnings`, ne `failed` (Kniha DPH per měsíc,
     * přiznání k dani jen dle typu poplatníka, saldo/majetek/časové rozlišení dle dat).
     */
    public const REQUIRED_PARTS = [
        'balance_sheet', 'income_statement', 'general_ledger',
        'trial_balance', 'journal', 'balance_inventory',
        // Příloha je podle § 18 odst. 1 písm. c) součástí závěrky stejně jako rozvaha
        // a výsledovka — balíček bez ní by nesměl hlásit „hotovo". Neúplný OBSAH přílohy
        // ale balíček neshazuje, jen varuje: doplňuje se v průběhu uzávěrky.
        'statement_notes',
    ];

    /** Sestavy, které podporují i XLSX (Kniha DPH a přiznání k dani jen PDF/XML). */
    private const XLSX_CAPABLE = [
        'balance_sheet', 'income_statement', 'general_ledger', 'trial_balance', 'journal',
        'balance_inventory', 'asset_inventory', 'saldo_over_1y', 'accruals',
        'cash_flow', 'equity_changes', 'open_items',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly ImportJobRepository $jobs,
        private readonly ActivityLogger $logger,
        private readonly AccountingPeriodRepository $periods,
        private readonly \MyInvoice\Service\Accounting\Reports\StatementSupplementPolicy $frameworks,
        private readonly FinancialStatementService $statements,
        private readonly BalanceSheetPdfRenderer $balanceSheetPdf,
        private readonly IncomeStatementPdfRenderer $incomeStatementPdf,
        private readonly GeneralLedgerService $ledgerService,
        private readonly GeneralLedgerPdfRenderer $ledgerPdf,
        private readonly TrialBalanceService $trialBalanceService,
        private readonly TrialBalancePdfRenderer $trialBalancePdf,
        private readonly JournalExportService $journalExportService,
        private readonly JournalPdfRenderer $journalPdf,
        private readonly DphBookBuilder $dphBookBuilder,
        private readonly DphBookPdfRenderer $dphBookRenderer,
        private readonly TaxReturnService $taxReturns,
        private readonly TaxReturnReportService $taxReturnReports,
        private readonly IncomeTaxAdvanceNoticeReportService $advanceNoticeReport,
        private readonly IncomeTaxAdvanceNoticePdfRenderer $advanceNoticePdf,
        private readonly ReportXlsxExporter $xlsx,
        private readonly AssetInventoryReportService $assetInventoryReport,
        private readonly AssetInventoryPdfRenderer $assetInventoryPdf,
        private readonly AssetDepreciationCardReportService $assetDepreciationCardReport,
        private readonly AssetDepreciationCardPdfRenderer $assetDepreciationCardPdf,
        private readonly SmallAssetReportService $smallAssetReport,
        private readonly SmallAssetInventoryPdfRenderer $smallAssetInventoryPdf,
        private readonly SaldoService $saldoService,
        private readonly SaldoPdfRenderer $saldoPdf,
        private readonly AccrualsReportService $accrualsReport,
        private readonly \MyInvoice\Service\Accounting\Reports\StatementNotesService $statementNotes,
        private readonly \MyInvoice\Service\Pdf\StatementNotesPdfRenderer $statementNotesPdf,
        private readonly BalanceInventoryPdfRenderer $balanceInventoryPdf,
        private readonly BalanceInventoryService $balanceInventoryReport,
        private readonly \MyInvoice\Service\Accounting\Reports\CashFlowStatementService $cashFlowService,
        private readonly \MyInvoice\Service\Pdf\CashFlowPdfRenderer $cashFlowPdf,
        private readonly \MyInvoice\Service\Accounting\Reports\EquityChangesStatementService $equityService,
        private readonly \MyInvoice\Service\Pdf\EquityChangesPdfRenderer $equityPdf,
        private readonly \MyInvoice\Service\Accounting\Reports\OpenItemsService $openItems,
        private readonly \MyInvoice\Service\Pdf\OpenItemsListPdfRenderer $openItemsPdf,
    ) {}

    /** Verze generátoru balíčku (manifest.json, EP-6). */
    private const MANIFEST_VERSION = '1';

    /** Absolutní základ úložiště ZIPů (pod data_dir, jinak repo root). */
    public function storageBaseDir(): string
    {
        return ($this->config->dataDir() ?? \MyInvoice\Bootstrap::rootDir())
            . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'closing-packages';
    }

    /** Absolutní cesta k souboru z relativního result_path (sup-N/file.zip). */
    public function resolveResultPath(string $relative): string
    {
        return $this->storageBaseDir() . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * @param list<string> $requested
     * @return list<string>
     */
    public static function normalizeParts(array $requested): array
    {
        $valid = array_values(array_intersect($requested, self::ALL_PARTS));
        return $valid !== [] ? $valid : self::ALL_PARTS;
    }

    public function partsForPeriod(int $supplierId, int $periodId, array $requested): array
    {
        $framework = $this->frameworks->framework($supplierId, $periodId);
        $parts = self::normalizeParts($requested);
        if ($framework === 'cz_504' && array_intersect($requested, self::ALL_PARTS) === []) {
            $parts = array_values(array_diff($parts, ['cash_flow', 'equity_changes']));
        }
        return $parts;
    }

    /**
     * EP-6: klasifikace stavu balíčku (čistá funkce, bez DB — testovatelná). Balíček je
     * `failed`, když nevzniklo NIC nebo když některá POVINNÁ (REQUIRED_PARTS) vyžádaná
     * část nevyprodukovala soubor (nesmí být „hotovo" jen proto, že vznikl aspoň jeden
     * soubor); `completed_with_warnings`, když povinné jádro je kompletní, ale nějaká
     * doplňková část selhala/přeskočila (warnings); jinak `completed`.
     *
     * @param list<string> $requestedParts vyžádané části
     * @param list<string> $producedParts  klíče částí, které vyprodukovaly aspoň 1 soubor
     * @return array{status:'completed'|'completed_with_warnings'|'failed', missing_required:list<string>}
     */
    public static function classifyStatus(array $requestedParts, array $producedParts, bool $hasWarnings): array
    {
        $requiredRequested = array_values(array_intersect($requestedParts, self::REQUIRED_PARTS));
        $missingRequired = array_values(array_filter(
            $requiredRequested,
            static fn (string $p): bool => !in_array($p, $producedParts, true),
        ));
        if ($producedParts === [] || $missingRequired !== []) {
            return ['status' => 'failed', 'missing_required' => $missingRequired];
        }
        return ['status' => $hasWarnings ? 'completed_with_warnings' : 'completed', 'missing_required' => []];
    }

    /**
     * Dostupnost sestav pro UI checkboxy (preview). Standardní sestavy jsou vždy
     * nabídnuté (jdou sestavit i nad prázdným obdobím); Kniha DPH ukazuje počet
     * kalendářních měsíců období, přiznání k dani jen když firma má vyplněný typ
     * poplatníka (fo/po).
     *
     * @return array<string,int>
     */
    public function previewCounts(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            return array_fill_keys(self::ALL_PARTS, 0);
        }
        $months = $this->monthsInPeriod((string) $period['starts_on'], (string) $period['ends_on']);
        $is504 = $this->frameworks->framework($supplierId, $periodId) === 'cz_504';
        return [
            'balance_sheet'     => 1,
            'income_statement'  => 1,
            'general_ledger'    => 1,
            'trial_balance'     => 1,
            'journal'           => 1,
            'balance_inventory' => 1,
            'dph_book'          => count($months),
            'income_tax'        => $this->taxpayerType($supplierId) !== null ? 1 : 0,
            'income_tax_report' => $this->taxpayerType($supplierId) !== null ? 1 : 0,
            'income_tax_advances' => $this->taxpayerType($supplierId) === 'po' ? 1 : 0,
            'asset_inventory'   => 1,
            'saldo_over_1y'     => 1,
            'accruals'          => 1,
            'statement_notes'   => 1,
            'cash_flow'         => $is504 ? 0 : 1,
            'equity_changes'    => $is504 ? 0 : 1,
            'open_items'        => 1,
        ];
    }

    public function previewMetadata(int $supplierId, int $periodId): array
    {
        return $this->frameworks->framework($supplierId, $periodId) === 'cz_504'
            ? ['accounting_framework' => 'cz_504'] : [];
    }

    /**
     * Příloha jako čitelný text: nadpis sekce, právní opora a obsah. Nevyplněné povinné
     * sekce se do souboru vypíší VÝSLOVNĚ jako chybějící — vynechat je by dalo dokument,
     * který vypadá hotově, ačkoli závěrka úplná není.
     *
     * @param array<string,mixed> $notes výstup {@see StatementNotesService::build()}
     */
    private static function renderNotes(array $notes): string
    {
        $out = ["PŘÍLOHA K ÚČETNÍ ZÁVĚRCE ZA ROK " . (int) $notes['fiscal_year'], ''];
        $out[] = $notes['legal_basis'] ?? '§ 18 odst. 1 písm. c) zákona č. 563/1991 Sb., § 39/39a/39b vyhlášky č. 500/2002 Sb.';
        if (isset($notes['period'])) $out[] = 'Účetní období: ' . $notes['period']['starts_on'] . ' - ' . $notes['period']['ends_on'];
        $out[] = 'Kategorie účetní jednotky: ' . (string) $notes['category'];
        $out[] = str_repeat('=', 78);
        $out[] = '';

        foreach ($notes['sections'] as $s) {
            $out[] = mb_strtoupper((string) $s['label']);
            $out[] = '(' . (string) $s['legal'] . ')';
            $out[] = $s['filled']
                ? (string) $s['content']
                : '*** NEVYPLNĚNO — povinný údaj přílohy ***';
            $out[] = '';
            $out[] = str_repeat('-', 78);
            $out[] = '';
        }

        if ($notes['missing'] !== []) {
            $out[] = 'UPOZORNĚNÍ: příloha není úplná, chybí ' . count($notes['missing']) . ' povinných sekcí.';
        }

        return implode("\r\n", $out);
    }

    /**
     * Worker entrypoint — vyrobí ZIP a uloží ho jako výsledek jobu.
     * Vlastní try/catch → markFailed; cancel přes cancel_requested flag.
     */
    public function run(int $jobId): void
    {
        $job = $this->findJob($jobId);
        if ($job === null) return;
        if (!$this->jobs->markRunning($jobId)) return;

        $supplierId = (int) $job['supplier_id'];
        $userId = (int) ($job['created_by'] ?? 0) ?: null;
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $periodId = (int) ($params['period_id'] ?? 0);
        $requestedParts = array_map('strval', (array) ($params['parts'] ?? []));
        $includeXlsx = (bool) ($params['include_xlsx'] ?? false);

        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            $this->jobs->markFailed($jobId, 'Účetní období #' . $periodId . ' neexistuje.');
            $this->logFinished($jobId, $userId, $supplierId, (string) $periodId, 'failed', ['error' => 'period_not_found']);
            return;
        }
        $fiscalYear = (int) $period['fiscal_year'];
        $startsOn = (string) $period['starts_on'];
        $endsOn = (string) $period['ends_on'];

        try {
            $is504 = $this->frameworks->framework($supplierId, $periodId) === 'cz_504';
            $parts = $this->partsForPeriod($supplierId, $periodId, $requestedParts);
            $companyName = $this->supplierCompanyName($supplierId);

            $relDir = 'sup-' . $supplierId;
            $absDir = $this->storageBaseDir() . DIRECTORY_SEPARATOR . $relDir;
            if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
                throw new \RuntimeException('Nelze vytvořit úložiště exportů.');
            }
            $companySlug = $this->asciiSlug($companyName);
            $fileName = $companySlug !== ''
                ? sprintf('%s-uzaverkovy-balicek-%d.zip', $companySlug, $fiscalYear)
                : sprintf('myucto-uzaverkovy-balicek-%d.zip', $fiscalYear);
            $relPath = $relDir . '/' . $jobId . '-' . $fileName;
            $absPath = $this->storageBaseDir() . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relPath);

            $zip = new ZipArchive();
            if ($zip->open($absPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Nelze vytvořit ZIP.');
            }

            $dphMonths = in_array('dph_book', $parts, true) ? $this->monthsInPeriod($startsOn, $endsOn) : [];
            $total = count(array_diff($parts, ['dph_book'])) + count($dphMonths);
            $this->jobs->updateProgress($jobId, ['total_items' => max($total, 1), 'current_step' => 'Příprava…']);

            $added = 0;
            $processed = 0;
            $summary = [];
            $warnings = [];
            // EP-10b: pokud se knihy uzavřely přes nezaúčtované doklady (oprávněný override),
            // výjimka je v payloadu kroku close_books — do balíčku ji promítneme jako zřetelný
            // warning (README + manifest) s důvodem a počtem dokladů.
            $override = $this->closeBooksUnpostedOverride($supplierId, $periodId);
            if ($override !== null) {
                $reason = (string) ($override['reason'] ?? '');
                $warnings[] = sprintf(
                    'Knihy byly uzavřeny přes %d nezaúčtovaných aktivních dokladů (override). Důvod: %s',
                    (int) ($override['count'] ?? 0),
                    $reason !== '' ? $reason : '(neuveden)',
                );
            }
            // EP-6: manifest — každý zapsaný soubor s SHA-256 a velikostí.
            $files = [];

            // Zapiš soubor do ZIPu a zaeviduj ho do manifestu (path + SHA-256 + bytes).
            $put = function (string $path, string $bytes) use ($zip, &$files): void {
                $zip->addFromString($path, $bytes);
                $files[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
            };

            $bump = function (string $step) use (&$processed, $jobId): void {
                $processed++;
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'current_step' => $step]);
            };

            // 1) Rozvaha
            if (in_array('balance_sheet', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                try {
                    $data = $this->statements->balanceSheet($supplierId, $periodId, $is504 ? $endsOn : null, 'auto');
                    $put(sprintf('Rozvaha/rozvaha-%d.pdf', $fiscalYear), $this->balanceSheetPdf->render($data));
                    $added++; $summary['balance_sheet'] = 1;
                    if ($includeXlsx) {
                        $out = $this->xlsx->balanceSheet($data, 'czk');
                        $put(sprintf('Rozvaha/rozvaha-%d.xlsx', $fiscalYear), $out['bytes']);
                    }
                } catch (\Throwable $e) { $warnings[] = 'Rozvaha: ' . $e->getMessage(); }
                $bump('Rozvaha');
            }

            // 2) Výsledovka
            if (in_array('income_statement', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                try {
                    $data = $this->statements->incomeStatement($supplierId, $periodId, $is504 ? $endsOn : null, 'auto');
                    $put(sprintf('Vysledovka/vysledovka-%d.pdf', $fiscalYear), $this->incomeStatementPdf->render($data));
                    $added++; $summary['income_statement'] = 1;
                    if ($includeXlsx) {
                        $out = $this->xlsx->incomeStatement($data, 'czk');
                        $put(sprintf('Vysledovka/vysledovka-%d.xlsx', $fiscalYear), $out['bytes']);
                    }
                } catch (\Throwable $e) { $warnings[] = 'Výsledovka: ' . $e->getMessage(); }
                $bump('Výsledovka');
            }

            // 3) Hlavní kniha
            if (in_array('general_ledger', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                try {
                    $data = $this->ledgerService->build($supplierId, $periodId, null, null, false);
                    $put(sprintf('Hlavni-kniha/hlavni-kniha-%d.pdf', $fiscalYear), $this->ledgerPdf->render($data));
                    $added++; $summary['general_ledger'] = 1;
                    if ($includeXlsx) {
                        $out = $this->xlsx->generalLedger($data);
                        $put(sprintf('Hlavni-kniha/hlavni-kniha-%d.xlsx', $fiscalYear), $out['bytes']);
                    }
                } catch (\Throwable $e) { $warnings[] = 'Hlavní kniha: ' . $e->getMessage(); }
                $bump('Hlavní kniha');
            }

            // 4) Obratová předvaha
            if (in_array('trial_balance', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                try {
                    $data = $this->trialBalanceService->build($supplierId, $periodId, null, null, false);
                    $put(sprintf('Obratova-predvaha/obratova-predvaha-%d.pdf', $fiscalYear), $this->trialBalancePdf->render($data));
                    $added++; $summary['trial_balance'] = 1;
                    if ($includeXlsx) {
                        $out = $this->xlsx->trialBalance($data);
                        $put(sprintf('Obratova-predvaha/obratova-predvaha-%d.xlsx', $fiscalYear), $out['bytes']);
                    }
                } catch (\Throwable $e) { $warnings[] = 'Obratová předvaha: ' . $e->getMessage(); }
                $bump('Obratová předvaha');
            }

            // 5) Účetní deník
            if (in_array('journal', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                try {
                    // Rok nad strop jednoho souboru se sestaví po měsících (paměť workeru).
                    try {
                        $chunks = [[sprintf('ucetni-denik-%d', $fiscalYear),
                            $this->journalExportService->build($supplierId, ['period_id' => $periodId], JournalExportService::PACKAGE_MAX_ROWS)]];
                    } catch (ReportException $e) {
                        if ($e->errorCode !== 'too_many_rows') {
                            throw $e;
                        }
                        $chunks = [];
                    }
                    foreach ($chunks === [] ? $this->monthsInPeriod($startsOn, $endsOn) : [] as [$y, $m]) {
                        $from = max($startsOn, sprintf('%04d-%02d-01', $y, $m));
                        $to = min($endsOn, (new \DateTimeImmutable($from))->format('Y-m-t'));
                        $chunks[] = [sprintf('ucetni-denik-%d-%02d', $y, $m), fn (): array => $this->journalExportService->build(
                            $supplierId, ['period_id' => $periodId, 'date_from' => $from, 'date_to' => $to], JournalExportService::PACKAGE_MAX_ROWS)];
                    }
                    foreach ($chunks as [$name, $data]) {
                        $this->ensureNotCancelled($jobId, $zip, $absPath);
                        $data = is_callable($data) ? $data() : $data;
                        $put("Ucetni-denik/{$name}.pdf", $this->journalPdf->render($data));
                        if ($includeXlsx) {
                            $put("Ucetni-denik/{$name}.xlsx", $this->xlsx->journal($data)['bytes']);
                        }
                        unset($data);
                    }
                    $added++; $summary['journal'] = 1;
                } catch (ReportException $e) { $warnings[] = 'Účetní deník: ' . $e->getMessage(); }
                catch (\Throwable $e) { $warnings[] = 'Účetní deník: ' . $e->getMessage(); }
                $bump('Účetní deník');
            }

            // 5b) Inventarizace rozvahových účtů (§29–30 ZoÚ, EP-6) — soupis KZ rozvahových
            //     účtů k rozvahovému dni + uložený skutečný stav / rozdíly / odpovědná osoba /
            //     protokol. POVINNÁ část balíčku (bez ní není závěrka úplná).
            if (in_array('balance_inventory', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                try {
                    $data = $this->balanceInventoryReport->buildWithSaved($supplierId, $periodId);
                    $put(sprintf('Inventarizace-uctu/inventarizace-uctu-%d.pdf', $fiscalYear), $this->balanceInventoryPdf->render($data));
                    $added++; $summary['balance_inventory'] = 1;
                    if ($includeXlsx) {
                        $out = $this->xlsx->balanceInventory($data);
                        $put(sprintf('Inventarizace-uctu/inventarizace-uctu-%d.xlsx', $fiscalYear), $out['bytes']);
                    }
                    // Balíček s nedokončeným protokolem nesmí vypadat hotově.
                    $inventory = $data['inventory'];
                    if (!$inventory['can_close']) {
                        $warnings[] = 'Inventarizace rozvahových účtů není dokončená: nevyřešených účtů ' . (int) $inventory['unresolved_count'] . '.';
                    }
                } catch (\Throwable $e) { $warnings[] = 'Inventarizace rozvahových účtů: ' . $e->getMessage(); }
                $bump('Inventarizace rozvahových účtů');
            }

            // 5c) Soupis otevřených položek k rozvahovému dni jako podklad inventarizace
            //     průběžných účtů. Doplňkový: firma takové účty mít nemusí.
            if (in_array('open_items', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                try {
                    $data = $this->openItems->listing($supplierId, $endsOn, [], 100000);
                    if ($data['accounts'] !== []) {
                        $put(sprintf('Inventarizace-uctu/otevrene-polozky-%d.pdf', $fiscalYear), $this->openItemsPdf->render($data));
                        $added++; $summary['open_items'] = 1;
                        if ($includeXlsx) {
                            $out = $this->xlsx->openItemsList($data);
                            $put(sprintf('Inventarizace-uctu/otevrene-polozky-%d.xlsx', $fiscalYear), $out['bytes']);
                        }
                        if ((int) $data['totals']['unmatched'] > 0) {
                            $warnings[] = 'Soupis otevřených položek: součet nesedí na zůstatek u ' . (int) $data['totals']['unmatched'] . ' účtů.';
                        }
                    }
                } catch (\Throwable $e) { $warnings[] = 'Soupis otevřených položek: ' . $e->getMessage(); }
                $bump('Soupis otevřených položek');
            }

            // 6) Kniha DPH — per kalendářní měsíc období.
            if ($dphMonths !== []) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                foreach ($dphMonths as [$bookYear, $bookMon]) {
                    $this->ensureNotCancelled($jobId, $zip, $absPath);
                    try {
                        $data = $this->dphBookBuilder->build($supplierId, $bookYear, $bookMon);
                        if (!empty($data['sections'])) {
                            $put(sprintf('Kniha-DPH/kniha-dph-%04d-%02d.pdf', $bookYear, $bookMon), $this->dphBookRenderer->render($data));
                            $added++; $summary['dph_book'] = ($summary['dph_book'] ?? 0) + 1;
                        }
                    } catch (\Throwable $e) { $warnings[] = sprintf('Kniha DPH %04d-%02d: %s', $bookYear, $bookMon, $e->getMessage()); }
                    $bump('Kniha DPH');
                }
            }

            // 7) Daň z příjmů (DPPO/DPFO XML)
            if (in_array('income_tax', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                $type = $this->taxpayerType($supplierId);
                if ($type !== null) {
                    try {
                        $built = $this->taxReturns->buildXml($supplierId, $fiscalYear, $type);
                        $put('Dan-z-prijmu/' . $built['filename'], $built['xml']);
                        $added++; $summary['income_tax'] = 1;
                        foreach ($built['warnings'] as $w) {
                            $warnings[] = 'Daň z příjmů: ' . $w;
                        }
                    } catch (\Throwable $e) { $warnings[] = 'Daň z příjmů: ' . $e->getMessage(); }
                } else {
                    $warnings[] = 'Daň z příjmů: firma nemá vyplněný typ poplatníka (fo/po) — přeskočeno.';
                }
                $bump('Daň z příjmů');
            }

            // 7a) Pracovní PDF sestava přiznání (DPPO/DPFO). Čte částky ze stejného XML jako
            //     export pro EPO; když balík XML nenese z části income_tax, přibalí se vedle.
            //     Přiznání, které za rok sestavit nejde, se vynechá s upozorněním, balík doběhne.
            if (in_array('income_tax_report', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                $type = $this->taxpayerType($supplierId);
                if ($type !== null) {
                    try {
                        $report = $this->taxReturnReports->pdf($supplierId, $fiscalYear, $type);
                        $reportFiles = [['Dan-z-prijmu/' . $report['filename'], $report['pdf']]];
                        $xmlWarnings = [];
                        if (!in_array('income_tax', $parts, true)) {
                            $built = $this->taxReturns->buildXml($supplierId, $fiscalYear, $type);
                            $reportFiles[] = ['Dan-z-prijmu/' . $built['filename'], $built['xml']];
                            $xmlWarnings = $built['warnings'];
                        }
                        foreach ($reportFiles as [$path, $bytes]) {
                            $put($path, $bytes);
                        }
                        $added += count($reportFiles); $summary['income_tax_report'] = count($reportFiles);
                        foreach ($xmlWarnings as $w) {
                            $warnings[] = 'Daň z příjmů: ' . $w;
                        }
                    } catch (\Throwable $e) { $warnings[] = 'Sestava přiznání k dani z příjmů: ' . $e->getMessage(); }
                } else {
                    $warnings[] = 'Sestava přiznání k dani z příjmů: firma nemá vyplněný typ poplatníka (fo/po), přeskočeno.';
                }
                $bump('Sestava přiznání k dani z příjmů');
            }

            // 7b) Placení záloh na daň dle §38a (jen PO — DPFO §38a se v systému negeneruje)
            if (in_array('income_tax_advances', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                $type = $this->taxpayerType($supplierId);
                if ($type === 'po') {
                    try {
                        $data = $this->advanceNoticeReport->build($supplierId, $fiscalYear);
                        $put(
                            sprintf('Dan-a-zalohy/predpis-zaloh-38a-%d.pdf', $fiscalYear),
                            $this->advanceNoticePdf->render($data)
                        );
                        $added++; $summary['income_tax_advances'] = 1;
                    } catch (\Throwable $e) { $warnings[] = 'Placení záloh §38a: ' . $e->getMessage(); }
                } else {
                    $warnings[] = 'Placení záloh §38a: sestava je jen pro poplatníky PO — přeskočeno.';
                }
                $bump('Placení záloh §38a');
            }

            // 8) Inventarizace majetku — dlouhodobý majetek (karty assets) + drobný majetek (§DM)
            if (in_array('asset_inventory', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                $assetFiles = 0;
                try {
                    $data = $this->assetInventoryReport->build($supplierId, $periodId);
                    $put(sprintf('Inventarizace-majetku/dlouhodoby-majetek-%d.pdf', $fiscalYear), $this->assetInventoryPdf->render($data));
                    $assetFiles++;
                    if ($includeXlsx) {
                        $out = $this->xlsx->assetInventory($data);
                        $put(sprintf('Inventarizace-majetku/dlouhodoby-majetek-%d.xlsx', $fiscalYear), $out['bytes']);
                    }
                } catch (\Throwable $e) { $warnings[] = 'Inventarizace majetku (dlouhodobý majetek): ' . $e->getMessage(); }
                try {
                    $cardsData = $this->assetDepreciationCardReport->build($supplierId, $periodId);
                    foreach ($cardsData['cards'] as $card) {
                        try {
                            $cardPdf = $cardsData;
                            $cardPdf['cards'] = [$card];
                            $put(
                                sprintf('Inventarizace-majetku/karty/karta-%d.pdf', $card['card_number']),
                                $this->assetDepreciationCardPdf->render($cardPdf)
                            );
                            $assetFiles++;
                        } catch (\Throwable $e) {
                            $warnings[] = sprintf('Inventarizace majetku (karta %d): %s', $card['card_number'], $e->getMessage());
                        }
                    }
                } catch (\Throwable $e) { $warnings[] = 'Inventarizace majetku (karty dlouhodobého majetku): ' . $e->getMessage(); }
                try {
                    $smallData = $this->smallAssetReport->inventory($supplierId, $endsOn);
                    $put(sprintf('Inventarizace-majetku/drobny-majetek-%d.pdf', $fiscalYear), $this->smallAssetInventoryPdf->render($smallData));
                    $assetFiles++;
                    if ($includeXlsx) {
                        $out = $this->xlsx->smallAssetInventory($smallData);
                        $put(sprintf('Inventarizace-majetku/drobny-majetek-%d.xlsx', $fiscalYear), $out['bytes']);
                    }
                } catch (\Throwable $e) { $warnings[] = 'Inventarizace majetku (drobný majetek): ' . $e->getMessage(); }
                if ($assetFiles > 0) { $added += $assetFiles; $summary['asset_inventory'] = $assetFiles; }
                $bump('Inventarizace majetku');
            }

            // 9) Saldokonta pohledávek a závazků starší 1 roku
            if (in_array('saldo_over_1y', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                try {
                    $data = $this->saldoService->build($supplierId, $periodId, null);
                    $aged = $this->filterSaldoOlderThanYear($data);
                    $put(sprintf('Saldokonta-nad-1-rok/saldo-nad-1-rok-%d.pdf', $fiscalYear), $this->saldoPdf->render($aged));
                    $added++; $summary['saldo_over_1y'] = 1;
                    if ($includeXlsx) {
                        $out = $this->xlsx->saldo($aged);
                        $put(sprintf('Saldokonta-nad-1-rok/saldo-nad-1-rok-%d.xlsx', $fiscalYear), $out['bytes']);
                    }
                } catch (\Throwable $e) { $warnings[] = 'Saldokonta nad 1 rok: ' . $e->getMessage(); }
                $bump('Saldokonta nad 1 rok');
            }

            // 10) Časové rozlišení (381–385)
            if (in_array('accruals', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                try {
                    $data = $this->accrualsReport->build($supplierId, $periodId);
                    $put(sprintf('Casova-rozliseni/casove-rozliseni-%d.pdf', $fiscalYear), $this->balanceInventoryPdf->render($data));
                    $added++; $summary['accruals'] = 1;
                    if ($includeXlsx) {
                        $out = $this->xlsx->balanceInventory($data);
                        $put(sprintf('Casova-rozliseni/casove-rozliseni-%d.xlsx', $fiscalYear), $out['bytes']);
                    }
                } catch (\Throwable $e) { $warnings[] = 'Časové rozlišení: ' . $e->getMessage(); }
                $bump('Časové rozlišení');
            }

            // 11) Příloha k účetní závěrce (§ 18/1/c ZoÚ, § 39/39a/39b vyhl. 500/2002).
            // Chyběla, přestože komentář u REQUIRED_PARTS § 18 sám cituje — a bez přílohy
            // závěrka není úplná. Primárně PDF (oficiální dokument závěrky, stejně jako
            // ostatní části balíčku); TXT zůstává vedle jako strojově/copy-paste čitelná
            // verze téhož obsahu.
            if (in_array('statement_notes', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                try {
                    $notes = $this->statementNotes->build($supplierId, $periodId);
                    $put(sprintf('Priloha-k-zaverce/priloha-%d.pdf', $fiscalYear), $this->statementNotesPdf->render([
                        'notes'  => $notes,
                        'entity' => $this->statements->entityHeader($supplierId),
                        'period' => ['starts_on' => $startsOn, 'ends_on' => $endsOn],
                    ]));
                    $put(sprintf('Priloha-k-zaverce/priloha-%d.txt', $fiscalYear), self::renderNotes($notes));
                    $added++; $summary['statement_notes'] = 1;
                    if ($notes['missing'] !== []) {
                        // Neúplnou přílohu je nutné hlásit: balíček s ní vypadá hotově,
                        // ale závěrka úplná není.
                        $warnings[] = 'Příloha k závěrce není úplná — chybí sekce: '
                            . implode(', ', $notes['missing']) . '.';
                    }
                } catch (\Throwable $e) { $warnings[] = 'Příloha k závěrce: ' . $e->getMessage(); }
                $bump('Příloha k závěrce');
            }

            // 12) Přehledy podle § 18 odst. 2 ZoÚ (§ 40–44 vyhl. 500/2002 Sb.).
            // Uměly se spočítat a zobrazit, ale nešly dostat ven — uzávěrka na to jen
            // upozorňovala varováním „přiložte ručně". Velká a střední ÚJ (a každá
            // s povinným auditem) je přitom má jako povinnou součást závěrky stejně jako
            // rozvahu a výsledovku, takže balíček bez nich byl neúplný.
            //
            // Doplňkové, ne povinné: u mikro a malé ÚJ povinnost není a balíček by kvůli
            // nim neměl padat na `failed`.
            if (in_array('cash_flow', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                try {
                    $data = $this->cashFlowService->build($supplierId, $periodId);
                    $data['entity'] = $this->statements->entityHeader($supplierId);
                    $put(sprintf('Prehledy-18-2/penezni-toky-%d.pdf', $fiscalYear), $this->cashFlowPdf->render($data));
                    $added++; $summary['cash_flow'] = 1;
                    if ($includeXlsx) {
                        $out = $this->xlsx->cashFlow($data);
                        $put(sprintf('Prehledy-18-2/penezni-toky-%d.xlsx', $fiscalYear), $out['bytes']);
                    }
                    if (($data['reconciles'] ?? true) !== true) {
                        $warnings[] = 'Přehled o peněžních tocích nesedí: počáteční stav + čistý tok ≠ konečný stav.';
                    }
                } catch (\Throwable $e) { $warnings[] = 'Přehled o peněžních tocích: ' . $e->getMessage(); }
                $bump('Přehled o peněžních tocích');
            }

            if (in_array('equity_changes', $parts, true)) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                try {
                    $data = $this->equityService->build($supplierId, $periodId);
                    $data['entity'] = $this->statements->entityHeader($supplierId);
                    $put(sprintf('Prehledy-18-2/zmeny-vlastniho-kapitalu-%d.pdf', $fiscalYear), $this->equityPdf->render($data));
                    $added++; $summary['equity_changes'] = 1;
                    if ($includeXlsx) {
                        $out = $this->xlsx->equityChanges($data);
                        $put(sprintf('Prehledy-18-2/zmeny-vlastniho-kapitalu-%d.xlsx', $fiscalYear), $out['bytes']);
                    }
                    if (($data['reconciles'] ?? true) !== true) {
                        $warnings[] = 'Přehled o změnách vlastního kapitálu nesedí u některé složky.';
                    }
                } catch (\Throwable $e) { $warnings[] = 'Přehled o změnách vlastního kapitálu: ' . $e->getMessage(); }
                $bump('Přehled o změnách vlastního kapitálu');
            }

            // EP-6: POVINNÉ jádro vs. doplňkové části. Balíček je `failed`, když nevzniklo
            // NIC, nebo když selhala některá POVINNÁ část (nesmí být „hotovo" jen proto, že
            // vznikl aspoň jeden soubor). Počet selhání + log chybějících částí se ukládá
            // vždy, ať uživatel vidí, co chybí, ještě PŘED stažením.
            $classified = self::classifyStatus($parts, array_keys($summary), $warnings !== []);
            $missingRequired = $classified['missing_required'];

            if ($classified['status'] === 'failed') {
                $zip->close();
                @unlink($absPath);
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $added, 'failed_count' => count($warnings)]);
                foreach (array_slice($warnings, 0, 50) as $w) {
                    $this->jobs->appendLog($jobId, 'Chyba části: ' . $w);
                }
                $reason = $added === 0
                    ? "Za rok {$fiscalYear} se nepodařilo sestavit žádnou zvolenou sestavu."
                    : 'Povinné části balíčku se nepodařilo sestavit (' . implode(', ', $missingRequired) . ') — balíček není kompletní.';
                $this->jobs->markFailed($jobId, $reason);
                $this->logFinished($jobId, $userId, $supplierId, (string) $fiscalYear, 'failed', [
                    'reason' => $added === 0 ? 'no_data' : 'required_parts_failed',
                    'missing_required' => $missingRequired,
                    'failed_count' => count($warnings),
                ]);
                return;
            }

            // Doplňkové části selhaly, ale povinné jádro je kompletní → completed_with_warnings.
            $withWarnings = $warnings !== [];
            $packageStatus = $classified['status'];

            $put('README.txt', ($is504 ? "Účetní rámec: vyhláška č. 504/2002 Sb.\r\n" : '') . $this->buildReadme($companyName, $fiscalYear, $startsOn, $endsOn, $summary, $warnings));
            // Manifest zaeviduje i README (přidané přes $put výše); sám sebe nezahrnuje.
            $zip->addFromString('manifest.json', $this->buildManifest($supplierId, $periodId, $period, $companyName, $summary, $warnings, $files, $packageStatus));
            $zip->close();

            $size = (int) (@filesize($absPath) ?: 0);
            $this->jobs->setResult($jobId, $relPath, $fileName, $size, 'application/zip');
            $this->jobs->updateProgress($jobId, [
                'processed' => $processed,
                'created_count' => $added,
                'failed_count' => count($warnings),
                'current_step' => 'Hotovo',
            ]);
            foreach (array_slice($warnings, 0, 50) as $w) {
                $this->jobs->appendLog($jobId, 'Upozornění: ' . $w);
            }
            if ($withWarnings) {
                $this->jobs->appendLog($jobId, 'Balíček dokončen s upozorněními — ' . count($warnings) . ' doplňkových částí selhalo/přeskočeno (viz výše).');
                $this->jobs->appendLog($jobId, "Povinné jádro kompletní — {$added} souborů (" . $this->humanSize($size) . ').');
                $this->jobs->markCompletedWithWarnings($jobId);
            } else {
                $this->jobs->appendLog($jobId, "Balíček dokončen — {$added} souborů (" . $this->humanSize($size) . ').');
                $this->jobs->markCompleted($jobId);
            }
            $this->logFinished($jobId, $userId, $supplierId, (string) $fiscalYear, $packageStatus, [
                'files' => $added, 'size_bytes' => $size, 'warnings' => count($warnings), 'failed_count' => count($warnings),
            ]);
        } catch (CancelledException) {
            $this->jobs->markCancelled($jobId);
            $this->logFinished($jobId, $userId, $supplierId, (string) $fiscalYear, 'cancelled', []);
        } catch (\Throwable $e) {
            if (isset($absPath) && is_file($absPath)) @unlink($absPath);
            $this->jobs->markFailed($jobId, $e->getMessage());
            $this->logFinished($jobId, $userId, $supplierId, (string) $fiscalYear, 'failed', ['error' => $e->getMessage()]);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function ensureNotCancelled(int $jobId, ZipArchive $zip, string $absPath): void
    {
        if ($this->jobs->isCancelRequested($jobId)) {
            $zip->close();
            @unlink($absPath);
            throw new CancelledException();
        }
    }

    /**
     * Zaloguj dokončení jobu do activity_log (páruje se s 'reports.closing_package_started').
     *
     * @param array<string,mixed> $extra
     */
    private function logFinished(int $jobId, ?int $userId, int $supplierId, string $year, string $status, array $extra): void
    {
        $this->logger->log('reports.closing_package_finished', $userId, 'import_job', $jobId,
            array_merge(['fiscal_year' => $year, 'status' => $status], $extra), null, null, $supplierId);
    }

    /** Načte job řádek cross-tenant (worker). */
    private function findJob(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT supplier_id, created_by, params, status FROM import_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if ($row['params'] !== null) {
            $decoded = json_decode((string) $row['params'], true);
            $row['params'] = is_array($decoded) ? $decoded : [];
        }
        return $row;
    }

    /**
     * Kalendářní měsíce spadající do <startsOn, endsOn> (fiskální rok nemusí být
     * kalendářní — Kniha DPH se přesto počítá per kalendářní měsíc, shodně
     * s MonthlyExportService::monthsInPeriod).
     *
     * @return list<array{0:int,1:int}> [[year, month], …]
     */
    private function monthsInPeriod(string $startsOn, string $endsOn): array
    {
        $out = [];
        $cur = new \DateTimeImmutable($startsOn);
        $end = (new \DateTimeImmutable($endsOn))->modify('+1 day');
        while ($cur < $end) {
            $out[] = [(int) $cur->format('Y'), (int) $cur->format('n')];
            $cur = $cur->modify('+1 month');
        }
        return $out;
    }

    /**
     * Filtruje výstup SaldoService::build() na otevřené položky starší 1 roku
     * (splatnost, fallback datum vystavení) od rozvahového dne — uzávěrkový
     * balíček #33 (staré/nevypořádané saldo). Přepočítá Σ otevřených položek/
     * rozdíl/shodu jen z vypsané (filtrované) podmnožiny, ať čísla v protokolu
     * odpovídají vypsaným řádkům; zůstatek účtu z hlavní knihy (gl_balance)
     * zůstává celý — je to kontext, kolik ze salda účtu je staré.
     *
     * @param array<string,mixed> $data výstup SaldoService::build()
     * @return array<string,mixed>
     */
    private function filterSaldoOlderThanYear(array $data): array
    {
        $asOf = (string) ($data['as_of'] ?? '');
        $cutoff = (new \DateTimeImmutable($asOf))->modify('-1 year')->format('Y-m-d');

        $accounts = [];
        foreach ($data['accounts'] ?? [] as $acc) {
            $partners = [];
            $openTotalCents = 0;
            foreach ($acc['partners'] ?? [] as $p) {
                $items = array_values(array_filter($p['items'] ?? [], static function (array $it) use ($cutoff): bool {
                    $dueDate = (string) $it['due_date'];
                    $ref = $dueDate !== '' ? $dueDate : (string) $it['issue_date'];
                    return $ref !== '' && $ref < $cutoff;
                }));
                if ($items === []) {
                    continue;
                }
                $total = 0.0;
                foreach ($items as $it) {
                    $total = round($total + (float) $it['remaining_czk'], 2);
                }
                $partners[] = [
                    'partner_id'      => $p['partner_id'],
                    'partner_name'    => $p['partner_name'],
                    'total_remaining' => $total,
                    'items'           => $items,
                ];
                $openTotalCents += (int) round($total * 100);
            }
            if ($partners === []) {
                continue;
            }
            $openTotal = $openTotalCents / 100;
            $difference = round((float) $acc['gl_balance'] - $openTotal, 2);
            $accounts[] = [
                'account'          => $acc['account'],
                'gl_balance'       => $acc['gl_balance'],
                'open_items_total' => $openTotal,
                'difference'       => $difference,
                'matches'          => (int) round($difference * 100) === 0,
                'partners'         => $partners,
                'note'             => 'Zobrazeny jsou jen položky splatné/vzniklé před '
                    . (new \DateTimeImmutable($cutoff))->format('d.m.Y') . ' (starší než 1 rok od rozvahového dne).',
            ];
        }

        $data['accounts'] = $accounts;
        $data['report_title'] = 'Saldokonto — položky nad 1 rok';
        return $data;
    }

    /** Typ poplatníka pro DPPO/DPFO XML ('fo'|'po'), null = nevyplněno. */
    private function taxpayerType(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT taxpayer_type FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $type = $stmt->fetchColumn();
        return in_array($type, ['fo', 'po'], true) ? (string) $type : null;
    }

    private function sanitize(string $s): string
    {
        return ExportFilename::sanitize($s);
    }

    private function supplierCompanyName(int $sid): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name FROM supplier WHERE id = ?');
        $stmt->execute([$sid]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    }

    private function asciiSlug(string $s): string
    {
        return \MyInvoice\Support\Slugifier::slug($s, '-', 'keep', 60);
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024) . ' kB';
        return $bytes . ' B';
    }

    /**
     * EP-10b: výjimka override nezaúčtovaných dokladů z payloadu kroku close_books
     * (null = knihy uzavřeny bez override / krok neproběhl).
     *
     * @return array<string,mixed>|null
     */
    private function closeBooksUnpostedOverride(int $supplierId, int $periodId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM accounting_closing_steps
              WHERE supplier_id = ? AND period_id = ? AND step_key = 'close_books' LIMIT 1"
        );
        $stmt->execute([$supplierId, $periodId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null) {
            return null;
        }
        $payload = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($payload) || !isset($payload['unposted_override']) || !is_array($payload['unposted_override'])) {
            return null;
        }
        return $payload['unposted_override'];
    }

    /**
     * EP-6: manifest.json balíčku — období, jednotka, čas/verze generování, seznam souborů
     * s SHA-256 a velikostí, a zdrojový snapshot (row_version období + stav inventarizace),
     * podle kterého lze balíček zpětně ověřit proti datům, ze kterých vznikl.
     *
     * @param array<string,mixed> $period
     * @param array<string,int> $summary
     * @param list<string> $warnings
     * @param list<array{path:string, sha256:string, bytes:int}> $files
     */
    private function buildManifest(int $supplierId, int $periodId, array $period, string $companyName, array $summary, array $warnings, array $files, string $status): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic, dic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $sup = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $inventory = ['exists' => false, 'completed' => false, 'unresolved_count' => 0, 'ok' => false];
        try {
            $inventory = $this->balanceInventoryReport->inventoryStatus($supplierId, $periodId);
        } catch (\Throwable) {
            // Snapshot inventarizace je informativní — jeho selhání nesmí shodit manifest.
        }

        $manifest = [
            'generator' => 'MyÚčto.cz — uzávěrkový balíček',
            'generator_version' => self::MANIFEST_VERSION,
            'generated_at' => date(DATE_ATOM),
            'status' => $status,
            'period' => [
                'id' => (int) $period['id'],
                'fiscal_year' => (int) $period['fiscal_year'],
                'starts_on' => (string) $period['starts_on'],
                'ends_on' => (string) $period['ends_on'],
            ],
            'entity' => [
                'company_name' => $companyName,
                'ico' => isset($sup['ic']) && $sup['ic'] !== '' ? (string) $sup['ic'] : null,
                'dic' => isset($sup['dic']) && $sup['dic'] !== '' ? (string) $sup['dic'] : null,
            ],
            'source_snapshot' => [
                'period_row_version' => (int) ($period['row_version'] ?? 0),
                'inventory' => [
                    'exists' => (bool) $inventory['exists'],
                    'completed' => (bool) $inventory['completed'],
                    'unresolved_count' => (int) $inventory['unresolved_count'],
                ],
            ],
            'parts' => $summary,
            'warnings' => array_values($warnings),
            'failed_count' => count($warnings),
            'file_count' => count($files),
            'files' => array_values($files),
        ];

        if ($this->frameworks->framework($supplierId, $periodId) === 'cz_504') {
            $manifest['accounting_framework'] = 'cz_504';
            $manifest['statement_as_of'] = $period['ends_on'];
        }
        return (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string,int> $summary
     * @param list<string> $warnings
     */
    private function buildReadme(string $companyName, int $fiscalYear, string $startsOn, string $endsOn, array $summary, array $warnings): string
    {
        $labels = [
            'balance_sheet' => 'Rozvaha (PDF)',
            'income_statement' => 'Výsledovka (PDF)',
            'general_ledger' => 'Hlavní kniha (PDF)',
            'trial_balance' => 'Obratová předvaha (PDF)',
            'journal' => 'Účetní deník (PDF)',
            'balance_inventory' => 'Inventarizace rozvahových účtů, vyplněný protokol (PDF, §29–30 ZoÚ)',
            'open_items' => 'Soupis otevřených položek k rozvahovému dni (PDF)',
            'dph_book' => 'Kniha DPH (PDF, počet měsíců)',
            'income_tax' => 'Přiznání k dani z příjmů (XML)',
            'income_tax_report' => 'Sestava přiznání k dani z příjmů (PDF, pracovní, není podáním; bez části XML i XML)',
            'income_tax_advances' => 'Placení záloh dle §38a (PDF)',
            'asset_inventory' => 'Inventarizace majetku (PDF, dlouhodobý + drobný majetek + inventární karty)',
            'saldo_over_1y' => 'Saldokonta pohledávek a závazků starší 1 roku (PDF)',
            'accruals' => 'Časové rozlišení 381–385 (PDF)',
            'statement_notes' => 'Příloha k účetní závěrce §18/1/c (PDF + TXT)',
            'cash_flow' => 'Přehled o peněžních tocích (PDF, §18/2 ZoÚ)',
            'equity_changes' => 'Přehled o změnách vlastního kapitálu (PDF, §18/2 ZoÚ)',
        ];
        $lines = [
            'Uzávěrkový balíček MyÚčto.cz', '============================',
        ];
        if (trim($companyName) !== '') {
            $lines[] = 'Firma: ' . $companyName;
        }
        $lines = array_merge($lines, [
            'Účetní období: ' . $fiscalYear . ' (' . $startsOn . ' – ' . $endsOn . ')',
            'Vygenerováno: ' . date('Y-m-d H:i:s'), '',
            'Obsah:',
        ]);
        foreach ($labels as $key => $label) {
            if (isset($summary[$key])) $lines[] = sprintf('  - %s: %d', $label, $summary[$key]);
        }
        if (!empty($warnings)) {
            $lines[] = '';
            $lines[] = 'Upozornění (přeskočené/chybějící sestavy):';
            foreach (array_slice($warnings, 0, 50) as $w) $lines[] = '  - ' . $w;
        }
        $lines[] = '';
        $lines[] = 'Přiznání k dani z příjmů je jen MVP foundation (viz varování uvnitř XML) —';
        $lines[] = 'před podáním na EPO ho doplňte ve spolupráci s účetní/daňovým poradcem.';
        return implode("\r\n", $lines) . "\r\n";
    }
}
