<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eet\Transport;

use MyInvoice\Service\Eet\Transport\EetMessageBuilder;
use MyInvoice\Service\Eet\Transport\EetSigningCredential;
use MyInvoice\Service\Eet\Transport\EetXml;
use MyInvoice\Service\Eet\Transport\EetXmlSignature;

/**
 * Syntetická PKI pro testy EET: vlastní kořenová CA, „certifikát správce daně“
 * a pokladní certifikáty. Všechno vzniká za běhu, žádné klíče v repozitáři.
 */
final class EetTestPki
{
    public const PASSWORD = 'Synth3tic';

    private static ?string $config = null;

    /** @var array<string, array{cert:string, key:\OpenSSLAsymmetricKey}> */
    private static array $cache = [];

    /** @return array{cert:string, key:\OpenSSLAsymmetricKey} */
    public static function ca(): array
    {
        return self::$cache['ca'] ??= self::issue(['commonName' => 'Synthetic EET Test Root'], null, 'v3_ca', 1);
    }

    /** Certifikát se stejnými znaky jako podpisový certifikát správce daně na playgroundu. */
    public static function taxAuthority(string $commonName = 'NEOSTRE - playground prostredi EET', string $organizationId = 'NTRCZ-72080043'): array
    {
        return self::$cache['fs-' . $commonName . $organizationId] ??= self::issue([
            'commonName' => $commonName,
            'countryName' => 'CZ',
            'organizationName' => 'Synthetic Tax Authority',
            'organizationIdentifier' => $organizationId,
        ], self::ca(), 'v3_leaf', 2);
    }

    /** Pokladní certifikát; `$section` určuje politiku (playground / produkce / žádná). */
    public static function cashier(string $eic = 'CZ00000019', string $section = 'v3_cashier_pg'): array
    {
        return self::$cache['cashier-' . $eic . $section] ??= self::issue(['commonName' => $eic, 'countryName' => 'CZ'], self::ca(), $section, 3);
    }

    public static function credential(string $eic = 'CZ00000019', string $section = 'v3_cashier_pg'): EetSigningCredential
    {
        $pair = self::cashier($eic, $section);
        $keyPem = '';
        openssl_pkey_export($pair['key'], $keyPem, null, self::configArgs());

        return EetSigningCredential::fromPem($pair['cert'], $keyPem);
    }

    public static function pkcs12(string $eic = 'CZ00000019', string $password = self::PASSWORD): string
    {
        $pair = self::cashier($eic);
        $out = '';
        openssl_pkcs12_export($pair['cert'], $out, $pair['key'], $password, ['extracerts' => [self::ca()['cert']]]);

        return $out;
    }

    public static function caFile(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eet-test-ca-' . getmypid() . '.pem';
        file_put_contents($path, self::ca()['cert']);

        return $path;
    }

    /**
     * SOAP odpověď podepsaná stejně jako potvrzení správce daně (BinarySecurityToken,
     * Exclusive C14N bez PrefixList, reference na Body).
     *
     * @param array{cert:string, key:\OpenSSLAsymmetricKey}|null $signer
     */
    public static function signedResponse(string $odpoved, ?array $signer = null): string
    {
        $signer ??= self::taxAuthority();
        $token = preg_replace('/-----[A-Z ]+-----|\s+/', '', $signer['cert']);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:eet="' . EetMessageBuilder::NAMESPACE . '" xmlns:wsu="' . EetXml::NS_WSU . '" xmlns:wsse="' . EetXml::NS_WSSE . '" xmlns:soapenv="' . EetXml::NS_SOAP . '">'
            . '<soapenv:Header><wsse:Security soapenv:mustUnderstand="1">'
            . '<wsse:BinarySecurityToken wsu:Id="SecurityToken-1" EncodingType="' . EetXml::ENCODING_BASE64 . '" ValueType="' . EetXml::VALUE_TYPE_X509 . '">' . $token . '</wsse:BinarySecurityToken>'
            . '<Signature xmlns="' . EetXml::NS_DS . '"><SignedInfo>'
            . '<CanonicalizationMethod Algorithm="' . EetXml::NS_EXC_C14N . '"/>'
            . '<SignatureMethod Algorithm="' . EetXml::ALG_RSA_SHA256 . '"/>'
            . '<Reference URI="#Body-1"><Transforms><Transform Algorithm="' . EetXml::NS_EXC_C14N . '"/></Transforms>'
            . '<DigestMethod Algorithm="' . EetXml::ALG_SHA256 . '"/><DigestValue></DigestValue></Reference>'
            . '</SignedInfo><SignatureValue></SignatureValue>'
            . '<KeyInfo><wsse:SecurityTokenReference><wsse:Reference URI="#SecurityToken-1" ValueType="' . EetXml::VALUE_TYPE_X509 . '"/></wsse:SecurityTokenReference></KeyInfo>'
            . '</Signature></wsse:Security></soapenv:Header>'
            . '<soapenv:Body wsu:Id="Body-1">' . $odpoved . '</soapenv:Body></soapenv:Envelope>';
        $document = EetXml::loadSafe($xml);
        $xpath = EetXml::xpath($document);
        $body = $xpath->query('/soapenv:Envelope/soapenv:Body')->item(0);
        $signedInfo = $xpath->query('//ds:SignedInfo')->item(0);
        $xpath->query('//ds:DigestValue')->item(0)->textContent = base64_encode(hash('sha256', EetXmlSignature::canonicalize($body, []), true));
        $signature = '';
        openssl_sign(EetXmlSignature::canonicalize($signedInfo, []), $signature, $signer['key'], OPENSSL_ALGO_SHA256);
        $xpath->query('//ds:SignatureValue')->item(0)->textContent = base64_encode($signature);

        return (string) $document->saveXML();
    }

    /** @return array{config:string} */
    public static function configArgs(): array
    {
        if (self::$config === null) {
            self::$config = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eet-test-openssl-' . getmypid() . '.cnf';
            file_put_contents(self::$config, implode("\n", [
                '[req]',
                'distinguished_name = dn',
                '[dn]',
                '[v3_ca]',
                'basicConstraints = critical,CA:TRUE',
                'keyUsage = critical,keyCertSign,cRLSign',
                '[v3_leaf]',
                'basicConstraints = CA:FALSE',
                'keyUsage = critical,digitalSignature,nonRepudiation',
                '[v3_cashier_pg]',
                'basicConstraints = CA:FALSE',
                'certificatePolicies = 1.2.203.19122063.10.2.102.0.1',
                '[v3_cashier_prod]',
                'basicConstraints = CA:FALSE',
                'certificatePolicies = 1.2.203.19122063.10.1.102.0.1',
                '[v3_plain]',
                'basicConstraints = CA:FALSE',
                '',
            ]));
        }

        return ['config' => self::$config];
    }

    /**
     * @param array<string,string> $dn
     * @param array{cert:string, key:\OpenSSLAsymmetricKey}|null $issuer
     * @return array{cert:string, key:\OpenSSLAsymmetricKey}
     */
    private static function issue(array $dn, ?array $issuer, string $section, int $serial): array
    {
        $args = self::configArgs() + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($args);
        if ($key === false) {
            throw new \RuntimeException('openssl_pkey_new selhal: ' . openssl_error_string());
        }
        $csr = openssl_csr_new($dn, $key, $args);
        if ($csr === false) {
            throw new \RuntimeException('openssl_csr_new selhal: ' . openssl_error_string());
        }
        $certificate = openssl_csr_sign($csr, $issuer['cert'] ?? null, $issuer['key'] ?? $key, 30, $args + ['x509_extensions' => $section], $serial);
        if ($certificate === false) {
            throw new \RuntimeException('openssl_csr_sign selhal: ' . openssl_error_string());
        }
        $pem = '';
        openssl_x509_export($certificate, $pem);

        return ['cert' => $pem, 'key' => $key];
    }
}
