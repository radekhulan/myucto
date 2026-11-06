<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Repository\AccountDefinitionRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Nonprofit\Membership\MembershipClassification;
use MyInvoice\Service\Nonprofit\Membership\MembershipJournalPlan;
use MyInvoice\Service\Nonprofit\Membership\MembershipJournalProof;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoLegalRules;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class MembershipCloneScrubber
{
    private const BATCH = MembershipCloneCapture::BATCH_SIZE;

    public static function capture(Connection $db): MembershipCloneCapture
    {
        self::assertClonePhase($db);
        $pdo = $db->pdo();
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $writer = new MembershipCloneSpoolWriter($database);
        $pdo->beginTransaction();
        try {
            self::assertCompleteGraph($pdo);
            foreach (self::memberBatches($pdo) as $batch) {
                foreach ($batch as $row) $writer->append('member', self::memberRow($row));
            }
            $revisionState = null;
            foreach (self::revisionBatches($pdo) as $batch) {
                foreach ($batch as $raw) {
                    $row = self::revisionRow($raw);
                    self::validateRevision($row, $revisionState);
                    $writer->append('revision', $row);
                    $revisionState = $row;
                }
            }
            $chain = null;
            foreach (self::operationBatches($pdo) as [$supplier, $batch]) {
                $operations = array_map(self::operationRow(...), $batch);
                $context = self::operationContext($db, $supplier, $operations, false);
                self::validateOperations($db, $supplier, $operations, $context, $chain, false);
                foreach ($operations as $operation) {
                    $writer->append('operation', ['operation' => $operation, 'command' => $context['commands'][$operation['command_id']]]);
                }
            }
            self::finishChain($chain);
            $capture = $writer->finish(self::financialHash($pdo));
            $pdo->commit();
            return $capture;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $writer->abort();
            throw $error;
        }
    }

    public static function run(Connection $db, TextScrubber $scrubber, MembershipCloneCapture $capture, array $legalHashes,
        ?FinancialCloneHashMap $hashes = null): void
    {
        self::assertClonePhase($db);
        $capture->assertReadable();
        $pdo = $db->pdo();
        if ($capture->database !== (string) $pdo->query('SELECT DATABASE()')->fetchColumn()) {
            throw new \LogicException('Membership clone capture belongs to another database.');
        }
        foreach ($legalHashes as $old => $new) {
            if (!is_string($old) || !is_string($new) || preg_match('/^[a-f0-9]{64}$/D', $old) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $new) !== 1) {
                throw new \LogicException('Membership clone legal profile hash map is invalid.');
            }
        }
        $pdo->beginTransaction();
        try {
            foreach ($capture->batches('member') as $batch) self::rewriteMembers($pdo, $batch);
            $revisionState = null;
            foreach ($capture->batches('revision') as $batch) self::rewriteRevisions($pdo, $scrubber, $batch, $revisionState, $hashes);
            $oldChain = null;
            $proofChain = null;
            $finalChain = null;
            foreach ($capture->batches('operation') as $batch) {
                foreach (self::groupBySupplier($batch, static fn (array $row): int => (int) $row['operation']['supplier_id']) as $supplier => $records) {
                    self::rewriteOperations($db, $scrubber, (int) $supplier, $records, $legalHashes,
                        $oldChain, $proofChain, $finalChain, $hashes);
                }
            }
            self::finishChain($oldChain);
            self::finishChain($finalChain);
            self::verifyAll($db);
            if (!hash_equals($capture->financialHash, self::financialHash($pdo))) {
                throw new \LogicException('Membership clone changed financial facts or references.');
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        } finally {
            $capture->cleanup();
        }
    }

    private static function rewriteMembers(PDO $pdo, array $captured): void
    {
        foreach (self::groupBySupplier($captured, static fn (array $row): int => (int) $row['supplier_id']) as $supplier => $rows) {
            $current = self::rowsByIds($pdo, 'nonprofit_members', (int) $supplier, array_column($rows, 'id'));
            $updates = [];
            foreach ($rows as $old) {
                $row = isset($current[$old['id']]) ? self::memberRow($current[$old['id']]) : null;
                if ($row === null || !self::same($old, $row)) throw new \LogicException('Membership clone member changed after capture.');
                $updates[] = ['id' => $old['id'], 'member_number' => 'clone-member-' . $old['id']];
            }
            self::bulkUpdate($pdo, 'nonprofit_members', (int) $supplier, $updates, ['member_number']);
        }
    }

    private static function rewriteRevisions(PDO $pdo, TextScrubber $scrubber, array $captured, ?array &$state,
        ?FinancialCloneHashMap $hashes): void
    {
        foreach (self::groupBySupplier($captured, static fn (array $row): int => (int) $row['supplier_id']) as $supplier => $rows) {
            $current = self::rowsByIds($pdo, 'nonprofit_member_revisions', (int) $supplier, array_column($rows, 'id'));
            $updates = [];
            foreach ($rows as $old) {
                $row = isset($current[$old['id']]) ? self::revisionRow($current[$old['id']]) : null;
                if ($row === null || !self::same($old, $row)) throw new \LogicException('Membership clone revision changed after capture.');
                if ($state === null || $state['supplier_id'] !== $row['supplier_id'] || $state['member_id'] !== $row['member_id']) $state = null;
                $row['legal_basis'] = self::legalBasis($scrubber, $row['legal_basis']);
                $row['request']['legal_basis'] = $row['legal_basis'];
                $row['idempotency_key'] = 'clone-membership-revision-' . $row['id'];
                $row['request_hash'] = NonprofitTaxRules::hash($row['request']);
                $row['parent_hash'] = $state['record_hash'] ?? str_repeat('0', 64);
                $row['record_hash'] = NonprofitTaxRules::hash(MembershipRepository::revisionRecord($row));
                $hashes?->recordOwned(FinancialCloneHashKind::MEMBERSHIP_REVISION, (int) $supplier,
                    $row['id'], $old['record_hash'], $row['record_hash']);
                $updates[] = ['id' => $row['id'], 'legal_basis_json' => NonprofitTaxRules::canonicalJson($row['legal_basis']),
                    'request_json' => NonprofitTaxRules::canonicalJson($row['request']), 'idempotency_key' => $row['idempotency_key'],
                    'request_hash' => $row['request_hash'], 'parent_hash' => $row['parent_hash'], 'record_hash' => $row['record_hash']];
                $state = $row;
            }
            self::bulkUpdate($pdo, 'nonprofit_member_revisions', (int) $supplier, $updates,
                ['legal_basis_json', 'request_json', 'idempotency_key', 'request_hash', 'parent_hash', 'record_hash']);
        }
    }

    private static function rewriteOperations(Connection $db, TextScrubber $scrubber, int $supplier, array $records,
        array $legalHashes, ?array &$oldChain, ?array &$proofChain, ?array &$finalChain,
        ?FinancialCloneHashMap $hashes): void
    {
        $pdo = $db->pdo();
        $capturedById = [];
        foreach ($records as $record) {
            if (!is_array($record['operation'] ?? null) || !is_array($record['command'] ?? null)) throw new \LogicException('Membership clone operation spool record is invalid.');
            $capturedById[(int) $record['operation']['id']] = $record;
        }
        $ids = array_keys($capturedById);
        $raw = self::rowsByIds($pdo, 'nonprofit_membership_charge_operations', $supplier, $ids);
        $operations = [];
        foreach ($ids as $id) {
            $operation = isset($raw[$id]) ? self::operationRow($raw[$id]) : null;
            if ($operation === null || !self::same($capturedById[$id]['operation'], $operation)) throw new \LogicException('Membership clone operation changed after capture.');
            $operations[] = $operation;
        }
        $context = self::operationContext($db, $supplier, $operations, true);
        $classificationProofs = [];
        foreach ($operations as $operation) $classificationProofs[$operation['id']] = $operation['proof']['classification'] ?? [];
        $rebasedClassifications = (new MembershipClassification($db))->rebaseMany($supplier, $classificationProofs, true);
        foreach ($operations as $operation) {
            if (!self::same($capturedById[$operation['id']]['command'], $context['commands'][$operation['command_id']])) {
                throw new \LogicException('Membership clone command changed after capture.');
            }
            self::advanceChain($oldChain, $operation, $context['cases'][$operation['case_id']], $context['claims'][$operation['id']]);
        }

        $preliminary = [];
        foreach ($operations as $old) {
            $case = $context['cases'][$old['case_id']];
            if ($proofChain === null || $proofChain['key'] !== $supplier . ':' . $old['case_id']) $proofChain = ['key' => $supplier . ':' . $old['case_id'], 'operation' => null];
            $proof = $old['operation'] === 'issue'
                ? self::issueProof($scrubber, $old, $context, $legalHashes, $rebasedClassifications[$old['id']])
                : self::reversalProof($scrubber, $old, $proofChain['operation']);
            $command = $context['commands'][$old['command_id']];
            $request = self::commandRequest($scrubber, $command['request'], $proof);
            $plan = MembershipJournalPlan::issue($proof['accounts']['receivable']['account_code'], $proof['accounts']['revenue']['account_code'], bcdiv((string) $old['amount_cents'], '100', 2), $proof['classification']);
            if ($old['operation'] === 'reverse') $plan = MembershipJournalPlan::reverse($plan);
            $preliminary[] = compact('old', 'case', 'proof', 'request', 'plan', 'command');
            $proofChain['operation'] = array_replace($old, ['proof' => $proof]);
        }
        $journalRequests = [];
        foreach ($preliminary as $row) $journalRequests[] = ['key' => $row['old']['id'], 'entry_id' => $row['old']['journal_entry_id'],
            'case_id' => $row['old']['case_id'], 'plan' => $row['plan'], 'operation' => $row['old']['operation'],
            'original_entry_id' => $row['old']['operation'] === 'reverse' ? (int) $row['proof']['original_journal_entry_id'] : null];
        $receipts = (new MembershipJournalProof($db))->captureMany($supplier, $journalRequests, true);

        $commandUpdates = [];
        $operationUpdates = [];
        $repository = new MembershipChargeRepository($db);
        foreach ($preliminary as $row) {
            $old = $row['old'];
            if ($finalChain === null || $finalChain['key'] !== $supplier . ':' . $old['case_id']) {
                self::finishChain($finalChain);
                $finalChain = ['key' => $supplier . ':' . $old['case_id'], 'head' => $row['case']['head_operation_id'], 'operation' => null, 'claim' => null];
            }
            $previous = $finalChain['operation'];
            $preview = self::preview($old, $row['request'], $row['proof'], $row['plan'], $previous, $row['case'], $context['periods'][$old['accounting_period_id']]);
            $previewHash = NonprofitTaxRules::hash($preview);
            $request = $row['request'];
            $request['preview_hash'] = $previewHash;
            $parentHash = $previous['record_hash'] ?? str_repeat('0', 64);
            $operation = $old;
            $operation['proof'] = $row['proof'];
            $operation['receipt'] = $receipts[$old['id']];
            $operation['preview_hash'] = $previewHash;
            $operation['parent_hash'] = $parentHash;
            $operation['record_hash'] = NonprofitTaxRules::hash($repository->operationRecord($operation));
            if ($hashes !== null) {
                $claim = $context['claims'][$old['id']];
                $hashes->recordOwned(FinancialCloneHashKind::MEMBERSHIP_CLAIM, $supplier,
                    $claim['id'], $claim['record_hash'], $claim['record_hash']);
                $hashes->recordOwned(FinancialCloneHashKind::MEMBERSHIP_LEGAL_PROFILE, $supplier,
                    $old['legal_profile_id'], $old['proof']['legal_profile']['record_hash'],
                    $operation['proof']['legal_profile']['record_hash']);
                $hashes->recordOwned(FinancialCloneHashKind::MEMBERSHIP_OPERATION, $supplier,
                    $old['id'], $old['record_hash'], $operation['record_hash']);
                $hashes->recordOwned(FinancialCloneHashKind::MEMBERSHIP_JOURNAL_RECEIPT, $supplier,
                    $old['id'], NonprofitTaxRules::hash($old['receipt']), NonprofitTaxRules::hash($operation['receipt']));
                $hashes->recordOwned(FinancialCloneHashKind::MEMBERSHIP_CLASSIFICATION, $supplier,
                    $old['id'], NonprofitTaxRules::hash($old['proof']['classification']),
                    NonprofitTaxRules::hash($operation['proof']['classification']));
            }
            $commandUpdates[] = ['id' => $row['command']['id'], 'idempotency_key' => 'clone-membership-command-' . $row['command']['id'],
                'request_json' => NonprofitTaxRules::canonicalJson($request), 'request_hash' => NonprofitTaxRules::hash($request)];
            $operationUpdates[] = ['id' => $operation['id'], 'preview_hash' => $previewHash,
                'proof_json' => NonprofitTaxRules::canonicalJson($operation['proof']), 'receipt_json' => NonprofitTaxRules::canonicalJson($operation['receipt']),
                'parent_hash' => $parentHash, 'record_hash' => $operation['record_hash']];
            $finalChain['operation'] = $operation;
            $finalChain['claim'] = $context['claims'][$operation['id']];
        }
        self::bulkUpdate($pdo, 'nonprofit_membership_charge_commands', $supplier, $commandUpdates, ['idempotency_key', 'request_json', 'request_hash']);
        self::bulkUpdate($pdo, 'nonprofit_membership_charge_operations', $supplier, $operationUpdates, ['preview_hash', 'proof_json', 'receipt_json', 'parent_hash', 'record_hash']);
    }

    private static function verifyAll(Connection $db): void
    {
        $pdo = $db->pdo();
        self::assertCompleteGraph($pdo);
        $revisionState = null;
        foreach (self::revisionBatches($pdo) as $batch) {
            foreach ($batch as $raw) {
                $row = self::revisionRow($raw);
                self::validateRevision($row, $revisionState);
                $revisionState = $row;
            }
        }
        $chain = null;
        foreach (self::operationBatches($pdo) as [$supplier, $batch]) {
            $operations = array_map(self::operationRow(...), $batch);
            $context = self::operationContext($db, $supplier, $operations, true);
            self::validateOperations($db, $supplier, $operations, $context, $chain, true);
        }
        self::finishChain($chain);
    }

    private static function validateOperations(Connection $db, int $supplier, array $operations, array $context, ?array &$chain, bool $rebased): void
    {
        $classificationProofs = [];
        foreach ($operations as $operation) $classificationProofs[$operation['id']] = $operation['proof']['classification'] ?? [];
        (new MembershipClassification($db))->assertStoredMany($supplier, $classificationProofs, $rebased);
        foreach ($operations as $operation) {
            $case = $context['cases'][$operation['case_id']] ?? throw new \LogicException('Membership clone case reference is invalid.');
            $member = $context['members'][$case['member_id']] ?? throw new \LogicException('Membership clone member reference is invalid.');
            if ($case['member_id'] !== (int) ($operation['proof']['member']['id'] ?? 0) || !self::same($member, $operation['proof']['member'])) {
                throw new \LogicException('Membership clone member proof reference is invalid.');
            }
            $revision = $context['revisions'][$operation['member_revision_id']] ?? throw new \LogicException('Membership clone proof revision disappeared.');
            if (!self::same(self::revisionProof($revision), $operation['proof']['member_revision'])) throw new \LogicException('Membership clone historical title proof is invalid.');
            foreach ($operation['proof']['membership_intervals'] ?? [] as $interval) {
                $current = $context['revisions'][(int) ($interval['id'] ?? 0)] ?? throw new \LogicException('Membership clone proof interval disappeared.');
                $withoutEnd = $interval;
                unset($withoutEnd['effective_to']);
                if (($interval['status'] ?? null) !== 'active' || !self::same(self::revisionProof($current), $withoutEnd)) {
                    throw new \LogicException('Membership clone interval proof is invalid.');
                }
            }
            if (($operation['proof']['legal_title']['organisation_form'] ?? null) !== 'association'
                || ($operation['proof']['legal_title']['relationship'] ?? null) !== 'nonreciprocal_membership'
                || ($operation['proof']['tax_treatment'] ?? null) !== 'unclassified_requires_explicit_j6_decision') {
                throw new \LogicException('Membership clone legal title proof is invalid.');
            }
            $legal = $context['legal'][$operation['legal_profile_id']] ?? throw new \LogicException('Membership clone legal profile reference is invalid.');
            if (!self::same($legal, $operation['proof']['legal_profile'])) throw new \LogicException('Membership clone legal profile reference is invalid.');
            if (!self::same($context['periods'][$operation['accounting_period_id']], $operation['proof']['accounting_period'])) {
                throw new \LogicException('Membership clone accounting period proof is invalid.');
            }
            foreach (['receivable', 'revenue'] as $role) {
                if (!self::same($context['accounts'][$operation['id']][$role], $operation['proof']['accounts'][$role])) {
                    throw new \LogicException('Membership clone account proof is invalid.');
                }
            }
            if ((int) $operation['receipt']['entry']['id'] !== $operation['journal_entry_id']
                || (int) $operation['receipt']['lines'][0]['id'] !== $operation['receivable_line_id']
                || (int) $operation['receipt']['lines'][1]['id'] !== $operation['revenue_line_id']) {
                throw new \LogicException('Membership clone typed financial proof reference is invalid.');
            }
            $command = $context['commands'][$operation['command_id']] ?? throw new \LogicException('Membership clone command reference is invalid.');
            if (!hash_equals($command['request_hash'], NonprofitTaxRules::hash($command['request']))
                || !hash_equals($operation['preview_hash'], (string) ($command['request']['preview_hash'] ?? ''))
                || ($command['request']['operation'] ?? null) !== $operation['operation']
                || (int) ($command['request']['actor'] ?? 0) !== $operation['created_by']
                || $command['created_by'] !== $operation['created_by']) {
                throw new \LogicException('Membership clone command hash or operation reference is invalid.');
            }
            if ($operation['operation'] === 'issue' && ((int) ($command['request']['request']['member_id'] ?? 0) !== $case['member_id']
                || ($command['request']['request']['title_key'] ?? null) !== $case['title_key']
                || ($command['request']['request']['period_from'] ?? null) !== $case['period_from']
                || ($command['request']['request']['period_to'] ?? null) !== $case['period_to']
                || (int) ($command['request']['request']['amount_cents'] ?? 0) !== $operation['amount_cents']
                || ($command['request']['request']['accounting_date'] ?? null) !== $operation['accounting_date']
                || ($command['request']['request']['due_date'] ?? null) !== $operation['due_date'])) {
                throw new \LogicException('Membership clone issue command reference is invalid.');
            }
            if ($operation['operation'] === 'reverse' && (int) ($command['request']['request']['case_id'] ?? 0) !== $case['id']) {
                throw new \LogicException('Membership clone reversal command reference is invalid.');
            }
            $claim = $context['claims'][$operation['id']] ?? throw new \LogicException('Membership clone claim reference is invalid.');
            self::advanceChain($chain, $operation, $case, $claim);
            if ($claim['case_id'] !== $operation['case_id'] || $claim['member_id'] !== $case['member_id']
                || $claim['client_id'] !== $member['client_id'] || $claim['amount_cents'] !== $operation['amount_cents']
                || (($operation['operation'] === 'issue') !== ($claim['kind'] === 'issue'))) {
                throw new \LogicException('Membership clone typed claim is invalid.');
            }
            if ($operation['operation'] === 'reverse' && $claim['original_claim_id'] !== ($chain['previous_claim_id'] ?? null)) {
                throw new \LogicException('Membership clone reversal claim reference is invalid.');
            }
            if ($operation['operation'] === 'issue' && $claim['original_claim_id'] !== null) throw new \LogicException('Membership clone issue claim reference is invalid.');
            if ($rebased) {
                $plan = MembershipJournalPlan::issue($operation['proof']['accounts']['receivable']['account_code'], $operation['proof']['accounts']['revenue']['account_code'], bcdiv((string) $operation['amount_cents'], '100', 2), $operation['proof']['classification']);
                if ($operation['operation'] === 'reverse') $plan = MembershipJournalPlan::reverse($plan);
                $previous = $chain['previous_operation'] ?? null;
                if (!hash_equals($operation['preview_hash'], NonprofitTaxRules::hash(self::preview($operation, $command['request'], $operation['proof'], $plan, $previous, $case, $context['periods'][$operation['accounting_period_id']])))) {
                    throw new \LogicException('Membership clone preview hash is invalid.');
                }
            }
        }
        (new MembershipJournalProof($db))->assertOperations($supplier, $operations, $rebased);
    }

    private static function operationContext(Connection $db, int $supplier, array $operations, bool $current): array
    {
        if ($operations === [] || count($operations) > self::BATCH) throw new \LogicException('Membership clone operation batch is invalid.');
        $pdo = $db->pdo();
        $commands = self::commandRows(self::rowsByIds($pdo, 'nonprofit_membership_charge_commands', $supplier, array_column($operations, 'command_id'), $current));
        $cases = self::caseRows(self::rowsByIds($pdo, 'nonprofit_membership_charge_cases', $supplier, array_column($operations, 'case_id'), $current));
        $memberIds = array_values(array_unique(array_column($cases, 'member_id')));
        $members = [];
        foreach (self::rowsByIds($pdo, 'nonprofit_members', $supplier, $memberIds, $current) as $row) {
            $member = self::memberRow($row);
            $members[$member['id']] = $member;
        }
        $claimRows = self::rowsByColumn($pdo, 'nonprofit_membership_charge_claims', 'operation_id', $supplier, array_column($operations, 'id'), false, $current);
        $claims = [];
        foreach ($claimRows as $raw) {
            $claim = self::claimRow($raw);
            $claims[$claim['operation_id']] = $claim;
        }
        $revisionIds = [];
        foreach ($operations as $operation) {
            $revisionIds[$operation['member_revision_id']] = $operation['member_revision_id'];
            foreach ($operation['proof']['membership_intervals'] ?? [] as $interval) {
                $id = (int) ($interval['id'] ?? 0);
                if ($id > 0) $revisionIds[$id] = $id;
            }
        }
        $revisions = [];
        foreach (array_chunk(array_values($revisionIds), self::BATCH) as $ids) {
            foreach (self::rowsByIds($pdo, 'nonprofit_member_revisions', $supplier, $ids, $current) as $raw) {
                $row = self::revisionRow($raw);
                $revisions[$row['id']] = $row;
            }
        }
        $legal = self::legalRows($pdo, $supplier, array_column($operations, 'legal_profile_id'), $current);
        $periods = self::periodRows(self::rowsByIds($pdo, 'accounting_periods', $supplier, array_column($operations, 'accounting_period_id'), $current));
        return compact('commands', 'cases', 'members', 'claims', 'revisions', 'legal', 'periods') + ['accounts' => self::accountProofs($pdo, $supplier, $operations)];
    }

    private static function issueProof(TextScrubber $scrubber, array $operation, array $context, array $legalHashes, array $classification): array
    {
        $old = $operation['proof'];
        $case = $context['cases'][$operation['case_id']];
        $member = $context['members'][$case['member_id']] ?? throw new \LogicException('Membership clone proof member disappeared.');
        $revision = $context['revisions'][$operation['member_revision_id']] ?? throw new \LogicException('Membership clone proof revision disappeared.');
        $intervals = [];
        foreach ($old['membership_intervals'] as $oldInterval) {
            $current = $context['revisions'][(int) $oldInterval['id']] ?? throw new \LogicException('Membership clone proof interval disappeared.');
            $intervals[] = self::revisionProof($current) + ['effective_to' => $oldInterval['effective_to']];
        }
        $legal = $context['legal'][$operation['legal_profile_id']] ?? throw new \LogicException('Membership clone legal profile replacement is missing.');
        $expected = $legalHashes[$old['legal_profile']['record_hash']] ?? $old['legal_profile']['record_hash'];
        if (!hash_equals($expected, $legal['record_hash'])) throw new \LogicException('Membership clone legal profile replacement is missing.');
        return ['schema' => 'nonprofit_membership_charge.v1', 'member' => $member, 'member_revision' => self::revisionProof($revision),
            'membership_intervals' => $intervals, 'legal_profile' => $legal,
            'accounting_period' => $context['periods'][$operation['accounting_period_id']], 'framework' => $old['framework'],
            'legal_title' => self::legalBasis($scrubber, $old['legal_title']),
            'accounts' => $context['accounts'][$operation['id']], 'classification' => $classification, 'tax_treatment' => $old['tax_treatment']];
    }

    private static function reversalProof(TextScrubber $scrubber, array $operation, ?array $original): array
    {
        if ($original === null || $operation['original_operation_id'] !== $original['id']) throw new \LogicException('Membership clone reversal original is missing.');
        $proof = $original['proof'];
        $proof['original_operation_id'] = $original['id'];
        $proof['original_journal_entry_id'] = $original['journal_entry_id'];
        $proof['reversal_reason'] = $scrubber->scrub((string) $operation['proof']['reversal_reason']);
        return $proof;
    }

    private static function preview(array $operation, array $command, array $proof, array $plan, ?array $previous, array $case, array $period): array
    {
        if ($operation['operation'] === 'issue') {
            return ['request' => $command['request'], 'case_id' => $operation['previous_operation_id'] === null ? null : $case['id'],
                'expected_case_version' => $command['expected_case_version'], 'proof' => $proof, 'journal_plan' => $plan];
        }
        if ($previous === null || $operation['original_operation_id'] !== $previous['id']) throw new \LogicException('Membership clone reversal preview original is missing.');
        return ['request' => $command['request'], 'case_id' => $case['id'], 'expected_case_version' => $command['expected_case_version'],
            'original_operation_id' => $previous['id'], 'original_record_hash' => $previous['record_hash'], 'target_period' => $period];
    }

    private static function commandRequest(TextScrubber $scrubber, array $request, array $proof): array
    {
        $request['request']['reason'] = $scrubber->scrub((string) $request['request']['reason']);
        if ($request['operation'] === 'issue') {
            $request['request']['legal_title'] = self::legalBasis($scrubber, $request['request']['legal_title']);
            $request['request']['classification'] = [
                'activity_value_id' => $proof['classification']['activity']['value_id'],
                'activity_kind_value_id' => $proof['classification']['activity_kind']['value_id'],
                'tax_regime_value_id' => $proof['classification']['tax_regime']['value_id'],
            ];
        }
        return $request;
    }

    private static function advanceChain(?array &$state, array $operation, array $case, array $claim): void
    {
        $key = $operation['supplier_id'] . ':' . $operation['case_id'];
        if ($state === null || $state['key'] !== $key) {
            self::finishChain($state);
            if ($operation['previous_operation_id'] !== null || !hash_equals($operation['parent_hash'], str_repeat('0', 64))) {
                throw new \LogicException('Membership clone operation chain does not start at its root.');
            }
            $state = ['key' => $key, 'head' => $case['head_operation_id'], 'operation' => null, 'claim' => null,
                'previous_operation' => null, 'previous_claim_id' => null];
        } else {
            $previous = $state['operation'];
            if ($previous === null || $operation['previous_operation_id'] !== $previous['id'] || !hash_equals($operation['parent_hash'], $previous['record_hash'])) {
                throw new \LogicException('Membership clone operation chain is invalid.');
            }
            if ($operation['operation'] === 'reverse' && $operation['original_operation_id'] !== $previous['id']) {
                throw new \LogicException('Membership clone reversal reference is invalid.');
            }
            $state['previous_operation'] = $previous;
            $state['previous_claim_id'] = $state['claim']['id'] ?? null;
        }
        $state['operation'] = $operation;
        $state['claim'] = $claim;
    }

    private static function finishChain(?array &$state): void
    {
        if ($state !== null && ($state['operation']['id'] ?? null) !== $state['head']) throw new \LogicException('Membership clone case head is invalid.');
        $state = null;
    }

    private static function validateRevision(array $row, ?array $previous): void
    {
        if ($previous === null || $previous['supplier_id'] !== $row['supplier_id'] || $previous['member_id'] !== $row['member_id']) $previous = null;
        if ($row['revision_no'] !== ($previous['revision_no'] ?? 0) + 1 || $row['previous_id'] !== ($previous['id'] ?? null)
            || ($previous !== null && $row['effective_from'] <= $previous['effective_from'])
            || !hash_equals($row['parent_hash'], $previous['record_hash'] ?? str_repeat('0', 64))
            || !hash_equals($row['request_hash'], NonprofitTaxRules::hash($row['request']))
            || !hash_equals($row['record_hash'], NonprofitTaxRules::hash(MembershipRepository::revisionRecord($row)))) {
            throw new \LogicException('Membership clone revision history is invalid.');
        }
    }

    private static function assertCompleteGraph(PDO $pdo): void
    {
        foreach (self::idBatches($pdo, 'nonprofit_membership_charge_commands') as [$supplier, $rows]) {
            $ids = array_map('intval', array_column($rows, 'id'));
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $q = $pdo->prepare("SELECT command_id FROM nonprofit_membership_charge_operations WHERE supplier_id=? AND command_id IN ({$marks}) ORDER BY command_id");
            $q->execute([$supplier, ...$ids]);
            $referenced = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            sort($ids, SORT_NUMERIC);
            if ($ids !== $referenced) throw new \LogicException('Membership clone cannot discard a pending charge command.');
        }
        foreach (self::idBatches($pdo, 'nonprofit_membership_charge_cases') as [$supplier, $rows]) {
            $ids = array_map('intval', array_column($rows, 'id'));
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $q = $pdo->prepare("SELECT c.id FROM nonprofit_membership_charge_cases c JOIN nonprofit_membership_charge_operations o ON o.supplier_id=c.supplier_id AND o.case_id=c.id AND o.id=c.head_operation_id WHERE c.supplier_id=? AND c.id IN ({$marks}) ORDER BY c.id");
            $q->execute([$supplier, ...$ids]);
            $referenced = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            sort($ids, SORT_NUMERIC);
            if ($ids !== $referenced) throw new \LogicException('Membership clone charge case has no valid head.');
        }
    }

    private static function idBatches(PDO $pdo, string $table): \Generator
    {
        foreach (self::suppliers($pdo, $table) as $supplier) {
            $after = 0;
            do {
                $q = $pdo->prepare("SELECT id FROM {$table} WHERE supplier_id=? AND id>? ORDER BY id LIMIT " . self::BATCH);
                $q->execute([$supplier, $after]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                if ($rows !== []) {
                    $after = (int) $rows[array_key_last($rows)]['id'];
                    yield [$supplier, $rows];
                }
            } while (count($rows) === self::BATCH);
        }
    }

    private static function memberBatches(PDO $pdo): \Generator
    {
        foreach (self::suppliers($pdo, 'nonprofit_members') as $supplier) {
            $after = 0;
            do {
                $q = $pdo->prepare('SELECT * FROM nonprofit_members WHERE supplier_id=? AND id>? ORDER BY id LIMIT ' . self::BATCH);
                $q->execute([$supplier, $after]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                if ($rows !== []) {
                    $after = (int) $rows[array_key_last($rows)]['id'];
                    yield $rows;
                }
            } while (count($rows) === self::BATCH);
        }
    }

    private static function revisionBatches(PDO $pdo): \Generator
    {
        foreach (self::suppliers($pdo, 'nonprofit_member_revisions') as $supplier) {
            $member = $revision = $id = 0;
            do {
                $q = $pdo->prepare('SELECT * FROM nonprofit_member_revisions WHERE supplier_id=? AND (member_id,revision_no,id)>(?,?,?) ORDER BY member_id,revision_no,id LIMIT ' . self::BATCH);
                $q->execute([$supplier, $member, $revision, $id]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                if ($rows !== []) {
                    $last = $rows[array_key_last($rows)];
                    [$member, $revision, $id] = [(int) $last['member_id'], (int) $last['revision_no'], (int) $last['id']];
                    yield $rows;
                }
            } while (count($rows) === self::BATCH);
        }
    }

    private static function operationBatches(PDO $pdo): \Generator
    {
        foreach (self::suppliers($pdo, 'nonprofit_membership_charge_operations') as $supplier) {
            $case = $id = 0;
            do {
                $q = $pdo->prepare('SELECT * FROM nonprofit_membership_charge_operations WHERE supplier_id=? AND (case_id,id)>(?,?) ORDER BY case_id,id LIMIT ' . self::BATCH);
                $q->execute([$supplier, $case, $id]);
                $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                if ($rows !== []) {
                    $last = $rows[array_key_last($rows)];
                    [$case, $id] = [(int) $last['case_id'], (int) $last['id']];
                    yield [$supplier, $rows];
                }
            } while (count($rows) === self::BATCH);
        }
    }

    private static function suppliers(PDO $pdo, string $table): \Generator
    {
        $after = 0;
        while (true) {
            $q = $pdo->prepare("SELECT supplier_id FROM {$table} WHERE supplier_id>? ORDER BY supplier_id LIMIT 1");
            $q->execute([$after]);
            $supplier = $q->fetchColumn();
            if ($supplier === false) return;
            $after = (int) $supplier;
            yield $after;
        }
    }

    private static function rowsByIds(PDO $pdo, string $table, int $supplier, array $ids, bool $current = true): array
    {
        return self::rowsByColumn($pdo, $table, 'id', $supplier, $ids, true, $current);
    }

    private static function rowsByColumn(PDO $pdo, string $table, string $column, int $supplier, array $ids, bool $keyById = false, bool $current = true): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) return [];
        if (count($ids) > self::BATCH) throw new \LogicException('Membership clone reference batch exceeds 500 rows.');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT * FROM {$table} WHERE supplier_id=? AND {$column} IN ({$marks}) ORDER BY {$column},id" . ($current ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, ...$ids]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$keyById) return $rows;
        $out = [];
        foreach ($rows as $row) $out[(int) $row['id']] = $row;
        return $out;
    }

    private static function commandRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach (['id', 'supplier_id', 'created_by'] as $field) $row[$field] = (int) $row[$field];
            $row['request'] = json_decode($row['request_json'], true, flags: JSON_THROW_ON_ERROR);
            unset($row['request_json']);
            $out[$row['id']] = $row;
        }
        return $out;
    }

    private static function caseRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach (['id', 'supplier_id', 'member_id', 'row_version', 'created_by'] as $field) $row[$field] = (int) $row[$field];
            $row['head_operation_id'] = $row['head_operation_id'] === null ? null : (int) $row['head_operation_id'];
            $out[$row['id']] = $row;
        }
        return $out;
    }

    private static function periodRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $proof = ['id' => (int) $row['id'], 'fiscal_year' => (int) $row['fiscal_year'], 'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'], 'status' => $row['status']];
            $out[$proof['id']] = $proof;
        }
        return $out;
    }

    private static function legalRows(PDO $pdo, int $supplier, array $ids, bool $current): array
    {
        $raw = self::rowsByIds($pdo, 'nonprofit_legal_profiles', $supplier, $ids, $current);
        $previousIds = [];
        foreach ($raw as $row) if ($row['previous_id'] !== null) $previousIds[] = (int) $row['previous_id'];
        $previous = self::rowsByIds($pdo, 'nonprofit_legal_profiles', $supplier, $previousIds, $current);
        $out = [];
        foreach ($raw as $row) {
            foreach (['id', 'supplier_id', 'period_id', 'revision_no', 'created_by'] as $field) $row[$field] = (int) $row[$field];
            $row['previous_id'] = $row['previous_id'] === null ? null : (int) $row['previous_id'];
            foreach (['profile', 'module_proof'] as $field) $row[$field] = json_decode($row[$field . '_json'], true, flags: JSON_THROW_ON_ERROR);
            $normalized = NonprofitDppoLegalRules::profile($row['profile'], $row['module_proof'] !== []);
            if (!self::same($normalized, $row['profile'])) throw new \LogicException('Membership clone legal profile is invalid.');
            $request = ['period_id' => $row['period_id'], 'expected_revision' => $row['revision_no'] - 1, 'profile' => $row['profile'], 'reason' => $row['reason'], 'actor' => $row['created_by']];
            $record = ['supplier_id' => $row['supplier_id'], 'period_id' => $row['period_id'], 'revision_no' => $row['revision_no'],
                'previous_id' => $row['previous_id'], 'profile' => $row['profile'], 'module_proof' => $row['module_proof'], 'reason' => $row['reason'],
                'idempotency_key' => $row['idempotency_key'], 'request_hash' => $row['request_hash'], 'created_by' => $row['created_by'],
                'parent_hash' => $row['previous_id'] === null ? null : ($previous[$row['previous_id']]['record_hash'] ?? null)];
            if (!hash_equals($row['request_hash'], NonprofitTaxRules::hash($request)) || !hash_equals($row['record_hash'], NonprofitTaxRules::hash($record))) {
                throw new \LogicException('Membership clone legal profile hash is invalid.');
            }
            $out[$row['id']] = ['id' => $row['id'], 'revision_no' => $row['revision_no'], 'profile' => $row['profile'],
                'record_hash' => $row['record_hash'], 'request_hash' => $row['request_hash'], 'module_proof' => $row['module_proof']];
        }
        return $out;
    }

    private static function accountProofs(PDO $pdo, int $supplier, array $operations): array
    {
        $selects = [];
        $params = [];
        foreach ($operations as $operation) {
            foreach (['receivable' => 'receivable_account_id', 'revenue' => 'revenue_account_id'] as $role => $field) {
                $selects[] = 'SELECT ? operation_id,? role,? account_id,CAST(? AS DATE) accounting_date';
                array_push($params, $operation['id'], $role, $operation[$field], $operation['accounting_date']);
            }
        }
        $sql = 'WITH requested AS (' . implode(' UNION ALL ', $selects) . '), ranked AS (
            SELECT r.operation_id,r.role,a.id,a.account_code,a.account_type,
                   CASE WHEN h.id IS NULL THEN a.name ELSE h.name END name,
                   CASE WHEN h.id IS NULL THEN a.normal_side ELSE h.normal_side END normal_side,
                   CASE WHEN h.id IS NULL THEN a.tax_deductibility ELSE h.tax_deductibility END tax_deductibility,
                   CASE WHEN h.id IS NULL THEN a.is_active ELSE h.is_active END is_active,
                   h.id definition_id,h.effective_from,h.definition_json,h.definition_hash,
                   ROW_NUMBER() OVER(PARTITION BY r.operation_id,r.role ORDER BY h.effective_from DESC,h.id DESC) rn
              FROM requested r
              JOIN chart_of_accounts a ON a.supplier_id=? AND a.id=r.account_id
         LEFT JOIN accounting_account_definition_history h
                ON h.supplier_id=a.supplier_id AND h.account_id=a.id AND h.effective_from<=r.accounting_date
             WHERE h.id IS NOT NULL OR NOT EXISTS(
                   SELECT 1 FROM accounting_account_definition_history future
                    WHERE future.supplier_id=a.supplier_id AND future.account_id=a.id)
        ) SELECT * FROM ranked WHERE rn=1 ORDER BY operation_id,role';
        $q = $pdo->prepare($sql);
        $q->execute([...$params, $supplier]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['definition_id'] !== null) {
                $definition = AccountDefinitionRepository::definition($row);
                if (AccountDefinitionRepository::encode($definition) !== $row['definition_json']
                    || !hash_equals(AccountDefinitionRepository::definitionHash($supplier, (int) $row['id'], $row['effective_from'], $definition), $row['definition_hash'])) {
                    throw new \LogicException('Membership clone account definition is invalid.');
                }
            }
            $role = $row['role'];
            $expectedType = $role === 'receivable' ? 'asset' : 'revenue';
            if ($row['account_type'] !== $expectedType || (int) $row['is_active'] !== 1) throw new \LogicException('Membership clone account proof is invalid.');
            $proof = ['id' => (int) $row['id'], 'account_code' => $row['account_code'], 'account_type' => $row['account_type'],
                'name' => $row['name'], 'normal_side' => $row['normal_side'], 'is_active' => true,
                'role' => $role === 'receivable' ? 'membership_receivable' : 'membership_revenue'];
            $proof['definition_hash'] = NonprofitTaxRules::hash(['id' => (int) $row['id'], 'account_code' => $row['account_code'],
                'account_type' => $row['account_type'], 'name' => $row['name'], 'normal_side' => $row['normal_side'],
                'tax_deductibility' => $row['tax_deductibility'], 'is_active' => $row['is_active']]);
            $out[(int) $row['operation_id']][$role] = $proof;
        }
        foreach ($operations as $operation) if (!isset($out[$operation['id']]['receivable'], $out[$operation['id']]['revenue'])) {
            throw new \LogicException('Membership clone account proof is incomplete.');
        }
        return $out;
    }

    private static function memberRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'client_id', 'created_by'] as $field) $row[$field] = (int) $row[$field];
        return $row;
    }

    private static function revisionRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'member_id', 'revision_no', 'created_by'] as $field) $row[$field] = (int) $row[$field];
        $row['previous_id'] = $row['previous_id'] === null ? null : (int) $row['previous_id'];
        foreach (['legal_basis', 'request'] as $field) {
            $row[$field] = json_decode($row[$field . '_json'], true, flags: JSON_THROW_ON_ERROR);
            unset($row[$field . '_json']);
        }
        return $row;
    }

    private static function operationRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'case_id', 'command_id', 'member_revision_id', 'legal_profile_id', 'accounting_period_id', 'amount_cents',
            'receivable_account_id', 'revenue_account_id', 'journal_entry_id', 'receivable_line_id', 'revenue_line_id', 'created_by'] as $field) $row[$field] = (int) $row[$field];
        foreach (['previous_operation_id', 'original_operation_id'] as $field) $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        foreach (['proof', 'receipt'] as $field) {
            $row[$field] = json_decode($row[$field . '_json'], true, flags: JSON_THROW_ON_ERROR);
            unset($row[$field . '_json']);
        }
        $record = self::operationRecord($row);
        if (!hash_equals($row['record_hash'], NonprofitTaxRules::hash($record))) throw new \LogicException('Membership clone operation hash is invalid.');
        return $row;
    }

    private static function claimRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'case_id', 'operation_id', 'member_id', 'client_id', 'amount_cents'] as $field) $row[$field] = (int) $row[$field];
        $row['original_claim_id'] = $row['original_claim_id'] === null ? null : (int) $row['original_claim_id'];
        if (!hash_equals($row['record_hash'], NonprofitTaxRules::hash(MembershipChargeRepository::claimRecord($row)))) throw new \LogicException('Membership clone claim hash is invalid.');
        return $row;
    }

    private static function operationRecord(array $row): array
    {
        return array_intersect_key($row, array_flip(['supplier_id', 'case_id', 'command_id', 'operation', 'previous_operation_id',
            'original_operation_id', 'member_revision_id', 'legal_profile_id', 'accounting_period_id', 'accounting_date', 'due_date',
            'amount_cents', 'receivable_account_id', 'revenue_account_id', 'journal_entry_id', 'receivable_line_id', 'revenue_line_id',
            'preview_hash', 'proof', 'receipt', 'parent_hash', 'created_by']));
    }

    private static function revisionProof(array $revision): array
    {
        return array_intersect_key($revision, array_flip(['id', 'revision_no', 'effective_from', 'status', 'legal_basis', 'record_hash', 'request_hash']));
    }

    private static function legalBasis(TextScrubber $scrubber, array $basis): array
    {
        if (($basis['organisation_form'] ?? null) !== 'association' || ($basis['relationship'] ?? null) !== 'nonreciprocal_membership') {
            throw new \LogicException('Membership clone legal basis is invalid.');
        }
        $basis['reference'] = $scrubber->scrub((string) ($basis['reference'] ?? ''));
        $basis['evidence'] = $scrubber->scrub((string) ($basis['evidence'] ?? ''));
        return $basis;
    }

    private static function bulkUpdate(PDO $pdo, string $table, int $supplier, array $rows, array $fields): void
    {
        if ($rows === []) return;
        if (count($rows) > self::BATCH) throw new \LogicException('Membership clone update batch exceeds 500 rows.');
        $sets = [];
        $params = [];
        foreach ($fields as $field) {
            $case = [];
            foreach ($rows as $row) {
                $case[] = 'WHEN ? THEN ?';
                array_push($params, $row['id'], $row[$field]);
            }
            $sets[] = $field . '=CASE id ' . implode(' ', $case) . ' ELSE ' . $field . ' END';
        }
        $ids = array_column($rows, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("UPDATE {$table} SET " . implode(',', $sets) . " WHERE supplier_id=? AND id IN ({$marks})");
        $q->execute([...$params, $supplier, ...$ids]);
        if ($q->rowCount() !== count($rows)) throw new \LogicException('Membership clone update lost a captured row.');
    }

    private static function groupBySupplier(array $rows, callable $supplier): array
    {
        $out = [];
        foreach ($rows as $row) $out[$supplier($row)][] = $row;
        return $out;
    }

    private static function financialHash(PDO $pdo): string
    {
        $tables = [
            'nonprofit_members' => ['id', 'supplier_id', 'client_id', 'created_by', 'created_at', 'recorded_at_utc'],
            'nonprofit_member_revisions' => ['id', 'supplier_id', 'member_id', 'revision_no', 'previous_id', 'effective_from', 'status', 'created_by', 'created_at', 'recorded_at_utc'],
            'nonprofit_membership_charge_cases' => ['id', 'supplier_id', 'member_id', 'title_key', 'period_from', 'period_to', 'head_operation_id', 'row_version', 'created_by', 'created_at'],
            'nonprofit_membership_charge_operations' => ['id', 'supplier_id', 'case_id', 'command_id', 'operation', 'previous_operation_id', 'original_operation_id', 'member_revision_id', 'legal_profile_id', 'accounting_period_id', 'accounting_date', 'due_date', 'amount_cents', 'receivable_account_id', 'revenue_account_id', 'journal_entry_id', 'receivable_line_id', 'revenue_line_id', 'receipt_json', 'created_by', 'created_at'],
            'nonprofit_membership_charge_claims' => ['id', 'supplier_id', 'case_id', 'operation_id', 'original_claim_id', 'member_id', 'client_id', 'kind', 'amount_cents', 'created_at', 'recorded_at_utc'],
        ];
        $hash = str_repeat('0', 64);
        foreach ($tables as $table => $columns) {
            $hash = hash('sha256', $hash . $table);
            foreach (self::suppliers($pdo, $table) as $supplier) {
                $after = 0;
                do {
                    $q = $pdo->prepare('SELECT ' . implode(',', $columns) . " FROM {$table} WHERE supplier_id=? AND id>? ORDER BY id LIMIT " . self::BATCH);
                    $q->execute([$supplier, $after]);
                    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        $after = (int) $row['id'];
                        $hash = hash('sha256', $hash . NonprofitTaxRules::canonicalJson($row));
                    }
                } while (count($rows) === self::BATCH);
            }
        }
        return $hash;
    }

    private static function same(array $left, array $right): bool
    {
        return hash_equals(NonprofitTaxRules::hash($left), NonprofitTaxRules::hash($right));
    }

    private static function assertClonePhase(Connection $db): void
    {
        if ($db->pdo()->inTransaction() || TriggerMetadata::any($db->pdo())) {
            throw new \LogicException('Membership clone must run before trigger installation outside an active transaction.');
        }
    }
}
