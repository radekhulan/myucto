<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceReceiver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Referenční obchodní společnost „Vzorová obchodní s.r.o.“ (plátce DPH, měsíční období,
 * podvojné účetnictví, rámec 500) přes dva kalendářní roky produkčními službami a akcemi:
 * doklady, import výpisu, pokladna, majetek, výkazy DPH, uzávěrka, DPPO a otevření roku.
 *
 * Očekávané hodnoty jsou spočtené ručně z účetní a daňové logiky (rozpis v komentářích
 * u jednotlivých kroků), ne opsané z výstupu aplikace. Všechny osoby a částky jsou syntetické.
 *
 * Mzdy v referenčním příkladu nejsou: PayrollFullFlowTrait si zakládá vlastní firmu
 * i obalovou transakci a pracuje s pevnými daty roku 2026, rok 2024 tedy neumí.
 */
final class CommercialReferenceCompanyEndToEndTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const BANK_ACCOUNT = '2000000042';
    private const BANK_CODE = '0800';

    private \DI\Container $container;
    private Connection $db;
    private PDO $pdo;
    private int $supplier;
    private int $actor;
    private int $czk;
    private int $eur;
    private int $vat21;
    private int $vat12;
    private int $vat0;
    private int $period2024;
    private int $period2025;
    /** @var array<string,int> protistrany podle referenčního značení */
    private array $parties = [];
    /** @var array<string,int> doklady podle referenčního značení */
    private array $docs = [];
    private int $statementSeq = 0;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->beginTransaction();

        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->pdo->prepare("UPDATE supplier SET company_name='Vzorová obchodní s.r.o.', accounting_mode='double_entry', taxpayer_type='po',
                stock_enabled=0, vat_period='monthly', ic='12345679', dic='CZ12345679', financial_office_code='451',
                proforma_payment_document='always_tax_document', cz_nace_code='62020', opr_jmeno='Jana', opr_prijmeni='Syntetická', opr_postaveni='jednatelka' WHERE id=?")
            ->execute([$this->supplier]);
        $this->setVatPayerAt($this->pdo, $this->supplier, '1900-01-01', true);
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2024-01-01','cz_500')")
            ->execute([$this->supplier]);
        $this->czk = $this->currency('CZK', 'Česká koruna', 1);
        $this->eur = $this->currency('EUR', 'euro', 0);
        $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$this->czk, $this->supplier]);
        $this->pdo->prepare("INSERT INTO supplier_bank_accounts (supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,kind,source,is_active)
                VALUES (?,'Běžný účet',?,?,?,'CZK',?,'current','manual',1)")
            ->execute([$this->supplier, self::BANK_ACCOUNT, self::BANK_CODE, self::BANK_CODE, self::BANK_ACCOUNT]);

        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplier, '2024-01-01');
        $this->period2024 = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2024, '2024-01-01', '2024-12-31');
        foreach (['document.invoice', 'document.purchase', 'bank.payment.matched'] as $operation) {
            $this->pdo->prepare("INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by) VALUES (?, ?, 'auto', ?)
                    ON DUPLICATE KEY UPDATE level='auto'")
                ->execute([$this->supplier, $operation, $this->actor]);
        }

        $rate = static fn (PDO $pdo, string $percent): int => (int) $pdo->query(
            "SELECT id FROM vat_rates WHERE country='CZ' AND rate_percent={$percent} AND is_reverse_charge=0 AND valid_to IS NULL ORDER BY id LIMIT 1"
        )->fetchColumn();
        $this->vat21 = $rate($this->pdo, '21');
        $this->vat12 = $rate($this->pdo, '12');
        $this->vat0 = $rate($this->pdo, '0');

        $this->parties = [
            'alfa' => $this->party('Odběratel Alfa s.r.o.', 'CZ', '76543214', 'CZ76543214', true, false),
            'gamma' => $this->party('Syntetische Kunde GmbH', 'DE', null, 'DE123456789', true, false),
            'beta' => $this->party('Strojírna Beta s.r.o.', 'CZ', '11111119', 'CZ11111119', false, true),
            'delta' => $this->party('Služby Delta s.r.o.', 'CZ', '22222227', 'CZ22222227', false, true),
            'stavby' => $this->party('Stavby Epsilon s.r.o.', 'CZ', '33333335', 'CZ33333335', false, true),
            'cloud' => $this->party('Synthetic Cloud Ltd', 'IE', null, 'IE1234567T', false, true),
            'pojistovna' => $this->party('Pojišťovna Zeta a.s.', 'CZ', '44444443', 'CZ44444443', false, true),
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testReferenceCompanyTwoYears(): void
    {
        $this->opening2024();
        $this->firstQuarter2024();
        self::assertSame(['022' => '120000.00', '211' => '22420.00', '221' => '454700.00', '311' => '56000.00', '343' => '-21120.00',
            '411' => '-200000.00', '428' => '-220000.00', '501' => '30000.00', '511' => '30000.00', '518' => '40000.00', '602' => '-312000.00'],
            $this->balances('2024-03-31'));
        $this->restOf2024();
        self::assertSame(['022' => '120000.00', '211' => '21210.00', '221' => '475600.00', '311' => '44165.00', '343' => '-24375.00',
            '411' => '-200000.00', '428' => '-220000.00', '501' => '31000.00', '511' => '30000.00', '518' => '65000.00', '548' => '36500.00',
            '602' => '-378500.00', '663' => '-600.00'], $this->balances('2024-12-31'));
        $this->assertVatReports2024();
        $this->clearVat(2024);
        $this->closing2024();
        $this->year2025();
        $this->closing2025();
    }

    /**
     * Uzávěrka 2024. Kroky uzávěrky:
     *   odpisy: 120 000 / 60 měsíců = 2 000 měsíčně, zařazení 15. 1. → únor až prosinec = 22 000 (551/082);
     *           daňově rovnoměrně sk. 2, první rok 11 % = 13 200;
     *   dohad: audit 15 000 (518/389); výdaj příštích období: prosincový nájem 12 000 (518/383);
     *   náklad příštích období 27 300 (381/548) a výnos příštích období 33 400 (602/384) z dokladů s obdobím;
     *   ostatní rezerva 25 000 (554/459), daňově neuznatelná.
     * Výsledek před zdaněním: výnosy 602 345 100 + 663 600 = 345 700; náklady 501 31 000 + 511 30 000
     * + 518 92 000 + 548 9 200 + 551 22 000 + 554 25 000 = 209 200; VH = 136 500.
     * DPPO: ř. 10 136 500, ř. 40 rezerva 25 000, ř. 50 odpisy 22 000 − 13 200 = 8 800, ř. 200 170 300,
     * zaokrouhleno 170 000, daň 21 % = 35 700. Zálohy § 38a na 2025: daň mezi 30 000 a 150 000 →
     * pololetní 40 % = 14 280, zaokrouhleno nahoru na stovky 14 300.
     */
    private function closing2024(): void
    {
        $closing = $this->container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        $period = $this->period2024;
        $meta = ['user_id' => $this->actor, 'posted_by' => $this->actor];
        $closing->start($this->supplier, $period, $this->periodVersion($period), $meta);
        $closing->runPrecheck($this->supplier, $period, $this->periodVersion($period), $meta);
        $depreciation = $closing->bookDepreciation($this->supplier, $period, $meta);
        self::assertEquals([22000.0, 13200.0], [$depreciation['total_accounting'], $depreciation['total_tax']]);
        $closing->confirmStep($this->supplier, $period, 'depreciation', 'done', null, $this->periodVersion($period), $meta);
        $closing->runFxRevaluation($this->supplier, $period, [], $this->periodVersion($period), $meta);
        $this->assisted($period, 'estimates', 'estimate.liability', 15000.00, 'Syntetický dohad na audit 2024', '518');
        $closing->confirmStep($this->supplier, $period, 'estimates', 'done', null, $this->periodVersion($period), $meta);
        self::assertEquals(27300.0, $closing->runPrepaidExpenseAccrual($this->supplier, $period, $this->periodVersion($period), $meta)['total']);
        self::assertEquals(33400.0, $closing->runDeferredRevenueAccrual($this->supplier, $period, $this->periodVersion($period), $meta)['total']);
        $this->assisted($period, 'deferrals', 'accrual.accrued.expense', 12000.00, 'Syntetický nájem za prosinec 2024', '518');
        $closing->confirmStep($this->supplier, $period, 'deferrals', 'done', null, $this->periodVersion($period), $meta);
        $this->assisted($period, 'provisions', 'reserve.other.create', 25000.00, 'Syntetická rezerva na soudní spor');
        $closing->confirmStep($this->supplier, $period, 'provisions', 'done', null, $this->periodVersion($period), $meta);

        $dppo = $this->finalDppo(2024, []);
        self::assertSame([10 => 136500.0, 40 => 25000.0, 50 => 8800.0, 70 => 33800.0, 200 => 170300.0, 270 => 170000.0, 290 => 35700.0, 340 => 35700.0],
            $this->dppoLines($dppo, [10, 40, 50, 70, 150, 200, 270, 290, 340]));
        self::assertEquals(['regime' => 'semiannual', 'count' => 2, 'amount' => 14300], array_intersect_key($dppo['next_advances'], array_flip(['regime', 'count', 'amount'])));

        $tax = $closing->incomeTaxPreview($this->supplier, $period);
        self::assertSame('finalized_return', $tax['suggested_source']);
        self::assertEquals(35700.0, $tax['suggested_amount']);
        $closing->runIncomeTax($this->supplier, $period, 35700.00, $this->periodVersion($period), $meta);
        self::assertSame(self::TRIAL_BALANCE_2024, $this->balances('2024-12-31'));
        // Rozvaha: daňové závazky = DPH 24 375 (zúčtování za měsíce na 343.900) + daň z příjmů 35 700 = 60 075.
        // Čistý obrat od roku 2024 tvoří výnosy obchodního modelu (602), kurzový zisk 663 do něj nepatří.
        $this->assertStatements($period, '2024-12-31', 666275,
            ['B.II.2.' => 98000, 'C.II.2.1.' => 44165, 'C.IV.1.' => 21210, 'C.IV.2.' => 475600, 'D.1.' => 27300],
            ['A.I.1.' => 200000, 'A.IV.1.' => 220000, 'A.V.' => 100800, 'B.4.' => 25000, 'C.II.8.5.' => 60075, 'C.II.8.6.' => 15000,
                'D.1.' => 12000, 'D.2.' => 33400],
            ['I.' => 345100, 'A.2.' => 31000, 'A.3.' => 122000, 'E.1.1.' => 22000, 'F.4.' => 25000, 'F.5.' => 9200, 'VII.' => 600, 'L.1.' => 35700],
            100800, 345100);
        self::assertStringContainsString('kc_ii_340="35700"', $this->container->get(\MyInvoice\Service\Tax\Return\TaxReturnService::class)
            ->buildXml($this->supplier, 2024, 'po')['xml']);

        $this->inventory($period);
        self::assertNotNull($closing->closeBooks($this->supplier, $period, $this->periodVersion($period), $meta)['document_no']);
        self::assertSame([], $this->balances('2024-12-31'), 'Po uzavření knih jsou všechny účty nulové.');
        $opened = $closing->openNext($this->supplier, $period, $this->periodVersion($period), $meta);
        $this->period2025 = (int) $opened['next_period_id'];
        // Otevření 2025: rozvahové zůstatky, výsledek 100 800 na 431 a rozpuštění 381 do 548 a 384 do 602.
        $opening = self::TRIAL_BALANCE_2024;
        foreach (array_keys($opening) as $account) if (in_array(((string) $account)[0], ['5', '6'], true)) unset($opening[$account]);
        unset($opening['381'], $opening['384']);
        $opening += ['431' => '-100800.00', '548' => '27300.00', '602' => '-33400.00'];
        ksort($opening, SORT_STRING);
        self::assertSame($opening, $this->balances('2025-01-01', '2025-01-01'));
    }

    // ---------------------------------------------------------------- rok 2025

    private function year2025(): void
    {
        $posting = $this->container->get(PostingService::class);
        $manual = fn (string $date, string $debit, string $credit, string $amount, string $text): int => $posting->postDocument($this->supplier, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount], ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => $date, 'description' => $text, 'posted' => true, 'posted_by' => $this->actor, 'user_id' => $this->actor]);

        $this->bank('2025-01-10', [[44165.00, 'FV-LIC', 'Odberatel Alfa']]);
        // Nájem za prosinec 2024 od neplátce 12 000 (518) a zúčtování výdaje příštích období 383 / 518.
        $this->parties['najem'] = $this->party('Pronajímatel Théta', 'CZ', '55555551', 'CZ55555551', false, true);
        $this->pdo->prepare('UPDATE clients SET is_vat_payer=0 WHERE id=?')->execute([$this->parties['najem']]);
        $this->docs['PF-NAJ'] = $this->purchase('najem', 'NAJ-2024-12', '2025-01-15', [['Syntetický nájem prosinec 2024', 12000, $this->vat0,
            ['expense_account_code' => '518']]], ['vat_deduction' => 'none', 'vendor_is_vat_payer' => false, 'varsymbol' => '2501']);
        $manual('2025-01-15', '383', '518', '12000.00', 'Zúčtování výdaje příštích období – nájem prosinec 2024');
        $this->bank('2025-01-31', [[-12000.00, 'PF-NAJ', 'Pronajimatel Theta']]);
        // Audit 2024 fakturován 16 000 + 21 % 3 360; dohad 15 000 se zúčtuje 389 / 518.
        $this->docs['PF-AUD'] = $this->purchase('delta', 'PF-DELTA-2503', '2025-03-10', [['Syntetický audit 2024', 16000, $this->vat21]], ['varsymbol' => '2502']);
        $manual('2025-03-10', '389', '518', '15000.00', 'Zúčtování dohadu na audit 2024');
        $this->bank('2025-03-31', [
            [-19360.00, 'PF-AUD', 'Sluzby Delta'],
            [-35700.00, 'DPPO-2024', 'Financni urad DPPO', 'account:341'],
        ]);
        // FV-5: služby 150 000 + 21 % 31 500; PF-5: služby 20 000 + 21 % 4 200; obě uhrazeny 30. 6.
        $this->docs['FV-5'] = $this->issued('alfa', '2025-05-15', [['Syntetické poradenství květen', 150000, $this->vat21]]);
        $this->docs['PF-5'] = $this->purchase('delta', 'PF-DELTA-2505', '2025-05-20', [['Syntetické služby', 20000, $this->vat21]], ['varsymbol' => '2503']);
        // Zálohy na daň z příjmů 2025 podle § 38a: 2 × 14 300.
        $this->bank('2025-06-16', [[-14300.00, 'ZAL-1', 'Financni urad zaloha', 'account:341']]);
        $this->bank('2025-06-30', [
            [181500.00, 'FV-5', 'Odberatel Alfa'],
            [-24200.00, 'PF-5', 'Sluzby Delta'],
        ]);
        // Valná hromada 30. 6. 2025 schválí závěrku 2024 a celý zisk 100 800 převede do nerozděleného zisku (431 → 428).
        self::assertTrue((new AccountingPeriodRepository($this->db))->setStatusCas($this->period2024, $this->supplier, 'approved',
            $this->periodVersion($this->period2024), $this->actor));
        $closing = $this->container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        $distribution = $closing->runProfitDistribution($this->supplier, $this->period2024, ['decision_date' => '2025-06-30',
            'target_row_version' => $this->periodVersion($this->period2025),
            'allocations' => [['account_code' => '428', 'amount' => 100800.00, 'kind' => 'retained']]], ['user_id' => $this->actor, 'posted_by' => $this->actor]);
        self::assertEquals([100800.0, 0.0], [$distribution['available_profit'], $distribution['withholding']]);
        // Reprezentace 5 000 v hotovosti, daňově neuznatelná (513).
        $this->cash(['purpose' => 'other', 'doc_type' => 'out', 'issue_date' => '2025-07-10', 'total_amount' => 5000.00,
            'counter_account_code' => '513', 'description' => 'Syntetické pohoštění obchodních partnerů']);
        $this->bank('2025-12-15', [[-14300.00, 'ZAL-2', 'Financni urad zaloha', 'account:341']]);
        $this->clearVat(2025);

        self::assertEquals(['odp_sum_nar' => '3360', 'odp_tuz23_nar' => '3360', 'pln23' => '16000', 'dano_no' => '3360', 'odp_zocelk' => '3360'], $this->dph(2025, 3));
        self::assertEquals(['dan23' => '31500', 'obrat23' => '150000', 'odp_sum_nar' => '4200', 'odp_tuz23_nar' => '4200', 'pln23' => '20000',
            'dan_zocelk' => '31500', 'dano_da' => '27300', 'odp_zocelk' => '4200'], $this->dph(2025, 5));
        self::assertSame([], $this->dph(2025, 1), 'Nájem od neplátce do přiznání nepatří.');
        self::assertSame(['A4' => [['76543214', '150000.00', '31500.00', '0.00', '0.00']], 'B2' => [['22222227', '20000.00', '4200.00', '0.00', '0.00']]],
            $this->kh(2025, 5));

        // Předpis záloh 2025 z finálního DPPO 2024: 15. 6. připadá na neděli, lhůta se posouvá na pondělí 16. 6. (§ 33 odst. 4 DŘ).
        $schedule = $this->container->get(\MyInvoice\Service\Tax\Return\TaxAdvanceScheduleService::class)->listForYear($this->supplier, 2025, 'po');
        self::assertEquals([['2025-06-16', 14300.0], ['2025-12-15', 14300.0]],
            array_map(static fn (array $row): array => [(string) $row['due_date'], (float) $row['amount']], $schedule));
    }

    /**
     * Uzávěrka 2025: odpisy 12 × 2 000 = 24 000, daňově 22,25 % × 120 000 = 26 700; rezerva trvá.
     * VH před zdaněním: výnosy 602 33 400 + 150 000 = 183 400; náklady 513 5 000 + 518 (12 000 − 12 000
     * + 16 000 − 15 000 + 20 000) 21 000 + 548 27 300 + 551 24 000 = 77 300; VH = 106 100.
     * DPPO: ř. 40 reprezentace 5 000, ř. 150 odpisy 26 700 − 24 000 = 2 700, ř. 200 108 400,
     * zaokrouhleno 108 000, daň 22 680; zaplacené zálohy 28 600 → přeplatek 5 920; daň do 30 000 → zálohy na 2026 nejsou.
     */
    private function closing2025(): void
    {
        $closing = $this->container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        $period = $this->period2025;
        $meta = ['user_id' => $this->actor, 'posted_by' => $this->actor];
        $closing->start($this->supplier, $period, $this->periodVersion($period), $meta);
        $closing->runPrecheck($this->supplier, $period, $this->periodVersion($period), $meta);
        $depreciation = $closing->bookDepreciation($this->supplier, $period, $meta);
        self::assertEquals([24000.0, 26700.0], [$depreciation['total_accounting'], $depreciation['total_tax']]);
        $closing->confirmStep($this->supplier, $period, 'depreciation', 'done', null, $this->periodVersion($period), $meta);
        $closing->runFxRevaluation($this->supplier, $period, [], $this->periodVersion($period), $meta);
        foreach (['estimates', 'deferrals', 'provisions'] as $step) {
            $closing->confirmStep($this->supplier, $period, $step, 'skipped', null, $this->periodVersion($period), $meta);
        }

        $dppo = $this->finalDppo(2025, ['tax_paid_advances' => 28600]);
        self::assertSame([10 => 106100.0, 40 => 5000.0, 70 => 5000.0, 150 => 2700.0, 170 => 2700.0, 200 => 108400.0, 270 => 108000.0,
            290 => 22680.0, 340 => 22680.0], $this->dppoLines($dppo, [10, 40, 50, 70, 150, 170, 200, 270, 290, 340]));
        self::assertEquals([28600.0, -5920.0, 'none'], [$dppo['advances_paid'], $dppo['balance_due'], $dppo['next_advances']['regime']]);
        $closing->runIncomeTax($this->supplier, $period, (float) $closing->incomeTaxPreview($this->supplier, $period)['suggested_amount'], $this->periodVersion($period), $meta);

        self::assertSame(self::TRIAL_BALANCE_2025, $this->balances('2025-12-31'));
        $this->assertStatements($period, '2025-12-31', 677535,
            ['B.II.2.' => 74000, 'C.II.2.4.3.' => 5920, 'C.IV.1.' => 16210, 'C.IV.2.' => 581405],
            ['A.I.1.' => 200000, 'A.IV.1.' => 320800, 'A.V.' => 83420, 'B.4.' => 25000, 'C.II.8.5.' => 48315],
            ['I.' => 183400, 'A.3.' => 26000, 'E.1.1.' => 24000, 'F.5.' => 27300, 'L.1.' => 22680],
            83420, 183400);
        self::assertStringContainsString('kc_ii_340="22680"', $this->container->get(\MyInvoice\Service\Tax\Return\TaxReturnService::class)
            ->buildXml($this->supplier, 2025, 'po')['xml']);

        $this->inventory($period);
        self::assertNotNull($closing->closeBooks($this->supplier, $period, $this->periodVersion($period), $meta)['document_no']);
        self::assertSame([], $this->balances('2025-12-31'));
        $closing->openNext($this->supplier, $period, $this->periodVersion($period), $meta);
        $opening = self::TRIAL_BALANCE_2025;
        foreach (array_keys($opening) as $account) if (in_array(((string) $account)[0], ['5', '6'], true)) unset($opening[$account]);
        $opening['431'] = '-83420.00';
        ksort($opening, SORT_STRING);
        self::assertSame($opening, $this->balances('2026-01-01', '2026-01-01'));
    }

    /**
     * Přiznání DPH (hodnoty, jak jdou do XML), kontrolní a souhrnné hlášení 2024.
     * Leden: ř. 1 = FV-1 200 000 + hotovost 2 000, ř. 40 a 47 = stroj 120 000.
     * Únor: ř. 2 = ubytování 50 000, ř. 40 = služby 40 000 + notebook 30 000.
     * Březen: ř. 1 = daňový doklad k záloze 60 000 (vyúčtování ho odečte na nulu), ř. 10 a 43 = § 92a 30 000.
     * Duben: ř. 1 = dobropis −20 000, ř. 5 a 43 = služba z EU 25 000, ř. 21 = služba do EU 50 000.
     */
    private function assertVatReports2024(): void
    {
        self::assertEquals(['dan23' => '42420', 'obrat23' => '202000', 'nar_maj' => '120000', 'odp_sum_nar' => '25200', 'odp_tuz23_nar' => '25200',
            'pln23' => '120000', 'dan_zocelk' => '42420', 'dano_da' => '17220', 'odp_zocelk' => '25200'], $this->dph(2024, 1));
        self::assertEquals(['dan5' => '6000', 'obrat5' => '50000', 'odp_sum_nar' => '14700', 'odp_tuz23_nar' => '14700', 'pln23' => '70000',
            'dan_zocelk' => '6000', 'dano_no' => '8700', 'odp_zocelk' => '14700'], $this->dph(2024, 2));
        self::assertEquals(['dan23' => '12600', 'dan_rpren23' => '6300', 'obrat23' => '60000', 'rez_pren23' => '30000', 'nar_zdp23' => '30000',
            'od_zdp23' => '6300', 'odp_sum_nar' => '6300', 'dan_zocelk' => '18900', 'dano_da' => '12600', 'odp_zocelk' => '6300'], $this->dph(2024, 3));
        self::assertEquals(['dan23' => '-4200', 'dan_psl23_e' => '5250', 'obrat23' => '-20000', 'p_sl23_e' => '25000', 'pln_sluzby' => '50000',
            'nar_zdp23' => '25000', 'od_zdp23' => '5250', 'odp_sum_nar' => '5250', 'dan_zocelk' => '1050', 'dano_no' => '4200', 'odp_zocelk' => '5250'],
            $this->dph(2024, 4));
        self::assertEquals(['odp_sum_nar' => '210', 'odp_tuz23_nar' => '210', 'pln23' => '1000', 'dano_no' => '210', 'odp_zocelk' => '210'], $this->dph(2024, 6));
        self::assertEquals(['dan23' => '7665', 'obrat23' => '36500', 'dan_zocelk' => '7665', 'dano_da' => '7665'], $this->dph(2024, 12));

        self::assertSame(['A4' => [['76543214', '200000.00', '42000.00', '0.00', '0.00']], 'A5' => [['', '2000.00', '420.00', '0.00', '0.00']],
            'B2' => [['11111119', '120000.00', '25200.00', '0.00', '0.00']]], $this->kh(2024, 1));
        self::assertSame(['A4' => [['76543214', '0.00', '0.00', '50000.00', '6000.00']],
            'B2' => [['11111119', '30000.00', '6300.00', '0.00', '0.00'], ['22222227', '40000.00', '8400.00', '0.00', '0.00']]], $this->kh(2024, 2));
        self::assertSame(['A4' => [['76543214', '60000.00', '12600.00', '0.00', '0.00']], 'B1' => [['33333335', '30000.00', '6300.00', '0.00', '0.00']]],
            $this->kh(2024, 3));
        self::assertSame(['A2' => [['1234567T', '25000.00', '5250.00', '0.00', '0.00']], 'A4' => [['76543214', '-20000.00', '-4200.00', '0.00', '0.00']]],
            $this->kh(2024, 4));
        self::assertSame(['B3' => [['', '1000.00', '210.00', '0.00', '0.00']]], $this->kh(2024, 6));
        self::assertSame(['A4' => [['76543214', '36500.00', '7665.00', '0.00', '0.00']]], $this->kh(2024, 12));

        $sh = $this->container->get(\MyInvoice\Service\Report\SouhrnneHlaseniBuilder::class)->build($this->supplier, 2024, 4);
        self::assertSame([['k_stat' => 'DE', 'vat_id' => '123456789', 'sh_type' => '3', 'amount' => 50000.0, 'count' => 1]],
            array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['k_stat', 'vat_id', 'sh_type', 'amount', 'count'])), $sh['summary']['rows']));
    }

    /** Zúčtování DPH za každý měsíc roku: obrat 343.100 a 343.200 přejde na 343.900. */
    private function clearVat(int $year): void
    {
        $clearing = $this->container->get(\MyInvoice\Service\Accounting\Vat\VatClearingService::class);
        for ($month = 1; $month <= 12; $month++) {
            $clearing->postForPeriod($this->supplier, $year, $month, ['user_id' => $this->actor, 'posted_by' => $this->actor]);
        }
    }

    /** @return array<string,string> nenulové hodnoty řádků přiznání DPH z XML */
    private function dph(int $year, int $month): array
    {
        $built = $this->container->get(\MyInvoice\Service\Report\DphPriznaniBuilder::class)->build($this->supplier, $year, $month);
        $form = (new \SimpleXMLElement($built['xml']))->DPHDP3;
        $out = [];
        foreach (['Veta1', 'Veta2', 'Veta4', 'Veta6'] as $veta) {
            foreach ($form->{$veta}->attributes() ?? [] as $name => $value) {
                if (!in_array((string) $value, ['', '0'], true)) $out[(string) $name] = (string) $value;
            }
        }
        return $out;
    }

    /** @return array<string,list<list<string>>> řádky kontrolního hlášení: DIČ, základ a daň 21 %, základ a daň 12 % */
    private function kh(int $year, int $month): array
    {
        $built = $this->container->get(\MyInvoice\Service\Report\KontrolniHlaseniBuilder::class)->build($this->supplier, $year, $month);
        $form = (new \SimpleXMLElement($built['xml']))->DPHKH1;
        $out = [];
        foreach (['A1', 'A2', 'A4', 'A5', 'B1', 'B2', 'B3'] as $section) {
            foreach ($form->{'Veta' . $section} as $row) {
                $out[$section][] = [(string) ($row['dic_odb'] ?? $row['dic_dod'] ?? $row['vatid_dod'] ?? ''), (string) ($row['zakl_dane1'] ?? '0.00'),
                    (string) ($row['dan1'] ?? '0.00'), (string) ($row['zakl_dane2'] ?? '0.00'), (string) ($row['dan2'] ?? '0.00')];
            }
            if (isset($out[$section])) sort($out[$section]);
        }
        return $out;
    }

    // ---------------------------------------------------------------- rok 2024

    /** Počáteční rozvaha 1. 1. 2024: banka 400 000, pokladna 20 000 | ZK 200 000, nerozdělený zisk 220 000. */
    private function opening2024(): void
    {
        $this->container->get(PostingService::class)->postDocument($this->supplier, 'opening', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => '400000.00'],
            ['account_code' => '211', 'side' => 'debit', 'amount' => '20000.00'],
            ['account_code' => '411', 'side' => 'credit', 'amount' => '200000.00'],
            ['account_code' => '428', 'side' => 'credit', 'amount' => '220000.00'],
        ], ['entry_date' => '2024-01-01', 'document_no' => 'PS-2024', 'description' => 'Počáteční stavy', 'posted' => true,
            'posted_by' => $this->actor, 'user_id' => $this->actor]);
    }

    private function firstQuarter2024(): void
    {
        // PF-DM: stroj 120 000 + 21 % 25 200 = 145 200 → 042 / 343 / 321; zařazení 15. 1. → 022 / 042.
        $this->docs['PF-DM'] = $this->purchase('beta', 'PF-BETA-2401', '2024-01-10', [
            ['Syntetický obráběcí stroj', 120000, $this->vat21, ['expense_kind' => 'fixed_asset', 'is_fixed_asset' => 1]],
        ], ['is_fixed_asset' => 1, 'varsymbol' => '2401']);
        $assets = $this->container->get(\MyInvoice\Service\Accounting\Assets\AssetService::class);
        $asset = $assets->create($this->supplier, ['inventory_number' => 'DM-001', 'name' => 'Syntetický obráběcí stroj', 'input_price' => 120000.00,
            'acquisition_date' => '2024-01-10', 'tax_method' => 'straight', 'tax_group' => 2, 'acc_useful_life_months' => 60,
            'purchase_invoice_id' => $this->docs['PF-DM']], ['user_id' => $this->actor]);
        $this->docs['DM'] = (int) $asset['asset']['id'];
        $assets->putIntoUse($this->supplier, $this->docs['DM'], '2024-01-15', true, ['user_id' => $this->actor, 'posted_by' => $this->actor]);
        // FV-1: služby 200 000 + 21 % 42 000 = 242 000.
        $this->docs['FV-1'] = $this->issued('alfa', '2024-01-20', [['Syntetické poradenství leden', 200000, $this->vat21]]);
        // Pokladna: tržba 2 000 + 21 % 420 = 2 420 (KH A.5).
        $registers = $this->container->get(\MyInvoice\Service\Accounting\Cash\CashRegisterService::class);
        $this->docs['POK'] = $registers->create($this->supplier, ['name' => 'Hlavní pokladna', 'account_code' => '211', 'is_default' => true]);
        $this->cash(['purpose' => 'sale', 'doc_type' => 'in', 'issue_date' => '2024-01-25', 'total_amount' => 2420.00, 'vat_mode' => 'vat',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 2000.00, 'vat_amount' => 420.00]], 'description' => 'Syntetická hotovostní tržba']);

        // PF-1: služby 40 000 + 21 % 8 400; FV-2: ubytování 50 000 + 12 % 6 000; PF-DrM: notebook 30 000 + 21 % 6 300 (drobný majetek 501).
        $this->docs['PF-1'] = $this->purchase('delta', 'PF-DELTA-2402', '2024-02-05', [['Syntetické služby', 40000, $this->vat21]], ['varsymbol' => '2402']);
        $this->docs['FV-2'] = $this->issued('alfa', '2024-02-10', [['Syntetické ubytování', 50000, $this->vat12]]);
        $this->docs['PF-DrM'] = $this->purchase('beta', 'PF-BETA-2403', '2024-02-15', [
            ['Syntetický notebook', 30000, $this->vat21, ['expense_kind' => 'small_asset']],
        ], ['varsymbol' => '2403']);
        $this->bank('2024-02-28', [
            [242000.00, 'FV-1', 'Odberatel Alfa'],
            [-145200.00, 'PF-DM', 'Strojirna Beta'],
            [-48400.00, 'PF-1', 'Sluzby Delta'],
        ]);

        // PF-RC: stavební práce § 92a 30 000, daň 6 300 přizná odběratel (ř. 10 a 43, KH B.1).
        $this->docs['PF-RC'] = $this->purchase('stavby', 'PF-EPS-2404', '2024-03-10', [['Syntetické stavební práce', 30000, $this->vat21,
            ['expense_account_code' => '511']]], ['reverse_charge' => 1, 'varsymbol' => '2404']);
        // ZF-1: záloha 60 000 + 21 % = 72 600, uhrazena 12. 3.; daňový doklad k platbě a vyúčtování 25. 3.
        $this->docs['ZF-1'] = $this->issued('alfa', '2024-03-01', [['Syntetický projekt', 60000, $this->vat21]], ['invoice_type' => 'proforma']);
        $this->bank('2024-03-12', [[72600.00, 'ZF-1', 'Odberatel Alfa']]);
        $ddkp = (int) $this->pdo->query("SELECT id FROM invoices WHERE parent_invoice_id={$this->docs['ZF-1']} AND invoice_type='tax_document'")->fetchColumn();
        self::assertGreaterThan(0, $ddkp, 'Úhrada zálohy založí daňový doklad k platbě.');
        $this->issue($ddkp);
        $this->docs['DDKP-1'] = $ddkp;
        $this->docs['FV-ZF'] = $this->container->get(\MyInvoice\Service\Invoice\FinalFromProformaCreator::class)
            ->create($this->docs['ZF-1'], $this->actor, '2024-03-25', '2024-04-08');
        $this->issue($this->docs['FV-ZF']);
        $this->bank('2024-03-31', [
            [-30000.00, 'PF-RC', 'Stavby Epsilon'],
            [-36300.00, 'PF-DrM', 'Strojirna Beta'],
        ]);
    }

    private function restOf2024(): void
    {
        // DOB-1: dobropis k FV-1 −20 000 − 21 % 4 200 = −24 200, vrácen odběrateli 30. 4.
        $this->docs['DOB-1'] = $this->issued('alfa', '2024-04-15', [['Syntetická sleva z poradenství', 20000, $this->vat21, ['quantity' => -1]]],
            ['invoice_type' => 'credit_note', 'parent_invoice_id' => $this->docs['FV-1']]);
        // FV-EU: služba do Německa 2 000 EUR × 25,00 = 50 000 Kč, daň přiznává příjemce (ř. 21, SH kód 3).
        $this->rate('2024-04-19', '25.000');
        $this->docs['FV-EU'] = $this->issued('gamma', '2024-04-20', [['Synthetic consulting', 2000, $this->vat0]],
            ['currency_id' => $this->eur, 'reverse_charge' => 1, 'vat_classification_code' => '22', 'exchange_rate' => 25.0, 'exchange_rate_date' => '2024-04-19']);
        // PF-EU: služba z Irska 1 000 EUR × 25,00 = 25 000 Kč, daň 5 250 přizná odběratel (ř. 5 a 43, KH A.2).
        $this->docs['PF-EU'] = $this->purchase('cloud', 'IE-2024-0425', '2024-04-25', [['Synthetic cloud service', 1000, $this->vat21]],
            ['currency_id' => $this->eur, 'exchange_rate' => 25.0, 'exchange_rate_date' => '2024-04-24', 'reverse_charge' => 1, 'varsymbol' => '2405']);
        $this->bank('2024-04-30', [
            [-24200.00, 'DOB-1', 'Odberatel Alfa', 'invoice_id'],
            [-25000.00, 'PF-EU', 'Synthetic Cloud'],
        ]);
        // Úhrada FV-EU v Kč 50 600 → kurzový zisk 600 (663); úhrada FV-2 56 000.
        $this->bank('2024-05-31', [
            [50600.00, 'FV-EU', 'Synthetische Kunde'],
            [56000.00, 'FV-2', 'Odberatel Alfa'],
        ]);
        // Pokladna: kancelářské potřeby 1 000 + 21 % 210 = 1 210 (KH B.3).
        $this->cash(['purpose' => 'purchase', 'doc_type' => 'out', 'issue_date' => '2024-06-10', 'total_amount' => 1210.00, 'vat_mode' => 'vat',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]], 'description' => 'Syntetické kancelářské potřeby']);
        // PF-POJ: pojistné 36 500 (osvobozené) na 1. 10. 2024 – 30. 9. 2025, 365 dnů → do roku 2025 patří 273 dnů = 27 300 (381).
        $this->docs['PF-POJ'] = $this->purchase('pojistovna', 'POJ-2024-10', '2024-10-01', [['Syntetické pojištění majetku', 36500, $this->vat0,
            ['expense_account_code' => '548', 'accrual_from' => '2024-10-01', 'accrual_to' => '2025-09-30']]], ['vat_deduction' => 'none', 'varsymbol' => '2406']);
        $this->bank('2024-10-15', [[-36500.00, 'PF-POJ', 'Pojistovna Zeta']]);
        // FV-LIC: licence 36 500 + 21 % 7 665 na 1. 12. 2024 – 30. 11. 2025, 365 dnů → do roku 2025 patří 334 dnů = 33 400 (384).
        $this->docs['FV-LIC'] = $this->issued('alfa', '2024-12-01', [['Syntetická roční licence', 36500, $this->vat21,
            ['accrual_from' => '2024-12-01', 'accrual_to' => '2025-11-30']]]);
    }

    // ---------------------------------------------------------------- uzávěrka a daň

    /** Konečné zůstatky 2025 před uzavřením knih; 341 je přeplatek záloh 28 600 − daň 22 680 = 5 920. */
    private const TRIAL_BALANCE_2025 = ['022' => '120000.00', '082' => '-46000.00', '211' => '16210.00', '221' => '581405.00', '341' => '5920.00',
        '343' => '-48315.00', '411' => '-200000.00', '428' => '-320800.00', '459' => '-25000.00', '513' => '5000.00', '518' => '21000.00',
        '548' => '27300.00', '551' => '24000.00', '591' => '22680.00', '602' => '-183400.00'];

    /** Konečné zůstatky 2024 před uzavřením knih (ručně spočtená předvaha, syntetické účty). */
    private const TRIAL_BALANCE_2024 = ['022' => '120000.00', '082' => '-22000.00', '211' => '21210.00', '221' => '475600.00', '311' => '44165.00',
        '341' => '-35700.00', '343' => '-24375.00', '381' => '27300.00', '383' => '-12000.00', '384' => '-33400.00', '389' => '-15000.00',
        '411' => '-200000.00', '428' => '-220000.00', '459' => '-25000.00', '501' => '31000.00', '511' => '30000.00', '518' => '92000.00',
        '548' => '9200.00', '551' => '22000.00', '554' => '25000.00', '591' => '35700.00', '602' => '-345100.00', '663' => '-600.00'];

    private function periodVersion(int $period): int
    {
        $q = $this->pdo->prepare('SELECT row_version FROM accounting_periods WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplier, $period]);
        return (int) $q->fetchColumn();
    }

    /**
     * Rozvaha a výsledovka 500 (plný rozsah) z produkčního výkazu: nenulové řádky nejnižší
     * úrovně (aktiva netto), bilanční suma, výsledek a čistý obrat.
     */
    private function assertStatements(int $period, string $asOf, int $total, array $assets, array $liabilities, array $income, int $profit, int $turnover): void
    {
        $statements = $this->container->get(\MyInvoice\Service\Accounting\Reports\FinancialStatementService::class);
        $balance = $statements->balanceSheet($this->supplier, $period, $asOf, 'full');
        self::assertTrue($balance['checks']['balanced']);
        self::assertEquals([$total, $total, $profit], [$balance['checks']['assets_net'], $balance['checks']['liabilities_total'], $balance['checks']['profit_current']]);
        self::assertEquals($assets, self::leaves($balance['assets'], 'net'), 'aktiva ' . $asOf);
        self::assertEquals($liabilities, self::leaves($balance['liabilities'], 'amount'), 'pasiva ' . $asOf);
        $statement = $statements->incomeStatement($this->supplier, $period, $asOf, 'full');
        self::assertEquals([$profit, $turnover], [$statement['checks']['profit_current'], $statement['checks']['net_turnover']]);
        self::assertEquals($income, self::leaves(array_filter($statement['rows'], static fn (array $row): bool => $row['row_type'] !== 'computed'), 'amount'), 'výsledovka ' . $asOf);
    }

    /** @return array<string,float> nenulové řádky, pod kterými už žádný nenulový řádek není */
    private static function leaves(array $rows, string $column): array
    {
        $nonzero = [];
        foreach ($rows as $row) {
            if ((float) $row[$column] !== 0.0 && preg_match('/^[A-Z]+\.|^[IVX]+\./', (string) $row['display_code'])) $nonzero[(string) $row['display_code']] = (float) $row[$column];
        }
        $out = [];
        foreach ($nonzero as $code => $value) {
            $parent = false;
            foreach (array_keys($nonzero) as $other) {
                if ($other !== $code && str_starts_with((string) $other, $code)) $parent = true;
            }
            if (!$parent) $out[$code] = $value;
        }
        return $out;
    }

    /** Inventarizace: skutečný stav každého rozvahového účtu odpovídá účetnímu. */
    private function inventory(int $period): void
    {
        $closing = $this->container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        $items = [];
        foreach ($closing->inventoryPreview($this->supplier, $period)['rows'] as $row) {
            $items[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $closing->saveInventory($this->supplier, $period, $this->periodVersion($period), ['complete' => true], $items, ['user_id' => $this->actor]);
    }

    private function assisted(int $period, string $step, string $rule, float $amount, string $description, ?string $counter = null): void
    {
        $body = ['row_version' => $this->periodVersion($period), 'rule_key' => $rule, 'amount' => $amount, 'description' => $description];
        if ($counter !== null) $body['counter_account'] = $counter;
        $this->container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class)
            ->createAssistedEntry($this->supplier, $period, $step, $body, ['user_id' => $this->actor, 'posted_by' => $this->actor]);
    }

    /** Řádné DPPO: koncept, ruční vstupy, finalizace; vrací vypočtené hodnoty finálního přiznání. */
    private function finalDppo(int $year, array $inputs): array
    {
        $service = $this->container->get(\MyInvoice\Service\Tax\Return\TaxReturnService::class);
        $draft = $service->getReturn($this->supplier, $year, 'po', $this->actor);
        if ($inputs !== []) {
            $draft = $service->saveInputs($this->supplier, $year, 'po', $inputs, $draft['return']['row_version'], $this->actor, partial: true);
        }
        $final = $service->finalize($this->supplier, $year, 'po', $draft['return']['row_version'], $this->actor);
        self::assertSame('final', $final['return']['status']);
        return $final['computed'];
    }

    /** @return array<int,float> nenulové hodnoty vybraných řádků DPPO */
    private function dppoLines(array $computed, array $lines): array
    {
        $out = [];
        foreach ($computed['lines'] as $row) {
            if (in_array((int) $row['line'], $lines, true) && (float) $row['value'] !== 0.0) $out[(int) $row['line']] = (float) $row['value'];
        }
        ksort($out);
        return $out;
    }

    // ---------------------------------------------------------------- doklady

    /** @param list<array{0:string,1:float|int,2:int,3?:array<string,mixed>}> $items */
    private function issued(string $party, string $date, array $items, array $header = []): int
    {
        $repo = $this->container->get(InvoiceRepository::class);
        $id = $repo->createDraft($header + ['client_id' => $this->parties[$party], 'issue_date' => $date, 'tax_date' => $date,
            'due_date' => date('Y-m-d', strtotime($date . ' +14 days')), 'currency_id' => $this->czk], $this->actor);
        if (isset($header['exchange_rate'])) $repo->setExchangeRate($id, (float) $header['exchange_rate'], (string) $header['exchange_rate_date']);
        $repo->replaceItems($id, array_map(static fn (array $item): array => ($item[3] ?? []) + ['description' => $item[0], 'quantity' => 1,
            'unit' => 'ks', 'unit_price_without_vat' => $item[1], 'vat_rate_id' => $item[2]], $items));
        $this->container->get(InvoiceCalculator::class)->recompute($id);
        $this->issue($id, ($header['invoice_type'] ?? 'invoice') !== 'proforma');
        return $id;
    }

    private function issue(int $invoiceId, bool $posted = true): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/invoices/' . $invoiceId . '/issue')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'admin']);
        $response = $this->container->get(IssueInvoiceAction::class)->__invoke($request, new Psr7Response(), ['id' => (string) $invoiceId]);
        $response->getBody()->rewind();
        self::assertLessThan(300, $response->getStatusCode(), 'Vystavení #' . $invoiceId . ': ' . (string) $response->getBody());
        if ($posted) $this->assertPosted('invoice', $invoiceId);
    }

    /** @param list<array{0:string,1:float|int,2:int,3?:array<string,mixed>}> $items */
    private function purchase(string $party, string $number, string $date, array $items, array $header = []): int
    {
        $repo = $this->container->get(PurchaseInvoiceRepository::class);
        $id = $repo->createDraft($header + ['vendor_id' => $this->parties[$party], 'vendor_invoice_number' => $number, 'issue_date' => $date,
            'tax_date' => $date, 'received_at' => $date, 'received_at_source' => 'manual', 'due_date' => date('Y-m-d', strtotime($date . ' +14 days')),
            'currency_id' => $this->czk], $this->actor, $this->supplier);
        $repo->replaceItems($id, array_map(static fn (array $item): array => ($item[3] ?? []) + ['description' => $item[0], 'quantity' => 1,
            'unit' => 'ks', 'unit_price_without_vat' => $item[1], 'vat_rate_id' => $item[2]], $items));
        (new PurchaseInvoiceCalculator($this->db))->recompute($id);
        self::assertTrue($this->container->get(PurchaseInvoiceReceiver::class)->receiveDraft($this->supplier, $id, $this->actor));
        $this->assertPosted('purchase_invoice', $id);
        return $id;
    }

    private function assertPosted(string $sourceType, int $id): int
    {
        $q = $this->pdo->prepare('SELECT id FROM journal_entries WHERE supplier_id=? AND source_type=? AND source_id=? AND reversed_by IS NULL AND posted_at IS NOT NULL ORDER BY id DESC LIMIT 1');
        $q->execute([$this->supplier, $sourceType, $id]);
        $entry = (int) $q->fetchColumn();
        if ($entry === 0) {
            $reason = $sourceType === 'bank'
                ? json_encode($this->pdo->query("SELECT amount,variable_symbol,matched_invoice_id,match_status,match_reason,processing_error FROM bank_transactions WHERE id={$id}")->fetch(PDO::FETCH_ASSOC))
                : $this->pdo->query("SELECT payload FROM activity_log WHERE action='accounting.auto_post_failed' AND entity_id={$id} ORDER BY id DESC LIMIT 1")->fetchColumn();
            self::fail("Doklad {$sourceType} #{$id} se nezaúčtoval: " . (is_string($reason) ? $reason : '?'));
        }
        return $entry;
    }

    private function cash(array $document): int
    {
        $created = $this->container->get(\MyInvoice\Service\Accounting\Cash\CashDocumentService::class)
            ->create($this->supplier, $document + ['register_id' => $this->docs['POK'], 'post' => true], $this->actor);
        self::assertSame('posted', $created['status'], json_encode($created));
        return (int) $created['id'];
    }

    private function vs(string $doc): string
    {
        $q = $this->pdo->prepare('SELECT varsymbol FROM invoices WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplier, $this->docs[$doc]]);
        return (string) $q->fetchColumn();
    }

    /**
     * Import výpisu GPC produkčním importem a ruční spárování každého pohybu s dokladem
     * (akce párování, která pohyb rovnou zaúčtuje). Účet firmy je v registru vlastních
     * účtů, výpis tedy patří této firmě. Připojený import přes měnový účet si otevírá
     * vlastní transakci, a ta v obalové testové transakci nejde; automatické párování
     * podle VS hledá firmu právě jen přes měnové účty, proto test páruje ručně.
     *
     * @param list<array{0:float,1:string,2:string,3?:string|null}> $rows částka (záporná = odchozí), doklad, protistrana, druh párování
     * @return list<int> pohyby výpisu
     */
    private function bank(string $date, array $rows): array
    {
        $number = ++$this->statementSeq;
        $account = str_pad(self::BANK_ACCOUNT, 16, '0', STR_PAD_LEFT);
        $day = (new \DateTimeImmutable($date))->format('dmy');
        $zero = str_pad('0', 14, '0', STR_PAD_LEFT) . '+';
        $lines = ['074' . $account . str_pad('VZOROVA OBCHODNI', 20) . $day . $zero . $zero . $zero . $zero . str_pad((string) $number, 3, '0', STR_PAD_LEFT) . $day];
        foreach ($rows as $index => [$amount, $doc, $name]) {
            $vs = isset($this->docs[$doc]) ? (string) $this->docs[$doc] : '0';
            $lines[] = '075' . $account . str_pad('1000000005', 16, '0', STR_PAD_LEFT) . str_pad((string) ($number * 100 + $index), 13, '0', STR_PAD_LEFT)
                . str_pad((string) (int) round(abs($amount) * 100), 12, '0', STR_PAD_LEFT) . ($amount < 0 ? '1' : '2')
                . str_pad($vs, 10, '0', STR_PAD_LEFT) . '00' . '0100' . '0000' . str_pad('', 10, '0')
                . $day . str_pad(substr($name, 0, 20), 20) . '00203' . $day;
        }
        $result = $this->container->get(\MyInvoice\Service\Bank\StatementImporter::class)
            ->import(implode("\r\n", $lines) . "\r\n", 'vypis-' . $number . '.gpc', $this->actor);
        self::assertSame(count($rows), $result['transactions'], json_encode($result));
        $q = $this->pdo->prepare('SELECT id FROM bank_transactions WHERE statement_id=? ORDER BY id');
        $q->execute([$result['statement_id']]);
        $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        foreach ($rows as $index => $row) {
            $kind = $row[3] ?? ($row[0] < 0 ? 'purchase_invoice_id' : 'invoice_id');
            if (str_starts_with($kind, 'account:')) {
                // Pohyb bez dokladu (platba finančnímu úřadu) zaúčtuje účetní ručně MD/D.
                $counter = substr($kind, 8);
                $this->container->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class)->postManual($this->supplier, $ids[$index], [
                    'debit_account_code' => $row[0] < 0 ? $counter : '221', 'credit_account_code' => $row[0] < 0 ? '221' : $counter,
                    'description' => $row[2]], ['user_id' => $this->actor, 'posted_by' => $this->actor]);
                $this->assertPosted('bank', $ids[$index]);
                continue;
            }
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/bank/transactions/' . $ids[$index] . '/match')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'admin'])
                ->withParsedBody([$kind => $this->docs[$row[1]]]);
            $response = $this->container->get(\MyInvoice\Action\Bank\BankStatementAction::class)
                ->manualMatch($request, new Psr7Response(), ['id' => (string) $ids[$index]]);
            $response->getBody()->rewind();
            self::assertLessThan(300, $response->getStatusCode(), 'Párování ' . $row[1] . ': ' . (string) $response->getBody());
            $this->assertPosted('bank', $ids[$index]);
        }
        return $ids;
    }

    // ---------------------------------------------------------------- fixtury firmy

    private function rate(string $date, string $rate): void
    {
        $this->pdo->prepare("INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, 'EUR', ?) ON DUPLICATE KEY UPDATE rate = VALUES(rate)")
            ->execute([$date, $rate]);
    }

    private function currency(string $code, string $name, int $default): int
    {
        $this->pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default) VALUES (?,?,?,?,?,?,2,1,?)')
            ->execute([$this->supplier, $code, $code, $code === 'CZK' ? 'Kč' : '€', $name, $name, $default]);
        return (int) $this->pdo->lastInsertId();
    }

    private function party(string $name, string $country, ?string $ic, string $dic, bool $customer, bool $vendor): int
    {
        $countryId = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2='{$country}' LIMIT 1")->fetchColumn();
        $this->pdo->prepare('INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, ic, dic, main_email,
                is_customer, is_vendor, is_vat_payer) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)')
            ->execute([$this->supplier, $name, 'Syntetická 1', 'Syntetické město', '10000', $countryId, $this->czk, $ic, $dic,
                strtolower(substr($dic, 2)) . '@example.test', $customer ? 1 : 0, $vendor ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    // ---------------------------------------------------------------- deník

    /** Zůstatky syntetických účtů (MD − D) ke dni, jen nenulové. */
    private function balances(string $date, ?string $from = null): array
    {
        $q = $this->pdo->prepare("SELECT LEFT(a.account_code,3) code, SUM(CASE l.side WHEN 'debit' THEN l.amount ELSE -l.amount END) balance
            FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id
            JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id
            WHERE l.supplier_id=? AND e.posted_at IS NOT NULL AND e.entry_date>=? AND e.entry_date<=? GROUP BY LEFT(a.account_code,3) ORDER BY code");
        $q->execute([$this->supplier, $from ?? '1900-01-01', $date]);
        return array_filter(array_column($q->fetchAll(PDO::FETCH_ASSOC), 'balance', 'code'), fn ($v) => bccomp((string) $v, '0', 2) !== 0);
    }
}
