<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\AccountingFrameworkImportGuard;
use MyInvoice\Service\Accounting\AccountingFrameworkException;
use MyInvoice\Infrastructure\Database\TableStatistics;
use MyInvoice\Repository\AbraImportRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Migration\OssMigrationPolicy;
use MyInvoice\Service\Migration\MoneyS3\AccountingUnitSwitch;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\FiscalYearMismatchException;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use MyInvoice\Service\Bank\BankTransactionPostingScope;
use MyInvoice\Service\Migration\Shared\BankStatementImportWriter;
use MyInvoice\Service\Migration\Shared\JournalEntryLinker;
use MyInvoice\Service\Migration\Shared\MigratedDocumentItem;
use MyInvoice\Service\Migration\Shared\MigratedDocumentWriter;
use MyInvoice\Service\Migration\Shared\MigratedIssuedDocument;
use MyInvoice\Service\Migration\Shared\MigratedPaymentWriter;
use MyInvoice\Service\Migration\Shared\MigratedPurchaseDocument;
use PDO;
use Throwable;

/**
 * Incrementální převod účetnictví ABRA Flexi.
 *
 * Dokladové snapshoty se nikdy automaticky nezaúčtují. Autoritativní účetní
 * stopou jsou původní řádky evidence ucetni-denik.
 */
final class AbraAccountingImporter
{
    private const CANCELLED = '__ABRA_IMPORT_CANCELLED__';
    private const PLAN_CHUNK = 500;
    /** Cíle, které nemají vazbu zápisu na doklad: ostatní položka si zápis převezme sama. */
    private const NOT_LINKED_TARGETS = ['other_item', 'other_item_pending', 'not_transferred'];
    /** Zdrojový doklad zůstal jen v deníku, jeho úhrada se nepáruje. */
    private const NOT_TRANSFERRED_TARGETS = ['not_transferred', 'other_item_pending'];
    private array $vatRateCache = [];
    private array $historicalVatRateCache = [];
    /** @var list<array<string,mixed>> ostatní položky čekající na převzatý deník */
    private array $pendingOtherItems = [];
    /** @var array<int,float> ostatní položka => uhrazeno podle zdroje */
    private array $otherItemSourcePaid = [];

    public function __construct(
        private readonly Connection $db,
        private readonly AbraImportRepository $imports,
        private readonly AbraTaxPlanner $taxPlanner,
        private readonly AbraExistingDocumentTaxUpdater $existingTaxUpdater,
        private readonly AbraVatProjectionApplier $vatProjectionApplier,
        private readonly ActivityLogger $logger,
        private readonly AccountingUnitSwitch $accountingUnit,
        private readonly OtherItemService $otherItems,
    ) {}

    /**
     * @return array{ok:bool,blockers:list<string>,warnings:list<string>,years:list<int>,periods:list<array<string,mixed>>}
     */
    public function preflight(int $supplierId, array $snapshot, array $years): array
    {
        $years = $this->years($years);
        $blockers = [];
        $warnings = [];
        $meta = is_array($snapshot['_meta'] ?? null) ? $snapshot['_meta'] : [];
        $company = is_array($meta['company'] ?? null) ? $meta['company'] : [];
        $sync = (string) ($meta['mode'] ?? '') === 'sync' || ($meta['delta'] ?? false) === true;
        $unavailable = is_array($meta['unavailable'] ?? null)
            ? array_values(array_filter(array_map('strval', $meta['unavailable']))) : [];

        $supplier = $this->db->pdo()->prepare('SELECT s.ic, s.accounting_mode, c.code AS default_currency
            FROM supplier s JOIN currencies c ON c.id = s.default_currency_id AND c.supplier_id = s.id WHERE s.id = ?');
        $supplier->execute([$supplierId]);
        $target = $supplier->fetch(PDO::FETCH_ASSOC);
        if ($target === false) {
            $blockers[] = 'target_supplier_missing';
        } else {
            if (!AbraSource::sameIco($company['ico'] ?? '', $target['ic'] ?? '')) {
                $blockers[] = 'source_target_ico_mismatch';
            }
            if ((string) ($target['accounting_mode'] ?? '') !== 'double_entry') {
                $blockers[] = 'target_not_double_entry';
            }
            if ((string) ($target['default_currency'] ?? '') !== 'CZK') {
                $blockers[] = 'target_base_currency_unsupported';
            }
        }
        $sourceBaseCurrency = mb_strtoupper(AbraSource::reference($company['base_currency'] ?? null));
        if (in_array($sourceBaseCurrency, ['KČ', 'CZK'], true)) {
            $sourceBaseCurrency = 'CZK';
        }
        if ($sourceBaseCurrency === '') {
            $blockers[] = 'source_base_currency_missing';
        } elseif ($sourceBaseCurrency !== 'CZK') {
            $blockers[] = 'source_base_currency_unsupported';
        }
        if ($years === []) {
            $blockers[] = 'years_missing';
        }
        if ((!$sync && !AbraSource::hasEvidence($snapshot, 'ucetni-denik', 'denik'))
            || in_array('ucetni-denik', $unavailable, true) || in_array('denik', $unavailable, true)) {
            $blockers[] = 'journal_evidence_missing';
        }
        if ((!$sync && !AbraSource::hasEvidence($snapshot, 'ucetni-osnova', 'ucet'))
            || in_array('ucetni-osnova', $unavailable, true) || in_array('ucet', $unavailable, true)) {
            $blockers[] = 'chart_evidence_missing';
        }

        $journalRows = AbraSource::rows($snapshot, 'ucetni-denik', 'denik');
        $chartRows = [...AbraSource::rows($snapshot, 'ucetni-osnova'), ...AbraSource::rows($snapshot, 'ucet')];
        if (!$sync && $journalRows === []) {
            $blockers[] = 'journal_rows_missing';
        }
        if (!$sync && $chartRows === []) {
            $blockers[] = 'chart_rows_missing';
        }
        if ($journalRows !== [] && (($sync && AbraSource::rows($snapshot, 'pohyb-na-uctech') === [])
            || (!$sync && AbraSource::rows($snapshot, 'stav-uctu') === []
                && AbraSource::rows($snapshot, 'pohyb-na-uctech') === []))) {
            $blockers[] = 'source_trial_balance_missing';
        }
        $hasPaymentSources = AbraSource::rows($snapshot, 'banka') !== []
            || AbraSource::rows($snapshot, 'pokladni-pohyb') !== [];
        if ($hasPaymentSources && (!AbraSource::hasEvidence($snapshot, 'vazba')
            || in_array('vazba', $unavailable, true))) {
            $blockers[] = 'payment_links_evidence_missing';
        }

        $periodRows = is_array($meta['periods'] ?? null) ? array_values(array_filter($meta['periods'], 'is_array')) : [];
        $periods = [];
        foreach ($periodRows as $row) {
            $starts = AbraSource::date($row['platiOdData'] ?? null);
            $ends = AbraSource::date($row['platiDoData'] ?? null);
            if ($starts === null || $ends === null || $starts > $ends) {
                $blockers[] = 'source_period_invalid';
                continue;
            }
            $year = AbraSource::periodYear($row) ?? (int) substr($starts, 0, 4);
            if (!in_array($year, $years, true)) {
                continue;
            }
            $periods[] = ['source' => $row, 'source_key' => AbraSource::sourceKey($row, (string) $year),
                'source_hash' => AbraSource::hash($row), 'year' => $year, 'starts_on' => $starts, 'ends_on' => $ends];
        }
        $periodBounds = [];
        $periodCounts = [];
        foreach ($periods as $period) {
            $bounds = $period['starts_on'] . '|' . $period['ends_on'];
            $periodBounds[$period['year']][$bounds] = true;
            $periodCounts[$period['year']] = ($periodCounts[$period['year']] ?? 0) + 1;
        }
        foreach ($periodBounds as $year => $bounds) {
            if (($periodCounts[$year] ?? 0) > 1 || count($bounds) > 1) {
                $blockers[] = 'source_period_ambiguous:' . $year;
            }
        }
        foreach ($years as $year) {
            if (!array_any($periods, static fn (array $period): bool => $period['year'] === $year)) {
                if ($sync) {
                    $existing = $this->db->pdo()->prepare('SELECT id, starts_on, ends_on, status FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ?');
                    $existing->execute([$supplierId, $year]);
                    $targetPeriod = $existing->fetch(PDO::FETCH_ASSOC);
                    if ($targetPeriod !== false) {
                        $periods[] = ['existing_only' => true, 'target_id' => (int) $targetPeriod['id'],
                            'year' => $year, 'starts_on' => (string) $targetPeriod['starts_on'],
                            'ends_on' => (string) $targetPeriod['ends_on'], 'status' => (string) $targetPeriod['status']];
                    } else {
                        $blockers[] = 'source_period_missing:' . $year;
                    }
                } else {
                    $blockers[] = 'source_period_missing:' . $year;
                }
            }
        }

        $calendar = (new MigrationPeriods(new AccountingPeriodRepository($this->db)))->calendar($supplierId);
        foreach ($periods as $period) {
            if (!empty($period['existing_only'])) {
                if ((string) $period['status'] !== 'open' && $this->yearContainsNewJournalRows($supplierId, $journalRows, $period['starts_on'], $period['ends_on'])) {
                    $blockers[] = 'target_period_closed:' . $period['year'];
                }
                continue;
            }
            // Tvar účetního roku určuje firma: období ABRA musí padnout do jednoho období
            // firmy (hospodářský rok ABRA do kalendářní firmy ani obráceně nejde).
            try {
                MigrationPeriods::assertSourceYear($calendar, [$period['starts_on'], $period['ends_on']], (string) $period['year']);
            } catch (FiscalYearMismatchException $e) {
                // Kód pro protokol a celá česká hláška s navrženým začátkem roku.
                $blockers[] = 'fiscal_year_mismatch:' . $period['year'];
                $warnings[] = $e->getMessage();
                continue;
            }
            if (!$calendar->isCalendar() && $calendar->fiscalYearOfDate($period['starts_on']) !== $period['year']) {
                $blockers[] = 'source_period_label_mismatch:' . $period['year'];
                continue;
            }
            $existing = $this->db->pdo()->prepare('SELECT id, starts_on, ends_on, status FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ?');
            $existing->execute([$supplierId, $period['year']]);
            $targetPeriod = $existing->fetch(PDO::FETCH_ASSOC);
            if ($targetPeriod !== false && ((string) $targetPeriod['starts_on'] !== $period['starts_on']
                || (string) $targetPeriod['ends_on'] !== $period['ends_on'])) {
                $blockers[] = 'target_period_incompatible:' . $period['year'];
            }
            if ($targetPeriod !== false && (string) $targetPeriod['status'] !== 'open'
                && $this->yearContainsNewJournalRows($supplierId, $journalRows, $period['starts_on'], $period['ends_on'])) {
                $blockers[] = 'target_period_closed:' . $period['year'];
            }
            if (!$sync && $targetPeriod !== false
                && $this->targetPeriodHasForeignJournal($supplierId, (int) $targetPeriod['id'])) {
                $blockers[] = 'target_period_not_empty:' . $period['year'];
            }
            if (!$sync && $this->targetPeriodHasForeignDocuments(
                $supplierId, $snapshot, $period['starts_on'], $period['ends_on']
            )) {
                $blockers[] = 'target_document_period_not_empty:' . $period['year'];
            }
        }

        try {
            (new AccountingFrameworkImportGuard($this->db))->assertCz500Periods($supplierId, $periods);
        } catch (AccountingFrameworkException $e) {
            $blockers[] = $e->errorCode;
        }

        return [
            'ok' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'years' => $years,
            'periods' => $periods,
        ];
    }

    /**
     * @return array{created:int,skipped:int,changed:int,failed:int,warnings:list<string>,blocked:bool,
     *     cancelled:bool,counts:array<string,int>,conflicts:list<array<string,string>>,
     *     reconciliation:array<string,mixed>}
     */
    public function import(
        int $supplierId,
        int $userId,
        array $snapshot,
        array $years,
        callable $progress,
        callable $cancelled,
    ): array {
        $report = $this->report();
        $preflight = $this->preflight($supplierId, $snapshot, $years);
        array_push($report['warnings'], ...$preflight['warnings']);
        if (!$preflight['ok']) {
            $report['blocked'] = true;
            array_push($report['warnings'], ...$preflight['blockers']);
            return $this->finish($report);
        }
        $years = $preflight['years'];
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        try {
            $nested ? $pdo->exec('SAVEPOINT abra_accounting_import') : $pdo->beginTransaction();
            (new AccountingFrameworkImportGuard($this->db))->assertCz500Periods($supplierId, $preflight['periods'], true);
            $this->checkCancelled($cancelled);
            $sourceOss = $snapshot['_meta']['source_oss'] ?? [];
            if (is_array($sourceOss)) (new AbraOssSettingsImporter($this->db))->apply($supplierId, $sourceOss);
            if (is_array($sourceOss) && (($sourceOss['non_eu'] ?? null) === true || ($sourceOss['import'] ?? null) === true)) {
                $report['warnings'][] = 'source_oss_extra_regime_requires_review';
            }
            $progress('periods', 0, count($preflight['periods']));
            $periods = $this->writePeriods($supplierId, $preflight['periods'], $report, $cancelled);
            $progress('periods', count($preflight['periods']), count($preflight['periods']));

            $chartRows = [
                'ucetni-osnova' => AbraSource::rows($snapshot, 'ucetni-osnova'),
                'ucet' => AbraSource::rows($snapshot, 'ucet'),
            ];
            $progress('chart', 0, count($chartRows['ucetni-osnova']) + count($chartRows['ucet']));
            $accountIds = $this->writeChart($supplierId, $chartRows, $report, $progress, $cancelled);

            $linked = AbraSnapshotBuilder::linkedInvoiceKeys($snapshot);
            $documentTotal = array_sum(array_map(static fn (string $evidence): int
                => count(AbraSource::rows($snapshot, $evidence)), AbraSource::DOCUMENT_EVIDENCES));
            $this->pendingOtherItems = [];
            $this->otherItemSourcePaid = [];
            $documentDone = 0;
            $documentTargets = [];
            $settlementFloors = [];
            $openingDocuments = [];
            $purchaseItems = [];
            $firstYear = min($years);
            $vatProjection = is_array($snapshot['_meta']['vat_projection'] ?? null)
                ? $snapshot['_meta']['vat_projection'] : [];
            $progress('documents', 0, $documentTotal);
            foreach (AbraSource::DOCUMENT_EVIDENCES as $evidence) {
                foreach (array_chunk(AbraSource::rows($snapshot, $evidence), self::PLAN_CHUNK) as $chunk) {
                    $plans = $this->documentPlans([$evidence => $chunk], $years, $preflight['periods'], $report, $linked);
                    $base = $documentDone;
                    $chunkProgress = static fn (string $step, int $done, int $all)
                        => $progress($step, $base + $done, $documentTotal);
                    $documentTargets += $this->writeDocuments($supplierId, $userId, $plans, $report,
                        $chunkProgress, $cancelled, $vatProjection);
                    foreach ($plans as $plan) {
                        $target = $documentTargets[$plan['evidence'] . '|' . $plan['source_key']] ?? null;
                        // Ostatní pohledávky a závazky stav úhrad nesou alokacemi, ne fakturou.
                        if ($target !== null && !in_array($target['type'], ['invoice', 'purchase_invoice'], true)) {
                            continue;
                        }
                        if ($target !== null && !$plan['source_cancelled'] && $plan['paid_total'] > 0.005) {
                            $settlementFloors[$target['type'] . '|' . $target['id']] = [
                                'amount' => $plan['paid_total'], 'date' => $plan['paid_at'],
                            ];
                        }
                        if ($target === null) {
                            continue;
                        }
                        // Doklad minulého roku je v deníku jen počátečním stavem účtu.
                        if ($plan['year'] < $firstYear && !$plan['source_cancelled']) {
                            $openingDocuments[] = ['type' => $target['type'], 'id' => $target['id'],
                                'account' => (string) ($plan['settlement_account'] ?? ''),
                                'kind' => $plan['kind'] === 'purchase' ? (string) $plan['document_kind'] : (string) $plan['invoice_type']];
                        }
                        if ($plan['kind'] === 'purchase') {
                            foreach ($plan['items'] as $item) {
                                $purchaseItems[(string) $item['source_key']] = ['final' => $plan['source_key'],
                                    'base' => $item['base_czk'] ?? null, 'total' => $item['total_czk'] ?? null];
                            }
                        }
                    }
                    $documentDone += count($chunk);
                    unset($plans, $chunk);
                }
            }
            $progress('documents', $documentTotal, $documentTotal);

            $movementTotal = count(AbraSource::rows($snapshot, 'banka'))
                + count(AbraSource::rows($snapshot, 'pokladni-pohyb'));
            $movementDone = 0;
            $movementTargets = [];
            $bankAccounts = AbraPaymentMapper::bankAccountLookup(AbraSource::rows($snapshot, 'bankovni-ucet'));
            $bankAccountTargets = [];
            $progress('payments', 0, $movementTotal);
            foreach (['banka', 'pokladni-pohyb'] as $evidence) {
                foreach (array_chunk(AbraSource::rows($snapshot, $evidence), self::PLAN_CHUNK) as $chunk) {
                    $plans = $this->movementPlans([$evidence => $chunk], $years, $preflight['periods'], $report, $bankAccounts);
                    $base = $movementDone;
                    $chunkProgress = static fn (string $step, int $done, int $all)
                        => $progress($step, $base + $done, $movementTotal);
                    $movementTargets += $this->writeMovements($supplierId, $userId, $plans, $report,
                        $chunkProgress, $cancelled, $bankAccountTargets);
                    $movementDone += count($chunk);
                    unset($plans, $chunk);
                }
            }
            $progress('payments', $movementTotal, $movementTotal);
            $bankRegistry = (new AbraBankAccountImporter(
                $this->db,
                new SupplierBankAccountRepository($this->db, new BankStatementOwnershipResolver($this->db)),
                new ChartOfAccountsRepository($this->db),
            ))->import($supplierId, AbraSource::rows($snapshot, 'bankovni-ucet'));
            array_push($report['warnings'], ...$bankRegistry['warnings']);
            $this->writeBankBalances($supplierId, $snapshot, $years, $preflight['periods'], $report);

            $journalPlans = $this->journalPlans($supplierId, $snapshot, $years, $preflight['periods'], $report);
            $progress('journal', 0, count($journalPlans));
            $journalTargets = $this->writeJournal($supplierId, $userId, $journalPlans, $periods, $accountIds,
                $report, $progress, $cancelled);
            $this->writeOpeningBalances($supplierId, $userId, AbraSource::rows($snapshot, 'stav-uctu'),
                $years, $preflight['periods'], $periods, $accountIds, $report, $cancelled,
                (string) ($snapshot['_meta']['mode'] ?? 'initial') === 'sync');
            $this->writeJournalDocumentLinks($supplierId, $userId, $journalPlans, $journalTargets,
                $documentTargets, $movementTargets, $report);
            $this->writeOtherItems($supplierId, $userId, $journalPlans, $journalTargets, $documentTargets, $report);

            $linkRows = AbraSource::rows($snapshot, 'vazba');
            $progress('links', 0, count($linkRows));
            $this->writeLinks($supplierId, $userId, $linkRows, $documentTargets, $movementTargets,
                $report, $progress, $cancelled);
            $this->restoreSourceSettlements($supplierId, $settlementFloors);
            $this->restoreOtherItemSettlements($supplierId, $report);
            $this->writeDocumentRelations($supplierId, $userId, $snapshot, $firstYear, $openingDocuments,
                $purchaseItems, $documentTargets, $settlementFloors, $journalPlans, $journalTargets, $report);
            $this->markUnverifiedBankMovements($supplierId, $report, $cancelled);

            $report['reconciliation'] = $this->reconcile($supplierId, $snapshot, $years, $preflight['periods'], $journalPlans, $journalTargets,
                (string) (($snapshot['_meta']['mode'] ?? '') ?: 'initial') === 'sync' || (($snapshot['_meta']['delta'] ?? false) === true));
            array_push($report['warnings'], ...($report['reconciliation']['warnings'] ?? []));
            if (($report['reconciliation']['ok'] ?? false) !== true) {
                $report['blocked'] = true;
                $report['warnings'][] = 'trial_balance_reconciliation_failed';
                $nested ? $pdo->exec('ROLLBACK TO SAVEPOINT abra_accounting_import') : $pdo->rollBack();
                if ($nested) {
                    $pdo->exec('RELEASE SAVEPOINT abra_accounting_import');
                }
                $report['created'] = 0;
                foreach ($report['counts'] as $key => $value) {
                    if (str_ends_with($key, '_created')) {
                        $report['counts'][$key] = 0;
                    }
                }
                return $this->finish($report);
            }

            if ($report['blocked']) {
                $nested ? $pdo->exec('ROLLBACK TO SAVEPOINT abra_accounting_import') : $pdo->rollBack();
                if ($nested) {
                    $pdo->exec('RELEASE SAVEPOINT abra_accounting_import');
                }
                $report['created'] = 0;
                foreach ($report['counts'] as $key => $value) {
                    if (str_ends_with($key, '_created')) {
                        $report['counts'][$key] = 0;
                    }
                }
                return $this->finish($report);
            }

            $assets = (new AbraAssetImporter($this->db, $this->imports))->import($supplierId, $userId,
                AbraSource::rows($snapshot, 'majetek'), max($years), $cancelled);
            foreach (['created', 'skipped', 'changed', 'failed'] as $key) $report[$key] += $assets[$key];
            foreach ($assets['counts'] as $key => $count) {
                $report['counts'][$key] = ($report['counts'][$key] ?? 0) + $count;
            }
            array_push($report['warnings'], ...$assets['warnings']);
            if ($assets['failed'] > 0 || $assets['changed'] > 0) {
                $report['blocked'] = true;
                $nested ? $pdo->exec('ROLLBACK TO SAVEPOINT abra_accounting_import') : $pdo->rollBack();
                if ($nested) $pdo->exec('RELEASE SAVEPOINT abra_accounting_import');
                $report['created'] = 0;
                foreach ($report['counts'] as $key => $value) {
                    if (str_ends_with($key, '_created')) $report['counts'][$key] = 0;
                }
                return $this->finish($report);
            }

            $starts = array_column($preflight['periods'], 'starts_on');
            $ends = array_column($preflight['periods'], 'ends_on');
            $this->accountingUnit->switchToDoubleEntry($supplierId, min($starts), true, max($ends));
            $this->unsupportedEvidence($snapshot, $report);
            $nested ? $pdo->exec('RELEASE SAVEPOINT abra_accounting_import') : $pdo->commit();
            if (!$nested) {
                try {
                    (new TableStatistics($this->db))->refreshAfterImport(['abra_flexi_import_map']);
                } catch (Throwable) {
                    $report['warnings'][] = 'statistics_refresh_failed';
                }
            }
        } catch (Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($nested && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT abra_accounting_import');
                $pdo->exec('RELEASE SAVEPOINT abra_accounting_import');
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $report['created'] = 0;
            foreach ($report['counts'] as $key => $value) {
                if (str_ends_with($key, '_created')) {
                    $report['counts'][$key] = 0;
                }
            }
            if ($e->getMessage() === self::CANCELLED
                || $e->getMessage() === 'asset_import_cancelled'
                || ($e instanceof AbraException && $e->errorCode === 'cancelled')) {
                $report['cancelled'] = true;
                $report['warnings'][] = 'import_cancelled';
            } else {
                error_log('ABRA importer: ' . $e::class . ' code=' . $e->getCode()
                    . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
                $report['failed']++;
                $report['blocked'] = true;
                if ($e instanceof AbraException || $e instanceof AccountingFrameworkException) $report['warnings'][] = $e->errorCode;
                $report['warnings'][] = 'import_failed';
            }
        }
        return $this->finish($report);
    }

    /**
     * Vazby dokladů pro saldokonto: doklady počátečních stavů, dobropisy, čerpání záloh
     * a vyrovnání interním dokladem ({@see AbraDocumentRelationLinker}).
     *
     * @param list<array{type:string,id:int,account:string,kind:string}> $openingDocuments
     * @param array<string,array{final:string,base:?float,total:?float}> $purchaseItems
     * @param array<string,array{type:string,id:int}> $documentTargets
     * @param array<string,array{amount:float,date:?string}> $settlementFloors
     * @param list<array<string,mixed>> $journalPlans
     * @param array<string,int> $journalTargets
     * @param array<string,mixed> $report
     */
    private function writeDocumentRelations(int $supplierId, int $userId, array $snapshot, int $firstYear,
        array $openingDocuments, array $purchaseItems, array $documentTargets, array $settlementFloors,
        array $journalPlans, array $journalTargets, array &$report): void
    {
        $linker = new AbraDocumentRelationLinker($this->db, $this->imports);
        $user = $userId > 0 ? $userId : null;
        $linker->linkOpeningDocuments($supplierId, $user, $firstYear, $openingDocuments, $report);
        $links = AbraDocumentRelationLinker::links($snapshot);
        $linker->linkCreditNotes($supplierId, $user, $links, $documentTargets, $settlementFloors, $report);
        $linker->linkAdvanceDeductions($supplierId, $user, $links, $documentTargets, $purchaseItems, $report);
        $internalEntries = [];
        foreach ($journalPlans as $plan) {
            $relation = $plan['document_relation'];
            if (is_array($relation) && in_array($relation['evidence'] ?? '', ['interni-doklad', 'vzajemny-zapocet'], true)
                && ($relation['key'] ?? '') !== '' && isset($journalTargets[$plan['source_key']])
                && $plan['source_type'] === 'manual') {
                $internalEntries[$relation['evidence'] . '|' . $relation['key']][] = (int) $journalTargets[$plan['source_key']];
            }
        }
        $linker->linkInternalSettlements($supplierId, $user, $links, $documentTargets, $internalEntries, $report);
    }

    /** @param array<string,array{amount:float,date:?string}> $floors */
    private function restoreSourceSettlements(int $supplierId, array $floors): void
    {
        $pdo = $this->db->pdo();
        $issued = $pdo->prepare('UPDATE invoices SET paid_total = GREATEST(COALESCE(paid_total, 0), ?),
            paid_at = CASE WHEN GREATEST(COALESCE(paid_total, 0), ?) >= ABS(total_with_vat) - 0.01
                THEN COALESCE(paid_at, ?, (SELECT MAX(paid_on) FROM invoice_payments
                    WHERE supplier_id = ? AND invoice_id = ?)) ELSE paid_at END
            WHERE supplier_id = ? AND id = ? AND status <> "cancelled"');
        $issuedStatus = $pdo->prepare('UPDATE invoices SET status = "paid" WHERE supplier_id = ? AND id = ?
            AND status NOT IN ("draft", "cancelled") AND paid_total >= ABS(total_with_vat) - 0.01');
        $purchase = $pdo->prepare('UPDATE purchase_invoices SET
            paid_amount_invoice_ccy = GREATEST(COALESCE(paid_amount_invoice_ccy, 0), ?),
            paid_amount_payment_ccy = GREATEST(COALESCE(paid_amount_payment_ccy, 0), ?),
            payment_currency_id = currency_id, payment_exchange_rate = 1,
            paid_at = CASE WHEN GREATEST(COALESCE(paid_amount_invoice_ccy, 0), ?) >= ABS(total_with_vat + rounding) - 0.01
                THEN COALESCE(paid_at, ?, (SELECT MAX(bt.posted_at) FROM payment_matches pm
                    JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                    WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?)) ELSE paid_at END
            WHERE supplier_id = ? AND id = ? AND status <> "cancelled"');
        $purchaseStatus = $pdo->prepare('UPDATE purchase_invoices SET status = "paid" WHERE supplier_id = ? AND id = ?
            AND status NOT IN ("draft", "cancelled")
            AND paid_amount_invoice_ccy >= ABS(total_with_vat + rounding) - 0.01');
        foreach ($floors as $target => $floor) {
            [$type, $id] = explode('|', $target, 2);
            if ($type === 'invoice') {
                $issued->execute([$floor['amount'], $floor['amount'], $floor['date'], $supplierId, (int) $id,
                    $supplierId, (int) $id]);
                $issuedStatus->execute([$supplierId, (int) $id]);
            } elseif ($type === 'purchase_invoice') {
                $purchase->execute([$floor['amount'], $floor['amount'], $floor['amount'], $floor['date'],
                    $supplierId, (int) $id, $supplierId, (int) $id]);
                $purchaseStatus->execute([$supplierId, (int) $id]);
            }
        }
    }

    private function markUnverifiedBankMovements(int $supplierId, array &$report, callable $cancelled): void
    {
        $stmt = $this->db->pdo()->prepare("SELECT bt.id FROM abra_flexi_import_map m
            JOIN bank_transactions bt ON bt.id = m.target_id
            WHERE m.supplier_id = ? AND m.kind = 'banka' AND bt.match_status <> 'ignored'
              AND (bt.match_reason IS NULL OR bt.match_reason <> 'migration_review')
              AND NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.supplier_id = m.supplier_id
                AND je.source_type = 'bank' AND je.source_id = bt.id AND je.reversed_by IS NULL)");
        $stmt->execute([$supplierId]);
        $linker = new JournalEntryLinker($this->db, 'ABRA Flexi');
        $count = 0;
        while (($id = $stmt->fetchColumn()) !== false) {
            $this->checkCancelled($cancelled);
            $linker->markUnverifiedMovement($supplierId, 'bank', (int) $id,
                BankTransactionPostingScope::MIGRATION_REVIEW_REASON,
                'Převzatý bankovní pohyb nemá ve zdroji doložený účetní zápis. Před účtováním jej zkontrolujte.');
            $count++;
        }
        if ($count > 0) {
            $report['counts']['bank_movements_review'] = $count;
            $report['warnings'][] = 'bank_movement_without_source_journal_requires_review';
        }
    }

    private function equivalentBankTarget(int $supplierId, array $plan, array $mapped): bool
    {
        if (($mapped['target_type'] ?? null) !== 'bank_transaction' || $plan['storno']) return false;
        $statementKey = $plan['year'] . '|' . $plan['account_key'] . '|'
            . $plan['currency'] . '|' . $plan['statement_key'];
        $statement = $this->imports->lookup($supplierId, 'banka-vypis', $statementKey);
        if ($statement === null || ($statement['target_type'] ?? null) !== 'bank_statement') return false;
        $stmt = $this->db->pdo()->prepare('SELECT bt.source_ref, bt.statement_id, bt.posted_at, bt.amount,
                bt.currency, bt.variable_symbol, bt.constant_symbol, bt.specific_symbol,
                bt.counterparty_account, bt.counterparty_bank, bt.counterparty_name,
                bt.description, bt.bank_ref, bs.account_number, bs.bank_code,
                bs.currency AS statement_currency, bs.statement_number
            FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
            WHERE bt.id = ? AND bs.supplier_id = ?');
        $stmt->execute([(int) $mapped['target_id'], $supplierId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($target === false || (int) $target['statement_id'] !== (int) $statement['target_id']) return false;
        $expected = [
            'source_ref' => $plan['source_key'], 'posted_at' => $plan['date'],
            'currency' => $plan['currency'], 'variable_symbol' => $plan['variable_symbol'],
            'constant_symbol' => $plan['constant_symbol'], 'specific_symbol' => $plan['specific_symbol'],
            'counterparty_account' => $plan['counterparty_account'],
            'counterparty_bank' => $plan['counterparty_bank'],
            'counterparty_name' => $plan['counterparty_name'], 'description' => $plan['description'],
            'bank_ref' => $plan['document_no'], 'account_number' => $plan['account_number'],
            'bank_code' => $plan['bank_code'], 'statement_currency' => $plan['currency'],
            'statement_number' => $plan['statement_label'],
        ];
        foreach ($expected as $field => $value) {
            if ((string) ($target[$field] ?? '') !== (string) ($value ?: '')) return false;
        }
        return number_format((float) $target['amount'], 2, '.', '')
            === number_format((float) $plan['amount'], 2, '.', '');
    }

    /** @param list<array<string,mixed>> $periodPlans @param array<string,mixed> $report @return array<int,int> */
    private function writePeriods(int $supplierId, array $periodPlans, array &$report, callable $cancelled): array
    {
        // Zakládá sdílená vrstva (důvod `import`); do mapy převodu se období zapíše níže
        // stejně jako dřív, nově založené i převzaté existující.
        $periodsService = new MigrationPeriods(new AccountingPeriodRepository($this->db));
        $protocol = new ImportProtocol('import');
        $periods = [];
        foreach ($periodPlans as $plan) {
            $this->checkCancelled($cancelled);
            if (!empty($plan['existing_only'])) {
                $periods[(int) $plan['year']] = (int) $plan['target_id'];
                continue;
            }
            $mapped = $this->mapped($supplierId, 'ucetni-obdobi', $plan['source_key'], $plan['source_hash'], $report);
            if ($mapped['state'] === 'changed') {
                $report['blocked'] = true;
                continue;
            }
            $created = false;
            $ensured = $periodsService->ensure(
                $supplierId, (int) $plan['year'], (string) $plan['starts_on'], (string) $plan['ends_on'],
                $protocol, 'periods',
                static function (int $id) use (&$created): void { $created = true; },
            );
            $id = $ensured['id'];
            if ($created) {
                $this->created($report, 'periods');
            } else {
                if ($mapped['state'] === 'same') {
                    $this->skipped($report, 'periods');
                } else {
                    $report['counts']['periods_adopted'] = ($report['counts']['periods_adopted'] ?? 0) + 1;
                }
            }
            $periods[(int) $plan['year']] = $id;
            if ($mapped['state'] === 'new') {
                $this->imports->remember($supplierId, 'ucetni-obdobi', $plan['source_key'], $plan['source_hash'], 'accounting_period', $id, $plan['year']);
            }
        }
        return $periods;
    }

    /** @param array<string,list<array<string,mixed>>> $rows @param array<string,mixed> $report @return array<string,int> */
    private function writeChart(int $supplierId, array $rows, array &$report, callable $progress, callable $cancelled): array
    {
        $mapper = new AbraJournalMapper();
        $plans = [];
        foreach ($rows as $evidence => $sourceRows) {
            foreach ($sourceRows as $row) {
                $plan = $mapper->mapAccount($row);
                $plan['source_evidence'] = $evidence;
                $plans[] = $plan;
            }
        }
        usort($plans, static fn (array $a, array $b): int => strlen((string) $a['code']) <=> strlen((string) $b['code']));
        $ids = $this->accountIds($supplierId);
        foreach ($plans as $index => $plan) {
            $this->checkCancelled($cancelled);
            $progress('chart', $index + 1, count($plans));
            if ($plan['blockers'] !== []) {
                $this->failed($report, 'accounts', $plan['blockers']);
                continue;
            }
            $mapped = $this->mapped($supplierId, $plan['source_evidence'], $plan['source_key'], $plan['source_hash'], $report);
            if ($mapped['state'] === 'changed') {
                continue;
            }
            $code = (string) $plan['code'];
            if (isset($ids[$code])) {
                $id = $ids[$code];
                $this->skipped($report, 'accounts');
            } else {
                $parentId = null;
                if (!$plan['is_synthetic']) {
                    $parentCode = substr($code, 0, 3);
                    $parentId = $ids[$parentCode] ?? null;
                    if ($parentId === null) {
                        $this->failed($report, 'accounts', ['chart_parent_missing']);
                        continue;
                    }
                }
                $this->db->pdo()->prepare('INSERT INTO chart_of_accounts
                    (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    $supplierId, $code, $plan['name'], $plan['account_type'], $plan['normal_side'],
                    $plan['is_synthetic'] ? 1 : 0, $parentId, $plan['active'] ? 1 : 0,
                ]);
                $id = (int) $this->db->pdo()->lastInsertId();
                $ids[$code] = $id;
                $this->created($report, 'accounts');
            }
            if ($mapped['state'] === 'new') {
                $this->imports->remember($supplierId, $plan['source_evidence'], $plan['source_key'], $plan['source_hash'], 'chart_account', $id, null);
            }
        }
        return $ids;
    }

    /** @return list<array<string,mixed>> */
    private function documentPlans(array $snapshot, array $years, array $periods, array &$report, array $linked): array
    {
        $mapper = new AbraDocumentMapper();
        $plans = [];
        foreach (AbraSource::DOCUMENT_EVIDENCES as $evidence) {
            foreach (AbraSource::rows($snapshot, $evidence) as $row) {
                $plan = $mapper->map($row, $evidence);
                $accountingDate = AbraSource::date($row['datUcto'] ?? null) ?? $plan['date'];
                $plan['year'] = $this->yearForDate($accountingDate, $periods) ?? $plan['year'];
                if (in_array($plan['year'], $years, true) || isset($linked[$evidence][$plan['source_key']])) {
                    array_push($report['warnings'], ...array_values(array_diff($plan['warnings'],
                        ['document_tax_classification_requires_review'])));
                    $plans[] = $plan;
                }
            }
        }
        return $plans;
    }

    /** @param list<array<string,mixed>> $plans @param array<string,mixed> $report @return array<string,array{type:string,id:int}> */
    private static function partnerSnapshot(array $partner): string
    {
        return json_encode([
            'company_name' => $partner['name'], 'ic' => $partner['ic'], 'dic' => $partner['dic'],
            'street' => $partner['street'], 'city' => $partner['city'], 'zip' => $partner['zip'],
            'country_iso2' => $partner['country'], 'main_email' => $partner['email'],
            'phone' => $partner['phone'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function repairPartnerSnapshot(int $supplierId, int $targetId, array $plan): void
    {
        $issued = $plan['kind'] === 'issued';
        $table = $issued ? 'invoices' : 'purchase_invoices';
        $column = $issued ? 'client_snapshot' : 'vendor_snapshot';
        $legacy = json_encode($plan['partner'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->db->pdo()->prepare("UPDATE {$table} SET {$column} = ?
            WHERE supplier_id = ? AND id = ? AND {$column} = ?")
            ->execute([self::partnerSnapshot($plan['partner']), $supplierId, $targetId, $legacy]);
    }

    private function repairIssuedDocumentNumber(int $supplierId, int $targetId, array $plan, array &$report): void
    {
        if ($plan['kind'] !== 'issued' || $plan['variable_symbol'] === $plan['document_no']) return;
        $documentNo = (string) $plan['document_no'];
        if (preg_match('~^[0-9A-Za-z._/-]{1,20}$~D', $documentNo) !== 1) {
            $report['warnings'][] = 'document_number_requires_review';
            return;
        }
        $stmt = $this->db->pdo()->prepare('SELECT varsymbol FROM invoices WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $targetId]);
        $current = $stmt->fetchColumn();
        $legacy = (string) $plan['variable_symbol'];
        $legacyCollision = mb_substr($legacy, 0, 11) . '-' . substr(hash('sha256', $plan['source_key']), 0, 8);
        if ($current !== $legacy && ($legacy === '' || $current !== $legacyCollision)) return;
        $conflict = $this->db->pdo()->prepare('SELECT 1 FROM invoices WHERE supplier_id = ? AND varsymbol = ? AND id <> ? LIMIT 1');
        $conflict->execute([$supplierId, $documentNo, $targetId]);
        if ($conflict->fetchColumn() !== false) {
            $report['warnings'][] = 'document_number_collision_requires_review';
            return;
        }
        $this->db->pdo()->prepare('UPDATE invoices SET varsymbol = ? WHERE supplier_id = ? AND id = ? AND varsymbol = ?')
            ->execute([$documentNo, $supplierId, $targetId, $current]);
    }

    private function writeDocuments(int $supplierId, int $userId, array $plans, array &$report,
        callable $progress, callable $cancelled, array $vatProjection): array
    {
        $targets = [];
        $writer = new MigratedDocumentWriter($this->db);
        $taxSnapshots = new AbraPurchaseTaxSnapshotWriter($this->db);
        foreach ($plans as $index => $plan) {
            $this->checkCancelled($cancelled);
            $progress('documents', $index + 1, count($plans));
            if ($plan['blockers'] !== []) {
                $this->failed($report, 'documents', $plan['blockers']);
                continue;
            }
            $mapped = $this->mapped($supplierId, $plan['evidence'], $plan['source_key'], $plan['source_hash'], $report);
            if ($mapped['state'] === 'changed') {
                continue;
            }
            if ($mapped['state'] === 'same') {
                $this->repairPartnerSnapshot($supplierId, (int) $mapped['target_id'], $plan);
                $this->repairIssuedDocumentNumber($supplierId, (int) $mapped['target_id'], $plan, $report);
                if ($plan['source_cancelled']) {
                    $this->cancelMappedDocument($supplierId, $userId, $plan, $mapped, $report);
                }
                $taxUpdate = $this->existingTaxUpdater->update($supplierId, (int) $mapped['target_id'], $plan);
                if ($taxUpdate['updated']) {
                    $report['counts']['document_tax_enriched'] = ($report['counts']['document_tax_enriched'] ?? 0) + 1;
                    $report['counts']['oss_items'] = ($report['counts']['oss_items'] ?? 0) + $taxUpdate['oss_items'];
                }
                array_push($report['warnings'], ...$taxUpdate['warnings']);
                if ($plan['kind'] === 'purchase') {
                    $snapshotUpdate = $taxSnapshots->apply($supplierId, (int) $mapped['target_id'], $plan);
                    if (!$snapshotUpdate['matched']) $report['warnings'][] = 'document_tax_snapshot_modified_requires_review';
                    $report['counts']['purchase_tax_snapshots_updated'] =
                        ($report['counts']['purchase_tax_snapshots_updated'] ?? 0) + $snapshotUpdate['updated'];
                }
                $this->applyVatProjection($supplierId, $userId, $plan, (int) $mapped['target_id'],
                    $vatProjection, $report);
                $targets[$plan['evidence'] . '|' . $plan['source_key']] = ['type' => (string) $mapped['target_type'], 'id' => (int) $mapped['target_id']];
                $this->skipped($report, 'documents');
                continue;
            }
            if ($this->routeOtherItem($supplierId, $plan, $targets, $report)) {
                continue;
            }
            $currencyId = $this->currencyId($supplierId, $plan['currency'], $report);
            if ($currencyId === null) {
                $this->failed($report, 'documents', ['target_currency_missing:' . $plan['currency']]);
                continue;
            }
            $clientId = $this->ensurePartner($supplierId, $currencyId, $plan['partner'], $plan['year'], $report);
            if ($clientId === null) {
                continue;
            }
            $taxPlan = $this->taxPlanner->plan($supplierId, $clientId, $plan);
            $plan = $taxPlan['plan'];
            array_push($report['warnings'], ...$taxPlan['warnings']);
            $report['counts']['oss_items'] = ($report['counts']['oss_items'] ?? 0) + $taxPlan['oss_items'];
            $snapshot = self::partnerSnapshot($plan['partner']);
            $preferredNumber = $plan['kind'] === 'issued'
                ? $plan['document_no'] : ($plan['variable_symbol'] ?: $plan['document_no']);
            $varsymbol = $this->uniqueDocumentNumber($supplierId, $plan['kind'], $preferredNumber, $plan['source_key']);
            if ($plan['kind'] === 'issued' && $varsymbol !== $plan['document_no']) {
                $report['warnings'][] = 'document_number_requires_review';
            }
            $sourceTotalNote = in_array('document_source_total_mismatch_requires_review', $plan['warnings'], true)
                ? 'ABRA Flexi: původní celková částka ' . number_format($plan['source_total_with_vat'], 2, ',', ' ')
                    . ' ' . $plan['currency'] . '. Nesouhlasí se základem a DPH. Doklad zkontrolujte proti originálu.'
                : null;
            $vendorNumber = $plan['vendor_document_no'];
            if ($plan['kind'] === 'purchase') {
                $vendorNumber = $this->uniqueVendorInvoiceNumber($supplierId, $clientId, $plan['date'],
                    $plan['vendor_document_no'], $plan['source_key']);
                if ($vendorNumber !== $plan['vendor_document_no']) {
                    $report['warnings'][] = 'document_vendor_number_collision_requires_review';
                    $sourceTotalNote = trim(($sourceTotalNote === null ? '' : $sourceTotalNote . "\n")
                        . 'ABRA Flexi: původní číslo přijatého dokladu ' . $plan['vendor_document_no']
                        . '. Cílové číslo bylo doplněno kvůli duplicitě.');
                }
            }
            if ($plan['kind'] === 'issued') {
                $paymentSymbol = preg_match('/^[0-9]{1,10}$/D', $plan['variable_symbol']) === 1
                    ? $plan['variable_symbol'] : null;
                if ($paymentSymbol === null && $plan['variable_symbol'] !== '') {
                    $report['warnings'][] = 'document_payment_symbol_requires_review';
                }
                $id = $writer->insertIssued(new MigratedIssuedDocument(
                    $supplierId, $plan['invoice_type'], $clientId, $varsymbol, $plan['date'], $plan['tax_date'],
                    $plan['due_date'], $currencyId, $plan['exchange_rate'], $plan['prices_include_vat'], $plan['reverse_charge'],
                    'Převzato z ABRA Flexi. Daňové členění zkontrolujte proti zdroji.', $sourceTotalNote, $snapshot,
                    $plan['total_without_vat'], $plan['total_vat'], $plan['total_with_vat'], $plan['rounding'],
                    $plan['status'], $userId > 0 ? $userId : null, $paymentSymbol, 0.0, $plan['paid_total'], null,
                    $plan['booked_at'], $plan['booked_at'] !== null && $userId > 0 ? $userId : null, null,
                ));
                $targetType = 'invoice';
            } else {
                $id = $writer->insertPurchase(new MigratedPurchaseDocument(
                    $supplierId, $clientId, str_starts_with((string) ($plan['partner']['dic'] ?? ''), 'CZ'),
                    $varsymbol, $vendorNumber, $plan['document_kind'], $plan['date'], $plan['tax_date'] ?? $plan['date'],
                    $plan['due_date'], $plan['date'], 'import', $currencyId, $plan['exchange_rate'],
                    $plan['prices_include_vat'], $plan['reverse_charge'], $snapshot, $plan['total_without_vat'], $plan['total_vat'],
                    $plan['stored_total_with_vat'], $plan['rounding'], $plan['status'], $plan['vat_deduction'],
                    'Převzato z ABRA Flexi. Daňové členění zkontrolujte proti zdroji.', $sourceTotalNote, $userId,
                    bookedAt: $plan['booked_at'], bookedBy: $plan['booked_at'] !== null && $userId > 0 ? $userId : null,
                    isFixedAsset: $plan['is_fixed_asset'],
                ));
                $targetType = 'purchase_invoice';
            }
            $items = [];
            foreach ($plan['items'] as $order => $item) {
                $vatRateId = $item['oss_rate_id'] ?? $item['foreign_rate_id']
                    ?? $this->documentVatRateId((float) $item['vat_rate'], $plan['tax_date'] ?? $plan['date'], $report);
                $items[$order] = $plan['kind'] === 'issued'
                    ? MigratedDocumentItem::issued($item['description'], $item['quantity'], $item['unit'], $item['unit_price'],
                        $vatRateId, $item['vat_rate'], $item['base'], $item['vat'], $item['total'], $item['vat_classification'],
                        $item['oss'] ?? OssMigrationPolicy::DOMESTIC_COLUMNS)
                    : MigratedDocumentItem::purchase($item['description'], $item['quantity'], $item['unit'], $item['unit_price'],
                        $vatRateId, $item['vat_rate'], $item['base'], $item['vat'], $item['total'], $item['vat_classification'],
                        $item['is_fixed_asset'] ?? false);
            }
            $plan['kind'] === 'issued' ? $writer->insertIssuedItems($id, $items) : $writer->insertPurchaseItems($id, $items);
            if ($plan['kind'] === 'purchase') {
                $snapshotUpdate = $taxSnapshots->apply($supplierId, $id, $plan);
                if (!$snapshotUpdate['matched']) throw new \LogicException('ABRA purchase item snapshot does not match imported items.');
                $report['counts']['purchase_tax_snapshots_updated'] =
                    ($report['counts']['purchase_tax_snapshots_updated'] ?? 0) + $snapshotUpdate['updated'];
            }
            $this->imports->remember($supplierId, $plan['evidence'], $plan['source_key'], $plan['source_hash'], $targetType, $id, $plan['year']);
            $this->applyVatProjection($supplierId, $userId, $plan, $id, $vatProjection, $report);
            $targets[$plan['evidence'] . '|' . $plan['source_key']] = ['type' => $targetType, 'id' => $id];
            $this->created($report, 'documents');
        }
        return $targets;
    }

    /**
     * Ostatní pohledávka či závazek ABRA ({@see AbraOtherItemWriter::route()}). Nový doklad
     * agendy počká na převzatý deník ({@see self::writeOtherItems()}), předpis mezd a daní
     * a doklad ke kontrole zůstávají jen v deníku. Závazek s DPH pokračuje jako faktura.
     *
     * @param array<string,array{type:string,id:int}> $targets
     * @return bool doklad je vyřízený, fakturou se nezakládá
     */
    private function routeOtherItem(int $supplierId, array $plan, array &$targets, array &$report): bool
    {
        $route = AbraOtherItemWriter::route($plan);
        if ($route === AbraOtherItemWriter::ROUTE_DOCUMENT) {
            return false;
        }
        $target = $plan['evidence'] . '|' . $plan['source_key'];
        if ($route === AbraOtherItemWriter::ROUTE_OTHER_ITEM) {
            $mapped = $this->mapped($supplierId, AbraSource::otherItemMapKind($plan['evidence']), $plan['source_key'],
                $plan['source_hash'], $report);
            if ($mapped['state'] === 'changed') {
                return true;
            }
            if ($mapped['state'] === 'same') {
                $targets[$target] = ['type' => (string) $mapped['target_type'], 'id' => (int) $mapped['target_id']];
                $this->otherItemSourcePaid[(int) $mapped['target_id']] = (float) $plan['paid_total'];
                $this->skipped($report, 'other_items');
                return true;
            }
            $currencyId = $this->currencyId($supplierId, 'CZK', $report);
            $partnerId = $currencyId === null ? null : $this->ensurePartner($supplierId, $currencyId, $plan['partner'], $plan['year'], $report);
            $this->pendingOtherItems[] = $plan + ['partner_id' => $partnerId];
            $targets[$target] = ['type' => 'other_item_pending', 'id' => 0];
            return true;
        }
        $targets[$target] = ['type' => 'not_transferred', 'id' => 0];
        if ($route === AbraOtherItemWriter::ROUTE_MODULE) {
            $this->skipped($report, 'other_items_payroll_or_tax');
        } else {
            $report['warnings'][] = 'other_item_requires_review';
            $this->skipped($report, 'other_items_review');
        }
        return true;
    }

    /**
     * Založí čekající ostatní položky, když už je převzatý deník: doklad s jedním vlastním
     * zápisem ho převezme ({@see AbraOtherItemWriter::posting()}), doklad bez rozpoznatelné
     * kontace zůstane jen v deníku.
     *
     * @param array<string,array{type:string,id:int}> $documentTargets
     */
    private function writeOtherItems(int $supplierId, int $userId, array $journalPlans, array $journalTargets,
        array &$documentTargets, array &$report): void
    {
        if ($this->pendingOtherItems === []) {
            return;
        }
        $entries = [];
        foreach ($journalPlans as $plan) {
            $relation = $plan['document_relation'];
            if (!is_array($relation) || !isset($journalTargets[$plan['source_key']])) {
                continue;
            }
            $evidence = (string) ($relation['evidence'] ?? '');
            if ($evidence !== '' && !in_array($evidence, ['zavazek', 'pohledavka'], true)) {
                continue;
            }
            $entry = ['id' => (int) $journalTargets[$plan['source_key']], 'source_type' => (string) $plan['source_type']];
            foreach ($evidence !== '' ? [$evidence] : ['zavazek', 'pohledavka'] as $candidate) {
                $entries[$candidate . '|' . $relation['key']][$entry['id']] = $entry;
            }
        }
        $writer = new AbraOtherItemWriter($this->db);
        foreach ($this->pendingOtherItems as $plan) {
            $target = $plan['evidence'] . '|' . $plan['source_key'];
            $posting = $writer->posting($supplierId, $plan, array_values($entries[$target] ?? []));
            if ($posting === null) {
                $documentTargets[$target] = ['type' => 'not_transferred', 'id' => 0];
                $report['warnings'][] = 'other_item_requires_review';
                $this->skipped($report, 'other_items_review');
                continue;
            }
            $id = $writer->insert($supplierId, $userId > 0 ? $userId : null, $plan, $plan['partner_id'], $posting);
            $this->imports->remember($supplierId, AbraSource::otherItemMapKind($plan['evidence']), $plan['source_key'],
                $plan['source_hash'], 'other_item', $id, $plan['year']);
            $documentTargets[$target] = ['type' => 'other_item', 'id' => $id];
            $this->otherItemSourcePaid[$id] = (float) $plan['paid_total'];
            $this->created($report, $posting['status'] === 'posted' ? 'other_items' : 'other_items_unposted');
        }
        $this->pendingOtherItems = [];
    }

    /**
     * Stav úhrady ostatní položky podle zdroje. Co ABRA vede jako uhrazené a co nepokryla
     * párovaná úhrada z banky či pokladny (zápočet, interní doklad, platba v nepřeváděném
     * roce), nese `settled_outside_amount`. Nastavuje se absolutně, opakovaný převod ho
     * nenásobí; víc alokací než zdroj položku neotevře.
     */
    private function restoreOtherItemSettlements(int $supplierId, array &$report): void
    {
        $update = $this->db->pdo()->prepare('UPDATE other_items oi
            SET oi.settled_outside_amount = GREATEST(0, LEAST(oi.amount, ?) - COALESCE((SELECT SUM(a.amount)
                FROM other_item_allocations a WHERE a.supplier_id = oi.supplier_id AND a.other_item_id = oi.id
                    AND a.reversed_on IS NULL), 0))
            WHERE oi.supplier_id = ? AND oi.id = ?');
        foreach ($this->otherItemSourcePaid as $id => $paid) {
            $update->execute([number_format(round($paid, 2), 2, '.', ''), $supplierId, $id]);
            if ($update->rowCount() > 0) {
                $report['counts']['other_items_settled_outside'] = ($report['counts']['other_items_settled_outside'] ?? 0) + 1;
            }
        }
        $this->otherItemSourcePaid = [];
    }

    /**
     * Úhrada ostatní položky přes {@see OtherItemService::allocate()}, který ověří volnou
     * částku, směr a zaúčtování platby na saldokontní účet položky. Zápis platby převzala
     * banka či pokladna z deníku ABRA, nic se neúčtuje.
     */
    private function allocateOtherItem(int $supplierId, int $userId, array $plan, int $itemId, string $movementKind,
        int $movementId, array &$report): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT abra_other_item_payment');
        try {
            $this->otherItems->allocate($supplierId, $itemId, [
                ($movementKind === 'bank' ? 'bank_transaction_id' : 'cash_document_id') => $movementId,
                'amount' => round($plan['amount'], 2),
            ], $userId > 0 ? $userId : null);
            $pdo->exec('RELEASE SAVEPOINT abra_other_item_payment');
        } catch (OtherItemException) {
            $pdo->exec('ROLLBACK TO SAVEPOINT abra_other_item_payment');
            $pdo->exec('RELEASE SAVEPOINT abra_other_item_payment');
            $report['warnings'][] = 'other_item_payment_requires_review';
            $this->skipped($report, 'payment_links');
            return;
        }
        $column = $movementKind === 'bank' ? 'bank_transaction_id' : 'cash_document_id';
        $allocation = $pdo->prepare("SELECT id FROM other_item_allocations WHERE supplier_id = ? AND other_item_id = ? AND {$column} = ?");
        $allocation->execute([$supplierId, $itemId, $movementId]);
        $this->imports->remember($supplierId, 'vazba', $plan['source_key'], $plan['source_hash'],
            'other_item_allocation', (int) $allocation->fetchColumn(), null);
        $this->created($report, 'other_item_payments');
    }

    private function applyVatProjection(int $supplierId, int $userId, array $plan, int $targetId,
        array $projection, array &$report): void
    {
        $groups = $projection[$plan['evidence'] . '|' . $plan['source_key']] ?? null;
        if (!is_array($groups) || $groups === []) return;
        $result = $this->vatProjectionApplier->apply($supplierId, $targetId, $plan, $groups);
        if ($result['warning'] !== null) $report['warnings'][] = $result['warning'];
        if ($result['updated'] < 1) return;
        $report['counts']['document_vat_projections_updated'] =
            ($report['counts']['document_vat_projections_updated'] ?? 0) + $result['updated'];
        $this->logger->log('migration.abra.tax_projection_aligned', $userId > 0 ? $userId : null,
            $plan['kind'] === 'issued' ? 'invoice' : 'purchase_invoice', $targetId,
            ['source_year' => $plan['year']], supplierId: $supplierId);
    }

    private function cancelMappedDocument(int $supplierId, int $userId, array $plan, array $mapped,
        array &$report): void
    {
        $issued = $plan['kind'] === 'issued';
        $table = $issued ? 'invoices' : 'purchase_invoices';
        $expectedType = $issued ? 'invoice' : 'purchase_invoice';
        if ($mapped['target_type'] !== $expectedType) {
            $report['warnings'][] = 'source_cancelled_target_modified_requires_review';
            return;
        }
        $stmt = $this->db->pdo()->prepare("SELECT status, booked_at FROM {$table}
            WHERE supplier_id = ? AND id = ? FOR UPDATE");
        $stmt->execute([$supplierId, $mapped['target_id']]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($target === false) {
            $report['warnings'][] = 'source_cancelled_target_missing_requires_review';
            return;
        }
        if ($target['status'] === 'cancelled') return;
        if ($target['booked_at'] !== null || $target['status'] !== ($issued ? 'sent' : 'received')) {
            $report['warnings'][] = 'source_cancelled_target_modified_requires_review';
            return;
        }
        $this->db->pdo()->prepare("UPDATE {$table} SET status = 'cancelled'
            WHERE supplier_id = ? AND id = ? AND status = ? AND booked_at IS NULL")
            ->execute([$supplierId, $mapped['target_id'], $target['status']]);
        $this->logger->log('migration.abra.document_cancelled', $userId > 0 ? $userId : null,
            $expectedType, (int) $mapped['target_id'], ['source_year' => $plan['year']], supplierId: $supplierId);
        $report['counts']['documents_cancelled'] = ($report['counts']['documents_cancelled'] ?? 0) + 1;
    }

    /** @return list<array<string,mixed>> */
    private function movementPlans(array $snapshot, array $years, array $periods, array &$report,
        array $bankAccounts = []): array
    {
        $mapper = new AbraPaymentMapper();
        $plans = [];
        foreach (['banka', 'pokladni-pohyb'] as $evidence) {
            foreach (AbraSource::rows($snapshot, $evidence) as $row) {
                $plan = $mapper->mapMovement($row, $evidence, $bankAccounts);
                $plan['year'] = $this->yearForDate($plan['date'], $periods) ?? $plan['year'];
                if (in_array($plan['year'], $years, true)) {
                    array_push($report['warnings'], ...$plan['warnings']);
                    $plans[] = $plan;
                }
            }
        }
        return $plans;
    }

    /** @return array<string,array{type:string,id:int}> */
    private function writeMovements(int $supplierId, int $userId, array $plans, array &$report,
        callable $progress, callable $cancelled, array &$bankAccountTargets = []): array
    {
        $targets = [];
        $bank = new BankStatementImportWriter($this->db, 'abra-flexi');
        $payments = new MigratedPaymentWriter($this->db);
        $cashRegister = $this->prepareCashRegister($supplierId, $plans, $payments);
        $statements = [];
        foreach ($plans as $index => $plan) {
            $this->checkCancelled($cancelled);
            $progress('payments', $index + 1, count($plans));
            if ($plan['storno']) {
                if ($plan['source_key'] !== '') {
                    $mapped = $this->mapped($supplierId, $plan['evidence'], $plan['source_key'], $plan['source_hash'], $report);
                    if ($mapped['state'] === 'same') {
                        $report['blocked'] = true;
                        $report['warnings'][] = 'source_cancelled_movement_requires_review';
                    }
                }
                $report['warnings'][] = 'storno_payment_movement_skipped';
                $this->skipped($report, 'movements');
                continue;
            }
            if ($plan['blockers'] !== []) {
                $this->failed($report, 'movements', $plan['blockers']);
                continue;
            }
            if ($plan['kind'] === 'bank') {
                $previous = $this->imports->lookup($supplierId, $plan['evidence'], $plan['source_key']);
                if ($previous !== null && !hash_equals((string) $previous['source_hash'], $plan['source_hash'])
                    && $this->equivalentBankTarget($supplierId, $plan, $previous)) {
                    if ($this->imports->refreshBankHash($supplierId, $plan['source_key'],
                        (string) $previous['source_hash'], $plan['source_hash'])) {
                        $report['counts']['bank_hash_refreshed'] = ($report['counts']['bank_hash_refreshed'] ?? 0) + 1;
                    }
                }
            }
            $mapped = $this->mapped($supplierId, $plan['evidence'], $plan['source_key'], $plan['source_hash'], $report);
            if ($mapped['state'] === 'changed') {
                continue;
            }
            if ($mapped['state'] === 'same') {
                $targets[$plan['evidence'] . '|' . $plan['source_key']] = ['type' => (string) $mapped['target_type'], 'id' => (int) $mapped['target_id']];
                $this->skipped($report, 'movements');
                continue;
            }
            if ($plan['loan_account'] ?? false) {
                // Úvěrový účet: pohyb mění jistinu úvěru, ne peníze firmy. Zůstává v deníku.
                $targets[$plan['evidence'] . '|' . $plan['source_key']] = ['type' => 'not_transferred', 'id' => 0];
                $this->skipped($report, 'loan_account_movements');
                continue;
            }
            if ($plan['kind'] === 'bank') {
                if (($plan['account_label'] ?? '') !== '' && !isset($bankAccountTargets[$plan['account_key'] . '|' . $plan['currency']])) {
                    $this->ensureBankCurrencyAccount($supplierId, $plan, $report);
                    $bankAccountTargets[$plan['account_key'] . '|' . $plan['currency']] = true;
                }
                $statementKey = $plan['year'] . '|' . $plan['account_key'] . '|' . $plan['currency'] . '|' . $plan['statement_key'];
                if (!isset($statements[$statementKey])) {
                    $statementHash = hash('sha256', $statementKey);
                    $statementMap = $this->mapped($supplierId, 'banka-vypis', $statementKey, $statementHash, $report);
                    if ($statementMap['state'] === 'same') {
                        $statements[$statementKey] = (int) $statementMap['target_id'];
                    } elseif ($statementMap['state'] === 'new') {
                        $statementId = $bank->createStatement($supplierId, $statementKey, $plan['statement_label'],
                            $plan['account_number'], $plan['bank_code'], $plan['currency'], $plan['date'], $userId > 0 ? $userId : null);
                        $this->imports->remember($supplierId, 'banka-vypis', $statementKey, $statementHash,
                            'bank_statement', $statementId, $plan['year']);
                        $statements[$statementKey] = $statementId;
                        $this->created($report, 'bank_statements');
                    } else {
                        $this->failed($report, 'movements', ['bank_statement_changed']);
                        continue;
                    }
                }
                $id = $bank->insertTransaction($supplierId, $statements[$statementKey], $plan['source_key'], [
                    'source_ref' => $plan['source_key'], 'posted_at' => $plan['date'],
                    'amount' => number_format($plan['amount'], 2, '.', ''), 'currency' => $plan['currency'],
                    'variable_symbol' => $plan['variable_symbol'] ?: null,
                    'constant_symbol' => $plan['constant_symbol'] ?: null,
                    'specific_symbol' => $plan['specific_symbol'] ?: null,
                    'counterparty_account' => $plan['counterparty_account'] ?: null,
                    'counterparty_bank' => $plan['counterparty_bank'] ?: null,
                    'counterparty_name' => $plan['counterparty_name'] ?: null,
                    'description' => $plan['description'] ?: null, 'bank_ref' => $plan['document_no'] ?: null,
                ]);
                $bank->touchStatement($supplierId, $statements[$statementKey], 1, $plan['date']);
                $targetType = 'bank_transaction';
            } else {
                $id = $payments->insertCash($supplierId, $cashRegister, $this->uniqueCashNumber($supplierId, $plan['document_no'], $plan['source_key']),
                    $plan['date'], $plan['description'], $plan['amount'], true, $userId > 0 ? $userId : null);
                $targetType = 'cash_document';
            }
            $this->imports->remember($supplierId, $plan['evidence'], $plan['source_key'], $plan['source_hash'], $targetType, $id, $plan['year']);
            $targets[$plan['evidence'] . '|' . $plan['source_key']] = ['type' => $targetType, 'id' => $id];
            $this->created($report, 'movements');
        }
        return $targets;
    }

    private function prepareCashRegister(int $supplierId, array $plans, MigratedPaymentWriter $payments): ?int
    {
        $sourceKeys = [];
        foreach ($plans as $plan) {
            if ($plan['kind'] !== 'cash' || $plan['storno'] || $plan['blockers'] !== []) continue;
            $key = (string) $plan['account_key'];
            if ($key === '') {
                throw new AbraException('cash_register_mapping_ambiguous',
                    'Zdrojový pokladní pohyb nemá jednoznačnou pokladnu.');
            }
            $sourceKeys[$key] = true;
        }
        if ($sourceKeys === []) return null;
        $existing = $this->db->pdo()->prepare("SELECT abra_key, target_id FROM abra_flexi_import_map
            WHERE supplier_id = ? AND kind = 'pokladna' AND target_type = 'cash_register'");
        $existing->execute([$supplierId]);
        $mapped = $existing->fetchAll(PDO::FETCH_KEY_PAIR);
        $allKeys = array_unique(array_merge(array_keys($sourceKeys), array_keys($mapped)));
        if (count($allKeys) !== 1) {
            throw new AbraException('cash_register_mapping_ambiguous',
                'Zdroj obsahuje více pokladen. Nelze je bezpečně sloučit do jedné cílové pokladny.');
        }
        $key = (string) $allKeys[0];
        if (isset($mapped[$key])) {
            $check = $this->db->pdo()->prepare('SELECT id FROM cash_registers WHERE supplier_id = ? AND id = ?');
            $check->execute([$supplierId, $mapped[$key]]);
            if ($check->fetchColumn() === false) {
                throw new AbraException('cash_register_mapping_ambiguous', 'Dříve převzatá pokladna v cíli chybí.');
            }
            return (int) $mapped[$key];
        }
        $register = $payments->cashRegister($supplierId, 'Pokladna ABRA Flexi');
        $this->imports->remember($supplierId, 'pokladna', $key,
            AbraSource::hash(['cash_register' => $key]), 'cash_register', $register, null);
        return $register;
    }

    private function writeBankBalances(int $supplierId, array $snapshot, array $years, array $periodPlans,
        array &$report): void
    {
        $accounts = AbraSource::rows($snapshot, 'bankovni-ucet');
        if ($accounts === []) return;
        $stmt = $this->db->pdo()->prepare('SELECT m.abra_key, bs.id, bs.prev_balance,
                COALESCE(SUM(CASE WHEN bt.amount > 0 THEN bt.amount ELSE 0 END), 0) AS credit,
                COALESCE(SUM(CASE WHEN bt.amount < 0 THEN -bt.amount ELSE 0 END), 0) AS debit
            FROM abra_flexi_import_map m
            JOIN bank_statements bs ON bs.id = m.target_id AND bs.supplier_id = m.supplier_id
            LEFT JOIN bank_transactions bt ON bt.statement_id = bs.id
            WHERE m.supplier_id = ? AND m.kind = "banka-vypis" AND m.source_year = ?
            GROUP BY m.abra_key, bs.id, bs.prev_balance');
        $writer = new BankStatementImportWriter($this->db, 'abra-flexi');
        foreach ($years as $year) {
            $stateRows = $this->rowsForYear(AbraSource::rows($snapshot, 'stav-uctu'), $year,
                count($years) === 1, $periodPlans);
            $bankRows = array_values(array_filter(AbraSource::rows($snapshot, 'banka'),
                fn (array $row): bool => $this->yearForDate(AbraSource::date($row['datUcto'] ?? $row['datVyst'] ?? null),
                    $periodPlans) === $year));
            $openings = $stateRows !== [] && $bankRows !== []
                ? AbraBankBalancePlanner::verifiedOpenings($accounts, $stateRows, $bankRows) : [];
            $stmt->execute([$supplierId, $year]);
            $groups = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $parts = explode('|', (string) $row['abra_key'], 4);
                if (count($parts) !== 4 || (int) $parts[0] !== $year
                    || preg_match('/^\d{4}-\d{2}$/D', $parts[3]) !== 1) continue;
                $groups[$parts[1] . '|' . $parts[2]][$parts[3]] = $row;
            }
            foreach ($groups as $key => $months) {
                ksort($months, SORT_STRING);
                $first = reset($months);
                $opening = $openings[$key] ?? ($first['prev_balance'] !== null
                    ? (int) round((float) $first['prev_balance'] * 100) : null);
                if ($opening === null) {
                    $report['warnings'][] = 'bank_balance_unverified';
                    continue;
                }
                $turnovers = array_map(static fn (array $row): array => [
                    'credit' => (int) round((float) $row['credit'] * 100),
                    'debit' => (int) round((float) $row['debit'] * 100),
                ], array_values($months));
                $balances = AbraBankBalancePlanner::monthly($opening, $turnovers);
                foreach (array_values($months) as $i => $month) {
                    $balance = $balances[$i];
                    $writer->setBalancesInCents($supplierId, (int) $month['id'], $balance['prev'],
                        $balance['curr'], $balance['credit'], $balance['debit']);
                    $report['counts']['bank_statements_with_balance'] =
                        ($report['counts']['bank_statements_with_balance'] ?? 0) + 1;
                }
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function journalPlans(int $supplierId, array $snapshot, array $years, array $periods, array &$report): array
    {
        $mapper = new AbraJournalMapper();
        $plans = [];
        $seen = [];
        foreach (AbraSource::rows($snapshot, 'ucetni-denik', 'denik') as $row) {
            $plan = $mapper->mapEntry($row);
            $plan['year'] = $this->yearForDate($plan['date'], $periods) ?? $plan['year'];
            if (!in_array($plan['year'], $years, true)) {
                continue;
            }
            if ($plan['zero_amount'] && $plan['blockers'] === []) {
                if ($this->imports->lookup($supplierId, 'ucetni-denik', $plan['source_key']) !== null) {
                    $this->mapped($supplierId, 'ucetni-denik', $plan['source_key'], $plan['source_hash'], $report);
                }
                $report['counts']['journal_zero_entries_skipped'] =
                    ($report['counts']['journal_zero_entries_skipped'] ?? 0) + 1;
                continue;
            }
            if (isset($seen[$plan['source_key']])) {
                $plan['blockers'][] = 'journal_identity_duplicate';
            }
            $seen[$plan['source_key']] = true;
            array_push($report['warnings'], ...$plan['warnings']);
            $plans[] = $plan;
        }
        if (($report['counts']['journal_zero_entries_skipped'] ?? 0) > 0) {
            $report['warnings'][] = 'journal_zero_entries_skipped';
        }
        return $plans;
    }

    /** @return array<string,int> */
    private function writeJournal(int $supplierId, int $userId, array $plans, array $periods, array $accountIds,
        array &$report, callable $progress, callable $cancelled): array
    {
        $targets = [];
        foreach ($plans as $index => $plan) {
            $this->checkCancelled($cancelled);
            $progress('journal', $index + 1, count($plans));
            if ($plan['blockers'] !== []) {
                $this->failed($report, 'journal_entries', $plan['blockers']);
                continue;
            }
            $mapped = $this->mapped($supplierId, 'ucetni-denik', $plan['source_key'], $plan['source_hash'], $report);
            if ($mapped['state'] === 'changed') {
                continue;
            }
            if ($mapped['state'] === 'same') {
                $targets[$plan['source_key']] = (int) $mapped['target_id'];
                $this->skipped($report, 'journal_entries');
                continue;
            }
            $periodId = $periods[$plan['year']] ?? null;
            $debitId = $accountIds[$plan['debit']] ?? null;
            $creditId = $accountIds[$plan['credit']] ?? null;
            if ($periodId === null || $debitId === null || $creditId === null) {
                $this->failed($report, 'journal_entries', ['journal_target_reference_missing']);
                continue;
            }
            $this->db->pdo()->prepare('INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, document_date, document_no, description, source_type, source_id, posted_at, posted_by)
                VALUES (?, ?, ?, NULL, ?, ?, ?, NULL, NOW(), ?)')->execute([
                $supplierId, $periodId, $plan['date'], $plan['document_no'] ?: null, $plan['description'],
                $plan['source_type'], $userId > 0 ? $userId : null,
            ]);
            $id = (int) $this->db->pdo()->lastInsertId();
            $insert = $this->db->pdo()->prepare('INSERT INTO journal_entry_lines
                (entry_id, supplier_id, account_id, side, amount, is_red_storno, cost_center,
                 currency_code, amount_foreign, fx_rate, line_no)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ([[$debitId, 'debit', 1], [$creditId, 'credit', 2]] as [$accountId, $side, $lineNo]) {
                $fxLine = ($plan['fx_bank_side'] ?? null) === $side;
                $insert->execute([$id, $supplierId, $accountId, $side, number_format($plan['amount'], 2, '.', ''),
                    $plan['is_red_storno'] ? 1 : 0, $plan['cost_center'],
                    $fxLine ? $plan['fx_currency'] : null, $fxLine ? $plan['fx_amount_foreign'] : null,
                    $fxLine ? $plan['fx_rate'] : null, $lineNo]);
            }
            $this->imports->remember($supplierId, 'ucetni-denik', $plan['source_key'], $plan['source_hash'], 'journal_entry', $id, $plan['year']);
            $this->logger->log('migration.abra.journal_imported', $userId > 0 ? $userId : null,
                'journal_entry', $id, ['source_year' => $plan['year']], supplierId: $supplierId);
            $targets[$plan['source_key']] = $id;
            $this->created($report, 'journal_entries');
            $report['counts']['journal_lines_created'] = ($report['counts']['journal_lines_created'] ?? 0) + 2;
        }
        return $targets;
    }

    private function writeOpeningBalances(int $supplierId, int $userId, array $rows, array $years,
        array $periodPlans, array $periods, array $accountIds, array &$report, callable $cancelled, bool $sync): void
    {
        if ($rows === []) {
            if (!$sync) $report['warnings'][] = 'opening_balance_evidence_missing';
            return;
        }
        foreach ($years as $year) {
            $base = [];
            $foreign = [];
            foreach ($this->rowsForYear($rows, $year, count($years) === 1, $periodPlans) as $row) {
                $this->checkCancelled($cancelled);
                $account = AbraSource::account($row['ucet'] ?? null);
                if ($account === '') {
                    $this->failed($report, 'opening_balances', ['opening_account_invalid']);
                    continue;
                }
                $debit = AbraSource::number($row['pocatekMD'] ?? $row['pocatekMd'] ?? null);
                $credit = AbraSource::number($row['pocatekDal'] ?? $row['pocatekDAL'] ?? null);
                if ($debit === null || $credit === null) {
                    $this->failed($report, 'opening_balances', ['opening_amount_invalid']);
                    continue;
                }
                $netCents = (int) round($debit * 100) - (int) round($credit * 100);
                $currency = AbraSource::currency($row['mena'] ?? null);
                if ($currency === 'CZK') {
                    $base[$account] = ($base[$account] ?? 0) + $netCents;
                } elseif ($netCents !== 0) {
                    if (isset($foreign[$account]) && $foreign[$account]['currency'] !== $currency) {
                        $foreign[$account]['currency'] = '';
                        if (!in_array('opening_foreign_currency_ambiguous', $report['warnings'], true)) {
                            $report['warnings'][] = 'opening_foreign_currency_ambiguous';
                        }
                    } else {
                        $foreign[$account] = ['currency' => $currency,
                            'net_cents' => ($foreign[$account]['net_cents'] ?? 0) + $netCents];
                    }
                }
            }
            if (array_sum($base) !== 0) {
                $this->failed($report, 'opening_balances', ['opening_balance_unbalanced']);
            }
            if (($base['701'] ?? 0) !== 0) {
                $this->failed($report, 'opening_balances', ['opening_control_account_nonzero']);
            }
            $openingFx = [];
            foreach ($foreign as $account => $value) {
                if ((!str_starts_with((string) $account, '211') && !str_starts_with((string) $account, '221'))
                    || $value['net_cents'] === 0 || $value['currency'] === '') continue;
                if (($base[$account] ?? 0) === 0) {
                    if (!in_array('opening_foreign_base_missing', $report['warnings'], true)) {
                        $report['warnings'][] = 'opening_foreign_base_missing';
                    }
                    continue;
                }
                $openingFx[$account] = [
                    'currency' => $value['currency'],
                    'amount_foreign' => number_format(abs($value['net_cents']) / 100, 2, '.', ''),
                    'fx_rate' => number_format(abs($base[$account] / $value['net_cents']), 6, '.', ''),
                ];
            }
            if ($report['blocked'] || $report['failed'] > 0) continue;
            $openingAccount = $accountIds['701'] ?? null;
            if ($sync) {
                $this->syncOpeningBalances($supplierId, $userId, $year, $base,
                    $periods[$year] ?? 0, $this->periodStart($year, $periodPlans),
                    $accountIds, $openingFx, $report, $cancelled);
                continue;
            }
            foreach ($base as $account => $netCents) {
                if ($netCents === 0) continue;
                $key = $year . '|' . $account;
                $hash = AbraSource::hash(['year' => $year, 'account' => $account, 'net_cents' => $netCents]);
                $mapped = $this->mapped($supplierId, 'stav-uctu', $key, $hash, $report);
                if ($mapped['state'] === 'changed') continue;
                if ($mapped['state'] === 'same') {
                    if (!$this->openingEntryMatches($supplierId, (int) $mapped['target_id'],
                        $accountIds[$account] ?? 0, $openingAccount ?? 0, $netCents)
                        || !$this->enrichOpeningFx($supplierId, (int) $mapped['target_id'],
                            $accountIds[$account] ?? 0, $openingFx[$account] ?? null)) {
                        $this->failed($report, 'opening_balances', ['opening_target_integrity_failed']);
                    } else {
                        $this->skipped($report, 'opening_balances');
                    }
                    continue;
                }
                if ($openingAccount === null || !isset($accountIds[$account], $periods[$year])) {
                    $this->failed($report, 'opening_balances', ['opening_target_reference_missing']);
                    continue;
                }
                $lockReason = $this->openingAdjustmentLockReason($supplierId, (int) $periods[$year],
                    $this->periodStart($year, $periodPlans), $year);
                if ($lockReason !== null) {
                    $this->failed($report, 'opening_balances', [$lockReason]);
                    continue;
                }
                $this->db->pdo()->prepare('INSERT INTO journal_entries
                    (supplier_id, period_id, entry_date, document_no, description, source_type, posted_at, posted_by)
                    VALUES (?, ?, ?, ?, ?, "opening", NOW(), ?)')->execute([
                    $supplierId, $periods[$year], $this->periodStart($year, $periodPlans),
                    'ABRA-PS-' . $year, 'Počáteční stav převzatý z ABRA Flexi', $userId > 0 ? $userId : null,
                ]);
                $id = (int) $this->db->pdo()->lastInsertId();
                $debitId = $netCents > 0 ? $accountIds[$account] : $openingAccount;
                $creditId = $netCents > 0 ? $openingAccount : $accountIds[$account];
                $amount = number_format(abs($netCents) / 100, 2, '.', '');
                $insert = $this->db->pdo()->prepare('INSERT INTO journal_entry_lines
                    (entry_id, supplier_id, account_id, side, amount, is_red_storno,
                     currency_code, amount_foreign, fx_rate, line_no)
                    VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)');
                foreach ([[$debitId, 'debit', 1], [$creditId, 'credit', 2]] as [$lineAccount, $side, $lineNo]) {
                    $fx = $lineAccount === $accountIds[$account] ? ($openingFx[$account] ?? null) : null;
                    $insert->execute([$id, $supplierId, $lineAccount, $side, $amount,
                        $fx['currency'] ?? null, $fx['amount_foreign'] ?? null, $fx['fx_rate'] ?? null, $lineNo]);
                }
                $this->imports->remember($supplierId, 'stav-uctu', $key, $hash, 'journal_entry', $id, $year);
                $this->logger->log('migration.abra.opening_imported', $userId > 0 ? $userId : null,
                    'journal_entry', $id, ['source_year' => $year], supplierId: $supplierId);
                $this->created($report, 'opening_balances');
                $report['counts']['journal_lines_created'] = ($report['counts']['journal_lines_created'] ?? 0) + 2;
            }
        }
    }

    private function openingAdjustmentLockReason(int $supplierId, int $periodId, string $date, int $year): ?string
    {
        $period = $this->db->pdo()->prepare('SELECT status FROM accounting_periods WHERE supplier_id = ? AND id = ?');
        $period->execute([$supplierId, $periodId]);
        $status = $period->fetchColumn();
        if ($status !== 'open') return 'target_period_closed:' . $year;
        $lock = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $lock->execute([$supplierId]);
        $lockedUntil = $lock->fetchColumn();
        return is_string($lockedUntil) && $date <= $lockedUntil ? 'target_date_locked:' . $year : null;
    }

    private function syncOpeningBalances(int $supplierId, int $userId, int $year, array $source,
        int $periodId, string $date, array $accountIds, array $openingFx,
        array &$report, callable $cancelled): void
    {
        $openingId = $accountIds['701'] ?? 0;
        if ($periodId <= 0 || $openingId <= 0) {
            $this->failed($report, 'opening_balances', ['opening_target_reference_missing']);
            return;
        }
        $map = $this->db->pdo()->prepare("SELECT kind, abra_key, source_hash, target_id
            FROM abra_flexi_import_map WHERE supplier_id = ? AND source_year = ?
              AND kind IN ('stav-uctu', 'stav-uctu-uprava')");
        $map->execute([$supplierId, $year]);
        $entry = $this->db->pdo()->prepare('SELECT period_id, entry_date, posted_at, reversed_by
            FROM journal_entries WHERE supplier_id = ? AND id = ?');
        $lines = $this->db->pdo()->prepare('SELECT account_id, side, amount, is_red_storno
            FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no, id');
        $target = [];
        $sequence = [];
        while ($mapped = $map->fetch(PDO::FETCH_ASSOC)) {
            $this->checkCancelled($cancelled);
            $parts = explode('|', (string) $mapped['abra_key']);
            $sourceAccount = (string) ($parts[1] ?? '');
            $account = AbraSource::account('code:' . $sourceAccount);
            if ((int) ($parts[0] ?? 0) !== $year || !isset($accountIds[$account])
                || ($mapped['kind'] === 'stav-uctu' && count($parts) !== 2)
                || ($mapped['kind'] === 'stav-uctu-uprava' && (count($parts) !== 3
                    || !ctype_digit($parts[2]) || (int) $parts[2] < 1))) {
                $this->failed($report, 'opening_balances', ['opening_target_integrity_failed']);
                return;
            }
            if ($mapped['kind'] === 'stav-uctu-uprava') {
                $sequence[$account] = max($sequence[$account] ?? 0, (int) $parts[2]);
            }
            $entry->execute([$supplierId, (int) $mapped['target_id']]);
            $header = $entry->fetch(PDO::FETCH_ASSOC);
            $lines->execute([$supplierId, (int) $mapped['target_id']]);
            $rowLines = $lines->fetchAll(PDO::FETCH_ASSOC);
            if ($header === false || (int) $header['period_id'] !== $periodId
                || $header['entry_date'] !== $date || $header['posted_at'] === null
                || $header['reversed_by'] !== null || count($rowLines) !== 2) {
                $this->failed($report, 'opening_balances', ['opening_target_integrity_failed']);
                return;
            }
            $netCents = 0;
            foreach ($rowLines as $line) {
                if ((int) $line['account_id'] === (int) $accountIds[$account]) {
                    $netCents += ((string) $line['side'] === 'debit' ? 1 : -1)
                        * (int) round((float) $line['amount'] * 100);
                }
            }
            if ($netCents === 0 || !hash_equals((string) $mapped['source_hash'], self::openingHash(
                $year, $sourceAccount, $netCents,
            )) || !$this->openingEntryMatches($supplierId, (int) $mapped['target_id'],
                (int) $accountIds[$account], $openingId, $netCents)
                || ($mapped['kind'] === 'stav-uctu' && !$this->enrichOpeningFx($supplierId,
                    (int) $mapped['target_id'], (int) $accountIds[$account], $openingFx[$account] ?? null))) {
                $this->failed($report, 'opening_balances', ['opening_target_integrity_failed']);
                return;
            }
            $target[$account] = ($target[$account] ?? 0) + $netCents;
        }
        foreach (array_unique([...array_keys($source), ...array_keys($target)]) as $account) {
            $this->checkCancelled($cancelled);
            $delta = (int) ($source[$account] ?? 0) - (int) ($target[$account] ?? 0);
            if ($delta === 0) {
                $this->skipped($report, 'opening_balances');
                continue;
            }
            if (!isset($accountIds[$account])) {
                $this->failed($report, 'opening_balances', ['opening_target_reference_missing']);
                return;
            }
            if (isset($openingFx[$account])) {
                $this->failed($report, 'opening_balances', ['opening_foreign_adjustment_requires_review']);
                return;
            }
            $lockReason = $this->openingAdjustmentLockReason($supplierId, $periodId, $date, $year);
            if ($lockReason !== null) {
                $this->failed($report, 'opening_balances', [$lockReason]);
                return;
            }
            $this->db->pdo()->prepare('INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, document_no, description, source_type, posted_at, posted_by)
                VALUES (?, ?, ?, ?, ?, "opening", NOW(), ?)')->execute([
                $supplierId, $periodId, $date, 'ABRA-PS-' . $year,
                'Úprava počátečního stavu podle ABRA Flexi', $userId > 0 ? $userId : null,
            ]);
            $entryId = (int) $this->db->pdo()->lastInsertId();
            $insert = $this->db->pdo()->prepare('INSERT INTO journal_entry_lines
                (entry_id, supplier_id, account_id, side, amount, is_red_storno, line_no)
                VALUES (?, ?, ?, ?, ?, 0, ?)');
            $amount = number_format(abs($delta) / 100, 2, '.', '');
            $insert->execute([$entryId, $supplierId, $delta > 0 ? $accountIds[$account] : $openingId,
                'debit', $amount, 1]);
            $insert->execute([$entryId, $supplierId, $delta > 0 ? $openingId : $accountIds[$account],
                'credit', $amount, 2]);
            $key = $year . '|' . $account . '|' . (($sequence[$account] ?? 0) + 1);
            $sequence[$account] = ($sequence[$account] ?? 0) + 1;
            $this->imports->remember($supplierId, 'stav-uctu-uprava', $key,
                self::openingHash($year, (string) $account, $delta), 'journal_entry', $entryId, $year);
            $target[$account] = ($target[$account] ?? 0) + $delta;
            $this->created($report, 'opening_balances');
            $report['counts']['opening_balances_adjusted'] = ($report['counts']['opening_balances_adjusted'] ?? 0) + 1;
            $report['counts']['journal_lines_created'] = ($report['counts']['journal_lines_created'] ?? 0) + 2;
        }
        $target = array_filter($target, static fn (int $amount): bool => $amount !== 0);
        $source = array_filter($source, static fn (int $amount): bool => $amount !== 0);
        ksort($target);
        ksort($source);
        if ($target !== $source) {
            $this->failed($report, 'opening_balances', ['opening_target_integrity_failed']);
        } elseif (($report['counts']['opening_balances_adjusted'] ?? 0) > 0) {
            $report['warnings'][] = 'opening_balances_adjusted_to_source';
        }
    }

    private static function openingHash(int $year, string $account, int $netCents): string
    {
        $key = ctype_digit($account) && (string) (int) $account === $account ? (int) $account : $account;
        return AbraSource::hash(['year' => $year, 'account' => $key, 'net_cents' => $netCents]);
    }

    private function enrichOpeningFx(int $supplierId, int $entryId, int $accountId, ?array $fx): bool
    {
        if ($fx === null) return true;
        $stmt = $this->db->pdo()->prepare('SELECT id, currency_code, amount_foreign, fx_rate
            FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? AND account_id = ?');
        $stmt->execute([$supplierId, $entryId, $accountId]);
        $line = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($line === false) return false;
        if ($line['currency_code'] === $fx['currency']
            && $line['amount_foreign'] === $fx['amount_foreign']
            && $line['fx_rate'] === $fx['fx_rate']) return true;
        if ($line['currency_code'] !== null || $line['amount_foreign'] !== null || $line['fx_rate'] !== null) return false;
        $update = $this->db->pdo()->prepare('UPDATE journal_entry_lines
            SET currency_code = ?, amount_foreign = ?, fx_rate = ?
            WHERE id = ? AND supplier_id = ? AND currency_code IS NULL
              AND amount_foreign IS NULL AND fx_rate IS NULL');
        $update->execute([$fx['currency'], $fx['amount_foreign'], $fx['fx_rate'], (int) $line['id'], $supplierId]);
        return $update->rowCount() === 1;
    }

    private function openingEntryMatches(int $supplierId, int $entryId, int $accountId, int $openingId, int $netCents): bool
    {
        if ($accountId <= 0 || $openingId <= 0) return false;
        $stmt = $this->db->pdo()->prepare('SELECT e.source_type, l.account_id, l.side, l.amount, l.is_red_storno
            FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
            WHERE e.supplier_id = ? AND e.id = ? ORDER BY l.line_no, l.id');
        $stmt->execute([$supplierId, $entryId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($lines) !== 2) return false;
        $debitId = $netCents > 0 ? $accountId : $openingId;
        $creditId = $netCents > 0 ? $openingId : $accountId;
        return $lines[0]['source_type'] === 'opening' && $lines[1]['source_type'] === 'opening'
            && $lines[0]['side'] === 'debit' && $lines[1]['side'] === 'credit'
            && (int) $lines[0]['account_id'] === $debitId && (int) $lines[1]['account_id'] === $creditId
            && (int) round((float) $lines[0]['amount'] * 100) === abs($netCents)
            && (int) round((float) $lines[1]['amount'] * 100) === abs($netCents)
            && !(bool) $lines[0]['is_red_storno'] && !(bool) $lines[1]['is_red_storno'];
    }

    private function writeLinks(int $supplierId, int $userId, array $rows, array $documents, array $movements,
        array &$report, callable $progress, callable $cancelled): void
    {
        $mapper = new AbraPaymentMapper();
        $writer = new MigratedPaymentWriter($this->db);
        foreach ($rows as $index => $row) {
            $this->checkCancelled($cancelled);
            $progress('links', $index + 1, count($rows));
            $plan = $mapper->mapLink($row);
            if ($plan['storno']) {
                $report['warnings'][] = 'storno_payment_link_skipped';
                $this->skipped($report, 'payment_links');
                continue;
            }
            if ($plan['blockers'] !== []) {
                $this->failed($report, 'payment_links', $plan['blockers']);
                continue;
            }
            if (!$plan['amount_currency_verified']) {
                $report['warnings'][] = 'payment_currency_conversion_requires_review';
                $this->skipped($report, 'payment_links');
                continue;
            }
            if ($plan['amount'] < 0.005) {
                $report['warnings'][] = 'zero_amount_link_skipped';
                $this->skipped($report, 'document_links');
                continue;
            }
            if ($plan['link_type'] === 'typVazbyDokl.vazbaZDD') {
                // ZDD je daňový doklad k platbě zálohy, kterou nese záloha (`uhradaZALOHY`).
                // Spárovaný by tutéž platbu započetl podruhé; k záloze ho váže
                // AbraDocumentRelationLinker.
                $this->skipped($report, 'document_links');
                continue;
            }
            $ends = [$plan['a']['evidence'], $plan['b']['evidence']];
            $invoiceEnd = array_intersect($ends, AbraSource::DOCUMENT_EVIDENCES) !== [];
            $paymentEnd = array_intersect($ends, ['banka', 'pokladni-pohyb']) !== [];
            if (!$invoiceEnd || !$paymentEnd) {
                $report['warnings'][] = 'unsupported_document_link:' . ($plan['link_type'] ?: 'unknown');
                $this->skipped($report, 'document_links');
                continue;
            }
            $mapped = $this->mapped($supplierId, 'vazba', $plan['source_key'], $plan['source_hash'], $report);
            if ($mapped['state'] === 'changed') {
                continue;
            }
            if ($mapped['state'] === 'same') {
                $this->skipped($report, 'payment_links');
                continue;
            }
            $a = $this->resolveRelation($supplierId, $plan['a'], $documents, $movements);
            $b = $this->resolveRelation($supplierId, $plan['b'], $documents, $movements);
            if (in_array($a['type'] ?? '', self::NOT_TRANSFERRED_TARGETS, true)
                || in_array($b['type'] ?? '', self::NOT_TRANSFERRED_TARGETS, true)) {
                // Úhrada předpisu, který zůstal jen v deníku (mzdy, DPH, úvěrový účet).
                $this->skipped($report, 'payment_links_not_transferred');
                continue;
            }
            [$document, $movement] = $this->documentAndMovement($a, $b);
            if ($document === null || $movement === null) {
                $referencesMovement = in_array($plan['a']['evidence'], ['banka', 'pokladni-pohyb'], true)
                    || in_array($plan['b']['evidence'], ['banka', 'pokladni-pohyb'], true)
                    || ($a !== null && in_array($a['type'], ['bank_transaction', 'cash_document'], true))
                    || ($b !== null && in_array($b['type'], ['bank_transaction', 'cash_document'], true));
                // Pohledávku převod dřív nenačítal a úhrady k ní jen přeskakoval; chybějící
                // pohledávka (mimo vybrané roky, starší snímek) proto převod nezastaví.
                $missingReceivable = ($a === null && $plan['a']['evidence'] === 'pohledavka')
                    || ($b === null && $plan['b']['evidence'] === 'pohledavka');
                if ($referencesMovement && !$missingReceivable) {
                    $this->failed($report, 'payment_links', ['payment_link_target_missing']);
                } else {
                    $report['warnings'][] = 'unsupported_document_link:' . ($plan['link_type'] ?: 'unknown');
                    $this->skipped($report, 'document_links');
                }
                continue;
            }
            $movementKind = $movement['type'] === 'bank_transaction' ? 'bank' : 'cash';
            if ($document['type'] === 'other_item') {
                $this->allocateOtherItem($supplierId, $userId, $plan, $document['id'], $movementKind, $movement['id'], $report);
                continue;
            }
            $docKind = $document['type'] === 'invoice' ? 'issued' : 'purchase';
            try {
                $this->assertPaymentDocumentActive($supplierId, $docKind, $document['id']);
                if ($movementKind === 'cash') {
                    $this->assertCashPaymentAmount($supplierId, $movement['id'], $plan['amount'], $plan['currency']);
                }
                $linkId = $movementKind === 'bank'
                    ? $this->attachBankPayment($supplierId, $userId, $docKind, $document['id'], $movement['id'],
                        $plan['amount'], $plan['currency'])
                    : $writer->attach($supplierId, $userId > 0 ? $userId : null, $docKind, $movementKind,
                        $document['id'], $movement['id'], $plan['amount']);
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $warning = match ($e->getMessage()) {
                    'payment_currency_conversion_unsupported', 'payment_currency_unverified' =>
                        'payment_currency_conversion_requires_review',
                    'payment_cash_allocation_unsupported' => 'payment_cash_allocation_requires_review',
                    'payment_target_cancelled' => 'payment_cancelled_document_requires_review',
                    default => null,
                };
                if ($warning === null) {
                    throw $e;
                }
                $report['warnings'][] = $warning;
                $this->skipped($report, 'payment_links');
                continue;
            }
            $this->imports->remember($supplierId, 'vazba', $plan['source_key'], $plan['source_hash'],
                $movementKind === 'bank' ? 'payment_match' : 'cash_document_link', $linkId, null);
            $this->created($report, 'payment_links');
        }
        $this->refreshCashPaymentBalances($supplierId, $writer);
    }

    private function refreshCashPaymentBalances(int $supplierId, MigratedPaymentWriter $writer): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT DISTINCT cd.invoice_id, cd.purchase_invoice_id FROM cash_documents cd
            JOIN abra_flexi_import_map m ON m.supplier_id = cd.supplier_id AND m.target_id = cd.id
                AND m.kind = "pokladni-pohyb" AND m.target_type = "cash_document"
            WHERE cd.supplier_id = ? AND (cd.invoice_id IS NOT NULL OR cd.purchase_invoice_id IS NOT NULL)');
        $stmt->execute([$supplierId]);
        $issued = $purchases = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['invoice_id'] !== null) $issued[] = (int) $row['invoice_id'];
            if ($row['purchase_invoice_id'] !== null) $purchases[] = (int) $row['purchase_invoice_id'];
        }
        $writer->refreshBalances($supplierId, $issued, [], []);
        $totals = $pdo->prepare('SELECT
            (SELECT COALESCE(SUM(pm.amount), 0) FROM payment_matches pm
                WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?)
            + (SELECT COALESCE(SUM(cd.total_amount), 0) FROM cash_documents cd
                WHERE cd.supplier_id = ? AND cd.purchase_invoice_id = ? AND
                    (cd.status = "posted" OR EXISTS (SELECT 1 FROM abra_flexi_import_map m
                        WHERE m.supplier_id = cd.supplier_id AND m.kind = "pokladni-pohyb"
                            AND m.target_type = "cash_document" AND m.target_id = cd.id))) AS paid,
            (SELECT MAX(bt.posted_at) FROM payment_matches pm JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?) AS bank_date,
            (SELECT MAX(cd.issue_date) FROM cash_documents cd WHERE cd.supplier_id = ? AND cd.purchase_invoice_id = ?
                AND (cd.status = "posted" OR EXISTS (SELECT 1 FROM abra_flexi_import_map m
                    WHERE m.supplier_id = cd.supplier_id AND m.kind = "pokladni-pohyb"
                        AND m.target_type = "cash_document" AND m.target_id = cd.id))) AS cash_date');
        $update = $pdo->prepare('UPDATE purchase_invoices SET paid_amount_invoice_ccy = ?, paid_amount_payment_ccy = ?,
            payment_currency_id = currency_id, payment_exchange_rate = 1,
            paid_at = CASE WHEN ? >= ABS(total_with_vat + rounding) - 0.01 THEN ? ELSE NULL END
            WHERE supplier_id = ? AND id = ? AND status <> "cancelled"');
        foreach (array_unique($purchases) as $id) {
            $totals->execute([$supplierId, $id, $supplierId, $id, $supplierId, $id, $supplierId, $id]);
            $row = $totals->fetch(PDO::FETCH_ASSOC);
            if ($row === false) throw new \LogicException('Imported purchase payment totals are missing.');
            $paid = round((float) $row['paid'], 2);
            $date = max((string) ($row['bank_date'] ?? ''), (string) ($row['cash_date'] ?? '')) ?: null;
            $update->execute([$paid, $paid, $paid, $date, $supplierId, $id]);
            $pdo->prepare('UPDATE purchase_invoices SET status = "paid" WHERE supplier_id = ? AND id = ?
                AND status NOT IN ("draft", "cancelled") AND paid_amount_invoice_ccy >= ABS(total_with_vat + rounding) - 0.01')
                ->execute([$supplierId, $id]);
        }
    }

    private function writeJournalDocumentLinks(int $supplierId, int $userId, array $plans, array $journalTargets,
        array $documents, array $movements, array &$report): void
    {
        $groups = [];
        foreach ($plans as $plan) {
            $relation = $plan['document_relation'];
            if (!is_array($relation) || ($relation['key'] ?? '') === '' || !isset($journalTargets[$plan['source_key']])) {
                continue;
            }
            $target = $this->resolveRelation($supplierId, $relation, $documents, $movements);
            if (in_array($target['type'] ?? '', self::NOT_LINKED_TARGETS, true)) {
                continue;
            }
            $type = match ($target['type'] ?? '') {
                'invoice' => 'invoice',
                'purchase_invoice' => 'purchase_invoice',
                'bank_transaction' => 'bank',
                'cash_document' => 'cash',
                default => null,
            };
            if ($type === null) {
                $report['warnings'][] = 'journal_document_link_target_missing';
                continue;
            }
            $group = $type . '|' . $target['id'];
            $groups[$group]['type'] = $type;
            $groups[$group]['id'] = (int) $target['id'];
            $groups[$group]['entries'][(string) $plan['source_key']] = [
                'id' => (int) $journalTargets[$plan['source_key']],
                'date' => (string) $plan['date'],
                'source_type' => (string) $plan['source_type'],
            ];
        }
        $linker = new JournalEntryLinker($this->db, 'ABRA Flexi', true);
        foreach ($groups as $group) {
            $entries = array_values($group['entries']);
            usort($entries, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
            $manualIds = array_column(array_values(array_filter($entries,
                static fn (array $entry): bool => $entry['source_type'] !== 'opening')), 'id');
            $created = $manualIds !== [] && $linker->attach($supplierId, $userId > 0 ? $userId : null,
                $group['type'], 'manual', $group['type'], $group['id'], $manualIds);
            $openingLink = $this->db->pdo()->prepare('INSERT IGNORE INTO journal_entry_document_links
                (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($entries as $entry) {
                if ($entry['source_type'] !== 'opening') {
                    continue;
                }
                $openingLink->execute([$supplierId, $entry['id'], $group['type'], $group['id'],
                    'Počáteční stav převzatý z ABRA Flexi', $userId > 0 ? $userId : null]);
                $created = $created || $openingLink->rowCount() > 0;
            }
            if ($created) {
                $report['counts']['journal_document_links_created'] =
                    ($report['counts']['journal_document_links_created'] ?? 0) + 1;
            }
        }
    }

    /** @return array<string,mixed> */
    private function reconcile(int $supplierId, array $snapshot, array $years, array $periods, array $journal,
        array $journalTargets, bool $sync): array
    {
        $movements = AbraSource::rows($snapshot, 'pohyb-na-uctech');
        $balances = $sync ? [] : AbraSource::rows($snapshot, 'stav-uctu');
        if ($sync && $journal === []) {
            return ['ok' => true, 'years' => [], 'warnings' => ['journal_unchanged_reconciliation_skipped']];
        }
        $writtenJournal = $this->writtenJournal($supplierId, $journal, $journalTargets);
        if ($writtenJournal === null) {
            return ['ok' => false, 'years' => [], 'warnings' => ['journal_target_integrity_failed']];
        }
        $checks = [];
        $warnings = [];
        foreach ($years as $year) {
            $yearJournal = array_values(array_filter($writtenJournal, static fn (array $row): bool => $row['year'] === $year));
            $yearMovements = $this->rowsForYear($movements, $year, count($years) === 1, $periods);
            $yearBalances = $this->rowsForYear($balances, $year, count($years) === 1, $periods);
            if ($sync && $yearJournal === [] && $yearMovements === []) continue;
            $check = (new AbraReconciler())->reconcile($yearJournal, $yearMovements, $yearBalances);
            $check['year'] = $year;
            array_push($warnings, ...$check['warnings']);
            $checks[] = $check;
        }
        return [
            'ok' => !array_any($checks, static fn (array $check): bool => !$check['ok']),
            'years' => $checks,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** @return list<array<string,mixed>>|null */
    private function writtenJournal(int $supplierId, array $plans, array $targets): ?array
    {
        if ($plans === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', array_values($targets))));
        if (count($targets) !== count($plans) || count($ids) !== count($plans)) {
            return null;
        }
        $out = [];
        foreach (array_chunk($plans, self::PLAN_CHUNK) as $batchPlans) {
            $batch = array_map(static fn (array $plan): int => (int) $targets[$plan['source_key']], $batchPlans);
            $lines = [];
            $stmt = $this->db->pdo()->prepare('SELECT e.id, e.entry_date, a.account_code, l.side, l.amount, l.is_red_storno
                FROM journal_entries e
                JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
                WHERE e.supplier_id = ? AND e.id IN (' . implode(',', array_fill(0, count($batch), '?')) . ')
                ORDER BY e.id, l.line_no, l.id');
            $stmt->execute([$supplierId, ...$batch]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $lines[(int) $row['id']][] = $row;
            }
            foreach ($batchPlans as $plan) {
                $entryLines = $lines[(int) $targets[$plan['source_key']]] ?? [];
                if (count($entryLines) !== 2) {
                    return null;
                }
                $sides = [];
                foreach ($entryLines as $line) {
                    if (isset($sides[$line['side']])) {
                        return null;
                    }
                    $sides[$line['side']] = $line;
                }
                if (!isset($sides['debit'], $sides['credit'])
                    || round((float) $sides['debit']['amount'], 2) !== round((float) $sides['credit']['amount'], 2)
                    || (bool) $sides['debit']['is_red_storno'] !== (bool) $sides['credit']['is_red_storno']) {
                    return null;
                }
                $out[] = [
                    'source_key' => $plan['source_key'],
                    'year' => $plan['year'],
                    'debit' => (string) $sides['debit']['account_code'],
                    'credit' => (string) $sides['credit']['account_code'],
                    'amount' => (float) $sides['debit']['amount'],
                    'is_red_storno' => (bool) $sides['debit']['is_red_storno'],
                ];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function rowsForYear(array $rows, int $year, bool $singleYear, array $periods): array
    {
        return array_values(array_filter($rows, function (array $row) use ($year, $singleYear, $periods): bool {
            foreach (['datUcto', 'datum', 'platiOdData'] as $field) {
                $date = AbraSource::date($row[$field] ?? null);
                if ($date !== null) {
                    return $this->yearForDate($date, $periods) === $year;
                }
            }
            foreach (['postingPeriod', 'ucetniObdobi'] as $field) {
                $period = AbraSource::reference($row[$field] ?? null);
                if (preg_match('/(?:19|20)\d{2}/', $period, $m) === 1) {
                    return (int) $m[0] === $year;
                }
            }
            return $singleYear;
        }));
    }

    private function yearForDate(?string $date, array $periods): ?int
    {
        if ($date === null) return null;
        foreach ($periods as $period) {
            if ($date >= $period['starts_on'] && $date <= $period['ends_on']) return (int) $period['year'];
        }
        return null;
    }

    private function periodStart(int $year, array $periods): string
    {
        foreach ($periods as $period) {
            if ($period['year'] === $year) return $period['starts_on'];
        }
        throw new \LogicException('Selected fiscal period is missing.');
    }

    private function ensurePartner(int $supplierId, int $currencyId, array $partner, int $year, array &$report): ?int
    {
        $key = (string) $partner['source_key'];
        // Doklady nesou historický snapshot adresy. Mapa protistrany proto drží
        // stabilní zdrojovou identitu; konkrétní dobový stav zůstává na dokladu.
        $hash = AbraSource::hash(['source_key' => $key]);
        $mapped = $this->mapped($supplierId, 'adresar', $key, $hash, $report);
        if ($mapped['state'] === 'changed') {
            return null;
        }
        if ($mapped['state'] === 'same') {
            return (int) $mapped['target_id'];
        }
        $country = $this->countryId((string) $partner['country']);
        if ($country === null) {
            $this->failed($report, 'partners', ['partner_country_missing']);
            return null;
        }
        $find = $this->db->pdo()->prepare('SELECT id FROM clients WHERE supplier_id = ? AND ((ic IS NOT NULL AND ic = ?) OR (dic IS NOT NULL AND dic = ?)) ORDER BY id LIMIT 1');
        $find->execute([$supplierId, $partner['ic'], $partner['dic']]);
        $id = $find->fetchColumn();
        if ($id === false || ($partner['ic'] === null && $partner['dic'] === null)) {
            $vat = $this->vatRateId(21.0, sprintf('%04d-01-01', $year));
            $this->db->pdo()->prepare('INSERT INTO clients
                (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email, phone,
                 language, currency_default_id, vat_rate_default_id, reverse_charge, auto_send_reminders)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "cs", ?, ?, 0, 0)')->execute([
                $supplierId, $partner['name'], $partner['ic'], $partner['dic'], $partner['street'], $partner['city'],
                $partner['zip'], $country, $partner['email'], $partner['phone'] ?: null, $currencyId, $vat,
            ]);
            $id = (int) $this->db->pdo()->lastInsertId();
            $this->created($report, 'partners');
        } else {
            $id = (int) $id;
            $report['counts']['partners_adopted'] = ($report['counts']['partners_adopted'] ?? 0) + 1;
        }
        $this->imports->remember($supplierId, 'adresar', $key, $hash, 'client', $id, $year);
        return $id;
    }

    /** @return array{state:string,target_type?:string,target_id?:int} */
    private function mapped(int $supplierId, string $evidence, string $key, string $hash, array &$report): array
    {
        $mapped = $this->imports->lookup($supplierId, $evidence, $key);
        if ($mapped === null) {
            return ['state' => 'new'];
        }
        if (!hash_equals((string) $mapped['source_hash'], $hash)) {
            $report['changed']++;
            $report['blocked'] = true;
            $report['conflicts'][] = ['evidence' => $evidence, 'source_key' => mb_substr($key, 0, 190), 'reason' => 'source_changed'];
            $report['warnings'][] = 'source_record_changed:' . $evidence;
            return ['state' => 'changed'];
        }
        return ['state' => 'same', 'target_type' => (string) $mapped['target_type'], 'target_id' => (int) $mapped['target_id']];
    }

    /** @return array<string,int> */
    private function accountIds(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['account_code']] = (int) $row['id'];
        }
        return $out;
    }

    private function currencyId(int $supplierId, string $code, array &$report): ?int
    {
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) return null;
        $stmt = $this->db->pdo()->prepare('SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id LIMIT 1');
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;
        $this->db->pdo()->prepare('INSERT INTO currencies
            (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
            VALUES (?, ?, ?, ?, ?, ?, 2, 1, 0)')->execute([$supplierId, $code, $code, $code, $code, $code]);
        $this->created($report, 'currencies');
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function ensureBankCurrencyAccount(int $supplierId, array $plan, array &$report): void
    {
        $code = (string) $plan['currency'];
        $number = (string) $plan['account_number'];
        $bankCode = (string) $plan['bank_code'];
        $stmt = $this->db->pdo()->prepare('SELECT id FROM currencies
            WHERE supplier_id = ? AND code = ? AND account_number = ? AND COALESCE(bank_code, "") = ? LIMIT 1');
        $stmt->execute([$supplierId, $code, $number, $bankCode]);
        if ($stmt->fetchColumn() !== false) return;

        $template = $this->db->pdo()->prepare('SELECT symbol, name_cs, name_en, decimals FROM currencies
            WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id LIMIT 1');
        $template->execute([$supplierId, $code]);
        $base = $template->fetch(PDO::FETCH_ASSOC) ?: [
            'symbol' => $code, 'name_cs' => $code, 'name_en' => $code, 'decimals' => 2,
        ];
        $this->db->pdo()->prepare('INSERT INTO currencies
            (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
             account_number, bank_code, bank_name, iban, bic)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?, ?, ?, ?)')->execute([
                $supplierId, $code, (string) $plan['account_label'], $base['symbol'], $base['name_cs'],
                $base['name_en'], $base['decimals'], $number, $bankCode !== '' ? $bankCode : null,
                $plan['account_bank_name'] !== '' ? $plan['account_bank_name'] : null,
                $plan['account_iban'] !== '' ? $plan['account_iban'] : null,
                $plan['account_bic'] !== '' ? $plan['account_bic'] : null,
            ]);
        $this->created($report, 'bank_accounts');
    }

    private function countryId(string $code): ?int
    {
        $code = mb_strtoupper($code);
        if ($code === 'ČR' || $code === 'CZE') {
            $code = 'CZ';
        }
        $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ? LIMIT 1');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function vatRateId(float $rate, string $date): ?int
    {
        $key = number_format($rate, 2, '.', '') . '|' . $date;
        if (array_key_exists($key, $this->vatRateCache)) return $this->vatRateCache[$key];
        $stmt = $this->db->pdo()->prepare('SELECT id FROM vat_rates WHERE country = "CZ" AND ABS(rate_percent - ?) < 0.001
            AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?) ORDER BY is_default DESC, id LIMIT 1');
        $stmt->execute([$rate, $date, $date]);
        $id = $stmt->fetchColumn();
        return $this->vatRateCache[$key] = $id === false ? null : (int) $id;
    }

    private function documentVatRateId(float $rate, string $date, array &$report): int
    {
        $current = $this->vatRateId($rate, $date);
        if ($current !== null) return $current;
        $key = number_format($rate, 2, '.', '');
        if (!array_key_exists($key, $this->historicalVatRateCache)) {
            $stmt = $this->db->pdo()->prepare('SELECT id FROM vat_rates WHERE country = "CZ" AND ABS(rate_percent - ?) < 0.001
                ORDER BY valid_from DESC, id LIMIT 1');
            $stmt->execute([$rate]);
            $historical = $stmt->fetchColumn();
            $this->historicalVatRateCache[$key] = $historical === false ? null : (int) $historical;
        }
        $historical = $this->historicalVatRateCache[$key];
        if ($historical !== null) {
            if (($report['counts']['vat_rate_outside_schedule'] ?? 0) === 0) {
                $report['warnings'][] = 'document_vat_rate_outside_cz_schedule';
            }
            $report['counts']['vat_rate_outside_schedule'] = ($report['counts']['vat_rate_outside_schedule'] ?? 0) + 1;
            return $historical;
        }
        $placeholder = $this->vatRateId(0.0, $date);
        if ($placeholder === null) throw new \RuntimeException('vat_rate_placeholder_missing');
        if (($report['counts']['vat_rate_placeholders'] ?? 0) === 0) {
            $report['warnings'][] = 'document_vat_rate_placeholder_requires_review';
        }
        $report['counts']['vat_rate_placeholders'] = ($report['counts']['vat_rate_placeholders'] ?? 0) + 1;
        return $placeholder;
    }

    private function uniqueDocumentNumber(int $supplierId, string $kind, string $preferred, string $sourceKey): string
    {
        $candidate = mb_substr(preg_replace('~[^0-9A-Za-z._/-]~u', '', $preferred) ?: ('ABRA' . substr(hash('sha256', $sourceKey), 0, 12)), 0, 20);
        $table = $kind === 'issued' ? 'invoices' : 'purchase_invoices';
        $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE supplier_id = ? AND varsymbol = ? LIMIT 1");
        $stmt->execute([$supplierId, $candidate]);
        if ($stmt->fetchColumn() !== false) {
            $candidate = mb_substr($candidate, 0, 11) . '-' . substr(hash('sha256', $sourceKey), 0, 8);
        }
        return $candidate;
    }

    private function uniqueVendorInvoiceNumber(int $supplierId, int $vendorId, string $date, string $preferred, string $sourceKey): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM purchase_invoices
            WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? AND issue_date = ? LIMIT 1');
        $stmt->execute([$supplierId, $vendorId, $preferred, $date]);
        if ($stmt->fetchColumn() === false) {
            return $preferred;
        }
        return mb_substr($preferred, 0, 41) . '-' . substr(hash('sha256', $sourceKey), 0, 8);
    }

    private function uniqueCashNumber(int $supplierId, string $preferred, string $sourceKey): string
    {
        $candidate = mb_substr($preferred !== '' ? $preferred : ('ABRA-' . substr(hash('sha256', $sourceKey), 0, 12)), 0, 50);
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM cash_documents WHERE supplier_id = ? AND doc_number = ? LIMIT 1');
        $stmt->execute([$supplierId, $candidate]);
        return $stmt->fetchColumn() === false ? $candidate : mb_substr($candidate, 0, 40) . '-' . substr(hash('sha256', $sourceKey), 0, 8);
    }

    private function assertPaymentDocumentActive(int $supplierId, string $kind, int $documentId): void
    {
        $table = $kind === 'issued' ? 'invoices' : 'purchase_invoices';
        $stmt = $this->db->pdo()->prepare("SELECT status FROM {$table} WHERE supplier_id = ? AND id = ?");
        $stmt->execute([$supplierId, $documentId]);
        if ($stmt->fetchColumn() === 'cancelled') {
            throw new \RuntimeException('payment_target_cancelled');
        }
    }

    private function assertCashPaymentAmount(int $supplierId, int $movementId, float $amount, string $currency): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT total_amount, currency_code FROM cash_documents
            WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $movementId]);
        $cash = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cash === false || $cash['currency_code'] !== $currency || $currency !== 'CZK') {
            throw new \RuntimeException('payment_currency_conversion_unsupported');
        }
        if (abs((float) $cash['total_amount'] - $amount) > 0.01) {
            throw new \RuntimeException('payment_cash_allocation_unsupported');
        }
    }

    /** Bankovní vazba stejné měny. Sdílený MigratedPaymentWriter je záměrně pouze CZK. */
    private function attachBankPayment(int $supplierId, int $userId, string $documentKind, int $documentId,
        int $movementId, float $amount, string $linkCurrency): int
    {
        $pdo = $this->db->pdo();
        $movement = $pdo->prepare('SELECT bt.currency, bt.posted_at FROM bank_transactions bt
            JOIN bank_statements bs ON bs.id = bt.statement_id
            WHERE bt.id = ? AND bs.supplier_id = ?');
        $movement->execute([$movementId, $supplierId]);
        $movementRow = $movement->fetch(PDO::FETCH_ASSOC);
        $table = $documentKind === 'issued' ? 'invoices' : 'purchase_invoices';
        $document = $pdo->prepare("SELECT c.id, c.code FROM {$table} d
            JOIN currencies c ON c.id = d.currency_id AND c.supplier_id = d.supplier_id
            WHERE d.id = ? AND d.supplier_id = ?");
        $document->execute([$documentId, $supplierId]);
        $documentRow = $document->fetch(PDO::FETCH_ASSOC);
        if ($movementRow === false || $documentRow === false
            || (string) $movementRow['currency'] !== (string) $documentRow['code']
            || $linkCurrency !== (string) $movementRow['currency']) {
            throw new \RuntimeException('payment_currency_conversion_unsupported');
        }
        $pdo->prepare('INSERT INTO payment_matches
            (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
            VALUES (?, ?, ?, ?, ?, "manual", ?)')->execute([
            $supplierId, $movementId, $documentKind === 'issued' ? $documentId : null,
            $documentKind === 'purchase' ? $documentId : null, $amount, $userId > 0 ? $userId : null,
        ]);
        $matchId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE bank_transactions SET match_status = "manual", matched_at = NOW(), matched_by = ?
            WHERE id = ? AND statement_id IN (SELECT id FROM bank_statements WHERE supplier_id = ?)')
            ->execute([$userId > 0 ? $userId : null, $movementId, $supplierId]);
        if ($documentKind === 'issued') {
            $pdo->prepare('INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id, created_by)
                VALUES (?, ?, ?, ?, ?, "bank", ?, ?)')->execute([
                $supplierId, $documentId, $movementRow['posted_at'], $amount, $documentRow['code'],
                $movementId, $userId > 0 ? $userId : null,
            ]);
            $pdo->prepare('UPDATE invoices SET paid_total =
                (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ?),
                paid_at = CASE WHEN
                    (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ?)
                    >= ABS(total_with_vat) - 0.01
                    THEN (SELECT MAX(paid_on) FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ?)
                    ELSE NULL END
                WHERE supplier_id = ? AND id = ?')->execute([
                $supplierId, $documentId, $supplierId, $documentId, $supplierId, $documentId,
                $supplierId, $documentId,
            ]);
            $pdo->prepare('UPDATE invoices SET status = "paid" WHERE supplier_id = ? AND id = ?
                AND status NOT IN ("draft", "cancelled") AND paid_total >= ABS(total_with_vat) - 0.01')->execute([$supplierId, $documentId]);
        } else {
            $pdo->prepare('UPDATE purchase_invoices SET payment_currency_id = ?, payment_exchange_rate = 1,
                paid_amount_payment_ccy =
                (SELECT COALESCE(SUM(amount), 0) FROM payment_matches WHERE supplier_id = ? AND purchase_invoice_id = ?),
                paid_amount_invoice_ccy =
                (SELECT COALESCE(SUM(amount), 0) FROM payment_matches WHERE supplier_id = ? AND purchase_invoice_id = ?),
                paid_at = CASE WHEN
                    (SELECT COALESCE(SUM(amount), 0) FROM payment_matches WHERE supplier_id = ? AND purchase_invoice_id = ?)
                    >= ABS(total_with_vat + rounding) - 0.01 THEN
                    (SELECT MAX(bt.posted_at) FROM payment_matches pm JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                        WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?)
                    ELSE NULL END
                WHERE supplier_id = ? AND id = ?')->execute([
                $documentRow['id'], $supplierId, $documentId, $supplierId, $documentId,
                $supplierId, $documentId, $supplierId, $documentId, $supplierId, $documentId,
            ]);
            $pdo->prepare('UPDATE purchase_invoices SET status = "paid" WHERE supplier_id = ? AND id = ?
                AND status NOT IN ("draft", "cancelled") AND paid_amount_invoice_ccy >= ABS(total_with_vat + rounding) - 0.01')
                ->execute([$supplierId, $documentId]);
        }
        return $matchId;
    }

    /** @return array{type:string,id:int}|null */
    private function resolveRelation(int $supplierId, array $relation, array $documents, array $movements): ?array
    {
        $evidence = $relation['evidence'];
        $key = $relation['key'];
        if ($evidence !== '') {
            $lookup = $evidence . '|' . $key;
            if (isset($documents[$lookup])) return $documents[$lookup];
            if (isset($movements[$lookup])) return $movements[$lookup];
            $mapped = $this->imports->lookup($supplierId, $evidence, $key)
                ?? (in_array($evidence, ['zavazek', 'pohledavka'], true)
                    ? $this->imports->lookup($supplierId, AbraSource::otherItemMapKind($evidence), $key) : null);
            return $mapped === null ? null : ['type' => (string) $mapped['target_type'], 'id' => (int) $mapped['target_id']];
        }
        foreach ([$evidence . '|' . $key, ...array_map(static fn (string $document): string => $document . '|' . $key,
            AbraSource::DOCUMENT_EVIDENCES)] as $lookup) {
            if (isset($documents[$lookup])) {
                return $documents[$lookup];
            }
        }
        foreach ([$evidence . '|' . $key, 'banka|' . $key, 'pokladni-pohyb|' . $key] as $lookup) {
            if (isset($movements[$lookup])) {
                return $movements[$lookup];
            }
        }
        foreach (array_values(array_unique(array_filter([$evidence, ...AbraSource::DOCUMENT_EVIDENCES, 'banka', 'pokladni-pohyb',
            AbraSource::otherItemMapKind('zavazek'), AbraSource::otherItemMapKind('pohledavka')]))) as $tryEvidence) {
            $mapped = $this->imports->lookup($supplierId, $tryEvidence, $key);
            if ($mapped !== null) {
                return ['type' => (string) $mapped['target_type'], 'id' => (int) $mapped['target_id']];
            }
        }
        return null;
    }

    /** @return array{0:?array,1:?array} */
    private function documentAndMovement(?array $a, ?array $b): array
    {
        $documents = ['invoice', 'purchase_invoice', 'other_item'];
        $movements = ['bank_transaction', 'cash_document'];
        if ($a !== null && $b !== null && in_array($a['type'], $documents, true) && in_array($b['type'], $movements, true)) {
            return [$a, $b];
        }
        if ($a !== null && $b !== null && in_array($b['type'], $documents, true) && in_array($a['type'], $movements, true)) {
            return [$b, $a];
        }
        return [null, null];
    }

    private function unsupportedEvidence(array $snapshot, array &$report): void
    {
        foreach (['inventar', 'skladovy-pohyb', 'skladova-karta', 'objednavka-prijata', 'objednavka-vydana'] as $evidence) {
            $count = count(AbraSource::rows($snapshot, $evidence));
            if ($count > 0) {
                $report['warnings'][] = 'unsupported_evidence:' . $evidence . ':' . $count;
                $report['counts']['unsupported_records'] = ($report['counts']['unsupported_records'] ?? 0) + $count;
            }
        }
    }

    private function yearContainsNewJournalRows(int $supplierId, array $rows, string $starts, string $ends): bool
    {
        foreach ($rows as $row) {
            $date = AbraSource::date($row['datUcto'] ?? null);
            if ($date === null || $date < $starts || $date > $ends) {
                continue;
            }
            if (AbraSource::number($row['sumTuz'] ?? null) === 0.0) {
                continue;
            }
            $key = AbraSource::sourceKey($row);
            $mapped = $this->imports->lookup($supplierId, 'ucetni-denik', $key);
            if ($mapped === null || !hash_equals((string) $mapped['source_hash'], AbraSource::hash($row))) {
                return true;
            }
        }
        return false;
    }

    private function targetPeriodHasForeignJournal(int $supplierId, int $periodId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM journal_entries e
            LEFT JOIN abra_flexi_import_map m ON m.supplier_id = e.supplier_id
                AND m.target_type = "journal_entry" AND m.target_id = e.id
                AND m.kind IN ("ucetni-denik", "stav-uctu")
            WHERE e.supplier_id = ? AND e.period_id = ? AND m.target_id IS NULL LIMIT 1');
        $stmt->execute([$supplierId, $periodId]);
        return $stmt->fetchColumn() !== false;
    }

    private function targetPeriodHasForeignDocuments(int $supplierId, array $snapshot, string $starts, string $ends): bool
    {
        foreach ([
            [['faktura-vydana', 'prodejka'], 'invoices', 'invoice'],
            [['faktura-prijata', 'zavazek'], 'purchase_invoices', 'purchase_invoice'],
        ] as [$evidences, $table, $targetType]) {
            $mappedIds = [];
            foreach ($evidences as $evidence) {
                foreach (AbraSource::rows($snapshot, $evidence) as $row) {
                    $date = AbraSource::date($row['datVyst'] ?? $row['datUcto'] ?? null);
                    if ($date === null || $date < $starts || $date > $ends) {
                        continue;
                    }
                    $mapped = $this->imports->lookup($supplierId, $evidence, AbraSource::sourceKey($row));
                    if ($mapped !== null && (string) $mapped['target_type'] === $targetType) {
                        $mappedIds[] = (int) $mapped['target_id'];
                    }
                }
            }
            $sql = "SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND issue_date BETWEEN ? AND ?";
            $params = [$supplierId, $starts, $ends];
            if ($mappedIds !== []) {
                $mappedIds = array_values(array_unique($mappedIds));
                $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($mappedIds), '?')) . ')';
                array_push($params, ...$mappedIds);
            }
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($params);
            if ((int) $stmt->fetchColumn() > 0) {
                return true;
            }
        }
        return false;
    }

    /** @return list<int> */
    private function years(array $years): array
    {
        $out = [];
        foreach ($years as $year) {
            $year = (int) $year;
            if ($year >= 1900 && $year <= 2200) {
                $out[$year] = true;
            }
        }
        $out = array_keys($out);
        sort($out, SORT_NUMERIC);
        return $out;
    }

    private function checkCancelled(callable $cancelled): void
    {
        if ($cancelled()) {
            throw new \RuntimeException(self::CANCELLED);
        }
    }

    private function created(array &$report, string $kind): void
    {
        $report['created']++;
        $report['counts'][$kind . '_created'] = ($report['counts'][$kind . '_created'] ?? 0) + 1;
    }

    private function skipped(array &$report, string $kind): void
    {
        $report['skipped']++;
        $report['counts'][$kind . '_skipped'] = ($report['counts'][$kind . '_skipped'] ?? 0) + 1;
    }

    private function failed(array &$report, string $kind, array $reasons): void
    {
        $report['failed']++;
        $report['blocked'] = true;
        $report['counts'][$kind . '_failed'] = ($report['counts'][$kind . '_failed'] ?? 0) + 1;
        foreach ($reasons as $reason) {
            $report['warnings'][] = (string) $reason;
        }
    }

    /** @return array<string,mixed> */
    private function report(): array
    {
        return ['created' => 0, 'skipped' => 0, 'changed' => 0, 'failed' => 0,
            'warnings' => [], 'blocked' => false, 'cancelled' => false, 'counts' => [],
            'conflicts' => [], 'reconciliation' => []];
    }

    /** @return array<string,mixed> */
    private function finish(array $report): array
    {
        $report['warnings'] = array_values(array_unique(array_map('strval', $report['warnings'])));
        return $report;
    }
}
