<?php

declare(strict_types=1);

use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonProfile;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Anonymization\Streaming\ChunkedJsonColumnReader;
use MyInvoice\Service\Anonymization\Streaming\FinancialArchiveJsonColumn;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Infrastructure\Config\Config;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$script, $mode, $input, $output] = $argv + [null, null, null, null];
if (!is_string($mode) || !is_string($input) || !is_string($output)) throw new InvalidArgumentException('Missing stream worker arguments.');

$baseline = memory_get_usage();
memory_reset_peak_usage();
$started = hrtime(true);

if ($mode === 'legacy') {
    $decoded = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException('Synthetic archive is not an array.');
    $canonical = NonprofitTaxRules::canonicalJson($decoded);
    file_put_contents($output, $canonical);
    $sha256 = NonprofitTaxRules::hash($decoded);
    $bytes = strlen($canonical);
} elseif ($mode === 'stream') {
    $handle = fopen($output, 'w+b');
    if (!is_resource($handle)) throw new RuntimeException('Cannot open stream output.');
    try {
        $result = (new CanonicalJsonSpool())->canonicalize(fileChunks($input), CanonicalJsonProfile::NONPROFIT, $handle);
    } finally {
        fclose($handle);
    }
    $sha256 = $result->sha256;
    $bytes = $result->bytes;
} elseif ($mode === 'database') {
    $root = dirname(__DIR__, 3);
    $config = Config::load($root);
    $database = (string) $config->get('db.name');
    $host = (string) $config->get('db.host', '');
    if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || !str_ends_with($database, '_test')
        || getenv('MYINVOICE_DB_NAME') !== $database) {
        throw new RuntimeException('Unsafe stream worker database configuration.');
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) $config->get('db.port', 3306), $database),
        (string) $config->get('db.user', ''),
        (string) $config->get('db.pass', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) throw new RuntimeException('Unexpected stream worker database.');
    $pdo->exec('CREATE TEMPORARY TABLE nonprofit_tax_assessments (
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        supplier_id BIGINT UNSIGNED NOT NULL,
        snapshot_json LONGTEXT NOT NULL,
        assessment_json LONGTEXT NOT NULL,
        result_json LONGTEXT NOT NULL,
        UNIQUE KEY uq_stream_tenant (supplier_id,id)
    ) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO nonprofit_tax_assessments VALUES (1,11,'','{}','[]')");
    $append = $pdo->prepare('UPDATE nonprofit_tax_assessments SET snapshot_json=CONCAT(snapshot_json,?) WHERE id=1 AND supplier_id=11');
    foreach (fileChunks($input) as $chunk) $append->execute([$chunk]);
    $pdo->beginTransaction();
    $handle = fopen($output, 'w+b');
    if (!is_resource($handle)) throw new RuntimeException('Cannot open database stream output.');
    try {
        $result = (new CanonicalJsonSpool())->canonicalize(
            (new ChunkedJsonColumnReader($pdo))->read(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, 11, 1),
            CanonicalJsonProfile::NONPROFIT,
            $handle,
        );
    } finally {
        fclose($handle);
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec('DROP TEMPORARY TABLE nonprofit_tax_assessments');
    }
    $sha256 = $result->sha256;
    $bytes = $result->bytes;
} else {
    throw new InvalidArgumentException('Unknown stream worker mode.');
}

echo json_encode([
    'mode' => $mode,
    'sha256' => $sha256,
    'bytes' => $bytes,
    'peak_delta_bytes' => max(0, memory_get_peak_usage() - $baseline),
    'elapsed_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;

/** @return iterable<string> */
function fileChunks(string $path): iterable
{
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) throw new RuntimeException('Cannot open synthetic archive.');
    try {
        while (!feof($handle)) {
            $chunk = fread($handle, 65_536);
            if (!is_string($chunk)) throw new RuntimeException('Cannot read synthetic archive.');
            if ($chunk !== '') yield $chunk;
        }
    } finally {
        fclose($handle);
    }
}
