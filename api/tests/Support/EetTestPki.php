<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Eet\Certificate\EetTrustStore;

/**
 * Syntetická PKI pro testy EET: kořen + podřízená CA pro Playground i produkci,
 * cizí CA a z nich vydané pokladní certifikáty. Nic reálného — klíče vznikají
 * v běhu testu a nikam se neukládají.
 */
final class EetTestPki
{
    public const PASSWORD = 'syntetickeHeslo-123';

    /** @var array<string, array{cert:string,key:\OpenSSLAsymmetricKey}>|null */
    private static ?array $authorities = null;
    private static ?string $config = null;
    private static int $serial = 1000;

    public static function trustStore(): EetTrustStore
    {
        $ca = self::authorities();
        return new EetTrustStore([
            'playground' => [$ca['playground-root']['cert'], $ca['playground-sub']['cert']],
            'production' => [$ca['production-root']['cert'], $ca['production-sub']['cert']],
        ]);
    }

    /**
     * Pokladní certifikát jako PKCS#12.
     *
     * @param 'playground'|'production'|'foreign' $issuer
     */
    public static function pkcs12(
        string $issuer = 'playground',
        string $cn = 'CZ00000019',
        int $days = 30,
        string $extensions = 'v3_leaf',
        string $password = self::PASSWORD,
    ): string {
        [$cert, $key, $chain] = self::leaf($issuer, $cn, $days, $extensions);
        $out = '';
        if (!openssl_pkcs12_export($cert, $out, $key, $password, ['extracerts' => $chain])) {
            throw new \RuntimeException('Syntetický PKCS#12 se nepodařilo vytvořit.');
        }
        return $out;
    }

    /**
     * Rozbalený balík pro `EetCertificateInspector::inspectBundle()`.
     *
     * @return array{cert:string,pkey:string,extracerts:list<string>}
     */
    public static function bundle(string $issuer = 'playground', string $cn = 'CZ00000019', bool $withKey = true, bool $foreignKey = false): array
    {
        [$cert, $key, $chain] = self::leaf($issuer, $cn, 30, 'v3_leaf');
        $pem = '';
        openssl_pkey_export($foreignKey ? self::newKey() : $key, $pem, null, ['config' => self::config()]);
        $certPem = '';
        openssl_x509_export($cert, $certPem);
        return ['cert' => $certPem, 'pkey' => $withKey ? $pem : '', 'extracerts' => $chain];
    }

    /** @return array{0:\OpenSSLCertificate,1:\OpenSSLAsymmetricKey,2:list<string>} */
    private static function leaf(string $issuer, string $cn, int $days, string $extensions): array
    {
        $ca = self::authorities();
        $parent = match ($issuer) {
            'playground' => $ca['playground-sub'],
            'production' => $ca['production-sub'],
            default => $ca['foreign-root'],
        };
        $chain = match ($issuer) {
            'playground' => [$ca['playground-sub']['cert'], $ca['playground-root']['cert']],
            'production' => [$ca['production-sub']['cert'], $ca['production-root']['cert']],
            default => [$ca['foreign-root']['cert']],
        };
        $key = self::newKey();
        $csr = openssl_csr_new(['countryName' => 'CZ', 'commonName' => $cn], $key, self::options());
        $cert = openssl_csr_sign($csr, $parent['cert'], $parent['key'], $days, self::options($extensions), self::$serial++);
        if ($cert === false) {
            throw new \RuntimeException('Syntetický certifikát se nepodařilo podepsat.');
        }
        return [$cert, $key, $chain];
    }

    /** @return array<string, array{cert:string,key:\OpenSSLAsymmetricKey}> */
    private static function authorities(): array
    {
        if (self::$authorities !== null) {
            return self::$authorities;
        }
        $out = [];
        foreach (['playground', 'production', 'foreign'] as $name) {
            $rootKey = self::newKey();
            $rootCsr = openssl_csr_new(['countryName' => 'CZ', 'organizationName' => 'Test PKI', 'commonName' => "Test {$name} Root"], $rootKey, self::options());
            $root = openssl_csr_sign($rootCsr, null, $rootKey, 3650, self::options('v3_ca'), self::$serial++);
            $rootPem = '';
            openssl_x509_export($root, $rootPem);
            $out[$name . '-root'] = ['cert' => $rootPem, 'key' => $rootKey];
            if ($name === 'foreign') {
                continue;
            }
            $subKey = self::newKey();
            $subCsr = openssl_csr_new(['countryName' => 'CZ', 'organizationName' => 'Test PKI', 'commonName' => "Test {$name} SubCA"], $subKey, self::options());
            $sub = openssl_csr_sign($subCsr, $rootPem, $rootKey, 3650, self::options('v3_ca'), self::$serial++);
            $subPem = '';
            openssl_x509_export($sub, $subPem);
            $out[$name . '-sub'] = ['cert' => $subPem, 'key' => $subKey];
        }
        return self::$authorities = $out;
    }

    private static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => self::config(),
        ]);
        if ($key === false) {
            throw new \RuntimeException('Syntetický klíč se nepodařilo vytvořit (OPENSSL_CONF?).');
        }
        return $key;
    }

    /** @return array<string,mixed> */
    private static function options(?string $extensions = null): array
    {
        $options = ['config' => self::config(), 'digest_alg' => 'sha256'];
        if ($extensions !== null) {
            $options['x509_extensions'] = $extensions;
        }
        return $options;
    }

    private static function config(): string
    {
        if (self::$config !== null && is_file(self::$config)) {
            return self::$config;
        }
        $path = tempnam(sys_get_temp_dir(), 'eetpki');
        file_put_contents($path, <<<'CNF'
            [req]
            distinguished_name = dn
            prompt = no
            [dn]
            CN = placeholder
            [v3_ca]
            basicConstraints = critical,CA:TRUE
            keyUsage = critical,keyCertSign,cRLSign
            subjectKeyIdentifier = hash
            [v3_leaf]
            basicConstraints = critical,CA:FALSE
            keyUsage = critical,digitalSignature,nonRepudiation
            [v3_leaf_encipherment]
            basicConstraints = critical,CA:FALSE
            keyUsage = critical,keyEncipherment
            CNF);
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });
        return self::$config = $path;
    }
}
