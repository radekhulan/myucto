<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRegistrationCompletionRepository;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dohlášení údajů (A3) u převzaté firmy: registraci, kterou podal předchozí
 * program, ani dohlášení s lhůtou před začátkem vedení mezd v MyÚčtu seznam
 * nesmí hlásit jako nesplněné. Dřív ukazoval stovky vztahů „Nepodáno".
 */
#[Group('integration')]
final class PayrollRegistrationCompletionPredecessorTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRegistrationCompletionRepository $repository;
    private JmhzExternalSubmissionStore $store;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        foreach (['payroll_external_jmhz_submissions', 'payroll_module_state', 'payroll_employment_external_ids'] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $this->repository = $container->get(PayrollRegistrationCompletionRepository::class);
        $this->store = $container->get(JmhzExternalSubmissionStore::class);
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($source === 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testTakenOverFirmReadsCompletionAsHandledByPredecessor(): void
    {
        $this->startPeriod('2026-06-01');
        [$registeredEmployee, $registered] = $this->employment('Syntetická dohlášená osoba', '2025-03-01');
        $onzOnly = $this->employment('Syntetická osoba z ONZ', '2024-01-01')[1];
        $hiredLater = $this->employment('Syntetická osoba nastoupivší v dubnu', '2026-04-15')[1];
        $hiredInMyUcto = $this->employment('Syntetická osoba nastoupivší v MyÚčtu', '2026-07-01')[1];

        $submission = $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_PAMICA, [
            'source_key' => 'RegZAM:1',
            'document_kind' => 'registration',
            'period' => null,
            'submission_type' => null,
            'submission_guid' => null,
            'corrected_source_key' => null,
            'status' => JmhzExternalSubmissionStore::STATUS_SENT,
            'filled_at' => '2026-04-29T10:00:00',
            'submitted_at' => '2026-04-29T10:00:00',
            'accepted_at' => null,
            'program' => 'PAMICA',
            'file_name' => null,
            'payload' => ['program' => 'PAMICA', 'kind' => 'registration', 'header' => []],
        ], [[
            'position' => 1,
            'form_guid' => null,
            'form_type' => 'existing',
            'source_relation_ref' => '1',
            'employee_id' => $registeredEmployee,
            'employment_id' => $registered,
            'payload' => ['item' => [], 'attributes' => [['id' => 10009, 'order' => 0, 'value' => '2026-04-29']]],
        ]], null);

        $rows = array_column($this->repository->candidates($this->supplierId, 'production'), null, 'employment_id');

        self::assertSame('registration', $rows[$registered]['predecessor_reason']);
        self::assertSame($submission['id'], $rows[$registered]['predecessor_submission_id']);
        self::assertSame('A3', $rows[$registered]['predecessor_action']);
        self::assertSame('PAMICA', $rows[$registered]['predecessor_program']);
        self::assertSame('deadline', $rows[$onzOnly]['predecessor_reason'], 'Lhůta 30. 4. 2026 uplynula před začátkem vedení mezd.');
        self::assertSame('deadline', $rows[$hiredLater]['predecessor_reason'], 'Přihlášku dubnového nástupu podal předchozí program.');
        self::assertNull($rows[$hiredInMyUcto]['predecessor_reason']);
        $test = array_column($this->repository->candidates($this->supplierId, 'test'), null, 'employment_id');
        self::assertSame('deadline', $test[$registered]['predecessor_reason'], 'Registrace z produkce se do testovacího prostředí nepočítá.');
    }

    public function testFirmStartingBeforeTheDeadlineKeepsTheObligation(): void
    {
        $this->startPeriod('2026-04-01');
        $onz = $this->employment('Syntetická osoba z ONZ', '2024-01-01')[1];
        $rows = array_column($this->repository->candidates($this->supplierId, 'production'), null, 'employment_id');
        self::assertNull($rows[$onz]['predecessor_reason'], 'Lhůta dohlášení běží za MyÚčta.');
    }

    private function startPeriod(string $period): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, 'setup', ?)
             ON DUPLICATE KEY UPDATE start_period = VALUES(start_period)"
        )->execute([$this->supplierId, $period]);
    }

    /** @return array{0:int,1:int} */
    private function employment(string $name, string $start): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, ?, "employee", 1)'
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, is_primary, start_date, actual_start_date)
             VALUES (?, ?, ?, "employment", "active", 0, ?, ?)'
        )->execute([$this->supplierId, $employeeId, 'P' . $employeeId, $start, $start]);
        $employmentId = (int) $pdo->lastInsertId();
        foreach (['production', 'test'] as $environment) {
            $pdo->prepare(
                "INSERT INTO payroll_employment_external_ids
                    (supplier_id, employee_id, employment_id, environment,
                     identifier_type, value_ciphertext, value_hash, value_masked,
                     valid_from, source_kind, source_reference_hash)
                 VALUES (?, ?, ?, ?, 'id_ppv', 'enc:v2:test', UNHEX(SHA2(?, 256)),
                         '***123', '2026-01-01', 'verified_manual_import', ?)"
            )->execute([$this->supplierId, $employeeId, $employmentId, $environment, "id-ppv-{$environment}-{$employmentId}", str_repeat('a', 64)]);
        }

        return [$employeeId, $employmentId];
    }
}
