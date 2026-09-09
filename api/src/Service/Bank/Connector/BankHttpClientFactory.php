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
                        ] + $this->transportDiagnostic($transport));
                        return $response;
                    }, $failed);
                };
            }, 'bank_diagnostics');
        }
        return new Client(['handler' => $stack]);
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
