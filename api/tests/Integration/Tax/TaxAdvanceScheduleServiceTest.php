<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxAdvanceOverrideRepository;
use MyInvoice\Repository\TaxAdvanceScheduleRepository;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Tax\Return\TaxAdvanceScheduleService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E9 (audit 2026-07) — správa záloh na daň a pojistné. Ověřuje:
 *  - vygenerování předpisů §38a z finalizovaného DPPO se správnou periodicitou/splatností,
 *  - měsíční OSVČ zálohy (soc./zdrav.) se splatnostmi (konec měsíce / 8. den násl. měsíce),
 *  - párování odchozí bankovní platby přes VS (= DIČ) a předvyplnění do přiznání.
 *
 * Izolovaný supplier, transakce s rollbackem v tearDown, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class TaxAdvanceScheduleServiceTest extends TestCase
{
    private Connection $db;
    private TaxAdvanceScheduleService $service;
    private TaxAdvanceScheduleRepository $repo;
    private TaxAdvanceOverrideRepository $overrides;
    private TaxReturnRepository $returns;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->service = $c->get(TaxAdvanceScheduleService::class);
            $this->repo = $c->get(TaxAdvanceScheduleRepository::class);
            $this->overrides = $c->get(TaxAdvanceOverrideRepository::class);
            $this->returns = $c->get(TaxReturnRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($czId === 0 || $currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = 2100;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([2100, json_encode($constants, JSON_UNESCAPED_UNICODE)]);

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, dic, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "advtest@example.com", ?, ?, ?)'
        );
        $stmt->execute(['Zálohy test s.r.o.', $czId, 'CZ12345678', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testPoTaxAdvancesQuarterlyPeriodicityAndDueDates(): void
    {
        $result = ['next_advances' => ['regime' => 'quarterly', 'count' => 4, 'amount' => 50000.0, 'total' => 200000.0]];
        $counts = $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);
        self::assertSame(4, $counts['tax']);

        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        self::assertCount(4, $rows);
        $due = array_map(fn ($r) => $r['due_date'], $rows);
        self::assertSame(['2101-06-15', '2101-09-15', '2101-12-15', '2102-03-15'], $due);
        foreach ($rows as $r) {
            self::assertSame('tax', $r['advance_kind']);
            self::assertSame(50000.0, $r['amount']);
            self::assertSame('12345678', $r['variable_symbol']); // kmenová část DIČ
        }
    }

    public function testPoSemiannualRegime(): void
    {
        $result = ['next_advances' => ['regime' => 'semiannual', 'count' => 2, 'amount' => 24000.0, 'total' => 48000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);
        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        self::assertCount(2, $rows);
        self::assertSame(['2101-06-15', '2101-12-15'], array_map(fn ($r) => $r['due_date'], $rows));
    }

    /**
     * 15. 6. 2025 je neděle: lhůta zálohy § 38a končí podle § 33 odst. 4 daňového řádu
     * v pondělí 16. 6. Stejně pro predikci z přiznání i pro rozhodnutí finančního úřadu.
     */
    public function testPoAdvanceDueOnWeekendMovesToNextWorkingDay(): void
    {
        $result = ['next_advances' => ['regime' => 'semiannual', 'count' => 2, 'amount' => 14300.0, 'total' => 28600.0]];
        $this->service->generateFromReturn($this->supplierId, 2024, 'po', null, $result);
        self::assertSame(['2025-06-16', '2025-12-15'], array_map(fn ($r) => $r['due_date'], $this->repo->listForYear($this->supplierId, 2025, 'po')));

        $this->overrides->upsert($this->supplierId, 'po', 'tax', 2025, '2025-01-01', 20000.0, 'quarterly', null, 'fu_decision');
        $this->service->generateFromReturn($this->supplierId, 2024, 'po', null, []);
        self::assertSame(['2025-03-17', '2025-06-16', '2025-09-15', '2025-12-15'],
            array_map(fn ($r) => $r['due_date'], $this->repo->listForYear($this->supplierId, 2025, 'po')));
    }

    public function testPoExtendedFilingDeadlineMovesAdvancePeriod(): void
    {
        $quarterly = ['next_advances' => [
            'regime' => 'quarterly', 'count' => 4, 'amount' => 50000.0, 'total' => 200000.0,
            'filing_deadline' => '2101-07-01',
        ]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $quarterly);
        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        self::assertSame(
            ['2101-09-15', '2101-12-15', '2102-03-15', '2102-06-15'],
            array_map(fn ($r) => $r['due_date'], $rows)
        );

        $semiannual = ['next_advances' => [
            'regime' => 'semiannual', 'count' => 2, 'amount' => 24000.0, 'total' => 48000.0,
            'filing_deadline' => '2101-07-01',
        ]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $semiannual);
        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        self::assertSame(['2101-12-15', '2102-06-15'], array_map(fn ($r) => $r['due_date'], $rows));
    }

    public function testFoMonthlyInsuranceAdvancesDueDates(): void
    {
        $counts = $this->service->generateFromReturn($this->supplierId, 2100, 'fo', null, []);
        self::assertSame(12, $counts['social']);
        self::assertSame(12, $counts['health']);

        $rows = $this->service->listForYear($this->supplierId, 2101, 'fo');
        $social = array_values(array_filter($rows, fn ($r) => $r['advance_kind'] === 'social'));
        $health = array_values(array_filter($rows, fn ($r) => $r['advance_kind'] === 'health'));
        self::assertCount(12, $social);
        self::assertCount(12, $health);

        // Sociální: poslední den měsíce, za který se platí.
        self::assertSame('2101-01-31', $social[0]['due_date']);
        self::assertSame('2101-02-28', $social[1]['due_date']);
        // Zdravotní: do 8. dne následujícího měsíce; prosinec → 8. 1. dalšího roku.
        self::assertSame('2101-02-08', $health[0]['due_date']);
        self::assertSame('2102-01-08', $health[11]['due_date']);
        // Minimální zálohy jsou kladné i bez příjmů (počítá se z minim).
        self::assertGreaterThan(0.0, $social[0]['amount']);
        self::assertGreaterThan(0.0, $health[0]['amount']);
    }

    public function testFoNewInsuranceAdvanceStartsInFilingMonth(): void
    {
        $previousSocial = [];
        $previousHealth = [];
        for ($month = 1; $month <= 12; $month++) {
            $previousSocial[] = ['seq_no' => $month, 'amount' => 999999.0, 'due_date' => sprintf('2100-%02d-28', $month), 'variable_symbol' => null];
            $previousHealth[] = ['seq_no' => $month, 'amount' => 888888.0, 'due_date' => sprintf('2100-%02d-08', $month), 'variable_symbol' => null];
        }
        $this->repo->replacePlanned($this->supplierId, 'fo', 'social', 2100, $previousSocial, null);
        $this->repo->replacePlanned($this->supplierId, 'fo', 'health', 2100, $previousHealth, null);

        $this->service->generateFromReturn($this->supplierId, 2100, 'fo', null, []);
        $rows = $this->repo->listForYear($this->supplierId, 2101, 'fo');
        $social = array_values(array_filter($rows, static fn (array $row): bool => $row['advance_kind'] === 'social'));
        $health = array_values(array_filter($rows, static fn (array $row): bool => $row['advance_kind'] === 'health'));
        $effectiveMonth = (int) (new \DateTimeImmutable('today'))->format('n');

        if ($effectiveMonth > 1) {
            self::assertSame(999999.0, $social[0]['amount']);
            self::assertSame(888888.0, $health[0]['amount']);
        }
        self::assertLessThan(999999.0, $social[$effectiveMonth - 1]['amount']);
        self::assertLessThan(888888.0, $health[$effectiveMonth - 1]['amount']);
    }

    public function testMatchOutgoingPaymentByVariableSymbolAndPrefill(): void
    {
        // Předpisy §38a za 2101.
        $result = ['next_advances' => ['regime' => 'quarterly', 'count' => 4, 'amount' => 50000.0, 'total' => 200000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);

        // Účet supplera + výpis + odchozí platba s VS = DIČ, poblíž splatnosti 15. 6.
        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        $this->seedTransaction($stmtId, '2101-06-14', -50000.0, '12345678');

        $res = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertGreaterThanOrEqual(1, $res['matched']);
        self::assertSame(50000.0, $res['totals']['tax']);

        // Právě jeden předpis je zaplacený.
        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        $paid = array_filter($rows, fn ($r) => $r['status'] === 'paid');
        self::assertCount(1, $paid);

        // Předvyplnění do rozpracovaného přiznání 2101.
        $this->returns->create($this->supplierId, 2101, 'po', [], null);
        $applied = $this->returns->applyAutoMatchedAdvancesIfEmpty($this->supplierId, 2101, 'po', $res['totals']);
        self::assertSame(['tax'], $applied['applied']);
        self::assertSame([], $applied['skipped']);
        self::assertFalse($applied['conflict']);
        $row = $this->returns->find($this->supplierId, 2101, 'po');
        self::assertSame(50000.0, (float) ($row['inputs']['tax_paid_advances'] ?? 0));
    }

    public function testWrongVariableSymbolIsNotMatched(): void
    {
        $result = ['next_advances' => ['regime' => 'semiannual', 'count' => 2, 'amount' => 24000.0, 'total' => 48000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);
        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        // VS neodpovídá DIČ → nesmí se spárovat.
        $this->seedTransaction($stmtId, '2101-06-14', -24000.0, '99999999');

        $res = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(0, $res['matched']);
        self::assertSame(0.0, $res['totals']['tax']);
    }

    /**
     * F1 regrese (adversariální review 2026-07): dvě odchozí platby se STEJNÝM VS, ale
     * RŮZNOU částkou, obě v okně kolem splatnosti — časově bližší má ŠPATNOU částku.
     * Matcher musí přednostně spárovat transakci se SHODNOU částkou, i když je časově dál.
     */
    public function testAmountMatchTakesPriorityOverDateProximity(): void
    {
        $result = ['next_advances' => ['regime' => 'quarterly', 'count' => 4, 'amount' => 50000.0, 'total' => 200000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);
        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');

        // Splatnost 2101-06-15. Časově bližší (1 den) transakce má JINOU částku (chybná
        // shoda by ji vybrala podle pouhé blízkosti data). Vzdálenější (3 dny) transakce má
        // PŘESNOU částku předpisu — ta musí vyhrát.
        $this->seedTransaction($stmtId, '2101-06-14', -12345.0, '12345678'); // blíž datu, špatná částka
        $this->seedTransaction($stmtId, '2101-06-12', -50000.0, '12345678'); // dál od data, správná částka

        $res = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(1, $res['matched']);
        self::assertSame(50000.0, $res['totals']['tax']);
        self::assertFalse($res['details'][0]['amount_mismatch']);
        self::assertSame('2101-06-12', $res['details'][0]['paid_on']);

        // Špatná-částka transakce zůstala NEspárovaná (jen jeden předpis byl due v okně).
        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        $paid = array_values(array_filter($rows, fn ($r) => $r['status'] === 'paid'));
        self::assertCount(1, $paid);
        self::assertSame(50000.0, $paid[0]['paid_amount']);
    }

    /**
     * F1 + audit 2026-07 — u DANĚ §38a (VS = DIČ nese i doplatky a DPH) platba s částkou
     * MIMO toleranční pásmo predikce se NESMÍ spárovat naslepo: předpis zůstává 'planned',
     * úhrada se vrací jen jako NÁVRH (`suggested`/`needs_review`) k ručnímu potvrzení a do
     * zaplacených záloh (`totals.tax`) se NIC tiše nezapíše.
     */
    public function testTaxAmountFarOffIsNotAutoMatchedButSuggested(): void
    {
        $result = ['next_advances' => ['regime' => 'semiannual', 'count' => 2, 'amount' => 40000.0, 'total' => 80000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);
        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        // Jediná transakce v okně, ale s částkou hluboko mimo pásmo (20 000 vs predikce 40 000 = −50 %).
        $this->seedTransaction($stmtId, '2101-06-14', -20000.0, '12345678');

        $res = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(0, $res['matched']);
        self::assertSame(1, $res['suggested']);
        self::assertSame(0.0, $res['totals']['tax']);
        self::assertTrue($res['details'][0]['suggested']);
        self::assertTrue($res['details'][0]['needs_review']);
        self::assertTrue($res['details'][0]['amount_mismatch']);
        self::assertSame('uncertain', $res['details'][0]['match_confidence']);

        // Předpis zůstal nezaplacený (žádné tiché započtení).
        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        self::assertCount(0, array_filter($rows, fn ($r) => $r['status'] === 'paid'));
    }

    /**
     * Audit 2026-07 (jádro nálezu): DOPLATEK daně minulého roku (velká částka, STEJNÝ
     * VS = DIČ, stejné okno kolem splatnosti) se NESMÍ spárovat jako záloha §38a.
     */
    public function testTaxArrearsLargeAmountIsNotMatchedAsAdvance(): void
    {
        // Predikce čtvrtletní zálohy 150 000; doplatek 600 000 (≈ 4×) nese stejný VS.
        $result = ['next_advances' => ['regime' => 'quarterly', 'count' => 4, 'amount' => 150000.0, 'total' => 600000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);
        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        $this->seedTransaction($stmtId, '2101-06-14', -600000.0, '12345678');

        $res = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(0, $res['matched']);
        self::assertSame(0.0, $res['totals']['tax']);
        // Doplatek se objeví jen jako návrh k ověření, ne jako zaplacená záloha.
        self::assertSame(1, $res['suggested']);
        self::assertTrue($res['details'][0]['suggested']);
    }

    /**
     * Audit 2026-07: reálná záloha (predikce +20 %) se spáruje, ale cizí platba na FÚ
     * se stejným VS a jinou částkou (41 250 vs 150 000) NE — vybere se ta amount-konzistentní.
     */
    public function testRealAdvanceChosenOverForeignPaymentWithSameVs(): void
    {
        $result = ['next_advances' => ['regime' => 'semiannual', 'count' => 2, 'amount' => 150000.0, 'total' => 300000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);
        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        // Cizí platba blíž datu (41 250) i reálná záloha dál od data (180 000 = predikce +20 %).
        $this->seedTransaction($stmtId, '2101-06-15', -41250.0, '12345678');
        $this->seedTransaction($stmtId, '2101-06-12', -180000.0, '12345678');

        $res = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(1, $res['matched']);
        self::assertSame(180000.0, $res['totals']['tax']);
        self::assertFalse($res['details'][0]['amount_mismatch']);
        self::assertSame('2101-06-12', $res['details'][0]['paid_on']);
    }

    /**
     * F3 (audit 2026-07): předpis roku Y NESMÍ konzumovat zálohu splatnou v roce Y+1
     * (nese stejný VS = DIČ). Čtvrtletní předpis 2101 má 4. splatnost 2102-03-15;
     * platba z března 2102 se do přiznání 2101 NEsmí připsat.
     */
    public function testTaxPaymentFromNextCalendarYearIsNotCreditedToPeriodYear(): void
    {
        $result = ['next_advances' => ['regime' => 'quarterly', 'count' => 4, 'amount' => 50000.0, 'total' => 200000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);
        // 4. předpis je splatný 2102-03-15 (viz testPoTaxAdvancesQuarterlyPeriodicityAndDueDates).
        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        self::assertSame('2102-03-15', end($rows)['due_date']);

        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        // Přesná částka, v datovém okně 4. předpisu, ALE kalendářní rok 2102 ≠ periodYear 2101.
        $this->seedTransaction($stmtId, '2102-03-14', -50000.0, '12345678');

        $res = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(0, $res['matched']);
        self::assertSame(0.0, $res['totals']['tax']);
    }

    /**
     * Fix #2 (audit 2026-07): paidTotals sčítá do `exact` JEN jisté shody; nejisté
     * (uncertain) vrací zvlášť, aby se tiše nezapočítaly do předvyplněného přiznání.
     */
    public function testPaidTotalsCountsOnlyExactConfidence(): void
    {
        $rows = [
            ['seq_no' => 1, 'amount' => 50000.0, 'due_date' => '2101-06-15', 'variable_symbol' => '12345678'],
            ['seq_no' => 2, 'amount' => 50000.0, 'due_date' => '2101-09-15', 'variable_symbol' => '12345678'],
        ];
        $this->repo->replacePlanned($this->supplierId, 'po', 'tax', 2101, $rows, null);
        $planned = $this->repo->plannedForMatching($this->supplierId, 'po', 'tax', 2101);
        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        $tx1 = $this->seedTransaction($stmtId, '2101-06-14', -50000.0, '12345678');
        $tx2 = $this->seedTransaction($stmtId, '2101-09-14', -30000.0, '12345678');

        $this->repo->markPaid($this->supplierId, (int) $planned[0]['id'], 50000.0, '2101-06-14', $tx1, 'exact');
        $this->repo->markPaid($this->supplierId, (int) $planned[1]['id'], 30000.0, '2101-09-14', $tx2, 'uncertain');

        $totals = $this->repo->paidTotals($this->supplierId, 'po', 2101);
        self::assertSame(50000.0, $totals['exact']['tax']);
        self::assertSame(30000.0, $totals['uncertain']['tax']);
    }

    /**
     * F3 regrese (adversariální review 2026-07): účetní zadá ruční hodnotu zaplacených
     * záloh (znala úhradu, kterou modul netrackuje), párování pak najde NIŽŠÍ částku —
     * ruční hodnota se NESMÍ tiše přepsat. Návrh z banky se vrátí ve `skipped`, aby ho
     * mohla FE nabídnout k ověření, ale `inputs.tax_paid_advances` zůstane 45000.
     */
    public function testManualValueIsNotSilentlyOverwrittenByLowerBankMatch(): void
    {
        $result = ['next_advances' => ['regime' => 'semiannual', 'count' => 2, 'amount' => 40000.0, 'total' => 80000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);
        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        $this->seedTransaction($stmtId, '2101-06-14', -40000.0, '12345678');

        $created = $this->returns->create($this->supplierId, 2101, 'po', ['tax_paid_advances' => 45000.0], null);

        $res = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(40000.0, $res['totals']['tax']);

        $applied = $this->returns->applyAutoMatchedAdvancesIfEmpty($this->supplierId, 2101, 'po', $res['totals']);
        self::assertSame([], $applied['applied']);
        self::assertSame(['tax'], $applied['skipped']);
        self::assertFalse($applied['conflict']);

        $row = $this->returns->find($this->supplierId, 2101, 'po');
        // Ruční hodnota zůstala nedotčená — NEpřepsáno na 40000.
        self::assertSame(45000.0, (float) $row['inputs']['tax_paid_advances']);
        self::assertSame((int) $created['row_version'], (int) $row['row_version']);
    }

    // ── #43 — rozhodnutí FÚ o změně výše záloh (§174 DŘ) + realita ──────────────

    /**
     * #43 jádro: FÚ na žádost SNÍŽIL zálohy na 85 000. Predikce §38a je čtvrtletní
     * ~150 000 → reálná platba 85 000 leží MIMO pásmo ±30 % kolem predikce a #39 ji
     * (správně) odmítne jako nejistou. Po zadání override rozhodnutím FÚ (85 000) se
     * předpisy přepočítají na tuto výši a TÁŽ platba 85 000 se napáruje jako 'exact'.
     */
    public function testFuOverrideMakesReducedAdvanceMatchAsExact(): void
    {
        // Predikce: čtvrtletní zálohy 150 000 z poslední známé daně.
        $result = ['next_advances' => ['regime' => 'quarterly', 'count' => 4, 'amount' => 150000.0, 'total' => 600000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);

        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        // Reálná (FÚ snížená) záloha 85 000, poblíž splatnosti 15. 6.
        $this->seedTransaction($stmtId, '2101-06-14', -85000.0, '12345678');

        // BEZ override: 85 000 vs predikce 150 000 = −43 % → nespáruje, jen návrh.
        $before = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(0, $before['matched']);
        self::assertSame(0.0, $before['totals']['tax']);
        self::assertSame(1, $before['suggested']);

        // Zadej override rozhodnutím FÚ a přepočti předpisy na 85 000.
        $this->overrides->upsert($this->supplierId, 'po', 'tax', 2101, '2101-01-01', 85000.0, 'quarterly', 'č.j. FÚ', 'fu_decision');
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);

        // Předpisy teď nesou override výši.
        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        self::assertNotEmpty($rows);
        self::assertSame(85000.0, $rows[0]['amount']);

        // Táž platba 85 000 se teď napáruje jako jistá (exact).
        $after = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(1, $after['matched']);
        self::assertSame(85000.0, $after['totals']['tax']);
        self::assertFalse($after['details'][0]['amount_mismatch']);
        self::assertSame('exact', $after['details'][0]['match_confidence']);
    }

    /**
     * #43 ochrana #39 zachována: i s override 85 000 se doplatek daně min. roku (0,6 M)
     * ani cizí platba (41 250) se stejným VS = DIČ NEnapárují jako záloha — tolerance se
     * počítá proti override částce, ne proti násobkům.
     */
    public function testFuOverrideStillRejectsArrearsAndForeignPayment(): void
    {
        $this->overrides->upsert($this->supplierId, 'po', 'tax', 2101, '2101-01-01', 85000.0, 'quarterly', null, 'fu_decision');
        // I s prázdnou predikcí override sám nasadí předpisy (viz #42).
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, []);
        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        self::assertSame(85000.0, $rows[0]['amount']);

        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        // Doplatek daně 600 000 i cizí platba 41 250 — obě se stejným VS, obě mimo pásmo.
        $this->seedTransaction($stmtId, '2101-06-14', -600000.0, '12345678');
        $this->seedTransaction($stmtId, '2101-06-16', -41250.0, '12345678');

        $res = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(0, $res['matched']);
        self::assertSame(0.0, $res['totals']['tax']);
    }

    /**
     * #42: náhled/párování záloh roku 2101 BEZ finalizace přiznání 2100 — override
     * rozhodnutím FÚ sám nasadí předpisy nezávisle na finalizaci a reálné platby se napárují.
     */
    public function testOverrideSeedsAdvancesWithoutFinalizedReturn(): void
    {
        // Žádné přiznání 2100 (draft ani final). Jen rozhodnutí FÚ: pololetní 85 000.
        $this->overrides->upsert($this->supplierId, 'po', 'tax', 2101, '2101-01-01', 85000.0, 'semiannual', null, 'fu_decision');
        $counts = $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, []);
        self::assertSame(2, $counts['tax']);

        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        self::assertCount(2, $rows);
        self::assertSame(['2101-06-15', '2101-12-15'], array_map(fn ($r) => $r['due_date'], $rows));
        self::assertSame([85000.0, 85000.0], array_map(fn ($r) => $r['amount'], $rows));

        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        $this->seedTransaction($stmtId, '2101-06-14', -85000.0, '12345678');
        $this->seedTransaction($stmtId, '2101-12-15', -85000.0, '12345678');

        $res = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(2, $res['matched']);
        self::assertSame(170000.0, $res['totals']['tax']);
    }

    /** #43 — override je tenant-izolovaný: nepřeteče na jiného supplera. */
    public function testOverrideTenantIsolation(): void
    {
        $other = $this->seedSecondSupplier();
        $this->overrides->upsert($this->supplierId, 'po', 'tax', 2101, '2101-01-01', 85000.0, 'quarterly', null, 'fu_decision');

        self::assertNotNull($this->service->activeTaxOverride($this->supplierId, 'po', 2101));
        self::assertNull($this->service->activeTaxOverride($other, 'po', 2101));

        // Generování pro druhého supplera override prvního neuvidí → žádné předpisy.
        $this->service->generateFromReturn($other, 2100, 'po', null, []);
        self::assertCount(0, $this->repo->listForYear($other, 2101, 'po'));
    }

    /** #43 bod 3 — hromadné „vše zaplaceno" ručně potvrdí předpisy a započte je (exact). */
    public function testConfirmAllManuallyMarksPaidAndCountsInTotals(): void
    {
        $result = ['next_advances' => ['regime' => 'semiannual', 'count' => 2, 'amount' => 40000.0, 'total' => 80000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);

        $confirmed = $this->service->confirmAllPaidManual($this->supplierId, 'po', 2101, 'tax');
        self::assertSame(2, $confirmed);

        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        foreach ($rows as $r) {
            self::assertSame('paid', $r['status']);
            self::assertSame('manual', $r['paid_source']);
            self::assertSame(40000.0, $r['paid_amount']);
        }
        $totals = $this->repo->paidTotals($this->supplierId, 'po', 2101);
        self::assertSame(80000.0, $totals['exact']['tax']);
    }

    /** #43 bod 3 — ruční úprava výše NEzaplaceného předpisu + ruční potvrzení + zrušení. */
    public function testManualAmountEditConfirmAndUnconfirm(): void
    {
        $result = ['next_advances' => ['regime' => 'quarterly', 'count' => 4, 'amount' => 50000.0, 'total' => 200000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);
        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        $id = (int) $rows[0]['id'];

        // Úprava výše u nezaplaceného předpisu.
        self::assertTrue($this->service->updatePlannedAmount($this->supplierId, $id, 30000.0));
        self::assertSame(30000.0, $this->repo->findById($this->supplierId, $id)['amount']);

        // Ruční potvrzení bez zadané částky → použije předepsanou (30 000) a splatnost.
        self::assertTrue($this->service->confirmPaidManual($this->supplierId, $id, null, null));
        $paid = $this->repo->findById($this->supplierId, $id);
        self::assertSame('paid', $paid['status']);
        self::assertSame('manual', $paid['paid_source']);
        self::assertSame(30000.0, $paid['paid_amount']);
        self::assertSame($rows[0]['due_date'], $paid['paid_on']);

        // Úprava výše na zaplaceném předpisu už neprojde.
        self::assertFalse($this->service->updatePlannedAmount($this->supplierId, $id, 99999.0));

        // Zrušení potvrzení → zpět na planned.
        self::assertTrue($this->service->unconfirmManual($this->supplierId, $id));
        self::assertSame('planned', $this->repo->findById($this->supplierId, $id)['status']);
    }

    // ── Párování dle účtu FÚ (předčíslí berního účtu) — 2026-07 ─────────────────

    /**
     * Párování dle účtu FÚ: odchozí platba na účet s předčíslím 7704 (DPPO) a VS = DIČ
     * poblíž splatnosti se spáruje jako záloha §38a (jistá shoda).
     */
    public function testTaxAdvanceMatchesByFuAccountPrefix7704(): void
    {
        $result = ['next_advances' => ['regime' => 'quarterly', 'count' => 4, 'amount' => 50000.0, 'total' => 200000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);
        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        $this->seedTransaction($stmtId, '2101-06-14', -50000.0, '12345678', '7704-77627311');

        $res = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(1, $res['matched']);
        self::assertSame(50000.0, $res['totals']['tax']);
        self::assertSame('exact', $res['details'][0]['match_confidence']);
    }

    /**
     * Uzavření známé mezery: platba DPH nese STEJNÝ VS = DIČ i PŘESNĚ částku zálohy a padne
     * do okna kolem splatnosti, ale předčíslí protiúčtu je 705 (DPH), ne 7704 (DPPO) → NESMÍ
     * se spárovat jako záloha na daň z příjmů ani se nabídnout jako návrh.
     */
    public function testDphPaymentWithSameVsIsExcludedByFuAccountPrefix(): void
    {
        $result = ['next_advances' => ['regime' => 'quarterly', 'count' => 4, 'amount' => 50000.0, 'total' => 200000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);
        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        $this->seedTransaction($stmtId, '2101-06-14', -50000.0, '12345678', '705-77627311');

        $res = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(0, $res['matched']);
        self::assertSame(0.0, $res['totals']['tax']);
        self::assertSame(0, $res['suggested']);
    }

    /**
     * Doplatek DPPO (předčíslí 7704, VS = DIČ, ale ~4× výše zálohy poblíž splatnosti) projde
     * filtrem předčíslí, ale spadne MIMO toleranční pásmo částky → vrátí se jen jako NÁVRH
     * (`suggested`), NEspáruje se tiše jako záloha.
     */
    public function testTaxArrearsOnFu7704IsSuggestedNotMatched(): void
    {
        $result = ['next_advances' => ['regime' => 'quarterly', 'count' => 4, 'amount' => 50000.0, 'total' => 200000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);
        $this->seedBankAccount('1000000005', '0100');
        $stmtId = $this->seedStatement('1000000005', '0100');
        $this->seedTransaction($stmtId, '2101-06-14', -200000.0, '12345678', '7704-77627311');

        $res = $this->service->matchPayments($this->supplierId, 2101, 'po');
        self::assertSame(0, $res['matched']);
        self::assertSame(0.0, $res['totals']['tax']);
        self::assertSame(1, $res['suggested']);
        self::assertTrue($res['details'][0]['suggested']);
    }

    /**
     * CHYBA #1 (start zálohového období): nový poplatník, přiznání předchozího roku podáno
     * poradcem (lhůta 1. 7.) → první zálohové období začíná až po ní. Override s
     * `effective_from` = 1. 9. dá jen zálohy 15. 9. a 15. 12. — ŽÁDNÁ fantomová 15. 6. a
     * žádné přetečení do dalšího roku.
     */
    public function testOverrideDrivenScheduleStartsAtEffectiveFromNoPhantom(): void
    {
        $this->overrides->upsert($this->supplierId, 'po', 'tax', 2101, '2101-09-01', 180000.0, 'quarterly', 'č.j. FÚ', 'fu_decision');
        $counts = $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, []);
        self::assertSame(2, $counts['tax']);

        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        self::assertSame(['2101-09-15', '2101-12-15'], array_map(fn ($r) => $r['due_date'], $rows));
        self::assertSame([180000.0, 180000.0], array_map(fn ($r) => $r['amount'], $rows));
    }

    /**
     * Zrcadlo opačné chyby (rok s celoročním obdobím, effective 1. 1.): čtvrtletní override
     * musí zahrnout i PRVNÍ zálohu 15. 3. (dřív ji default lhůta 1. 4. vynechala a předpis
     * přetekl do dalšího kalendářního roku).
     */
    public function testOverrideDrivenScheduleIncludesFirstQuarterFromJanuary(): void
    {
        $this->overrides->upsert($this->supplierId, 'po', 'tax', 2101, '2101-01-01', 85000.0, 'quarterly', null, 'fu_decision');
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, []);

        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        self::assertSame(
            ['2101-03-15', '2101-06-15', '2101-09-15', '2101-12-15'],
            array_map(fn ($r) => $r['due_date'], $rows)
        );
        self::assertSame([85000.0, 85000.0, 85000.0, 85000.0], array_map(fn ($r) => $r['amount'], $rows));
    }

    // ── #46 — rozhodnutí FÚ s rozsahem OD-DO (effective_to) ─────────────────────

    /**
     * #46 jádro: rozhodnutí platí jen pro období [OD, DO]. Splatnost UVNITŘ rozsahu nese
     * částku rozhodnutí, splatnost MIMO rozsah spadá zpět na predikci §38a z přiznání.
     * Override [1. 1. – 30. 6.] 100 000 čtvrtletně + predikce 50 000 čtvrtletně:
     *   15. 3. a 15. 6. (uvnitř) → 100 000; 15. 9. a 15. 12. (mimo) → 50 000 z predikce.
     */
    public function testOverrideWithEffectiveToUsesPredictionOutsideRange(): void
    {
        $this->overrides->insert($this->supplierId, 'po', 'tax', 2101, '2101-01-01', '2101-06-30', 100000.0, 'quarterly', 'č.j. FÚ', 'fu_decision');
        $result = ['next_advances' => ['regime' => 'quarterly', 'count' => 4, 'amount' => 50000.0, 'total' => 200000.0]];
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, $result);

        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        $byDue = [];
        foreach ($rows as $r) {
            $byDue[$r['due_date']] = $r['amount'];
        }
        // Uvnitř rozsahu → částka rozhodnutí.
        self::assertSame(100000.0, $byDue['2101-03-15'] ?? null);
        self::assertSame(100000.0, $byDue['2101-06-15'] ?? null);
        // Mimo rozsah → predikce §38a.
        self::assertSame(50000.0, $byDue['2101-09-15'] ?? null);
        self::assertSame(50000.0, $byDue['2101-12-15'] ?? null);
    }

    /**
     * #46 překryv (obrana v generování): dvě otevřená rozhodnutí, novější (pozdější
     * effective_from) VYHRÁVÁ na sdílené splatnosti — generování nikdy nezdvojí předpis.
     * A [od 1. 1.] 100 000, B [od 1. 7.] 200 000: 15. 3./15. 6. = 100 000 (jen A),
     * 15. 9./15. 12. = 200 000 (B přebíjí A).
     */
    public function testOverlapNewerEffectiveFromWinsAtGeneration(): void
    {
        $this->overrides->insert($this->supplierId, 'po', 'tax', 2101, '2101-01-01', null, 100000.0, 'quarterly', null, 'fu_decision');
        $this->overrides->insert($this->supplierId, 'po', 'tax', 2101, '2101-07-01', null, 200000.0, 'quarterly', null, 'fu_decision');
        $this->service->generateFromReturn($this->supplierId, 2100, 'po', null, []);

        $rows = $this->repo->listForYear($this->supplierId, 2101, 'po');
        $byDue = [];
        foreach ($rows as $r) {
            $byDue[$r['due_date']] = $r['amount'];
        }
        self::assertSame(100000.0, $byDue['2101-03-15'] ?? null);
        self::assertSame(100000.0, $byDue['2101-06-15'] ?? null);
        self::assertSame(200000.0, $byDue['2101-09-15'] ?? null);
        self::assertSame(200000.0, $byDue['2101-12-15'] ?? null);
    }

    /** #46: validace při zápisu zabrání překryvu rozsahů rozhodnutí (nejednoznačná výše). */
    public function testCreateTaxOverrideRejectsOverlappingRange(): void
    {
        $this->service->createTaxOverride($this->supplierId, 'po', '2101-01-01', '2101-06-30', 100000.0, 'quarterly', null, 'fu_decision');

        $this->expectException(\MyInvoice\Service\Tax\Return\TaxReturnException::class);
        $this->expectExceptionMessageMatches('/překrývá|overlap/u');
        // Rozsah [1. 6. – 31. 12.] se protíná s [1. 1. – 30. 6.] → musí selhat.
        $this->service->createTaxOverride($this->supplierId, 'po', '2101-06-01', '2101-12-31', 200000.0, 'quarterly', null, 'fu_decision');
    }

    /** #46: sousední (nepřekrývající se) rozsahy projdou; effective_to se uloží a přečte. */
    public function testAdjacentRangesAllowedAndEffectiveToRoundTrips(): void
    {
        $a = $this->service->createTaxOverride($this->supplierId, 'po', '2101-01-01', '2101-06-30', 100000.0, 'quarterly', null, 'fu_decision');
        $b = $this->service->createTaxOverride($this->supplierId, 'po', '2101-07-01', null, 200000.0, 'quarterly', null, 'fu_decision');

        self::assertSame('2101-06-30', $a['effective_to']);
        self::assertNull($b['effective_to']);
        self::assertCount(2, $this->service->listTaxOverrides($this->supplierId, 'po'));
    }

    private function seedSecondSupplier(): int
    {
        $pdo = $this->db->pdo();
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, dic, default_currency_id, default_vat_rate_id)
             VALUES (?, "Druhá 2", "Brno", "60200", ?, "adv2@example.com", ?, ?, ?)'
        );
        $stmt->execute(['Druhá s.r.o.', $czId, 'CZ87654321', $currencyId, $vatRateId]);
        return (int) $pdo->lastInsertId();
    }

    private function seedBankAccount(string $account, string $bank): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, account_number, bank_code)
              VALUES (?, "CZK", "CZK test", "Kc", "Koruna", "Koruna", ?, ?)'
        );
        $stmt->execute([$this->supplierId, $account, $bank]);
    }

    private function seedStatement(string $account, string $bank): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO bank_statements (file_name, file_hash, account_number, bank_code, currency, statement_date, transaction_count)
             VALUES (?, ?, ?, ?, "CZK", "2101-12-31", 1)'
        );
        $stmt->execute(['test.gpc', hash('sha256', uniqid('advtest', true)), $account, $bank]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function seedTransaction(int $statementId, string $postedAt, float $amount, string $vs, ?string $counterpartyAccount = null): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, variable_symbol, counterparty_account)
             VALUES (?, ?, ?, "CZK", ?, ?)'
        );
        $stmt->execute([$statementId, $postedAt, $amount, $vs, $counterpartyAccount]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
