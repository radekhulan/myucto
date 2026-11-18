<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\IpMatcher;

final class NodeBridge
{
    public function __construct(
        private readonly HostedMcp $hosted,
        private readonly Config $config,
    ) {}

    public function execute(array $input): array
    {
        if ($this->hosted->managedRelay()) {
            return (new ManagedNodeRelay($this->hosted, $this->config))->execute($input);
        }
        $script = dirname(__DIR__, 4) . '/MCP/src/hosted-bridge.mjs';
        if (!is_file($script)) {
            throw new \RuntimeException('Chybí soubor MCP/src/hosted-bridge.mjs.');
        }
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('PHP má vypnutou funkci proc_open.');
        }
        if (!\MyInvoice\Support\OpenBasedir::isExecutableFile($this->hosted->phpBinary())) {
            throw new \RuntimeException('PHP CLI není dostupné; nastavte MYINVOICE_MCP_PHP_BINARY.');
        }
        if (!$this->hosted->nodeAvailable()) {
            throw new \RuntimeException('Node.js není dostupný pro webový proces; nastavte MYINVOICE_MCP_NODE_BINARY na úplnou cestu k node.exe.');
        }

        $input['apiUrl'] = rtrim((string) $this->config->get('app.url', ''), '/') . '/api/v1';
        $input['phpBinary'] = $this->hosted->phpBinary();
        $input['apiScript'] = dirname(__DIR__, 3) . '/bin/mcp-internal-api.php';
        $serverParams = (array) ($input['serverParams'] ?? []);
        $forwardedHeader = 'HTTP_' . str_replace('-', '_', strtoupper(
            (string) $this->config->get('ip_allowlist.header', 'X-Forwarded-For')
        ));
        $input['serverParams'] = array_intersect_key($serverParams, array_flip([
            'REMOTE_ADDR', IpMatcher::TRUSTED_CLIENT_IP_PARAM, $forwardedHeader,
        ]));
        $command = [$this->hosted->nodeBinary(), $script];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname($script), self::nodeEnvironment());
        if (!is_resource($process)) {
            throw new \RuntimeException('Node.js MCP proces se nepodařilo spustit.');
        }

        try {
            fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            fclose($pipes[0]);
            // Výsledek může nést stažený soubor v base64, proto strop obálky.
            $output = stream_get_contents($pipes[1], McpFileLimits::MAX_ENVELOPE_BYTES + 1);
            fclose($pipes[1]);
            $error = stream_get_contents($pipes[2], 8192);
            fclose($pipes[2]);
            $exit = proc_close($process);
        } catch (\Throwable $e) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
            throw $e;
        }

        if ($exit !== 0 || $output === false || strlen($output) > McpFileLimits::MAX_ENVELOPE_BYTES) {
            throw new \RuntimeException('Node.js MCP proces selhal: ' . substr((string) $error, 0, 500));
        }
        $decoded = json_decode($output, false, 512, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) {
            throw new \RuntimeException('Node.js MCP proces vrátil neplatnou odpověď.');
        }
        return (array) $decoded;
    }

    /** Prostředí pro Node: pod IIS FastCGI musí dostat platnou proměnnou SystemRoot. */
    public static function nodeEnvironment(): ?array
    {
        if (PHP_OS_FAMILY !== 'Windows') return null;
        $systemRoot = trim((string) (getenv('SystemRoot') ?: getenv('windir') ?: ''));
        if ($systemRoot === '') {
            $comspec = (string) (getenv('ComSpec') ?: '');
            if ($comspec !== '' && is_file($comspec)) $systemRoot = dirname(dirname($comspec));
        }
        if ($systemRoot === '') {
            $drive = rtrim((string) (getenv('SystemDrive') ?: 'C:'), '/\\');
            $systemRoot = $drive . '\\Windows';
        }
        if (!is_dir($systemRoot)) {
            throw new \RuntimeException('Node.js vyžaduje platnou proměnnou SystemRoot v prostředí IIS FastCGI.');
        }
        $environment = getenv();
        if (!is_array($environment)) return ['SystemRoot' => $systemRoot];
        foreach (array_keys($environment) as $name) {
            if (strcasecmp($name, 'SystemRoot') === 0) unset($environment[$name]);
        }
        $environment['SystemRoot'] = $systemRoot;
        return $environment;
    }
}
