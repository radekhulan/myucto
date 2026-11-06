<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Repository\Property\Payments\DebtRepository;
use MyInvoice\Repository\Property\Payments\PaymentRepository;
use MyInvoice\Repository\Property\PropertyRegistryRepository;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Charges\ChargeCalculator;
use MyInvoice\Service\Property\Charges\ChargePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\Services\Settlement\SettlementHistory;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Referenční SVJ „Dům U Lip“ (tři jednotky, rámec 504) přes dvě účetní období produkčními
 * službami: založení, registr, počáteční stavy, předpisy, úhrady z banky,
 * náklady, čerpání záloh, nájem střechy, dohad, uzávěrka, otevření, jeden úplný vodní workflow
 * a částečná vratka kreditu. Všechny osoby a částky jsou syntetické.
 */
final class PropertyReferenceHouseEndToEndTest extends TestCase
{
    use PropertyChargeFixture;

    private const ACCOUNTS = ['315.100', '315.110', '315.120', '315.130', '315.210', '315.300', '315.400', '324.110', '324.200',
        '379.900', '379.910', '379.920', '388.210', '389.110', '518.200', '518.900', '549.900', '549.910', '641.900', '644.100',
        '649.100', '649.200', '649.900', '955.100'];

    private \DI\Container $container;
    private Connection $db;
    private PDO $pdo;
    private array $f;
    private int $supplier;
    private int $actor;
    private int $year2026;
    /** @var array<string,int> osoby podle referenčního značení */
    private array $people = [];
    /** @var array<string,int> dluh předpisu podle jednotky */
    private array $debts = [];
    /** @var array<string,array<string,int>> řádky předpisu podle jednotky a složky */
    private array $lines = [];
    private int $fundingRevision = 0;

    private function referenceHouseFixture(Connection $db): array
    {
        $pdo=$db->pdo(); $supplier=$this->createIsolatedSupplier($pdo,(int)$pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $actor=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?,'1900-01-01','cz_504')")->execute([$supplier]);
        (new PropertyProfileRepository($db))->save($supplier,['expected_revision'=>0,'effective_from'=>'2026-01-01T00:00:00+01:00','enabled'=>true,'reason'=>'Synthetic SVJ','title_ref'=>'Synthetic deed','idempotency_key'=>'profile'],$actor);
        $country=(int)$pdo->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();
        $currency=(int)$pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $clients=[];
        foreach([1,2,10,11,18] as $n) {
            $pdo->prepare('INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$supplier,'Synthetic '.$n,'Synthetic','Synthetic','10000',$country,'charge'.$n.'@example.test',$currency]);
            $clients[$n]=(int)$pdo->lastInsertId();
        }
        $registry=new PropertyRegistryRepository($db);
        $house=$registry->create($supplier,'house',self::registryRequest('LIP',['name'=>'Dům U Lip','active'=>true,'address'=>['street'=>'Synthetic 12','city'=>'Synthetic','postal_code'=>'10000','country'=>'CZ'],'declaration_ref'=>'Synthetic deed','share_scope'=>'LIP','registry_complete'=>true]),$actor);
        $service=$registry->create($supplier,'service',self::registryRequest('WATER',['name'=>'Synthetic water','active'=>true,'measure'=>'m3','measure_label'=>'']),$actor);
        $fixture=(static fn():array=>require dirname(__DIR__,2).'/Fixtures/property-house-at-limes.php')();
        $units=[]; $states=[];
        foreach(array_intersect_key($fixture,array_flip(['U01','U06','G12'])) as $code=>$state) {
            foreach($state['ownerships'] as &$group) $group['members']=array_map(fn($n)=>$clients[$n],$group['members']); unset($group);
            foreach($state['roles'] as &$role) $role['client_id']=$clients[$role['client_id']]; unset($role);
            $state['services']=$code==='U01'?[$service['id']]:[];
            $state['attributes']['floor_area']=['num'=>'60','den'=>'1'];
            $state['attributes']['persons']=['num'=>$code==='G12'?'0':'2','den'=>'1'];
            $state['attributes']['share']=['num'=>$code==='G12'?'100':'450','den'=>'1000'];
            $states[$code]=$state;
            $units[$code]=$registry->create($supplier,'unit',self::registryRequest($code,$state)+['parent_id'=>$house['id']],$actor)['id'];
        }
        $repo=new ChargeRepository($db); $components=[];
        foreach(['LONG'=>'long_term_advance','ADMIN'=>'admin_advance','WATER'=>'service_advance'] as $code=>$kind) $components[$code]=$repo->createComponent($supplier,
            ['code'=>$code,'name'=>'Synthetic '.$code,'kind'=>$kind,'service_id'=>$code==='WATER'?$service['id']:null,'effective_at'=>'2026-01-01T00:00:00+01:00','account_code'=>'','idempotency_key'=>$code],$actor)['id'];
        return compact('supplier','actor','house','service','units','states','clients','components');
    }

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->beginTransaction();
        $this->f = $this->referenceHouseFixture($this->db);
        $this->supplier = $this->f['supplier'];
        $this->actor = $this->f['actor'];
        $clients = $this->f['clients'];
        $this->people = ['P01a' => $clients[1], 'P01b' => $clients[2], 'P06' => $clients[10], 'Q06' => $clients[11], 'P12' => $clients[18]];
        $this->year2026 = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplier, '2026-01-01');
        $chart = new ChartOfAccountsRepository($this->db);
        foreach (self::ACCOUNTS as $code) {
            if ($chart->findByCode($this->supplier, $code) !== null) continue;
            $parent = $chart->findByCode($this->supplier, substr($code, 0, 3));
            $chart->insert($this->supplier, ['account_code' => $code, 'name' => 'Syntetický účet ' . $code,
                'account_type' => $parent['account_type'], 'normal_side' => $parent['normal_side'], 'parent_id' => $parent['id']]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testReferenceHouseTwoYearsMatchesBalanceSheets(): void
    {
        PropertyInstant::withNormalizationContext($this->referenceHouseTwoYears(...));
    }

    private function referenceHouseTwoYears(): void
    {
        $this->opening();
        $this->issueAnnualPrescriptions();
        $this->annualPayments();
        $this->costsAndCoverage();
        $this->roofRent();
        $this->post('2026-12-31', [['221', 'debit', '790.00'], ['644.100', 'credit', '790.00', true]]);
        $this->waterCosts();

        self::assertSame(self::TRIAL_BALANCE_2026, $this->balances('2026-12-31'));
        $this->assertStatements($this->year2026, '2026-12-31', 259730, 306000,
            ['B.II.5.' => 9200, 'B.II.18.' => 4500, 'B.III.3.' => 246030],
            ['A.II.1.' => 730, 'B.II.4.' => 225000, 'B.III.3.' => 13000, 'B.III.17.' => 16500, 'B.III.22.' => 4500],
            ['A.I.6.' => 2060, 'A.V.22.' => 240000, 'B.IV.7.' => 790, 'B.IV.10.' => 242000, 'C.' => 730, 'D.' => 730]);

        // Bankovní úrok je jiný zdanitelný příjem: přiznání je
        // nutné, i když potvrzený výpočet daně vyjde nula.
        $closed = $this->close($this->year2026, ['status' => 'filing_required', 'reason' => 'other_taxable_income',
            'legal_basis' => 'zdp_38mb_b']);
        self::assertNotNull($closed['entry_id']);
        self::assertSame([], $this->balances('2026-12-31'), 'Po uzávěrce jsou všechny účty včetně 933 a 962 nulové.');
        $closing = $this->container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        $opened = $closing->openNext($this->supplier, $this->year2026, $this->periodVersion($this->year2026));
        self::assertNotNull($opened['entry_id']);
        $year2027 = (int) $this->pdo->query('SELECT id FROM accounting_periods WHERE supplier_id=' . $this->supplier . ' AND fiscal_year=2027')->fetchColumn();
        $opening = self::TRIAL_BALANCE_2026;
        foreach (array_keys($opening) as $account) if (in_array(((string) $account)[0], ['5', '6'], true)) unset($opening[$account]);
        $opening['931'] = '-730.00';
        ksort($opening, SORT_STRING);
        self::assertSame($opening, $this->balances('2027-01-01', '2027-01-01'));
        // Shromáždění schválí závěrku 20. 3. 2027; o rozdělení výsledku rozhoduje až po dubnovém vypořádání.
        self::assertTrue((new AccountingPeriodRepository($this->db))->setStatusCas($this->year2026, $this->supplier, 'approved', $this->periodVersion($this->year2026),
            $this->actor, ['body' => 'Syntetické shromáždění vlastníků', 'decision_ref' => 'Syntetické usnesení 2027/03']));

        $this->finalWaterInvoices();
        $this->settleWater();
        $this->refundOldCredit();

        $april = ['221' => '239730.00', '315.130' => '2000.00', '324.200' => '-1000.00',
            '379.910' => '-4500.00', '379.920' => '-10500.00', '931' => '-730.00', '955.100' => '-225000.00'];
        self::assertSame($april, $this->balances('2027-04-25'));
        // Oficiální rozvaha 504 s nevypořádaným 931 záměrně nevznikne; dubnový stav ukazuje manažerský pohled po účtech.
        $view = $this->container->get(\MyInvoice\Service\Accounting\Reports\FinancialStatementService::class)->accountView($this->supplier, $year2027, '2027-04-25');
        self::assertSame('management', $view['view_kind']);
        self::assertEquals(['unresolved_pending_result' => 730, 'reconciliation_difference' => 0], $view['checks']);
        $assets = $liabilities = 0.0;
        foreach ($view['balances'] as $row) {
            if ($row['closing_debit_balance'] > 0) $assets += $row['closing_debit_balance'];
            else $liabilities -= $row['closing_debit_balance'];
        }
        self::assertEquals([241730, 241730], [$assets, $liabilities]);
        $this->assertSubledgers('2027-04-25');

        $distribution = $closing->profitDistributionPreview($this->supplier, $this->year2026, '2027-06-15');
        $closing->runProfitDistribution($this->supplier, $this->year2026, ['decision_date' => '2027-06-15',
            'decision_reference' => 'Syntetické usnesení shromáždění 2027/06', 'decision_authority' => 'Syntetické shromáždění vlastníků',
            'target_row_version' => $distribution['target_period']['row_version'], 'snapshot_hash' => $distribution['snapshot_hash'],
            'allocations' => array_map(static fn (array $cell): array => ['cell_key' => $cell['key'], 'role' => 'result.retained_profit',
                'amount' => abs($cell['available'])], $distribution['cells'])], ['user_id' => $this->actor, 'posted_by' => $this->actor]);
        $distributed = $april;
        unset($distributed['931']);
        $distributed['932'] = '-730.00';
        ksort($distributed, SORT_STRING);
        self::assertSame($distributed, $this->balances('2027-06-15'));

        // Rok 2027 má jen vyúčtování služeb a vratku kreditu: žádný nový zdanitelný příjem.
        $closedNext = $this->close($year2027, ['status' => 'filing_not_required', 'reason' => 'only_exempt_or_withheld_income',
            'legal_basis' => 'zdp_38mb_b']);
        self::assertNotNull($closedNext['entry_id']);
        self::assertSame([], $this->balances('2027-12-31'));
        $openedNext = $closing->openNext($this->supplier, $year2027, $this->periodVersion($year2027));
        self::assertNotNull($openedNext['entry_id']);
        // Rámec 504 otevírá 932 záměrně do 931 (AccountingRoleProfile::openingAccount), rozvaha 2028 je tedy dubnová.
        self::assertSame($april, $this->balances('2028-01-01', '2028-01-01'));
    }

    /**
     * Hrubé pohledávky 2 000 Kč a kredity 4 500 Kč se shodují s účty i po jednotkách
     * a neslévají se do čistého salda (U06 dluh 2 000 a kredit 3 500).
     */
    private function assertSubledgers(string $date): void
    {
        $house = PropertyInstant::withNormalizationContext(fn (): array => SettlementHistory::inReadScope(function () use ($date): array {
            $reconciliation = new \MyInvoice\Service\Property\Receivables\PropertyReconciliation($this->db);
            $house = $reconciliation->at($this->supplier, $date);
            self::assertTrue($house['matches'], json_encode($house['difference_cents']));
            self::assertSame(['receivable' => '200000', 'credit' => '450000'], $house['subledger_cents']);
            $expected = ['U01' => ['0', '100000'], 'U06' => ['200000', '350000'], 'G12' => ['0', '0']];
            foreach ($expected as $code => [$receivable, $credit]) {
                $unit = $reconciliation->at($this->supplier, $date, $this->f['units'][$code]);
                self::assertTrue($unit['matches'], $code);
                self::assertSame(['receivable' => $receivable, 'credit' => $credit], $unit['subledger_cents'], $code);
            }
            return $house;
        }));
        $this->assertPartySubledger($date, $house['subledger_cents']);
        $this->assertUnitHistoryVerifiesSettlementsOnce();
    }

    /**
     * Uzávěr historie jednotek vede k témuž vyúčtování přes jeho dluhy i kredity. Jedno
     * čtení ho ověří jednou ({@see \MyInvoice\Service\Property\Services\Settlement\SettlementHistory::inReadScope()}),
     * dřív se celý důkaz vyúčtování opakoval u každého dluhu a kreditu.
     */
    private function assertUnitHistoryVerifiesSettlementsOnce(): void
    {
        $units = array_values($this->f['units']);
        $applications = (int) $this->pdo->query('SELECT COUNT(*) FROM property_service_settlement_applications WHERE supplier_id=' . $this->supplier)->fetchColumn();
        self::assertSame(1, $applications);
        $before = $this->questions();
        $history = (new \MyInvoice\Repository\Property\Receivables\PropertySaldoReadRepository($this->db))->unitHistory($this->supplier, $units);
        self::assertNotSame([], $history['history']['credits']);
        self::assertLessThan(225, $this->questions() - $before - 1);
    }

    private function questions(): int
    {
        return (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
    }

    /**
     * Osobní pohled: nárok patří právnímu dlužníkovi k datu,
     * SJM U01 tvoří jediný řádek a součet řádků se rovná hrubým zůstatkům domu.
     */
    private function assertPartySubledger(string $date, array $house): void
    {
        $service = new \MyInvoice\Service\Property\Receivables\PropertyPartyReconciliation($this->db);
        $names = array_flip($this->people);
        $expected = ['P01a+P01b' => ['0', '100000'], 'P06' => ['200000', '0'], 'Q06' => ['0', '350000']];
        [$order, $cursors] = PropertyInstant::withNormalizationContext(fn (): array => SettlementHistory::inReadScope(function () use ($service, $date, $house, $expected, $names): array {
            $order = null;
            // Limit 1 staví i SJM P01a+P01b na hranu stránky.
            foreach ([1] as $limit) {
                $rows = [];
                $cursors = [];
                $cursor = null;
                do {
                    $page = $service->at($this->supplier, $date, $limit, $cursor);
                    $totals = $cursor === null ? ['receivable' => $house['receivable'], 'credit' => $house['credit']] : null;
                    self::assertSame($totals, $page['totals'], 'Součty domu nese jen první stránka.');
                    self::assertLessThanOrEqual($limit, count($page['items']));
                    foreach ($page['items'] as $item) {
                        $key = implode('+', array_map(fn (int $id) => $names[$id], $item['party_ids']));
                        self::assertArrayNotHasKey($key, $rows, 'Stránkování vrátilo osobu dvakrát.');
                        $rows[$key] = [$item['receivable_cents'], $item['credit_cents']];
                    }
                    $cursor = $page['next_cursor'];
                    if ($cursor !== null) $cursors[$key] = $cursor;
                } while ($cursor !== null);
                $order ??= array_keys($rows);
                ksort($rows);
                self::assertSame($expected, $rows, 'limit ' . $limit);
            }
            return [$order, $cursors];
        }));
        $this->assertPartyPageReadsOnlyItsUnits($service, $date, $expected, $order, $cursors);
    }

    /**
     * Stránka čte historii jen jednotek svých osob: poškozený cizí kredit nesmí
     * shodit stránku jiné množiny osob. Dřívější čtení celé firmy tady padalo na kontrole
     * původu kreditu. Stránka, která poškozenou jednotku obsahuje, i první stránka se součty domu dál
     * poškození odhalí.
     *
     * @param array<string,array{string,string}> $expected řádky podle jmen osob
     * @param list<string> $order pořadí řádků podle jmen osob
     * @param array<string,string> $cursors kurzor za řádkem při limitu 1
     */
    private function assertPartyPageReadsOnlyItsUnits(
        \MyInvoice\Service\Property\Receivables\PropertyPartyReconciliation $service,
        string $date,
        array $expected,
        array $order,
        array $cursors,
    ): void {
        $last = $order[array_key_last($order)];
        $damagedCode = $last === 'P01a+P01b' ? 'U06' : 'U01';
        $healthyCode = $damagedCode === 'U01' ? 'U06' : 'U01';
        $damagedParty = $damagedCode === 'U01' ? 'P01a+P01b' : 'Q06';
        $position = array_search($damagedParty, $order, true);
        self::assertIsInt($position);
        $after = $cursors[$order[count($order) - 2]];
        $credit = (int) $this->pdo->query('SELECT id FROM unit_credit_lots WHERE supplier_id=' . $this->supplier
            . ' AND unit_id=' . $this->f['units'][$damagedCode] . ' ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $credit);
        $this->pdo->exec('SAVEPOINT party_page_corruption');
        $this->pdo->prepare('INSERT INTO unit_credit_parties (supplier_id,credit_id,client_id,party_json) VALUES (?,?,?,?)')
            ->execute([$this->supplier, $credit, $this->people['P12'], json_encode(['id' => $this->people['P12'], 'label' => 'Syntetický vetřelec'], JSON_THROW_ON_ERROR)]);
        $page = $service->at($this->supplier, $date, 1, $after);
        self::assertCount(1, $page['items']);
        self::assertSame($last, implode('+', array_map(fn (int $id) => array_flip($this->people)[$id], $page['items'][0]['party_ids'])));
        self::assertSame($expected[$last], [$page['items'][0]['receivable_cents'], $page['items'][0]['credit_cents']]);
        foreach ([null, $position === 0 ? null : $cursors[$order[$position - 1]]] as $cursor) {
            try {
                $service->at($this->supplier, $date, 1, $cursor);
                self::fail('Poškozená historie jednotky se nesmí ztratit.');
            } catch (\DomainException | \MyInvoice\Service\Property\PropertyException $e) {
                self::assertContains($e instanceof \MyInvoice\Service\Property\PropertyException ? $e->errorCode : $e->getMessage(),
                    ['property_receivable_history_corrupt', 'settlement_credit_origin']);
            }
        }
        $reconciliation = new \MyInvoice\Service\Property\Receivables\PropertyReconciliation($this->db);
        self::assertTrue($reconciliation->at($this->supplier, $date, $this->f['units'][$healthyCode])['matches']);
        foreach ([null, $this->f['units'][$damagedCode]] as $unit) {
            try {
                $reconciliation->at($this->supplier, $date, $unit);
                self::fail('Poškozená historie jednotky se v rekonciliaci nesmí ztratit.');
            } catch (\DomainException | \MyInvoice\Service\Property\PropertyException $e) {
                self::assertContains($e instanceof \MyInvoice\Service\Property\PropertyException ? $e->errorCode : $e->getMessage(),
                    ['property_receivable_history_corrupt', 'settlement_credit_origin']);
            }
        }
        $this->pdo->exec('ROLLBACK TO SAVEPOINT party_page_corruption');
    }

    // ---------------------------------------------------------------- vyúčtování vody 2027


    /** V01 až V03: konečná faktura dosud nevyfakturované části 2026 obrátí přesně původní dohad. */
    private function finalWaterInvoices(): void
    {
        $country = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();
        $currency = (int) $this->pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $this->pdo->prepare('INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$this->supplier, 'Syntetická vodárna', 'Syntetická 1', 'Syntetické město', '10000', $country, 'voda@example.test', $currency]);
        $vendor = (int) $this->pdo->lastInsertId();
        $rate = (int) $this->pdo->query('SELECT id FROM vat_rates WHERE rate_percent=0 ORDER BY id LIMIT 1')->fetchColumn();
        $invoices = $this->container->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class);
        $posting = $this->container->get(PostingService::class);
        $workflow = $this->container->get(\MyInvoice\Service\Property\Services\Settlement\ServiceLifecycleWorkflow::class);
        foreach ($this->waterSegments() as $key => $segment) {
            $invoice = $invoices->createDraft(['vendor_id' => $vendor, 'vendor_invoice_number' => 'VODA-2026-' . $key, 'issue_date' => '2027-04-05',
                'due_date' => '2027-04-30', 'currency_id' => $currency, 'exchange_rate' => 1, 'status' => 'received', 'vat_deduction' => 'none',
                'vendor_is_vat_payer' => false], $this->actor, $this->supplier);
            $invoices->replaceItems($invoice, [['description' => 'Syntetické vodné a stočné 2026 ' . $key, 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => (int) $segment['invoice'], 'vat_rate_id' => $rate, 'expense_kind' => 'service']]);
            (new \MyInvoice\Service\Invoice\PurchaseInvoiceCalculator($this->db))->recompute($invoice);
            $this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class)
                ->saveDocument($this->supplier, 'purchase_invoice', $invoice, [], [1 => $this->serviceDimensions($key)]);
            // Návrh zaúčtování bere účet z rozvahového profilu služby, ne z předkontace služeb 518.
            $lines = $posting->buildFromPurchaseInvoice($this->supplier, $invoice);
            self::assertSame(['315.210'], array_values(array_unique(array_column(array_filter($lines, fn ($l) => $l['side'] === 'debit'), 'account_code'))), $key);
            $entry = $posting->postDocument($this->supplier, 'purchase_invoice', $invoice, $lines,
                ['entry_date' => '2027-04-05', 'posted_by' => $this->actor]);
            $command = ['operation' => 'finalize', 'case_id' => $this->estimateCase[$key], 'accounting_date' => '2027-04-05', 'invoice_id' => $invoice,
                'line_id' => $this->entryLine($entry, 'debit'), 'title' => 'Syntetické konečné rozúčtování dodávky vody 2026'];
            $preview = $workflow->preview($this->supplier, $command, $this->actor);
            $workflow->execute($this->supplier, $command, $this->actor, $preview['expected_state'], $preview['preview_hash'], 'water-final-' . $key);
        }
        self::assertSame(['221' => '246030.00', '315.130' => '2000.00', '315.210' => '11000.00', '321' => '-3800.00',
            '324.110' => '-12000.00', '324.200' => '-1000.00', '379.910' => '-6000.00', '379.920' => '-10500.00', '931' => '-730.00', '955.100' => '-225000.00'],
            $this->balances('2027-04-05'));
        $this->post('2027-04-10', [['321', 'debit', '3800.00'], ['221', 'credit', '3800.00']]);
    }

    /** V04 až V07: vyúčtování po právních segmentech, žádné globální minimum za dům. */
    private function settleWater(): void
    {
        $adapter = $this->container->get(\MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter::class);
        $documents = $this->container->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);
        $applications = $this->container->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
        $date = '2027-04-20';
        $totals = ['advance_applied_cents' => '0', 'new_claim_cents' => '0', 'cancelled_unpaid_cents' => '0', 'new_credit_cents' => '0'];
        foreach ($this->waterSegments() as $key => $segment) {
            $selector = ['kind' => 's3_line', 'id' => $this->lines[$key]['WATER'],
                'cost_sources' => [['kind' => 'document', 'id' => $this->knownDocuments[$key]], ['kind' => 'lifecycle', 'id' => $this->estimateCase[$key]]]];
            $current = $adapter->current($this->supplier, $this->f['units'][substr($key, 0, 3)], $this->f['service']['id'], $date, [$selector]);
            $actual = bcadd(bcmul($segment['known'], '100', 0), bcmul($segment['invoice'], '100', 0), 0);
            $facts = $this->documentFacts($key, $segment, 'FINAL-2026-' . $key, self::instant($date, '09:00:00'), $current, $actual);
            $document = \MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts);
            $saved = $documents->save($this->supplier, $document, [$selector], $date, 0, 'final-' . $key, $this->actor);
            $amounts = $document->snapshot()['amounts'];
            foreach ($totals as $name => $sum) $totals[$name] = bcadd($sum, $amounts[$name], 0);
            $request = $this->applicationRequest($key, $segment, $date, $current, $amounts, $facts['recipient_ids']);
            $preview = $applications->preview($this->supplier, $saved['id'], $request, 0);
            $applications->apply($this->supplier, $saved['id'], $request, 0, $preview['preview_hash'], 'settle-' . $key, $this->actor);
        }
        self::assertSame(['advance_applied_cents' => '1100000', 'new_claim_cents' => '0', 'cancelled_unpaid_cents' => '0', 'new_credit_cents' => '100000'], $totals);
    }

    private function applicationRequest(string $key, array $segment, string $date, array $current, array $amounts, array $recipients): array
    {
        $origin = $current['origins'][0];
        $reliefs = [];
        $credit = $amounts['new_credit_cents'];
        $allocations = array_filter($current['allocations'], fn ($a) => $a['active']);
        usort($allocations, fn ($a, $b) => bccomp($b['amount_cents'], $a['amount_cents'], 0) ?: $a['id'] <=> $b['id']);
        foreach ($allocations as $allocation) {
            if ($credit === '0') break;
            $take = bccomp($credit, $allocation['amount_cents'], 0) < 0 ? $credit : $allocation['amount_cents'];
            $reliefs[] = ['allocation_id' => $allocation['id'], 'amount_cents' => $take, 'entitlement' => ['party_ids' => $recipients,
                'evidence' => 'Syntetický kredit oprávněných příjemců služby', 'due_date' => '2027-06-30', 'account_code' => '379.910']];
            $credit = bcsub($credit, $take, 0);
        }
        $request = ['accounting_date' => $date, 'evidence' => 'Syntetické doručené vyúčtování vody 2026',
            'advances' => [['origin_key' => $origin['kind'] . ':' . $origin['id'], 'applied_cents' => $amounts['advance_applied_cents']]],
            'reliefs' => $reliefs, 'opening_credits' => [], 'new_claim' => null];
        if ($amounts['new_claim_cents'] !== '0') {
            $at = self::instant($date, '12:00:00');
            $groups = $segment['person'] !== null ? [$this->group('OWNER-' . $segment['person'], $segment['person'], $at)] : $this->ownerGroups($key, $at);
            $request['new_claim'] = ['party_ids' => $recipients, 'evidence' => 'Syntetický nový rozdílový nárok z vyúčtování', 'due_date' => '2027-06-30', 'account_code' => '315.300'];
            $request['claim_title'] = ['effective_at' => $at, 'title_ref' => 'Syntetické vyúčtování služby 2026', 'liability_groups' => $groups,
                'legal' => ['basis' => 'legal_act', 'legal_act_date' => $date, 'evidence' => 'Syntetické doručené vyúčtování', 'regime' => 'general'],
                'security' => ['kind' => 'unsecured', 'evidence' => 'Syntetické posouzení bez zajištění']];
        }
        return $request;
    }

    /** V08: částečná výplata starého kreditu Q06 přes rezervaci a bankovní událost. */
    private function refundOldCredit(): void
    {
        $credit = (int) $this->pdo->query('SELECT id FROM unit_credit_lots WHERE supplier_id=' . $this->supplier . ' AND opening_item_id IS NOT NULL')->fetchColumn();
        $payments = $this->container->get(PaymentRepository::class);
        $this->documentRefundAccount($this->people['Q06']);
        $reservation = fn (string $amount) => ['amount_cents' => $amount, 'party_ids' => [$this->people['Q06']],
            'disposition' => 'Syntetická dispozice Q06 k výplatě kreditu', 'recipient_account' => '1000000005/0100'];
        $reserved = $payments->reserve($this->supplier, $credit, $payments->credit($this->supplier, $credit)['row_version'], $reservation('250000'), 'q06-reserve', $this->actor);
        $state = $payments->credit($this->supplier, $credit);
        self::assertSame(['600000', '250000', '350000'], [$state['balance_cents'], $state['reserved_cents'], $state['available_cents']]);
        try {
            $payments->reserve($this->supplier, $credit, $state['row_version'], $reservation('500000'), 'q06-reserve-over', $this->actor);
            self::fail('Souběžná rezervace překročila kredit Q06.');
        } catch (\MyInvoice\Service\Property\Payments\PaymentException $e) {
            self::assertNotSame('', $e->errorCode);
        }
        $request = ['source' => ['kind' => 'bank', 'id' => $this->rawBank('2027-04-25', '-2500.00')],
            'clearing_cells' => [['key' => 'refund', 'amount_cents' => '250000', 'dimensions' => []]],
            'parts' => [['key' => 'refund-q06', 'request' => ['kind' => 'refund', 'amount_cents' => '250000', 'accounting_date' => '2027-04-25',
                'reason' => 'Syntetická výplata kreditu Q06', 'reservation_id' => $reserved['id'], 'expected_reservation_version' => $reserved['row_version']]]]];
        $facade = $this->container->get(\MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowFacade::class);
        $preview = $facade->preview($this->supplier, $request);
        $result = $facade->confirm($this->supplier, $request, $preview['preview_hash'], 'q06-refund', $this->actor);
        self::assertSame($result, $facade->confirm($this->supplier, $request, $preview['preview_hash'], 'q06-refund', $this->actor));
        $state = $payments->credit($this->supplier, $credit);
        self::assertSame(['350000', '0', '350000'], [$state['balance_cents'], $state['reserved_cents'], $state['available_cents']]);
    }

    // ---------------------------------------------------------------- uzávěrka

    private function periodVersion(int $period): int
    {
        $q = $this->pdo->prepare('SELECT row_version FROM accounting_periods WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplier, $period]);
        return (int) $q->fetchColumn();
    }

    /**
     * Uzávěrka produkčním postupem; daňové posouzení 504 je potvrzené před uzavřením knih.
     * Předkontrola SVJ musí po potvrzení ukázat očekávaný výsledek testu § 38mb písm. b).
     */
    private function close(int $period, array $expectedPrecheck): array
    {
        $closing = $this->container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        $meta = ['user_id' => $this->actor, 'posted_by' => $this->actor];
        $closing->start($this->supplier, $period, $this->periodVersion($period), $meta);
        $items = [];
        foreach ($closing->inventoryPreview($this->supplier, $period)['rows'] as $row) {
            $items[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $closing->saveInventory($this->supplier, $period, $this->periodVersion($period), ['complete' => true], $items, $meta);
        $closing->confirmStep($this->supplier, $period, 'depreciation', 'skipped', null, $this->periodVersion($period), $meta);
        $fx = $closing->fxPreview($this->supplier, $period);
        $closing->runFxRevaluation($this->supplier, $period, [], $this->periodVersion($period), $meta, ['snapshot_hash' => $fx['snapshot_hash'], 'account_decisions' => []]);
        foreach (['estimates', 'deferrals', 'provisions', 'income_tax'] as $step) {
            $closing->confirmStep($this->supplier, $period, $step, 'skipped', null, $this->periodVersion($period), $meta);
        }
        $closing->runStockValuation($this->supplier, $period, $this->periodVersion($period), $meta);
        // Splatná daň SVJ je nulová (krok 591/341 se přeskakuje), ale daňová předkontrola
        // příjmů 504 se přeskočit nedá: bez potvrzeného posouzení knihy neuzavře.
        try {
            $closing->closeBooks($this->supplier, $period, $this->periodVersion($period), $meta);
            self::fail('Uzávěrka SVJ prošla bez daňové předkontroly.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) {
            self::assertSame('nonprofit_tax_assessment_required', $e->errorCode);
        }
        $guard = $this->container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentGuard::class);
        $pending = $guard->closingCheck($this->supplier, $period);
        self::assertFalse($pending['complete']);
        self::assertSame(['status' => 'unassessed', 'reason' => 'assessment_incomplete', 'legal_basis' => 'zdp_38mb_b', 'taxable_source_count' => 0],
            $pending['svj_precheck'], 'Bez potvrzeného posouzení předkontrola nesmí dovodit nepodání.');
        \MyInvoice\Tests\Support\NonprofitTaxAssessmentFixture::confirm($this->container, $this->supplier, $period, $this->actor);
        $check = array_values(array_filter($closing->runPrecheck($this->supplier, $period, $this->periodVersion($period), $meta)['checks'] ?? [],
            fn (array $c): bool => $c['key'] === 'nonprofit_tax_assessment'));
        self::assertCount(1, $check);
        $precheck = $check[0]['value']['svj_precheck'];
        $taxableSources = $precheck['taxable_source_count'];
        unset($precheck['taxable_source_count']);
        self::assertSame($expectedPrecheck, $precheck);
        if ($expectedPrecheck['status'] === 'filing_required') self::assertGreaterThan(0, $taxableSources);
        else self::assertSame(0, $taxableSources);
        return $closing->closeBooks($this->supplier, $period, $this->periodVersion($period), $meta);
    }

    /** Rozvaha a výsledovka 504 z produkčního výkazu, řádky s nenulovou hodnotou na nejnižší úrovni. */
    private function assertStatements(int $period, string $asOf, int $total, int $start, array $assets, array $liabilities, array $income): void
    {
        $statements = $this->container->get(\MyInvoice\Service\Accounting\Reports\FinancialStatementService::class);
        $balance = $statements->balanceSheet($this->supplier, $period, $asOf, 'full');
        self::assertSame('cz_504', $balance['accounting_framework']);
        // Výkaz vrací částky jako float, porovnání proto bez ohledu na typ.
        self::assertEquals(['assets_start' => $start, 'assets_end' => $total, 'liabilities_start' => $start, 'liabilities_end' => $total], $balance['totals']);
        self::assertTrue($balance['checks']['balanced']);
        foreach (['assets' => $assets, 'liabilities' => $liabilities] as $side => $expected) {
            $rows = [];
            foreach ($balance[$side] as $row) {
                if ((float) $row['end'] !== 0.0 && preg_match('/^[A-Z]\.[IV]+\.[0-9]+\.$/D', $row['display_code'])) $rows[$row['display_code']] = $row['end'];
            }
            self::assertEquals($expected, $rows, $side . ' ' . $asOf);
        }
        $rows = [];
        foreach ($statements->incomeStatement($this->supplier, $period, $asOf, 'full')['rows'] as $row) {
            if ((float) $row['total'] !== 0.0 && (preg_match('/^[A-Z]\.[IV]+\.[0-9]+\.$/D', $row['display_code']) || in_array($row['display_code'], ['C.', 'D.'], true))) {
                $rows[$row['display_code']] = $row['total'];
            }
        }
        self::assertEquals($income, $rows, 'income ' . $asOf);
    }

    /** Konečné zůstatky 2026 před závěrkou (obratová předvaha referenčního příkladu). */
    private const TRIAL_BALANCE_2026 = ['221' => '246030.00', '315.130' => '2000.00', '315.210' => '7200.00',
        '324.110' => '-12000.00', '324.200' => '-1000.00', '379.910' => '-6000.00', '379.920' => '-10500.00',
        '388.210' => '4500.00', '389.110' => '-4500.00', '518.200' => '2000.00', '518.900' => '60.00',
        '549.900' => '90000.00', '549.910' => '150000.00', '644.100' => '-790.00', '649.100' => '-240000.00',
        '649.200' => '-2000.00', '955.100' => '-225000.00'];

    // ---------------------------------------------------------------- náklady a jejich krytí 2026

    private function costsAndCoverage(): void
    {
        $this->post('2026-02-25', [['518.900', 'debit', '60.00', true], ['321', 'credit', '60.00']]);
        $this->post('2026-03-05', [['321', 'debit', '60.00'], ['221', 'credit', '60.00']]);
        $admin = $this->post('2026-06-30', [['518.200', 'debit', '2000.00', true], ['321', 'credit', '2000.00']]);
        $this->post('2026-07-05', [['321', 'debit', '2000.00'], ['221', 'credit', '2000.00']]);
        $this->draw('2026-06-30', 'administration', '200000', $this->entryLine($admin, 'debit'), 'issued', 'admin-2026');
        $repair = $this->post('2026-07-15', [['549.900', 'debit', '90000.00', true], ['321', 'credit', '90000.00']]);
        $this->post('2026-07-20', [['321', 'debit', '90000.00'], ['221', 'credit', '90000.00']]);
        $this->draw('2026-07-15', 'repair', '9000000', $this->entryLine($repair, 'debit'), 'opening', 'repair-2026');
        $improvement = $this->post('2026-08-20', [['549.910', 'debit', '150000.00', true], ['321', 'credit', '150000.00']]);
        $this->post('2026-08-25', [['321', 'debit', '150000.00'], ['221', 'credit', '150000.00']]);
        $this->draw('2026-08-20', 'improvement', '15000000', $this->entryLine($improvement, 'debit'), 'opening', 'improvement-2026');
    }

    /** Čerpání záloh na náklad podle klíče z registru k datu nákladu. */
    private function draw(string $date, string $classification, string $amount, int $cost, string $sourceKind, string $key): void
    {
        $admin = $classification === 'administration';
        $fundingKey = ['mode' => 'at_accounting_date', 'at' => \MyInvoice\Service\Property\Funds\FundingSourceProof::instant($date), 'method' => $admin ? 'equal' : 'share',
            'legal_basis' => $admin ? 'own_administration_equal' : 'management_share', 'evidence_ref' => 'Syntetický schválený klíč rozúčtování'];
        $request = $this->fundingRequest('draw', $date, $amount) + ['classification' => $classification, 'ownership' => 'owners_common_parts',
            'cost_line_id' => $cost, 'key' => $fundingKey, 'other_coverage' => [], 'sources' => []];
        $registry = (new \MyInvoice\Service\Property\Funds\FundingSourceProof($this->db))->registry($this->supplier, $this->f['house']['id'], $date, $fundingKey, $amount);
        $available = (new \MyInvoice\Service\Property\Funds\FundingRead($this->db))->at($this->supplier, $this->f['house']['id'], $date)['sources'];
        foreach ($registry['calculation']['lines'] as $allocation) {
            foreach ($available as $candidate) {
                if ($candidate['unit_id'] !== $allocation['unit_id'] || $candidate['source']['kind'] !== $sourceKind) continue;
                if ($admin ? $candidate['account_code'] !== '324.200' : !str_starts_with($candidate['account_code'], '955')) continue;
                $request['sources'][] = ['source' => $candidate['source'], 'amount_cents' => $allocation['amount_cents'],
                    'purpose_evidence' => 'Syntetický soulad účelu zálohy s nákladem'];
                break;
            }
        }
        $this->fund($request, $key);
    }

    /** H01 až H03: prosincový nájem střechy patří prosincovým vlastníkům; část jde na dlouhé zálohy, část se vyplatí. */
    private function roofRent(): void
    {
        $date = '2026-12-05';
        $key = ['mode' => 'at_accounting_date', 'at' => \MyInvoice\Service\Property\Funds\FundingSourceProof::instant($date), 'method' => 'share',
            'legal_basis' => 'management_share', 'evidence_ref' => 'Syntetický klíč podílů na společných částech'];
        $income = $this->fund($this->fundingRequest('income', $date, '6000000') + ['bank_id' => $this->rawBank($date, '60000.00'), 'key' => $key], 'roof-income');
        $lots = [];
        foreach ($income['receipt']['effects'] as $effect) $lots[$effect['unit_id']][] = $effect;
        $dispositions = [];
        foreach ($lots as $unitLots) foreach ($unitLots as $lot) {
            $dispositions[] = ['source_id' => $lot['id'], 'amount_cents' => bcdiv(bcmul($lot['amount_cents'], '3', 0), '4', 0),
                'party_ids' => $lot['parties']['members'], 'evidence_ref' => 'Syntetická dispozice vlastníků s podílem na nájemném'];
        }
        $this->fund($this->fundingRequest('transfer', '2026-12-10', '4500000') + ['dispositions' => $dispositions, 'target' => 'long_term',
            'purpose_ref' => 'Syntetické schválené práce 2028/2029', 'settlement_due_on' => '2029-12-31'], 'roof-transfer');
        $lot = $lots[$this->f['units']['U01']][0];
        $payout = bcdiv(bcmul($lot['amount_cents'], '1', 0), '6', 0);
        $recipient = $lot['parties']['members'][0];
        $this->documentRefundAccount($recipient);
        $this->fund($this->fundingRequest('payout', '2026-12-15', $payout) + ['bank_id' => $this->rawBank('2026-12-15', '-' . bcdiv($payout, '100', 2)),
            'recipient_id' => $recipient, 'recipient_account' => '1000000005/0100',
            'dispositions' => [['source_id' => $lot['id'], 'amount_cents' => $payout, 'party_ids' => $lot['parties']['members'],
                'evidence_ref' => 'Syntetický výplatní pokyn vlastníka']]], 'roof-payout-' . $lot['id']);
        $catalog = (new \MyInvoice\Repository\Property\Funds\FundingRepository($this->db))->list($this->supplier, $this->f['house']['id']);
        self::assertSame($this->fundingRevision, $catalog['revision']);
        self::assertCount($this->fundingRevision, $catalog['items']);
    }

    private function fundingRequest(string $kind, string $date, string $amount): array
    {
        return ['kind' => $kind, 'house_id' => $this->f['house']['id'], 'accounting_date' => $date,
            'expected_revision' => $this->fundingRevision,
            'evidence_ref' => 'Syntetické doložené rozhodnutí', 'reason' => 'Syntetická operace ' . $kind, 'amount_cents' => $amount];
    }

    private function fund(array $request, string $key): array
    {
        $owner = new \MyInvoice\Repository\Property\Funds\FundingRepository($this->db);
        $operation = $owner->apply($this->supplier, $request, $owner->preview($this->supplier, $request, $this->actor)['preview_hash'], $key, $this->actor);
        $this->fundingRevision = $operation['revision'];
        return $operation;
    }

    private function documentRefundAccount(int $client): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO client_bank_accounts (supplier_id,client_id,account_number,bank_code,account_key,bank_key,source_manual) VALUES (?,?,?,?,?,?,1)')
            ->execute([$this->supplier, $client, '1000000005', '0100', '1000000005', '0100']);
    }

    /** Nespárovaný pohyb výpisu bez zaúčtování; zaúčtuje ho až operace, která ho použije. */
    private function rawBank(string $date, string $amount): int
    {
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Syntetický výpis SVJ',?,'2000000042','0100','CZK',?)")
            ->execute([$this->supplier, hash('sha256', random_bytes(20)), $date]);
        $statement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,?,?,'CZK','1000000005','0100')")
            ->execute([$statement, $date, $amount]);
        return (int) $this->pdo->lastInsertId();
    }

    // ---------------------------------------------------------------- voda 2026

    private array $knownCost = [];
    private array $estimateCase = [];

    /** W01 známé ceny a W03 dohad po jednotkách a segmentech; dohad patří do životního cyklu služby. */
    private function waterCosts(): void
    {
        $lifecycle = new \MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository($this->db);
        foreach ($this->waterSegments() as $key => $segment) {
            $dims = $this->serviceDimensions($key);
            $known = $this->postDims('2026-12-20', '315.210', '321', $segment['known'], $dims);
            $this->knownCost[$key] = $this->entryLine($known, 'debit');
            // Známou cenu váže doklad dřív, než vznikne případ dohadu; potom už jednotlivý náklad musí projít konečným zúčtováním.
            $this->knownDocument($key, $segment);
            // Dohad musí ležet uvnitř zúčtovacího období případu.
            $estimate = $this->postDims($segment['to'], '388.210', '389.110', $segment['estimate'], $dims);
            $unit = $this->f['units'][substr($key, 0, 3)];
            $case = $lifecycle->openCase($this->supplier, $unit, $this->f['service']['id'], $segment['from'], $segment['to'], 'service_balance_sheet',
                'Syntetická schválená metoda rozvahového profilu služeb', $this->actor, '315.210');
            $lifecycle->registerEstimate($this->supplier, $case, [$estimate], 'Syntetický odhad nevyfakturované dodávky vody');
            $this->estimateCase[$key] = $case;
        }
        $this->post('2026-12-28', [['321', 'debit', '7200.00'], ['221', 'credit', '7200.00']]);
    }

    /** Doklad ke známé ceně vody roku 2026; zatím se neuplatní, ale váže náklad na jednotku a segment. */
    private function knownDocument(string $key, array $segment): void
    {
        $unit = $this->f['units'][substr($key, 0, 3)];
        $selector = ['kind' => 's3_line', 'id' => $this->lines[$key]['WATER'], 'cost_line_id' => $this->knownCost[$key]];
        $adapter = $this->container->get(\MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter::class);
        $current = $adapter->current($this->supplier, $unit, $this->f['service']['id'], '2026-12-31', [$selector]);
        $facts = $this->documentFacts($key, $segment, 'KNOWN-2026-' . $key, self::instant('2026-12-31', '12:00:00'), $current, bcmul($segment['known'], '100', 0));
        $document = \MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts);
        $this->knownDocuments[$key] = $this->container->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class)
            ->save($this->supplier, $document, [$selector], '2026-12-31', 0, 'known-' . $key, $this->actor)['id'];
    }

    private array $knownDocuments = [];

    /** Podklad vyúčtování segmentu: předpis, úhrady do konce segmentu, příjemci služby a náklad. */
    private function documentFacts(string $key, array $segment, string $segmentKey, string $knownAt, array $current, string $actual): array
    {
        $cutoff = self::instant($segment['to'], '23:59:59');
        $paid = '0';
        $allocations = [];
        foreach ($current['allocations'] as $allocation) {
            if (!$allocation['active'] || $allocation['accounting_date'] > $segment['to']) continue;
            $allocations[] = array_intersect_key($allocation, array_flip(['id', 'origin_key', 'source_hash', 'amount_cents']));
            $paid = bcadd($paid, $allocation['amount_cents'], 0);
        }
        $recipients = $segment['person'] !== null
            ? [$this->people[$segment['person']]]
            : array_merge(...array_column($this->f['states'][$key]['ownerships'], 'members'));
        return ['supplier_id' => $this->supplier, 'unit_id' => $this->f['units'][substr($key, 0, 3)], 'service_id' => $this->f['service']['id'],
            'segment_key' => $segmentKey, 'period_from' => $segment['from'], 'period_to' => $segment['to'], 'cutoff_at' => $cutoff, 'known_at' => $knownAt,
            'recipient_ids' => $recipients, 'evidence' => 'Syntetické odečty, klíč a podklady vyúčtování vody',
            'prescribed_cents' => $current['origins'][0]['prescribed_cents'], 'paid_cents' => $paid, 'actual_cents' => $actual,
            'origins' => $current['origins'], 'cutoff_allocations' => $allocations, 'cost' => array_values($current['costs'])[0]];
    }

    /** @return array<string,array{from:string,to:string,known:string,estimate:string,invoice:string,person:string}> */
    private function waterSegments(): array
    {
        return [
            'U01' => ['from' => '2026-01-01', 'to' => '2026-12-31', 'known' => '7200.00', 'estimate' => '4500.00', 'invoice' => '3800', 'person' => null],
        ];
    }

    /** Dimenze jednotky a služby z předpisu zálohy doplněné o činnost SVJ. */
    private function serviceDimensions(string $key): array
    {
        if (isset($this->serviceDims[$key])) return $this->serviceDims[$key];
        $line = $this->lines[$key]['WATER'];
        $current = $this->container->get(\MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter::class)
            ->current($this->supplier, $this->f['units'][substr($key, 0, 3)], $this->f['service']['id'], '2026-12-31', [['kind' => 's3_line', 'id' => $line]]);
        return $this->serviceDims[$key] = $current['origins'][0]['advance_line']['dimensions'] + $this->activityDimensions();
    }

    /** @var array<string,array<int,int>> dimenze zjištěné v otevřeném roce 2026 */
    private array $serviceDims = [];

    private function postDims(string $date, string $debit, string $credit, string $amount, array $dims): int
    {
        $origins = array_fill_keys(array_keys($dims), ['origin' => 'explicit', 'ref' => null]);
        return $this->container->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount, 'dimensions' => $dims, 'dimension_origins' => $origins],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount, 'dimensions' => $dims, 'dimension_origins' => $origins],
        ], ['entry_date' => $date, 'posted_by' => $this->actor]);
    }

    // ---------------------------------------------------------------- úhrady 2026

    /** Řádné roční úhrady; plné úhrady páruje návrh z banky podle variabilního symbolu jednotky. */
    private function annualPayments(): void
    {
        $this->proposalPayment('U01', 'P01a', '2026-01-20');
        $this->pay('2026-01-20', 'P12', 'P12', [['G12', null]], 'garage-january');
        $this->pay('2026-01-20', 'P06', 'P06', [['U06', ['LONG' => '5200000', 'ADMIN' => '100000']]], 'u06-january');
    }

    private function proposalPayment(string $code, string $person, string $date): void
    {
        $total = (new DebtRepository($this->db))->current($this->supplier, $this->debts[$code])['snapshot']['amount_cents'];
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Syntetický výpis SVJ',?,'2000000042','0100','CZK',?)")
            ->execute([$this->supplier, hash('sha256', random_bytes(20)), $date]);
        $statement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,variable_symbol,counterparty_name,description) VALUES (?,?,?,'CZK',?,'Syntetický plátce','Syntetická záloha')")
            ->execute([$statement, $date, bcdiv($total, '100', 2), (string) (int) substr($code, 1)]);
        $tx = (int) $this->pdo->lastInsertId();
        $service = $this->container->get(\MyInvoice\Service\Property\Payments\BankProposalService::class);
        self::assertSame(1, $service->afterImport($this->supplier, [$tx]));
        $plan = $service->plan($this->supplier, $tx);
        if ($plan['review'] !== null) $plan = $service->plan($this->supplier, $tx, ['debtor_id' => $this->people[$person]]);
        self::assertNull($plan['review'], $code . ': ' . json_encode($plan['review']));
        self::assertSame($this->f['units'][$code], $plan['resolution']['unit_id']);
        $result = $this->container->get(\MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowFacade::class)
            ->confirm($this->supplier, $plan['request'], $plan['preview']['preview_hash'], 'proposal-' . $code, $this->actor);
        self::assertSame($total, $result['settled_cents'], $code);
    }

    /**
     * Úhrada s určením dluhů plátcem. Část je buď celý zbytek dluhu (null), nebo určené
     * rozdělení jistiny na řádky předpisu podle složek, nebo částka dluhu s jediným řádkem.
     *
     * @param list<array{0:string,1:null|string|array<string,string>}> $parts
     */
    private function pay(string $date, string $payer, string $debtor, array $parts, string $key, array $payment = []): void
    {
        $ids = [];
        $amount = '0';
        $expected = [];
        $distribution = [];
        $reader = new DebtRepository($this->db);
        foreach ($parts as [$code, $lines]) {
            $id = $this->debts[$code];
            $ids[] = $id;
            if (is_string($lines)) {
                $cents = $lines;
            } elseif ($lines === null) {
                $debt = $reader->current($this->supplier, $id);
                $cents = $debt['snapshot']['amount_cents'];
                foreach ($debt['paid'] as $paid) $cents = bcsub($cents, $paid, 0);
                foreach ($debt['accessories'] as $accessory) $cents = bcadd($cents, $accessory['amount_cents'], 0);
            } else {
                $cents = array_reduce($lines, fn ($sum, $value) => bcadd($sum, $value, 0), '0');
                $distribution[$id] = ['evidence' => 'Syntetické určení složek dlužníkem', 'lines' => array_map(
                    fn ($component, $value) => ['line_id' => $this->lines[$code][$component], 'amount_cents' => $value], array_keys($lines), array_values($lines))];
            }
            $expected[$id] = $cents;
            $amount = bcadd($amount, $cents, 0);
        }
        $bank = $this->bank($date, bcdiv($amount, '100', 2));
        $payment = ['payer_id' => $this->people[$payer], 'debtor_id' => $this->people[$debtor], 'received_at' => self::instant($date, '10:00:00'),
            'choice' => ['claim_ids' => $ids, 'evidence' => 'Syntetické určení dluhů plátcem']] + $payment;
        if ($distribution !== []) $payment['principal_distribution'] = $distribution;
        $request = ['kind' => 'allocation', 'source' => ['kind' => 'bank', 'id' => $bank, 'expected_version' => 0], 'amount_cents' => $amount,
            'accounting_date' => $date, 'reason' => 'Syntetická úhrada záloh', 'debts' => $ids, 'payment' => $payment];
        $payments = $this->container->get(PaymentRepository::class);
        $done = $payments->apply($this->supplier, $request, $payments->preview($this->supplier, $request)['preview_hash'], 'pay-' . $key, $this->actor);
        self::assertSame($expected, $this->allocatedByDebt($done), $key);
    }

    private function allocatedByDebt(array $operation): array
    {
        $out = [];
        foreach ($operation['snapshot']['allocations'] as $allocation) {
            $out[$allocation['debt_id']] = bcadd($out[$allocation['debt_id']] ?? '0', $allocation['amount_cents'], 0);
        }
        return $out;
    }

    /** Pohyb na výpisu SVJ zaúčtovaný na banku a nespárované platby jako při importu výpisu. */
    private function bank(string $date, string $amount): int
    {
        $this->pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Syntetický výpis SVJ',?,'2000000042','0100','CZK',?)")
            ->execute([$this->supplier, hash('sha256', random_bytes(20)), $date]);
        $statement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,?,?,'CZK','1000000005','0100')")
            ->execute([$statement, $date, $amount]);
        $id = (int) $this->pdo->lastInsertId();
        $out = str_starts_with($amount, '-');
        $this->container->get(PostingService::class)->postDocument($this->supplier, 'bank', $id, [
            ['account_code' => '221', 'side' => $out ? 'credit' : 'debit', 'amount' => ltrim($amount, '-')],
            ['account_code' => '379.900', 'side' => $out ? 'debit' : 'credit', 'amount' => ltrim($amount, '-')],
        ], ['entry_date' => $date, 'posted_by' => $this->actor]);
        return $id;
    }

    // ---------------------------------------------------------------- deník

    /** @param list<array{0:string,1:string,2:string,3?:bool}> $lines účet, strana, částka, výsledkový řádek s dimenzemi činnosti */
    private function post(string $date, array $lines): int
    {
        $out = [];
        foreach ($lines as $line) {
            $row = ['account_code' => $line[0], 'side' => $line[1], 'amount' => $line[2]];
            if ($line[3] ?? false) {
                $row['dimensions'] = $this->activityDimensions();
                $row['dimension_origins'] = array_fill_keys(array_keys($row['dimensions']), ['origin' => 'explicit', 'ref' => null]);
            }
            $out[] = $row;
        }
        return $this->container->get(PostingService::class)->postDocument($this->supplier, 'manual', null, $out, ['entry_date' => $date, 'posted_by' => $this->actor]);
    }

    private ?array $activity = null;

    private function activityDimensions(): array
    {
        if ($this->activity === null) {
            $cat = (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2026-01-01');
            $kind = $this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class)
                ->createValue($this->supplier, $cat['activity_kind']['id'], ['code' => 'SPRAVA-DOMU', 'name' => 'Syntetická správa domu'], $this->actor);
            $this->activity = [$cat['activity']['id'] => $cat['activity']['values']['main'], $cat['activity_kind']['id'] => (int) $kind['id'],
                $cat['tax_regime']['id'] => $cat['tax_regime']['values']['subject']];
        }
        return $this->activity;
    }

    private function entryLine(int $entry, string $side): int
    {
        $q = $this->pdo->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? AND side=? ORDER BY id LIMIT 1');
        $q->execute([$this->supplier, $entry, $side]);
        return (int) $q->fetchColumn();
    }

    // ---------------------------------------------------------------- počáteční stavy

    private function opening(): void
    {
        $units = $this->f['units'];
        $items = [];
        foreach ($units as $code => $unit) {
            $share = $this->f['states'][$code]['attributes']['share'];
            $amount = bcdiv(bcmul('30000000', $share['num'], 0), $share['den'], 0);
            $items[] = ['unit_id' => $unit, 'service_id' => null, 'origin_at' => '2026-01-01T00:00:00+01:00', 'period_from' => '2026-01-01',
                'period_to' => '2026-01-01', 'occurrence_ref' => '2026', 'case_ref' => 'long-' . $code, 'kind' => 'advance',
                'title_ref' => 'Syntetické převzetí dlouhodobých záloh', 'evidence_ref' => 'Syntetická odsouhlasená evidence záloh',
                'components' => [['key' => 'long', 'role' => 'long_term_advance', 'account_code' => '955.100', 'amount_cents' => $amount]],
                'advance_state' => ['purpose_ref' => 'Syntetické schválené práce 2028/2029', 'settlement_due_on' => '2029-12-31',
                    'prescribed_cents' => $amount, 'paid_cents' => $amount, 'used_cents' => '0', 'unpaid_cases' => []]];
        }
        $items[] = ['unit_id' => $units['U06'], 'service_id' => null, 'origin_at' => '2026-01-01T00:00:00+01:00', 'period_from' => '2025-01-01',
            'period_to' => '2025-12-31', 'due_date' => '2026-06-30', 'occurrence_ref' => '2025', 'case_ref' => 'CR-Q06', 'kind' => 'credit',
            'title_ref' => 'Syntetický vyúčtovaný kredit bývalého vlastníka', 'evidence_ref' => 'Syntetický výplatní titul Q06',
            'creditor_ids' => [$this->people['Q06']],
            'components' => [['key' => 'credit', 'role' => 'credit', 'account_code' => '379.910', 'amount_cents' => '600000']]];
        $request = ['mode' => 'post_new', 'accounting_date' => '2026-01-01', 'effective_at' => '2026-01-01T00:00:00+01:00', 'expected_revision' => 0,
            'evidence_ref' => 'Syntetická počáteční rozvaha', 'reason' => 'Syntetické otevření SVJ', 'items' => $items,
            'other_balances' => [['account_code' => '221', 'side' => 'debit', 'amount_cents' => '30600000', 'dimensions' => []]]];
        $owner = $this->container->get(OpeningRepository::class);
        $owner->apply($this->supplier, $request, $owner->preview($this->supplier, $request)['preview_hash'], 'svj-opening', $this->actor);
    }

    // ---------------------------------------------------------------- předpisy

    private function issueAnnualPrescriptions(): void
    {
        $units = $this->f['units'];
        $all = array_values($units);
        sort($all, SORT_NUMERIC);
        $water = array_values(array_diff($all, [$units['G12'], $units['U06']]));
        $version = $this->saveVersion('annual', [['LONG', 'share', '120000', 'total_budget', 'management_share', $all],
            ['ADMIN', 'equal', '1000', 'rate_per_basis', 'own_administration_equal', $all],
            ['WATER', 'unit', '12000', 'rate_per_basis', 'service_decision', $water]], 0, 'svj-version-annual');
        $requests = [];
        foreach ($units as $code => $unit) {
            $notices = in_array($unit, $water, true)
                ? [['component_id' => $this->f['components']['WATER'], 'notice' => ['mode' => 'initial', 'title_ref' => 'Syntetické rozhodnutí o zálohách']]]
                : [];
            $requests[] = ['unit_id' => $unit, 'expected_revision' => 0, 'previous_charge_id' => null,
                'liability_groups' => $this->ownerGroups($code, '2026-01-01T00:00:00+01:00'), 'notices' => $notices];
        }
        $batch = $this->issue($version['id'], 'SVJ-2026-ANNUAL', 'annual-2026', $requests, '2026-01-01', '2026-12-31', '2026-01-01', '2026-01-15');
        foreach ($batch['charges'] as $charge) {
            $code = array_search($charge['unit_id'], $units, true);
            $this->debts[$code] = $this->registerDebt($code, $charge['id'], '2026-01-15', $code === 'U03');
        }
    }


    /** Půlnoc v Praze včetně letního času. */
    private static function instant(string $date, string $time = '00:00:00'): string
    {
        return (new \DateTimeImmutable($date . ' ' . $time, new \DateTimeZone('Europe/Prague')))->format('Y-m-d\TH:i:sP');
    }

    private function saveVersion(string $frequency, array $rates, int $revision, string $key): array
    {
        $out = [];
        foreach ($rates as [$code, $method, $amount, $mode, $legal, $ids]) {
            $out[] = ['component_id' => $this->f['components'][$code], 'unit_ids' => $ids, 'method' => $method, 'amount' => $amount, 'mode' => $mode,
                'rounding' => ChargeCalculator::ROUNDING, 'legal_basis' => $legal, 'scope_ref' => 'Syntetické usnesení ' . $code,
                'correction' => false, 'correction_reason' => ''];
        }
        $request = ['house_id' => $this->f['house']['id'], 'expected_revision' => $revision, 'effective_from' => '2026-01-01T00:00:00+01:00',
            'effective_to' => null, 'basis_at' => '2026-01-01T00:00:00+01:00', 'frequency' => $frequency, 'basis_policy' => 'fixed_registry_snapshot',
            'period_policy' => 'no_automatic_proration', 'decision_ref' => 'Syntetické usnesení shromáždění 2025', 'decided_at' => '2025-12-15T18:00:00+01:00',
            'reason' => 'Syntetický rozpis záloh', 'rates' => $out];
        $preview = (new ChargePreviewService($this->db))->preview($this->supplier, $request);
        return (new ChargeRepository($this->db))->saveVersion($this->supplier, ['request' => $request, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => $key], $this->actor);
    }

    private function issue(int $version, string $source, string $key, array $units, string $from, string $to, string $date, string $due, ?string $origin = null): array
    {
        $origin ??= $date . 'T00:00:00+01:00';
        $accounts = [];
        foreach ($this->f['components'] as $code => $id) {
            $accounts[] = ['component_id' => $id, 'receivable_account' => ['LONG' => '315.130', 'ADMIN' => '315.120', 'WATER' => '315.110'][$code],
                'counter_account' => ['LONG' => '955.100', 'ADMIN' => '324.200', 'WATER' => '324.110'][$code], 'basis_ref' => 'Syntetické účetní rozhodnutí',
                'settlement_due_on' => $code === 'LONG' ? '2029-12-31' : null, 'dimensions' => []];
        }
        $request = ['version_id' => $version, 'source_key' => $source, 'title' => 'prescription', 'issue_mode' => 'initial', 'period_from' => $from,
            'period_to' => $to, 'claim_origin_at' => $origin, 'issued_at' => str_replace('T00:00:00', 'T10:00:00', $origin), 'accounting_date' => $date,
            'due_date' => $due, 'reason' => 'Syntetický předpis', 'units' => $units, 'accounts' => $accounts,
            'case_identity' => ['schema' => 1, 'case_ref' => $source, 'occurrence_ref' => substr($from, 0, 7), 'title_ref' => 'Syntetický předpis záloh',
                'decision_ref' => 'Syntetické usnesení 2025/12', 'decided_on' => '2025-12-15', 'evidence_ref' => 'Syntetický schválený rozpis']];
        $preview = (new IssuancePreviewService($this->db))->preview($this->supplier, $request);
        $owner = $this->container->get(IssuanceRepository::class);
        $batch = $owner->issue($this->supplier, ['request' => $request, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => $key], $this->actor);
        return $owner->post($this->supplier, $batch['id'], array_map(fn ($c) => ['id' => $c['id'], 'row_version' => $c['row_version']], $batch['charges']),
            $this->actor, ChargeDebtRegistrar::MODE_MANUAL);
    }

    /** Jeden dluh na předpis se všemi jeho řádky; U03 má doloženou bankovní záruku. */
    private function registerDebt(string $code, int $charge, string $due, bool $secured): int
    {
        $q = $this->pdo->prepare('SELECT id,component_id FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? AND amount_cents>0 ORDER BY id');
        $q->execute([$this->supplier, $charge]);
        $ids = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $this->lines[$code][array_search((int) $line['component_id'], $this->f['components'], true)] = (int) $line['id'];
            $ids[] = (int) $line['id'];
        }
        $security = $secured
            ? ['kind' => 'secured', 'evidence' => 'Syntetická realizovatelná bankovní záruka']
            : ['kind' => 'unsecured', 'evidence' => 'Syntetické posouzení bez zajištění'];
        return (new DebtRepository($this->db))->register($this->supplier, ['charge_id' => $charge, 'expected_version' => 2, 'principal_line_ids' => $ids,
            'title_ref' => 'Syntetický předpis', 'due_date' => $due,
            'legal' => ['basis' => 'legal_act', 'legal_act_date' => '2025-12-15', 'evidence' => 'Syntetické usnesení', 'regime' => 'general'],
            'security' => $security, 'damages_for' => null], 'debt-' . $code, $this->actor)['id'];
    }

    private function ownerGroups(string $code, string $from): array
    {
        $groups = [];
        foreach ($this->f['states'][$code]['ownerships'] as $ownership) {
            $sjm = $ownership['kind'] === 'marital_community';
            $groups[] = ['group_key' => $ownership['group_key'], 'role' => 'debtor', 'mode' => $sjm ? 'solidary' : 'individual',
                'basis' => $sjm ? 'sjm_713' : 'documented_title', 'members' => $ownership['members'], 'extent' => $ownership['share'],
                'evidence_ref' => 'Syntetický výpis z katastru', 'legal_reason' => 'Syntetické posouzení odpovědnosti', 'valid_from' => $from, 'valid_to' => null];
        }
        return $groups;
    }

    private function group(string $key, string $person, string $from, string $role = 'debtor'): array
    {
        return ['group_key' => $key, 'role' => $role, 'mode' => 'individual', 'basis' => 'documented_title', 'members' => [$this->people[$person]],
            'extent' => ['num' => '1', 'den' => '1'], 'evidence_ref' => 'Syntetický právní titul', 'legal_reason' => 'Syntetické posouzení odpovědnosti',
            'valid_from' => $from, 'valid_to' => null];
    }

    // ---------------------------------------------------------------- kontrolní výstupy

    /** @return array<string,string> nenulové zůstatky účtů (MD kladně) k datu */
    private function balances(string $date, ?string $from = null): array
    {
        $q = $this->pdo->prepare("SELECT a.account_code,SUM(CASE l.side WHEN 'debit' THEN l.amount ELSE -l.amount END) balance
            FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id
            JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id
            WHERE l.supplier_id=? AND e.posted_at IS NOT NULL AND e.entry_date>=? AND e.entry_date<=? GROUP BY a.account_code ORDER BY a.account_code");
        $q->execute([$this->supplier, $from ?? '1900-01-01', $date]);
        return array_filter(array_column($q->fetchAll(PDO::FETCH_ASSOC), 'balance', 'account_code'), fn ($v) => bccomp((string) $v, '0', 2) !== 0);
    }
}
