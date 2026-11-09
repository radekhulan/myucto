<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxAccountingTables;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use MyInvoice\Tests\Support\EntryFormMigrationTrait;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticNx1Archive.php';
require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxAccountingTables.php';
require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxTables.php';

/**
 * Účetní převod ze Stereo NX v instalaci s výchozím jednoduchým zápisem: firma bez
 * formy a bez zápisů dostane formu single před prvním zápisem deníku a každá kontace
 * Stereo NX (MD, Dal, částka) je jedna dvojice s textem kontace. Obraty po účtech
 * a řádky deníku jsou stejné jako ve složené formě.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class StereoNxEntryFormImportTest extends TestCase
{
    use EntryFormMigrationTrait;
    use IsolatedSupplierTrait;

    private const MAP_KEYS = "SELECT CONCAT(kind, '|', source_key) AS entry_key, target_id AS entry_id FROM stereo_nx_import_map WHERE supplier_id = ? AND kind = 'accounting_journal'";

    private ?Connection $db = null;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
        self::assertTrue(str_ends_with((string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) @unlink($file);
        if ($this->db !== null && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testSingleInstallationImportsStereoJournalAsSourcePairsWithSameTurnover(): void
    {
        $path = sys_get_temp_dir() . '/stereo-entry-form-' . bin2hex(random_bytes(6)) . '.zip';
        SyntheticNx1Archive::write($path, self::tables(), SyntheticStereoNxAccountingTables::identity());
        $this->files[] = $path;
        $this->assertSingleMatchesComposite(function (ContainerInterface $c, PDO $pdo) use ($path): array {
            $supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
            $identity = SyntheticStereoNxAccountingTables::identity();
            $pdo->prepare("UPDATE supplier SET company_name=?, ic=?, dic=?, accounting_mode='double_entry',
                accounting_enabled=0, accounting_starts_on=NULL, accounting_activation_status='none', is_vat_payer=1 WHERE id=?")
                ->execute([$identity['name'], $identity['ico'], $identity['dic'], $supplierId]);
            $this->setVatPayerAt($pdo, $supplierId, '1900-01-01', true);
            $pdo->prepare('INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default)
                VALUES (?,"CZK","CZK","Kč","CZK","CZK",2,1,1)')->execute([$supplierId]);
            $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([(int) $pdo->lastInsertId(), $supplierId]);

            $report = $c->get(StereoNxAccountingImporter::class)->run(StereoNxBackup::open($path, 0), $supplierId, $this->firstUserId($pdo), false, true);
            self::assertTrue($report['ok'], (string) json_encode($report['errors'] ?? $report, JSON_UNESCAPED_UNICODE));
            return [
                'fingerprint' => $this->journalFingerprint($pdo, $supplierId),
                'lines' => $this->journalLinesByKey($pdo, $supplierId, self::MAP_KEYS),
                'compare' => [$report['written'], array_map(static fn (array $y): bool => (bool) $y['ok'], $report['reconciliation'])],
            ];
        });
    }

    private static function tables(): array
    {
        $documents = SyntheticStereoNxTables::tables();
        $tables = array_replace($documents, SyntheticStereoNxAccountingTables::tables());
        $tables['LAdresy'] = $documents['LAdresy'];
        $tables['LFirmaUc'][0]['BaUcet'] = '19-1000000005';
        $tables['LFirmaUc'][0]['KodBanky'] = '0100';
        $tables['CBanka'][0]['Kurz'] = 1.0; $tables['CBanka'][0]['KurzMn'] = 1.0;
        foreach ($tables['CBankap'] as &$row) {
            $row['Kurz'] = 1.0; $row['KurzMn'] = 1.0; $row['CastkaVlastni'] = $row['Castka'];
            $row['DPHz'] = 0.0; $row['DPHs'] = 0.0; $row['DPHt'] = 0.0;
        }
        unset($row);
        $tables['CBankap'][3]['DPHz'] = 1.0;
        $tables['CBankap'][3]['DokladS'] = 'BANK-4';
        $tables['CBankap'][4]['Kurz'] = 25.0;
        $tables['CBankap'][4]['CastkaVlastni'] = 1250.0;
        foreach ($tables['CPokl'] as &$row) {
            $row['Kurz'] = 1.0; $row['KurzMn'] = 1.0; $row['CastkaVlastni'] = $row['Castka'];
            $row['DPHz'] = 0.0; $row['DPHs'] = 0.0; $row['DPHt'] = 0.0;
        }
        unset($row);
        foreach ($tables['CPZZ'] as &$row) $row['Mena'] = 'Kč';
        unset($row);
        foreach ($tables['Cpz'] as &$row) $row['Agenda'] = $row['DoklSRada'];
        unset($row);
        return $tables;
    }

}
