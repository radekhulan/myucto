<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Repository\AssetRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\SmallAssetRepository;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\AssetSale\InvoiceAssetSaleService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Prodej majetku z vydané faktury (migrace 1177) — automat, který po vystavení uzavře
 * kartu navázanou na řádek dokladu.
 *
 * Co je v sázce: evidence majetku je podklad k inventarizaci (§28/5 ZoÚ). Když se věc
 * prodá fakturou, ale karta o tom neví, soupis doloží majetek, který firma nemá — a u
 * dlouhodobého majetku navíc zůstane neodepsaná zůstatková cena v aktivech. Druhý směr
 * je stejně důležitý: stornovaná faktura prodej neuskutečnila, karta se musí vrátit.
 */
#[Group('integration')]
final class InvoiceAssetSaleTest extends BankPostingTestCase
{
    private InvoiceAssetSaleService $sale;
    private InvoiceRepository $invoices;
    private SmallAssetRepository $smallAssets;
    private AssetRepository $assets;
    private AssetService $assetService;
    private JournalEntryRepository $journalRepo;
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sale         = $this->container->get(InvoiceAssetSaleService::class);
        $this->invoices     = $this->container->get(InvoiceRepository::class);
        $this->smallAssets  = $this->container->get(SmallAssetRepository::class);
        $this->assets       = $this->container->get(AssetRepository::class);
        $this->assetService = $this->container->get(AssetService::class);
        $this->journalRepo  = $this->container->get(JournalEntryRepository::class);
        $this->vatRateId    = (int) ($this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí vat_rates v DB.');
        }
    }

    // ── drobný majetek ───────────────────────────────────────────────────────

    public function testIssuedInvoiceClosesSmallAssetCard(): void
    {
        $card      = $this->smallAssetCard('Notebook Dell', 25000.00);
        $invoiceId = $this->invoice('FV-DM-1', [['net' => 18000.00, 'small_asset_id' => $card]]);

        $this->sale->applyForIssuedInvoice($this->supplierId, $invoiceId, ['user_id' => $this->userId]);

        $row = $this->smallAssets->find($this->supplierId, $card);
        self::assertSame('sold', $row['status'], 'Karta přechází do prodáno.');
        self::assertSame($invoiceId, $row['sale_invoice_id'], 'Karta drží vazbu na doklad prodeje.');
        self::assertSame(self::YEAR . '-06-15', $row['sold_at'], 'Datum prodeje = DUZP dokladu.');
        self::assertSame(18000.00, $row['sale_price'], 'Prodejní cena = netto řádku.');
        self::assertNull($row['disposed_at'], 'Prodaná karta není zároveň vyřazená (chk_sma_disposal).');
    }

    public function testRepeatedRunKeepsCardUntouched(): void
    {
        // Idempotence: re-issue po stornu, force-edit i opakované doručení requestu nesmí
        // narazit na 'already_sold' ani přepsat datum prodeje.
        $card      = $this->smallAssetCard('Monitor', 8000.00);
        $invoiceId = $this->invoice('FV-DM-2', [['net' => 6000.00, 'small_asset_id' => $card]]);

        $this->sale->applyForIssuedInvoice($this->supplierId, $invoiceId, ['user_id' => $this->userId]);
        $first = $this->smallAssets->find($this->supplierId, $card);
        $this->sale->applyForIssuedInvoice($this->supplierId, $invoiceId, ['user_id' => $this->userId]);
        $second = $this->smallAssets->find($this->supplierId, $card);

        self::assertSame('sold', $second['status']);
        self::assertSame($first['sold_at'], $second['sold_at']);
        self::assertSame($first['sale_price'], $second['sale_price']);
    }

    public function testProformaDoesNotSellCard(): void
    {
        // Proforma je výzva k platbě, ne doklad o prodeji — majetek přechází až vyúčtovací
        // fakturou, kam FinalFromProformaCreator vazbu kopíruje.
        $card      = $this->smallAssetCard('Tiskárna', 4000.00);
        $invoiceId = $this->invoice('PRO-DM-1', [['net' => 3000.00, 'small_asset_id' => $card]], 'proforma');

        $this->sale->applyForIssuedInvoice($this->supplierId, $invoiceId, ['user_id' => $this->userId]);

        self::assertSame('in_use', $this->smallAssets->find($this->supplierId, $card)['status']);
    }

    public function testForeignCurrencyInvoiceStoresSalePriceInCzk(): void
    {
        // Karta i práh §26/2 ZDP jsou v korunách — cizoměnová faktura se přepočítá kurzem
        // dokladu, jinak by evidence tvrdila 500 Kč za notebook prodaný za 500 EUR.
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Měna EUR není v číselníku.');
        }
        $card      = $this->smallAssetCard('Notebook EUR', 30000.00);
        $invoiceId = $this->invoice('FV-DM-EUR', [['net' => 500.00, 'small_asset_id' => $card]], 'invoice', $eurId, 25.0);

        $this->sale->applyForIssuedInvoice($this->supplierId, $invoiceId, ['user_id' => $this->userId]);

        self::assertSame(12500.00, $this->smallAssets->find($this->supplierId, $card)['sale_price'], '500 EUR × 25 = 12 500 Kč.');
    }

    // ── dlouhodobý majetek ───────────────────────────────────────────────────

    public function testIssuedInvoiceDisposesAssetAndPostsResidualValue(): void
    {
        $assetId   = $this->assetInUse('INV-SALE-1', self::YEAR . '-01-15', 500000.00);
        $invoiceId = $this->invoice('FV-DHM-1', [['net' => 400000.00, 'asset_id' => $assetId]]);

        $this->sale->applyForIssuedInvoice($this->supplierId, $invoiceId, ['user_id' => $this->userId]);

        $asset = $this->assets->find($this->supplierId, $assetId);
        self::assertSame('disposed', $asset['status'], 'Prodaný majetek je vyřazený z evidence.');
        self::assertSame('sold', $asset['disposal_type']);
        self::assertSame(self::YEAR . '-06-15', $asset['disposal_date']);
        self::assertSame($invoiceId, (int) $asset['sale_invoice_id'], 'Karta drží vazbu na fakturu prodeje.');

        // Vyřazení JE účetní případ: zůstatková cena 541/08x + vyřazení v PC 08x/02x.
        $entry = $this->journalRepo->findBySource($this->supplierId, 'asset_disposal', $assetId);
        self::assertNotNull($entry, 'Vyřazení musí mít zápis v deníku.');
        $codes = $this->accountCodes((int) $entry['id']);
        self::assertContains('541', $codes, 'Zůstatková cena prodaného majetku jde na 541.');
        self::assertContains('022', $codes, 'Vyřazení v pořizovací ceně odchází z 022.');
    }

    public function testFailedDisposalIsReportedBackToCaller(): void
    {
        // Reálný spouštěč: majetek zařazený vloni, jehož loňský daňový odpis nikdo nepotvrdil.
        // assertDisposalChronology vyřazení odmítne — faktura zůstává vystavená a zaúčtovaná,
        // ale volající musí dostat adresný důvod, aby ho uživatel viděl hned po vystavení.
        // Firma vedla účetnictví už vloni, zápis o zařazení do roku bez období se neúčtuje.
        $this->container->get(\MyInvoice\Repository\AccountingPeriodRepository::class)
            ->ensureOpenPeriodFor($this->supplierId, (self::YEAR - 1) . '-03-10', forPosting: false);
        $assetId   = $this->assetInUse('INV-WARN-1', (self::YEAR - 1) . '-03-10', 600000.00);
        $invoiceId = $this->invoice('FV-WARN-1', [['net' => 500000.00, 'asset_id' => $assetId]]);

        $warnings = $this->sale->applyForIssuedInvoice($this->supplierId, $invoiceId, ['user_id' => $this->userId]);

        self::assertCount(1, $warnings, 'Neuzavřená karta musí být hlášena volajícímu.');
        self::assertSame('auto_failed', $warnings[0]['code']);
        self::assertSame('Majetek INV-WARN-1', $warnings[0]['name'], 'Varování nese název karty.');
        self::assertStringContainsString((string) (self::YEAR - 1), $warnings[0]['message'],
            'Zpráva říká, který rok chybí — jinak uživatel neví, co dodělat.');
        self::assertSame('in_use', $this->assets->find($this->supplierId, $assetId)['status'],
            'Karta zůstává v užívání, nic se nepůlvyřadilo.');
    }

    public function testSuccessfulSaleReportsNoWarnings(): void
    {
        $card      = $this->smallAssetCard('Bez varování', 3000.00);
        $assetId   = $this->assetInUse('INV-WARN-2', self::YEAR . '-01-15', 300000.00);
        $invoiceId = $this->invoice('FV-WARN-2', [
            ['net' => 2000.00, 'small_asset_id' => $card],
            ['net' => 250000.00, 'asset_id' => $assetId],
        ]);

        self::assertSame([], $this->sale->applyForIssuedInvoice($this->supplierId, $invoiceId, ['user_id' => $this->userId]));
    }

    public function testAssetSaleIsSkippedForSingleEntryTenant(): void
    {
        // V daňové evidenci se deník nevede a celý modul majetku je za requireDoubleEntry —
        // automat proto vyřazení nespouští (skončilo by chybou období), jen to zaznamená.
        $assetId   = $this->assetInUse('INV-SALE-SE', self::YEAR . '-01-15', 120000.00);
        $invoiceId = $this->invoice('FV-DHM-SE', [['net' => 90000.00, 'asset_id' => $assetId]]);
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);

        $warnings = $this->sale->applyForIssuedInvoice($this->supplierId, $invoiceId, ['user_id' => $this->userId]);

        self::assertSame('in_use', $this->assets->find($this->supplierId, $assetId)['status'],
            'Bez podvojného účetnictví se karta nevyřazuje.');
        self::assertSame('single_entry_mode', $warnings[0]['code'] ?? null,
            'I vědomé přeskočení musí uživatel vidět — karta zůstala v evidenci.');
    }

    // ── storno ───────────────────────────────────────────────────────────────

    public function testRevertReturnsBothKindsOfCardsToUse(): void
    {
        $card      = $this->smallAssetCard('Vrácený skener', 6000.00);
        $assetId   = $this->assetInUse('INV-REV-1', self::YEAR . '-01-15', 300000.00);
        $invoiceId = $this->invoice('FV-REV-1', [
            ['net' => 5000.00, 'small_asset_id' => $card],
            ['net' => 250000.00, 'asset_id' => $assetId],
        ]);
        $this->sale->applyForIssuedInvoice($this->supplierId, $invoiceId, ['user_id' => $this->userId]);
        self::assertSame('sold', $this->smallAssets->find($this->supplierId, $card)['status']);
        self::assertSame('disposed', $this->assets->find($this->supplierId, $assetId)['status']);

        $this->sale->revertForInvoice($this->supplierId, $invoiceId, ['user_id' => $this->userId]);

        $small = $this->smallAssets->find($this->supplierId, $card);
        self::assertSame('in_use', $small['status'], 'Storno vrací drobný majetek do užívání.');
        self::assertNull($small['sale_invoice_id']);
        self::assertSame('in_use', $this->assets->find($this->supplierId, $assetId)['status'],
            'Storno vrací i dlouhodobý majetek (R24).');
    }

    public function testRevertIgnoresCardSoldByAnotherInvoice(): void
    {
        // Storno faktury A nesmí sáhnout na kartu, kterou prodala faktura B — vazba se
        // porovnává, ne jen stav karty.
        $card     = $this->smallAssetCard('Cizí prodej', 3000.00);
        $invoiceA = $this->invoice('FV-REV-A', [['net' => 2000.00, 'small_asset_id' => $card]]);
        $invoiceB = $this->invoice('FV-REV-B', [['net' => 2000.00, 'small_asset_id' => $card]]);
        $this->sale->applyForIssuedInvoice($this->supplierId, $invoiceB, ['user_id' => $this->userId]);

        $this->sale->revertForInvoice($this->supplierId, $invoiceA, ['user_id' => $this->userId]);

        $row = $this->smallAssets->find($this->supplierId, $card);
        self::assertSame('sold', $row['status'], 'Karta zůstává prodaná fakturou B.');
        self::assertSame($invoiceB, $row['sale_invoice_id']);
    }

    // ── validace vazeb při ukládání položek ──────────────────────────────────

    public function testReplaceItemsRejectsCardOfAnotherTenant(): void
    {
        $foreign   = $this->smallAssetCard('Cizí notebook', 20000.00, $this->otherSupplierId());
        $invoiceId = $this->invoice('FV-VAL-1', [['net' => 1000.00]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nebyla nalezena/');
        $this->invoices->replaceItems($invoiceId, [$this->itemPayload(1000.00, $foreign, null)]);
    }

    public function testReplaceItemsRejectsSameCardTwice(): void
    {
        $card      = $this->smallAssetCard('Dvakrát prodaný', 5000.00);
        $invoiceId = $this->invoice('FV-VAL-2', [['net' => 1000.00]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/více řádcích/');
        $this->invoices->replaceItems($invoiceId, [
            $this->itemPayload(1000.00, $card, null),
            $this->itemPayload(2000.00, $card, null),
        ]);
    }

    public function testReplaceItemsRejectsCardAlreadySoldByAnotherInvoice(): void
    {
        $card     = $this->smallAssetCard('Už prodaný', 9000.00);
        $sold     = $this->invoice('FV-VAL-3A', [['net' => 9000.00, 'small_asset_id' => $card]]);
        $this->sale->applyForIssuedInvoice($this->supplierId, $sold, ['user_id' => $this->userId]);
        $another  = $this->invoice('FV-VAL-3B', [['net' => 1000.00]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/už prodaný jinou fakturou/');
        $this->invoices->replaceItems($another, [$this->itemPayload(9000.00, $card, null)]);
    }

    public function testReplaceItemsAllowsResaveOfOwnSoldCard(): void
    {
        // Po vystavení je karta 'sold' TÍMHLE dokladem — jeho re-uložení (force-edit,
        // přepočet) nesmí spadnout, jinak by vystavená faktura šla jen smazat.
        $card      = $this->smallAssetCard('Znovu uložený', 7000.00);
        $invoiceId = $this->invoice('FV-VAL-4', [['net' => 7000.00, 'small_asset_id' => $card]]);
        $this->sale->applyForIssuedInvoice($this->supplierId, $invoiceId, ['user_id' => $this->userId]);

        $this->invoices->replaceItems($invoiceId, [$this->itemPayload(7000.00, $card, null)]);

        $items = $this->invoices->itemsFor($invoiceId);
        self::assertSame($card, $items[0]['small_asset_id'], 'Vazba přežila re-uložení.');
    }

    public function testFixedAssetLineGetsCoefficientExcludedClassification(): void
    {
        // § 76/4 ZDPH: prodej dlouhodobého majetku se nezapočítává do koeficientu. Číselník
        // na to má '1m' (tatáž řádka přiznání i A.4 jako '1'), takže se doplní automaticky.
        $assetId   = $this->assetInUse('INV-KOEF-1', self::YEAR . '-01-15', 400000.00);
        $invoiceId = $this->invoice('FV-KOEF-1', [['net' => 1000.00]]);

        $this->invoices->replaceItems($invoiceId, [$this->itemPayload(300000.00, null, $assetId)]);

        self::assertSame('1m', $this->invoices->itemsFor($invoiceId)[0]['vat_classification_code']);
    }

    public function testSmallAssetLineKeepsOrdinaryClassification(): void
    {
        // Drobný majetek šel pořízením do spotřeby (501), dlouhodobým majetkem nikdy nebyl —
        // z koeficientu se nevylučuje, kód zůstává '1'.
        $card      = $this->smallAssetCard('Běžná klasifikace', 5000.00);
        $invoiceId = $this->invoice('FV-KOEF-2', [['net' => 1000.00]]);

        $this->invoices->replaceItems($invoiceId, [$this->itemPayload(5000.00, $card, null)]);

        self::assertSame('1', $this->invoices->itemsFor($invoiceId)[0]['vat_classification_code']);
    }

    public function testExplicitClassificationCodeWins(): void
    {
        // Ruční volba účetní má přednost před automatikou § 76/4.
        $assetId   = $this->assetInUse('INV-KOEF-3', self::YEAR . '-01-15', 400000.00);
        $invoiceId = $this->invoice('FV-KOEF-3', [['net' => 1000.00]]);

        $payload = $this->itemPayload(300000.00, null, $assetId) + ['vat_classification_code' => '1'];
        $this->invoices->replaceItems($invoiceId, [$payload]);

        self::assertSame('1', $this->invoices->itemsFor($invoiceId)[0]['vat_classification_code']);
    }

    public function testReplaceItemsRejectsBothKindsOnOneLine(): void
    {
        $card      = $this->smallAssetCard('Drobný', 1000.00);
        $assetId   = $this->assetInUse('INV-VAL-5', self::YEAR . '-01-15', 200000.00);
        $invoiceId = $this->invoice('FV-VAL-5', [['net' => 1000.00]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/zároveň/');
        $this->invoices->replaceItems($invoiceId, [$this->itemPayload(1000.00, $card, $assetId)]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function smallAssetCard(string $name, float $price, ?int $supplierId = null): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO small_assets (supplier_id, name, acquisition_date, quantity, unit_price, price, status)
             VALUES (?, ?, ?, 1, ?, ?, "in_use")'
        )->execute([$supplierId ?? $this->supplierId, $name, self::YEAR . '-01-10', $price, $price]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function assetInUse(string $inventoryNumber, string $useDate, float $price): int
    {
        $created = $this->assetService->create($this->supplierId, [
            'inventory_number' => $inventoryNumber,
            'name' => 'Majetek ' . $inventoryNumber,
            'input_price' => $price,
            'acquisition_date' => $useDate,
            'tax_method' => 'straight',
            'tax_group' => 2,
            'acc_useful_life_months' => 60,
        ], ['user_id' => $this->userId]);
        $assetId = (int) $created['asset']['id'];
        $this->assetService->putIntoUse($this->supplierId, $assetId, $useDate, true, [
            'user_id' => $this->userId, 'posted_by' => $this->userId,
        ]);
        return $assetId;
    }

    /**
     * Vystavená faktura s řádky; každý řádek volitelně navázaný na kartu majetku.
     *
     * @param list<array{net:float, small_asset_id?:int, asset_id?:int}> $items
     */
    private function invoice(
        string $varsymbol,
        array $items,
        string $type = 'invoice',
        ?int $currencyId = null,
        ?float $rate = null,
    ): int {
        $pdo = $this->db->pdo();
        $clientId = $this->invoiceClient();
        $issue = self::YEAR . '-06-15';
        $net = 0.0;
        foreach ($items as $i) {
            $net += $i['net'];
        }
        $vat = round($net * 0.21, 2);
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", "1", ?)'
        )->execute([
            // invoices.varsymbol je varchar(20) — základ i unikátní přípona se musí vejít,
            // jinak to na přísném sql_mode (výchozí MariaDB) skončí chybou 1406.
            $this->supplierId, substr($varsymbol, 0, 9) . '-' . substr(uniqid('', false), -10),
            $type, $clientId, $issue, $issue, $issue,
            $currencyId ?? $this->currencyId, $rate, $net, $vat, $net + $vat, $this->userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code, small_asset_id, asset_id)
             VALUES (?, "Prodej", 1, "ks", ?, ?, 21.00, ?, ?, ?, ?, "1", ?, ?)'
        );
        foreach (array_values($items) as $order => $item) {
            $lineVat = round($item['net'] * 0.21, 2);
            $stmt->execute([
                $invoiceId, $item['net'], $this->vatRateId, $item['net'], $lineVat,
                $item['net'] + $lineVat, $order,
                $item['small_asset_id'] ?? null, $item['asset_id'] ?? null,
            ]);
        }
        return $invoiceId;
    }

    /** @return array<string,mixed> payload jednoho řádku pro replaceItems() */
    private function itemPayload(float $net, ?int $smallAssetId, ?int $assetId): array
    {
        return [
            'description' => 'Prodej majetku',
            'quantity' => 1,
            'unit' => 'ks',
            'unit_price_without_vat' => $net,
            'vat_rate_id' => $this->vatRateId,
            'small_asset_id' => $smallAssetId,
            'asset_id' => $assetId,
        ];
    }

    private function invoiceClient(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "test@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, 'Kupující ' . uniqid('c', false), $this->czId, $this->currencyId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<string> kódy účtů zápisu */
    private function accountCodes(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ?'
        );
        $stmt->execute([$entryId]);
        return array_map(static fn (array $r): string => (string) $r['account_code'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
}
