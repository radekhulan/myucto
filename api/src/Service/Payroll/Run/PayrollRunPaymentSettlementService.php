<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Kontrola platebního ledgeru pro příkaz `mark_paid`.
 *
 * Platební dávka ani její alokace nejsou důkazem úhrady. Jedinou platební
 * skutečností je reconciliation ledger `payroll_payment_matches`, kde kladný
 * match dokládá bankovní pohyb nebo zaúčtovaný pokladní doklad a záporná
 * reverze jeho účinek ruší.
 *
 * Částečně uhrazený běh do `paid` nesmí. `paid` je poslední brána před
 * `close` a jediné místo, kde se rozdíl dá ještě odhalit; kdyby se do něj
 * pustil běh s nepokrytým zbytkem, rozdíl by se ztratil v uzavřeném období.
 * Legitimní cesta pro doplatky a opravy vede přes `request_correction`
 * a novou revizi, ne přes „skoro uhrazeno".
 */
final class PayrollRunPaymentSettlementService
{
    private const KIND_LABELS = [
        'net_wage' => 'čistá mzda',
        'social_insurance' => 'sociální pojištění',
        'health_insurance' => 'zdravotní pojištění',
        'advance_tax' => 'záloha na daň',
        'withholding_tax' => 'srážková daň',
        'deduction' => 'srážka',
        'enforcement' => 'exekuční srážka',
        'insolvency' => 'insolvenční srážka',
        'benefit' => 'benefit',
        'statutory_insurance' => 'zákonné pojištění',
        'other' => 'jiný závazek',
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   liability_count:int,
     *   batch_count:int,
     *   required_minor:int,
     *   allocated_minor:int,
     *   settled_minor:int,
     *   incoming_unsettled_count:int,
     *   uncovered:list<array{
     *     liability_id:int,
     *     liability_kind:string,
     *     direction:string,
     *     employee_name:?string,
     *     currency_code:string,
     *     uncovered_minor:int
     *   }>
     * }
     */
    public function inspect(int $supplierId, int $revisionId): array
    {
        if ($supplierId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a revize kontroly úhrad musí být kladná čísla.',
            );
        }
        $revisionStatement = $this->db->pdo()->prepare(
            'SELECT run_id, revision_no
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $revisionStatement->execute([$supplierId, $revisionId]);
        $revision = $revisionStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($revision) || array_is_list($revision)) {
            throw new \OutOfBoundsException(
                'Mzdová revize pro kontrolu skutečných úhrad nebyla nalezena.',
            );
        }
        $runId = (int) ($revision['run_id'] ?? 0);
        $revisionNo = (int) ($revision['revision_no'] ?? 0);
        if ($runId <= 0 || $revisionNo <= 0) {
            throw new \UnexpectedValueException(
                'Mzdová revize pro kontrolu skutečných úhrad je neplatná.',
            );
        }

        $statement = $this->db->pdo()->prepare(
            'SELECT liability.id,
                    liability.liability_kind,
                    liability.direction,
                    liability.currency_code,
                    liability.amount_minor,
                    employee.full_name AS employee_name,
                    (
                      SELECT COALESCE(SUM(allocation.amount_minor), 0)
                        FROM payroll_payment_allocations allocation
                       WHERE allocation.supplier_id = liability.supplier_id
                         AND allocation.liability_id = liability.id
                    ) AS allocated_minor,
                    (
                      SELECT COALESCE(SUM(payment_match.amount_minor), 0)
                        FROM payroll_payment_matches payment_match
                       WHERE payment_match.supplier_id = liability.supplier_id
                         AND payment_match.liability_id = liability.id
                    ) AS settled_minor,
                    (
                      SELECT COUNT(DISTINCT payment_item.batch_id)
                        FROM payroll_payment_allocations allocation
                        JOIN payroll_payment_items payment_item
                          ON payment_item.supplier_id = allocation.supplier_id
                         AND payment_item.id = allocation.item_id
                       WHERE allocation.supplier_id = liability.supplier_id
                         AND allocation.liability_id = liability.id
                    ) AS batch_count
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions liability_revision
                 ON liability_revision.supplier_id = liability.supplier_id
                AND liability_revision.id = liability.revision_id
          LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = liability.supplier_id
                AND employee.id = liability.employee_id
              WHERE liability.supplier_id = ?
                AND liability_revision.run_id = ?
                AND liability_revision.revision_no <= ?
           ORDER BY liability_revision.revision_no,
                    liability.liability_kind, liability.id'
        );
        $statement->execute([$supplierId, $runId, $revisionNo]);

        $liabilityCount = 0;
        $batchIds = 0;
        $required = 0;
        $allocated = 0;
        $settled = 0;
        $incomingUnsettledCount = 0;
        $uncovered = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \UnexpectedValueException(
                    'Databáze vrátila neplatný platební závazek.',
                );
            }
            $amount = (int) $row['amount_minor'];
            $allocatedForLiability = (int) $row['allocated_minor'];
            $settledForLiability = (int) $row['settled_minor'];
            if ($amount <= 0
                || $allocatedForLiability < 0
                || $allocatedForLiability > $amount
                || $settledForLiability < 0
                || $settledForLiability > $amount
                || ((string) $row['direction'] === 'outgoing'
                    && $settledForLiability > $allocatedForLiability)
            ) {
                throw new \UnexpectedValueException(
                    'Součty skutečných úhrad mzdového závazku jsou mimo povolené meze.',
                );
            }
            ++$liabilityCount;
            $batchIds += (int) $row['batch_count'];
            $required += $amount;
            $allocated += $allocatedForLiability;
            $settled += $settledForLiability;
            if ($settledForLiability >= $amount) {
                continue;
            }
            $name = $row['employee_name'] ?? null;
            $direction = (string) $row['direction'];
            if (!in_array($direction, ['outgoing', 'incoming'], true)) {
                throw new \UnexpectedValueException(
                    'Směr mzdového závazku není platný.',
                );
            }
            if ($direction === 'incoming') {
                ++$incomingUnsettledCount;
            }
            $uncovered[] = [
                'liability_id' => (int) $row['id'],
                'liability_kind' => (string) $row['liability_kind'],
                'direction' => $direction,
                'employee_name' => is_string($name) && trim($name) !== ''
                    ? trim($name)
                    : null,
                'currency_code' => (string) $row['currency_code'],
                'uncovered_minor' => $amount - $settledForLiability,
            ];
        }

        return [
            'liability_count' => $liabilityCount,
            'batch_count' => $batchIds,
            'required_minor' => $required,
            'allocated_minor' => $allocated,
            'settled_minor' => $settled,
            'incoming_unsettled_count' => $incomingUnsettledCount,
            'uncovered' => $uncovered,
        ];
    }

    /**
     * Lidsky čitelné vyčíslení nepokrytého zbytku. Účetní z něj musí poznat,
     * kolik chybí a u čeho — ne že „chybí platební dávka".
     *
     * @param array{
     *   liability_count:int,
     *   batch_count:int,
     *   required_minor:int,
     *   allocated_minor:int,
     *   settled_minor:int,
     *   incoming_unsettled_count:int,
     *   uncovered:list<array{
     *     liability_id:int,
     *     liability_kind:string,
     *     direction:string,
     *     employee_name:?string,
     *     currency_code:string,
     *     uncovered_minor:int
     *   }>
     * } $coverage
     */
    public function blockingReason(array $coverage): string
    {
        $missing = $coverage['required_minor'] - $coverage['settled_minor'];
        $details = [];
        foreach (array_slice($coverage['uncovered'], 0, 5) as $item) {
            $label = self::KIND_LABELS[$item['liability_kind']]
                ?? $item['liability_kind'];
            if ($item['employee_name'] !== null) {
                $label .= ' — ' . $item['employee_name'];
            }
            if ($item['direction'] === 'incoming') {
                $label = 'příchozí opravná vratka: ' . $label;
            }
            $details[] = sprintf(
                '%s %s %s',
                $label,
                self::amount($item['uncovered_minor']),
                $item['currency_code'],
            );
        }
        $rest = count($coverage['uncovered']) - count($details);
        if ($rest > 0) {
            $details[] = "a další ({$rest})";
        }

        $reason = sprintf(
            'Mzdový běh nelze označit za uhrazený: skutečné úhrady doložené '
            . 'bankovním pohybem nebo zaúčtovaným pokladním dokladem nepokrývají '
            . '%s z celkových %s (chybí %d z %d závazků). Nepokryto: %s. '
            . 'Spárujte chybějící úhrady v agendě Mzdové příkazy a úhrady. '
            . 'Pokud se částky změnily, vyžádejte u běhu opravnou revizi.',
            self::amount($missing),
            self::amount($coverage['required_minor']),
            count($coverage['uncovered']),
            $coverage['liability_count'],
            implode('; ', $details),
        );
        if ($coverage['incoming_unsettled_count'] > 0) {
            $reason .= ' Příchozí opravné částky nevkládejte do odchozí '
                . 'platební dávky. Spárujte skutečně přijatý bankovní pohyb '
                . 'nebo zaúčtovaný příjmový pokladní doklad přímo s vratkou.';
        }

        return $reason;
    }

    private static function amount(int $minor): string
    {
        return number_format($minor / 100, 2, ',', "\u{00a0}");
    }
}
