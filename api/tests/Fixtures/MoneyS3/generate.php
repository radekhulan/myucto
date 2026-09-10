<?php

declare(strict_types=1);

/**
 * Generátor syntetické zálohy agendy Money S3 pro testy převodu.
 *
 *   php api/tests/Fixtures/MoneyS3/generate.php [cílový soubor.lz]
 *
 * Bez argumentu přepíše `synthetic-agenda.lz` vedle sebe. Obsah je v
 * {@see \MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda} — po jeho změně záložku
 * vygeneruj znovu, jinak `SyntheticAgendaFixtureTest` ohlásí rozdíl.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../../../vendor/autoload.php';

use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;

$target = $argv[1] ?? (__DIR__ . DIRECTORY_SEPARATOR . 'synthetic-agenda.lz');
SyntheticAgenda::writeLz($target);
fwrite(STDOUT, 'Záloha agendy: ' . $target . ' (' . filesize($target) . " B)\n");
