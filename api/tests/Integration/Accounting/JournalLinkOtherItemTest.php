<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Action\Accounting\JournalRelatedAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\JournalLinkService;
use MyInvoice\Service\Accounting\JournalRelatedAccess;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Panel „Souvisí“ pro ostatní pohledávky a závazky ({@see JournalLinkService}).
 *
 * Hrany: položka ↔ úhrada (nezrušené other_item_allocations, banka i pokladna),
 * položka ↔ faktura, kterou kryje (invoices.other_item_id), a položka ze zůstatku ↔
 * zápis, ve kterém leží její předpis (journal_settlement_allocations kind 'origin').
 * Položky vidí jen role s právem other_items, jejich úhrady jen s právem bank / cash
 * ({@see JournalRelatedAccess}). Odznak v seznamu deníku musí říkat totéž co panel.
 *
 * DB běží v transakci (rollback v tearDown).
 */
#[Group('integration')]
final class JournalLinkOtherItemTest extends BankPostingTestCase
{
    private JournalLinkService $links;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = $this->container->get(JournalLinkService::class);
    }

    public function testOtherItemEntryShowsBankAndCashPayments(): void
    {
        $item      = $this->otherItem('OP-SOUV-1', 1000.0);
        $itemEntry = $this->entry('other_item', $item, '378', '648', 1000.0);
        $tx        = $this->transaction($this->statement(), 600.0);
        $bankEntry = $this->entry('bank', $tx, '221', '378', 600.0);
        $cash      = $this->cashDocument(400.0);
        $cashEntry = $this->entry('cash', $cash, '211', '378', 400.0);
        $this->allocate($item, 'bank', $tx, 600.0);
        $this->allocate($item, 'cash', $cash, 400.0);

        $items = $this->byType($this->related($itemEntry)['items']);

        self::assertSame(['bank', 'cash'], array_keys($items));
        self::assertSame($tx, $items['bank']['source_id']);
        self::assertSame('payment', $items['bank']['relation']);
        self::assertSame(600.0, $items['bank']['allocated_amount']);
        self::assertSame($bankEntry, $items['bank']['entry_id']);
        self::assertSame('bank', $items['bank']['permission']);
        self::assertSame($cash, $items['cash']['source_id']);
        self::assertSame(400.0, $items['cash']['allocated_amount']);
        self::assertSame($cashEntry, $items['cash']['entry_id']);
        self::assertSame('cash', $items['cash']['permission']);
    }

    public function testBankAndCashEntriesShowPaidOtherItem(): void
    {
        $item      = $this->otherItem('OP-SOUV-2', 1000.0, 'Partner Souvisí');
        $itemEntry = $this->entry('other_item', $item, '378', '648', 1000.0);
        $tx        = $this->transaction($this->statement(), 600.0);
        $bankEntry = $this->entry('bank', $tx, '221', '378', 600.0);
        $cash      = $this->cashDocument(400.0);
        $cashEntry = $this->entry('cash', $cash, '211', '378', 400.0);
        $this->allocate($item, 'bank', $tx, 600.0);
        $this->allocate($item, 'cash', $cash, 400.0);

        foreach ([[$bankEntry, 600.0], [$cashEntry, 400.0]] as [$entryId, $allocated]) {
            $items = $this->related($entryId)['items'];
            self::assertCount(1, $items, 'Platba neukazuje další úhrady téže položky, shodně s fakturou.');
            self::assertSame('other_item', $items[0]['source_type']);
            self::assertSame('document', $items[0]['relation']);
            self::assertSame($item, $items[0]['source_id']);
            self::assertSame('OP-SOUV-2', $items[0]['title']);
            self::assertSame('Partner Souvisí', $items[0]['subtitle']);
            self::assertSame(1000.0, $items[0]['amount']);
            self::assertSame($allocated, $items[0]['allocated_amount']);
            self::assertSame($itemEntry, $items[0]['entry_id']);
            self::assertTrue($items[0]['entry_posted']);
            self::assertSame('other_items', $items[0]['permission']);
            self::assertSame(['name' => 'other-item-detail', 'params' => ['id' => $item]], $items[0]['route']);
        }
    }

    public function testTitleAndSubtitleFallBackToItemTitle(): void
    {
        $noPartner = $this->otherItem('OP-SOUV-2B', 100.0);
        $noNumber  = $this->otherItem(null, 100.0, null, 'Půjčka členovi');
        $tx        = $this->transaction($this->statement(), 200.0);
        $bankEntry = $this->entry('bank', $tx, '221', '378', 200.0);
        $this->allocate($noPartner, 'bank', $tx, 100.0);
        $this->allocate($noNumber, 'bank', $tx, 100.0);

        $byId = [];
        foreach ($this->related($bankEntry)['items'] as $it) $byId[$it['source_id']] = [$it['title'], $it['subtitle']];

        self::assertSame(['OP-SOUV-2B', 'Ostatní pohledávka'], $byId[$noPartner]);
        self::assertSame(['Půjčka členovi', null], $byId[$noNumber]);
    }

    public function testSplitPaymentShowsEveryOtherItem(): void
    {
        $first  = $this->otherItem('OP-SOUV-3A', 300.0);
        $second = $this->otherItem('OP-SOUV-3B', 700.0);
        $this->entry('other_item', $first, '378', '648', 300.0);
        $this->entry('other_item', $second, '378', '648', 700.0);
        $tx        = $this->transaction($this->statement(), 1000.0);
        $bankEntry = $this->entry('bank', $tx, '221', '378', 1000.0);
        $this->allocate($first, 'bank', $tx, 300.0);
        $this->allocate($second, 'bank', $tx, 700.0);

        $allocated = [];
        foreach ($this->related($bankEntry)['items'] as $it) {
            self::assertSame('other_item', $it['source_type']);
            $allocated[$it['source_id']] = $it['allocated_amount'];
        }
        self::assertSame([$first => 300.0, $second => 700.0], $allocated);
    }

    public function testAllocatedAmountIsWholeInstallmentPaymentInBothDirections(): void
    {
        $item      = $this->otherItem('OP-SOUV-3C', 10000.0);
        $itemEntry = $this->entry('other_item', $item, '378', '648', 10000.0);
        $tx        = $this->transaction($this->statement(), 1200.0);
        $bankEntry = $this->entry('bank', $tx, '221', '378', 1200.0);
        $this->allocate($item, 'bank', $tx, 1000.0, null, 150.0, 50.0);

        self::assertSame(1200.0, $this->related($itemEntry)['items'][0]['allocated_amount']);
        self::assertSame(1200.0, $this->related($bankEntry)['items'][0]['allocated_amount']);
    }

    public function testReversedAllocationIsHiddenInPanelAndBadge(): void
    {
        $item      = $this->otherItem('OP-SOUV-4', 500.0);
        $itemEntry = $this->entry('other_item', $item, '378', '648', 500.0);
        $tx        = $this->transaction($this->statement(), 500.0);
        $bankEntry = $this->entry('bank', $tx, '221', '378', 500.0);
        $cash      = $this->cashDocument(500.0);
        $cashEntry = $this->entry('cash', $cash, '211', '378', 500.0);
        $this->allocate($item, 'bank', $tx, 500.0, self::YEAR . '-07-01');
        $this->allocate($item, 'cash', $cash, 500.0, self::YEAR . '-07-02');

        foreach ([$itemEntry, $bankEntry, $cashEntry] as $entryId) {
            self::assertSame([], $this->related($entryId)['items'], "Zápis #{$entryId}: stornované přiřazení nehradí.");
        }
        self::assertSame([], $this->links->hasRelatedMap($this->supplierId, $this->entries([$itemEntry, $bankEntry, $cashEntry]), JournalRelatedAccess::all()));
    }

    public function testItemFromBalanceIsPostedByItsOriginEntryAndSeenFromIt(): void
    {
        $item   = $this->otherItem('OP-SOUV-5', 800.0);
        $origin = $this->entry('manual', null, '378', '701', 800.0);
        $this->journalAllocation($origin, $item, 'origin');
        $tx        = $this->transaction($this->statement(), 800.0);
        $bankEntry = $this->entry('bank', $tx, '221', '378', 800.0);
        $this->allocate($item, 'bank', $tx, 800.0);
        // Úhrada nese řádek rozpadu (kind settlement) na tutéž položku a má vyšší id:
        // zaúčtováním položky musí zůstat zápis původu.
        $this->journalAllocation($bankEntry, $item, 'settlement');

        $items = $this->related($bankEntry)['items'];
        self::assertCount(1, $items);
        self::assertSame($item, $items[0]['source_id']);
        self::assertSame($origin, $items[0]['entry_id'], 'Zaúčtováním položky ze zůstatku je zápis původu.');
        self::assertTrue($items[0]['entry_posted']);
        self::assertSame(800.0, $items[0]['allocated_amount'], 'Řádek rozpadu úhrady (settlement) není předpis původu.');

        // Řádek původu jiného druhu dokladu se stejným id položku nezakládá.
        $foreignKind = $this->entry('manual', null, '311', '701', 800.0);
        $this->journalAllocation($foreignKind, $item, 'origin', 'invoice');
        self::assertSame([], $this->related($foreignKind)['items']);
        self::assertSame([], $this->links->hasRelatedMap($this->supplierId, $this->entries([$foreignKind]), JournalRelatedAccess::all()));
        // Zápis nesoucí jen rozpad úhrady (settlement) bez přiřazení platby není zápisem původu.
        $settlementOnly = $this->entry('manual', null, '221', '378', 800.0);
        $this->journalAllocation($settlementOnly, $item, 'settlement');
        self::assertSame([], $this->related($settlementOnly)['items']);
        self::assertSame([], $this->links->hasRelatedMap($this->supplierId, $this->entries([$settlementOnly]), JournalRelatedAccess::all()));

        // Opačný směr: zápis původu ukáže položku (zaúčtovanou jím samým) a její úhradu.
        $fromOrigin = $this->byType($this->related($origin)['items']);
        self::assertSame(['bank', 'other_item'], array_keys($fromOrigin));
        self::assertSame($origin, $fromOrigin['other_item']['entry_id']);
        self::assertSame($bankEntry, $fromOrigin['bank']['entry_id']);
        self::assertSame([$origin => true], $this->links->hasRelatedMap($this->supplierId, $this->entries([$origin]), JournalRelatedAccess::all()));
        self::assertSame([], $this->related($origin, new JournalRelatedAccess(false, true, true))['items']);
        self::assertSame([], $this->links->hasRelatedMap($this->supplierId, $this->entries([$origin]), new JournalRelatedAccess(false, true, true)));

        $this->db->pdo()->prepare(
            "UPDATE journal_settlement_allocations SET released_on = ? WHERE supplier_id = ? AND doc_type = 'other_item' AND doc_id = ? AND kind = 'origin'"
        )->execute([self::YEAR . '-08-01', $this->supplierId, $item]);
        self::assertNull($this->related($bankEntry)['items'][0]['entry_id'], 'Zrušený předpis původu už položku nezaúčtovává.');
        self::assertSame([], $this->related($origin)['items']);
        self::assertSame([], $this->links->hasRelatedMap($this->supplierId, $this->entries([$origin]), JournalRelatedAccess::all()));
    }

    public function testInvoiceCoveredByOtherItemIsLinkedBothWays(): void
    {
        $item         = $this->otherItem('OP-SOUV-10', 1000.0);
        $itemEntry    = $this->entry('other_item', $item, '378', '648', 1000.0);
        $invoice      = $this->saleInvoice('SOUV-F1', $this->client('Odběratel OP'), 1000.0);
        $invoiceEntry = $this->entry('invoice', $invoice, '311', '604', 1000.0);
        $this->reverseEntry($invoiceEntry);
        $this->db->pdo()->prepare('UPDATE invoices SET other_item_id = ? WHERE id = ?')->execute([$item, $invoice]);
        $tx        = $this->transaction($this->statement(), 1000.0);
        $bankEntry = $this->entry('bank', $tx, '221', '378', 1000.0);
        $this->allocate($item, 'bank', $tx, 1000.0);

        // Zápis položky: úhrada i krytá faktura, kterou zaúčtovává on sám.
        $fromItem = $this->byType($this->related($itemEntry)['items']);
        self::assertSame(['bank', 'invoice'], array_keys($fromItem));
        self::assertSame($invoice, $fromItem['invoice']['source_id']);
        self::assertSame($itemEntry, $fromItem['invoice']['entry_id']);

        // Platba: položka i faktura, kterou položka kryje.
        $fromBank = $this->byType($this->related($bankEntry)['items']);
        self::assertSame(['invoice', 'other_item'], array_keys($fromBank));
        self::assertSame($itemEntry, $fromBank['invoice']['entry_id'], 'Fakturu účtuje zápis položky.');

        // Stornovaný zápis faktury: jen položka, která ji účtuje (úhrady ukáže zápis položky,
        // jinak by je detail faktury vykreslil dvakrát).
        $fromInvoice = $this->byType($this->related($invoiceEntry)['items']);
        self::assertSame(['other_item'], array_keys($fromInvoice));

        $page = $this->entries([$itemEntry, $invoiceEntry, $bankEntry]);
        self::assertSame(
            [$itemEntry => true, $invoiceEntry => true, $bankEntry => true],
            $this->sorted($this->links->hasRelatedMap($this->supplierId, $page, JournalRelatedAccess::all()))
        );
        $noOther = new JournalRelatedAccess(false, true, true);
        self::assertSame([], $this->related($invoiceEntry, $noOther)['items']);
        self::assertSame([], $this->links->hasRelatedMap($this->supplierId, $page, $noOther));
    }

    public function testCoveredInvoiceIsNotPostedByReversedItemEntry(): void
    {
        $item  = $this->otherItem('OP-SOUV-15', 400.0);
        $stale = $this->entry('other_item', $item, '378', '648', 400.0);
        $invoice  = $this->saleInvoice('SOUV-F15', $this->client('Odběratel OP 15'), 400.0);
        $this->db->pdo()->prepare('UPDATE invoices SET other_item_id = ? WHERE id = ?')->execute([$item, $invoice]);
        $manual = $this->entry('manual', null, '311', '604', 400.0);
        $this->db->pdo()->prepare(
            "INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, created_by) VALUES (?, ?, 'invoice', ?, ?)"
        )->execute([$this->supplierId, $manual, $invoice, $this->userId]);
        self::assertSame($stale, $this->links->linkedDocuments($this->supplierId, $manual)[0]['entry_id']);

        // Jediný zápis položky je stornovaný (aktivní zápis na zdroj smí být nejvýš jeden,
        // uq_je_supplier_active_source): faktura pak zaúčtovaná není.
        $this->reverseEntry($stale);
        self::assertNull($this->links->linkedDocuments($this->supplierId, $manual)[0]['entry_id']);
        $live = $this->entry('other_item', $item, '378', '648', 400.0);
        self::assertSame($live, $this->links->linkedDocuments($this->supplierId, $manual)[0]['entry_id']);
    }

    public function testBulkPaymentOfManyItemsIsTruncated(): void
    {
        $tx = $this->transaction($this->statement(), 100.0);
        $bankEntry = $this->entry('bank', $tx, '221', '378', 100.0);
        for ($i = 0; $i < JournalLinkService::MAX_ITEMS + 2; $i++) {
            $this->allocate($this->otherItem('OP-SOUV-16-' . $i, 1.0), 'bank', $tx, 1.0);
        }

        $related = $this->related($bankEntry);
        self::assertTrue($related['truncated']);
        self::assertCount(JournalLinkService::MAX_ITEMS, $related['items']);
    }

    public function testCoveringInvoiceAloneMarksItemEntry(): void
    {
        $item      = $this->otherItem('OP-SOUV-11', 500.0);
        $itemEntry = $this->entry('other_item', $item, '378', '648', 500.0);
        $invoice   = $this->saleInvoice('SOUV-F2', $this->client('Odběratel OP 2'), 500.0);
        $this->db->pdo()->prepare('UPDATE invoices SET other_item_id = ? WHERE id = ?')->execute([$item, $invoice]);

        self::assertSame($invoice, $this->related($itemEntry)['items'][0]['source_id']);
        self::assertSame([$itemEntry => true], $this->links->hasRelatedMap($this->supplierId, $this->entries([$itemEntry]), JournalRelatedAccess::all()));
    }

    public function testOtherItemRightHidesItemsAndPaymentRightsHidePayments(): void
    {
        $item      = $this->otherItem('OP-SOUV-6', 1000.0);
        $itemEntry = $this->entry('other_item', $item, '378', '648', 1000.0);
        $tx        = $this->transaction($this->statement(), 600.0);
        $bankEntry = $this->entry('bank', $tx, '221', '378', 600.0);
        $cash      = $this->cashDocument(400.0);
        $cashEntry = $this->entry('cash', $cash, '211', '378', 400.0);
        $this->allocate($item, 'bank', $tx, 600.0);
        $this->allocate($item, 'cash', $cash, 400.0);
        $page = $this->entries([$itemEntry, $bankEntry, $cashEntry]);

        $noOther = new JournalRelatedAccess(false, true, true);
        foreach ([$itemEntry, $bankEntry, $cashEntry] as $entryId) {
            self::assertSame([], $this->related($entryId, $noOther)['items'], "Zápis #{$entryId} bez práva other_items.");
        }
        self::assertSame([], $this->links->hasRelatedMap($this->supplierId, $page, $noOther));

        $noBank = new JournalRelatedAccess(true, false, true);
        self::assertSame(['cash'], array_keys($this->byType($this->related($itemEntry, $noBank)['items'])));
        $noCash = new JournalRelatedAccess(true, true, false);
        self::assertSame(['bank'], array_keys($this->byType($this->related($itemEntry, $noCash)['items'])));
        $noPayments = new JournalRelatedAccess(true, false, false);
        self::assertSame([], $this->related($itemEntry, $noPayments)['items']);
        self::assertArrayNotHasKey($itemEntry, $this->links->hasRelatedMap($this->supplierId, $page, $noPayments));
        self::assertSame(
            [$itemEntry => true, $bankEntry => true, $cashEntry => true],
            $this->sorted($this->links->hasRelatedMap($this->supplierId, $page, JournalRelatedAccess::all()))
        );
    }

    public function testEndpointAndJournalListFollowRoleRights(): void
    {
        $item      = $this->otherItem('OP-SOUV-7', 600.0);
        $itemEntry = $this->entry('other_item', $item, '378', '648', 600.0);
        $tx        = $this->transaction($this->statement(), 600.0);
        $bankEntry = $this->entry('bank', $tx, '221', '378', 600.0);
        $this->allocate($item, 'bank', $tx, 600.0);
        $cashItem  = $this->otherItem('OP-SOUV-7B', 300.0);
        $cashItemEntry = $this->entry('other_item', $cashItem, '378', '648', 300.0);
        $cash      = $this->cashDocument(300.0);
        $this->allocate($cashItem, 'cash', $cash, 300.0);

        $read    = AccessLevel::READ->value;
        $full    = ['accounting' => $read, 'other_items' => $read, 'bank' => $read, 'cash' => $read];
        $noOther = ['accounting' => $read, 'bank' => $read, 'cash' => $read];
        $noBank  = ['accounting' => $read, 'other_items' => $read, 'cash' => $read];
        $noCash  = ['accounting' => $read, 'other_items' => $read, 'bank' => $read];

        $related = $this->container->get(JournalRelatedAction::class);
        $types = fn (array $permissions, int $entryId): array => array_column($this->call($related, $permissions, ['id' => (string) $entryId])['items'], 'source_type');
        self::assertSame(['other_item'], $types($full, $bankEntry));
        self::assertSame([], $types($noOther, $bankEntry));
        self::assertSame(['bank'], $types($full, $itemEntry));
        self::assertSame([], $types($noBank, $itemEntry));
        self::assertSame(['cash'], $types($full, $cashItemEntry));
        self::assertSame(['cash'], $types($noBank, $cashItemEntry));
        self::assertSame([], $types($noCash, $cashItemEntry));

        $badges = function (array $permissions) use ($itemEntry, $bankEntry, $cashItemEntry): array {
            $out = [];
            foreach ($this->call($this->container->get(JournalAction::class), $permissions, null, ['q' => 'SOUV-OI-', 'per_page' => '200'])['items'] as $row) {
                if (in_array((int) $row['id'], [$itemEntry, $bankEntry, $cashItemEntry], true)) $out[(int) $row['id']] = $row['has_related'];
            }
            ksort($out);
            return $out;
        };
        self::assertSame([$itemEntry => true, $bankEntry => true, $cashItemEntry => true], $badges($full));
        self::assertSame([$itemEntry => false, $bankEntry => false, $cashItemEntry => false], $badges($noOther));
        self::assertSame([$itemEntry => false, $bankEntry => true, $cashItemEntry => true], $badges($noBank));
        self::assertSame([$itemEntry => true, $bankEntry => true, $cashItemEntry => false], $badges($noCash));
    }

    public function testSharedPaymentOfItemsInOneOriginEntryIsSummed(): void
    {
        $first  = $this->otherItem('OP-SOUV-12A', 500.0);
        $second = $this->otherItem('OP-SOUV-12B', 300.0);
        $origin = $this->entry('manual', null, '378', '701', 800.0);
        $this->journalAllocation($origin, $first, 'origin');
        $this->journalAllocation($origin, $second, 'origin');
        $tx = $this->transaction($this->statement(), 800.0);
        $this->entry('bank', $tx, '221', '378', 800.0);
        $this->allocate($first, 'bank', $tx, 500.0);
        $this->allocate($second, 'bank', $tx, 300.0);

        $bank = $this->byType($this->related($origin)['items'])['bank'];
        self::assertSame(800.0, $bank['allocated_amount'], 'Na položky tohoto zápisu připadlo z platby 500 + 300.');
    }

    public function testOriginEntryWithManyItemsIsTruncated(): void
    {
        $origin = $this->entry('manual', null, '378', '701', 1.0);
        for ($i = 0; $i < JournalLinkService::MAX_ITEMS + 2; $i++) {
            $this->journalAllocation($origin, $this->otherItem('OP-SOUV-13-' . $i, 1.0), 'origin');
        }

        $related = $this->related($origin);
        self::assertTrue($related['truncated']);
        self::assertCount(JournalLinkService::MAX_ITEMS, $related['items']);
    }

    public function testSoftLinkToCoveredInvoiceShowsItemEntryAsPosting(): void
    {
        $item      = $this->otherItem('OP-SOUV-14', 700.0);
        $itemEntry = $this->entry('other_item', $item, '378', '648', 700.0);
        $invoice   = $this->saleInvoice('SOUV-F14', $this->client('Odběratel OP 14'), 700.0);
        $this->db->pdo()->prepare('UPDATE invoices SET other_item_id = ? WHERE id = ?')->execute([$item, $invoice]);
        $manual = $this->entry('manual', null, '311', '604', 700.0);
        $this->db->pdo()->prepare(
            "INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, created_by) VALUES (?, ?, 'invoice', ?, ?)"
        )->execute([$this->supplierId, $manual, $invoice, $this->userId]);

        $linked = $this->links->linkedDocuments($this->supplierId, $manual);
        self::assertSame($invoice, $linked[0]['source_id']);
        self::assertSame($itemEntry, $linked[0]['entry_id'], 'Krytou fakturu účtuje zápis položky (shodně s detailem faktury).');

        // Nezaúčtovaný (koncept) zápis položky fakturu nezaúčtovává, shodně s JournalForDocumentAction.
        $this->db->pdo()->prepare('UPDATE journal_entries SET posted_at = NULL WHERE id = ?')->execute([$itemEntry]);
        self::assertNull($this->links->linkedDocuments($this->supplierId, $manual)[0]['entry_id']);
    }

    public function testDeletedItemAndForeignTenantAreNeverShown(): void
    {
        $item      = $this->otherItem('OP-SOUV-8', 500.0);
        $itemEntry = $this->entry('other_item', $item, '378', '648', 500.0);
        $tx        = $this->transaction($this->statement(), 500.0);
        $bankEntry = $this->entry('bank', $tx, '221', '378', 500.0);
        $this->allocate($item, 'bank', $tx, 500.0);

        foreach ([['other_item', $item], ['bank', $tx]] as [$type, $id]) {
            $foreign = $this->links->related($this->supplierId + 99999, ['id' => 0, 'source_type' => $type, 'source_id' => $id], JournalRelatedAccess::all());
            self::assertSame([], $foreign['items']);
        }

        // Smazaná položka s krytou fakturou a předpisem v zápisu původu.
        $invoice  = $this->saleInvoice('SOUV-F8', $this->client('Odběratel OP 8'), 500.0);
        $invEntry = $this->entry('invoice', $invoice, '311', '604', 500.0);
        $this->reverseEntry($invEntry);
        $this->db->pdo()->prepare('UPDATE invoices SET other_item_id = ? WHERE id = ?')->execute([$item, $invoice]);
        $origin = $this->entry('manual', null, '378', '701', 500.0);
        $this->journalAllocation($origin, $item, 'origin');
        self::assertNotSame([], $this->related($invEntry)['items']);

        $this->db->pdo()->prepare('UPDATE other_items SET deleted_at = NOW() WHERE id = ?')->execute([$item]);
        foreach ([$bankEntry, $itemEntry, $invEntry, $origin] as $entryId) {
            self::assertSame([], $this->related($entryId)['items'], "Zápis #{$entryId} se smazanou položkou.");
        }
        self::assertSame([], $this->links->hasRelatedMap($this->supplierId, $this->entries([$itemEntry, $bankEntry, $invEntry, $origin]), JournalRelatedAccess::all()));
    }

    public function testBadgeAgreesWithPanelForMixedPage(): void
    {
        $paid      = $this->otherItem('OP-SOUV-9A', 400.0);
        $unpaid    = $this->otherItem('OP-SOUV-9B', 400.0);
        $paidEntry = $this->entry('other_item', $paid, '378', '648', 400.0);
        $openEntry = $this->entry('other_item', $unpaid, '378', '648', 400.0);
        $tx        = $this->transaction($this->statement(), 400.0);
        $bankEntry = $this->entry('bank', $tx, '221', '378', 400.0);
        $loneTx    = $this->transaction($this->statement(), 50.0);
        $loneEntry = $this->entry('bank', $loneTx, '221', '602', 50.0);
        $cash      = $this->cashDocument(10.0);
        $cashEntry = $this->entry('cash', $cash, '211', '378', 10.0);
        $this->allocate($paid, 'bank', $tx, 400.0);
        $this->allocate($unpaid, 'cash', $cash, 10.0, self::YEAR . '-07-01');
        $balance = $this->otherItem('OP-SOUV-9C', 300.0);
        $origin  = $this->entry('manual', null, '378', '701', 300.0);
        $this->journalAllocation($origin, $balance, 'origin');
        // Zápis původu s pokladní úhradou a samostatně stojící položka kryjící fakturu.
        $balanceCash = $this->cashDocument(300.0);
        $this->allocate($balance, 'cash', $balanceCash, 300.0);
        $covering  = $this->otherItem('OP-SOUV-9D', 200.0);
        $coverEntry = $this->entry('other_item', $covering, '378', '648', 200.0);
        $invoice   = $this->saleInvoice('SOUV-F9', $this->client('Odběratel OP 9'), 200.0);
        $invEntry  = $this->entry('invoice', $invoice, '311', '604', 200.0);
        $this->reverseEntry($invEntry);
        $this->db->pdo()->prepare('UPDATE invoices SET other_item_id = ? WHERE id = ?')->execute([$covering, $invoice]);

        $page = $this->entries([$paidEntry, $openEntry, $bankEntry, $loneEntry, $cashEntry, $origin, $coverEntry, $invEntry]);
        foreach ([[true, true, true], [false, true, true], [true, false, true], [true, true, false], [true, false, false], [false, false, false]] as $flags) {
            $access = new JournalRelatedAccess(...$flags);
            $map = $this->links->hasRelatedMap($this->supplierId, $page, $access);
            foreach ($page as $entry) {
                $hasItems = $this->links->related($this->supplierId, $entry, $access)['items'] !== [];
                self::assertSame($hasItems, isset($map[(int) $entry['id']]), 'Odznak a panel se rozcházejí u zápisu #' . $entry['id'] . ' pro ' . json_encode($flags));
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function otherItem(?string $documentNo, float $amount, ?string $partner = null, string $title = 'Ostatní pohledávka'): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO other_items (supplier_id, side, title, partner_name, issued_on, due_on, amount, status, document_no)
             VALUES (?, 'receivable', ?, ?, ?, ?, ?, 'posted', ?)"
        )->execute([$this->supplierId, $title, $partner, self::YEAR . '-06-01', self::YEAR . '-06-30', $amount, $documentNo]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function entry(string $sourceType, ?int $sourceId, string $debit, string $credit, float $amount): int
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        return $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => self::YEAR . '-06-10',
            'document_no' => 'SOUV-OI-' . $sourceType . '-' . ($sourceId ?? uniqid()),
            'description' => 'Souvisí',
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map[$debit]['id'], 'side' => 'debit', 'amount' => $amount],
            ['account_id' => $map[$credit]['id'], 'side' => 'credit', 'amount' => $amount],
        ]);
    }

    /** Storno jako samostatný zápis bez zdroje (tak ho zakládá PostingService). */
    private function reverseEntry(int $entryId): void
    {
        $reversal = $this->entry('manual', null, '604', '311', 1.0);
        $this->db->pdo()->prepare('UPDATE journal_entries SET reversed_by = ? WHERE id = ?')->execute([$reversal, $entryId]);
    }

    /** Řádek rozpadu zápisu na položku: 'origin' = předpis položky ze zůstatku, 'settlement' = úhrada. */
    private function journalAllocation(int $entryId, int $itemId, string $kind, string $docType = 'other_item'): void
    {
        $line = $this->db->pdo()->prepare('SELECT line_no, account_id, side, amount FROM journal_entry_lines WHERE entry_id = ? ORDER BY line_no LIMIT 1');
        $line->execute([$entryId]);
        $row = $line->fetch(\PDO::FETCH_ASSOC);
        $this->db->pdo()->prepare(
            "INSERT INTO journal_settlement_allocations (supplier_id, entry_id, line_no, account_id, side, kind, doc_type, doc_id, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$this->supplierId, $entryId, $row['line_no'], $row['account_id'], $row['side'], $kind, $docType, $itemId, $row['amount']]);
    }

    private ?int $registerId = null;

    private function cashDocument(float $amount): int
    {
        $pdo = $this->db->pdo();
        if ($this->registerId === null) {
            $pdo->prepare(
                'INSERT INTO cash_registers (supplier_id, name, currency_code, account_code) VALUES (?, ?, "CZK", "211")'
            )->execute([$this->supplierId, 'Pokladna OI ' . uniqid()]);
            $this->registerId = (int) $pdo->lastInsertId();
        }
        $registerId = $this->registerId;
        $pdo->prepare(
            'INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, description, total_amount, currency_code, status)
             VALUES (?, ?, "in", "other", ?, ?, "Úhrada ostatní položky", ?, "CZK", "posted")'
        )->execute([$this->supplierId, $registerId, 'PPD-OI-' . uniqid(), self::YEAR . '-06-16', $amount]);
        return (int) $pdo->lastInsertId();
    }

    private function allocate(int $itemId, string $kind, int $paymentId, float $amount, ?string $reversedOn = null, float $interest = 0.0, float $fee = 0.0): void
    {
        $col = $kind === 'bank' ? 'bank_transaction_id' : 'cash_document_id';
        $this->db->pdo()->prepare(
            "INSERT INTO other_item_allocations (supplier_id, other_item_id, {$col}, amount, interest_amount, fee_amount, payment_on, reversed_on)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$this->supplierId, $itemId, $paymentId, $amount, $interest, $fee, self::YEAR . '-06-16', $reversedOn]);
    }

    /** @return array{items:list<array<string,mixed>>, truncated:bool} */
    private function related(int $entryId, ?JournalRelatedAccess $access = null): array
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        self::assertIsArray($entry);
        return $this->links->related($this->supplierId, $entry, $access ?? JournalRelatedAccess::all());
    }

    /**
     * @param  list<int> $ids
     * @return list<array<string,mixed>>
     */
    private function entries(array $ids): array
    {
        return array_map(fn (int $id): array => $this->journal->find($id, $this->supplierId), $ids);
    }

    /**
     * @param  list<array<string,mixed>> $items
     * @return array<string,array<string,mixed>>
     */
    private function byType(array $items): array
    {
        $out = [];
        foreach ($items as $it) $out[$it['source_type']] = $it;
        ksort($out);
        return $out;
    }

    /** @param array<int,true> $map */
    private function sorted(array $map): array
    {
        ksort($map);
        return $map;
    }

    /**
     * @param  array<string,int>         $permissions
     * @param  array<string,string>|null $args
     * @param  array<string,string>      $query
     * @return array<string,mixed>
     */
    private function call(object $action, array $permissions, ?array $args, array $query = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/journal')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(99, 'Synthetic role', 'staff', true, $permissions));
        $resp = $args === null ? $action->list($req, new Psr7Response()) : $action($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $resp->getBody()->rewind();
        return (array) json_decode((string) $resp->getBody(), true);
    }
}
