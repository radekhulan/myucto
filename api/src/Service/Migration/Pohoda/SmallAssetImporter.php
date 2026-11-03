<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Service\Migration\Shared\SmallAssetCard;

/**
 * Drobný majetek z operativní evidence POHODY (tabulka `DM` v `90_majetek.xml`) do evidence
 * drobného majetku v MyÚčtu. Karta nic neúčtuje - náklad je v převedeném deníku.
 *
 * Zdrojový doklad karty dohledá už exportní nástroj (`DM.RelAgID` je kód agendy: 2 faktury,
 * 27 pokladna, 29 interní doklady; `DM.RefPol` záznam v ní) a přidá ke kartě `SrcAgenda`,
 * `SrcCislo`, `SrcText` a `SrcKc`. Převod podle čísla najde převedenou přijatou fakturu
 * (i ostatní závazek), pokladní nebo interní doklad a u faktury i položku - podle textu,
 * jinak podle částky, vždy jen jednoznačně. Číslo dokladu zůstane na kartě i tehdy, když
 * doklad v převodu není (minulý rok).
 *
 * Karta bez odkazu na doklad (v POHODĚ běžné) se naváže na volnou položku přijaté faktury
 * se stejným datem a stejnou částkou bez DPH (u více kusů cenou za kus); nejednoznačnou
 * vazbu rozhodne název, jinak karta zůstane bez dokladu.
 */
final class SmallAssetImporter
{
    public const STEP = 'small_assets';

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly SmallAssetService $cards,
    ) {}

    public function import(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($ctx->export->path('assets') === null) {
            return;
        }
        $places = [];
        foreach ($ctx->export->records('assets', 'IMmist') as $row) {
            $places[PohodaXml::text($row, 'ID')] = PohodaXml::text($row, 'SText');
        }
        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_SMALL_ASSET);

        foreach ($ctx->export->records('assets', 'DM') as $row) {
            $number = PohodaXml::text($row, 'Cislo');
            $key = 'small_asset|' . ($number !== '' ? $number : '#' . PohodaXml::text($row, 'ID'));
            if (isset($existing[$key])) {
                $p->count(self::STEP, 'existing');
                $this->attachExisting($ctx, (int) $existing[$key], $row);
                continue;
            }
            if ($ctx->skipsNew(self::STEP)) {
                continue;
            }
            $date = PohodaXml::date($row, 'Datum');
            $name = PohodaXml::text($row, 'SText') ?: $number;
            if ($date === null || $name === '') {
                $p->warn(self::STEP, 'small_asset_incomplete', "Karta drobného majetku {$number} nemá datum nebo název, nepřevzata.", ['document_no' => $number]);
                continue;
            }
            $quantity = max(1, (int) PohodaXml::num($row, 'Pocet'));
            $price = round(PohodaXml::num($row, 'Kc'), 2);
            $unitPrice = UnitPricePrecision::normalize(PohodaXml::num($row, 'KcJedn'));
            $disposed = PohodaXml::date($row, 'DatLikv');
            $vendor = PohodaXml::text($row, 'Firma');
            $note = PohodaXml::text($row, 'Pozn');
            $data = SmallAssetCard::payload(
                // Typ drobného majetku (`DM.RelTpDM`) převod nerozlišuje - kódy nejsou ověřené.
                'tangible',
                $name,
                SmallAssetCard::inventoryNumber($number, false),
                $date,
                $date,
                $quantity,
                $unitPrice > 0 ? $unitPrice : UnitPricePrecision::normalize($price / $quantity),
                $price,
                $places[PohodaXml::text($row, 'RefIMmist')] ?? '',
                $disposed,
                'Vyřazeno v POHODĚ',
                $note !== '' ? $note : null,
                ['vendor_name' => $vendor !== '' ? mb_substr($vendor, 0, 255) : null],
            ) + $this->source($ctx, $row);
            if (!isset($data['purchase_invoice_id']) && !isset($data['cash_document_id']) && PohodaXml::text($row, 'SrcAgenda') === '') {
                // POHODA vazbu karty na doklad často nedrží - náhradou jediná volná položka
                // přijaté faktury se stejným datem a částkou bez DPH.
                $match = $this->matchByDateAndAmount($ctx->supplierId, $date, $price, $name);
                if ($match !== null) {
                    $data += $match;
                    $p->count(self::STEP, 'matched_by_amount');
                }
            }

            try {
                $id = $this->cards->create($ctx->supplierId, $data, $ctx->userOrNull());
            } catch (PostingException $e) {
                $p->warn(self::STEP, 'small_asset_rejected', "Karta drobného majetku {$number} nepřevzata: " . $e->getMessage(), ['document_no' => $number]);
                continue;
            }
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_SMALL_ASSET, $key, $id, $ctx->runId);
            $p->count(self::STEP, 'created');
            $p->count(self::STEP, isset($data['purchase_invoice_id']) || isset($data['cash_document_id']) ? 'linked' : 'no_document');
        }
        $this->markSmallAssetItems($ctx->supplierId);
    }

    /**
     * Karta z dřívějšího převodu bez dokladu: opakovaný převod zkusí položku dohledat stejně
     * jako u nové karty (datum a částka, u více kusů cena za kus).
     *
     * @param array<string,mixed> $row
     */
    private function attachExisting(PohodaContext $ctx, int $cardId, array $row): void
    {
        if (PohodaXml::text($row, 'SrcAgenda') !== '') {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT price, name, acquisition_date FROM small_assets
              WHERE id = ? AND supplier_id = ? AND purchase_invoice_id IS NULL AND cash_document_id IS NULL'
        );
        $stmt->execute([$cardId, $ctx->supplierId]);
        $card = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($card === false) {
            return;
        }
        $match = $this->matchByDateAndAmount($ctx->supplierId, (string) $card['acquisition_date'], (float) $card['price'], (string) $card['name']);
        if ($match !== null && $this->cards->attachPurchaseSource($ctx->supplierId, $cardId, $match['purchase_invoice_id'], $match['purchase_invoice_item_id'], $match['document_ref'] ?: null)) {
            $ctx->protocol->count(self::STEP, 'matched_by_amount');
        }
    }

    /**
     * POHODA druh nákladu u položky nevede, převedená položka ho tedy nemá. Položka, ke které
     * patří karta drobného majetku, je náklad na drobný majetek: bez druhu by ji obrat
     * drobného majetku (inventarizace, odklad na 381) neviděl a kontrola úplnosti evidence
     * by hlásila celý rozdíl. Deník se nemění, druh je jen třídění položky.
     */
    private function markSmallAssetItems(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoice_items pii
               JOIN small_assets s ON s.purchase_invoice_item_id = pii.id
                SET pii.expense_kind = IF(s.asset_kind = 'intangible', 'small_intangible', 'small_asset')
              WHERE s.supplier_id = ? AND pii.expense_kind IS NULL"
        )->execute([$supplierId]);
    }

    /**
     * Vazba karty na převedený doklad.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function source(PohodaContext $ctx, array $row): array
    {
        $agenda = PohodaXml::text($row, 'SrcAgenda');
        $number = PohodaXml::text($row, 'SrcCislo');
        if ($agenda === '' || $number === '') {
            return [];
        }
        $out = ['document_ref' => mb_substr($number, 0, 60)];
        if ($agenda === 'HO') {
            if (isset($ctx->cashDocuments[$number])) {
                $out['cash_document_id'] = $ctx->cashDocuments[$number];
            }
            return $out;
        }
        $invoiceId = $agenda === 'FA'
            ? ($ctx->purchaseInvoices[$number] ?? $ctx->commitments[$number] ?? null)
            : ($agenda === 'INT' ? ($ctx->internalPurchases[$number] ?? null) : null);
        if ($invoiceId === null) {
            return $out;
        }
        $out['purchase_invoice_id'] = $invoiceId;
        $item = $this->matchItem($invoiceId, PohodaXml::text($row, 'SrcText'), PohodaXml::num($row, 'SrcKc'));
        if ($item !== null) {
            $out['purchase_invoice_item_id'] = $item;
        }
        return $out;
    }

    /**
     * Volná položka přijaté faktury s datem vystavení nebo DUZP rovným datu karty a částkou
     * bez DPH rovnou ceně karty. Položka s více kusy (4 dokovací stanice na jednom řádku,
     * v POHODĚ 4 karty) se páruje cenou za kus, dokud na ní není karta pro každý kus.
     * Víc kandidátů se rozhodne názvem; stejné položky téže faktury jsou zaměnitelné
     * (3 stejné monitory), karta dostane první volnou.
     *
     * @return array{purchase_invoice_id:int,purchase_invoice_item_id:int,document_ref:string}|null
     */
    private function matchByDateAndAmount(int $supplierId, string $date, float $price, string $name): ?array
    {
        if ($price <= 0) {
            return null;
        }
        $home = ForeignCurrencyTakeover::homeAmountSql('pii.total_without_vat', 'pi.exchange_rate');
        $stmt = $this->db->pdo()->prepare(
            "SELECT pii.id, pi.id AS invoice_id, pi.vendor_invoice_number, pii.description
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pi.supplier_id = ? AND (pi.issue_date = ? OR pi.tax_date = ?)
                AND (
                     (ABS({$home} - ?) < 0.005
                      AND NOT EXISTS (SELECT 1 FROM small_assets s WHERE s.supplier_id = pi.supplier_id AND s.purchase_invoice_item_id = pii.id))
                  OR (pii.quantity > 1 AND ABS({$home} / pii.quantity - ?) < 0.02
                      AND (SELECT COUNT(*) FROM small_assets s WHERE s.supplier_id = pi.supplier_id AND s.purchase_invoice_item_id = pii.id) < pii.quantity)
                )
              ORDER BY pi.id, pii.order_index, pii.id"
        );
        $stmt->execute([$supplierId, $date, $date, $price, $price]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            $normalize = static fn (string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
            $card = $normalize($name);
            $named = $card === '' ? [] : array_values(array_filter($rows, static function (array $r) use ($normalize, $card): bool {
                $text = $normalize((string) $r['description']);
                return $text !== '' && (str_starts_with($text, $card) || str_starts_with($card, $text));
            }));
            $rows = $named !== [] ? $named : $rows;
            $same = count(array_unique(array_map(
                static fn (array $r): string => $r['invoice_id'] . '|' . $normalize((string) $r['description']),
                $rows,
            ))) === 1;
            if (!$same) {
                return null;
            }
        }
        if ($rows === []) {
            return null;
        }
        return [
            'purchase_invoice_id' => (int) $rows[0]['invoice_id'],
            'purchase_invoice_item_id' => (int) $rows[0]['id'],
            'document_ref' => mb_substr((string) $rows[0]['vendor_invoice_number'], 0, 60),
        ];
    }

    /**
     * Položka faktury: jediná se shodným textem, jinak jediná se shodnou částkou bez DPH.
     * Cena karty je v Kč, položka dokladu v cizí měně se porovná přepočtená kurzem dokladu.
     */
    private function matchItem(int $invoiceId, string $text, float $amount): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT pii.id, pii.description, '
            . ForeignCurrencyTakeover::homeAmountSql('pii.total_without_vat', 'pi.exchange_rate') . ' AS total_without_vat
               FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pii.purchase_invoice_id = ? ORDER BY pii.order_index, pii.id');
        $stmt->execute([$invoiceId]);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $normalize = static fn (string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
        $byText = $text === '' ? [] : array_values(array_filter($items, static fn (array $i): bool => $normalize((string) $i['description']) === $normalize($text)));
        if (count($byText) === 1) {
            return (int) $byText[0]['id'];
        }
        $pool = $byText !== [] ? $byText : $items;
        $byAmount = array_values(array_filter($pool, static fn (array $i): bool => abs((float) $i['total_without_vat'] - $amount) < 0.005));
        return count($byAmount) === 1 ? (int) $byAmount[0]['id'] : null;
    }
}
