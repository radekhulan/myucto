<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;
use PDO;

final class PayrollPaymentQueryService
{
    /**
     * Tvrdý strop tabulky platebních závazků. Za jeden měsíc vzniká závazek na
     * každou čistou mzdu plus odvody za každou instituci a každou exekuci —
     * u větší firmy jsou to tisíce řádků v jediné odpovědi.
     */
    public const LIST_MAX_LIMIT = 200;

    public const LIST_DEFAULT_LIMIT = 50;

    private const BATCHABLE_LIABILITY_KINDS = [
        'net_wage',
        'health_insurance',
        'social_insurance',
        'advance_tax',
        'withholding_tax',
        'enforcement',
        'risky_savings',
    ];

    private readonly PayrollPaymentBatchQueryService $batchQueries;

    public function __construct(
        private readonly Connection $db,
        ?PayrollPaymentBatchQueryService $batchQueries = null,
    ) {
        $this->batchQueries = $batchQueries
            ?? new PayrollPaymentBatchQueryService(
                $db,
                new CzechBankAccountValidator(),
                new IbanValidator(),
            );
    }

    /** @return list<array<string,mixed>> */
    public function payerOptions(int $supplierId): array
    {
        return $this->batchQueries->payerOptions($supplierId);
    }

    /** @return list<array<string,mixed>> */
    public function batchesForPeriod(
        int $supplierId,
        string $period,
    ): array {
        return $this->batchQueries->batchesForPeriod(
            $supplierId,
            $period,
        );
    }

    /**
     * @return array{items:list<array{
     *   id:int,
     *   run_id:int,
     *   revision_id:int,
     *   revision_no:int,
     *   employee_id:?int,
     *   employee_name:?string,
     *   recipient_name:?string,
     *   institution_type:?string,
     *   institution_code:?string,
     *   liability_kind:string,
     *   direction:string,
     *   recipient_kind:string,
     *   payment_target_status:string,
     *   payment_target_masked:?string,
     *   batch_eligibility:string,
     *   batch_block_reason:?string,
     *   revision_kind:string,
     *   due_on:string,
     *   currency_code:string,
     *   amount_minor:int,
     *   allocated_minor:int,
     *   settled_minor:int,
     *   state:string,
     *   created_at:string
     * }>,total:int,totals:array{
     *   amount_minor:int,allocated_minor:int,settled_minor:int
     * }}
     */
    public function listForPeriod(
        int $supplierId,
        string $period,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být kladné číslo.');
        }
        if (preg_match('/^(20[0-9]{2}|21[0-9]{2})-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException('Mzdové období musí mít tvar RRRR-MM.');
        }
        // Strop se klampuje i tady, ne jen na HTTP hranici, aby ho nešlo
        // objednat ani jinou cestou než akcí.
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $statement = $this->db->pdo()->prepare(
            'SELECT liability.id, revision.run_id, liability.revision_id,
                    revision.revision_no, revision.revision_kind,
                    liability.employee_id,
                    employee.full_name AS employee_name,
                    COALESCE(
                      employee.full_name,
                      institution_account.institution_name
                    ) AS recipient_name,
                    institution.institution_type,
                    institution.institution_code,
                    liability.liability_kind, liability.direction,
                    CASE
                      WHEN liability.recipient_reference LIKE "employee-cash:%"
                        THEN "cash"
                      ELSE "bank"
                    END AS recipient_kind,
                    COALESCE(
                      institution_account.bank_account_masked,
                      employee_account.bank_account_masked
                    ) AS payment_target_masked,
                    liability.due_on, liability.currency_code,
                    liability.amount_minor, liability.created_at,
                    (
                      SELECT COALESCE(SUM(allocation.amount_minor), 0)
                        FROM payroll_payment_allocations allocation
                       WHERE allocation.supplier_id = liability.supplier_id
                         AND allocation.liability_id = liability.id
                    ) AS allocated_minor,
                    (
                      SELECT COALESCE(SUM(payment_match.amount_minor), 0)
                        FROM payroll_payment_allocations allocation
                        JOIN payroll_payment_matches payment_match
                          ON payment_match.supplier_id =
                             allocation.supplier_id
                         AND payment_match.allocation_id = allocation.id
                       WHERE allocation.supplier_id = liability.supplier_id
                         AND allocation.liability_id = liability.id
                    ) AS settled_minor
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
               LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = liability.supplier_id
                AND employee.id = liability.employee_id
               LEFT JOIN payroll_person_accounts employee_account
                 ON employee_account.supplier_id = liability.supplier_id
                AND employee_account.id = CASE
                  WHEN liability.recipient_reference
                    LIKE "employee-account:%"
                  THEN CAST(SUBSTRING_INDEX(
                    liability.recipient_reference, ":", -1
                  ) AS UNSIGNED)
                  ELSE NULL
                END
               LEFT JOIN payroll_institution_accounts institution_account
                 ON institution_account.supplier_id = liability.supplier_id
                AND institution_account.id = CASE
                  WHEN liability.recipient_reference
                    LIKE "institution:%:account:%"
                  THEN CAST(SUBSTRING_INDEX(
                    liability.recipient_reference, ":", -1
                  ) AS UNSIGNED)
                  ELSE NULL
                END
               LEFT JOIN payroll_institutions institution
                 ON institution.supplier_id =
                    institution_account.supplier_id
                AND institution.id = institution_account.institution_id
              WHERE liability.supplier_id = ?
                AND run.period_start = CONCAT(?, "-01")
              ORDER BY liability.due_on, employee.full_name, liability.id
              LIMIT ? OFFSET ?'
        );
        $statement->bindValue(1, $supplierId, PDO::PARAM_INT);
        $statement->bindValue(2, $period, PDO::PARAM_STR);
        $statement->bindValue(3, $limit, PDO::PARAM_INT);
        $statement->bindValue(4, $offset, PDO::PARAM_INT);
        $statement->execute();

        // Součty se počítají nad CELÝM obdobím, ne nad stránkou. Jsou to
        // peníze — číslo „celkem k úhradě", které by ve skutečnosti znamenalo
        // „celkem na téhle stránce", je horší než žádné. Znaménko se řídí
        // směrem stejně jako u jednotlivého řádku: příchozí závazek částku
        // odečítá.
        $signed = static fn (string $expression): string =>
            'COALESCE(SUM(CASE WHEN liability.direction = "incoming"
                               THEN -(' . $expression . ')
                               ELSE (' . $expression . ') END), 0)';
        $allocated = '(SELECT COALESCE(SUM(allocation.amount_minor), 0)
                         FROM payroll_payment_allocations allocation
                        WHERE allocation.supplier_id = liability.supplier_id
                          AND allocation.liability_id = liability.id)';
        $settled = '(SELECT COALESCE(SUM(payment_match.amount_minor), 0)
                       FROM payroll_payment_allocations allocation
                       JOIN payroll_payment_matches payment_match
                         ON payment_match.supplier_id = allocation.supplier_id
                        AND payment_match.allocation_id = allocation.id
                      WHERE allocation.supplier_id = liability.supplier_id
                        AND allocation.liability_id = liability.id)';

        $countStatement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS row_count,
                    ' . $signed('liability.amount_minor') . ' AS amount_minor,
                    ' . $signed($allocated) . ' AS allocated_minor,
                    ' . $signed($settled) . ' AS settled_minor
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE liability.supplier_id = ?
                AND run.period_start = CONCAT(?, "-01")'
        );
        $countStatement->execute([$supplierId, $period]);
        $aggregate = self::row($countStatement->fetch(PDO::FETCH_ASSOC));
        $total = self::integer($aggregate, 'row_count');
        $totals = [
            'amount_minor' => self::integer($aggregate, 'amount_minor'),
            'allocated_minor' => self::integer($aggregate, 'allocated_minor'),
            'settled_minor' => self::integer($aggregate, 'settled_minor'),
        ];

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::row($row);
            $amount = self::integer($row, 'amount_minor');
            $allocated = self::integer($row, 'allocated_minor');
            $settled = self::integer($row, 'settled_minor');
            if ($amount <= 0 || $allocated < 0 || $allocated > $amount
                || $settled < 0 || $settled > $allocated
            ) {
                throw new \UnexpectedValueException(
                    'Součty platebního závazku jsou mimo povolené meze.',
                );
            }
            $employeeId = self::nullableInteger($row, 'employee_id');
            $employeeName = $row['employee_name'] ?? null;
            if ($employeeName !== null && !is_string($employeeName)) {
                throw new \UnexpectedValueException(
                    'Jméno osoby u platebního závazku není text.',
                );
            }
            $result[] = [
                'id' => self::integer($row, 'id'),
                'run_id' => self::integer($row, 'run_id'),
                'revision_id' => self::integer($row, 'revision_id'),
                'revision_no' => self::integer($row, 'revision_no'),
                'employee_id' => $employeeId,
                'employee_name' => $employeeName,
                'recipient_name' => self::nullableText(
                    $row,
                    'recipient_name',
                ),
                'institution_type' => self::nullableText(
                    $row,
                    'institution_type',
                ),
                'institution_code' => self::nullableText(
                    $row,
                    'institution_code',
                ),
                'liability_kind' => self::text($row, 'liability_kind'),
                'direction' => self::text($row, 'direction'),
                'recipient_kind' => self::text($row, 'recipient_kind'),
                'payment_target_status' => 'ready',
                'payment_target_masked' => self::nullableText(
                    $row,
                    'payment_target_masked',
                ),
                'batch_eligibility' =>
                    self::text($row, 'direction') === 'outgoing'
                    && in_array(
                        self::text($row, 'liability_kind'),
                        self::BATCHABLE_LIABILITY_KINDS,
                        true,
                    )
                        ? 'ready'
                        : 'blocked',
                'batch_block_reason' =>
                    self::text($row, 'direction') === 'incoming'
                        ? 'unsupported_direction'
                        : (
                            in_array(
                                self::text($row, 'liability_kind'),
                                self::BATCHABLE_LIABILITY_KINDS,
                                true,
                            )
                                ? null
                                : 'unsupported_liability_kind'
                        ),
                'revision_kind' => self::text(
                    $row,
                    'revision_kind',
                ),
                'due_on' => self::text($row, 'due_on'),
                'currency_code' => self::text($row, 'currency_code'),
                'amount_minor' => $amount,
                'allocated_minor' => $allocated,
                'settled_minor' => $settled,
                'state' => self::state($amount, $allocated, $settled),
                'created_at' => self::text($row, 'created_at'),
            ];
        }

        return ['items' => $result, 'total' => $total, 'totals' => $totals];
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                'Databáze vrátila neplatný platební závazek.',
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Platební závazek nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private static function state(int $amount, int $allocated, int $settled): string
    {
        if ($settled === $amount) {
            return 'settled';
        }
        if ($settled > 0) {
            return 'partially_settled';
        }
        if ($allocated === $amount) {
            return 'batched';
        }
        if ($allocated > 0) {
            return 'partially_batched';
        }

        return 'open';
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException("Hodnota {$key} není celé číslo.");
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($validated)) {
            throw new \UnexpectedValueException("Hodnota {$key} není celé číslo.");
        }

        return $validated;
    }

    /** @param array<string,mixed> $row */
    private static function nullableInteger(array $row, string $key): ?int
    {
        return ($row[$key] ?? null) === null ? null : self::integer($row, $key);
    }

    /** @param array<string,mixed> $row */
    private static function nullableText(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Hodnota {$key} není text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("Hodnota {$key} není neprázdný text.");
        }

        return $value;
    }
}
