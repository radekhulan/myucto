<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

/** Krátce držený PKCS#12 klientský certifikát předaný libcurl jen z paměti. */
final class IsdsClientCertificate
{
    private bool $cleared = false;

    private function __construct(
        private string $pkcs12,
        private string $passphrase,
    ) {}

    public static function fromBase64(string $encoded, string $passphrase): self
    {
        $pkcs12 = base64_decode($encoded, true);
        if ($pkcs12 === false || $pkcs12 === '') {
            throw new \UnexpectedValueException('PKCS#12 certifikát není platný Base64 dokument.');
        }

        $bundle = [];
        try {
            if (!@openssl_pkcs12_read($pkcs12, $bundle, $passphrase)) {
                self::erase($pkcs12);
                throw new \UnexpectedValueException('PKCS#12 certifikát nebo jeho heslo nejsou platné.');
            }
        } finally {
            foreach ($bundle as &$value) {
                if (is_string($value)) {
                    self::erase($value);
                } elseif (is_array($value)) {
                    foreach ($value as &$part) {
                        if (is_string($part)) {
                            self::erase($part);
                        }
                    }
                    unset($part);
                }
            }
            unset($value, $bundle);
        }

        return new self($pkcs12, $passphrase);
    }

    public function applyTo(\CurlHandle $handle): void
    {
        if ($this->cleared) {
            throw new \LogicException('Klientský certifikát už byl odstraněn z paměti.');
        }
        if (!defined('CURLOPT_SSLCERT_BLOB')) {
            throw new \RuntimeException('Použitá verze libcurl neumí klientský certifikát z paměti.');
        }
        if (!curl_setopt_array($handle, [
            CURLOPT_SSLCERT_BLOB => $this->pkcs12,
            CURLOPT_SSLCERTTYPE => 'P12',
            CURLOPT_KEYPASSWD => $this->passphrase,
        ])) {
            throw new \RuntimeException('Klientský certifikát se nepodařilo předat libcurl.');
        }
    }

    public function clear(): void
    {
        if ($this->cleared) {
            return;
        }
        self::erase($this->pkcs12);
        self::erase($this->passphrase);
        $this->cleared = true;
    }

    public function __destruct()
    {
        $this->clear();
    }

    private static function erase(string &$value): void
    {
        if ($value !== '' && function_exists('sodium_memzero')) {
            sodium_memzero($value);
        }
        $value = '';
    }
}
