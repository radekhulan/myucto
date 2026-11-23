<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Abra;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Abra\AbraAccountingImporter;
use MyInvoice\Tests\Support\EntryFormMigrationTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Převod z ABRA Flexi v instalaci s výchozím jednoduchým zápisem: nová firma dostane
 * formu single před prvním zápisem deníku (podvojné účetnictví převod přepíná až na
 * konci) a každý řádek deníku ABRA je jedna dvojice. Obraty po účtech a řádky deníku
 * jsou stejné jako ve složené formě.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class AbraEntryFormImportTest extends TestCase
{
    use EntryFormMigrationTrait;

    private const MAP_KEYS = "SELECT CONCAT(kind, '|', abra_key) AS entry_key, target_id AS entry_id FROM abra_flexi_import_map WHERE supplier_id = ? AND target_type = 'journal_entry'";

    private ?Connection $db = null;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testSingleInstallationImportsAbraJournalAsPairsWithSameTurnover(): void
    {
        $this->assertSingleMatchesComposite(function (ContainerInterface $c, PDO $pdo): array {
            $supplierId = $this->migrationFirm($pdo, 'Synthetic ABRA target', '88888888');
            // Převod z ABRA jde do firmy, která už podvojné účetnictví vede.
            $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', accounting_enabled = 1 WHERE id = ?")->execute([$supplierId]);
            $report = $c->get(AbraAccountingImporter::class)->import($supplierId, $this->firstUserId($pdo), $this->snapshot(), [2025],
                static function (): void {}, static fn (): bool => false);
            self::assertFalse($report['blocked'], (string) json_encode($report));
            self::assertSame(0, $report['failed'], (string) json_encode($report));
            return [
                'fingerprint' => $this->journalFingerprint($pdo, $supplierId),
                'lines' => $this->journalLinesByKey($pdo, $supplierId, self::MAP_KEYS),
                'compare' => $report['counts'],
            ];
        });
    }

    private function snapshot(): array
    {
        $period = ['id' => '1', 'kod' => '2025', 'platiOdData' => '2025-01-01', 'platiDoData' => '2025-12-31'];
        $entries = [
            ['idUcetniDenik' => '1', 'datUcto' => '2025-06-01', 'mdUcet' => 'code:311000', 'dalUcet' => 'code:602000', 'sumTuz' => '100.00', 'idDokl@evidencePath' => 'faktura-vydana/101'],
            ['idUcetniDenik' => '2', 'datUcto' => '2025-06-01', 'mdUcet' => 'code:311000', 'dalUcet' => 'code:343000', 'sumTuz' => '21.00', 'idDokl@evidencePath' => 'faktura-vydana/101'],
            ['idUcetniDenik' => '3', 'datUcto' => '2025-06-02', 'mdUcet' => 'code:221000', 'dalUcet' => 'code:311000', 'sumTuz' => '121.00', 'idDokl@evidencePath' => 'banka/301'],
        ];
        return [
            '_meta' => ['mode' => 'initial', 'company' => ['ico' => '88888888', 'base_currency' => 'CZK'], 'periods' => [$period]],
            'ucetni-obdobi' => [$period],
            'ucetni-osnova' => array_map(static fn ($code) => ['id' => $code, 'kod' => $code, 'nazev' => 'Synthetic account'], ['221', '311', '343', '602', '221000', '311000', '343000', '602000']),
            'ucetni-denik' => $entries,
            'pohyb-na-uctech' => array_map(static fn ($row) => $row + ['sumTuzMd' => $row['sumTuz'], 'sumTuzDal' => $row['sumTuz']], $entries),
            'faktura-vydana' => [[
                'id' => '101', 'kod' => 'SYN-2025-001', 'datVyst' => '2025-06-01', 'datUcto' => '2025-06-01', 'datSplat' => '2025-06-15',
                'mena' => 'code:CZK', 'sumZklCelkem' => '100.00', 'sumDphCelkem' => '21.00', 'sumCelkem' => '121.00', 'zuctovano' => true,
                'nazFirmy' => 'Synthetic partner', 'firma' => ['id' => '7', 'evidencePath' => 'adresar/7'], 'stat' => 'code:CZ',
                'polozkyDokladu' => [['id' => '201', 'popis' => 'Synthetic item', 'mnozMj' => '1', 'szbDph' => '21', 'sumZkl' => '100.00', 'sumDph' => '21.00', 'sumCelkem' => '121.00', 'clenDph' => 'code:01-02']],
            ]],
            'faktura-prijata' => [], 'pokladni-pohyb' => [],
            'banka' => [['id' => '301', 'kod' => 'SYN-B-001', 'datUcto' => '2025-06-02', 'mena' => 'code:CZK',
                'sumCelkem' => '121.00', 'typPohybuK' => 'typPohybu.prijem', 'banka' => ['id' => '1', 'evidencePath' => 'bankovni-ucet/1'],
                'bankaUcet' => '1000000005', 'bankaKod' => '0100']],
            'vazba' => [['id' => '401', 'a' => ['id' => '101', 'evidencePath' => 'faktura-vydana/101'],
                'b' => ['id' => '301', 'evidencePath' => 'banka/301'], 'castka' => '121.00', 'mena' => 'code:CZK']],
        ];
    }
}
