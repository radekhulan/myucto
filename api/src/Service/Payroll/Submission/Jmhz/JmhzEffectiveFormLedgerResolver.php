<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;

/** Načte immutable XML + podepsané protokoly a předá je čistému resolveru. */
final readonly class JmhzEffectiveFormLedgerResolver
{
    public function __construct(
        private PayrollSubmissionRepository $repository,
        private JmhzFrozenPayloadReader $frozen,
        private JmhzEffectiveFormStateResolver $resolver = new JmhzEffectiveFormStateResolver(),
    ) {}

    /** @param list<string> $currentEmploymentExternalIdentifiers */
    public function resolve(
        int $supplierId,
        string $environment,
        int $regularSubmissionId,
        array $currentEmploymentExternalIdentifiers,
    ): JmhzEffectiveFormSet {
        $rows = $this->repository->jmhzChainForRoot(
            $supplierId,
            $environment,
            $regularSubmissionId,
        );
        if ($rows === [] || $rows[0]['id'] !== $regularSubmissionId
            || $rows[0]['submission_kind'] !== 'regular'
        ) {
            throw new JmhzXmlException(
                'jmhz_effective_state_root_invalid',
                'Řádné podání nebylo nalezeno ve stejné firmě a prostředí.',
            );
        }
        $chain = [];
        foreach ($rows as $row) {
            $description = $this->frozen->describe(
                $supplierId,
                $environment,
                $row['id'],
            );
            $evidence = $this->repository->listJmhzReceiptEvidenceForSubmission(
                $supplierId,
                $environment,
                $row['id'],
            );
            $chain[] = $this->submissionEvidence($row['id'], $description, $evidence);
        }

        return $this->resolver->resolve($chain, $currentEmploymentExternalIdentifiers);
    }

    /**
     * @param array{submission_type:string,forms:list<array<string,mixed>>} $description
     * @param list<array<string,mixed>> $evidence
     * @return array<string,mixed>
     */
    private function submissionEvidence(int $submissionId, array $description, array $evidence): array
    {
        if ($evidence === []) {
            return [
                'submission_id' => $submissionId,
                'submission_type' => $description['submission_type'],
                'trusted' => false,
                'remote_status' => null,
                'forms' => [],
            ];
        }
        /** @var array<string,array<string,mixed>> $formsByGuid */
        $formsByGuid = [];
        foreach ($description['forms'] as $form) {
            $formsByGuid[(string) $form['form_guid']] = $form + ['remote_status' => null];
        }
        $trusted = true;
        $terminalStatus = null;
        $outcomes = [];
        foreach ($evidence as $row) {
            $receiptStatus = $row['remote_status'] ?? null;
            if (($row['protocol_code'] ?? null) !== 'CSSZ_JMHZ') {
                // Převzetí VREP je legitimní součást transportního ledgeru,
                // ale neurčuje výsledek JMHZ. Jiný kód smí být ignorován jen
                // dokud netvrdí konečný stav podání.
                if (in_array($receiptStatus, ['accepted', 'partially_accepted', 'rejected'], true)) {
                    $trusted = false;
                }
                continue;
            }
            if (($row['verification_status'] ?? null) !== 'trusted') {
                $trusted = false;
                continue;
            }
            if (in_array($receiptStatus, ['accepted', 'partially_accepted', 'rejected'], true)) {
                if ($terminalStatus !== null && $terminalStatus !== $receiptStatus) {
                    throw new JmhzXmlException(
                        'jmhz_effective_state_protocol_conflict',
                        'Podepsané protokoly uvádějí rozporný konečný stav podání.',
                    );
                }
                $terminalStatus = $receiptStatus;
            }
            $guid = $row['form_guid'] ?? null;
            if (!is_string($guid)) {
                continue;
            }
            $guid = strtoupper($guid);
            if (!isset($formsByGuid[$guid])) {
                throw new JmhzXmlException(
                    'jmhz_effective_state_protocol_conflict',
                    'Podepsaný protokol odkazuje na formulář mimo zmrazené podání.',
                );
            }
            $status = $row['form_remote_status'] ?? null;
            if (isset($outcomes[$guid]) && $outcomes[$guid] !== $status) {
                throw new JmhzXmlException(
                    'jmhz_effective_state_protocol_conflict',
                    'Podepsané protokoly uvádějí rozporný výsledek formuláře.',
                );
            }
            $outcomes[$guid] = $status;
            foreach ([
                'external_person_reference' => 'person_external_identifier',
                'external_employment_reference' => 'employment_external_identifier',
            ] as $source => $target) {
                $protocolValue = $row[$source] ?? null;
                $frozenValue = $formsByGuid[$guid][$target] ?? null;
                if (is_string($protocolValue) && is_string($frozenValue)
                    && !hash_equals($frozenValue, $protocolValue)
                ) {
                    throw new JmhzXmlException(
                        'jmhz_effective_state_protocol_conflict',
                        'Identita formuláře v protokolu neodpovídá zmrazenému XML.',
                    );
                }
                if ($frozenValue === null && is_string($protocolValue)) {
                    $formsByGuid[$guid][$target] = $protocolValue;
                }
            }
        }
        foreach ($formsByGuid as $guid => &$form) {
            $status = $outcomes[$guid] ?? null;
            if ($status === null && $terminalStatus === 'accepted') {
                $status = 'accepted';
            } elseif ($status === null && $terminalStatus === 'rejected') {
                $status = 'rejected';
            }
            $form['remote_status'] = $status;
        }
        unset($form);

        return [
            'submission_id' => $submissionId,
            'submission_type' => $description['submission_type'],
            'trusted' => $trusted,
            'remote_status' => $terminalStatus,
            'forms' => array_values($formsByGuid),
        ];
    }
}
