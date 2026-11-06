<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Repository\NonprofitTaxAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentGraphReceipt;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CurrentProviderFactory;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HistoricProviderFactory;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadMode;
use MyInvoice\Service\Tax\Return\TaxReturnException;

/**
 * Jediná čtecí cesta DPPO 504 k aktuálnímu potvrzenému posouzení J6.
 *
 * Legacy (a výslovně převedené) revize se ověřují dosavadním způsobem nad uloženým JSON.
 * Revize uložená jen ve stránkovaném formátu (paged_v2) se ověří proti aktuálnímu otisku
 * účetních podkladů a její výsledek se sestaví z ověřených stránek neměnného grafu.
 */
final readonly class NonprofitDppoAssessmentSource
{
    /** Strop řádků výsledku, které DPPO přebírá do jednoho výpočtu a důkazu. */
    public const int MAX_RESULT_ROWS = 100000;

    public function __construct(
        private NonprofitTaxAssessmentRepository $legacy,
        private NonprofitTaxPagedAssessmentRepository $paged,
        private J6FundingCapacity $fundingCapacity,
        private J6CurrentProviderFactory $current,
        private J6HistoricProviderFactory $historic,
        private \MyInvoice\Infrastructure\Database\Connection $db,
    ) {}

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>} [posouzení ve tvaru legacy, receipt pro DPPO]
     */
    public function current(int $supplierId, array $period, array $legacySnapshot): array
    {
        $view = $this->currentView($supplierId, (int) $period['id']);
        if (($view['storage_version'] ?? null) !== 'paged_v2' || !isset($view['graph_receipt_hash'])) {
            return [$view, NonprofitDppoJ6Receipt::capture($period, $legacySnapshot, $view)];
        }
        return [$view, NonprofitDppoJ6Receipt::capturePaged($period, $view, $view['graph_receipt_hash'])];
    }

    /**
     * Aktuální potvrzené posouzení období ve tvaru legacy (result.rows, snapshot.legal_profile),
     * ověřené proti dnešním účetním podkladům. Používá DPPO i použití daňové úspory.
     */
    public function currentView(int $supplierId, int $periodId): array
    {
        $assessment = $this->legacy->latest($supplierId, $periodId, true);
        if ($assessment === null || $assessment['status'] !== 'confirmed') {
            throw new TaxReturnException('nonprofit_dppo_assessment_required', 'DPPO 504 vyžaduje aktuální potvrzené posouzení J6.', 422);
        }
        if (!self::pagedOnly($assessment)) {
            $this->fundingCapacity->assertCurrentReceipt($supplierId, $assessment);
            return $assessment;
        }
        [$row, $receipt] = $this->pagedRow($supplierId, $assessment['id']);
        $fingerprint = $this->current->fingerprint($supplierId, $periodId, true);
        if ($fingerprint->findingCount !== 0 || !hash_equals($fingerprint->inputHash, $receipt->snapshotInputHash)) self::stale();
        return $this->pagedView($row, $receipt);
    }

    /**
     * Konkrétní (i historická) potvrzená revize ve tvaru legacy pro ověření dříve
     * zaznamenaného použití úspory. Neověřuje aktuálnost podkladů, jen neměnnost revize.
     */
    public function historicalView(int $supplierId, int $assessmentId): array
    {
        $assessment = $this->legacy->find($supplierId, $assessmentId);
        if (!self::pagedOnly($assessment)) {
            $this->fundingCapacity->assertHistoricalReceipt($supplierId, $assessment);
            return $assessment;
        }
        [$row, $receipt] = $this->pagedRow($supplierId, $assessmentId);
        return $this->pagedView($row, $receipt);
    }

    /** @return array{0: array<string,mixed>, 1: J6AssessmentGraphReceipt} */
    private function pagedRow(int $supplierId, int $assessmentId): array
    {
        $row = $this->paged->find($supplierId, $assessmentId, true);
        $receipt = $row['graph_receipt'] ?? null;
        if (!$receipt instanceof J6AssessmentGraphReceipt || $row['status'] !== 'confirmed') self::stale();
        return [$row, $receipt];
    }

    private function pagedView(array $row, J6AssessmentGraphReceipt $receipt): array
    {
        $providers = $this->historic->create($this->paged->binding($row), J6ReadMode::HISTORIC);
        return ['id' => $row['id'], 'supplier_id' => $row['supplier_id'], 'period_id' => $row['period_id'],
            'revision_no' => $row['revision_no'], 'status' => $row['status'], 'ruleset' => $row['ruleset'],
            'storage_version' => 'paged_v2', 'input_hash' => $receipt->snapshotInputHash,
            'request_hash' => $row['request_hash'], 'graph_receipt_hash' => (string) $receipt->toArray()['receipt_hash'],
            // Stránkovaný zdroj právní profil nenese; použije se potvrzený profil období jako v legacy snímku.
            'snapshot' => ['legal_profile' => (new NonprofitLegalProfileService($this->db))->assessmentContext($row['supplier_id'], $row['period_id'], true)['legal_profile'] ?? null],
            'result' => $this->result($providers, $receipt)];
    }

    /** Aktuální revize období vznikla stránkovanou cestou a nemá legacy snímek podkladů. */
    public function currentIsPagedOnly(int $supplierId, int $periodId): bool
    {
        $assessment = $this->legacy->latest($supplierId, $periodId, true);
        return $assessment !== null && $assessment['status'] === 'confirmed' && self::pagedOnly($assessment);
    }

    /**
     * Legacy snímek zdrojů období nejde sestavit: aktuální revize období nebo revize
     * některého předchozího období vznikla stránkovanou cestou. Podklady období se pak
     * dokládají otiskem stránkovaného zdroje (J6CurrentProviderFactory::fingerprint).
     */
    public static function pagedHistory(\MyInvoice\Infrastructure\Database\Connection $db, int $supplierId, int $periodId, bool $currentRead = false): bool
    {
        $assessments = new NonprofitTaxAssessmentRepository($db);
        $latest = $assessments->latest($supplierId, $periodId, $currentRead);
        if ($latest !== null && $latest['status'] === 'confirmed' && self::pagedOnly($latest)) return true;
        $paged = array_values(array_filter($assessments->effectiveOtherPeriods($supplierId, $periodId, $currentRead), self::pagedOnly(...)));
        if ($paged === []) return false;
        $stmt = $db->pdo()->prepare('SELECT id, starts_on, ends_on FROM accounting_periods WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $periods = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), null, 'id');
        $startsOn = (string) ($periods[$periodId]['starts_on'] ?? '');
        foreach ($paged as $revision) if ((string) ($periods[$revision['period_id']]['ends_on'] ?? '') < $startsOn) return true;
        return false;
    }

    /** Revize bez původního legacy JSON (vznikla stránkovanou cestou). */
    public static function pagedOnly(array $assessment): bool
    {
        return ($assessment['storage_version'] ?? null) === 'paged_v2' && ($assessment['legacy_input_hash'] ?? null) === null;
    }

    private function result(\MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HistoricProviderSet $providers, J6AssessmentGraphReceipt $receipt): array
    {
        if ($receipt->ruleset !== NonprofitTaxRules::VERSION) self::stale();
        if (($receipt->counts['result'] ?? PHP_INT_MAX) > self::MAX_RESULT_ROWS) {
            throw new TaxReturnException('nonprofit_dppo_assessment_too_large',
                'Posouzení má příliš mnoho řádků pro jeden výpočet DPPO 504.', 422);
        }
        $rows = [];
        foreach ($providers->calculation->pages('result', 500) as $page) {
            foreach ($page as $result) {
                unset($result['result_row_hash']);
                $rows[] = $result;
            }
        }
        $summary = $receipt->summary;
        return ['ruleset' => NonprofitTaxRules::VERSION, 'profile' => $summary['profile'],
            'assessment_method' => $summary['assessment_method'],
            'accounting_result_cents' => $summary['accounting_result_cents'],
            'excluded_revenue_cents' => $summary['excluded_revenue_cents'],
            'cost_addbacks_cents' => $summary['cost_addbacks_cents'],
            'assessed_result_cents' => $summary['assessed_result_cents'],
            'scope' => 'classification_bridge_before_general_adjustments', 'dppo_supported' => false,
            'rows' => $rows, 'external_adjustments' => []];
    }

    private static function stale(): never
    {
        throw new TaxReturnException('nonprofit_dppo_j6_receipt_invalid',
            'Potvrzené posouzení J6 neodpovídá aktuálním účetním podkladům. Potvrďte posouzení znovu.', 409);
    }
}
