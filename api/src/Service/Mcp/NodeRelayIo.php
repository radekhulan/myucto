<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

/**
 * Společné vstupy a výstupy reléového mostu s Node (`MCP/src/hosted-relay.mjs`):
 * zprávy po řádcích JSON a odbavení požadavku na API v samostatném PHP procesu
 * (`api/bin/mcp-internal-api.php`). Používá je {@see ManagedNodeRelay} i AI asistent
 * ({@see \MyInvoice\Service\Ai\Assistant\AssistantDataTools}).
 */
final class NodeRelayIo
{
    public static function internalApi(string $php, array $request): array
    {
        $pipes = [];
        $process = proc_open([$php, '-d', 'opcache.file_cache=', dirname(__DIR__, 3) . '/bin/mcp-internal-api.php'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Interní PHP API se nepodařilo spustit.');
        }
        fwrite($pipes[0], json_encode($request, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1], McpFileLimits::MAX_ENVELOPE_BYTES + 1);
        fclose($pipes[1]);
        stream_get_contents($pipes[2], 8192);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || $output === false || strlen($output) > McpFileLimits::MAX_ENVELOPE_BYTES) {
            throw new \RuntimeException('Interní PHP API selhalo.');
        }
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_int($decoded['status'] ?? null)) {
            throw new \RuntimeException('Interní PHP API vrátilo neplatnou odpověď.');
        }
        $reply = [
            'status' => $decoded['status'],
            'headers' => (object) array_filter((array) ($decoded['headers'] ?? []), 'is_string'),
            'body' => (string) ($decoded['body'] ?? ''),
        ];
        if (is_string($decoded['bodyBase64'] ?? null)) {
            $reply['bodyBase64'] = $decoded['bodyBase64'];
        }
        return $reply;
    }

    /**
     * Pod FastCGI přicházejí proměnné z konfigurace webu (`SetEnv`) jen jako
     * parametry požadavku a podproces by je nezdědil. Bez `MYINVOICE_DATA_DIR`
     * by interní API sáhlo do jiného datového adresáře než web.
     */
    public static function exportEnvironment(): void
    {
        $environment = getenv();
        if (!is_array($environment) || !function_exists('putenv')) return;
        foreach ($environment as $name => $value) {
            if (is_string($name) && is_string($value) && str_starts_with($name, 'MYINVOICE_')) {
                putenv($name . '=' . $value);
            }
        }
    }

    /** @param resource $pipe */
    public static function write($pipe, array $message): void
    {
        $line = json_encode($message, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        $written = @fwrite($pipe, $line);
        if ($written !== strlen($line)) {
            throw new \RuntimeException('Node.js MCP proces nepřijímá data.');
        }
        fflush($pipe);
    }

    /**
     * Další řádek ze standardního výstupu mostu, nebo null po jeho uzavření.
     * Časový limit hlídá `stream_select`; na Windows nad rourami procesu
     * nefunguje, tam čtení čeká bez limitu.
     *
     * @param resource $stdout
     */
    public static function readLine($stdout, string &$buffer, float $deadline): ?string
    {
        $searched = 0;
        while (($position = strpos($buffer, "\n", $searched)) === false) {
            $searched = strlen($buffer);
            if ($searched > McpFileLimits::MAX_LINE_BYTES) {
                throw new \RuntimeException('Node.js MCP proces vrátil příliš dlouhou odpověď.');
            }
            if (DIRECTORY_SEPARATOR !== '\\') {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new \RuntimeException('Node.js MCP proces neodpověděl včas.');
                }
                $read = [$stdout];
                $write = $except = null;
                $seconds = (int) $remaining;
                $ready = stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000));
                if ($ready === false) {
                    throw new \RuntimeException('Čtení z Node.js MCP procesu selhalo.');
                }
                if ($ready === 0) continue;
            }
            $chunk = fread($stdout, 65536);
            if ($chunk === false || ($chunk === '' && feof($stdout))) return null;
            $buffer .= $chunk;
        }
        $line = substr($buffer, 0, $position);
        $buffer = substr($buffer, $position + 1);
        return $line;
    }
}
