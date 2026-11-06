<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\IpMatcher;

/**
 * Serverový MCP ve spravované instalaci: Node běží odděleně od aplikace
 * (kontejner provozovatele) a nemá PHP, kód instance ani databázi.
 *
 * Na rozdíl od {@see NodeBridge} proto Node nevolá interní API sám. Požadavek
 * jen vypíše na standardní výstup a odbaví ho tento proces, který výsledek vrátí
 * na jeho vstup (protokol v `MCP/src/hosted-relay.mjs`).
 *
 * Node je tu nedůvěryhodná strana. Token ani hlavičku firmy nedostane: obojí
 * doplňuje až {@see self::apiRequest()} podle schváleného připojení, takže
 * odpověď z kontejneru nemůže sáhnout na jinou firmu ani token vynést.
 */
final class ManagedNodeRelay
{
    private const DEADLINE_SECONDS = 100;
    private const MAX_LINE_BYTES = McpFileLimits::MAX_LINE_BYTES;
    private const MAX_API_BYTES = McpFileLimits::MAX_ENVELOPE_BYTES;
    private const FORWARDED_HEADERS = [
        'accept', 'content-type', 'x-myucto-client', 'x-myucto-client-version', 'x-myucto-tool',
    ];
    private const WITHOUT_COMPANY = ['whoami', 'list_suppliers'];

    public function __construct(
        private readonly HostedMcp $hosted,
        private readonly Config $config,
    ) {}

    public function execute(array $input): array
    {
        $script = dirname(__DIR__, 4) . '/MCP/src/hosted-relay.mjs';
        if (!is_file($script)) {
            throw new \RuntimeException('Chybí soubor MCP/src/hosted-relay.mjs.');
        }
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('PHP má vypnutou funkci proc_open.');
        }
        $php = $this->hosted->relayPhpBinary();
        if ($php === null) {
            throw new \RuntimeException('PHP CLI není dostupné; nastavte MYINVOICE_MCP_PHP_BINARY.');
        }
        $errors = tmpfile();
        if ($errors === false) {
            throw new \RuntimeException('Nelze založit dočasný soubor pro MCP most.');
        }

        $serverParams = (array) ($input['serverParams'] ?? []);
        $forwardedHeader = 'HTTP_' . str_replace('-', '_', strtoupper(
            (string) $this->config->get('ip_allowlist.header', 'X-Forwarded-For')
        ));
        $input['serverParams'] = array_intersect_key($serverParams, array_flip([
            'REMOTE_ADDR', IpMatcher::TRUSTED_CLIENT_IP_PARAM, $forwardedHeader,
        ]));
        $input['apiUrl'] = rtrim((string) $this->config->get('app.url', ''), '/') . '/api/v1';
        $input['version'] = trim((string) @file_get_contents(dirname(__DIR__, 4) . '/VERSION'));

        $pipes = [];
        $process = proc_open([$this->hosted->nodeBinary(), $script], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => $errors,
        ], $pipes, dirname($script));
        if (!is_resource($process)) {
            fclose($errors);
            throw new \RuntimeException('Node.js MCP proces se nepodařilo spustit.');
        }

        $result = null;
        try {
            $this->write($pipes[0], array_intersect_key($input, array_flip([
                'operation', 'scope', 'cursor', 'name', 'arguments',
                'boundSupplierId', 'lockedSupplierId', 'apiUrl', 'version', 'allowedTools',
            ])));
            $deadline = microtime(true) + self::DEADLINE_SECONDS;
            $buffer = '';
            $exported = false;
            while (($line = $this->readLine($pipes[1], $buffer, $deadline)) !== null) {
                $message = json_decode($line, false, 512);
                if (!$message instanceof \stdClass) continue;
                $type = $message->type ?? null;
                if ($type === 'result') {
                    $result = $message->result ?? null;
                    break;
                }
                if ($type !== 'fetch' || !is_int($message->id ?? null)) continue;
                if (!$exported) {
                    $this->exportEnvironment();
                    $exported = true;
                }
                try {
                    $request = $this->apiRequest((array) json_decode($line, true, 512), $input);
                    $reply = $request === null ? $this->forbidden() : $this->internalApi($php, $request);
                } catch (\InvalidArgumentException $e) {
                    $reply = McpInternalRequest::uploadError($e);
                } catch (\Throwable) {
                    $reply = ['error' => 'Interní PHP API selhalo.'];
                }
                $this->write($pipes[0], ['id' => $message->id] + $reply);
            }
        } catch (\Throwable $e) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
            fclose($errors);
            throw $e;
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        proc_close($process);
        rewind($errors);
        $error = (string) stream_get_contents($errors, 8192);
        fclose($errors);

        if (!$result instanceof \stdClass) {
            throw new \RuntimeException('Node.js MCP proces selhal: ' . substr($error, 0, 500));
        }
        return (array) $result;
    }

    /**
     * Požadavek na interní API sestavený z toho, co si Node vyžádal. Vrací null,
     * když schválené připojení volání API nedovoluje.
     *
     * Nahrávaný soubor posílá Node v `bodyBase64`. Tělo se pustí dál jen jako
     * multipart u zápisové metody a jen v platném base64 do stropu
     * {@see McpFileLimits::MAX_BODY_BYTES}; jinak výjimka s HTTP statusem v kódu.
     *
     * @return array{url:string,method:string,headers:array<string,string>,body:string,bodyBase64?:string,serverParams:array<string,mixed>}|null
     * @throws \InvalidArgumentException
     */
    public function apiRequest(array $message, array $input): ?array
    {
        $supplier = $this->supplierFor($input);
        if ($supplier === false) return null;

        $headers = [];
        foreach ((array) ($message['headers'] ?? []) as $name => $value) {
            if (is_string($name) && is_string($value)
                && in_array(strtolower($name), self::FORWARDED_HEADERS, true)) {
                $headers[$name] = $value;
            }
        }
        $headers['Authorization'] = 'Bearer ' . (string) ($input['token'] ?? '');
        if ($supplier !== null) $headers['X-Supplier-Id'] = (string) $supplier;

        $request = [
            'url' => (string) ($message['url'] ?? ''),
            'method' => (string) ($message['method'] ?? ''),
            'headers' => $headers,
            'body' => (string) ($message['body'] ?? ''),
            'serverParams' => (array) ($input['serverParams'] ?? []),
        ];

        $contentType = '';
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') $contentType = $value;
        }
        $multipart = preg_match('#^multipart/form-data\b#i', $contentType) === 1;
        if (array_key_exists('bodyBase64', $message)) {
            if (!$multipart || !in_array(strtoupper($request['method']), ['POST', 'PUT', 'PATCH'], true)) {
                throw new \InvalidArgumentException('Binární tělo smí nést jen nahrání souboru.', 400);
            }
            McpFileLimits::assertBase64($message['bodyBase64']);
            $request['body'] = '';
            $request['bodyBase64'] = (string) $message['bodyBase64'];
        } elseif ($multipart) {
            throw new \InvalidArgumentException('Nahrávaný soubor musí přijít v base64.', 400);
        }

        $request['mcpResource'] = $input['mcpResource'] ?? null;
        return $request;
    }

    /**
     * Firma, pod kterou smí volání běžet: číslo, null (nástroj bez firmy a
     * připojení bez vazby), nebo false (žádné volání API není povolené).
     * Zrcadlí pravidla v `MCP/src/hosted-core.mjs`, ale rozhoduje tady.
     */
    private function supplierFor(array $input): int|false|null
    {
        if (($input['operation'] ?? null) !== 'call') return false;
        $bound = $this->positiveInt($input['boundSupplierId'] ?? null);
        $locked = $this->positiveInt($input['lockedSupplierId'] ?? null);
        if (in_array($input['name'] ?? null, self::WITHOUT_COMPANY, true)) {
            return $locked ?? $bound;
        }
        $arguments = $input['arguments'] ?? [];
        $supplier = $bound ?? $this->positiveInt(is_array($arguments) ? ($arguments['supplier_id'] ?? null) : null);
        if ($supplier === null) return false;
        if ($locked !== null && $supplier !== $locked) return false;
        return $supplier;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private function forbidden(): array
    {
        return [
            'status' => 403,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['error' => [
                'code' => 'forbidden_supplier',
                'message' => 'Toto připojení nemá k požadované firmě přístup.',
            ]], JSON_THROW_ON_ERROR),
        ];
    }

    private function internalApi(string $php, array $request): array
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
        $output = stream_get_contents($pipes[1], self::MAX_API_BYTES + 1);
        fclose($pipes[1]);
        stream_get_contents($pipes[2], 8192);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || $output === false || strlen($output) > self::MAX_API_BYTES) {
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
    private function exportEnvironment(): void
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
    private function write($pipe, array $message): void
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
    private function readLine($stdout, string &$buffer, float $deadline): ?string
    {
        $searched = 0;
        while (($position = strpos($buffer, "\n", $searched)) === false) {
            $searched = strlen($buffer);
            if ($searched > self::MAX_LINE_BYTES) {
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
