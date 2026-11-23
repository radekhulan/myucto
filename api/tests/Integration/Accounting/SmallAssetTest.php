<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Reports\SmallAssetReportAction;
use MyInvoice\Action\Accounting\SmallAssetAction;
use MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\SmallAssetRepository;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * §DM — karty evidence drobného majetku a jejich sestavy (migrace 1094).
 *
 * Co je tu v sázce: soupis k datu je podklad, který účetní podepisuje k inventarizaci
 * (§28/5 ZoÚ). Karta cizí firmy v našem soupisu = doložený majetek, který nemáme, a
 * naopak. Tenant izolace proto není hygiena, ale funkční požadavek — testuje se na
 * repozitáři, ve službě i přes API včetně sestav.
 */
#[Group('integration')]
final class SmallAssetTest extends BankPostingTestCase
{
    private SmallAssetRepository $repo;
    private SmallAssetService $cardService;
    private SmallAssetAction $action;
    private SmallAssetReportAction $reports;
    private TransitionPurchaseInvoiceStatusAction $transition;
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->container->get(SmallAssetRepository::class);
        $this->cardService = $this->container->get(SmallAssetService::class);
        $this->action = $this->container->get(SmallAssetAction::class);
        $this->reports = $this->container->get(SmallAssetReportAction::class);
        $this->transition = $this->container->get(TransitionPurchaseInvoiceStatusAction::class);
        $this->vatRateId = (int) ($this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí vat_rates v DB.');
        }
    }

    // ── repozitář ────────────────────────────────────────────────────────────

    public function testCrudRoundTrip(): void
    {
        $id = $this->card(['name' => 'Kávovar do kuchyňky', 'price' => 20652.89, 'location' => 'Kuchyňka']);

        $row = $this->repo->find($this->supplierId, $id);
        self::assertNotNull($row);
        self::assertSame('Kávovar do kuchyňky', $row['name']);
        self::assertSame(20652.89, $row['price']);
        self::assertSame('in_use', $row['status']);
        self::assertNull($row['disposed_at']);

        self::assertTrue($this->repo->update($this->supplierId, $id, ['location' => 'Zasedačka']));
        self::assertSame('Zasedačka', $this->repo->find($this->supplierId, $id)['location']);

        self::assertTrue($this->repo->delete($this->supplierId, $id));
        self::assertNull($this->repo->find($this->supplierId, $id));
    }

    /** DB pojistka chk_sma_disposal: vyřazená karta bez data vyřazení nejde vykázat v úbytcích. */
    public function testDatabaseRejectsDisposedCardWithoutDate(): void
    {
        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'INSERT INTO small_assets (supplier_id, name, acquisition_date, price, status)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'Bez data', self::YEAR . '-01-01', 1000.0, 'disposed']);
    }

    /** DB pojistka chk_sma_disposal_after: vyřadit dřív, než se to koupilo, nejde. */
    public function testDatabaseRejectsDisposalBeforeAcquisition(): void
    {
        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'INSERT INTO small_assets (supplier_id, name, acquisition_date, price, status, disposed_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'Zpětně', self::YEAR . '-06-01', 1000.0, 'disposed', self::YEAR . '-01-01']);
    }

    // ── tenant izolace ───────────────────────────────────────────────────────

    public function testCardOfOtherSupplierIsNeitherVisibleNorWritable(): void
    {
        $otherId = $this->otherSupplierId();
        $foreign = $this->card(['name' => 'Cizí notebook', 'price' => 45000.0], $otherId);

        self::assertNull($this->repo->find($this->supplierId, $foreign), 'find() nesmí vidět přes hranici firmy.');
        self::assertFalse($this->repo->update($this->supplierId, $foreign, ['name' => 'Přepis']));
        self::assertFalse($this->repo->delete($this->supplierId, $foreign));
        self::assertNotNull($this->repo->find($otherId, $foreign), 'Cizí karta zůstala nedotčená.');
    }

    public function testApiListDoesNotLeakOtherSupplierCards(): void
    {
        $foreign = $this->card(['name' => 'Cizí notebook'], $this->otherSupplierId());
        $own = $this->card(['name' => 'Náš kávovar']);

        $res = $this->callAction($this->action, 'list', 'GET', 'accountant');

        self::assertSame(200, $res['status']);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $res['body']['items']);
        self::assertContains($own, $ids);
        self::assertNotContains($foreign, $ids);
    }

    public function testApiGetUpdateAndDeleteOfForeignCardReturn404(): void
    {
        $foreign = $this->card(['name' => 'Cizí'], $this->otherSupplierId());

        foreach ([['get', 'GET'], ['update', 'PUT'], ['delete', 'DELETE']] as [$method, $http]) {
            $res = $this->callAction($this->action, $method, $http, 'accountant', ['name' => 'Přepis'], ['id' => (string) $foreign]);
            self::assertSame(404, $res['status'], "Cizí karta nesmí být dostupná přes {$method}.");
        }
        self::assertNotNull($this->repo->find($this->otherSupplierId(), $foreign), 'Cizí karta přežila.');
    }

    /**
     * Nejdůležitější test celé evidence: soupis k inventarizaci nesmí obsahovat cizí
     * majetek. Kdyby ano, účetní podepíše, že firma vlastní věci, které nemá.
     */
    public function testInventoryReportNeverIncludesOtherSuppliersCards(): void
    {
        // Baseline PŘED: tenant dev DB nese i reálné karty (§DM backfill), takže „total = 20652,89"
        // by testovalo prázdnou databázi, ne izolaci tenanta. Ověřujeme DELTU: cizí karta soupis
        // nezvedne, vlastní ano přesně o svou cenu.
        $before = (float) $this->callQuery('inventory', ['as_of' => self::YEAR . '-12-31'])['body']['total'];

        $otherId = $this->otherSupplierId();
        $this->card(['name' => 'Cizí kávovar', 'price' => 99999.0], $otherId);
        $this->card(['name' => 'Náš kávovar', 'price' => 20652.89]);

        $res = $this->callQuery('inventory', ['as_of' => self::YEAR . '-12-31']);

        self::assertSame(200, $res['status']);
        $names = $this->inventoryNames($res['body']);
        self::assertContains('Náš kávovar', $names);
        self::assertNotContains('Cizí kávovar', $names);
        self::assertEqualsWithDelta($before + 20652.89, (float) $res['body']['total'], 0.001,
            'Soupis se zvedne jen o vlastní kartu, cizí majetek do něj nesmí.');
    }

    public function testApiRejectsSourceInvoiceOfOtherSupplier(): void
    {
        $foreignInvoice = $this->purchaseWithItems('PF-CIZI', null, [['Notebook', 45000.0, 'small_asset']], $this->otherSupplierId());

        $res = $this->callAction($this->action, 'create', 'POST', 'accountant', [
            'name' => 'Přes hranici',
            'acquisition_date' => self::YEAR . '-06-10',
            'price' => 45000.0,
            'purchase_invoice_id' => $foreignInvoice,
        ]);

        self::assertSame(422, $res['status']);
        self::assertSame('source_not_found', $res['body']['error']['code']);
    }

    public function testGenerateIgnoresOtherSuppliersInvoice(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $pf = $this->purchaseWithItems('PF-GEN-FOREIGN', $vendorId, [['Notebook Dell', 45000.0, 'small_asset']]);

        $res = $this->callAction($this->action, 'generateFromPurchaseInvoice', 'POST', 'accountant', [], ['id' => (string) $pf]);
        self::assertSame(201, $res['status'], 'Vlastní doklad projde.');

        // Cizí tenant na tentýž doklad nedosáhne.
        self::assertSame([], $this->repo->forPurchaseInvoice($this->otherSupplierId(), $pf));
    }

    // ── RBAC ─────────────────────────────────────────────────────────────────

    public function testClientRoleCannotWriteCards(): void
    {
        $id = $this->card(['name' => 'Kávovar']);
        $cases = [
            ['create', 'POST', []],
            ['update', 'PUT', ['id' => (string) $id]],
            ['dispose', 'POST', ['id' => (string) $id]],
            ['sell', 'POST', ['id' => (string) $id]],
            ['restore', 'POST', ['id' => (string) $id]],
            ['delete', 'DELETE', ['id' => (string) $id]],
        ];
        foreach ($cases as [$method, $http, $args]) {
            $res = $this->callAction($this->action, $method, $http, 'client', [
                'name' => 'Pokus', 'acquisition_date' => self::YEAR . '-01-01', 'price' => 100.0,
                'disposed_at' => self::YEAR . '-02-01',
            ], $args);
            self::assertSame(403, $res['status'], "Role client nesmí projít na {$method}.");
            self::assertSame('forbidden', $res['body']['error']['code']);
        }
        self::assertSame('in_use', $this->repo->find($this->supplierId, $id)['status'], 'Karta se nezměnila.');
    }

    // ── vyřazení ─────────────────────────────────────────────────────────────

    public function testDisposeAndRestoreRoundTrip(): void
    {
        $id = $this->card(['name' => 'Skartovač', 'acquisition_date' => self::YEAR . '-01-15', 'price' => 2270.90]);

        $res = $this->callAction($this->action, 'dispose', 'POST', 'accountant',
            ['disposed_at' => self::YEAR . '-11-30', 'disposal_reason' => 'Nefunkční, ekologická likvidace'],
            ['id' => (string) $id]);

        self::assertSame(200, $res['status']);
        $card = $this->repo->find($this->supplierId, $id);
        self::assertSame('disposed', $card['status']);
        self::assertSame(self::YEAR . '-11-30', $card['disposed_at']);
        self::assertSame('Nefunkční, ekologická likvidace', $card['disposal_reason']);

        // Druhé vyřazení už neprojde — jinak by úbytky za období vykázaly věc dvakrát.
        $again = $this->callAction($this->action, 'dispose', 'POST', 'accountant',
            ['disposed_at' => self::YEAR . '-12-01'], ['id' => (string) $id]);
        self::assertSame(422, $again['status']);
        self::assertSame('already_disposed', $again['body']['error']['code']);

        $restore = $this->callAction($this->action, 'restore', 'POST', 'accountant', [], ['id' => (string) $id]);
        self::assertSame(200, $restore['status']);
        $card = $this->repo->find($this->supplierId, $id);
        self::assertSame('in_use', $card['status']);
        self::assertNull($card['disposed_at'], 'chk_sma_disposal: in_use a datum vyřazení se vylučují.');
    }

    public function testDisposeBeforeAcquisitionIsRejected(): void
    {
        $id = $this->card(['name' => 'Monitor', 'acquisition_date' => self::YEAR . '-06-01']);

        $res = $this->callAction($this->action, 'dispose', 'POST', 'accountant',
            ['disposed_at' => self::YEAR . '-01-01'], ['id' => (string) $id]);

        self::assertSame(422, $res['status']);
        self::assertSame('disposal_before_acquisition', $res['body']['error']['code']);
    }

    // ── prodej ───────────────────────────────────────────────────────────────

    /**
     * Prodej drobného majetku = běžná vydaná faktura (výnos 642 + DPH); z KARTY se nic
     * neúčtuje (ZC=0, náklad na 501 padl při pořízení). Karta jen přejde do 'sold' a naváže
     * se na doklad prodeje — a tím zmizí ze soupisu k inventarizaci.
     */
    public function testSellLinksCardToInvoiceAndMovesToSold(): void
    {
        $id = $this->card(['name' => 'Notebook k prodeji', 'acquisition_date' => self::YEAR . '-01-15', 'price' => 20000.0]);
        $invoiceId = $this->issuedSaleInvoice('FV-SELL-1');

        $res = $this->callAction($this->action, 'sell', 'POST', 'accountant', [
            'sale_invoice_id' => $invoiceId,
            'sold_at' => self::YEAR . '-11-30',
            'sale_price' => 15000.0,
        ], ['id' => (string) $id]);

        self::assertSame(200, $res['status']);
        $card = $this->repo->find($this->supplierId, $id);
        self::assertSame('sold', $card['status']);
        self::assertSame($invoiceId, $card['sale_invoice_id']);
        self::assertSame(self::YEAR . '-11-30', $card['sold_at']);
        self::assertSame(15000.0, $card['sale_price']);
        self::assertNull($card['disposed_at'], 'Prodaná karta nemá disposed_at (chk_sma_disposal).');

        // Prodaná karta zmizí ze soupisu k datu — už není v užívání.
        $inv = $this->callQuery('inventory', ['as_of' => self::YEAR . '-12-31']);
        self::assertNotContains('Notebook k prodeji', $this->inventoryNames($inv['body']));

        // Restore vrátí do užívání a vynuluje prodejní pole.
        $restore = $this->callAction($this->action, 'restore', 'POST', 'accountant', [], ['id' => (string) $id]);
        self::assertSame(200, $restore['status']);
        $card = $this->repo->find($this->supplierId, $id);
        self::assertSame('in_use', $card['status']);
        self::assertNull($card['sale_invoice_id']);
        self::assertNull($card['sold_at']);
    }

    public function testSellRejectsInvoiceOfOtherSupplier(): void
    {
        $id = $this->card(['name' => 'Monitor', 'price' => 5000.0]);
        $foreignInvoice = $this->issuedSaleInvoice('FV-SELL-FOREIGN', $this->otherSupplierId());

        $res = $this->callAction($this->action, 'sell', 'POST', 'accountant', [
            'sale_invoice_id' => $foreignInvoice,
            'sold_at' => self::YEAR . '-06-30',
        ], ['id' => (string) $id]);

        self::assertSame(422, $res['status']);
        self::assertSame('sale_invoice_not_found', $res['body']['error']['code']);
        self::assertSame('in_use', $this->repo->find($this->supplierId, $id)['status'], 'Karta zůstala v užívání.');
    }

    public function testCannotSellAlreadyDisposedCard(): void
    {
        $id = $this->card(['name' => 'Skartovač', 'acquisition_date' => self::YEAR . '-01-15', 'price' => 2000.0]);
        $this->cardService->dispose($this->supplierId, $id, self::YEAR . '-06-30', 'Nefunkční');
        $invoiceId = $this->issuedSaleInvoice('FV-SELL-2');

        $res = $this->callAction($this->action, 'sell', 'POST', 'accountant', [
            'sale_invoice_id' => $invoiceId, 'sold_at' => self::YEAR . '-07-01',
        ], ['id' => (string) $id]);

        self::assertSame(422, $res['status']);
        self::assertSame('already_disposed', $res['body']['error']['code']);
    }

    /** Karta se nesmí dát založit rovnou jako vyřazená kolem pravidel v dispose(). */
    public function testCreateAlwaysStartsInUse(): void
    {
        $res = $this->callAction($this->action, 'create', 'POST', 'accountant', [
            'name' => 'Rovnou vyřazený', 'acquisition_date' => self::YEAR . '-01-01', 'price' => 500.0,
            'status' => 'disposed', 'disposed_at' => self::YEAR . '-01-02',
        ]);

        self::assertSame(201, $res['status']);
        self::assertSame('in_use', $res['body']['card']['status']);
        self::assertNull($res['body']['card']['disposed_at']);
    }

    // ── zdroj: faktura × pokladna × ruční ────────────────────────────────────

    /**
     * Nález z produkce: účetní má na 501.200 pokladní doklad za mobilní telefon
     * s protiúčtem 211.100 — nákup z POKLADNY, ne z faktury. Evidence to musí umět, jinak
     * ten telefon ze soupisu k inventarizaci vypadne.
     */
    public function testCashDocumentCanBeSourceOfCard(): void
    {
        $cashDocId = $this->cashDocument('VPD-2099-0003', 37386.78);

        $res = $this->callAction($this->action, 'create', 'POST', 'accountant', [
            'name' => 'Telefon Samsung',
            'acquisition_date' => self::YEAR . '-07-18',
            'price' => 37386.78,
            'cash_document_id' => $cashDocId,
            'document_ref' => 'VPD-2099-0003',
            'location' => 'Kancelář',
        ]);

        self::assertSame(201, $res['status']);
        $card = $res['body']['card'];
        self::assertSame($cashDocId, $card['cash_document_id']);
        self::assertNull($card['purchase_invoice_item_id'], 'Pokladní doklad nemá řádek faktury.');
        self::assertSame('VPD-2099-0003', $card['document_ref']);
    }

    /** Levný majetek po statisících kusů: cena za kus na šest míst, cena karty v haléřích. */
    public function testUnitPriceKeepsSixDecimalsAndCardPriceStaysOnCents(): void
    {
        $res = $this->callAction($this->action, 'create', 'POST', 'accountant', [
            'name' => 'Víčka', 'acquisition_date' => self::YEAR . '-02-01', 'quantity' => 250000, 'unit_price' => 0.004,
        ]);

        self::assertSame(201, $res['status']);
        self::assertSame(0.004, $res['body']['card']['unit_price']);
        self::assertSame(1000.0, (float) $res['body']['card']['price']);

        $tooPrecise = $this->callAction($this->action, 'create', 'POST', 'accountant', [
            'name' => 'Víčka', 'acquisition_date' => self::YEAR . '-02-01', 'quantity' => 1, 'unit_price' => '0.0000001',
        ]);
        self::assertSame(422, $tooPrecise['status']);
    }

    /** Karta bez dokladu je legální — majetek starší než aplikace, dar, vklad. */
    public function testManualCardWithoutAnySourceIsAllowed(): void
    {
        $res = $this->callAction($this->action, 'create', 'POST', 'accountant', [
            'name' => 'Regál ze zakladatelských dob',
            'acquisition_date' => '2019-03-01',
            'price' => 3500.0,
        ]);

        self::assertSame(201, $res['status']);
        self::assertNull($res['body']['card']['purchase_invoice_id']);
        self::assertNull($res['body']['card']['cash_document_id']);
    }

    /** Invariant „nejvýš jeden zdroj" vynucuje služba — CHECK ho v MariaDB mít nemůže (viz 1094). */
    public function testCardCannotHaveBothInvoiceItemAndCashDocumentSource(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $pf = $this->purchaseWithItems('PF-BOTH', $vendorId, [['Notebook', 45000.0, 'small_asset']]);
        $itemId = (int) $this->db->pdo()->query(
            "SELECT id FROM purchase_invoice_items WHERE purchase_invoice_id = {$pf} LIMIT 1"
        )->fetchColumn();

        $res = $this->callAction($this->action, 'create', 'POST', 'accountant', [
            'name' => 'Dva zdroje',
            'acquisition_date' => self::YEAR . '-06-10',
            'price' => 45000.0,
            'purchase_invoice_item_id' => $itemId,
            'cash_document_id' => $this->cashDocument('VPD-2099-0009', 45000.0),
        ]);

        self::assertSame(422, $res['status']);
        self::assertSame('multiple_sources', $res['body']['error']['code']);
    }

    // ── generování z dokladu ─────────────────────────────────────────────────

    public function testGenerateCreatesCardsOnlyForSmallAssetLines(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $pf = $this->purchaseWithItems('PF-GEN', $vendorId, [
            ['Notebook Dell', 45000.0, 'small_asset'],
            ['Brašna', 1200.0, 'material'],
            ['Doprava', 150.0, 'service'],
        ]);

        $res = $this->callAction($this->action, 'generateFromPurchaseInvoice', 'POST', 'accountant', [], ['id' => (string) $pf]);

        self::assertSame(201, $res['status']);
        self::assertSame(1, $res['body']['created'], 'Jen řádek small_asset zakládá kartu — materiál ani služba ne.');
        $card = $res['body']['cards'][0];
        self::assertSame('Notebook Dell', $card['name']);
        self::assertSame(45000.0, (float) $card['price']);
        self::assertSame($pf, $card['purchase_invoice_id']);
        self::assertSame('PF-GEN', $card['document_ref'], 'document_ref je snapshot čísla dokladu.');
        self::assertSame('Alza.cz a.s.', $card['vendor_name']);
    }

    /**
     * DDNM — drobný NEHMOTNÝ majetek (software, licence) patří do evidence stejně jako
     * hmotný, jen s jiným druhem karty. Do teď se do ní nedostal vůbec: druh výdaje pro
     * něj neexistoval, takže se licence zaúčtovala jako služba a nikde po ní nezůstala
     * stopa, přestože ji ÚJ musí evidovat a při inventarizaci doložit.
     *
     * Druh karty se rozlišuje proto, že inventarizace hmotného je fyzická, kdežto
     * u nehmotného se dokládá licenčním ujednáním — soupis, který obojí míchá, se nedá
     * použít ani pro jedno.
     */
    public function testSmallIntangibleAssetCreatesIntangibleCard(): void
    {
        $vendorId = $this->client('Software s.r.o.');
        $pf = $this->purchaseWithItems('PF-DDNM', $vendorId, [
            ['Licence CAD', 40000.0, 'small_intangible'],
            ['Notebook', 30000.0, 'small_asset'],
            ['Školení', 5000.0, 'service'],
        ]);

        $res = $this->callAction($this->action, 'generateFromPurchaseInvoice', 'POST', 'accountant', [], ['id' => (string) $pf]);

        self::assertSame(201, $res['status']);
        self::assertSame(2, $res['body']['created'], 'Karty zakládá hmotný i nehmotný drobný majetek, služba ne.');

        $kinds = [];
        foreach ($res['body']['cards'] as $c) {
            $stmt = $this->db->pdo()->prepare('SELECT asset_kind FROM small_assets WHERE id = ?');
            $stmt->execute([(int) $c['id']]);
            $kinds[(string) $c['name']] = (string) $stmt->fetchColumn();
        }

        self::assertSame('intangible', $kinds['Licence CAD']);
        self::assertSame('tangible', $kinds['Notebook'], 'Hmotný zůstává hmotný — chování se zpětně nemění.');
    }

    /**
     * Kontace DDNM míří na 518, ne na 501. Licence není spotřeba materiálu; sloučení
     * s hmotným by rozbilo druhové členění nákladů ve výsledovce.
     */
    public function testSmallIntangibleUsesServicesAccountNotMaterial(): void
    {
        $kind = \MyInvoice\Service\Accounting\Expense\ExpenseKind::SmallIntangible;

        self::assertSame('518', $kind->fallbackAccount());
        self::assertSame('invoice.small_intangible.received', $kind->ruleKey());
        self::assertTrue($kind->isSmallAssetEvidence(), 'DDNM patří do evidence drobného majetku.');
        self::assertSame('501', \MyInvoice\Service\Accounting\Expense\ExpenseKind::SmallAsset->fallbackAccount());
    }

    /**
     * REGRESE (nahlásil uživatel): ČÁSTEČNÝ dobropis vyřadí jen vrácenou věc, ne celý doklad.
     * PF 38 = switch + router, dobropis vrací jen switch. Vyřazení obou karet rodiče by
     * router omylem pohřbilo.
     */
    public function testPartialCreditNoteDisposesOnlyReturnedItem(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $pf = $this->purchaseWithItems('PF-PARTCN', $vendorId, [
            ['Switch QNAP', 3680.32, 'small_asset'],
            ['WiFi router', 10623.85, 'small_asset'],
        ]);
        $this->callAction($this->action, 'generateFromPurchaseInvoice', 'POST', 'accountant', [], ['id' => (string) $pf]);

        // Dobropis jen na switch, navázaný na původní fakturu.
        $cn = $this->creditNoteFor('CN-SWITCH', $vendorId, $pf, [['Switch QNAP', -3680.32, 'small_asset']]);
        $this->callAction($this->action, 'generateFromPurchaseInvoice', 'POST', 'accountant', [], ['id' => (string) $cn]);

        $cards = [];
        foreach ($this->repo->forPurchaseInvoice($this->supplierId, $pf) as $c) {
            $cards[$c['name']] = $c['status'];
        }
        self::assertSame('disposed', $cards['Switch QNAP'], 'Vrácený switch je vyřazený.');
        self::assertSame('in_use', $cards['WiFi router'], 'Nevrácený router MUSÍ zůstat v užívání.');
    }

    /** Proforma (zálohová faktura) NEzakládá karty — majetek přijde na finální faktuře. */
    public function testProformaDoesNotGenerateCards(): void
    {
        $vendorId = $this->client('M Computers s.r.o.');
        $proforma = $this->purchaseWithItems('PRO-LENOVO', $vendorId, [['Lenovo ThinkPad', 66033.06, 'small_asset']], null, 'advance');

        $res = $this->callAction($this->action, 'generateFromPurchaseInvoice', 'POST', 'accountant', [], ['id' => (string) $proforma]);

        self::assertSame(201, $res['status']);
        self::assertSame(0, $res['body']['created'], 'Proforma nezakládá kartu.');
        self::assertCount(0, $this->repo->forPurchaseInvoice($this->supplierId, $proforma));
    }

    public function testGenerateIsIdempotent(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $pf = $this->purchaseWithItems('PF-IDEM', $vendorId, [['Kávovar', 20652.89, 'small_asset']]);

        $first = $this->callAction($this->action, 'generateFromPurchaseInvoice', 'POST', 'accountant', [], ['id' => (string) $pf]);
        $second = $this->callAction($this->action, 'generateFromPurchaseInvoice', 'POST', 'accountant', [], ['id' => (string) $pf]);

        self::assertSame(1, $first['body']['created']);
        self::assertSame(0, $second['body']['created'], 'Druhé spuštění nesmí založit duplicitu.');
        self::assertSame(1, $second['body']['skipped']);
        self::assertCount(1, $this->repo->forPurchaseInvoice($this->supplierId, $pf));
    }

    /**
     * Idempotence stojí na přirozeném klíči (doklad + název + cena), ne na id řádku —
     * replaceItems() při každé editaci dokladu položky smaže a vloží s NOVÝMI id, takže
     * na id postavená idempotence by po editaci založila duplicity.
     */
    public function testGenerateStaysIdempotentAfterInvoiceItemsWereReplaced(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $pf = $this->purchaseWithItems('PF-REPLACE', $vendorId, [['Kávovar', 20652.89, 'small_asset']]);
        $this->callAction($this->action, 'generateFromPurchaseInvoice', 'POST', 'accountant', [], ['id' => (string) $pf]);

        // Simulace editace dokladu: replaceItems() = DELETE + INSERT s novým id.
        $this->db->pdo()->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$pf]);
        $this->insertItem($pf, 'Kávovar', 20652.89, 'small_asset', 0);

        $card = $this->repo->forPurchaseInvoice($this->supplierId, $pf)[0];
        self::assertNull($card['purchase_invoice_item_id'], 'ON DELETE SET NULL odpojil kartu od smazaného řádku.');
        self::assertSame($pf, $card['purchase_invoice_id'], 'Vazba na hlavičku dokladu editaci přežila.');

        $again = $this->callAction($this->action, 'generateFromPurchaseInvoice', 'POST', 'accountant', [], ['id' => (string) $pf]);
        self::assertSame(0, $again['body']['created'], 'Po editaci dokladu se karta nesmí zdvojit.');
        self::assertCount(1, $this->repo->forPurchaseInvoice($this->supplierId, $pf));
    }

    public function testGenerateForForeignInvoiceReturns404(): void
    {
        $res = $this->callAction($this->action, 'generateFromPurchaseInvoice', 'POST', 'accountant', [], ['id' => '999999999']);
        self::assertSame(404, $res['status']);
    }

    // ── zařazení při přijetí dokladu ─────────────────────────────────────────

    /**
     * Nahlášeno z produkce 2026-08-06: uživatel naimportoval ISDOC fakturu, označil na ní
     * položky za drobný majetek, uložil, přepnul na finální — a v evidenci nebylo nic.
     * Karta vznikla teprve tím, že šel do faktury podruhé a znovu uložil.
     *
     * Příčina: jediný hook byl v UpdatePurchaseInvoiceAction a ten na draftu záměrně mlčí.
     * ISDOC import zakládá fakturu vždy jako draft, takže klasifikace udělaná v draftu
     * neměla kdo promítnout — přechod na 'received' o evidenci nevěděl. Druhé uložení už
     * přijaté faktury navíc chce ?force=1 a roli admin, takže klientovi karta nevznikla
     * nikdy.
     */
    public function testTransitionToReceivedCreatesCardsClassifiedInDraft(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $pf = $this->purchaseWithItems('PF-DRAFT-DM', $vendorId, [
            ['Monitor Dell', 12000.0, 'small_asset'],
            ['Licence CAD', 40000.0, 'small_intangible'],
            ['Doprava', 150.0, 'service'],
        ]);
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET status = 'draft' WHERE id = ? AND supplier_id = ?"
        )->execute([$pf, $this->supplierId]);

        self::assertCount(0, $this->repo->forPurchaseInvoice($this->supplierId, $pf));

        $res = $this->callAction(
            $this->transition, '__invoke', 'POST', 'client',
            ['target' => 'received'], ['id' => (string) $pf],
        );
        self::assertSame(200, $res['status']);

        $names = array_map(
            static fn (array $c): string => (string) $c['name'],
            $this->repo->forPurchaseInvoice($this->supplierId, $pf),
        );
        sort($names);
        self::assertSame(
            ['Licence CAD', 'Monitor Dell'],
            $names,
            'Přijetí dokladu zařadí majetek klasifikovaný v draftu — bez druhého uložení.',
        );
    }

    /** Un-cancel (cancelled → received) prochází stejným hookem a nesmí kartu zdvojit. */
    public function testRepeatedReceivedTransitionDoesNotDuplicateCards(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $pf = $this->purchaseWithItems('PF-UNCANCEL-DM', $vendorId, [
            ['Monitor Dell', 12000.0, 'small_asset'],
        ]);
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET status = 'draft' WHERE id = ? AND supplier_id = ?"
        )->execute([$pf, $this->supplierId]);

        $this->callAction($this->transition, '__invoke', 'POST', 'client',
            ['target' => 'received'], ['id' => (string) $pf]);
        self::assertCount(1, $this->repo->forPurchaseInvoice($this->supplierId, $pf));

        $this->callAction($this->transition, '__invoke', 'POST', 'accountant',
            ['target' => 'cancelled'], ['id' => (string) $pf]);
        $this->callAction($this->transition, '__invoke', 'POST', 'client',
            ['target' => 'received'], ['id' => (string) $pf]);

        self::assertCount(
            1,
            $this->repo->forPurchaseInvoice($this->supplierId, $pf),
            'Opakované dosažení stavu received kartu neduplikuje.',
        );
    }

    /**
     * Úklidová fáze syncu se ptala jen na `small_asset`, kdežto zakládací na DDHM i DDNM.
     * Karta drobného NEhmotného majetku tak v témže běhu vznikla a hned se zase smazala —
     * u DDNM nepomohlo ani to druhé uložení, kterým si uživatel pomáhal.
     */
    public function testSyncKeepsIntangibleCardItJustCreated(): void
    {
        $vendorId = $this->client('Software s.r.o.');
        $pf = $this->purchaseWithItems('PF-SYNC-DDNM', $vendorId, [
            ['Licence CAD', 40000.0, 'small_intangible'],
            ['Notebook', 30000.0, 'small_asset'],
        ]);

        $first = $this->cardService->syncFromPurchaseInvoice($this->supplierId, $pf, $this->userId);
        self::assertCount(2, $first['created']);
        self::assertSame([], $first['pruned'], 'Úklid nesmí smazat kartu, kterou tentýž běh založil.');
        self::assertCount(2, $this->repo->forPurchaseInvoice($this->supplierId, $pf));

        $second = $this->cardService->syncFromPurchaseInvoice($this->supplierId, $pf, $this->userId);
        self::assertSame([], $second['created']);
        self::assertSame([], $second['pruned']);
        self::assertCount(
            2,
            $this->repo->forPurchaseInvoice($this->supplierId, $pf),
            'Opakovaný sync drží hmotnou i nehmotnou kartu.',
        );
    }

    /**
     * Karta je vždy v CZK — generate cenu násobí kurzem dokladu. Úklidová fáze ale
     * počítala klíče z ceny v původní měně, takže u EUR faktury držela 197,39 EUR,
     * kdežto právě založená karta 4 934,75 Kč: klíče se nepotkaly a sync kartu v témže
     * běhu smazal. U cizoměnového dokladu tak evidence nevznikla NIKDY — ani opakovaným
     * uložením, kterým si uživatel pomáhal. Vyplavalo z dry-runu dorovnávacího skriptu
     * nad ostrými daty (PF 43, faktura v EUR).
     */
    public function testSyncKeepsCardOnForeignCurrencyInvoice(): void
    {
        $vendorId = $this->client('Sonnet Technologies');
        $pf = $this->purchaseWithItems('PF-EUR-DM', $vendorId, [
            ['Sonnet Solo 10G Thunderbolt', 197.39, 'small_asset'],
        ]);
        $eurId = $this->currencyRow($this->supplierId, 'EUR');
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET currency_id = ?, exchange_rate = 25.00 WHERE id = ?'
        )->execute([$eurId, $pf]);

        $first = $this->cardService->syncFromPurchaseInvoice($this->supplierId, $pf, $this->userId);
        self::assertCount(1, $first['created']);
        self::assertSame([], $first['pruned'], 'Úklid nesmí smazat kartu, kterou tentýž běh založil.');

        $cards = $this->repo->forPurchaseInvoice($this->supplierId, $pf);
        self::assertCount(1, $cards);
        self::assertSame(4934.75, (float) $cards[0]['price'], 'Karta drží cenu v CZK (197,39 × 25).');

        $second = $this->cardService->syncFromPurchaseInvoice($this->supplierId, $pf, $this->userId);
        self::assertSame([], $second['created'], 'Opakovaný sync kartu nezdvojí…');
        self::assertSame([], $second['pruned'], '…ani nesmaže.');
        self::assertCount(1, $this->repo->forPurchaseInvoice($this->supplierId, $pf));
    }

    /** Úklid pořád musí zabrat, když položka přestane být majetkem. */
    public function testSyncStillPrunesCardWhoseItemStoppedBeingAsset(): void
    {
        $vendorId = $this->client('Software s.r.o.');
        $pf = $this->purchaseWithItems('PF-SYNC-PRUNE', $vendorId, [
            ['Licence CAD', 40000.0, 'small_intangible'],
        ]);
        $this->cardService->syncFromPurchaseInvoice($this->supplierId, $pf, $this->userId);
        self::assertCount(1, $this->repo->forPurchaseInvoice($this->supplierId, $pf));

        $this->db->pdo()->prepare(
            "UPDATE purchase_invoice_items SET expense_kind = 'service' WHERE purchase_invoice_id = ?"
        )->execute([$pf]);

        $res = $this->cardService->syncFromPurchaseInvoice($this->supplierId, $pf, $this->userId);
        self::assertCount(1, $res['pruned']);
        self::assertCount(0, $this->repo->forPurchaseInvoice($this->supplierId, $pf));
    }

    // ── sestavy ──────────────────────────────────────────────────────────────

    /**
     * Soupis k datu = stav ke KONCI rozhodného dne: co bylo k tomu dni pořízené a ještě
     * ne vyřazené. Věc vyřazená přesně k rozhodnému dni na soupisu být nemá.
     */
    public function testInventoryRespectsAsOfDate(): void
    {
        $old = $this->card(['name' => 'Starý monitor', 'acquisition_date' => self::YEAR . '-01-10', 'price' => 4000.0]);
        $this->card(['name' => 'Pozdější tablet', 'acquisition_date' => self::YEAR . '-09-01', 'price' => 13959.50]);
        $this->cardService->dispose($this->supplierId, $old, self::YEAR . '-06-30', 'Rozbitý');

        $mid = $this->callQuery('inventory', ['as_of' => self::YEAR . '-06-29']);
        self::assertContains('Starý monitor', $this->inventoryNames($mid['body']), 'Den před vyřazením ještě v soupisu je.');
        self::assertNotContains('Pozdější tablet', $this->inventoryNames($mid['body']), 'Ještě nepořízený tablet v soupisu není.');

        $onDisposal = $this->callQuery('inventory', ['as_of' => self::YEAR . '-06-30']);
        self::assertNotContains('Starý monitor', $this->inventoryNames($onDisposal['body']), 'V den vyřazení už v soupisu není.');

        // Přesný seznam „jen tablet" by testoval prázdnou DB — tenant má reálné karty (§DM).
        // Ověřujeme tedy chování VLASTNÍCH dvou karet, ne globální stav.
        $end = $this->inventoryNames($this->callQuery('inventory', ['as_of' => self::YEAR . '-12-31'])['body']);
        self::assertContains('Pozdější tablet', $end, 'Pořízený a nevyřazený tablet v soupisu je.');
        self::assertNotContains('Starý monitor', $end, 'Vyřazený monitor v soupisu k 31. 12. není.');
    }

    public function testInventoryGroupsByLocation(): void
    {
        // Unikátní umístění, ať se seskupení netýká reálných karet tenanta (§DM backfill).
        // Reálné karty mají location NULL, takže do těchhle dvou skupin nespadnou; globální
        // total ale ano, proto se ověřují jen SOUČTY VLASTNÍCH skupin, ne total.
        $kuchyn = 'Kuchyňka #' . self::YEAR;
        $kancl = 'Kancelář #' . self::YEAR;
        $this->card(['name' => 'Kávovar', 'price' => 20000.0, 'location' => $kuchyn]);
        $this->card(['name' => 'Monitor', 'price' => 5000.0, 'location' => $kancl]);
        $this->card(['name' => 'Tablet', 'price' => 1000.0, 'location' => $kancl]);

        $res = $this->callQuery('inventory', ['as_of' => self::YEAR . '-12-31']);

        $byLocation = [];
        foreach ($res['body']['groups'] as $group) {
            $byLocation[(string) $group['location']] = $group['total'];
        }
        self::assertSame(5000.0 + 1000.0, (float) $byLocation[$kancl]);
        self::assertSame(20000.0, (float) $byLocation[$kuchyn]);
    }

    public function testMovementsSplitsAdditionsAndDisposals(): void
    {
        $disposed = $this->card(['name' => 'Vyřazený skartovač', 'acquisition_date' => self::YEAR . '-02-01', 'price' => 2270.90]);
        $this->card(['name' => 'Nový kávovar', 'acquisition_date' => self::YEAR . '-07-05', 'price' => 20652.89]);
        $this->cardService->dispose($this->supplierId, $disposed, self::YEAR . '-08-15', 'Nefunkční');

        $res = $this->callQuery('movements', ['from' => self::YEAR . '-07-01', 'to' => self::YEAR . '-12-31']);

        self::assertSame(200, $res['status']);
        self::assertSame(1, $res['body']['additions_count'], 'Skartovač byl pořízen před rozsahem.');
        self::assertSame(20652.89, $res['body']['additions_total']);
        self::assertSame(1, $res['body']['disposals_count']);
        self::assertSame(2270.90, $res['body']['disposals_total']);
        self::assertSame('Vyřazený skartovač', $res['body']['disposals'][0]['name']);
    }

    /**
     * Rozpis 501 čte ŘÁDKY FAKTUR, ne karty — sestava má sedět na hlavní knihu, kde
     * náklad vznikl z expense_kind (1092), ne z evidence.
     */
    public function testExpenseBreakdownSplitsMaterialAndSmallAssetFromInvoiceLines(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $this->purchaseWithItems('PF-501', $vendorId, [
            ['Kávovar', 20652.89, 'small_asset'],
            ['PHM natural', 1500.0, 'material'],
            ['Hosting', 990.0, 'service'],
        ]);

        $res = $this->callQuery('expenseBreakdown', ['from' => self::YEAR . '-01-01', 'to' => self::YEAR . '-12-31']);

        self::assertSame(200, $res['status']);
        $byKind = [];
        foreach ($res['body']['groups'] as $group) {
            $byKind[(string) $group['expense_kind']] = $group;
        }
        self::assertSame(20652.89, (float) $byKind['small_asset']['total'], 'Odpovídá analytice účetní 501.200.');
        self::assertSame(1500.0, (float) $byKind['material']['total'], 'Odpovídá analytice účetní 501.100 (PHM je materiál).');
        self::assertSame(20652.89 + 1500.0, (float) $res['body']['total'], 'Služba na 518 do rozpisu 501 nepatří.');
        self::assertArrayNotHasKey('service', $byKind);
    }

    /** PHM je materiál, ale drobný majetek NENÍ — do evidence ani do 501.200 nesmí. */
    public function testExpenseBreakdownKeepsFuelOutOfSmallAssets(): void
    {
        $vendorId = $this->client('Autoservis s.r.o.');
        $this->purchaseWithItems('PF-PHM', $vendorId, [['phm natural 95', 2100.0, 'material']]);

        $res = $this->callQuery('expenseBreakdown', ['from' => self::YEAR . '-01-01', 'to' => self::YEAR . '-12-31']);

        $byKind = [];
        foreach ($res['body']['groups'] as $group) {
            $byKind[(string) $group['expense_kind']] = $group;
        }
        self::assertSame(2100.0, (float) $byKind['material']['total']);
        self::assertSame(0.0, (float) $byKind['small_asset']['total'], 'PHM nesmí spadnout mezi drobný majetek.');
    }

    /** Stornovaný doklad se neúčtuje, takže do rozpisu 501 nepatří — jinak by sestava lhala. */
    public function testExpenseBreakdownExcludesCancelledInvoices(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $pf = $this->purchaseWithItems('PF-STORNO', $vendorId, [['Kávovar', 20652.89, 'small_asset']]);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled' WHERE id = ?")->execute([$pf]);

        $res = $this->callQuery('expenseBreakdown', ['from' => self::YEAR . '-01-01', 'to' => self::YEAR . '-12-31']);

        self::assertSame(0.0, (float) $res['body']['total']);
    }

    public function testExpenseBreakdownNeverIncludesOtherSuppliersDocuments(): void
    {
        $this->purchaseWithItems('PF-CIZI-501', null, [['Cizí kávovar', 99999.0, 'small_asset']], $this->otherSupplierId());
        $vendorId = $this->client('Alza.cz a.s.');
        $this->purchaseWithItems('PF-NASE-501', $vendorId, [['Náš kávovar', 20652.89, 'small_asset']]);

        $res = $this->callQuery('expenseBreakdown', ['from' => self::YEAR . '-01-01', 'to' => self::YEAR . '-12-31']);

        self::assertSame(20652.89, (float) $res['body']['total'], 'Cizí doklad se nesmí započítat do našeho 501.');
    }

    // ── validace sestav a exportů ────────────────────────────────────────────

    public function testReportRejectsUnknownExportFormat(): void
    {
        $res = $this->callQuery('exportInventory', ['as_of' => self::YEAR . '-12-31', 'format' => 'csv']);

        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    public function testMovementsRequiresDateRange(): void
    {
        $res = $this->callQuery('movements', ['from' => self::YEAR . '-01-01']);

        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    public function testInventoryRejectsInvalidAsOfDate(): void
    {
        $res = $this->callQuery('inventory', ['as_of' => '31.12.2099']);

        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    /** Export musí opravdu vyrobit soubor — prázdný PDF/XLSX je tichá chyba až u účetní. */
    public function testInventoryExportProducesPdfAndXlsxBytes(): void
    {
        $this->card(['name' => 'Kávovar', 'price' => 20652.89, 'location' => 'Kuchyňka']);

        $pdf = $this->rawReport('exportInventory', ['as_of' => self::YEAR . '-12-31', 'format' => 'pdf']);
        self::assertSame(200, $pdf->getStatusCode());
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        $pdf->getBody()->rewind();
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody());

        $xlsx = $this->rawReport('exportInventory', ['as_of' => self::YEAR . '-12-31', 'format' => 'xlsx']);
        self::assertSame(200, $xlsx->getStatusCode());
        $xlsx->getBody()->rewind();
        self::assertStringStartsWith('PK', (string) $xlsx->getBody(), 'XLSX je ZIP.');
        self::assertStringContainsString('soupis-drobneho-majetku', $xlsx->getHeaderLine('Content-Disposition'));
    }

    public function testExpenseBreakdownExportProducesPdf(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $this->purchaseWithItems('PF-EXP', $vendorId, [['Kávovar', 20652.89, 'small_asset']]);

        $pdf = $this->rawReport('exportExpenseBreakdown', ['from' => self::YEAR . '-01-01', 'to' => self::YEAR . '-12-31', 'format' => 'pdf']);

        self::assertSame(200, $pdf->getStatusCode());
        $pdf->getBody()->rewind();
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody());
    }

    public function testMovementsExportProducesXlsx(): void
    {
        $this->card(['name' => 'Kávovar', 'acquisition_date' => self::YEAR . '-07-05', 'price' => 20652.89]);

        $xlsx = $this->rawReport('exportMovements', ['from' => self::YEAR . '-01-01', 'to' => self::YEAR . '-12-31', 'format' => 'xlsx']);

        self::assertSame(200, $xlsx->getStatusCode());
        $xlsx->getBody()->rewind();
        self::assertStringStartsWith('PK', (string) $xlsx->getBody());
    }

    // ── volání sestav ────────────────────────────────────────────────────────

    /**
     * Sestavy berou vstup z QUERY STRINGU, který sdílený callAction() neumí — proto
     * vlastní helper místo ohýbání základní třídy.
     *
     * @param array<string,string> $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function callQuery(string $method, array $query, string $role = 'accountant'): array
    {
        $resp = $this->rawReport($method, $query, $role);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /**
     * Syrová odpověď — u exportů je tělo binární (PDF/XLSX), takže se nedá dekódovat.
     *
     * @param array<string,string> $query
     */
    private function rawReport(string $method, array $query, string $role = 'accountant'): ResponseInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/reports/small-assets')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withQueryParams($query);

        return $this->reports->{$method}($req, new Psr7Response());
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    private function card(array $data, ?int $supplierId = null): int
    {
        return $this->repo->insert($supplierId ?? $this->supplierId, [
            'name' => $data['name'] ?? 'Drobný majetek',
            'acquisition_date' => $data['acquisition_date'] ?? self::YEAR . '-06-10',
            'quantity' => $data['quantity'] ?? 1,
            'unit_price' => $data['unit_price'] ?? ($data['price'] ?? 1000.0),
            'price' => $data['price'] ?? 1000.0,
            'location' => $data['location'] ?? null,
            'responsible_person' => $data['responsible_person'] ?? null,
            'document_ref' => $data['document_ref'] ?? null,
            'status' => 'in_use',
        ], $this->userId);
    }

    /** Minimální vydaná faktura (issued) — doklad prodeje pro sell(). */
    private function issuedSaleInvoice(string $varsymbol, ?int $supplierId = null): int
    {
        $supplierId ??= $this->supplierId;
        $clientId = $this->clientFor($supplierId, 'Odběratel ' . uniqid('c', false));
        $issue = self::YEAR . '-06-10';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, 1000.00, 210.00, 1210.00, "issued", "1", ?)'
        )->execute([$supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function cashDocument(string $number, float $amount): int
    {
        $pdo = $this->db->pdo();
        $registerId = (int) ($pdo->query(
            "SELECT id FROM cash_registers WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($registerId === 0) {
            $pdo->prepare(
                'INSERT INTO cash_registers (supplier_id, name, currency_code, account_code, is_default, is_active)
                 VALUES (?, ?, "CZK", "211", 1, 1)'
            )->execute([$this->supplierId, 'Pokladna ' . uniqid('t', false)]);
            $registerId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, description,
                 vat_mode, total_amount, status, created_by)
             VALUES (?, ?, "out", "purchase", ?, ?, ?, "none", ?, "posted", ?)'
        )->execute([$this->supplierId, $registerId, $number, self::YEAR . '-07-18',
            'Nákup za hotové', $amount, $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param list<array{0:string,1:float,2:?string}> $items [popis, cena za kus bez DPH, expense_kind]
     */
    private function purchaseWithItems(string $number, ?int $vendorId, array $items, ?int $supplierId = null, string $documentKind = 'invoice'): int
    {
        $supplierId ??= $this->supplierId;
        $vendorId ??= $this->clientFor($supplierId, 'Dodavatel ' . uniqid('v', false));
        $base = 0.0;
        foreach ($items as $it) {
            $base += $it[1];
        }
        $issue = self::YEAR . '-06-10';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", ?, "full", ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, "received", "40", ?)'
        )->execute([$supplierId, $vendorId, $number, $documentKind, $issue, $issue, $issue, $issue,
            $this->currencyId, round($base, 2), round($base, 2), $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();

        foreach (array_values($items) as $i => [$desc, $price, $kind]) {
            $this->insertItem($id, $desc, $price, $kind, $i);
        }
        return $id;
    }

    /**
     * Dobropis navázaný na původní fakturu přes parent_purchase_invoice_id (1096).
     * @param list<array{0:string,1:float,2:?string}> $items záporné částky
     */
    private function creditNoteFor(string $number, int $vendorId, int $parentId, array $items): int
    {
        $cn = $this->purchaseWithItems($number, $vendorId, $items, $this->supplierId, 'credit_note');
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$parentId, $cn, $this->supplierId]);
        return $cn;
    }

    private function insertItem(int $purchaseInvoiceId, string $desc, float $price, ?string $kind, int $order): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code, expense_kind)
             VALUES (?, ?, 1, 'ks', ?, ?, 21.00, ?, 0, ?, ?, '40', ?)"
        )->execute([$purchaseInvoiceId, $desc, $price, $this->vatRateId, $price, $price, $order, $kind]);
    }

    private function clientFor(int $supplierId, string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "t@example.com", "cs", ?, 1, 1)'
        );
        $stmt->execute([$supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $body
     * @return list<string>
     */
    private function inventoryNames(array $body): array
    {
        $names = [];
        foreach ($body['groups'] ?? [] as $group) {
            foreach ($group['rows'] ?? [] as $row) {
                $names[] = (string) $row['name'];
            }
        }
        return $names;
    }
}
