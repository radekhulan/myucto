<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollRiskySavingsRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

final class PayrollRiskySavingsLiabilityMaterializer
{
    public const LIABILITY_KIND = 'risky_savings';
    private const SOURCE_SCHEMA = 'payroll-payment-risky-savings-source.v1';

    public function __construct(
        private readonly PayrollPaymentLiabilityRepository $liabilities,
        private readonly PayrollRiskySavingsRepository $contributions,
        private readonly PayrollInstitutionAccountRepository $institutions,
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
                'Firma, revize nebo uživatel plateb povinného spoření není platný.',
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
                    self::LIABILITY_KIND,
                ),
            );
            if ($revision['revision_kind'] === 'regular' && $prior !== []) {
                throw new \DomainException(
                    'Další revize povinného spoření musí být opravná.',
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
                        'Platební cíl povinného spoření se v opravném řetězci změnil.',
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
                $recipientReference = $target['recipient_reference']
                    ?? $previous['recipient_reference']
                    ?? throw new \LogicException('Chybí příjemce povinného spoření.');
                $targetSnapshot = $target['target_snapshot']
                    ?? $previous['target_snapshot']
                    ?? throw new \LogicException('Chybí platební cíl povinného spoření.');
                $source = [
                    'schema_reference' => self::SOURCE_SCHEMA,
                    'run_id' => $revision['run_id'],
                    'revision_id' => $revisionId,
                    'revision_no' => $revision['revision_no'],
                    'logical_reference' => $reference,
                    'recipient_reference' => $recipientReference,
                    'liability_kind' => self::LIABILITY_KIND,
                    ...$targetSnapshot,
                    'target_amount_minor' => $targetAmount,
                    'prior_signed_minor' => $priorSigned,
                    'delta_signed_minor' => $delta,
                ];
                $sourceJson = CanonicalJson::encode($source);
                $sourceHash = hash('sha256', $sourceJson);
                $idempotencyHash = hash(
                    'sha256',
                    CanonicalJson::encode([
                        'schema_reference' =>
                            'payroll-payment-risky-savings-idempotency.v1',
                        'supplier_id' => $supplierId,
                        'revision_id' => $revisionId,
                        'logical_reference' => $reference,
                        'source_snapshot_hash' => $sourceHash,
                    ]),
                    true,
                );
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
                        $recipientReference,
                        (string) $targetSnapshot['payment_due_on'],
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
                    self::LIABILITY_KIND,
                    $direction,
                    $recipientReference,
                    (string) $targetSnapshot['payment_due_on'],
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
            'regular', 'correction',
        ], true)) {
            throw new \DomainException('Typ mzdové revize není podporovaný.');
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function currentTargets(int $supplierId, int $revisionId): array
    {
        $targets = [];
        foreach ($this->contributions->lockApprovedContributionsForRevision(
            $supplierId,
            $revisionId,
        ) as $row) {
            $dueOn = $this->date($row['payment_due_on'] ?? null);
            $institutionCode = $this->text(
                $row['institution_code'] ?? null,
                'kód penzijní společnosti',
            );
            $accounts = $this->institutions->lockEffectivePaymentTargets(
                $supplierId,
                'other_recipient',
                $institutionCode,
                'CZK',
                $dueOn,
            );
            $account = null;
            foreach ($accounts as $candidate) {
                if ($candidate['id'] === $row['institution_account_id']) {
                    $account = $candidate;
                    break;
                }
            }
            if ($account === null
                || $account['row_version']
                    !== $row['institution_account_row_version']
                || !is_string($row['institution_account_hash'] ?? null)
                || !hash_equals(
                    $account['bank_account_hash'],
                    $row['institution_account_hash'],
                )
            ) {
                throw new \DomainException(
                    'Účet penzijní společnosti se po schválení mzdové revize změnil.',
                );
            }
            $this->assertVerifiedAccount($supplierId, $dueOn, $account);
            $symbols = [
                'variable_symbol' => $this->nullableText($row['variable_symbol'] ?? null),
                'specific_symbol' => $this->nullableText($row['specific_symbol'] ?? null),
                'constant_symbol' => $account['constant_symbol'],
            ];
            $verificationHash = hash('sha256', CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-institution-payment-target-verification.v1',
                'institution_type' => 'other_recipient',
                'institution_code' => $institutionCode,
                'payment_target_id' => $account['id'],
                'payment_target_hash' => $account['bank_account_hash'],
                'row_version' => $account['row_version'],
                ...$symbols,
                'source_kind' => $account['source_kind'],
                'source_reference' => $account['source_reference'],
                'verified_on' => $account['verified_on'],
                'verified_by' => $account['verified_by'],
            ]));
            $employmentId = (int) $row['employment_id'];
            $reference = "risky-savings:e{$employmentId}";
            if (isset($targets[$reference])) {
                throw new \DomainException(
                    'Revize obsahuje pro pracovní vztah více příspěvků povinného spoření.',
                );
            }
            $recipientReference = 'institution:other_recipient:'
                . $institutionCode . ':account:' . $account['id'];
            $targets[$reference] = [
                'recipient_reference' => $recipientReference,
                'amount_minor' => (int) $row['contribution_minor'],
                'target_snapshot' => [
                    'employment_id' => $employmentId,
                    'pension_company' => (string) $row['pension_company'],
                    'product_reference' => (string) $row['product_reference'],
                    'institution_type' => 'other_recipient',
                    'institution_code' => $institutionCode,
                    'payment_target_id' => $account['id'],
                    'payment_target_hash' => $account['bank_account_hash'],
                    'payment_target_row_version' => $account['row_version'],
                    'payment_target_verification_hash' => $verificationHash,
                    ...$symbols,
                    'payment_message' => $row['payment_message'],
                    'payment_due_on' => $dueOn,
                ],
            ];
        }
        return $targets;
    }

    /** @param array<string,mixed> $account */
    private function assertVerifiedAccount(
        int $supplierId,
        string $dueOn,
        array $account,
    ): void {
        if (!in_array($account['source_kind'], [
            'official_registry', 'official_document',
            'institution_notice', 'user_verified',
        ], true)
            || $account['verified_by'] === null
            || $account['verified_on'] > $dueOn
        ) {
            throw new \DomainException(
                'Účet penzijní společnosti nemá úplné a účinné ověření.',
            );
        }
        $plaintext = $this->sensitiveData->reveal(
            $account['bank_account_ciphertext'],
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
            $account['id'],
        );
        $actualHash = bin2hex($this->sensitiveData->lookupHash(
            $plaintext,
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
        ));
        if (!hash_equals($account['bank_account_hash'], $actualHash)) {
            throw new \DomainException(
                'Obsah účtu penzijní společnosti neodpovídá uloženému otisku.',
            );
        }
    }

    /** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
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
                    'Dřívější závazek povinného spoření nemá platný zdroj.',
                );
            }
            $reference = $row['liability_reference'];
            $signed = $row['direction'] === 'outgoing'
                ? $row['amount_minor'] : -$row['amount_minor'];
            $snapshot = [];
            foreach ([
                'employment_id', 'pension_company', 'product_reference', 'institution_type',
                'institution_code', 'payment_target_id', 'payment_target_hash',
                'payment_target_row_version', 'payment_target_verification_hash',
                'variable_symbol', 'specific_symbol', 'constant_symbol',
                'payment_message', 'payment_due_on',
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
                    'Řetězec povinného spoření změnil zmrazený platební cíl.',
                );
            }
            $state[$reference]['signed_minor'] += $signed;
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
            || $existing['liability_kind'] !== self::LIABILITY_KIND
            || $existing['direction'] !== $direction
            || $existing['recipient_reference'] !== $recipient
            || $existing['due_on'] !== $dueOn
            || $existing['amount_minor'] !== $amount
            || $existing['previous_liability_id'] !== $previousId
            || !hash_equals($existing['source_snapshot_hash'], $sourceHash)
            || !hash_equals($existing['idempotency_key_hash'], $idempotencyHash)
        ) {
            throw new \DomainException(
                'Idempotentní opakování závazku povinného spoření nesouhlasí.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function canonicalObject(string $json, string $expectedHash): array
    {
        try {
            $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \DomainException('Zdroj závazku není platný JSON.', previous: $exception);
        }
        if (!is_array($value) || array_is_list($value)
            || !hash_equals($expectedHash, hash('sha256', CanonicalJson::encode($value)))
        ) {
            throw new \DomainException('Otisk zdroje závazku povinného spoření nesouhlasí.');
        }
        return $value;
    }

    private function subtract(int $left, int $right): int
    {
        if (($right > 0 && $left < PHP_INT_MIN + $right)
            || ($right < 0 && $left > PHP_INT_MAX + $right)
        ) {
            throw new \OverflowException('Rozdíl závazků povinného spoření přetekl.');
        }
        return $left - $right;
    }

    private function date(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \DomainException('Splatnost povinného spoření není datum.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \DomainException('Splatnost povinného spoření není platné datum.');
        }
        return $value;
    }

    private function text(mixed $value, string $context): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException(ucfirst($context) . ' není platný.');
        }
        return trim($value);
    }

    private function nullableText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
