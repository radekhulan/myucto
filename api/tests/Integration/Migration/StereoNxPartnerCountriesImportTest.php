<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Shared\PartnerIdentityMatcher;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxAccountingTables;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class StereoNxPartnerCountriesImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxImporter $importer;
    private StereoNxAccountingImporter $accountingImporter;
    private PartnerIdentityMatcher $partners;
    private int $supplierId;
    private int $userId;
    private int $currencyId;
    private array $files = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(StereoNxImporter::class);
        $this->accountingImporter = $container->get(StereoNxAccountingImporter::class);
        $this->partners = $container->get(PartnerIdentityMatcher::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $pdo->prepare('INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default)
            VALUES (?,"CZK","CZK","Kč","CZK","CZK",2,1,1)')->execute([$this->supplierId]);
        $this->currencyId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$this->currencyId, $this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) if (is_file($file)) unlink($file);
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public static function modes(): array
    {
        return ['daňová evidence' => [false], 'podvojné účetnictví' => [true]];
    }

    #[DataProvider('modes')]
    public function testConfirmedCountryDryRunRepeatAndChangedChoiceAreSafe(bool $accounting): void
    {
        $backup = $this->backup($accounting);
        $before = $this->snapshot();
        $dry = $this->runImport($backup, $accounting, true, ['V1' => 'CZ']);
        self::assertTrue($dry['ok'], json_encode($dry, JSON_UNESCAPED_UNICODE));
        self::assertSame($before, $this->snapshot());
        self::assertSame('CZ', $dry['partner_country_candidates'][0]['confirmed_country']);
        self::assertCount(2, $dry['partner_country_candidates'][0]['documents']);
        $first = $this->runImport($backup, $accounting, false, ['V1' => 'CZ']);
        self::assertTrue($first['ok'], json_encode($first, JSON_UNESCAPED_UNICODE));
        self::assertSame('CZ', $this->vendor()['country_code']);
        $snapshot = $this->snapshot();
        $again = $this->runImport($backup, $accounting, false, ['V1' => 'CZ']);
        self::assertTrue($again['ok'], json_encode($again, JSON_UNESCAPED_UNICODE));
        self::assertSame($snapshot, $this->snapshot());
        $changed = $this->runImport($backup, $accounting, false, ['V1' => 'DE']);
        self::assertFalse($changed['ok']);
        self::assertContains('source_changed', array_column($changed['errors'], 'code'));
        self::assertSame($snapshot, $this->snapshot(), 'Změna země nesmí přepsat kartu ani existující historické doklady.');
    }

    #[DataProvider('modes')]
    public function testExistingCardWithDifferentCountryIsNotOverwrittenOrSilentlyAccepted(bool $accounting): void
    {
        $backup = $this->backup($accounting);
        $countryId = (int) $this->db->pdo()->query("SELECT id FROM countries WHERE iso2='PL'")->fetchColumn();
        self::assertGreaterThan(0, $countryId);
        $id = $this->partners->insertImportedClient($this->supplierId, ['name' => 'Syntetický existující partner',
            'ico' => '22222222', 'dic' => '', 'street' => 'Původní 1', 'city' => 'Vzorov', 'zip' => '10000',
            'country_id' => $countryId, 'currency_id' => $this->currencyId, 'email' => '', 'phone' => '',
            'is_vat_payer' => false, 'note' => 'Původní poznámka']);
        $before = $this->vendor();
        $report = $this->runImport($backup, $accounting, false, ['V1' => 'CZ']);
        self::assertTrue($report['ok'], json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame($before, $this->vendor());
        $reviews = array_values(array_filter($report['review_documents'],
            static fn (array $row): bool => $row['kind'] === 'purchase' && $row['document_no'] === 'PF-1'));
        self::assertCount(1, $reviews);
        self::assertContains('partner_country_changed', $reviews[0]['review_codes']);
        $stmt = $this->db->pdo()->prepare('SELECT vendor_id,status,vendor_is_vat_payer FROM purchase_invoices WHERE supplier_id=? AND varsymbol=?');
        $stmt->execute([$this->supplierId, 'PF-1']);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($id, $document['vendor_id']);
        self::assertSame('draft', $document['status']);
        self::assertSame(0, $document['vendor_is_vat_payer']);
    }

    #[DataProvider('modes')]
    public function testHistoricalVatIdConflictKeepsDocumentUnresolvedAfterCountryConfirmation(bool $accounting): void
    {
        $backup = $this->backup($accounting, conflictingVatIds: true);
        $report = $this->runImport($backup, $accounting, false, ['V1' => 'DE']);
        self::assertTrue($report['ok'], json_encode($report, JSON_UNESCAPED_UNICODE));
        $reviews = array_values(array_filter($report['review_documents'],
            static fn (array $row): bool => $row['kind'] === 'purchase' && $row['document_no'] === 'PF-1'));
        self::assertCount(1, $reviews);
        self::assertContains('partner_country_eu_unspecified', $reviews[0]['review_codes']);
        $stmt = $this->db->pdo()->prepare('SELECT p.status,co.iso2 FROM purchase_invoices p
            JOIN clients c ON c.id=p.vendor_id AND c.supplier_id=p.supplier_id
            JOIN countries co ON co.id=c.country_id WHERE p.supplier_id=? AND p.varsymbol=?');
        $stmt->execute([$this->supplierId, 'PF-1']);
        self::assertSame(['status' => 'draft', 'iso2' => 'DE'], $stmt->fetch(\PDO::FETCH_ASSOC));
    }

    private function runImport(StereoNxBackup $backup, bool $accounting, bool $dryRun, array $countries): array
    {
        return $accounting
            ? $this->accountingImporter->run($backup, $this->supplierId, $this->userId, $dryRun, false, null, $countries)
            : $this->importer->run($backup, $this->supplierId, $this->userId, $dryRun, false, $countries);
    }

    private function backup(bool $accounting, bool $conflictingVatIds = false): StereoNxBackup
    {
        $tables = SyntheticStereoNxTables::tables();
        $identity = $accounting ? SyntheticStereoNxAccountingTables::identity() : SyntheticStereoNxTables::identity();
        if ($accounting) {
            $clients = $tables['LAdresy'];
            $tables = array_replace($tables, SyntheticStereoNxAccountingTables::tables());
            $tables['LAdresy'] = $clients;
            foreach (['CBanka', 'CBankap', 'CPokl', 'Cpz', 'CPZZ'] as $table) $tables[$table] = [];
        }
        $tables['LAdresy'][1]['Stat'] = '';
        $tables['LAdresy'][1]['DIC'] = '';
        foreach ($tables['SPFH'] as &$row) {
            $row['FirmaStat'] = ''; $row['FirmaDIC'] = ''; $row['FirmaICO'] = '22222222';
        }
        unset($row);
        if ($conflictingVatIds) {
            $tables['LAdresy'][1]['ICO'] = '';
            $tables['LAdresy'][1]['DIC'] = 'DE111111111';
            $tables['LAdresy'][1]['Stat'] = 'EU';
            $tables['SPFH'][0]['FirmaICO'] = '';
            $tables['SPFH'][0]['FirmaDIC'] = 'ATU22222222';
            $tables['SPFH'][0]['FirmaStat'] = 'EU';
        }
        $this->db->pdo()->prepare("UPDATE supplier SET company_name=?,ic=?,dic=?,accounting_mode=?,is_vat_payer=1,
            accounting_enabled=0,accounting_starts_on=NULL,accounting_activation_status='none' WHERE id=?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $accounting ? 'double_entry' : 'tax_evidence', $this->supplierId]);
        $path = sys_get_temp_dir() . '/stereo-country-' . bin2hex(random_bytes(6)) . '.zip';
        $this->files[] = $path;
        SyntheticNx1Archive::write($path, $tables, $identity);
        return StereoNxBackup::open($path, 0);
    }

    private function vendor(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT c.*,co.iso2 AS country_code FROM clients c JOIN countries co ON co.id=c.country_id
            WHERE c.supplier_id=? AND c.ic=?');
        $stmt->execute([$this->supplierId, '22222222']);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['clients', 'invoices', 'purchase_invoices', 'stereo_nx_import_map'] as $table) {
            $order = $table === 'stereo_nx_import_map' ? 'source_ico,source_company_index,kind,source_key' : 'id';
            $stmt = $this->db->pdo()->prepare("SELECT * FROM {$table} WHERE supplier_id=? ORDER BY {$order}");
            $stmt->execute([$this->supplierId]);
            $result[$table] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $result;
    }
}
