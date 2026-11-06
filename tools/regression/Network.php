<?php

declare(strict_types=1);

namespace MyUctoRegression;

final class Network
{
    public const FUNCTIONS = [
        'curl_init', 'curl_exec', 'curl_multi_init', 'curl_multi_exec',
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server',
        'socket_create', 'socket_connect', 'socket_sendto',
        'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec', 'mail',
        'ftp_connect', 'ftp_ssl_connect', 'dns_get_record', 'gethostbyname', 'gethostbynamel',
    ];

    public static function install(): void
    {
        foreach (self::FUNCTIONS as $name) {
            if (function_exists($name)) {
                throw new \RuntimeException('child_network_functions_not_disabled');
            }
            eval('function ' . $name . '(...$args) { \\MyUctoRegression\\Tripwire::fail("network_or_process_attempt"); }');
        }
        foreach (['http', 'https', 'ftp', 'ftps'] as $scheme) {
            if (in_array($scheme, stream_get_wrappers(), true)) {
                stream_wrapper_unregister($scheme);
            }
            stream_wrapper_register($scheme, BlockedStream::class);
        }
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return true;
            }
            if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                return true;
            }
            Tripwire::warning($severity, $file, $line);
        });
    }
}

final class BlockedStream
{
    public mixed $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        Tripwire::fail('network_stream_attempt');
    }

    public function url_stat(string $path, int $flags): array|false
    {
        Tripwire::fail('network_stream_attempt');
    }
}
