<?php

declare(strict_types=1);

/**
 * Kontrola dat po převodu z jiného účetního programu (POHODA, Money S3, ABRA Flexi a další).
 *
 * Spouští tytéž kontroly jako stránka „Kontrola dat po převodu" v aplikaci
 * ({@see \MyInvoice\Service\Migration\Check\MigrationDataCheckService}). Výchozí je běh
 * nanečisto: souhrn kontrol, s --verbose i nálezy po dokladech. S --fix provede opravu
 * kontrol, které ji mají, se stejnými pojistkami jako aplikace (uzavřená, zamčená
 * a podaná období jen hlásí, každá změna v auditní stopě). Idempotentní.
 *
 * Použití:
 *   php api/bin/migration-data-check.php --all                                  # nanečisto, všechny firmy s převodem
 *   php api/bin/migration-data-check.php --supplier=12 --verbose                # nálezy jedné firmy
 *   php api/bin/migration-data-check.php --supplier=12 --check=tax_document_advance_link --fix
 *   php api/bin/migration-data-check.php --supplier=12 --check=foreign_bank_in_czk --option=include_uploaded --fix
 *   php api/bin/migration-data-check.php --all --json                           # strojový výstup
 *
 * Kontroly: settled_without_payment, foreign_bank_in_czk, imported_statement_balances,
 * payroll_liabilities, subledger_vs_ledger, purchase_settled_uncovered,
 * tax_document_advance_link, final_invoice_advance_vat, foreign_purchase_in_czk,
 * foreign_issued_in_czk, asset_account_undetermined, asset_activation_missing,
 * tax_evidence_cash_missing.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\Check\FixableMigrationDataCheck;
use MyInvoice\Service\Migration\Check\MigrationDataCheckException;
use MyInvoice\Service\Migration\Check\MigrationDataCheckRegistry;
use MyInvoice\Service\Migration\Check\MigrationDataCheckService;
use MyInvoice\Service\Migration\Check\MigrationSources;

$fix = false;
$all = false;
$verbose = false;
$json = false;
$suppliers = [];
$checks = [];
$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--fix') {
        $fix = true;
    } elseif ($arg === '--all') {
        $all = true;
    } elseif ($arg === '--verbose') {
        $verbose = true;
    } elseif ($arg === '--json') {
        $json = true;
    } elseif (preg_match('/^--supplier=([1-9]\d*)$/D', $arg, $m) === 1) {
        $suppliers[(int) $m[1]] = (int) $m[1];
    } elseif (preg_match('/^--check=([a-z0-9_]+)$/D', $arg, $m) === 1) {
        $checks[$m[1]] = $m[1];
    } elseif (preg_match('/^--option=([a-z0-9_]+)$/D', $arg, $m) === 1) {
        $options[$m[1]] = true;
    } else {
        fwrite(STDERR, "Neznámý nebo neplatný argument: {$arg}\n");
        exit(2);
    }
}
if ($all === ($suppliers !== [])) {
    fwrite(STDERR, "Zadejte --supplier=ID (i opakovaně), nebo --all.\n");
    exit(2);
}

$container = Bootstrap::buildContainer();
$registry = $container->get(MigrationDataCheckRegistry::class);
foreach ($checks as $key) {
    if ($registry->get($key) === null) {
        fwrite(STDERR, "Neznámá kontrola {$key}. Kontroly: " . implode(', ', array_keys($registry->all())) . "\n");
        exit(2);
    }
}
$service = $container->get(MigrationDataCheckService::class);
$ids = $all ? $container->get(MigrationSources::class)->suppliers() : array_values($suppliers);
$company = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo()->prepare('SELECT company_name FROM supplier WHERE id = ?');

$fmt = static fn (float $v): string => number_format($v, 2, ',', ' ');
$labels = ['ok' => 'OK', 'fixable' => 'k opravě', 'review' => 'ke kontrole', 'error' => 'chyba'];
$out = [];
$failed = 0;
if (!$json) {
    echo ($fix ? '' : '[NANEČISTO] ') . 'Kontrola dat po převodu' . PHP_EOL;
}
foreach ($ids as $supplierId) {
    $company->execute([$supplierId]);
    $name = (string) ($company->fetchColumn() ?: '');
    $summary = $service->summary($supplierId);
    $row = ['supplier_id' => $supplierId, 'company' => $name, 'sources' => $summary['sources'], 'checks' => [], 'fixes' => []];
    if (!$json) {
        printf("Firma %d %s (%s)\n", $supplierId, $name, $summary['sources'] === [] ? 'bez převodu' : implode(', ', $summary['sources']));
    }
    foreach ($summary['checks'] as $c) {
        if ($checks !== [] && !isset($checks[$c['key']])) {
            continue;
        }
        $row['checks'][] = $c;
        if (!$json) {
            printf("  %-28s %-12s nálezů %d (k opravě %d, chráněné období %d, ke kontrole %d), %s Kč\n",
                $c['key'], $labels[$c['status']] ?? $c['status'], $c['count'], $c['fixable_count'], $c['locked_count'], $c['review_count'], $fmt((float) $c['amount_czk']));
            foreach ($c['notes'] as $note) {
                echo '    pozn.: ' . $note . PHP_EOL;
            }
        }
        if ($verbose && !$json && $c['count'] > 0) {
            $detail = $service->detail($supplierId, $c['key'], 1, MigrationDataCheckService::MAX_PER_PAGE);
            foreach ($detail['items'] as $i) {
                printf("    %-8s %s #%s %-24s %s %s  %s\n", $i['state'], $i['entity_type'], $i['entity_id'] ?? '-', $i['label'], $i['date'] ?? '',
                    $i['amount_czk'] !== null ? $fmt((float) $i['amount_czk']) . ' Kč' : '', $i['reason']);
            }
            if ($detail['pagination']['total'] > count($detail['items'])) {
                printf("    … a dalších %d nálezů\n", $detail['pagination']['total'] - count($detail['items']));
            }
        }
        if ($fix && $c['fixable'] && $c['status'] === 'fixable') {
            try {
                $check = $registry->get($c['key']);
                $known = $check instanceof FixableMigrationDataCheck ? $check->options() : [];
                $result = $service->fix($supplierId, $c['key'], null, array_intersect_key($options, $known));
                $row['fixes'][] = ['key' => $c['key'], 'fixed_count' => $result['fixed_count'], 'amount_czk' => $result['amount_czk'], 'after' => $result['after']['status']];
                if (!$json) {
                    printf("    opraveno %d nálezů, %s Kč; stav po opravě: %s%s\n", $result['fixed_count'], $fmt((float) $result['amount_czk']),
                        $labels[$result['after']['status']] ?? $result['after']['status'], $result['notes'] !== [] ? PHP_EOL . '    ' . implode(PHP_EOL . '    ', $result['notes']) : '');
                }
            } catch (MigrationDataCheckException $e) {
                // Souhrn firmy je z doby před opravami: nálezy mohla opravit oprava předchozí
                // kontroly (oprava Money S3 doplní vydané i přijaté doklady najednou).
                if ($e->errorCode === 'nothing_to_fix') {
                    $row['fixes'][] = ['key' => $c['key'], 'fixed_count' => 0, 'after' => 'nothing_to_fix'];
                    if (!$json) {
                        echo '    nic k opravě, nálezy opravila předchozí oprava' . PHP_EOL;
                    }
                    continue;
                }
                $failed++;
                $row['fixes'][] = ['key' => $c['key'], 'error' => $e->errorCode, 'message' => $e->getMessage()];
                if (!$json) {
                    echo '    oprava neproběhla: ' . $e->getMessage() . PHP_EOL;
                }
            }
        }
    }
    $out[] = $row;
}
if ($json) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} elseif (!$fix) {
    echo PHP_EOL . 'Nic nezměněno. Opravu provede spuštění s --fix.' . PHP_EOL;
}
exit($failed > 0 ? 1 : 0);
