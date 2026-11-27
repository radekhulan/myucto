<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Reports\YearEndTaxEstimateAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\YearEndTaxEstimateService;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Odhad do konce roku u výsledovky po účtech: čísla musí být TÁŽ jako náhled DPPO
 * (stejný zdroj, žádný vlastní výpočet), návrhy k potvrzení (dohady, opravné položky)
 * se ukazují, ale nesčítají, nezaúčtované odpisy se neprojeví dvakrát a uzavřený rok
 * nebo rok se zaúčtovanou daní blok nemá.
 */
#[Group('integration')]
final class YearEndTaxEstimateTest extends TestCase
{
    private const YEAR = 2047;

    private Connection $db;
    private YearEndTaxEstimateService $service;
    private TaxReturnService $returns;
    private DppoReturnDataProvider $dppo;
    private FinancialStatementService $statements;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;
    private ?YearEndTaxEstimateAction $action = null;
    private ?AssetService $assets = null;
    private ?DepreciationPostingService $depreciation = null;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $czId = 0;
    private int $currencyId = 0;
    private bool $inTx = false;
    private static int $purchaseSeq = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(YearEndTaxEstimateService::class);
            $this->returns = $container->get(TaxReturnService::class);
            $this->dppo = $container->get(DppoReturnDataProvider::class);
            $this->statements = $container->get(FinancialStatementService::class);
            $this->posting = $container->get(PostingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->action = $container->get(YearEndTaxEstimateAction::class);
            $this->assets = $container->get(AssetService::class);
            $this->depreciation = $container->get(DepreciationPostingService::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->czId === 0 || $this->currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = self::YEAR;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([self::YEAR, json_encode($constants, JSON_UNESCAPED_UNICODE)]);

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
                                   taxpayer_type, ic, dic, accounting_mode)
             VALUES (?, "Zkušební 1", "Vzorov", "10000", ?, "odhad-dane@example.com", ?, ?, "po", "12345678", "CZ12345678", "double_entry")'
        )->execute(['Odhad daně test s.r.o.', $this->czId, $this->currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        // Výnos 1 000 000 (602), náklad 300 000 (518), reprezentace 50 000 (513, nedaňová).
        $this->post(self::YEAR . '-03-15', [['311', 'debit', 1000000], ['602', 'credit', 1000000]]);
        $this->post(self::YEAR . '-04-10', [['518', 'debit', 300000], ['321', 'credit', 300000]]);
        $this->post(self::YEAR . '-05-20', [['513', 'debit', 50000], ['321', 'credit', 50000]]);
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

    public function testOpenYearTakesEveryNumberFromDppoPreview(): void
    {
        $draft = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        $this->returns->saveInputs($this->supplierId, self::YEAR, 'po', [
            'donations' => 40000,
            'tax_paid_advances' => 10000,
        ], (int) $draft['return']['row_version'], $this->userId);
        $preview = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);

        $e = $this->service->estimate($this->supplierId, $this->periodId);

        self::assertTrue($e['applicable']);
        $vh = $this->dppo->gather($this->supplierId, self::YEAR)['vh'];
        self::assertSame(650000.0, $vh);
        self::assertSame($vh, $e['vh_posted'], 'VH průběžně = podklad ř. 10 náhledu DPPO.');
        $view = $this->statements->accountView($this->supplierId, $this->periodId, self::YEAR . '-12-31');
        self::assertSame($view['profit_loss']['profit_before_tax'], $e['vh_posted'], 'Pohled po účtech a náhled DPPO musí mít stejný VH před zdaněním.');

        self::assertFalse($e['is_projection']);
        self::assertSame([], $e['closing_items']);
        self::assertSame($vh, $e['vh_before_tax']);
        self::assertSame((float) $preview['computed']['tax'], $e['tax'], 'Daň = náhled DPPO včetně odečtu darů.');
        self::assertSame(138600.0, $e['tax']);
        self::assertSame(700000.0, $e['tax_base']);
        self::assertSame(50000.0, $e['increases']);
        self::assertSame(10000.0, $e['advances_paid']);
        self::assertSame('return', $e['advances_source']);
        self::assertSame((float) $preview['computed']['balance_due'], $e['balance_due']);
        self::assertSame(round(650000 - 138600, 2), $e['vh_after_tax']);

        [$req, $res] = $this->req('/api/accounting/reports/statement-accounts/tax-estimate?period_id=' . $this->periodId, ['period_id' => (string) $this->periodId]);
        $r = $this->action->get($req, $res);
        self::assertSame(200, $r->getStatusCode());
        $r->getBody()->rewind();
        $body = json_decode((string) $r->getBody(), true);
        self::assertEqualsWithDelta(138600.0, $body['tax'], 0.001);
        self::assertTrue($body['applicable']);
    }

    /**
     * Můstek od VH k datu výkazu (tabulka výsledovky) k zaúčtovanému VH celého období:
     * později datované zaúčtované zápisy po skupinách podle povahy, součet přesně vh_posted,
     * uzávěrkový zápis vyloučený stejně jako ve VH tabulky i náhledu DPPO.
     */
    public function testBridgeFromStatementDateToPostedProfit(): void
    {
        $asOf = self::YEAR . '-11-27';
        $this->post(self::YEAR . '-06-30', [['384', 'debit', 10000], ['602', 'credit', 10000]]);
        $this->post(self::YEAR . '-11-30', [['384', 'debit', 120000.45], ['602', 'credit', 120000.45]]);
        $this->post(self::YEAR . '-12-31', [['518', 'debit', 15000.10], ['381', 'credit', 15000.10]]);
        $this->post(self::YEAR . '-12-31', [['518', 'debit', 7000], ['389', 'credit', 7000]]);
        $this->post(self::YEAR . '-12-31', [['551', 'debit', 24000], ['082', 'credit', 24000]]);
        $this->post(self::YEAR . '-12-15', [['518', 'debit', 5000], ['321', 'credit', 5000]]);
        // Rudé storno rozpuštění: patří do stejné skupiny jako rušený zápis.
        $this->post(self::YEAR . '-12-20', [['384', 'debit', 2000, true], ['602', 'credit', 2000, true]]);
        $closingId = $this->post(self::YEAR . '-12-31', [['602', 'debit', 1000], ['710', 'credit', 1000]], 'closing');
        $this->db->pdo()->prepare('UPDATE journal_entries SET source_id = ? WHERE id = ?')->execute([$this->periodId, $closingId]);

        $e = $this->service->estimate($this->supplierId, $this->periodId, $asOf);

        self::assertTrue($e['applicable']);
        self::assertSame(round(650000 + 10000 + 120000.45 - 2000 - 15000.10 - 7000 - 24000 - 5000, 2), $e['vh_posted']);
        $bridge = $e['bridge'];
        self::assertIsArray($bridge);
        self::assertSame($asOf, $bridge['as_of']);
        self::assertSame(self::YEAR . '-12-31', $bridge['ends_on']);
        self::assertSame(660000.0, $bridge['vh_as_of']);
        $view = $this->statements->accountView($this->supplierId, $this->periodId, $asOf);
        self::assertEqualsWithDelta($view['profit_loss']['profit_before_tax'], $bridge['vh_as_of'], 0.001, 'Začátek můstku = VH tabulky k datu výkazu.');
        self::assertSame([
            ['key' => 'deferred_revenue', 'amount' => 118000.45],
            ['key' => 'prepaid_expenses', 'amount' => -15000.1],
            ['key' => 'accruals', 'amount' => -7000.0],
            ['key' => 'depreciation', 'amount' => -24000.0],
            ['key' => 'other', 'amount' => -5000.0],
        ], $bridge['groups']);
        $cents = (int) round($bridge['vh_as_of'] * 100);
        foreach ($bridge['groups'] as $g) {
            $cents += (int) round($g['amount'] * 100);
        }
        self::assertSame((int) round($e['vh_posted'] * 100), $cents, 'Součet můstku = vh_posted na haléř.');
        self::assertSame($e['vh_posted'], $bridge['vh_posted']);

        self::assertNull($this->service->estimate($this->supplierId, $this->periodId)['bridge'], 'Bez data výkazu se můstek nevrací.');
        self::assertNull($this->service->estimate($this->supplierId, $this->periodId, self::YEAR . '-12-31')['bridge'], 'Den výkazu = konec období: není co přemosťovat.');

        [$req, $res] = $this->req('/api/accounting/reports/statement-accounts/tax-estimate', ['period_id' => (string) $this->periodId, 'as_of' => $asOf]);
        $r = $this->action->get($req, $res);
        self::assertSame(200, $r->getStatusCode());
        $r->getBody()->rewind();
        $body = json_decode((string) $r->getBody(), true);
        self::assertEqualsWithDelta(660000.0, $body['bridge']['vh_as_of'], 0.001);
        self::assertCount(5, $body['bridge']['groups']);

        foreach (['2047-02-31', (self::YEAR + 1) . '-01-05'] as $bad) {
            [$req, $res] = $this->req('/api/accounting/reports/statement-accounts/tax-estimate', ['period_id' => (string) $this->periodId, 'as_of' => $bad]);
            self::assertSame(422, $this->action->get($req, $res)->getStatusCode(), 'Neplatné datum nebo mimo období: ' . $bad);
        }
    }

    /**
     * Můstek s průběžným rozlišením: začíná VH tabulky k datu s volbou a skupiny později
     * datovaných zápisů sníží o část, kterou volba už započetla (roční rozpuštění 381 poměrem
     * dnů, odpis za deset měsíců). Součet je dál přesně vh_posted.
     */
    public function testBridgeWithAccrualSpreadStartsAtSpreadProfit(): void
    {
        $asOf = self::YEAR . '-11-27';
        $this->post(self::YEAR . '-11-30', [['384', 'debit', 120000.45], ['602', 'credit', 120000.45]]);
        $this->post(self::YEAR . '-12-31', [['518', 'debit', 15000.10], ['381', 'credit', 15000.10]]);
        $this->post(self::YEAR . '-12-31', [['551', 'debit', 24000], ['082', 'credit', 24000]]);
        $this->post(self::YEAR . '-12-15', [['518', 'debit', 5000], ['321', 'credit', 5000]]);

        $plain = $this->service->estimate($this->supplierId, $this->periodId, $asOf);
        $e = $this->service->estimate($this->supplierId, $this->periodId, $asOf, true);
        self::assertSame($plain['vh_posted'], $e['vh_posted'], 'Zaúčtovaný VH období volba nemění.');

        $prepaid = 13602.83; // 15 000,10 × 331/365
        $view = $this->statements->accountView($this->supplierId, $this->periodId, $asOf, null, null, true);
        $bridge = $e['bridge'];
        self::assertEqualsWithDelta($view['profit_loss']['profit_before_tax'], $bridge['vh_as_of'], 0.001, 'Začátek můstku = VH tabulky s volbou.');
        self::assertEqualsWithDelta(650000.0 - $prepaid - 20000.0, $bridge['vh_as_of'], 0.001);
        self::assertSame(650000.0, $bridge['vh_as_of_posted']);
        self::assertEqualsWithDelta(-$prepaid - 20000.0, $bridge['accrual_spread'], 0.001);
        self::assertSame([
            ['key' => 'deferred_revenue', 'amount' => 120000.45, 'spread' => 0.0],
            ['key' => 'prepaid_expenses', 'amount' => round(-15000.10 + $prepaid, 2), 'spread' => -$prepaid],
            ['key' => 'depreciation', 'amount' => -4000.0, 'spread' => -20000.0],
            ['key' => 'other', 'amount' => -5000.0, 'spread' => 0.0],
        ], $bridge['groups']);
        $cents = (int) round($bridge['vh_as_of'] * 100);
        foreach ($bridge['groups'] as $g) {
            $cents += (int) round($g['amount'] * 100);
        }
        self::assertSame((int) round($e['vh_posted'] * 100), $cents, 'Součet můstku = vh_posted na haléř i s volbou.');

        [$req, $res] = $this->req('/api/accounting/reports/statement-accounts/tax-estimate', ['period_id' => (string) $this->periodId, 'as_of' => $asOf, 'accrual_spread' => '1']);
        $r = $this->action->get($req, $res);
        self::assertSame(200, $r->getStatusCode());
        $r->getBody()->rewind();
        self::assertEqualsWithDelta($bridge['vh_as_of'], json_decode((string) $r->getBody(), true)['bridge']['vh_as_of'], 0.001);
        [$req, $res] = $this->req('/api/accounting/reports/statement-accounts/tax-estimate', ['period_id' => (string) $this->periodId, 'as_of' => $asOf, 'accrual_spread' => 'x']);
        self::assertSame(422, $this->action->get($req, $res)->getStatusCode());
    }

    public function testOptionalClosingSuggestionsAreShownButNotAdded(): void
    {
        // Pravidelná měsíční faktura leden–listopad, prosinec chybí → návrh dohadné položky.
        $vendorId = $this->vendor();
        for ($month = 1; $month <= 11; $month++) {
            $this->purchase($vendorId, sprintf('%d-%02d-15', self::YEAR, $month), 10000.0);
        }

        $e = $this->service->estimate($this->supplierId, $this->periodId);

        $items = array_column($e['closing_items'], null, 'key');
        self::assertArrayHasKey('estimate', $items, 'Návrh dohadu musí být v bloku vidět.');
        self::assertTrue($items['estimate']['optional']);
        self::assertSame(10000.0, $items['estimate']['amount']);
        self::assertFalse($e['is_projection'], 'Samotný návrh k potvrzení projekci nezakládá.');
        self::assertSame($e['vh_posted'], $e['vh_before_tax'], 'Návrh k potvrzení se do VH nesčítá.');
        $effects = array_column($e['balance_effects'], null, 'key');
        self::assertSame(['389', 'liability', 10000.0, true], [$effects['estimate']['accounts'], $effects['estimate']['side'], $effects['estimate']['liabilities'], $effects['estimate']['optional']]);
        self::assertSame(-$e['tax'], $e['balance_check']['difference'], 'Návrh k potvrzení se do změn rozvahy nesčítá.');
        self::assertTrue($e['balance_check']['matches']);
        $gathered = $this->dppo->gather($this->supplierId, self::YEAR);
        self::assertSame($gathered['closing_projection']['vh_projected'], $e['vh_before_tax']);
        self::assertSame(540000.0, $e['vh_before_tax']);
        $preview = $this->returns->previewReadOnly($this->supplierId, self::YEAR, 'po');
        self::assertSame((float) $preview['result']['tax'], $e['tax']);
    }

    /**
     * Selhání náhledu jednoho kroku uzávěrky nesmí krok tiše vypustit z projektovaného VH
     * a daně: projekce ostatních kroků doběhne a vynechaný krok se vrátí jako varování.
     */
    public function testFailedClosingPreviewIsReportedAsIncompleteProjection(): void
    {
        $vendorId = $this->vendor();
        for ($month = 1; $month <= 11; $month++) {
            $this->purchase($vendorId, sprintf('%d-%02d-15', self::YEAR, $month), 10000.0);
        }
        // Neplatná politika rozlišení drobného majetku: náhled kroku skončí výjimkou.
        $this->db->pdo()->prepare("UPDATE accounting_periods SET small_asset_accrual_mode = 'flat_pct', small_asset_accrual_pct = NULL WHERE id = ?")
            ->execute([$this->periodId]);

        $log = ini_set('error_log', '/dev/null');
        try {
            $projection = $this->dppo->gather($this->supplierId, self::YEAR)['closing_projection'];
            $e = $this->service->estimate($this->supplierId, $this->periodId);
            $preview = $this->returns->previewReadOnly($this->supplierId, self::YEAR, 'po');
        } finally {
            ini_set('error_log', (string) $log);
        }

        $expected = [['source' => 'small_asset', 'reason' => 'Paušální procento musí být v rozsahu 0–100 %.']];
        self::assertSame($expected, $projection['warnings']);
        self::assertArrayHasKey('estimate', array_column($projection['items'], null, 'key'), 'Ostatní kroky se projektují dál.');
        self::assertSame($expected, $e['projection_warnings']);
        self::assertSame($expected, $preview['podklady']['closing_projection']['warnings']);
    }

    public function testUnpostedDepreciationIsProjectedOnceAndNeverAddedTwice(): void
    {
        $this->assets->create($this->supplierId, [
            'inventory_number' => 'ODH-001',
            'name' => 'Zkušební stroj',
            'input_price' => 120000.00,
            'acquisition_date' => self::YEAR . '-01-10',
            'put_into_use_date' => self::YEAR . '-01-10',
            'status' => 'in_use',
            'tax_method' => 'straight',
            'tax_group' => 2,
            'acc_useful_life_months' => 60,
        ], ['user_id' => $this->userId]);

        $plan = $this->depreciation->previewYear($this->supplierId, self::YEAR);
        self::assertSame(1, $plan['assets']);
        self::assertGreaterThan(0.0, $plan['pending_accounting']);
        self::assertGreaterThan(0.0, $plan['pending_tax']);

        $before = $this->service->estimate($this->supplierId, $this->periodId);
        $items = array_column($before['closing_items'], null, 'key');
        self::assertArrayHasKey('depreciation', $items, 'Nezaúčtované odpisy jsou položkou projekce uzávěrky.');
        self::assertSame([$plan['pending_accounting'], -1, false], [$items['depreciation']['amount'], $items['depreciation']['sign'], $items['depreciation']['optional']]);
        self::assertSame(round($before['vh_posted'] - $plan['pending_accounting'], 2), $before['vh_before_tax']);
        $previewBefore = $this->returns->previewReadOnly($this->supplierId, self::YEAR, 'po')['result'];
        self::assertSame((float) $previewBefore['projection']['projected_tax'], $before['tax'], 'Blok a náhled DPPO mají stejnou daň.');

        // Rozvaha po odhadu: oprávky snižují aktiva, daň zvyšuje 341, rozdíl aktiv a pasiv = změna VH.
        $effects = array_column($before['balance_effects'], null, 'key');
        self::assertSame(['07x/08x', 'asset', -$plan['pending_accounting'], 0.0], [$effects['depreciation']['accounts'], $effects['depreciation']['side'], $effects['depreciation']['assets'], $effects['depreciation']['liabilities']]);
        self::assertSame(['341', $before['tax']], [$effects['income_tax']['accounts'], $effects['income_tax']['liabilities']]);
        self::assertTrue($before['balance_check']['matches']);
        self::assertSame(round($before['vh_after_tax'] - $before['vh_posted'], 2), $before['balance_check']['difference']);

        $this->depreciation->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        $after = $this->service->estimate($this->supplierId, $this->periodId);

        self::assertArrayNotHasKey('depreciation', array_column($after['closing_items'], null, 'key'));
        self::assertSame(round($before['vh_posted'] - $plan['pending_accounting'], 2), $after['vh_posted'],
            'Po zaúčtování je odpis ve VH právě jednou.');
        self::assertSame($before['vh_before_tax'], $after['vh_before_tax'], 'Odhad VH se zaúčtováním nemění.');
        self::assertSame($before['tax_base'], $after['tax_base'], 'Základ se zaúčtováním nemění (rozdíl odpisů stejnou cestou).');
        self::assertSame($before['tax'], $after['tax']);
    }

    public function testClosedYearOrPostedIncomeTaxHasNoEstimate(): void
    {
        $this->post(self::YEAR . '-12-31', [['591', 'debit', 100000], ['341', 'credit', 100000]], 'income_tax');
        $e = $this->service->estimate($this->supplierId, $this->periodId);
        self::assertFalse($e['applicable']);
        self::assertSame('income_tax_posted', $e['reason']);
        self::assertArrayNotHasKey('tax', $e);

        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $e = $this->service->estimate($this->supplierId, $this->periodId);
        self::assertFalse($e['applicable']);
        self::assertSame('period_closed', $e['reason']);
    }

    public function testRedStornoOfIncomeTaxKeepsEstimateAvailable(): void
    {
        $this->post(self::YEAR . '-12-31', [
            ['591', 'debit', 100000],
            ['341', 'credit', 100000],
        ], 'income_tax');
        $this->post(self::YEAR . '-12-31', [
            ['591', 'debit', 100000, true],
            ['341', 'credit', 100000, true],
        ], 'manual');

        $e = $this->service->estimate($this->supplierId, $this->periodId);

        self::assertTrue($e['applicable']);
        self::assertNull($e['reason']);
    }

    public function testNaturalPersonHasNoEstimate(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET taxpayer_type = 'fo' WHERE id = ?")->execute([$this->supplierId]);
        $e = $this->service->estimate($this->supplierId, $this->periodId);
        self::assertFalse($e['applicable']);
        self::assertSame('taxpayer_fo', $e['reason']);
    }

    public function testRouteNeedsTaxReturnPermission(): void
    {
        $p = (new RoutePermissionMap())->match('GET', '/api/accounting/reports/statement-accounts/tax-estimate');
        self::assertNotNull($p);
        self::assertSame('reports', $p->key);
        self::assertSame(AccessLevel::READ, $p->minimum);
    }

    /** @param list<array{0:string,1:string,2:float|int,3?:bool}> $lines */
    private function post(string $date, array $lines, string $sourceType = 'manual'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO journal_entries (supplier_id, period_id, entry_date, posted_at, source_type) VALUES (?, ?, ?, NOW(), ?)'
        )->execute([$this->supplierId, $this->periodId, $date, $sourceType]);
        $entryId = (int) $pdo->lastInsertId();
        $ids = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $ins = $pdo->prepare('INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, is_red_storno) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($lines as $line) {
            [$code, $side, $amount] = $line;
            $ids->execute([$this->supplierId, $code]);
            $ins->execute([$entryId, $this->supplierId, (int) $ids->fetchColumn(), $side, $amount, !empty($line[3]) ? 1 : 0]);
        }

        return $entryId;
    }

    private function vendor(): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Energie a.s.", "Ulice 1", "Praha", "11000", ?, "CZ12345679", ?, "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $this->czId, 'odhad' . uniqid() . '@example.com', $this->currencyId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchase(int $vendorId, string $date, float $net): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, "received", ?)'
        )->execute([
            $this->supplierId, $vendorId, sprintf('ODH-%05d', ++self::$purchaseSeq),
            $date, $date, $date, $date, $this->currencyId, json_encode(['name' => 'Snapshot']), $net, $net, $this->userId,
        ]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', (int) $pdo->lastInsertId(), [
            ['account_code' => '518', 'side' => 'debit', 'amount' => $net],
            ['account_code' => '321', 'side' => 'credit', 'amount' => $net],
        ], ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
    }

    /** @param array<string,string> $query */
    private function req(string $path, array $query): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);

        return [$req, new Psr7Response()];
    }
}
