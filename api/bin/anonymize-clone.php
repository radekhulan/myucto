<?php

declare(strict_types=1);

/**
 * Anonymizovaná kopie databáze instance pro testovací prostředí.
 *
 *   php api/bin/anonymize-clone.php --from=myucto --to=myucto_anon
 *   php api/bin/anonymize-clone.php --from=myucto --to=myucto_anon --replace --dump=/tmp/anon.sql
 *   php api/bin/anonymize-clone.php --from=myucto --to=myucto_anon --files-out=/srv/test/storage
 *
 * Originál se jen čte. Kopie vznikne jako NOVÁ databáze na témž serveru; osobní a
 * obchodní údaje (jména, adresy, IČO/DIČ, rodná čísla, účty, e-maily, telefony,
 * texty dokladů, přílohy) se nahradí deterministickými pseudonymy, částky, data a
 * vazby zůstanou. Relace, tokeny a tajemství se zahodí, odchozí integrace vypnou.
 * Rozhodnutí pro každý sloupec je v AnonymizationPolicy.
 *
 * Exit kódy: 0 ok · 1 chyba běhu · 2 chybné argumenty
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Anonymization\AnonymizationOptions;
use MyInvoice\Service\Anonymization\AnonymizationService;

$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    $body = substr($arg, 2);
    $eq = strpos($body, '=');
    $opts[$eq === false ? $body : substr($body, 0, $eq)] = $eq === false ? true : substr($body, $eq + 1);
}

$usage = <<<TXT
Anonymizovaná kopie databáze pro testovací instanci.

  --from=DB            zdrojová databáze (výchozí: databáze této instance)
  --to=DB              cílová databáze — vznikne nová; nesmí to být databáze instance
  --replace            přepsat cíl, pokud je to předchozí anonymizovaná kopie
  --password=HESLO     všem uživatelům kopie nastavit toto heslo (min. 12 znaků);
                       bez něj jsou hesla nepoužitelná a nastaví je set-password.php
  --files-out=DIR      vytvořit zrcadlo úložiště se zástupnými soubory
  --files-from=DIR     zdrojové úložiště (výchozí: storage této instance)
  --dump=SOUBOR        po dokončení uložit dump kopie
  --dump-bin=CESTA     mariadb-dump / mysqldump (výchozí: mariadb-dump z PATH)
  --seed=TEXT          reprodukovatelné pseudonymy mezi běhy (jinak náhodný klíč;
                       se známým seedem jde pseudonym zpětně dohledat — jen pro vývoj)
  --source-keys=SOUBOR klíče, kterými jsou šifrované údaje zdroje (PHP soubor vracející
                       ['app' => [secret_encryption_key, pepper, payroll_hash_key,
                       secret_encryption_previous_keys]]); výchozí: klíče instance
  --target-keys=SOUBOR klíče testovací instance, pro kterou kopie vzniká (stejný tvar);
                       šifrované mzdové údaje se zapečetí jimi. Co nejde dešifrovat,
                       nahradí vymyšlená hodnota (rodné číslo podle data narození z karty)
  --older-source       zdroj je dump starší verze aplikace: tabulky a sloupce politiky,
                       které v něm ještě nejsou, se přeskočí (kopii pak doplní migrate.php)
  --help

TXT;

if (isset($opts['help'])) {
    fwrite(STDOUT, $usage);
    exit(0);
}

$string = static fn (string $key): ?string => is_string($opts[$key] ?? null) && $opts[$key] !== '' ? $opts[$key] : null;

$container = Bootstrap::buildApp()->getContainer();
/** @var Config $config */
$config = $container->get(Config::class);

$source = $string('from') ?? (string) $config->get('db.name', '');
$target = $string('to');
if ($target === null || $source === '') {
    fwrite(STDERR, "Chybí --to=DB (a případně --from=DB).\n\n" . $usage);
    exit(2);
}

$options = new AnonymizationOptions(
    source: $source,
    target: $target,
    replace: isset($opts['replace']),
    seed: $string('seed'),
    password: $string('password'),
    filesFrom: $string('files-from'),
    filesOut: $string('files-out'),
    dumpPath: $string('dump'),
    dumpBinary: $string('dump-bin'),
    sourceKeysPath: $string('source-keys'),
    targetKeysPath: $string('target-keys'),
    olderSource: isset($opts['older-source']),
);

set_time_limit(0);
$stamp = static fn (): string => '[' . date('H:i:s') . '] ';
$log = static function (string $line) use ($stamp): void {
    fwrite(STDOUT, $stamp() . $line . "\n");
};

try {
    $report = (new AnonymizationService($config))->run($options, $log);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Anonymizace selhala: ' . $e->getMessage() . "\n");
    exit(1);
}

$log("Hotovo za {$report['seconds']} s: {$options->target}");
$log(sprintf('  tabulek %d, vyprázdněno %d, upraveno řádků %d, slovník pseudonymů %d položek',
    $report['tables'], $report['truncated'], array_sum($report['changed_rows']), $report['dictionary']));
if ($report['leaks'] !== []) {
    $log('  Hodnoty shodné s originálem (zachované veřejné účty institucí, nebo k prověření):');
    foreach ($report['leaks'] as $column => $count) {
        $log("    {$column}: {$count}");
    }
}
if ($report['synthesized'] > 0) {
    $log("  nečitelných šifrovaných hodnot nahrazeno vymyšlenými: {$report['synthesized']}");
}
$log('  Uživatelé kopie (přihlašovací e-mail):');
foreach ($report['users'] as $user) {
    $log("    #{$user['id']} {$user['email']} ({$user['name']})");
}
if ($options->password === null) {
    $log('  Hesla jsou nepoužitelná — nastav je: MYINVOICE_DB_NAME=' . $options->target . ' php api/bin/set-password.php <e-mail>'
        . ($options->targetKeysPath !== null ? ' (v instanci s klíči z --target-keys, heslo závisí na pepperu)' : ''));
}
if ($report['files'] !== null) {
    $log("  zástupných souborů: {$report['files']['files']}");
}
if ($report['dump'] !== null) {
    $log("  dump: {$report['dump']}");
}
exit(0);
