<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Pokladní certifikát se soukromým klíčem, jen v paměti procesu.
 *
 * Klíč drží jako objekt OpenSSL (ne jako PEM řetězec) a ven ho nevydává; podpis
 * se dělá metodou {@see sign()}. Serializace je zakázaná a `var_dump`/`print_r`
 * ukáže jen veřejná metadata, takže se klíč nedostane do logu ani do cache.
 */
final class EetSigningCredential
{
    private const EIC_PATTERN = '/^CZ[0-9]{8,10}$/D';

    /**
     * Politika pokladního certifikátu (OID) podle provozních informací:
     * 1.2.203.19122063.10.1.* = produkce, ostatní větve .10.* = neprodukční
     * (dokumenty uvádějí .10.4, skutečné playground certifikáty nesou .10.2).
     */
    private const POLICY_PRODUCTION = '1.2.203.19122063.10.1.';
    private const POLICY_ROOT = '1.2.203.19122063.10.';

    /** @var array<string,mixed> */
    private array $parsed;

    private function __construct(
        private readonly string $certificatePem,
        private readonly \OpenSSLAsymmetricKey $privateKey,
    ) {
        $parsed = openssl_x509_parse($certificatePem);
        if (!is_array($parsed)) {
            throw new EetCredentialException('unreadable', 'Certifikát nelze přečíst.');
        }
        $this->parsed = $parsed;
    }

    public static function fromPkcs12(string $pkcs12, #[\SensitiveParameter] string $password): self
    {
        $bundle = EetPkcs12Reader::read($pkcs12, $password);

        return self::fromParts($bundle['cert'], $bundle['pkey']);
    }

    /** Z certifikátu a klíče ve formátu PEM (hlavně pro testy a import ze zabezpečeného úložiště). */
    public static function fromPem(string $certificatePem, #[\SensitiveParameter] string $privateKeyPem, #[\SensitiveParameter] ?string $passphrase = null): self
    {
        $key = @openssl_pkey_get_private($privateKeyPem, $passphrase);
        self::clearOpensslErrors();
        if ($key === false) {
            throw new EetCredentialException('private_key_missing', 'Soukromý klíč nelze načíst.');
        }

        return self::fromParts($certificatePem, $key);
    }

    private static function fromParts(string $certificatePem, \OpenSSLAsymmetricKey $key): self
    {
        $certificate = @openssl_x509_read($certificatePem);
        if ($certificate === false) {
            self::clearOpensslErrors();
            throw new EetCredentialException('unreadable', 'Certifikát nelze přečíst.');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new EetCredentialException('unsupported_key', 'Pokladní certifikát musí mít klíč RSA.');
        }
        if (!@openssl_x509_check_private_key($certificate, $key)) {
            self::clearOpensslErrors();
            throw new EetCredentialException('key_mismatch', 'Soukromý klíč nepatří k certifikátu.');
        }
        $pem = '';
        openssl_x509_export($certificate, $pem);
        self::clearOpensslErrors();

        return new self($pem, $key);
    }

    public function certificatePem(): string
    {
        return $this->certificatePem;
    }

    /** Certifikát v DER/base64 bez hlaviček, jak se vkládá do BinarySecurityToken. */
    public function certificateBase64(): string
    {
        return preg_replace('/-----[A-Z ]+-----|\s+/', '', $this->certificatePem) ?? '';
    }

    /** EIČ z CN certifikátu; `null`, když CN nemá tvar EIČ. */
    public function eic(): ?string
    {
        $cn = $this->parsed['subject']['CN'] ?? null;
        $cn = is_array($cn) ? ($cn[0] ?? null) : $cn;

        return is_string($cn) && preg_match(self::EIC_PATTERN, $cn) === 1 ? $cn : null;
    }

    public function notBefore(): \DateTimeImmutable
    {
        return self::fromTimestamp((int) ($this->parsed['validFrom_time_t'] ?? 0));
    }

    public function notAfter(): \DateTimeImmutable
    {
        return self::fromTimestamp((int) ($this->parsed['validTo_time_t'] ?? 0));
    }

    public function serialNumber(): string
    {
        return strtoupper((string) ($this->parsed['serialNumberHex'] ?? ''));
    }

    public function fingerprintSha256(): string
    {
        return strtolower((string) openssl_x509_fingerprint($this->certificatePem, 'sha256'));
    }

    public function isValidAt(\DateTimeImmutable $at): bool
    {
        return $at >= $this->notBefore() && $at <= $this->notAfter();
    }

    /** Odhad prostředí podle politiky certifikátu a vydavatele; `null`, když nejde poznat. */
    public function environmentHint(): ?EetEnvironment
    {
        $policies = (string) ($this->parsed['extensions']['certificatePolicies'] ?? '');
        if (preg_match_all('/Policy:\s*([0-9.]+)/', $policies, $matches) > 0) {
            foreach ($matches[1] as $oid) {
                if (str_starts_with($oid . '.', self::POLICY_PRODUCTION)) {
                    return EetEnvironment::Production;
                }
                if (str_starts_with($oid, self::POLICY_ROOT)) {
                    return EetEnvironment::Playground;
                }
            }
        }
        $issuer = $this->parsed['issuer']['CN'] ?? '';
        $issuer = is_array($issuer) ? implode(' ', $issuer) : (string) $issuer;
        if (stripos($issuer, 'playground') !== false) {
            return EetEnvironment::Playground;
        }

        return null;
    }

    /** Podpis RSA-SHA256 (PKCS#1 v1.5). */
    public function sign(string $data): string
    {
        $signature = '';
        if (!openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            self::clearOpensslErrors();
            throw new EetCredentialException('signing_failed', 'Datovou zprávu se nepodařilo podepsat.');
        }

        return $signature;
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return [
            'eic' => $this->eic(),
            'serialNumber' => $this->serialNumber(),
            'notAfter' => $this->notAfter()->format(DATE_ATOM),
            'privateKey' => '***',
        ];
    }

    /** @return array<string,mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Pokladní certifikát se soukromým klíčem nelze serializovat.');
    }

    /** @param array<string,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Pokladní certifikát se soukromým klíčem nelze deserializovat.');
    }

    private static function fromTimestamp(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('Europe/Prague'));
    }

    private static function clearOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}
