<?php

declare(strict_types=1);

namespace MyUctoRegression;

final class Runner
{
    public static function main(array $argv): int
    {
        set_error_handler(static function (): never {
            throw new \RuntimeException('runner_io_failure');
        });
        try {
            $mode = $argv[1] ?? '';
            $options = self::options(array_slice($argv, 2));
            $allowed = $mode === 'capture'
                ? ['code-root', 'database', 'as-of', 'execution-date', 'dataset-id', 'output', 'docker-test-host']
                : ['baseline', 'candidate', 'output'];
            if (array_diff(array_keys($options), $allowed) !== []) {
                throw new \RuntimeException('unknown_option');
            }
            if ($mode === 'capture') {
                return self::capture($options);
            }
            if ($mode === 'compare') {
                return self::compare($options);
            }
            throw new \RuntimeException('expected_capture_or_compare');
        } catch (\Throwable $error) {
            // Exception messages can contain SQL, paths, DSNs or user data.
            echo json_encode(['status' => 'error', 'error' => 'runner_failed', 'exception_class' => get_class($error)], JSON_THROW_ON_ERROR) . PHP_EOL;
            return 2;
        } finally {
            restore_error_handler();
        }
    }

    private static function options(array $args): array
    {
        $options = [];
        while ($args !== []) {
            $name = array_shift($args);
            if (!str_starts_with($name, '--') || $args === [] || isset($options[substr($name, 2)])) {
                throw new \RuntimeException('invalid_options');
            }
            $options[substr($name, 2)] = array_shift($args);
        }
        return $options;
    }

    private static function capture(array $options): int
    {
        $root = realpath($options['code-root'] ?? '');
        if ($root === false || !is_file($root . '/api/vendor/autoload.php')) {
            throw new \RuntimeException('invalid_code_root');
        }
        $roots = [$root, dirname(__DIR__, 2)];
        $output = Safety::outside($options['output'] ?? '', $roots);
        if (file_exists($output)) {
            throw new \RuntimeException('output_exists');
        }
        $database = $options['database'] ?? '';
        Safety::database($database, '');
        $asOf = Safety::date($options['as-of'] ?? '');
        $executionDate = Safety::date($options['execution-date'] ?? '');
        $dataset = $options['dataset-id'] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $dataset)) {
            throw new \RuntimeException('dataset_sha256_required');
        }
        $workspace = dirname($output) . '/regression-' . bin2hex(random_bytes(12));
        if (!mkdir($workspace, 0700)) {
            throw new \RuntimeException('workspace_creation_failed');
        }
        $request = ['code_root' => $root, 'database' => $database, 'as_of' => $asOf, 'execution_date' => $executionDate, 'docker_host' => $options['docker-test-host'] ?? null];
        $preflight = self::child($request + ['action' => 'preflight'], $workspace, 'preflight');
        if ($preflight['status'] !== 'ok') {
            Safety::writeJson($output, ['format' => 1, 'status' => 'error', 'preflight' => $preflight]);
            echo "{\"status\":\"error\",\"error\":\"preflight_failed\"}" . PHP_EOL;
            return 2;
        }
        $manifest = $preflight['data']['cases'];
        $capture = ['format' => 1, 'code_root' => $root, 'dataset_id' => $dataset, 'as_of' => $asOf, 'execution_date' => $executionDate, 'coverage' => $preflight['data']['coverage'], 'manifest' => $manifest, 'cases' => []];
        $failed = 0;
        $numeric = 0;
        foreach ($manifest as $id => $case) {
            $result = self::child($request + ['action' => 'case', 'case' => $case], $workspace, $id);
            $capture['cases'][$id] = $result;
            if ($result['status'] === 'ok') {
                $numeric += Comparator::values($result['data'], $result['data'])['numeric_count'];
            } else {
                ++$failed;
                echo json_encode(['case_id' => $id, 'status' => 'error', 'error' => $result['error'] ?? 'case_failed'], JSON_THROW_ON_ERROR) . PHP_EOL;
            }
        }
        $capture['status'] = $failed === 0 && $numeric > 0 ? 'ok' : 'error';
        $capture['coverage']['complete'] = $capture['coverage']['manifest_complete'] === true && $capture['status'] === 'ok' && $manifest !== [];
        $capture['numeric_count'] = $numeric;
        Safety::writeJson($output, $capture);
        echo json_encode(['status' => $capture['status'], 'cases' => count($manifest), 'errors' => $failed, 'numeric_count' => $numeric], JSON_THROW_ON_ERROR) . PHP_EOL;
        return $capture['status'] === 'ok' ? 0 : 2;
    }

    public static function child(array $request, string $workspace, string $id): array
    {
        $directory = $workspace . '/' . $id;
        mkdir($directory, 0700);
        mkdir($directory . '/tmp', 0700);
        $request['runtime'] = $directory;
        $request['response'] = $directory . '/response.json';
        $requestFile = $directory . '/request.json';
        Safety::writeJson($requestFile, $request);
        $command = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'allow_url_fopen=1', '-d', 'allow_url_include=0', '-d', 'disable_functions=' . implode(',', Network::FUNCTIONS), '-d', 'disable_classes=Redis,RedisCluster,SoapClient', '-d', 'sys_temp_dir=' . $directory . '/tmp', __DIR__ . '/worker.php', $requestFile];
        $environment = getenv();
        foreach (['TEMP', 'TMP', 'TMPDIR'] as $key) {
            $environment[$key] = $directory . '/tmp';
        }
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $directory, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['status' => 'error', 'error' => 'worker_start_failed'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $start = microtime(true);
        $bytes = 0;
        do {
            $bytes += strlen((string) stream_get_contents($pipes[1])) + strlen((string) stream_get_contents($pipes[2]));
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $start > 120 || $bytes > 1048576) {
                proc_terminate($process);
                break;
            }
            usleep(20000);
        } while (true);
        $bytes += strlen((string) stream_get_contents($pipes[1])) + strlen((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = (int) $status['exitcode'];
        $closedCode = proc_close($process);
        if ($exitCode < 0) {
            $exitCode = $closedCode;
        }
        if ($status['running'] || $bytes > 0 || !is_file($request['response'])) {
            return ['status' => 'error', 'error' => $status['running'] ? 'worker_timeout' : 'worker_output_or_fatal'];
        }
        $response = json_decode((string) file_get_contents($request['response']), true, 512, JSON_THROW_ON_ERROR);
        if ($exitCode !== 0 && ($response['status'] ?? null) === 'ok') {
            return ['status' => 'error', 'error' => 'worker_exit_failed'];
        }
        return $response;
    }

    private static function compare(array $options): int
    {
        $left = json_decode((string) file_get_contents($options['baseline'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        $right = json_decode((string) file_get_contents($options['candidate'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        $roots = [dirname(__DIR__, 2), $left['code_root'] ?? '', $right['code_root'] ?? ''];
        Safety::outside($options['baseline'], $roots);
        Safety::outside($options['candidate'], $roots);
        $output = Safety::outside($options['output'] ?? '', $roots);
        $result = Comparator::captures($left, $right);
        Safety::writeJson($output, $result);
        echo json_encode(['status' => $result['status'], 'numeric_count' => $result['numeric_count'], 'errors' => count($result['errors']), 'different_cases' => count($result['differences'])], JSON_THROW_ON_ERROR) . PHP_EOL;
        return $result['status'] === 'ok' ? 0 : 2;
    }
}
