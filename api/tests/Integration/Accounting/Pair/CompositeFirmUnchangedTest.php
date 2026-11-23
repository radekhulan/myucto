<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\Reports\AccountStatementService;
use MyInvoice\Service\Accounting\Reports\CashFlowStatementService;
use MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService;
use MyInvoice\Service\Accounting\Reports\GeneralLedgerService;
use MyInvoice\Service\Accounting\Reports\JournalExportService;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Pdf\AccountStatementPdfRenderer;
use MyInvoice\Service\Pdf\JournalPdfRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Firma, která jednoduchý zápis nezapne, nepozná žádnou změnu (kritérium h).
 *
 * Zlaté soubory v tests/Fixtures/pair/composite vznikly z kódu PŘED bodem 6 nad
 * syntetickou sadou {@see PairReportFixture::postScenario()}: JSON opisu účtu (i s filtrem
 * protiúčtu), hlavní knihy, rozpisu hlavní knihy, cash flow a exportu deníku, list XLSX
 * exportu deníku a opisu a HTML šablon PDF. Výstupy se porovnávají bajtově po náhradě
 * proměnlivých identifikátorů (id záznamů, časová razítka) stálými značkami.
 *
 * Rozpočet SQL dotazů: každá sestava smí oproti stavu před bodem 6 přidat nejvýš jeden
 * dotaz (EntryFormService::hasAnySingle(), paměť na požadavek). Měří se bez paměti.
 *
 * Přegenerování (jen nad kódem před bodem 6!): UPDATE_PAIR_GOLDEN=1.
 */
#[Group('integration')]
final class CompositeFirmUnchangedTest extends TestCase
{
    use PairReportFixture;

    private const GOLDEN_DIR = __DIR__ . '/../../../Fixtures/pair/composite';
    private const EXTRA_QUERIES = 1;

    /** @var array<int,string> */
    private array $entryTokens = [];
    /** @var array<int,string> */
    private array $lineTokens = [];
    /** @var array<int,string> */
    private array $accountTokens = [];

    protected function setUp(): void
    {
        $this->setUpFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
    }

    public function testReportsAreByteIdenticalForFirmWithoutSingleForm(): void
    {
        $entries = $this->postScenario();
        $this->buildTokens($entries);
        $from = self::YEAR . '-01-01';
        $to = self::YEAR . '-12-31';

        $statement = $this->container->get(AccountStatementService::class);
        $ledger = $this->container->get(GeneralLedgerService::class);
        $detail = $this->container->get(GeneralLedgerDetailService::class);
        $cashFlow = $this->container->get(CashFlowStatementService::class);
        $export = $this->container->get(JournalExportService::class);
        $xlsx = $this->container->get(ReportXlsxExporter::class);

        $queries = [];
        $outputs = [];

        $statements = [
            'statement_221'            => ['221', null],
            'statement_221_filter_568' => ['221', '568'],
            'statement_311'            => ['311', null],
            'statement_311_filter_602' => ['311', '602'],
            'statement_321'            => ['321', null],
            'statement_211'            => ['211', null],
            'statement_343'            => ['343', null],
            'statement_602_filter_311' => ['602', '311'],
        ];
        foreach ($statements as $name => [$code, $counter]) {
            $data = $this->measure($queries, $name, fn (): array => $statement->build(
                $this->supplierId,
                $this->accountId($code),
                $from,
                $to,
                1,
                50,
                false,
                $counter === null ? null : $this->accountId($counter),
            ));
            $outputs[$name . '.json'] = $this->json($data);
        }

        $page2 = $statement->build($this->supplierId, $this->accountId('221'), $from, $to, 2, 3);
        $outputs['statement_221_page2.json'] = $this->json($page2);

        $full = $statement->build($this->supplierId, $this->accountId('221'), $from, $to, 1, 100000);
        $full['from'] = $from;
        $full['to'] = $to;
        $outputs['statement_221.xlsx.xml'] = self::xlsxSheet($xlsx->accountStatement($full)['bytes']);
        $outputs['statement_221.html'] = self::html($this->renderTemplate(AccountStatementPdfRenderer::class, 'account_statement.twig', $full));

        $gl = $this->measure($queries, 'general_ledger', fn (): array => $ledger->build($this->supplierId, $this->periodId, null, null));
        $outputs['general_ledger.json'] = $this->json($gl);

        $glDetail = $ledger->build($this->supplierId, $this->periodId, null, null, false, [], false, true);
        $events = $this->measure($queries, 'general_ledger_detail', fn (): array => iterator_to_array(
            $detail->events($this->supplierId, $glDetail, [], false),
            false,
        ));
        $outputs['general_ledger_detail.json'] = $this->json($events);

        $cash = $this->measure($queries, 'cash_flow', fn (): array => $cashFlow->build($this->supplierId, $this->periodId));
        $outputs['cash_flow.json'] = $this->json($cash);

        $journal = $this->measure($queries, 'journal_export', fn (): array => $export->build($this->supplierId, ['date_from' => $from, 'date_to' => $to]));
        $outputs['journal_export.json'] = $this->json($journal);
        $outputs['journal_export.xlsx.xml'] = self::xlsxSheet($xlsx->journal($journal)['bytes']);
        $outputs['journal_export.html'] = self::html($this->renderTemplate(JournalPdfRenderer::class, 'journal.twig', $journal));

        if (getenv('UPDATE_PAIR_GOLDEN') === '1') {
            if (!is_dir(self::GOLDEN_DIR)) {
                mkdir(self::GOLDEN_DIR, 0775, true);
            }
            foreach ($outputs as $file => $content) {
                file_put_contents(self::GOLDEN_DIR . '/' . $file, $content);
            }
            file_put_contents(self::GOLDEN_DIR . '/queries.json', json_encode($queries, JSON_PRETTY_PRINT) . "\n");
            $this->markTestSkipped('Zlaté soubory přegenerovány.');
        }

        foreach ($outputs as $file => $content) {
            self::assertFileExists(self::GOLDEN_DIR . '/' . $file);
            self::assertSame(file_get_contents(self::GOLDEN_DIR . '/' . $file), $content, 'Výstup ' . $file . ' firmy bez jednoduchého zápisu se změnil.');
        }

        $budget = json_decode((string) file_get_contents(self::GOLDEN_DIR . '/queries.json'), true, 8, JSON_THROW_ON_ERROR);
        foreach ($budget as $name => $count) {
            self::assertArrayHasKey($name, $queries);
            self::assertLessThanOrEqual($count + self::EXTRA_QUERIES, $queries[$name], 'Rozpočet SQL dotazů sestavy ' . $name . ' (stav před bodem 6: ' . $count . ').');
        }
    }

    /**
     * @template T
     * @param array<string,int> $queries
     * @param callable():T $fn
     * @return T
     */
    private function measure(array &$queries, string $name, callable $fn): mixed
    {
        $this->entryForms->forget();
        $before = $this->questions();
        $result = $fn();
        // SHOW STATUS se do Questions počítá taky; stejně před i po, takže se odečte jeden.
        $queries[$name] = $this->questions() - $before - 1;
        return $result;
    }

    /** @param array<string,int> $entries */
    private function buildTokens(array $entries): void
    {
        $n = 0;
        foreach ($entries as $key => $id) {
            $this->entryTokens[$id] = 'entry:' . $key;
        }
        $stmt = $this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id = ? ORDER BY id');
        $stmt->execute([$this->supplierId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->lineTokens[(int) $id] = 'line:' . (++$n);
        }
        foreach ($this->accountIds as $code => $id) {
            $this->accountTokens[$id] = 'account:' . $code;
        }
    }

    private function json(mixed $data): string
    {
        return json_encode($this->normalize($data, null, null), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    }

    private function normalize(mixed $value, int|string|null $key, int|string|null $parent): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                // Položka seznamu dědí rodiče seznamu (entries[3].id je id zápisu).
                $out[$k] = $this->normalize($v, $k, is_int($key) ? $parent : $key);
            }
            return $out;
        }
        if (in_array($key, ['posted_at', 'prepared_at', 'created_at', 'updated_at'], true) && $value !== null) {
            return '<timestamp>';
        }
        if (!is_int($value)) {
            return $value;
        }
        $map = match (true) {
            $key === 'entry_id', $key === 'reversed_by' => $this->entryTokens,
            $key === 'line_id' => $this->lineTokens,
            in_array($key, ['account_id', 'line_account_id', 'counter_account_id', 'parent_id', 'group_id'], true) => $this->accountTokens,
            $key === 'id' && $parent === 'account' => $this->accountTokens,
            $key === 'id' && $parent === 'period' => [$this->periodId => 'period'],
            $key === 'id' && $parent === 'entries' => $this->entryTokens,
            default => [],
        };
        return $map[$value] ?? $value;
    }

    /** @param class-string $class */
    private function renderTemplate(string $class, string $template, array $data): string
    {
        $renderer = $this->container->get($class);
        return (string) (new \ReflectionMethod($renderer, 'renderTemplate'))->invoke($renderer, $template, $data);
    }

    private static function html(string $html): string
    {
        return (string) preg_replace('/Ze dne: \d{2}\.\d{2}\.\d{4}/', 'Ze dne: <date>', $html);
    }

    /** List a sdílené řetězce XLSX bez metadat souboru (časy vytvoření). */
    private static function xlsxSheet(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pairgold_');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp) === true);
        $out = (string) $zip->getFromName('xl/worksheets/sheet1.xml') . "\n" . (string) $zip->getFromName('xl/sharedStrings.xml') . "\n";
        $zip->close();
        @unlink($tmp);
        return $out;
    }
}
