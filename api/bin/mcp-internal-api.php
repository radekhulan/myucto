<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Mcp\McpFileLimits;
use MyInvoice\Service\Mcp\McpInternalRequest;

$internal = null;
try {
    $raw = stream_get_contents(STDIN, McpFileLimits::MAX_ENVELOPE_BYTES + 1);
    if ($raw === false || strlen($raw) > McpFileLimits::MAX_ENVELOPE_BYTES) {
        throw new RuntimeException('Neplatná velikost interního požadavku.');
    }
    $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new RuntimeException('Neplatný interní požadavek.');

    $config = Config::load(Bootstrap::rootDir());
    $internal = new McpInternalRequest(
        (string) $config->get('app.url', ''),
        RuntimePaths::storage('tmp/mcp-uploads'),
        (string) $config->get('session.cookie_name', '__Host-myinvoice_session'),
    );
    try {
        $request = $internal->build($input);
    } catch (InvalidArgumentException $e) {
        $request = null;
        $reply = McpInternalRequest::uploadError($e);
    }
    if ($request !== null) {
        $reply = $internal->encodeResponse(Bootstrap::buildApp()->handle($request));
    }
    $internal->cleanup();
    echo json_encode($reply, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable) {
    $internal?->cleanup();
    fwrite(STDERR, 'Interní MCP API selhalo.');
    exit(1);
}
