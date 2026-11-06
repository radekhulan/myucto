<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Nonprofit;

use MyInvoice\Service\Anonymization\FinancialCloneHashKind;
use MyInvoice\Service\Anonymization\FinancialCloneHashMap;
use MyInvoice\Service\Nonprofit\Membership\MembershipClassification;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\SourceFacts\MembershipJ6SourceFacts;

final readonly class J6MembershipModuleFactRebaser
{
    private const FIELDS = [
        'operation_id', 'case_id', 'claim_id', 'claim_record_hash', 'operation', 'reversal_of_operation_id',
        'member_id', 'member_revision_id', 'legal_profile_id', 'accounting_period_id', 'accounting_date',
        'amount_cents', 'journal_entry_id', 'journal_line_id', 'member_revision_hash', 'legal_profile_hash',
        'operation_record_hash', 'journal_receipt_hash', 'classification', 'classification_hash',
        'legal_basis_code', 'tax_treatment',
    ];

    private const HASHES = [
        'claim_record_hash' => [FinancialCloneHashKind::MEMBERSHIP_CLAIM, 'claim_id'],
        'member_revision_hash' => [FinancialCloneHashKind::MEMBERSHIP_REVISION, 'member_revision_id'],
        'legal_profile_hash' => [FinancialCloneHashKind::MEMBERSHIP_LEGAL_PROFILE, 'legal_profile_id'],
        'operation_record_hash' => [FinancialCloneHashKind::MEMBERSHIP_OPERATION, 'operation_id'],
        'journal_receipt_hash' => [FinancialCloneHashKind::MEMBERSHIP_JOURNAL_RECEIPT, 'operation_id'],
        'classification_hash' => [FinancialCloneHashKind::MEMBERSHIP_CLASSIFICATION, 'operation_id'],
    ];

    public function __construct(
        private int $supplierId,
        private FinancialCloneHashMap $hashes,
    ) {
        if ($supplierId <= 0) throw new \InvalidArgumentException('Tenant membership fact mapy musí být kladný.');
    }

    public function rebaseRows(array $rows): array
    {
        if (count($rows) > 500) throw new \InvalidArgumentException('J6 membership fact stránka překračuje 500 řádků.');
        $requests = [];
        $bindings = [];
        foreach ($rows as $rowIndex => $row) {
            $fact = $row['module_facts'][MembershipJ6SourceFacts::SCHEMA] ?? null;
            if ($fact === null) continue;
            if (!is_array($fact)) throw new \UnexpectedValueException('J6 membership module fact není typovaný.');
            $this->assertFact($row, $fact);
            foreach (self::HASHES as $field => [$kind, $ownerField]) {
                $bindings[] = [$rowIndex, $field];
                $requests[] = ['kind' => $kind, 'supplier_id' => $this->supplierId,
                    'owner_id' => $fact[$ownerField], 'old_hash' => $fact[$field]];
            }
        }
        if ($requests === []) return $rows;
        $resolved = $this->hashes->resolveOwnedMany($requests);
        foreach ($bindings as $offset => [$rowIndex, $field]) {
            $replacement = $resolved[$offset] ?? null;
            if (!is_string($replacement)) {
                throw new \LogicException('J6 membership module fact nemá doloženou cílovou hash náhradu.');
            }
            $rows[$rowIndex]['module_facts'][MembershipJ6SourceFacts::SCHEMA][$field] = $replacement;
        }
        foreach ($rows as $row) {
            $fact = $row['module_facts'][MembershipJ6SourceFacts::SCHEMA] ?? null;
            if (is_array($fact) && !hash_equals($fact['classification_hash'], NonprofitTaxRules::hash($fact['classification']))) {
                throw new \LogicException('J6 membership klasifikace vyžaduje typovaný payload rebase.');
            }
        }
        return $rows;
    }

    private function assertFact(array $row, array $fact): void
    {
        if (count($fact) !== count(self::FIELDS) || array_diff(array_keys($fact), self::FIELDS) !== []) {
            throw new \UnexpectedValueException('J6 membership module fact obsahuje neznámá nebo chybějící pole.');
        }
        foreach (['operation_id', 'case_id', 'claim_id', 'member_id', 'member_revision_id', 'legal_profile_id',
            'accounting_period_id', 'journal_entry_id', 'journal_line_id'] as $field) {
            if (!is_int($fact[$field]) || $fact[$field] <= 0) throw new \UnexpectedValueException('J6 membership identity je neplatná.');
        }
        if ($fact['reversal_of_operation_id'] !== null
            && (!is_int($fact['reversal_of_operation_id']) || $fact['reversal_of_operation_id'] <= 0)) {
            throw new \UnexpectedValueException('J6 membership reversal identity je neplatná.');
        }
        foreach (array_keys(self::HASHES) as $field) {
            if (!is_string($fact[$field]) || preg_match('/^[a-f0-9]{64}$/D', $fact[$field]) !== 1) {
                throw new \UnexpectedValueException('J6 membership hash je neplatný.');
            }
        }
        if (!is_array($fact['classification']) || count($fact['classification']) !== count(MembershipClassification::AXES)
            || array_diff(array_keys($fact['classification']), MembershipClassification::AXES) !== []) {
            throw new \UnexpectedValueException('J6 membership klasifikace není typovaná.');
        }
        foreach (MembershipClassification::AXES as $axis) {
            $proof = $fact['classification'][$axis];
            $identity = $axis === 'activity_kind' ? ['type_id', 'value_id', 'code', 'origin']
                : ['type_id', 'value_id', 'semantic_key', 'origin'];
            if (!is_array($proof) || count($proof) !== count($identity) || array_diff(array_keys($proof), $identity) !== []
                || !is_int($proof['type_id']) || $proof['type_id'] <= 0
                || !is_int($proof['value_id']) || $proof['value_id'] <= 0
                || !is_array($proof['origin']) || count($proof['origin']) !== 2
                || array_diff(array_keys($proof['origin']), ['origin', 'ref']) !== []) {
                throw new \UnexpectedValueException('J6 membership osa klasifikace není typovaná.');
            }
        }
        if ($fact['operation'] !== 'issue' && $fact['operation'] !== 'reverse') {
            throw new \UnexpectedValueException('J6 membership operace je neplatná.');
        }
        if ($fact['tax_treatment'] !== 'unclassified_requires_explicit_j6_decision'
            || $fact['legal_basis_code'] !== ['organisation_form' => 'association',
                'relationship' => 'nonreciprocal_membership']) {
            throw new \UnexpectedValueException('J6 membership právní titul je neplatný.');
        }
        if (!is_int($fact['amount_cents']) || (int) ($row['amount_cents'] ?? 0) !== $fact['amount_cents']
            || ($row['date'] ?? null) !== $fact['accounting_date']
            || (int) ($row['entry_id'] ?? 0) !== $fact['journal_entry_id']
            || (int) ($row['line_id'] ?? 0) !== $fact['journal_line_id']
            || ($row['kind'] ?? null) !== 'revenue' || ($row['source_type'] ?? null) !== 'nonprofit_membership') {
            throw new \UnexpectedValueException('J6 membership finanční vazba neodpovídá archivnímu řádku.');
        }
    }
}
