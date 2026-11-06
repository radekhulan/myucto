<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Registration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\JmhzReportFixtures;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převzetí historie mezd z hlášení JMHZ, kterému MariaDB odvolá krok vztahu
 * deadlockem (1213). Krok, který neprojde kontrolou, se jen přeskočí; odvolaná
 * transakce ale vrátila i všechno, co volající zapsal před ním, takže další kroky
 * nesmí běžet v autocommitu. Deadlock vyvolá trigger se SIGNAL `40001`/1213 při
 * zápisu čerpání dovolené, jen v session testu.
 */
#[Group('integration')]
final class JmhzTakeoverAbortTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const TRIGGER = 'trg_test_abort_takeover_leave';
    private const PPV = '200000000000000000201';

    private Connection $db;
    private RegistrationImportService $imports;
    private int $supplierId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 6) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->imports = $container->get(RegistrationImportService::class);
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $sourceSupplierId, 'Testovací DB nemá výchozí firmu.');
        $pdo->exec('SET @payroll_import_abort = NULL');
        $pdo->exec('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
        $pdo->exec(
            'CREATE TRIGGER ' . self::TRIGGER . " BEFORE INSERT ON payroll_leave_ledger FOR EACH ROW
             BEGIN
                 IF @payroll_import_abort > 0 THEN
                     SET @payroll_import_abort = @payroll_import_abort - 1;
                     SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213,
                         MESSAGE_TEXT = 'Deadlock found when trying to get lock; try restarting transaction';
                 END IF;
             END"
        );
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, 'IMPORT', 'Syntetická účtárna', '1234567890', 1)"
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id) VALUES (?, ?)'
        )->execute([$this->supplierId, (int) $pdo->lastInsertId()]);
        $pdo->prepare(
            "INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, 'active', '2026-04-01')"
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('SET @payroll_import_abort = NULL');
        $pdo->exec('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
        $this->db->close();
    }

    public function testDeadlockInTakeoverStepReachesCaller(): void
    {
        $vacation = ['unworked_total_millihours' => 8_000, 'unworked_paid_millihours' => 8_000, 'vacation_millihours' => 8_000];
        $person = static fn (array $o): array => JmhzReportFixtures::person($o + [
            'employment_id' => 201,
            'id_ppv' => self::PPV,
            'oic' => RegistrationXmlFixtures::oic(21),
            'children' => [],
        ]);
        $files = [];
        foreach ([
            1 => [$person(['standard_fund' => 168_000, 'agreed_fund' => 168_000, 'unworked' => $vacation])],
            2 => [$person(['standard_fund' => 160_000, 'agreed_fund' => 160_000, 'unworked' => ['unworked_total_millihours' => 0]])],
        ] as $month => $people) {
            $files[] = [
                'name' => "jmhz-{$month}.xml",
                'content_base64' => base64_encode(JmhzReportFixtures::report($people, 2026, $month, ['guid_seed' => 60 + $month])),
            ];
        }
        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        $keys = array_values(array_map(
            static fn (array $record): string => $record['key'],
            array_filter($preview['records'], static fn (array $record): bool => $record['selectable']),
        ));
        $pdo = $this->db->pdo();
        $pdo->exec('SET @payroll_import_abort = 1');

        try {
            $result = $this->imports->apply(
                $this->supplierId, 'test', $files, $keys, true, null, null, null, 'jmhz-takeover-abort-test',
                null, false, false, false, false, true,
            );
            self::fail('Odvolání převzetí musí dostat vlastník transakce: '
                . json_encode($result['takeover'] ?? null, JSON_UNESCAPED_UNICODE));
        } catch (\PDOException $e) {
            self::assertSame(1213, (int) $e->errorInfo[1]);
        }
        self::assertSame(0, (int) $pdo->query('SELECT @payroll_import_abort')->fetchColumn(), 'Odvolání se musí opravdu vyvolat.');
        self::assertFalse($pdo->inTransaction());
    }
}
