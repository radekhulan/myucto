<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\AccountDefinitionRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\AccountRoleResolver;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class MembershipChargeService
{
    public function __construct(
        private readonly Connection $db,
        private readonly MembershipRepository $members,
        private readonly MembershipChargeRepository $charges,
        private readonly PostingService $posting,
    ) {}

    public function previewIssue(int $supplier, array $input): array
    {
        $request = MembershipChargeRequest::issue($input);
        return $this->issueContext($supplier, $request, false);
    }

    public function confirmIssue(int $supplier, array $input, int $expectedVersion, string $previewHash, string $idempotencyKey, int $actor): array
    {
        $request = MembershipChargeRequest::issue($input);
        $key = MembershipChargeRequest::key($idempotencyKey);
        $this->confirmation($expectedVersion, $previewHash, $actor);
        $commandRequest = ['operation' => 'issue', 'request' => $request, 'expected_case_version' => $expectedVersion, 'preview_hash' => $previewHash, 'actor' => $actor];
        $requestHash = NonprofitTaxRules::hash($commandRequest);
        return TransactionFailure::retrying($this->db->pdo(), fn (): array => (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $request, $expectedVersion, $previewHash, $key, $actor, $commandRequest, $requestHash): array {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);
            if (($replay = $this->replay($supplier, $key, $requestHash)) !== null) return $replay;
            $context = $this->issueContext($supplier, $request, true);
            if (!hash_equals($context['preview_hash'], $previewHash) || $context['expected_case_version'] !== $expectedVersion) {
                throw new MembershipException('nonprofit_membership_preview_stale', 'Podklady členského předpisu se změnily. Načtěte nový náhled.', 409);
            }
            $case = $context['case'];
            if ($case === null) {
                if ($expectedVersion !== 0) throw new MembershipException('nonprofit_membership_case_changed', 'Účetní případ se mezitím změnil.', 409);
                $case = $this->charges->insertCase($supplier, $request['member_id'], $request['title_key'], $request['period_from'], $request['period_to'], $actor);
            }
            $previous = $this->charges->head($supplier, $case, true);
            $command = $this->charges->insertCommand($supplier, $key, $commandRequest, $requestHash, $actor);
            $entry = (new MembershipUseGuard($this->db))->withIssueOwner($supplier, $case['id'], fn (): int => $this->posting->postDocument(
                $supplier,
                'nonprofit_membership',
                $case['id'],
                $context['journal_plan'],
                ['entry_date' => $request['accounting_date'], 'document_date' => $request['accounting_date'],
                    'description' => 'Členský příspěvek ' . $request['title_key'], 'posted' => true, 'posted_by' => $actor, 'user_id' => $actor],
            ));
            $receipt = (new MembershipJournalProof($this->db))->capture($supplier, $entry, $case['id'], $context['journal_plan'], 'issue');
            $operation = $this->charges->insertOperation([
                'supplier_id' => $supplier, 'case_id' => $case['id'], 'command_id' => $command['id'], 'operation' => 'issue',
                'previous_operation_id' => $previous['id'] ?? null, 'original_operation_id' => null,
                'member_revision_id' => $context['proof']['member_revision']['id'], 'legal_profile_id' => $context['proof']['legal_profile']['id'],
                'accounting_period_id' => $context['proof']['accounting_period']['id'], 'accounting_date' => $request['accounting_date'], 'due_date' => $request['due_date'],
                'amount_cents' => $request['amount_cents'], 'receivable_account_id' => $context['proof']['accounts']['receivable']['id'],
                'revenue_account_id' => $context['proof']['accounts']['revenue']['id'], 'journal_entry_id' => $entry,
                'receivable_line_id' => $receipt['lines'][0]['id'], 'revenue_line_id' => $receipt['lines'][1]['id'],
                'preview_hash' => $previewHash, 'proof' => $context['proof'], 'receipt' => $receipt,
                'parent_hash' => $previous['record_hash'] ?? str_repeat('0', 64), 'created_by' => $actor,
            ]);
            $member = $context['member'];
            $this->charges->appendClaim(['supplier_id' => $supplier, 'case_id' => $case['id'], 'operation_id' => $operation['id'],
                'original_claim_id' => null, 'member_id' => $member['id'], 'client_id' => $member['client_id'], 'kind' => 'issue', 'amount_cents' => $request['amount_cents']]);
            $case = $this->charges->advanceHead($supplier, $case['id'], $previous['id'] ?? null, $case['row_version'], $operation['id']);
            $this->assertStoredProof($operation);
            (new MembershipJournalProof($this->db))->assertOperation($operation);
            return ['case' => $case, 'operation' => $operation, 'idempotent_replay' => false];
        }));
    }

    public function previewReverse(int $supplier, array $input): array
    {
        return $this->reverseContext($supplier, MembershipChargeRequest::reversal($input), false);
    }

    public function confirmReverse(int $supplier, array $input, int $expectedVersion, string $previewHash, string $idempotencyKey, int $actor): array
    {
        $request = MembershipChargeRequest::reversal($input);
        $key = MembershipChargeRequest::key($idempotencyKey);
        $this->confirmation($expectedVersion, $previewHash, $actor);
        $commandRequest = ['operation' => 'reverse', 'request' => $request, 'expected_case_version' => $expectedVersion, 'preview_hash' => $previewHash, 'actor' => $actor];
        $requestHash = NonprofitTaxRules::hash($commandRequest);
        return TransactionFailure::retrying($this->db->pdo(), fn (): array => (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $request, $expectedVersion, $previewHash, $key, $actor, $commandRequest, $requestHash): array {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);
            if (($replay = $this->replay($supplier, $key, $requestHash)) !== null) return $replay;
            $context = $this->reverseContext($supplier, $request, true);
            if (!hash_equals($context['preview_hash'], $previewHash) || $context['expected_case_version'] !== $expectedVersion) {
                throw new MembershipException('nonprofit_membership_preview_stale', 'Podklady storna se změnily. Načtěte nový náhled.', 409);
            }
            $case = $context['case'];
            $original = $context['original'];
            $claim = $this->charges->claimForOperation($supplier, $original['id'], true)
                ?? throw new MembershipException('nonprofit_membership_claim_missing', 'Původní předpis nemá průkaznou pohledávku.', 409);
            $command = $this->charges->insertCommand($supplier, $key, $commandRequest, $requestHash, $actor);
            $entry = (new MembershipUseGuard($this->db))->withReversalOwner($supplier, $original['journal_entry_id'], fn (): int => $this->posting->reverse(
                $supplier,
                $original['journal_entry_id'],
                ['entry_date' => $request['accounting_date'], 'description' => $request['reason'], 'posted_by' => $actor, 'user_id' => $actor],
            ));
            $issuePlan = MembershipJournalPlan::issue($original['proof']['accounts']['receivable']['account_code'], $original['proof']['accounts']['revenue']['account_code'], bcdiv((string) $original['amount_cents'], '100', 2), $original['proof']['classification']);
            $receipt = (new MembershipJournalProof($this->db))->capture($supplier, $entry, $case['id'], MembershipJournalPlan::reverse($issuePlan), 'reverse', $original['journal_entry_id']);
            $proof = $original['proof'];
            $proof['original_operation_id'] = $original['id'];
            $proof['original_journal_entry_id'] = $original['journal_entry_id'];
            $proof['reversal_reason'] = $request['reason'];
            $operation = $this->charges->insertOperation([
                'supplier_id' => $supplier, 'case_id' => $case['id'], 'command_id' => $command['id'], 'operation' => 'reverse',
                'previous_operation_id' => $original['id'], 'original_operation_id' => $original['id'],
                'member_revision_id' => $original['member_revision_id'], 'legal_profile_id' => $original['legal_profile_id'],
                'accounting_period_id' => $context['target_period']['id'], 'accounting_date' => $request['accounting_date'], 'due_date' => $original['due_date'],
                'amount_cents' => $original['amount_cents'], 'receivable_account_id' => $original['receivable_account_id'],
                'revenue_account_id' => $original['revenue_account_id'], 'journal_entry_id' => $entry,
                'receivable_line_id' => $receipt['lines'][0]['id'], 'revenue_line_id' => $receipt['lines'][1]['id'],
                'preview_hash' => $previewHash, 'proof' => $proof, 'receipt' => $receipt,
                'parent_hash' => $original['record_hash'], 'created_by' => $actor,
            ]);
            $this->charges->appendClaim(['supplier_id' => $supplier, 'case_id' => $case['id'], 'operation_id' => $operation['id'],
                'original_claim_id' => $claim['id'], 'member_id' => $claim['member_id'], 'client_id' => $claim['client_id'], 'kind' => 'reversal', 'amount_cents' => $claim['amount_cents']]);
            $case = $this->charges->advanceHead($supplier, $case['id'], $original['id'], $case['row_version'], $operation['id']);
            $this->assertStoredProof($operation);
            (new MembershipJournalProof($this->db))->assertOperation($operation);
            return ['case' => $case, 'operation' => $operation, 'idempotent_replay' => false];
        }));
    }

    private function issueContext(int $supplier, array $request, bool $current): array
    {
        $member = $this->members->member($supplier, $request['member_id'], $current)
            ?? throw new MembershipException('nonprofit_membership_member_not_found', 'Člen nebyl nalezen v této organizaci.', 404);
        $history = $this->members->history($supplier, $member['id'], $current);
        $revision = MembershipRevisionTimeline::at($history, $request['accounting_date']);
        if ($revision === null || $revision['status'] !== 'active') throw new MembershipException('nonprofit_membership_inactive', 'Členství není k účetnímu datu aktivní.', 409);
        $covered = [];
        foreach (MembershipRevisionTimeline::intervals($history) as $interval) {
            if ($interval['effective_from'] <= $request['period_to'] && ($interval['effective_to'] === null || $interval['effective_to'] > $request['period_from'])) {
                if ($interval['status'] !== 'active') throw new MembershipException('nonprofit_membership_period_inactive', 'Členství není aktivní po celé období příspěvku.', 409);
                $covered[] = $this->revisionProof($interval) + ['effective_to' => $interval['effective_to']];
            }
        }
        $lastCovered = $covered === [] ? null : $covered[array_key_last($covered)];
        if ($covered === [] || $covered[0]['effective_from'] > $request['period_from'] || $lastCovered['effective_to'] !== null && $lastCovered['effective_to'] <= $request['period_to']) {
            throw new MembershipException('nonprofit_membership_period_inactive', 'Členství není aktivní po celé období příspěvku.', 409);
        }
        $framework = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        $frameworks = $framework->forInterval($supplier, $request['period_from'], $request['period_to'], $current);
        if (array_values(array_unique($frameworks)) !== [AccountingFrameworkService::CZ_504]) throw new MembershipException('nonprofit_membership_framework_required', 'Členský příspěvek vyžaduje rámec 504 po celé období.', 409);
        $period = $this->period($supplier, $request['accounting_date'], $current);
        $legal = (new NonprofitLegalProfileService($this->db))->snapshotProof($supplier, $period['id'], $current);
        if ($legal === null || ($legal['profile']['entity_type'] ?? null) !== 'association' || empty($legal['profile']['main_activity_nonbusiness'])) {
            throw new MembershipException('nonprofit_membership_association_profile_required', 'Předpis vyžaduje potvrzený právní profil spolku pro dané období.', 409);
        }
        $accounts = [
            'receivable' => $this->account($supplier, $request['receivable_account'], 'asset', $request['accounting_date'], $current),
            'revenue' => $this->account($supplier, $request['revenue_account'], 'revenue', $request['accounting_date'], $current),
        ];
        $roles = AccountRoleResolver::fromConnection($this->db)->forDate($supplier, $request['accounting_date']);
        if ($roles->framework !== AccountingFrameworkService::CZ_504) {
            throw new MembershipException('nonprofit_membership_framework_required', 'Členský příspěvek vyžaduje rámec 504 k účetnímu datu.', 409);
        }
        $revenueRole = $roles->account('nonprofit.membership_revenue');
        if (!str_starts_with($accounts['revenue']['account_code'], $revenueRole)) {
            throw new MembershipException('nonprofit_membership_revenue_account_invalid', 'Členský příspěvek se účtuje do výnosu ' . $revenueRole . ' (přijaté členské příspěvky) nebo jeho analytiky.', 409,
                ['required_account' => $revenueRole]);
        }
        $deferral = $this->deferralProposal($request, $period);
        $cases = $this->charges->casesForTitle($supplier, $member['id'], $request['title_key'], $current);
        $case = null;
        foreach ($cases as $candidate) if ($candidate['period_from'] === $request['period_from'] && $candidate['period_to'] === $request['period_to']) $case = $candidate;
        $overlaps = $this->charges->activeOverlaps($supplier, $member['id'], $request['title_key'], $request['period_from'], $request['period_to'], $current);
        if ($overlaps !== []) throw new MembershipException('nonprofit_membership_claim_overlap', 'Člen už má aktivní předpis stejného titulu pro překrývající se období.', 409, ['case_ids' => array_column($overlaps, 'id')]);
        $classification = (new MembershipClassification($this->db))->resolve($supplier, $request['classification'], $current);
        $plan = MembershipJournalPlan::issue($accounts['receivable']['account_code'], $accounts['revenue']['account_code'], $request['amount'], $classification);
        $proof = ['schema' => 'nonprofit_membership_charge.v1', 'member' => $member,
            'member_revision' => $this->revisionProof($revision), 'membership_intervals' => $covered,
            'legal_profile' => $legal, 'accounting_period' => $this->periodProof($period), 'framework' => $frameworks,
            'legal_title' => $request['legal_title'], 'accounts' => $accounts, 'classification' => $classification,
            'tax_treatment' => 'unclassified_requires_explicit_j6_decision'];
        $preview = ['request' => $request, 'case_id' => $case['id'] ?? null, 'expected_case_version' => $case['row_version'] ?? 0,
            'proof' => $proof, 'journal_plan' => $plan, 'deferral' => $deferral];
        return $preview + ['preview_hash' => NonprofitTaxRules::hash($preview), 'case' => $case, 'member' => $member];
    }

    /**
     * Návrh časového rozlišení výnosu: předpis jde celý do 684 k účetnímu datu, část období
     * za koncem účetního období odloží krok uzávěrky „deferrals" na 384 a rozpustí v N+1
     * ({@see ClosingService::deferredRevenueAccrualPreview}). Náhled ukazuje tutéž částku.
     */
    private function deferralProposal(array $request, array $period): ?array
    {
        $endsOn = (string) $period['ends_on'];
        if ($request['period_to'] <= $endsOn) return null;
        $amount = ClosingService::deferredRevenueAmount((float) $request['amount'], $request['period_from'], $request['period_to'], $endsOn);
        if ((int) round($amount * 100) === 0) return null;
        return ['mechanism' => 'closing.deferred_revenue_accrual', 'balance_sheet_date' => $endsOn,
            'deferred_amount' => number_format($amount, 2, '.', '')];
    }

    private function reverseContext(int $supplier, array $request, bool $current): array
    {
        $case = $this->charges->caseById($supplier, $request['case_id'], $current)
            ?? throw new MembershipException('nonprofit_membership_case_not_found', 'Účetní případ nebyl nalezen.', 404);
        $head = $this->charges->head($supplier, $case, $current);
        if ($head === null || $head['operation'] !== 'issue') throw new MembershipException('nonprofit_membership_already_reversed', 'Členský předpis už není aktivní.', 409);
        $this->assertStoredProof($head, $current);
        (new MembershipJournalProof($this->db))->assertOperation($head, $current);
        $framework = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        $frameworkAt = $current
            ? $framework->forDateForUpdate($supplier, $request['accounting_date'])
            : $framework->forDate($supplier, $request['accounting_date']);
        if ($frameworkAt !== AccountingFrameworkService::CZ_504) {
            throw new MembershipException('nonprofit_membership_framework_required', 'Storno členského příspěvku vyžaduje rámec 504.', 409);
        }
        $period = $this->period($supplier, $request['accounting_date'], $current);
        $preview = ['request' => $request, 'case_id' => $case['id'], 'expected_case_version' => $case['row_version'],
            'original_operation_id' => $head['id'], 'original_record_hash' => $head['record_hash'], 'target_period' => $this->periodProof($period)];
        return $preview + ['preview_hash' => NonprofitTaxRules::hash($preview), 'case' => $case, 'original' => $head, 'target_period' => $period];
    }

    private function replay(int $supplier, string $key, string $requestHash): ?array
    {
        $command = $this->charges->command($supplier, $key, true);
        if ($command === null) return null;
        if (!hash_equals($command['request_hash'], $requestHash)) throw new MembershipException('nonprofit_membership_idempotency_conflict', 'Klíč požadavku už patří jiné operaci.', 409);
        $operation = $this->charges->operationForCommand($supplier, $command['id'], true)
            ?? throw new MembershipException('nonprofit_membership_command_incomplete', 'Uložený požadavek nemá průkaznou finanční operaci.', 409);
        $this->assertStoredProof($operation);
        $case = $this->charges->caseById($supplier, $operation['case_id'], true);
        if ($case === null) throw new MembershipException('nonprofit_membership_case_changed', 'Účetní případ uložené operace nebyl nalezen.', 409);
        return ['case' => $case, 'operation' => $operation, 'idempotent_replay' => true];
    }

    private function assertStoredProof(array $operation, bool $current = true): void
    {
        (new MembershipStoredProof($this->db, $this->members))->assertMany((int) $operation['supplier_id'], [$operation], $current);
    }

    private function period(int $supplier, string $date, bool $current): array
    {
        $q = $this->db->pdo()->prepare('SELECT * FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? ORDER BY starts_on,id' . ($current ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $date, $date]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) throw new MembershipException('nonprofit_membership_period_missing', 'Účetní datum nepatří právě do jednoho období.', 409);
        $row = $rows[0];
        foreach (['id', 'supplier_id', 'fiscal_year'] as $field) $row[$field] = (int) $row[$field];
        if ($current && $row['status'] !== 'open') throw new MembershipException('nonprofit_membership_period_locked', 'Členský příspěvek lze účtovat jen do otevřeného období.', 409);
        return $row;
    }

    private function account(int $supplier, string $code, string $type, string $date, bool $current): array
    {
        $q = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code=?' . ($current ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $code]);
        $id = $q->fetchColumn();
        if ($id === false) throw new MembershipException('nonprofit_membership_account_invalid', 'Vybraný účet není v účtové osnově organizace.', 409);
        $definition = (new AccountDefinitionRepository($this->db))->findForDate($supplier, (int) $id, $date, $current);
        if ($definition === null || (int) $definition['is_active'] !== 1 || $definition['account_type'] !== $type) {
            throw new MembershipException('nonprofit_membership_account_invalid', 'Vybraný účet nemá k účetnímu datu požadovaný typ a platnost.', 409);
        }
        return ['id' => (int) $definition['id'], 'account_code' => $definition['account_code'], 'account_type' => $definition['account_type'],
            'name' => $definition['name'], 'normal_side' => $definition['normal_side'], 'is_active' => true,
            'role' => $type === 'asset' ? 'membership_receivable' : 'membership_revenue',
            'definition_hash' => NonprofitTaxRules::hash(array_intersect_key($definition, array_flip(['id', 'account_code', 'account_type', 'name', 'normal_side', 'tax_deductibility', 'is_active'])) )];
    }

    private function revisionProof(array $revision): array
    {
        return array_intersect_key($revision, array_flip(['id', 'revision_no', 'effective_from', 'status', 'legal_basis', 'record_hash', 'request_hash']));
    }

    private function periodProof(array $period): array
    {
        return array_intersect_key($period, array_flip(['id', 'fiscal_year', 'starts_on', 'ends_on', 'status']));
    }

    private function confirmation(int $expectedVersion, string $previewHash, int $actor): void
    {
        if ($expectedVersion < 0 || $actor <= 0 || preg_match('/^[a-f0-9]{64}$/D', $previewHash) !== 1) {
            throw new MembershipException('nonprofit_membership_confirmation_invalid', 'Potvrzení vyžaduje autora, verzi a platný otisk náhledu.');
        }
    }
}
