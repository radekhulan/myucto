<?php

declare(strict_types=1);

/**
 * Hromadný přepočet kontroly zaúčtovaných dokladů proti vytěžení příloh.
 * Logika: MyInvoice\Service\Document\AttachmentCheck\AttachmentCheckService::recheckAll().
 *
 * Bez rozsahu projde celou firmu a zahodí i výsledky dokladů, které přílohu
 * s vytěžením už nemají. Nic neúčtuje ani nemění doklady; potvrzení „v pořádku"
 * zůstávají. Bezpečné pouštět opakovaně.
 *
 * Použití:
 *   php api/bin/recheck-attachment-checks.php                          # všechny firmy
 *   php api/bin/recheck-attachment-checks.php --supplier=5             # jedna firma
 *   php api/bin/recheck-attachment-checks.php --from=2026-01-01 --to=2026-03-31
 */

require __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['supplier::', 'from::', 'to::']);
$from = isset($opts['from']) && is_string($opts['from']) && $opts['from'] !== '' ? $opts['from'] : null;
$to = isset($opts['to']) && is_string($opts['to']) && $opts['to'] !== '' ? $opts['to'] : null;
foreach ([$from, $to] as $d) {
    if ($d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
        fwrite(STDERR, "Datum musí být ve tvaru RRRR-MM-DD.\n");
        exit(2);
    }
}

$container = \MyInvoice\Bootstrap::buildApp()->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$service = $container->get(\MyInvoice\Service\Document\AttachmentCheck\AttachmentCheckService::class);

$suppliers = isset($opts['supplier']) && is_string($opts['supplier']) && ctype_digit($opts['supplier'])
    ? [(int) $opts['supplier']]
    : array_map('intval', $pdo->query('SELECT id FROM supplier ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));

foreach ($suppliers as $sid) {
    $r = $service->recheckAll($sid, $from, $to);
    if ($r['checked'] === 0 && $r['removed'] === 0) {
        continue;
    }
    echo "Firma #{$sid}: zkontrolováno {$r['checked']}, s rozdílem {$r['mismatches']}, otevřených {$r['open']}, odstraněno {$r['removed']}\n";
}
echo "Hotovo.\n";
