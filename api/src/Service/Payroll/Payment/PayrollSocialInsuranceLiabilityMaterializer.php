<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

final class PayrollSocialInsuranceLiabilityMaterializer
{
    public function __construct(
        private readonly PayrollPaymentLiabilityRepository $liabilities,
        private readonly PayrollStatutoryResultRepository $statutoryResults,
        private readonly PayrollInstitutionAccountRepository $institutions,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly Connection $db,
        private readonly PayrollLevyDeadlinePolicy $deadlines,
        private readonly PayrollSocialOfficeAllocator $officeAllocator,
    ) {}

    /** @return array{liability_ids:list<int>,created_count:int} */
    public function materialize(
        int $supplierId,
        int $revisionId,
        ?int $actorUserId = null,
    ): array {
        if ($supplierId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a revize sociálního závazku musí být kladná čísla.',
            );
        }
        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel materializace sociálního závazku není platný.',
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
            $input = $this->canonicalObject(
                $revision['input_snapshot_json'],
                $revision['input_snapshot_hash'],
                'vstupního snapshotu revize',
            );
            if (($input['schema_version'] ?? null) !== 'payroll-run-input.v2'
                || ($input['supplier_id'] ?? null) !== $supplierId
                || ($input['period_start'] ?? null)
                    !== $revision['period_start']
            ) {
                throw new \DomainException(
                    'Zmrazený vstup neodpovídá firmě a období revize.',
                );
            }
            /*
             * `office_id` v KOŘENI zmrazeného vstupu je filtr rozsahu běhu, ne
             * účtárna odvodu — u celofiremního běhu je legitimně `null`. Účtárna
             * se bere z pracovního vztahu, viz {@see PayrollSocialOfficeAllocator}.
             * Dokud se četla odsud, celofiremní běh sociální závazek vůbec
             * nezmaterializoval.
             */
            $scopeOfficeId = $input['office_id'] ?? null;
            if ($scopeOfficeId !== null
                && (!is_int($scopeOfficeId) || $scopeOfficeId <= 0)
            ) {
                throw new \DomainException(
                    'Zmrazený filtr mzdové účtárny není platný.',
                );
            }
            $statutory = $this->statutoryResults->find(
                $supplierId,
                $revisionId,
                'social_insurance',
            );
            if ($statutory === null) {
                throw new \DomainException(
                    'Revize nemá neměnný výsledek sociálního pojištění.',
                );
            }
            if (($statutory['schema_version'] ?? null)
                    !== 'payroll-social-result.v1'
                || ($statutory['result_status'] ?? null) !== 'calculated'
                || !hash_equals(
                    $revision['input_snapshot_hash'],
                    $this->hash($statutory, 'input_snapshot_hash'),
                )
            ) {
                throw new \DomainException(
                    'Sociální závazek vyžaduje vypočtený výsledek ze stejného zmrazeného vstupu.',
                );
            }
            $root = $this->object(
                $statutory['result_snapshot'] ?? null,
                'výsledek sociálního pojištění',
            );
            $rootHash = $this->hash($statutory, 'result_snapshot_hash');
            if (($root['status'] ?? null) !== 'calculated'
                || !hash_equals(
                    $rootHash,
                    hash('sha256', CanonicalJson::encode($root)),
                )
            ) {
                throw new \DomainException(
                    'Výsledek sociálního pojištění není úplný nebo má chybný otisk.',
                );
            }
            $calculationDate = $this->date(
                $root['calculation_date'] ?? null,
                'datum výpočtu sociálního pojištění',
            );
            if (substr($calculationDate, 0, 7)
                !== substr($revision['period_start'], 0, 7)
            ) {
                throw new \DomainException(
                    'Výpočet sociálního pojištění patří jinému období.',
                );
            }
            $employee = $this->nonNegativeInt(
                $root['employee_contribution_minor_units'] ?? null,
                'odvod zaměstnanců',
            );
            $employer = $this->nonNegativeInt(
                $root['employer_contribution_minor_units'] ?? null,
                'odvod zaměstnavatele',
            );
            $employerBeforeDiscount = $this->nonNegativeInt(
                $root[
                    'employer_contribution_before_discount_minor_units'
                ] ?? null,
                'odvod zaměstnavatele před slevou',
            );
            $partTimeDiscount = $this->nonNegativeInt(
                $root['part_time_discount_minor_units'] ?? null,
                'sleva zaměstnavatele',
            );
            if ($partTimeDiscount > $employerBeforeDiscount
                || $employer !== $employerBeforeDiscount
                    - $partTimeDiscount
            ) {
                throw new \DomainException(
                    'Kořenový odvod zaměstnavatele neodpovídá odvodu před slevou.',
                );
            }
            $personSum = 0;
            $people = $this->rows(
                $statutory['people'] ?? null,
                'výsledky osob sociálního pojištění',
            );
            foreach ($people as $person) {
                if (($person['result_status'] ?? null) !== 'calculated') {
                    throw new \DomainException(
                        'Sociální výsledek obsahuje osobu k ruční kontrole.',
                    );
                }
                $personResult = $this->object(
                    $person['result_snapshot'] ?? null,
                    'výsledek osoby sociálního pojištění',
                );
                $personHash = $this->hash(
                    $person,
                    'result_snapshot_hash',
                );
                if (!hash_equals(
                    $personHash,
                    hash(
                        'sha256',
                        CanonicalJson::encode($personResult),
                    ),
                )) {
                    throw new \DomainException(
                        'Otisk výsledku osoby sociálního pojištění nesouhlasí.',
                    );
                }
                $personSum = $this->add(
                    $personSum,
                    $this->nonNegativeInt(
                        $personResult[
                            'employee_contribution_minor_units'
                        ] ?? null,
                        'odvod osoby',
                    ),
                );
                /*
                 * Vyměřovací základ vztahu je VÁHOU rozpadu na účtárny, takže
                 * musí projít stejným ověřením otisku jako částky nad ním —
                 * jinak by se odvod dělil podle čísla, které nikdo nepodepsal.
                 */
                foreach ($this->rows(
                    $person['relationships'] ?? null,
                    'vztahy osoby sociálního pojištění',
                ) as $relationship) {
                    $relationshipResult = $this->object(
                        $relationship['result_snapshot'] ?? null,
                        'výsledek vztahu sociálního pojištění',
                    );
                    if (!hash_equals(
                        $this->hash($relationship, 'result_snapshot_hash'),
                        hash(
                            'sha256',
                            CanonicalJson::encode($relationshipResult),
                        ),
                    )) {
                        throw new \DomainException(
                            'Otisk výsledku vztahu sociálního pojištění nesouhlasí.',
                        );
                    }
                }
            }
            if ($personSum !== $employee) {
                throw new \DomainException(
                    'Kořenový odvod zaměstnanců neodpovídá součtu osob.',
                );
            }
            $targetAmount = $this->add($employee, $employer);
            $dueOn = $this->statutoryDueOn(
                $revision['period_start'],
            );
            /*
             * Rozpad na mzdové účtárny. Zúžený běh má jedinou účtárnu, takže
             * celá částka padne na ni a výsledek je shodný s dosavadním
             * chováním; celofiremní běh dá tolik závazků, kolik různých
             * účtáren mají vztahy v běhu.
             *
             * Rozdělení dělá VÝHRADNĚ allocator — přehled o výši pojistného
             * (PVPOJ) čte tytéž buňky účtárna × sazbová kategorie, takže závazek
             * a podání nemohou mít každý jiné zaokrouhlení.
             */
            $allocations = $this->officeAllocator->allocate(
                $input,
                $people,
                $root,
            );
            if ($scopeOfficeId !== null
                && array_column($allocations, 'office_id') !== [$scopeOfficeId]
            ) {
                throw new \DomainException(
                    'Zmrazený vstup zúžený na účtárnu obsahuje vztahy jiné účtárny.',
                );
            }
            $allocated = 0;
            $targets = [];
            foreach ($allocations as $allocation) {
                $allocated = $this->add($allocated, $allocation['amount_minor']);
                if ($allocation['amount_minor'] === 0) {
                    continue;
                }
                $officeId = $allocation['office_id'];
                $reference = "social-insurance:office:{$officeId}";
                $targets[$reference] = [
                    ...$this->target($supplierId, $officeId, $dueOn),
                    'employee_minor' => $allocation['employee_minor'],
                    'employer_minor' => $allocation['employer_minor'],
                    'amount_minor' => $allocation['amount_minor'],
                ];
            }
            if ($allocated !== $targetAmount) {
                throw new \DomainException(
                    'Rozpad sociálního odvodu na účtárny nesouhlasí s kořenovým výsledkem.',
                );
            }
            $prior = $this->priorState(
                $this->liabilities->lockEarlierInstitutionalLiabilities(
                    $supplierId,
                    $revision['run_id'],
                    $revision['revision_no'],
                    'social_insurance',
                ),
            );
            if ($revision['revision_kind'] === 'regular' && $prior !== []) {
                throw new \DomainException(
                    'Další revize sociálního závazku musí být opravná.',
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
                        $previous['recipient_reference']
                            !== $target['recipient_reference']
                        || $previous['target_snapshot']
                            !== $target['target_snapshot']
                    )
                ) {
                    throw new \DomainException(
                        'Ověřený cíl sociálního pojištění se proti předchozímu závazku změnil.',
                    );
                }
                $officeAmount = $target['amount_minor'] ?? 0;
                $priorSigned = $previous['signed_minor'] ?? 0;
                $delta = $this->subtract($officeAmount, $priorSigned);
                if ($delta === 0) {
                    continue;
                }
                $direction = $delta > 0 ? 'outgoing' : 'incoming';
                $amount = $this->absolute($delta);
                $recipientReference = $target['recipient_reference']
                    ?? $previous['recipient_reference']
                    ?? throw new \LogicException('Chybí příjemce sociálního závazku.');
                $targetSnapshot = $target['target_snapshot']
                    ?? $previous['target_snapshot']
                    ?? throw new \LogicException('Chybí snapshot příjemce.');
                $source = [
                    'schema_reference' =>
                        'payroll-payment-social-insurance-source.v1',
                    'run_id' => $revision['run_id'],
                    'revision_id' => $revisionId,
                    'revision_no' => $revision['revision_no'],
                    'statutory_result_hash' => $rootHash,
                    'logical_reference' => $reference,
                    'recipient_reference' => $recipientReference,
                    ...$targetSnapshot,
                    'employee_contribution_minor' =>
                        $target['employee_minor'] ?? 0,
                    'employer_contribution_minor' =>
                        $target['employer_minor'] ?? 0,
                    'target_amount_minor' => $officeAmount,
                    'prior_signed_minor' => $priorSigned,
                    'delta_signed_minor' => $delta,
                ];
                $sourceJson = CanonicalJson::encode($source);
                $sourceHash = hash('sha256', $sourceJson);
                $idempotencyHash = hash(
                    'sha256',
                    CanonicalJson::encode([
                        'schema_reference' =>
                            'payroll-payment-social-insurance-idempotency.v1',
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
                    'social_insurance',
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
                'Závazek lze vytvořit jen z aktuální schválené revize.',
            );
        }
        if (($revision['schema_version'] ?? null)
            !== 'payroll-run-input.v2'
        ) {
            throw new \DomainException(
                'Závazek vyžaduje vstup payroll-run-input.v2.',
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
     * @return array{
     *   recipient_reference:string,
     *   target_snapshot:array<string,mixed>
     * }
     */
    private function target(
        int $supplierId,
        int $officeId,
        string $dueOn,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT office.id, office.code, office.name,
                    office.social_security_variable_symbol,
                    office.is_active, office.row_version AS office_row_version,
                    settings.social_security_office_code,
                    settings.row_version AS settings_row_version
               FROM payroll_offices office
               JOIN payroll_employer_settings settings
                 ON settings.supplier_id = office.supplier_id
              WHERE office.supplier_id = ? AND office.id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $officeId]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            throw new \DomainException(
                'Zmrazená mzdová účtárna neexistuje.',
            );
        }
        $office = $this->databaseRow($raw, 'mzdovou účtárnu');
        if (!$this->boolean($office, 'is_active')) {
            throw new \DomainException(
                'Zmrazená mzdová účtárna není aktivní.',
            );
        }
        $variableSymbol = $this->string(
            $office,
            'social_security_variable_symbol',
        );
        if (preg_match('/^[0-9]{1,10}$/D', $variableSymbol) !== 1) {
            throw new \DomainException(
                'Mzdová účtárna nemá platný zaměstnavatelský VS.',
            );
        }
        $institutionCode = $this->string(
            $office,
            'social_security_office_code',
        );
        if (preg_match(
            '/^[A-Z0-9][A-Z0-9._-]{0,31}$/D',
            $institutionCode,
        ) !== 1) {
            throw new \DomainException(
                'Kód správy sociálního zabezpečení není platný.',
            );
        }
        $accounts = $this->institutions->lockEffectivePaymentTargets(
            $supplierId,
            'social_security',
            $institutionCode,
            'CZK',
            $dueOn,
        );
        if (count($accounts) !== 1) {
            throw new \DomainException(
                count($accounts) === 0
                    ? 'Správa sociálního zabezpečení nemá účinný ověřený účet.'
                    : 'Správa sociálního zabezpečení má nejednoznačný účet.',
            );
        }
        $account = $accounts[0];
        $this->assertVerifiedAccount($supplierId, $dueOn, $account);
        $verificationHash = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-social-institution-target-verification.v1',
                'institution_type' => 'social_security',
                'institution_code' => $institutionCode,
                'payment_target_id' => $account['id'],
                'payment_target_hash' => $account['bank_account_hash'],
                'payment_target_row_version' => $account['row_version'],
                'payroll_office_id' => $officeId,
                'payroll_office_code' => $this->string($office, 'code'),
                'payroll_office_row_version' =>
                    $this->integer($office, 'office_row_version'),
                'employer_settings_row_version' =>
                    $this->integer($office, 'settings_row_version'),
                'variable_symbol' => $variableSymbol,
                'specific_symbol' => $account['specific_symbol'],
                'constant_symbol' => $account['constant_symbol'],
                'source_kind' => $account['source_kind'],
                'source_reference' => $account['source_reference'],
                'verified_on' => $account['verified_on'],
                'verified_by' => $account['verified_by'],
            ]),
        );

        return [
            'recipient_reference' =>
                "institution:social_security:{$institutionCode}:account:"
                . $account['id'],
            'target_snapshot' => [
                'institution_type' => 'social_security',
                'institution_code' => $institutionCode,
                'payment_target_id' => $account['id'],
                'payment_target_hash' => $account['bank_account_hash'],
                'payment_target_row_version' => $account['row_version'],
                'payment_target_verification_hash' => $verificationHash,
                'payroll_office_id' => $officeId,
                'payroll_office_code' => $this->string($office, 'code'),
                'payroll_office_row_version' =>
                    $this->integer($office, 'office_row_version'),
                'employer_settings_row_version' =>
                    $this->integer($office, 'settings_row_version'),
                'variable_symbol' => $variableSymbol,
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
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                $account['bank_account_hash'],
            ) !== 1
        ) {
            throw new \DomainException(
                'Účet správy sociálního zabezpečení není ověřený.',
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
            $reference = $row['liability_reference'];
            if (preg_match(
                '/^social-insurance:office:[1-9][0-9]*$/D',
                $reference,
            ) !== 1) {
                throw new \DomainException(
                    'Dřívější sociální závazek nemá referenci mzdové účtárny.',
                );
            }
            $source = $this->canonicalObject(
                $row['source_snapshot_json'],
                $row['source_snapshot_hash'],
                'zdroje sociálního závazku',
            );
            if (($source['schema_reference'] ?? null)
                    !== 'payroll-payment-social-insurance-source.v1'
                || ($source['recipient_reference'] ?? null)
                    !== $row['recipient_reference']
            ) {
                throw new \DomainException(
                    'Dřívější sociální závazek nemá platný zdroj.',
                );
            }
            $target = [];
            foreach ([
                'institution_type',
                'institution_code',
                'payment_target_id',
                'payment_target_hash',
                'payment_target_row_version',
                'payment_target_verification_hash',
                'payroll_office_id',
                'payroll_office_code',
                'payroll_office_row_version',
                'employer_settings_row_version',
                'variable_symbol',
                'specific_symbol',
                'constant_symbol',
            ] as $field) {
                $target[$field] = $source[$field] ?? null;
            }
            if (!isset($state[$reference])) {
                $state[$reference] = [
                    'recipient_reference' => $row['recipient_reference'],
                    'signed_minor' => 0,
                    'latest_id' => $row['id'],
                    'target_snapshot' => $target,
                ];
            } elseif ($state[$reference]['recipient_reference']
                    !== $row['recipient_reference']
                || $state[$reference]['target_snapshot'] !== $target
            ) {
                throw new \DomainException(
                    'Řetězec sociálního závazku změnil zmrazený cíl.',
                );
            }
            $signed = $row['direction'] === 'outgoing'
                ? $row['amount_minor']
                : -$row['amount_minor'];
            $state[$reference]['signed_minor'] = $this->add(
                $state[$reference]['signed_minor'],
                $signed,
            );
            $state[$reference]['latest_id'] = $row['id'];
        }
        foreach ($state as $item) {
            if ($item['signed_minor'] < 0) {
                throw new \DomainException(
                    'Dřívější sociální závazky mají záporný zůstatek.',
                );
            }
        }

        return $state;
    }

    /** @param array<string,mixed> $existing */
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
        if (($existing['employee_id'] ?? null) !== null
            || ($existing['liability_kind'] ?? null) !== 'social_insurance'
            || ($existing['direction'] ?? null) !== $direction
            || ($existing['recipient_reference'] ?? null)
                !== $recipientReference
            || ($existing['due_on'] ?? null) !== $dueOn
            || ($existing['amount_minor'] ?? null) !== $amount
            || ($existing['previous_liability_id'] ?? null) !== $previousId
            || !is_string($existing['source_snapshot_hash'] ?? null)
            || !hash_equals(
                $existing['source_snapshot_hash'],
                $sourceHash,
            )
            || !is_string($existing['idempotency_key_hash'] ?? null)
            || !hash_equals(
                $existing['idempotency_key_hash'],
                $idempotencyHash,
            )
        ) {
            throw new \DomainException(
                'Idempotentní replay sociálního závazku nesouhlasí.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function canonicalObject(
        string $json,
        string $expectedHash,
        string $context,
    ): array {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \DomainException(
                "Kanonický JSON {$context} není platný.",
                previous: $exception,
            );
        }
        $object = $this->object($decoded, $context);
        $canonical = CanonicalJson::encode($object);
        if ($canonical !== $json
            || !hash_equals($expectedHash, hash('sha256', $canonical))
        ) {
            throw new \DomainException("Otisk {$context} nesouhlasí.");
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

    /** @return array<string,mixed> */
    private function databaseRow(mixed $value, string $context): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatnou hodnotu pro {$context}.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "Databáze vrátila neplatný klíč pro {$context}.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException("{$field} není neprázdný text.");
        }

        return trim($value);
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

    /** @param array<string,mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "{$field} není celé číslo.",
            );
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new \UnexpectedValueException(
                "{$field} není platné celé číslo.",
            );
        }

        return $integer;
    }

    /** @param array<string,mixed> $row */
    private function boolean(array $row, string $field): bool
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value) && !is_bool($value)) {
            throw new \UnexpectedValueException(
                "{$field} není logická hodnota.",
            );
        }
        $normalized = filter_var(
            $value,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );
        if ($normalized === null) {
            throw new \UnexpectedValueException(
                "{$field} není platná logická hodnota.",
            );
        }

        return $normalized;
    }

    private function positiveInt(mixed $value, string $context): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \DomainException(
                "{$context} musí být kladné celé číslo.",
            );
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

    private function statutoryDueOn(string $periodStart): string
    {
        $period = $this->date($periodStart, 'mzdové období');
        if (substr($period, 8, 2) !== '01') {
            throw new \DomainException(
                'Mzdové období musí začínat prvním dnem měsíce.',
            );
        }

        return $this->deadlines->dueOn(
            PayrollLevyDeadlinePolicy::SOCIAL_INSURANCE,
            $period,
        );
    }

    private function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \OverflowException('Součet sociálního závazku přetekl.');
        }

        return $left + $right;
    }

    private function subtract(int $left, int $right): int
    {
        if (($right > 0 && $left < PHP_INT_MIN + $right)
            || ($right < 0 && $left > PHP_INT_MAX + $right)
        ) {
            throw new \OverflowException('Rozdíl sociálního závazku přetekl.');
        }

        return $left - $right;
    }

    private function absolute(int $value): int
    {
        if ($value === PHP_INT_MIN) {
            throw new \OverflowException(
                'Absolutní sociální závazek přetekl.',
            );
        }

        return abs($value);
    }
}
