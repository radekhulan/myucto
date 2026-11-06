<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollTakeoverRunBuilder;
use MyInvoice\Service\Payroll\Run\PayrollTakeoverRunService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převzatý mzdový běh (PAM-17) a doložení jeho plateb (PAM-18).
 *
 * Testy stojí na TŘECH tvrzeních, a každé z nich šlo před opravou porušit:
 *
 *  1. převzatý běh vznikne JEN za historické období, jen z převzatých dat
 *     a je na první pohled poznat, že nevznikl výpočtem;
 *  2. workflow spočítaného běhu se ho nedotkne a on se nedotkne jeho —
 *     ani přes příkaz, ani ruční revizí, ani účetní dávkou;
 *  3. doložení plateb nezaloží v platebním ledgeru nic, takže se nedostane
 *     do salda a nevyrobí druhý účetní zápis.
 *
 * Data jsou syntetická.
 */
#[Group('integration')]
final class PayrollRunTakeoverTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const START_PERIOD = '2026-07-01';
    private const HISTORICAL = '2026-03';
    private const CALCULATED_PERIOD = '2026-08';

    private Connection $db;
    private PayrollTakeoverRunService $takeover;
    private PayrollRunCommandService $commands;
    private int $supplierId;
    private int $employeeId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        if (!$db->hasTable('payroll_takeover_runs')) {
            $this->markTestSkipped('Migrace převzatých běhů neproběhla.');
        }
        $takeover = $container->get(PayrollTakeoverRunService::class);
        $commands = $container->get(PayrollRunCommandService::class);
        if (!$takeover instanceof PayrollTakeoverRunService
            || !$commands instanceof PayrollRunCommandService
        ) {
            $this->markTestSkipped('Mzdové služby nejsou v kontejneru dostupné.');
        }
        $this->takeover = $takeover;
        $this->commands = $commands;

        $pdo = $db->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($sourceSupplierId <= 0 || $userId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma nebo uživatel.');
        }
        $this->userId = $userId;

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT INTO payroll_module_state (supplier_id, status, start_period)
             VALUES (?, 'active', ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status),
                                     start_period = VALUES(start_period)",
        )->execute([$this->supplierId, self::START_PERIOD]);
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name) VALUES (?, ?)',
        )->execute([$this->supplierId, 'Testovací Zaměstnanec']);
        $this->employeeId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testTakeoverRunIsBuiltFromTakeoverDataAndIsRecognisable(): void
    {
        $this->seedTakeoverMonth(self::HISTORICAL, '2026-04-10');

        $result = $this->takeover->build(
            $this->supplierId,
            self::HISTORICAL,
            $this->userId,
        );

        self::assertSame('takeover', $result['run']['run_kind']);
        self::assertSame('closed', $result['run']['status']);
        self::assertSame(0, (int) $result['run']['current_revision_no']);
        self::assertSame(
            PayrollTakeoverRunBuilder::RESULT_SCHEMA,
            $result['takeover']['result_snapshot']['schema_reference'],
        );
        self::assertFalse($result['takeover']['result_snapshot']['calculated']);
        self::assertSame(
            26_000_00,
            $result['takeover']['result_snapshot']['totals']['gross_minor'],
        );
        self::assertSame(['pamica'], $result['takeover']['sources']);

        // Revize nevznikla — a právě na ní stojí roční zúčtování, ELDP, JMHZ,
        // výplatní pásky i platební závazky.
        self::assertSame(0, $this->countRevisions((int) $result['run']['id']));
    }

    /**
     * Seznam běhů nesmí u převzatého běhu nabídnout mazání: `deleteRun()` ho
     * odmítne a uživatel by klikal na tlačítko, které vždy selže.
     */
    public function testTakeoverRunIsNotOfferedForDeletion(): void
    {
        $this->seedTakeoverMonth(self::HISTORICAL, '2026-04-10');
        $runId = (int) $this->takeover->build(
            $this->supplierId,
            self::HISTORICAL,
            $this->userId,
        )['run']['id'];

        $runs = new \MyInvoice\Repository\Payroll\PayrollRunRepository($this->db);
        $decision = $runs->canDelete($this->supplierId, $runId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);

        $this->takeover->discard($this->supplierId, $runId, 'Test.', $this->userId);
        $afterDiscard = $runs->canDelete($this->supplierId, $runId);
        self::assertNotNull($afterDiscard);
        self::assertFalse($afterDiscard->canDelete);
    }

    public function testTakeoverRunCannotBeBuiltForAPeriodMyuctoCalculates(): void
    {
        $this->seedTakeoverMonth(self::CALCULATED_PERIOD, null);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/předchází aktivaci|vede MyÚčto/u');
        $this->takeover->build(
            $this->supplierId,
            self::CALCULATED_PERIOD,
            $this->userId,
        );
    }

    public function testTakeoverRunCannotBeBuiltWithoutTakeoverData(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/nejsou převzaté mzdy/u');
        $this->takeover->build($this->supplierId, '2026-02', $this->userId);
    }

    public function testWorkflowCommandsRefuseATakeoverRun(): void
    {
        $this->seedTakeoverMonth(self::HISTORICAL, '2026-04-10');
        $run = $this->takeover->build(
            $this->supplierId,
            self::HISTORICAL,
            $this->userId,
        )['run'];

        $this->expectException(\DomainException::class);
        // Bez brány na `run_kind` by tenhle příkaz skončil hláškou o období
        // před aktivací modulu, tedy jinou chybou z jiného důvodu.
        $this->expectExceptionMessageMatches('/Převzatý mzdový běh se neřídí/u');
        $this->commands->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'takeover-guard-test-key',
            $this->userId,
        );
    }

    public function testDatabaseRefusesARevisionOverATakeoverRun(): void
    {
        $this->seedTakeoverMonth(self::HISTORICAL, '2026-04-10');
        $runId = (int) $this->takeover->build(
            $this->supplierId,
            self::HISTORICAL,
            $this->userId,
        )['run']['id'];

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/cannot have a calculated revision/');
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash,
                 input_snapshot_json, input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, 'regular', 'snapshot', 'v2', ?, '{}', ?, ?)",
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            random_bytes(32),
        ]);
    }

    public function testDatabaseRefusesAPostingBatchOverATakeoverRun(): void
    {
        $this->seedTakeoverMonth(self::HISTORICAL, '2026-04-10');
        $runId = (int) $this->takeover->build(
            $this->supplierId,
            self::HISTORICAL,
            $this->userId,
        )['run']['id'];

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/must not be posted again/');
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_posting_batches
                (supplier_id, run_id, revision_id, entry_date, status,
                 target_hash, delta_hash)
             VALUES (?, ?, 0, '2026-03-31', 'prepared', ?, ?)",
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('c', 64),
            str_repeat('d', 64),
        ]);
    }

    public function testPaymentEvidenceStaysOutOfThePaymentLedger(): void
    {
        $this->seedTakeoverMonth(self::HISTORICAL, '2026-04-10');
        $result = $this->takeover->build(
            $this->supplierId,
            self::HISTORICAL,
            $this->userId,
        );
        $evidence = [];
        foreach ($result['payment_evidence'] as $row) {
            $evidence[$row['evidence_kind']] = $row;
        }

        // Čistá mzda: částka i datum pocházejí ze zdroje, takže je to doložení.
        self::assertSame('reported', $evidence['net_wage']['certainty']);
        self::assertSame('2026-04-10', $evidence['net_wage']['paid_on']);
        self::assertSame(18_000_00, $evidence['net_wage']['amount_minor']);
        self::assertSame($this->employeeId, $evidence['net_wage']['employee_id']);

        // Odvod nemá ve zdroji ani datum, ani příjemce — je odvozený a datum
        // se u něj nevymýšlí (databáze ho u `derived` ani nepřijme).
        self::assertSame('derived', $evidence['social_insurance']['certainty']);
        self::assertNull($evidence['social_insurance']['paid_on']);
        self::assertSame(
            1_690_00 + 6_448_00,
            $evidence['social_insurance']['amount_minor'],
        );
        self::assertSame('derived', $evidence['health_insurance']['certainty']);
        self::assertSame(
            1_170_00 + 2_340_00,
            $evidence['health_insurance']['amount_minor'],
        );
        // Záloha na daň a bonus zůstávají rozepsané: jejich rozdíl by byl náš
        // dopočet, ne doložení.
        self::assertSame(2_640_00, $evidence['advance_tax']['amount_minor']);
        self::assertSame(1_267_00, $evidence['tax_bonus']['amount_minor']);

        $runId = (int) $result['run']['id'];
        self::assertSame(0, $this->countPaymentLiabilities($runId));
        self::assertSame(0, $this->countPostingBatches($runId));
    }

    public function testDerivedEvidenceCannotCarryAPaymentDate(): void
    {
        $this->seedTakeoverMonth(self::HISTORICAL, '2026-04-10');
        $runId = (int) $this->takeover->build(
            $this->supplierId,
            self::HISTORICAL,
            $this->userId,
        )['run']['id'];

        // `withholding_tax` je v tomhle měsíci nula, takže řádek neexistuje —
        // vložení tedy spadne na kontrole data, ne na duplicitě.
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/chk_payroll_takeover_payment_date|CONSTRAINT/i');
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_takeover_payment_evidence
                (supplier_id, run_id, evidence_kind, certainty, amount_minor, paid_on)
             VALUES (?, ?, 'withholding_tax', 'derived', 1, '2026-04-10')",
        )->execute([$this->supplierId, $runId]);
    }

    public function testDiscardedTakeoverRunCanBeTakenAgain(): void
    {
        $this->seedTakeoverMonth(self::HISTORICAL, '2026-04-10');
        $runId = (int) $this->takeover->build(
            $this->supplierId,
            self::HISTORICAL,
            $this->userId,
        )['run']['id'];

        $this->takeover->discard(
            $this->supplierId,
            $runId,
            'Chyba v podkladech z původního programu.',
            $this->userId,
        );
        $status = $this->db->pdo()->prepare(
            'SELECT status FROM payroll_runs WHERE supplier_id = ? AND id = ?',
        );
        $status->execute([$this->supplierId, $runId]);
        self::assertSame('cancelled', (string) $status->fetchColumn());
        self::assertSame(0, $this->countEvidence($runId));

        $again = $this->takeover->build(
            $this->supplierId,
            self::HISTORICAL,
            $this->userId,
        );
        // Týž obal běhu, tedy žádný duplicitní měsíc.
        self::assertSame($runId, (int) $again['run']['id']);
        self::assertSame('closed', $again['run']['status']);
    }

    /**
     * REGRESE: běžný běh se chová přesně jako dosud.
     *
     * Kdyby brána na `run_kind` zahradila i spočítaný běh, padne tenhle test —
     * a je to ta chyba, která by z převzatých běhů udělala drahou funkci.
     */
    public function testCalculatedRunIsUnaffected(): void
    {
        $run = $this->commands->createRun(
            $this->supplierId,
            self::CALCULATED_PERIOD . '-01',
            '2026-09-10',
            null,
            $this->userId,
        );

        self::assertSame('calculated', $run['run_kind']);
        self::assertSame('draft', $run['status']);

        /*
         * Příkaz workflow projde branou na druh běhu a pokračuje dál, kam má.
         * Jestli se pak zamknutí vstupů povede, závisí na tom, co má firma
         * nastavené (izolovaná testovací firma nemá mzdovou politiku) — a to
         * je chování TÉHOŽ kódu jako před opravou. Tvrdé je tu jediné: brána
         * převzatého běhu se u spočítaného běhu neozve.
         */
        try {
            $result = $this->commands->lockInputs(
                $this->supplierId,
                (int) $run['id'],
                (int) $run['row_version'],
                'calculated-regression-key',
                $this->userId,
            );
            self::assertSame('inputs_locked', $result->to->value);
        } catch (\DomainException $exception) {
            self::assertStringNotContainsString(
                'Převzatý mzdový běh',
                $exception->getMessage(),
            );
            self::assertStringContainsString(
                'mzdová politika',
                $exception->getMessage(),
            );
        }
    }

    private function seedTakeoverMonth(string $period, ?string $payoutDate): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_migration_reference_totals
                (supplier_id, source, period_start, external_person_ref,
                 external_relationship_ref, employee_id,
                 gross_minor, net_minor, deductions_minor, net_payable_minor,
                 social_base_minor, health_base_minor,
                 employee_social_minor, employee_health_minor,
                 employer_social_minor, employer_health_minor,
                 advance_tax_minor, withholding_tax_minor, tax_bonus_minor,
                 payout_date)
             VALUES (?, 'pamica', ?, 'P-1', 'R-1', ?,
                     2600000, 1850000, 50000, 1800000,
                     2600000, 2600000,
                     169000, 117000,
                     644800, 234000,
                     264000, 0, 126700,
                     ?)",
        )->execute([
            $this->supplierId,
            $period . '-01',
            $this->employeeId,
            $payoutDate,
        ]);
    }

    private function countRevisions(int $runId): int
    {
        return $this->countIn(
            'SELECT COUNT(*) FROM payroll_run_revisions WHERE supplier_id = ? AND run_id = ?',
            $runId,
        );
    }

    private function countPostingBatches(int $runId): int
    {
        return $this->countIn(
            'SELECT COUNT(*) FROM payroll_posting_batches WHERE supplier_id = ? AND run_id = ?',
            $runId,
        );
    }

    private function countEvidence(int $runId): int
    {
        return $this->countIn(
            'SELECT COUNT(*) FROM payroll_takeover_payment_evidence
              WHERE supplier_id = ? AND run_id = ?',
            $runId,
        );
    }

    private function countPaymentLiabilities(int $runId): int
    {
        return $this->countIn(
            'SELECT COUNT(*)
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
              WHERE liability.supplier_id = ? AND revision.run_id = ?',
            $runId,
        );
    }

    private function countIn(string $sql, int $runId): int
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute([$this->supplierId, $runId]);

        return (int) $statement->fetchColumn();
    }
}
