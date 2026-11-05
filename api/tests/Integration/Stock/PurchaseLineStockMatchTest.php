<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Repository\StockItemVendorRepository;
use PHPUnit\Framework\Attributes\Group;

/** Párování řádků přijaté faktury se skladovými kartami v návrhu příjemky. */
#[Group('integration')]
final class PurchaseLineStockMatchTest extends StockTestCase
{
    private int $sid = 0;
    private int $vendorId = 0;
    private StockItemVendorRepository $vendors;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sid = $this->createSupplier();
        $this->vendorId = $this->client($this->sid, 'Dodavatel párování');
        $this->vendors = $this->container->get(StockItemVendorRepository::class);
    }

    public function testOnlyVendorCodeAndValidEanMatchAutomaticallyOthersAreSuggestions(): void
    {
        $byVendor = $this->item($this->sid, 'INT-1');
        $this->vendors->add($this->sid, $byVendor, ['client_id' => $this->vendorId, 'vendor_sku' => 'SPOLECNY']);
        // Stejný kód jako kód dodavatele u jiné karty: přednost má kód dodavatele.
        $this->item($this->sid, 'SPOLECNY');
        $bySku = $this->item($this->sid, '000079673');
        $byEan = $this->item($this->sid, 'INT-3');
        $this->setEan($byEan, '8590000000015');
        $this->setEan($this->item($this->sid, 'INT-4'), '8590000000011');

        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $lineVendor = $this->line($piId, 'spolecny', null);
        $lineSku = $this->line($piId, '000079673', null);
        $lineEan = $this->line($piId, 'NEZNAMY', '8590000000015');
        $lineCodeIsEan = $this->line($piId, '8590000000015', null);
        $badDigitField = $this->line($piId, 'JINY', '8590000000011');
        $badDigitCode = $this->line($piId, '8590000000011', null);

        $proposal = $this->receipts->proposeForPurchaseInvoice($this->sid, $piId);
        $auto = $this->linesById($proposal);
        self::assertSame([$lineVendor, $lineEan], array_keys($auto), 'Samo se použije jen kód dodavatele a platný EAN z pole EAN.');
        self::assertSame([$byVendor, 'vendor_sku'], [$auto[$lineVendor]['stock_item_id'], $auto[$lineVendor]['match_source']]);
        self::assertSame([$byEan, 'ean'], [$auto[$lineEan]['stock_item_id'], $auto[$lineEan]['match_source']]);

        $suggested = array_column($proposal['unmatched_lines'], null, 'purchase_invoice_item_id');
        self::assertSame([null, $bySku, 'sku'], [$suggested[$lineSku]['stock_item_id'], $suggested[$lineSku]['matched_stock_item_id'], $suggested[$lineSku]['match_source']]);
        self::assertSame([null, $byEan, 'code_ean'], [$suggested[$lineCodeIsEan]['stock_item_id'], $suggested[$lineCodeIsEan]['matched_stock_item_id'], $suggested[$lineCodeIsEan]['match_source']]);
        foreach ([$badDigitField, $badDigitCode] as $line) {
            self::assertNull($suggested[$line]['matched_stock_item_id'], 'EAN s chybnou kontrolní číslicí se nepáruje.');
        }
    }

    public function testSuggestionNeedsExplicitCardInRequest(): void
    {
        $wh = $this->warehouse($this->sid);
        // Vlastní karta „1001“ a kód dodavatele „1001“ se shodují náhodou.
        $own = $this->item($this->sid, '1001');
        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $lineId = $this->line($piId, '1001', null);
        $request = fn (array $line): array => $this->receipts->createReceipt($this->sid, $piId, [
            'warehouse_id' => $wh,
            'doc_date'     => '2099-01-10',
            'lines'        => [['purchase_invoice_item_id' => $lineId, 'quantity' => '1.000'] + $line],
        ], $this->userId);

        try {
            $request([]);
            self::fail('Návrh podle kódu karty se nesmí použít bez výslovné karty.');
        } catch (\MyInvoice\Service\Stock\StockException $e) {
            self::assertSame('invalid_document', $e->errorCode);
        }
        self::assertSame([], $this->container->get(\MyInvoice\Repository\StockDocumentRepository::class)->listByPurchaseInvoice($this->sid, $piId));

        $doc = $request(['stock_item_id' => $own]);
        self::assertSame($own, (int) $doc['lines'][0]['stock_item_id']);
    }

    public function testSameCodeForTwoDifferentCardsIsRejected(): void
    {
        $wh = $this->warehouse($this->sid);
        $first = $this->item($this->sid, 'PRVNI');
        $second = $this->item($this->sid, 'DRUHA');
        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $lineA = $this->line($piId, 'DVA-RADKY', null);
        $lineB = $this->line($piId, 'dva-radky', null);

        try {
            $this->receipts->createReceipt($this->sid, $piId, [
                'warehouse_id' => $wh,
                'doc_date'     => '2099-01-10',
                'lines'        => [
                    ['purchase_invoice_item_id' => $lineA, 'stock_item_id' => $first, 'quantity' => '1.000', 'remember_seller_code' => true],
                    ['purchase_invoice_item_id' => $lineB, 'stock_item_id' => $second, 'quantity' => '1.000', 'remember_seller_code' => true],
                ],
            ], $this->userId, true);
            self::fail('Dvě karty pro jeden kód se musí odmítnout.');
        } catch (\MyInvoice\Service\Stock\StockException $e) {
            self::assertSame(['invalid_document', 422], [$e->errorCode, $e->httpStatus]);
        }
        self::assertNull($this->vendors->findByItemAndClient($this->sid, $first, $this->vendorId));
        self::assertNull($this->vendors->findByItemAndClient($this->sid, $second, $this->vendorId));
        self::assertSame([], $this->container->get(\MyInvoice\Repository\StockDocumentRepository::class)->listByPurchaseInvoice($this->sid, $piId));
    }

    public function testAmbiguousMatchStaysUnmatchedAndDoesNotFallThrough(): void
    {
        foreach (['A-1', 'A-2'] as $sku) {
            $this->vendors->add($this->sid, $this->item($this->sid, $sku), ['client_id' => $this->vendorId, 'vendor_sku' => 'DVOJI']);
        }
        $this->item($this->sid, 'DVOJI');
        foreach (['E-1', 'E-2'] as $sku) {
            $this->setEan($this->item($this->sid, $sku), '8590000000022');
        }

        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $this->line($piId, 'DVOJI', null);
        $this->line($piId, null, '8590000000022');

        $proposal = $this->receipts->proposeForPurchaseInvoice($this->sid, $piId);
        self::assertSame([], $proposal['lines']);
        self::assertCount(2, $proposal['unmatched_lines']);
    }

    public function testVendorCodeOfOtherVendorInactiveAndServiceCardsDoNotMatch(): void
    {
        $other = $this->client($this->sid, 'Jiný dodavatel');
        $this->vendors->add($this->sid, $this->item($this->sid, 'CIZI-1'), ['client_id' => $other, 'vendor_sku' => 'KOD-JINY']);
        $this->item($this->sid, 'STARA', 'goods', false);
        $this->item($this->sid, 'SLUZBA', 'service');

        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        foreach (['KOD-JINY', 'STARA', 'SLUZBA'] as $code) {
            $this->line($piId, $code, null);
        }

        $proposal = $this->receipts->proposeForPurchaseInvoice($this->sid, $piId);
        self::assertSame([], $proposal['lines']);
        self::assertCount(3, $proposal['unmatched_lines']);
    }

    public function testCardOnLineWinsAndServiceOrCodelessLinesAreOnlyCostCandidates(): void
    {
        $manual = $this->item($this->sid, 'RUCNE');
        $this->item($this->sid, 'KOD-1');
        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $withCard = $this->purchaseInvoiceItem($piId, $manual);
        $this->setCodes($withCard, 'KOD-1', null);
        $freight = $this->purchaseInvoiceItem($piId, null);
        $service = $this->line($piId, 'SLUZ-1', null);
        $this->db->pdo()->prepare("UPDATE purchase_invoice_items SET expense_kind = 'service' WHERE id = ?")->execute([$service]);

        $proposal = $this->receipts->proposeForPurchaseInvoice($this->sid, $piId);
        $line = $this->linesById($proposal)[$withCard];
        self::assertSame($manual, $line['stock_item_id'], 'Karta z řádku se nepřepisuje.');
        self::assertSame('sku', $line['match_source'], 'Automatická shoda jde vidět, liší se od zvolené karty.');
        self::assertSame([], $proposal['unmatched_lines']);
        self::assertEqualsCanonicalizing([$freight, $service], array_column($proposal['cost_candidates'], 'purchase_invoice_item_id'));
    }

    public function testRememberedCodePairsNextInvoiceAndMovesFromOtherCard(): void
    {
        $wh = $this->warehouse($this->sid);
        $target = $this->item($this->sid, 'CIL');
        $old = $this->item($this->sid, 'STARA-KARTA');
        $this->vendors->add($this->sid, $old, ['client_id' => $this->vendorId, 'vendor_sku' => 'NOVY-KOD']);

        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $lineId = $this->line($piId, 'NOVY-KOD', null);
        self::assertSame($old, $this->linesById($this->receipts->proposeForPurchaseInvoice($this->sid, $piId))[$lineId]['stock_item_id']);

        $this->receipts->createReceipt($this->sid, $piId, [
            'warehouse_id' => $wh,
            'doc_date'     => '2099-01-10',
            'lines'        => [['purchase_invoice_item_id' => $lineId, 'stock_item_id' => $target, 'quantity' => '1.000', 'remember_seller_code' => true]],
        ], $this->userId, true);

        self::assertSame('NOVY-KOD', $this->vendors->findByItemAndClient($this->sid, $target, $this->vendorId)['vendor_sku']);
        self::assertNull($this->vendors->findByItemAndClient($this->sid, $old, $this->vendorId)['vendor_sku'], 'Kód se z původní karty odebere.');

        $next = $this->purchaseInvoice($this->sid, $this->vendorId);
        $nextLine = $this->line($next, 'NOVY-KOD', null);
        $line = $this->linesById($this->receipts->proposeForPurchaseInvoice($this->sid, $next))[$nextLine];
        self::assertSame([$target, 'vendor_sku'], [$line['stock_item_id'], $line['match_source']]);
    }

    public function testRememberKeepsExistingOfferAndNeedsCheckbox(): void
    {
        $wh = $this->warehouse($this->sid);
        $card = $this->item($this->sid, 'S-NABIDKOU');
        $offerId = $this->vendors->add($this->sid, $card, ['client_id' => $this->vendorId, 'vendor_sku' => 'PUVODNI', 'purchase_price' => '12.50']);

        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $first = $this->line($piId, 'BEZ-ULOZENI', null);
        $second = $this->line($piId, 'ULOZIT', null);
        $this->receipts->createReceipt($this->sid, $piId, [
            'warehouse_id' => $wh,
            'doc_date'     => '2099-01-10',
            'lines'        => [['purchase_invoice_item_id' => $first, 'stock_item_id' => $card, 'quantity' => '1.000']],
        ], $this->userId);
        self::assertSame('PUVODNI', $this->vendors->findOffer($this->sid, $offerId)['vendor_sku']);

        $this->receipts->createReceipt($this->sid, $piId, [
            'warehouse_id' => $wh,
            'doc_date'     => '2099-01-10',
            'lines'        => [['purchase_invoice_item_id' => $second, 'stock_item_id' => $card, 'quantity' => '1.000', 'remember_seller_code' => true]],
        ], $this->userId, true);
        $offer = $this->vendors->findOffer($this->sid, $offerId);
        self::assertSame('ULOZIT', $offer['vendor_sku']);
        self::assertSame(12.5, (float) $offer['purchase_price'], 'Cena v nabídce zůstává.');
    }

    public function testRememberNeedsExplicitTrueAndCardPermissionAndIsLogged(): void
    {
        $wh = $this->warehouse($this->sid);
        $card = $this->item($this->sid, 'PRAVA');
        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $create = fn (string $code, mixed $flag, bool $canWrite): array => $this->receipts->createReceipt($this->sid, $piId, [
            'warehouse_id' => $wh,
            'doc_date'     => '2099-01-10',
            'lines'        => [['purchase_invoice_item_id' => $this->line($piId, $code, null), 'stock_item_id' => $card, 'quantity' => '1.000', 'remember_seller_code' => $flag]],
        ], $this->userId, $canWrite);

        try {
            $create('BEZ-PRAVA', true, false);
            self::fail('Bez práva upravovat karty se volba musí odmítnout.');
        } catch (\MyInvoice\Service\Stock\StockException $e) {
            self::assertSame(['card_write_forbidden', 403], [$e->errorCode, $e->httpStatus]);
        }
        self::assertNull($this->vendors->findByItemAndClient($this->sid, $card, $this->vendorId));
        self::assertSame([], $this->container->get(\MyInvoice\Repository\StockDocumentRepository::class)->listByPurchaseInvoice($this->sid, $piId), 'Odmítnutí nesmí nechat koncept příjemky.');

        foreach ([false, 1, 'true', null] as $notExplicit) {
            $create('NE-VYSLOVNE', $notExplicit, true);
            self::assertNull($this->vendors->findByItemAndClient($this->sid, $card, $this->vendorId));
        }
        self::assertSame([], $this->activity('stock.item_vendor_code_remembered', $card));

        $this->vendors->add($this->sid, $this->item($this->sid, 'DRIVE'), ['client_id' => $this->vendorId, 'vendor_sku' => 'POTVRZENY']);
        $doc = $create('POTVRZENY', true, true);
        self::assertSame('POTVRZENY', $this->vendors->findByItemAndClient($this->sid, $card, $this->vendorId)['vendor_sku']);

        $logged = $this->activity('stock.item_vendor_code_remembered', $card);
        self::assertCount(1, $logged);
        self::assertNull($logged[0]['old_vendor_sku']);
        self::assertSame('POTVRZENY', $logged[0]['new_vendor_sku']);
        self::assertSame($this->vendorId, $logged[0]['vendor_id']);
        self::assertSame((int) $doc['id'], $logged[0]['stock_document_id']);
        self::assertSame((int) $doc['lines'][0]['purchase_invoice_item_id'], $logged[0]['purchase_invoice_item_id']);
        self::assertCount(1, $logged[0]['moved_from_stock_item_ids']);
    }

    public function testActionChecksCardPermissionOfTheCaller(): void
    {
        $wh = $this->warehouse($this->sid);
        $card = $this->item($this->sid, 'AKCE');
        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $post = function (string $code, array $permissions) use ($wh, $card, $piId): array {
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/purchase-invoices/' . $piId . '/stock-receipt')
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
                ->withAttribute('auth.effective_role', new \MyInvoice\Security\EffectiveRole(0, 'Skladník', 'staff', true, $permissions))
                ->withParsedBody([
                    'warehouse_id' => $wh,
                    'doc_date'     => '2099-01-10',
                    'lines'        => [['purchase_invoice_item_id' => $this->line($piId, $code, null), 'stock_item_id' => $card, 'quantity' => '1.000', 'remember_seller_code' => true]],
                ]);
            $response = $this->container->get(\MyInvoice\Action\Stock\StockReceiptAction::class)
                ->create($request, (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(), ['id' => (string) $piId]);
            return [$response->getStatusCode(), (array) json_decode((string) $response->getBody(), true)];
        };

        [$status, $denied] = $post('AKCE-1', ['stock' => 2, 'accounting' => 2]);
        self::assertSame(403, $status);
        self::assertSame('stock.error.card_write_forbidden', $denied['error']['code']);
        self::assertNull($this->vendors->findByItemAndClient($this->sid, $card, $this->vendorId));

        [$status] = $post('AKCE-2', ['stock' => 2, 'accounting' => 2, 'stock.items.write' => 2]);
        self::assertSame(201, $status);
        self::assertSame('AKCE-2', $this->vendors->findByItemAndClient($this->sid, $card, $this->vendorId)['vendor_sku']);
    }

    public function testForeignCardCannotBeRemembered(): void
    {
        $wh = $this->warehouse($this->sid);
        $foreignCard = $this->item($this->createSupplier(), 'CIZI');
        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $lineId = $this->line($piId, 'KOD-X', null);

        try {
            $this->receipts->createReceipt($this->sid, $piId, [
                'warehouse_id' => $wh,
                'doc_date'     => '2099-01-10',
                'lines'        => [['purchase_invoice_item_id' => $lineId, 'stock_item_id' => $foreignCard, 'quantity' => '1.000', 'remember_seller_code' => true]],
            ], $this->userId, true);
            self::fail('Karta cizí firmy musí být odmítnuta.');
        } catch (\MyInvoice\Service\Stock\StockException) {
        }
        self::assertNull($this->vendors->findByItemAndClient($this->sid, $foreignCard, $this->vendorId));
    }

    public function testIntrastatIsWrittenOnlyAfterPostingWithExplicitChoice(): void
    {
        $empty = $this->item($this->sid, 'PRAZDNA');
        $filled = $this->item($this->sid, 'VYPLNENA');
        $noChoice = $this->item($this->sid, 'BEZ-VOLBY');
        $this->db->pdo()->prepare("UPDATE stock_items SET intrastat_cn8_code = '11111111' WHERE id = ?")->execute([$filled]);

        [$docId, $byCode] = $this->receiptWith(['PRAZDNA' => true, 'VYPLNENA' => true, 'BEZ-VOLBY' => 1]);
        $proposalLine = $byCode['PRAZDNA'];
        self::assertSame(['73181639', 'IT'], [$proposalLine['intrastat_cn8_code'], $proposalLine['intrastat_country_of_origin']], 'Návrh hodnoty ukazuje.');
        $before = $this->card($empty);
        self::assertNull($before['intrastat_cn8_code'], 'Koncept příjemky kartu nemění.');
        self::assertSame([], $this->receipts->fillIntrastatAfterPost($this->sid, $docId, true, $this->userId), 'Koncept se ještě nedoplňuje.');
        self::assertNull($this->card($empty)['intrastat_cn8_code']);

        $this->documents->post($this->sid, $docId, $this->userId);
        self::assertNull($this->card($empty)['intrastat_cn8_code'], 'Samotné zaúčtování službou nic nedoplní.');
        self::assertSame([], $this->receipts->fillIntrastatAfterPost($this->sid, $docId, true, $this->userId));

        $card = $this->card($empty);
        self::assertSame(['73181639', 'IT'], [$card['intrastat_cn8_code'], $card['intrastat_country_of_origin']]);
        self::assertSame((int) $before['row_version'] + 1, (int) $card['row_version']);
        $kept = $this->card($filled);
        self::assertSame(['11111111', 'IT'], [$kept['intrastat_cn8_code'], $kept['intrastat_country_of_origin']], 'Existující hodnota se nepřepíše.');
        self::assertNull($this->card($noChoice)['intrastat_cn8_code'], 'Bez výslovné volby true se nic nezapíše.');

        $logged = $this->activity('stock.item_intrastat_filled', $empty);
        self::assertCount(1, $logged);
        self::assertSame(['old' => null, 'new' => '73181639'], $logged[0]['changes']['intrastat_cn8_code']);
        self::assertSame(['old' => null, 'new' => 'IT'], $logged[0]['changes']['intrastat_country_of_origin']);
        self::assertSame($docId, $logged[0]['stock_document_id']);
        self::assertSame($byCode['PRAZDNA']['purchase_invoice_item_id'], $logged[0]['purchase_invoice_item_id']);
        self::assertArrayNotHasKey('intrastat_cn8_code', $this->activity('stock.item_intrastat_filled', $filled)[0]['changes']);
        self::assertSame([], $this->activity('stock.item_intrastat_filled', $noChoice));
    }

    public function testPostingActionNeedsCardPermissionAndTellsTheUser(): void
    {
        $denied = $this->item($this->sid, 'AKCE-BEZ');
        $allowed = $this->item($this->sid, 'AKCE-S');
        [$deniedDoc] = $this->receiptWith(['AKCE-BEZ' => true]);
        [$allowedDoc] = $this->receiptWith(['AKCE-S' => true]);

        $post = function (int $docId, array $permissions): array {
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/stock/documents/' . $docId . '/post')
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
                ->withAttribute('auth.effective_role', new \MyInvoice\Security\EffectiveRole(0, 'Skladník', 'staff', true, $permissions));
            $response = $this->container->get(\MyInvoice\Action\Stock\StockDocumentAction::class)
                ->post($request, new \Slim\Psr7\Response(), ['id' => (string) $docId]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            return (array) json_decode((string) $response->getBody(), true);
        };

        $body = $post($deniedDoc, ['stock' => 2, 'accounting' => 2]);
        self::assertSame('posted', $body['status'], 'Bez práva na karty se příjemka zaúčtuje, přeskočí se jen doplnění.');
        self::assertContains('Údaje pro Intrastat se na kartu nedoplnily: nemáte právo upravovat skladové karty.', $body['warnings']);
        self::assertNull($this->card($denied)['intrastat_cn8_code']);
        self::assertSame([], $this->activity('stock.item_intrastat_filled', $denied));

        $body = $post($allowedDoc, ['stock' => 2, 'accounting' => 2, 'stock.items.write' => 2]);
        self::assertNotContains('Údaje pro Intrastat se na kartu nedoplnily: nemáte právo upravovat skladové karty.', $body['warnings']);
        self::assertSame('73181639', $this->card($allowed)['intrastat_cn8_code']);
        self::assertCount(1, $this->activity('stock.item_intrastat_filled', $allowed));
    }

    public function testIntrastatCanComeOnlyFromTheReceiptsOwnInvoice(): void
    {
        $card = $this->item($this->sid, 'VLASTNI');
        $foreignCard = $this->item($this->sid, 'CIZI-RADEK');
        [$docId] = $this->receiptWith(['VLASTNI' => true]);
        $foreignLine = $this->receiptWith(['CIZI-RADEK' => false])[1]['CIZI-RADEK']['purchase_invoice_item_id'];
        $doc = $this->docsRepo->findWithLines($this->sid, $docId);
        $header = ['doc_type' => 'receipt', 'warehouse_id' => $doc['warehouse_id'], 'doc_date' => $doc['doc_date'], 'description' => 'Příjem'];
        $line = ['stock_item_id' => $card, 'qty' => '1.000', 'unit_cost' => '10', 'purchase_invoice_item_id' => $foreignLine, 'fill_intrastat' => true];

        foreach ([
            'cizí řádek ke konceptu z faktury' => fn () => $this->documents->updateDraft($this->sid, $docId, $header + ['lines' => [$line]], $this->userId),
            'ruční příjemka bez faktury'      => fn () => $this->documents->create($this->sid, $header + ['lines' => [$line]], $this->userId),
            'řádek bez vazby na fakturu'      => fn () => $this->documents->create($this->sid, $header + ['purchase_invoice_id' => $doc['purchase_invoice_id'], 'lines' => [['purchase_invoice_item_id' => null] + $line]], $this->userId),
        ] as $case => $call) {
            try {
                $call();
                self::fail($case . ': musí se odmítnout.');
            } catch (\MyInvoice\Service\Stock\StockException $e) {
                self::assertSame(['invalid_document', 422], [$e->errorCode, $e->httpStatus], $case);
            }
        }
        self::assertSame($doc['lines'][0]['purchase_invoice_item_id'], $this->docsRepo->findWithLines($this->sid, $docId)['lines'][0]['purchase_invoice_item_id']);

        // Obrana i při zaúčtování: řádek přepojený mimo API na cizí fakturu se nedoplní.
        $this->db->pdo()->prepare('UPDATE stock_document_lines SET purchase_invoice_item_id = ? WHERE document_id = ?')->execute([$foreignLine, $docId]);
        $this->documents->post($this->sid, $docId, $this->userId);
        self::assertSame([], $this->receipts->fillIntrastatAfterPost($this->sid, $docId, true, $this->userId));
        self::assertNull($this->card($card)['intrastat_cn8_code']);
        self::assertNull($this->card($foreignCard)['intrastat_cn8_code']);
    }

    public function testDeletedDraftLeavesCardUntouched(): void
    {
        $card = $this->item($this->sid, 'SMAZANY');
        $before = $this->card($card);
        [$docId] = $this->receiptWith(['SMAZANY' => true]);
        self::assertSame($before, $this->card($card));

        $this->documents->deleteDraft($this->sid, $docId, $this->userId);
        self::assertSame($before, $this->card($card));
        self::assertSame([], $this->activity('stock.item_intrastat_filled', $card));
    }

    public function testChoiceWithoutCardPermissionIsRejected(): void
    {
        $card = $this->item($this->sid, 'ODMITNUTI');
        $byCode = $this->receiptWith(['ODMITNUTI' => false])[1];
        $piLine = $byCode['ODMITNUTI']['purchase_invoice_item_id'];
        $piId = (int) $this->db->pdo()->query('SELECT purchase_invoice_id FROM purchase_invoice_items WHERE id = ' . (int) $piLine)->fetchColumn();
        $receiptsBefore = count($this->docsRepo->listByPurchaseInvoice($this->sid, $piId));

        try {
            $this->receipts->createReceipt($this->sid, $piId, [
                'warehouse_id' => $this->warehouse($this->sid, 'W' . uniqid()),
                'doc_date'     => '2099-01-10',
                'lines'        => [['purchase_invoice_item_id' => $piLine, 'quantity' => '1.000', 'fill_intrastat' => true]],
            ], $this->userId);
            self::fail('Bez práva upravovat karty se volba musí odmítnout.');
        } catch (\MyInvoice\Service\Stock\StockException $e) {
            self::assertSame(['card_write_forbidden', 403], [$e->errorCode, $e->httpStatus]);
        }
        self::assertCount($receiptsBefore, $this->docsRepo->listByPurchaseInvoice($this->sid, $piId), 'Odmítnutí nesmí nechat koncept.');
        self::assertNull($this->card($card)['intrastat_cn8_code']);
    }

    public function testDocumentApiRejectsNewChoiceWithoutCardPermissionButKeepsExisting(): void
    {
        $card = $this->item($this->sid, 'EDITOR');
        $other = $this->item($this->sid, 'EDITOR-JINA');
        [$docId] = $this->receiptWith(['EDITOR' => true]);
        $doc = $this->docsRepo->findWithLines($this->sid, $docId);
        $line = $doc['lines'][0];
        self::assertSame($card, (int) $line['stock_item_id']);

        $call = function (?int $id, array $lineBody, array $permissions) use ($doc): array {
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/stock/documents')
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
                ->withAttribute('auth.effective_role', new \MyInvoice\Security\EffectiveRole(0, 'Skladník', 'staff', true, $permissions))
                ->withParsedBody([
                    'doc_type' => 'receipt', 'warehouse_id' => $doc['warehouse_id'], 'doc_date' => $doc['doc_date'],
                    'description' => 'Příjem', 'lines' => [$lineBody],
                ]);
            $action = $this->container->get(\MyInvoice\Action\Stock\StockDocumentAction::class);
            $response = $id === null
                ? $action->create($request, new \Slim\Psr7\Response())
                : $action->update($request, new \Slim\Psr7\Response(), ['id' => (string) $id]);
            return [$response->getStatusCode(), (array) json_decode((string) $response->getBody(), true)];
        };
        $body = static fn (int $stockItem, bool $choice): array => [
            'stock_item_id' => $stockItem, 'qty' => $line['qty'], 'unit_cost' => $line['unit_cost'],
            'purchase_invoice_item_id' => $line['purchase_invoice_item_id'], 'fill_intrastat' => $choice,
        ];
        $noRight = ['stock' => 2, 'accounting' => 2];
        $right = $noRight + ['stock.items.write' => 2];

        [$status, $denied] = $call(null, $body($card, true), $noRight);
        self::assertSame(403, $status);
        self::assertSame('stock.error.card_write_forbidden', $denied['error']['code']);

        [$status] = $call($docId, $body($other, true), $noRight);
        self::assertSame(403, $status, 'Bez práva nejde volbu přesunout na jinou kartu.');
        self::assertSame($card, (int) $this->docsRepo->findWithLines($this->sid, $docId)['lines'][0]['stock_item_id']);

        [$status] = $call($docId, $body($card, true), $noRight);
        self::assertSame(200, $status, 'Existující volbu lze bez práva ponechat.');
        self::assertTrue($this->docsRepo->findWithLines($this->sid, $docId)['lines'][0]['fill_intrastat']);

        [$status] = $call($docId, $body($card, false), $noRight);
        self::assertSame(200, $status, 'Volbu lze bez práva zrušit.');
        self::assertFalse($this->docsRepo->findWithLines($this->sid, $docId)['lines'][0]['fill_intrastat']);

        [$status] = $call($docId, $body($card, true), $noRight);
        self::assertSame(403, $status, 'Zrušenou volbu už bez práva znovu nastavit nejde.');

        [$status] = $call($docId, $body($card, true), $right);
        self::assertSame(200, $status);
        self::assertTrue($this->docsRepo->findWithLines($this->sid, $docId)['lines'][0]['fill_intrastat']);
    }

    public function testChoiceSurvivesDraftEditOnlyWhenSentAgain(): void
    {
        $this->item($this->sid, 'UPRAVA');
        [$docId] = $this->receiptWith(['UPRAVA' => true]);
        $doc = $this->docsRepo->findWithLines($this->sid, $docId);
        self::assertTrue($doc['lines'][0]['fill_intrastat']);

        $line = static fn (array $l, array $extra): array => $extra + [
            'stock_item_id' => $l['stock_item_id'], 'qty' => $l['qty'], 'unit_cost' => $l['unit_cost'], 'purchase_invoice_item_id' => $l['purchase_invoice_item_id'],
        ];
        $header = ['doc_type' => 'receipt', 'warehouse_id' => $doc['warehouse_id'], 'doc_date' => $doc['doc_date']];
        $this->documents->updateDraft($this->sid, $docId, $header + ['lines' => [$line($doc['lines'][0], ['fill_intrastat' => true])]], $this->userId);
        self::assertTrue($this->docsRepo->findWithLines($this->sid, $docId)['lines'][0]['fill_intrastat']);

        $this->documents->updateDraft($this->sid, $docId, $header + ['lines' => [$line($doc['lines'][0], [])]], $this->userId);
        self::assertFalse($this->docsRepo->findWithLines($this->sid, $docId)['lines'][0]['fill_intrastat']);
    }

    public function testUnknownOriginCountryDoesNotReachTheCard(): void
    {
        $card = $this->item($this->sid, 'ZEME');
        $changes = $this->container->get(\MyInvoice\Repository\StockItemRepository::class)->fillMissingIntrastat($this->sid, $card, '73181639', 'XX');
        self::assertSame(['intrastat_cn8_code'], array_keys($changes));
        self::assertSame(['73181639', null], [$this->card($card)['intrastat_cn8_code'], $this->card($card)['intrastat_country_of_origin']]);
    }

    public function testInvoiceLineKeepsOnlyValidIntrastatValues(): void
    {
        $cases = [
            ['7318.16.39', 'it', '73181639', 'IT'],
            [' 73181639 ', ' CZ ', '73181639', 'CZ'],
            ['7318 16 39', 'IT', null, 'IT'],
            ['NOIN.TR.A', 'IT', null, 'IT'],
            ['7318.16.3X', 'IT', null, 'IT'],
            ['73181639', 'XX', '73181639', null],
            ['7318', 'Italy', null, null],
            ['HS 73181639', 'I', null, null],
            ['1234-56-78', null, null, null],
            ['73181639 kg', 'IT', null, 'IT'],
            ['123456789', 'IT', null, 'IT'],
        ];
        $repo = $this->container->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class);
        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $repo->replaceItems($piId, array_map(fn (array $c): array => [
            'description' => 'A', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 10, 'vat_rate_id' => $this->vatRateId,
            'intrastat_cn8_code' => $c[0], 'intrastat_country_of_origin' => $c[1],
        ], $cases));
        foreach ($repo->itemsFor($piId) as $i => $row) {
            self::assertSame([$cases[$i][2], $cases[$i][3]], [$row['intrastat_cn8_code'], $row['intrastat_country_of_origin']], (string) $cases[$i][0]);
        }
    }

    public function testPackagingRatioAppliesToMatchedLine(): void
    {
        $wh = $this->warehouse($this->sid);
        $card = $this->item($this->sid, 'BALENE');
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_units (supplier_id, stock_item_id, unit_code, numerator, denominator, is_sales_unit) VALUES (?, ?, ?, 8, 1, 1)'
        )->execute([$this->sid, $card, 'KT']);
        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $lineId = $this->line($piId, 'BALENE', null, '2.000');
        $this->db->pdo()->prepare("UPDATE purchase_invoice_items SET unit = 'KT' WHERE id = ?")->execute([$lineId]);

        $line = array_column($this->receipts->proposeForPurchaseInvoice($this->sid, $piId)['unmatched_lines'], null, 'purchase_invoice_item_id')[$lineId];
        self::assertSame([$card, '16.000'], [$line['matched_stock_item_id'], $line['remaining_qty']], 'Návrh přepočte balení podle navržené karty.');

        $doc = $this->receipts->createReceipt($this->sid, $piId, [
            'warehouse_id' => $wh,
            'doc_date'     => '2099-01-10',
            'lines'        => [['purchase_invoice_item_id' => $lineId, 'stock_item_id' => $card, 'quantity' => '16.000']],
        ], $this->userId);
        self::assertSame('16.000', number_format((float) $doc['lines'][0]['qty'], 3, '.', ''));
    }

    public function testPriceFollowsTheChosenCardAndManualPriceIsAccepted(): void
    {
        $wh = $this->warehouse($this->sid);
        $units = $this->db->pdo()->prepare(
            'INSERT INTO stock_item_units (supplier_id, stock_item_id, unit_code, numerator, denominator, is_sales_unit) VALUES (?, ?, ?, 10, 1, 1)'
        );
        $boxOfTen = $this->item($this->sid, 'KRABICE-10');
        $units->execute([$this->sid, $boxOfTen, 'KT']);
        $single = $this->item($this->sid, 'KUS');
        $receive = fn (int $piId, int $lineId, int $card, string $qty, ?string $unitCost): array => $this->receipts->createReceipt($this->sid, $piId, [
            'warehouse_id' => $wh,
            'doc_date'     => '2099-01-10',
            'lines'        => [['purchase_invoice_item_id' => $lineId, 'stock_item_id' => $card, 'quantity' => $qty] + ($unitCost === null ? [] : ['unit_cost' => $unitCost])],
        ], $this->userId);

        // Návrh karty po 10 (5 KT za 4 000 = 50 ks po 80), člověk zvolí kartu 1:1.
        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $lineId = $this->line($piId, 'KRABICE-10', null, '5.000');
        $this->db->pdo()->prepare("UPDATE purchase_invoice_items SET unit = 'KT', unit_price_without_vat = 800, total_without_vat = 4000, total_with_vat = 4000 WHERE id = ?")->execute([$lineId]);
        $proposed = array_column($this->receipts->proposeForPurchaseInvoice($this->sid, $piId)['unmatched_lines'], null, 'purchase_invoice_item_id')[$lineId];
        self::assertSame(['50.000', '80.000000'], [$proposed['remaining_qty'], $proposed['unit_cost']]);
        $recalculated = array_column($this->receipts->proposeForPurchaseInvoice($this->sid, $piId, [$lineId => $single])['lines'], null, 'purchase_invoice_item_id')[$lineId];
        self::assertSame([$single, '5.000', '800.000000'], [$recalculated['stock_item_id'], $recalculated['remaining_qty'], $recalculated['unit_cost']], 'Návrh pro vybranou kartu.');

        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/purchase-invoices/' . $piId . '/stock-receipt')
            ->withQueryParams(['stock_item_ids' => [(string) $lineId => (string) $single, 'x' => 'y']])
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid);
        $response = $this->container->get(\MyInvoice\Action\Stock\StockReceiptAction::class)
            ->propose($request, new \Slim\Psr7\Response(), ['id' => (string) $piId]);
        $body = json_decode((string) $response->getBody(), true);
        $viaApi = array_column($body['lines'] ?? $body['data']['lines'], null, 'purchase_invoice_item_id')[$lineId];
        self::assertSame('800.000000', $viaApi['unit_cost'], 'Přepočet jde i přes API.');

        $doc = $receive($piId, $lineId, $single, '2.000', null);
        self::assertSame('800.000000', $doc['lines'][0]['unit_cost'], 'Bez ceny ji server spočítá podle zvolené karty.');
        $doc = $receive($piId, $lineId, $single, '1.000', '650.5');
        self::assertSame('650.500000', $doc['lines'][0]['unit_cost'], 'Ruční cena se přijme jako dřív.');
    }

    public function testPartialReceiptMustStayOnTheSameCard(): void
    {
        $wh = $this->warehouse($this->sid);
        $first = $this->item($this->sid, 'CAST-PRVNI');
        $other = $this->item($this->sid, 'CAST-JINA');
        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $lineId = $this->line($piId, 'CASTECNE', null, '4.000');
        $receive = fn (int $card): array => $this->receipts->createReceipt($this->sid, $piId, [
            'warehouse_id' => $wh,
            'doc_date'     => '2099-01-10',
            'lines'        => [['purchase_invoice_item_id' => $lineId, 'stock_item_id' => $card, 'quantity' => '1.000']],
        ], $this->userId);

        $draft = $receive($first);
        foreach ([false, true] as $posted) {
            if ($posted) {
                $this->documents->post($this->sid, (int) $draft['id'], $this->userId);
            }
            try {
                $receive($other);
                self::fail('Zbytek řádku na jinou kartu se musí odmítnout.');
            } catch (\MyInvoice\Service\Stock\StockException $e) {
                self::assertSame(['receipt_card_mismatch', 409, [$first]], [$e->errorCode, $e->httpStatus, $e->details['stock_item_ids']]);
            }
        }
        self::assertSame($first, (int) $receive($first)['lines'][0]['stock_item_id'], 'Na stejnou kartu zbytek projde.');
    }

    public function testOtherCardThanSuggestionUsesItsOwnPackaging(): void
    {
        $wh = $this->warehouse($this->sid);
        $units = $this->db->pdo()->prepare(
            'INSERT INTO stock_item_units (supplier_id, stock_item_id, unit_code, numerator, denominator, is_sales_unit) VALUES (?, ?, ?, ?, 1, 1)'
        );
        $suggested = $this->item($this->sid, 'NAVRH-BAL');
        $units->execute([$this->sid, $suggested, 'KT', 10]);
        $plain = $this->item($this->sid, 'BEZ-BALENI');
        $byFour = $this->item($this->sid, 'PO-CTYRECH');
        $units->execute([$this->sid, $byFour, 'KT', 4]);
        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $lineId = $this->line($piId, 'NAVRH-BAL', null, '2.000');
        $this->db->pdo()->prepare("UPDATE purchase_invoice_items SET unit = 'KT' WHERE id = ?")->execute([$lineId]);
        $line = array_column($this->receipts->proposeForPurchaseInvoice($this->sid, $piId)['unmatched_lines'], null, 'purchase_invoice_item_id')[$lineId];
        self::assertSame([$suggested, '20.000'], [$line['matched_stock_item_id'], $line['remaining_qty']]);
        $receive = fn (int $card, string $qty): array => $this->receipts->createReceipt($this->sid, $piId, [
            'warehouse_id' => $wh,
            'doc_date'     => '2099-01-10',
            'lines'        => [['purchase_invoice_item_id' => $lineId, 'stock_item_id' => $card, 'quantity' => $qty]],
        ], $this->userId);

        try {
            $receive($plain, '20.000');
            self::fail('Karta bez balení nesmí převzít převod navržené karty.');
        } catch (\MyInvoice\Service\Stock\StockException $e) {
            self::assertSame(['over_receipt', '2.000'], [$e->errorCode, $e->details['remaining']]);
        }
        try {
            $receive($byFour, '20.000');
            self::fail('Karta s vlastním balením se přepočte podle sebe.');
        } catch (\MyInvoice\Service\Stock\StockException $e) {
            self::assertSame(['over_receipt', '8.000'], [$e->errorCode, $e->details['remaining']]);
        }
        $doc = $receive($byFour, '8.000');
        self::assertSame('8.000', number_format((float) $doc['lines'][0]['qty'], 3, '.', ''));
    }

    /**
     * Koncept příjemky z faktury, jejíž řádky mají KN8 a zemi. Klíč = kód karty, hodnota = volba `fill_intrastat`.
     *
     * @param array<string,mixed> $choices
     * @return array{0:int,1:array<string,array<string,mixed>>} id dokladu a řádky návrhu podle kódu
     */
    private function receiptWith(array $choices): array
    {
        $piId = $this->purchaseInvoice($this->sid, $this->vendorId);
        $this->container->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class)->replaceItems($piId, array_map(fn (string $code): array => [
            'description' => $code, 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 10, 'vat_rate_id' => $this->vatRateId,
            'seller_item_code' => $code, 'intrastat_cn8_code' => '7318.16.39', 'intrastat_country_of_origin' => 'it',
        ], array_keys($choices)));
        $proposal = $this->receipts->proposeForPurchaseInvoice($this->sid, $piId);
        $byCode = array_column([...$proposal['lines'], ...$proposal['unmatched_lines']], null, 'seller_item_code');
        $doc = $this->receipts->createReceipt($this->sid, $piId, [
            'warehouse_id' => $this->warehouse($this->sid, 'W' . uniqid()),
            'doc_date'     => '2099-01-10',
            'lines'        => array_map(static fn (string $code, mixed $choice): array => [
                'purchase_invoice_item_id' => $byCode[$code]['purchase_invoice_item_id'], 'quantity' => '1.000', 'fill_intrastat' => $choice,
                'stock_item_id' => $byCode[$code]['matched_stock_item_id'],
            ], array_keys($choices), $choices),
        ], $this->userId, true);
        return [(int) $doc['id'], $byCode];
    }

    /** @return list<array<string,mixed>> payloady záznamů logu aktivit */
    private function activity(string $action, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log WHERE supplier_id = ? AND action = ? AND entity_type = 'stock_item' AND entity_id = ? ORDER BY id"
        );
        $stmt->execute([$this->sid, $action, $stockItemId]);
        return array_map(static fn (string $json): array => (array) json_decode($json, true), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed> */
    private function card(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT intrastat_cn8_code, intrastat_country_of_origin, row_version FROM stock_items WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function line(int $piId, ?string $code, ?string $ean, string $qty = '1.000'): int
    {
        $id = $this->purchaseInvoiceItem($piId, null, $qty);
        $this->setCodes($id, $code, $ean);
        return $id;
    }

    private function setCodes(int $lineId, ?string $code, ?string $ean): void
    {
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET seller_item_code = ?, item_ean = ? WHERE id = ?')->execute([$code, $ean, $lineId]);
    }

    private function setEan(int $itemId, string $ean): void
    {
        $this->db->pdo()->prepare('UPDATE stock_items SET ean = ? WHERE id = ?')->execute([$ean, $itemId]);
    }

    /**
     * @param array<string,mixed> $proposal
     * @return array<int,array<string,mixed>>
     */
    private function linesById(array $proposal): array
    {
        return array_column($proposal['lines'], null, 'purchase_invoice_item_id');
    }
}
