<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Certificate;

/**
 * Výsledek ověření pokladního certifikátu. Metadata jsou bezpečná pro výpis;
 * `pkcs12` je citlivé (soukromý klíč) a smí jen do šifrovaného úložiště.
 */
final class EetInspectedCertificate
{
    public function __construct(
        public readonly string $environment,
        public readonly string $eic,
        public readonly string $subjectCn,
        public readonly string $serialNumber,
        public readonly string $fingerprintSha256,
        public readonly string $issuer,
        public readonly \DateTimeImmutable $notBefore,
        public readonly \DateTimeImmutable $notAfter,
        #[\SensitiveParameter] public readonly string $pkcs12,
    ) {}

    public function __debugInfo(): array
    {
        return [
            'environment' => $this->environment,
            'eic' => $this->eic,
            'fingerprint_sha256' => $this->fingerprintSha256,
            'not_after' => $this->notAfter->format(DATE_ATOM),
        ];
    }
}
