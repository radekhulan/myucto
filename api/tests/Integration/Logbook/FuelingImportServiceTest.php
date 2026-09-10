<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Service\Logbook\FuelingImportService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hromadný import tankování: idempotence (opakovaný import nic nezdvojí), náhled bez zápisu,
 * přiřazení vozidla podle SPZ a vazba na firmu.
 */
#[Group('integration')]
final class FuelingImportServiceTest extends TestCase
{
    use LogbookFixtures;

    private const CSV = "datum;cas;spz;palivo;litry;cena za litr;celkem;tachometr;stanice;cislo uctenky\n"
        . "05.03.2099;07:15;1ab-2345;Nafta;40,5;38,90;1575,45;120500;Testovací stanice;R-1\n"
        . "06.03.2099;;1AB2345;Nafta;30;;1170,00;;Testovací stanice;R-2\n";

    private FuelingImportService $importer;
    private int $carA = 0;

    protected function setUp(): void
    {
        $this->bootLogbook();
        $this->importer = $this->container->get(FuelingImportService::class);
        $this->carA = $this->car($this->supplierA, '1AB 2345');
        $this->car($this->supplierB, '9ZZ 9999');
    }

    protected function tearDown(): void
    {
        $this->shutdownLogbook();
    }

    public function testRepeatedImportDoesNotDuplicate(): void
    {
        $first = $this->importer->import($this->supplierA, $this->userId ?: null, self::CSV, 'tankovani.csv');
        self::assertTrue($first['ok']);
        self::assertSame(2, $first['created']);
        self::assertSame(0, $first['failed']);

        $second = $this->importer->import($this->supplierA, $this->userId ?: null, self::CSV, 'tankovani.csv');
        self::assertSame(0, $second['created']);
        self::assertSame(2, $second['duplicates']);
        self::assertSame(2, $this->fuelingCount($this->supplierA), 'Opakovaný import nesmí přidat řádky.');
    }

    public function testReimportFillsMissingValuesWithoutDuplicating(): void
    {
        $this->importer->import($this->supplierA, null, self::CSV, 'tankovani.csv');
        $withOdometer = str_replace(";1170,00;;", ";1170,00;121000;", self::CSV);

        $r = $this->importer->import($this->supplierA, null, $withOdometer, 'tankovani.csv');

        self::assertSame(0, $r['created']);
        self::assertSame(1, $r['updated']);
        self::assertSame(2, $this->fuelingCount($this->supplierA));
        $odo = $this->pdo->prepare("SELECT odometer FROM fuelings WHERE supplier_id = ? AND receipt_number = 'R-2'");
        $odo->execute([$this->supplierA]);
        self::assertSame(121000, (int) $odo->fetchColumn());
    }

    public function testDryRunWritesNothingAndPredictsDuplicates(): void
    {
        $preview = $this->importer->import($this->supplierA, null, self::CSV, 'tankovani.csv', true);
        self::assertTrue($preview['dry_run']);
        self::assertSame(['preview', 'preview'], array_column($preview['rows'], 'status'));
        self::assertSame(0, $this->fuelingCount($this->supplierA), 'Náhled nesmí nic zapsat.');

        $this->importer->import($this->supplierA, null, self::CSV, 'tankovani.csv');
        $again = $this->importer->import($this->supplierA, null, self::CSV, 'tankovani.csv', true);
        self::assertSame(['duplicate', 'duplicate'], array_column($again['rows'], 'status'));
    }

    public function testDuplicateRowInsideOneFileIsImportedOnce(): void
    {
        $csv = self::CSV . "05.03.2099;07:15;1AB 2345;Nafta;40,5;38,90;1575,45;120500;Testovací stanice;R-1\n";

        $r = $this->importer->import($this->supplierA, null, $csv, 'tankovani.csv');

        self::assertSame(2, $r['created']);
        self::assertSame(1, $r['duplicates']);
    }

    public function testVehicleIsMatchedByNormalizedPlate(): void
    {
        $this->importer->import($this->supplierA, null, self::CSV, 'tankovani.csv');

        $stmt = $this->pdo->prepare('SELECT DISTINCT car_id, source FROM fuelings WHERE supplier_id = ?');
        $stmt->execute([$this->supplierA]);
        self::assertSame([['car_id' => $this->carA, 'source' => 'import']], array_map(
            static fn (array $r): array => ['car_id' => (int) $r['car_id'], 'source' => (string) $r['source']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    public function testPlateOfAnotherSupplierIsNotMatched(): void
    {
        $csv = "datum;spz;celkem\n05.03.2099;9ZZ 9999;800\n";

        $r = $this->importer->import($this->supplierA, null, $csv, 'tankovani.csv');

        self::assertSame(1, $r['failed']);
        self::assertStringContainsString('9ZZ 9999', (string) $r['rows'][0]['reason']);
        self::assertSame(0, $this->fuelingCount($this->supplierA));
        self::assertSame(0, $this->fuelingCount($this->supplierB));
    }

    public function testMissingAmountColumnIsRejected(): void
    {
        $r = $this->importer->import($this->supplierA, null, "datum;spz\n05.03.2099;1AB 2345\n", 'tankovani.csv');

        self::assertFalse($r['ok']);
    }
}
