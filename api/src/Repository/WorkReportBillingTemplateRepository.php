<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\WorkReport\WorkReportBilling;
use PDO;

final class WorkReportBillingTemplateRepository
{
    public function __construct(private readonly Connection $db) {}

    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, settings FROM work_report_billing_templates WHERE supplier_id = ? AND archived_at IS NULL ORDER BY name, id LIMIT 500');
        $stmt->execute([$supplierId]);
        return array_map(self::decode(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, settings FROM work_report_billing_templates WHERE supplier_id = ? AND id = ? AND archived_at IS NULL');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::decode($row) : null;
    }

    private static function decode(array $row): array
    {
        return ['id' => (int) $row['id']] + json_decode($row['settings'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function save(int $supplierId, ?int $id, array $data): int
    {
        $settings = WorkReportBilling::validate($data);
        $pdo = $this->db->pdo();
        $currency = $pdo->prepare('SELECT 1 FROM currencies WHERE supplier_id = ? AND code = ?');
        $currency->execute([$supplierId, $settings['currency']]);
        if (!$currency->fetchColumn()) throw new \InvalidArgumentException('Měna není nastavena pro aktuální firmu.');
        $json = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($id !== null) {
            $pdo->prepare('UPDATE work_report_billing_templates SET name = ?, settings = ? WHERE supplier_id = ? AND id = ? AND archived_at IS NULL')
                ->execute([$settings['name'], $json, $supplierId, $id]);
            return $id;
        }
        $pdo->prepare('INSERT INTO work_report_billing_templates (supplier_id, name, settings) VALUES (?, ?, ?)')
            ->execute([$supplierId, $settings['name'], $json]);
        return (int) $pdo->lastInsertId();
    }

    public function delete(int $supplierId, int $id): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE projects p JOIN clients c ON c.id = p.client_id SET p.billing_template_id = NULL WHERE c.supplier_id = ? AND p.billing_template_id = ?')->execute([$supplierId, $id]);
            $pdo->prepare('UPDATE work_report_billing_templates SET archived_at = CURRENT_TIMESTAMP WHERE supplier_id = ? AND id = ? AND archived_at IS NULL')->execute([$supplierId, $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
