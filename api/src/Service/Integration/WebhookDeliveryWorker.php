<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Http\OutboundRequestException;
use MyInvoice\Service\Http\OutboundUrlGuard;
use PDO;

final class WebhookDeliveryWorker
{
    private const MAX_ATTEMPTS = 6;
    private const RETRY_DELAY = [60, 300, 1800, 7200, 21600];

    public function __construct(
        private readonly Connection $db,
        private readonly WebhookPolicy $policy,
        private readonly SecretEncryption $encryption,
        private readonly OutboundUrlGuard $http,
    ) {}

    public function run(int $limit = 25): int
    {
        $mode = $this->policy->mode();
        if ($mode === 'disabled' || !$this->policy->licensed()) {
            return 0;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Doručení webhooku vyžaduje samostatnou transakci.');
        }
        $allowedHosts = $mode === 'allowlist' ? $this->policy->allowedHosts() : [];
        $handled = 0;
        while ($handled < $limit && ($row = $this->claim($pdo)) !== null) {
            $this->deliver($pdo, $row, $allowedHosts);
            $handled++;
        }
        return $handled;
    }

    public function purgeExpired(): int
    {
        return $this->db->pdo()->exec(
            'DELETE FROM webhook_deliveries WHERE created_at < NOW(6) - INTERVAL 30 DAY
              ORDER BY created_at, id LIMIT 5000'
        );
    }

    private function claim(PDO $pdo): ?array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->query(
                "SELECT d.id, d.supplier_id, d.endpoint_id, d.attempts, d.event_id, d.body_json
                   FROM webhook_deliveries d
                   JOIN webhook_endpoints h ON h.supplier_id = d.supplier_id AND h.id = d.endpoint_id
                  WHERE d.status IN ('pending', 'processing') AND d.available_at <= NOW(6)
                    AND d.created_at >= NOW(6) - INTERVAL 30 DAY AND h.enabled = 1
                  ORDER BY d.available_at, d.id LIMIT 1 FOR UPDATE SKIP LOCKED"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                $row['lease_token'] = bin2hex(random_bytes(16));
                $row['attempts'] = (int) $row['attempts'] + 1;
                $pdo->prepare(
                    "UPDATE webhook_deliveries SET status = 'processing', attempts = ?, lease_token = ?,
                            available_at = NOW(6) + INTERVAL 90 SECOND WHERE id = ?"
                )->execute([$row['attempts'], $row['lease_token'], $row['id']]);
            }
            $pdo->commit();
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function deliver(PDO $pdo, array $row, array $allowedHosts): void
    {
        if ($row['attempts'] > self::MAX_ATTEMPTS) {
            $this->finish($pdo, $row, 'failed', null, 'lease_expired');
            return;
        }
        $stmt = $pdo->prepare(
            "SELECT h.url, h.secret_enc, h.enabled FROM webhook_deliveries d
               JOIN webhook_endpoints h ON h.supplier_id = d.supplier_id AND h.id = d.endpoint_id
              WHERE d.supplier_id = ? AND d.id = ? AND d.status = 'processing' AND d.lease_token = ?"
        );
        $stmt->execute([$row['supplier_id'], $row['id'], $row['lease_token']]);
        $endpoint = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($endpoint === false) {
            return;
        }
        if (!(bool) $endpoint['enabled']) {
            $pdo->prepare(
                "UPDATE webhook_deliveries SET status = 'pending', attempts = attempts - 1,
                        available_at = NOW(6), lease_token = NULL WHERE id = ? AND lease_token = ?"
            )->execute([$row['id'], $row['lease_token']]);
            return;
        }
        try {
            $this->http->assertSyntax($endpoint['url'], $allowedHosts);
        } catch (OutboundRequestException) {
            $this->finish($pdo, $row, 'failed', null, 'destination_blocked');
            return;
        }
        try {
            $key = base64_decode($this->encryption->decryptFor(
                $endpoint['secret_enc'],
                WebhookEndpoints::secretContext((int) $row['supplier_id'], (int) $row['endpoint_id']),
            ), true);
        } catch (\RuntimeException) {
            $key = false;
        }
        if ($key === false || strlen($key) !== 32) {
            $this->finish($pdo, $row, 'failed', null, 'invalid_secret');
            return;
        }

        $body = $row['body_json'];
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $row['event_id'] . '.' . $timestamp . '.' . $body, $key, true);
        $httpStatus = null;
        try {
            $httpStatus = $this->http->request('POST', $endpoint['url'], [
                'Content-Type' => 'application/json',
                'Webhook-Id' => $row['event_id'],
                'Webhook-Timestamp' => $timestamp,
                'Webhook-Signature' => 'v1,' . base64_encode($signature),
            ], $body, $allowedHosts, timeout: 8, maxBytes: 65536, directConnection: true)->status;
        } catch (OutboundRequestException) {
        }
        if ($httpStatus !== null && $httpStatus >= 200 && $httpStatus < 300) {
            $this->finish($pdo, $row, 'delivered', $httpStatus, null);
            return;
        }
        $this->finish($pdo, $row, $row['attempts'] >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
            $httpStatus, $httpStatus === null ? 'request_failed' : 'http_error');
    }

    private function finish(PDO $pdo, array $row, string $status, ?int $httpStatus, ?string $error): void
    {
        $delay = $status === 'pending' ? self::RETRY_DELAY[$row['attempts'] - 1] : 0;
        $pdo->prepare(
            'UPDATE webhook_deliveries SET status = ?, attempts = ?, http_status = ?, last_error_code = ?,
                    available_at = NOW(6) + INTERVAL ? SECOND, lease_token = NULL WHERE id = ? AND lease_token = ?'
        )->execute([$status, min(self::MAX_ATTEMPTS, $row['attempts']), $httpStatus, $error, $delay, $row['id'], $row['lease_token']]);
    }
}
