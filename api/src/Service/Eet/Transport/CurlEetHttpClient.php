<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * HTTP/1.1 přes cURL podle kap. 2.3 a 5.1 popisu rozhraní: jen HTTPS, TLS 1.2 a
 * vyšší, ověření serverového certifikátu a jména (systémové úložiště nebo
 * `curl.cainfo` z php.ini), BEZ klientského TLS certifikátu — zprávu autentizuje
 * podpis WS-Security, ne TLS.
 */
final class CurlEetHttpClient implements EetHttpClientInterface
{
    private const MAX_RESPONSE_BYTES = EetXml::MAX_RESPONSE_BYTES;

    public function __construct(
        /** Volitelný soubor s CA pro ověření TLS; jinak výchozí nastavení cURL/php.ini. */
        private readonly ?string $caInfo = null,
    ) {}

    public function post(string $url, string $body, array $headers, EetTimeouts $timeouts): EetHttpResponse
    {
        if (!str_starts_with($url, 'https://')) {
            throw new EetHttpException('Spojení se správcem daně musí jít přes HTTPS.');
        }
        if (!function_exists('curl_init')) {
            throw new EetHttpException('Server nemá rozšíření PHP cURL.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new EetHttpException('Spojení se nepodařilo připravit.');
        }
        $headerLines = ['Expect:'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $responseHeaders = [];
        $responseBody = '';
        $tooLarge = false;
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, $timeouts->connectMs),
            CURLOPT_TIMEOUT_MS => max(1, $timeouts->totalMs),
            CURLOPT_COOKIEFILE => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, &$tooLarge): int {
                if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;

                    return 0;
                }
                $responseBody .= $chunk;

                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($this->caInfo !== null && $this->caInfo !== '') {
            $options[CURLOPT_CAINFO] = $this->caInfo;
        }
        curl_setopt_array($handle, $options);

        $started = hrtime(true);
        $result = curl_exec($handle);
        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($tooLarge) {
            throw new EetHttpException('Odpověď správce daně je nepřípustně velká.', false, $durationMs);
        }
        if ($result === false || $errno !== 0) {
            $timeout = $errno === CURLE_OPERATION_TIMEDOUT;
            throw new EetHttpException(
                ($timeout ? 'Vypršel časový limit spojení' : 'Spojení se správcem daně selhalo') . ' (cURL ' . $errno . ': ' . $error . ').',
                $timeout,
                $durationMs,
            );
        }

        return new EetHttpResponse($status, $responseBody, $responseHeaders, $durationMs);
    }
}
