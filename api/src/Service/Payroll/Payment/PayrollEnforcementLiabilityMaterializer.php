<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollEnforcementPaymentRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

/**
 * MZ-14-W08 — poslední článek exekučních srážek: sražené peníze se skutečně
 * odešlou příjemci přes platební vrstvu MZ-17.
 *
 * Cílová částka vzniká výhradně z neměnného exekučního ledgeru schválené revize
 * jako `withheld` mínus `held`. Depozitum, odklad i zastavení proto z definice
 * nemohou skončit v odchozí dávce — nikoli díky podmínce v kódu, ale protože
 * pro ně ledger vede protipoložku `held`. Stav případu se navíc kontroluje
 * podruhé (fail-closed) pro případ, že se případ po schválení mzdy odložil.
 *
 * Vzor je záměrně shodný s institucionálními materializátory (ZP/SP/daň):
 * zmrazený ověřený účet z katalogu MZ-03-W03, kanonický zdrojový snapshot,
 * idempotentní replay a rozdílová oprava se zápornou reverzí.
 */
final class PayrollEnforcementLiabilityMaterializer
{
    private const SOURCE_SCHEMA = 'payroll-payment-enforcement-source.v1';
    private const INSTITUTION_TYPE = 'other_recipient';

    public function __construct(
        private readonly PayrollPaymentLiabilityRepository $liabilities,
        private readonly PayrollEnforcementPaymentRepository $enforcement,
        private readonly PayrollInstitutionAccountRepository $institutions,
        private readonly PayrollSensitiveData $sensitiveData,
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
                'Firma a revize exekučních závazků musí být kladná čísla.',
            );
        }
        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel materializace exekučních závazků není platný.',
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
            $dueOn = $this->date(
                $revision['payment_date'],
                'datum výplaty mzdového běhu',
            );

            $targets = $this->currentTargets(
                $supplierId,
                $revisionId,
                $dueOn,
            );
            $prior = $this->priorState(
                $this->liabilities->lockEarlierInstitutionalLiabilities(
                    $supplierId,
                    $revision['run_id'],
                    $revision['revision_no'],
                    PayrollEnforcementPaymentRepository::LIABILITY_KIND,
                ),
            );
            if ($revision['revision_kind'] === 'regular' && $prior !== []) {
                throw new \DomainException(
                    'Další revize exekučních závazků musí být opravná.',
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
            usort(
                $references,
                fn (string $left, string $right): int => $this->compare(
                    $targets[$left] ?? null,
                    $left,
                    $targets[$right] ?? null,
                    $right,
                ),
            );
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
                        'Ověřený účet příjemce srážky se proti předchozímu závazku '
                        . 'změnil. Nejprve uzavřete původní korekční řetězec.',
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
                    ?? throw new \LogicException('Chybí příjemce závazku.');
                $targetSnapshot = $target['target_snapshot']
                    ?? $previous['target_snapshot']
                    ?? throw new \LogicException('Chybí snapshot příjemce.');
                $releaseEvidence = $target === null
                    ? ($previous['release_evidence'] ?? self::emptyReleaseEvidence())
                    : $target['release_evidence'];
                $source = [
                    'schema_reference' => self::SOURCE_SCHEMA,
                    'run_id' => $revision['run_id'],
                    'revision_id' => $revisionId,
                    'revision_no' => $revision['revision_no'],
                    'logical_reference' => $reference,
                    'recipient_reference' => $recipientReference,
                    'liability_kind' =>
                        PayrollEnforcementPaymentRepository::LIABILITY_KIND,
                    ...$targetSnapshot,
                    ...$releaseEvidence,
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
                            'payroll-payment-enforcement-idempotency.v1',
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
                    PayrollEnforcementPaymentRepository::LIABILITY_KIND,
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
     * @return array<string,array{
     *   recipient_reference:string,
     *   amount_minor:int,
     *   sort_key:array{int,string,int},
     *   release_evidence:array<string,mixed>,
     *   target_snapshot:array<string,mixed>
     * }>
     */
    private function currentTargets(
        int $supplierId,
        int $revisionId,
        string $dueOn,
    ): array {
        $targets = [];
        foreach ($this->enforcement->remittableForRevision(
            $supplierId,
            $revisionId,
        ) as $row) {
            $caseId = $row['case_id'];
            $claimId = $row['claim_id'];
            $remittable = $row['remittable_minor'];
            if ($remittable < 0) {
                throw new \DomainException(
                    "Exekuční ledger případu {$caseId} vede vyšší depozitum "
                    . 'než sraženou částku.',
                );
            }
            if ($remittable === 0) {
                continue;
            }
            if ($row['case_status'] !== 'remit') {
                throw new \DomainException(
                    "Případ {$caseId} není ve stavu odesílání; sražená částka "
                    . 'zůstává v depozitu a nelze ji zařadit do platební dávky.',
                );
            }
            if (!$row['recipient_verified']) {
                throw new \DomainException(
                    "Případ {$caseId} nemá ověřeného příjemce srážky.",
                );
            }
            $instruction = $this->enforcement->documentedRecipientForPayment(
                $supplierId,
                $caseId,
                $dueOn,
            );
            if ($instruction === null) {
                throw new \DomainException(
                    "Případ {$caseId} nemá doloženou instrukci příjemce; "
                    . 'historický případ vyžaduje explicitní backfill.',
                );
            }
            if (!$instruction['authority_current']
                || !$instruction['beneficiary_current']
                || !$instruction['recipient_party_current']
                || $instruction['institution_type'] !== self::INSTITUTION_TYPE) {
                throw new \DomainException(
                    "Případ {$caseId} nemá aktuální doloženou právní stranu "
                    . 'pro příjemce platby.',
                );
            }
            $institutionCode = $instruction['institution_code'];
            if (preg_match(
                '/^[A-Z0-9][A-Z0-9._-]{0,31}$/D',
                $institutionCode,
            ) !== 1) {
                throw new \DomainException(
                    "Kód příjemce srážky u případu {$caseId} obsahuje znaky, "
                    . 'které nelze bezpečně použít v platební referenci.',
                );
            }
            $accounts = $this->institutions->lockEffectivePaymentTargets(
                $supplierId,
                self::INSTITUTION_TYPE,
                $institutionCode,
                'CZK',
                $dueOn,
            );
            if (count($accounts) !== 1) {
                throw new \DomainException(
                    count($accounts) === 0
                        ? "Příjemce srážky případu {$caseId} nemá k datu výplaty "
                            . 'ověřený účet.'
                        : "Příjemce srážky případu {$caseId} má k datu výplaty "
                            . 'nejednoznačný účet.',
                );
            }
            $account = $accounts[0];
            if ($account['id'] !== $instruction['payment_account_id']) {
                throw new \DomainException(
                    "Doložený účet příjemce případu {$caseId} není k datu "
                    . 'výplaty aktuálním jednoznačným účtem katalogu.',
                );
            }
            $this->assertVerifiedAccount($supplierId, $dueOn, $account);
            $accountId = $account['id'];
            $verificationHash = hash(
                'sha256',
                CanonicalJson::encode([
                    'schema_reference' =>
                        'payroll-institution-payment-target-verification.v1',
                    'institution_type' => self::INSTITUTION_TYPE,
                    'institution_code' => $institutionCode,
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
            $reference = PayrollEnforcementPaymentRepository::liabilityReference(
                $caseId,
                $claimId,
            );
            if (isset($targets[$reference])) {
                throw new \DomainException(
                    'Exekuční ledger obsahuje pohledávku vícekrát.',
                );
            }
            $targets[$reference] = [
                'recipient_reference' =>
                    'institution:' . self::INSTITUTION_TYPE
                    . ":{$institutionCode}:account:{$accountId}",
                'amount_minor' => $remittable,
                'sort_key' => [
                    ClaimCategory::from((string) $row['claim_category'])
                        ->paymentPriorityRank(),
                    $row['claim_priority_date'] ?? '9999-12-31',
                    $claimId,
                ],
                'release_evidence' => [
                    'release_decision_event_id' =>
                        $row['release_decision_event_id'],
                    'release_decision_document_id' =>
                        $row['release_decision_document_id'],
                    'release_decision_evidence_hash' =>
                        $row['release_decision_evidence_hash'],
                ],
                'target_snapshot' => [
                    'case_id' => $caseId,
                    'claim_id' => $claimId,
                    'claim_category' => $row['claim_category'],
                    'recipient_party_id' => $instruction['recipient_party_id'],
                    'recipient_instruction_document_id' => $instruction['source_document_id'],
                    'recipient_instruction_document_sha256' => $instruction['source_document_sha256'],
                    'institution_type' => self::INSTITUTION_TYPE,
                    'institution_code' => $institutionCode,
                    'payment_target_id' => $accountId,
                    'payment_target_hash' => $account['bank_account_hash'],
                    'payment_target_row_version' => $account['row_version'],
                    'payment_target_verification_hash' => $verificationHash,
                    'variable_symbol' => $account['variable_symbol'],
                    'specific_symbol' => $account['specific_symbol'],
                    'constant_symbol' => $account['constant_symbol'],
                ],
            ];
        }

        return $targets;
    }

    /**
     * @param array{
     *   recipient_reference:string,
     *   amount_minor:int,
     *   sort_key:array{int,string,int},
     *   release_evidence:array<string,mixed>,
     *   target_snapshot:array<string,mixed>
     * }|null $left
     * @param array{
     *   recipient_reference:string,
     *   amount_minor:int,
     *   sort_key:array{int,string,int},
     *   release_evidence:array<string,mixed>,
     *   target_snapshot:array<string,mixed>
     * }|null $right
     */
    private function compare(
        ?array $left,
        string $leftReference,
        ?array $right,
        string $rightReference,
    ): int {
        $leftKey = $left['sort_key'] ?? [9, '9999-12-31', PHP_INT_MAX];
        $rightKey = $right['sort_key'] ?? [9, '9999-12-31', PHP_INT_MAX];

        return [$leftKey, $leftReference] <=> [$rightKey, $rightReference];
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
                'datum ověření účtu příjemce srážky',
            ) > $dueOn
            || $account['row_version'] <= 0
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                $account['bank_account_hash'],
            ) !== 1
            || $account['bank_account_hash'] === str_repeat('0', 64)
        ) {
            throw new \DomainException(
                'Účet příjemce srážky nemá úplné a účinné ověření.',
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
                'Obsah účtu příjemce srážky neodpovídá uloženému otisku.',
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
     *   release_evidence:array<string,mixed>,
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
            if (($source['schema_reference'] ?? null) !== self::SOURCE_SCHEMA
                || ($source['recipient_reference'] ?? null)
                    !== $row['recipient_reference']
            ) {
                throw new \DomainException(
                    'Dřívější exekuční závazek nemá platný zdroj.',
                );
            }
            $reference = $row['liability_reference'];
            $signed = $row['direction'] === 'outgoing'
                ? $row['amount_minor']
                : -$row['amount_minor'];
            $snapshot = [];
            foreach ([
                'case_id',
                'claim_id',
                'claim_category',
                'recipient_party_id',
                'recipient_instruction_document_id',
                'recipient_instruction_document_sha256',
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
                    'release_evidence' => [
                        'release_decision_event_id' =>
                            $source['release_decision_event_id'] ?? null,
                        'release_decision_document_id' =>
                            $source['release_decision_document_id'] ?? null,
                        'release_decision_evidence_hash' =>
                            $source['release_decision_evidence_hash'] ?? null,
                    ],
                    'target_snapshot' => $snapshot,
                ];
            } elseif ($state[$reference]['recipient_reference']
                    !== $row['recipient_reference']
                || $state[$reference]['target_snapshot'] !== $snapshot
            ) {
                throw new \DomainException(
                    'Řetězec exekučního závazku změnil zmrazený cíl.',
                );
            }
            $state[$reference]['signed_minor'] = $this->add(
                $state[$reference]['signed_minor'],
                $signed,
            );
            $state[$reference]['latest_id'] = $row['id'];
            $state[$reference]['release_evidence'] = [
                'release_decision_event_id' =>
                    $source['release_decision_event_id'] ?? null,
                'release_decision_document_id' =>
                    $source['release_decision_document_id'] ?? null,
                'release_decision_evidence_hash' =>
                    $source['release_decision_evidence_hash'] ?? null,
            ];
        }
        foreach ($state as $item) {
            if ($item['signed_minor'] < 0) {
                throw new \DomainException(
                    'Dřívější exekuční závazky mají záporný zůstatek.',
                );
            }
        }

        return $state;
    }

    /** @return array<string,null> */
    private static function emptyReleaseEvidence(): array
    {
        return [
            'release_decision_event_id' => null,
            'release_decision_document_id' => null,
            'release_decision_evidence_hash' => null,
        ];
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
            || $existing['liability_kind']
                !== PayrollEnforcementPaymentRepository::LIABILITY_KIND
            || $existing['direction'] !== $direction
            || $existing['recipient_reference'] !== $recipientReference
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
                'Idempotentní replay exekučního závazku nesouhlasí.',
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
                'Zdroj exekučního závazku není platný JSON.',
                previous: $exception,
            );
        }
        $object = $this->object($decoded, 'zdroj exekučního závazku');
        if (!hash_equals(
            $expectedHash,
            hash('sha256', CanonicalJson::encode($object)),
        )) {
            throw new \DomainException(
                'Otisk zdroje exekučního závazku nesouhlasí.',
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
                'Součet exekučních závazků přetekl.',
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
                'Rozdíl exekučních závazků přetekl.',
            );
        }

        return $left - $right;
    }
}
