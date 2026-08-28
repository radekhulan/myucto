<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

final class PayrollHealthInsuranceLiabilityMaterializer
{
    public function __construct(
        private readonly PayrollPaymentLiabilityRepository $liabilities,
        private readonly PayrollStatutoryResultRepository $statutoryResults,
        private readonly PayrollInstitutionAccountRepository $institutions,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly PayrollLevyDeadlinePolicy $deadlines,
    ) {}

    /**
     * @return array{liability_ids:list<int>,created_count:int}
     */
    public function materialize(
        int $supplierId,
        int $revisionId,
        ?int $actorUserId = null,
    ): array {
        if ($supplierId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a revize zdravotních závazků musí být kladná čísla.',
            );
        }
        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel materializace zdravotních závazků není platný.',
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

            $statutory = $this->statutoryResults->find(
                $supplierId,
                $revisionId,
                'health_insurance',
            );
            if ($statutory === null) {
                throw new \DomainException(
                    'Revize nemá neměnný výsledek zdravotního pojištění.',
                );
            }
            $root = $this->object(
                $statutory['result_snapshot'] ?? null,
                'výsledek zdravotního pojištění',
            );
            $rootHash = $this->hash($statutory, 'result_snapshot_hash');
            if (!hash_equals(
                $rootHash,
                hash('sha256', CanonicalJson::encode($root)),
            )) {
                throw new \DomainException(
                    'Otisk výsledku zdravotního pojištění nesouhlasí.',
                );
            }
            if (($statutory['schema_version'] ?? null)
                    !== 'payroll-health-result.v1'
                || ($statutory['result_status'] ?? null) !== 'calculated'
                || ($root['status'] ?? null) !== 'calculated'
            ) {
                throw new \DomainException(
                    'Zdravotní závazky vyžadují plně vypočtený výsledek bez ruční kontroly.',
                );
            }

            $this->date(
                $root['calculation_date'] ?? null,
                'datum výpočtu zdravotního pojištění',
            );
            $periodStart = $this->date(
                $revision['period_start'],
                'období mzdového běhu',
            );
            $dueOn = $this->deadlines->dueOn(
                PayrollLevyDeadlinePolicy::HEALTH_INSURANCE,
                $periodStart,
            );

            $targets = $this->currentTargets(
                $supplierId,
                $dueOn,
                $root,
            );
            $prior = $this->priorState(
                $this->liabilities->lockEarlierInstitutionalLiabilities(
                    $supplierId,
                    $revision['run_id'],
                    $revision['revision_no'],
                    'health_insurance',
                ),
            );
            if ($revision['revision_kind'] === 'regular' && $prior !== []) {
                throw new \DomainException(
                    'Další revize zdravotních závazků musí být opravná.',
                );
            }
            if ($revision['revision_kind'] === 'correction'
                && $revision['previous_revision_id'] === null
            ) {
                throw new \DomainException(
                    'Opravná revize nemá předchozí revizi.',
                );
            }

            $references = array_unique([
                ...array_keys($targets),
                ...array_keys($prior),
            ]);
            sort($references, SORT_STRING);
            $ids = [];
            $created = 0;
            foreach ($references as $reference) {
                $target = $targets[$reference] ?? null;
                $previous = $prior[$reference] ?? null;
                if ($target !== null && $previous !== null
                    && (
                        $target['recipient_reference']
                            !== $previous['recipient_reference']
                        || $target['target_snapshot']
                            !== $previous['target_snapshot']
                    )
                ) {
                    throw new \DomainException(
                        'Ověřený účet pojišťovny se proti předchozímu závazku změnil. '
                        . 'Nejprve uzavřete původní korekční řetězec.',
                    );
                }
                $targetAmount = $target['amount_minor'] ?? 0;
                $priorSigned = $previous['signed_minor'] ?? 0;
                $delta = $this->subtract($targetAmount, $priorSigned);
                if ($delta === 0) {
                    continue;
                }
                if ($delta === PHP_INT_MIN) {
                    throw new \OverflowException(
                        'Zdravotní závazek přetekl.',
                    );
                }
                $direction = $delta > 0 ? 'outgoing' : 'incoming';
                $amount = abs($delta);
                $recipientReference = $target['recipient_reference']
                    ?? $previous['recipient_reference']
                    ?? throw new \LogicException('Chybí příjemce závazku.');
                $targetSnapshot = $target['target_snapshot']
                    ?? $previous['target_snapshot']
                    ?? throw new \LogicException('Chybí snapshot příjemce.');
                $source = [
                    'schema_reference' =>
                        'payroll-payment-health-insurance-source.v1',
                    'run_id' => $revision['run_id'],
                    'revision_id' => $revisionId,
                    'revision_no' => $revision['revision_no'],
                    'statutory_result_hash' => $rootHash,
                    'logical_reference' => $reference,
                    'recipient_reference' => $recipientReference,
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
                            'payroll-payment-health-insurance-idempotency.v1',
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
                        $dueOn,
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
                    'health_insurance',
                    $direction,
                    $recipientReference,
                    $dueOn,
                    $amount,
                    $previousId,
                    $sourceJson,
                    $sourceHash,
                    $idempotencyHash,
                    $actorUserId,
                );
                ++$created;
            }

            return [
                'liability_ids' => $ids,
                'created_count' => $created,
            ];
        });
    }

    /**
     * @param array<string,mixed> $revision
     */
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

    /**
     * @param array<string,mixed> $root
     * @return array<string,array{
     *   recipient_reference:string,
     *   amount_minor:int,
     *   target_snapshot:array<string,mixed>
     * }>
     */
    private function currentTargets(
        int $supplierId,
        string $dueOn,
        array $root,
    ): array {
        $total = $this->nonNegativeInt(
            $root['total_contribution_minor_units'] ?? null,
            'celkový odvod zdravotního pojištění',
        );
        $sum = 0;
        $targets = [];
        foreach ($this->rows(
            $root['insurer_liabilities'] ?? null,
            'závazky zdravotních pojišťoven',
        ) as $row) {
            $code = $this->string($row, 'insurer_code');
            // Ze závazku se odvozuje reference i platební příkaz, takže
            // pojišťovna musí být z číselníku — pouhý tvar `\d{3}` tu propustil
            // neexistující kód až do zákonné platby.
            if (!HealthInsurers::isValid($code)) {
                throw new \DomainException(
                    HealthInsurers::invalidCodeMessage($code),
                );
            }
            $amount = $this->nonNegativeInt(
                $row['total_contribution_minor_units'] ?? null,
                'odvod zdravotní pojišťovny',
            );
            $sum = $this->add($sum, $amount);
            if ($amount === 0) {
                continue;
            }
            $reference = "health-insurance:i{$code}";
            if (isset($targets[$reference])) {
                throw new \DomainException(
                    'Výsledek obsahuje zdravotní pojišťovnu vícekrát.',
                );
            }
            $accounts = $this->institutions->lockEffectivePaymentTargets(
                $supplierId,
                'health_insurer',
                $code,
                'CZK',
                $dueOn,
            );
            if (count($accounts) !== 1) {
                throw new \DomainException(
                    count($accounts) === 0
                        ? "Pojišťovna {$code} nemá k datu splatnosti ověřený účet."
                        : "Pojišťovna {$code} má k datu splatnosti nejednoznačný účet.",
                );
            }
            $account = $accounts[0];
            $this->assertVerifiedAccount($supplierId, $dueOn, $account);
            $accountId = $account['id'];
            $verificationHash = hash(
                'sha256',
                CanonicalJson::encode([
                    'schema_reference' =>
                        'payroll-institution-payment-target-verification.v1',
                    'institution_type' => 'health_insurer',
                    'institution_code' => $code,
                    'payment_target_id' => $accountId,
                    'payment_target_hash' => $account['bank_account_hash'],
                    'row_version' => $account['row_version'],
                    'variable_symbol' => $account['variable_symbol'],
                    'specific_symbol' => $account['specific_symbol'],
                    'constant_symbol' => $account['constant_symbol'],
                    'source_kind' => $account['source_kind'],
                    'source_reference' => $account['source_reference'],
                    'verified_on' => $account['verified_on'],
                    'verified_by' => $account['verified_by'],
                ]),
            );
            $recipientReference =
                "institution:health_insurer:{$code}:account:{$accountId}";
            $targets[$reference] = [
                'recipient_reference' => $recipientReference,
                'amount_minor' => $amount,
                'target_snapshot' => [
                    'institution_type' => 'health_insurer',
                    'institution_code' => $code,
                    'payment_target_id' => $accountId,
                    'payment_target_hash' => $account['bank_account_hash'],
                    'payment_target_row_version' =>
                        $account['row_version'],
                    'payment_target_verification_hash' =>
                        $verificationHash,
                    'variable_symbol' => $account['variable_symbol'],
                    'specific_symbol' => $account['specific_symbol'],
                    'constant_symbol' => $account['constant_symbol'],
                ],
            ];
        }
        if ($sum !== $total) {
            throw new \DomainException(
                'Součet závazků pojišťoven neodpovídá kořenovému výsledku.',
            );
        }

        return $targets;
    }

    /**
     * @param array{
     *   id:int,
     *   institution_id:int,
     *   institution_type:string,
     *   institution_code:string,
     *   institution_name:string,
     *   bank_account_ciphertext:string,
     *   bank_account_hash:string,
     *   currency_code:string,
     *   variable_symbol:?string,
     *   specific_symbol:?string,
     *   constant_symbol:?string,
     *   valid_from:string,
     *   valid_to:?string,
     *   source_kind:string,
     *   source_reference:string,
     *   verified_on:string,
     *   verified_by:?int,
     *   row_version:int
     * } $account
     */
    private function assertVerifiedAccount(
        int $supplierId,
        string $dueOn,
        array $account,
    ): void {
        if (!in_array($account['source_kind'], [
            'official_registry',
            'official_document',
            'institution_notice',
            'user_verified',
        ], true)
            || $account['verified_by'] === null
            || $account['verified_by'] <= 0
            || $this->date(
                $account['verified_on'],
                'datum ověření účtu instituce',
            ) > $dueOn
            || $account['row_version'] <= 0
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                $account['bank_account_hash'],
            ) !== 1
            || $account['bank_account_hash'] === str_repeat('0', 64)
        ) {
            throw new \DomainException(
                'Účet instituce nemá úplné a účinné ověření.',
            );
        }
        $plaintext = $this->sensitiveData->reveal(
            $account['bank_account_ciphertext'],
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
            $account['id'],
            PayrollRevealPurpose::PAYMENT_LIABILITY_ACCOUNT,
        );
        $actualHash = bin2hex($this->sensitiveData->lookupHash(
            $plaintext,
            PayrollSensitiveField::BANK_ACCOUNT,
            $supplierId,
        ));
        if (!hash_equals($account['bank_account_hash'], $actualHash)) {
            throw new \DomainException(
                'Obsah účtu instituce neodpovídá uloženému otisku.',
            );
        }
    }

    /**
     * @param list<array{
     *   id:int,
     *   liability_reference:string,
     *   direction:string,
     *   recipient_reference:string,
     *   amount_minor:int,
     *   source_snapshot_json:string,
     *   source_snapshot_hash:string
     * }> $rows
     * @return array<string,array{
     *   recipient_reference:string,
     *   signed_minor:int,
     *   latest_id:int,
     *   target_snapshot:array<string,mixed>
     * }>
     */
    private function priorState(array $rows): array
    {
        $state = [];
        foreach ($rows as $row) {
            $source = $this->canonicalObject(
                $row['source_snapshot_json'],
                $row['source_snapshot_hash'],
            );
            if (($source['schema_reference'] ?? null)
                    !== 'payroll-payment-health-insurance-source.v1'
                || ($source['recipient_reference'] ?? null)
                    !== $row['recipient_reference']
            ) {
                throw new \DomainException(
                    'Dřívější zdravotní závazek nemá platný zdroj.',
                );
            }
            $reference = $row['liability_reference'];
            $signed = $row['direction'] === 'outgoing'
                ? $row['amount_minor']
                : -$row['amount_minor'];
            $snapshot = [];
            foreach ([
                'institution_type',
                'institution_code',
                'payment_target_id',
                'payment_target_hash',
                'payment_target_row_version',
                'payment_target_verification_hash',
                'variable_symbol',
                'specific_symbol',
                'constant_symbol',
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
                    'Řetězec zdravotního závazku změnil zmrazený cíl.',
                );
            }
            $state[$reference]['signed_minor'] = $this->add(
                $state[$reference]['signed_minor'],
                $signed,
            );
            $state[$reference]['latest_id'] = $row['id'];
        }
        foreach ($state as $item) {
            if ($item['signed_minor'] < 0) {
                throw new \DomainException(
                    'Dřívější zdravotní závazky mají záporný zůstatek.',
                );
            }
        }

        return $state;
    }

    /**
     * @param array{
     *   id:int,
     *   employee_id:?int,
     *   liability_kind:string,
     *   direction:string,
     *   recipient_reference:string,
     *   due_on:string,
     *   amount_minor:int,
     *   previous_liability_id:?int,
     *   source_snapshot_hash:string,
     *   idempotency_key_hash:string
     * } $existing
     */
    private function assertReplay(
        array $existing,
        string $direction,
        string $recipientReference,
        string $dueOn,
        int $amount,
        ?int $previousId,
        string $sourceHash,
        string $idempotencyHash,
    ): void {
        if ($existing['employee_id'] !== null
            || $existing['liability_kind'] !== 'health_insurance'
            || $existing['direction'] !== $direction
            || $existing['recipient_reference'] !== $recipientReference
            || $existing['due_on'] !== $dueOn
            || $existing['amount_minor'] !== $amount
            || $existing['previous_liability_id'] !== $previousId
            || !hash_equals(
                $existing['source_snapshot_hash'],
                $sourceHash,
            )
            || !hash_equals(
                $existing['idempotency_key_hash'],
                $idempotencyHash,
            )
        ) {
            throw new \DomainException(
                'Idempotentní replay zdravotního závazku nesouhlasí.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function canonicalObject(
        string $json,
        string $expectedHash,
    ): array {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \DomainException(
                'Zdroj zdravotního závazku není platný JSON.',
                previous: $exception,
            );
        }
        $object = $this->object($decoded, 'zdroj zdravotního závazku');
        if (!hash_equals(
            $expectedHash,
            hash('sha256', CanonicalJson::encode($object)),
        )) {
            throw new \DomainException(
                'Otisk zdroje zdravotního závazku nesouhlasí.',
            );
        }

        return $object;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("{$context} musí být objekt.");
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \DomainException(
                    "{$context} musí mít textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value, string $context): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException("{$context} musí být seznam.");
        }

        return array_map(
            fn (mixed $row): array => $this->object($row, $context),
            $value,
        );
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \DomainException("{$field} není neprázdný text.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function hash(array $row, string $field): string
    {
        $value = $this->string($row, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \DomainException("{$field} není SHA-256 otisk.");
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value, string $context): int
    {
        if (!is_int($value) || $value < 0) {
            throw new \DomainException(
                "{$context} musí být nezáporné celé číslo.",
            );
        }

        return $value;
    }

    private function date(mixed $value, string $context): string
    {
        if (!is_string($value)) {
            throw new \DomainException("{$context} není datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \DomainException("{$context} není platné datum.");
        }

        return $value;
    }

    private function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \OverflowException(
                'Součet zdravotních závazků přetekl.',
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
                'Rozdíl zdravotních závazků přetekl.',
            );
        }

        return $left - $right;
    }
}
