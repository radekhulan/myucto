<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Service\Payroll\Net\PayoutAllocation;
use MyInvoice\Service\Payroll\Net\PayoutAllocationRequest;
use MyInvoice\Service\Payroll\Net\PayoutAllocationService;
use MyInvoice\Service\Payroll\Net\PayrollPartnerSettlement;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class PayrollNetWageLiabilityMaterializer
{
    public function __construct(
        private readonly PayrollPaymentLiabilityRepository $liabilities,
        private readonly PayoutAllocationService $allocations,
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
                'Firma a revize platebních závazků musí být kladná čísla.',
            );
        }
        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel materializace platebních závazků není platný.',
            );
        }

        return $this->liabilities->transaction(function () use (
            $supplierId,
            $revisionId,
            $actorUserId,
        ): array {
            $context = $this->liabilities->lockRevision(
                $supplierId,
                $revisionId,
            );
            if ($context === null) {
                throw new \DomainException(
                    'Mzdová revize pro platební závazky neexistuje.',
                );
            }
            $this->assertRevisionContext($context);

            $input = $this->canonicalObject(
                $context['input_snapshot_json'],
                $context['input_snapshot_hash'],
                'vstupního snapshotu',
            );
            $result = $this->canonicalObject(
                $context['result_snapshot_json'],
                $context['result_snapshot_hash'],
                'výsledku revize',
            );
            $this->assertRevisionPayload(
                $supplierId,
                $context['payment_date'],
                $context['input_snapshot_hash'],
                $input,
                $result,
            );

            $snapshotPeople = $this->snapshotPeople($input);
            $rootPeople = $this->resultPeople($result);
            $storedPeople = $this->storedPeople(
                $this->liabilities->lockPersonResults(
                    $supplierId,
                    $revisionId,
                ),
                $rootPeople,
            );
            if (array_keys($snapshotPeople) !== array_keys($storedPeople)) {
                throw new \DomainException(
                    'Schválená revize nepokrývá přesně zmrazené osoby.',
                );
            }

            $targets = [];
            foreach ($storedPeople as $employeeId => $stored) {
                $personTargets = $this->personTargets(
                    $employeeId,
                    $stored['result'],
                    $snapshotPeople[$employeeId],
                    $context['payment_date'],
                );
                foreach ($personTargets as $reference => $target) {
                    if (isset($targets[$reference])) {
                        throw new \DomainException(
                            'Logický odkaz závazku čisté mzdy není jednoznačný.',
                        );
                    }
                    $targets[$reference] = $target;
                }
            }

            $prior = $this->priorState(
                $this->liabilities->lockEarlierNetWageLiabilities(
                    $supplierId,
                    $context['run_id'],
                    $context['revision_no'],
                ),
            );
            if ($context['revision_kind'] === 'regular' && $prior !== []) {
                throw new \DomainException(
                    'Další revize se závazky musí být označena jako oprava.',
                );
            }
            if ($context['revision_kind'] === 'correction'
                && $context['previous_revision_id'] === null
            ) {
                throw new \DomainException(
                    'Opravná revize nemá odkaz na předchozí revizi.',
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
                if ($target !== null && $previous !== null) {
                    if ($target['employee_id'] !== $previous['employee_id']
                        || $target['recipient_reference']
                            !== $previous['recipient_reference']
                    ) {
                        throw new \DomainException(
                            'Dřívější závazek neodpovídá logickému platebnímu cíli.',
                        );
                    }
                }

                $employeeId = $target['employee_id']
                    ?? $previous['employee_id']
                    ?? throw new \LogicException(
                        'Závazek nemá zaměstnance.',
                    );
                if (!isset($storedPeople[$employeeId])) {
                    throw new \DomainException(
                        "Opravná revize nemá výsledek osoby {$employeeId}.",
                    );
                }
                $recipientReference = $target['recipient_reference']
                    ?? $previous['recipient_reference']
                    ?? throw new \LogicException(
                        'Závazek nemá příjemce.',
                    );
                $this->assertRecipientReference(
                    $recipientReference,
                    $employeeId,
                );
                $targetAmount = $target['amount_minor'] ?? 0;
                $priorSigned = $previous['signed_minor'] ?? 0;
                $delta = $this->subtract($targetAmount, $priorSigned);
                if ($delta === 0) {
                    continue;
                }
                $direction = $delta > 0 ? 'outgoing' : 'incoming';
                $amount = $this->absolute($delta);
                $previousLiabilityId = $previous['latest_id'] ?? null;
                $personHash = $storedPeople[$employeeId]['hash'];
                $source = [
                    'schema_reference' =>
                        'payroll-payment-net-wage-source.v1',
                    'run_id' => $context['run_id'],
                    'revision_id' => $revisionId,
                    'revision_no' => $context['revision_no'],
                    'person_id' => $employeeId,
                    'person_result_hash' => $personHash,
                    'input_snapshot_hash' =>
                        $context['input_snapshot_hash'],
                    'result_snapshot_hash' =>
                        $context['result_snapshot_hash'],
                    'logical_reference' => $reference,
                    'recipient_reference' => $recipientReference,
                    'allocation_reference_hash' =>
                        $target['allocation_reference_hash'] ?? null,
                    'payment_target_id' =>
                        $target['payment_target_id'] ?? null,
                    'payment_target_hash' =>
                        $target['payment_target_hash'] ?? null,
                    'payment_target_row_version' =>
                        $target['payment_target_row_version'] ?? null,
                    'payment_target_verification_hash' =>
                        $target['payment_target_verification_hash'] ?? null,
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
                            'payroll-payment-net-wage-idempotency.v1',
                        'supplier_id' => $supplierId,
                        'revision_id' => $revisionId,
                        'logical_reference' => $reference,
                        'source_snapshot_hash' => $sourceHash,
                    ]),
                    true,
                );
                $existing = $this->liabilities->findForUpdate(
                    $supplierId,
                    $revisionId,
                    $reference,
                );
                if ($existing !== null) {
                    $this->assertReplay(
                        $existing,
                        $employeeId,
                        $direction,
                        $recipientReference,
                        $context['payment_date'],
                        $amount,
                        $previousLiabilityId,
                        $sourceHash,
                        $idempotencyHash,
                    );
                    $ids[] = $existing['id'];
                    continue;
                }

                $ids[] = $this->liabilities->insert(
                    $supplierId,
                    $revisionId,
                    $employeeId,
                    $reference,
                    $direction,
                    $recipientReference,
                    $context['payment_date'],
                    $amount,
                    $previousLiabilityId,
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
     * @param array{
     *   revision_no:int,
     *   previous_revision_id:?int,
     *   revision_kind:string,
     *   revision_status:string,
     *   schema_version:string,
     *   current_revision_no:int,
     *   payment_date:string
     * } $context
     */
    private function assertRevisionContext(array $context): void
    {
        if ($context['revision_status'] !== 'approved') {
            throw new \DomainException(
                'Platební závazky lze vytvořit pouze ze schválené revize.',
            );
        }
        if ($context['revision_no'] !== $context['current_revision_no']) {
            throw new \DomainException(
                'Platební závazky lze vytvořit pouze z aktuální revize běhu.',
            );
        }
        if ($context['schema_version'] !== 'payroll-run-input.v2') {
            throw new \DomainException(
                'Platební závazky vyžadují vstupní schéma payroll-run-input.v2.',
            );
        }
        if (!in_array(
            $context['revision_kind'],
            ['regular', 'correction'],
            true,
        )) {
            throw new \DomainException('Typ mzdové revize není podporovaný.');
        }
        $this->date($context['payment_date'], 'datum výplaty běhu');
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $result
     */
    private function assertRevisionPayload(
        int $supplierId,
        string $paymentDate,
        string $inputHash,
        array $input,
        array $result,
    ): void {
        if (($input['schema_version'] ?? null) !== 'payroll-run-input.v2') {
            throw new \DomainException(
                'Zmrazený vstup nemá schéma payroll-run-input.v2.',
            );
        }
        if (($result['schema_version'] ?? null) !== 'payroll-run-result.v2') {
            throw new \DomainException(
                'Výsledek nemá schéma payroll-run-result.v2.',
            );
        }
        if (($input['supplier_id'] ?? null) !== $supplierId) {
            throw new \DomainException(
                'Zmrazený vstup nepatří požadované firmě.',
            );
        }
        if (($input['payment_date'] ?? null) !== $paymentDate) {
            throw new \DomainException(
                'Datum výplaty snapshotu neodpovídá schválenému běhu.',
            );
        }
        $sourceHash = $result['source_snapshot_hash'] ?? null;
        if (!is_string($sourceHash) || !hash_equals($inputHash, $sourceHash)) {
            throw new \DomainException(
                'Výsledek revize neodpovídá zmrazenému vstupu.',
            );
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<int,array<string,mixed>>
     */
    private function snapshotPeople(array $input): array
    {
        $result = [];
        foreach ($this->rows($input['people'] ?? null, 'snapshot.people') as $person) {
            $employee = $this->object(
                $person['employee'] ?? null,
                'snapshot.employee',
            );
            $employeeId = $this->positiveInteger(
                $employee['id'] ?? null,
                'snapshot.employee.id',
            );
            if (isset($result[$employeeId])) {
                throw new \DomainException(
                    "Snapshot obsahuje osobu {$employeeId} vícekrát.",
                );
            }
            $result[$employeeId] = $person;
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<int,array<string,mixed>>
     */
    private function resultPeople(array $result): array
    {
        $people = [];
        foreach ($this->rows($result['people'] ?? null, 'result.people') as $person) {
            $employeeId = $this->positiveInteger(
                $person['employee_id'] ?? null,
                'result.employee_id',
            );
            if (isset($people[$employeeId])) {
                throw new \DomainException(
                    "Výsledek obsahuje osobu {$employeeId} vícekrát.",
                );
            }
            $people[$employeeId] = $person;
        }
        ksort($people, SORT_NUMERIC);

        return $people;
    }

    /**
     * @param list<array{
     *   employee_id:int,
     *   status:string,
     *   result_json:string,
     *   result_hash:string
     * }> $rows
     * @param array<int,array<string,mixed>> $rootPeople
     * @return array<int,array{result:array<string,mixed>,hash:string}>
     */
    private function storedPeople(array $rows, array $rootPeople): array
    {
        $result = [];
        foreach ($rows as $row) {
            $employeeId = $row['employee_id'];
            if ($row['status'] !== 'calculated') {
                throw new \DomainException(
                    "Osoba {$employeeId} nemá vypočtený výsledek.",
                );
            }
            if (isset($result[$employeeId])) {
                throw new \DomainException(
                    "Výsledek osoby {$employeeId} je uložen vícekrát.",
                );
            }
            $person = $this->canonicalObject(
                $row['result_json'],
                $row['result_hash'],
                "výsledku osoby {$employeeId}",
            );
            if (($person['employee_id'] ?? null) !== $employeeId) {
                throw new \DomainException(
                    "Výsledek osoby {$employeeId} má jinou identitu.",
                );
            }
            $root = $rootPeople[$employeeId] ?? null;
            if ($root === null
                || !hash_equals(
                    $row['result_hash'],
                    hash('sha256', CanonicalJson::encode($root)),
                )
            ) {
                throw new \DomainException(
                    "Otisk výsledku osoby {$employeeId} nesouhlasí s revizí.",
                );
            }
            $result[$employeeId] = [
                'result' => $person,
                'hash' => $row['result_hash'],
            ];
        }
        ksort($result, SORT_NUMERIC);
        if (array_keys($result) !== array_keys($rootPeople)) {
            throw new \DomainException(
                'Kořenový výsledek nepokrývá přesně uložené osoby.',
            );
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $personResult
     * @param array<string,mixed> $personSnapshot
     * @return array<string,array{
     *   employee_id:int,
     *   recipient_reference:string,
     *   amount_minor:int,
     *   allocation_reference_hash:string,
     *   payment_target_id:int,
     *   payment_target_hash:?string,
     *   payment_target_row_version:?int,
     *   payment_target_verification_hash:?string
     * }>
     */
    private function personTargets(
        int $employeeId,
        array $personResult,
        array $personSnapshot,
        string $paymentDate,
    ): array {
        $payable = $personResult['payable_after_enforcement_minor'] ?? null;
        if (!is_int($payable)) {
            throw new \DomainException(
                "Výsledek osoby {$employeeId} nemá platnou částku po exekucích.",
            );
        }
        if ($payable < 0) {
            // Přeplatek čisté mzdy: zaměstnanec dluží zaměstnavateli (měsíc
            // bez peněžního příjmu s doplatkem ZP do minimálního vyměřovacího
            // základu, § 3 odst. 10 z. č. 592/1992 Sb.). Žádný platební
            // závazek NEVZNIKÁ — a to je jediná bezpečná odpověď: závazek se
            // zápornou částkou by v {@see PayrollPaymentBatchBuilder} buď
            // spadl na `amount_minor <= 0`, nebo — hůř — prošel jako platba
            // se záporným znaménkem do odchozí dávky, tedy do bankovního
            // příkazu. Pohledávku vede ÚČETNICTVÍ (MD 335 / D 331, viz
            // PayrollPostingLineBuilder) a inkasuje se zápočtem v dalším
            // měsíci nebo úhradou, ne obrácenou mzdovou platbou.
            //
            // Kontrolní součty MZ-13 tenhle rozdíl znají: záporná výplata
            // jde do samostatné položky `employee_receivable` (směr
            // `incoming`) a do `net_wage` se nezapočítá, takže součet
            // závazků čisté mzdy dál sedí na to, co se skutečně materializuje.
            return [];
        }
        if (!array_key_exists('payout_accounts', $personSnapshot)) {
            throw new \DomainException(
                "Revize osoby {$employeeId} neobsahuje zmrazené výplatní účty. "
                . 'Pro platby vytvořte opravnou revizi z aktuálních podkladů.',
            );
        }
        $accounts = $this->verifiedAccounts(
            $personSnapshot['payout_accounts'],
            $paymentDate,
            $employeeId,
        );
        $requests = $this->allocationRequests(
            $personSnapshot['payout_rules'] ?? null,
        );
        $allocationResult = $this->allocations->allocate($payable, $requests);

        $targets = [];
        foreach ($allocationResult->allocations as $allocation) {
            if ($allocation->amountMinorUnits === 0) {
                continue;
            }
            if ($allocation->destinationKind === PayrollPartnerSettlement::KIND) {
                // Zápočet na účet společníka NENÍ výplata: jde o čistě účetní
                // překlasifikaci závazku (331/366 MD / 365 D, viz
                // PayrollPostingLineBuilder). Nevzniká platba, platební příkaz
                // ani pokladní doklad, takže tahle částka nesmí vytvořit závazek
                // čisté mzdy — jinak by ji PayrollPaymentBatchBuilder poslal do
                // platební dávky a firma by vyplatila peníze, které už jsou
                // vypořádané. Napřed ale ověříme, že si zápočet daná osoba vůbec
                // smí nastavit; fail-closed i pro pravidlo vzniklé mimo aplikaci.
                PayrollPartnerSettlement::assertEligible(
                    $this->relationTypes($personSnapshot),
                    $employeeId,
                );
                continue;
            }
            [$recipientReference, $target] = $this->paymentTarget(
                $allocation,
                $accounts,
                $employeeId,
            );
            $allocationHash = hash(
                'sha256',
                $allocation->allocationReference,
            );
            $logicalReference = $this->logicalReference(
                $employeeId,
                $allocationHash,
                $recipientReference,
            );
            if (isset($targets[$logicalReference])) {
                throw new \DomainException(
                    "Osoba {$employeeId} má duplicitní platební cíl.",
                );
            }
            $targets[$logicalReference] = [
                'employee_id' => $employeeId,
                'recipient_reference' => $recipientReference,
                'amount_minor' => $allocation->amountMinorUnits,
                'allocation_reference_hash' => $allocationHash,
                'payment_target_id' => $target['id'],
                'payment_target_hash' => $target['hash'],
                'payment_target_row_version' => $target['row_version'],
                'payment_target_verification_hash' =>
                    $target['verification_hash'],
            ];
        }

        return $targets;
    }

    /**
     * Typy pracovních vztahů osoby ze zmrazené revize — podklad pro kontrolu,
     * kdo si smí nastavit zápočet na účet společníka.
     *
     * @param array<string,mixed> $personSnapshot
     * @return list<string>
     */
    private function relationTypes(array $personSnapshot): array
    {
        $result = [];
        foreach ($this->rows(
            $personSnapshot['employments'] ?? null,
            'snapshot.employments',
        ) as $employment) {
            $identity = $this->object(
                $employment['employment'] ?? null,
                'snapshot.employment',
            );
            $result[] = $this->requiredString(
                $identity['relation_type'] ?? null,
                'employment.relation_type',
            );
        }

        return $result;
    }

    /**
     * @return list<PayoutAllocationRequest>
     */
    private function allocationRequests(mixed $value): array
    {
        $requests = [];
        foreach ($this->rows($value, 'snapshot.payout_rules') as $rule) {
            $reference = $this->requiredString(
                $rule['allocation_reference'] ?? null,
                'payout_rule.allocation_reference',
            );
            if (mb_strlen($reference, 'UTF-8') > 96) {
                throw new \DomainException(
                    'Reference alokačního pravidla je příliš dlouhá.',
                );
            }
            $this->positiveInteger(
                $rule['id'] ?? null,
                'payout_rule.id',
            );
            $this->positiveInteger(
                $rule['row_version'] ?? null,
                'payout_rule.row_version',
            );
            $priority = $this->nonNegativeInteger(
                $rule['priority_no'] ?? null,
                'payout_rule.priority_no',
            );
            $destinationKind = $this->requiredString(
                $rule['destination_kind'] ?? null,
                'payout_rule.destination_kind',
            );
            $destinationReference = $rule['destination_reference'] ?? null;
            if ($destinationReference !== null
                && !is_string($destinationReference)
            ) {
                throw new \DomainException(
                    'Reference platebního cíle není text.',
                );
            }
            $allocationKind = $this->requiredString(
                $rule['allocation_kind'] ?? null,
                'payout_rule.allocation_kind',
            );
            if ($allocationKind === 'fixed'
                && ($rule['basis_points'] ?? null) !== null
            ) {
                throw new \DomainException(
                    'Pevná alokace nesmí mít procentní sazbu.',
                );
            }
            if ($allocationKind === 'percentage'
                && ($rule['amount_minor'] ?? null) !== null
            ) {
                throw new \DomainException(
                    'Procentní alokace nesmí mít pevnou částku.',
                );
            }
            if ($allocationKind === 'remainder'
                && (($rule['amount_minor'] ?? null) !== null
                    || ($rule['basis_points'] ?? null) !== null)
            ) {
                throw new \DomainException(
                    'Alokace zbytku nesmí mít částku ani procentní sazbu.',
                );
            }
            $requests[] = match ($allocationKind) {
                'fixed' => PayoutAllocationRequest::fixed(
                    $reference,
                    $destinationKind,
                    $destinationReference,
                    $this->nonNegativeInteger(
                        $rule['amount_minor'] ?? null,
                        'payout_rule.amount_minor',
                    ),
                    $priority,
                ),
                'percentage' => PayoutAllocationRequest::percentage(
                    $reference,
                    $destinationKind,
                    $destinationReference,
                    $this->nonNegativeInteger(
                        $rule['basis_points'] ?? null,
                        'payout_rule.basis_points',
                    ),
                    $priority,
                ),
                'remainder' => PayoutAllocationRequest::remainder(
                    $reference,
                    $destinationKind,
                    $destinationReference,
                    $priority,
                ),
                default => throw new \DomainException(
                    "Typ alokace {$allocationKind} není podporovaný.",
                ),
            };
        }

        return $requests;
    }

    /**
     * @return array<int,array{
     *   id:int,
     *   hash:string,
     *   row_version:int,
     *   verification_hash:string
     * }>
     */
    private function verifiedAccounts(
        mixed $value,
        string $paymentDate,
        int $employeeId,
    ): array {
        $accounts = [];
        foreach ($this->rows($value, 'snapshot.payout_accounts') as $account) {
            $id = $this->positiveInteger(
                $account['id'] ?? null,
                'payout_account.id',
            );
            if (isset($accounts[$id])) {
                throw new \DomainException(
                    "Zmrazený účet {$id} je uveden vícekrát.",
                );
            }
            $hash = $this->requiredString(
                $account['bank_account_hash'] ?? null,
                'payout_account.bank_account_hash',
            );
            if (preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1
                || $hash === str_repeat('0', 64)
            ) {
                throw new \DomainException(
                    "Zmrazený účet {$id} nemá tenant-safe SHA-256 otisk.",
                );
            }
            $effectiveFrom = $this->date(
                $account['effective_from'] ?? null,
                'počátek účinnosti platebního účtu',
            );
            $effectiveTo = $account['effective_to'] ?? null;
            if ($effectiveTo !== null) {
                $effectiveTo = $this->date(
                    $effectiveTo,
                    'konec účinnosti platebního účtu',
                );
            }
            if ($effectiveFrom > $paymentDate
                || ($effectiveTo !== null && $effectiveTo < $paymentDate)
            ) {
                throw new \DomainException(
                    "Zmrazený účet {$id} není účinný k datu výplaty.",
                );
            }
            $rowVersion = $this->positiveInteger(
                $account['row_version'] ?? null,
                'payout_account.row_version',
            );
            $verificationSource = $account['verification_source'] ?? null;
            $verifiedOn = $account['verified_on'] ?? null;
            $verifiedBy = $account['verified_by'] ?? null;
            if (!is_string($verificationSource)
                || !in_array($verificationSource, [
                    'employee_confirmation',
                    'bank_document',
                    'user_verified',
                ], true)
                || !is_string($verifiedOn)
                || !is_int($verifiedBy)
                || $verifiedBy <= 0
            ) {
                throw new \DomainException(
                    "Zmrazený účet {$id} nemá úplné ověření.",
                );
            }
            $verifiedDate = $this->date(
                $verifiedOn,
                'datum ověření platebního účtu',
            );
            if ($verifiedDate > $paymentDate) {
                throw new \DomainException(
                    "Zmrazený účet {$id} je ověřen až po datu výplaty.",
                );
            }
            $accounts[$id] = [
                'id' => $id,
                'hash' => $hash,
                'row_version' => $rowVersion,
                'verification_hash' => hash(
                    'sha256',
                    CanonicalJson::encode([
                        'schema_reference' =>
                            'payroll-payment-target-verification.v1',
                        'person_id' => $employeeId,
                        'payment_target_id' => $id,
                        'payment_target_hash' => $hash,
                        'row_version' => $rowVersion,
                        'verification_source' => $verificationSource,
                        'verified_on' => $verifiedOn,
                        'verified_by' => $verifiedBy,
                    ]),
                ),
            ];
        }

        return $accounts;
    }

    /**
     * @param array<int,array{
     *   id:int,
     *   hash:string,
     *   row_version:int,
     *   verification_hash:string
     * }> $accounts
     * @return array{
     *   0:string,
     *   1:array{id:int,hash:?string,row_version:?int,verification_hash:?string}
     * }
     */
    private function paymentTarget(
        PayoutAllocation $allocation,
        array $accounts,
        int $employeeId,
    ): array {
        if ($allocation->destinationKind === 'cash') {
            if ($allocation->destinationReference !== null) {
                throw new \DomainException(
                    'Hotovostní výplata nesmí mít referenci cíle.',
                );
            }
            return [
                "employee-cash:{$employeeId}",
                [
                    'id' => $employeeId,
                    'hash' => null,
                    'row_version' => null,
                    'verification_hash' => null,
                ],
            ];
        }
        if ($allocation->destinationKind !== 'bank'
            || !is_string($allocation->destinationReference)
            || preg_match(
                '/^account:([1-9][0-9]*)$/D',
                $allocation->destinationReference,
                $match,
            ) !== 1
        ) {
            throw new \DomainException(
                'Bankovní výplata vyžaduje zmrazený cíl account:<id>.',
            );
        }
        $accountId = (int) $match[1];
        $account = $accounts[$accountId] ?? null;
        if ($account === null) {
            throw new \DomainException(
                "Bankovní cíl account:{$accountId} není ve zmrazených účtech.",
            );
        }

        return ["employee-account:{$accountId}", $account];
    }

    private function logicalReference(
        int $employeeId,
        string $allocationHash,
        string $recipientReference,
    ): string {
        $targetHash = hash(
            'sha256',
            $allocationHash . "\0" . $recipientReference,
        );

        return "net-wage:e{$employeeId}:t" . substr($targetHash, 0, 48);
    }

    /**
     * @param list<array{
     *   id:int,
     *   employee_id:int,
     *   liability_reference:string,
     *   direction:string,
     *   recipient_reference:string,
     *   amount_minor:int
     * }> $rows
     * @return array<string,array{
     *   employee_id:int,
     *   recipient_reference:string,
     *   signed_minor:int,
     *   latest_id:int
     * }>
     */
    private function priorState(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!in_array($row['direction'], ['outgoing', 'incoming'], true)
                || $row['amount_minor'] <= 0
            ) {
                throw new \UnexpectedValueException(
                    'Dřívější závazek čisté mzdy má neplatnou částku.',
                );
            }
            $reference = $row['liability_reference'];
            $signed = $row['direction'] === 'outgoing'
                ? $row['amount_minor']
                : -$row['amount_minor'];
            if (!isset($result[$reference])) {
                $result[$reference] = [
                    'employee_id' => $row['employee_id'],
                    'recipient_reference' => $row['recipient_reference'],
                    'signed_minor' => 0,
                    'latest_id' => $row['id'],
                ];
            } elseif ($result[$reference]['employee_id'] !== $row['employee_id']
                || $result[$reference]['recipient_reference']
                    !== $row['recipient_reference']
            ) {
                throw new \DomainException(
                    'Řetězec dřívějších závazků není konzistentní.',
                );
            }
            $result[$reference]['signed_minor'] = $this->add(
                $result[$reference]['signed_minor'],
                $signed,
            );
            $result[$reference]['latest_id'] = $row['id'];
        }

        return $result;
    }

    /**
     * @param array{
     *   employee_id:int,
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
        int $employeeId,
        string $direction,
        string $recipientReference,
        string $dueOn,
        int $amountMinor,
        ?int $previousLiabilityId,
        string $sourceHash,
        string $idempotencyHash,
    ): void {
        if ($existing['employee_id'] !== $employeeId
            || $existing['direction'] !== $direction
            || $existing['recipient_reference'] !== $recipientReference
            || $existing['due_on'] !== $dueOn
            || $existing['amount_minor'] !== $amountMinor
            || $existing['previous_liability_id'] !== $previousLiabilityId
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
                'Idempotentní replay závazku neodpovídá uložené ekonomické povinnosti.',
            );
        }
    }

    private function assertRecipientReference(
        string $reference,
        int $employeeId,
    ): void {
        if ($reference === "employee-cash:{$employeeId}") {
            return;
        }
        if (preg_match('/^employee-account:[1-9][0-9]*$/D', $reference) === 1) {
            return;
        }
        throw new \DomainException(
            'Reference příjemce závazku čisté mzdy není bezpečná.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalObject(
        string $json,
        string $expectedHash,
        string $context,
    ): array {
        try {
            $decoded = json_decode(
                $json,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \DomainException(
                "Kanonický JSON {$context} není platný.",
                previous: $exception,
            );
        }
        $object = $this->object($decoded, $context);
        $canonical = CanonicalJson::encode($object);
        if ($json !== $canonical
            || !hash_equals($expectedHash, hash('sha256', $canonical))
        ) {
            throw new \DomainException(
                "Otisk {$context} neodpovídá kanonickému JSON.",
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
                    "{$context} musí mít pouze textové klíče.",
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

    private function requiredString(mixed $value, string $context): string
    {
        if (!is_string($value) || $value === '') {
            throw new \DomainException("{$context} musí být neprázdný text.");
        }

        return $value;
    }

    private function positiveInteger(mixed $value, string $context): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \DomainException(
                "{$context} musí být kladné celé číslo.",
            );
        }

        return $value;
    }

    private function nonNegativeInteger(mixed $value, string $context): int
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
                'Součet závazků čisté mzdy přetekl.',
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
                'Rozdíl závazků čisté mzdy přetekl.',
            );
        }

        return $left - $right;
    }

    private function absolute(int $value): int
    {
        if ($value === PHP_INT_MIN) {
            throw new \OverflowException(
                'Absolutní závazek čisté mzdy přetekl.',
            );
        }

        return abs($value);
    }
}
