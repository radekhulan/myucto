<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Důvěryhodnost podepsaného potvrzení.
 *
 * 1. Kryptografie a tvar podpisu ({@see EetXmlSignature}) a shoda podepsaného
 *    `Body` s tím, ze kterého parser četl.
 * 2. Řetěz certifikátu podepisujícího jen proti připnutým certifikátům I.CA
 *    (`api/resources/eet/trust/`), ne proti systémovému úložišti.
 * 3. Certifikát platí v okamžiku ověření.
 * 4. Certifikát patří správci daně: I.CA vydává certifikáty komukoli, takže sám
 *    řetěz nestačí — `organizationIdentifier` musí být GFŘ (NTRCZ-72080043).
 * 5. Certifikát odpovídá prostředí: playground podepisuje certifikátem s CN
 *    „NEOSTRE - playground prostredi EET“; potvrzení s ním nesmí projít jako
 *    produkční a naopak.
 *
 * Revokace (CRL/OCSP I.CA) se neověřuje — vyžadovala by síťové volání v každém
 * pokusu; řeší ji výměna připnutých certifikátů.
 */
final class EetResponseVerifier
{
    public const TAX_AUTHORITY_ORGANIZATION_ID = 'NTRCZ-72080043';

    /**
     * @param list<string> $trustFiles PEM soubory kotev (kořen + zprostředkující CA)
     */
    public function __construct(
        private readonly array $trustFiles = [],
        private readonly string $organizationIdentifier = self::TAX_AUTHORITY_ORGANIZATION_ID,
    ) {}

    /** @return list<string> */
    public static function defaultTrustFiles(): array
    {
        $directory = dirname(__DIR__, 4) . '/resources/eet/trust';

        return [
            $directory . '/ica-root-ca-rsa-05-2022.pem',
            $directory . '/ica-public-ca-rsa-06-2022.pem',
        ];
    }

    /**
     * @return string PEM ověřeného certifikátu podepisujícího
     *
     * @throws EetSignatureException
     */
    public function verify(EetParsedResponse $response, EetEnvironment $environment, ?\DateTimeImmutable $at = null): string
    {
        if ($response->body === null) {
            throw new EetSignatureException('body_ambiguous', 'Odpověď nemá tělo SOAP.');
        }
        $verified = EetXmlSignature::verify($response->document);
        if (!$verified['body']->isSameNode($response->body)) {
            throw new EetSignatureException('reference_not_body', 'Podpis nekryje tělo, ze kterého byla odpověď přečtena.');
        }
        $certificatePem = $verified['certificatePem'];
        $parsed = openssl_x509_parse($certificatePem);
        if (!is_array($parsed)) {
            throw new EetSignatureException('certificate_missing', 'Certifikát podepisujícího nelze přečíst.');
        }
        $now = ($at ?? new \DateTimeImmutable('now'))->getTimestamp();
        if (!self::validAt($parsed, $now)) {
            throw new EetSignatureException('certificate_expired', 'Certifikát podepisujícího potvrzení není platný.');
        }
        $this->assertTrustedChain($certificatePem, $now);
        $organization = self::subjectValue($parsed, 'organizationIdentifier');
        if ($organization !== $this->organizationIdentifier) {
            throw new EetSignatureException('certificate_not_tax_authority', 'Potvrzení nepodepsal certifikát správce daně.');
        }
        $commonName = self::subjectValue($parsed, 'CN') ?? '';
        $isTestCertificate = stripos($commonName, 'playground') !== false || stripos($commonName, 'NEOSTRE') !== false;
        if ($isTestCertificate !== $environment->isTest()) {
            throw new EetSignatureException(
                'certificate_environment_mismatch',
                $environment->isTest()
                    ? 'Potvrzení z playgroundu nepodepsal testovací certifikát správce daně.'
                    : 'Produkční potvrzení podepsal testovací (neostrý) certifikát.',
            );
        }

        return $certificatePem;
    }

    /**
     * Řetěz: podepisující ← připnutá zprostředkující CA ← připnutý kořen. Ověřuje se
     * výslovně (podpisy, shoda vydavatele, příznak CA, platnost v okamžiku `$at`),
     * protože `openssl_x509_checkpurpose()` by platnost posuzoval jen k dnešku.
     */
    private function assertTrustedChain(string $certificatePem, int $at): void
    {
        $anchors = [];
        foreach ($this->trustFiles !== [] ? $this->trustFiles : self::defaultTrustFiles() as $file) {
            $pem = is_file($file) ? file_get_contents($file) : false;
            $certificate = is_string($pem) ? @openssl_x509_read($pem) : false;
            $parsed = $certificate !== false ? openssl_x509_parse($certificate) : false;
            if ($certificate === false || !is_array($parsed)) {
                self::clearOpensslErrors();
                throw new EetSignatureException('trust_store_missing', 'Připnuté certifikáty I.CA chybí v instalaci nebo jsou nečitelné.');
            }
            $anchors[] = ['certificate' => $certificate, 'parsed' => $parsed];
        }
        $current = @openssl_x509_read($certificatePem);
        if ($current === false) {
            self::clearOpensslErrors();
            throw new EetSignatureException('certificate_missing', 'Certifikát podepisujícího nelze přečíst.');
        }
        for ($depth = 0; $depth < 4; $depth++) {
            $currentParsed = openssl_x509_parse($current);
            $issuer = null;
            foreach ($anchors as $anchor) {
                if (!is_array($currentParsed) || ($currentParsed['issuer'] ?? null) !== ($anchor['parsed']['subject'] ?? false)
                    || !self::isCertificateAuthority($anchor['parsed']) || !self::validAt($anchor['parsed'], $at)
                ) {
                    continue;
                }
                $key = openssl_pkey_get_public($anchor['certificate']);
                if ($key !== false && openssl_x509_verify($current, $key) === 1) {
                    $issuer = $anchor;
                    break;
                }
            }
            self::clearOpensslErrors();
            if ($issuer === null) {
                throw new EetSignatureException('certificate_untrusted', 'Certifikát podepisujícího potvrzení nevydala připnutá autorita I.CA.');
            }
            if (($issuer['parsed']['subject'] ?? null) === ($issuer['parsed']['issuer'] ?? false)) {
                return;
            }
            $current = $issuer['certificate'];
        }

        throw new EetSignatureException('certificate_untrusted', 'Řetěz certifikátu podepisujícího je příliš dlouhý.');
    }

    /** @param array<string,mixed> $parsed */
    private static function validAt(array $parsed, int $at): bool
    {
        return $at >= (int) ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) && $at <= (int) ($parsed['validTo_time_t'] ?? 0);
    }

    /** @param array<string,mixed> $parsed */
    private static function isCertificateAuthority(array $parsed): bool
    {
        return str_contains((string) ($parsed['extensions']['basicConstraints'] ?? ''), 'CA:TRUE');
    }

    private static function clearOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }

    /** @param array<string,mixed> $parsed */
    private static function subjectValue(array $parsed, string $field): ?string
    {
        $value = $parsed['subject'][$field] ?? null;
        if (is_array($value)) {
            return count($value) === 1 && is_string($value[0] ?? null) ? $value[0] : null;
        }

        return is_string($value) ? $value : null;
    }
}
