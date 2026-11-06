<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Catalog;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Catalog\FundingCatalogReadRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentReadService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;

final class FundingCatalogEvidenceRead
{
    private const TITLES = [
        'gift' => 'gift',
        'operating_grant' => 'public_operating_grant',
        'investment_grant' => 'public_investment_grant',
    ];
    private const REGIMES = ['subject', 'out_of_scope', 'exempt'];
    private const METHODS = ['return', 'none', 'annual_activity_test', 'final_withholding'];
    private const ASSESSMENT_METHODS = ['ruleset', 'documented_manual'];
    /** Druh podkladu => blok neměnného grafu J6, ze kterého se čte. */
    public const CHUNKS = ['purpose' => 'result', 'usage' => 'usage', 'uses' => 'decision'];

    /**
     * Ověřitelné podklady jednoho účelového zdroje z potvrzeného stránkovaného posouzení J6:
     * právní titul a rozhodnutí o osvobození (purpose), souhrn čerpání (usage) a jednotlivé
     * čerpané náklady (uses). Volné texty, důkazy a interní otisky se nevracejí.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly FundingCatalogReadRepository $funding,
        private readonly J6AssessmentReadService $assessments,
    ) {}

    public function read(int $supplierId, int $fundingId, int $periodId, int $assessmentId, string $kind,
        ?array $cursor = null, int $limit = 25): array
    {
        if ($supplierId <= 0 || $fundingId <= 0 || $periodId <= 0 || $assessmentId <= 0
            || !isset(self::CHUNKS[$kind]) || $limit < 1 || $limit > 100) {
            self::invalidQuery();
        }
        $position = self::cursor($cursor);
        return $this->snapshot(function () use ($supplierId, $fundingId, $periodId, $assessmentId, $kind, $position, $limit): array {
            $funding = $this->funding->page($supplierId, 0, 1, $fundingId);
            if ($funding['rows'] === []) {
                throw new NonprofitTaxAssessmentException('nonprofit_catalog_not_found', 'Zdroj nebyl nalezen v dostupné firmě.', 404);
            }
            $revision = $funding['rows'][0];
            $anchor = $revision['policy']['source_anchor'] ?? null;
            $fundingKind = $revision['policy']['kind'] ?? null;
            if (!is_string($anchor) || preg_match('/^line:[1-9][0-9]*$/D', $anchor) !== 1
                || !is_string($fundingKind) || !isset(self::TITLES[$fundingKind])) {
                self::proofInvalid();
            }
            try {
                $assessment = $this->assessments->summary($supplierId, $periodId, $assessmentId);
                if (($assessment['status'] ?? null) !== 'confirmed') {
                    throw new NonprofitTaxAssessmentException('nonprofit_funding_catalog_assessment_unconfirmed',
                        'Účel a použití lze zveřejnit jen z potvrzeného daňového posouzení.', 409);
                }
                $assessmentMethod = $assessment['summary']['assessment_method'] ?? null;
                if (!is_string($assessmentMethod) || !in_array($assessmentMethod, self::ASSESSMENT_METHODS, true)) {
                    self::proofInvalid();
                }
                $page = $this->assessments->calculationPage($supplierId, $periodId, $assessmentId,
                    self::CHUNKS[$kind], $position['after_ordinal']);
            } catch (NonprofitTaxAssessmentException $failure) {
                throw $failure;
            } catch (\JsonException|\UnexpectedValueException) {
                self::proofInvalid();
            }
            $rows = $page['rows'] ?? null;
            $next = $page['next_cursor'] ?? null;
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > 500
                || ($next !== null && (!is_int($next) || $next < 0))) {
                self::proofInvalid();
            }
            if ($position['offset'] > count($rows)) {
                self::invalidQuery();
            }
            $items = [];
            $nextCursor = null;
            $offset = $position['offset'];
            foreach (array_slice($rows, $position['offset']) as $row) {
                ++$offset;
                if (!is_array($row) || !self::matches($kind, $row, $anchor)) {
                    continue;
                }
                $items[] = match ($kind) {
                    'purpose' => self::purpose($row, $fundingId, $periodId, $assessmentId, $fundingKind, $assessmentMethod),
                    'usage' => self::usage($row, $fundingId, $periodId, $assessmentId),
                    'uses' => self::use($row, $fundingId, $periodId, $assessmentId),
                };
                if ($kind !== 'uses' && count($items) > 1) {
                    self::proofInvalid();
                }
                if ($kind === 'uses' && count($items) === $limit && $offset < count($rows)) {
                    // Stránka čerpání je plná uprostřed bloku: pokračuje se týmž blokem od další pozice.
                    $nextCursor = ['after_ordinal' => $position['after_ordinal'], 'offset' => $offset];
                    break;
                }
            }
            $single = $kind !== 'uses' && $items !== [];
            if ($nextCursor === null && !$single && $next !== null) {
                $nextCursor = ['after_ordinal' => $next, 'offset' => 0];
            }
            $coverage = match (true) {
                $nextCursor === null && $items === [] => 'not_available_in_assessment',
                $nextCursor === null => 'proven',
                $items === [] => 'page_verified_more_available',
                default => 'proven_more_available',
            };
            return [
                'funding_id' => $fundingId,
                'period_id' => $periodId,
                'assessment_id' => $assessmentId,
                'evidence_kind' => $kind,
                'items' => $items,
                'next_cursor' => $nextCursor,
                'temporal_basis' => 'confirmed_assessment',
                'consistency' => 'immutable_j6_graph',
                'proof_status' => 'verified',
                'coverage' => $coverage,
                'financial_authorization' => false,
            ];
        });
    }

    private function snapshot(callable $read): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        } elseif ($pdo->query('SELECT @@tx_isolation')->fetchColumn() !== 'REPEATABLE-READ') {
            throw new NonprofitTaxAssessmentException('nonprofit_catalog_snapshot_required',
                'Čtení vyžaduje konzistentní databázový snapshot.', 409);
        }
        return (new DimensionPeriodGuard($this->db))->transaction($read);
    }

    private static function cursor(?array $cursor): array
    {
        if ($cursor === null) {
            return ['after_ordinal' => -1, 'offset' => 0];
        }
        if (array_keys($cursor) !== ['after_ordinal', 'offset'] || !is_int($cursor['after_ordinal'])
            || $cursor['after_ordinal'] < -1 || !is_int($cursor['offset']) || $cursor['offset'] < 0 || $cursor['offset'] > 499) {
            self::invalidQuery();
        }
        return $cursor;
    }

    private static function matches(string $kind, array $row, string $anchor): bool
    {
        if ($kind === 'purpose') {
            return ($row['id'] ?? null) === $anchor;
        }
        if ($kind === 'uses') {
            return ($row['record_kind'] ?? null) === 'funding_link' && ($row['source_id'] ?? null) === $anchor;
        }
        return ($row['anchor'] ?? null) === $anchor && ($row['member_id'] ?? null) === $anchor;
    }

    private static function purpose(array $row, int $fundingId, int $periodId, int $assessmentId,
        string $fundingKind, string $assessmentMethod): array
    {
        $decision = $row['decision'] ?? null;
        $classification = $row['classification'] ?? null;
        $amount = $row['amount_cents'] ?? null;
        $activityKind = $row['activity_kind_id'] ?? null;
        if (($row['kind'] ?? null) !== 'revenue' || !is_int($amount) || $amount <= 0 || !is_int($activityKind) || $activityKind <= 0
            || !is_array($decision) || ($decision['title'] ?? null) !== self::TITLES[$fundingKind]
            || !is_array($classification) || !in_array($classification['regime'] ?? null, self::REGIMES, true)
            || !in_array($classification['method'] ?? null, self::METHODS, true)) {
            self::proofInvalid();
        }
        $facts = $decision['facts'] ?? [];
        if (!is_array($facts)) {
            self::proofInvalid();
        }
        foreach (['qualifying_purpose', 'claim_exemption', 'public_budget'] as $field) {
            if (array_key_exists($field, $facts) && !is_bool($facts[$field])) {
                self::proofInvalid();
            }
        }
        if (array_key_exists('election_evidence', $facts)
            && (!is_string($facts['election_evidence']) || trim($facts['election_evidence']) === '')) {
            self::proofInvalid();
        }
        $legalBasis = $classification['legal_basis'] ?? null;
        if (!is_string($legalBasis) || trim($legalBasis) === '') {
            self::proofInvalid();
        }
        $standard = $assessmentMethod === 'ruleset';
        if ($standard && preg_match('/^zdp_[a-z0-9_]{1,72}$/D', $legalBasis) !== 1) {
            self::proofInvalid();
        }
        $line = $row['line_id'] ?? null;
        $date = $row['date'] ?? null;
        if (!is_int($line) || 'line:' . $line !== $row['id'] || !is_string($date)
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            self::proofInvalid();
        }
        return [
            'funding_id' => $fundingId,
            'period_id' => $periodId,
            'assessment_id' => $assessmentId,
            'source_line_id' => $line,
            'date' => $date,
            'amount_cents' => (string) $amount,
            'activity_kind_id' => $activityKind,
            'decision' => [
                'title' => $decision['title'],
                'qualifying_purpose' => $facts['qualifying_purpose'] ?? null,
                'claim_exemption' => $facts['claim_exemption'] ?? null,
                'election_evidence_present' => isset($facts['election_evidence']),
                'public_budget' => $facts['public_budget'] ?? null,
            ],
            'classification' => [
                'regime' => $classification['regime'],
                'method' => $classification['method'],
                'legal_basis_status' => $standard ? 'standard_code' : 'documented_not_exposed',
                'legal_basis_code' => $standard ? $legalBasis : null,
            ],
            'assessment_method' => $assessmentMethod,
            'proof_status' => 'confirmed_immutable_j6',
        ];
    }

    private static function usage(array $row, int $fundingId, int $periodId, int $assessmentId): array
    {
        $amounts = [];
        foreach (['net_source_cents', 'prior_drawdown_cents', 'drawdown_cents', 'remaining_cents'] as $field) {
            if (!is_int($row[$field] ?? null)) {
                self::proofInvalid();
            }
            $amounts[$field] = (string) $row[$field];
        }
        return ['funding_id' => $fundingId, 'period_id' => $periodId, 'assessment_id' => $assessmentId]
            + $amounts + ['proof_status' => 'confirmed_immutable_j6'];
    }

    private static function use(array $row, int $fundingId, int $periodId, int $assessmentId): array
    {
        $amount = $row['payload']['amount_cents'] ?? null;
        $cost = $row['cost_id'] ?? null;
        if (!is_int($amount) || $amount <= 0 || !is_string($cost)
            || preg_match('/^line:([1-9][0-9]*)$/D', $cost, $parts) !== 1) {
            self::proofInvalid();
        }
        return ['funding_id' => $fundingId, 'period_id' => $periodId, 'assessment_id' => $assessmentId,
            'cost_line_id' => (int) $parts[1], 'amount_cents' => (string) $amount, 'proof_status' => 'confirmed_immutable_j6'];
    }

    private static function invalidQuery(): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_funding_catalog_query_invalid',
            'Neplatný filtr nebo stránkování potvrzeného účelu a použití.', 422);
    }

    private static function proofInvalid(): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_funding_catalog_proof_invalid',
            'Potvrzený účel nebo použití nemá průkazný neměnný podklad.', 409);
    }
}
