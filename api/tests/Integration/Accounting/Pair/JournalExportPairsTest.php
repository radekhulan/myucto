<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\Reports\JournalExportService;
use MyInvoice\Service\Accounting\Reports\JournalLineContext;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Pdf\JournalPdfRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Export deníku v jednoduchém režimu (kritérium e): jeden řádek na dvojici se sloupci
 * Účet MD, Účet Dal a Částka, hlavička zápisu zůstává; M : N označené; limit platí
 * na zápisy, ne na řádky.
 */
#[Group('integration')]
final class JournalExportPairsTest extends TestCase
{
    use PairReportFixture;

    private JournalExportService $export;

    protected function setUp(): void
    {
        $this->setUpFixture();
        $this->export = $this->container->get(JournalExportService::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
    }

    public function testExportHasOneRowPerPair(): void
    {
        $entries = $this->postScenario();
        $this->switchToSingle(self::YEAR . '-01-01');
        self::assertTrue($this->pairEntryInPlace($entries['bulk']));

        $data = $this->build();
        self::assertSame('pairs', $data['layout']);
        $byId = array_column($data['entries'], null, 'id');

        $bulk = $byId[$entries['bulk']];
        self::assertSame(JournalLineContext::PAIR_STATUS_PAIRED, $bulk['pair_status']);
        self::assertSame([
            ['321', '221', 1210.0], ['321', '221', 605.0], ['568', '221', 15.0],
        ], self::pairs($bulk));
        self::assertSame(1830.0, $bulk['amount'], 'Hlavička zápisu zůstává.');

        // Faktura se dvěma sazbami jako v Zadání: 4 dvojice, součet = částka dokladu.
        $sale = $byId[$entries['sale2']];
        self::assertSame(JournalLineContext::PAIR_STATUS_DERIVED, $sale['pair_status']);
        self::assertSame([
            ['311', '602', 10000.0], ['311', '343.200', 2100.0], ['311', '604', 1000.0], ['311', '343.200', 120.0],
        ], self::pairs($sale));
        self::assertSame(13220.0, array_sum(array_column($sale['pairs'], 'amount')));

        $storno = $byId[$entries['storno']];
        self::assertSame([['311', '602', -100.0], ['311', '343.200', -21.0]], self::pairs($storno), 'Červené storno: MD a Dal jako v deníku, částka záporně.');

        $mn = $byId[$entries['mn']];
        self::assertSame(JournalLineContext::PAIR_STATUS_COMPOSITE, $mn['pair_status']);
        self::assertSame([], $mn['pairs']);
        self::assertCount(4, $mn['lines'], 'M : N zůstává po řádcích.');

        // Součty deníku se tvarem exportu nemění.
        self::assertSame($data['totals']['debit'], $data['totals']['credit']);
    }

    public function testXlsxAndPdfHaveOneRowPerPairAndMarkManyToMany(): void
    {
        $entries = $this->postScenario();
        $this->switchToSingle(self::YEAR . '-01-01');
        $this->pairAllInPlace($entries);
        $data = $this->build();

        $rows = self::sheetRows($this->container->get(ReportXlsxExporter::class)->journal($data)['bytes']);
        $header = $rows[5];
        self::assertSame(['Datum', 'Doklad', 'Popis', 'Původ', 'Dvojice', 'Účet MD', 'Název MD', 'Účet Dal', 'Název Dal', 'Částka', 'Text', 'Středisko'], array_values($header));

        $expectedPairs = 0;
        foreach ($data['entries'] as $entry) {
            $expectedPairs += count($entry['pairs']);
        }
        $pairRows = array_filter($rows, static fn (array $r): bool => isset($r['E']) && $r['E'] !== '' && $r['E'] !== 'Dvojice');
        self::assertCount($expectedPairs, $pairRows, 'Jeden řádek XLSX na dvojici.');
        $bulkRows = array_values(array_filter($pairRows, static fn (array $r): bool => ($r['H'] ?? '') === '221' && in_array($r['F'] ?? '', ['321', '568'], true) && in_array((float) $r['J'], [1210.0, 605.0, 15.0], true)));
        self::assertCount(3, $bulkRows);
        $marked = array_filter($rows, static fn (array $r): bool => str_contains((string) ($r['C'] ?? ''), '[složený zápis M : N]'));
        self::assertCount(1, $marked, 'Zápis M : N je v exportu označený.');

        $renderer = $this->container->get(JournalPdfRenderer::class);
        $html = (string) (new \ReflectionMethod($renderer, 'renderTemplate'))->invoke($renderer, 'journal_pairs.twig', $data);
        self::assertStringContainsString('[složený zápis M : N]', $html);
        self::assertSame($expectedPairs, preg_match_all('/<td class="num">\d+<\/td>/', $html), 'Jeden řádek PDF na dvojici.');
        self::assertStringStartsWith('%PDF', $renderer->render($data));
    }

    public function testLimitCountsEntriesNotPairRows(): void
    {
        $entries = $this->postScenario();
        $this->switchToSingle(self::YEAR . '-01-01');
        $this->pairAllInPlace($entries);

        $data = $this->build(count($entries));
        $pairRows = array_sum(array_map(static fn (array $e): int => max(count($e['pairs']), count($e['lines'])), $data['entries']));
        self::assertGreaterThan(count($entries), $pairRows, 'Dvojic je víc než zápisů, limit přesto projde.');

        $this->expectException(ReportException::class);
        $this->build(count($entries) - 1);
    }

    /** @return array<string,mixed> */
    private function build(int $maxRows = JournalExportService::MAX_ROWS): array
    {
        $this->entryForms->forget();
        return $this->export->build($this->supplierId, ['date_from' => self::YEAR . '-01-01', 'date_to' => self::YEAR . '-12-31'], $maxRows);
    }

    /**
     * @param array<string,mixed> $entry
     * @return list<array{string,string,float}>
     */
    private static function pairs(array $entry): array
    {
        return array_map(static fn (array $p): array => [$p['debit_account_code'], $p['credit_account_code'], $p['amount']], $entry['pairs']);
    }

    /** @return array<int,array<string,string>> číslo řádku => sloupec => hodnota */
    private static function sheetRows(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pairxlsx_');
        file_put_contents($tmp, $bytes);
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);
        $out = [];
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->getValue();
                if ($value !== null && $value !== '') {
                    $out[$row->getRowIndex()][$cell->getColumn()] = (string) $value;
                }
            }
        }
        return $out;
    }
}
