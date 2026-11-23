<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\SmallAsset;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SmallAssetRepository;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\Expense\PurchaseDiscountAllocation;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use PDO;

/**
 * Karty evidence drobného majetku (§DM krok 3, migrace 1094).
 *
 * JEDINÝ ZAPISOVATEL do small_assets — a to je funkční požadavek, ne sloh. Invariant
 * „karta má nejvýš jeden zdroj" nejde vynutit CHECKem, protože MariaDB nepustí CHECK nad
 * sloupcem s FK ON DELETE SET NULL (chyba 1901, viz 1094). Kdyby do tabulky sahal kdokoliv
 * jiný, invariant by nedržel nic.
 *
 * NIC NEÚČTUJE. Náklad na 501 zaúčtoval PostingService už při zaúčtování dokladu podle
 * `expense_kind` (1092); karta je jen evidence věci vedle toho. Proto tu není žádná vazba
 * na journal_entries a založení karty nemá na výsledek hospodaření vliv.
 */
final class SmallAssetService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SmallAssetRepository $cards,
    ) {}

    /**
     * Založí kartu. Zdroj je volitelný — ruční karta (majetek starší než aplikace, dar,
     * vklad) žádný doklad v systému nemá a evidenci mít musí stejně (§28/5 ZoÚ).
     *
     * @param array<string,mixed> $data už normalizovaná data z Action
     */
    public function create(int $supplierId, array $data, ?int $createdBy): int
    {
        $this->assertSingleSource($data);
        $this->assertSourceBelongsToTenant($supplierId, $data);
        return $this->cards->insert($supplierId, $data, $createdBy);
    }

    /**
     * Dodatečně naváže kartu BEZ zdroje na položku přijaté faktury (opakovaný převod z jiného
     * programu dohledá doklad, který dřívější převod nenašel). Karta se zdrojem zůstává,
     * jak je - vazbu nikdy nepřepisuje.
     */
    public function attachPurchaseSource(int $supplierId, int $id, int $purchaseInvoiceId, int $purchaseInvoiceItemId, ?string $documentRef): bool
    {
        $this->assertSourceBelongsToTenant($supplierId, ['purchase_invoice_id' => $purchaseInvoiceId, 'purchase_invoice_item_id' => $purchaseInvoiceItemId]);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE small_assets SET purchase_invoice_id = ?, purchase_invoice_item_id = ?, document_ref = COALESCE(document_ref, ?)
              WHERE id = ? AND supplier_id = ? AND purchase_invoice_id IS NULL AND purchase_invoice_item_id IS NULL AND cash_document_id IS NULL'
        );
        $stmt->execute([$purchaseInvoiceId, $purchaseInvoiceItemId, $documentRef, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Vyřazení karty. §28/5 ZoÚ chce, aby evidence prokazovala existenci majetku — proto
     * se karta NEMAŽE, jen mění stav; vyřazený drobný majetek musí jít doložit i zpětně
     * (soupis k datu v minulosti ho pořád ukáže).
     */
    public function dispose(int $supplierId, int $id, string $disposedAt, ?string $reason): void
    {
        $card = $this->cards->find($supplierId, $id);
        if ($card === null) {
            throw new PostingException('not_found', 'Karta nenalezena.', 404);
        }
        if ($card['status'] === 'disposed') {
            throw new PostingException('already_disposed', 'Karta je už vyřazená.', 422);
        }
        if ($disposedAt < (string) $card['acquisition_date']) {
            throw new PostingException(
                'disposal_before_acquisition',
                'Datum vyřazení nesmí předcházet datu pořízení.',
                422,
            );
        }
        $this->cards->update($supplierId, $id, [
            'status' => 'disposed',
            'disposed_at' => $disposedAt,
            'disposal_reason' => $reason,
        ]);
    }

    /**
     * Prodej karty drobného majetku. Prodej je běžná vydaná faktura (výnos 642 + DPH);
     * náklad na 501 padl už při pořízení, takže zůstatková cena je 0 a z KARTY se NIC
     * neúčtuje — jen se propojí s dokladem prodeje a přejde do stavu 'sold'. Idempotence
     * s AssetService::dispose (dlouhodobý majetek) tu vědomě není: drobný majetek disposal
     * zápis netvoří.
     *
     * @param float|null $salePrice prodejní cena bez DPH — jen evidenční
     */
    public function sell(int $supplierId, int $id, int $saleInvoiceId, string $soldAt, ?float $salePrice): void
    {
        $card = $this->cards->find($supplierId, $id);
        if ($card === null) {
            throw new PostingException('not_found', 'Karta nenalezena.', 404);
        }
        if ($card['status'] === 'disposed') {
            throw new PostingException('already_disposed', 'Karta je už vyřazená — nejdřív vraťte vyřazení.', 422);
        }
        if ($card['status'] === 'sold') {
            throw new PostingException('already_sold', 'Karta je už prodaná.', 422);
        }
        if ($soldAt < (string) $card['acquisition_date']) {
            throw new PostingException(
                'sale_before_acquisition',
                'Datum prodeje nesmí předcházet datu pořízení.',
                422,
            );
        }
        // Faktura prodeje musí patřit tenantovi — id je cizí vstup z API, jinak by karta
        // odkazovala přes hranici firmy (vzor assertSourceBelongsToTenant).
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$saleInvoiceId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new PostingException('sale_invoice_not_found', 'Faktura prodeje nenalezena.', 422);
        }

        $this->cards->update($supplierId, $id, [
            'status' => 'sold',
            'sale_invoice_id' => $saleInvoiceId,
            'sold_at' => $soldAt,
            'sale_price' => $salePrice,
            // chk_sma_disposal: 'sold' se vylučuje s disposed_at → jistota, že tam nezůstane.
            'disposed_at' => null,
            'disposal_reason' => null,
        ]);
    }

    /** Vrácení vyřazené / prodané karty zpět do užívání (oprava omylu). */
    public function restore(int $supplierId, int $id): void
    {
        $card = $this->cards->find($supplierId, $id);
        if ($card === null) {
            throw new PostingException('not_found', 'Karta nenalezena.', 404);
        }
        // chk_sma_disposal: `in_use` se v DB vylučuje s disposed_at i sold_at, takže se maže vše.
        $this->cards->update($supplierId, $id, [
            'status' => 'in_use',
            'disposed_at' => null,
            'disposal_reason' => null,
            'sale_invoice_id' => null,
            'sold_at' => null,
            'sale_price' => null,
        ]);
    }

    /**
     * Založí karty ze VŠECH řádků dokladu klasifikovaných jako drobný majetek (1092).
     *
     * IDEMPOTENCE PŘES PŘIROZENÝ KLÍČ (doklad + název + cena), ne přes id řádku:
     * PurchaseInvoiceRepository::replaceItems() smaže a znovu vloží všechny položky při
     * každé editaci dokladu, takže `purchase_invoice_item_id` na kartě se po nevinné
     * opravě překlepu vynuluje. Kdyby idempotence stála na něm, druhé spuštění by po
     * editaci založilo duplicitní karty a soupis k inventarizaci by lhal.
     *
     * ZÁPORNÉ ŘÁDKY NEJSOU MAJETEK. Sleva ani vratka není věc, kterou lze evidovat,
     * natož „vzít do užívání" — karta se zápornou cenou je nesmysl. Řeší se dvěma
     * způsoby podle toho, co ten záporný řádek znamená:
     *
     *   - SLEVA na téže faktuře (Alza: „Sleva 10% k položce SAMO0" −3 100,45) patří
     *     k věci, kterou zlevňuje → rozpustí se POMĚRNĚ do kladných řádků. Evidenční
     *     hodnota pak sedí na to, co doklad opravdu stál, a shodně s tím, co dává
     *     účetní na 501.200 (net celého dokladu).
     *   - DOBROPIS (vrácení dodavateli) je samostatný doklad → kartu nezakládá vůbec,
     *     místo toho VYŘADÍ původní kartu téhož názvu. Vrácený monitor přestane být
     *     majetkem; nestane se majetkem za −34 919,26.
     *
     * @return array{created:list<int>, skipped:int, disposed:list<int>}
     */
    public function generateFromPurchaseInvoice(int $supplierId, int $purchaseInvoiceId, ?int $createdBy): array
    {
        $header = $this->purchaseInvoiceHeader($supplierId, $purchaseInvoiceId);
        if ($header === null) {
            throw new PostingException('not_found', 'Doklad nenalezen.', 404);
        }

        if ($header['is_credit_note']) {
            return ['created' => [], 'skipped' => 0,
                'disposed' => $this->disposeReturned($supplierId, $header, $this->smallAssetRows($supplierId, $purchaseInvoiceId, false))];
        }
        $rows = $this->smallAssetRows($supplierId, $purchaseInvoiceId, true);

        // Kartu zakládá JEN skutečná faktura. Proforma / zálohová faktura (document_kind
        // 'advance') NENÍ pořízení — je to jen výzva k platbě zálohy (účtuje se na 314, ne do
        // nákladu), a majetek k ní přijde teprve na finální faktuře. Vzor: M Computers dodal
        // proformu 231 (Lenovo 66 033) i finální fakturu 234 na tutéž věc — kdyby kartu
        // zakládaly obě, majetek by v evidenci figuroval dvakrát. Účtenka ('receipt') se sem
        // taky netahá — drobný majetek z účtenky se do evidence přidá ručně.
        if ($header['document_kind'] !== 'invoice') {
            return ['created' => [], 'skipped' => 0, 'disposed' => []];
        }

        $existing = [];
        foreach ($this->cards->forPurchaseInvoice($supplierId, $purchaseInvoiceId) as $card) {
            $existing[$this->naturalKey((string) $card['name'], (float) $card['price'])] = true;
        }

        $prices = $this->allocateDiscounts($rows);

        $created = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $price = $prices[(int) $row['id']] ?? null;
            if ($price === null) {
                continue;   // záporný řádek (sleva) — rozpuštěný do kladných, kartu nezakládá
            }
            // Přepočet na CZK — karta evidence i práh §26/2 ZDP jsou v korunách. U CZK
            // dokladu je kurz 1,0 (beze změny), u EUR/USD se cena vynásobí kurzem dokladu.
            $price = round($price * (float) $header['czk_rate'], 2);
            $name = trim((string) $row['description']);
            if (isset($existing[$this->naturalKey($name, $price)])) {
                $skipped++;
                continue;
            }
            $qty = (float) $row['quantity'];
            $created[] = $this->cards->insert($supplierId, [
                'purchase_invoice_id' => $purchaseInvoiceId,
                'purchase_invoice_item_id' => (int) $row['id'],
                'document_ref' => $header['document_ref'],
                'name' => $name === '' ? 'Drobný majetek' : $name,
                'vendor_client_id' => $header['vendor_id'],
                'vendor_name' => $header['vendor_name'],
                // Datum pořízení = datum plnění dokladu (fallback vystavení) — shodně
                // s rokem, podle kterého se posuzuje limit §26/2 ZDP v klasifikátoru.
                'acquisition_date' => $header['acquisition_date'],
                'quantity' => $qty,
                // Jednotková cena PO slevě — jinak by karta tvrdila jinou cenu za kus,
                // než kolik věc stála, a §26/2 se posuzuje právě podle ceny za kus.
                'unit_price' => $qty != 0.0 ? UnitPricePrecision::normalize($price / $qty) : $price,
                'price' => $price,
                // DDHM vs DDNM — inventarizace hmotného je fyzická, u nehmotného se
                // dokládá licenčním ujednáním; soupis, který obojí míchá, je nepoužitelný.
                'asset_kind' => (ExpenseKind::tryFromNullable((string) ($row['expense_kind'] ?? ''))
                    ?? ExpenseKind::SmallAsset)->smallAssetCardKind(),
            ], $createdBy);
            $existing[$this->naturalKey($name, $price)] = true;
        }

        return ['created' => $created, 'skipped' => $skipped, 'disposed' => []];
    }

    /**
     * Řádky dokladu klasifikované jako drobný majetek (DDHM i DDNM — obojí patří do
     * evidence, ČÚS 013, liší se jen druhem karty).
     *
     * S `$applyDiscounts` nesou v `total_without_vat` základ PO slevových řádcích, které
     * k nim patří podle {@see PurchaseDiscountAllocation} — tentýž rozpad jako zaúčtování,
     * takže karta sedí na 501. Sleva klasifikovaná jinak než zboží (typicky jako služba)
     * se tak do ceny karty promítne taky; dřív karta zůstala v ceně před slevou.
     *
     * @return list<array<string,mixed>>
     */
    private function smallAssetRows(int $supplierId, int $purchaseInvoiceId, bool $applyDiscounts): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.description, pii.quantity, pii.unit_price_without_vat, pii.total_without_vat,
                    pii.vat_rate_snapshot, pii.expense_kind, pii.expense_account_code
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pii.purchase_invoice_id = ? AND pi.supplier_id = ?
              ORDER BY pii.order_index, pii.id'
        );
        $stmt->execute([$purchaseInvoiceId, $supplierId]);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $nets = $applyDiscounts
            ? PurchaseDiscountAllocation::netsAfterDiscounts(
                PurchaseDiscountAllocation::withReturns($this->db->pdo(), $supplierId, $purchaseInvoiceId, $all),
            )
            : null;
        $small = [ExpenseKind::SmallAsset->value, ExpenseKind::SmallIntangible->value];
        $rows = [];
        foreach ($all as $row) {
            if (!in_array((string) $row['expense_kind'], $small, true)) {
                continue;
            }
            if ($nets !== null) {
                if (!array_key_exists((int) $row['id'], $nets)) {
                    continue;   // slevový řádek rozpuštěný do zlevněných položek
                }
                $row['total_without_vat'] = $nets[(int) $row['id']];
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Rozpustí záporné řádky (slevy) poměrně do kladných a vrátí `id řádku => cena karty`.
     * Záporné řádky v návratu nejsou — kartu nezakládají.
     *
     * Zbytek ze zaokrouhlení dostane největší řádek, aby Σ karet sedělo na Σ řádků na
     * haléř; jinak by se evidence rozešla s účtem 501 o haléře a soupis k inventarizaci
     * by nešel odsouhlasit.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<int,float>
     */
    private function allocateDiscounts(array $rows): array
    {
        $positive = [];
        $discount = 0.0;
        foreach ($rows as $row) {
            $net = round((float) $row['total_without_vat'], 2);
            if ($net > 0.0) {
                $positive[(int) $row['id']] = $net;
            } else {
                $discount += $net;   // záporné
            }
        }
        if ($positive === []) {
            return [];
        }
        if ($discount === 0.0) {
            return $positive;
        }

        $base = array_sum($positive);
        if ($base <= 0.0) {
            return $positive;
        }

        $out = [];
        $assigned = 0;
        $targetCents = (int) round(($base + $discount) * 100);
        foreach ($positive as $id => $net) {
            $c = (int) round($targetCents * ($net / $base));
            $out[$id] = $c;
            $assigned += $c;
        }
        $residual = $targetCents - $assigned;
        if ($residual !== 0) {
            $biggest = array_key_first($out);
            $max = -1.0;
            foreach ($positive as $id => $net) {
                if ($net > $max) {
                    $max = $net;
                    $biggest = $id;
                }
            }
            $out[$biggest] += $residual;
        }

        return array_map(static fn(int $c): float => $c / 100, $out);
    }

    /**
     * Dobropis = vrácení dodavateli → vyřadí karty pořízené OPRAVOVANOU fakturou, ale JEN
     * ty, které dobropis skutečně vrací.
     *
     * Dvě úrovně párování, obě nutné:
     *   1. `parent_purchase_invoice_id` (1096) určí, KTERÉ faktury se dobropis týká — bez toho
     *      by se u tří kusů „Monitor 40" Dell" z různých faktur nedalo poznat, o kterou jde.
     *   2. NÁZEV+CENA položky dobropisu určí, KTERÉ karty té faktury vyřadit — dobropis bývá
     *      ČÁSTEČNÝ. Reálný nález: PF 38 = switch + router, dobropis PF 42 vrací JEN switch;
     *      vyřadit obě karty rodiče by router omylem vyřadilo.
     * Uvnitř jedné faktury je název jednoznačný (na rozdíl od párování přes víc faktur),
     * takže tahle kombinace hádání nehrozí.
     *
     * @param array<string,mixed> $header
     * @param list<array<string,mixed>> $rows položky dobropisu (klasifikované jako small_asset)
     * @return list<int>
     */
    private function disposeReturned(int $supplierId, array $header, array $rows): array
    {
        $parentId = $header['parent_purchase_invoice_id'] ?? null;
        if ($parentId === null) {
            return [];   // nenavázaný dobropis nic nevyřazuje — nehádá se
        }

        // Vrácené věci dle názvu (cena dobropisu je záporná → abs).
        $returned = [];
        foreach ($rows as $row) {
            $returned[$this->naturalKey((string) $row['description'], round(abs((float) $row['total_without_vat']), 2))] = true;
        }

        $disposed = [];
        foreach ($this->cards->forPurchaseInvoice($supplierId, (int) $parentId) as $card) {
            if ((string) ($card['status'] ?? '') !== 'in_use') {
                continue;
            }
            if (!isset($returned[$this->naturalKey((string) $card['name'], (float) $card['price'])])) {
                continue;   // tuhle věc dobropis nevrací
            }
            $this->dispose(
                $supplierId,
                (int) $card['id'],
                (string) $header['acquisition_date'],
                'Vráceno dodavateli — dobropis ' . (string) ($header['document_ref'] ?? ''),
            );
            $disposed[] = (int) $card['id'];
        }
        return $disposed;
    }

    /**
     * Sesynchronizuje karty dokladu s jeho aktuálními položkami — volá se po editaci
     * přijaté faktury. Bez tohoto volání se změny na dokladu do evidence nepromítly:
     * uživatel označil položku jako majetek, ale karta nevznikla (nahlásil 2026-07-16).
     *
     * Dělá dvě věci:
     *   1. DOPLNÍ karty za nově klasifikované položky (idempotentně přes generate).
     *   2. UKLIDÍ karty, které už nemají v dokladu protějšek (položka přeřazena na službu
     *      nebo smazána) — ale JEN ty NEDOTČENÉ automatické. Karta, kterou uživatel ručně
     *      doplnil (umístění, odpovědná osoba, inventární číslo, poznámka) nebo už vyřadil,
     *      zůstává; tam by tichý úklid smazal ruční práci.
     *
     * @return array{created:list<int>, skipped:int, disposed:list<int>, pruned:list<int>}
     */
    public function syncFromPurchaseInvoice(int $supplierId, int $purchaseInvoiceId, ?int $createdBy): array
    {
        $result = $this->generateFromPurchaseInvoice($supplierId, $purchaseInvoiceId, $createdBy);

        // Přirozené klíče (název+cena po rozpuštění slev) položek, které JSOU drobný majetek —
        // musí sedět na klíče, pod kterými generate karty založil, jinak by úklid smazal i
        // právě vytvořené karty. Proto tady MUSÍ být tentýž filtr druhů jako v generate
        // (DDHM i DDNM): dokud se ptal jen na `small_asset`, karta drobného NEhmotného
        // majetku se v témže běhu založila a hned zase smazala.
        $items = $this->smallAssetRows($supplierId, $purchaseInvoiceId, true);
        $prices = $this->allocateDiscounts($items);
        // Karta je vždy v CZK, takže generate cenu násobí kurzem dokladu — a klíč pro úklid
        // musí projít TOUTÉŽ transformací. Bez toho držel úklid u cizoměnové faktury cenu
        // v EUR, kdežto právě založená karta cenu v korunách: klíče se nepotkaly a sync
        // kartu v témže běhu smazal. U EUR/USD dokladu tak evidence nevznikla nikdy.
        // Doklad tu existuje jistě — generate výš by jinak vyhodil not_found.
        $header = $this->purchaseInvoiceHeader($supplierId, $purchaseInvoiceId);
        $czkRate = (float) ($header['czk_rate'] ?? 1.0);
        $wantedKeys = [];
        foreach ($items as $it) {
            $price = $prices[(int) $it['id']] ?? null;
            if ($price === null) {
                continue;   // slevový řádek — kartu netvoří
            }
            $wantedKeys[$this->naturalKey(
                (string) $it['description'],
                round($price * $czkRate, 2),
            )] = true;
        }

        $pruned = [];
        foreach ($this->cards->forPurchaseInvoice($supplierId, $purchaseInvoiceId) as $card) {
            $key = $this->naturalKey((string) $card['name'], (float) $card['price']);
            if (isset($wantedKeys[$key])) {
                continue;   // pořád má protějšek
            }
            if ((string) ($card['status'] ?? '') !== 'in_use') {
                continue;   // vyřazenou kartu nemažeme (drží historii)
            }
            if ($this->cardWasManuallyTouched($card)) {
                continue;   // ruční práce se nezahazuje
            }
            $this->cards->delete($supplierId, (int) $card['id']);
            $pruned[] = (int) $card['id'];
        }

        return $result + ['pruned' => $pruned];
    }

    /** @param array<string,mixed> $card */
    private function cardWasManuallyTouched(array $card): bool
    {
        foreach (['location', 'responsible_person', 'inventory_number', 'notes'] as $field) {
            if (trim((string) ($card[$field] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Přirozený klíč karty v rámci dokladu. Název se normalizuje na malá písmena a
     * jednu mezeru — jinak by „Notebook Dell" a „notebook  dell" byly dvě věci a
     * idempotence by padla na formátování.
     */
    private function naturalKey(string $name, float $price): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', mb_strtolower($name, 'UTF-8')) ?? $name);
        return $normalized . '|' . number_format($price, 2, '.', '');
    }

    /**
     * @param array<string,mixed> $data
     */
    private function assertSingleSource(array $data): void
    {
        $sources = 0;
        foreach (['purchase_invoice_item_id', 'cash_document_id'] as $key) {
            if (($data[$key] ?? null) !== null) {
                $sources++;
            }
        }
        if ($sources > 1) {
            throw new PostingException(
                'multiple_sources',
                'Karta může mít jen jeden zdrojový doklad — buď přijatou fakturu, nebo pokladní doklad.',
                422,
            );
        }
    }

    /**
     * Zdrojový doklad musí patřit tenantovi. Bez téhle kontroly by FK pustilo cizí
     * purchase_invoice_items.id / cash_documents.id — id je cizí vstup z API a evidence
     * by pak odkazovala přes hranici firmy.
     *
     * @param array<string,mixed> $data
     */
    private function assertSourceBelongsToTenant(int $supplierId, array $data): void
    {
        $pdo = $this->db->pdo();

        if (($data['purchase_invoice_id'] ?? null) !== null) {
            $stmt = $pdo->prepare('SELECT 1 FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$data['purchase_invoice_id'], $supplierId]);
            if ($stmt->fetchColumn() === false) {
                throw new PostingException('source_not_found', 'Zdrojový doklad nenalezen.', 422);
            }
        }
        if (($data['purchase_invoice_item_id'] ?? null) !== null) {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM purchase_invoice_items pii
                   JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
                  WHERE pii.id = ? AND pi.supplier_id = ?'
            );
            $stmt->execute([$data['purchase_invoice_item_id'], $supplierId]);
            if ($stmt->fetchColumn() === false) {
                throw new PostingException('source_not_found', 'Zdrojový řádek dokladu nenalezen.', 422);
            }
        }
        if (($data['cash_document_id'] ?? null) !== null) {
            $stmt = $pdo->prepare('SELECT 1 FROM cash_documents WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$data['cash_document_id'], $supplierId]);
            if ($stmt->fetchColumn() === false) {
                throw new PostingException('source_not_found', 'Zdrojový pokladní doklad nenalezen.', 422);
            }
        }
        if (($data['vendor_client_id'] ?? null) !== null) {
            $stmt = $pdo->prepare('SELECT 1 FROM clients WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$data['vendor_client_id'], $supplierId]);
            if ($stmt->fetchColumn() === false) {
                throw new PostingException('vendor_not_found', 'Dodavatel nenalezen.', 422);
            }
        }
    }

    /**
     * @return array{document_ref:?string, vendor_id:?int, vendor_name:?string, acquisition_date:string}|null
     */
    private function purchaseInvoiceHeader(int $supplierId, int $purchaseInvoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.vendor_invoice_number, pi.vendor_id, pi.document_kind,
                    pi.parent_purchase_invoice_id, c.company_name AS vendor_name,
                    COALESCE(pi.tax_date, pi.issue_date) AS acquisition_date,
                    pi.exchange_rate, cur.code AS currency
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
               LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
              WHERE pi.id = ? AND pi.supplier_id = ?'
        );
        $stmt->execute([$purchaseInvoiceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        // Karta majetku je vždy v CZK (evidence i práh §26/2 ZDP). Cizoměnovou fakturu
        // (EUR apod.) proto přepočítáváme kurzem dokladu: `částka × kurz`. CZK doklad má
        // kurz 1,0 (fallback při NULL taky 1,0), takže se nic nezmění.
        $currency = (string) ($row['currency'] ?? 'CZK');
        $rate = ($row['exchange_rate'] !== null && (float) $row['exchange_rate'] > 0.0)
            ? (float) $row['exchange_rate'] : 1.0;
        $czkRate = ($currency === 'CZK') ? 1.0 : $rate;
        return [
            'document_ref' => $row['vendor_invoice_number'] !== null ? (string) $row['vendor_invoice_number'] : null,
            'vendor_id' => $row['vendor_id'] !== null ? (int) $row['vendor_id'] : null,
            'vendor_name' => $row['vendor_name'] !== null ? (string) $row['vendor_name'] : null,
            'acquisition_date' => (string) $row['acquisition_date'],
            'document_kind' => (string) ($row['document_kind'] ?? 'invoice'),
            'is_credit_note' => (string) ($row['document_kind'] ?? '') === 'credit_note',
            'parent_purchase_invoice_id' => $row['parent_purchase_invoice_id'] !== null
                ? (int) $row['parent_purchase_invoice_id'] : null,
            'czk_rate' => $czkRate,
        ];
    }
}
