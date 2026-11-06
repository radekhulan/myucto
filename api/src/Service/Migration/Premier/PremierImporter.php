<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingFrameworkException;
use MyInvoice\Service\Accounting\AccountingFrameworkImportGuard;
use MyInvoice\Infrastructure\Database\TableStatistics;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Migration\MoneyS3\AccountingUnitSwitch;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PartnerImporter as PohodaPartners;
use MyInvoice\Service\Migration\Shared\FiscalYearMismatchException;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use MyInvoice\Service\Migration\Shared\VatCoefficientSeeder;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationModuleSetup;
use PDO;

/**
 * Převod jednoho účetního roku ze zálohy dat PREMIER do existující firmy v MyÚčtu.
 *
 * Záloha nese všechny roky firmy; převádí se rok po roku, nejlépe od nejstaršího.
 * Pořadí kroků: osnova → období, počáteční stavy a deník → režim účetní jednotky →
 * adresář → přijaté a vydané faktury → doklady s DPH mimo faktury a pokladna → banka →
 * vazby dokladů na deník a úhrady → majetek → zaměstnanci a mzdy (bez účetních zápisů,
 * ty jsou v deníku) → koeficient krácení odpočtu (§ 76) → rekonciliace → kontrola proti podáním KH a DPPO uloženým v PREMIER
 * → uzávěrka. Automatika účtování je po celou dobu vypnutá ({@see AccountingUnitSwitch}).
 *
 * **Zkouška nanečisto** běží stejným kódem v jedné transakci, která se na konci vrátí.
 * **Ostrý převod** zapisuje po krocích, každý krok je idempotentní ({@see PremierImportRepository}):
 * opakovaný běh téhož roku nic nezdvojí.
 */
final class PremierImporter
{
    public const STEP_PREFLIGHT = 'preflight';
    public const STEP_ACCOUNTING_MODE = 'accounting_mode';

    /** Kroky, bez kterých nemá smysl pokračovat. */
    private const CRITICAL_STEPS = [ChartJournalImporter::STEP_CHART, ChartJournalImporter::STEP_JOURNAL, self::STEP_ACCOUNTING_MODE];

    public function __construct(
        private readonly Connection $db,
        private readonly PremierImportRepository $map,
        private readonly AccountingPeriodRepository $periods,
        private readonly ChartJournalImporter $journal,
        private readonly AccountingUnitSwitch $unit,
        private readonly PartnerImporter $partners,
        private readonly InvoiceImporter $invoices,
        private readonly VatDocumentImporter $vatDocuments,
        private readonly BankImporter $bank,
        private readonly DocumentLinker $linker,
        private readonly AssetImporter $assets,
        private readonly SmallAssetImporter $smallAssets,
        private readonly PayrollImporter $payroll,
        private readonly PremierReconciler $reconciler,
        private readonly TaxReturnImporter $taxReturn,
        private readonly PremierVerifier $verifier,
        private readonly ClosingImporter $closing,
        private readonly TableStatistics $statistics,
        private readonly VatCoefficientSeeder $coefficients,
    ) {}

    /** @return list<string> */
    public static function stepKeys(): array
    {
        return [
            ChartJournalImporter::STEP_CHART,
            ChartJournalImporter::STEP_JOURNAL,
            self::STEP_ACCOUNTING_MODE,
            PartnerImporter::STEP,
            InvoiceImporter::STEP_PURCHASE,
            InvoiceImporter::STEP_ISSUED,
            VatDocumentImporter::STEP,
            BankImporter::STEP,
            DocumentLinker::STEP_LINK,
            DocumentLinker::STEP_PAYMENTS,
            AssetImporter::STEP,
            SmallAssetImporter::STEP,
            PayrollImporter::STEP,
            VatCoefficientSeeder::STEP,
            PremierReconciler::STEP,
            TaxReturnImporter::STEP,
            PremierVerifier::STEP,
            ClosingImporter::STEP,
        ];
    }

    /**
     * Roky zálohy, jak je průvodce nabízí a převod bere: u firmy s hospodářským rokem účetní
     * roky firmy s hranicemi období ({@see PremierBackup::companyOverview()}), jinak přehled
     * nahrání beze změny. Zálohu otevře, jen když ji potřebuje (hospodářský rok, agenda IČO firmy).
     *
     * @param list<array<string,mixed>> $agendas přehled nahrání z `meta.json`
     * @param callable():PremierBackup $backup
     * @return list<array<string,mixed>>
     */
    public function companyAgendas(int $supplierId, array $agendas, string $ico, callable $backup): array
    {
        $calendar = (new MigrationPeriods($this->periods))->calendar($supplierId);
        if ($calendar->isCalendar() || $ico === '' || !in_array($ico, array_map('strval', array_column($agendas, 'ico')), true)) {
            return PremierBackup::withoutMonths($agendas);
        }
        return $backup()->withOverview($agendas)->companyOverview($agendas, $calendar);
    }

    /**
     * Kontrola před převodem - nic nezapisuje. Chyba převod zastaví, upozornění ne.
     *
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    public function preflight(int $supplierId, PremierBackup $backup, int $year): array
    {
        $out = [];
        $add = static function (string $level, string $code, string $message, array $context = []) use (&$out): void {
            $out[] = ['level' => $level, 'code' => $code, 'message' => $message, 'context' => $context];
        };
        $stmt = $this->db->pdo()->prepare('SELECT id, ic, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($supplier === false) {
            $add('error', 'supplier_missing', 'Cílová firma neexistuje.');
            return $out;
        }
        $supplierIco = PohodaPartners::ico((string) ($supplier['ic'] ?? ''));
        if ($backup->ico === '' || $supplierIco === '' || $backup->ico !== $supplierIco) {
            // Záloha cizí firmy by se jinak vmíchala do účetnictví téhle.
            $add('error', 'ico_mismatch', "Záloha je firma IČO {$backup->ico}, firma v MyÚčtu má IČO {$supplierIco}.", ['backup' => $backup->ico, 'supplier' => $supplierIco]);
        }
        $calendar = (new MigrationPeriods($this->periods))->calendar($supplierId);
        $years = $backup->fiscalYears($calendar);
        if (!in_array($year, $years, true)) {
            $label = $calendar->isCalendar() ? (string) $year
                : sprintf('%d (%s – %s)', $year, ...MigrationPeriods::bounds($calendar, $year));
            $add('error', 'year_missing', "Záloha neobsahuje účetní zápisy roku {$label}.", ['years' => $years]);
            return $out;
        }
        try {
            PremierSourceYears::assertCompatible($calendar, $backup, $year, $backup->journalDates(...));
        } catch (FiscalYearMismatchException $e) {
            $add('error', 'fiscal_year_mismatch', $e->getMessage(), ['suggested_start_month' => $e->suggestedStartMonth]);
            return $out;
        }
        foreach (['FA_OUT', 'FA_IN', 'PARTNERY'] as $table) {
            if (!$backup->hasTable($table)) {
                $add('warning', 'table_missing', "Záloha neobsahuje tabulku {$table}, doklady z ní se nepřevedou.", ['table' => $table]);
            }
        }
        try {
            $guard = new AccountingFrameworkImportGuard($this->db);
            $guard->assertCz500Periods($supplierId, $guard->periodsForYears($supplierId, [$year]));
        } catch (AccountingFrameworkException $e) { $add('error', $e->errorCode, $e->getMessage()); }
        $period = $this->periods->findByYear($supplierId, $year);
        if ($period !== null) {
            $mapped = $this->map->get($supplierId, PremierImportRepository::KIND_PERIOD, (string) $year) !== null
                || $this->map->hasAny($supplierId, PremierImportRepository::KIND_JOURNAL_ENTRY);
            $foreign = $this->journal->foreignEntryCount($supplierId, (int) $period['id']);
            if ($foreign > 0) {
                $add('error', 'journal_not_empty', "Účetní období {$year} už obsahuje {$foreign} zápisů, které nevznikly převodem z PREMIER. Deník z PREMIER se do rozjetého účetnictví nepřimíchává.", ['entries' => $foreign]);
            }
            if ((string) $period['status'] !== 'open' && !$mapped) {
                $add('error', 'period_not_open', "Účetní období {$year} je v MyÚčtu uzavřené.");
            }
        }
        $earlier = array_values(array_filter($years, static fn (int $y): bool => $y < $year));
        $missing = [];
        foreach ($earlier as $y) {
            if ($this->map->get($supplierId, PremierImportRepository::KIND_PERIOD, (string) $y) === null && $this->periods->findByYear($supplierId, $y) === null) {
                $missing[] = $y;
            }
        }
        if ($missing !== []) {
            $add('info', 'earlier_years_not_imported', 'Starší roky zálohy (' . implode(', ', $missing) . ') v MyÚčtu nejsou. Počáteční stavy roku ' . $year
                . ' se dopočtou z deníku těchto let, jejich doklady ale převedené nebudou - doporučujeme převádět od nejstaršího roku.', ['years' => $missing]);
        }
        if (($supplier['accounting_mode'] ?? '') !== 'double_entry') {
            $add('info', 'switch_to_double_entry', 'Firma se převodem přepne do podvojného účetnictví.');
        }
        if ($backup->hasRows('MZDY')) {
            $blocker = $this->payroll->prerequisite($supplierId);
            $plan = $blocker === null ? null : $this->payroll->moduleSetupPlan($supplierId, $backup);
            if ($blocker === null) {
                $add('info', 'payroll_included', 'Záloha obsahuje mzdy. Převod založí zaměstnance a převezme zpracované mzdy jako evidenci předchozího systému; jejich účetní zápisy jsou v deníku a znovu nevznikají.');
            } elseif (($plan['outcome'] ?? null) === PayrollMigrationModuleSetup::OUTCOME_READY) {
                $add('info', 'payroll_module_will_enable', 'Záloha obsahuje mzdy a firma je v MyÚčtu zatím nemá nastavené. Převod zapne modul Mzdy'
                    . ($plan['create_office'] ? ', založí mzdovou účtárnu' : '')
                    . ($plan['start_period'] !== null ? ', nastaví začátek vedení mezd na ' . $plan['start_period'] : '')
                    . ' a převezme zaměstnance a zpracované mzdy. Údaje z rozhodnutí úřadů (VS ČSSZ, účty institucí) doplníte v Mzdy → Nastavení.');
            } else {
                $add('warning', 'payroll_module_missing', "Záloha obsahuje mzdy. {$blocker} Bez toho se zaměstnanci a mzdy nepřevedou, účetnictví ano.");
            }
        }
        return $out;
    }

    /**
     * @param (callable(string,int,int):void)|null $progress
     * @param (callable():bool)|null $shouldCancel
     * @param bool $acceptDifferences ostrý převod přijme rozdíly k přijetí ({@see ImportProtocol::difference()})
     */
    public function run(int $supplierId, int $userId, PremierBackup $backup, int $year, bool $dryRun, ?int $runId = null, ?callable $progress = null, ?callable $shouldCancel = null, bool $acceptDifferences = false): ImportProtocol
    {
        $protocol = new ImportProtocol($dryRun ? 'dry_run' : 'import', $acceptDifferences);
        $protocol->set('agenda', [
            'ico' => $backup->ico,
            'year' => $year,
            'program' => 'PREMIER',
            'company' => $backup->company,
        ]);
        $preflight = $this->preflight($supplierId, $backup, $year);
        $protocol->set('preflight', $preflight);
        $protocol->begin(self::STEP_PREFLIGHT);
        foreach ($preflight as $m) {
            if ($m['level'] === 'error') {
                $protocol->error(self::STEP_PREFLIGHT, $m['code'], $m['message'], $m['context']);
            }
        }
        if ($protocol->hasErrors()) {
            $protocol->fail(self::STEP_PREFLIGHT);
            return $protocol;
        }
        $protocol->finish(self::STEP_PREFLIGHT);

        $calendar = (new MigrationPeriods($this->periods))->calendar($supplierId);
        $journal = new PremierJournal($backup, $year, $calendar);
        $vat = PremierVat::fromBackup($backup);
        $documents = PremierDocuments::fromBackup($backup, $journal, $vat, $year);
        $journal->usePaymentLinks($documents->paymentLinks());
        $ctx = new PremierContext($supplierId, $userId, $backup, $year, $journal, $vat, $dryRun, $protocol, $calendar);
        $ctx->runId = $runId;
        $ctx->progress = $progress;
        $ctx->smallAssets = PremierSmallAssets::fromBackup($backup);

        $frameworkGuard = new AccountingFrameworkImportGuard($this->db);
        $frameworkPeriods = $frameworkGuard->periodsForYears($supplierId, [$year]);
        $pdo = $this->db->pdo();
        // Zkouška nanečisto uvnitř cizí transakce (testy) jede přes savepoint.
        $savepoint = $dryRun && $pdo->inTransaction();
        if ($savepoint) {
            $pdo->exec('SAVEPOINT premier_dry_run');
        } elseif ($dryRun) {
            $pdo->beginTransaction();
        }
        try {
            $snapshot = ($dryRun ? null : $this->map->pendingAutomationSnapshot($supplierId)) ?? $this->unit->snapshot($supplierId);
            if (!$dryRun) {
                $frameworkGuard->writeCz500Periods($supplierId, $frameworkPeriods, function () use ($runId, $supplierId, $snapshot, $userId, $ctx): void {
                    if ($runId !== null) $this->map->saveAutomationSnapshot($runId, $supplierId, $snapshot);
                    $this->unit->disableAutomation($supplierId, $ctx->userOrNull());
                });
            } else {
                $frameworkGuard->assertCz500Periods($supplierId, $frameworkPeriods, true);
            }
            $automation = ['before' => $snapshot, 'during' => $dryRun ? 'off' : $this->unit->automationLevel($supplierId), 'restored' => false, 'after' => null];

            $steps = $this->steps($ctx, $documents);
            $total = count($steps);
            $index = 0;
            foreach ($steps as $key => $fn) {
                if ($shouldCancel !== null && $shouldCancel()) {
                    $protocol->fail('cancelled');
                    break;
                }
                $ctx->report($key, $index++, $total);
                $protocol->begin($key);
                if (!$dryRun && $key === PremierReconciler::STEP) {
                    $this->statistics->refreshAfterImport(['premier_import_map']);
                }
                try {
                    $dryRun ? $fn() : $this->transactional(function () use ($frameworkGuard, $supplierId, $frameworkPeriods, $fn): void {
                        $frameworkGuard->assertCz500Periods($supplierId, $frameworkPeriods, true);
                        $fn();
                    });
                    $protocol->finish($key);
                } catch (\Throwable $e) {
                    // Odvolaná transakce (1020/1213) nesmí zůstat viset jako otevřená:
                    // savepoint zkušebního běhu i zbytek převodu by jinak pracovaly naslepo.
                    TransactionFailure::rollbackIfAborted($pdo, $e);
                    if ($e instanceof PremierException || $e instanceof AccountingFrameworkException) {
                        $protocol->error($key, $e->errorCode, $e->getMessage());
                    } else {
                        error_log(sprintf('PREMIER: krok %s převodu firmy %d selhal: %s', $key, $supplierId, (string) $e));
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
            if (!$dryRun && !$protocol->hasErrors()) {
                $this->unit->restoreAutomation($supplierId, $snapshot, $ctx->userOrNull());
                $this->map->markAutomationRestored($supplierId);
                $automation['restored'] = true;
                $automation['after'] = $this->unit->automationLevel($supplierId);
            }
            $protocol->set('automation', $automation);
        } catch (AccountingFrameworkException $e) {
            $protocol->error(self::STEP_PREFLIGHT, $e->errorCode, $e->getMessage());
            $protocol->fail(self::STEP_PREFLIGHT);
        } finally {
            if ($savepoint && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT premier_dry_run');
            } elseif ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        return $protocol;
    }

    /** @return array<string,callable():void> */
    private function steps(PremierContext $ctx, PremierDocuments $documents): array
    {
        return [
            ChartJournalImporter::STEP_CHART => fn () => $this->journal->chart($ctx),
            ChartJournalImporter::STEP_JOURNAL => fn () => $this->journal->journal($ctx),
            self::STEP_ACCOUNTING_MODE => fn () => $this->switchMode($ctx),
            PartnerImporter::STEP => fn () => $this->partners->import($ctx),
            InvoiceImporter::STEP_PURCHASE => fn () => $this->invoices->importPurchases($ctx, $documents),
            InvoiceImporter::STEP_ISSUED => fn () => $this->invoices->importIssued($ctx, $documents),
            VatDocumentImporter::STEP => fn () => $this->vatDocuments->import($ctx, $documents),
            BankImporter::STEP => fn () => $this->bank->import($ctx),
            DocumentLinker::STEP_LINK => fn () => $this->linker->link($ctx, $documents),
            DocumentLinker::STEP_PAYMENTS => fn () => $this->linker->matchPayments($ctx, $documents),
            AssetImporter::STEP => fn () => $this->assets->import($ctx),
            SmallAssetImporter::STEP => fn () => $this->smallAssets->import($ctx),
            PayrollImporter::STEP => fn () => $this->payroll->import($ctx),
            // Koeficient § 76 je kalendářní. U hospodářského roku je kalendářní rok L úplný po
            // převodu období L (srpen L - červenec L+1); vypořádá se jako dosud až s dalším rokem.
            VatCoefficientSeeder::STEP => fn () => $this->coefficients->seedConverted(
                $ctx->supplierId,
                array_keys($this->map->all($ctx->supplierId, PremierImportRepository::KIND_PERIOD)),
                [$ctx->year],
                $ctx->userId,
                $ctx->protocol,
            ),
            PremierReconciler::STEP => fn () => $this->reconciler->run($ctx),
            TaxReturnImporter::STEP => fn () => $this->taxReturn->import($ctx),
            PremierVerifier::STEP => fn () => $this->verifier->run($ctx),
            ClosingImporter::STEP => fn () => $this->closing->run($ctx),
        ];
    }

    private function switchMode(PremierContext $ctx): void
    {
        if ($ctx->period === null) {
            return;
        }
        $this->unit->switchToDoubleEntry($ctx->supplierId, $ctx->period['starts_on'], !$ctx->dryRun, $ctx->period['ends_on']);
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
