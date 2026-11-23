<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Importer;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Mzdy z Money S3 nad skutečnou DB: návrh kontací z mzdových dokladů, měsíční kontrolní
 * úhrny, zapnutí modulu Mzdy firmě bez mzdové účtárny a upozornění, že zaměstnance
 * převod nepřevádí. Izolovaná firma, transakce s rollbackem.
 */
#[Group('integration')]
final class MoneyS3PayrollImportTest extends TestCase
{
    use OwnedSandboxDatabase;
    private Connection $db;
    private MoneyS3Importer $importer;
    private string $tmp = '';
    private int $userId = 0;
    private int $anyCurrencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        $ownsTransactions = in_array('fulltext-transactions', $this->groups(), true);
        try {
            $container = Bootstrap::buildContainer();
            if ($ownsTransactions) {
                $container->set(Config::class, self::sandboxConfig('money_pay_preview'));
                $container->set(Connection::class, self::sandboxConnection('money_pay_preview'));
            }
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(MoneyS3Importer::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        foreach (['payroll_posting_map_proposals', 'payroll_module_state', 'payroll_employer_settings'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms3pay_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        if (!$ownsTransactions) {
            $pdo->beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testPayrollDocumentsGiveProposalTotalsAndEnableModule(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->convert($supplierId, SyntheticAgenda::filesWithPayroll(), ImportOptions::MODE_IMPORT);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        // Návrh kontací: uložený jen jako návrh, nic nepotvrzeno.
        $stmt = $this->db->pdo()->prepare('SELECT source, status, source_year, proposal_json FROM payroll_posting_map_proposals WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $stored = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $stored, $this->explain($protocol));
        self::assertSame(['money_s3', 'draft', 2025], [$stored[0]['source'], $stored[0]['status'], (int) $stored[0]['source_year']]);
        $proposal = json_decode((string) $stored[0]['proposal_json'], true);
        $keys = [];
        foreach ($proposal['keys'] as $key) {
            $keys[$key['key']] = [$key['status'], $key['suggested_code']];
        }
        $expected = [
            'employment_gross_debit' => ['unambiguous', '521.000'],
            'employment_gross_credit' => ['unambiguous', '331.000'],
            'social_insurance_credit' => ['unambiguous', '336.200'],
            'health_insurance_credit' => ['unambiguous', '336.100'],
            'employer_insurance_debit' => ['conflict', null],
            'income_tax_credit' => ['unambiguous', '342.100'],
            'enforcement_deductions_credit' => ['unambiguous', '379.000'],
        ];
        $actual = array_intersect_key($keys, $expected);
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual, $this->explain($protocol));
        self::assertCount(1, $proposal['unmapped']);

        // Kontrolní úhrny po měsících v protokolu; březnová daň nesedí na úhrn v Money.
        $totals = $protocol->get('payroll_totals');
        self::assertSame(['2025-01', '2025-02', '2025-03'], array_column($totals, 'period'));
        self::assertSame([30000.0, 22710.0, 3810.0, true], [$totals[0]['gross'], $totals[0]['net_payable'], $totals[0]['dpfo'], $totals[0]['tax_ok']]);
        $codes = $this->messageCodes($protocol);
        self::assertContains('payroll_tax_mismatch', $codes);
        self::assertContains('payroll_people_not_converted', $codes);
        self::assertContains('posting_map', $codes);

        // Modul Mzdy zapnutý se začátkem za posledním měsícem mezd, účtárna bez VS ČSSZ.
        self::assertContains('payroll_module_enabled', $codes, $this->explain($protocol));
        self::assertContains('payroll_setup_incomplete', $codes);
        self::assertSame(1, $this->scalar('SELECT payroll_enabled FROM supplier WHERE id = ?', $supplierId));
        self::assertSame(['setup', '2025-04-01'], $this->row('SELECT status, start_period FROM payroll_module_state WHERE supplier_id = ?', $supplierId));
        self::assertSame(['MZDY', null], $this->row('SELECT o.code, o.social_security_variable_symbol FROM payroll_employer_settings s
            JOIN payroll_offices o ON o.supplier_id = s.supplier_id AND o.id = s.default_office_id WHERE s.supplier_id = ?', $supplierId));

        // Zaměstnance převod z Money nezakládá (osoby jsou v šifrované databázi agendy).
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?", $supplierId));

        // Opakovaný převod nic nezapíná ani nepřepisuje.
        $again = $this->convert($supplierId, SyntheticAgenda::filesWithPayroll(), ImportOptions::MODE_IMPORT);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertNotContains('payroll_module_enabled', $this->messageCodes($again));
        self::assertSame(['setup', '2025-04-01'], $this->row('SELECT status, start_period FROM payroll_module_state WHERE supplier_id = ?', $supplierId));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM payroll_offices WHERE supplier_id = ?', $supplierId));
    }

    public function testAgendaWithoutPayrollLeavesPayrollAlone(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->convert($supplierId, SyntheticAgenda::files(), ImportOptions::MODE_IMPORT);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertNull($protocol->get('payroll_totals'));
        self::assertSame([], array_intersect(['payroll_module_enabled', 'payroll_people_not_converted', 'posting_map'], $this->messageCodes($protocol)));
        self::assertSame(0, $this->scalar('SELECT payroll_enabled FROM supplier WHERE id = ?', $supplierId));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_offices WHERE supplier_id = ?', $supplierId));
    }

    #[Group('fulltext-transactions')]
    public function testDryRunEnablesNothing(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->convert($supplierId, SyntheticAgenda::filesWithPayroll(), ImportOptions::MODE_DRY_RUN);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertContains('payroll_module_enabled', $this->messageCodes($protocol));
        self::assertSame(0, $this->scalar('SELECT payroll_enabled FROM supplier WHERE id = ?', $supplierId));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_module_state WHERE supplier_id = ?', $supplierId));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_posting_map_proposals WHERE supplier_id = ?', $supplierId));
    }

    /** @param array<string,string> $files */
    private function convert(int $supplierId, array $files, string $mode): ImportProtocol
    {
        $lz = $this->tmp . DIRECTORY_SEPARATOR . 'agenda-' . bin2hex(random_bytes(3)) . '.lz';
        SyntheticAgenda::writeLzFiles($lz, $files);
        $backup = Ms3Backup::extract($lz, $this->tmp . DIRECTORY_SEPARATOR . 'x-' . bin2hex(random_bytes(3)));
        return $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions($mode, true));
    }

    private function supplier(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, default_currency_id, default_vat_rate_id, accounting_mode, taxpayer_type)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, ?, "tax_evidence", "po")'
        )->execute([SyntheticAgenda::NAME, $this->czId, SyntheticAgenda::ICO, $this->anyCurrencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    private function scalar(string $sql, int ...$params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<mixed> */
    private function row(string $sql, int ...$params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        return $row === false ? [] : array_map(static fn (mixed $v): mixed => is_int($v) ? (string) $v : $v, $row);
    }

    /** @return list<string> */
    private function messageCodes(ImportProtocol $protocol): array
    {
        $codes = [];
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                $codes[] = $m['code'];
            }
        }
        return $codes;
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
