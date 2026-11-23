<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Stock\StockReportAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Pdf\StockValuationPdfRenderer;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockReportService;
use MyInvoice\Service\Stock\StockReportXlsxExporter;
use MyInvoice\Service\Stock\StockValuation;
use MyInvoice\Service\Stock\StockValuationCategoryGroups;
use MyInvoice\Service\Stock\StockValuationJobService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Ocenění k datu seskupené podle kategorií: skupiny jsou přímé podkategorie
 * zvolené kategorie (nebo kategorie nejvyšší úrovně), „přímo v kategorii“
 * a „ostatní“. Karta ve více kategoriích se počítá jen jednou (primární, jinak
 * první v pořadí karty) a součet skupin se rovná celkovému ocenění.
 *
 * Úloha ocenění omezená na kategorii (`category_id`) bere jen karty ve stromu
 * kategorie včetně podkategorií; řádky, součty, seskupení i export jsou z nich.
 */
#[Group('integration')]
final class StockValuationCategoryGroupsTest extends StockTestCase
{
    use \MyInvoice\Tests\Support\StockCategoryFixtureTrait;

    /** @return array{sid:int, wh:int, root:int, c1:int, c2:int, c2a:int, other:int, empty:int} */
    private function fixture(): array
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $wh2 = $this->warehouse($sid, 'DRUHY', false);
        $root = $this->category($sid, 'VAL-ROOT', null, 1);
        $other = $this->category($sid, 'VAL-OTHER', null, 2);
        $c2 = $this->category($sid, 'VAL-C2', $root, 1);
        $c1 = $this->category($sid, 'VAL-C1', $root, 0);
        $c2a = $this->category($sid, 'VAL-C2A', $c2);
        $empty = $this->category($sid, 'VAL-EMPTY', $root, 2);
        $this->category($sid, 'VAL-ARCH', $root, 3, true); // archivovaná a prázdná: ve skupinách chybí

        $cards = [
            // sku => [kategorie [id, primární, pořadí], sklad => hodnota]
            'VAL-1' => [[[$c1, true, 0]], [$wh => 100.0]],
            'VAL-2' => [[[$c2a, true, 0]], [$wh => 200.0, $wh2 => 50.0]],
            'VAL-3' => [[[$root, true, 0]], [$wh => 30.0]],
            'VAL-4' => [[[$c1, false, 0], [$c2, true, 1]], [$wh => 7.0]],
            'VAL-5' => [[[$c1, false, 1], [$c2a, false, 0]], [$wh => 11.0]],
            'VAL-6' => [[[$other, true, 0]], [$wh => 13.0]],
            'VAL-7' => [[], [$wh => 17.0]],
            'VAL-8' => [[[$other, true, 0], [$c1, false, 1]], [$wh => 19.0]],
        ];
        foreach ($cards as $sku => [$categories, $values]) {
            $item = $this->item($sid, $sku);
            foreach ($categories as [$category, $primary, $order]) {
                $this->assignCategory($sid, $item, $category, $primary, $order);
            }
            foreach ($values as $warehouse => $value) {
                $this->receiveStock($sid, $warehouse, $item, '1', $value, '2099-01-05');
            }
        }
        return ['sid' => $sid, 'wh' => $wh, 'root' => $root, 'c1' => $c1, 'c2' => $c2, 'c2a' => $c2a, 'other' => $other, 'empty' => $empty];
    }

    /** @param array<string,mixed> $grouping @return array<string,array{0:int,1:string}> */
    private static function byLabel(array $grouping): array
    {
        $out = [];
        foreach ($grouping['groups'] as $g) {
            $out[$g['kind'] . ':' . ($g['category_id'] ?? '-')] = [$g['count'], $g['value_total']];
        }
        return $out;
    }

    private function completedJob(int $sid): int
    {
        $jobs = $this->container->get(StockValuationJobService::class);
        $job = $jobs->enqueue($sid, '2099-01-31');
        self::assertSame('completed', $jobs->tick($sid)['status']);
        return (int) $job['id'];
    }

    public function testGroupsByDirectSubcategoriesAndSumEqualsTotal(): void
    {
        $f = $this->fixture();
        $jobs = $this->container->get(StockValuationJobService::class);
        $jobId = $this->completedJob($f['sid']);

        $grouping = $jobs->categoryGroups($f['sid'], $jobId, $f['root']);
        $total = $jobs->result($f['sid'], $jobId)['totals']['value_total'];

        self::assertSame(['id' => $f['root'], 'code' => 'VAL-ROOT', 'name' => 'Kategorie VAL-ROOT'], $grouping['parent']);
        self::assertSame([
            'category:' . $f['c1'] => [2, '119.00'],          // VAL-1 + VAL-8 (primární mimo strom, ve stromu jen C1)
            'category:' . $f['c2'] => [4, '268.00'],          // VAL-2 na dvou skladech + VAL-4 (primární C2) + VAL-5 (C2A první v pořadí karty)
            'category:' . $f['empty'] => [0, '0.00'],         // prázdná podkategorie zůstává, archivovaná prázdná ne
            'parent:' . $f['root'] => [1, '30.00'],           // VAL-3 přímo v kořeni
            'uncategorized:-' => [2, '30.00'],                // VAL-6 mimo kořen, VAL-7 bez kategorie
        ], self::byLabel($grouping));
        self::assertSame($total, $grouping['value_total']);
        self::assertSame('447.00', $total);
    }

    public function testCardInOtherCategoryIsCountedInsideTheChosenTree(): void
    {
        $f = $this->fixture();
        $jobs = $this->container->get(StockValuationJobService::class);
        $grouping = $jobs->categoryGroups($f['sid'], $this->completedJob($f['sid']), $f['root']);
        // VAL-8: primární kategorie je mimo zvolený strom, ale kartu má i v C1 → patří do C1.
        $c1 = array_values(array_filter($grouping['groups'], static fn (array $g): bool => $g['category_id'] === $f['c1']))[0];
        self::assertSame(2, $c1['count']);
        self::assertSame('119.00', $c1['value_total']);
    }

    public function testTopLevelGrouping(): void
    {
        $f = $this->fixture();
        $jobs = $this->container->get(StockValuationJobService::class);
        $grouping = $jobs->categoryGroups($f['sid'], $this->completedJob($f['sid']), null);

        self::assertNull($grouping['parent']);
        self::assertSame([
            'category:' . $f['root'] => [6, '398.00'],
            'category:' . $f['other'] => [2, '32.00'],
            'uncategorized:-' => [1, '17.00'],
        ], self::byLabel($grouping));
        self::assertSame('447.00', $grouping['value_total']);
    }

    public function testSynchronousValuationUsesTheSameRules(): void
    {
        $f = $this->fixture();
        $report = $this->container->get(StockReportService::class)->valuation($f['sid'], '2099-01-31', []);
        $grouping = $this->container->get(StockValuationCategoryGroups::class)->summarize($f['sid'], $f['root'], $report['items']);
        $jobs = $this->container->get(StockValuationJobService::class);
        self::assertSame(
            self::byLabel($jobs->categoryGroups($f['sid'], $this->completedJob($f['sid']), $f['root'])),
            self::byLabel($grouping),
        );
        self::assertSame($report['totals']['value_total'], $grouping['value_total']);
    }

    public function testRejectsForeignOrInvalidParent(): void
    {
        $f = $this->fixture();
        $foreign = $this->createSupplier();
        $foreignCategory = $this->category($foreign, 'VAL-FOREIGN');
        $jobs = $this->container->get(StockValuationJobService::class);
        $jobId = $this->completedJob($f['sid']);
        try {
            $jobs->categoryGroups($f['sid'], $jobId, $foreignCategory);
            self::fail('Kategorie jiné firmy musí být odmítnuta.');
        } catch (StockException $e) {
            self::assertSame(422, $e->httpStatus);
        }
        self::assertNull(StockValuationCategoryGroups::normalize([]));
        self::assertSame(['parent_id' => null], StockValuationCategoryGroups::normalize(['group_by' => 'category']));
        self::assertSame(['parent_id' => 5], StockValuationCategoryGroups::normalize(['group_by' => 'category', 'group_parent_id' => '5']));
        foreach ([['group_by' => 'warehouse'], ['group_by' => 'category', 'group_parent_id' => 'x']] as $input) {
            try {
                StockValuationCategoryGroups::normalize($input);
                self::fail('Očekávána chyba validace pro ' . json_encode($input));
            } catch (StockException $e) {
                self::assertSame(422, $e->httpStatus);
            }
        }
    }

    public function testExportsContainCategorySummary(): void
    {
        $f = $this->fixture();
        $jobs = $this->container->get(StockValuationJobService::class);
        $jobId = $this->completedJob($f['sid']);
        $data = $jobs->result($f['sid'], $jobId, 1, 500);
        $plain = $this->container->get(StockReportXlsxExporter::class)->valuation($data);
        self::assertSame(['Ocenění zásob'], $this->sheetNames($plain['bytes']));

        $data['category_groups'] = $jobs->categoryGroups($f['sid'], $jobId, $f['root']);
        $xlsx = $this->container->get(StockReportXlsxExporter::class)->valuation($data);
        self::assertSame(['Ocenění zásob', 'Podle kategorií'], $this->sheetNames($xlsx['bytes']));
        $rows = $this->sheetRows($xlsx['bytes'], 'Podle kategorií');
        self::assertSame(['Kategorie', 'Položek', 'Hodnota'], $rows[3]);
        self::assertSame(['Kategorie VAL-C1', 2, 119.0], $rows[4]);
        $totalRow = array_values(array_filter($rows, static fn (array $r): bool => ($r[0] ?? '') === 'CELKEM'));
        self::assertSame([['CELKEM', 9, 447.0]], $totalRow);

        $pdf = $this->container->get(StockValuationPdfRenderer::class)->render($data);
        self::assertStringStartsWith('%PDF', $pdf);
    }

    public function testApiAddsGroupsOnlyOnRequest(): void
    {
        $f = $this->fixture();
        $jobId = $this->completedJob($f['sid']);
        $action = $this->container->get(StockReportAction::class);

        $plain = $this->json($action->valuationJobResult($this->request($f['sid']), new Response(), ['id' => $jobId]));
        self::assertSame(['job_id', 'date', 'items', 'source_versions', 'totals', 'pagination'], array_keys($plain));

        $query = ['group_by' => 'category', 'group_parent_id' => (string) $f['root'], 'limit' => '2'];
        $grouped = $this->json($action->valuationJobResult($this->request($f['sid'], $query), new Response(), ['id' => $jobId]));
        self::assertCount(2, $grouped['items'], 'Stránkování řádků zůstává.');
        self::assertSame($grouped['totals']['value_total'], $grouped['category_groups']['value_total'], 'Souhrn je za celou úlohu, ne za stránku.');

        $sync = $this->json($action->valuation($this->request($f['sid'], ['date' => '2099-01-31', 'group_by' => 'category']), new Response()));
        self::assertSame($sync['totals']['value_total'], $sync['category_groups']['value_total']);
        self::assertArrayNotHasKey('category_groups', $this->json($action->valuation($this->request($f['sid'], ['date' => '2099-01-31']), new Response())));

        $bad = $action->valuationJobResult($this->request($f['sid'], ['group_by' => 'category', 'group_parent_id' => 'x']), new Response(), ['id' => $jobId]);
        self::assertSame(422, $bad->getStatusCode());

        $export = $action->export($this->request($f['sid'], ['format' => 'xlsx', 'job_id' => (string) $jobId, 'group_by' => 'category']), new Response(), ['name' => 'valuation']);
        self::assertSame(200, $export->getStatusCode());
        self::assertSame(['Ocenění zásob', 'Podle kategorií'], $this->sheetNames((string) $export->getBody()));
    }

    public function testCategoryFilterKeepsOnlyCardsInTheSubtree(): void
    {
        $f = $this->fixture();
        $result = $this->valuate($f['sid'], '2099-01-31', ['category_id' => $f['c2']]);

        // VAL-2 (C2A) na dvou skladech, VAL-4 (i v C2) a VAL-5 (i v C2A). VAL-1 je jen v C1, ostatní mimo strom.
        self::assertSame(['VAL-2', 'VAL-2', 'VAL-4', 'VAL-5'], array_column($result['items'], 'sku'));
        self::assertSame(['count' => 4, 'value_total' => '268.00'], $result['totals']);
        $sumC = 0;
        foreach ($result['items'] as $row) {
            $sumC += StockValuation::valueToC((string) $row['value_total']);
        }
        self::assertSame('268.00', StockValuation::cToDecimal($sumC), 'Součet řádků sedí s celkem.');
        self::assertSame(['id' => $f['c2'], 'code' => 'VAL-C2', 'name' => 'Kategorie VAL-C2'], $result['category']);

        $jobs = $this->container->get(StockValuationJobService::class);
        $grouping = $jobs->categoryGroups($f['sid'], $result['job_id'], $f['c2']);
        self::assertSame('268.00', $grouping['value_total'], 'Seskupení počítá jen s řádky kategorie.');
    }

    public function testCategoryFilterFollowsTheValuationDate(): void
    {
        $f = $this->fixture();
        $late = $this->item($f['sid'], 'VAL-LATE');
        $this->assignCategory($f['sid'], $late, $f['c2a'], true);
        $this->receiveStock($f['sid'], $f['wh'], $late, '2', 40.0, '2099-02-10');
        $outside = $this->item($f['sid'], 'VAL-OUT');
        $this->receiveStock($f['sid'], $f['wh'], $outside, '1', 500.0, '2099-02-10');

        $before = $this->valuate($f['sid'], '2099-01-04', ['category_id' => $f['c2']]);
        self::assertSame([], $before['items']);
        self::assertSame(0, $before['totals']['count']);

        $january = $this->valuate($f['sid'], '2099-01-31', ['category_id' => $f['c2']]);
        self::assertSame(['count' => 4, 'value_total' => '268.00'], $january['totals']);

        $february = $this->valuate($f['sid'], '2099-02-28', ['category_id' => $f['c2']]);
        self::assertSame(['VAL-2', 'VAL-2', 'VAL-4', 'VAL-5', 'VAL-LATE'], array_column($february['items'], 'sku'));
        self::assertSame(['count' => 5, 'value_total' => '348.00'], $february['totals']);
    }

    public function testJobWithoutCategoryIsUnchangedAndNotSharedWithFilteredJob(): void
    {
        $f = $this->fixture();
        $all = $this->valuate($f['sid'], '2099-01-31');
        $c1 = $this->valuate($f['sid'], '2099-01-31', ['category_id' => $f['c1']]);

        self::assertSame(['job_id', 'date', 'items', 'source_versions', 'totals', 'pagination'], array_keys($all));
        self::assertSame(['count' => 9, 'value_total' => '447.00'], $all['totals']);
        self::assertNotSame($all['job_id'], $c1['job_id'], 'Omezená úloha nesmí vrátit hotové ocenění celého skladu.');
        // C1: VAL-1, VAL-4, VAL-5, VAL-8 (karty i v jiných kategoriích se počítají, když jsou i v C1).
        self::assertSame(['count' => 4, 'value_total' => '137.00'], $c1['totals']);
        self::assertSame($all['job_id'], $this->valuate($f['sid'], '2099-01-31')['job_id'], 'Úloha bez kategorie se dál použije znovu.');
        self::assertSame($c1['job_id'], $this->valuate($f['sid'], '2099-01-31', ['category_id' => $f['c1']])['job_id']);
    }

    public function testRecategorisedCardStartsANewCategoryJob(): void
    {
        $f = $this->fixture();
        $before = $this->valuate($f['sid'], '2099-01-31', ['category_id' => $f['c2']]);
        // Přeřazení karty verze skladu nezmění; hotové ocenění se starým seznamem karet se nesmí vrátit.
        $val7 = (int) $this->db->pdo()->query("SELECT id FROM stock_items WHERE sku = 'VAL-7' AND supplier_id = " . $f['sid'])->fetchColumn();
        $this->assignCategory($f['sid'], $val7, $f['c2a'], true);

        $after = $this->valuate($f['sid'], '2099-01-31', ['category_id' => $f['c2']]);
        self::assertNotSame($before['job_id'], $after['job_id']);
        self::assertSame(['count' => 5, 'value_total' => '285.00'], $after['totals']);
        self::assertSame(['id', 'code', 'name'], array_keys($after['category']));
    }

    public function testApiAndExportsWithCategory(): void
    {
        $f = $this->fixture();
        $foreign = $this->category($this->createSupplier(), 'VAL-FOREIGN');
        $action = $this->container->get(StockReportAction::class);
        foreach (['abc', 0, '5', $foreign] as $bad) {
            $response = $action->createValuationJob($this->request($f['sid'])->withParsedBody(['date' => '2099-01-31', 'category_id' => $bad]), new Response());
            self::assertSame(422, $response->getStatusCode(), 'category_id ' . var_export($bad, true));
        }

        $created = $action->createValuationJob($this->request($f['sid'])->withParsedBody(['date' => '2099-01-31', 'category_id' => $f['c2']]), new Response());
        self::assertSame(202, $created->getStatusCode(), (string) $created->getBody());
        $jobId = (int) json_decode((string) $created->getBody(), true, 512, JSON_THROW_ON_ERROR)['id'];
        self::assertSame('completed', $this->container->get(StockValuationJobService::class)->tick($f['sid'])['status']);
        $result = $this->json($action->valuationJobResult($this->request($f['sid']), new Response(), ['id' => $jobId]));
        self::assertSame('268.00', $result['totals']['value_total']);
        self::assertSame('Kategorie VAL-C2', $result['category']['name']);

        $export = $action->export($this->request($f['sid'], ['format' => 'xlsx', 'job_id' => (string) $jobId]), new Response(), ['name' => 'valuation']);
        self::assertSame(200, $export->getStatusCode());
        $rows = $this->sheetRows((string) $export->getBody(), 'Ocenění zásob');
        self::assertSame(['Kategorie: Kategorie VAL-C2 (včetně podkategorií)'], $rows[2]);
        self::assertSame(['VAL-2', 'VAL-2', 'VAL-4', 'VAL-5'], array_map(static fn (array $r) => $r[1], array_slice($rows, 3, 4)));
        self::assertSame(['CELKEM (4 položek)', 268.0], $rows[8]);

        // Bez kategorie export beze změny: titulek, prázdný řádek 2, hlavička, 9 řádků a CELKEM.
        $plain = $this->valuate($f['sid'], '2099-01-31');
        $plainRows = $this->sheetRows($this->container->get(StockReportXlsxExporter::class)->valuation($plain)['bytes'], 'Ocenění zásob');
        self::assertCount(13, $plainRows);
        self::assertStringStartsWith('Ocenění zásob k ', $plainRows[1][0]);
        self::assertSame([], $plainRows[2]);
        self::assertSame(['Sklad', 'SKU', 'Název', 'MJ', 'Množství', 'Hodnota'], $plainRows[3]);
        self::assertSame(['CELKEM (9 položek)', 447.0], $plainRows[13]);

        $pdf = $action->export($this->request($f['sid'], ['format' => 'pdf', 'job_id' => (string) $jobId]), new Response(), ['name' => 'valuation']);
        self::assertSame(200, $pdf->getStatusCode());
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody());
        $renderer = $this->container->get(StockValuationPdfRenderer::class);
        $html = (fn (array $data): string => $this->renderTemplate('stock_valuation.twig', $data))->call($renderer, $result);
        self::assertStringContainsString('Kategorie: Kategorie VAL-C2 (včetně podkategorií)', $html);
    }

    public function testRenamedOrDeletedCategoryStillMarksTheResultAsFiltered(): void
    {
        $f = $this->fixture();
        $jobId = $this->valuate($f['sid'], '2099-01-31', ['category_id' => $f['c2']])['job_id'];
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE stock_categories SET name = ? WHERE id = ?')->execute(['Přejmenovaná', $f['c2']]);
        $pdo->prepare('DELETE FROM stock_item_categories WHERE category_id IN (?, ?)')->execute([$f['c2'], $f['c2a']]);
        $pdo->prepare('DELETE FROM stock_categories WHERE id IN (?, ?)')->execute([$f['c2a'], $f['c2']]);

        $jobs = $this->container->get(StockValuationJobService::class);
        $result = $jobs->result($f['sid'], $jobId, 1, 500);
        self::assertSame(['count' => 4, 'value_total' => '268.00'], $result['totals']);
        self::assertSame(['id' => $f['c2'], 'code' => 'VAL-C2', 'name' => 'Kategorie VAL-C2'], $result['category'], 'Kategorie z doby výpočtu.');
        $rows = $this->sheetRows($this->container->get(StockReportXlsxExporter::class)->valuation($result)['bytes'], 'Ocenění zásob');
        self::assertSame(['Kategorie: Kategorie VAL-C2 (včetně podkategorií)'], $rows[2]);
        self::assertStringStartsWith('%PDF', $this->container->get(StockValuationPdfRenderer::class)->render($result));
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function valuate(int $sid, string $date, array $filters = []): array
    {
        $jobs = $this->container->get(StockValuationJobService::class);
        $job = $jobs->enqueue($sid, $date, $filters);
        if ($job['status'] !== 'completed') {
            self::assertSame('completed', $jobs->tick($sid)['status']);
        }
        return $jobs->result($sid, (int) $job['id'], 1, 500);
    }

    public function testJobOfAnotherCompanyIsNotExposed(): void
    {
        $f = $this->fixture();
        $jobId = $this->completedJob($f['sid']);
        $other = $this->createSupplier();
        $jobs = $this->container->get(StockValuationJobService::class);
        foreach ([fn () => $jobs->categoryGroups($other, $jobId, null), fn () => $jobs->result($other, $jobId)] as $call) {
            try {
                $call();
                self::fail('Úloha jiné firmy musí být nenalezena.');
            } catch (StockException $e) {
                self::assertSame(404, $e->httpStatus);
            }
        }
        $action = $this->container->get(StockReportAction::class);
        $response = $action->valuationJobResult($this->request($other, ['group_by' => 'category']), new Response(), ['id' => $jobId]);
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('VAL-', (string) $response->getBody());
        $export = $action->export($this->request($other, ['format' => 'xlsx', 'job_id' => (string) $jobId, 'group_by' => 'category']), new Response(), ['name' => 'valuation']);
        self::assertSame(404, $export->getStatusCode());
        // Kategorie jiné firmy jako kořen seskupení vlastní úlohy: odmítnuto, ne prázdný souhrn.
        $response = $action->valuationJobResult($this->request($other, ['group_by' => 'category', 'group_parent_id' => (string) $f['root']]), new Response(), ['id' => $jobId]);
        self::assertSame(404, $response->getStatusCode());
    }

    /** @param array<string,string> $query */
    private function request(int $supplierId, array $query = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/stock/reports/valuation')
            ->withQueryParams($query)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    /** @return array<string,mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function sheetNames(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stockval_') . '.xlsx';
        file_put_contents($tmp, $bytes);
        try {
            return IOFactory::load($tmp)->getSheetNames();
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array<int,list<mixed>> */
    private function sheetRows(string $bytes, string $sheetName): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stockval_') . '.xlsx';
        file_put_contents($tmp, $bytes);
        try {
            $rows = [];
            foreach (IOFactory::load($tmp)->getSheetByName($sheetName)->toArray(null, true, false, false) as $index => $row) {
                $rows[$index + 1] = array_values(array_filter($row, static fn ($v) => $v !== null));
            }
            return $rows;
        } finally {
            @unlink($tmp);
        }
    }
}
