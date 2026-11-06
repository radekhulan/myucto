<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Certificate;

use MyInvoice\Service\Eet\EetException;
use MyInvoice\Service\Eet\EetIdentifiers;
use MyInvoice\Service\Eet\Transport\EetCredentialException;
use MyInvoice\Service\Eet\Transport\EetSigningCredential;

/**
 * Ověří nahraný pokladní certifikát EET (PKCS#12 + heslo) dřív, než se uloží.
 *
 * Soubor otevírá {@see EetSigningCredential::fromPkcs12()} — stejná čtečka,
 * jakou pak podepisuje transport, takže projde i RC2 z oficiálních souborů CA
 * bez legacy provideru OpenSSL a uloží se jen to, co půjde použít. Čtečka
 * ověří soukromý klíč, RSA a shodu klíče s certifikátem; tady navíc délka
 * klíče, CN = DIČ poplatníka (EIČ), účel „Digital Signature", doba platnosti
 * a řetězec k připnuté autoritě EET, podle které se určí prostředí. Politika
 * certifikátu ({@see EetSigningCredential::environmentHint()}) mu nesmí odporovat.
 * Text chyb OpenSSL ani čtečky se do odpovědi nepropisuje.
 */
final class EetCertificateInspector
{
    private const MAX_PKCS12_BYTES = 65536;
    private const MAX_CHAIN_DEPTH = 4;

    public function __construct(private readonly EetTrustStore $trust) {}

    public function inspect(
        #[\SensitiveParameter] string $pkcs12,
        #[\SensitiveParameter] string $password,
        ?\DateTimeImmutable $now = null,
    ): EetInspectedCertificate {
        $now ??= new \DateTimeImmutable();
        if ($pkcs12 === '' || strlen($pkcs12) > self::MAX_PKCS12_BYTES) {
            throw EetException::validation('Nahrajte soubor pokladního certifikátu ve formátu P12 (nejvýš 64 kB).');
        }
        try {
            $credential = EetSigningCredential::fromPkcs12($pkcs12, $password);
        } catch (EetCredentialException $exception) {
            throw self::fromCredentialException($exception);
        }

        return $this->inspectCredential($credential, $pkcs12, $now);
    }

    /** Převod chyby čtečky PKCS#12 na odpověď API bez technických detailů. */
    public static function fromCredentialException(EetCredentialException $exception): EetException
    {
        return match ($exception->reason) {
            'private_key_missing' => new EetException('eet_certificate_no_private_key', 'Soubor neobsahuje soukromý klíč. Nahrajte P12 se soukromým klíčem.', 422),
            'key_mismatch' => new EetException('eet_certificate_key_mismatch', 'Soukromý klíč v souboru nepatří k certifikátu.', 422),
            'unsupported_key' => new EetException('eet_certificate_key_type', 'Pokladní certifikát musí mít klíč RSA o délce alespoň 2048 bitů.', 422),
            default => new EetException('eet_certificate_unreadable', 'Soubor certifikátu nelze otevřít. Zkontrolujte soubor a heslo.', 422),
        };
    }

    private function inspectCredential(EetSigningCredential $credential, #[\SensitiveParameter] string $pkcs12, \DateTimeImmutable $now): EetInspectedCertificate
    {
        $certificate = $credential->certificatePem();
        $parsed = @openssl_x509_parse($certificate);
        if (!is_array($parsed)) {
            throw new EetException('eet_certificate_invalid', 'Certifikát se nepodařilo přečíst.', 422);
        }

        $publicKey = @openssl_pkey_get_public($certificate);
        $details = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
        if (!is_array($details) || (int) ($details['bits'] ?? 0) < 2048) {
            throw new EetException('eet_certificate_key_type', 'Pokladní certifikát musí mít klíč RSA o délce alespoň 2048 bitů.', 422);
        }

        $cn = self::nameField($parsed['subject'] ?? [], 'CN');
        $eic = $credential->eic();
        if ($eic === null || !EetIdentifiers::isEic($eic)) {
            throw new EetException('eet_certificate_subject', 'Certifikát nemá v CN platné DIČ poplatníka (EIČ). Nejde o pokladní certifikát EET.', 422);
        }

        $keyUsage = (string) ($parsed['extensions']['keyUsage'] ?? '');
        if (!str_contains($keyUsage, 'Digital Signature')) {
            throw new EetException('eet_certificate_key_usage', 'Certifikát není určen k elektronickému podpisu (chybí Digital Signature).', 422);
        }

        $notBefore = $credential->notBefore()->setTimezone($now->getTimezone());
        $notAfter = $credential->notAfter()->setTimezone($now->getTimezone());
        if ($notBefore > $now) {
            throw new EetException('eet_certificate_not_yet_valid', 'Certifikát ještě není platný (platnost od ' . $notBefore->format('j. n. Y H:i') . ').', 422);
        }
        if ($notAfter <= $now) {
            throw new EetException('eet_certificate_expired', 'Platnost certifikátu skončila ' . $notAfter->format('j. n. Y H:i') . '.', 422);
        }

        $environment = $this->trustedEnvironment($certificate, $now);
        if ($environment === null) {
            throw new EetException(
                'eet_certificate_untrusted',
                'Certifikát nevydala certifikační autorita EET (Playground ani produkční). Nahrajte pokladní certifikát z aplikace CA EET.',
                422,
            );
        }
        $hint = $credential->environmentHint();
        if ($hint !== null && $hint->value !== $environment) {
            throw new EetException('eet_certificate_untrusted', 'Politika certifikátu neodpovídá autoritě, která ho vydala.', 422);
        }

        return new EetInspectedCertificate(
            $environment,
            $eic,
            mb_substr($cn, 0, 64),
            substr($credential->serialNumber(), 0, 80),
            $credential->fingerprintSha256(),
            mb_substr(self::issuerLabel($parsed['issuer'] ?? []), 0, 255),
            $notBefore,
            $notAfter,
            $pkcs12,
        );
    }

    /**
     * Prostředí autority, ke které certifikát vede platným podpisovým řetězcem
     * až ke kořeni z úložiště důvěry; `null`, když k žádné. Řetězec přibalený
     * v souboru se nepoužívá.
     */
    private function trustedEnvironment(string $certificate, \DateTimeImmutable $now): ?string
    {
        foreach ($this->trust->anchors() as $environment => $authorities) {
            if ($this->chainsTo($certificate, $authorities, $now, 0)) {
                return (string) $environment;
            }
        }
        return null;
    }

    /** @param list<string> $authorities */
    private function chainsTo(string $certificate, array $authorities, \DateTimeImmutable $now, int $depth): bool
    {
        if ($depth > self::MAX_CHAIN_DEPTH) {
            return false;
        }
        $parsed = @openssl_x509_parse($certificate);
        if (!is_array($parsed)) {
            return false;
        }
        foreach ($authorities as $authority) {
            $ca = @openssl_x509_parse($authority);
            if (!is_array($ca) || ($ca['subject'] ?? null) != ($parsed['issuer'] ?? null)) {
                continue;
            }
            if (!str_contains((string) ($ca['extensions']['basicConstraints'] ?? ''), 'CA:TRUE')) {
                continue;
            }
            $from = (int) ($ca['validFrom_time_t'] ?? 0);
            $to = (int) ($ca['validTo_time_t'] ?? 0);
            if ($from > $now->getTimestamp() || $to <= $now->getTimestamp()) {
                continue;
            }
            if (@openssl_x509_verify($certificate, $authority) !== 1) {
                continue;
            }
            $selfSigned = ($ca['subject'] ?? null) == ($ca['issuer'] ?? null)
                && @openssl_x509_verify($authority, $authority) === 1;
            if ($selfSigned || $this->chainsTo($authority, $authorities, $now, $depth + 1)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $name */
    private static function nameField(array $name, string $field): string
    {
        $value = $name[$field] ?? '';
        if (is_array($value)) {
            $value = (string) (reset($value) ?: '');
        }
        return trim((string) $value);
    }

    /** @param array<string,mixed> $issuer */
    private static function issuerLabel(array $issuer): string
    {
        $parts = [];
        foreach (['CN', 'O', 'C'] as $field) {
            $value = self::nameField($issuer, $field);
            if ($value !== '') {
                $parts[] = $field . '=' . $value;
            }
        }
        return implode(', ', $parts);
    }
}
