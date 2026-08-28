<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Component\PayrollBenefitBasketService;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefinitionFactory;
use MyInvoice\Service\Payroll\Component\PayrollMealShiftEvidenceService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;
use PDOException;

final class PayrollInputRepository
{
    public const LIST_DEFAULT_LIMIT = 25;
    public const LIST_MAX_LIMIT = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollComponentDefinitionFactory $definitionFactory,
        private readonly PayrollBenefitBasketService $baskets,
        private readonly PayrollMealShiftEvidenceService $mealEvidence,
    ) {}

    /**
     * Mzdové vstupy měsíce bez zrušených.
     *
     * Zrušený vstup se nevypisuje — jinak by zůstal v seznamu viset a uživatel by
     * neměl jak poznat, že už neplatí. Do blokátoru mzdového běhu se nepočítá už
     * tím, že {@see \MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBatchLoader}
     * počítá výhradně `status = "draft"`.
     *
     * Zúžení na jeden pracovní vztah (`$employmentId`) padá do TÉHOŽ dotazu jako
     * stránkování. Dokud filtroval prohlížeč nad načtenou stránkou, vztah z jiné
     * strany se tiše neprojevil: seznam zůstal celý, nebo vyšel prázdný, a obojí
     * vypadá jako legitimní výsledek.
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function list(
        int $supplierId,
        string $periodStart,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
        ?int $employmentId = null,
    ): array {
        // Strop se klampuje i tady, ne jen na HTTP hranici: repozitář volá
        // i jiný kód než akce a „nekonečný" seznam nesmí jít objednat nikudy.
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        if ($employmentId !== null && $employmentId <= 0) {
            throw new \InvalidArgumentException('Vztah musí být kladné číslo.');
        }

        $narrowing = $employmentId === null ? '' : ' AND input.employment_id = ?';
        $filterParams = [$supplierId, $periodStart];
        if ($employmentId !== null) {
            $filterParams[] = $employmentId;
        }

        $countStmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_inputs input
              WHERE input.supplier_id = ?
                AND input.period_start = ?
                AND input.status <> "cancelled"' . $narrowing
        );
        $countStmt->execute($filterParams);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            'SELECT input.*, employee.full_name AS employee_name,
                    employment.code AS employment_code,
                    employment.relation_type,
                    component.code AS component_code,
                    component.name AS component_name,
                    component.component_kind,
                    component.value_kind
               FROM payroll_inputs input
               JOIN payroll_employees employee
                 ON employee.supplier_id = input.supplier_id
                AND employee.id = input.employee_id
               JOIN payroll_employments employment
                 ON employment.supplier_id = input.supplier_id
                AND employment.id = input.employment_id
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ?
                AND input.period_start = ?
                AND input.status <> "cancelled"' . $narrowing
            . ' ORDER BY employee.full_name, employment.code, component.code, input.id
              LIMIT ? OFFSET ?'
        );
        $position = 1;
        foreach ($filterParams as $param) {
            $stmt->bindValue(
                $position++,
                $param,
                is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR,
            );
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(
                self::cast(...),
                PayrollTimeValue::rows(
                    $stmt->fetchAll(PDO::FETCH_ASSOC),
                    'payroll_inputs',
                ),
            ),
            'total' => $total,
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.*, employee.full_name AS employee_name,
                    employment.code AS employment_code,
                    employment.relation_type,
                    component.code AS component_code,
                    component.name AS component_name,
                    component.component_kind,
                    component.value_kind
               FROM payroll_inputs input
               JOIN payroll_employees employee
                 ON employee.supplier_id = input.supplier_id
                AND employee.id = input.employee_id
               JOIN payroll_employments employment
                 ON employment.supplier_id = input.supplier_id
                AND employment.id = input.employment_id
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false
            ? null
            : self::cast(PayrollTimeValue::row($row, 'payroll_input'));
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $data, ?int $userId): array
    {
        $this->assertValidReferences($supplierId, $data);
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO payroll_inputs
                    (supplier_id, employee_id, employment_id, component_id,
                     period_start, source_period_start, amount_minor,
                     quantity_milliunits, source_kind, external_id,
                     source_snapshot_json, source_snapshot_hash, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $data['employee_id'],
                $data['employment_id'],
                $data['component_id'],
                $data['period_start'],
                $data['source_period_start'],
                $data['amount_minor'],
                $data['quantity_milliunits'],
                $data['source_kind'],
                $data['external_id'],
                $data['source_snapshot_json'] ?? null,
                $data['source_snapshot_hash'] ?? null,
                $userId,
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new \InvalidArgumentException(
                    'Externí mzdový vstup už byl pro tento vztah a měsíc importován.',
                    previous: $e,
                );
            }
            throw $e;
        }

        return $this->find($supplierId, (int) $this->db->pdo()->lastInsertId())
            ?? throw new \RuntimeException('Mzdový vstup se nepodařilo načíst.');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(
        int $supplierId,
        int $id,
        array $data,
        int $expectedVersion,
    ): ?array {
        $this->assertValidReferences($supplierId, $data);
        $current = $this->find($supplierId, $id);
        if ($current === null) {
            return null;
        }
        if ($current['status'] !== 'draft') {
            throw new \DomainException('Upravit lze jen rozpracovaný mzdový vstup.');
        }
        $currentVersion = PayrollTimeValue::int(
            $current['row_version'] ?? null,
            'row_version',
        );
        if ($currentVersion !== $expectedVersion) {
            throw new PayrollInputConflictException($currentVersion);
        }
        try {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE payroll_inputs
                    SET employee_id = ?, employment_id = ?, component_id = ?,
                        period_start = ?, source_period_start = ?, amount_minor = ?,
                        quantity_milliunits = ?, source_kind = ?, external_id = ?,
                        source_snapshot_json = ?, source_snapshot_hash = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?
                    AND status = "draft"'
            );
            $stmt->execute([
                $data['employee_id'],
                $data['employment_id'],
                $data['component_id'],
                $data['period_start'],
                $data['source_period_start'],
                $data['amount_minor'],
                $data['quantity_milliunits'],
                $data['source_kind'],
                $data['external_id'],
                $data['source_snapshot_json'] ?? null,
                $data['source_snapshot_hash'] ?? null,
                $supplierId,
                $id,
                $expectedVersion,
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new \InvalidArgumentException(
                    'Externí mzdový vstup už byl pro tento vztah a měsíc importován.',
                    previous: $e,
                );
            }
            throw $e;
        }
        if ($stmt->rowCount() !== 1) {
            $latest = $this->find($supplierId, $id);
            throw new PayrollInputConflictException(
                $latest === null
                    ? $expectedVersion
                    : PayrollTimeValue::int(
                        $latest['row_version'] ?? null,
                        'row_version',
                    ),
            );
        }
        return $this->find($supplierId, $id);
    }

    /** @return array<string,mixed>|null */
    public function approve(
        int $supplierId,
        int $id,
        int $expectedVersion,
        ?int $userId,
    ): ?array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT input.id AS input_id,
                        input.row_version AS input_row_version,
                        input.status AS input_status,
                        input.employee_id,
                        input.component_id,
                        input.amount_minor,
                        input.period_start,
                        component.code,
                        component.name,
                        component.component_kind,
                        component.value_kind,
                        component.frequency_kind,
                        component.tax_treatment,
                        component.social_participation_treatment,
                        component.social_treatment,
                        component.health_participation_treatment,
                        component.health_treatment,
                        component.average_earning_treatment,
                        component.enforcement_treatment,
                        component.jmhz_treatment,
                        component.statistics_treatment,
                        component.accounting_debit_code,
                        component.accounting_credit_code,
                        component.annual_limit_minor,
                        component.exemption_basket,
                        component.exemption_basis,
                        component.valid_from,
                        component.valid_to,
                        component.row_version AS component_row_version
                   FROM payroll_inputs input
                   JOIN payroll_component_definitions component
                     ON component.supplier_id = input.supplier_id
                    AND component.id = input.component_id
                  WHERE input.supplier_id = ? AND input.id = ?
                  FOR UPDATE'
            );
            $stmt->execute([$supplierId, $id]);
            $raw = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($raw === false) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return null;
            }
            $row = PayrollTimeValue::row($raw, 'payroll_input_approval');
            $currentVersion = PayrollTimeValue::int(
                $row['input_row_version'] ?? null,
                'input_row_version',
            );
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollInputConflictException($currentVersion);
            }
            if (PayrollTimeValue::string(
                $row['input_status'] ?? null,
                'input_status',
            ) !== 'draft') {
                throw new PayrollInputApprovalException(
                    'input_state_conflict',
                    'Schválit lze jen rozpracovaný mzdový vstup.',
                );
            }

            $definition = $this->definitionFactory->fromArray($row);
            try {
                $definition->impact(new \MyInvoice\Service\Payroll\Calculation\Money(
                    PayrollTimeValue::int($row['amount_minor'] ?? null, 'amount_minor'),
                ));
            } catch (\DomainException $e) {
                throw new PayrollInputApprovalException(
                    'input_requires_manual_review',
                    $e->getMessage(),
                );
            }
            $employeeId = PayrollTimeValue::int($row['employee_id'] ?? null, 'employee_id');
            $componentId = PayrollTimeValue::int($row['component_id'] ?? null, 'component_id');
            $amountMinor = PayrollTimeValue::int($row['amount_minor'] ?? null, 'amount_minor');
            $taxYear = (int) substr(
                PayrollTimeValue::string($row['period_start'] ?? null, 'period_start'),
                0,
                4,
            );
            $split = null;
            $entitlement = null;
            if ($definition->annualLimitMinor !== null
                || $definition->exemptionBasket !== null
            ) {
                $this->lockEmployee($pdo, $supplierId, $employeeId);
            }
            // Vlastní strop zaměstnavatele. NENÍ to daňová hranice: zákon
            // poskytnutí nad limit nezakazuje, jen ho zdaňuje. Zůstává proto
            // tvrdou zábranou schválení a hlídá se dál per složka.
            if ($definition->annualLimitMinor !== null) {
                $used = $this->annualBenefitTotal(
                    $supplierId,
                    $employeeId,
                    $componentId,
                    $taxYear,
                );
                if ($used + max(0, $amountMinor) > $definition->annualLimitMinor) {
                    throw new PayrollInputApprovalException(
                        'benefit_limit_exceeded',
                        'Schválením by byl překročen roční limit benefitu.',
                    );
                }
            }
            // Zákonný koš podle § 6 odst. 9 ZDP. Neblokuje: nadlimitní část je
            // běžný zdanitelný příjem a rozpad se zmrazí na vstupu, aby ho
            // výpočet běhu nemusel dopočítávat z historie schvalování.
            //
            // Výjimka je jediná a je fail-closed: u příspěvku na stravování stojí
            // strop na POČTU SMĚN S NÁROKEM, a ten se bez uzavřené docházky
            // odhadovat nesmí — jinak by se osvobodila i nadlimitní část.
            if ($definition->exemptionBasket !== null) {
                $periodStart = PayrollTimeValue::string(
                    $row['period_start'] ?? null,
                    'period_start',
                );
                $entitlements = 0;
                try {
                    if ($definition->exemptionBasket->scalesWithShifts()) {
                        $entitlement = $this->mealEvidence->forPeriod(
                            $supplierId,
                            $employeeId,
                            $periodStart,
                        );
                        if (!$entitlement->complete) {
                            throw new PayrollInputApprovalException(
                                'meal_shift_evidence_incomplete',
                                'Osvobozený příspěvek na stravování je podle § 6 odst. 9 '
                                . 'písm. b) ZDP limitovaný za jednu směnu. Chybí podklad '
                                . 'o odpracovaných směnách: '
                                . implode(', ', $entitlement->missing)
                                . '. Uzavřete docházku období a schvalte vstup znovu.',
                            );
                        }
                        $entitlements = $entitlement->count();
                    }
                    $split = $this->baskets->split(
                        $definition->exemptionBasket,
                        $periodStart,
                        $this->basketTotal(
                            $supplierId,
                            $employeeId,
                            $definition->exemptionBasket,
                            $taxYear,
                            $periodStart,
                        ),
                        $amountMinor,
                        $entitlements,
                        $definition->exemptionBasket->scalesWithShifts()
                            ? $this->mealBasketEntitlements(
                                $supplierId,
                                $employeeId,
                                $definition->exemptionBasket,
                                $periodStart,
                            )
                            : null,
                    );
                } catch (\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException $e) {
                    throw new PayrollInputApprovalException(
                        'benefit_basket_limit_unavailable',
                        'Limit koše osvobození není pro rozhodné období k dispozici, '
                        . 'rozpad plnění proto nelze určit: ' . $e->getMessage(),
                    );
                }
            }
            $snapshot = [
                ...$definition->snapshot(),
                'component_id' => PayrollTimeValue::int(
                    $row['component_id'] ?? null,
                    'component_id',
                ),
                'component_row_version' => PayrollTimeValue::int(
                    $row['component_row_version'] ?? null,
                    'component_row_version',
                ),
                'valid_from' => PayrollTimeValue::string(
                    $row['valid_from'] ?? null,
                    'valid_from',
                ),
                'valid_to' => $row['valid_to'] === null
                    ? null
                    : PayrollTimeValue::string($row['valid_to'], 'valid_to'),
            ];
            $json = CanonicalJson::encode($snapshot);
            $hash = hash('sha256', $json, true);
            $benefitAllocation = $split?->allocation;
            if ($benefitAllocation !== null && $entitlement !== null) {
                $benefitAllocation = [
                    ...$benefitAllocation,
                    'entitlement_basis' => $entitlement->basis,
                    'entitlement_snapshot' => $entitlement->jsonSerialize(),
                ];
            }

            $update = $pdo->prepare(
                'UPDATE payroll_inputs
                    SET status = "approved",
                        component_snapshot_json = ?,
                        component_snapshot_hash = ?,
                        benefit_basket = ?,
                        benefit_exempt_minor = ?,
                        benefit_taxable_minor = ?,
                        benefit_allocation_json = ?,
                        approved_by = ?,
                        approved_at = NOW(),
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?
                    AND status = "draft"'
            );
            $update->execute([
                $json,
                $hash,
                $split?->basket->value,
                $split?->exemptMinor,
                $split?->taxableMinor,
                $benefitAllocation === null
                    ? null
                    : CanonicalJson::encode($benefitAllocation),
                $userId,
                $supplierId,
                $id,
                $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new PayrollInputConflictException($currentVersion);
            }
            if ($definition->kind->isBenefit()) {
                $pdo->prepare(
                    'INSERT IGNORE INTO payroll_benefit_accumulators
                        (supplier_id, employee_id, component_id, input_id,
                         tax_year, amount_minor)
                     VALUES (?, ?, ?, ?, YEAR(?), ?)'
                )->execute([
                    $supplierId,
                    $employeeId,
                    $componentId,
                    $id,
                    PayrollTimeValue::string($row['period_start'] ?? null, 'period_start'),
                    $amountMinor,
                ]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            throw $e;
        }

        return $this->find($supplierId, $id);
    }

    /**
     * Storno SCHVÁLENÉHO benefitního vstupu a uvolnění ročního koše.
     *
     * ── Proč to musí jít ────────────────────────────────────────────────────────
     * Schválením benefitu vzniká řádek `payroll_benefit_accumulators`, který navždy
     * čerpá roční koš osvobození podle § 6 odst. 9 ZDP. Když účetní zjistí, že
     * plnění bylo jiné nebo že nebylo vůbec, musí jít koš vrátit — jinak se
     * zaměstnanci až do konce roku nesprávně zdaňuje všechno další plnění.
     * `status = 'reversed'` byl v enumu od migrace 1210, ale nikdo ho nenastavoval:
     * `cancel()` bere jen koncept a jiná cesta neexistovala.
     *
     * ── Co se NEPŘEPOČÍTÁVÁ ─────────────────────────────────────────────────────
     * Zmrazený rozpad (`benefit_basket`, `benefit_exempt_minor`,
     * `benefit_taxable_minor`) i `amount_minor` akumulátoru zůstávají beze změny.
     * Storno je stavový přechod, ne oprava historie — dřív schválené vstupy se
     * zpětně nepřepočítávají a nikdy nesmějí. Opravné plnění je NOVÝ vstup
     * s vlastním rozpadem proti koši, který se právě uvolnil.
     *
     * ── Co blokuje ──────────────────────────────────────────────────────────────
     * Vstup zmrazený ve schválené revizi mzdového běhu. Ten už je na výplatní
     * pásce, v zákonných výsledcích i v účetní dávce; uvolnit jeho koš by
     * rozhodilo zdanění měsíce, který je hotový. Oprava takového plnění patří do
     * opravné revize běhu, ne do storna vstupu.
     *
     * Operace je idempotentní: druhé storno už jen vrátí stav.
     *
     * @return array<string,mixed>|null null, když vstup neexistuje
     */
    public function reverseBenefit(
        int $supplierId,
        int $id,
        int $expectedVersion,
        int $userId,
        string $reason,
    ): ?array {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 190) {
            throw new \InvalidArgumentException(
                'Důvod storna benefitu musí mít 1 až 190 znaků.',
            );
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT id, status, row_version, period_start
                   FROM payroll_inputs
                  WHERE supplier_id = ? AND id = ?
                  FOR UPDATE'
            );
            $stmt->execute([$supplierId, $id]);
            $raw = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($raw === false) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return null;
            }
            $row = PayrollTimeValue::row($raw, 'payroll_benefit_reversal');
            $status = PayrollTimeValue::string($row['status'] ?? null, 'status');
            $currentVersion = PayrollTimeValue::int(
                $row['row_version'] ?? null,
                'row_version',
            );
            if ($status === 'cancelled' && !$this->hasActiveBenefit($pdo, $supplierId, $id)) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $this->find($supplierId, $id);
            }
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollInputConflictException($currentVersion);
            }
            if ($status !== 'approved') {
                throw new PayrollInputCancellationException(
                    'input_state_conflict',
                    'Stornovat lze jen schválený mzdový vstup.',
                );
            }
            if (!$this->hasActiveBenefit($pdo, $supplierId, $id)) {
                throw new PayrollInputCancellationException(
                    'input_state_conflict',
                    'Vstup nečerpá roční koš osvobození; není co stornovat.',
                );
            }
            $this->assertNotFrozenInApprovedRevision(
                $pdo,
                $supplierId,
                $id,
                PayrollTimeValue::string($row['period_start'] ?? null, 'period_start'),
            );
            $update = $pdo->prepare(
                'UPDATE payroll_inputs
                    SET status = "cancelled", row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?
                    AND status = "approved"'
            );
            $update->execute([$supplierId, $id, $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new PayrollInputConflictException($currentVersion);
            }
            $reverse = $pdo->prepare(
                'UPDATE payroll_benefit_accumulators
                    SET status = "reversed",
                        reversed_at = NOW(),
                        reversed_by = ?,
                        reversal_reason = ?
                  WHERE supplier_id = ? AND input_id = ? AND status = "active"'
            );
            $reverse->execute([$userId, $reason, $supplierId, $id]);
            if ($reverse->rowCount() !== 1) {
                throw new PayrollInputConflictException($currentVersion);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id);
    }

    private function hasActiveBenefit(PDO $pdo, int $supplierId, int $id): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1
               FROM payroll_benefit_accumulators
              WHERE supplier_id = ? AND input_id = ? AND status = "active"
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Zrušení vlastního konceptu mzdového vstupu.
     *
     * Jde cestou `status = "cancelled"`, ne tvrdého DELETE — schéma pro to bylo
     * navržené už v migraci 1210: hodnota je v enumu, CHECK ji vyjímá z povinnosti
     * mít snapshot složky a generovaný `external_dedupe_key` se u ní nuluje, takže
     * se uvolní i unikátní klíč externího vstupu. Zachová se tím auditní stopa
     * a odpadne řešení cizích klíčů.
     *
     * Blokovat smí jen důkaz pohybu: rozpracovaný vstup bez schválení a bez
     * navázané evidence zrušit lze, cokoliv dál v řetězci ne. Operace je
     * idempotentní — druhé zrušení už jen vrátí stav.
     *
     * @return array<string,mixed>|null null, když vstup neexistuje
     */
    public function cancel(int $supplierId, int $id, int $expectedVersion): ?array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT id, status, approved_at, row_version, period_start
                   FROM payroll_inputs
                  WHERE supplier_id = ? AND id = ?
                  FOR UPDATE'
            );
            $stmt->execute([$supplierId, $id]);
            $raw = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($raw === false) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return null;
            }
            $row = PayrollTimeValue::row($raw, 'payroll_input_cancellation');
            $status = PayrollTimeValue::string($row['status'] ?? null, 'status');
            if ($status === 'cancelled') {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $this->find($supplierId, $id);
            }
            $currentVersion = PayrollTimeValue::int(
                $row['row_version'] ?? null,
                'row_version',
            );
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollInputConflictException($currentVersion);
            }
            if ($status !== 'draft' || $row['approved_at'] !== null) {
                throw new PayrollInputCancellationException(
                    'input_state_conflict',
                    'Zrušit lze jen rozpracovaný mzdový vstup.',
                );
            }
            $this->assertNoMovement(
                $pdo,
                $supplierId,
                $id,
                PayrollTimeValue::string($row['period_start'] ?? null, 'period_start'),
            );
            $update = $pdo->prepare(
                'UPDATE payroll_inputs
                    SET status = "cancelled", row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?
                    AND status = "draft"'
            );
            $update->execute([$supplierId, $id, $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new PayrollInputConflictException($currentVersion);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id);
    }

    /**
     * Důkaz pohybu, který zrušení konceptu zakazuje.
     *
     * Materializace pracovní cesty a import si drží vazbu na řádek zdroje
     * a schválená revize běhu má vstup zmrazený ve svém snapshotu. Nic z toho
     * se nesmí utrhnout od záznamu, na který ukazuje.
     *
     * Roční akumulátor benefitu se tu NEKONTROLUJE. Vzniká výhradně schválením
     * ({@see approve()}) a sem se dojde jen u vstupu ve stavu `draft`, takže by
     * ta větev nemohla nikdy nastat — kontrola, která nemá co chytit, vypadá
     * jako ochrana a není. Akumulátor hlídá `reverseBenefit()`, kde je jediná
     * cesta ke schválenému benefitu, a zpět do konceptu se vstup nedostane.
     */
    private function assertNoMovement(
        PDO $pdo,
        int $supplierId,
        int $id,
        string $periodStart,
    ): void {
        $travel = $pdo->prepare(
            'SELECT 1
               FROM payroll_travel_compensation_links
              WHERE supplier_id = ? AND input_id = ?
              LIMIT 1'
        );
        $travel->execute([$supplierId, $id]);
        if ($travel->fetchColumn() !== false) {
            throw new PayrollInputCancellationException(
                'input_has_movement',
                'Vstup je navázaný na vyúčtování pracovní cesty; zrušit ho nelze.',
            );
        }

        $import = $pdo->prepare(
            'SELECT 1
               FROM payroll_input_import_rows
              WHERE supplier_id = ? AND input_id = ?
              LIMIT 1'
        );
        $import->execute([$supplierId, $id]);
        if ($import->fetchColumn() !== false) {
            throw new PayrollInputCancellationException(
                'input_has_movement',
                'Vstup vznikl importem a drží vazbu na jeho řádek; zrušit ho nelze.',
            );
        }

        $this->assertNotFrozenInApprovedRevision($pdo, $supplierId, $id, $periodStart);
    }

    /**
     * Vstup zmrazený v revizi mzdového běhu se nesmí utrhnout od snapshotu,
     * který na něj ukazuje — ani zrušením konceptu, ani stornem benefitu.
     */
    private function assertNotFrozenInApprovedRevision(
        PDO $pdo,
        int $supplierId,
        int $id,
        string $periodStart,
    ): void {
        $frozen = $pdo->prepare(
            'SELECT 1
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND run.period_start = ?
                AND JSON_CONTAINS(
                        COALESCE(
                            JSON_EXTRACT(
                                revision.input_snapshot_json,
                                "$.people[*].employments[*].inputs[*].id"
                            ),
                            JSON_ARRAY()
                        ),
                        ?
                    )
              LIMIT 1'
        );
        $frozen->execute([$supplierId, $periodStart, (string) $id]);
        if ($frozen->fetchColumn() !== false) {
            throw new PayrollInputCancellationException(
                'input_has_movement',
                'Vstup je zmrazený v revizi mzdového běhu; zrušit ho nelze.',
            );
        }
    }

    /**
     * Úhrn zákonného koše osvobození za rok — NAPŘÍČ VŠEMI SLOŽKAMI téhož koše
     * a napříč všemi vztahy téže osoby u téhož zaměstnavatele.
     *
     * Klíč akumulátoru je `employee_id`, ne `employment_id`, takže souběžné
     * vztahy sdílí koš samy od sebe. Sčítá se HRUBÁ částka plnění, ne jen její
     * osvobozená část: koš čerpá celé plnění a další plnění se proti němu
     * poměřuje stejně.
     */
    public function annualBasketTotal(
        int $supplierId,
        int $employeeId,
        PayrollBenefitExemptionBasket $basket,
        int $year,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(accumulator.amount_minor), 0)
               FROM payroll_benefit_accumulators accumulator
               JOIN payroll_component_definitions component
                 ON component.supplier_id = accumulator.supplier_id
                AND component.id = accumulator.component_id
              WHERE accumulator.supplier_id = ?
                AND accumulator.employee_id = ?
                AND accumulator.tax_year = ?
                AND accumulator.status = "active"
                AND component.exemption_basket = ?'
        );
        $stmt->execute([$supplierId, $employeeId, $year, $basket->value]);

        return PayrollTimeValue::int(
            $stmt->fetchColumn(),
            'annual_basket_total',
        );
    }

    /**
     * Úhrn koše za MĚSÍC — protějšek {@see annualBasketTotal()} pro koše, jejichž
     * rozhodným obdobím je kalendářní měsíc (§ 6 odst. 9 písm. b) a i) ZDP).
     *
     * Období se bere z mzdového VSTUPU, ne z akumulátoru: ten nese jen zdaňovací
     * rok. Zpětný vstup do minulého měsíce se proto poměřuje proti tomu měsíci,
     * ne proti měsíci, ve kterém se zadával.
     */
    public function monthlyBasketTotal(
        int $supplierId,
        int $employeeId,
        PayrollBenefitExemptionBasket $basket,
        string $periodStart,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(accumulator.amount_minor), 0)
               FROM payroll_benefit_accumulators accumulator
               JOIN payroll_inputs input
                 ON input.supplier_id = accumulator.supplier_id
                AND input.id = accumulator.input_id
               JOIN payroll_component_definitions component
                 ON component.supplier_id = accumulator.supplier_id
                AND component.id = accumulator.component_id
              WHERE accumulator.supplier_id = ?
                AND accumulator.employee_id = ?
                AND accumulator.status = "active"
                AND input.period_start = ?
                AND component.exemption_basket = ?'
        );
        $stmt->execute([$supplierId, $employeeId, $periodStart, $basket->value]);

        return PayrollTimeValue::int(
            $stmt->fetchColumn(),
            'monthly_basket_total',
        );
    }

    /**
     * Počet nároků zmrazený u dříve schválených příspěvků stejného měsíce.
     * Neauditovatelný legacy nebo rozdílný počet je pojmenovaný blocker, ne
     * příležitost znovu použít měsíční pooling.
     */
    public function mealBasketEntitlements(
        int $supplierId,
        int $employeeId,
        PayrollBenefitExemptionBasket $basket,
        string $periodStart,
    ): ?int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.benefit_allocation_json
               FROM payroll_benefit_accumulators accumulator
               JOIN payroll_inputs input
                 ON input.supplier_id = accumulator.supplier_id
                AND input.id = accumulator.input_id
               JOIN payroll_component_definitions component
                 ON component.supplier_id = accumulator.supplier_id
                AND component.id = accumulator.component_id
              WHERE accumulator.supplier_id = ?
                AND accumulator.employee_id = ?
                AND accumulator.status = "active"
                AND input.period_start = ?
                AND component.exemption_basket = ?'
        );
        $stmt->execute([$supplierId, $employeeId, $periodStart, $basket->value]);
        $expected = null;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            if (!is_string($json)) {
                throw new \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException(
                    'Dříve schválený příspěvek nemá auditovatelnou alokaci na jednotlivé nároky.',
                );
            }
            $allocation = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $count = is_array($allocation) ? ($allocation['entitlement_count'] ?? null) : null;
            if (!is_int($count) || $count < 0) {
                throw new \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException(
                    'Dříve schválený příspěvek nemá platný počet nároků v auditní alokaci.',
                );
            }
            if ($expected !== null && $expected !== $count) {
                throw new \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException(
                    'Dříve schválené příspěvky používají rozdílný počet nároků.',
                );
            }
            $expected = $count;
        }

        return $expected;
    }

    /**
     * Dosud vyčerpaný úhrn koše za jeho ROZHODNÉ OBDOBÍ.
     *
     * Které to je, říká samo ustanovení
     * ({@see PayrollBenefitExemptionBasket::accumulatesPerMonth()}), ne volající.
     */
    public function basketTotal(
        int $supplierId,
        int $employeeId,
        PayrollBenefitExemptionBasket $basket,
        int $year,
        string $periodStart,
    ): int {
        return $basket->accumulatesPerMonth()
            ? $this->monthlyBasketTotal($supplierId, $employeeId, $basket, $periodStart)
            : $this->annualBasketTotal($supplierId, $employeeId, $basket, $year);
    }

    public function annualBenefitTotal(
        int $supplierId,
        int $employeeId,
        int $componentId,
        int $year,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(amount_minor), 0)
               FROM payroll_benefit_accumulators
              WHERE supplier_id = ?
                AND employee_id = ?
                AND component_id = ?
                AND tax_year = ?
                AND status = "active"'
        );
        $stmt->execute([$supplierId, $employeeId, $componentId, $year]);
        return PayrollTimeValue::int(
            $stmt->fetchColumn(),
            'annual_benefit_total',
        );
    }

    /**
     * Kontrola referencí mzdového vstupu.
     *
     * Kromě příslušnosti k firmě hlídá i stav vztahu: na `archived` ani `no_show`
     * se mzdový vstup zakládat nesmí. Nabídka vztahů ve formuláři je odfiltrovaná
     * stejně, tohle je serverová pojistka — klient není zdroj pravdy.
     *
     * @param array<string,mixed> $data
     */
    public function assertValidReferences(int $supplierId, array $data): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.status
               FROM payroll_employments employment
               JOIN payroll_component_definitions component
                 ON component.supplier_id = employment.supplier_id
                AND component.id = ?
                AND component.is_active = 1
                AND component.valid_from <= ?
                AND (component.valid_to IS NULL OR component.valid_to >= ?)
              WHERE employment.supplier_id = ?
                AND employment.id = ?
                AND employment.employee_id = ?'
        );
        $stmt->execute([
            $data['component_id'],
            $data['period_start'],
            $data['period_start'],
            $supplierId,
            $data['employment_id'],
            $data['employee_id'],
        ]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            throw new \InvalidArgumentException(
                'Zaměstnanec, vztah nebo účinná mzdová složka nepatří této firmě.'
            );
        }
        if (in_array((string) $status, ['archived', 'no_show'], true)) {
            throw new \InvalidArgumentException(
                'Pracovní vztah je archivovaný nebo nenastoupil; mzdový vstup na něj založit nelze.'
            );
        }
    }

    private function lockEmployee(PDO $pdo, int $supplierId, int $employeeId): void
    {
        $stmt = $pdo->prepare(
            'SELECT id
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employeeId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException(
                'Zaměstnanec nepatří této firmě.'
            );
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        foreach ([
            'id',
            'supplier_id',
            'employee_id',
            'employment_id',
            'component_id',
            'amount_minor',
            'quantity_milliunits',
            'import_id',
            'row_version',
            'created_by',
            'approved_by',
            'benefit_exempt_minor',
            'benefit_taxable_minor',
        ] as $key) {
            if (($row[$key] ?? null) !== null) {
                $row[$key] = PayrollTimeValue::int($row[$key], $key);
            }
        }
        unset($row['component_snapshot_hash'], $row['source_snapshot_hash']);
        return $row;
    }

}
