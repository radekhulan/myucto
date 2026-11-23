<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai\Assistant;

use MyInvoice\Service\Mcp\McpInternalRequest;
use MyInvoice\Service\Mcp\NodeBridge;
use MyInvoice\Service\Mcp\NodeRelayIo;
use Psr\Log\LoggerInterface;

/**
 * Čtecí nástroje AI asistenta z katalogu MCP (`MCP/src/tools.mjs`), stejné jako pro
 * externí AI. Node běží jako reléový most (`MCP/src/hosted-relay.mjs`): sám nikam
 * nevolá, požadavky na API vypíše a odbaví je tento proces přes
 * `api/bin/mcp-internal-api.php` celým middleware stackem aplikace.
 *
 * Identita a hranice:
 *  - Node nedostane relaci, token ani firmu. Relaci přihlášeného uživatele a hlavičku
 *    aktuální firmy doplní až {@see self::apiRequest()}; API pak rozhoduje o právech
 *    stejně jako v prohlížeči (role v aktuální firmě, MFA, zámek relace, IP allowlist).
 *  - Projde jen GET do `/api/v1/` (bez Authorization). Zápisové nástroje se nenabídnou
 *    (rozsah `read`) a most je odmítne i sám; nástroje, které by potřebovaly jinou
 *    metodu než GET, z katalogu vypadnou podle manifestu `tool-routes.generated.json`.
 */
final class McpAssistantDataSource implements AssistantDataSource
{
    private const DEADLINE_SECONDS = 45;
    private const MAX_FETCHES_PER_CALL = 12;
    private const FORWARDED_HEADERS = ['accept', 'x-myucto-client-version', 'x-myucto-tool'];
    /** Nástroje mimo nabídku: binární soubory, diagnostika připojení a výběr firmy. */
    private const EXCLUDED = ['whoami', 'list_suppliers'];

    /** @var list<array{name:string, title:string, description:string, inputSchema:array<string,mixed>}>|null */
    private ?array $catalog = null;

    /**
     * @param list<string>|null                   $allowedTools nabídka podle role (null = bez omezení)
     * @param array<string,mixed>                 $serverParams REMOTE_ADDR a hlavička proxy pro IP allowlist
     * @param array<string, array{write:bool, api:list<array{string,string}>}> $routeManifest
     */
    public function __construct(
        private readonly string $nodeBinary,
        private readonly string $phpBinary,
        private readonly string $script,
        private readonly string $apiUrl,
        private readonly string $version,
        private readonly int $supplierId,
        private readonly string $sessionToken,
        private readonly array $serverParams,
        private readonly ?array $allowedTools,
        private readonly array $routeManifest,
        private readonly LoggerInterface $logger,
    ) {}

    public function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }
        $this->catalog = [];
        try {
            $result = $this->run(['operation' => 'list']);
        } catch (\Throwable $e) {
            $this->logger->warning('AI assistant: MCP catalog unavailable', ['error' => substr($e->getMessage(), 0, 300)]);
            return [];
        }
        foreach ((array) ($result['tools'] ?? []) as $tool) {
            $tool = json_decode((string) json_encode($tool), true);
            $name = is_array($tool) ? (string) ($tool['name'] ?? '') : '';
            if ($name === '' || !$this->offered($name) || ($tool['annotations']['readOnlyHint'] ?? false) !== true) {
                continue;
            }
            $this->catalog[] = [
                'name' => $name,
                'title' => (string) ($tool['title'] ?? $name),
                'description' => (string) ($tool['description'] ?? ''),
                'inputSchema' => is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : ['type' => 'object', 'properties' => []],
            ];
        }
        return $this->catalog;
    }

    public function call(string $name, array $arguments): array
    {
        if (!in_array($name, array_column($this->catalog(), 'name'), true)) {
            return ['ok' => false, 'text' => 'Nástroj ' . $name . ' není v nabídce. Použij jen nástroje ze seznamu.'];
        }
        unset($arguments['supplier_id']);
        try {
            $result = $this->run(['operation' => 'call', 'name' => $name, 'arguments' => (object) $arguments]);
        } catch (\Throwable $e) {
            $this->logger->warning('AI assistant: MCP tool failed', ['tool' => $name, 'error' => substr($e->getMessage(), 0, 300)]);
            return ['ok' => false, 'text' => 'Nástroj ' . $name . ' se nepodařilo spustit.'];
        }
        $texts = [];
        foreach ((array) ($result['content'] ?? []) as $part) {
            $part = (array) $part;
            if (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }
        return ['ok' => ($result['isError'] ?? false) !== true, 'text' => implode("\n", $texts)];
    }

    private function offered(string $name): bool
    {
        if (in_array($name, self::EXCLUDED, true) || str_starts_with($name, 'download_')) {
            return false;
        }
        if ($this->allowedTools !== null && !in_array($name, $this->allowedTools, true)) {
            return false;
        }
        $contract = $this->routeManifest[$name] ?? null;
        if (!is_array($contract) || ($contract['write'] ?? true) !== false || ($contract['api'] ?? []) === []) {
            return false;
        }
        foreach ($contract['api'] as $operation) {
            if (($operation[0] ?? null) !== 'GET') {
                return false;
            }
        }
        return true;
    }

    /**
     * Jeden běh mostu: zadání na stdin, požadavky na API odbavené tady, výsledek z posledního řádku.
     *
     * @param array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private function run(array $operation): array
    {
        $errors = tmpfile();
        if ($errors === false) {
            throw new \RuntimeException('Nelze založit dočasný soubor pro MCP most.');
        }
        $pipes = [];
        $process = proc_open([$this->nodeBinary, $this->script], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => $errors,
        ], $pipes, dirname($this->script), NodeBridge::nodeEnvironment());
        if (!is_resource($process)) {
            fclose($errors);
            throw new \RuntimeException('Node.js MCP proces se nepodařilo spustit.');
        }
        $result = null;
        try {
            NodeRelayIo::write($pipes[0], $operation + [
                'scope' => 'read',
                'boundSupplierId' => $this->supplierId,
                'apiUrl' => $this->apiUrl,
                'version' => $this->version,
                'allowedTools' => $this->allowedTools,
            ]);
            $deadline = microtime(true) + self::DEADLINE_SECONDS;
            $buffer = '';
            $fetches = 0;
            $exported = false;
            while (($line = NodeRelayIo::readLine($pipes[1], $buffer, $deadline)) !== null) {
                $message = json_decode($line, true, 512);
                if (!is_array($message)) continue;
                if (($message['type'] ?? null) === 'result') {
                    $result = is_array($message['result'] ?? null) ? $message['result'] : null;
                    break;
                }
                if (($message['type'] ?? null) !== 'fetch' || !is_int($message['id'] ?? null)) continue;
                if (!$exported) {
                    NodeRelayIo::exportEnvironment();
                    $exported = true;
                }
                $request = ++$fetches <= self::MAX_FETCHES_PER_CALL
                    ? $this->apiRequest($message, is_string($operation['name'] ?? null) ? $operation['name'] : null) : null;
                try {
                    $reply = $request === null ? self::forbidden() : NodeRelayIo::internalApi($this->phpBinary, $request);
                } catch (\Throwable) {
                    $reply = ['error' => 'Interní PHP API selhalo.'];
                }
                NodeRelayIo::write($pipes[0], ['id' => $message['id']] + $reply);
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) fclose($pipe);
            }
            if ($result === null) proc_terminate($process);
            proc_close($process);
            fclose($errors);
        }
        if ($result === null) {
            throw new \RuntimeException('Node.js MCP proces nevrátil výsledek.');
        }
        return $result;
    }

    /**
     * Požadavek pro interní API, nebo null, když ho asistent nesmí poslat. Při volání
     * nástroje projde jen cesta, kterou nástroj podle manifestu používá.
     *
     * @param array<string,mixed> $message
     * @return array<string,mixed>|null
     */
    public function apiRequest(array $message, ?string $tool = null): ?array
    {
        $url = (string) ($message['url'] ?? '');
        if (strtoupper((string) ($message['method'] ?? '')) !== 'GET' || array_key_exists('bodyBase64', $message)
            || ((string) ($message['body'] ?? '')) !== ''
            || !str_starts_with($url, rtrim($this->apiUrl, '/') . '/')
            || ($tool !== null && !$this->toolUsesPath($tool, $url))) {
            return null;
        }
        $headers = ['X-MyUcto-Client' => 'ai-assistant', 'X-Supplier-Id' => (string) $this->supplierId];
        foreach ((array) ($message['headers'] ?? []) as $name => $value) {
            if (is_string($name) && is_string($value) && in_array(strtolower($name), self::FORWARDED_HEADERS, true)) {
                $headers[$name] = $value;
            }
        }
        return [
            'url' => $url,
            'method' => 'GET',
            'headers' => $headers,
            'body' => '',
            'serverParams' => $this->serverParams,
            'sessionToken' => $this->sessionToken,
        ];
    }

    private function toolUsesPath(string $tool, string $url): bool
    {
        $path = (string) parse_url(substr($url, strlen(rtrim($this->apiUrl, '/'))), PHP_URL_PATH);
        foreach ((array) ($this->routeManifest[$tool]['api'] ?? []) as $operation) {
            if (($operation[0] ?? null) !== 'GET' || !is_string($operation[1] ?? null)) continue;
            $template = substr($operation[1], strlen('/api'));
            $pattern = '#^' . preg_replace('/\\\{[a-z_]+\\\}/', '[^/]+', preg_quote($template, '#')) . '$#D';
            if (preg_match($pattern, rawurldecode($path)) === 1) return true;
        }
        return false;
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    private static function forbidden(): array
    {
        return McpInternalRequest::error(403, 'assistant_read_only', 'AI asistent smí data jen číst.');
    }
}
