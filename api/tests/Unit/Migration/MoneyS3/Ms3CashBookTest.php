<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Repository\MovementClassificationRepository;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Migration\MoneyS3\Ms3CashBook;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticTaxAgenda;
use PHPUnit\Framework\TestCase;

final class Ms3CashBookTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms3cash_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
    }

    /** @param array<string,string> $files */
    private function book(array $files, string $dir): Ms3CashBook
    {
        $name = 'a' . bin2hex(random_bytes(3));
        SyntheticAgenda::writeLzFiles("{$this->tmp}/{$name}.lz", $files);
        $backup = Ms3Backup::extract("{$this->tmp}/{$name}.lz", "{$this->tmp}/{$name}");
        return Ms3CashBook::read($backup, $backup->dir() . '/' . $dir);
    }

    /**
     * Řádky peněžního deníku se seskupí po dokladech (zdroj + číslo dokladu), částky nesou
     * znaménko peněžního toku a každá část svou vazbu na uhrazený doklad. Smazaný řádek
     * v deníku není.
     */
    public function testGroupsRowsByDocumentWithSignsBucketsAndLinks(): void
    {
        $book = $this->book(SyntheticTaxAgenda::files(), 'ROK.002');

        self::assertSame('ROK.002', $book->dir);
        self::assertSame(['PK|2', 'BK|1', 'PK|1', 'PK|3', 'BK|2', 'BK|3', 'BK|4'], array_keys($book->movements));
        $income = $book->movements['BK|1'];
        self::assertSame(12100.0, $income['amount']);
        self::assertSame('BP24001', $income['document_no']);
        self::assertSame('2024-08-20', $income['date']);
        self::assertSame(['income_taxable', Ms3CashBook::VAT], array_column($income['parts'], 'bucket'));
        self::assertSame(['table' => 'FV', 'dir' => 'ROK.002', 'number' => 1], $income['parts'][0]['link']);
        self::assertSame(-1210.0, $book->movements['PK|1']['amount']);
        // Vratka k dobropisu: záporný výdaj v deníku = příjem do pokladny.
        self::assertSame(121.0, $book->movements['PK|3']['amount']);
        self::assertSame([100.0, 21.0], array_column($book->movements['PK|3']['parts'], 'amount'));
        self::assertSame('expense_nontax', $book->movements['BK|2']['parts'][0]['bucket']);
        self::assertSame('private', $book->movements['BK|3']['parts'][0]['bucket']);
        self::assertNull($book->movements['BK|3']['parts'][0]['link']);
        self::assertArrayNotHasKey('BK|999', $book->movements, 'smazaný řádek');

        self::assertSame(['kind' => 'U', 'opening' => 50000.0, 'currency' => ''], $book->accounts['BAN']);
        self::assertSame('P', $book->accounts['HOT']['kind']);
        self::assertSame([
            'expense_nontax' => -3000.0, 'expense_taxable' => -900.0, 'income_nontax' => 15.0,
            'income_taxable' => 10000.0, 'private' => -6000.0, Ms3CashBook::VAT => 1911.0,
        ], $book->totals());
    }

    /**
     * Doklad se zařadí podle předkontace daňové evidence: předkontace → pohyb základu →
     * sloupec. Neznámá předkontace zařazení nemá.
     */
    public function testDocumentBucketFollowsTaxRecordsPostingRule(): void
    {
        $book = $this->book(SyntheticTaxAgenda::files(), 'ROK.001');
        self::assertSame('expense_taxable', $book->ruleBucket('V106'));
        self::assertSame('expense_nontax', $book->ruleBucket('V142'));
        self::assertSame('income_taxable', $book->ruleBucket('P504'));
        self::assertSame('private', $book->ruleBucket('V160'));
        self::assertSame(Ms3CashBook::VAT, $book->ruleBucket('VDPH'));
        self::assertNull($book->ruleBucket('XX999'));
    }

    /**
     * Vazba do jiného roku (`URok`) vede do adresáře toho roku; sloupec, který převod nezná,
     * zařazení nemá a neodhaduje se.
     */
    public function testCrossYearLinkAndUnknownColumn(): void
    {
        $files = SyntheticTaxAgenda::with(SyntheticTaxAgenda::files(), 'ROK.001/UcPenDen.DAT', SyntheticTaxAgenda::CASHBOOK_FIELDS,
            static fn (array $rows): array => [...$rows,
                ['Cislo' => 50, 'Datum' => '2025-01-10', 'Zdroj' => 'BK', 'ZRadek' => 7, 'Doklad' => 'BP25007', 'Typ' => 'P', 'Pohyb' => 'PP_504',
                    'Castka' => 100.0, 'UZdroj' => 'FV', 'URok' => '002', 'URadek' => 1],
                ['Cislo' => 51, 'Datum' => '2025-01-11', 'Zdroj' => 'BK', 'ZRadek' => 8, 'Doklad' => 'BV25008', 'Typ' => 'V', 'Pohyb' => 'VP_XXX', 'Castka' => 5.0],
            ]);
        $files = SyntheticTaxAgenda::with($files, 'ROK.001/UcPohyby.DAT', SyntheticTaxAgenda::MOVEMENT_FIELDS,
            static fn (array $rows): array => [...$rows, ['Zkrat' => 'VP_XXX', 'Popis' => 'neznámý sloupec', 'Typ' => 'V', 'Sloupec' => 'VZZZ']]);

        $book = $this->book($files, 'ROK.001');
        self::assertSame(['table' => 'FV', 'dir' => 'ROK.002', 'number' => 1], $book->movements['BK|7']['parts'][0]['link']);
        self::assertSame('VZZZ', $book->movements['BK|8']['parts'][0]['column']);
        self::assertNull($book->movements['BK|8']['parts'][0]['bucket']);
    }

    /** Mapa sloupců zařazuje jen do zařazení peněžního deníku MyÚčta (a DPH). */
    public function testColumnBucketsAreKnownTaxBuckets(): void
    {
        foreach (Ms3CashBook::COLUMN_BUCKETS as $column => $bucket) {
            self::assertContains($bucket, [...MovementClassificationRepository::TAX_BUCKETS, Ms3CashBook::VAT], $column);
        }
    }
}
