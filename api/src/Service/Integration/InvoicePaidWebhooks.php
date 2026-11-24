<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Http\OutboundUrlGuard;
use PDO;

final class InvoicePaidWebhooks
{
    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $encryption,
        private readonly OutboundUrlGuard $http,
    ) {}

    public static function context(int $supplierId, int $id): string
    {
        return "invoice.paid:{$supplierId}:{$id}";
    }

    public function list(int $supplierId, ?int $templateId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, url, include_advance FROM invoice_paid_webhooks
            WHERE supplier_id=? AND template_id <=> ? ORDER BY id');
        $stmt->execute([$supplierId, $templateId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function register(int $supplierId, ?int $templateId, string $url, bool $includeAdvance): array
    {
        $this->http->assertSyntax($url);
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            if ($templateId !== null) {
                $check = $pdo->prepare('SELECT id FROM recurring_invoice_templates WHERE supplier_id=? AND id=? LOCK IN SHARE MODE');
                $check->execute([$supplierId, $templateId]);
                if (!$check->fetchColumn()) throw new \InvalidArgumentException('Neplatná šablona.');
            }
            $pdo->prepare("INSERT INTO invoice_paid_webhooks (supplier_id,template_id,url,include_advance,secret_enc)
                VALUES (?,?,?,?,'')")->execute([$supplierId, $templateId, $url, (int) $includeAdvance]);
            $id = (int) $pdo->lastInsertId();
            $key = $this->rotate($supplierId, $id);
            if ($own) $pdo->commit();
            return ['id' => $id, 'secret' => $key];
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function rotate(int $supplierId, int $id): ?string
    {
        $key = base64_encode(random_bytes(32));
        $stmt = $this->db->pdo()->prepare('UPDATE invoice_paid_webhooks SET secret_enc=? WHERE supplier_id=? AND id=?');
        $stmt->execute([$this->encryption->encryptFor($key, self::context($supplierId, $id)), $supplierId, $id]);
        return $stmt->rowCount() ? 'whsec_' . $key : null;
    }

    public function remove(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM invoice_paid_webhooks WHERE supplier_id=? AND id=?');
        $stmt->execute([$supplierId, $id]);
        return $stmt->rowCount() > 0;
    }

    public function history(int $supplierId, int $hookId, int $before): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT d.id, d.event_id, d.status, d.http_status, d.attempts, d.available_at
            FROM invoice_paid_webhooks h JOIN invoice_paid_deliveries d ON d.hook_id=h.id
            WHERE h.supplier_id=? AND h.id=? AND d.id < ? ORDER BY d.id DESC LIMIT 25');
        $stmt->execute([$supplierId, $hookId, $before]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function retry(int $supplierId, int $id, int $deliveryId): bool
    {
        $stmt = $this->db->pdo()->prepare("UPDATE invoice_paid_webhooks h JOIN invoice_paid_deliveries d ON d.hook_id=h.id
            SET d.status='pending', d.attempts=0, d.available_at=NOW(6), d.http_status=NULL
            WHERE h.supplier_id=? AND h.id=? AND d.id=? AND d.status='failed'");
        $stmt->execute([$supplierId, $id, $deliveryId]);
        return $stmt->rowCount() > 0;
    }
}
