<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
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
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{booked:int, skipped:int, total_accounting:float, total_tax:float,
     *     errors: list<array{asset_id:int, code:string}>}
     */
    public function bookYear(int $supplierId, int $fiscalYear, array $meta = [], bool $allowClosingPeriod = false): array
    {
        $modeStmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $modeStmt->execute([$supplierId]);
        $taxEvidence = $modeStmt->fetchColumn() === 'tax_evidence';
        $result = [
            'booked' => 0,
            'skipped' => 0,
            'total_accounting' => 0.0,
            'total_tax' => 0.0,
            'errors' => [],
        ];

        // Reálné hranice zdaňovacího období (hospodářský rok posunut), fallback kalendář.
        $calendar = $this->supplierCalendar($supplierId);
        $periodRow = $this->periods->findByYear($supplierId, $fiscalYear);
        if ($periodRow !== null) {
            $periodStart = (string) $periodRow['starts_on'];
            $periodEnd = (string) $periodRow['ends_on'];
        } elseif ($calendar->isCalendar() || $taxEvidence) {
            $periodStart = $calendar->periodStart($fiscalYear);
            $periodEnd = $calendar->periodEnd($fiscalYear);
        } else {
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

        $pdo = $this->db->pdo();
        foreach ($this->assets->listForBooking($supplierId, $fiscalYear, $periodStart, $periodEnd) as $asset) {
            $assetId = (int) $asset['id'];
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            try {
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
                $firstYear = $asset['put_into_use_date'] !== null
                    ? $calendar->fiscalYearOfDate((string) $asset['put_into_use_date'])
                    : $fiscalYear;
                $systemStartYear = $firstYear + (int) $asset['opening_tax_years'];
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

                // 1) účetní řádek roku → upsert (posted) + journal 551/oprávky
                if (!$taxEvidence && $asset['accumulated_account_code'] !== null) {
                    $accRow = $this->calculator->accountingYearRow(
                        $ctx,
                        $fiscalYear,
                        (string) $asset['acc_method'],
                        (string) $asset['tax_method'],
                    );
                    if ($accRow !== null && round((float) $accRow['amount'], 2) > 0.0) {
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
                }

                // 2) daňový řádek roku → upsert confirmed; existující pauza se nechá být (R14)
                $existingTax = $this->entries->findYear($assetId, 'tax', $fiscalYear);
                if ($existingTax === null || !$existingTax['is_paused']) {
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
                if ($bookedSomething) {
                    $result['booked']++;
                    $result['total_accounting'] = round($result['total_accounting'] + $accAmount, 2);
                    $result['total_tax'] = round($result['total_tax'] + $taxAmount, 2);
                } else {
                    $result['skipped']++;
                }
            } catch (PostingException $e) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $this->logger->warning('bookYear: zaúčtování odpisu majetku selhalo', [
                    'supplier_id' => $supplierId,
                    'asset_id' => $assetId,
                    'fiscal_year' => $fiscalYear,
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                ]);
                $result['errors'][] = ['asset_id' => $assetId, 'code' => $e->errorCode];
            } catch (\Throwable $e) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
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

        return new DepreciationContext(
            inputPrice: (float) $asset['input_price'],
            taxGroup: $asset['tax_group'] === null ? null : (int) $asset['tax_group'],
            firstYearIncrease: (string) $asset['tax_first_year_increase'],
            isFirstOwner: (bool) $asset['is_first_owner'],
            // §30e platí jen pro vozidla pořízená od 1. 1. 2024 (zákon 349/2023 Sb.);
            // u starších M1 se limit 2 mil. neaplikuje.
            isM1Vehicle: (bool) $asset['is_m1_vehicle'] && (string) $asset['acquisition_date'] >= '2024-01-01',
            m1LimitException: (bool) $asset['m1_limit_exception'],
            putIntoUseDate: $asset['put_into_use_date'] === null ? null : (string) $asset['put_into_use_date'],
            disposalDate: $asset['disposal_date'] === null ? null : (string) $asset['disposal_date'],
            accUsefulLifeMonths: $asset['acc_useful_life_months'] === null ? null : (int) $asset['acc_useful_life_months'],
            accResidualValue: (float) $asset['acc_residual_value'],
            openingTaxYears: (int) $asset['opening_tax_years'],
            openingTaxAmount: (float) $asset['opening_tax_amount'],
            openingAccMonths: (int) $asset['opening_acc_months'],
            openingAccAmount: (float) $asset['opening_acc_amount'],
            improvements: $improvements,
            confirmedEntries: $confirmed,
            calendar: $calendar,
        );
    }

    /** @var array<int, FiscalCalendar> memo režimu firmy v rámci běhu */
    private array $calendarCache = [];

    /**
     * Kalendář odpisů firmy (kalendářní vs hospodářský rok) — dle TVARU období,
     * ne jednoho kotevního data (F1). Kalendářní poplatníci = shodné s v1.
     */
    private function supplierCalendar(int $supplierId): FiscalCalendar
    {
        return $this->calendarCache[$supplierId]
            ??= FiscalCalendar::forPeriods($this->periods->listForTenant($supplierId));
    }
}
