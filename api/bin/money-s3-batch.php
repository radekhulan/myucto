<?php

declare(strict_types=1);

/**
 * Dávkový převod více záloh Money S3 najednou (účetní kancelář, převod skupiny firem),
 * viz {@see \MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchImporter}. Totéž dělá
 * průvodce „Přechod z Money S3" v režimu Dávka.
 *
 *   php api/bin/money-s3-batch.php --dir=<adresář se zálohami .lz> --list
 *   php api/bin/money-s3-batch.php --dir=<adresář> --all [--dry-run]
 *   php api/bin/money-s3-batch.php --dir=<adresář> --ico=12345679,87654326 --from-year=auto
 *
 * Každá záloha je jedna firma, pozná se podle IČO v záloze; z více záloh téže firmy platí
 * nejnovější. Firma, která v MyÚčtu není, se založí (identita ze zálohy, podaného DPPO
 * a ARES). Pád jedné firmy nezastaví ostatní; na konci je souhrn po firmách s kontrolami
 * K1–K4 (K1 předvaha proti deníku Money, K2 vyrovnanost, K3 úplnost mapování výkazů,
 * K4 doklady proti deníku).
 *
 * Volby:
 *   --from-year=auto|RRRR|all  první převáděný rok; auto (výchozí) = první rok, od kterého
 *                              v Money navazují počáteční a konečné stavy
 *   --dry-run                  zkouška nanečisto: nic nezůstane, ani založené firmy
 *   --existing=skip|update|relink  firma už v MyÚčtu je: přeskočit (výchozí), převést znovu
 *                              (převod doplní jen to, co chybí), nebo opravný převod vazeb
 *                              (firma se mezitím účtuje v MyÚčtu: nic nového nezaloží, jen
 *                              převzatým dokladům znovu dopočítá úhrady, zápočty a zálohy)
 *   --dppo-dir=<adresář>       podaná přiznání k DPPO (EPO XML, i v podadresářích): identita
 *                              firmy (NACE, kategorie ÚJ, audit, sídlo) a převzetí do přiznání
 *                              a evidence ztrát po převodu
 *   --group="Název"            firmy zařadit do nové skupiny firem (sdílené dimenze)
 *   --group-id=N               … do existující skupiny
 *   --related-parties          firmy dávky (a skupiny) jsou navzájem spřízněné osoby
 *   --no-close                 historické roky neuzavírat
 *   --disposal-year-tax=half|none  daňový odpis majetku v roce vyřazení (výchozí half)
 *   --no-registry              bez ARES a registru plátců DPH (bez sítě)
 *   --user-id=N                kdo převádí (výchozí první uživatel instalace)
 *   --report=<soubor.json>     souhrn dávky navíc jako JSON
 *
 * Návratové kódy: 0 = všechny firmy převedené nebo přeskočené, 1 = aspoň jedna selhala,
 * 2 = chybné použití.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\CodebookImporter;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchImporter;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchOptions;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Migration\Shared\FiledDppoFiling;
use MyInvoice\Service\Migration\Shared\MigrationBatchActor;
use MyInvoice\Service\Migration\Shared\MigrationBatchRunner;
use MyInvoice\Service\Tax\Return\TaxReturnException;

const MS3B_USAGE = <<<TXT
Použití:
  php api/bin/money-s3-batch.php --dir=<adresář se zálohami .lz> (--all | --ico=<IČO>[,<IČO>…] | --list)
      [--from-year=auto|RRRR|all] [--dry-run] [--existing=skip|update|relink] [--dppo-dir=<adresář>]
      [--group="Název" | --group-id=N] [--related-parties] [--no-close] [--disposal-year-tax=half|none]
      [--no-registry] [--user-id=N] [--report=<soubor.json>]

TXT;

$opts = getopt('', ['dir:', 'ico:', 'all', 'list', 'from-year:', 'dry-run', 'existing:', 'dppo-dir:', 'group:', 'group-id:',
    'related-parties', 'no-close', 'disposal-year-tax:', 'no-registry', 'user-id:', 'report:']);

function ms3bFail(string $message, int $code = 2): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

function ms3bPad(string $s, int $width): string
{
    $s = mb_substr($s, 0, $width);
    return $s . str_repeat(' ', max(0, $width - mb_strlen($s)));
}

$dir = isset($opts['dir']) ? rtrim((string) $opts['dir'], '/\\') : '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, MS3B_USAGE);
    exit(2);
}

// Zálohy v adresáři: IČO a datum zálohy z hlavičky, z více záloh firmy nejnovější.
$tempRoot = RuntimePaths::storage('money-s3-batch/cli-' . bin2hex(random_bytes(6)));
$files = array_merge(glob($dir . DIRECTORY_SEPARATOR . '*.lz') ?: [], glob($dir . DIRECTORY_SEPARATOR . '*.LZ') ?: []);
$files = array_values(array_unique($files));
sort($files, SORT_STRING);
$backups = [];
foreach ($files as $i => $file) {
    try {
        $id = Ms3Backup::peekIdentity($file, $tempRoot . DIRECTORY_SEPARATOR . 'peek' . $i);
    } catch (MoneyS3Exception $e) {
        fwrite(STDERR, sprintf("Přeskočeno %s: %s\n", basename($file), $e->getMessage()));
        continue;
    }
    $backups[] = ['path' => $file, 'ico' => CodebookImporter::ico($id['ico']), 'name' => $id['name'], 'backup_at' => $id['backup_at'], 'mtime' => (int) filemtime($file)];
}
MoneyS3BatchImporter::removeTree($tempRoot);
$selection = MoneyS3BatchImporter::latestPerIco($backups);
$latest = array_map(static fn (int $i): array => $backups[$i], $selection['keep']);

if (array_key_exists('list', $opts)) {
    printf("%-10s  %-40s  %-17s  %s\n", 'IČO', 'agenda', 'záloha', 'soubor');
    foreach ($backups as $i => $b) {
        printf("%-10s  %s  %-17s  %s%s\n", $b['ico'] !== '' ? $b['ico'] : '?', ms3bPad($b['name'], 40), $b['backup_at'], basename($b['path']),
            in_array($i, $selection['superseded'], true) ? '  (starší, nepřevádí se)' : '');
    }
    printf("\n%d záloh, %d firem.\n", count($backups), count($latest));
    exit(0);
}

if (array_key_exists('all', $opts)) {
    $selected = $latest;
} else {
    $wanted = [];
    foreach ((array) ($opts['ico'] ?? []) as $v) {
        foreach (explode(',', (string) $v) as $ico) {
            if (trim($ico) !== '') {
                $wanted[] = CodebookImporter::ico($ico);
            }
        }
    }
    if ($wanted === []) {
        fwrite(STDERR, MS3B_USAGE);
        exit(2);
    }
    $selected = array_values(array_filter($latest, static fn (array $b): bool => in_array($b['ico'], $wanted, true)));
    foreach (array_diff($wanted, array_column($selected, 'ico')) as $missing) {
        fwrite(STDERR, "IČO {$missing}: v adresáři není záloha.\n");
    }
}
if ($selected === []) {
    ms3bFail('Žádná záloha k převodu.');
}

$fromYearOpt = (string) ($opts['from-year'] ?? 'auto');
if ($fromYearOpt !== 'auto' && $fromYearOpt !== 'all' && preg_match('/^\d{4}$/', $fromYearOpt) !== 1) {
    ms3bFail('--from-year čeká auto, rok (RRRR) nebo all.');
}

$filings = [];
if (isset($opts['dppo-dir'])) {
    $dppoDir = (string) $opts['dppo-dir'];
    if (!is_dir($dppoDir)) {
        ms3bFail("Adresář {$dppoDir} neexistuje.");
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dppoDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'xml') {
            continue;
        }
        $xml = (string) file_get_contents($f->getPathname());
        try {
            $filings[] = ['filing' => FiledDppoFiling::parse($xml), 'xml' => $xml, 'file' => $f->getPathname()];
        } catch (TaxReturnException) {
            // jiné podání než DPPDP9
        }
    }
    printf("Podaná přiznání k DPPO: %d\n", count($filings));
}

try {
    $options = new MoneyS3BatchOptions(
        array_key_exists('dry-run', $opts) ? 'dry_run' : 'import',
        $fromYearOpt === 'auto' ? MoneyS3BatchOptions::FROM_YEAR_AUTO : ($fromYearOpt === 'all' ? null : (int) $fromYearOpt),
        (string) ($opts['existing'] ?? MoneyS3BatchOptions::EXISTING_SKIP),
        !array_key_exists('no-close', $opts),
        (string) ($opts['disposal-year-tax'] ?? 'half'),
        isset($opts['group-id']) ? (int) $opts['group-id'] : null,
        isset($opts['group']) ? (string) $opts['group'] : null,
        array_key_exists('related-parties', $opts),
        [],
        !array_key_exists('no-registry', $opts),
        $filings !== [],
    );
} catch (MoneyS3Exception $e) {
    ms3bFail($e->getMessage());
}

$container = Bootstrap::buildApp()->getContainer();
$pdo = $container->get(Connection::class)->pdo();
$userId = isset($opts['user-id']) ? (int) $opts['user-id'] : (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$userExists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
$userExists->execute([$userId]);
if ($userId <= 0 || (int) $userExists->fetchColumn() === 0) {
    // Převedené doklady nesou autora (cizí klíč na uživatele); bez něj by se nepřevzaly.
    ms3bFail(isset($opts['user-id']) ? "Uživatel #{$userId} neexistuje." : 'V instalaci není žádný uživatel, převod nemá komu doklady přiřadit. Dokončete nejdřív instalaci (setup).');
}
/** @var MoneyS3BatchImporter $importer */
$importer = $container->get(MoneyS3BatchImporter::class);
$options = $importer->prepareBatch($options, array_column($selected, 'ico'));
$actor = MigrationBatchActor::cli($userId);

printf("Databáze: %s\n%s %d firem, od roku %s, existující firmy: %s%s\n",
    (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
    $options->isDryRun() ? 'Zkouška nanečisto' : 'Ostrý převod', count($selected),
    $fromYearOpt, match ($options->existing) { 'update' => 'převést znovu', 'relink' => 'opravný převod vazeb', default => 'přeskočit' },
    $options->groupId !== null ? ", skupina #{$options->groupId}" : '');

$started = microtime(true);
$workRoot = RuntimePaths::storage('money-s3-batch/cli-' . bin2hex(random_bytes(6)));
$results = (new MigrationBatchRunner())->run(
    $selected,
    static function (array $b, int $index) use ($importer, $options, $actor, $filings, $workRoot): array {
        $last = '';
        $progress = static function (string $step) use (&$last): void {
            if ($step !== $last) {
                printf("  … %s\n", $step);
                $last = $step;
            }
        };
        return $importer->importCompany($b['path'], $workRoot . DIRECTORY_SEPARATOR . $index, $options, $actor, $filings, null, $progress);
    },
    static function (\Throwable $e): string {
        if ($e instanceof MoneyS3Exception) {
            return $e->getMessage();
        }
        error_log('Money S3 dávka (CLI): ' . (string) $e);
        return 'Neočekávaná chyba: ' . $e->getMessage();
    },
    null,
    static function (array $b, int $index) use ($selected): void {
        printf("\n######## %d/%d  %s  %s  (%s)\n", $index + 1, count($selected), $b['ico'], $b['name'], basename($b['path']));
    },
    static function (array $b, int $index, array $r): void {
        printf("  → %s%s\n", $r['status'], isset($r['error']) && $r['error'] !== null ? ': ' . $r['error'] : '');
        foreach ((array) ($r['summary']['notices'] ?? []) as $n) {
            printf("    ! %s\n", $n);
        }
    },
);
MoneyS3BatchImporter::removeTree($workRoot);

$mark = static fn (?bool $ok): string => $ok === null ? '-' : ($ok ? 'ok' : 'NE');
echo "\n\nSOUHRN DÁVKY\n";
printf("%-10s  %-30s  %-16s  %-7s  %-24s  %-3s %-3s %-3s %-3s  %s\n", 'IČO', 'firma', 'firma v MyÚčtu', 'od roku', 'stav', 'K1', 'K2', 'K3', 'K4', 'DPPO');
$report = [];
$failed = 0;
foreach ($selected as $i => $b) {
    $r = $results[$i];
    $s = (array) ($r['summary'] ?? []);
    $k = (array) ($s['criteria']['total'] ?? []);
    $company = match ($r['company_action'] ?? null) {
        'created' => ($r['target_supplier_id'] ?? null) !== null ? 'nová #' . $r['target_supplier_id'] : 'nová',
        'existing' => '#' . ($r['target_supplier_id'] ?? '?'),
        'skipped' => 'přeskoč. #' . ($r['target_supplier_id'] ?? '?'),
        default => '-',
    };
    $filed = array_map(static fn (array $f): string => $f['year'] . ':' . $f['status'], (array) ($s['filings'] ?? []));
    printf("%-10s  %s  %s  %-7s  %s  %-3s %-3s %-3s %-3s  %s\n", $b['ico'], ms3bPad($b['name'], 30), ms3bPad($company, 16),
        ($r['company_action'] ?? null) === 'skipped' || $r['status'] === MigrationBatchRunner::FAILED && ($r['company_action'] ?? null) === null ? '-' : ($r['from_year'] ?? 'vše'), ms3bPad((string) $r['status'], 24),
        $mark($k['K1'] ?? null), $mark($k['K2'] ?? null), $mark($k['K3'] ?? null), $mark($k['K4'] ?? null),
        $filed !== [] ? implode(', ', $filed) : (($s['filings_available'] ?? []) !== [] ? 'k převzetí: ' . implode(', ', $s['filings_available']) : '-'));
    $failed += $r['status'] === MigrationBatchRunner::FAILED ? 1 : 0;
    $report[] = ['ico' => $b['ico'], 'name' => $b['name'], 'file' => basename($b['path'])] + $r;
}
printf("\nDávka: %s za %.0f s.\n", MigrationBatchRunner::batchStatus(array_column($results, 'status')), microtime(true) - $started);

if (isset($opts['report'])) {
    foreach ($report as &$row) {
        unset($row['summary']['protocol']);
    }
    unset($row);
    file_put_contents((string) $opts['report'], (string) json_encode(['options' => $options->toArray(), 'items' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    printf("Souhrn: %s\n", (string) $opts['report']);
}
exit($failed > 0 ? 1 : 0);
