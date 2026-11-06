<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MyUctoRegression\Comparator;
use MyUctoRegression\Runner;
use MyUctoRegression\Safety;

try {
    $input = $argv[1] ?? '';
    $directory = realpath(dirname($input));
    if ($directory === false || !is_file($input)) {
        throw new RuntimeException('invalid_bridge_input');
    }
    $request = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($request)) {
        throw new RuntimeException('invalid_bridge_request');
    }
    $root = dirname(__DIR__, 2);
    Safety::outside($input, [$root]);
    $action = $request['action'] ?? null;
    if ($action === 'compare') {
        if (!isset($request['pair']) || !is_array($request['pair']) || count($request['pair']) !== 2) {
            throw new RuntimeException('invalid_compare_pair');
        }
        $left = json_decode((string) file_get_contents(Safety::outside((string) $request['pair'][0], [$root])), true, 512, JSON_THROW_ON_ERROR);
        $right = json_decode((string) file_get_contents(Safety::outside((string) $request['pair'][1], [$root])), true, 512, JSON_THROW_ON_ERROR);
        $result = ($left['status'] ?? null) === 'ok' && ($right['status'] ?? null) === 'ok'
            ? Comparator::values($left['data'], $right['data'])
            : ['equal' => false, 'numeric_count' => 0, 'differences' => [], 'errors' => ['case_failed']];
    } elseif (in_array($action, ['case', 'preflight'], true)) {
        $codeRoot = realpath((string) ($request['code_root'] ?? ''));
        if ($codeRoot === false || !is_file($codeRoot . '/api/vendor/autoload.php')) {
            throw new RuntimeException('complete_code_root_required');
        }
        Safety::outside($input, [$root, $codeRoot]);
        $result = Runner::child($request, $directory, 'native');
        $numeric = $action === 'case' && ($result['status'] ?? null) === 'ok'
            ? Comparator::values($result['data'], $result['data'])['numeric_count']
            : 0;
        Safety::writeJson($directory . '/metrics.json', ['numeric_count' => $numeric]);
    } else {
        throw new RuntimeException('invalid_action');
    }
    Safety::writeJson($directory . '/result.json', $result);
    exit(0);
} catch (Throwable) {
    exit(2);
}
