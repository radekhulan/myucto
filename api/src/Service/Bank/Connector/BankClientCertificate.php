<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class BankClientCertificate
{
    public static function curlOptions(#[\SensitiveParameter] string $base64, #[\SensitiveParameter] string $password): array
    {
        if (!extension_loaded('curl') || !defined('CURLOPT_SSLCERT_BLOB') || !defined('CURLOPT_SSLKEY_BLOB')) {
            throw new BankConnectorOperationException('certificate_runtime_unavailable');
        }
        if (strlen($base64) > 32768 || strlen($password) > 1024) {
            throw new BankConnectorOperationException('certificate_invalid');
        }
        $bytes = base64_decode($base64, true);
        $bundle = [];
        if ($bytes === false || $bytes === '' || !@openssl_pkcs12_read($bytes, $bundle, $password)) {
            self::clearErrors();
            throw new BankConnectorOperationException('certificate_invalid');
        }
        $certificate = @openssl_x509_parse($bundle['cert'] ?? '');
        if (!is_array($certificate)
            || ($certificate['validFrom_time_t'] ?? PHP_INT_MAX) > time()
            || ($certificate['validTo_time_t'] ?? 0) <= time()
            || !@openssl_x509_check_private_key($bundle['cert'], $bundle['pkey'] ?? '')
        ) {
            self::clearErrors();
            throw new BankConnectorOperationException('certificate_invalid');
        }
        self::clearErrors();
        return [
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEYTYPE => 'PEM',
            CURLOPT_SSLCERT_BLOB => $bundle['cert'] . implode('', $bundle['extracerts'] ?? []),
            CURLOPT_SSLKEY_BLOB => $bundle['pkey'],
        ];
    }

    private static function clearErrors(): void
    {
        while (openssl_error_string() !== false) {}
    }
}
