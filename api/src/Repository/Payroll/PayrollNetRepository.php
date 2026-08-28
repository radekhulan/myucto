<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;
use PDOException;

/**
 * Append-only evidence SRÁŽEK ze mzdy (`payroll_deduction_ledger`).
 *
 * Repozitář kdysi držel i zápis čisté mzdy do `payroll_net_results` a rozpisu
 * výplaty do `payroll_payout_allocations`. Ten zápis se do pipeline nikdy
 * nezapojil (v `src/` nebyl jediný volající od vzniku v 2c4278051) a MEZITÍM
 * ho model přerostl ve třech bodech, takže by dnes zapisoval NESPRÁVNÁ data:
 *
 *  1. Základ rozpisu. `saveCalculation()` vyžadovalo, aby se alokace rovnaly
 *     `net_payable_minor`, tedy čisté mzdě PŘED exekučními srážkami. Skutečné
 *     platby ale {@see \MyInvoice\Service\Payroll\Payment\PayrollNetWageLiabilityMaterializer}
 *     rozděluje z `payable_after_enforcement_minor`. Jakmile je na osobě
 *     exekuce, oba rozpisy si odporují — a tabulky jsou od migrace 1631
 *     DB-neměnné, takže by ten rozpor byl trvalý.
 *  2. Zápočet na účet společníka (`PayrollPartnerSettlement`) není výplata a do
 *     platebních závazků se záměrně nedostane; do alokací by se dostal.
 *  3. `payroll_net_results.net_payable_minor` je `BIGINT UNSIGNED`, jenže čistá
 *     mzda smí být záporná (doplatek ZP do minimálního vyměřovacího základu,
 *     § 3 odst. 10 z. č. 592/1992 Sb.). Zápis by na takovém měsíci spadl.
 *
 * Kde ta data doopravdy jsou (a odkud je čtou VŠECHNY produkční cesty —
 * {@see \MyInvoice\Service\Payroll\Net\PayrollNetResultQueryService}, a přes ni
 * `PayrollNetResultAction` i součinnost XMLZAM):
 *  * rozklad čisté mzdy — zmrazený `payroll_run_revisions.result_snapshot_json`
 *    a `payroll_run_persons.result_json` (klíč `statutory.net_pay`),
 *  * rozpis výplaty na víc cílů — `payroll_payment_liabilities`
 *    (`liability_kind = 'net_wage'`), jeden závazek na cíl.
 *
 * Obě tabulky jsou proto mrtvé a prokazatelně prázdné ve všech databázích
 * včetně ostré. Fyzický `DROP` je samostatná migrace (viz W27 report): vyžaduje
 * přepsat trigger `trg_payroll_run_result_period_propagate` z migrace 1593,
 * který do `payroll_net_results` propaguje období rodičovského běhu.
 */
final class PayrollNetRepository
{
    private const SAVEPOINT = 'payroll_net_repository';

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $metadata
     */
    public function appendLedgerMovement(
        int $supplierId,
        ?int $agreementId,
        int $revisionId,
        int $employeeId,
        string $eventKind,
        int $amountMinor,
        string $eventKey,
        ?int $sourceLedgerId,
        array $metadata,
        ?int $actorUserId,
    ): int {
        $this->validateLedgerShape($eventKind, $amountMinor, $sourceLedgerId, $eventKey);
        $keyHash = hash('sha256', $eventKey, true);
        $metadataJson = CanonicalJson::encode($metadata);

        return $this->transactional(function () use (
            $supplierId,
            $agreementId,
            $revisionId,
            $employeeId,
            $eventKind,
            $amountMinor,
            $keyHash,
            $sourceLedgerId,
            $metadataJson,
            $actorUserId,
        ): int {
            $replayedId = $this->ledgerReplayId(
                $this->ledgerByEventKey($supplierId, $keyHash),
                $agreementId,
                $revisionId,
                $employeeId,
                $eventKind,
                $amountMinor,
                $sourceLedgerId,
                $metadataJson,
            );
            if ($replayedId !== null) {
                return $replayedId;
            }
            if ($sourceLedgerId !== null) {
                $this->validateReversalSource(
                    $supplierId,
                    $employeeId,
                    $agreementId,
                    $eventKind,
                    $amountMinor,
                    $sourceLedgerId,
                );
            }
            try {
                $stmt = $this->db->pdo()->prepare(
                    'INSERT INTO payroll_deduction_ledger
                        (supplier_id, agreement_id, revision_id, employee_id,
                         event_kind, amount_minor, event_key_hash,
                         source_ledger_id, metadata_json, actor_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $supplierId,
                    $agreementId,
                    $revisionId,
                    $employeeId,
                    $eventKind,
                    $amountMinor,
                    $keyHash,
                    $sourceLedgerId,
                    $metadataJson,
                    $actorUserId,
                ]);
                $id = (int) $this->db->pdo()->lastInsertId();
            } catch (PDOException $e) {
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }
                return $this->ledgerReplayId(
                    $this->ledgerByEventKey($supplierId, $keyHash),
                    $agreementId,
                    $revisionId,
                    $employeeId,
                    $eventKind,
                    $amountMinor,
                    $sourceLedgerId,
                    $metadataJson,
                    $e,
                ) ?? throw new \LogicException('Duplicitní ledger pohyb nebyl nalezen.');
            }
            if ($agreementId !== null && in_array($eventKind, ['withheld', 'reversed'], true)) {
                $this->updateAgreementBalance(
                    $supplierId,
                    $agreementId,
                    $eventKind === 'withheld' ? $amountMinor : -abs($amountMinor),
                );
            }
            return $id;
        });
    }

    /** @return list<array<string,mixed>> */
    public function ledger(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_deduction_ledger
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $result = [];
        foreach (PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'deduction_ledger',
        ) as $row) {
            $result[] = self::castLedger($row);
        }
        return $result;
    }

    /**
     * @return list<array{
     *   agreement_id:int,
     *   employee_id:int,
     *   amount_minor:int
     * }>
     */
    public function deductionMovementsForRun(int $supplierId, int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ledger.agreement_id, ledger.employee_id, ledger.amount_minor
               FROM payroll_deduction_ledger ledger
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = ledger.supplier_id
                AND revision.id = ledger.revision_id
              WHERE ledger.supplier_id = ? AND revision.run_id = ?
                AND ledger.agreement_id IS NOT NULL
                AND ledger.event_kind IN ("withheld", "reversed")
              ORDER BY ledger.id
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $runId]);
        $result = [];
        foreach (PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'deduction_run_movements',
        ) as $row) {
            $result[] = [
                'agreement_id' => self::boundedInt(
                    $row['agreement_id'] ?? null,
                    'agreement_id',
                ),
                'employee_id' => self::boundedInt(
                    $row['employee_id'] ?? null,
                    'employee_id',
                ),
                'amount_minor' => self::boundedInt(
                    $row['amount_minor'] ?? null,
                    'amount_minor',
                ),
            ];
        }

        return $result;
    }

    public function deductionNetForRevision(
        int $supplierId,
        int $revisionId,
        int $employeeId,
        int $agreementId,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT amount_minor
               FROM payroll_deduction_ledger
              WHERE supplier_id = ? AND revision_id = ?
                AND employee_id = ? AND agreement_id = ?
                AND event_kind IN ("withheld", "reversed")
              ORDER BY id
              FOR UPDATE'
        );
        $stmt->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $agreementId,
        ]);
        $total = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $amount) {
            $total = self::checkedAdd(
                $total,
                self::boundedInt($amount, 'revision_deduction_amount'),
                'Součet pohybů revize překročil číselný limit.',
            );
        }

        return $total;
    }

    /**
     * @return list<array{id:int,available_minor:int}>
     */
    public function availableWithholdingsForRun(
        int $supplierId,
        int $runId,
        int $employeeId,
        int $agreementId,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ledger.id, ledger.amount_minor
               FROM payroll_deduction_ledger ledger
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = ledger.supplier_id
                AND revision.id = ledger.revision_id
              WHERE ledger.supplier_id = ? AND revision.run_id = ?
                AND ledger.employee_id = ? AND ledger.agreement_id = ?
                AND ledger.event_kind = "withheld"
              ORDER BY ledger.id
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $runId, $employeeId, $agreementId]);
        $result = [];
        foreach (PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'available_withholdings',
        ) as $row) {
            $sourceId = self::boundedInt($row['id'] ?? null, 'withholding.id');
            $sourceAmount = self::boundedInt(
                $row['amount_minor'] ?? null,
                'withholding.amount_minor',
            );
            if ($sourceAmount <= 0) {
                throw new \UnexpectedValueException(
                    'Zdrojový pohyb srážky musí být kladný.',
                );
            }
            $reversed = $this->reversedAmount($supplierId, $sourceId);
            if ($reversed > $sourceAmount) {
                throw new \DomainException(
                    'Zdrojový pohyb srážky je obrácen nad původní částku.',
                );
            }
            if ($reversed < $sourceAmount) {
                $result[] = [
                    'id' => $sourceId,
                    'available_minor' => $sourceAmount - $reversed,
                ];
            }
        }

        return $result;
    }

    private function validateLedgerShape(
        string $eventKind,
        int $amountMinor,
        ?int $sourceLedgerId,
        string $eventKey,
    ): void {
        $positive = in_array($eventKind, ['withheld', 'paid'], true);
        $reversal = in_array($eventKind, ['reversed', 'payment_reversed'], true);
        if ($eventKey === ''
            || (!$positive && !$reversal)
            || ($positive && ($amountMinor <= 0 || $sourceLedgerId !== null))
            || ($reversal && ($amountMinor >= 0 || $sourceLedgerId === null))
        ) {
            throw new \InvalidArgumentException('Neplatný tvar ledger pohybu.');
        }
    }

    private function validateReversalSource(
        int $supplierId,
        int $employeeId,
        ?int $agreementId,
        string $eventKind,
        int $amountMinor,
        int $sourceLedgerId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_deduction_ledger
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $sourceLedgerId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        $requiredKind = $eventKind === 'reversed' ? 'withheld' : 'paid';
        if (!is_array($source)) {
            throw new \DomainException('Zdrojový ledger pohyb není platný pro tuto korekci.');
        }
        $sourceEmployeeId = PayrollTimeValue::int(
            $source['employee_id'] ?? null,
            'source.employee_id',
        );
        $sourceAgreementId = $source['agreement_id'] === null
            ? null
            : PayrollTimeValue::int($source['agreement_id'], 'source.agreement_id');
        $sourceKind = PayrollTimeValue::string(
            $source['event_kind'] ?? null,
            'source.event_kind',
        );
        if ($sourceEmployeeId !== $employeeId
            || $sourceAgreementId !== $agreementId
            || $sourceKind !== $requiredKind
        ) {
            throw new \DomainException('Zdrojový ledger pohyb není platný pro tuto korekci.');
        }
        $sum = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(ABS(amount_minor)), 0)
               FROM payroll_deduction_ledger
              WHERE supplier_id = ? AND source_ledger_id = ?'
        );
        $sum->execute([$supplierId, $sourceLedgerId]);
        $reversed = PayrollTimeValue::int($sum->fetchColumn(), 'reversed_minor');
        $sourceAmount = PayrollTimeValue::int(
            $source['amount_minor'] ?? null,
            'source.amount_minor',
        );
        if ($reversed + abs($amountMinor) > $sourceAmount) {
            throw new \DomainException('Korekce by obrátila více než původní ledger pohyb.');
        }
    }

    private function updateAgreementBalance(
        int $supplierId,
        int $agreementId,
        int $deltaMinor,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_deduction_agreements
                SET withheld_total_minor = withheld_total_minor + ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?
                AND withheld_total_minor + ? >= 0
                AND (
                  total_limit_minor IS NULL
                  OR withheld_total_minor + ? <= total_limit_minor
                )'
        );
        $stmt->execute([$deltaMinor, $supplierId, $agreementId, $deltaMinor, $deltaMinor]);
        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Pohyb překračuje zůstatek nebo limit dohody o srážce.');
        }
    }

    private function reversedAmount(int $supplierId, int $sourceLedgerId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT amount_minor
               FROM payroll_deduction_ledger
              WHERE supplier_id = ? AND source_ledger_id = ?
                AND event_kind = "reversed"
              ORDER BY id
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $sourceLedgerId]);
        $total = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $amount) {
            $value = self::boundedInt($amount, 'reversal.amount_minor');
            if ($value >= 0 || $value === PHP_INT_MIN) {
                throw new \UnexpectedValueException(
                    'Reversal srážky musí být bezpečné záporné celé číslo.',
                );
            }
            $total = self::checkedAdd(
                $total,
                abs($value),
                'Součet reversalů překročil číselný limit.',
            );
        }

        return $total;
    }

    /** @return array<string,mixed>|null */
    private function ledgerByEventKey(int $supplierId, string $keyHash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_deduction_ledger
              WHERE supplier_id = ? AND event_key_hash = ?'
        );
        $stmt->execute([$supplierId, $keyHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            ? self::castLedger(PayrollTimeValue::row($row, 'deduction_ledger'))
            : null;
    }

    /**
     * @param array<string,mixed>|null $existing
     */
    private function ledgerReplayId(
        ?array $existing,
        ?int $agreementId,
        int $revisionId,
        int $employeeId,
        string $eventKind,
        int $amountMinor,
        ?int $sourceLedgerId,
        string $metadataJson,
        ?PDOException $previous = null,
    ): ?int {
        if ($existing === null) {
            return null;
        }
        if ($existing['agreement_id'] !== $agreementId
            || $existing['revision_id'] !== $revisionId
            || $existing['employee_id'] !== $employeeId
            || $existing['event_kind'] !== $eventKind
            || $existing['amount_minor'] !== $amountMinor
            || $existing['source_ledger_id'] !== $sourceLedgerId
            || $existing['metadata_json'] !== $metadataJson
        ) {
            throw new \DomainException(
                'Idempotency klíč už používá jiný pohyb srážky.',
                previous: $previous,
            );
        }
        return PayrollTimeValue::int($existing['id'] ?? null, 'ledger.id');
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castLedger(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'revision_id', 'employee_id', 'amount_minor',
        ] as $field) {
            $row[$field] = PayrollTimeValue::int($row[$field] ?? null, $field);
        }
        foreach (['agreement_id', 'source_ledger_id', 'actor_user_id'] as $field) {
            $row[$field] = $row[$field] === null
                ? null
                : PayrollTimeValue::int($row[$field], $field);
        }
        $row['metadata'] = json_decode(
            PayrollTimeValue::string($row['metadata_json'] ?? null, 'metadata_json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        return $row;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;
        return $driverCode === 1062 || $driverCode === '1062';
    }

    private static function boundedInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value)
            || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1
        ) {
            throw new \UnexpectedValueException("{$field} musí být celé číslo.");
        }
        $negative = str_starts_with($value, '-');
        $digits = $negative ? substr($value, 1) : $value;
        $limit = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit)
                && strcmp($digits, $limit) > 0)
        ) {
            throw new \OverflowException("{$field} překročilo číselný limit.");
        }

        return (int) $value;
    }

    private static function checkedAdd(int $left, int $right, string $message): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \OverflowException($message);
        }

        return $left + $right;
    }
}
