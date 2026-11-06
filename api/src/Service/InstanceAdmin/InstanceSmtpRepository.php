<?php

declare(strict_types=1);

namespace MyInvoice\Service\InstanceAdmin;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\System\ManagedModeGuard;
use PDO;

/** Oddělené schválené cíle a šifrované SMTP tajemství; konfigurace hostingu má přednost. */
final class InstanceSmtpRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly SecretEncryption $secrets,
        private readonly ManagedModeGuard $managed,
    ) {}

    public function readOnlyReason(): ?string
    {
        if ($this->managed->isLocked(ManagedModeGuard::CAPABILITY_MAIL_TRANSPORT)) return 'managed';
        if (Config::hasEnvironmentOverride('smtp')) return 'environment';
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function targets(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, name, host, port, encryption, username, from_email, test_recipient
            FROM instance_smtp_targets ORDER BY id LIMIT 101')->fetchAll(PDO::FETCH_ASSOC);
        return array_map($this->normalizeTarget(...), $rows);
    }

    public function target(int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name, host, port, encryption, username, from_email, test_recipient
            FROM instance_smtp_targets WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->normalizeTarget($row);
    }

    public function createTarget(array $input, int $userId): array
    {
        $values = SmtpTarget::fromInput($input);
        $existing = $this->db->pdo()->query('SELECT id FROM instance_smtp_targets ORDER BY id LIMIT 101 FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        if (count($existing) >= 100) throw new \InvalidArgumentException('Lze schválit nejvýše 100 SMTP cílů.');
        $stmt = $this->db->pdo()->prepare('INSERT INTO instance_smtp_targets
            (name, host, port, encryption, username, from_email, test_recipient, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([...array_values($values), $userId]);
        return ['id' => (int) $this->db->pdo()->lastInsertId(), ...$values];
    }

    public function deleteTarget(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT target_id FROM instance_smtp_settings WHERE id = 1 FOR UPDATE');
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === $id) return false;
        $this->db->pdo()->prepare('DELETE FROM instance_smtp_targets WHERE id = ?')->execute([$id]);
        return true;
    }

    /** @return array<string,mixed>|null */
    public function stored(bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->query('SELECT target_id, password_ciphertext FROM instance_smtp_settings WHERE id = 1'
            . ($lock ? ' FOR UPDATE' : ''));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function save(int $targetId, ?string $password): void
    {
        if ($this->readOnlyReason() !== null) throw new \DomainException('smtp_read_only');
        if ($this->target($targetId, true) === null) throw new \InvalidArgumentException('Schválený SMTP cíl neexistuje.');
        $stored = $this->stored(true);
        $unchanged = $stored !== null && (int) $stored['target_id'] === $targetId;
        if (!$unchanged && ($password === null || $password === '')) {
            throw new \DomainException('smtp_target_requires_password');
        }
        if ($password !== null && (strlen($password) > 8192 || $password === '' || str_contains($password, "\0"))) {
            throw new \InvalidArgumentException('Neplatné heslo SMTP.');
        }
        $ciphertext = $password === null ? (string) $stored['password_ciphertext']
            : $this->secrets->encryptFor($password, 'instance-smtp:' . $targetId);
        $this->db->pdo()->prepare('INSERT INTO instance_smtp_settings (id, target_id, password_ciphertext) VALUES (1, ?, ?)
            ON DUPLICATE KEY UPDATE target_id = VALUES(target_id), password_ciphertext = VALUES(password_ciphertext)')
            ->execute([$targetId, $ciphertext]);
    }

    /** Návrat ke konfiguraci odstraňuje i tajemství override, schválené cíle ponechá. */
    public function clearOverride(): ?int
    {
        if ($this->readOnlyReason() !== null) throw new \DomainException('smtp_read_only');
        $stored = $this->stored(true);
        $this->db->pdo()->exec('DELETE FROM instance_smtp_settings WHERE id = 1');
        return $stored === null ? null : (int) $stored['target_id'];
    }

    /** Rozhraní pouze pro odesílání; veřejné DTO nesmí použít tento výsledek. */
    public function runtime(): ?array
    {
        if ($this->readOnlyReason() !== null || !$this->db->hasTable('instance_smtp_settings')) return null;
        $stored = $this->stored();
        if ($stored === null) return null;
        $target = $this->target((int) $stored['target_id']);
        if ($target === null) throw new \RuntimeException('Schválený SMTP cíl není dostupný.');
        return [...$target, 'password' => $this->secrets->decryptFor((string) $stored['password_ciphertext'], 'instance-smtp:' . $target['id'])];
    }

    public function view(): array
    {
        $reason = $this->readOnlyReason();
        if ($reason !== null) {
            return ['managed' => $reason === 'managed', 'read_only' => true, 'read_only_reason' => $reason,
                'configured' => trim((string) $this->config->get('smtp.host', '')) !== '', 'source' => 'configuration',
                'target_id' => null, 'password_set' => false, 'targets' => [], 'test_recipient' => null];
        }
        $stored = $this->stored();
        $target = $stored === null ? null : $this->target((int) $stored['target_id']);
        return ['managed' => false, 'read_only' => false, 'read_only_reason' => null,
            'configured' => $target !== null || trim((string) $this->config->get('smtp.host', '')) !== '',
            'source' => $target === null ? 'configuration' : 'approved_target', 'target_id' => $target['id'] ?? null,
            'password_set' => $stored !== null && $stored['password_ciphertext'] !== '',
            'targets' => $this->targets(), 'test_recipient' => $target['test_recipient'] ?? null];
    }

    /** Atomická rezervace platí i pro souběžné testy různých firem téhož správce. */
    public function reserveTest(int $userId, int $targetId): bool
    {
        $stmt = $this->db->pdo()->prepare('INSERT IGNORE INTO instance_smtp_test_attempts (user_id, attempted_at) VALUES (?, UTC_TIMESTAMP())');
        $stmt->execute([$userId]);
        if ($stmt->rowCount() !== 1) {
            $stmt = $this->db->pdo()->prepare('UPDATE instance_smtp_test_attempts SET attempted_at = UTC_TIMESTAMP()
                WHERE user_id = ? AND attempted_at <= UTC_TIMESTAMP() - INTERVAL 60 SECOND');
            $stmt->execute([$userId]);
            if ($stmt->rowCount() !== 1) return false;
        }
        $stmt = $this->db->pdo()->prepare('UPDATE instance_smtp_settings SET test_attempted_at = UTC_TIMESTAMP()
            WHERE id = 1 AND target_id = ? AND (test_attempted_at IS NULL OR test_attempted_at <= UTC_TIMESTAMP() - INTERVAL 60 SECOND)');
        $stmt->execute([$targetId]);
        return $stmt->rowCount() === 1;
    }

    private function normalizeTarget(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['port'] = (int) $row['port'];
        return $row;
    }
}
