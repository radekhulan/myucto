<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Assets\AssetAction;
use MyInvoice\Action\Accounting\Assets\AssetLifecycleAction;
use MyInvoice\Action\Accounting\Assets\DepreciationAction;
use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Symfony\Component\Clock\MockClock;

/**
 * Integrační testy REST API majetku (Epic F3, spec §6.2 I6–I9).
 *
 * I6 CRUD + validační matice §3.3 (extraordinary podmínky, skupina, R16,
 * duplicitní inventární číslo, zámek R13, bezpečné mazání chybného zařazení), I7 RBAC
 * (readonly GET/POST, accountant, cizí tenant), I8 hromadný book se souhrnem
 * a chybou uzavřeného období per majetek (HTTP 200), I9 kandidáti z PF.
 *
 * Vzor AccountingApiTest: Action třídy volané přímo s ATTR_USER/ATTR_CURRENT_ID,
 * transakce s rollbackem, soft-skip bez cfg.php, rok 2098.
 */
#[Group('integration')]
final class AssetsApiTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private AssetAction $assetAction;
    private AssetService $assetService;
    private AssetLifecycleAction $lifecycleAction;
    private DepreciationAction $depreciationAction;
    private JournalAction $journalAction;
    private DepreciationEntryRepository $entries;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $container->set(ClockInterface::class, new MockClock(self::YEAR . '-12-31 12:00:00'));
            $this->db                 = $container->get(Connection::class);
            $this->assetAction        = $container->get(AssetAction::class);
            $this->assetService       = $container->get(AssetService::class);
            $this->lifecycleAction    = $container->get(AssetLifecycleAction::class);
            $this->depreciationAction = $container->get(DepreciationAction::class);
            $this->journalAction      = $container->get(JournalAction::class);
            $this->entries            = $container->get(DepreciationEntryRepository::class);
            $this->periods            = $container->get(AccountingPeriodRepository::class);
            $seeder                   = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    // ── I6: CRUD + validační matice ────────────────────────────────────────────

    public function testI6CrudHappyPath(): void
    {
        $create = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-001'));
        self::assertSame(201, $create['status']);
        self::assertIsArray($create['body']['asset']);
        self::assertIsArray($create['body']['warnings']);
        $assetId = (int) $create['body']['asset']['id'];
        self::assertGreaterThan(0, $assetId);
        self::assertSame('M-API-001', $create['body']['asset']['inventory_number']);
        self::assertSame('draft', $create['body']['asset']['status']);
        self::assertSame('082', $create['body']['asset']['accumulated_account_code'], 'Oprávky odvozené mapou R18.');

        $get = $this->call($this->assetAction, 'get', 'GET', 'readonly', ['id' => (string) $assetId]);
        self::assertSame(200, $get['status']);
        self::assertSame($assetId, (int) $get['body']['id']);
        self::assertArrayHasKey('locked', $get['body'], 'Detail nese zámky R13.');
        self::assertFalse($get['body']['locked']['tax_params']);

        $update = $this->call($this->assetAction, 'update', 'PUT', 'accountant', ['id' => (string) $assetId],
            ['name' => 'Stroj přejmenovaný']);
        self::assertSame(200, $update['status']);
        self::assertSame('Stroj přejmenovaný', $update['body']['asset']['name']);

        $list = $this->call($this->assetAction, 'list', 'GET', 'readonly');
        self::assertSame(200, $list['status']);
        self::assertContains($assetId, array_column($list['body']['items'], 'id'));

        $delete = $this->call($this->assetAction, 'delete', 'DELETE', 'accountant', ['id' => (string) $assetId]);
        self::assertSame(200, $delete['status'], 'Koncept lze smazat.');
        $gone = $this->call($this->assetAction, 'get', 'GET', 'accountant', ['id' => (string) $assetId]);
        self::assertSame(404, $gone['status']);
    }

    public function testI6ExtraordinaryWithoutZeroEmissionRejected(): void
    {
        $res = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-030A', [
            'tax_method' => 'extraordinary',
            'is_first_owner' => true,
            'is_zero_emission' => false,
            'acquisition_date' => '2024-06-15',
        ]));
        self::assertSame(422, $res['status']);
        self::assertSame('extraordinary_conditions_not_met', $res['body']['error']['code']);
    }

    public function testI6StraightWithoutTaxGroupRejected(): void
    {
        $res = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-NOGRP', [
            'tax_group' => null,
        ]));
        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    public function testI6IntangibleWithStraightMethodRejected(): void
    {
        $res = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-DNM', [
            'kind' => 'intangible',
            'tax_method' => 'straight',
            'asset_account_code' => '013',
        ]));
        self::assertSame(422, $res['status'], 'R16: DNM smí jen by_accounting|none.');
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    public function testOpeningTaxAmountCannotExceedInputPrice(): void
    {
        $res = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-EP4-TAX', [
            'status' => 'in_use',
            'put_into_use_date' => self::YEAR . '-01-10',
            'opening_tax_amount' => 500000.01,
        ]));

        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    public function testOpeningAccountingAmountAndResidualCannotExceedInputPrice(): void
    {
        $res = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-EP4-ACC', [
            'status' => 'in_use',
            'put_into_use_date' => self::YEAR . '-01-10',
            'opening_acc_amount' => 450000.00,
            'acc_residual_value' => 60000.00,
        ]));

        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    public function testM1FirstYearIncreaseIsRejected(): void
    {
        $res = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-EP4-M1', [
            'tax_group' => 2,
            'tax_first_year_increase' => 'p10',
            'is_first_owner' => true,
            'is_m1_vehicle' => true,
        ]));

        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    // ── § 28 ZDP: odpisovatel ────────────────────────────────────────────────
    //
    // Karta evidovala jen `is_first_owner`. Právní důvod odpisování — proč zrovna tenhle
    // poplatník majetek odpisuje — nikde nebyl, přestože z něj plynou tvrdá pravidla
    // s přímým daňovým dopadem.

    /**
     * Právní nástupce POKRAČUJE v odpisování po předchůdci (§ 30 odst. 10), takže nemůže
     * být zároveň prvním odpisovatelem. Bez téhle vazby by si uplatnil zvýšení odpisu
     * v 1. roce, na které nemá nárok — tedy neoprávněně sníženou daň.
     */
    public function testLegalSuccessorCannotBeFirstOwner(): void
    {
        $res = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-EP4-SUCC', [
            'depreciator_ground' => 'legal_successor',
            'is_first_owner' => true,
        ]));

        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
        self::assertStringContainsString('nástupce', $res['body']['error']['message']);
    }

    /** Zvýšení odpisu v 1. roce (§ 31/1 b–d) náleží jen prvnímu odpisovateli. */
    public function testLegalSuccessorCannotClaimFirstYearIncrease(): void
    {
        $res = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-EP4-SUCC2', [
            'depreciator_ground' => 'legal_successor',
            'is_first_owner' => false,
            'tax_first_year_increase' => 'p10',
        ]));

        self::assertSame(422, $res['status']);
        self::assertStringContainsString('prvnímu odpisovateli', $res['body']['error']['message']);
    }

    /**
     * Spoluvlastník odpisuje ze svého PODÍLU (§ 28 odst. 5) — bez uvedeného podílu není
     * z čeho ověřit, že se neodpisuje celý majetek místo poměrné části.
     */
    public function testCoOwnerWithoutShareIsRejected(): void
    {
        $res = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-EP4-CO', [
            'depreciator_ground' => 'co_owner',
        ]));

        self::assertSame(422, $res['status']);
        self::assertStringContainsString('podíl', $res['body']['error']['message']);
    }

    /** S uvedeným podílem projde. */
    public function testCoOwnerWithShareIsAccepted(): void
    {
        $res = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-EP4-CO2', [
            'depreciator_ground' => 'co_owner',
            'co_ownership_share' => 50.00,
        ]));

        self::assertSame(201, $res['status']);
    }

    /**
     * Nájemce smí TZ na cizím majetku odpisovat jen se SOUHLASEM vlastníka
     * (§ 28 odst. 3) — souhlas je podmínkou nároku, takže musí jít doložit.
     */
    public function testLesseeImprovementRequiresOwnerConsent(): void
    {
        $res = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-EP4-TZ', [
            'depreciator_ground' => 'lessee_improvement',
        ]));

        self::assertSame(422, $res['status']);
        self::assertStringContainsString('souhlas', $res['body']['error']['message']);
    }

    /** Vlastník je výchozí důvod — dosavadní karty se chovají beze změny. */
    public function testOwnerIsDefaultAndUnaffected(): void
    {
        $res = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-EP4-OWNER', [
            'is_first_owner' => true,
            'tax_first_year_increase' => 'p10',
        ]));

        self::assertSame(201, $res['status']);
    }

    public function testNonDepreciatedAssetCannotHaveOpeningAccountingDepreciation(): void
    {
        $res = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-EP4-NONDEP', [
            'tax_method' => 'none',
            'tax_group' => null,
            'accumulated_account_code' => null,
            'opening_acc_months' => 1,
            'opening_acc_amount' => 1000.00,
        ]));

        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    public function testI6DuplicateInventoryNumberRejected(): void
    {
        $first = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-DUP'));
        self::assertSame(201, $first['status']);

        $second = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-DUP'));
        self::assertSame(422, $second['status']);
        self::assertSame('duplicate_inventory_number', $second['body']['error']['code']);
    }

    public function testI6UpdateAfterConfirmedTaxEntryLocked(): void
    {
        $create = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-LOCK'));
        $assetId = (int) $create['body']['asset']['id'];

        // potvrzený daňový řádek → zámek R13
        $this->entries->upsert([
            'supplier_id' => $this->supplierId,
            'asset_id' => $assetId,
            'kind' => 'tax',
            'fiscal_year' => self::YEAR,
            'amount' => 55000.0,
            'full_amount' => 55000.0,
            'residual_value_end' => 445000.0,
            'status' => 'confirmed',
        ]);

        $res = $this->call($this->assetAction, 'update', 'PUT', 'accountant', ['id' => (string) $assetId],
            ['tax_group' => 3]);
        self::assertSame(422, $res['status']);
        self::assertSame('asset_locked', $res['body']['error']['code']);

        $priceRes = $this->call($this->assetAction, 'update', 'PUT', 'accountant', ['id' => (string) $assetId],
            ['input_price' => 600000.00]);
        self::assertSame(422, $priceRes['status'], 'Vstupní cena je po potvrzeném odpisu zamčená.');
        self::assertSame('asset_locked', $priceRes['body']['error']['code']);
    }

    public function testI6DeleteInUseRemovesActivationEntryAndCard(): void
    {
        $create = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-DEL'));
        $assetId = (int) $create['body']['asset']['id'];
        $putIntoUse = $this->call($this->lifecycleAction, 'putIntoUse', 'POST', 'accountant',
            ['id' => (string) $assetId], ['date' => self::YEAR . '-01-15', 'book_entry' => true]);
        self::assertSame(200, $putIntoUse['status']);
        self::assertGreaterThan(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries
              WHERE supplier_id = {$this->supplierId} AND source_type = 'asset' AND source_id = {$assetId}"
        )->fetchColumn());

        $res = $this->call($this->assetAction, 'delete', 'DELETE', 'accountant', ['id' => (string) $assetId]);
        self::assertSame(200, $res['status']);
        self::assertTrue($res['body']['activation_entry_deleted']);
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries
              WHERE supplier_id = {$this->supplierId} AND source_type = 'asset' AND source_id = {$assetId}"
        )->fetchColumn());
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM assets WHERE supplier_id = {$this->supplierId} AND id = {$assetId}"
        )->fetchColumn());
    }

    public function testI6DeleteInUseWithConfirmedDepreciationRejected(): void
    {
        $create = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-DEL-DEP', [
            'status' => 'in_use',
            'put_into_use_date' => self::YEAR . '-01-15',
        ]));
        $assetId = (int) $create['body']['asset']['id'];
        $this->entries->upsert([
            'supplier_id' => $this->supplierId,
            'asset_id' => $assetId,
            'kind' => 'tax',
            'fiscal_year' => self::YEAR,
            'amount' => 100000.0,
            'full_amount' => 100000.0,
            'residual_value_end' => 400000.0,
            'status' => 'confirmed',
        ]);

        $res = $this->call($this->assetAction, 'delete', 'DELETE', 'accountant', ['id' => (string) $assetId]);
        self::assertSame(409, $res['status']);
        self::assertSame('asset_has_depreciation', $res['body']['error']['code']);
    }

    // ── I7: RBAC ───────────────────────────────────────────────────────────────

    public function testI7RbacReadonlyAndAccountant(): void
    {
        $list = $this->call($this->assetAction, 'list', 'GET', 'readonly');
        self::assertSame(200, $list['status'], 'Readonly smí číst.');

        $denied = $this->call($this->assetAction, 'create', 'POST', 'readonly', [], $this->cardPayload('M-API-RO'));
        self::assertSame(403, $denied['status'], 'Readonly nesmí zapisovat.');

        $ok = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-ACC'));
        self::assertSame(201, $ok['status'], 'Účetní smí založit kartu.');
        $assetId = (int) $ok['body']['asset']['id'];

        $lifecycleDenied = $this->call($this->lifecycleAction, 'putIntoUse', 'POST', 'readonly',
            ['id' => (string) $assetId], ['date' => self::YEAR . '-02-01']);
        self::assertSame(403, $lifecycleDenied['status'], 'Readonly nesmí lifecycle.');

        $bookDenied = $this->call($this->depreciationAction, 'bookYear', 'POST', 'readonly', [],
            ['fiscal_year' => self::YEAR]);
        self::assertSame(403, $bookDenied['status'], 'Readonly nesmí účtovat odpisy.');
    }

    public function testI7CrossTenantReturns404(): void
    {
        $create = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-TEN'));
        $assetId = (int) $create['body']['asset']['id'];

        $foreignSupplierId = $this->cloneSupplier('double_entry');

        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/assets/' . $assetId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $foreignSupplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        $resp = $this->assetAction->get($req, new Psr7Response(), ['id' => (string) $assetId]);
        self::assertSame(404, $resp->getStatusCode(), 'Cizí tenant kartu nevidí.');

        $planReq = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/assets/' . $assetId . '/depreciation-plan')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $foreignSupplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        $planResp = $this->depreciationAction->plan($planReq, new Psr7Response(), ['id' => (string) $assetId]);
        self::assertSame(404, $planResp->getStatusCode(), 'Cizí tenant nevidí ani plán.');
    }

    // ── I8: hromadný book ──────────────────────────────────────────────────────

    public function testI8BookYearReturnsSummaryAndClosedPeriodAsPerAssetError(): void
    {
        $create = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-BOOK', [
            'status' => 'in_use',
            'put_into_use_date' => self::YEAR . '-01-15',
        ]));
        self::assertSame(201, $create['status']);
        $assetId = (int) $create['body']['asset']['id'];

        $missing = $this->call($this->depreciationAction, 'bookYear', 'POST', 'accountant', [], []);
        self::assertSame(422, $missing['status'], 'fiscal_year je povinný.');

        $ok = $this->call($this->depreciationAction, 'bookYear', 'POST', 'accountant', [],
            ['fiscal_year' => self::YEAR]);
        self::assertSame(200, $ok['status']);
        foreach (['booked', 'skipped', 'total_accounting', 'total_tax', 'errors'] as $key) {
            self::assertArrayHasKey($key, $ok['body'], 'Souhrn obsahuje ' . $key . '.');
        }
        self::assertGreaterThanOrEqual(1, (int) $ok['body']['booked']);
        self::assertNotNull($this->entries->findYear($assetId, 'accounting', self::YEAR), 'Účetní řádek zaúčtován.');

        // Uzavřené období → chyba per majetek v errors, HTTP 200 (vzor backfill SKIP)
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $closed = $this->call($this->depreciationAction, 'bookYear', 'POST', 'accountant', [],
            ['fiscal_year' => self::YEAR]);
        self::assertSame(200, $closed['status'], 'Chyby per majetek neshazují HTTP status.');
        self::assertContains($assetId, array_column((array) $closed['body']['errors'], 'asset_id'),
            'Majetek s uzavřeným obdobím je v errors.');
    }

    public function testBookYearRejectsPeriodThatHasNotEnded(): void
    {
        $future = $this->call($this->depreciationAction, 'bookYear', 'POST', 'accountant', [],
            ['fiscal_year' => self::YEAR + 1]);

        self::assertSame(409, $future['status']);
        self::assertSame('period_not_ended', $future['body']['error']['code']);
    }

    public function testLatestDepreciationEntryCanBeDeletedAndBookedAgain(): void
    {
        $create = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-DEL-DEP', [
            'status' => 'in_use',
            'put_into_use_date' => self::YEAR . '-01-15',
        ]));
        $assetId = (int) $create['body']['asset']['id'];
        $booked = $this->call($this->depreciationAction, 'bookYear', 'POST', 'accountant', [],
            ['fiscal_year' => self::YEAR]);
        self::assertSame(200, $booked['status']);

        $accounting = $this->entries->findYear($assetId, 'accounting', self::YEAR);
        self::assertNotNull($accounting);
        $entryId = (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries
              WHERE supplier_id = {$this->supplierId} AND source_type = 'depreciation'
                AND source_id = " . (int) $accounting['id']
        )->fetchColumn();
        self::assertGreaterThan(0, $entryId);

        $plan = $this->call($this->depreciationAction, 'plan', 'GET', 'accountant', ['id' => (string) $assetId]);
        $accountingByYear = array_column($plan['body']['accounting'], null, 'fiscal_year');
        self::assertSame((int) $accounting['id'], (int) $accountingByYear[self::YEAR]['depreciation_entry_id']);
        self::assertSame($entryId, (int) $accountingByYear[self::YEAR]['journal_entry_id']);

        $deleted = $this->call($this->journalAction, 'delete', 'DELETE', 'accountant', ['id' => (string) $entryId]);
        self::assertSame(200, $deleted['status']);
        self::assertSame($assetId, (int) $deleted['body']['asset_id']);
        self::assertSame(self::YEAR, (int) $deleted['body']['fiscal_year']);
        self::assertNull($this->entries->findYear($assetId, 'accounting', self::YEAR));
        self::assertNull($this->entries->findYear($assetId, 'tax', self::YEAR));

        $again = $this->call($this->depreciationAction, 'bookYear', 'POST', 'accountant', [],
            ['fiscal_year' => self::YEAR]);
        self::assertSame(200, $again['status']);
        self::assertGreaterThanOrEqual(1, (int) $again['body']['booked']);
        self::assertNotNull($this->entries->findYear($assetId, 'accounting', self::YEAR));
    }

    public function testOlderDepreciationEntryCannotBeDeletedWhileLaterYearExists(): void
    {
        $create = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-DEL-OLD', [
            'status' => 'in_use',
            'put_into_use_date' => self::YEAR . '-01-15',
        ]));
        $assetId = (int) $create['body']['asset']['id'];
        $this->call($this->depreciationAction, 'bookYear', 'POST', 'accountant', [], ['fiscal_year' => self::YEAR]);
        $accounting = $this->entries->findYear($assetId, 'accounting', self::YEAR);
        $entryId = (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries
              WHERE supplier_id = {$this->supplierId} AND source_type = 'depreciation'
                AND source_id = " . (int) $accounting['id']
        )->fetchColumn();
        $this->entries->upsert([
            'supplier_id' => $this->supplierId,
            'asset_id' => $assetId,
            'kind' => 'tax',
            'fiscal_year' => self::YEAR + 1,
            'amount' => 1.0,
            'full_amount' => 1.0,
            'residual_value_end' => 1.0,
            'status' => 'confirmed',
        ]);

        $blocked = $this->call($this->journalAction, 'delete', 'DELETE', 'accountant', ['id' => (string) $entryId]);
        self::assertSame(409, $blocked['status']);
        self::assertSame('later_year_confirmed', $blocked['body']['error']['code']);
        self::assertNotNull($this->entries->findYear($assetId, 'accounting', self::YEAR));
    }

    public function testDeletingDepreciationPreservesTaxPause(): void
    {
        $create = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-DEL-PAUSE', [
            'status' => 'in_use',
            'put_into_use_date' => self::YEAR . '-01-15',
        ]));
        $assetId = (int) $create['body']['asset']['id'];
        $paused = $this->call($this->depreciationAction, 'pause', 'POST', 'accountant', ['id' => (string) $assetId], [
            'fiscal_year' => self::YEAR,
        ]);
        self::assertSame(200, $paused['status']);
        $this->call($this->depreciationAction, 'bookYear', 'POST', 'accountant', [], ['fiscal_year' => self::YEAR]);

        $accounting = $this->entries->findYear($assetId, 'accounting', self::YEAR);
        $entryId = (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries
              WHERE supplier_id = {$this->supplierId} AND source_type = 'depreciation'
                AND source_id = " . (int) $accounting['id']
        )->fetchColumn();
        $deleted = $this->call($this->journalAction, 'delete', 'DELETE', 'accountant', ['id' => (string) $entryId]);

        self::assertSame(200, $deleted['status']);
        self::assertTrue((bool) $deleted['body']['pause_preserved']);
        self::assertNull($this->entries->findYear($assetId, 'accounting', self::YEAR));
        $tax = $this->entries->findYear($assetId, 'tax', self::YEAR);
        self::assertNotNull($tax);
        self::assertTrue((bool) $tax['is_paused']);
    }

    // ── I9: kandidáti z přijatých faktur ──────────────────────────────────────

    public function testI9PurchaseCandidatesFlagHasAsset(): void
    {
        $vendor = $this->vendor('Dodavatel kandidátů s.r.o.');
        $purchaseId = $this->purchase('PF-2098-KAND', $vendor, 200000.00, 42000.00, 21.00, true);
        $plainId = $this->purchase('PF-2098-PLAIN', $vendor, 5000.00, 1050.00, 21.00, false);

        $res = $this->call($this->assetAction, 'purchaseCandidates', 'GET', 'accountant');
        self::assertSame(200, $res['status']);
        $byId = array_column($res['body'], null, 'id');
        self::assertArrayHasKey($purchaseId, $byId, 'PF s is_fixed_asset je v kandidátech.');
        self::assertFalse((bool) $byId[$purchaseId]['has_asset'], 'Bez karty has_asset=false.');
        self::assertArrayNotHasKey($plainId, $byId, 'PF bez příznaku majetku v kandidátech není.');

        $create = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('M-API-KAND', [
            'purchase_invoice_id' => $purchaseId,
        ]));
        self::assertSame(201, $create['status']);

        $res2 = $this->call($this->assetAction, 'purchaseCandidates', 'GET', 'accountant');
        $byId2 = array_column($res2['body'], null, 'id');
        self::assertArrayHasKey($purchaseId, $byId2, 'PF zůstává v kandidátech (víc karet povoleno).');
        self::assertTrue((bool) $byId2[$purchaseId]['has_asset'], 'Po založení karty has_asset=true.');
    }

    public function testRevisionEndpointsRequireDepreciationWriteAndEnforceTenantAndPlanKind(): void
    {
        $created = $this->call($this->assetAction, 'create', 'POST', 'accountant', [], $this->cardPayload('SYN-REVISION-API'));
        self::assertSame(201, $created['status']);
        $args = ['id' => (string) $created['body']['asset']['id']];
        $input = ['effective_from' => '2099-01', 'remaining_months' => 12, 'reason' => 'Nový odhad', 'expected_revision' => 0];
        foreach (['previewRevision', 'revise'] as $method) {
            self::assertSame(403, $this->call($this->depreciationAction, $method, 'POST', 'readonly', $args, $input)['status']);
            $response = $this->call($this->depreciationAction, $method, 'POST', 'accountant', $args, $input);
            self::assertSame(422, $response['status']);
            self::assertSame('imported_plan_revision_not_allowed', $response['body']['error']['code']);
        }
        $oldSupplier = $this->supplierId;
        $this->supplierId = $this->cloneSupplier('double_entry');
        try {
            self::assertSame(404, $this->call($this->depreciationAction, 'previewRevision', 'POST', 'accountant', $args, $input)['status']);
        } finally { $this->supplierId = $oldSupplier; }
    }

    public function testRevisionScopeIsDepreciationWriteRatherThanCardWrite(): void
    {
        $year = self::YEAR + 1;
        $source = ['version' => 1, 'source_program' => 'Synthetic source',
            'source_tax_method' => 'source-tax', 'source_accounting_method' => 'source-accounting',
            'accounting' => [
                ['month' => $year . '-01', 'amount_cents' => 899000],
                ['month' => $year . '-02', 'amount_cents' => 899000],
                ['month' => $year . '-03', 'amount_cents' => 899000],
                ['month' => $year . '-04', 'amount_cents' => 903000],
            ], 'tax' => [['fiscal_year' => $year, 'amount_cents' => 3600000]]];
        $asset = $this->assetService->createImported($this->supplierId, [
            'inventory_number' => 'SYN-REVISION-SCOPE', 'name' => 'Synthetic imported software',
            'kind' => 'intangible', 'asset_account_code' => '013', 'accumulated_account_code' => '073',
            'acquisition_account_code' => '041', 'input_price' => 96000.0,
            'acquisition_date' => '2097-08-03', 'put_into_use_date' => '2097-08-05',
            'status' => 'in_use', 'tax_method' => 'by_accounting', 'acc_method' => 'straight_line',
            'acc_useful_life_months' => 20, 'opening_tax_years' => 2, 'opening_tax_amount' => 60000.0,
            'opening_acc_months' => 16, 'opening_acc_amount' => 60000.0,
        ], $source)['asset'];
        $args = ['id' => (string) $asset['id']];
        $input = ['effective_from' => $year . '-02', 'remaining_months' => 3,
            'reason' => 'Synthetic reassessment', 'expected_revision' => 0];
        foreach ([
            ['assets.write' => AccessLevel::WRITE->value, 'assets.depreciation' => AccessLevel::NONE->value],
            ['assets.write' => AccessLevel::WRITE->value, 'assets.depreciation' => AccessLevel::READ->value],
            ['assets.write' => AccessLevel::NONE->value, 'assets.depreciation' => AccessLevel::READ->value],
        ] as $permissions) {
            foreach (['previewRevision', 'revise'] as $method) {
                $response = $this->call($this->depreciationAction, $method, 'POST', 'accountant', $args, $input, $permissions);
                self::assertSame(403, $response['status']);
            }
        }
        $permissions = ['assets.write' => AccessLevel::NONE->value, 'assets.depreciation' => AccessLevel::WRITE->value];
        $preview = $this->call($this->depreciationAction, 'previewRevision', 'POST', 'accountant', $args, $input, $permissions);
        self::assertSame(200, $preview['status'], json_encode($preview['body'], JSON_THROW_ON_ERROR));
        $saved = $this->call($this->depreciationAction, 'revise', 'POST', 'accountant', $args, $input + [
            'expected_calendar_start_month' => $preview['body']['calendar_start_month'],
            'expected_calendar_fingerprint' => $preview['body']['calendar_fingerprint'],
        ], $permissions);
        self::assertSame(200, $saved['status'], json_encode($saved['body'], JSON_THROW_ON_ERROR));
        self::assertCount(1, $saved['body']['asset']['imported_depreciation_plan']['revisions']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function cardPayload(string $inventoryNumber, array $overrides = []): array
    {
        return array_merge([
            'inventory_number' => $inventoryNumber,
            'name' => 'Majetek ' . $inventoryNumber,
            'input_price' => 500000.00,
            'acquisition_date' => self::YEAR . '-01-10',
            'tax_method' => 'straight',
            'tax_group' => 2,
            'acc_useful_life_months' => 60,
        ], $overrides);
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @param array<string,int>|null $permissions
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(object $action, string $method, string $httpMethod, string $role, array $args = [], array $body = [], ?array $permissions = null): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting/assets')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        if ($permissions !== null) {
            $req = $req->withAttribute('auth.effective_role', new EffectiveRole(0, 'Synthetic scoped role', 'staff', true, $permissions));
        }
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        $resp = $args === []
            ? $action->{$method}($req, new Psr7Response())
            : $action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** Skutečný (nikoli neexistující) druhý double_entry supplier — od G6 gate
     *  (accounting_mode) 403uje neexistující/tax_evidence tenanta dřív, než se
     *  stihne vyhodnotit ownership 404 (viz Stock modul precedent, GuardsStockEnabled). */
    private function cloneSupplier(string $accountingMode): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email,
                default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['Cizí tenant s.r.o.', $this->czId, 'foreign-' . uniqid() . '@example.com',
            $this->currencyId, $this->vatRateId, $accountingMode]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function vendor(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchase(string $number, int $vendorId, float $base, float $vat, float $rate, bool $fixedAsset): int
    {
        $with = $base + $vat;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, is_fixed_asset, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", "40", "full", ?, ?)'
        );
        $issue = self::YEAR . '-01-10';
        $stmt->execute([$this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue,
            $this->currencyId, $base, $vat, $with, $fixedAsset ? 1 : 0, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $itemStmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, is_fixed_asset, order_index)
             VALUES (?, "Položka", 1, "ks", ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $itemStmt->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with, $fixedAsset ? 1 : 0]);
        return $id;
    }
}
