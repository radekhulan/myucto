<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingFrameworkException;
use MyInvoice\Service\Accounting\AccountingFrameworkImportGuard;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Infrastructure\Database\TableStatistics;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\MoneyS3\AccountingUnitSwitch;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\FiscalYearMismatchException;
use MyInvoice\Service\Migration\Shared\PayrollMigrationNotice;
use MyInvoice\Service\Migration\Shared\VatCoefficientSeeder;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use PDO;

/**
 * Převod agendy Pohody (XML export jednoho účetního roku) do existující firmy v MyÚčtu.
 *
 * Pořadí: osnova → období a deník → režim účetní jednotky → adresář a předkontace →
 * přijaté a vydané doklady → pokladna a banka → vazby dokladů na deník a úhrady →
 * zakázky a činnosti → majetek → sklad → otevřené objednávky → koeficient krácení odpočtu (§ 76) → rekonciliace. Automatika účtování je po celou dobu vypnutá ({@see AccountingUnitSwitch}).
 * Agenda vedená v daňové evidenci má vlastní kroky bez účtování ({@see taxEvidenceSteps()}).
 *
 * **Zkouška nanečisto** běží stejným kódem v jedné transakci, která se na konci vrátí.
 * **Ostrý převod** zapisuje po krocích, každý krok je idempotentní ({@see PohodaImportRepository}):
 * opakovaný běh téhož nebo novějšího exportu nic nezdvojí.
 */
final class PohodaImporter
{
    public const STEP_PREFLIGHT = 'preflight';
    public const STEP_ACCOUNTING_MODE = 'accounting_mode';

    /** Kroky, bez kterých nemá smysl pokračovat. */
    private const CRITICAL_STEPS = [ChartJournalImporter::STEP_CHART, ChartJournalImporter::STEP_JOURNAL, self::STEP_ACCOUNTING_MODE];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly AccountingPeriodRepository $periods,
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly ChartJournalImporter $journal,
        private readonly AccountingUnitSwitch $unit,
        private readonly PartnerImporter $partners,
        private readonly InvoiceImporter $invoices,
        private readonly OtherItemImporter $otherItems,
        private readonly CashBankImporter $cashBank,
        private readonly DocumentLinker $linker,
        private readonly PohodaReconciler $reconciler,
        private readonly AssetImporter $assets,
        private readonly SmallAssetImporter $smallAssets,
        private readonly TableStatistics $statistics,
        private readonly UnbookedBankPayments $unbooked,
        private readonly VatCoefficientSeeder $coefficients,
        private readonly StockImporter $stock,
        private readonly CostCenterImporter $costCenters,
        private readonly InternalDocumentImporter $internalDocuments,
        private readonly BalanceItemImporter $balanceItems,
        private readonly UnbookedBankProposals $proposals,
        private readonly PayrollModuleAccess $payrollAccess,
        private readonly ContractImporter $contracts,
        private readonly ActivityImporter $activities,
        private readonly PohodaTaxEvidenceImporter $taxEvidence,
        private readonly OrderImporter $orders,
    ) {}

    /**
     * Klíče kroků v pořadí, v jakém běží. `$taxEvidence` = agenda vedená v POHODĚ jako daňová
     * evidence ({@see taxEvidenceSteps()}).
     *
     * @return list<string>
     */
    public static function stepKeys(bool $taxEvidence = false): array
    {
        if ($taxEvidence) {
            return [
                PartnerImporter::STEP_PARTNERS,
                InvoiceImporter::STEP_PURCHASE,
                InvoiceImporter::STEP_ISSUED,
                InvoiceImporter::STEP_INTERNAL,
                OtherItemImporter::STEP,
                CashBankImporter::STEP_CASH,
                CashBankImporter::STEP_BANK,
                PohodaTaxEvidenceImporter::STEP_PAYMENTS,
                ContractImporter::STEP,
                ActivityImporter::STEP,
                AssetImporter::STEP,
                SmallAssetImporter::STEP,
                StockImporter::STEP,
                OrderImporter::STEP,
                VatCoefficientSeeder::STEP,
                PohodaTaxEvidenceImporter::STEP_RECONCILIATION,
            ];
        }
        return [
            ChartJournalImporter::STEP_CHART,
            ChartJournalImporter::STEP_JOURNAL,
            self::STEP_ACCOUNTING_MODE,
            PartnerImporter::STEP_PARTNERS,
            PartnerImporter::STEP_POSTING_RULES,
            InvoiceImporter::STEP_PURCHASE,
            InvoiceImporter::STEP_ISSUED,
            InvoiceImporter::STEP_INTERNAL,
            OtherItemImporter::STEP,
            CashBankImporter::STEP_CASH,
            CashBankImporter::STEP_BANK,
            DocumentLinker::STEP_LINK,
            DocumentLinker::STEP_PAYMENTS,
            ContractImporter::STEP,
            ActivityImporter::STEP,
            AssetImporter::STEP,
            SmallAssetImporter::STEP,
            StockImporter::STEP,
            OrderImporter::STEP,
            VatCoefficientSeeder::STEP,
            PohodaReconciler::STEP,
        ];
    }

    /** Kalendář účetního roku firmy - podle něj se agendy řadí do jejích účetních období. */
    public function companyCalendar(int $supplierId): FiscalCalendar
    {
        return $this->periods->fiscalCalendar($supplierId);
    }

    /**
     * Kontrola před převodem - nic nezapisuje. Chyba převod zastaví, upozornění ne.
     * `$skipYears` = roky po roce agendy, které se nepřevádějí ({@see PohodaContext::$skippedYears}).
     * `$laterYears` = pozdější roky agendy z přehledu v `meta.json` (náhled průvodce); bez
     * nich se spočítají průchodem celým deníkem.
     *
     * `$agendaYear` = období agendy podle kalendáře firmy; bez něj se určí z deníku.
     *
     * @param list<int> $skipYears
     * @param list<int>|null $laterYears
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    public function preflight(int $supplierId, PohodaExport $export, array $skipYears = [], ?array $laterYears = null, ?PohodaAgendaYear $agendaYear = null, bool $relinkOnly = false, bool $keepOwnJournal = false): array
    {
        $out = $export->sourceWarnings();
        $add = static function (string $level, string $code, string $message, array $context = []) use (&$out): void {
            $out[] = ['level' => $level, 'code' => $code, 'message' => $message, 'context' => $context];
        };
        $stmt = $this->db->pdo()->prepare('SELECT id, ic, company_name, accounting_mode, taxpayer_type FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($supplier === false) {
            $add('error', 'supplier_missing', 'Cílová firma neexistuje.');
            return $out;
        }
        $supplierIco = PartnerImporter::ico((string) ($supplier['ic'] ?? ''));
        if ($export->ico === '' || $supplierIco === '' || $export->ico !== $supplierIco) {
            // Export cizí firmy by se jinak vmíchal do účetnictví téhle.
            $add('error', 'ico_mismatch', "Export je agenda IČO {$export->ico}, firma v MyÚčtu má IČO {$supplierIco}.", ['agenda' => $export->ico, 'supplier' => $supplierIco]);
        }
        // Agenda vedená v daňové evidenci nemá účetní deník ani osnovu: POHODA na jejich export
        // odpoví chybou nebo prázdným seznamem. Převede se jako daňová evidence a firma v ní
        // zůstane ({@see PohodaTaxEvidenceImporter}).
        $taxEvidence = self::isTaxEvidenceAgenda($export);
        foreach ($export->files() as $f) {
            if (!$f['exists']) {
                continue;
            }
            if ($f['ico'] !== '' && $f['ico'] !== $export->ico) {
                $add('error', 'file_ico_mismatch', "Soubor {$f['file']} je z agendy IČO {$f['ico']}, ne {$export->ico}.", ['file' => $f['file']]);
            } elseif ($taxEvidence && in_array($f['key'], ['journal', 'chart', 'posting_rules', 'balance'], true)) {
                continue;
            } elseif ($taxEvidence && $f['key'] === 'vat_classes' && $f['state'] !== 'ok') {
                $add('info', 'file_state', "Soubor {$f['file']}: POHODA vrátila stav „{$f['state']}“. Doklady s členěním DPH, které převod nezná, se převezmou jako koncepty k ruční kontrole.", ['file' => $f['file']]);
            } elseif ($f['state'] !== 'ok') {
                $add(in_array($f['key'], ['journal', 'chart', 'vat_classes'], true) ? 'error' : 'info', 'file_state',
                    "Soubor {$f['file']}: Pohoda vrátila stav „{$f['state']}“" . ($f['note'] !== '' ? " ({$f['note']})" : '') . '.', ['file' => $f['file']]);
            }
        }
        if ($taxEvidence) {
            // Daňová evidence nemá účetní období ani rámec: kontroly deníku, období a osnovy
            // neziskovky se jí netýkají. Rok agendy je kalendářní rok exportu.
            array_push($out, ...$this->taxEvidence->preflight($supplierId, $supplier, $export->year));
            if ($export->path('payroll') !== null) {
                $add('info', 'payroll_separate', 'Export obsahuje mzdy z datového souboru POHODY (91_mzdy.xml). Převod daňové evidence je nepřevádí, zaměstnanci a mzdy se převádějí zvlášť.');
            }
            return $out;
        }
        // Rok agendy musí sedět na účetní rok firmy: hospodářský rok v kalendářních obdobích
        // (nebo obráceně) by se rozdělil do dvou období a převod se zastaví s návrhem nastavení.
        $agendaYear ??= PohodaAgendaYear::of($export, $this->periods->fiscalCalendar($supplierId));
        try {
            $agendaYear->assertMatchesCompany();
        } catch (FiscalYearMismatchException $e) {
            $add('error', 'fiscal_year_mismatch', $e->getMessage(), ['suggested_start_month' => $e->suggestedStartMonth]);
        }
        $agendaLabel = $agendaYear->label;
        // Agenda POHODY vede i doklady po konci roku - jejich zápisy jdou do období podle
        // skutečného data, takže do rozjetého účetnictví se nesmí přimíchat ani tam. Pozdější
        // roky z přehledu exportu jsou kalendářní (náhled firmu nezná); u hospodářského roku
        // firmy se proto počítají znovu podle jejího kalendáře.
        $allLaterYears = $laterYears !== null && $agendaYear->calendar->isCalendar() ? array_map('intval', $laterYears) : $agendaYear->laterYears();
        $laterYears = array_values(array_diff($allLaterYears, $skipYears));
        $skipped = array_values(array_intersect($allLaterYears, $skipYears));
        $nonprofit = $export->hasNonprofitChart();
        $guard = new AccountingFrameworkImportGuard($this->db, $nonprofit);
        $targetPeriods = $guard->periodsForYears($supplierId, [$agendaLabel, ...$laterYears]);
        try {
            $guard->assertCz500Periods($supplierId, $targetPeriods);
        } catch (AccountingFrameworkException $e) { $add('error', $e->errorCode, $e->getMessage()); }
        foreach ($this->periodBlockers($supplierId, $targetPeriods) as $blocker) {
            $add('error', $blocker->errorCode, $blocker->getMessage(), $blocker->context);
        }
        if ($nonprofit && !$this->periodsLeaveFramework500($supplierId, [$agendaLabel, ...$laterYears])) {
            // Účty 9xx a výkazy neziskovky do firmy v rámci 500 nepatří.
            $add('error', 'nonprofit_agenda_needs_504', 'Agenda POHODY je vedená v osnově pro neziskové organizace, firma v MyÚčtu ale účtuje podle vyhlášky 500. Nastavte firmě účetní rámec vyhlášky 504 a převod spusťte znovu.');
        }

        if ($relinkOnly && !$this->map->hasAny($supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY)) {
            $add('error', 'relink_without_conversion', 'Opravný převod vazeb předpokládá firmu převedenou z Pohody; deník převodu tu chybí.');
        }
        foreach (array_merge([$agendaLabel], $laterYears) as $year) {
            $period = $this->periods->findByYear($supplierId, $year);
            if ($period === null) {
                continue;
            }
            $foreign = $this->journal->foreignEntryCount($supplierId, (int) $period['id']);
            if ($foreign > 0 && $relinkOnly) {
                $add('info', 'relink_keeps_own_journal', "Účetní období {$year} obsahuje {$foreign} zápisů zadaných v MyÚčtu. Opravný převod je nechá beze změny a nové doklady z Pohody nepřidá.", ['entries' => $foreign, 'year' => $year]);
            } elseif ($foreign > 0 && $keepOwnJournal) {
                $add('info', 'keeps_own_journal', "Účetní období {$year} obsahuje {$foreign} zápisů zadaných v MyÚčtu. Dorovnání je nechá beze změny a převezme z POHODY jen záznamy starší než první z nich.", ['entries' => $foreign, 'year' => $year]);
            } elseif ($foreign > 0) {
                $add('error', 'journal_not_empty', "Účetní období {$year} už obsahuje {$foreign} zápisů, které nevznikly převodem z Pohody. Deník z Pohody se do rozjetého účetnictví nepřimíchává.", ['entries' => $foreign, 'year' => $year]);
            }
        }
        if ($laterYears !== []) {
            $add('info', 'later_periods', sprintf(
                'Agenda %d obsahuje doklady s datem v roce %s. Jejich zápisy se převedou do účetního období podle skutečného data (chybějící období se založí otevřené); rok %d zůstane neuzavřený, uzávěrku a převod zůstatků provede účetní.',
                $agendaLabel, implode(', ', $laterYears), $agendaLabel,
            ), ['years' => $laterYears]);
        }
        if ($skipped !== []) {
            $add('info', 'later_years_skipped', sprintf(
                'Rok %s se nepřevádí: zápisy deníku, pohyby v bance a pokladně, doklady a úhrady s datem v něm převod přeskočí. Opakovaný převod s vybraným rokem je doplní, nic nezdvojí.',
                implode(', ', $skipped),
            ), ['years' => $skipped]);
        }
        if (($supplier['accounting_mode'] ?? '') !== 'double_entry') {
            $add('info', 'switch_to_double_entry', 'Firma se převodem přepne do podvojného účetnictví.');
        }
        if ($export->path('payroll') !== null) {
            $add('info', 'payroll_separate', 'Export obsahuje mzdy z datového souboru POHODY (91_mzdy.xml). Převod účetnictví je nepřevádí, zaměstnanci a mzdy se převádějí zvlášť.');
        }
        return $out;
    }

    /**
     * Agenda v daňové evidenci: typ účetní jednotky z přehledu jednotek, nebo účetní deník,
     * který POHODA nevydala (stav jiný než `ok`) a nemá ani jeden zápis. Na export deníku
     * taková agenda často odpoví jen prázdným seznamem se stavem `ok`, pak ji pozná jen
     * typ jednotky.
     */
    public static function isTaxEvidenceAgenda(PohodaExport $export): bool
    {
        if ($export->unitType() === PohodaExport::UNIT_TYPE_TAX_EVIDENCE) {
            return true;
        }
        foreach ($export->files() as $f) {
            if ($f['key'] === 'journal') {
                if (!$f['exists'] || $f['state'] === 'ok' || $f['note'] === 'MDB') {
                    return false;
                }
                foreach ($export->records('journal', 'accountingItem') as $ignored) {
                    return false;
                }
                return true;
            }
        }
        return false;
    }

    /**
     * Výkazy vyhlášky 504 potřebují u každého řádku nákladů a výnosů činnost (hlavní,
     * hospodářská) a bez ní se nesestaví. Činnost převod z POHODY nečte, proto řádky bez
     * ní dostanou hlavní činnost, kterou účetní přeřadí; opakovaný převod jejich změnu nepřepíše.
     * Které středisko je hospodářská činnost, POHODA nikde neuvádí, z dat to odvodit nejde.
     */
    private function stampNonprofitActivity(PohodaContext $ctx): void
    {
        $periodIds = $ctx->periodIds();
        if (!$ctx->export->hasNonprofitChart() || $periodIds === []) {
            return;
        }
        $pdo = $this->db->pdo();
        $activity = (new SystemDimensionCatalog($this->db))->ensureForFramework($ctx->supplierId, $ctx->periodStart(), $ctx->userOrNull())['activity'] ?? null;
        if ($activity === null) {
            return;
        }
        $periodFilter = implode(',', array_fill(0, count($periodIds), '?'));
        $stmt = $pdo->prepare("INSERT IGNORE INTO journal_entry_line_dimensions
                (line_id, dimension_type_id, supplier_id, dimension_value_id, assignment_origin, assignment_origin_ref)
            SELECT l.id, ?, l.supplier_id, ?, 'explicit', 'pohoda_import'
              FROM journal_entry_lines l
              JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
              JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
             WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND a.account_code REGEXP '^[56]'
               AND e.period_id IN ({$periodFilter})
               AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits sp WHERE sp.line_id = l.id AND sp.dimension_type_id = ?)");
        $stmt->execute([$activity['id'], $activity['values']['main'], $ctx->supplierId, ...$periodIds, $activity['id']]);
        $lines = $stmt->rowCount() + $ctx->nonprofitLines;
        if ($lines > 0) {
            $ctx->protocol->warn(PohodaReconciler::STEP, 'nonprofit_activity_default', sprintf(
                '%d řádkům nákladů a výnosů převod nastavil hlavní činnost, hlavní a hospodářskou činnost z POHODY neodvozuje (činnosti POHODY převádí do samostatné dimenze Činnost POHODY). Účetní přeřadí hospodářskou činnost podle skutečnosti, ovlivňuje to výkazy i daň z příjmů.',
                $lines,
            ), ['lines' => $lines]);
        }
    }

    /** @param list<int> $years */
    private function periodsLeaveFramework500(int $supplierId, array $years): bool
    {
        $strict = new AccountingFrameworkImportGuard($this->db);
        try {
            $strict->assertCz500Periods($supplierId, $strict->periodsForYears($supplierId, $years));
            return false;
        } catch (AccountingFrameworkException) {
            return true;
        }
    }

    /**
     * @param (callable(string,int,int):void)|null $progress
     * @param (callable():bool)|null $shouldCancel
     * @param list<int> $skipYears roky po roce agendy, které se nepřevádějí
     * @param bool $acceptDifferences ostrý převod přijme rozdíly k přijetí ({@see ImportProtocol::difference()})
     * @param array{warehouses:array<string,string>}|null $stock převod skladu zvolený v průvodci ({@see PohodaContext::$stock})
     * @param bool $relinkOnly opravný převod vazeb už převedené firmy ({@see PohodaContext::$relinkOnly})
     * @param array<string,mixed>|null $payroll přehled mezd agendy z `meta.json` (`employees`, `months`, `first`, `last`);
     *        mzdy, které firma převzít nemůže, protokol zapíše jako upozornění ({@see PayrollMigrationNotice})
     */
    public function run(int $supplierId, int $userId, PohodaExport $export, bool $dryRun, ?int $runId = null, ?callable $progress = null, ?callable $shouldCancel = null, array $skipYears = [], bool $acceptDifferences = false, ?array $stock = null, bool $relinkOnly = false, bool $keepOwnJournal = false, ?array $payroll = null, ?array $selectedYears = null): ImportProtocol
    {
        // Dorovnání: deník nese i vlastní zápisy, rozdíl proti POHODĚ je očekávaný a hlásí se.
        $acceptDifferences = $acceptDifferences || $keepOwnJournal;
        // Tvar roku firmy se určí před prvním zápisem: převod podle něj zakládá období.
        $agendaYear = PohodaAgendaYear::of($export, $this->periods->fiscalCalendar($supplierId));
        if ($selectedYears !== null) {
            $skipYears = array_values(array_unique([...$skipYears, ...array_diff($agendaYear->laterYears(), $selectedYears)]));
        }
        $protocol = new ImportProtocol($dryRun ? 'dry_run' : 'import', $acceptDifferences);
        $protocol->set('agenda', [
            'ico' => $export->ico,
            'year' => $export->year,
            'program' => $export->info['program'],
            'exported_at' => $export->info['timestamp'],
            'dir' => basename($export->dir),
            'skipped_years' => $skipYears,
            'relink_only' => $relinkOnly,
        ]);
        $preflight = $this->preflight($supplierId, $export, $skipYears, null, $agendaYear, $relinkOnly, $keepOwnJournal);
        $protocol->set('preflight', $preflight);
        $protocol->begin(self::STEP_PREFLIGHT);
        foreach ($preflight as $m) {
            if ($m['level'] === 'error') {
                $protocol->error(self::STEP_PREFLIGHT, $m['code'], $m['message'], $m['context']);
            } elseif ($m['level'] === 'warning') {
                $protocol->warn(self::STEP_PREFLIGHT, $m['code'], $m['message'], $m['context']);
                $protocol->count(self::STEP_PREFLIGHT, 'skipped_documents');
            }
        }
        // Mzdy agendy, které se nepřevedou (licence, modul Mzdy): upozornění zůstane
        // v historii převodů. Převod účetnictví nezastaví, zaúčtování mezd nese deník.
        PayrollMigrationNotice::warn($protocol, self::STEP_PREFLIGHT,
            PayrollMigrationNotice::forSupplier($this->payrollAccess, $supplierId, $payroll));
        if ($protocol->hasErrors()) {
            $protocol->fail(self::STEP_PREFLIGHT);
            return $protocol;
        }
        $protocol->finish(self::STEP_PREFLIGHT);

        $ctx = new PohodaContext($supplierId, $userId, $export, PohodaVat::fromExport($export), $dryRun, $protocol);
        $ctx->useAgendaYear($agendaYear);
        $ctx->runId = $runId;
        $ctx->progress = $progress;
        $ctx->skippedYears = array_fill_keys($skipYears, true);
        $ctx->selectedYears = $selectedYears === null ? null : array_fill_keys($selectedYears, true);
        $ctx->stock = $stock;
        $ctx->relinkOnly = $relinkOnly;
        $ctx->taxEvidence = self::isTaxEvidenceAgenda($export);
        // Daňová evidence se neúčtuje: automatika účtování, účetní období ani účetní rámec se
        // jí netýkají a převod je nemění.
        $automationManaged = !$ctx->taxEvidence;
        if ($keepOwnJournal) {
            $ctx->ownJournalFrom = $this->journal->firstForeignEntryDate($supplierId);
            if ($ctx->ownJournalFrom !== null) {
                $protocol->info(self::STEP_PREFLIGHT, 'own_journal_cutoff', "Záznamy POHODY od {$ctx->ownJournalFrom} se nepřebírají, od tohoto dne se účtuje v MyÚčtu. Rekonciliace proto může hlásit rozdíl.", ['from' => $ctx->ownJournalFrom]);
            }
        }

        $frameworkGuard = new AccountingFrameworkImportGuard($this->db, $export->hasNonprofitChart());
        $frameworkPeriods = $automationManaged
            ? $frameworkGuard->periodsForYears($supplierId, [$agendaYear->label, ...array_values(array_diff($agendaYear->laterYears(), $skipYears))])
            : [];
        $pdo = $this->db->pdo();
        // Zkouška nanečisto uvnitř cizí transakce (testy, vnořené volání) jede přes savepoint -
        // vlastní BEGIN by PDO odmítlo a vnější transakci by nesměla vrátit.
        $savepoint = $dryRun && $pdo->inTransaction();
        if ($savepoint) {
            $pdo->exec('SAVEPOINT pohoda_dry_run');
        } elseif ($dryRun) {
            $pdo->beginTransaction();
        }
        try {
            $snapshot = $automationManaged ? (($dryRun ? null : $this->map->pendingAutomationSnapshot($supplierId)) ?? $this->unit->snapshot($supplierId)) : [];
            if ($automationManaged && !$dryRun) {
                $frameworkGuard->writeCz500Periods($supplierId, $frameworkPeriods, function () use ($runId, $supplierId, $snapshot, $userId, $ctx, $frameworkPeriods): void {
                    $this->assertPeriodsWritable($supplierId, $frameworkPeriods);
                    if ($runId !== null) $this->map->saveAutomationSnapshot($runId, $supplierId, $snapshot);
                    $this->unit->disableAutomation($supplierId, $ctx->userOrNull());
                });
            } elseif ($automationManaged) {
                $frameworkGuard->assertCz500Periods($supplierId, $frameworkPeriods, true);
                $this->assertPeriodsWritable($supplierId, $frameworkPeriods);
            }
            $automation = ['before' => $snapshot, 'during' => $dryRun || !$automationManaged ? 'off' : $this->unit->automationLevel($supplierId), 'restored' => false, 'after' => null];

            $steps = $ctx->taxEvidence ? $this->taxEvidenceSteps($ctx) : $this->steps($ctx);
            $total = count($steps);
            $index = 0;
            foreach ($steps as $key => $fn) {
                if ($shouldCancel !== null && $shouldCancel()) {
                    $protocol->fail('cancelled');
                    break;
                }
                $ctx->report($key, $index++, $total);
                $protocol->begin($key);
                if (!$dryRun && ($key === PohodaReconciler::STEP || $key === PohodaTaxEvidenceImporter::STEP_RECONCILIATION)) {
                    $this->statistics->refreshAfterImport(['pohoda_import_map']);
                }
                try {
                    $dryRun ? $fn() : $this->transactional(function () use ($frameworkGuard, $supplierId, $frameworkPeriods, $fn): void {
                        $frameworkGuard->assertCz500Periods($supplierId, $frameworkPeriods, true);
                        $this->assertPeriodsWritable($supplierId, $frameworkPeriods);
                        $fn();
                    });
                    $protocol->finish($key);
                } catch (\Throwable $e) {
                    // Odvolaná transakce (1020/1213) nesmí zůstat viset jako otevřená:
                    // savepoint zkušebního běhu i zbytek převodu by jinak pracovaly naslepo.
                    TransactionFailure::rollbackIfAborted($pdo, $e);
                    if ($e instanceof PohodaException || $e instanceof AccountingFrameworkException) {
                        $protocol->error($key, $e->errorCode, $e->getMessage());
                    } else {
                        // Text výjimky (SQL, cesty) do protokolu viditelného v UI nepatří.
                        error_log(sprintf('POHODA: krok %s převodu firmy %d selhal: %s', $key, $supplierId, (string) $e));
                        $protocol->error($key, 'unexpected', 'Krok převodu selhal na neočekávané chybě, podrobnosti jsou v logu serveru.');
                    }
                    $protocol->fail($key);
                    break;
                }
                if (in_array($key, self::CRITICAL_STEPS, true) && $protocol->blocks($key)) {
                    $protocol->fail($key);
                    break;
                }
            }
            $ctx->report('done', $total, $total);
            if (!$dryRun) {
                $this->invoices->recomputeClientStats($ctx);
            }
            if ($automationManaged && !$dryRun && !$protocol->hasErrors()) {
                $this->unit->restoreAutomation($supplierId, $snapshot, $ctx->userOrNull());
                $this->map->markAutomationRestored($supplierId);
                $automation['restored'] = true;
                $automation['after'] = $this->unit->automationLevel($supplierId);
            }
            $protocol->set('automation', $automation);
        } catch (AccountingFrameworkException | PohodaException $e) {
            $protocol->error(self::STEP_PREFLIGHT, $e->errorCode, $e->getMessage());
            $protocol->fail(self::STEP_PREFLIGHT);
        } finally {
            if ($savepoint && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_dry_run');
            } elseif ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($dryRun) {
                // Forma zápisu zapsaná zkouškou se vrátila s ní; sdílená odpověď procesu taky.
                \MyInvoice\Service\Accounting\EntryForm\EntryFormService::forgetShared($supplierId);
            }
        }
        return $protocol;
    }

    private function periodBlockers(int $supplierId, array $periods, bool $currentRead = false): array
    {
        if ($periods === []) {
            return [];
        }
        $targets = [];
        foreach ($periods as $period) {
            $targets[$period['year']] = $this->periods->findByYear($supplierId, $period['year'], $currentRead) ?? $period;
        }
        $lockedUntil = $this->settings->getLockedUntil($supplierId, $currentRead);
        $blockers = [];
        foreach ($targets as $year => $period) {
            if (($period['status'] ?? 'open') !== 'open') {
                $blockers[] = new PohodaException('period_not_open', "Účetní období {$year} není otevřené. Převod spusťte před uzavřením knih.", ['year' => $year]);
            } elseif ($lockedUntil !== null && $lockedUntil >= $period['starts_on']) {
                $blockers[] = new PohodaException('date_locked', "Účetní období {$year} zasahuje do zámku účtování k {$lockedUntil}. Převod celého období je zablokovaný.", ['year' => $year, 'locked_until' => $lockedUntil]);
            }
        }
        return $blockers;
    }

    private function assertPeriodsWritable(int $supplierId, array $periods): void
    {
        $blockers = $this->periodBlockers($supplierId, $periods, true);
        if ($blockers !== []) {
            throw $blockers[0];
        }
    }

    /**
     * Kroky, které v opravném převodu vazeb neběží: přepnutí účetnictví a předkontace
     * firmu nastavují, majetek, sklad a koeficienty zakládají, dopočtené úhrady
     * (UnbookedBankPayments) účtují nové zápisy a rekonciliace srovnává celý export,
     * jehož novější doklady opravný převod záměrně nepřidá.
     */
    private const RELINK_SKIPPED_STEPS = [
        self::STEP_ACCOUNTING_MODE, PartnerImporter::STEP_POSTING_RULES, AssetImporter::STEP,
        StockImporter::STEP, OrderImporter::STEP, VatCoefficientSeeder::STEP, PohodaReconciler::STEP,
    ];

    /** @return array<string,callable():void> */
    private function steps(PohodaContext $ctx): array
    {
        $steps = $this->allSteps($ctx);
        return $ctx->relinkOnly ? array_diff_key($steps, array_flip(self::RELINK_SKIPPED_STEPS)) : $steps;
    }

    /** @return array<string,callable():void> */
    private function allSteps(PohodaContext $ctx): array
    {
        return [
            ChartJournalImporter::STEP_CHART => fn () => $this->journal->chart($ctx),
            ChartJournalImporter::STEP_JOURNAL => function () use ($ctx): void {
                // Forma zápisu nové firmy dřív, než do deníku přijde první převzatý zápis.
                $this->unit->applyEntryFormDefault($ctx->supplierId);
                $this->journal->journal($ctx);
            },
            self::STEP_ACCOUNTING_MODE => fn () => $this->switchMode($ctx),
            PartnerImporter::STEP_PARTNERS => fn () => $this->partners->importPartners($ctx),
            PartnerImporter::STEP_POSTING_RULES => fn () => $this->partners->importPostingRules($ctx),
            InvoiceImporter::STEP_PURCHASE => fn () => $this->invoices->importPurchases($ctx),
            InvoiceImporter::STEP_ISSUED => fn () => $this->invoices->importIssued($ctx),
            InvoiceImporter::STEP_INTERNAL => fn () => $this->invoices->importInternalTaxDocuments($ctx),
            OtherItemImporter::STEP => fn () => $this->otherItems->run($ctx),
            CashBankImporter::STEP_CASH => fn () => $this->cashBank->importCash($ctx),
            CashBankImporter::STEP_BANK => fn () => $this->cashBank->importBank($ctx),
            DocumentLinker::STEP_LINK => function () use ($ctx): void {
                if (!$ctx->relinkOnly) {
                    $this->unbooked->supersede($ctx);
                }
                $this->linker->link($ctx);
            },
            DocumentLinker::STEP_PAYMENTS => function () use ($ctx): void {
                $this->linker->matchPayments($ctx);
                if (!$ctx->relinkOnly) {
                    $this->unbooked->settle($ctx);
                    $this->proposals->propose($ctx);
                }
                $this->internalDocuments->run($ctx);
                // Až po všech úhradách: co některá z nich vysvětluje, není zůstatek bez dokladu.
                if (!$ctx->relinkOnly) {
                    $this->balanceItems->run($ctx);
                }
            },
            ContractImporter::STEP => fn () => $this->contracts->run($ctx),
            ActivityImporter::STEP => fn () => $this->activities->run($ctx),
            AssetImporter::STEP => fn () => $this->assets->import($ctx),
            SmallAssetImporter::STEP => fn () => $this->smallAssets->import($ctx),
            StockImporter::STEP => fn () => $this->stock->import($ctx),
            OrderImporter::STEP => fn () => $this->orders->import($ctx),
            VatCoefficientSeeder::STEP => fn () => $this->coefficients->seedConverted(
                $ctx->supplierId,
                $this->vatYears($ctx, array_filter(
                    array_keys($this->map->all($ctx->supplierId, PohodaImportRepository::KIND_PERIOD)),
                    static fn (int|string $year): bool => !$ctx->skipsDate($ctx->calendar->periodStart((int) $year)),
                )),
                $this->vatYears($ctx, [$ctx->year(), ...array_keys(array_filter($ctx->periods))]),
                $ctx->userId,
                $ctx->protocol,
            ),
            PohodaReconciler::STEP => function () use ($ctx): void {
                $this->costCenters->run($ctx);
                $this->stampNonprofitActivity($ctx);
                $this->reconciler->run($ctx);
            },
        ];
    }

    /**
     * Kroky převodu agendy vedené v POHODĚ jako daňová evidence: bez osnovy, deníku, režimu
     * účetní jednotky, předkontací, vazeb na deník, dopočtených úhrad a rekonciliace deníku.
     * Doklady, banku a pokladnu převádějí stávající kroky bez zaúčtování, úhrady jen přesné
     * vazby na pohyby ({@see PohodaTaxEvidenceImporter}). Dlouhodobý majetek převod v daňové
     * evidenci nepřevádí: karta bez účetních odpisů z deníku by byla neúplná.
     *
     * @return array<string,callable():void>
     */
    private function taxEvidenceSteps(PohodaContext $ctx): array
    {
        return [
            PartnerImporter::STEP_PARTNERS => fn () => $this->partners->importPartners($ctx),
            InvoiceImporter::STEP_PURCHASE => fn () => $this->invoices->importPurchases($ctx),
            InvoiceImporter::STEP_ISSUED => fn () => $this->invoices->importIssued($ctx),
            InvoiceImporter::STEP_INTERNAL => fn () => $this->invoices->importInternalTaxDocuments($ctx),
            OtherItemImporter::STEP => fn () => $this->otherItems->run($ctx),
            CashBankImporter::STEP_CASH => fn () => $this->cashBank->importCash($ctx),
            CashBankImporter::STEP_BANK => fn () => $this->cashBank->importBank($ctx),
            PohodaTaxEvidenceImporter::STEP_PAYMENTS => fn () => $this->taxEvidence->payments($ctx),
            ContractImporter::STEP => fn () => $this->contracts->run($ctx),
            ActivityImporter::STEP => fn () => $this->activities->run($ctx),
            AssetImporter::STEP => function () use ($ctx): void {
                foreach ($ctx->export->records('assets', 'IM') as $ignored) {
                    $ctx->protocol->info(AssetImporter::STEP, 'tax_evidence_assets_manual', 'Dlouhodobý majetek převod daňové evidence nepřevádí. Karty majetku a daňové odpisy převeďte ručně (Majetek).');
                    break;
                }
            },
            SmallAssetImporter::STEP => fn () => $this->smallAssets->import($ctx),
            StockImporter::STEP => fn () => $this->stock->import($ctx),
            OrderImporter::STEP => fn () => $this->orders->import($ctx),
            VatCoefficientSeeder::STEP => fn () => $this->coefficients->seedConverted(
                $ctx->supplierId, $this->taxEvidence->convertedYears($ctx->supplierId), [$ctx->year()], $ctx->userId, $ctx->protocol,
            ),
            PohodaTaxEvidenceImporter::STEP_RECONCILIATION => fn () => $this->taxEvidence->reconcile($ctx),
        ];
    }

    /**
     * Koeficient § 76 je za KALENDÁŘNÍ rok (DPH), ne za účetní období: období hospodářského
     * roku zasahuje do dvou kalendářních let. Firma s kalendářním rokem předá labely beze změny.
     *
     * @param list<int|string> $periodYears labely účetních období
     * @return list<int|string>
     */
    private function vatYears(PohodaContext $ctx, array $periodYears): array
    {
        if ($ctx->calendar->isCalendar()) {
            return $periodYears;
        }
        $supplierId = $ctx->supplierId;
        $years = [];
        foreach (array_map('intval', $periodYears) as $label) {
            $period = $this->periods->findByYear($supplierId, $label);
            if ($period === null) {
                $years[$label] = true;
                continue;
            }
            for ($y = (int) substr((string) $period['starts_on'], 0, 4); $y <= (int) substr((string) $period['ends_on'], 0, 4); $y++) {
                $years[$y] = true;
            }
        }
        ksort($years);
        return array_keys($years);
    }

    private function switchMode(PohodaContext $ctx): void
    {
        if ($ctx->period === null) {
            return;
        }
        $this->unit->switchToDoubleEntry($ctx->supplierId, $ctx->period['starts_on'], !$ctx->dryRun, $ctx->lastPeriodEnd() ?? $ctx->period['ends_on']);
        $ctx->protocol->info(self::STEP_ACCOUNTING_MODE, 'double_entry', 'Podvojné účetnictví od ' . $ctx->period['starts_on'] . '.');
    }


    private function transactional(callable $fn): void
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $fn();
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
