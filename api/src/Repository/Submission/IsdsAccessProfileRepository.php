<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use PDO;

final readonly class IsdsAccessProfileRepository
{
    private const SUMMARY = 'p.id, p.label, p.box_id, p.method, p.scope, p.environment, p.certificate_id';
    private const READABLE_CERTIFICATE = "(p.certificate_id IS NULL OR EXISTS (
        SELECT 1 FROM epo_signing_credentials c WHERE c.id = p.certificate_id AND c.deleted_at IS NULL
        AND c.valid_from <= NOW() AND c.valid_to >= NOW()
    ))";

    public function __construct(private Connection $db) {}

    public function owned(int $id, int $owner, string $scope): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM isds_access_profiles WHERE id = ? AND owner_user_id = ? AND scope = ?');
        $stmt->execute([$id, $owner, $scope]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function summaries(int $owner): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT " . self::SUMMARY . " FROM isds_access_profiles p
            WHERE p.owner_user_id = ? AND p.scope = 'shared' ORDER BY p.label, p.id");
        $stmt->execute([$owner]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,list<array<string,mixed>>> */
    public function grantsByProfile(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT profile_id, supplier_id, can_send, can_receive, can_receipts
            FROM isds_access_profile_suppliers WHERE profile_id IN ($marks) ORDER BY supplier_id");
        $stmt->execute(array_values($ids));
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['profile_id']][] = [
                'supplier_id' => (int) $row['supplier_id'],
                'send' => (bool) $row['can_send'],
                'receive' => (bool) $row['can_receive'],
                'receipts' => (bool) $row['can_receipts'],
            ];
        }
        return $result;
    }

    public function choices(int $supplier, int $actor, string $environment, string $operation): array
    {
        $guard = $this->grantSql($operation);
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::SUMMARY . " FROM isds_access_profiles p
            JOIN isds_access_profile_suppliers g ON g.profile_id = p.id AND g.supplier_id = ?
            WHERE p.environment = ? AND (p.scope = 'shared' OR p.owner_user_id = ?)
                AND $guard AND " . self::READABLE_CERTIFICATE . ' ORDER BY p.scope, p.label, p.id');
        $stmt->execute([$supplier, $environment, $actor]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function usable(int $id, int $supplier, int $actor, string $environment, string $operation): ?array
    {
        $guard = $this->grantSql($operation);
        $stmt = $this->db->pdo()->prepare("SELECT p.*, g.can_receipts FROM isds_access_profiles p
            JOIN isds_access_profile_suppliers g ON g.profile_id = p.id AND g.supplier_id = ?
            WHERE p.id = ? AND p.environment = ? AND (p.scope = 'shared' OR p.owner_user_id = ?)
                AND $guard AND " . self::READABLE_CERTIFICATE);
        $stmt->execute([$supplier, $id, $environment, $actor]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function grantSql(string $operation): string
    {
        return match ($operation) {
            'send' => 'g.can_send = 1',
            'receipts' => 'g.can_receipts = 1',
            'receive' => 'g.can_receive = 1 AND EXISTS (
                SELECT 1 FROM isds_access_inbox_owners io WHERE io.environment = p.environment
                AND io.box_id = p.box_id AND io.supplier_id = g.supplier_id
            )',
            default => throw new \InvalidArgumentException('Neznámá operace datové schránky.'),
        };
    }

    public function save(array $row, ?int $id, array $grants, bool $receiveRequired, ?string $expectedContext = null): int
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($row['certificate_id'] !== null) {
                $stmt = $pdo->prepare('SELECT id FROM epo_signing_credentials WHERE id = ? AND owner_user_id = ? AND deleted_at IS NULL FOR UPDATE');
                $stmt->execute([$row['certificate_id'], $row['owner_user_id']]);
                if ($stmt->fetchColumn() === false) {
                    throw new SubmissionChannelException('isds_access_invalid_certificate', 'Vybraný certifikát už není dostupný.', 409);
                }
            }
            foreach ($grants as &$grant) {
                if ($grant['receive'] && !$this->assignInbox($row['environment'], $row['box_id'], $grant['supplier_id'], $id)) {
                    if ($receiveRequired) {
                        throw new SubmissionChannelException('isds_inbox_assigned_elsewhere', 'Příjem této schránky je už nastavený v jiné firmě.', 409);
                    }
                    $grant['receive'] = false;
                }
            }
            unset($grant);
            if ($id === null) {
                $stmt = $pdo->prepare('INSERT INTO isds_access_profiles
                    (owner_user_id, personal_supplier_id, scope, environment, box_id, label, method, certificate_id,
                        secret_context, username_ciphertext, password_ciphertext)
                    VALUES (:owner_user_id, :personal_supplier_id, :scope, :environment, :box_id, :label, :method, :certificate_id,
                        :secret_context, :username_ciphertext, :password_ciphertext)
                    ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), secret_context = VALUES(secret_context),
                        username_ciphertext = VALUES(username_ciphertext), password_ciphertext = VALUES(password_ciphertext)');
                $stmt->execute($row);
                $id = (int) $pdo->lastInsertId();
            } else {
                $stmt = $pdo->prepare("SELECT secret_context FROM isds_access_profiles WHERE id = ? AND owner_user_id = ? AND scope = 'shared' FOR UPDATE");
                $stmt->execute([$id, $row['owner_user_id']]);
                $context = $stmt->fetchColumn();
                if ($context === false) {
                    throw new SubmissionChannelException('isds_access_not_found', 'Uložený přístup neexistuje.', 404);
                }
                if ($context !== $expectedContext) {
                    throw new SubmissionChannelException('isds_access_changed', 'Přístup se mezitím změnil. Načtěte ho znovu.', 409);
                }
                $stmt = $pdo->prepare('UPDATE isds_access_profiles SET label = ?, method = ?, certificate_id = ?,
                    secret_context = ?, username_ciphertext = ?, password_ciphertext = ? WHERE id = ?');
                $stmt->execute([$row['label'], $row['method'], $row['certificate_id'], $row['secret_context'],
                    $row['username_ciphertext'], $row['password_ciphertext'], $id]);
            }

            $pdo->prepare('DELETE FROM isds_access_profile_suppliers WHERE profile_id = ?')->execute([$id]);
            $stmt = $pdo->prepare('INSERT INTO isds_access_profile_suppliers
                (profile_id, supplier_id, can_send, can_receive, can_receipts) VALUES (?, ?, ?, ?, ?)');
            foreach ($grants as $grant) {
                $stmt->execute([$id, $grant['supplier_id'], (int) $grant['send'], (int) $grant['receive'], (int) $grant['receipts']]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function assignInbox(string $environment, string $boxId, int $supplier, ?int $excludedProfile): bool
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO isds_access_inbox_owners (environment, box_id, supplier_id) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE supplier_id = supplier_id')->execute([$environment, $boxId, $supplier]);

        $stmt = $pdo->prepare('SELECT supplier_id FROM isds_access_inbox_owners WHERE environment = ? AND box_id = ? FOR UPDATE');
        $stmt->execute([$environment, $boxId]);
        if ((int) $stmt->fetchColumn() === $supplier) {
            return true;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM isds_access_profiles p
            JOIN isds_access_profile_suppliers g ON g.profile_id = p.id
            WHERE p.environment = ? AND p.box_id = ? AND p.id <> ? AND g.can_receive = 1 LIMIT 1 FOR UPDATE');
        $stmt->execute([$environment, $boxId, $excludedProfile ?? 0]);
        if ($stmt->fetchColumn() !== false) {
            return false;
        }

        $pdo->prepare('UPDATE isds_access_inbox_owners SET supplier_id = ? WHERE environment = ? AND box_id = ?')
            ->execute([$supplier, $environment, $boxId]);
        return true;
    }

    public function delete(int $id, int $owner, string $scope, ?int $supplier = null): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM isds_access_profiles
            WHERE id = ? AND owner_user_id = ? AND scope = ? AND personal_supplier_id <=> ?');
        $stmt->execute([$id, $owner, $scope, $supplier]);
        return $stmt->rowCount() > 0;
    }
}
