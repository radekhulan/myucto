<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImportJobService;
use MyInvoice\Service\Migration\Pohoda\PohodaUploads;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayrollTwoYears as Payroll;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod mezd z PAMICA za víc roků v jedné úloze průvodce. Exportní nástroj dává každý
 * rok do vlastní složky, takže převod staršího roku o mzdách novějšího neví.
 *
 * Firma bez začátku vedení mezd: dřív ho převod roku 2025 nastavil za únor 2025 a převod
 * roku 2026 v téže úloze skončil chybou `payroll_start_decision_required` (začátek před
 * měsíci, které PAMICA zpracovala), přestože náhled žádnou volbu nenabídl. Úloha teď
 * nastaví začátek za poslední měsíc posledního vybraného roku. Firma, která začátek
 * měla už před úlohou, se chová jako dřív: bez rozhodnutí ostrý převod odmítne.
 *
 * Syntetická data, izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class PohodaPayrollYearsJobStartTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ImportJobRepository $jobs;
    private PohodaImportJobService $service;
    private PohodaPayrollImporter $importer;
    private int $userId = 0;
    private int $sourceSupplierId = 0;
    /** @var list<array{int,string}> firma, token */
    private array $uploads = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->jobs = $container->get(ImportJobRepository::class);
        $this->service = $container->get(PohodaImportJobService::class);
        $this->importer = $container->get(PohodaPayrollImporter::class);
        foreach (['payroll_attendance_imports', 'payroll_employees', 'payroll_module_state', 'pohoda_import_map', 'payroll_migration_reference_totals'] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $this->sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($this->sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        foreach ($this->uploads as [$supplierId, $token]) {
            PohodaUploads::purge($supplierId, $token);
            @rmdir(PohodaUploads::base($supplierId));
        }
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testJobWithBothYearsSetsStartAfterLastYearWithoutDecision(): void
    {
        $supplierId = $this->supplierWithoutStart();
        $token = $this->upload($supplierId);

        $jobId = $this->jobs->create($supplierId, PohodaImportJobService::SOURCE, [
            'token' => $token, 'mode' => 'import', 'ico' => Payroll::ICO, 'kind' => 'payroll', 'years' => [2025, 2026],
        ], $this->userId);
        $this->service->run($jobId);

        $runs = $this->runs($jobId);
        self::assertSame([2025, 2026], array_column($runs, 'year'), $this->explainJob($jobId));
        foreach ($runs as $run) {
            self::assertContains($run['status'], ['completed', 'completed_with_warnings'], $this->explainJob($jobId));
            self::assertNotContains('payroll_start_decision_required', $this->codes($run['id']), $this->explainJob($jobId));
        }
        self::assertSame('2026-03-01', $this->startPeriod($supplierId), $this->explainJob($jobId));
        // Obě léta převzatá jako zpracovaná předchozím programem.
        foreach (['2025-01-01', '2025-02-01', '2026-01-01', '2026-02-01'] as $period) {
            self::assertGreaterThan(0, $this->scalar(
                'SELECT COUNT(*) FROM payroll_migration_reference_totals WHERE supplier_id = ? AND period_start = ?',
                [$supplierId, $period],
            ), $period . ' ' . $this->explainJob($jobId));
        }
        self::assertGreaterThan(0, $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?', [$supplierId]));
    }

    /** Regrese: začátek, který firma měla před úlohou, převod bez rozhodnutí neposune. */
    public function testExistingStartStillNeedsDecision(): void
    {
        $supplierId = $this->supplierWithoutStart();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at) VALUES (?, "setup", "2025-01-01", ?, NOW())',
        )->execute([$supplierId, $this->userId]);
        $token = $this->upload($supplierId);

        $refused = $this->jobs->create($supplierId, PohodaImportJobService::SOURCE, [
            'token' => $token, 'mode' => 'import', 'ico' => Payroll::ICO, 'kind' => 'payroll', 'years' => [2025, 2026],
        ], $this->userId);
        $this->service->run($refused);
        $runs = $this->runs($refused);
        self::assertSame([[2025, 'failed']], array_map(static fn (array $r): array => [$r['year'], $r['status']], $runs), $this->explainJob($refused));
        self::assertContains('payroll_start_decision_required', $this->codes($runs[0]['id']));
        self::assertSame('2025-01-01', $this->startPeriod($supplierId));

        $advanced = $this->jobs->create($supplierId, PohodaImportJobService::SOURCE, [
            'token' => $token, 'mode' => 'import', 'ico' => Payroll::ICO, 'kind' => 'payroll', 'years' => [2025, 2026],
            'start_decision' => PohodaPayrollImporter::START_ADVANCE,
        ], $this->userId);
        $this->service->run($advanced);
        foreach ($this->runs($advanced) as $run) {
            self::assertContains($run['status'], ['completed', 'completed_with_warnings'], $this->explainJob($advanced));
        }
        self::assertSame('2026-03-01', $this->startPeriod($supplierId));
    }

    /** Náhled roku 2025 sám ukáže začátek za svým rokem; s posledním měsícem úlohy za ní. */
    public function testPreflightAnnouncesStartFromLastPeriodOfJob(): void
    {
        $supplierId = $this->supplierWithoutStart();
        $dir = PohodaUploads::exportDir($supplierId, $this->upload($supplierId)) . DIRECTORY_SEPARATOR . Payroll::ICO . '_';

        $alone = $this->willSet($this->importer->preflight($supplierId, $dir . '2025' . DIRECTORY_SEPARATOR . PohodaExport::FILES['payroll'], 2025));
        self::assertSame('2025-03', $alone['start_period'] ?? null);
        $job = $this->willSet($this->importer->preflight($supplierId, $dir . '2025' . DIRECTORY_SEPARATOR . PohodaExport::FILES['payroll'], 2025, null, '2026-02'));
        self::assertSame('2026-03', $job['start_period'] ?? null);
        self::assertSame('2026-03', $this->willSet($this->importer->preflight($supplierId, $dir . '2026' . DIRECTORY_SEPARATOR . PohodaExport::FILES['payroll'], 2026))['start_period'] ?? null);
    }

    /**
     * @param list<array{level:string,code:string,message:string,context:array<string,mixed>}> $preflight
     * @return array<string,mixed>|null
     */
    private function willSet(array $preflight): ?array
    {
        foreach ($preflight as $m) {
            if ($m['code'] === PohodaPayrollImporter::START_WILL_SET) {
                return $m['context'];
            }
        }
        return null;
    }

    private function supplierWithoutStart(): int
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1, ic = ? WHERE id = ?')->execute([Payroll::ICO, $supplierId]);
        $pdo->prepare('DELETE FROM payroll_module_state WHERE supplier_id = ?')->execute([$supplierId]);
        return $supplierId;
    }

    private function upload(int $supplierId): string
    {
        $token = PohodaUploads::newToken();
        $this->uploads[] = [$supplierId, $token];
        $root = PohodaUploads::exportDir($supplierId, $token);
        Payroll::writeYears($root);
        PohodaUploads::writeMeta($supplierId, $token, ['token' => $token, 'sha256' => str_repeat('0', 64), 'agendas' => PohodaExport::overview($root)]);
        return $token;
    }

    /** @return list<array{id:int,year:int,status:string}> */
    private function runs(int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, agenda_year, status FROM pohoda_imports WHERE job_id = ? ORDER BY id');
        $stmt->execute([$jobId]);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'year' => (int) $r['agenda_year'], 'status' => (string) $r['status']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return list<string> */
    private function codes(int $runId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT protocol FROM pohoda_imports WHERE id = ?');
        $stmt->execute([$runId]);
        $protocol = (array) json_decode((string) $stmt->fetchColumn(), true);
        $codes = [];
        foreach ((array) ($protocol['steps'] ?? []) as $step) {
            foreach ((array) ($step['messages'] ?? []) as $message) {
                $codes[] = (string) ($message['code'] ?? '');
            }
        }
        return $codes;
    }

    private function startPeriod(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT start_period FROM payroll_module_state WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function explainJob(int $jobId): string
    {
        $job = $this->jobs->findById($jobId);
        $out = ['log' => $job['log_text'] ?? null, 'error' => $job['last_error'] ?? null];
        foreach ($this->runs($jobId) as $run) {
            $stmt = $this->db->pdo()->prepare('SELECT protocol FROM pohoda_imports WHERE id = ?');
            $stmt->execute([$run['id']]);
            $protocol = (array) json_decode((string) $stmt->fetchColumn(), true);
            $out[$run['year']] = array_map(static fn (array $s): array => ['key' => $s['key'] ?? '', 'status' => $s['status'] ?? '',
                'messages' => array_map(static fn (array $m): string => ($m['level'] ?? '') . ' ' . ($m['code'] ?? '') . ': ' . ($m['text'] ?? ''), (array) ($s['messages'] ?? []))],
                (array) ($protocol['steps'] ?? []));
        }
        return (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
