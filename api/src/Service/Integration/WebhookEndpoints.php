<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Http\OutboundUrlGuard;
use PDO;

final class WebhookEndpoints
{
    private const MAX_ENDPOINTS = 20;
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $encryption,
        private readonly OutboundUrlGuard $http,
        private readonly WebhookPolicy $policy,
    ) {}

    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, url, enabled, event_types, template_id, include_advance
               FROM webhook_endpoints WHERE supplier_id = ? ORDER BY id'
        );
        $stmt->execute([$supplierId]);
        return array_map(self::serialize(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $supplierId, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, url, enabled, event_types, template_id, include_advance
               FROM webhook_endpoints WHERE supplier_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::serialize($row);
    }

    public function create(int $supplierId, array $input): array
    {
        $data = $this->settings($supplierId, $input, []);
        $pdo = $this->db->pdo();
        $lock = $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
        $lock->execute([$supplierId]);
        $count = $pdo->prepare('SELECT COUNT(*) FROM webhook_endpoints WHERE supplier_id = ?');
        $count->execute([$supplierId]);
        if ((int) $count->fetchColumn() >= self::MAX_ENDPOINTS) {
            throw new \InvalidArgumentException('Firma může mít nejvýše 20 webhooků.');
        }
        $pdo->prepare(
            "INSERT INTO webhook_endpoints (supplier_id, name, url, enabled, event_types, template_id, include_advance, secret_enc)
             VALUES (?, ?, ?, ?, ?, ?, ?, '')"
        )->execute([$supplierId, $data['name'], $data['url'], (int) $data['enabled'], json_encode($data['event_types'], JSON_THROW_ON_ERROR),
            $data['template_id'], (int) $data['include_advance']]);
        $id = (int) $pdo->lastInsertId();
        return ['id' => $id, 'secret' => $this->rotate($supplierId, $id)];
    }

    public function update(int $supplierId, array $current, array $input): void
    {
        if (array_key_exists('url', $input) && $input['url'] !== $current['url']) {
            throw new \InvalidArgumentException('Pro novou URL vytvořte nový webhook.');
        }
        $data = $this->settings($supplierId, $input, $current);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE webhook_endpoints SET name = ?, enabled = ?, event_types = ?, template_id = ?, include_advance = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$data['name'], (int) $data['enabled'], json_encode($data['event_types'], JSON_THROW_ON_ERROR),
            $data['template_id'], (int) $data['include_advance'], $supplierId, $current['id']]);
        if ($data['event_types'] !== $current['event_types'] || $data['template_id'] !== $current['template_id']
            || $data['include_advance'] !== $current['include_advance']) {
            $pdo->prepare(
                "UPDATE webhook_deliveries
                    SET status = 'cancelled', lease_token = NULL, last_error_code = 'subscription_changed'
                  WHERE supplier_id = ? AND endpoint_id = ? AND status IN ('pending','processing','failed')"
            )->execute([$supplierId, $current['id']]);
        }
    }

    public function rotate(int $supplierId, int $id): string
    {
        $key = base64_encode(random_bytes(32));
        $this->db->pdo()->prepare(
            'UPDATE webhook_endpoints SET secret_enc = ? WHERE supplier_id = ? AND id = ?'
        )->execute([$this->encryption->encryptFor($key, self::secretContext($supplierId, $id)), $supplierId, $id]);
        return 'whsec_' . $key;
    }

    public function delete(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM webhook_endpoints WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $id]);
    }

    public function enqueueTest(int $supplierId, int $id): ?string
    {
        $pdo = $this->db->pdo();
        $recent = $pdo->prepare(
            "SELECT 1 FROM webhook_deliveries WHERE supplier_id = ? AND endpoint_id = ?
              AND event_type = 'webhook.test' AND created_at > NOW(6) - INTERVAL 1 MINUTE LIMIT 1"
        );
        $recent->execute([$supplierId, $id]);
        if ($recent->fetchColumn() !== false) {
            return null;
        }
        $eventId = WebhookEvents::eventId();
        $pdo->prepare(
            "INSERT INTO webhook_deliveries (supplier_id, endpoint_id, event_id, event_type, body_json)
             VALUES (?, ?, ?, 'webhook.test', JSON_OBJECT('type', 'webhook.test', 'version', 1,
                'event_id', ?, 'occurred_at', DATE_FORMAT(UTC_TIMESTAMP(6), '%Y-%m-%dT%H:%i:%s.%fZ'),
                'supplier_id', ?, 'test', JSON_EXTRACT('true', '$')))"
        )->execute([$supplierId, $id, $eventId, $eventId, $supplierId]);
        return $eventId;
    }

    public function history(int $supplierId, int $id, int $before): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, event_id, event_type, status, attempts, http_status, last_error_code, created_at,
                    IF(status = \'pending\', available_at, NULL) AS next_attempt_at
               FROM webhook_deliveries WHERE supplier_id = ? AND endpoint_id = ? AND id < ?
              ORDER BY id DESC LIMIT ' . self::PAGE_SIZE
        );
        $stmt->execute([$supplierId, $id, $before]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['attempts'] = (int) $row['attempts'];
            $row['http_status'] = $row['http_status'] === null ? null : (int) $row['http_status'];
        }
        return $rows;
    }

    public function retry(int $supplierId, int $id, int $deliveryId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE webhook_deliveries SET status = 'pending', attempts = 0, available_at = NOW(6),
                    http_status = NULL, last_error_code = NULL
              WHERE supplier_id = ? AND endpoint_id = ? AND id = ? AND status = 'failed'
                AND created_at >= NOW(6) - INTERVAL 30 DAY"
        );
        $stmt->execute([$supplierId, $id, $deliveryId]);
        return $stmt->rowCount() === 1;
    }

    public static function secretContext(int $supplierId, int $id): string
    {
        return "webhook:{$supplierId}:{$id}";
    }

    private function settings(int $supplierId, array $input, array $current): array
    {
        $data = $input + $current + ['name' => '', 'url' => '', 'enabled' => true, 'template_id' => null,
            'event_types' => [], 'include_advance' => false];
        if (!is_string($data['name']) || trim($data['name']) === '' || mb_strlen(trim($data['name'])) > 120
            || !is_string($data['url']) || !is_bool($data['include_advance']) || !is_bool($data['enabled'])) {
            throw new \InvalidArgumentException('Zkontrolujte název, adresu a nastavení webhooku.');
        }
        $data['name'] = trim($data['name']);
        $data['url'] = trim($data['url']);
        if ($data['enabled'] || $current === []) {
            $this->http->assertSyntax($data['url'], $this->policy->mode() === 'allowlist' ? $this->policy->allowedHosts() : []);
        }

        if (!is_array($data['event_types']) || !array_is_list($data['event_types'])
            || $data['event_types'] === [] || count($data['event_types']) > count(WebhookEvents::TYPES)) {
            throw new \InvalidArgumentException('Vyberte alespoň jednu podporovanou událost.');
        }
        foreach ($data['event_types'] as $event) {
            if (!in_array($event, WebhookEvents::TYPES, true)) {
                throw new \InvalidArgumentException('Webhook obsahuje nepodporovanou událost.');
            }
        }
        $data['event_types'] = array_values(array_intersect(WebhookEvents::TYPES, $data['event_types']));
        if ($data['template_id'] !== null && array_diff($data['event_types'], InvoiceWebhookEvents::TYPES) !== []) {
            throw new \InvalidArgumentException('Filtr pravidelné šablony lze kombinovat jen s událostmi vydaných dokladů.');
        }
        if ($data['template_id'] !== null && $data['template_id'] !== ($current['template_id'] ?? null)) {
            if (!is_int($data['template_id']) || $data['template_id'] < 1) {
                throw new \InvalidArgumentException('Vyberte platnou šablonu pravidelné fakturace.');
            }
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM recurring_invoice_templates WHERE supplier_id = ? AND id = ? LOCK IN SHARE MODE'
            );
            $stmt->execute([$supplierId, $data['template_id']]);
            if ($stmt->fetchColumn() === false) {
                throw new \InvalidArgumentException('Šablona pravidelné fakturace v této firmě neexistuje.');
            }
        }
        return $data;
    }

    private static function serialize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['enabled'] = (bool) $row['enabled'];
        $row['include_advance'] = (bool) $row['include_advance'];
        $row['template_id'] = $row['template_id'] === null ? null : (int) $row['template_id'];
        $row['event_types'] = json_decode($row['event_types'], true, 4, JSON_THROW_ON_ERROR);
        return $row;
    }
}
