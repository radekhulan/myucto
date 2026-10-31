<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalRelatedAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\JournalLinkService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Graf vazeb doklad ↔ úhrada promítnutý do deníku ({@see JournalLinkService}).
 *
 * Dědí z BankPostingTestCase kvůli fixturám (výpis, transakce, faktura, párování) —
 * jsou to přesně ty tabulky, nad kterými graf běží, a duplikovat je znovu by znamenalo
 * dvě verze téhož setupu, které se rozejdou.
 *
 * TĚŽIŠTĚ TESTU:
 *  1. SYMETRIE — z účtování banky se musí dát na účtování dokladu a zpátky. Vazba
 *     jen jedním směrem je horší než žádná: účetní se z ní nedostane zpět.
 *  2. DEDUPLIKACE — jedna bankovní úhrada vydané faktury je zapsaná ve TŘECH
 *     tabulkách (invoice_payments, payment_matches, legacy matched_invoice_id).
 *     Bez deduplikace panel ukáže tutéž platbu třikrát.
 *  3. SHODA ODZNAKU S PANELEM — hasRelatedMap() (odznak v seznamu) a related()
 *     (obsah panelu) jsou dvě různé SQL cesty. Když se rozejdou, seznam lže.
 *  4. Bezpečnost — syntetická source_id uzávěrkových zápisů a cizí tenant.
 *
 * DB běží v transakci (rollback v tearDown).
 */
#[Group('integration')]
final class JournalLinkServiceTest extends BankPostingTestCase
{
    private JournalLinkService $links;
    private JournalRelatedAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links  = $this->container->get(JournalLinkService::class);
        $this->action = $this->container->get(JournalRelatedAction::class);
    }

    public function testInvoiceAndBankEntriesSeeEachOther(): void
    {
        $clientId  = $this->client('Odběratel JL');
        $invoiceId = $this->saleInvoice('JL0001', $clientId, 1000.0);
        $txId      = $this->transaction($this->statement(), 1000.0, ['variable_symbol' => 'JL0001']);
        $this->invoicePayment($invoiceId, $txId, 1000.0);

        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 1000.0);
        $bankEntry    = $this->postPredpis('bank', $txId, '221', '311', 1000.0);

        $fromInvoice = $this->related($invoiceEntry);
        self::assertCount(1, $fromInvoice['items'], 'Faktura vidí právě jednu úhradu.');
        self::assertSame('bank', $fromInvoice['items'][0]['source_type']);
        self::assertSame('payment', $fromInvoice['items'][0]['relation']);
        self::assertSame($txId, $fromInvoice['items'][0]['source_id']);
        self::assertSame($bankEntry, $fromInvoice['items'][0]['entry_id'], 'Musí nést ZAÚČTOVÁNÍ úhrady, ne jen doklad.');
        self::assertTrue($fromInvoice['items'][0]['entry_posted']);

        $fromBank = $this->related($bankEntry);
        self::assertCount(1, $fromBank['items'], 'Banka vidí právě jeden hrazený doklad.');
        self::assertSame('invoice', $fromBank['items'][0]['source_type']);
        self::assertSame('document', $fromBank['items'][0]['relation']);
        self::assertSame($invoiceId, $fromBank['items'][0]['source_id']);
        self::assertSame($invoiceEntry, $fromBank['items'][0]['entry_id'], 'Zpětná hrana chybí — z banky se nedostaneš na doklad.');
        self::assertIsArray($fromBank['items'][0]['route']);
        self::assertSame('invoice-detail', $fromBank['items'][0]['route']['name']);
    }

    public function testDocumentTitlesShowNumberAndLeaveDeletedDocumentEmpty(): void
    {
        $invoiceId = $this->saleInvoice('JL0099', $this->client('Odběratel JL číslo'), 100.0);

        $links = $this->links->withDocumentTitles($this->supplierId, [
            ['doc_type' => 'invoice', 'doc_id' => $invoiceId, 'note' => 'Zápočet'],
            ['doc_type' => 'invoice', 'doc_id' => 2147483000],
        ]);

        self::assertSame(['doc_type' => 'invoice', 'doc_id' => $invoiceId, 'note' => 'Zápočet', 'doc_title' => 'JL0099'], $links[0]);
        self::assertNull($links[1]['doc_title']);
    }

    public function testDuplicatePaymentEdgesYieldSingleItem(): void
    {
        $clientId  = $this->client('Odběratel JL dedup');
        $invoiceId = $this->saleInvoice('JL0002', $clientId, 500.0);
        // Tatáž platba zapsaná všemi třemi cestami, které v DB reálně koexistují.
        $txId = $this->transaction($this->statement(), 500.0, [
            'variable_symbol'    => 'JL0002',
            'matched_invoice_id' => $invoiceId,
            'match_status'       => 'auto_exact',
        ]);
        $this->invoicePayment($invoiceId, $txId, 500.0);
        $this->invoicePaymentMatch($txId, $invoiceId, 500.0);

        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 500.0);
        $bankEntry    = $this->postPredpis('bank', $txId, '221', '311', 500.0);

        $fromInvoice = $this->related($invoiceEntry);
        self::assertCount(1, $fromInvoice['items'], 'Trojitá evidence téže platby nesmí dát tři položky.');
        self::assertSame($bankEntry, $fromInvoice['items'][0]['entry_id']);

        $fromBank = $this->related($bankEntry);
        self::assertCount(1, $fromBank['items'], 'Trojitá evidence téže platby nesmí dát tři položky.');
        self::assertSame($invoiceEntry, $fromBank['items'][0]['entry_id']);
    }

    public function testPartialPaymentReportsAllocatedAmountSeparately(): void
    {
        $clientId  = $this->client('Odběratel JL splátka');
        $invoiceId = $this->saleInvoice('JL0003', $clientId, 1000.0);
        // Jedna transakce na 3 000 kryje víc dokladů; na tuhle fakturu jde 400.
        $txId = $this->transaction($this->statement(), 3000.0);
        $this->invoicePayment($invoiceId, $txId, 400.0);

        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 1000.0);
        $item = $this->related($invoiceEntry)['items'][0];

        self::assertSame(3000.0, (float) $item['amount'], 'amount = celá transakce.');
        self::assertSame(400.0, (float) $item['allocated_amount'], 'allocated = co z ní připadlo na doklad.');
    }

    public function testUnpostedCashPaymentIsListedWithoutEntry(): void
    {
        $clientId  = $this->client('Odběratel JL pokladna');
        $invoiceId = $this->saleInvoice('JL0004', $clientId, 250.0);
        $cashDocId = $this->cashDocument($invoiceId, 250.0);

        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 250.0);
        $items = $this->related($invoiceEntry)['items'];

        self::assertCount(1, $items);
        self::assertSame('cash', $items[0]['source_type']);
        self::assertSame($cashDocId, $items[0]['source_id']);
        // Nezaúčtovaná úhrada je sama o sobě nález (saldo nesedí s deníkem) —
        // panel ji musí ukázat, ne zamlčet kvůli chybějícímu zápisu.
        self::assertNull($items[0]['entry_id']);
        self::assertFalse($items[0]['entry_posted']);
    }

    public function testBadgeAgreesWithPanelForWholePage(): void
    {
        $clientId  = $this->client('Odběratel JL odznak');
        $invoiceId = $this->saleInvoice('JL0005', $clientId, 700.0);
        $txId      = $this->transaction($this->statement(), 700.0);
        $this->invoicePayment($invoiceId, $txId, 700.0);

        $linkedInvoice = $this->postPredpis('invoice', $invoiceId, '311', '604', 700.0);
        $linkedBank    = $this->postPredpis('bank', $txId, '221', '311', 700.0);
        // Kontrolní zápisy BEZ vazby — odznak u nich svítit nesmí.
        $lonelyInvoice = $this->postPredpis('invoice', $this->saleInvoice('JL0006', $clientId, 100.0), '311', '604', 100.0);
        $lonelyBank    = $this->postPredpis('bank', $this->transaction($this->statement(), 100.0), '221', '648', 100.0);
        $closing       = $this->postPredpis('closing', ClosingSourceId::stockClosing($this->periodId), '221', '648', 1.0);

        $page = [];
        foreach ([$linkedInvoice, $linkedBank, $lonelyInvoice, $lonelyBank, $closing] as $id) {
            $page[] = $this->journal->find($id, $this->supplierId);
        }
        $map = $this->links->hasRelatedMap($this->supplierId, $page);

        foreach ($page as $entry) {
            $entryId  = (int) $entry['id'];
            $hasPanel = $this->related($entryId)['items'] !== [];
            self::assertSame(
                $hasPanel,
                isset($map[$entryId]),
                "Odznak a panel se u zápisu #{$entryId} rozcházejí ({$entry['source_type']})."
            );
        }
        self::assertArrayHasKey($linkedInvoice, $map);
        self::assertArrayHasKey($linkedBank, $map);
        self::assertArrayNotHasKey($lonelyInvoice, $map);
        self::assertArrayNotHasKey($lonelyBank, $map);
        self::assertArrayNotHasKey($closing, $map);
    }

    public function testSyntheticSourceIdIsNeverResolved(): void
    {
        // Kdyby se syntetický klíč použil jako id dokladu, resolver by nabídl
        // NÁHODNOU CIZÍ fakturu jako „protějšek" uzávěrkového zápisu.
        foreach ([
            ['closing', ClosingSourceId::stockClosing($this->periodId)],
            ['fx_revaluation', ClosingSourceId::fxSaldo($this->periodId)],
            ['invoice', ClosingSourceId::STOCK_SLOT_BASE + 7],
        ] as [$type, $sourceId]) {
            $related = $this->links->related($this->supplierId, [
                'id' => 0, 'source_type' => $type, 'source_id' => $sourceId,
            ]);
            self::assertSame([], $related['items'], "{$type}/{$sourceId} nesmí mít protějšky.");
        }
    }

    public function testForeignTenantSeesNothing(): void
    {
        $clientId  = $this->client('Odběratel JL cizí');
        $invoiceId = $this->saleInvoice('JL0007', $clientId, 900.0);
        $txId      = $this->transaction($this->statement(), 900.0);
        $this->invoicePayment($invoiceId, $txId, 900.0);
        $this->postPredpis('bank', $txId, '221', '311', 900.0);

        $related = $this->links->related($this->supplierId + 99999, [
            'id' => 0, 'source_type' => 'invoice', 'source_id' => $invoiceId,
        ]);
        self::assertSame([], $related['items'], 'Vazby cizího tenanta nesmí prosáknout.');
    }

    public function testEndpointIsKeyedByEntryIdAndScopedByTenant(): void
    {
        $clientId  = $this->client('Odběratel JL endpoint');
        $invoiceId = $this->saleInvoice('JL0008', $clientId, 300.0);
        $txId      = $this->transaction($this->statement(), 300.0);
        $this->invoicePayment($invoiceId, $txId, 300.0);
        $bankEntry    = $this->postPredpis('bank', $txId, '221', '311', 300.0);
        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 300.0);

        $res = $this->invoke(['id' => (string) $bankEntry]);
        self::assertSame(200, $res['status'], 'Panel je readonly+.');
        self::assertSame($bankEntry, $res['body']['entry_id']);
        self::assertSame($invoiceEntry, $res['body']['items'][0]['entry_id']);
        self::assertFalse($res['body']['truncated']);

        $missing = $this->invoke(['id' => '999999999']);
        self::assertSame(404, $missing['status'], 'Neexistující zápis → 404, ne prázdný seznam.');
        self::assertSame('not_found', $missing['body']['error']['code']);
    }

    /**
     * Úhrada zálohy: záloha (proforma) vlastní zápis nemá, zaúčtuje se jen banka.
     * Bankovní zápis proto musí vést i na daňový doklad k platbě a konečnou fakturu
     * téže zálohy, a oba doklady zpátky na úhradu zálohy. Odznak musí sedět s panelem.
     */
    public function testAdvancePaymentLinksTaxDocumentAndFinalInvoice(): void
    {
        $clientId = $this->client('Odběratel JL záloha');
        $proforma = $this->saleInvoice('JL0101', $clientId, 1210.0, 'proforma', 'paid');
        $txId     = $this->transaction($this->statement(), 1210.0, ['variable_symbol' => 'JL0101']);
        $this->invoicePayment($proforma, $txId, 1210.0);
        $bankEntry = $this->postPredpis('bank', $txId, '221', '324', 1210.0);

        $ddkp = $this->saleInvoice('JL0102', $clientId, 1210.0, 'tax_document', 'paid');
        $final = $this->saleInvoice('JL0103', $clientId, 1210.0);
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id IN (?, ?)')
            ->execute([$proforma, $ddkp, $final]);
        $ddkpEntry  = $this->postPredpis('invoice', $ddkp, '324', '343', 210.0);
        $finalEntry = $this->postPredpis('invoice', $final, '311', '604', 1210.0);

        $fromBank = $this->related($bankEntry)['items'];
        $docs = array_map(static fn (array $i): string => $i['source_type'] . ':' . $i['source_id'], $fromBank);
        self::assertContains('invoice:' . $proforma, $docs, 'Banka vede na zálohu.');
        self::assertContains('invoice:' . $ddkp, $docs, 'Banka vede na daňový doklad k platbě.');
        self::assertContains('invoice:' . $final, $docs, 'Banka vede na konečnou fakturu.');
        $postable = [];
        foreach ($fromBank as $i) {
            $postable[$i['source_type'] . ':' . $i['source_id']] = $i['postable'];
        }
        self::assertFalse($postable['invoice:' . $proforma], 'Proforma se neúčtuje — chybějící zápis není nález.');
        self::assertTrue($postable['invoice:' . $ddkp]);

        foreach ([$ddkpEntry, $finalEntry] as $entryId) {
            $payments = array_values(array_filter(
                $this->related($entryId)['items'],
                static fn (array $i): bool => $i['source_type'] === 'bank',
            ));
            self::assertCount(1, $payments, "Zápis #{$entryId} ukazuje úhradu zálohy.");
            self::assertSame($txId, $payments[0]['source_id']);
            self::assertSame($bankEntry, $payments[0]['entry_id'], 'S jejím zaúčtováním.');
            self::assertSame(1210.0, (float) $payments[0]['allocated_amount']);
        }

        $page = array_map(fn (int $id): array => $this->journal->find($id, $this->supplierId), [$bankEntry, $ddkpEntry, $finalEntry]);
        $map = $this->links->hasRelatedMap($this->supplierId, $page);
        foreach ([$bankEntry, $ddkpEntry, $finalEntry] as $id) {
            self::assertArrayHasKey($id, $map, "Odznak u zápisu #{$id} chybí, panel přitom vazbu má.");
        }
    }

    /** Zrcadlo pro přijatou stranu: zálohová PF, přijatý DDKP a konečná faktura. */
    public function testPurchaseAdvancePaymentLinksTaxDocumentAndFinalInvoice(): void
    {
        $vendorId = $this->client('Dodavatel JL záloha');
        $advance  = $this->purchaseInvoice('JLA-1', $vendorId, 1210.0, 'advance');
        $txId     = $this->transaction($this->statement(), -1210.0);
        $this->paymentMatch($txId, $advance, 1210.0);
        $bankEntry = $this->postPredpis('bank', $txId, '314', '221', 1210.0);

        $ddkp  = $this->purchaseInvoice('JLA-2', $vendorId, 1210.0, 'tax_document');
        $final = $this->purchaseInvoice('JLA-3', $vendorId, 1210.0);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')->execute([$advance, $ddkp]);
        $pdo->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ? WHERE id = ?')->execute([$advance, $final]);
        $ddkpEntry  = $this->postPredpis('purchase_invoice', $ddkp, '343', '314', 210.0);
        $finalEntry = $this->postPredpis('purchase_invoice', $final, '518', '321', 1210.0);

        $items = $this->related($bankEntry)['items'];
        $docs = array_map(static fn (array $i): string => $i['source_type'] . ':' . $i['source_id'], $items);
        self::assertContains('purchase_invoice:' . $advance, $docs);
        foreach ($items as $i) {
            if ($i['source_type'] === 'purchase_invoice') {
                self::assertSame($i['source_id'] !== $advance, $i['postable'], 'Zálohová PF se neúčtuje, ostatní ano.');
            }
        }
        self::assertContains('purchase_invoice:' . $ddkp, $docs);
        self::assertContains('purchase_invoice:' . $final, $docs);

        foreach ([$ddkpEntry, $finalEntry] as $entryId) {
            $payments = array_values(array_filter(
                $this->related($entryId)['items'],
                static fn (array $i): bool => $i['source_type'] === 'bank',
            ));
            self::assertCount(1, $payments, "Zápis #{$entryId} ukazuje úhradu zálohy.");
            self::assertSame($bankEntry, $payments[0]['entry_id']);
        }

        $page = array_map(fn (int $id): array => $this->journal->find($id, $this->supplierId), [$ddkpEntry, $finalEntry]);
        $map = $this->links->hasRelatedMap($this->supplierId, $page);
        self::assertArrayHasKey($ddkpEntry, $map);
        self::assertArrayHasKey($finalEntry, $map);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{items:list<array<string,mixed>>, truncated:bool} */
    private function related(int $entryId): array
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        self::assertIsArray($entry, "Zápis #{$entryId} nenalezen.");
        return $this->links->related($this->supplierId, $entry);
    }

    /** payment_matches pro VYDANOU fakturu (base třída umí jen přijatou). */
    private function invoicePaymentMatch(int $txId, int $invoiceId, float $amount): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, amount, match_type)
             VALUES (?, ?, ?, ?, "manual")'
        )->execute([$this->supplierId, $txId, $invoiceId, $amount]);
    }

    /** Pokladní doklad hradící vydanou fakturu; ZÁMĚRNĚ bez zaúčtování. */
    private function cashDocument(int $invoiceId, float $amount): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO cash_registers (supplier_id, name, currency_code, account_code)
             VALUES (?, ?, "CZK", "211")'
        )->execute([$this->supplierId, 'Pokladna JL ' . uniqid()]);
        $registerId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date,
                 description, total_amount, currency_code, invoice_id, status)
             VALUES (?, ?, "in", "invoice_payment", ?, ?, "Úhrada faktury", ?, "CZK", ?, "draft")'
        )->execute([
            $this->supplierId, $registerId, 'PPD-JL-' . uniqid(),
            self::YEAR . '-06-16', $amount, $invoiceId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param  array<string,string> $args
     * @return array{status:int, body:array<string,mixed>}
     */
    private function invoke(array $args): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly']);

        $resp = $this->action->__invoke($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
