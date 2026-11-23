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
 * Zápis formuláře JMHZ a věty registrace, kterou MariaDB odvolá deadlockem (1213).
 *
 * MariaDB po 1020/1213 vrátí celou transakci, `PDO::inTransaction()` ale dál hlásí
 * true. Doplňková skupina, která chybu spolkne do poznámky, by zbytek formuláře
 * (slevy, děti) zapsala v autocommitu bez prohlášení a identifikátorů. Odvolání se tu
 * vyvolá triggerem se SIGNAL `40001`/1213 na tabulce jedné skupiny, jen v session
 * testu a jen tolikrát, kolik určí proměnná `@payroll_import_abort`.
 *
 * Zápis musí transakci vlastnit, aby ji mohl zopakovat, takže data izolované firmy
 * se commitují. Mzdové tabulky jsou neměnné a úklid po testu nejde, firma je syntetická.
 */
#[Group('integration')]
final class PayrollImportTransactionAbortTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const TRIGGERS = [
        'trg_test_abort_tax_declarations_insert' => 'BEFORE INSERT ON payroll_person_tax_declarations',
        'trg_test_abort_tax_declarations_update' => 'BEFORE UPDATE ON payroll_person_tax_declarations',
        'trg_test_abort_person_external_ids_insert' => 'BEFORE INSERT ON payroll_person_external_ids',
    ];

    private Connection $db;
    private RegistrationImportService $imports;
    private int $supplierId;
    private string $oic;
    private string $idPpv = '200000000000000000101';
    private string $abortTable = '';

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
        $this->dropTriggers();

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier SET payroll_enabled = 1, accounting_mode = 'double_entry' WHERE id = ?"
        )->execute([$this->supplierId]);
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
        $pdo->commit();
        $this->oic = RegistrationXmlFixtures::oic(7);
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
        $this->dropTriggers();
        $this->db->close();
    }

    public function testJmhzFormRetriedAfterDeadlockIsWrittenWhole(): void
    {
        [$employeeId] = $this->registerEmployee();
        $this->abortOn('payroll_person_tax_declarations');

        $result = $this->applyJmhz(1);

        self::assertSame(0, $this->remainingAborts(), 'Odvolání se musí opravdu vyvolat.');
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertNull($result['message'], 'Opakovaný formulář nesmí hlásit nezapsanou skupinu.');
        foreach (['tax_declaration', 'tax_credit_claims', 'dependants'] as $operation) {
            self::assertContains($operation, $result['operations']);
        }
        self::assertCount(count(array_unique($result['operations'])), $result['operations']);
        self::assertSame(1, $this->rows('payroll_person_tax_declarations', $employeeId, "status = 'signed'"));
        self::assertSame(1, $this->rows('payroll_dependants', $employeeId));
        self::assertSame(1, $this->rows('payroll_person_tax_child_claims', $employeeId));
    }

    public function testJmhzFormDeadlockedEveryTimeLeavesNoCreditsNorChildren(): void
    {
        [$employeeId] = $this->registerEmployee();
        $credits = $this->rows('payroll_person_tax_credit_claims', $employeeId);
        $this->abortOn('payroll_person_tax_declarations', 10);

        $result = $this->applyJmhz(1);

        self::assertSame('failed', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('Deadlock', (string) $result['message']);
        self::assertFalse($this->db->pdo()->inTransaction());
        self::assertSame($credits, $this->rows('payroll_person_tax_credit_claims', $employeeId), 'Slevy se bez prohlášení nesmí zapsat.');
        self::assertSame(0, $this->rows('payroll_dependants', $employeeId), 'Děti se bez prohlášení nesmí zapsat.');
        self::assertSame(0, $this->rows('payroll_person_tax_child_claims', $employeeId));
        self::assertSame(0, $this->rows('payroll_person_tax_declarations', $employeeId, "status = 'signed'"));
    }

    public function testJmhzDeadlockInsideCallerTransactionReachesCaller(): void
    {
        [$employeeId] = $this->registerEmployee();
        $files = [$this->jmhzFile(1)];
        $keys = array_column($this->imports->preview($this->supplierId, 'test', $files)['records'], 'key');
        $this->abortOn('payroll_person_tax_declarations');
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $this->apply($files, $keys);
            self::fail('Odvolání uvnitř cizí transakce musí dostat její vlastník.');
        } catch (\PDOException $e) {
            self::assertSame(1213, (int) $e->errorInfo[1]);
        }
        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, $this->rows('payroll_dependants', $employeeId));
        self::assertSame(0, $this->rows('payroll_person_tax_child_claims', $employeeId));
    }

    public function testRegistrationRetriedAfterDeadlockCreatesPersonOnce(): void
    {
        $this->abortOn('payroll_person_external_ids');

        $result = $this->applyRegistration();

        self::assertSame(0, $this->remainingAborts(), 'Odvolání se musí opravdu vyvolat.');
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertNull($result['message'], 'Opakovaná věta nesmí hlásit nezapsanou skupinu.');
        self::assertContains('person_created', $result['operations']);
        self::assertContains('identifiers', $result['operations']);
        self::assertCount(count(array_unique($result['operations'])), $result['operations']);
        self::assertSame(1, $this->supplierRows('payroll_employees'));
        self::assertSame((int) $result['employee_id'], $this->singleEmployeeId());
    }

    public function testRegistrationDeadlockedEveryTimeLeavesNoPerson(): void
    {
        $this->abortOn('payroll_person_external_ids', 10);

        $result = $this->applyRegistration();

        self::assertSame('failed', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('Deadlock', (string) $result['message']);
        self::assertFalse($this->db->pdo()->inTransaction());
        self::assertSame(0, $this->supplierRows('payroll_employees'), 'Osoba bez identifikátorů se nesmí zapsat.');
    }

    /** @return array{0:int,1:int} */
    private function registerEmployee(): array
    {
        $result = $this->applyRegistration();
        self::assertSame('applied', $result['status'], (string) $result['message']);

        return [(int) $result['employee_id'], (int) $result['employment_id']];
    }

    /** @return array<string,mixed> */
    private function applyRegistration(): array
    {
        $files = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1([
            'bno' => RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1),
            'start' => '2026-01-01',
            'ikmpsv' => $this->oic,
            'oid' => $this->idPpv,
        ]))];
        $key = $this->imports->preview($this->supplierId, 'test', $files)['records'][0]['key'];

        return $this->apply($files, [$key])['results'][0];
    }

    /** @return array<string,mixed> */
    private function applyJmhz(int $month): array
    {
        $files = [$this->jmhzFile($month)];
        $keys = array_column($this->imports->preview($this->supplierId, 'test', $files)['records'], 'key');

        return $this->apply($files, $keys)['results'][0];
    }

    /** @return array{name:string,content_base64:string} */
    private function jmhzFile(int $month): array
    {
        return $this->file("jmhz-{$month}.xml", JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => $this->idPpv,
        ])], 2026, $month));
    }

    /**
     * @param list<array{name:string,content_base64:string}> $files
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    private function apply(array $files, array $keys): array
    {
        return $this->imports->apply($this->supplierId, 'test', $files, $keys, true, null, null, null, 'payroll-import-abort-test');
    }

    private function abortOn(string $table, int $times = 1): void
    {
        $pdo = $this->db->pdo();
        if ($this->abortTable !== $table) {
            if ($pdo->inTransaction()) {
                throw new \LogicException('Trigger se zakládá před transakcí testu (DDL commituje).');
            }
            foreach (self::TRIGGERS as $name => $event) {
                if (!str_ends_with($event, ' ' . $table)) {
                    continue;
                }
                $pdo->exec(
                    "CREATE TRIGGER {$name} {$event} FOR EACH ROW
                     BEGIN
                         IF @payroll_import_abort > 0 THEN
                             SET @payroll_import_abort = @payroll_import_abort - 1;
                             SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213,
                                 MESSAGE_TEXT = 'Deadlock found when trying to get lock; try restarting transaction';
                         END IF;
                     END"
                );
            }
            $this->abortTable = $table;
        }
        $pdo->exec('SET @payroll_import_abort = ' . $times);
    }

    private function remainingAborts(): int
    {
        return (int) $this->db->pdo()->query('SELECT @payroll_import_abort')->fetchColumn();
    }

    private function dropTriggers(): void
    {
        foreach (array_keys(self::TRIGGERS) as $name) {
            $this->db->pdo()->exec("DROP TRIGGER IF EXISTS {$name}");
        }
        $this->abortTable = '';
    }

    private function rows(string $table, int $employeeId, string $where = '1 = 1'): int
    {
        $statement = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND employee_id = ? AND {$where}");
        $statement->execute([$this->supplierId, $employeeId]);

        return (int) $statement->fetchColumn();
    }

    private function supplierRows(string $table): int
    {
        $statement = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $statement->execute([$this->supplierId]);

        return (int) $statement->fetchColumn();
    }

    private function singleEmployeeId(): int
    {
        $statement = $this->db->pdo()->prepare('SELECT id FROM payroll_employees WHERE supplier_id = ?');
        $statement->execute([$this->supplierId]);

        return (int) $statement->fetchColumn();
    }

    /** @return array{name:string,content_base64:string} */
    private function file(string $name, string $content): array
    {
        return ['name' => $name, 'content_base64' => base64_encode($content)];
    }
}
