<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

final class PayrollIncomeTaxLiabilityMaterializer
{
    private const KINDS = [
        PayrollLevyDeadlinePolicy::ADVANCE_TAX => 'advance',
        PayrollLevyDeadlinePolicy::WITHHOLDING_TAX => 'withholding',
    ];

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
                'Firma a revize daňových závazků musí být kladná čísla.',
            );
        }
        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel materializace daňových závazků není platný.',
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
                'income_tax',
            );
            if ($statutory === null) {
                throw new \DomainException(
                    'Revize nemá neměnný výsledek daně z příjmů.',
                );
            }
            $root = $this->object(
                $statutory['result_snapshot'] ?? null,
                'výsledek daně z příjmů',
            );
            $rootHash = $this->hash($statutory, 'result_snapshot_hash');
            if (!hash_equals(
                $rootHash,
                hash('sha256', CanonicalJson::encode($root)),
            )) {
                throw new \DomainException(
                    'Otisk výsledku daně nesouhlasí.',
                );
            }
            if (($statutory['schema_version'] ?? null)
                    !== 'payroll-income-tax-result.v1'
                || ($statutory['result_status'] ?? null) !== 'calculated'
                || ($root['status'] ?? null) !== 'calculated'
                || !hash_equals(
                    $this->hash($revision, 'input_snapshot_hash'),
                    $this->hash($statutory, 'input_snapshot_hash'),
                )
            ) {
                throw new \DomainException(
                    'Daňové závazky vyžadují plně vypočtený výsledek '
                    . 'ze stejného zmrazeného vstupu bez ruční kontroly.',
                );
            }

            $totals = $this->validatedTotals($root, $statutory);
            $offset = $this->add(
                $totals['tax_bonus'],
                $this->annualSettlementTotal($supplierId, $revisionId),
            );
            $unappliedOffset = max(
                0,
                $this->subtract($offset, $totals['advance_tax']),
            );
            $totals['advance_tax'] = max(
                0,
                $this->subtract($totals['advance_tax'], $offset),
            );
            $periodStart = $this->date(
                $revision['period_start'],
                'období mzdového běhu',
            );
            $dueDates = [];
            foreach (array_keys(self::KINDS) as $kind) {
                $dueDates[$kind] = $this->deadlines->dueOn(
                    $kind,
                    $periodStart,
                );
            }

            $targets = [];
            foreach (self::KINDS as $kind => $referenceSuffix) {
                $amount = $totals[$kind];
                if ($amount === 0) {
                    continue;
                }
                $targets[$kind] = $this->paymentTarget(
                    $supplierId,
                    $kind,
                    $referenceSuffix,
                    $dueDates[$kind],
                    $amount,
                );
            }
            $prior = [];
            foreach (self::KINDS as $kind => $referenceSuffix) {
                $prior[$kind] = $this->priorState(
                    $this->liabilities->lockEarlierInstitutionalLiabilities(
                        $supplierId,
                        $revision['run_id'],
                        $revision['revision_no'],
                        $kind,
                    ),
                    $kind,
                    "payroll-tax:{$referenceSuffix}",
                );
            }
            if ($revision['revision_kind'] === 'regular'
                && ($prior['advance_tax'] !== null
                    || $prior['withholding_tax'] !== null)
            ) {
                throw new \DomainException(
                    'Další revize daňových závazků musí být opravná.',
                );
            }
            if ($revision['revision_kind'] === 'correction'
                && $revision['previous_revision_id'] === null
            ) {
                throw new \DomainException(
                    'Opravná revize nemá předchozí revizi.',
                );
            }

            $ids = [];
            $created = 0;
            foreach (self::KINDS as $kind => $referenceSuffix) {
                $target = $targets[$kind] ?? null;
                $previous = $prior[$kind];
                if ($target !== null && $previous !== null
                    && (
                        $target['recipient_reference']
                            !== $previous['recipient_reference']
                        || $target['target_snapshot']
                            !== $previous['target_snapshot']
                    )
                ) {
                    throw new \DomainException(
                        'Ověřený účet finančního úřadu se proti předchozímu '
                        . 'závazku změnil. Nejprve uzavřete původní korekční řetězec.',
                    );
                }
                $targetAmount = $target['amount_minor'] ?? 0;
                $priorSigned = $previous['signed_minor'] ?? 0;
                $delta = $this->subtract($targetAmount, $priorSigned);
                if ($delta === 0) {
                    continue;
                }
                if ($delta === PHP_INT_MIN) {
                    throw new \OverflowException('Daňový závazek přetekl.');
                }
                $direction = $delta > 0 ? 'outgoing' : 'incoming';
                $amount = abs($delta);
                $recipientReference = $target['recipient_reference']
                    ?? $previous['recipient_reference']
                    ?? throw new \LogicException('Chybí příjemce závazku.');
                $targetSnapshot = $target['target_snapshot']
                    ?? $previous['target_snapshot']
                    ?? throw new \LogicException('Chybí snapshot příjemce.');
                $liabilityReference = "payroll-tax:{$referenceSuffix}";
                $source = [
                    'schema_reference' =>
                        'payroll-payment-income-tax-source.v1',
                    'run_id' => $revision['run_id'],
                    'revision_id' => $revisionId,
                    'revision_no' => $revision['revision_no'],
                    'statutory_result_hash' => $rootHash,
                    'statutory_people_hash' => $totals['people_hash'],
                    'liability_kind' => $kind,
                    'logical_reference' => $liabilityReference,
                    'recipient_reference' => $recipientReference,
                    'due_on' => $dueDates[$kind],
                    ...$targetSnapshot,
                    'target_amount_minor' => $targetAmount,
                    'prior_signed_minor' => $priorSigned,
                    'delta_signed_minor' => $delta,
                    // § 35d odst. 5 a 9, § 38ch odst. 5: o co se odvod záloh
                    // snížil a co se z toho do nuly nevešlo. Nevešlý zbytek si
                    // plátce podle týchž ustanovení buď odečte v dalších
                    // měsících, nebo o něj požádá správce daně — obojí je jeho
                    // úkon, takže se tady jen pojmenuje, nedomýšlí.
                    ...($kind === PayrollLevyDeadlinePolicy::ADVANCE_TAX
                        ? [
                            'advance_tax_offset_minor' => $offset,
                            'advance_tax_offset_unapplied_minor' => $unappliedOffset,
                        ]
                        : []),
                ];
                $sourceJson = CanonicalJson::encode($source);
                $sourceHash = hash('sha256', $sourceJson);
                $idempotencyHash = hash(
                    'sha256',
                    CanonicalJson::encode([
                        'schema_reference' =>
                            'payroll-payment-income-tax-idempotency.v1',
                        'supplier_id' => $supplierId,
                        'revision_id' => $revisionId,
                        'liability_kind' => $kind,
                        'source_snapshot_hash' => $sourceHash,
                    ]),
                    true,
                );
                $previousId = $previous['latest_id'] ?? null;
                $existing = $this->liabilities->findAnyForUpdate(
                    $supplierId,
                    $revisionId,
                    $liabilityReference,
                );
                if ($existing !== null) {
                    $this->assertReplay(
                        $existing,
                        $kind,
                        $direction,
                        $recipientReference,
                        $dueDates[$kind],
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
                    $liabilityReference,
                    $kind,
                    $direction,
                    $recipientReference,
                    $dueDates[$kind],
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
     * Doplatky ze zúčtování vyplacené v téhle revizi (§ 35d odst. 8).
     *
     * Berou se ze zmrazeného výsledku čisté mzdy, ne z evidence ročního
     * zúčtování — odvod se musí opírat o totéž číslo, které skutečně odešlo
     * zaměstnanci, jinak by se výplata a odvod mohly rozejít.
     */
    private function annualSettlementTotal(int $supplierId, int $revisionId): int
    {
        $statutory = $this->statutoryResults->find(
            $supplierId,
            $revisionId,
            'net_pay',
        );
        if ($statutory === null) {
            throw new \DomainException(
                'Revize nemá neměnný výsledek čisté mzdy.',
            );
        }
        if (($statutory['schema_version'] ?? null) !== 'payroll-net-result.v1'
            || ($statutory['result_status'] ?? null) !== 'calculated'
        ) {
            throw new \DomainException(
                'Daňové závazky vyžadují plně vypočtenou čistou mzdu.',
            );
        }
        $people = $statutory['people'] ?? null;
        if (!is_array($people) || !array_is_list($people) || $people === []) {
            throw new \DomainException(
                'Výsledek čisté mzdy neobsahuje neměnné výsledky osob.',
            );
        }
        $total = 0;
        foreach ($people as $index => $value) {
            $person = $this->object($value, "čistá mzda {$index}");
            $result = $this->object(
                $person['result_snapshot'] ?? null,
                "čistá mzda {$index}.result_snapshot",
            );
            if (!hash_equals(
                $this->hash($person, 'result_snapshot_hash'),
                hash('sha256', CanonicalJson::encode($result)),
            )) {
                throw new \DomainException(
                    'Otisk výsledku čisté mzdy osoby nesouhlasí.',
                );
            }
            $total = $this->add(
                $total,
                $this->nonNegativeInt(
                    $result['annual_settlement_minor_units'] ?? 0,
                    "doplatek ze zúčtování {$index}",
                ),
            );
        }

        return $total;
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
        if (($revision['schema_version'] ?? null)
            !== 'payroll-run-input.v2'
        ) {
            throw new \DomainException(
                'Daňové závazky vyžadují vstup payroll-run-input.v2.',
            );
        }
    }

    /**
     * @param array<string,mixed> $root
     * @param array<string,mixed> $statutory
     * @return array{
     *   advance_tax:int,
     *   withholding_tax:int,
     *   tax_bonus:int,
     *   people_hash:string
     * }
     */
    private function validatedTotals(array $root, array $statutory): array
    {
        $expectedPeople = [];
        $rootPeople = $root['people'] ?? null;
        if (!is_array($rootPeople) || !array_is_list($rootPeople)) {
            throw new \DomainException(
                'Kořenové osoby daně musí být seznam referencí.',
            );
        }
        foreach ($rootPeople as $reference) {
            if (!is_string($reference)
                || preg_match('/^employee:[1-9][0-9]*$/D', $reference) !== 1
                || isset($expectedPeople[$reference])
            ) {
                throw new \DomainException(
                    'Kořenový seznam osob daně není platný.',
                );
            }
            $expectedPeople[$reference] = true;
        }

        $advance = 0;
        $withholding = 0;
        $bonus = 0;
        $actualPeople = [];
        $personHashes = [];
        $people = $statutory['people'] ?? null;
        if (!is_array($people) || !array_is_list($people) || $people === []) {
            throw new \DomainException(
                'Výsledek daně neobsahuje neměnné výsledky osob.',
            );
        }
        foreach ($people as $index => $value) {
            $person = $this->object($value, "výsledek osoby {$index}");
            if (($person['result_status'] ?? null) !== 'calculated') {
                throw new \DomainException(
                    'Daňový výsledek osoby vyžaduje ruční kontrolu.',
                );
            }
            $employeeId = $this->positiveInt(
                $person['employee_id'] ?? null,
                "výsledek osoby {$index}.employee_id",
            );
            $reference = "employee:{$employeeId}";
            if (isset($actualPeople[$reference])) {
                throw new \DomainException(
                    'Daňový výsledek obsahuje osobu vícekrát.',
                );
            }
            $result = $this->object(
                $person['result_snapshot'] ?? null,
                "výsledek osoby {$reference}",
            );
            $resultHash = $this->hash($person, 'result_snapshot_hash');
            if (!hash_equals(
                $resultHash,
                hash('sha256', CanonicalJson::encode($result)),
            )) {
                throw new \DomainException(
                    'Otisk daňového výsledku osoby nesouhlasí.',
                );
            }
            if (($result['status'] ?? null) !== 'calculated'
                || ($result['employee_reference'] ?? null) !== $reference
            ) {
                throw new \DomainException(
                    'Daňový výsledek osoby nemá platnou identitu nebo stav.',
                );
            }
            $this->date(
                $result['calculation_date'] ?? null,
                "datum daňového výpočtu {$reference}",
            );
            $advanceResult = $result['advance_tax'] ?? null;
            if ($advanceResult !== null) {
                $advanceObject = $this->object(
                    $advanceResult,
                    "zálohová daň {$reference}",
                );
                $advance = $this->add(
                    $advance,
                    $this->nonNegativeInt(
                        $advanceObject['tax_after_credits_minor_units'] ?? null,
                        "zálohová daň {$reference}",
                    ),
                );
                $bonus = $this->add(
                    $bonus,
                    $this->nonNegativeInt(
                        $advanceObject['tax_bonus_minor_units'] ?? null,
                        "daňový bonus {$reference}",
                    ),
                );
            }
            $withholding = $this->add(
                $withholding,
                $this->nonNegativeInt(
                    $result['withholding_tax_minor_units'] ?? null,
                    "srážková daň {$reference}",
                ),
            );
            $actualPeople[$reference] = true;
            $personHashes[$reference] = $resultHash;
        }
        ksort($expectedPeople, SORT_STRING);
        ksort($actualPeople, SORT_STRING);
        ksort($personHashes, SORT_STRING);
        if (array_keys($expectedPeople) !== array_keys($actualPeople)
            || $advance !== $this->nonNegativeInt(
                $root['advance_tax_minor_units'] ?? null,
                'kořenová zálohová daň',
            )
            || $withholding !== $this->nonNegativeInt(
                $root['withholding_tax_minor_units'] ?? null,
                'kořenová srážková daň',
            )
            || $bonus !== $this->nonNegativeInt(
                $root['tax_bonus_minor_units'] ?? null,
                'kořenový daňový bonus',
            )
        ) {
            throw new \DomainException(
                'Součet daňových výsledků osob neodpovídá kořenovému výsledku.',
            );
        }

        return [
            'advance_tax' => $advance,
            'withholding_tax' => $withholding,
            'tax_bonus' => $bonus,
            'people_hash' => hash(
                'sha256',
                CanonicalJson::encode($personHashes),
            ),
        ];
    }

    /**
     * @return array{
     *   recipient_reference:string,
     *   amount_minor:int,
     *   target_snapshot:array<string,mixed>
     * }
     */
    private function paymentTarget(
        int $supplierId,
        string $kind,
        string $referenceSuffix,
        string $dueOn,
        int $amount,
    ): array {
        $accounts = $this->institutions->lockEffectivePaymentTargets(
            $supplierId,
            'tax_office',
            $kind,
            'CZK',
            $dueOn,
        );
        if (count($accounts) !== 1) {
            throw new \DomainException(
                count($accounts) === 0
                    ? "Finanční úřad pro {$kind} nemá k datu splatnosti ověřený účet."
                    : "Finanční úřad pro {$kind} má k datu splatnosti nejednoznačný účet.",
            );
        }
        $account = $accounts[0];
        $this->assertVerifiedAccount($supplierId, $dueOn, $account);
        $this->assertSymbols($account);
        $verificationHash = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-institution-payment-target-verification.v1',
                'institution_type' => 'tax_office',
                'institution_code' => $kind,
                'payment_target_id' => $account['id'],
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
        $accountId = $account['id'];

        return [
            'recipient_reference' =>
                "institution:tax_office:{$referenceSuffix}:account:{$accountId}",
            'amount_minor' => $amount,
            'target_snapshot' => [
                'institution_type' => 'tax_office',
                'institution_code' => $kind,
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
        if ($account['institution_type'] !== 'tax_office'
            || !in_array($account['source_kind'], [
                'official_registry',
                'official_document',
                'institution_notice',
                'user_verified',
            ], true)
            || $account['verified_by'] === null
            || $account['verified_by'] <= 0
            || $this->date(
                $account['verified_on'],
                'datum ověření účtu finančního úřadu',
            ) > $dueOn
            || $account['row_version'] <= 0
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                $account['bank_account_hash'],
            ) !== 1
            || $account['bank_account_hash'] === str_repeat('0', 64)
        ) {
            throw new \DomainException(
                'Účet finančního úřadu nemá úplné a účinné ověření.',
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
                'Obsah účtu finančního úřadu neodpovídá uloženému otisku.',
            );
        }
    }

    /**
     * @param array{
     *   variable_symbol:?string,
     *   specific_symbol:?string,
     *   constant_symbol:?string
     * } $account
     */
    private function assertSymbols(array $account): void
    {
        if ($account['variable_symbol'] === null
            || preg_match(
                '/^[0-9]{1,10}$/D',
                $account['variable_symbol'],
            ) !== 1
            || ($account['specific_symbol'] !== null
                && preg_match(
                    '/^[0-9]{1,10}$/D',
                    $account['specific_symbol'],
                ) !== 1)
            || ($account['constant_symbol'] !== null
                && preg_match(
                    '/^[0-9]{4}$/D',
                    $account['constant_symbol'],
                ) !== 1)
        ) {
            throw new \DomainException(
                'Platební symboly finančního úřadu nejsou úplné nebo platné.',
            );
        }
    }

    /**
     * @param list<array{
     *   id:int,
     *   revision_no:int,
     *   liability_reference:string,
     *   direction:string,
     *   recipient_reference:string,
     *   amount_minor:int,
     *   source_snapshot_json:string,
     *   source_snapshot_hash:string
     * }> $rows
     * @return array{
     *   recipient_reference:string,
     *   signed_minor:int,
     *   latest_id:int,
     *   target_snapshot:array<string,mixed>
     * }|null
     */
    private function priorState(
        array $rows,
        string $kind,
        string $expectedReference,
    ): ?array {
        $state = null;
        foreach ($rows as $row) {
            if ($row['liability_reference'] !== $expectedReference) {
                throw new \DomainException(
                    'Dřívější daňový závazek má neplatnou logickou referenci.',
                );
            }
            $source = $this->canonicalObject(
                $row['source_snapshot_json'],
                $row['source_snapshot_hash'],
            );
            if (($source['schema_reference'] ?? null)
                    !== 'payroll-payment-income-tax-source.v1'
                || ($source['liability_kind'] ?? null) !== $kind
                || ($source['logical_reference'] ?? null)
                    !== $expectedReference
                || ($source['recipient_reference'] ?? null)
                    !== $row['recipient_reference']
            ) {
                throw new \DomainException(
                    'Dřívější daňový závazek nemá platný zdroj.',
                );
            }
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
            if ($state === null) {
                $state = [
                    'recipient_reference' => $row['recipient_reference'],
                    'signed_minor' => 0,
                    'latest_id' => $row['id'],
                    'target_snapshot' => $snapshot,
                ];
            } elseif ($state['recipient_reference']
                    !== $row['recipient_reference']
                || $state['target_snapshot'] !== $snapshot
            ) {
                throw new \DomainException(
                    'Řetězec daňového závazku změnil zmrazený cíl.',
                );
            }
            $signed = $row['direction'] === 'outgoing'
                ? $row['amount_minor']
                : -$row['amount_minor'];
            $state['signed_minor'] = $this->add(
                $state['signed_minor'],
                $signed,
            );
            $state['latest_id'] = $row['id'];
        }
        if ($state !== null && $state['signed_minor'] < 0) {
            throw new \DomainException(
                'Dřívější daňové závazky mají záporný zůstatek.',
            );
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
        string $kind,
        string $direction,
        string $recipientReference,
        string $dueOn,
        int $amount,
        ?int $previousId,
        string $sourceHash,
        string $idempotencyHash,
    ): void {
        if ($existing['employee_id'] !== null
            || $existing['liability_kind'] !== $kind
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
                'Daňový závazek má rozporný idempotentní replay.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function canonicalObject(string $json, string $hash): array
    {
        if (!hash_equals($hash, hash('sha256', $json))) {
            throw new \DomainException(
                'Otisk dřívějšího daňového závazku nesouhlasí.',
            );
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $object = $this->object($decoded, 'zdroj dřívějšího daňového závazku');
        if (CanonicalJson::encode($object) !== $json) {
            throw new \DomainException(
                'Zdroj dřívějšího daňového závazku není kanonický.',
            );
        }

        return $object;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "{$field} musí mít textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function hash(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)
            || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1
        ) {
            throw new \UnexpectedValueException(
                "{$field} musí být SHA-256 hash.",
            );
        }

        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "{$field} musí být datum.",
            );
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \UnexpectedValueException(
                "{$field} musí být platné datum.",
            );
        }

        return $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \UnexpectedValueException(
                "{$field} musí být kladné celé číslo.",
            );
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0) {
            throw new \UnexpectedValueException(
                "{$field} musí být nezáporné celé číslo.",
            );
        }

        return $value;
    }

    private function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \OverflowException('Součet daňových závazků přetekl.');
        }

        return $left + $right;
    }

    private function subtract(int $left, int $right): int
    {
        if (($right > 0 && $left < PHP_INT_MIN + $right)
            || ($right < 0 && $left > PHP_INT_MAX + $right)
        ) {
            throw new \OverflowException('Rozdíl daňových závazků přetekl.');
        }

        return $left - $right;
    }
}
