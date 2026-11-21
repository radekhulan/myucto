<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\OtherItemBookAction;
use MyInvoice\Bootstrap;
use MyInvoice\Http\OtherItemListFilters;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\OtherItemRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\OtherItemBook\OtherItemBook;
use MyInvoice\Service\Accounting\OtherItemBook\OtherItemBookService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Kniha ostatních pohledávek a závazků: tentýž výběr jako výpis (včetně výchozího okna
 * splatnosti a jeho výjimek), dávky bez mezer a dvojic, součty jako součty výpisu,
 * stav řádků jako odznak výpisu, sloupec Zaúčtováno jen s právem číst účetnictví, práva.
 */
#[Group('integration')]
final class OtherItemBookTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private OtherItemService $list;
    private OtherItemRepository $repo;
    private OtherItemBookService $books;
    private OtherItemBookAction $action;
    private int $supplierId;
    private int $registerId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $this->list = $container->get(OtherItemService::class);
        $this->repo = $container->get(OtherItemRepository::class);
        $this->books = $container->get(OtherItemBookService::class);
        $this->action = $container->get(OtherItemBookAction::class);
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $this->pdo->prepare(
            "INSERT INTO cash_registers (supplier_id, name, currency_code, account_code)
             VALUES (?, 'Syntetická pokladna', 'CZK', '211')"
        )->execute([$this->supplierId]);
        $this->registerId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testBookSelectsExactlyTheListItemsForEveryFilterIncludingDefaultWindow(): void
    {
        $kindId = $this->customKind('payable', 'Syntetický nájem skladu');
        $old = $this->item('receivable', 'CZK', 100, 'posted', due: $this->day(-800), title: 'Stará dlužná pohledávka');
        $oldPaid = $this->item('receivable', 'CZK', 100, 'posted', due: $this->day(-800), title: 'Stará uhrazená');
        $this->allocate($oldPaid, 100);
        $this->item('payable', 'CZK', 200, 'confirmed', due: $this->day(10), kind: 'rent', title: 'Nájem kanceláře');
        $this->item('payable', 'CZK', 300, 'posted', due: $this->day(20), kindId: $kindId, title: 'Nájem skladu');
        $this->item('receivable', 'EUR', 50, 'draft', due: $this->day(30), title: 'Koncept kauce');
        $this->item('receivable', 'CZK', 70, 'reversed', due: $this->day(40), title: 'Stornovaná');
        $this->item('payable', 'CZK', 900, 'posted', due: $this->day(800), title: 'Vzdálený závazek');
        $paid = $this->item('payable', 'CZK', 400, 'posted', due: $this->day(5), title: 'Uhrazený nájem');
        $this->allocate($paid, 400);

        $cases = [
            'výchozí výpis (otevřené)' => ['status' => 'open'],
            'vše ve výchozím okně' => ['status' => 'all'],
            'směr' => ['status' => 'all', 'side' => 'payable'],
            'vestavěný druh' => ['status' => 'all', 'side' => 'payable', 'kind' => 'rent'],
            'vlastní druh' => ['status' => 'all', 'kind_id' => (string) $kindId],
            'hledání ruší okno' => ['status' => 'all', 'q' => 'Stará'],
            'jen od' => ['status' => 'all', 'from' => $this->day(100)],
            'rozmezí' => ['status' => 'all', 'from' => $this->day(0), 'to' => $this->day(25)],
        ];
        foreach ($cases as $label => $query) {
            $parsed = OtherItemListFilters::fromRequest($this->request($query));
            self::assertArrayHasKey('filters', $parsed, $label);
            $listed = array_map(static fn (array $row): int => (int) $row['id'],
                $this->list->list($this->supplierId, $parsed['filters'], 1, 200)['items']);
            $book = $this->books->build($this->supplierId, $parsed, true, []);
            $booked = array_map(static fn (array $row): int => (int) $row['id'], iterator_to_array($book->rows(), false));
            sort($listed);
            $sortedBooked = $booked;
            sort($sortedBooked);
            self::assertSame($listed, $sortedBooked, $label);
            self::assertNotSame([], $booked, $label . ': případ musí něco vybrat');
        }

        // Výjimky okna: otevřená bez dolní meze ukáže starý dluh, ne starou uhrazenou; „vše“ ho nemá.
        $open = $this->bookIds(['status' => 'open']);
        self::assertContains($old, $open);
        self::assertNotContains($oldPaid, $open);
        self::assertNotContains($old, $this->bookIds(['status' => 'all']));
        self::assertContains($oldPaid, $this->bookIds(['status' => 'all', 'q' => 'Stará']));
    }

    public function testBatchesFollowDueDateKeyWithoutGapsOrDuplicates(): void
    {
        $ids = [];
        foreach ([3, 1, 1, 1, 2, 2] as $offset) {
            $ids[] = $this->item('payable', 'CZK', 10, 'confirmed', due: $this->day($offset));
        }
        $parsed = OtherItemListFilters::fromRequest($this->request(['status' => 'all']));
        $seen = [];
        $after = null;
        do {
            $batch = $this->repo->bookBatch($this->supplierId, $parsed['filters'], $after, 2);
            foreach ($batch as $row) $seen[] = [(string) $row['due_on'], (int) $row['id']];
            $last = $batch === [] ? null : end($batch);
            $after = $last === null ? null : [(string) $last['due_on'], (int) $last['id']];
        } while (count($batch) === 2);

        $expected = $seen;
        sort($expected);
        self::assertSame($expected, $seen, 'řazení podle splatnosti a id');
        $seenIds = array_column($seen, 1);
        sort($seenIds);
        sort($ids);
        self::assertSame($ids, $seenIds);
    }

    public function testTotalsMatchListTotalsPerSideAndCurrency(): void
    {
        $paid = $this->item('receivable', 'CZK', 1000, 'posted', settledOutside: 100);
        $this->allocate($paid, 300);
        $this->allocate($paid, 250, reversed: true);
        $this->item('receivable', 'CZK', 500, 'confirmed');
        // Přeplacená položka (zůstatek -100) nesmí snížit dluh ostatních.
        $this->allocate($this->item('receivable', 'CZK', 300, 'posted'), 400);
        $this->item('receivable', 'EUR', 200, 'posted');
        $this->item('payable', 'CZK', 700, 'posted');
        $this->item('receivable', 'CZK', 999, 'draft');
        $this->item('receivable', 'CZK', 888, 'reversed');
        $this->item('payable', 'CZK', 777, 'cancelled');

        $parsed = OtherItemListFilters::fromRequest($this->request(['status' => 'all']));
        $book = $this->books->build($this->supplierId, $parsed, true, []);
        $rows = iterator_to_array($book->rows(), false);
        self::assertCount(8, $rows, 'koncept, storno i zrušená jsou v knize, jen ne v součtech');
        $totals = array_map(static fn (array $t): array => [$t['side'], $t['currency'], $t['count'], $t['amount'], $t['paid_amount'], $t['remaining_amount']], $book->totals());
        self::assertSame([
            ['receivable', 'CZK', 3, 1800.0, 800.0, 1100.0],
            ['receivable', 'EUR', 1, 200.0, 0.0, 200.0],
            ['payable', 'CZK', 1, 700.0, 0.0, 700.0],
        ], $totals);

        $listTotals = $this->list->list($this->supplierId, $parsed['filters'], 1, 50)['totals'];
        $bookRemaining = array_map(static fn (array $t): array => [$t['side'], $t['currency'], $t['remaining_amount']], $book->totals());
        $listRemaining = array_map(static fn (array $t): array => [$t['side'], $t['currency'], $t['remaining_amount']], $listTotals);
        usort($bookRemaining, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        usort($listRemaining, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        self::assertSame($listRemaining, $bookRemaining, 'stejné pravidlo jako součty výpisu');
        self::assertSame(['side', 'currency', 'remaining_amount'], array_keys($listTotals[0]), 'tvar součtů výpisu se nemění');
    }

    public function testRowsCarryListStatusAndPostingColumnOnlyWithAccountingReadInDoubleEntry(): void
    {
        $partial = $this->item('receivable', 'CZK', 1000, 'posted', title: 'Částečně');
        $this->allocate($partial, 300);
        $over = $this->item('receivable', 'CZK', 100, 'confirmed', title: 'Přeplacená');
        $this->allocate($over, 150);
        $this->item('payable', 'CZK', 100, 'draft', title: 'Rozpracovaná', kind: 'loan');
        $parsed = OtherItemListFilters::fromRequest($this->request(['status' => 'all']));

        $rows = array_column(iterator_to_array($this->books->build($this->supplierId, $parsed, true, [])->rows(), false), null, 'title');
        self::assertSame('Částečně uhrazeno', $rows['Částečně']['status_label']);
        self::assertSame('Přeplaceno', $rows['Přeplacená']['status_label']);
        self::assertSame('Rozpracováno', $rows['Rozpracovaná']['status_label']);
        self::assertSame('Přijatá půjčka nebo splátka', $rows['Rozpracovaná']['kind_label']);
        self::assertFalse($rows['Rozpracovaná']['counted']);

        self::assertTrue($this->books->build($this->supplierId, $parsed, true, [])->showPosting);
        self::assertFalse($this->books->build($this->supplierId, $parsed, false, [])->showPosting, 'bez práva číst účetnictví');
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        self::assertFalse($this->books->build($this->supplierId, $parsed, true, [])->showPosting, 'daňová evidence nemá zápisy');
    }

    public function testSplitInstallmentRowShowsUnpaidInterestLikeListWhileTotalsStayPrincipal(): void
    {
        // Úvěr: jistina 1000 uhrazena, úrok 100 splátky ne. Výpis: zbývá 100, stav Částečně uhrazeno.
        $loan = $this->item('payable', 'CZK', 1000, 'posted', title: 'Syntetický úvěr', kind: 'loan');
        $this->pdo->prepare('INSERT INTO other_item_installments (supplier_id, other_item_id, position, due_on, amount,
                principal_amount, interest_amount, fee_amount) VALUES (?, ?, 1, ?, 1100, 1000, 100, 0)')
            ->execute([$this->supplierId, $loan, $this->day(15)]);
        $this->allocate($loan, 1000);
        $parsed = OtherItemListFilters::fromRequest($this->request(['status' => 'all']));

        $listed = $this->list->list($this->supplierId, $parsed['filters'], 1, 50)['items'][0];
        $book = $this->books->build($this->supplierId, $parsed, true, []);
        $row = iterator_to_array($book->rows(), false)[0];
        self::assertTrue($row['has_split_installments']);
        self::assertSame((float) $listed['remaining_payment'], (float) $row['remaining_payment']);
        self::assertEqualsWithDelta(100.0, (float) $row['remaining_payment'], 0.001);
        self::assertSame('Částečně uhrazeno', $row['status_label']);
        self::assertSame(0.0, $book->totals()[0]['remaining_amount'], 'součet je saldo jistiny jako výpis');
    }

    public function testRowsCrossBatchBoundariesAndTotalsFollowPrintedRows(): void
    {
        $ids = [];
        foreach ([1, 1, 1, 2, 2] as $i => $offset) {
            $ids[] = $this->item('receivable', 'CZK', 10 * ($i + 1), 'confirmed', due: $this->day($offset));
        }
        $parsed = OtherItemListFilters::fromRequest($this->request(['status' => 'all']));
        $book = $this->books->build($this->supplierId, $parsed, true, [], batchSize: 2);
        $rows = iterator_to_array($book->rows(), false);
        self::assertSame($ids, array_map(static fn (array $row): int => (int) $row['id'], $rows));
        self::assertSame(5, $book->totals()[0]['count']);
        self::assertSame(150.0, $book->totals()[0]['amount']);
    }

    public function testPostedDateOnlyForPostedItemWithEntryWhenShown(): void
    {
        $row = ['side' => 'payable', 'kind' => 'other', 'kind_name' => null, 'status' => 'posted', 'amount' => '10.00',
            'paid_amount' => '0', 'remaining_amount' => '10.00', 'journal_entry_id' => 7,
            'posted_at' => '2099-03-04 10:00:00', 'accounting_on' => '2099-03-01'];
        self::assertSame('2099-03-04', OtherItemBookService::normalize($row, [], true)['posted_on']);
        self::assertNull(OtherItemBookService::normalize($row, [], false)['posted_on']);
        self::assertNull(OtherItemBookService::normalize(['journal_entry_id' => null] + $row, [], true)['posted_on']);
        self::assertNull(OtherItemBookService::normalize(['status' => 'confirmed'] + $row, [], true)['posted_on']);
        self::assertSame('2099-03-01', OtherItemBookService::normalize(['posted_at' => null] + $row, [], true)['posted_on']);
    }

    public function testActionReturnsPdfAndXlsxWithMachineColumnsAndRespectsPermissions(): void
    {
        $kindId = $this->customKind('receivable', 'Syntetický vlastní druh');
        // Částečná úhrada: zobrazený stav (Částečně uhrazeno) se liší od uloženého (posted).
        $this->allocate($this->item('receivable', 'CZK', 1234.5, 'posted', kindId: $kindId, title: 'Syntetická pohledávka', documentNo: 'OP-SYNT-1'), 234.5);

        $pdf = $this->call(['format' => 'pdf', 'status' => 'all'], ['other_items' => 1]);
        self::assertSame(200, $pdf->getStatusCode());
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        self::assertStringContainsString('kniha-ostatnich-pohledavek-a-zavazku-', $pdf->getHeaderLine('Content-Disposition'));
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody());

        $xlsx = $this->call(['format' => 'xlsx', 'status' => 'all'], ['other_items' => 1]);
        self::assertSame(200, $xlsx->getStatusCode());
        $tmp = tempnam(sys_get_temp_dir(), 'oibook_test_');
        file_put_contents($tmp, (string) $xlsx->getBody());
        try {
            $sheet = IOFactory::load($tmp)->getActiveSheet()->toArray(null, false, false, false);
        } finally {
            @unlink($tmp);
        }
        $headerIndex = null;
        foreach ($sheet as $i => $line) {
            if (($line[0] ?? null) === 'Doklad') { $headerIndex = $i; break; }
        }
        self::assertNotNull($headerIndex);
        $header = array_values(array_filter($sheet[$headerIndex], static fn ($v): bool => $v !== null));
        self::assertSame(['Doklad', 'Směr', 'Druh', 'Položka', 'Protistrana', 'Datum vzniku', 'Splatnost', 'Částka',
            'Uhrazeno', 'Zbývá uhradit', 'Měna', 'Stav', 'side', 'kind', 'kind_id', 'status'], $header, 'bez práva účetnictví bez Zaúčtováno');
        $data = array_combine($header, array_slice($sheet[$headerIndex + 1], 0, count($header)));
        self::assertSame('OP-SYNT-1', $data['Doklad']);
        self::assertSame('Pohledávka', $data['Směr']);
        self::assertSame('Syntetický vlastní druh', $data['Druh']);
        self::assertEqualsWithDelta(1234.5, (float) $data['Částka'], 0.001);
        self::assertSame('Částečně uhrazeno', $data['Stav']);
        self::assertEqualsWithDelta(234.5, (float) $data['Uhrazeno'], 0.001);
        self::assertEqualsWithDelta(1000.0, (float) $data['Zbývá uhradit'], 0.001);
        self::assertSame('receivable', $data['side']);
        self::assertSame('other', $data['kind']);
        self::assertSame($kindId, (int) $data['kind_id']);
        self::assertSame('posted', $data['status']);

        $withAccounting = $this->call(['format' => 'xlsx', 'status' => 'all'], ['other_items' => 1, 'accounting' => 1]);
        self::assertSame(200, $withAccounting->getStatusCode());

        self::assertSame(403, $this->call(['format' => 'pdf'], ['accounting' => 1])->getStatusCode());
        self::assertSame(422, $this->call(['format' => 'csv'], ['other_items' => 1])->getStatusCode());
        $badDate = $this->call(['format' => 'pdf', 'from' => '2099-02-31'], ['other_items' => 1]);
        self::assertSame(422, $badDate->getStatusCode());
        self::assertStringContainsString('other_items.error.invalid_date', (string) $badDate->getBody());
        self::assertSame(422, $this->call(['format' => 'pdf', 'kind_id' => 'x'], ['other_items' => 1])->getStatusCode());
    }

    public function testPeriodLabelDescribesTheDueDateRangeTheSelectionUsed(): void
    {
        $label = static fn (array $query): string => (function () use ($query): string {
            $parsed = OtherItemListFilters::fromRequest((new ServerRequestFactory())->createServerRequest('GET', '/')->withQueryParams($query));
            return OtherItemBookService::periodLabel($parsed['filters'], $parsed['default_window']);
        })();
        self::assertStringEndsWith('(výchozí okno výpisu)', $label(['status' => 'all']));
        self::assertStringStartsWith('splatnost do ', $label(['status' => 'open']), 'otevřené nemají dolní mez');
        self::assertSame('bez omezení splatnosti', $label(['status' => 'all', 'q' => 'nájem']));
        self::assertSame('splatnost 1. 1. 2099 – 31. 12. 2099', $label(['from' => '2099-01-01', 'to' => '2099-12-31']));
        self::assertSame('splatnost od 1. 1. 2099', $label(['from' => '2099-01-01']));
        self::assertSame('splatnost do 31. 12. 2099', $label(['status' => 'all', 'to' => '2099-12-31']));
    }

    public function testBookIsTenantScoped(): void
    {
        $other = $this->createIsolatedSupplier($this->pdo, $this->supplierId);
        $mine = $this->item('payable', 'CZK', 10, 'confirmed');
        $this->pdo->prepare(
            'INSERT INTO other_items (supplier_id, side, title, issued_on, accounting_on, due_on, currency, amount, amount_czk, status)
             VALUES (?, "payable", "Cizí firma", CURDATE(), CURDATE(), CURDATE(), "CZK", 10, 10, "confirmed")'
        )->execute([$other]);
        self::assertSame([$mine], $this->bookIds(['status' => 'all']));
    }

    /** @return list<int> */
    private function bookIds(array $query): array
    {
        $parsed = OtherItemListFilters::fromRequest($this->request($query));
        return array_map(static fn (array $row): int => (int) $row['id'],
            iterator_to_array($this->books->build($this->supplierId, $parsed, true, [])->rows(), false));
    }

    private function request(array $query): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/accounting/other-items/book')->withQueryParams($query);
    }

    private function call(array $query, array $permissions): ResponseInterface
    {
        $request = $this->request($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 0, 'name' => 'Syntetický tisk', 'email' => 'kniha@example.test'])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, $permissions, 'custom'));
        $response = $this->action->book($request, new Response());
        $response->getBody()->rewind();
        return $response;
    }

    private function day(int $offset): string
    {
        return (new \DateTimeImmutable('today'))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    }

    private function customKind(string $side, string $name): int
    {
        $this->pdo->prepare('INSERT INTO other_item_kinds (supplier_id, side, name) VALUES (?, ?, ?)')
            ->execute([$this->supplierId, $side, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    private function item(string $side, string $currency, float $amount, string $status, float $settledOutside = 0.0,
        string $title = 'Syntetická položka', ?string $due = null, string $kind = 'other', ?int $kindId = null,
        ?string $documentNo = null): int
    {
        $due ??= $this->day(15);
        $this->pdo->prepare(
            'INSERT INTO other_items (supplier_id, side, kind, kind_id, title, issued_on, accounting_on, due_on, currency, amount,
                                      amount_czk, settled_outside_amount, status, document_no)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $side, $kind, $kindId, $title, $due, $due, $due, $currency, $amount, $amount,
            $settledOutside, $status, $documentNo]);
        return (int) $this->pdo->lastInsertId();
    }

    private function allocate(int $itemId, float $amount, bool $reversed = false): void
    {
        $this->pdo->prepare(
            "INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number,
                                         issue_date, description, total_amount, currency_code, status)
             VALUES (?, ?, 'in', 'other', ?, CURDATE(), 'Syntetická platba', ?, 'CZK', 'posted')"
        )->execute([$this->supplierId, $this->registerId, 'SYNTH-' . bin2hex(random_bytes(4)), $amount]);
        $cashId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO other_item_allocations (supplier_id, other_item_id, cash_document_id, amount, payment_on, reversed_on)
             VALUES (?, ?, ?, ?, CURDATE(), ?)'
        )->execute([$this->supplierId, $itemId, $cashId, $amount, $reversed ? (new \DateTimeImmutable('tomorrow'))->format('Y-m-d') : null]);
    }
}
