<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Database\{Connection, NamedLockName, TransactionFailure};
use MyInvoice\Service\Mail\RateLimit\MailOutbox;
use PDO;

final class PaymentThanksOutbox
{
    public const TEMPLATE = '__payment_thanks_intent_v1';

    public function __construct(
        private readonly Connection $db,
        private readonly ?\MyInvoice\Service\ActivityLogger $activity = null,
    ) {}

    public function enqueue(int $invoiceId, string $trigger, ?int $userId, ?string $ip, ?string $userAgent, bool $requireUnsent): array
    {
        $pdo = $this->db->pdo();
        try {
            $stmt = $pdo->prepare('SELECT supplier_id FROM invoices WHERE id = ?');
            $stmt->execute([$invoiceId]);
            $supplierId = (int) $stmt->fetchColumn();
            if ($supplierId <= 0) return ['status' => 'skipped', 'reason' => 'not_found'];
            $payload = json_encode([
                'version' => 1, 'invoice_id' => $invoiceId, 'supplier_id' => $supplierId,
                'trigger' => $trigger, 'user_id' => $userId, 'ip' => $ip,
                'user_agent' => $userAgent, 'require_unsent' => $requireUnsent,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $pdo->prepare("INSERT INTO mail_outbox
                (created_at,not_before,status,attempts,template,locale,recipients,defer_reason,payload)
                VALUES (NOW(3),NOW(3),'pending',0,?,'',0,'payment_commit',?)")
                ->execute([self::TEMPLATE, $payload]);
            return ['status' => 'queued', 'reason' => $pdo->inTransaction() ? 'awaiting_commit' : 'delivery_busy', 'outbox_id' => (int) $pdo->lastInsertId()];
        } catch (\Throwable $e) {
            TransactionFailure::rollbackIfAborted($pdo, $e);
            throw $e;
        }
    }

    public function withInvoiceLock(int $invoiceId, callable $send): ?array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) throw new \LogicException('Poděkování nelze odeslat v neukončené transakci.');
        $name = NamedLockName::for($this->db, 'payment_thanks', $invoiceId);
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$name]);
        $acquired = $stmt->fetchColumn();
        if ((string) $acquired === '0') return null;
        if ((string) $acquired !== '1') throw new \RuntimeException('Zámek poděkování nelze získat.');
        try {
            return $send();
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    public function flush(callable $send, ?int $invoiceId = null, int $max = 25): array
    {
        $report = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'queued' => 0];
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) return $report;
        $sql = "SELECT id,payload FROM mail_outbox WHERE template=? AND status='pending' AND not_before<=NOW(3)";
        $params = [self::TEMPLATE];
        if ($invoiceId !== null) {
            $sql .= " AND JSON_VALUE(payload,'$.invoice_id')=?";
            $params[] = $invoiceId;
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY not_before,id LIMIT ' . max(1, min(200, $max)));
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $payload = json_decode((string) $candidate['payload'], true);
            if (!is_array($payload) || ($payload['version'] ?? null) !== 1
                || !is_int($payload['invoice_id'] ?? null) || $payload['invoice_id'] <= 0
                || !is_int($payload['supplier_id'] ?? null) || $payload['supplier_id'] <= 0
                || !in_array($payload['trigger'] ?? null, ['manual', 'bulk', 'bank_match'], true)
                || !is_bool($payload['require_unsent'] ?? null)) {
                $this->finish((int) $candidate['id'], 'failed', 'invalid_intent');
                $this->logFailure(is_array($payload) ? $payload : [], 'invalid_intent');
                $report['failed']++;
                continue;
            }
            $result = $this->withInvoiceLock($payload['invoice_id'], function () use ($pdo, $candidate, $payload, $send): array {
                $current = $pdo->prepare("SELECT attempts FROM mail_outbox WHERE id=? AND template=? AND status='pending' AND not_before<=NOW(3)");
                $current->execute([(int) $candidate['id'], self::TEMPLATE]);
                $attempts = $current->fetchColumn();
                if ($attempts === false) return ['status' => 'skipped'];
                $tenant = $pdo->prepare('SELECT supplier_id FROM invoices WHERE id=?');
                $tenant->execute([$payload['invoice_id']]);
                if ((int) $tenant->fetchColumn() !== $payload['supplier_id']) {
                    $this->finish((int) $candidate['id'], 'failed', 'invoice_owner_changed');
                    return ['status' => 'failed'];
                }
                try {
                    $result = $send($payload);
                } catch (\Throwable $e) {
                    if (TransactionFailure::aborted($e)) throw $e;
                    $result = ['status' => 'failed', 'reason' => 'delivery_failed'];
                    // Selhání mimo SMTP mailer sám nezaloguje (to dělá jen u odeslání).
                    $this->logFailure($payload, 'delivery_failed: ' . $e->getMessage());
                }
                if ($result['status'] === 'failed') {
                    if ((int) $attempts + 1 >= MailOutbox::MAX_ATTEMPTS) {
                        $this->finish((int) $candidate['id'], 'failed', 'delivery_failed');
                    } else {
                        $pdo->prepare("UPDATE mail_outbox SET attempts=attempts+1,not_before=DATE_ADD(NOW(3),INTERVAL 15 MINUTE),last_error='delivery_failed' WHERE id=?")
                            ->execute([(int) $candidate['id']]);
                    }
                } else {
                    $this->finish((int) $candidate['id'], $result['status'] === 'sent' ? 'sent' : 'requeued', $result['reason'] ?? $result['status']);
                }
                return $result;
            });
            $report[$result['status'] ?? 'queued']++;
        }
        return $report;
    }

    /** @param array<string,mixed> $payload */
    private function logFailure(array $payload, string $reason): void
    {
        $invoiceId = is_int($payload['invoice_id'] ?? null) ? $payload['invoice_id'] : 0;
        if ($this->activity === null || $invoiceId <= 0) {
            return;
        }
        $this->activity->log('invoice.payment_thanks_failed', is_int($payload['user_id'] ?? null) ? $payload['user_id'] : null,
            'invoice', $invoiceId, [
                'reason'  => mb_substr($reason, 0, 500),
                'trigger' => $payload['trigger'] ?? null,
                'queued'  => true,
            ]);
    }

    private function finish(int $id, string $status, string $reason): void
    {
        $this->db->pdo()->prepare('UPDATE mail_outbox SET status=?,attempts=attempts+1,sent_at=IF(?=\'sent\',NOW(3),NULL),last_error=? WHERE id=?')
            ->execute([$status, $status, $reason, $id]);
    }
}
