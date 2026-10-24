<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\StereoNx\StereoNxAssets;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxIntangibleTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxIntangibleTables.php';

#[Group('integration')]
final class StereoNxAssetsWriteTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxAssets $assets;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php') && !getenv('MYINVOICE_DB_NAME')) {
            self::markTestSkipped('Integration database is not configured.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->assets = $container->get(StereoNxAssets::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $source);
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $account = $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ([
            ['022', 'Samostatné movité věci', 'asset', 'debit'],
            ['042', 'Pořízení dlouhodobého majetku', 'asset', 'debit'],
            ['082', 'Oprávky', 'asset', 'credit'],
        ] as $row) {
            $account->execute([$this->supplierId, ...$row]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testWriteIsIdempotentAndCreatesNoJournalOrDepreciationRows(): void
    {
        $plan = StereoNxAssets::fromTables($this->tables(), $this->identity(), 1);

        $first = $this->assets->write($plan, $this->supplierId, $this->userId);
        self::assertSame(1, $first['assets_created']);
        self::assertSame(1, $first['small_assets_created']);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM assets WHERE supplier_id = ?'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM small_assets WHERE supplier_id = ?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM depreciation_entries WHERE supplier_id = ?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?'));

        $again = $this->assets->write($plan, $this->supplierId, $this->userId);
        self::assertSame(0, $again['assets_created']);
        self::assertSame(1, $again['assets_existing']);
        self::assertSame(0, $again['small_assets_created']);
        self::assertSame(1, $again['small_assets_existing']);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM assets WHERE supplier_id = ?'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM small_assets WHERE supplier_id = ?'));
    }

    #[DataProvider('accountFormats')]
    public function testAssetAccountsFollowNormalizedChartAndLegacyImportMappings(bool $legacy): void
    {
        $container = Bootstrap::buildContainer();
        $accounts = $container->get(\MyInvoice\Repository\ChartOfAccountsRepository::class);
        $creator = new \MyInvoice\Service\Migration\Shared\ChartAccountCreator($accounts);
        $map = $container->get(\MyInvoice\Service\Migration\StereoNx\StereoNxImportMap::class);
        $tables = $this->tables();
        $expected = [];
        foreach (['asset_account_code' => ['022', 'UcetMDZarazeni'],
            'acquisition_account_code' => ['042', 'UcetDalZarazeni'],
            'accumulated_account_code' => ['082', 'UcetDalOdpisu']] as $field => [$parentCode, $sourceField]) {
            $sourceCode = $parentCode . '024';
            $targetCode = $legacy ? $sourceCode : $parentCode . '.024';
            $id = $creator->createAnalytic($this->supplierId, $targetCode,
                'Syntetická analytika', $accounts->findByCode($this->supplierId, $parentCode));
            if ($legacy) {
                $map->put($this->supplierId, $this->identity()['ico'], 1,
                    'accounting_account', $sourceCode, str_repeat('b', 64), $id);
            }
            $tables['JMajetek'][0][$sourceField] = $sourceCode;
            $expected[$field] = $targetCode;
        }
        $plan = StereoNxAssets::fromTables($tables, $this->identity(), 1);
        self::assertSame(1, $plan['counts']['assets_ready']);
        $this->assets->write($plan, $this->supplierId, $this->userId);
        $query = $this->db->pdo()->prepare('SELECT asset_account_code, acquisition_account_code, accumulated_account_code FROM assets WHERE supplier_id = ?');
        $query->execute([$this->supplierId]);
        self::assertSame($expected, $query->fetch(\PDO::FETCH_ASSOC));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?'));
        self::assertSame(1, $this->assets->write($plan, $this->supplierId, $this->userId)['assets_existing']);
    }

    public static function accountFormats(): iterable
    {
        yield 'nový převod' => [false];
        yield 'opakování staršího převodu' => [true];
    }

    public function testChangedSourceCardIsRejectedInsteadOfDuplicated(): void
    {
        $plan = StereoNxAssets::fromTables($this->tables(), $this->identity(), 1);
        $this->assets->write($plan, $this->supplierId, $this->userId);
        $changed = $plan;
        $changed['records']['assets'][0]['source_hash'] = str_repeat('a', 64);

        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('změnila');
        $this->assets->write($changed, $this->supplierId, $this->userId);
    }

    public function testExactIntangiblePlanIsStoredAndContinuesWithoutPostingHistoryAgain(): void
    {
        $account = $this->db->pdo()->prepare('INSERT INTO chart_of_accounts
            (supplier_id, account_code, name, account_type, normal_side) VALUES (?, ?, ?, ?, ?)');
        foreach ([['013', 'Syntetická licence', 'asset', 'debit'],
            ['041', 'Syntetické pořízení licence', 'asset', 'debit'],
            ['073', 'Syntetické oprávky licence', 'asset', 'credit']] as $row) {
            $account->execute([$this->supplierId, ...$row]);
        }
        $tables = SyntheticStereoNxIntangibleTables::tables();
        $plan = StereoNxAssets::fromTables($tables, $this->identity(), 1);
        self::assertSame(1, $plan['counts']['assets_ready'], json_encode($plan['warnings']));
        $first = $this->assets->write($plan, $this->supplierId, $this->userId);
        self::assertSame(1, $first['assets_created']);
        $query = $this->db->pdo()->prepare('SELECT id, status, tax_method, acc_method,
            opening_acc_months, opening_tax_years, opening_acc_amount, opening_tax_amount,
            imported_depreciation_plan FROM assets WHERE supplier_id = ?');
        $query->execute([$this->supplierId]);
        $asset = $query->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($asset);
        self::assertSame('in_use', $asset['status']);
        self::assertSame('by_accounting', $asset['tax_method']);
        self::assertSame('straight_line', $asset['acc_method']);
        self::assertSame(20, (int) $asset['opening_acc_months']);
        self::assertSame(2, (int) $asset['opening_tax_years']);
        self::assertSame(20_000.0, (float) $asset['opening_acc_amount']);
        self::assertSame(20_000.0, (float) $asset['opening_tax_amount']);
        $stored = json_decode($asset['imported_depreciation_plan'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($plan['records']['assets'][0]['imported_depreciation_plan'], $stored);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM depreciation_entries WHERE supplier_id = ?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?'));

        $target = Bootstrap::buildContainer()->get(AssetService::class)->plan($this->supplierId, (int) $asset['id']);
        $months = [];
        foreach ($target['accounting'] as $year) {
            foreach ($year['months'] ?? [] as $month) {
                $months[] = ['month' => $month['month'], 'amount_cents' => (int) round($month['amount'] * 100)];
            }
        }
        self::assertSame($stored['accounting'], $months);
        self::assertSame(400_007, (int) round(array_sum(array_column($target['tax'], 'amount')) * 100));
        self::assertSame(100_007, $months[3]['amount_cents']);
        $repeat = $this->assets->write($plan, $this->supplierId, $this->userId);
        self::assertSame(0, $repeat['assets_created']);
        self::assertSame(1, $repeat['assets_existing']);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM assets WHERE supplier_id = ?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM depreciation_entries WHERE supplier_id = ?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?'));

        $tables['JUcOdpisy'][20]['Castka'] += 0.10;
        $tables['JUcOdpisy'][23]['Castka'] -= 0.10;
        $changed = StereoNxAssets::fromTables($tables, $this->identity(), 1);
        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('změnila');
        $this->assets->write($changed, $this->supplierId, $this->userId);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function tables(): array
    {
        $asset = [
            'InvCislo' => 'M-SYN-1', 'Nazev' => 'Syntetický stroj', 'Typ' => 'H',
            'CenaPorizovaci' => 100_000.0, 'DatumPorizeni' => '2020-01-15', 'DatumZarazeni' => '2020-01-15',
            'DatumVyrazeni' => '', 'UcetMDZarazeni' => '022', 'UcetDalZarazeni' => '042',
            'UcetDalOdpisu' => '082', 'ZpusobDanOdepisovani' => 'Z', 'OdpisovaSkupina' => '2',
            'ZvyseniSazProc' => 0, 'DanoveOdepsano' => 20_000.0, 'UcetneOdepsano' => 20_000.0,
        ];
        $depreciation = [
            ['InvCislo' => 'M-SYN-1', 'Datum' => '2020-12-31', 'Castka' => 20_000.0, 'DatumUplatneni' => '2021-01-04'],
            ['InvCislo' => 'M-SYN-1', 'Datum' => '2021-12-31', 'Castka' => 30_000.0, 'DatumUplatneni' => ''],
        ];
        return [
            'JMajetek' => [$asset],
            'JDrobMaj' => [[
                'InvCislo' => 'D-SYN-1', 'Nazev' => 'Syntetická židle', 'Typ' => 'H',
                'JednCena' => 4_000.0, 'Mnozstvi' => 2.0, 'DatumPorizeni' => '2023-05-02',
                'DatumZarazeni' => '2023-05-03', 'DatumVyrazeni' => '',
            ]],
            'JDanOdpisy' => $depreciation,
            'JUcOdpisy' => $depreciation,
            'JTechZhod' => [],
        ];
    }

    /** @return array{ico:string,dic:string,name:string,vat_payer:bool} */
    private function identity(): array
    {
        return ['ico' => '12345679', 'dic' => 'CZ12345679', 'name' => 'Syntetická firma s.r.o.', 'vat_payer' => true];
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }
}
