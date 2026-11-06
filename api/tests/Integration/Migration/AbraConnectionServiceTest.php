<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AbraImportRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Migration\Abra\AbraConnectionService;
use MyInvoice\Service\Migration\Abra\AbraException;
use MyInvoice\Service\Migration\Abra\AbraReadOnlyClient;
use MyInvoice\Service\Migration\Abra\AbraOssSettingsImporter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class AbraConnectionServiceTest extends TestCase
{
    private Connection $db;
    private AbraConnectionService $service;
    private AbraImportRepository $maps;
    private array $suppliers = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->maps = new AbraImportRepository($this->db);
        $this->service = new AbraConnectionService($this->db, $container->get(SecretEncryption::class),
            new SyntheticConnectionClient(), $this->maps, new ImportJobRepository($this->db));
        $pdo = $this->db->pdo();
        $baseId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $baseId, 'Synthetic test database needs its supplier fixture.');
        foreach ([1, 2] as $index) {
            $pdo->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id FROM supplier WHERE id = ?')
                ->execute(['Synthetic ABRA ' . $index, 'abra' . $index . '@example.com', $baseId]);
            $this->suppliers[] = (int) $pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        foreach ($this->suppliers as $supplier) $this->db->pdo()->prepare('DELETE FROM supplier WHERE id = ?')->execute([$supplier]);
    }

    private function save(int $supplier, string $url = 'https://example.com/c/synthetic'): array
    {
        return $this->service->save($supplier, 0, ['url' => $url, 'username' => 'synthetic-user', 'password' => 'synthetic-secret']);
    }

    public function testCredentialsAreEncryptedAndNeverReturnedInStatus(): void
    {
        $status = $this->save($this->suppliers[0]);
        self::assertTrue($status['configured']);
        self::assertSame([(int) date('Y') - 1, (int) date('Y')], $status['default_years']);
        $stmt = $this->db->pdo()->prepare('SELECT credentials_enc FROM abra_flexi_connections WHERE supplier_id = ?');
        $stmt->execute([$this->suppliers[0]]);
        $cipher = $stmt->fetchColumn();
        self::assertStringStartsWith('enc:v2:', $cipher);
        self::assertStringNotContainsString('synthetic-secret', $cipher);
        self::assertSame('synthetic-secret', $this->service->credentials($this->suppliers[0])['password']);
        self::assertStringNotContainsString('synthetic-secret', json_encode($status));
        self::assertStringNotContainsString('synthetic-user', json_encode($status));
        self::assertStringNotContainsString('example.com', json_encode($status));
        self::assertFalse($this->service->status($this->suppliers[1])['configured']);
    }

    public function testCiphertextCopiedToAnotherCompanyCannotBeDecrypted(): void
    {
        $this->save($this->suppliers[0]);
        $this->save($this->suppliers[1]);
        $this->db->pdo()->prepare('UPDATE abra_flexi_connections dst JOIN abra_flexi_connections src ON src.supplier_id = ?
            SET dst.credentials_enc = src.credentials_enc WHERE dst.supplier_id = ?')->execute($this->suppliers);
        try {
            $this->service->credentials($this->suppliers[1]);
            self::fail('Ciphertext crossed company isolation.');
        } catch (AbraException $e) { self::assertSame('credentials_unavailable', $e->errorCode); }
    }

    public function testPartialImportLocksSourceAndCredentialsCannotBeDeleted(): void
    {
        $supplier = $this->suppliers[0];
        $this->save($supplier);
        $this->maps->remember($supplier, 'ucetni-denik', 'synthetic:1', hash('sha256', 'synthetic'), 'journal_entry', 1, 2025);
        try {
            $this->save($supplier, 'https://example.com/c/other');
            self::fail('Source changed after a partial import.');
        } catch (AbraException $e) { self::assertSame('source_locked', $e->errorCode); }
        try {
            $this->service->delete($supplier);
            self::fail('Source deleted after a partial import.');
        } catch (AbraException $e) { self::assertSame('source_locked', $e->errorCode); }
    }

    public function testSyncWarningsPersistInStatus(): void
    {
        $supplier = $this->suppliers[0];
        $this->save($supplier);
        $this->service->markSynced($supplier, [(int) date('Y')], ['warnings' => ['Synthetic sync warning']]);
        $status = $this->service->status($supplier);
        self::assertTrue($status['imported']);
        self::assertContains('Synthetic sync warning', $status['warnings']);
        self::assertSame([(int) date('Y')], $status['selected_years']);
    }

    public function testSourceEuOssEnablesOnlyMatchingSupplierAndKeepsManualSetup(): void
    {
        $first = $this->suppliers[0];
        $second = $this->suppliers[1];
        $importer = new AbraOssSettingsImporter($this->db);
        self::assertFalse($importer->apply($first, ['eu' => false, 'valid_from' => null]));
        self::assertTrue($importer->apply($first, ['eu' => true, 'valid_from' => '2025-02-01']));
        self::assertFalse($importer->apply($first, ['eu' => true, 'valid_from' => '2026-01-01']));

        $stmt = $this->db->pdo()->prepare('SELECT oss_enabled, oss_valid_from, oss_identification_country FROM supplier WHERE id = ?');
        $stmt->execute([$first]);
        $enabled = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $enabled['oss_enabled']);
        self::assertSame('2025-02-01', $enabled['oss_valid_from']);
        self::assertMatchesRegularExpression('/^[A-Z]{2}$/', $enabled['oss_identification_country']);
        $stmt->execute([$second]);
        self::assertSame(0, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['oss_enabled']);
    }

    public function testStatusReportsIcoMismatchBeforeTransferStarts(): void
    {
        $supplier = $this->suppliers[0];
        self::assertFalse($this->service->status($supplier)['ico_mismatch']);
        $setIco = $this->db->pdo()->prepare('UPDATE supplier SET ic = ? WHERE id = ?');
        $setIco->execute(['12345678', $supplier]);
        $status = $this->save($supplier);
        self::assertTrue($status['ico_mismatch']);
        self::assertSame(['name' => 'Synthetic ABRA 1', 'ico' => '12345678'], $status['target_company']);
        self::assertSame('88888888', $status['source_company']['ico']);
        $setIco->execute(['088 888 888', $supplier]);
        self::assertFalse($this->service->status($supplier)['ico_mismatch']);
        $setIco->execute([null, $supplier]);
        self::assertTrue($this->service->status($supplier)['ico_mismatch']);
    }

    public function testDiscoveryWarningForUnselectedYearIsHidden(): void
    {
        $supplier = $this->suppliers[0];
        $this->save($supplier);
        $warning = 'Rok 2021 obsahuje více zdrojových účetních období. Jeho převod vyžaduje kontrolu vymezení období.';
        $this->db->pdo()->prepare('UPDATE abra_flexi_connections SET discovery = JSON_SET(discovery, "$.warnings", JSON_ARRAY(?)) WHERE supplier_id = ?')
            ->execute([$warning, $supplier]);
        $this->service->markSynced($supplier, [2026], []);
        self::assertNotContains($warning, $this->service->status($supplier)['warnings']);
        $this->service->markSynced($supplier, [2021], []);
        self::assertContains($warning, $this->service->status($supplier)['warnings']);
    }
}

final class SyntheticConnectionClient extends AbraReadOnlyClient
{
    public function __construct() {}
    public function list(array $credentials, string $evidence, array $query = [], ?callable $progress = null, ?callable $cancelled = null, ?callable $consume = null): array
    {
        return array_map(static fn ($year) => ['platiOdData' => $year . '-01-01', 'platiDoData' => $year . '-12-31'],
            [(int) date('Y') - 2, (int) date('Y') - 1, (int) date('Y')]);
    }
    public function get(array $credentials, string $evidence, array $query = []): array
    {
        return $evidence === 'nastaveni'
            ? ['winstrom' => ['nastaveni' => [['nazFirmy' => 'Synthetic company', 'ic' => '88888888', 'platiOdData' => '']]]]
            : ['evidences' => ['companyName' => 'Synthetic company']];
    }
}
