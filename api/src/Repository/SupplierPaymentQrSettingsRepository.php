<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/** Supplier-scoped SSOT pro datum splatnosti v generovaných platebních QR. */
final class SupplierPaymentQrSettingsRepository
{
    public const INVOICE_FIELD = 'invoice_qr_include_due_date';
    public const PURCHASE_INVOICE_FIELD = 'purchase_invoice_qr_include_due_date';

    /** @var list<string> */
    public const FIELDS = [self::INVOICE_FIELD, self::PURCHASE_INVOICE_FIELD];

    public function __construct(private readonly Connection $db) {}

    /** @param list<string> $changed */
    public static function invalidatesInvoicePdfs(array $changed): bool
    {
        return in_array(self::INVOICE_FIELD, $changed, true);
    }

    /**
     * @return array{invoice_qr_include_due_date:bool,purchase_invoice_qr_include_due_date:bool}|null
     */
    public function find(int $supplierId): ?array
    {
        if ($supplierId <= 0) return null;

        $stmt = $this->db->pdo()->prepare(
            'SELECT invoice_qr_include_due_date, purchase_invoice_qr_include_due_date
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;

        return [
            self::INVOICE_FIELD => (bool) $row[self::INVOICE_FIELD],
            self::PURCHASE_INVOICE_FIELD => (bool) $row[self::PURCHASE_INVOICE_FIELD],
        ];
    }

    /**
     * @param array<string,bool> $values Už validovaný podvýběr self::FIELDS.
     * @return array{settings:array{invoice_qr_include_due_date:bool,purchase_invoice_qr_include_due_date:bool},changed:list<string>,before:array{invoice_qr_include_due_date:bool,purchase_invoice_qr_include_due_date:bool}}
     */
    public function update(int $supplierId, array $values): array
    {
        $before = $this->find($supplierId);
        if ($before === null) {
            throw new \OutOfBoundsException('Supplier nenalezen.');
        }

        $changed = [];
        $sets = [];
        $params = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $values)) continue;
            $value = (bool) $values[$field];
            if ($before[$field] === $value) continue;
            $changed[] = $field;
            $sets[] = $field . ' = ?';
            $params[] = $value ? 1 : 0;
        }

        if ($sets !== []) {
            $params[] = $supplierId;
            $this->db->pdo()->prepare(
                'UPDATE supplier SET ' . implode(', ', $sets) . ' WHERE id = ?'
            )->execute($params);
        }

        $settings = $before;
        foreach ($changed as $field) {
            $settings[$field] = (bool) $values[$field];
        }

        return ['settings' => $settings, 'changed' => $changed, 'before' => $before];
    }
}
