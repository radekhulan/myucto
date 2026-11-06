<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Infrastructure\Config\Config;
use MyUctoRegression\Runner;
use MyUctoRegression\Safety;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/regression/bootstrap.php';

final class RegressionCaptureTest extends TestCase
{
    public function testEveryManifestKindCapturesRealServicesOnFullyMigratedSyntheticDatabase(): void
    {
        if (getenv('MYINVOICE_REGRESSION_SYNTHETIC_DB') !== '1') {
            self::markTestSkipped('Explicit opt-in creates and drops a fully migrated owned synthetic database.');
        }
        $root = dirname(__DIR__, 3);
        $config = Config::load($root);
        $host = (string) $config->get('db.host', '127.0.0.1');
        Safety::host($host, null);
        $name = 'myucto_regcapture_' . bin2hex(random_bytes(8)) . '_test';
        Safety::database($name, (string) $config->get('db.name'));
        $server = new \PDO('mysql:host=' . $host . ';port=' . (int) $config->get('db.port', 3306) . ';charset=utf8mb4', (string) $config->get('db.user'), (string) $config->get('db.pass', ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $workspace = sys_get_temp_dir() . '/regression-full-schema-' . bin2hex(random_bytes(8));
        mkdir($workspace, 0700);
        Safety::outside($workspace, [$root]);
        $env = getenv();
        foreach (['DATABASE_URL', 'MYSQL_URL', 'MYSQL_HOST', 'MYSQL_PORT', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD', 'REDIS_URL'] as $key) {
            unset($env[$key]);
        }
        $env['MYINVOICE_DB_NAME'] = $name;
        $env['MYINVOICE_DATA_DIR'] = $workspace . '/setup-runtime';
        $env['MYINVOICE_REDIS_ENABLED'] = 'false';
        $owned = false;
        try {
            $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $owned = true;
            self::runSetup([$root . '/api/bin/migrate.php', '--no-backfills', '--no-analyze'], $env, $workspace . '/migrate.log');
            self::runSetup([$root . '/api/bin/ci-seed.php'], $env, $workspace . '/ci-seed.log');
            self::runSetup([$root . '/api/tests/Fixtures/regression-capture-seed.php', $name], $env, $workspace . '/fixture.log');
            $before = self::financialSnapshot($server, $name);
            $output = $workspace . '/capture.json';
            ob_start();
            $exit = Runner::main(['regression', 'capture', '--code-root', $root, '--database', $name, '--as-of', '2026-06-30', '--execution-date', date('Y-m-d'), '--dataset-id', $before, '--output', $output]);
            $stdout = ob_get_clean();
            self::assertSame(0, $exit, 'Capture failed; local synthetic output: ' . $output . '; summary: ' . $stdout);
            $capture = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('ok', $capture['status']);
            self::assertSame($before, self::financialSnapshot($server, $name), 'Capture must not persist drafts, dimensions, periods, closing steps or journal data.');
            $byKind = [];
            $dataFor = static function (string $kind, callable $matches) use ($capture): mixed {
                foreach ($capture['manifest'] as $id => $case) {
                    if ($case['kind'] === $kind && $matches($case)) {
                        return $capture['cases'][$id]['data'];
                    }
                }
                self::fail('Missing real capture case: ' . $kind);
            };
            foreach ($capture['manifest'] as $id => $case) {
                self::assertSame('ok', $capture['cases'][$id]['status'], $case['kind']);
                $byKind[$case['kind']][] = $capture['cases'][$id]['data'];
            }
            $expected = ['balance_sheet', 'income_statement', 'income_statement_function', 'trial_balance', 'general_ledger', 'dppo_preview', 'dppo_inputs', 'dppo_balance_due', 'dppo_data', 'dppo_forecast', 'dpfo_preview', 'dpfo_inputs', 'dpfo_balance_due', 'dpfo_data', 'cash_journal', 'tax_estimate', 'dimension_profit', 'dimension_cash_flow', 'closing_state', 'closing_income_tax', 'closing_pl_balances', 'closing_bs_balances', 'opening_balances', 'vat_ledger', 'vat_return_ledger', 'vat_book', 'vat_return', 'control_statement', 'recapitulative_statement', 'closing_entry_projection', 'closing_inventory', 'closing_stock', 'closing_fx', 'closing_small_asset_accrual', 'closing_prepaid_accrual', 'closing_deferred_revenue', 'closing_provisions', 'closing_deferred_tax', 'dppo_xml', 'dpfo_xml', 'accounting_mode_year'];
            sort($expected);
            $actual = array_keys($byKind);
            sort($actual);
            self::assertSame($expected, $actual);
            $year2025 = static fn (array $c): bool => $c['args'][0] === 1 && $c['args'][1] === 2025;
            $fo2025 = static fn (array $c): bool => $c['args'][0] === 2 && $c['args'][1] === 2025;
            self::assertSame(30000.15, $dataFor('dppo_data', $year2025)['vh']);
            self::assertSame(1234.56, $dataFor('dppo_inputs', $year2025)['inputs']['tax_paid_advances']);
            self::assertSame(5065.0, $dataFor('dppo_balance_due', $year2025)['balance_due']);
            self::assertSame(19000.0, $dataFor('dppo_data', static fn (array $c): bool => $c['args'][0] === 1 && $c['args'][1] === 2026)['vh']);
            self::assertSame(3890.0, $dataFor('dppo_forecast', static fn (array $c): bool => $c['args'][0] === 1 && $c['args'][1] === 2026)['amount']);
            self::assertSame(10000.0, $dataFor('income_statement', static fn (array $c): bool => $c['args'][0] === 1 && $c['args'][2] === '2026-06-30' && !isset($c['dimension']))['checks']['profit_current']);
            self::assertSame(19000.0, $dataFor('income_statement', static fn (array $c): bool => $c['args'][0] === 1 && $c['args'][2] === '2026-12-31' && !isset($c['dimension']))['checks']['profit_current']);
            self::assertSame(32000.19, $dataFor('income_statement', static fn (array $c): bool => $c['args'][0] === 1 && $c['args'][2] === '2025-12-31' && ($c['dimension']['value_id'] ?? 0) === 1 && $c['dimension']['descendants'])['checks']['profit_current']);
            self::assertSame(730000.15, $dataFor('dimension_profit', static fn (array $c): bool => $c['args'][0] === 1 && $c['args'][3] === '2025-12-31' && count($c['args'][4]) === 2)['totals']['result']);
            self::assertSame(700000.0, $dataFor('cash_journal', static fn (array $c): bool => $c['args'][1] === '2025-01-01')['totals']['net']);
            self::assertSame(-35000.25, array_column($dataFor('closing_pl_balances', static fn (array $c): bool => $c['args'][0] === 1 && $c['args'][3] === '2025-12-31'), 'bal', 'account_code')['602']);
            self::assertSame(35000.25, array_column($dataFor('closing_bs_balances', static fn (array $c): bool => $c['args'][0] === 1 && $c['args'][2] === '2025-12-31'), 'bal', 'account_code')['311']);
            self::assertSame(700000.0, $dataFor('dpfo_preview', $fo2025)['result']['s7']['base']);
            self::assertSame(74160.0, $dataFor('tax_estimate', $fo2025)['variants']['actual']['tax']);
            $quarter = $dataFor('vat_return', static fn (array $c): bool => $c['args'][0] === 1 && $c['args'][1] === 2025 && $c['args'][3] === 'quarterly');
            self::assertNotEmpty($quarter['xml_numeric']);
            self::assertContains('4200', array_values($quarter['xml_numeric']));
            self::assertTrue($capture['coverage']['complete']);
            self::assertSame([], $capture['coverage']['not_implemented']);
            self::assertSame(1, $capture['coverage']['suppliers_without_accounting_periods']);
            foreach ($expected as $kind) {
                if (!in_array($kind, ['closing_state', 'opening_balances', 'accounting_mode_year'], true)) {
                    self::assertTrue(self::hasNonzeroAmount($byKind[$kind]), $kind . ' must contain an actual nonzero financial value.');
                }
            }
            $third = static fn (array $case): bool => $case['args'][0] === 3;
            self::assertSame(1000.0, $dataFor('closing_fx', $third)['totals']['gain']);
            self::assertSame(1000.0, $dataFor('closing_stock', $third)['projection']['total']);
            self::assertSame(21000.0, $dataFor('closing_deferred_tax', $third)['movement']);
            foreach (['closing_small_asset_accrual', 'closing_prepaid_accrual', 'closing_deferred_revenue'] as $kind) self::assertGreaterThan(10000.0, $dataFor($kind, $third)['total']);
            self::assertSame('tax_evidence', $dataFor('accounting_mode_year', static fn (array $case): bool => $case['args'] === [2, 2025]));
            self::assertSame('double_entry', $dataFor('accounting_mode_year', static fn (array $case): bool => $case['args'] === [2, 2026]));
            $amended = $dataFor('vat_return', static fn (array $case): bool => $case['args'][0] === 3 && ($case['args'][4] ?? '') === 'dodatecne');
            self::assertContains('210', array_values($amended['xml_numeric']));
            $followup = $dataFor('recapitulative_statement', static fn (array $case): bool => $case['args'][0] === 3 && ($case['args'][4] ?? '') === 'nasledne');
            self::assertContains('16000', array_values($followup['xml_numeric']));
            self::assertNotEmpty($dataFor('dpfo_xml', $fo2025)['xml_numeric']);
            self::assertNotEmpty($dataFor('dppo_xml', $year2025)['xml_numeric']);
            $joint = $dataFor('closing_entry_projection', static fn (array $case): bool => $case['args'][0] === 1 && $case['args'][3] === '2025-12-31');
            foreach ([$joint['bs_balances'], $joint['closing']['lines'], $joint['opening']] as $rows) {
                $receivable = array_values(array_filter($rows, static fn (array $row): bool => $row['account_code'] === '311'));
                self::assertCount(1, $receivable);
                self::assertSame([1 => 2], $receivable[0]['dimensions']);
                self::assertSame([2 => [4 => 0.3, 5 => 0.7]], $receivable[0]['dimension_splits']);
                self::assertSame(35000.25, $receivable[0]['bal'] ?? $receivable[0]['amount']);
            }
            $baselineRoot = getenv('MYINVOICE_REGRESSION_BASELINE_ROOT');
            if (is_string($baselineRoot) && $baselineRoot !== '') {
                $baselineOutput = $workspace . '/baseline-capture.json';
                ob_start();
                $baselineExit = Runner::main(['regression', 'capture', '--code-root', $baselineRoot, '--database', $name, '--as-of', '2026-06-30', '--execution-date', date('Y-m-d'), '--dataset-id', $before, '--output', $baselineOutput]);
                $baselineSummary = ob_get_clean();
                self::assertSame(0, $baselineExit, 'Native baseline capture: ' . $baselineOutput . '; ' . $baselineSummary);
                $baseline = json_decode((string) file_get_contents($baselineOutput), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame($capture['manifest'], $baseline['manifest']);
                self::assertSame($before, self::financialSnapshot($server, $name));
            }
            $request = ['code_root' => $root, 'database' => $name, 'as_of' => '2026-06-30', 'execution_date' => date('Y-m-d'), 'docker_host' => null];
            $filed = $server->query("SELECT id,xml_content,xml_sha256 FROM `" . $name . "`.tax_submissions WHERE form_code='dphdp3' ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
            $badXml = '<Pisemnost><WrongForm/></Pisemnost>';
            $archiveUpdate = $server->prepare('UPDATE `' . $name . '`.tax_submissions SET xml_content=?,xml_sha256=? WHERE id=?');
            try {
                $archiveUpdate->execute([$badXml, hash('sha256', $badXml), $filed['id']]);
                $invalidArchive = Runner::child($request + ['action' => 'preflight'], $workspace, 'invalid-filed-archive');
                self::assertSame('error', $invalidArchive['status']);
                self::assertSame('invalid_filed_vat_xml', $invalidArchive['error']);
            } finally {
                $archiveUpdate->execute([$filed['xml_content'], $filed['xml_sha256'], $filed['id']]);
            }
            $supplierState = $server->query("SELECT taxpayer_type,updated_at FROM `" . $name . "`.supplier WHERE id=2")->fetch(\PDO::FETCH_ASSOC);
            try {
                $server->exec("UPDATE `" . $name . "`.supplier SET taxpayer_type='po' WHERE id=2");
                $historicalType = Runner::child($request + ['action' => 'case', 'case' => ['kind' => 'dpfo_preview', 'args' => [2, 2025, 'fo']]], $workspace, 'historical-type-conflict');
                self::assertSame('error', $historicalType['status']);
                self::assertSame('taxpayer_type_mismatch', $historicalType['domain_code']);
            } finally {
                $server->prepare("UPDATE `" . $name . "`.supplier SET taxpayer_type=?,updated_at=? WHERE id=2")->execute([$supplierState['taxpayer_type'], $supplierState['updated_at']]);
            }
            self::assertSame($before, self::financialSnapshot($server, $name));
            $originalData = $workspace . '/original-instance';
            mkdir($originalData, 0700);
            file_put_contents($originalData . '/cfg.local.php', '<?php return ' . var_export(['db' => ['name' => $name]], true) . ';');
            $originalEnvironment = [];
            foreach (['MYINVOICE_DATA_DIR', 'MYINVOICE_DB_NAME', 'MYSQL_DATABASE', 'DATABASE_URL', 'MYSQL_URL'] as $key) {
                $originalEnvironment[$key] = getenv($key);
                putenv($key);
            }
            try {
                putenv('MYINVOICE_DATA_DIR=' . $originalData);
                $guarded = Runner::child([
                    'code_root' => $root, 'database' => $name, 'as_of' => '2025-12-31',
                    'execution_date' => date('Y-m-d'), 'docker_host' => null, 'action' => 'preflight',
                ], $workspace, 'original-working-database');
                self::assertSame('error', $guarded['status']);
                self::assertSame('test_database_required', $guarded['error']);
            } finally {
                foreach ($originalEnvironment as $key => $value) {
                    putenv($value === false ? $key : $key . '=' . $value);
                }
            }
        } finally {
            if ($owned) {
                Safety::database($name, (string) $config->get('db.name'));
                $server->exec('DROP DATABASE `' . $name . '`');
            }
        }
    }

    private static function financialSnapshot(\PDO $pdo, string $name): string
    {
        Safety::database($name, '');
        $rows = [];
        foreach (['supplier', 'supplier_accounting_modes', 'statement_versions', 'statement_rows', 'statement_account_map', 'statement_function_map', 'invoices', 'invoice_items', 'purchase_invoices', 'purchase_invoice_items', 'cash_documents', 'income_tax_returns', 'tax_profiles', 'accounting_periods', 'accounting_closing_steps', 'journal_entries', 'journal_entry_lines', 'document_dimensions', 'journal_entry_line_dimensions', 'journal_entry_line_dimension_splits', 'tax_submissions', 'exchange_rates', 'small_assets', 'assets', 'depreciation_entries', 'stock_documents', 'stock_document_lines', 'accounting_balance_inventory', 'accounting_balance_inventory_items'] as $table) {
            $data = $pdo->query('SELECT * FROM `' . $name . '`.`' . $table . '`')->fetchAll(\PDO::FETCH_ASSOC);
            $encoded = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $data);
            sort($encoded, SORT_STRING);
            $rows[$table] = $encoded;
        }
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private static function runSetup(array $arguments, array $environment, string $log): void
    {
        $process = proc_open(array_merge([PHP_BINARY], $arguments), [['pipe', 'r'], ['file', $log, 'w'], ['file', $log, 'a']], $pipes, null, $environment, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        self::assertSame(0, proc_close($process), 'Synthetic setup failed; private log: ' . $log);
    }

    private static function hasNonzeroAmount(mixed $value, string $key = ''): bool
    {
        if ($key === 'xml' && is_string($value)) {
            $document = new \DOMDocument();
            self::assertTrue($document->loadXML($value, LIBXML_NONET));
            foreach ((new \DOMXPath($document))->query('//@*') as $attribute) {
                if (preg_match('/^(zakl_dane|dan[123]|pln_hodnota|obrat)/', $attribute->nodeName) && is_numeric($attribute->nodeValue) && (float) $attribute->nodeValue !== 0.0) {
                    return true;
                }
            }
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $child => $item) {
                if (self::hasNonzeroAmount($item, (string) $child)) {
                    return true;
                }
            }
            return false;
        }
        return !preg_match('/rate|percent|count|_id$/i', $key)
            && preg_match('/amount|balance|base|vat|tax|profit|total|turnover|revenue|cost|result|expense|income|gain|loss|remaining|deferred|^bal$|^vh$|^dan$|^md$|^d$|^net$|^gross$|^ps_|^ks_|zaklad|dan[0-9_]/i', $key) === 1
            && is_numeric($value) && (float) $value !== 0.0;
    }
}
