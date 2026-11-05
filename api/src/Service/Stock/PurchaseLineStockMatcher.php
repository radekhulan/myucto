<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemVendorRepository;
use MyInvoice\Service\Migration\Pohoda\PohodaStock;
use PDO;

/**
 * Páruje řádky přijaté faktury se skladovými kartami podle kódu z řádku:
 *   1. kód dodavatele uložený u karty pro tohoto dodavatele (`stock_item_vendors.vendor_sku`),
 *   2. EAN karty podle platného EAN z pole EAN řádku,
 *   3. kód karty (`stock_items.sku`), jen návrh,
 *   4. EAN karty podle kódu řádku, který je platný EAN a řádek pole EAN nemá, jen návrh.
 *
 * Kroky 1 a 2 se použijí samy (`auto`). Kroky 3 a 4 jsou jen návrh: vlastní kód karty se může
 * shodovat s kódem dodavatele náhodou, kartu musí potvrdit člověk nebo volající výslovně.
 *
 * Nejednoznačná shoda se nepáruje a nepropadá na další krok: dva kandidáti znamenají,
 * že kód o kartě nerozhodl, a slabší shoda by rozpor jen zakryla.
 */
final class PurchaseLineStockMatcher
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockItemVendorRepository $vendors,
    ) {}

    /**
     * @param array<int|string,array{seller_item_code?:?string,item_ean?:?string}> $lines
     * @return array<int|string,array{stock_item_id:int,source:'vendor_sku'|'ean'|'sku'|'code_ean',auto:bool}> jen spárované řádky, se stejnými klíči
     */
    public function match(int $supplierId, int $vendorId, array $lines): array
    {
        $codes = [];
        $eans = [];
        foreach ($lines as $line) {
            [$code, $ean, $codeEan] = self::keys($line);
            if ($code !== null) {
                $codes[$code] = true;
            }
            foreach ([$ean, $codeEan] as $value) {
                if ($value !== null) {
                    $eans[$value] = true;
                }
            }
        }
        if ($codes === [] && $eans === []) {
            return [];
        }

        $byVendorSku = $this->cardsBy('stock_item_vendors v JOIN stock_items si ON si.id = v.stock_item_id AND si.supplier_id = v.supplier_id', 'v.vendor_sku', $supplierId, array_keys($codes), 'v.client_id = ' . $vendorId);
        $bySku = $this->cardsBy('stock_items si', 'si.sku', $supplierId, array_keys($codes));
        $byEan = $this->cardsBy('stock_items si', 'si.ean', $supplierId, array_keys($eans));

        $out = [];
        foreach ($lines as $key => $line) {
            [$code, $ean, $codeEan] = self::keys($line);
            $steps = [
                ['vendor_sku', $code !== null ? ($byVendorSku[$code] ?? []) : [], true],
                ['ean', $ean !== null ? ($byEan[$ean] ?? []) : [], true],
                ['sku', $code !== null ? ($bySku[$code] ?? []) : [], false],
                ['code_ean', $codeEan !== null ? ($byEan[$codeEan] ?? []) : [], false],
            ];
            foreach ($steps as [$source, $cards, $auto]) {
                if ($cards === []) {
                    continue;
                }
                if (count($cards) === 1) {
                    $out[$key] = ['stock_item_id' => $cards[0], 'source' => $source, 'auto' => $auto];
                }
                break;
            }
        }
        return $out;
    }

    /**
     * @param array{seller_item_code?:?string,item_ean?:?string} $line
     * @return array{0:?string,1:?string,2:?string} kód, platný EAN z pole EAN, kód řádku jako platný EAN
     */
    private static function keys(array $line): array
    {
        $code = self::key($line['seller_item_code'] ?? null);
        $eanField = self::key($line['item_ean'] ?? null);
        $ean = $eanField !== null ? PohodaStock::ean($eanField) : null;
        $codeEan = $eanField === null && $code !== null ? PohodaStock::ean($code) : null;
        return [$code, $ean, $codeEan];
    }

    /**
     * Uloží kód dodavatele ke kartě. Člověk tím rozhodl, že kód patří téhle kartě, takže
     * se odebere z jiných karet téhož dodavatele; jinak by shoda zůstala nejednoznačná
     * a řádek by se příště zase nespároval.
     *
     * @return array{old_vendor_sku:?string,moved_from_stock_item_ids:list<int>} stav před zápisem pro audit
     */
    public function remember(int $supplierId, int $vendorId, int $stockItemId, string $code, string $currencyCode): array
    {
        $pdo = $this->db->pdo();
        $others = $pdo->prepare(
            'SELECT stock_item_id FROM stock_item_vendors
              WHERE supplier_id = ? AND client_id = ? AND vendor_sku = ? AND stock_item_id <> ?'
        );
        $others->execute([$supplierId, $vendorId, $code, $stockItemId]);
        $movedFrom = array_map('intval', $others->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($movedFrom !== []) {
            $pdo->prepare(
                'UPDATE stock_item_vendors SET vendor_sku = NULL
                  WHERE supplier_id = ? AND client_id = ? AND vendor_sku = ? AND stock_item_id <> ?'
            )->execute([$supplierId, $vendorId, $code, $stockItemId]);
        }

        $offer = $this->vendors->findByItemAndClient($supplierId, $stockItemId, $vendorId);
        if ($offer !== null) {
            $this->vendors->updateOffer($supplierId, (int) $offer['id'], ['vendor_sku' => $code]);
        } else {
            $this->vendors->add($supplierId, $stockItemId, ['client_id' => $vendorId, 'vendor_sku' => $code, 'currency_code' => $currencyCode]);
        }
        return ['old_vendor_sku' => $offer['vendor_sku'] ?? null, 'moved_from_stock_item_ids' => $movedFrom];
    }

    /**
     * Aktivní karty zboží (ne služby) podle hodnoty sloupce.
     *
     * @param list<string> $values
     * @return array<string,list<int>> klíč = hodnota malými písmeny
     */
    private function cardsBy(string $from, string $column, int $supplierId, array $values, string $extra = '1 = 1'): array
    {
        if ($values === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($values), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT {$column} AS value, si.id
               FROM {$from}
              WHERE si.supplier_id = ? AND {$column} IN ({$marks}) AND {$extra}
                AND si.is_active = 1 AND si.item_type <> 'service'"
        );
        $stmt->execute([$supplierId, ...$values]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[mb_strtolower((string) $row['value'])][] = (int) $row['id'];
        }
        return $out;
    }

    private static function key(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_strtolower($value);
    }
}
