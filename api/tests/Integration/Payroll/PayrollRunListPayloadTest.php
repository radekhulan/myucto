<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `GET /payroll/runs` nesmí do paměti načíst celou mzdovou historii firmy.
 *
 * ── Co se opravovalo ────────────────────────────────────────────────────────────
 * `PayrollRunRepository::list()` selektovala `revision.input_snapshot_json`
 * i `revision.result_snapshot_json` (oba LONGTEXT) pro VŠECHNY běhy firmy a oba
 * `json_decode`ovala. Filtr na období byl přitom volitelný, takže volání bez
 * parametru přečetlo úplně všechno — u firmy se stovkou zaměstnanců a pár lety
 * provozu to je pád na `memory_limit`, ne jen pomalý dotaz.
 *
 * Ze vstupního snapshotu se přitom používal JEDINÝ boolean
 * (`payment_materialization_supported`) a z výsledkového tři částky v `totals`.
 *
 * ── Co tenhle test hlídá ────────────────────────────────────────────────────────
 *  1. seznam vrací `totals`, ale NE osobní rozpad `people` (to je ta objemná část);
 *  2. `payment_materialization_supported` počítá nově SQL nad snapshotem a musí
 *     dávat tytéž odpovědi jako původní PHP cyklus — včetně osoby, které chybí
 *     `payout_accounts`, a včetně starší verze schématu;
 *  3. seznam stránkuje a strop nejde obejít parametrem z URL;
 *  4. detail běhu nese souhrn výsledku bez osob (ty stránkuje přehled osob běhu);
 *  5. souhrn uložený u běhu seznam použije jen pro revizi, ke které patří.
 */
#[Group('integration')]
final class PayrollRunListPayloadTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRunRepository $runs;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->runs = $container->get(PayrollRunRepository::class);
        $this->db->pdo()->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($this->db->pdo(), 1);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testListShipsTotalsButNotThePerPersonBreakdown(): void
    {
        $runId = $this->seedRun('2030-01-01', $this->inputSnapshot(withPayoutAccounts: true), [
            'totals' => [
                'cash_payable_minor' => 123_456,
                'enforcement_withheld_minor' => 7_800,
                'payable_after_enforcement_minor' => 115_656,
            ],
            'people' => [
                ['employee_id' => 1, 'net_minor' => 115_656],
            ],
        ]);

        $page = $this->runs->list($this->supplierId);
        self::assertSame(1, $page['total']);
        $run = $page['items'][0];

        self::assertSame(123_456, $run['result_snapshot']['totals']['cash_payable_minor']);
        self::assertSame(7_800, $run['result_snapshot']['totals']['enforcement_withheld_minor']);
        self::assertSame(115_656, $run['result_snapshot']['totals']['payable_after_enforcement_minor']);
        self::assertArrayNotHasKey(
            'people',
            $run['result_snapshot'],
            'Osobní rozpad je ta objemná část — do seznamu nepatří.',
        );

        // Snapshoty se v seznamu vůbec nesmí objevit ani v surové podobě.
        self::assertArrayNotHasKey('input_snapshot_json', $run);
        self::assertArrayNotHasKey('result_snapshot_json', $run);
        self::assertArrayNotHasKey('input_snapshot', $run);

        // Ani detail běhu výsledky osob nenese: u 150 lidí z nich bylo 2,8 MB
        // na jedno rozkliknutí. Zůstává souhrn a počet osob, osoby stránkuje
        // PayrollRunPeopleRepository (viz PayrollRunPeopleApiTest).
        $detail = $this->runs->detail($this->supplierId, $runId);
        self::assertNotNull($detail);
        self::assertArrayNotHasKey('people', $detail['result_snapshot']);
        self::assertSame(123_456, $detail['result_snapshot']['totals']['cash_payable_minor']);
        self::assertSame(2, $detail['result_snapshot']['person_count']);
        self::assertTrue($detail['payment_materialization_supported']);
        self::assertArrayNotHasKey('result_snapshot_json', $detail);
        self::assertArrayNotHasKey('result_summary_json', $detail);
        self::assertArrayNotHasKey(
            'input_snapshot',
            $detail,
            'Vstupní snapshot nese osobní údaje všech zaměstnanců a žádná obrazovka ho nezobrazuje.',
        );
    }

    /**
     * Souhrn uložený u běhu (sloupce `summary_*`) seznam použije místo čtení
     * megabajtového snímku, ale jen patří-li aktuální revizi. Cizí nebo
     * chybějící souhrn se ignoruje a čte se ze snímku.
     */
    public function testListReadsStoredSummaryOnlyWhenItBelongsToTheCurrentRevision(): void
    {
        $runId = $this->seedRun('2030-07-01', $this->inputSnapshot(withPayoutAccounts: false), [
            'totals' => [
                'cash_payable_minor' => 100,
                'enforcement_withheld_minor' => 20,
                'payable_after_enforcement_minor' => 80,
            ],
        ]);
        $pdo = $this->db->pdo();
        $revisionId = (int) $pdo->query(
            'SELECT id FROM payroll_run_revisions WHERE run_id = ' . $runId,
        )->fetchColumn();
        $store = $pdo->prepare(
            'UPDATE payroll_runs
                SET summary_revision_id = ?, summary_person_count = 7,
                    summary_payment_materialization = 1,
                    summary_cash_payable_minor = 900,
                    summary_enforcement_withheld_minor = 50,
                    summary_payable_after_enforcement_minor = 850
              WHERE id = ?',
        );

        // Souhrn patří aktuální revizi: čísla jdou ze sloupců, snímek se nečte.
        $store->execute([$revisionId, $runId]);
        $run = $this->runs->list($this->supplierId)['items'][0];
        self::assertSame(
            ['cash_payable_minor' => 900, 'enforcement_withheld_minor' => 50, 'payable_after_enforcement_minor' => 850],
            $run['result_snapshot']['totals'],
        );
        self::assertTrue($run['payment_materialization_supported']);
        self::assertSame(7, $this->runs->detail($this->supplierId, $runId)['result_snapshot']['person_count']);
        foreach (array_keys($run) as $key) {
            self::assertStringStartsNotWith('summary_', (string) $key, 'Uložený souhrn je vnitřní údaj.');
        }

        // Souhrn jiné revize: platí snímek aktuální revize.
        $store->execute([$revisionId + 1_000_000, $runId]);
        $run = $this->runs->list($this->supplierId)['items'][0];
        self::assertSame(
            ['cash_payable_minor' => 100, 'enforcement_withheld_minor' => 20, 'payable_after_enforcement_minor' => 80],
            $run['result_snapshot']['totals'],
        );
        self::assertFalse($run['payment_materialization_supported']);
        self::assertSame(2, $this->runs->detail($this->supplierId, $runId)['result_snapshot']['person_count']);
    }

    /** Revize bez spočítaného výsledku nemá `result_snapshot` vůbec, ne prázdné totals. */
    public function testRunWithoutResultHasNullSnapshot(): void
    {
        $this->seedRun('2030-02-01', $this->inputSnapshot(withPayoutAccounts: true), null);

        $run = $this->runs->list($this->supplierId)['items'][0];

        self::assertNull($run['result_snapshot']);
    }

    /**
     * Příznak se počítá v SQL, aby se LONGTEXT nemusel číst do PHP. Musí odpovídat
     * původnímu PHP testu: schéma v2 A KAŽDÁ osoba nese `payout_accounts`.
     */
    public function testPaymentMaterializationFlagMatchesTheSnapshotContent(): void
    {
        $this->seedRun('2030-03-01', $this->inputSnapshot(withPayoutAccounts: true), null);
        $this->seedRun('2030-04-01', $this->inputSnapshot(withPayoutAccounts: false), null);
        $this->seedRun('2030-05-01', ['schema_version' => 'payroll-run-input.v1', 'people' => []], null);
        $this->seedRun('2030-06-01', ['schema_version' => 'payroll-run-input.v2', 'people' => []], null);

        $byPeriod = [];
        foreach ($this->runs->list($this->supplierId, null, 50)['items'] as $run) {
            $byPeriod[$run['period_start']] = $run['payment_materialization_supported'];
        }

        self::assertTrue($byPeriod['2030-03-01'], 'v2 a všichni mají payout_accounts.');
        self::assertFalse($byPeriod['2030-04-01'], 'Jedné osobě payout_accounts chybí.');
        self::assertFalse($byPeriod['2030-05-01'], 'Starší verze schématu výplaty neumí.');
        self::assertTrue($byPeriod['2030-06-01'], 'Prázdný seznam osob nikomu nic nedluží.');
    }

    /** Strop stránky je tvrdý — nesmí ho zvednout parametr z požadavku. */
    public function testPaginationIsBoundedAndReportsTheTotal(): void
    {
        for ($month = 1; $month <= 5; $month++) {
            $this->seedRun(sprintf('2031-%02d-01', $month), $this->inputSnapshot(withPayoutAccounts: true), null);
        }

        $first = $this->runs->list($this->supplierId, null, 2, 0);
        self::assertSame(5, $first['total'], 'Total je počet VŠECH běhů, ne velikost stránky.');
        self::assertCount(2, $first['items']);

        $second = $this->runs->list($this->supplierId, null, 2, 2);
        self::assertCount(2, $second['items']);
        self::assertNotSame(
            $first['items'][0]['id'],
            $second['items'][0]['id'],
            'Offset musí seznam skutečně posunout.',
        );

        $overreach = $this->runs->list($this->supplierId, null, 10_000, 0);
        self::assertLessThanOrEqual(
            PayrollRunRepository::LIST_MAX_LIMIT,
            count($overreach['items']),
            'Strop nejde obejít vyšším limitem.',
        );
    }

    /** @param array<string,mixed> $inputSnapshot @param array<string,mixed>|null $resultSnapshot */
    private function seedRun(string $periodStart, array $inputSnapshot, ?array $resultSnapshot): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                 (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "draft", 1)',
        )->execute([$this->supplierId, $periodStart, $periodStart]);
        $runId = (int) $pdo->lastInsertId();

        $inputJson = json_encode($inputSnapshot, JSON_THROW_ON_ERROR);
        $resultJson = $resultSnapshot === null
            ? null
            : json_encode($resultSnapshot, JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                 (supplier_id, run_id, revision_no, status, schema_version,
                  ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                  result_snapshot_json, result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "snapshot", "payroll-run.v1", ?, ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $runId,
            hash('sha256', 'manifest'),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            $resultJson === null ? null : hash('sha256', $resultJson),
            random_bytes(32),
        ]);

        return $runId;
    }

    /** @return array<string,mixed> */
    private function inputSnapshot(bool $withPayoutAccounts): array
    {
        return [
            'schema_version' => 'payroll-run-input.v2',
            'people' => [
                ['employee_id' => 1, 'payout_accounts' => []],
                $withPayoutAccounts
                    ? ['employee_id' => 2, 'payout_accounts' => [['iban' => 'CZ0000000000000000000000']]]
                    : ['employee_id' => 2],
            ],
        ];
    }
}
