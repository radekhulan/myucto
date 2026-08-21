<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Compatibility;

use MyInvoice\Infrastructure\Config\Config;

/** Stabilní opaque identita instance bez hostname, DB názvu nebo surového klíče. */
final class InstanceFingerprintProvider
{
    private const CONTEXT = 'myucto:tenant-transfer:instance-fingerprint:v1';

    public function __construct(private readonly Config $config) {}

    public function current(): string
    {
        $encoded = $this->config->get('app.secret_encryption_key', '');
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($key) || strlen($key) !== 32) {
            throw new InstanceFingerprintUnavailable(
                'application_key_unavailable',
                'Instance fingerprint vyžaduje platný aplikační šifrovací klíč.',
            );
        }
        return 'sha256:' . hash_hmac('sha256', self::CONTEXT, $key);
    }
}
