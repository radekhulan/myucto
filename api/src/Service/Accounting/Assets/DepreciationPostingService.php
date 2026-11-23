<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\TaxEvidence\TaxEvidenceYearLock;
use Psr\Log\LoggerInterface;

/**
 * DepreciationPostingService — zaúčtování odpisů (Epic F3, §3.4).
 *
 * Účtuje se VÝHRADNĚ ÚČETNÍ odpis (R10): MD náklad z rule 'depreciation.booking'
 * (default 551) / D oprávky z KARTY (accumulated_account_code, R18), ročně jedním
 * zápisem per majetek, idempotence ('depreciation', depreciation_entries.id) — R3.
 * Daňový řádek je čistě evidenční (confirmed, bez journalu).
 *
 * bookYear je hromadný a idempotentní: upsert entries (stejná id přes
 * uq_de_asset_kind_year) + postDocument in-place rewrite. Chyba jednoho majetku
 * (PostingException — např. uzavřené období) běh NEshodí: loguje se a přidá do
 * errors (vzor backfill-accounting.php SKIP).
 */
final class DepreciationPostingService
{
    /** Kód nálezu: zaúčtovaný účetní řádek roku nemá v deníku svůj zápis ({@see postedWithoutJournal()}). */
    public const JOURNAL_MISSING = 'depreciation_journal_missing';

    public function __construct(
        private readonly PostingService $posting,
        private readonly PostingRuleRepository $rules,
        private readonly DepreciationEntryRepository $entries,
        private readonly AssetRepository $assets,
        private readonly AccountingPeriodRepository $periods,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly DepreciationCalculator $calculator,
    ) {}

    /**
     * Hromadné potvrzení + zaúčtování odpisů roku (R12): pro každý majetek in_use
     * (a vyřazený v daném roce) zapíše účetní řádek (posted, journal) a daňový
     * řádek (confirmed). Rok s pauzou §26/8 tax krok přeskočí (R14).
     *
     * $allowClosingPeriod (R7, audit 2026-07 B10): propíše se do meta['allow_closing_period']
     * VÝHRADNĚ když je true — tj. jen když volá {@see ClosingService::bookDepreciation}
     * pro období skutečně ve stavu 'closing'. Přímé volání z DepreciationAction ho nechává
     * false → účtování striktně jen do 'open' (žádné obcházení R7).
     *
     * Zaúčtovaný účetní řádek roku bez vlastního zápisu v deníku se znovu neúčtuje a vrátí
     * se v errors s kódem {@see JOURNAL_MISSING}. V roce uzavřeném původním systémem se
     * účetní odpisy neúčtují vůbec (`accounting_skipped` = 'closing_taken_over').
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{booked:int, skipped:int, total_accounting:float, total_tax:float,
     *     errors: list<array{asset_id:int, code:string}>, accounting_skipped?:string}
     */
    public function bookYear(int $supplierId, int $fiscalYear, array $meta = [], bool $allowClosingPeriod = false): array
    {
        $taxEvidence = $this->isTaxEvidence($supplierId);
        // Daňová evidence: rok s dokončenou roční uzávěrkou se nemění (tamtéž vyřazení, přepis,
        // přerušení i TZ v AssetService). Podvojné účetnictví hlídá období přes PostingService.
        if ($taxEvidence && TaxEvidenceYearLock::isFinal($this->db, $supplierId, $fiscalYear)) {
            throw new AssetException(TaxEvidenceYearLock::ERROR_CODE, TaxEvidenceYearLock::message($fiscalYear), 409);
        }
        $result = [
            'booked' => 0,
            'skipped' => 0,
            'total_accounting' => 0.0,
            'total_tax' => 0.0,
            'errors' => [],
        ];

        [$periodStart, $periodEnd] = $this->bookingWindow($supplierId, $fiscalYear, $taxEvidence);

        $pdo = $this->db->pdo();
        $foreign = $pdo->inTransaction();
        foreach ($this->assets->listForBooking($supplierId, $fiscalYear, $periodStart, $periodEnd) as $asset) {
            $assetId = (int) $asset['id'];
            try {
                // Odvolanou transakci (1020/1213) karta zopakuje, pokud ji vlastní. V cizí
                // transakci (uzávěrka) ji dostane vlastník: další karty by jinak běžely
                // v autocommitu.
                [$bookedSomething, $accAmount, $taxAmount, $takenOver, $journalMissing] = TransactionFailure::retrying(
                    $pdo,
                    fn (): array => $this->bookAsset($supplierId, $assetId, $fiscalYear, $periodEnd, $taxEvidence, $meta, $allowClosingPeriod),
                );
                if ($takenOver) {
                    $result['accounting_skipped'] = 'closing_taken_over';
                }
                // Účetní řádek roku je zaúčtovaný, ale jeho zápis v deníku chybí (deník roku
                // nahradil jiný, např. obnovený z převodu). Znovu se neúčtoval: odpis roku by
                // se zdvojil, pokud ho nese jiný zápis. Ohlásí se, rozhodne účetní.
                if ($journalMissing) {
                    $result['errors'][] = ['asset_id' => $assetId, 'code' => self::JOURNAL_MISSING];
                }
                if ($bookedSomething) {
                    $result['booked']++;
                    $result['total_accounting'] = round($result['total_accounting'] + $accAmount, 2);
                    $result['total_tax'] = round($result['total_tax'] + $taxAmount, 2);
                } else {
                    $result['skipped']++;
                }
            } catch (PostingException $e) {
                $this->logger->warning('bookYear: zaúčtování odpisu majetku selhalo', [
                    'supplier_id' => $supplierId,
                    'asset_id' => $assetId,
                    'fiscal_year' => $fiscalYear,
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                ]);
                $result['errors'][] = ['asset_id' => $assetId, 'code' => $e->errorCode];
            } catch (\Throwable $e) {
                if ($foreign && TransactionFailure::aborted($e)) {
                    throw $e;
                }
                $this->logger->error('bookYear: neočekávaná chyba u majetku', [
                    'supplier_id' => $supplierId,
                    'asset_id' => $assetId,
                    'fiscal_year' => $fiscalYear,
                    'message' => $e->getMessage(),
                ]);
                $result['errors'][] = ['asset_id' => $assetId, 'code' => 'operation_failed'];
            }
        }

        return $result;
    }

    /**
     * Odpisy roku jedné karty v jedné transakci.
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{0:bool,1:float,2:float,3:bool,4:bool} zaúčtováno něco, účetní a daňový odpis,
     *     rok uzavřel původní systém, zaúčtovaný účetní řádek bez vlastního zápisu v deníku
     */
    private function bookAsset(
        int $supplierId,
        int $assetId,
        int $fiscalYear,
        string $periodEnd,
        bool $taxEvidence,
        array $meta,
        bool $allowClosingPeriod,
    ): array {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            $lockedAsset = $this->assets->findForUpdate($supplierId, $assetId);
            if ($lockedAsset === null) {
                throw new PostingException('not_found', 'Majetek během účtování odpisů zanikl.', 404);
            }
            $asset = $lockedAsset;
            $ctx = $this->buildContext($asset);
            $bookedSomething = false;
            $accAmount = 0.0;
            $taxAmount = 0.0;

            // Chronologický zámek (audit 2026-07 B9): neúčtuj rok N, pokud bezprostředně
            // předchozí IN-SYSTEM rok (N-1) nemá potvrzený ani přerušený daňový řádek a
            // majetek v něm ještě měl nenulovou daňovou ZC. Migrační počáteční stavy
            // (opening_tax_years) posunou první in-system rok, takže roky kryté migrací
            // (< systemStartYear) guard nezablokuje.
            $systemStartYear = $ctx->firstSystemYear($fiscalYear);
            $priorYear = $fiscalYear - 1;
            if ($priorYear >= $systemStartYear
                && $this->entries->findYear($assetId, 'tax', $priorYear) === null
            ) {
                $probe = $this->calculator->taxYearRow($ctx, (string) $asset['tax_method'], $fiscalYear);
                if ($probe !== null
                    && (round((float) ($probe['full_amount'] ?? 0), 2) > 0.0
                        || round((float) ($probe['residual_end'] ?? 0), 2) > 0.0)
                ) {
                    throw new PostingException(
                        'prior_year_not_confirmed',
                        'Rok ' . $priorYear . ' (předchozí zdaňovací období) nemá potvrzený ani přerušený '
                            . 'daňový odpis, přitom majetek má nenulovou zůstatkovou cenu — nejdřív zaúčtuj '
                            . 'nebo přeruš rok ' . $priorYear . ', teprve pak rok ' . $fiscalYear . '.',
                    );
                }
            }

            // 1) účetní řádek roku → upsert (posted) + journal 551/oprávky. Rok, jehož
            //    odpisy zaúčtoval deník převzatý z jiného programu, ani rok uzavřený původním
            //    systémem se znovu neúčtuje.
            $closingTakenOver = $this->closingTakenOver($supplierId, $fiscalYear, $taxEvidence);
            $journalMissing = !$closingTakenOver && $this->accountingJournalMissing($supplierId, $asset, $fiscalYear, $taxEvidence);
            $accRow = $closingTakenOver ? null : $this->accountingRowToBook($asset, $ctx, $fiscalYear, $taxEvidence);
            if ($accRow !== null) {
                // Zaúčtování k poslednímu dni ZDAŇOVACÍHO OBDOBÍ (reálné hranice
                // z hlavičky bookYear — hospodářský rok posunut).
                $entryDate = $periodEnd;
                if ($asset['disposal_date'] !== null && $asset['disposal_date'] < $entryDate) {
                    $entryDate = (string) $asset['disposal_date'];
                }
                $this->postAccountingEntry($supplierId, $asset, $accRow, $entryDate, $meta, $allowClosingPeriod);
                $accAmount = round((float) $accRow['amount'], 2);
                $bookedSomething = true;
            }

            // 2) daňový řádek roku → upsert confirmed; existující pauza (R14) i ručně
            //    přepsaný odpis roku se nechají být
            $existingTax = $this->entries->findYear($assetId, 'tax', $fiscalYear);
            if (self::taxRowIsRecomputed($existingTax)) {
                $taxRow = $this->calculator->taxYearRow($ctx, (string) $asset['tax_method'], $fiscalYear);
                if ($taxRow !== null) {
                    $this->entries->upsert([
                        'supplier_id' => $supplierId,
                        'asset_id' => $assetId,
                        'kind' => 'tax',
                        'fiscal_year' => $fiscalYear,
                        'amount' => (float) $taxRow['amount'],
                        'full_amount' => (float) $taxRow['full_amount'],
                        'residual_value_end' => (float) $taxRow['residual_end'],
                        'is_paused' => (bool) $taxRow['is_paused'],
                        'is_half' => (bool) $taxRow['is_half'],
                        'months_count' => $taxRow['months_count'] ?? null,
                        'detail' => isset($taxRow['months']) && $taxRow['months'] !== null
                            ? json_encode($taxRow['months'], JSON_UNESCAPED_UNICODE)
                            : null,
                        'status' => 'confirmed',
                    ]);
                    $taxAmount = round((float) $taxRow['amount'], 2);
                    $bookedSomething = true;
                }
            }

            if ($ownTx) {
                $pdo->commit();
            }
            return [$bookedSomething, $accAmount, $taxAmount, $closingTakenOver, $journalMissing];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Co by {@see bookYear()} za rok zaúčtoval, bez zápisu: stejný výběr majetku, stejné
     * roční řádky kalkulátoru, stejná pravidla přeskočení (převzatý deník, pauza, ruční
     * přepis). `pending_*` je rozdíl proti tomu, co už za rok leží v evidenci — o tolik
     * se po zaúčtování změní účetní náklad 551 (VH), resp. daňové odpisy v DPPO.
     * Chronologický zámek předchozího roku se nekontroluje: jde o odhad, ne o zaúčtování.
     * `findings` = karty, jejichž zaúčtovaný účetní řádek roku nemá v deníku svůj zápis
     * (bookYear je neúčtuje, ohlásí je); `accounting_skipped` = 'closing_taken_over', když
     * rok uzavřel původní systém a účetní odpisy se v něm neúčtují.
     *
     * @return array{assets:int, planned_accounting:float, posted_accounting:float, pending_accounting:float,
     *     planned_tax:float, confirmed_tax:float, pending_tax:float, accounting_skipped:?string,
     *     findings: list<array{asset_id:int, code:string}>}
     */
    public function previewYear(int $supplierId, int $fiscalYear): array
    {
        $taxEvidence = $this->isTaxEvidence($supplierId);
        [$periodStart, $periodEnd] = $this->bookingWindow($supplierId, $fiscalYear, $taxEvidence);

        $takenOver = $this->closingTakenOver($supplierId, $fiscalYear, $taxEvidence);
        $out = [
            'assets' => 0,
            'planned_accounting' => 0.0, 'posted_accounting' => 0.0, 'pending_accounting' => 0.0,
            'planned_tax' => 0.0, 'confirmed_tax' => 0.0, 'pending_tax' => 0.0,
            'accounting_skipped' => $takenOver ? 'closing_taken_over' : null,
            'findings' => [],
        ];
        foreach ($this->assets->listForBooking($supplierId, $fiscalYear, $periodStart, $periodEnd) as $asset) {
            $assetId = (int) $asset['id'];
            $ctx = $this->buildContext($asset);
            $existingAcc = $this->entries->findYear($assetId, 'accounting', $fiscalYear);
            $existingTax = $this->entries->findYear($assetId, 'tax', $fiscalYear);
            $postedAcc = $existingAcc !== null ? round((float) $existingAcc['amount'], 2) : 0.0;
            $confirmedTax = $existingTax !== null ? round((float) $existingTax['amount'], 2) : 0.0;

            if (!$takenOver && $this->accountingJournalMissing($supplierId, $asset, $fiscalYear, $taxEvidence)) {
                $out['findings'][] = ['asset_id' => $assetId, 'code' => self::JOURNAL_MISSING];
            }
            $accRow = $takenOver ? null : $this->accountingRowToBook($asset, $ctx, $fiscalYear, $taxEvidence);
            $plannedAcc = $accRow !== null ? round((float) $accRow['amount'], 2) : $postedAcc;
            $taxRow = self::taxRowIsRecomputed($existingTax)
                ? $this->calculator->taxYearRow($ctx, (string) $asset['tax_method'], $fiscalYear)
                : null;
            $plannedTax = $taxRow !== null ? round((float) $taxRow['amount'], 2) : $confirmedTax;

            if ($plannedAcc === 0.0 && $plannedTax === 0.0 && $postedAcc === 0.0 && $confirmedTax === 0.0) {
                continue;
            }
            $out['assets']++;
            $out['planned_accounting'] += $plannedAcc;
            $out['posted_accounting'] += $postedAcc;
            $out['planned_tax'] += $plannedTax;
            $out['confirmed_tax'] += $confirmedTax;
        }
        foreach (['planned_accounting', 'posted_accounting', 'planned_tax', 'confirmed_tax'] as $k) {
            $out[$k] = round($out[$k], 2);
        }
        $out['pending_accounting'] = round($out['planned_accounting'] - $out['posted_accounting'], 2);
        $out['pending_tax'] = round($out['planned_tax'] - $out['confirmed_tax'], 2);

        return $out;
    }

    /**
     * Průběžný účetní odpis k rozvahovému dni mimo deník (volba výkazu, {@see
     * \MyInvoice\Service\Accounting\Reports\InterimAccrualAdjustment}).
     *
     * {@see bookYear()} zaúčtuje odpis celého roku až k poslednímu dni období. Výkaz k datu
     * uvnitř roku s touto volbou ukáže odpis za uplynulé celé měsíce, jako by se účtoval
     * měsíčně. Výběr karet a roční řádek jsou stejné jako u bookYear; karta, jejíž odpis
     * roku už v evidenci leží (zaúčtovaný, převzatý z jiného programu nebo zaúčtovaný při
     * vyřazení), se přeskočí, protože ho nese deník. Rok uzavřený původním systémem se
     * neupravuje vůbec. Do deníku se nic nezapisuje.
     *
     * Měsíční rozpis bere z ročního řádku (rovnoměrné účetní odpisy a převzatý plán). Řádek
     * bez rozpisu (účetní odpisy podle daňových) se rozloží rovným dílem na měsíce, v nichž
     * karta v období odpisuje. Rozpuštění investičního zdroje neziskové organizace, které
     * zaúčtování odpisu doprovází, se tu nepromítá.
     *
     * @return array{lines: list<array{expense:string, accumulated:string, amount:float}>, total: float, assets: int}
     */
    public function interimAccounting(int $supplierId, string $periodStartsOn, string $asOf): array
    {
        $out = ['lines' => [], 'total' => 0.0, 'assets' => 0];
        if ($asOf < $periodStartsOn || $this->isTaxEvidence($supplierId)) {
            return $out;
        }
        $period = $this->periods->findForDate($supplierId, $periodStartsOn);
        if ($period === null || (string) $period['starts_on'] !== $periodStartsOn) {
            return $out;
        }
        $fiscalYear = (int) $period['fiscal_year'];
        $periodEnd = (string) $period['ends_on'];
        if ($this->closingTakenOver($supplierId, $fiscalYear, false)) {
            return $out;
        }

        $firstMonth = self::monthIndex($periodStartsOn);
        $lastMonth = self::monthIndex($periodEnd);
        // Započítávají se jen celé uplynulé měsíce: k 15. 10. odpis za září, k 31. 10. i za říjen.
        $elapsedTo = $asOf >= $periodEnd
            ? $lastMonth
            : self::monthIndex($asOf) - ($asOf === date('Y-m-t', (int) strtotime($asOf)) ? 0 : 1);
        if ($elapsedTo < $firstMonth) {
            return $out;
        }
        $expense = (string) ($this->rules->resolve($supplierId, 'depreciation.booking')['debit_account_code'] ?? '551');

        $lines = [];
        foreach ($this->assets->listForBooking($supplierId, $fiscalYear, $periodStartsOn, $periodEnd) as $asset) {
            if ($asset['accumulated_account_code'] === null
                || $this->entries->findYear((int) $asset['id'], 'accounting', $fiscalYear) !== null) {
                continue;
            }
            $row = $this->accountingRowToBook($asset, $this->buildContext($asset), $fiscalYear, false);
            if ($row === null) {
                continue;
            }
            if (is_array($row['months'] ?? null) && $row['months'] !== []) {
                $amount = 0.0;
                foreach ($row['months'] as $month) {
                    if (self::monthIndex((string) $month['month'] . '-01') <= $elapsedTo) {
                        $amount += (float) $month['amount'];
                    }
                }
            } else {
                $from = max($firstMonth, self::monthIndex((string) $asset['put_into_use_date']) + 1);
                $to = $asset['disposal_date'] !== null ? min($lastMonth, self::monthIndex((string) $asset['disposal_date'])) : $lastMonth;
                $months = $to - $from + 1;
                $amount = $months > 0
                    ? (float) $row['amount'] * max(0, min($elapsedTo, $to) - $from + 1) / $months
                    : 0.0;
            }
            $amount = round($amount, 2);
            if ((int) round($amount * 100) === 0) {
                continue;
            }
            $accumulated = (string) $asset['accumulated_account_code'];
            $lines[$accumulated] = round(($lines[$accumulated] ?? 0.0) + $amount, 2);
            $out['total'] = round($out['total'] + $amount, 2);
            $out['assets']++;
        }
        ksort($lines, SORT_STRING);
        foreach ($lines as $accumulated => $amount) {
            $out['lines'][] = ['expense' => $expense, 'accumulated' => (string) $accumulated, 'amount' => $amount];
        }

        return $out;
    }

    private static function monthIndex(string $date): int
    {
        return ((int) substr($date, 0, 4)) * 12 + ((int) substr($date, 5, 2)) - 1;
    }

    /**
     * Účetní roční řádek, který bookYear pro majetek zaúčtuje, nebo null (daňová evidence,
     * karta bez účtu oprávek, rok zaúčtovaný převzatým deníkem, nulový odpis).
     *
     * @param array<string,mixed> $asset
     * @return array<string,mixed>|null
     */
    private function accountingRowToBook(array $asset, DepreciationContext $ctx, int $fiscalYear, bool $taxEvidence): ?array
    {
        if ($taxEvidence || $asset['accumulated_account_code'] === null) {
            return null;
        }
        $existing = $this->entries->findYear((int) $asset['id'], 'accounting', $fiscalYear);
        if (DepreciationEntryRepository::isBookedByMigratedJournal($existing)
            || $this->postedWithoutJournal((int) $asset['supplier_id'], $existing)) {
            return null;
        }
        $row = $this->calculator->accountingYearRow(
            $ctx,
            $fiscalYear,
            (string) $asset['acc_method'],
            (string) $asset['tax_method'],
        );

        return $row !== null && round((float) $row['amount'], 2) > 0.0 ? $row : null;
    }

    /**
     * Zaúčtovaný účetní řádek roku, jehož vlastní zápis ('depreciation', id řádku) v deníku
     * není. Odpis roku pak nese jiný zápis (deník převzatý nebo obnovený z jiného programu)
     * nebo chybí úplně; nové zaúčtování by ho v prvním případě zdvojilo. Řádek zaúčtovaný
     * převzatým deníkem ({@see DepreciationEntryRepository::isBookedByMigratedJournal()}) se
     * nepočítá: tam je to stav očekávaný.
     */
    private function postedWithoutJournal(int $supplierId, ?array $existing): bool
    {
        if ($existing === null || ($existing['status'] ?? '') !== 'posted' || DepreciationEntryRepository::isBookedByMigratedJournal($existing)) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_entries WHERE supplier_id = ? AND source_type = 'depreciation' AND source_id = ? LIMIT 1"
        );
        $stmt->execute([$supplierId, (int) $existing['id']]);
        return $stmt->fetchColumn() === false;
    }

    /** @param array<string,mixed> $asset */
    private function accountingJournalMissing(int $supplierId, array $asset, int $fiscalYear, bool $taxEvidence): bool
    {
        return !$taxEvidence && $asset['accumulated_account_code'] !== null
            && $this->postedWithoutJournal($supplierId, $this->entries->findYear((int) $asset['id'], 'accounting', $fiscalYear));
    }

    /**
     * Rok uzavřel původní systém (převzaté uzávěrkové zápisy, {@see ClosingRepository::takenOverClosingEntryIds()}):
     * jeho deník je úplný včetně odpisů, uzávěrka ho jen navazuje. Účetní odpis se do něj
     * neúčtuje, jinak by uzavření knih odmítlo zbytek po převzaté uzávěrce.
     */
    private function closingTakenOver(int $supplierId, int $fiscalYear, bool $taxEvidence): bool
    {
        if ($taxEvidence) {
            return false;
        }
        $period = $this->periods->findByYear($supplierId, $fiscalYear);
        return $period !== null && (new ClosingRepository($this->db))->takenOverClosingEntryIds($supplierId, (int) $period['id']) !== [];
    }

    /**
     * Přepočítá bookYear daňový řádek? Pauzu (R14), ruční přepis a řádek potvrzený převodem
     * z jiného programu nechává být (převzatý daňový odpis uplatnila firma v podaném přiznání).
     */
    private static function taxRowIsRecomputed(?array $existingTax): bool
    {
        return $existingTax === null
            || (!$existingTax['is_paused'] && !DepreciationEntryRepository::isOverridden($existingTax)
                && !DepreciationEntryRepository::isConfirmedByMigration($existingTax));
    }

    private function isTaxEvidence(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);

        return $stmt->fetchColumn() === 'tax_evidence';
    }

    /**
     * Reálné hranice zdaňovacího období (hospodářský rok posunut), fallback kalendář.
     *
     * @return array{0:string,1:string}
     */
    private function bookingWindow(int $supplierId, int $fiscalYear, bool $taxEvidence): array
    {
        $calendar = $this->supplierCalendar($supplierId);
        $periodRow = $this->periods->findByYear($supplierId, $fiscalYear);
        if ($periodRow !== null) {
            return [(string) $periodRow['starts_on'], (string) $periodRow['ends_on']];
        }
        if ($calendar->isCalendar() || $taxEvidence) {
            return [$calendar->periodStart($fiscalYear), $calendar->periodEnd($fiscalYear)];
        }
        // Hospodářský rok bez založeného období — nezakládat omylem kalendářní
        // období přes ensureOpenPeriodFor (F4). Uživatel založí období nejdřív.
        // Navigace v hlášce musí sedět na SKUTEČNOU položku menu: „Účetnictví →
        // Uzávěrka" (routa /accounting/periods). Dřív tu stálo „Účetnictví →
        // Období", což je sekce, která v rozhraní neexistuje — uživatel ji hledal
        // marně. `fiscal_year` v kontextu chyby staví proklik na FE.
        throw new PostingException(
            'period_missing',
            'Účetní období ' . $fiscalYear . ' (hospodářský rok) není založeno — '
            . 'nejdřív ho vytvořte v Účetnictví → Uzávěrka.',
            422,
            ['fiscal_year' => $fiscalYear],
        );
    }

    /**
     * Upsert účetního řádku (status posted) + zaúčtování MD 551 / D oprávky
     * s idempotencí ('depreciation', entry.id). Sdílené pro bookYear i dispose
     * (krok 1 R19). Vrací id depreciation_entries řádku.
     *
     * @param array<string,mixed> $asset karta (find/listForBooking)
     * @param array<string,mixed> $row roční řádek z DepreciationCalculator::accountingYearRow
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @param bool $allowClosingPeriod R7 (B10) — jen ClosingService pro období 'closing'.
     */
    public function postAccountingEntry(int $supplierId, array $asset, array $row, string $entryDate, array $meta = [], bool $allowClosingPeriod = false): int
    {
        $guard = new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db);
        return $guard->transaction(function () use ($guard, $supplierId, $asset, $row, $entryDate, $meta, $allowClosingPeriod): int {
            $guard->lockPeriods($supplierId);
            try {
                return $this->postAccountingEntryLocked($supplierId, $asset, $row, $entryDate, $meta, $allowClosingPeriod);
            } catch (\MyInvoice\Service\Nonprofit\Capital\CapitalException $e) {
                throw new PostingException($e->errorCode, $e->getMessage(), $e->httpStatus);
            }
        });
    }

    private function postAccountingEntryLocked(int $supplierId, array $asset, array $row, string $entryDate, array $meta, bool $allowClosingPeriod): int
    {
        $amount = round((float) $row['amount'], 2);
        $entryId = $this->entries->upsert([
            'supplier_id' => $supplierId,
            'asset_id' => (int) $asset['id'],
            'kind' => 'accounting',
            'fiscal_year' => (int) $row['fiscal_year'],
            'amount' => $amount,
            'full_amount' => $amount,
            'residual_value_end' => (float) $row['residual_end'],
            'is_paused' => false,
            'is_half' => false,
            'months_count' => $row['months_count'] ?? null,
            'detail' => isset($row['months']) && $row['months'] !== null
                ? json_encode($row['months'], JSON_UNESCAPED_UNICODE)
                : null,
            'status' => 'posted',
        ]);

        $rule = $this->rules->resolve($supplierId, 'depreciation.booking');
        $expense = $rule['debit_account_code'] ?? '551';

        $this->periods->ensureOpenPeriodFor($supplierId, $entryDate);
        $this->posting->postDocument($supplierId, 'depreciation', $entryId, [
            ['account_code' => $expense, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => (string) $asset['accumulated_account_code'], 'side' => 'credit', 'amount' => $amount],
        ], [
            'entry_date' => $entryDate,
            'document_no' => (string) $asset['inventory_number'],
            'description' => 'Účetní odpis ' . (int) $row['fiscal_year'] . ' — ' . $asset['name'],
            'posted' => true,
            'posted_by' => $meta['posted_by'] ?? null,
            'user_id' => $meta['user_id'] ?? null,
            'ip' => $meta['ip'] ?? null,
            'user_agent' => $meta['user_agent'] ?? null,
            // R7 (B10): flag se přidá jen když volající (ClosingService) explicitně povolil
            // 'closing'. Default false → PostingService drží striktní 'open'.
            'allow_closing_period' => $allowClosingPeriod,
        ]);

        (new \MyInvoice\Service\Nonprofit\Capital\CapitalService($this->db, $this->posting))->onDepreciation(
            $supplierId, (int) $asset['id'], $entryId, $allowClosingPeriod, $meta['posted_by'] ?? $meta['user_id'] ?? null,
        );
        return $entryId;
    }

    /**
     * Sestaví DepreciationContext karty z DB stavu (karta + TZ + potvrzené řádky).
     * Sdílené pro bookYear i AssetService (plan/dispose).
     *
     * @param array<string,mixed> $asset
     */
    public function buildContext(array $asset): DepreciationContext
    {
        $improvements = [];
        foreach ($this->assets->improvements((int) $asset['id']) as $imp) {
            $improvements[] = [
                'completed_on' => (string) $imp['completed_on'],
                'amount' => (float) $imp['amount'],
            ];
        }

        $confirmed = [];
        foreach ($this->entries->forAsset((int) $asset['id']) as $e) {
            $confirmed[] = [
                ...$e,
                'fiscal_year' => (int) $e['fiscal_year'],
                'kind' => (string) $e['kind'],
                'amount' => (float) $e['amount'],
                'full_amount' => (float) $e['full_amount'],
                'is_paused' => (bool) $e['is_paused'],
                'is_half' => (bool) $e['is_half'],
            ];
        }
        usort($confirmed, static fn (array $a, array $b): int => $a['fiscal_year'] <=> $b['fiscal_year']);

        $calendar = $this->supplierCalendar((int) $asset['supplier_id']);

        return DepreciationContext::fromAsset($asset, $calendar, $improvements, $confirmed);
    }

    /** @var array<int, FiscalCalendar> memo režimu firmy v rámci běhu */
    private array $calendarCache = [];

    /**
     * Kalendář odpisů firmy (kalendářní vs hospodářský rok) — období firmy, bez nich
     * nastavení firmy ({@see AccountingPeriodRepository::fiscalCalendar()}), ne jedno
     * kotevní datum (F1). Kalendářní poplatníci = shodné s v1.
     */
    private function supplierCalendar(int $supplierId): FiscalCalendar
    {
        return $this->calendarCache[$supplierId]
            ??= $this->periods->fiscalCalendar($supplierId);
    }
}
