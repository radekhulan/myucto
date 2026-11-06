<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Capital\CapitalService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Vyřazení majetku financovaného investičním zdrojem (901): zbytek zdroje se zúčtuje
 * 901/649 ve stejné operaci jako vyřazení a vrácení vyřazení ho řízeně stornuje.
 * Scénář: majetek 500 000 Kč z daru, odepsáno 200 000 Kč, prodej, 901/649 300 000 Kč.
 */
final class CapitalAssetDisposalTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private PostingService $posting;
    private CapitalService $capital;
    private DepreciationPostingService $depreciation;
    private AssetService $assets;
    private int $supplier;
    private int $actor;
    private int $asset;
    private int $provider;
    private int $kind;
    private array $catalog;
    private array $dimensions;

    protected function setUp(): void
    {
        $c = Bootstrap::buildContainer();
        $this->db = $c->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);
        $periods = $c->get(AccountingPeriodRepository::class);
        $periods->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $periods->create($this->supplier, 2027, '2027-01-01', '2027-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $this->catalog = $c->get(SystemDimensionCatalog::class)->ensureForFramework($this->supplier, '2026-01-01');
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name)VALUES(?,?,'SPORT','Synthetic sporting activity')")->execute([$this->catalog['activity_kind']['id'], $this->supplier]);
        $this->kind = (int) $this->pdo->lastInsertId();
        $this->dimensions = [
            $this->catalog['activity']['id'] => $this->catalog['activity']['values']['main'],
            $this->catalog['activity_kind']['id'] => $this->kind,
            $this->catalog['tax_regime']['id'] => $this->catalog['tax_regime']['values']['exempt'],
        ];
        $accounts = ['221' => 'asset', '022' => 'asset', '082' => 'asset', '541' => 'expense', '551' => 'expense', '901' => 'equity', '649' => 'revenue'];
        foreach ($accounts as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type)VALUES(?,?,?,?)')->execute([$this->supplier, (string) $code, 'Synthetic ' . $code, $type]);
        }
        $currency = (int) $this->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor)VALUES(?,'Synthetic capital donor','Synthetic street','Synthetic city','10000',203,'capital@example.test','cs',?,0,1)")->execute([$this->supplier, $currency]);
        $this->provider = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO assets(supplier_id,inventory_number,name,input_price,acquisition_date,put_into_use_date,status,asset_account_code,accumulated_account_code,tax_method,acc_method,acc_useful_life_months)VALUES(?,'CAPITAL-DISPOSAL','Synthetic donated machine',500000,'2025-12-01','2025-12-01','in_use','022','082','none','straight_line',50)")->execute([$this->supplier]);
        $this->asset = (int) $this->pdo->lastInsertId();
        foreach ($this->dimensions as $type => $value) {
            $this->pdo->prepare("INSERT INTO document_dimensions(supplier_id,doc_type,doc_id,item_no,dimension_type_id,dimension_value_id,assignment_origin)VALUES(?,'asset',?,0,?,?,'explicit')")->execute([$this->supplier, $this->asset, $type, $value]);
        }
        $this->posting = $c->get(PostingService::class);
        $this->capital = new CapitalService($this->db, $this->posting);
        $this->depreciation = $c->get(DepreciationPostingService::class);
        $this->assets = $c->get(AssetService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testSaleOfDonatedAssetReleasesTheRemainingSourceThrough901649AndRevertRestoresIt(): void
    {
        $this->enrollFullDonation();
        $this->bookFirstYear();

        $this->assets->dispose($this->supplier, $this->asset, ['date' => '2027-08-31', 'type' => 'sold', 'price' => 350000], $this->meta());

        $source = $this->capital->read($this->supplier)['sources'][0];
        self::assertSame(50000000, $source['used_cents']);
        self::assertSame(0, $source['available_cents']);
        self::assertSame([12000000, 8000000, 30000000], array_column($source['events'], 'amount_cents'));
        $disposalRelease = $source['events'][2];
        self::assertSame(['901', '649'], array_column($disposalRelease['receipt']['lines'], 'account_code'));
        self::assertSame(['debit', 'credit'], array_column($disposalRelease['receipt']['lines'], 'side'));
        self::assertSame('2027-08-31', $disposalRelease['receipt']['entry']['entry_date']);
        $disposalEntry = $this->disposalEntry();
        self::assertSame($disposalEntry, $disposalRelease['snapshot']['cost']['entry_id']);
        self::assertSame('541', $disposalRelease['snapshot']['cost']['account_code']);
        self::assertSame(30000000, $disposalRelease['snapshot']['cost']['amount_cents']);
        self::assertSame('500000.00', $this->balance('649'));
        self::assertSame('0.00', $this->balance('901'));

        try {
            $this->posting->reverse($this->supplier, $disposalEntry, ['entry_date' => '2027-08-31', 'posted_by' => $this->actor]);
            self::fail('Disposal covered by the capital source must be reverted only by its owner.');
        } catch (PostingException $e) {
            self::assertSame('nonprofit_capital_source_in_use', $e->errorCode);
        }

        $this->assets->revertDisposal($this->supplier, $this->asset, $this->meta());

        $source = $this->capital->read($this->supplier)['sources'][0];
        self::assertSame(12000000, $source['used_cents']);
        self::assertSame(38000000, $source['available_cents']);
        self::assertSame(['release', 'release', 'release', 'reverse', 'reverse'], array_column($source['events'], 'operation'));
        self::assertSame('120000.00', $this->balance('649'));
        $q = $this->pdo->prepare('SELECT reversed_by FROM journal_entries WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplier, $disposalEntry]);
        self::assertNotNull($q->fetchColumn());
        $q = $this->pdo->prepare('SELECT status FROM assets WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplier, $this->asset]);
        self::assertSame('in_use', $q->fetchColumn());

        $this->assets->dispose($this->supplier, $this->asset, ['date' => '2027-08-31', 'type' => 'sold', 'price' => 350000], $this->meta());

        $source = $this->capital->read($this->supplier)['sources'][0];
        self::assertSame(50000000, $source['used_cents']);
        self::assertSame([12000000, 8000000, 30000000, 30000000, 8000000, 8000000, 30000000], array_column($source['events'], 'amount_cents'));
        self::assertSame('500000.00', $this->balance('649'));
        self::assertSame('0.00', $this->balance('901'));
    }

    public function testRevertedDisposalRestoresTheDepreciationPlanOfTheDisposalYear(): void
    {
        $this->enrollFullDonation();
        $this->bookFirstYear();
        $before = $this->assets->plan($this->supplier, $this->asset);

        $this->assets->dispose($this->supplier, $this->asset, ['date' => '2027-08-31', 'type' => 'sold', 'price' => 350000], $this->meta());
        $this->assets->revertDisposal($this->supplier, $this->asset, $this->meta());

        $after = $this->assets->plan($this->supplier, $this->asset);
        self::assertSame($before['accounting'], $after['accounting']);
        self::assertSame($before['tax'], $after['tax']);
        self::assertSame($before['asset_summary'], $after['asset_summary']);

        $result = $this->depreciation->bookYear($this->supplier, 2027, $this->meta());
        self::assertSame([], $result['errors']);
        self::assertSame(120000.0, $result['total_accounting']);
        self::assertSame(24000000, $this->capital->read($this->supplier)['sources'][0]['used_cents']);
    }

    public function testPartialInvestmentSourceReleasesOnlyItsProportionalRemainder(): void
    {
        $this->enroll('300000.00');
        $this->bookFirstYear();

        $this->assets->dispose($this->supplier, $this->asset, ['date' => '2027-08-31', 'type' => 'liquidated'], $this->meta());

        $source = $this->capital->read($this->supplier)['sources'][0];
        self::assertSame([7200000, 4800000, 18000000], array_column($source['events'], 'amount_cents'));
        self::assertSame(0, $source['available_cents']);
        self::assertSame('0.00', $this->balance('901'));
        self::assertSame('300000.00', $this->balance('649'));
    }

    public function testJournalOnlyDisposalCannotLeaveTheRemainingSourceOn901(): void
    {
        $this->enrollFullDonation();
        $this->bookFirstYear();

        try {
            $this->assets->disposeFromJournal($this->supplier, $this->asset, ['date' => '2027-08-31', 'type' => 'sold'], $this->meta());
            self::fail('Journal-only disposal cannot keep the remaining investment source.');
        } catch (AssetException $e) {
            self::assertSame('nonprofit_capital_disposal_requires_module', $e->errorCode);
        }
        $q = $this->pdo->prepare('SELECT status FROM assets WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplier, $this->asset]);
        self::assertSame('in_use', $q->fetchColumn());
    }

    public function testOverviewReadTakesNoRowLocksAndReadsEachDepreciationYearOnce(): void
    {
        $this->enrollFullDonation();
        $this->bookFirstYear();
        $this->depreciation->bookYear($this->supplier, 2027, $this->meta());

        $locks = fn (): int => (int) $this->pdo->query('SELECT trx_lock_structs FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()')->fetchColumn();
        $before = $locks();
        $source = $this->capital->read($this->supplier)['sources'][0];
        self::assertSame(24000000, $source['used_cents']);
        self::assertSame($before, $locks(), 'Přehled zdrojů nesmí zamykat období ani účetní řádky.');

        $queries = fn (): int => (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        $start = $queries();
        $this->capital->read($this->supplier);
        $twoYears = $queries() - $start - 1;
        Bootstrap::buildContainer()->get(AccountingPeriodRepository::class)->create($this->supplier, 2028, '2028-01-01', '2028-12-31');
        $this->depreciation->bookYear($this->supplier, 2028, $this->meta());
        $start = $queries();
        $this->capital->read($this->supplier);
        $threeYears = $queries() - $start - 1;
        Bootstrap::buildContainer()->get(AccountingPeriodRepository::class)->create($this->supplier, 2029, '2029-01-01', '2029-12-31');
        $this->depreciation->bookYear($this->supplier, 2029, $this->meta());
        $start = $queries();
        $this->capital->read($this->supplier);
        $fourYears = $queries() - $start - 1;
        self::assertSame($threeYears - $twoYears, $fourYears - $threeYears, 'Ověření historie musí růst lineárně s počtem ročních odpisů.');
    }

    private function enrollFullDonation(): void
    {
        $this->enroll('500000.00');
    }

    private function enroll(string $amount): void
    {
        $entry = $this->posting->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '901', 'side' => 'credit', 'amount' => $amount] + $this->lineDimensions(),
            ['account_code' => '221', 'side' => 'debit', 'amount' => $amount] + $this->lineDimensions(),
        ], ['entry_date' => '2026-01-01', 'description' => 'Synthetic donation for an asset', 'posted' => true, 'posted_by' => $this->actor]);
        $q = $this->pdo->prepare("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code='901'");
        $q->execute([$this->supplier, $entry]);
        $policy = [
            'kind' => 'investment', 'source_line_id' => (int) $q->fetchColumn(), 'asset_id' => $this->asset, 'provider_id' => $this->provider,
            'revenue_tax_regime_id' => $this->catalog['tax_regime']['values']['exempt'], 'title' => 'Synthetic donated asset',
            'purpose' => 'Sporting equipment', 'evidence' => 'Synthetic donor contract', 'decision_authority' => 'Synthetic authorized board',
            'valid_from' => '2026-01-01', 'valid_to' => '2035-12-31', 'activity_kind_ids' => [$this->kind], 'account_prefixes' => ['551'],
        ];
        $preview = $this->capital->previewSource($this->supplier, $policy);
        $this->capital->confirmSource($this->supplier, $policy, $preview['input_hash'], 'Synthetic explicit release approval', 'disposal-source', $this->actor);
    }

    private function bookFirstYear(): void
    {
        $result = $this->depreciation->bookYear($this->supplier, 2026, $this->meta());
        self::assertSame([], $result['errors']);
        self::assertSame(120000.0, $result['total_accounting']);
    }

    private function lineDimensions(): array
    {
        return ['dimensions' => $this->dimensions, 'dimension_origins' => array_fill_keys(array_keys($this->dimensions), ['origin' => 'explicit', 'ref' => null])];
    }

    private function meta(): array
    {
        return ['posted_by' => $this->actor, 'user_id' => $this->actor];
    }

    private function disposalEntry(): int
    {
        $q = $this->pdo->prepare("SELECT id FROM journal_entries WHERE supplier_id=? AND source_type='asset_disposal' AND source_id=? AND reversed_by IS NULL");
        $q->execute([$this->supplier, $this->asset]);
        return (int) $q->fetchColumn();
    }

    /** Kreditní zůstatek účtu (kredit − debet) přes zaúčtované řádky firmy. */
    private function balance(string $account, string $side = 'net'): string
    {
        $q = $this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN l.side='credit' THEN l.amount ELSE 0 END),0) credit, COALESCE(SUM(CASE WHEN l.side='debit' THEN l.amount ELSE 0 END),0) debit FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND a.account_code=? AND e.posted_at IS NOT NULL");
        $q->execute([$this->supplier, $account]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $side === 'credit' ? number_format((float) $row['credit'], 2, '.', '') : number_format((float) $row['credit'] - (float) $row['debit'], 2, '.', '');
    }
}
