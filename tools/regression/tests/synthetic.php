<?php

declare(strict_types=1);

// Private synthetic scheduler fixture. Never loads the application or database.
$request = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$directory = dirname($argv[1]);
if ($request['action'] === 'preflight') {
    $cases = [];
    for ($i = 0; $i < $request['count']; ++$i) {
        $cases['synthetic_' . $i] = ['ordinal' => $i];
    }
    $result = ['status' => 'ok', 'data' => ['cases' => $cases, 'coverage' => ['manifest_complete' => true, 'complete' => false]]];
    $numeric = 0;
} else {
    usleep(15000);
    $i = $request['case']['ordinal'];
    $result = $i === $request['failure'] ? ['status' => 'error', 'error' => 'synthetic_failure']
        : ['status' => 'ok', 'data' => ['amount' => (string) ($i + 1)]];
    $numeric = $result['status'] === 'ok' ? 1 : 0;
}
foreach (['result' => $result, 'metrics' => ['numeric_count' => $numeric]] as $name => $value) {
    $handle = fopen($directory . '/' . $name . '.json', 'x');
    fwrite($handle, json_encode($value, JSON_THROW_ON_ERROR));
    fclose($handle);
}
