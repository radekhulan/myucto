<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollInsolvencyPaymentRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentInput;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyMode;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

final class PayrollInsolvencyLiabilityMaterializer
{
    private const SOURCE_SCHEMA = 'payroll-payment-insolvency-source.v1';

    public function __construct(
        private readonly PayrollPaymentLiabilityRepository $liabilities,
        private readonly PayrollInsolvencyPaymentRepository $insolvency,
        private readonly PayrollSensitiveData $sensitiveData,
    ) {}

    /** @return array{liability_ids:list<int>,created_count:int} */
    public function materialize(
        int $supplierId,
        int $revisionId,
        ?int $actorUserId = null,
    ): array {
        if ($supplierId <= 0 || $revisionId <= 0
            || ($actorUserId !== null && $actorUserId <= 0)
        ) {
            throw new \InvalidArgumentException(
                'Firma, revize nebo uživatel plateb oddlužení není platný.',
            );
        }

        return $this->liabilities->transaction(function () use (
            $supplierId,
            $revisionId,
            $actorUserId,
        ): array {
            $revision = $this->liabilities->lockRevision(
                $supplierId,
                $revisionId,
            );
            if ($revision === null) {
                throw new \DomainException('Mzdová revize neexistuje.');
            }
            $this->assertRevision($revision);
            $targets = $this->currentTargets($supplierId, $revisionId);
            $prior = $this->priorState(
                $this->liabilities->lockEarlierInstitutionalLiabilities(
                    $supplierId,
                    $revision['run_id'],
                    $revision['revision_no'],
                    PayrollInsolvencyPaymentRepository::LIABILITY_KIND,
                ),
            );
            if ($revision['revision_kind'] === 'regular' && $prior !== []) {
                throw new \DomainException(
                    'Další revize závazku oddlužení musí být opravná.',
                );
            }
            if ($revision['revision_kind'] === 'correction'
                && $revision['previous_revision_id'] === null
            ) {
                throw new \DomainException(
                    'Opravná revize nemá předchozí revizi.',
                );
            }

            $references = array_values(array_unique([
                ...array_keys($targets),
                ...array_keys($prior),
            ]));
            sort($references, SORT_STRING);
            $ids = [];
            $created = 0;
            foreach ($references as $reference) {
                $target = $targets[$reference] ?? null;
                $previous = $prior[$reference] ?? null;
                if ($target !== null && $previous !== null
                    && ($target['recipient_reference']
                            !== $previous['recipient_reference']
                        || $target['target_snapshot']
                            !== $previous['target_snapshot'])
                ) {
                    throw new \DomainException(
                        'Neměnný cíl oddlužení se v opravném řetězci změnil.',
                    );
                }
                $targetAmount = $target['amount_minor'] ?? 0;
                $priorSigned = $previous['signed_minor'] ?? 0;
                $delta = $this->subtract($targetAmount, $priorSigned);
                if ($delta === 0) {
                    continue;
                }
                $direction = $delta > 0 ? 'outgoing' : 'incoming';
                $amount = abs($delta);
                $recipient = $target['recipient_reference']
                    ?? $previous['recipient_reference']
                    ?? throw new \LogicException(
                        'Chybí příjemce závazku oddlužení.',
                    );
                $snapshot = $target['target_snapshot']
                    ?? $previous['target_snapshot']
                    ?? throw new \LogicException(
                        'Chybí zmrazený platební pokyn oddlužení.',
                    );
                $source = [
                    'schema_reference' => self::SOURCE_SCHEMA,
                    'run_id' => $revision['run_id'],
                    'revision_id' => $revisionId,
                    'revision_no' => $revision['revision_no'],
                    'logical_reference' => $reference,
                    'recipient_reference' => $recipient,
                    'liability_kind' =>
                        PayrollInsolvencyPaymentRepository::LIABILITY_KIND,
                    ...$snapshot,
                    'target_amount_minor' => $targetAmount,
                    'prior_signed_minor' => $priorSigned,
                    'delta_signed_minor' => $delta,
                ];
                $sourceJson = CanonicalJson::encode($source);
                $sourceHash = hash('sha256', $sourceJson);
                $idempotencyHash = hash('sha256', CanonicalJson::encode([
                    'schema_reference' =>
                        'payroll-payment-insolvency-idempotency.v1',
                    'supplier_id' => $supplierId,
                    'revision_id' => $revisionId,
                    'logical_reference' => $reference,
                    'source_snapshot_hash' => $sourceHash,
                ]), true);
                $previousId = $previous['latest_id'] ?? null;
                $existing = $this->liabilities->findAnyForUpdate(
                    $supplierId,
                    $revisionId,
                    $reference,
                );
                if ($existing !== null) {
                    $this->assertReplay(
                        $existing,
                        $direction,
                        $recipient,
                        (string) $snapshot['payment_due_on'],
                        $amount,
                        $previousId,
                        $sourceHash,
                        $idempotencyHash,
                    );
                    $ids[] = $existing['id'];
                    continue;
                }
                $ids[] = $this->liabilities->insertInstitutional(
                    $supplierId,
                    $revisionId,
                    $reference,
                    PayrollInsolvencyPaymentRepository::LIABILITY_KIND,
                    $direction,
                    $recipient,
                    (string) $snapshot['payment_due_on'],
                    $amount,
                    $previousId,
                    $sourceJson,
                    $sourceHash,
                    $idempotencyHash,
                    $actorUserId,
                );
                ++$created;
            }

            return ['liability_ids' => $ids, 'created_count' => $created];
        });
    }

    /** @param array<string,mixed> $revision */
    private function assertRevision(array $revision): void
    {
        if (($revision['revision_status'] ?? null) !== 'approved'
            || ($revision['revision_no'] ?? null)
                !== ($revision['current_revision_no'] ?? null)
        ) {
            throw new \DomainException(
                'Závazky lze vytvořit jen z aktuální schválené revize.',
            );
        }
        if (!in_array($revision['revision_kind'] ?? null, [
            'regular',
            'correction',
        ], true)) {
            throw new \DomainException('Typ mzdové revize není podporovaný.');
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function currentTargets(int $supplierId, int $revisionId): array
    {
        $targets = [];
        foreach ($this->insolvency->payableForRevision(
            $supplierId,
            $revisionId,
        ) as $row) {
            $employeeId = $this->integer($row, 'employee_id');
            $employmentId = $this->integer($row, 'employment_id');
            $instructionId = $this->integer($row, 'instruction_id');
            $accountId = $this->integer($row, 'institution_account_id');
            $amount = $this->integer($row, 'total_minor_units');
            if ($amount <= 0) {
                throw new \DomainException(
                    'Platební závazek oddlužení nemá kladnou částku.',
                );
            }
            $dueOn = $this->date($row['payment_date'] ?? null);
            $accountHash = $this->text($row, 'institution_account_hash');
            $instructionHash = $this->text($row, 'instruction_hash');
            $documentHash = $this->text($row, 'decision_document_hash');
            $inputSnapshot = $this->canonicalObject(
                $this->text($row, 'input_snapshot_json'),
                $this->text($row, 'input_snapshot_hash'),
            );
            $input = GarnishmentInput::fromCanonicalArray($inputSnapshot);
            if (($inputSnapshot['schema_version'] ?? null)
                    !== 'payroll-enforcement-input.v1'
                || ($inputSnapshot['supplier_id'] ?? null) !== $supplierId
                || ($inputSnapshot['employee_id'] ?? null) !== $employeeId
                || $input->period . '-01' !== $this->text($row, 'period_start')
                || $input->insolvency->mode !== InsolvencyMode::ApprovedStandard
                || !$input->insolvency->decisionVerified
                || !$input->insolvency->recipientVerified
                || $input->insolvency->paymentInstructionId !== $instructionId
                || $input->insolvency->paymentInstructionHash
                    !== $instructionHash
                || $input->insolvency->employmentId !== $employmentId
            ) {
                throw new \DomainException(
                    'Platební závazek smí vzniknout jen z neměnného pokynu '
                    . 'schváleného standardního oddlužení.',
                );
            }
            $instructionMaterial = [
                'schema_reference' =>
                    'payroll-insolvency-payment-instruction.v1',
                'supplier_id' => $supplierId,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'period_start' => $this->text($row, 'period_start'),
                'institution_account_id' => $accountId,
                'institution_account_row_version' =>
                    $this->integer($row, 'institution_account_row_version'),
                'institution_account_hash' => $accountHash,
                'institution_type' => $this->text($row, 'institution_type'),
                'institution_code' => $this->text($row, 'institution_code'),
                'decision_document_id' =>
                    $this->integer($row, 'decision_document_id'),
                'decision_document_hash' => $documentHash,
            ];
            if (!hash_equals(
                $instructionHash,
                hash('sha256', CanonicalJson::encode($instructionMaterial)),
            )
                || $row['institution_type'] !== 'other_recipient'
                || $row['currency_code'] !== 'CZK'
                || $this->integer($row, 'current_account_row_version')
                    !== $this->integer(
                        $row,
                        'institution_account_row_version',
                    )
                || !hash_equals(
                    $this->text($row, 'current_account_hash'),
                    $accountHash,
                )
                || !hash_equals(
                    $this->text($row, 'current_document_hash'),
                    $documentHash,
                )
                || ($row['document_deleted_at'] ?? null) !== null
                || $this->text($row, 'valid_from') > $dueOn
                || (($row['valid_to'] ?? null) !== null
                    && $this->text($row, 'valid_to') < $dueOn)
                || !in_array($row['source_kind'] ?? null, [
                    'official_registry',
                    'official_document',
                    'institution_notice',
                    'user_verified',
                ], true)
                || $this->integer($row, 'verified_by') <= 0
                || $this->date($row['verified_on'] ?? null) > $dueOn
            ) {
                throw new \DomainException(
                    'Neměnný platební pokyn oddlužení už neodpovídá '
                    . 'ověřenému účtu nebo rozhodnutí.',
                );
            }
            $plaintext = $this->sensitiveData->reveal(
                $this->text($row, 'bank_account_ciphertext'),
                PayrollSensitiveField::BANK_ACCOUNT,
                $supplierId,
                $accountId,
                PayrollRevealPurpose::PAYMENT_LIABILITY_ACCOUNT,
            );
            $actualHash = bin2hex($this->sensitiveData->lookupHash(
                $plaintext,
                PayrollSensitiveField::BANK_ACCOUNT,
                $supplierId,
            ));
            if (!hash_equals($accountHash, $actualHash)) {
                throw new \DomainException(
                    'Obsah účtu příjemce oddlužení neodpovídá otisku.',
                );
            }
            $symbols = [
                'variable_symbol' => $this->nullableText(
                    $row['variable_symbol'] ?? null,
                ),
                'specific_symbol' => $this->nullableText(
                    $row['specific_symbol'] ?? null,
                ),
                'constant_symbol' => $this->nullableText(
                    $row['constant_symbol'] ?? null,
                ),
            ];
            $verificationHash = hash('sha256', CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-institution-payment-target-verification.v1',
                'institution_type' => 'other_recipient',
                'institution_code' => $this->text($row, 'institution_code'),
                'payment_target_id' => $accountId,
                'payment_target_hash' => $accountHash,
                'row_version' =>
                    $this->integer($row, 'institution_account_row_version'),
                ...$symbols,
                'source_kind' => $this->text($row, 'source_kind'),
                'source_reference' => $this->text($row, 'source_reference'),
                'verified_on' => $this->text($row, 'verified_on'),
                'verified_by' => $this->integer($row, 'verified_by'),
            ]));
            $reference = PayrollInsolvencyPaymentRepository::liabilityReference(
                $employeeId,
                $employmentId,
            );
            if (isset($targets[$reference])) {
                throw new \DomainException(
                    'Revize obsahuje pro osobu a pracovní vztah více '
                    . 'platebních pokynů oddlužení.',
                );
            }
            $recipient = 'institution:other_recipient:'
                . $this->text($row, 'institution_code')
                . ":account:{$accountId}";
            $targets[$reference] = [
                'recipient_reference' => $recipient,
                'amount_minor' => $amount,
                'target_snapshot' => [
                    'employee_id' => $employeeId,
                    'employment_id' => $employmentId,
                    'period_start' => $this->text($row, 'period_start'),
                    'insolvency_payment_instruction_id' => $instructionId,
                    'insolvency_payment_instruction_hash' => $instructionHash,
                    'decision_document_id' =>
                        $this->integer($row, 'decision_document_id'),
                    'decision_document_hash' => $documentHash,
                    'institution_type' => 'other_recipient',
                    'institution_code' => $this->text($row, 'institution_code'),
                    'payment_target_id' => $accountId,
                    'payment_target_hash' => $accountHash,
                    'payment_target_row_version' =>
                        $this->integer(
                            $row,
                            'institution_account_row_version',
                        ),
                    'payment_target_verification_hash' => $verificationHash,
                    ...$symbols,
                    'payment_due_on' => $dueOn,
                ],
            ];
        }

        return $targets;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function priorState(array $rows): array
    {
        $state = [];
        foreach ($rows as $row) {
            $source = $this->canonicalObject(
                $row['source_snapshot_json'],
                $row['source_snapshot_hash'],
            );
            if (($source['schema_reference'] ?? null) !== self::SOURCE_SCHEMA
                || ($source['recipient_reference'] ?? null)
                    !== $row['recipient_reference']
            ) {
                throw new \DomainException(
                    'Dřívější závazek oddlužení nemá platný zdroj.',
                );
            }
            $reference = $row['liability_reference'];
            $signed = $row['direction'] === 'outgoing'
                ? $row['amount_minor'] : -$row['amount_minor'];
            $snapshot = [];
            foreach ([
                'employee_id', 'employment_id', 'period_start',
                'insolvency_payment_instruction_id',
                'insolvency_payment_instruction_hash', 'decision_document_id',
                'decision_document_hash', 'institution_type',
                'institution_code', 'payment_target_id',
                'payment_target_hash', 'payment_target_row_version',
                'payment_target_verification_hash', 'variable_symbol',
                'specific_symbol', 'constant_symbol', 'payment_due_on',
            ] as $field) {
                $snapshot[$field] = $source[$field] ?? null;
            }
            if (!isset($state[$reference])) {
                $state[$reference] = [
                    'recipient_reference' => $row['recipient_reference'],
                    'signed_minor' => 0,
                    'latest_id' => $row['id'],
                    'target_snapshot' => $snapshot,
                ];
            } elseif ($state[$reference]['recipient_reference']
                    !== $row['recipient_reference']
                || $state[$reference]['target_snapshot'] !== $snapshot
            ) {
                throw new \DomainException(
                    'Řetězec závazku oddlužení změnil zmrazený cíl.',
                );
            }
            $state[$reference]['signed_minor'] = $this->add(
                $state[$reference]['signed_minor'],
                $signed,
            );
            $state[$reference]['latest_id'] = $row['id'];
        }

        return $state;
    }

    /** @param array<string,mixed> $existing */
    private function assertReplay(
        array $existing,
        string $direction,
        string $recipient,
        string $dueOn,
        int $amount,
        ?int $previousId,
        string $sourceHash,
        string $idempotencyHash,
    ): void {
        if ($existing['employee_id'] !== null
            || $existing['liability_kind']
                !== PayrollInsolvencyPaymentRepository::LIABILITY_KIND
            || $existing['direction'] !== $direction
            || $existing['recipient_reference'] !== $recipient
            || $existing['due_on'] !== $dueOn
            || $existing['amount_minor'] !== $amount
            || $existing['previous_liability_id'] !== $previousId
            || !hash_equals($existing['source_snapshot_hash'], $sourceHash)
            || !hash_equals(
                $existing['idempotency_key_hash'],
                $idempotencyHash,
            )
        ) {
            throw new \DomainException(
                'Idempotentní opakování závazku oddlužení nesouhlasí.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function canonicalObject(string $json, string $hash): array
    {
        try {
            $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \DomainException(
                'Zdroj závazku oddlužení není platný JSON.',
                previous: $exception,
            );
        }
        if (!is_array($value) || array_is_list($value)
            || !hash_equals(
                $hash,
                hash('sha256', CanonicalJson::encode($value)),
            )
        ) {
            throw new \DomainException(
                'Otisk zdroje závazku oddlužení nesouhlasí.',
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = filter_var($row[$field] ?? null, FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new \UnexpectedValueException(
                "Databázové pole {$field} není celé číslo.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Databázové pole {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new \UnexpectedValueException(
                'Platební symbol oddlužení není platný.',
            );
        }

        return trim($value);
    }

    private function date(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                'Datum platebního pokynu oddlužení není platné.',
            );
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \UnexpectedValueException(
                'Datum platebního pokynu oddlužení není platné.',
            );
        }

        return $value;
    }

    private function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \OverflowException(
                'Součet závazků oddlužení přetekl.',
            );
        }

        return $left + $right;
    }

    private function subtract(int $left, int $right): int
    {
        if (($right > 0 && $left < PHP_INT_MIN + $right)
            || ($right < 0 && $left > PHP_INT_MAX + $right)
        ) {
            throw new \OverflowException(
                'Rozdíl závazků oddlužení přetekl.',
            );
        }

        return $left - $right;
    }
}
