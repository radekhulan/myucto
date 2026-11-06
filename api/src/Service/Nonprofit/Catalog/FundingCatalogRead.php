<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Catalog;

use MyInvoice\Repository\Nonprofit\Catalog\FundingCatalogReadRepository;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;

final class FundingCatalogRead
{
    public function __construct(private readonly FundingCatalogReadRepository $repository) {}

    public function read(int $supplier, int $after = 0, int $limit = 25, ?int $id = null): array
    {
        $page = $this->repository->page($supplier, $after, $limit, $id);
        if ($id !== null && $page['rows'] === []) {
            throw new NonprofitTaxAssessmentException('nonprofit_catalog_not_found', 'Zdroj nebyl nalezen v dostupné firmě.', 404);
        }
        $items = [];
        foreach ($page['rows'] as $revision) {
            $policy = $revision['policy'];
            $items[] = [
                'id' => $this->firstId($revision),
                'current_revision_id' => $revision['id'],
                'revision_no' => $revision['revision_no'],
                'previous_revision_id' => $revision['previous_id'],
                'period_id' => $revision['period_id'],
                'assessment_id' => $revision['assessment_id'],
                'kind' => $policy['kind'],
                'provider_client_id' => $revision['provider_id'],
                'valid_from' => $policy['valid_from'],
                'valid_to' => $policy['valid_to'],
                'activity_kind_ids' => array_map('intval', $policy['activity_kind_ids']),
                'account_prefixes' => array_values($policy['account_prefixes']),
                'recognized_cents' => $this->cents($revision['basis']['recognized_cents'] ?? null),
                'budget_cents' => $this->cents($policy['budget_cents'] ?? null),
                'used_cents' => null,
                'budget_remaining_cents' => null,
                'available_cents' => null,
                'source_current' => null,
                'proof_status' => 'recorded_chain_verified',
                'usage_count' => null,
            ];
        }
        $meta = [
            'temporal_basis' => 'current_knowledge',
            'consistency' => 'canonical_owner_transaction',
            'provenance' => 'recorded_funding_revision_chain',
            'coverage' => [
                'complete' => false,
                'pending_coverage' => 'not_assessed',
                'revision_history' => 'verified',
                'purpose_decision' => 'unknown',
                'usage_links' => 'not_available',
                'eligibility' => 'not_assessed',
                'findings' => [
                    ['code' => 'purpose_decision_coverage_unknown'],
                    ['code' => 'funding_usage_links_not_available'],
                ],
            ],
            'financial_authorization' => false,
        ];
        if ($id !== null) {
            return ['item' => $items[0]] + $meta;
        }
        return ['items' => $items, 'next_cursor' => $page['next_cursor']] + $meta;
    }

    private function firstId(array $revision): int
    {
        return $revision['revision_no'] === 1 ? $revision['id'] : $this->walkFirstId($revision);
    }

    private function walkFirstId(array $revision): int
    {
        return (int) ($revision['_first_id'] ?? throw new \LogicException('Funding catalog stable id is missing.'));
    }

    private function cents(mixed $value): ?string
    {
        return is_int($value) ? (string) $value : (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1 ? $value : null);
    }
}
