<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Invariants;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollInvariantService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vrstva L3 — INVARIANTY nad skutečným obsahem mzdového modulu.
 *
 * Účetnictví tuhle vrstvu má ({@see LedgerInvariantsTest}), mzdy do W25 neměly
 * jediné tvrzení, přestože mají přes 250 migrací, několik backfillů a v ostrých
 * datech i ruční zásahy. Právě tam scénářový test nedosáhne: ověřuje průchod,
 * který si sám vymyslel, kdežto invariant bere databázi TAK, JAK JE.
 *
 * **Pozor na vakuózní zelenou.** Izolovaná testovací DB mzdy typicky nemá, takže
 * by všechna tvrzení prošla, i kdyby byl kód rozbitý. Test to detekuje a v takovém
 * případě SKIPUJE místo aby předstíral kontrolu — ostří má tahle vrstva až nad
 * databází s obsahem: `php api/bin/check-payroll-invariants.php`, který volá TUTÉŽ
 * službu ({@see PayrollInvariantService}), aby logika nežila ve dvou kopiích
 * (test × cron) a nerozešla se.
 *
 * Aby vrstva nebyla zelená jen proto, že neumí nic najít, tři testy níž SEEDUJÍ
 * konkrétní porušení (v transakci, kterou vždycky vrátí zpět) a vyžadují, aby ho
 * příslušný invariant nahlásil.
 */
#[Group('invariants')]
final class PayrollInvariantsTest extends TestCase
{
    private const PERIOD = '2099-12-01';
    private const PAYMENT = '2100-01-31';
    private const ZERO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private Connection $db;
    private PayrollInvariantService $invariants;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — invarianty vyžadují DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->invariants = $container->get(PayrollInvariantService::class);
            // Connection navazuje spojení AŽ při prvním dotazu; bez tohohle probu by
            // nedostupná DB neskončila skipem, ale PDOException uprostřed testu.
            $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasTable('payroll_run_revisions')) {
            $this->markTestSkipped('Mzdový modul v DB není.');
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
    }

    public function testPayrollSatisfiesAllInvariants(): void
    {
        if ($this->invariants->payrollIsEmpty()) {
            self::markTestSkipped(
                'Mzdový modul je prázdný — invarianty nad daty by prošly vakuózně. '
                    . 'Pusť je proti databázi s obsahem: php api/bin/check-payroll-invariants.php',
            );
        }

        $failures = [];
        foreach ($this->invariants->checkAll() as $result) {
            foreach ($result['violations'] as $violation) {
                $failures[] = sprintf('%s (%s — %s): %s', $result['code'], $result['source'], $result['rule'], $violation);
            }
        }

        self::assertSame([], $failures, sprintf(
            "Porušené invarianty mzdového jádra:\n  %s",
            implode("\n  ", $failures),
        ));
    }

    /**
     * Pojistka proti tiché degradaci: kdyby se všechny invarianty přeskočily (chybí
     * tabulka, neproběhla migrace), sada by zezelenala a tvrdila by, že hlídá.
     */
    public function testAtLeastOneInvariantIsActuallyEvaluated(): void
    {
        $results = $this->invariants->checkAll();
        $checked = array_values(array_filter($results, static fn (array $r): bool => $r['checked']));

        self::assertNotEmpty($checked, sprintf(
            "Žádný mzdový invariant se nevyhodnotil — vrstva by tvrdila, že hlídá, a nekontrolovala nic.\nDůvody:\n  %s",
            implode("\n  ", array_map(
                static fn (array $r): string => $r['code'] . ': ' . (string) $r['skipped_reason'],
                $results,
            )),
        ));
    }

    /** Každý invariant musí být pojmenovaný a mít uvedený předpis, ze kterého plyne. */
    public function testEveryInvariantDeclaresItsLegalSource(): void
    {
        foreach ($this->invariants->checkAll() as $result) {
            self::assertNotSame('', trim($result['code']), 'Invariant bez kódu.');
            self::assertNotSame('', trim($result['rule']), $result['code'] . ': chybí formulace pravidla.');
            self::assertNotSame('', trim($result['source']), $result['code'] . ': chybí předpis, ze kterého plyne.');
        }
    }

    /**
     * M3 musí najít revizi, jejíž předchůdce patří JINÉMU běhu.
     *
     * Cizí klíč `fk_payroll_run_revision_previous` hlídá jen existenci cíle v téže
     * firmě — že jde o revizi TÉHOŽ běhu, nehlídá nic. Opravná revize by tak
     * počítala rozdíl proti mzdě jiného měsíce a čísla by přitom vypadala
     * věrohodně. Právě proto tohle tvrzení existuje.
     */
    public function testPreviousRevisionFromAnotherRunIsDetected(): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $foreignRunId = $this->seedRun($pdo, '2099-11-01', '2099-12-31');
            $foreignRevisionId = $this->seedRevision($pdo, $foreignRunId, 1, 'approved');

            $runId = $this->seedRun($pdo);
            $this->seedRevision($pdo, $runId, 1, 'approved', [
                'previous_revision_id' => $foreignRevisionId,
            ]);

            $violations = $this->violationsOf('M3');
            self::assertNotSame([], $violations, 'Odkaz do cizího běhu musí být nahlášen.');
            self::assertStringContainsString('previous_revision_id', implode("\n", $violations));
        } finally {
            $pdo->rollBack();
        }
    }

    /**
     * M4 musí najít běh se dvěma schválenými revizemi.
     *
     * Od migrace 1621 smí být schválená revize jen jedna — druhá se překlápí na
     * `superseded`. Databáze to ale nevynucuje (unikátní klíč přes stav neexistuje),
     * takže dvě platné pravdy o téže mzdě jsou možné a poznat to musí až invariant.
     */
    public function testTwoApprovedRevisionsOfOneRunAreDetected(): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $runId = $this->seedRun($pdo);
            $this->seedRevision($pdo, $runId, 1, 'approved');
            $this->seedRevision($pdo, $runId, 2, 'approved');

            $violations = $this->violationsOf('M4');
            self::assertNotSame([], $violations, 'Dvě schválené revize téhož běhu musí být nahlášeny.');
            self::assertStringContainsString('schválených revizí', implode("\n", $violations));
        } finally {
            $pdo->rollBack();
        }
    }

    /**
     * M8 musí najít revizi, jejíž zmrazený snapshot nesedí na uložený otisk.
     *
     * Otisk je jediný doklad, že se se zmrazeným podkladem po schválení nehnulo.
     * Zároveň se tím ověřuje, že se SHA-256 počítané v SQL shoduje s tím, co
     * ukládá PHP — kdyby se rozcházelo kódování, invariant by hlásil úplně všechno
     * a nikdo by ho nečetl.
     */
    public function testTamperedSnapshotHashIsDetected(): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $runId = $this->seedRun($pdo);
            $this->seedRevision($pdo, $runId, 1, 'snapshot', [
                'input_snapshot_hash' => self::ZERO_HASH,
            ]);

            $violations = $this->violationsOf('M8');
            self::assertNotSame([], $violations, 'Rozejitý otisk snapshotu musí být nahlášen.');
            self::assertStringContainsString('otisk vstupu', implode("\n", $violations));

            // A opačná větev: poctivě spočítaný otisk hlásit NESMÍ. Guard, který
            // svítí červeně vždycky, je stejně bezcenný jako ten, který mlčí.
            $this->seedRevision($pdo, $runId, 2, 'snapshot');
            $honest = array_values(array_filter(
                $this->violationsOf('M8'),
                static fn (string $v): bool => str_contains($v, 'č. 2'),
            ));
            self::assertSame([], $honest, 'Správný otisk nesmí hlásit porušení.');
        } finally {
            $pdo->rollBack();
        }
    }

    /** @return list<string> */
    private function violationsOf(string $code): array
    {
        foreach ($this->invariants->checkAll() as $result) {
            if ($result['code'] === $code) {
                self::assertTrue($result['checked'], $code . ' se nesmí přeskočit: ' . (string) $result['skipped_reason']);

                return $result['violations'];
            }
        }
        self::fail('Invariant ' . $code . ' v registru chybí.');
    }

    private function seedRun(
        PDO $pdo,
        string $period = self::PERIOD,
        string $payment = self::PAYMENT,
    ): int {
        $supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($supplierId === 0) {
            self::markTestSkipped('Chybí supplier.');
        }
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, ?, ?)'
        )->execute([$supplierId, $period, $payment]);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $overrides */
    private function seedRevision(
        PDO $pdo,
        int $runId,
        int $revisionNo,
        string $status,
        array $overrides = [],
    ): int {
        $supplierId = (int) $pdo->query(
            'SELECT supplier_id FROM payroll_runs WHERE id = ' . $runId
        )->fetchColumn();
        $json = '{"invariant_fixture":' . $revisionNo . '}';
        $row = $overrides + [
            'previous_revision_id' => null,
            'input_snapshot_json' => $json,
            'input_snapshot_hash' => hash('sha256', $json),
        ];

        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id, revision_kind,
                 status, schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, ?, ?, "regular", ?, "test", ?, ?, ?, ?, NULL)'
        )->execute([
            $supplierId,
            $runId,
            $revisionNo,
            $row['previous_revision_id'],
            $status,
            self::ZERO_HASH,
            $row['input_snapshot_json'],
            $row['input_snapshot_hash'],
            random_bytes(32),
        ]);

        return (int) $pdo->lastInsertId();
    }
}
