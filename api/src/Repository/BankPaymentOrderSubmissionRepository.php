<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class BankPaymentOrderSubmissionRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Stav unknown se zapisuje ještě před prvním HTTP voláním. Kolize unikátního
     * klíče vrací existující pokus a volající už banku nikdy znovu neosloví.
     *
     * @return array{created:bool,submission:array<string,mixed>}
     */
    public function begin(
        int $supplierId,
        int $orderId,
        int $connectionId,
        string $provider,
        string $payloadHash,
        ?int $userId,
        string $source = 'purchase_invoice',
    ): array
    {
        $column = $this->sourceColumn($source);
        if ($this->db->pdo()->inTransaction()) {
            throw new \LogicException('Bankovní odeslání nelze zahájit uvnitř databázové transakce.');
        }
        try {
            $stmt = $this->db->pdo()->prepare(
                "INSERT INTO bank_payment_order_submissions
                    (supplier_id, {$column}, connection_id, provider, payload_hash,
                     submitted_by_user_id, status, error_code)
                 VALUES (?, ?, ?, ?, ?, ?, 'unknown', 'submission_started')"
            );
            $stmt->execute([$supplierId, $orderId, $connectionId, $provider, $payloadHash, $userId]);
            $submission = $this->find($supplierId, $orderId, $source);
            if ($submission === null) {
                throw new \RuntimeException('Stav odeslání se nepodařilo načíst.');
            }
            return ['created' => true, 'submission' => $submission];
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $existing = $this->find($supplierId, $orderId, $source);
            if ($existing === null) {
                throw $e;
            }
            return ['created' => false, 'submission' => $existing];
        }
    }

    public function accept(int $supplierId, int $submissionId, string $reference, string $status = 'accepted_awaiting_authorization'): void
    {
        if (!in_array($status, ['accepted_awaiting_authorization', 'import_started'], true)) {
            throw new \InvalidArgumentException('Neplatný stav přijetí bankovního příkazu.');
        }
        $this->db->pdo()->prepare(
            "UPDATE bank_payment_order_submissions
                SET status = ?, provider_reference = ?,
                    error_code = NULL, submitted_at = CURRENT_TIMESTAMP
              WHERE supplier_id = ? AND id = ? AND status = 'unknown'"
        )->execute([$status, $reference, $supplierId, $submissionId]);
    }

    public function fail(
        int $supplierId,
        int $submissionId,
        string $status,
        string $errorCode,
        ?int $acceptedCount = null,
        ?int $rejectedCount = null,
        ?int $remoteHttpStatus = null,
    ): void {
        if (!in_array($status, ['rejected', 'unknown'], true)) {
            throw new \InvalidArgumentException('Neplatný stav bankovního odeslání.');
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE bank_payment_order_submissions
                SET status = ?, error_code = ?, accepted_count = ?, rejected_count = ?,
                    remote_http_status = ?, submitted_at = CURRENT_TIMESTAMP
              WHERE supplier_id = ? AND id = ? AND status = \'unknown\''
        );
        $stmt->execute([
            $status,
            mb_substr($errorCode, 0, 80),
            $acceptedCount,
            $rejectedCount,
            $remoteHttpStatus,
            $supplierId,
            $submissionId,
        ]);
    }

    public function find(int $supplierId, int $orderId, string $source = 'purchase_invoice'): ?array
    {
        $column = $this->sourceColumn($source);
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, payment_order_id, payroll_batch_id, connection_id, provider, status,
                    provider_reference, error_code, accepted_count, rejected_count,
                    created_at, submitted_at
               FROM bank_payment_order_submissions
              WHERE supplier_id = ? AND {$column} = ?"
        );
        $stmt->execute([$supplierId, $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->publicProjection($row) : null;
    }

    private function sourceColumn(string $source): string
    {
        return match ($source) {
            'purchase_invoice' => 'payment_order_id',
            'payroll' => 'payroll_batch_id',
            default => throw new \InvalidArgumentException('Neplatný zdroj bankovního příkazu.'),
        };
    }

    private function publicProjection(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            ...($row['payment_order_id'] !== null
                ? ['payment_order_id' => (int) $row['payment_order_id']]
                : ['payroll_batch_id' => (int) $row['payroll_batch_id']]),
            'connection_id' => $row['connection_id'] !== null ? (int) $row['connection_id'] : null,
            'provider' => (string) $row['provider'],
            'status' => (string) $row['status'],
            'provider_reference' => $row['provider_reference'] ?: null,
            'error_code' => $row['error_code'] ?: null,
            'accepted_count' => $row['accepted_count'] !== null ? (int) $row['accepted_count'] : null,
            'rejected_count' => $row['rejected_count'] !== null ? (int) $row['rejected_count'] : null,
            'created_at' => $row['created_at'],
            'submitted_at' => $row['submitted_at'] ?: null,
        ];
    }
}
