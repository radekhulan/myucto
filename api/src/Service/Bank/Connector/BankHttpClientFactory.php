<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\TransferStats;
use MyInvoice\Infrastructure\Config\Config;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class BankHttpClientFactory
{
    public function __construct(private readonly Config $config, private readonly LoggerInterface $logger) {}

    public function diagnosticLogger(): LoggerInterface
    {
        return $this->config->get('app.env') === 'development' ? $this->logger : new NullLogger();
    }

    public function create(string $provider, ?callable $handler = null): Client
    {
        $stack = HandlerStack::create($handler);
        if ($this->config->get('app.env') === 'development') {
            $stack->push(function (callable $next) use ($provider): callable {
                return function (RequestInterface $request, array $options) use ($next, $provider) {
                    $startedAt = microtime(true);
                    $transport = [];
                    $context = [
                        'provider' => $provider,
                        'request_id' => 'bank-' . bin2hex(random_bytes(16)),
                        'method' => $request->getMethod(),
                        'host' => $request->getUri()->getHost(),
                    ];
                    if ($provider === 'raiffeisenbank' && preg_match('/^myucto-[a-f0-9]{32}$/D', $request->getHeaderLine('X-Request-Id')) === 1) {
                        $context['bank_request_id'] = $request->getHeaderLine('X-Request-Id');
                    }
                    $onStats = $options['on_stats'] ?? null;
                    $options['on_stats'] = static function (TransferStats $stats) use (&$transport, $onStats): void {
                        $transport = $stats->getHandlerStats();
                        if (is_int($stats->getHandlerErrorData())) {
                            $transport['errno'] = $stats->getHandlerErrorData();
                        }
                        if ($onStats !== null) {
                            $onStats($stats);
                        }
                    };
                    $failed = function ($error) use ($context, &$transport, $startedAt) {
                        $this->logger->warning('bank_http_transport_failed', $context + [
                            'exception_class' => is_object($error) ? $error::class : get_debug_type($error),
                            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                        ] + $this->transportDiagnostic(['error' => $error instanceof \Throwable ? $error->getMessage() : ''] + $transport));
                        return Create::rejectionFor($error);
                    };
                    try {
                        $promise = $next($request, $options);
                    } catch (\Throwable $e) {
                        return $failed($e);
                    }
                    return $promise->then(function (ResponseInterface $response) use ($context, &$transport, $startedAt): ResponseInterface {
                        $status = $response->getStatusCode();
                        $this->logger->log($status >= 200 && $status < 300 ? 'info' : 'warning', 'bank_http_completed', $context + [
                            'http_status' => $status,
                            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                        ] + $this->transportDiagnostic($transport)
                            + ($context['provider'] === 'raiffeisenbank' && $status >= 400 ? $this->rbErrorDiagnostic($response) : [])
                            + (str_starts_with($context['provider'], 'kb_plus') && $status >= 400 ? $this->kbPlusErrorDiagnostic($response) : []));
                        return $response;
                    }, $failed);
                };
            }, 'bank_diagnostics');
        }
        return new Client(['handler' => $stack]);
    }

    private function rbErrorDiagnostic(ResponseInterface $response): array
    {
        $result = [];
        foreach (['X-Request-Id', 'X-Correlation-Id', 'X-Global-Transaction-ID'] as $header) {
            $value = $response->getHeaderLine($header);
            if (preg_match('/^(?:myucto-[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/Di', $value) === 1) {
                $result['response_' . strtolower($header)] = $value;
            }
        }
        $body = $response->getBody();
        if (!$body->isSeekable()) return $result;
        $position = $body->tell();
        try {
            $body->rewind();
            $content = $body->read(65537);
        } finally {
            $body->seek($position);
        }
        $result['error_body_truncated'] = strlen($content) > 65536;
        $result['error_body_sha256'] = hash('sha256', $content);
        $data = json_decode($content, true);
        $result['error_body_format'] = is_array($data) ? 'json' : 'other';
        if (!is_array($data)) return $result;
        foreach (['error', 'errorCode', 'code'] as $key) {
            $code = $data[$key] ?? null;
            if (is_string($code) && (in_array($code, ['DT01', 'UNAUTHORISED', 'INVALID_REQUEST', 'INSUFFICIENT_RIGHTS', 'ID_NOT_FOUND', 'TOO_MANY_REQUESTS', 'INTERNAL_SERVER_ERROR'], true) || preg_match('/^ERR_PAY_[0-9]{1,5}$/D', $code) === 1)) {
                $result['bank_error_code'] = $code;
                break;
            }
        }
        return $result;
    }

    /** Chybové odpovědi KB+ nenesou tajemství, tokeny a klíče se přesto před zápisem maskují. */
    private function kbPlusErrorDiagnostic(ResponseInterface $response): array
    {
        $result = ['response_content_type' => substr($response->getHeaderLine('Content-Type'), 0, 100)];
        foreach (['x-correlation-id', 'x-request-id'] as $header) {
            $value = $response->getHeaderLine($header);
            if (preg_match('/^[A-Za-z0-9-]{8,64}$/D', $value) === 1) {
                $result['response_' . $header] = $value;
            }
        }
        $body = $response->getBody();
        if (!$body->isSeekable()) return $result;
        $position = $body->tell();
        try {
            $body->rewind();
            $content = $body->read(2049);
        } finally {
            $body->seek($position);
        }
        $result['error_body_truncated'] = strlen($content) > 2048;
        $result['error_body'] = (string) preg_replace(
            ['/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/', '/[A-Za-z0-9+\/_=]{32,}/', '/[\x00-\x1F\x7F]+/'],
            ['[jwt]', '[redacted]', ' '],
            mb_scrub(substr($content, 0, 2048), 'UTF-8'),
        );
        return $result;
    }

    private function transportDiagnostic(#[\SensitiveParameter] array $context): array
    {
        $result = [];
        foreach (['errno', 'http_code', 'ssl_verify_result', 'namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'total_time'] as $key) {
            if (isset($context[$key]) && (is_int($context[$key]) || is_float($context[$key]))) {
                $result[$key] = $context[$key];
            }
        }
        if (isset($result['errno']) && is_int($result['errno']) && function_exists('curl_strerror')) {
            $result['curl_error'] = curl_strerror($result['errno']);
        }
        $error = strtolower(is_string($context['error'] ?? null) ? $context['error'] : '');
        foreach (['certificate verify failed', 'unable to get local issuer certificate', 'certificate has expired', 'tlsv1 alert unknown ca', 'sslv3 alert handshake failure', 'ssl/tls alert handshake failure', 'tlsv13 alert certificate required', 'no suitable signature algorithm', 'could not load pem client certificate', 'key values mismatch', 'connection reset by peer'] as $reason) {
            if (str_contains($error, $reason)) {
                $result['tls_reason'] = $reason;
                break;
            }
        }
        if (function_exists('curl_version')) {
            $version = curl_version();
            $result['curl_version'] = $version['version'];
            $result['ssl_backend'] = $version['ssl_version'];
        }
        return $result;
    }
}
