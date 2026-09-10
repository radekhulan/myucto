<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

final class IntegrationWebhookService
{
    private const MAX_CLOCK_SKEW = 300;

    public function __construct(
        private readonly IntegrationConnectionService $connections,
        private readonly IntegrationInboxService $inbox,
    ) {}

    public function receive(string $connectionUuid, string $timestamp, string $signature, string $body): array
    {
        $connection = $this->connections->findRawByUuid($connectionUuid);
        if ($connection === null || $connection['status'] !== 'active') {
            throw new \RuntimeException('webhook_unauthorized');
        }
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            throw new \RuntimeException('webhook_timestamp_invalid');
        }
        $secret = $this->connections->webhookSecret($connection);
        $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : '';
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret ?? '');
        if ($secret === null || strlen($provided) !== 64 || !hash_equals($expected, $provided)) {
            throw new \RuntimeException('webhook_signature_invalid');
        }
        $event = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($event)) {
            throw new \InvalidArgumentException('Webhook musí být JSON objekt.');
        }
        return $this->inbox->accept((int) $connection['supplier_id'], (int) $connection['id'], $event, $body);
    }
}
