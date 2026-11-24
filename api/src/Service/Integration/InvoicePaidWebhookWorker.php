<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Http\OutboundUrlGuard;
use PDO;

final class InvoicePaidWebhookWorker
{
    private const MAX_ATTEMPTS = 6;

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $encryption,
        private readonly OutboundUrlGuard $http,
    ) {}

    /** Jeden omezený průchod frontou. Propadlá rezervace po pádu také spotřebuje pokus. */
    public function run(int $limit = 25): int
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Doručení webhooku vyžaduje samostatné spojení bez transakce.');
        }
        // Jeden průchod smí každou zprávu zkusit nejvýše jednou.
        $cutoff = $pdo->query('SELECT NOW(6)')->fetchColumn();
        $claim = $pdo->prepare('SELECT id, hook_id, event_id, body_json, attempts
            FROM invoice_paid_deliveries WHERE status=? AND available_at <= ?
            ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED');
        for ($i = 0; $i < $limit; $i++) {
            $pdo->beginTransaction();
            try {
                foreach (['processing', 'pending'] as $state) {
                    $claim->execute([$state, $cutoff]);
                    $row = $claim->fetch(PDO::FETCH_ASSOC);
                    if ($row) break;
                }
                if (!$row) {
                    $pdo->commit();
                    break;
                }
                if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
                    $pdo->prepare("UPDATE invoice_paid_deliveries SET status='failed', lease_token=NULL WHERE id=?")
                        ->execute([$row['id']]);
                    $pdo->commit();
                    continue;
                }
                $lease = bin2hex(random_bytes(16));
                $pdo->prepare("UPDATE invoice_paid_deliveries SET status='processing', attempts=attempts+1,
                    available_at=DATE_ADD(NOW(6), INTERVAL 60 SECOND), lease_token=? WHERE id=?")
                    ->execute([$lease, $row['id']]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            $target = $pdo->prepare('SELECT supplier_id, id, url, secret_enc FROM invoice_paid_webhooks WHERE id=?');
            $target->execute([$row['hook_id']]);
            $hook = $target->fetch(PDO::FETCH_ASSOC);
            if (!$hook) continue;
            $code = null;
            try {
                $key = $this->encryption->decryptFor($hook['secret_enc'], InvoicePaidWebhooks::context((int) $hook['supplier_id'], (int) $hook['id']));
                $timestamp = (string) time();
                $body = $row['body_json'];
                $signed = $row['event_id'] . '.' . $timestamp . '.' . $body;
                $rawKey = base64_decode($key, true);
                if ($rawKey === false || strlen($rawKey) !== 32) {
                    throw new \RuntimeException('Neplatný podpisový klíč webhooku.');
                }
                $signature = base64_encode(hash_hmac('sha256', $signed, $rawKey, true));
                $headers = [
                    'Content-Type' => 'application/json',
                    'Webhook-Id' => $row['event_id'],
                    'Webhook-Timestamp' => $timestamp,
                    'Webhook-Signature' => 'v1,' . $signature,
                ];
                $code = $this->http->request('POST', $hook['url'], $headers, $body, timeout: 8, maxBytes: 65536)->status;
            } catch (\RuntimeException) {
                // Cílová URL ani odpověď příjemce nepatří do chybového protokolu.
            }
            $attempt = (int) $row['attempts'] + 1;
            $status = $code === 200 ? 'delivered' : ($attempt >= self::MAX_ATTEMPTS ? 'failed' : 'pending');
            $pdo->prepare('UPDATE invoice_paid_deliveries SET status=?, http_status=?, lease_token=NULL,
                available_at=NOW(6) WHERE id=? AND lease_token=?')
                ->execute([$status, $code, $row['id'], $lease]);
        }
        return $i;
    }
}
