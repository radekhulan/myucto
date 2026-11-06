<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxDraftRepository;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final readonly class DraftJ6DecisionProvider implements J6AssessmentDecisionProvider
{
    public function __construct(
        private NonprofitTaxDraftRepository $drafts,
        private J6DraftContext $context,
    ) {}

    public function context(): J6DecisionContext
    {
        return new J6DecisionContext($this->context->supplierId, $this->context->periodId, J6ReadMode::CURRENT,
            $this->context->sourceInputHash, $this->context->draftId, $this->context->revision, $this->context->actor);
    }

    public function header(): array
    {
        $draft = $this->draft();
        return $draft['header'] + ['draft_id' => $draft['id'], 'draft_revision' => $draft['revision_no'],
            'source_input_hash' => $draft['source_input_hash']];
    }

    public function decisionPages(int $limit = 500): iterable
    {
        yield from $this->recordPages('decision', 'source_id', $limit);
    }

    public function activityPages(int $limit = 500): iterable
    {
        yield from $this->recordPages('activity', 'activity_kind_id', $limit);
    }

    public function fundingPoolPages(int $limit = 500): iterable
    {
        $this->draft();
        $after = null;
        do {
            $page = $this->drafts->fundingPoolPage($this->context->supplierId, $this->context->draftId, $after, $limit);
            foreach ($page as $record) $this->verify('pool', [$record['pool_kind'], $record['anchor']], $record);
            if ($page !== []) $after = ['pool_kind' => $page[array_key_last($page)]['pool_kind'], 'anchor' => $page[array_key_last($page)]['anchor']];
            yield $page;
        } while (count($page) === $limit);
    }

    public function fundingMemberPages(int $limit = 500): iterable
    {
        $this->draft();
        $after = null;
        do {
            $page = $this->drafts->fundingMemberPage($this->context->supplierId, $this->context->draftId, $after, $limit);
            if ($page !== []) $after = $page[array_key_last($page)];
            yield $page;
        } while (count($page) === $limit);
    }

    public function fundingLinkPages(int $limit = 500): iterable
    {
        $this->draft();
        $after = null;
        do {
            $page = $this->drafts->fundingLinkPage($this->context->supplierId, $this->context->draftId, $after, $limit);
            foreach ($page as $record) $this->verify('link', [$record['source_id'], $record['cost_id']], $record);
            if ($page !== []) $after = ['source_id' => $page[array_key_last($page)]['source_id'], 'cost_id' => $page[array_key_last($page)]['cost_id']];
            yield $page;
        } while (count($page) === $limit);
    }

    private function recordPages(string $kind, string $key, int $limit): iterable
    {
        $this->draft();
        $after = null;
        do {
            $page = $this->drafts->page($this->context->supplierId, $this->context->draftId, $kind, $after, $limit);
            foreach ($page as $record) $this->verify($kind, [$record[$key]], $record);
            if ($page !== []) $after = $page[array_key_last($page)][$key];
            yield $page;
        } while (count($page) === $limit);
    }

    private function draft(): array
    {
        $draft = $this->drafts->find($this->context->supplierId, $this->context->draftId);
        if ($draft['period_id'] !== $this->context->periodId || $draft['created_by'] !== $this->context->actor
            || $draft['revision_no'] !== $this->context->revision
            || !hash_equals($draft['source_input_hash'], $this->context->sourceInputHash)) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_changed', 'Koncept posouzení nebo jeho účetní podklad se změnil.', 409);
        }
        return $draft;
    }

    private function verify(string $kind, array $keys, array $record): void
    {
        $expected = NonprofitTaxRules::hash(['domain' => 'J6DRAFT.' . $kind, 'keys' => $keys, 'payload' => $record['payload']]);
        if (!hash_equals($record['record_hash'], $expected)) throw new \UnexpectedValueException('J6 draft record hash mismatch.');
    }
}
