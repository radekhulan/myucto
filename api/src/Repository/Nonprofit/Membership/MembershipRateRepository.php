<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class MembershipRateRepository
{
    public function __construct(private readonly Connection $db) {}

    public function page(int $supplier, int $after, bool $activeOnly, int $limit = 100): array
    {
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_membership_rates WHERE supplier_id=?'
            . ($activeOnly ? ' AND is_active=1' : '') . ' AND id>? ORDER BY id LIMIT ' . max(1, min(100, $limit)));
        $q->execute([$supplier, $after]);
        return array_map($this->row(...), $q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $supplier, int $id, bool $lock = false): ?array
    {
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_membership_rates WHERE supplier_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->row($row);
    }

    public function save(int $supplier, ?int $id, array $input, int $version): array
    {
        $fields = ['name', 'amount', 'receivable_account', 'revenue_account', 'title_key', 'period_months', 'title_reference', 'evidence', 'classification_json', 'is_active'];
        $input['classification_json'] = $input['classification'] === null ? null : NonprofitTaxRules::canonicalJson($input['classification']);
        $values = array_map(static fn (string $field): mixed => $input[$field], $fields);
        if ($id === null) {
            $q = $this->db->pdo()->prepare('INSERT INTO nonprofit_membership_rates(supplier_id,' . implode(',', $fields) . ') VALUES(?' . str_repeat(',?', count($fields)) . ')');
            $q->execute([$supplier, ...$values]);
            $id = (int) $this->db->pdo()->lastInsertId();
        } else {
            $q = $this->db->pdo()->prepare('UPDATE nonprofit_membership_rates SET ' . implode(',', array_map(static fn (string $field): string => $field . '=?', $fields)) . ',row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=?');
            $q->execute([...$values, $supplier, $id, $version]);
            if ($q->rowCount() !== 1) throw new MembershipException('nonprofit_membership_rate_changed', 'Sazba se mezitím změnila. Načtěte ji znovu.', 409);
        }
        return $this->find($supplier, $id) ?? throw new \LogicException('Uložená sazba nebyla nalezena.');
    }

    private function row(array $row): array
    {
        foreach (['id', 'supplier_id', 'period_months', 'row_version'] as $field) $row[$field] = (int) $row[$field];
        $row['is_active'] = (bool) $row['is_active'];
        $row['classification'] = $row['classification_json'] === null ? null : json_decode($row['classification_json'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['classification_json']);
        return $row;
    }
}
