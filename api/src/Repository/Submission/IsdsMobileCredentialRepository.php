<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Šifrovaný osobní profil Mobilního klíče v rozsahu firma + uživatel. */
final class IsdsMobileCredentialRepository
{
    private const TABLE = 'submission_isds_mobile_credentials';

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /** @return array<string,mixed>|null */
    public function findWithSecrets(int $supplierId, int $userId, string $environment): ?array
    {
        $this->assertAvailable();
        $statement = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, user_id, environment, username_ciphertext,
                    communication_code_ciphertext, created_at, updated_at
               FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND user_id = ? AND environment = ?',
        );
        $statement->execute([$supplierId, $userId, $environment]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function save(
        int $supplierId,
        int $userId,
        string $environment,
        string $usernameCiphertext,
        string $communicationCodeCiphertext,
    ): void {
        $this->assertAvailable();
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . '
                (supplier_id, user_id, environment, username_ciphertext, communication_code_ciphertext)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                username_ciphertext = VALUES(username_ciphertext),
                communication_code_ciphertext = VALUES(communication_code_ciphertext)',
        );
        $statement->execute([
            $supplierId,
            $userId,
            $environment,
            $usernameCiphertext,
            $communicationCodeCiphertext,
        ]);
    }

    public function delete(int $supplierId, int $userId, string $environment): bool
    {
        $this->assertAvailable();
        $statement = $this->db->pdo()->prepare(
            'DELETE FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND user_id = ? AND environment = ?',
        );
        $statement->execute([$supplierId, $userId, $environment]);
        return $statement->rowCount() > 0;
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new \DomainException('Osobní trezor Mobilního klíče není k dispozici (chybí migrace 1534).');
        }
    }
}
