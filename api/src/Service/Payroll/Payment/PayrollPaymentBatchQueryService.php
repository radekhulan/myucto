<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;
use PDO;

final class PayrollPaymentBatchQueryService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CzechBankAccountValidator $czechAccounts,
        private readonly IbanValidator $iban,
    ) {}

    /**
     * @return list<array{
     *   reference:string,
     *   currency_id:int,
     *   currency_code:string,
     *   bank_name:?string,
     *   masked_account:string,
     *   export_formats:list<string>
     * }>
     */
    public function payerOptions(int $supplierId): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma účtů plátce musí být kladné číslo.',
            );
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT id, code, bank_name, account_number, bank_code,
                    iban, bic
               FROM currencies
              WHERE supplier_id = ? AND is_active = 1
                AND (
                  (code = "CZK" AND account_number IS NOT NULL
                    AND bank_code IS NOT NULL)
                  OR (code = "EUR" AND iban IS NOT NULL)
                )
              ORDER BY is_default DESC, code, id',
        );
        $statement->execute([$supplierId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::row($row, 'účet plátce');
            $id = self::integer($row, 'id');
            $code = self::text($row, 'code');
            $masked = null;
            $formats = [];
            if ($code === 'CZK') {
                $account = self::nullableText($row, 'account_number');
                $bankCode = self::nullableText($row, 'bank_code');
                if ($account !== null && $bankCode !== null) {
                    try {
                        $parsed = $this->czechAccounts->parse(
                            $account . '/' . $bankCode,
                        );
                        $masked = $this->maskCzechAccount(
                            $parsed['account_number'],
                            $parsed['bank_code'],
                        );
                        $formats = ['abo'];
                    } catch (\InvalidArgumentException) {
                    }
                }
            } elseif ($code === 'EUR') {
                $iban = self::nullableText($row, 'iban');
                if ($iban !== null) {
                    $normalized = $this->iban->normalize($iban);
                    if ($this->iban->isValid($normalized)) {
                        $masked = $this->maskIban($normalized);
                        $formats = ['sepa'];
                    }
                }
            }
            if ($masked === null) {
                continue;
            }
            $result[] = [
                'reference' => "currency:{$id}",
                'currency_id' => $id,
                'currency_code' => $code,
                'bank_name' => self::nullableText($row, 'bank_name'),
                'masked_account' => $masked,
                'export_formats' => $formats,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{
     *   id:int,
     *   batch_reference:string,
     *   channel:string,
     *   export_format:string,
     *   planned_payment_date:string,
     *   statutory_due_on:?string,
     *   is_shifted:bool,
     *   currency_code:string,
     *   declared_total_minor:int,
     *   declared_item_count:int,
     *   settled_minor:int,
     *   created_at:string,
     *   exports:list<array{
     *     id:int,
     *     revision_no:int,
     *     file_sha256:string,
     *     size_bytes:int,
     *     mime_type:string,
     *     suggested_filename:string,
     *     created_at:string
     *   }>
     * }>
     */
    public function batchesForPeriod(
        int $supplierId,
        string $period,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma platebních dávek musí být kladné číslo.',
            );
        }
        [$from, $to] = $this->periodRange($period);
        $statement = $this->db->pdo()->prepare(
            'SELECT batch.id, batch.batch_reference, batch.channel,
                    batch.export_format, batch.planned_payment_date,
                    batch.currency_code, batch.declared_total_minor,
                    batch.declared_item_count, batch.created_at,
                    COALESCE((
                      SELECT SUM(payment_match.amount_minor)
                        FROM payroll_payment_items payment_item
                        JOIN payroll_payment_allocations allocation
                          ON allocation.supplier_id =
                             payment_item.supplier_id
                         AND allocation.item_id = payment_item.id
                        JOIN payroll_payment_matches payment_match
                          ON payment_match.supplier_id =
                             allocation.supplier_id
                         AND payment_match.allocation_id = allocation.id
                       WHERE payment_item.supplier_id = batch.supplier_id
                         AND payment_item.batch_id = batch.id
                    ), 0) AS settled_minor,
                    (
                      SELECT MAX(liability.due_on)
                        FROM payroll_payment_items payment_item
                        JOIN payroll_payment_allocations allocation
                          ON allocation.supplier_id =
                             payment_item.supplier_id
                         AND allocation.item_id = payment_item.id
                        JOIN payroll_payment_liabilities liability
                          ON liability.supplier_id = allocation.supplier_id
                         AND liability.id = allocation.liability_id
                       WHERE payment_item.supplier_id = batch.supplier_id
                         AND payment_item.batch_id = batch.id
                    ) AS statutory_due_on
              FROM payroll_payment_batches batch
              WHERE batch.supplier_id = ?
                AND EXISTS (
                  SELECT 1
                    FROM payroll_payment_items payment_item
                    JOIN payroll_payment_allocations allocation
                      ON allocation.supplier_id =
                         payment_item.supplier_id
                     AND allocation.item_id = payment_item.id
                    JOIN payroll_payment_liabilities liability
                      ON liability.supplier_id =
                         allocation.supplier_id
                     AND liability.id = allocation.liability_id
                    JOIN payroll_run_revisions revision
                      ON revision.supplier_id = liability.supplier_id
                     AND revision.id = liability.revision_id
                    JOIN payroll_runs run
                      ON run.supplier_id = revision.supplier_id
                     AND run.id = revision.run_id
                   WHERE payment_item.supplier_id = batch.supplier_id
                     AND payment_item.batch_id = batch.id
                     AND run.period_start >= ?
                     AND run.period_start < ?
                )
              ORDER BY batch.planned_payment_date DESC, batch.id DESC',
        );
        $statement->execute([$supplierId, $from, $to]);
        $batches = [];
        $batchIds = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::row($row, 'platební dávku');
            $id = self::integer($row, 'id');
            $declared = self::integer($row, 'declared_total_minor');
            $settled = self::integer($row, 'settled_minor');
            if ($declared <= 0 || $settled < 0 || $settled > $declared) {
                throw new \UnexpectedValueException(
                    'Součty platební dávky jsou mimo povolené meze.',
                );
            }
            $batchIds[] = $id;
            $statutoryDueOn = self::nullableText($row, 'statutory_due_on');
            $batches[$id] = [
                'id' => $id,
                'batch_reference' => self::text(
                    $row,
                    'batch_reference',
                ),
                'channel' => self::text($row, 'channel'),
                'export_format' => self::text($row, 'export_format'),
                'planned_payment_date' => self::text(
                    $row,
                    'planned_payment_date',
                ),
                /*
                 * `planned_payment_date` je datum PŘÍKAZU, `statutory_due_on`
                 * zákonný termín ze splatnosti závazků v dávce (všechny mají
                 * z konstrukce dávky stejný). U odvodů je příkaz o rezervu na
                 * mezibankovní převod dřív, aby částka stihla být PŘIPSÁNÁ —
                 * viz PayrollLevyPaymentDate. `is_shifted` říká, že se ta dvě
                 * data liší, ať to UI umí vysvětlit.
                 */
                'statutory_due_on' => $statutoryDueOn,
                'is_shifted' => $statutoryDueOn !== null
                    && $statutoryDueOn !== self::text(
                        $row,
                        'planned_payment_date',
                    ),
                'currency_code' => self::text($row, 'currency_code'),
                'declared_total_minor' => $declared,
                'declared_item_count' => self::integer(
                    $row,
                    'declared_item_count',
                ),
                'settled_minor' => $settled,
                'created_at' => self::text($row, 'created_at'),
                'exports' => [],
            ];
        }
        if ($batchIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
        $exportStatement = $this->db->pdo()->prepare(
            'SELECT id, batch_id, export_revision_no, file_sha256,
                    size_bytes, mime_type, suggested_filename, created_at
               FROM payroll_payment_exports
              WHERE supplier_id = ?
                AND batch_id IN (' . $placeholders . ')
              ORDER BY batch_id, export_revision_no DESC',
        );
        $exportStatement->execute([$supplierId, ...$batchIds]);
        foreach ($exportStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::row($row, 'platební export');
            $batchId = self::integer($row, 'batch_id');
            if (!isset($batches[$batchId])) {
                throw new \UnexpectedValueException(
                    'Export nepatří načtené platební dávce.',
                );
            }
            $batches[$batchId]['exports'][] = [
                'id' => self::integer($row, 'id'),
                'revision_no' => self::integer(
                    $row,
                    'export_revision_no',
                ),
                'file_sha256' => self::hash($row, 'file_sha256'),
                'size_bytes' => self::integer($row, 'size_bytes'),
                'mime_type' => self::text($row, 'mime_type'),
                'suggested_filename' => self::text(
                    $row,
                    'suggested_filename',
                ),
                'created_at' => self::text($row, 'created_at'),
            ];
        }

        return array_values($batches);
    }

    /** @return array{string,string} */
    private function periodRange(string $period): array
    {
        if (preg_match(
            '/^(20[0-9]{2}|21[0-9]{2})-(0[1-9]|1[0-2])$/D',
            $period,
        ) !== 1) {
            throw new \InvalidArgumentException(
                'Mzdové období musí mít tvar RRRR-MM.',
            );
        }
        $from = new \DateTimeImmutable($period . '-01');

        return [
            $from->format('Y-m-d'),
            $from->modify('first day of next month')->format('Y-m-d'),
        ];
    }

    private function maskCzechAccount(
        string $accountNumber,
        string $bankCode,
    ): string {
        $digits = preg_replace('/\D+/', '', $accountNumber);
        if (!is_string($digits) || $digits === '') {
            throw new \UnexpectedValueException(
                'Český účet nelze bezpečně zamaskovat.',
            );
        }

        return '••••' . substr($digits, -4) . '/' . $bankCode;
    }

    private function maskIban(string $iban): string
    {
        return substr($iban, 0, 2)
            . '•• •••• •••• •••• •••• '
            . substr($iban, -4);
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatný {$context}.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "Databázový {$context} nemá textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není celé číslo.",
            );
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není celé číslo.",
            );
        }

        return $integer;
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableText(
        array $row,
        string $field,
    ): ?string {
        $value = $row[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function hash(array $row, string $field): string
    {
        $value = self::text($row, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není SHA-256.",
            );
        }

        return $value;
    }
}
