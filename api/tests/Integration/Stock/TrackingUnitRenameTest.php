<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Stock\StockTrackingAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\ClientStockRoutePolicy;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Stock\FulfillmentService;
use MyInvoice\Service\Stock\StockException;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Oprava kódu šarže a data spotřeby (PUT /api/stock/items/{id}/tracking-units/{unitId}):
 * kmen šarže se přepíše a nový text se propíše na koncepty faktur, řádky skladových dokladů
 * a rozpracovanou expedici. Vystavené faktury a expedovaná historie zůstávají.
 */
#[Group('integration')]
final class TrackingUnitRenameTest extends StockTestCase
{
    private const OLD = 'L-OLD';
    private const OLD_EXPIRES = '2027-01-31';
    private const NEW = 'L-NEW';
    private const NEW_EXPIRES = '2027-03-01';

    /** @var list<int> */
    private array $taskIds = [];

    /** Vystavení přes akci zakládá čítače číselné řady a vychystání úlohy; StockTestCase je neuklízí. */
    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($this->taskIds as $taskId) {
                $pdo->prepare('DELETE FROM fulfillment_tasks WHERE id = ?')->execute([$taskId]);
            }
            foreach ($this->supplierIds as $sid) {
                $pdo->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$sid]);
            }
        }
        parent::tearDown();
    }

    // ── (a) kmen, klíč a čtecí API ──────────────────────────────────────────

    public function testRenameChangesUnitKeyAndDraftInvoiceIssuesWithNewText(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-A');
        $this->receiveLot($sid, $wh, $item, '5', self::OLD, self::OLD_EXPIRES);
        $unitId = $this->unitId($sid, $item, self::OLD);
        $invoiceId = $this->createInvoice($sid, [$this->line($item, $wh, '2', [['quantity' => '2', 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES]])]);

        $res = $this->rename($sid, $item, $unitId, ['lot_code' => self::NEW, 'expires_on' => self::NEW_EXPIRES]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->body($res);
        self::assertSame(['id' => $unitId, 'lot_code' => self::NEW, 'expires_on' => self::NEW_EXPIRES], $body['unit']);
        self::assertSame(1, $body['updated']['invoices']);

        $row = $this->unitRow($unitId);
        self::assertSame(self::NEW, $row['lot_code']);
        self::assertSame(self::NEW_EXPIRES, $row['expires_on']);
        self::assertSame('lot:L-NEW|2027-03-01', $row['tracking_key']);

        $tracking = $this->body($this->container->get(StockTrackingAction::class)->item($this->request($sid), new Psr7Response(), ['id' => (string) $item]));
        self::assertSame([self::NEW], array_unique(array_column($tracking['inventory'], 'lot_code')));
        self::assertSame(self::NEW_EXPIRES, $tracking['inventory'][0]['expires_on']);
        self::assertSame([self::NEW], array_unique(array_column($tracking['history'], 'lot_code')));

        self::assertSame(self::NEW, $this->storedItems($invoiceId)[0]['tracking_allocations'][0]['lot_code']);
        self::assertSame(200, $this->issue($sid, $invoiceId)->getStatusCode(), 'Koncept s přepsaným textem jde vystavit.');
        self::assertSame(3000, $this->unitBalance($sid, $item, 'lot:L-NEW|2027-03-01'));
    }

    // ── (b) faktury ─────────────────────────────────────────────────────────

    public function testIssuedAndCancelledInvoicesKeepOldTextButDraftIsRewritten(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-B');
        $this->receiveLot($sid, $wh, $item, '9', self::OLD, self::OLD_EXPIRES);
        $unitId = $this->unitId($sid, $item, self::OLD);
        $selection = [['quantity' => '1', 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES]];
        $issuedId = $this->createInvoice($sid, [$this->line($item, $wh, '1', $selection)]);
        $cancelledId = $this->createInvoice($sid, [$this->line($item, $wh, '1', $selection)]);
        $draftId = $this->createInvoice($sid, [$this->line($item, $wh, '1', $selection)]);
        $this->forceStatus($issuedId, 'issued', 'FV' . random_int(10000000, 99999999));
        $this->forceStatus($cancelledId, 'cancelled', 'FV' . random_int(10000000, 99999999));
        $issuedBefore = $this->rawTracking($issuedId);
        $cancelledBefore = $this->rawTracking($cancelledId);
        $draftBefore = $this->rawTracking($draftId);

        self::assertSame(200, $this->rename($sid, $item, $unitId, ['lot_code' => self::NEW])->getStatusCode());

        self::assertSame($issuedBefore, $this->rawTracking($issuedId), 'Vystavená faktura zůstává bajtově stejná.');
        self::assertSame($cancelledBefore, $this->rawTracking($cancelledId), 'Stornovaná faktura zůstává bajtově stejná.');
        self::assertStringContainsString(self::OLD, $this->pdfText($issuedId), 'PDF vystavené faktury tiskne starý kód.');
        self::assertStringNotContainsString(self::NEW, $this->pdfText($issuedId));
        self::assertNotSame($draftBefore, $this->rawTracking($draftId));
        self::assertSame(self::NEW, $this->storedItems($draftId)[0]['tracking_allocations'][0]['lot_code']);
        self::assertSame(self::OLD_EXPIRES, $this->storedItems($draftId)[0]['tracking_allocations'][0]['expires_on'], 'Nezměněné datum zůstává.');
    }

    public function testDraftInvoiceCachedPdfIsInvalidatedAndRegeneratedWithNewText(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-B4');
        $this->receiveLot($sid, $wh, $item, '5', self::OLD, self::OLD_EXPIRES);
        $selection = [['quantity' => '1', 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES]];
        $draft = $this->createInvoice($sid, [$this->line($item, $wh, '1', $selection)]);
        $issued = $this->createInvoice($sid, [$this->line($item, $wh, '1', $selection)]);
        $this->forceStatus($issued, 'issued', 'FV' . random_int(10000000, 99999999));
        $renderer = $this->container->get(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class);
        $oldDraftPdf = $renderer->render($draft);
        $oldDraftHash = hash_file('sha256', $oldDraftPdf);
        $issuedPdf = $renderer->render($issued);
        $issuedPdfBefore = $this->pdfColumns($issued);
        self::assertNotNull($this->pdfColumns($draft)['pdf_path'], 'Koncept má vygenerované PDF v cache.');

        self::assertSame(200, $this->rename($sid, $item, $this->unitId($sid, $item, self::OLD), ['lot_code' => self::NEW])->getStatusCode());

        self::assertSame(['pdf_path' => null, 'pdf_generated_at' => null], $this->pdfColumns($draft), 'Cache PDF přepsaného konceptu se zneplatní.');
        self::assertSame($issuedPdfBefore, $this->pdfColumns($issued), 'Vystavená faktura se nedotkne.');
        self::assertFileExists($issuedPdf);
        self::assertStringContainsString(self::NEW, $this->pdfText($draft));
        $newDraftPdf = $renderer->render($draft);
        self::assertNotSame($oldDraftHash, hash_file('sha256', $newDraftPdf), 'PDF konceptu se vygeneruje znovu s novým kódem.');
    }

    /** @return array{pdf_path:?string, pdf_generated_at:?string} */
    private function pdfColumns(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT pdf_path, pdf_generated_at FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function testCreditNoteAfterRenameReturnsGoodsToTheSameLot(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-Q1');
        $this->receiveLot($sid, $wh, $item, '5', self::OLD, self::OLD_EXPIRES);
        $unitId = $this->unitId($sid, $item, self::OLD);
        $invoiceId = $this->createInvoice($sid, [$this->line($item, $wh, '2', [['quantity' => '2', 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES]])]);
        self::assertSame(200, $this->issue($sid, $invoiceId)->getStatusCode());
        $issuedBefore = $this->rawTracking($invoiceId);

        self::assertSame(200, $this->rename($sid, $item, $unitId, ['lot_code' => self::NEW, 'expires_on' => self::NEW_EXPIRES])->getStatusCode());
        self::assertSame($issuedBefore, $this->rawTracking($invoiceId), 'Vystavená faktura zůstává bajtově stejná.');

        $creditId = (int) $this->cancelInvoice($sid, $invoiceId, 'credit_note')['credit_note_id'];
        self::assertSame(self::NEW, $this->storedItems($creditId)[0]['tracking_allocations'][0]['lot_code'], 'Dobropis nese šarži z výdejky s opraveným kódem.');
        $res = $this->issue($sid, $creditId);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        self::assertSame(5000, $this->unitBalance($sid, $item, 'lot:L-NEW|2027-03-01'), 'Zboží se vrátilo do opravené šarže.');
        $units = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_tracking_units WHERE supplier_id = ? AND stock_item_id = ?');
        $units->execute([$sid, $item]);
        self::assertSame(1, (int) $units->fetchColumn(), 'Žádná nová šarže, tatáž (id).');
        self::assertSame($issuedBefore, $this->rawTracking($invoiceId));
    }

    public function testCreditNoteOfInvoiceShippedInTwoIssuesReturnsAllUnits(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-Q1B');
        $this->receiveLot($sid, $wh, $item, '12', self::OLD, self::OLD_EXPIRES);
        $unitId = $this->unitId($sid, $item, self::OLD);
        $invoiceId = $this->createInvoice($sid, [$this->line($item, $wh, '10', [['quantity' => '10', 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES]])]);
        $this->forceStatus($invoiceId, 'issued', 'FV' . random_int(10000000, 99999999));
        $invoiceItemId = (int) $this->storedItems($invoiceId)[0]['id'];
        // Faktura vydaná po částech (zásilky vychystání): každá zásilka je vlastní výdejka téhož řádku.
        foreach (['6', '4'] as $qty) {
            $issue = $this->documents->create($sid, [
                'doc_type' => 'issue', 'origin' => 'manual', 'warehouse_id' => $wh, 'doc_date' => '2099-06-10',
                'description' => 'Expedice zásilky', 'invoice_id' => $invoiceId,
                'lines' => [['stock_item_id' => $item, 'qty' => $qty, 'invoice_item_id' => $invoiceItemId,
                    'tracking_allocations' => [['quantity' => $qty, 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES]]]],
            ], $this->userId);
            $this->documents->post($sid, (int) $issue['id'], $this->userId);
        }
        self::assertSame(2000, $this->unitBalance($sid, $item, 'lot:L-OLD|2027-01-31'));

        self::assertSame(200, $this->rename($sid, $item, $unitId, ['lot_code' => self::NEW, 'expires_on' => self::NEW_EXPIRES])->getStatusCode());

        $creditId = (int) $this->cancelInvoice($sid, $invoiceId, 'credit_note')['credit_note_id'];
        $selection = $this->storedItems($creditId)[0]['tracking_allocations'];
        self::assertCount(1, $selection);
        self::assertSame(self::NEW, $selection[0]['lot_code']);
        self::assertEqualsWithDelta(10.0, (float) $selection[0]['quantity'], 0.0001, 'Dobropis vrací součet obou výdejek, ne jen poslední.');
        $res = $this->issue($sid, $creditId);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(12000, $this->unitBalance($sid, $item, 'lot:L-NEW|2027-03-01'), 'Všech 10 ks se vrátilo do opravené šarže.');
    }

    public function testUncancelAfterRenameIssuesTheSameLotAgain(): void
    {
        foreach (['cancel-then-rename', 'rename-then-cancel'] as $order) {
            $sid = $this->createSupplier();
            $wh = $this->warehouse($sid);
            $item = $this->lotItem($sid, 'REN-Q2-' . ($order === 'cancel-then-rename' ? 'A' : 'B'));
            $this->receiveLot($sid, $wh, $item, '5', self::OLD, self::OLD_EXPIRES);
            $unitId = $this->unitId($sid, $item, self::OLD);
            $invoiceId = $this->createInvoice($sid, [$this->line($item, $wh, '2', [['quantity' => '2', 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES]])]);
            self::assertSame(200, $this->issue($sid, $invoiceId)->getStatusCode());
            $rename = fn () => self::assertSame(200, $this->rename($sid, $item, $unitId, ['lot_code' => self::NEW, 'expires_on' => self::NEW_EXPIRES])->getStatusCode());
            if ($order === 'cancel-then-rename') {
                $this->cancelInvoice($sid, $invoiceId, 'internal');
                $this->container->get(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class)->render($invoiceId);
                $pdfBefore = $this->pdfColumns($invoiceId);
                $rename();
                self::assertSame($pdfBefore, $this->pdfColumns($invoiceId), 'PDF stornované faktury se opravou nezneplatňuje.');
            } else {
                $rename();
                $this->cancelInvoice($sid, $invoiceId, 'internal');
            }
            $rawBefore = $this->rawTracking($invoiceId);
            self::assertSame(5000, $this->unitBalance($sid, $item, 'lot:L-NEW|2027-03-01'), $order);

            $res = $this->container->get(\MyInvoice\Action\Invoice\UncancelInvoiceAction::class)(
                $this->adminRequest($sid, 'POST', []), new Psr7Response(), ['id' => (string) $invoiceId],
            );
            self::assertSame(200, $res->getStatusCode(), $order . ': ' . (string) $res->getBody());
            self::assertSame(3000, $this->unitBalance($sid, $item, 'lot:L-NEW|2027-03-01'), $order . ': obnovení vydalo tutéž šarži znovu.');
            self::assertSame($rawBefore, $this->rawTracking($invoiceId), 'Stornovaná faktura zůstává bajtově stejná.');
            $units = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_tracking_units WHERE supplier_id = ? AND stock_item_id = ?');
            $units->execute([$sid, $item]);
            self::assertSame(1, (int) $units->fetchColumn(), 'Žádná nová šarže.');
        }
    }

    public function testIssuedProformaIsNotRewrittenAndFinalNeedsNewLotSelection(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-Q3');
        $this->receiveLot($sid, $wh, $item, '5', self::OLD, self::OLD_EXPIRES, date('Y-m-d', strtotime('-1 day')));
        $unitId = $this->unitId($sid, $item, self::OLD);
        $selection = [['quantity' => '2', 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES]];
        $open = $this->createInvoice($sid, [$this->line($item, $wh, '2', $selection)], 'proforma');
        $this->forceStatus($open, 'paid', '9' . random_int(1000000, 9999999));
        $renderer = $this->container->get(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class);
        $renderer->render($open);
        $pdfBefore = $this->pdfColumns($open);
        $openBefore = $this->rawTracking($open);

        $res = $this->rename($sid, $item, $unitId, ['lot_code' => self::NEW, 'expires_on' => self::NEW_EXPIRES]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(0, $this->body($res)['updated']['invoices'], 'Vystavená záloha se nepřepisuje.');

        self::assertSame($openBefore, $this->rawTracking($open), 'Vystavená zálohová faktura zůstává bajtově stejná.');
        self::assertSame($pdfBefore, $this->pdfColumns($open), 'PDF zálohy se nezneplatňuje.');

        // Finál vytvořený po opravě nese starý kód; vystavit ho jde až po novém výběru šarže.
        $finalId = $this->container->get(\MyInvoice\Service\Invoice\FinalFromProformaCreator::class)->create($open, $this->userId);
        self::assertSame(self::OLD, $this->storedItems($finalId)[0]['tracking_allocations'][0]['lot_code']);
        $res = $this->issue($sid, $finalId);
        self::assertGreaterThanOrEqual(400, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(5000, $this->unitBalance($sid, $item, 'lot:L-NEW|2027-03-01'), 'Nic se nevydalo.');
        self::assertSame('draft', $this->invoiceStatus($finalId));

        $items = $this->storedItems($finalId);
        $items[0]['tracking_allocations'] = [['quantity' => '2', 'lot_code' => self::NEW, 'expires_on' => self::NEW_EXPIRES]];
        $res = $this->container->get(\MyInvoice\Action\Invoice\UpdateInvoiceAction::class)(
            $this->adminRequest($sid, 'PUT', ['items' => $items]), new Psr7Response(), ['id' => (string) $finalId],
        );
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $res = $this->issue($sid, $finalId);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(3000, $this->unitBalance($sid, $item, 'lot:L-NEW|2027-03-01'), 'Finál po novém výběru vydal opravenou šarži.');
    }

    private function invoiceStatus(int $invoiceId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        return (string) $stmt->fetchColumn();
    }

    public function testDraftOfAnotherCardWithSameLotTextIsNotRewritten(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-B2-A');
        $other = $this->lotItem($sid, 'REN-B2-B');
        $this->receiveLot($sid, $wh, $item, '3', self::OLD, self::OLD_EXPIRES);
        $this->receiveLot($sid, $wh, $other, '3', self::OLD, self::OLD_EXPIRES);
        $mine = $this->createInvoice($sid, [$this->line($item, $wh, '1', [['quantity' => '1', 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES]])]);
        $foreign = $this->createInvoice($sid, [$this->line($other, $wh, '1', [['quantity' => '1', 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES]])]);
        $foreignBefore = $this->rawTracking($foreign);

        self::assertSame(200, $this->rename($sid, $item, $this->unitId($sid, $item, self::OLD), ['lot_code' => self::NEW])->getStatusCode());

        self::assertSame(self::NEW, $this->storedItems($mine)[0]['tracking_allocations'][0]['lot_code']);
        self::assertSame($foreignBefore, $this->rawTracking($foreign), 'Koncept jiné karty se stejným textem se nemění.');
    }

    public function testDraftWithDifferentLetterCaseIsRewritten(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-B3');
        $this->receiveLot($sid, $wh, $item, '3', 'ABC', null);
        $draft = $this->createInvoice($sid, [$this->line($item, $wh, '1', [['quantity' => '1', 'lot_code' => 'abc']])]);

        self::assertSame(200, $this->rename($sid, $item, $this->unitId($sid, $item, 'ABC'), ['lot_code' => 'XYZ'])->getStatusCode());

        self::assertSame('XYZ', $this->storedItems($draft)[0]['tracking_allocations'][0]['lot_code']);
        self::assertSame(200, $this->issue($sid, $draft)->getStatusCode());
    }

    // ── (c) skladové doklady ────────────────────────────────────────────────

    public function testStockDraftsAndPostedDocumentsCarryNewText(): void
    {
        $sid = $this->createSupplier();
        $whA = $this->warehouse($sid, 'A');
        $whB = $this->warehouse($sid, 'B', false);
        $item = $this->lotItem($sid, 'REN-C');
        $receipt = $this->lotDocument($sid, 'receipt', $whA, $item, '10');
        $issue = $this->lotDocument($sid, 'issue', $whA, $item, '2');
        $transfer = $this->lotDocument($sid, 'transfer', $whA, $item, '3', $whB);
        $reversed = $this->lotDocument($sid, 'issue', $whA, $item, '1');
        $reversal = $this->documents->reverse($sid, (int) $reversed['id'], [], $this->userId)['reversal'];
        $draftReceipt = $this->lotDocument($sid, 'receipt', $whA, $item, '4', null, post: false);
        $draftIssue = $this->lotDocument($sid, 'issue', $whA, $item, '1', null, post: false);

        $res = $this->rename($sid, $item, $this->unitId($sid, $item, self::OLD), ['lot_code' => self::NEW, 'expires_on' => self::NEW_EXPIRES]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $updated = $this->body($res)['updated'];
        self::assertSame(2, $updated['stock_drafts']);
        self::assertSame(5, $updated['stock_posted'], 'Příjemka, výdejka, převodka, výdejka se stornem a její protidoklad.');

        foreach ([$receipt, $issue, $transfer, $reversed, $reversal] as $doc) {
            $line = $this->docsRepo->findWithLines($sid, (int) $doc['id'])['lines'][0];
            self::assertSame(self::NEW, $line['tracking_allocations'][0]['lot_code'], 'Doklad ' . $doc['doc_number']);
            self::assertSame(self::NEW_EXPIRES, $line['tracking_allocations'][0]['expires_on']);
        }

        $this->documents->post($sid, (int) $draftReceipt['id'], $this->userId);
        $this->documents->post($sid, (int) $draftIssue['id'], $this->userId);
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_tracking_units WHERE supplier_id = ? AND stock_item_id = ?');
        $count->execute([$sid, $item]);
        self::assertSame(1, (int) $count->fetchColumn(), 'Zaúčtování konceptu nezakládá druhou šarži se starým textem.');

        $storno = $this->documents->reverse($sid, (int) $issue['id'], [], $this->userId);
        self::assertSame('posted', $storno['reversal']['status'], 'Storno po opravě projde.');
    }

    // ── (d) vychystání a expedice ───────────────────────────────────────────

    public function testPackingShipmentAndOpenTaskAreRewrittenAndShippedHistoryKeepsAliases(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-D');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this->receiveLot($sid, $wh, $item, '6', self::OLD, self::OLD_EXPIRES, $yesterday);
        $unitId = $this->unitId($sid, $item, self::OLD);
        $source = $this->documents->create($sid, [
            'doc_type' => 'issue', 'origin' => 'manual', 'warehouse_id' => $wh, 'doc_date' => date('Y-m-d'),
            'description' => 'Syntetický výdej pro vychystání',
            'lines' => [['stock_item_id' => $item, 'qty' => '6.000', 'tracking_allocations' => [
                ['lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES, 'quantity' => '6'],
            ]]],
        ], $this->userId);
        $fulfillment = $this->container->get(FulfillmentService::class);
        $task = $fulfillment->createTask($sid, 'stock_issue_draft', (string) $source['id'], $this->userId);
        $this->taskIds[] = (int) $task['id'];
        $fulfillment->scan($sid, (int) $task['id'], [
            'client_operation_id' => 'rename-scan-' . uniqid(), 'code' => $task['lines'][0]['component_snapshot']['sku'], 'quantity' => '6.000',
        ], $this->userId, false);
        $lineId = (int) $task['lines'][0]['id'];
        $shipmentItems = static fn (string $qty): array => ['carrier' => 'Test Carrier', 'tracking_number' => 'TRACK-' . uniqid(), 'items' => [[
            'task_line_id' => $lineId, 'quantity' => $qty,
            'tracking_allocations' => [['lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES, 'quantity' => $qty]],
        ]]];

        // Expedovaná zásilka s první částečnou vratkou ještě před opravou.
        $first = $fulfillment->createShipment($sid, (int) $task['id'], $shipmentItems('3.000'), $this->userId);
        $dispatched = $fulfillment->dispatch($sid, (int) $first['id'], $this->userId);
        $shippedItem = $dispatched['shipments'][0]['items'][0];
        $fulfillment->receiveReturn($sid, (int) $first['id'], [
            'disposition' => 'scrap',
            'items' => [['shipment_item_id' => (int) $shippedItem['id'], 'quantity' => '1.000', 'tracking_allocations' => [
                ['lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES, 'quantity' => '1.000'],
            ]]],
        ], $this->userId);
        $second = $fulfillment->createShipment($sid, (int) $task['id'], $shipmentItems('3.000'), $this->userId);
        $secondItemId = (int) $this->db->pdo()->query('SELECT id FROM fulfillment_shipment_items WHERE shipment_id = ' . (int) $second['id'])->fetchColumn();
        $shippedBefore = $this->rawColumn('fulfillment_shipment_items', 'tracking_snapshot', (int) $shippedItem['id']);
        $returnBefore = (string) $this->db->pdo()->query('SELECT component_snapshot FROM fulfillment_return_items WHERE shipment_item_id = ' . (int) $shippedItem['id'])->fetchColumn();
        $scansBefore = (string) $this->db->pdo()->query('SELECT GROUP_CONCAT(payload_json) FROM fulfillment_scan_operations WHERE task_id = ' . (int) $task['id'])->fetchColumn();

        $res = $this->rename($sid, $item, $unitId, ['lot_code' => self::NEW, 'expires_on' => self::NEW_EXPIRES]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $updated = $this->body($res)['updated'];
        self::assertSame(2, $updated['fulfillment'], 'Snapshot rozpracované zásilky a řádek otevřené úlohy.');
        self::assertSame(1, $updated['stock_drafts'], 'Převzatý výdejní koncept.');

        self::assertSame($shippedBefore, $this->rawColumn('fulfillment_shipment_items', 'tracking_snapshot', (int) $shippedItem['id']), 'Expedovaný snapshot je historie.');
        self::assertSame($returnBefore, (string) $this->db->pdo()->query('SELECT component_snapshot FROM fulfillment_return_items WHERE shipment_item_id = ' . (int) $shippedItem['id'])->fetchColumn());
        self::assertSame($scansBefore, (string) $this->db->pdo()->query('SELECT GROUP_CONCAT(payload_json) FROM fulfillment_scan_operations WHERE task_id = ' . (int) $task['id'])->fetchColumn(), 'Log čtečky se nepřepisuje.');
        self::assertSame(self::NEW, json_decode($this->rawColumn('fulfillment_shipment_items', 'tracking_snapshot', $secondItemId), true)[0]['lot_code']);
        $taskLine = json_decode($this->rawColumn('fulfillment_task_lines', 'component_snapshot', $lineId), true);
        self::assertSame(self::NEW, $taskLine['tracking_allocations'][0]['lot_code']);

        $dispatched = $fulfillment->dispatch($sid, (int) $second['id'], $this->userId);
        self::assertSame('shipped', $dispatched['status'], 'Expedice rozpracované zásilky po opravě projde.');

        // Druhá vratka s identitou ze snapshotu expedované zásilky: id a starý kód, jak je posílá UI.
        $fulfillment->receiveReturn($sid, (int) $first['id'], [
            'disposition' => 'scrap',
            'items' => [['shipment_item_id' => (int) $shippedItem['id'], 'quantity' => '2.000', 'tracking_allocations' => [
                ['stock_tracking_unit_id' => $unitId, 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES, 'quantity' => '2.000'],
            ]]],
        ], $this->userId);
        $returned = $this->db->pdo()->query('SELECT returned_qty FROM fulfillment_shipment_items WHERE id = ' . (int) $shippedItem['id'])->fetchColumn();
        self::assertSame('3.000', $returned);
    }

    // ── (e) duplicita a beze změny ──────────────────────────────────────────

    public function testDuplicateKeyReturns409AndChangesNothing(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-E');
        $this->receiveLot($sid, $wh, $item, '3', 'L-A', self::OLD_EXPIRES);
        $this->receiveLot($sid, $wh, $item, '3', 'L-B', self::OLD_EXPIRES);
        $unitB = $this->unitId($sid, $item, 'L-B');
        $draft = $this->createInvoice($sid, [$this->line($item, $wh, '1', [['quantity' => '1', 'lot_code' => 'L-B', 'expires_on' => self::OLD_EXPIRES]])]);
        $before = $this->rawTracking($draft);
        $unitBefore = $this->unitRow($unitB);

        foreach (['L-A', 'l-a'] as $clashing) {
            $res = $this->rename($sid, $item, $unitB, ['lot_code' => $clashing]);
            $this->assertError($res, 409, 'tracking_unit_exists');
        }

        self::assertSame($before, $this->rawTracking($draft), 'Po 409 zůstává koncept beze změny.');
        self::assertSame($unitBefore, $this->unitRow($unitB));
    }

    public function testLetterCaseChangeOfSameUnitPassesAndUnchangedIsNoOp(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-E2');
        $this->receiveLot($sid, $wh, $item, '3', 'abc', null);
        $unitId = $this->unitId($sid, $item, 'abc');

        $res = $this->rename($sid, $item, $unitId, ['lot_code' => 'ABC']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('ABC', $this->unitRow($unitId)['lot_code']);
        self::assertSame('lot:ABC|', $this->unitRow($unitId)['tracking_key']);

        $logBefore = $this->logCount($sid, $item);
        $res = $this->rename($sid, $item, $unitId, ['lot_code' => 'ABC']);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['invoices' => 0, 'stock_drafts' => 0, 'stock_posted' => 0, 'fulfillment' => 0], $this->body($res)['updated']);
        self::assertSame($logBefore, $this->logCount($sid, $item), 'Beze změny se nezapisuje audit.');
    }

    public function testExpiryCanBeClearedAndKeyFollows(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-E3');
        $this->receiveLot($sid, $wh, $item, '3', self::OLD, self::OLD_EXPIRES);
        $unitId = $this->unitId($sid, $item, self::OLD);

        self::assertSame(200, $this->rename($sid, $item, $unitId, ['expires_on' => null])->getStatusCode());
        $row = $this->unitRow($unitId);
        self::assertNull($row['expires_on']);
        self::assertSame(self::OLD, $row['lot_code'], 'Chybějící klíč lot_code hodnotu ponechá.');
        self::assertSame('lot:L-OLD|', $row['tracking_key']);
    }

    // ── (f) 404, 422, 403 ───────────────────────────────────────────────────

    public function testForeignAndMissingUnitsReturn404(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-F-A');
        $other = $this->lotItem($sid, 'REN-F-B');
        $this->receiveLot($sid, $wh, $item, '3', self::OLD, self::OLD_EXPIRES);
        $this->receiveLot($sid, $wh, $other, '3', 'L-OTHER', null);
        $unitId = $this->unitId($sid, $item, self::OLD);

        $this->assertError($this->rename($sid, $other, $unitId, ['lot_code' => 'X']), 404, 'not_found');
        $this->assertError($this->rename($sid, $item, 99999999, ['lot_code' => 'X']), 404, 'not_found');
        $this->assertError($this->rename($sid, 99999999, $unitId, ['lot_code' => 'X']), 404, 'not_found');
        $foreignSupplier = $this->createSupplier();
        $this->assertError($this->rename($foreignSupplier, $item, $unitId, ['lot_code' => 'X']), 404, 'not_found');
        self::assertSame(self::OLD, $this->unitRow($unitId)['lot_code']);
    }

    public function testServiceDoesNotTouchUnitOfAnotherSupplierOrCard(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-F1-A');
        $other = $this->lotItem($sid, 'REN-F1-B');
        $this->receiveLot($sid, $wh, $item, '3', self::OLD, self::OLD_EXPIRES);
        $unitId = $this->unitId($sid, $item, self::OLD);
        $foreignSupplier = $this->createSupplier();
        $service = $this->container->get(\MyInvoice\Service\Stock\TrackingUnitRenameService::class);

        foreach ([[$foreignSupplier, $item], [$sid, $other]] as [$supplierId, $itemId]) {
            try {
                $service->rename($supplierId, $itemId, $unitId, ['lot_code' => 'X']);
                self::fail('Cizí šarže se nesmí opravit.');
            } catch (StockException $e) {
                self::assertSame(404, $e->httpStatus);
            }
        }
        self::assertSame(self::OLD, $this->unitRow($unitId)['lot_code']);
    }

    public function testInvalidInputReturns422AndSerialIsRejected(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-F2');
        $serialItem = $this->lotItem($sid, 'REN-F2-S');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'serial' WHERE id = ?")->execute([$serialItem]);
        $this->receiveLot($sid, $wh, $item, '3', self::OLD, self::OLD_EXPIRES);
        $this->receiveSerial($sid, $wh, $serialItem, 'SN-1');
        $unitId = $this->unitId($sid, $item, self::OLD);
        $serialId = (int) $this->db->pdo()->query("SELECT id FROM stock_tracking_units WHERE stock_item_id = $serialItem")->fetchColumn();

        $this->assertError($this->rename($sid, $item, $unitId, ['lot_code' => '']), 422, 'validation_failed');
        $this->assertError($this->rename($sid, $item, $unitId, ['lot_code' => '   ']), 422, 'validation_failed');
        $this->assertError($this->rename($sid, $item, $unitId, ['lot_code' => str_repeat('x', 101)]), 422, 'validation_failed');
        $this->assertError($this->rename($sid, $item, $unitId, ['lot_code' => ['x']]), 422, 'validation_failed');
        $this->assertError($this->rename($sid, $item, $unitId, ['expires_on' => '2026-02-31']), 422, 'validation_failed');
        $this->assertError($this->rename($sid, $item, $unitId, ['expires_on' => 'zítra']), 422, 'validation_failed');
        $this->assertError($this->rename($sid, $serialItem, $serialId, ['lot_code' => 'X']), 422, 'tracking_mode_mismatch');
        self::assertSame(self::OLD, $this->unitRow($unitId)['lot_code']);
    }

    public function testRenameRequiresStockItemsWritePermission(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-F3');
        $this->receiveLot($sid, $wh, $item, '3', self::OLD, self::OLD_EXPIRES);
        $unitId = $this->unitId($sid, $item, self::OLD);

        $reader = $this->rename($sid, $item, $unitId, ['lot_code' => 'X'], ['stock' => 1]);
        self::assertSame(403, $reader->getStatusCode());
        self::assertSame(self::OLD, $this->unitRow($unitId)['lot_code']);
    }

    public function testClientRoleRoutePolicyDoesNotAllowTheNewPut(): void
    {
        self::assertFalse(ClientStockRoutePolicy::allows('PUT', '/api/stock/items/5/tracking-units/9', 'stock.items.write'));
        self::assertFalse(ClientStockRoutePolicy::allows('GET', '/api/stock/items/5/tracking-units', 'stock'));
        self::assertTrue(ClientStockRoutePolicy::allows('GET', '/api/stock/items/5/tracking', 'stock'), 'Čtení šarží klientovi zůstává.');
    }

    // ── (g) audit ───────────────────────────────────────────────────────────

    public function testActivityLogRecordsOldAndNewTextWithCounts(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-G');
        $this->receiveLot($sid, $wh, $item, '3', self::OLD, self::OLD_EXPIRES);
        $this->createInvoice($sid, [$this->line($item, $wh, '1', [['quantity' => '1', 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES]])]);
        $unitId = $this->unitId($sid, $item, self::OLD);

        self::assertSame(200, $this->rename($sid, $item, $unitId, ['lot_code' => self::NEW, 'expires_on' => self::NEW_EXPIRES])->getStatusCode());

        $stmt = $this->db->pdo()->prepare("SELECT payload FROM activity_log WHERE supplier_id = ? AND action = 'stock.tracking_unit_updated' AND entity_type = 'stock_item' AND entity_id = ?");
        $stmt->execute([$sid, $item]);
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(1, $rows);
        $payload = json_decode((string) $rows[0], true);
        self::assertSame($unitId, $payload['stock_tracking_unit_id']);
        self::assertSame(['lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES], $payload['from']);
        self::assertSame(['lot_code' => self::NEW, 'expires_on' => self::NEW_EXPIRES], $payload['to']);
        self::assertSame(1, $payload['updated']['invoices']);
        self::assertSame(1, $payload['updated']['stock_posted']);
    }

    public function testFailureInLastStepRollsBackEverythingWrittenBefore(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-G2');
        $this->receiveLot($sid, $wh, $item, '6', self::OLD, self::OLD_EXPIRES, date('Y-m-d', strtotime('-1 day')));
        $unitId = $this->unitId($sid, $item, self::OLD);
        $invoice = $this->createInvoice($sid, [$this->line($item, $wh, '1', [['quantity' => '1', 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES]])]);
        $source = $this->documents->create($sid, [
            'doc_type' => 'issue', 'origin' => 'manual', 'warehouse_id' => $wh, 'doc_date' => date('Y-m-d'),
            'description' => 'Výdej pro vychystání',
            'lines' => [['stock_item_id' => $item, 'qty' => '2.000', 'tracking_allocations' => [
                ['lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES, 'quantity' => '2'],
            ]]],
        ], $this->userId);
        $task = $this->container->get(FulfillmentService::class)->createTask($sid, 'stock_issue_draft', (string) $source['id'], $this->userId);
        $this->taskIds[] = (int) $task['id'];
        $receiptLineId = (int) $this->db->pdo()->query("SELECT id FROM stock_document_lines WHERE supplier_id = $sid AND stock_item_id = $item AND document_id <> {$source['id']}")->fetchColumn();
        $snapshot = fn (): array => [
            $this->unitRow($unitId),
            $this->rawTracking($invoice),
            $this->rawColumn('stock_document_lines', 'tracking_input_json', $receiptLineId),
            $this->rawColumn('stock_document_lines', 'tracking_input_json', (int) $source['lines'][0]['id']),
            $this->rawColumn('fulfillment_task_lines', 'component_snapshot', (int) $task['lines'][0]['id']),
        ];
        $before = $snapshot();

        // Poslední krok přepisu (řádek úlohy vychystání) shodí databáze; předchozí zápisy se musí vrátit.
        $pdo = $this->db->pdo();
        $pdo->exec("CREATE TRIGGER trg_rename_fail BEFORE UPDATE ON fulfillment_task_lines FOR EACH ROW
            BEGIN IF NEW.supplier_id = $sid THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulovaná chyba'; END IF; END");
        try {
            $this->container->get(\MyInvoice\Service\Stock\TrackingUnitRenameService::class)
                ->rename($sid, $item, $unitId, ['lot_code' => self::NEW, 'expires_on' => self::NEW_EXPIRES]);
            self::fail('Chyba posledního kroku musí skončit výjimkou.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('simulovaná chyba', $e->getMessage());
        } finally {
            $leaked = $pdo->inTransaction();
            $afterFailure = $snapshot();
            if ($leaked) {
                $pdo->rollBack();
            }
            $pdo->exec('DROP TRIGGER IF EXISTS trg_rename_fail');
        }
        self::assertFalse($leaked, 'Služba po chybě neponechá otevřenou transakci.');
        self::assertSame($before, $afterFailure, 'Kmen šarže i všechny kopie jsou bajtově stejné jako před pokusem.');
    }

    public function testRenameLocksDraftInvoiceBeforeTheUnitAndMapsLockTimeoutTo409(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-LOCK');
        $this->receiveLot($sid, $wh, $item, '5', self::OLD, self::OLD_EXPIRES);
        $unitId = $this->unitId($sid, $item, self::OLD);
        $invoiceId = $this->createInvoice($sid, [$this->line($item, $wh, '1', [['quantity' => '1', 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES]])]);
        $before = $this->rawTracking($invoiceId);

        // Vystavení faktury zamyká nejdřív řádek faktury a až potom šarži (IssueInvoiceAction).
        $issuer = $this->otherConnection();
        $observer = $this->otherConnection();
        $pdo = $this->db->pdo();
        $default = (string) $pdo->query('SELECT @@innodb_lock_wait_timeout')->fetchColumn();
        $issuer->beginTransaction();
        $issuer->prepare('SELECT id FROM invoices WHERE id = ? FOR UPDATE')->execute([$invoiceId]);
        // Transakci vlastní test, aby po vypršení čekání zůstaly zámky, které služba stihla získat.
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $pdo->beginTransaction();
        try {
            try {
                $this->container->get(\MyInvoice\Service\Stock\TrackingUnitRenameService::class)
                    ->rename($sid, $item, $unitId, ['lot_code' => self::NEW]);
                self::fail('Služba má na zámek faktury čekat a po vypršení čekání skončit 409.');
            } catch (StockException $e) {
                self::assertSame('lock_conflict', $e->errorCode);
                self::assertSame(409, $e->httpStatus);
            }
            // Šarži služba nezamkla: doklady se zamykají dřív než šarže, stejně jako při vystavení.
            $observer->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $observer->beginTransaction();
            $locked = $observer->prepare('SELECT id FROM stock_tracking_units WHERE id = ? FOR UPDATE');
            $locked->execute([$unitId]);
            self::assertSame($unitId, (int) $locked->fetchColumn(), 'Šarži smí zamknout vystavení faktury, služba ji před doklady nedrží.');
        } finally {
            $observer->rollBack();
            $issuer->rollBack();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . (int) $default);
        }
        self::assertSame($before, $this->rawTracking($invoiceId));
        self::assertSame(self::OLD, $this->unitRow($unitId)['lot_code']);
    }

    private function otherConnection(): \PDO
    {
        $config = \MyInvoice\Infrastructure\Config\Config::load(dirname(__DIR__, 4));
        self::assertStringEndsWith('_test', (string) $config->get('db.name'));
        $pdo = new \PDO(
            'mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port') . ';dbname=' . $config->get('db.name') . ';charset=utf8mb4',
            (string) $config->get('db.user'),
            (string) $config->get('db.pass'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        return $pdo;
    }

    public function testPostedLinesAreRewrittenAcrossBatchBoundaries(): void
    {
        foreach ([[2, 5], [2, 4]] as [$batchSize, $movements]) {
            $sid = $this->createSupplier();
            $wh = $this->warehouse($sid);
            $item = $this->lotItem($sid, 'REN-H-' . $movements);
            $this->receiveLot($sid, $wh, $item, '20', self::OLD, self::OLD_EXPIRES);
            for ($i = 1; $i < $movements; $i++) {
                $this->lotDocument($sid, 'issue', $wh, $item, '1');
            }
            $unitId = $this->unitId($sid, $item, self::OLD);
            $service = new \MyInvoice\Service\Stock\TrackingUnitRenameService(
                $this->db,
                $this->container->get(\MyInvoice\Repository\StockTrackingRepository::class),
                $this->container->get(\MyInvoice\Repository\FulfillmentRepository::class),
                $batchSize,
            );

            $result = $service->rename($sid, $item, $unitId, ['lot_code' => self::NEW]);

            self::assertSame($movements, $result['updated']['stock_posted'], "dávka $batchSize, pohybů $movements");
            $stale = $this->db->pdo()->prepare("SELECT COUNT(*) FROM stock_document_lines WHERE supplier_id = ? AND tracking_input_json LIKE '%" . self::OLD . "%'");
            $stale->execute([$sid]);
            self::assertSame(0, (int) $stale->fetchColumn(), 'Žádný řádek nezůstal se starým kódem.');
        }
    }

    // ── seznam šarží pro editor karty ───────────────────────────────────────

    public function testLotUnitsListIsPagedSearchableAndIncludesZeroStockLots(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $item = $this->lotItem($sid, 'REN-LIST');
        foreach (['A-01', 'A-02', 'A-03', 'A-04', 'A-05', 'X_Y', 'P%Q'] as $lot) {
            $this->receiveLot($sid, $wh, $item, '1', $lot, $lot === 'A-02' ? '2027-05-01' : null);
        }
        $this->receiveLot($sid, $wh, $item, '1', 'ZERO', null);
        $issue = $this->documents->create($sid, [
            'doc_type' => 'issue', 'origin' => 'manual', 'warehouse_id' => $wh, 'doc_date' => '2099-02-01',
            'description' => 'Výdej celé šarže',
            'lines' => [['stock_item_id' => $item, 'qty' => '1.000', 'tracking_allocations' => [['quantity' => '1', 'lot_code' => 'ZERO']]]],
        ], $this->userId);
        $this->documents->post($sid, (int) $issue['id'], $this->userId);
        $serialItem = $this->item($sid, 'REN-LIST-S');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'serial' WHERE supplier_id = ? AND id = ?")->execute([$sid, $serialItem]);
        $this->receiveSerial($sid, $wh, $serialItem, 'SN-1');

        $list = fn (int $itemId, array $query, int $supplier = 0): array => $this->body($this->container->get(StockTrackingAction::class)->units(
            $this->request($supplier ?: $sid)->withQueryParams($query), new Psr7Response(), ['id' => (string) $itemId],
        ));

        $first = $list($item, ['per_page' => 5]);
        self::assertSame(['id', 'lot_code', 'expires_on'], array_keys($first['data'][0]), 'Jen kmen šarže, bez pohybů a historie.');
        self::assertSame(['A-01', 'A-02', 'A-03', 'A-04', 'A-05'], array_column($first['data'], 'lot_code'));
        self::assertSame('2027-05-01', $first['data'][1]['expires_on']);
        self::assertSame(['total' => 8, 'page' => 1, 'per_page' => 5, 'pages' => 2], $first['meta']);
        $second = $list($item, ['per_page' => 5, 'page' => 2]);
        self::assertCount(3, $second['data']);
        self::assertContains('ZERO', array_column($second['data'], 'lot_code'), 'Šarže s nulovým stavem je v seznamu.');
        self::assertSame($this->unitId($sid, $item, 'A-01'), $first['data'][0]['id']);

        self::assertSame(['A-03'], array_column($list($item, ['q' => 'a-03'])['data'], 'lot_code'), 'Hledání nerozlišuje velikost písmen.');
        self::assertSame(5, $list($item, ['q' => 'A-'])['meta']['total']);
        self::assertSame(['X_Y'], array_column($list($item, ['q' => '_'])['data'], 'lot_code'), 'Podtržítko se nehledá jako zástupný znak.');
        self::assertSame(['P%Q'], array_column($list($item, ['q' => '%'])['data'], 'lot_code'), 'Procento se nehledá jako zástupný znak.');
        self::assertSame([], $list($item, ['q' => 'neni'])['data']);

        self::assertSame([], $list($serialItem, [])['data'], 'Sériová čísla se nevracejí.');
        $foreign = $this->createSupplier();
        $res = $this->container->get(StockTrackingAction::class)->units(
            $this->request($foreign), new Psr7Response(), ['id' => (string) $item],
        );
        $this->assertError($res, 404, 'not_found');
    }

    // ── pomocníci ───────────────────────────────────────────────────────────

    private function lotItem(int $sid, string $sku): int
    {
        $id = $this->item($sid, $sku);
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'lot' WHERE supplier_id = ? AND id = ?")->execute([$sid, $id]);
        return $id;
    }

    private function receiveLot(int $sid, int $wh, int $item, string $qty, string $lot, ?string $expires, string $date = '2099-01-10'): void
    {
        $allocation = ['quantity' => $qty, 'lot_code' => $lot] + ($expires === null ? [] : ['expires_on' => $expires]);
        $draft = $this->documents->create($sid, [
            'doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $wh, 'doc_date' => $date,
            'description' => 'Příjem šarže',
            'lines' => [['stock_item_id' => $item, 'qty' => number_format((float) $qty, 3, '.', ''), 'unit_cost' => '100.000000', 'tracking_allocations' => [$allocation]]],
        ], $this->userId);
        $this->documents->post($sid, (int) $draft['id'], $this->userId);
    }

    private function receiveSerial(int $sid, int $wh, int $item, string $serial): void
    {
        $draft = $this->documents->create($sid, [
            'doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $wh, 'doc_date' => '2099-01-10',
            'description' => 'Příjem série',
            'lines' => [['stock_item_id' => $item, 'qty' => '1.000', 'unit_cost' => '100.000000', 'tracking_allocations' => [['quantity' => '1', 'serial_number' => $serial]]]],
        ], $this->userId);
        $this->documents->post($sid, (int) $draft['id'], $this->userId);
    }

    /** @return array<string,mixed> */
    private function lotDocument(int $sid, string $type, int $wh, int $item, string $qty, ?int $whTo = null, bool $post = true): array
    {
        $doc = $this->documents->create($sid, [
            'doc_type' => $type, 'origin' => 'manual', 'warehouse_id' => $wh, 'warehouse_to_id' => $whTo,
            'doc_date' => '2099-02-01', 'description' => 'Doklad šarže ' . $type,
            'lines' => [['stock_item_id' => $item, 'qty' => number_format((float) $qty, 3, '.', ''), 'unit_cost' => '100.000000', 'tracking_allocations' => [
                ['quantity' => $qty, 'lot_code' => self::OLD, 'expires_on' => self::OLD_EXPIRES],
            ]]],
        ], $this->userId);
        return $post ? $this->documents->post($sid, (int) $doc['id'], $this->userId) : $doc;
    }

    private function unitId(int $sid, int $item, string $lot): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM stock_tracking_units WHERE supplier_id = ? AND stock_item_id = ? AND lot_code = ?');
        $stmt->execute([$sid, $item, $lot]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function unitRow(int $unitId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT lot_code, expires_on, tracking_key FROM stock_tracking_units WHERE id = ?');
        $stmt->execute([$unitId]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function unitBalance(int $sid, int $item, string $key): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN a.direction = 'in' THEN a.quantity ELSE -a.quantity END), 0)
               FROM stock_tracking_allocations a JOIN stock_tracking_units u ON u.id = a.stock_tracking_unit_id
              WHERE a.supplier_id = ? AND u.stock_item_id = ? AND u.tracking_key = ?"
        );
        $stmt->execute([$sid, $item, $key]);
        return (int) round((float) $stmt->fetchColumn() * 1000);
    }

    private function logCount(int $sid, int $item): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = 'stock.tracking_unit_updated' AND entity_id = ?");
        $stmt->execute([$sid, $item]);
        return (int) $stmt->fetchColumn();
    }

    private function rawTracking(int $invoiceId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT GROUP_CONCAT(tracking_json ORDER BY id SEPARATOR "|") FROM invoice_items WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);
        return (string) $stmt->fetchColumn();
    }

    private function rawColumn(string $table, string $column, int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT $column FROM $table WHERE id = $id")->fetchColumn();
    }

    private function forceStatus(int $invoiceId, string $status, string $varsymbol): void
    {
        $this->db->pdo()->prepare('UPDATE invoices SET status = ?, varsymbol = ? WHERE id = ?')->execute([$status, $varsymbol, $invoiceId]);
    }

    private function pdfText(int $invoiceId): string
    {
        $html = $this->container->get(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class)->renderHtml(
            $this->container->get(InvoiceRepository::class)->find($invoiceId),
            includeCss: false,
            includeWorkReport: false,
        );
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param array<string,int> $permissions */
    private function rename(int $sid, int $item, int $unitId, array $body, array $permissions = ['stock' => 1, 'stock.items.write' => 2]): ResponseInterface
    {
        return $this->container->get(StockTrackingAction::class)->updateUnit(
            $this->request($sid, $permissions)->withMethod('PUT')->withParsedBody($body),
            new Psr7Response(),
            ['id' => (string) $item, 'unitId' => (string) $unitId],
        );
    }

    /** @param array<string,int> $permissions */
    private function request(int $sid, array $permissions = ['stock' => 1, 'stock.items.write' => 2]): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(101, 'Synthetic stock staff', 'staff', true, $permissions));
    }

    /** @return array<string,mixed> */
    private function cancelInvoice(int $sid, int $invoiceId, string $mode): array
    {
        $res = $this->container->get(\MyInvoice\Action\Invoice\CancelInvoiceAction::class)(
            $this->adminRequest($sid, 'POST', ['mode' => $mode, 'reason' => 'Test']), new Psr7Response(), ['id' => (string) $invoiceId],
        );
        self::assertContains($res->getStatusCode(), [200, 201], (string) $res->getBody());
        return $this->body($res);
    }

    private function issue(int $sid, int $invoiceId): ResponseInterface
    {
        return $this->container->get(\MyInvoice\Action\Invoice\IssueInvoiceAction::class)(
            $this->adminRequest($sid, 'POST', []), new Psr7Response(), ['id' => (string) $invoiceId],
        );
    }

    /**
     * @param list<array<string,mixed>>|null $tracking
     * @return array<string,mixed>
     */
    private function line(int $itemId, int $warehouseId, string $qty, ?array $tracking): array
    {
        return [
            'description' => 'Položka ' . $itemId,
            'quantity' => $qty,
            'unit' => 'ks',
            'unit_price_without_vat' => 1000,
            'vat_rate_id' => $this->vatRateId,
            'stock_item_id' => $itemId,
            'warehouse_id' => $warehouseId,
        ] + ($tracking === null ? [] : ['tracking_allocations' => $tracking]);
    }

    /** @param list<array<string,mixed>> $items */
    private function createInvoice(int $sid, array $items, string $type = 'invoice'): int
    {
        $body = [
            'client_id' => $this->client($sid),
            'invoice_type' => $type,
            'issue_date' => '2099-06-10',
            'tax_date' => '2099-06-10',
            'due_date' => '2099-06-24',
            'currency_id' => $this->currencyIdFor($sid),
            'items' => $items,
        ];
        $res = $this->container->get(CreateInvoiceAction::class)($this->adminRequest($sid, 'POST', $body), new Psr7Response());
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        return (int) $this->body($res)['id'];
    }

    /** @return list<array<string,mixed>> */
    private function storedItems(int $invoiceId): array
    {
        return array_values(array_filter(
            (array) ($this->container->get(InvoiceRepository::class)->find($invoiceId)['items'] ?? []),
            static fn (array $item): bool => ($item['item_kind'] ?? 'standard') !== 'discount',
        ));
    }

    /** @param array<string,mixed> $body */
    private function adminRequest(int $sid, string $method, array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/test')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function body(ResponseInterface $res): array
    {
        $res->getBody()->rewind();
        return (array) json_decode((string) $res->getBody(), true);
    }

    private function assertError(ResponseInterface $res, int $status, string $code): void
    {
        self::assertSame($status, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame($code, $this->body($res)['error']['code'] ?? null);
    }
}
