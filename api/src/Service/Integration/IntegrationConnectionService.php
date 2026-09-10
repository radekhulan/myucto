<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use PDO;

final class IntegrationConnectionService
{
    private const STATUSES = ['draft', 'active', 'paused', 'error'];
    private const OWNERS = ['local', 'remote', 'manual'];

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $encryption,
    ) {}

    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM integration_connections WHERE supplier_id = ? ORDER BY name, id');
        $stmt->execute([$supplierId]);
        return array_map($this->present(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM integration_connections WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->present($row);
    }

    public function findRawByUuid(string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM integration_connections WHERE connection_uuid = ?');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function create(int $supplierId, array $input, ?int $createdBy): array
    {
        $values = $this->normalize($input);
        if ($values['status'] === 'error') {
            throw new \InvalidArgumentException('Neplatné připojení.');
        }
        $uuid = self::uuid();
        $stmt = $this->db->pdo()->prepare('INSERT INTO integration_connections
            (connection_uuid, supplier_id, connector_key, name, status, mappings_json,
             field_ownership_json, rate_limit_per_minute, retention_days, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$uuid, $supplierId, $values['connector_key'], $values['name'], $values['status'],
            json_encode((object) $values['mappings'], JSON_THROW_ON_ERROR),
            json_encode((object) $values['field_ownership'], JSON_THROW_ON_ERROR),
            $values['rate_limit_per_minute'], $values['retention_days'], $createdBy]);
        return $this->find($supplierId, (int) $this->db->pdo()->lastInsertId())
            ?? throw new \RuntimeException('Připojení se nepodařilo vytvořit.');
    }

    public function update(int $supplierId, int $id, array $input): ?array
    {
        $current = $this->find($supplierId, $id);
        if ($current === null) {
            return null;
        }
        $values = $this->normalize(array_replace($current, $input));
        $stmt = $this->db->pdo()->prepare('UPDATE integration_connections SET connector_key = ?, name = ?,
            status = ?, mappings_json = ?, field_ownership_json = ?, rate_limit_per_minute = ?, retention_days = ?
            WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$values['connector_key'], $values['name'], $values['status'],
            json_encode((object) $values['mappings'], JSON_THROW_ON_ERROR),
            json_encode((object) $values['field_ownership'], JSON_THROW_ON_ERROR),
            $values['rate_limit_per_minute'], $values['retention_days'], $supplierId, $id]);
        return $this->find($supplierId, $id);
    }

    public function setCredentials(int $supplierId, int $id, array $credentials): ?array
    {
        $row = $this->raw($supplierId, $id);
        if ($row === null) {
            return null;
        }
        $clean = [];
        foreach ($credentials as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $key) !== 1 || !is_scalar($value)) {
                throw new \InvalidArgumentException('Neplatný formát credentials.');
            }
            $clean[$key] = (string) $value;
        }
        if ($clean === []) {
            throw new \InvalidArgumentException('Credentials nesmí být prázdné.');
        }
        $encrypted = $this->encryption->encryptFor(
            json_encode($clean, JSON_THROW_ON_ERROR),
            $this->context($supplierId, (string) $row['connection_uuid']),
        );
        $this->db->pdo()->prepare('UPDATE integration_connections SET credentials_enc = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$encrypted, $supplierId, $id]);
        return $this->find($supplierId, $id);
    }

    public function credentials(int $supplierId, int $id): ?array
    {
        $row = $this->raw($supplierId, $id);
        if ($row === null || $row['credentials_enc'] === null) {
            return null;
        }
        return json_decode($this->encryption->decryptFor(
            (string) $row['credentials_enc'],
            $this->context($supplierId, (string) $row['connection_uuid']),
        ), true, 64, JSON_THROW_ON_ERROR);
    }

    public function rotateWebhookSecret(int $supplierId, int $id): ?array
    {
        $row = $this->raw($supplierId, $id);
        if ($row === null) {
            return null;
        }
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $encrypted = $this->encryption->encryptFor($secret, $this->context($supplierId, (string) $row['connection_uuid']) . ':webhook');
        $this->db->pdo()->prepare('UPDATE integration_connections SET webhook_secret_enc = ?, webhook_secret_hash = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$encrypted, hash('sha256', $secret), $supplierId, $id]);
        return ['connection_uuid' => $row['connection_uuid'], 'secret' => $secret];
    }

    public function webhookSecret(array $row): ?string
    {
        if (($row['webhook_secret_enc'] ?? null) === null) {
            return null;
        }
        return $this->encryption->decryptFor((string) $row['webhook_secret_enc'],
            $this->context((int) $row['supplier_id'], (string) $row['connection_uuid']) . ':webhook');
    }

    private function raw(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM integration_connections WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function normalize(array $input): array
    {
        $connector = trim((string) ($input['connector_key'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $status = (string) ($input['status'] ?? 'draft');
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,79}$/D', $connector) || $name === '' || mb_strlen($name) > 150
            || !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Neplatné připojení.');
        }
        $mappings = $this->objectMap($input['mappings'] ?? []);
        $ownership = $this->objectMap($input['field_ownership'] ?? []);
        if ($mappings === null || $ownership === null) {
            throw new \InvalidArgumentException('Mapování musí být objekt.');
        }
        foreach ($ownership as $field => $owner) {
            if (!is_string($field) || preg_match('/^[a-z][a-z0-9_.]{0,99}$/D', $field) !== 1
                || !is_string($owner) || !in_array($owner, self::OWNERS, true)) {
                throw new \InvalidArgumentException('Neplatné vlastnictví pole.');
            }
        }
        $rate = (int) ($input['rate_limit_per_minute'] ?? 60);
        $retention = (int) ($input['retention_days'] ?? 30);
        if ($rate < 1 || $rate > 6000 || $retention < 1 || $retention > 365) {
            throw new \InvalidArgumentException('Neplatný provozní limit.');
        }
        return ['connector_key' => $connector, 'name' => $name, 'status' => $status,
            'mappings' => $mappings, 'field_ownership' => $ownership,
            'rate_limit_per_minute' => $rate, 'retention_days' => $retention];
    }

    private function present(array $row): array
    {
        foreach (['id', 'supplier_id', 'rate_limit_per_minute', 'retention_days'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['mappings'] = (object) json_decode($row['mappings_json'], true, 64, JSON_THROW_ON_ERROR);
        $row['field_ownership'] = (object) json_decode($row['field_ownership_json'], true, 64, JSON_THROW_ON_ERROR);
        $row['credentials_configured'] = $row['credentials_enc'] !== null;
        $row['webhook_configured'] = $row['webhook_secret_enc'] !== null;
        unset($row['mappings_json'], $row['field_ownership_json'], $row['credentials_enc'],
            $row['webhook_secret_enc'], $row['webhook_secret_hash']);
        return $row;
    }

    private function objectMap(mixed $value): ?array
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }
        return $value;
    }

    private function context(int $supplierId, string $uuid): string
    {
        return 'integration-connection:' . $supplierId . ':' . $uuid;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
