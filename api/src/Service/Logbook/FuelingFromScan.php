<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\DocumentExtractionRepository;
use MyInvoice\Service\Logbook\Fuel\FuelKeywords;

/**
 * Tankování ze skenu, který se připojil k přijatému nebo pokladnímu dokladu
 * (dávka „připojit skeny k dokladům", uložené AI vytěžení v `document_extractions`).
 *
 * Kdy je sken tankování:
 *   - vytěžení má položky → aspoň jedna je pohonná hmota / nabíjení (u čerpací stanice
 *     podle IČO dodavatele stačí palivové slovo, jinak se matchuje jako v účetnictví
 *     na hranici slova, {@see FuelKeywords::isFuelForAccounting()}); mytí apod. ne,
 *   - vytěžení položky nemá (účtenka jen s částkou) → dodavatel je čerpací stanice
 *     (`clients.is_fuel_station`) nebo účtenka nese SPZ.
 * Dobropis a doklad, na kterém je firma prodávajícím, tankováním nejsou. Firma bez
 * vozidel knihu jízd nevede — nic se nezakládá.
 *
 * Zápis jde výhradně přes {@see FuelingFromExtraction}: doklad, který tankování už má
 * (z pokladny, z faktury, z dřívějšího skenu), se jen doplní, nikdy nezdvojí.
 */
final class FuelingFromScan
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocumentExtractionRepository $extractions,
        private readonly CarRepository $cars,
        private readonly FuelingFromExtraction $fromExtraction,
    ) {}

    /**
     * @return array<string,mixed>|null výsledek {@see FuelingFromExtraction::fromExtraction()},
     *                                  null = doklad ani sken tankování nejsou
     */
    public function afterAttach(int $supplierId, string $targetType, int $targetId, int $documentId, ?int $userId = null): ?array
    {
        $ref = match ($targetType) {
            'purchase_invoice' => FuelingDocumentRef::purchaseInvoice($targetId),
            'cash_document'    => FuelingDocumentRef::cashDocument($targetId),
            default            => null,
        };
        if ($ref === null || $this->cars->countActive($supplierId) === 0) {
            return null;
        }
        $extraction = $this->extractions->findForDocument($supplierId, $documentId);
        if ($extraction === null) {
            return null;
        }
        $fields = $this->fieldsFor($supplierId, $extraction);
        return $fields === null ? null : $this->fromExtraction->fromExtraction($supplierId, $fields, $ref, $userId);
    }

    /**
     * Pole pro fromExtraction() z uloženého vytěžení, nebo null, když sken tankování není.
     *
     * @param array<string,mixed> $e řádek DocumentExtractionRepository (včetně `payload`)
     * @return array<string,mixed>|null
     */
    public function fieldsFor(int $supplierId, array $e): ?array
    {
        if (($e['company_role'] ?? null) === 'vendor' || ($e['document_kind'] ?? null) === 'credit_note') {
            return null;
        }
        $payload = is_array($e['payload'] ?? null) ? $e['payload'] : [];
        $items = array_values(array_filter(is_array($payload['items'] ?? null) ? $payload['items'] : [], 'is_array'));
        $station = $this->isFuelStation($supplierId, isset($e['vendor_ico']) ? (string) $e['vendor_ico'] : '');
        $plate = isset($e['license_plate']) && $e['license_plate'] !== '' ? (string) $e['license_plate'] : null;

        $fuel = null;
        foreach ($items as $item) {
            $desc = trim((string) ($item['description'] ?? ''));
            if ($desc !== '' && ($station ? FuelKeywords::isFuel($desc) : FuelKeywords::isFuelForAccounting($desc))) {
                $fuel = $item;
                break;
            }
        }
        if ($items !== [] ? $fuel === null : (!$station && $plate === null)) {
            return null;
        }

        $inclVat = !empty($payload['unit_prices_include_vat']);
        $amount = $e['total_with_vat'] ?? $e['amount_due'] ?? null;
        $unitPrice = null;
        if ($fuel !== null) {
            if ($inclVat && is_numeric($fuel['unit_price_without_vat'] ?? null)) {
                $unitPrice = (float) $fuel['unit_price_without_vat'];
            }
            // Víc položek (palivo + zboží): částka tankování je jen palivová položka s DPH.
            if (count($items) > 1) {
                $amount = self::lineGross($fuel, $inclVat) ?? $amount;
            }
        }

        return [
            'date'           => $e['tax_date'] ?? $e['issue_date'] ?? null,
            'amount'         => $amount,
            'currency'       => $e['currency'] ?? null,
            'liters'         => $fuel !== null && is_numeric($fuel['quantity'] ?? null) ? (float) $fuel['quantity'] : null,
            'unit'           => $fuel['unit'] ?? null,
            'unit_price'     => $unitPrice,
            'fuel_type'      => $fuel !== null ? trim((string) ($fuel['description'] ?? '')) : null,
            'plate'          => $plate,
            'station'        => $e['vendor_name'] ?? null,
            'station_ic'     => $e['vendor_ico'] ?? null,
            'card_last4'     => $e['card_last4'] ?? null,
            'receipt_number' => $e['document_number'] ?? null,
        ];
    }

    /** Celková cena položky s DPH (z řádku bez DPH a sazby, nebo z ceny s DPH). */
    private static function lineGross(array $item, bool $inclVat): ?float
    {
        $line = $item['line_total_without_vat'] ?? null;
        if (!is_numeric($line) && is_numeric($item['quantity'] ?? null) && is_numeric($item['unit_price_without_vat'] ?? null)) {
            $line = (float) $item['quantity'] * (float) $item['unit_price_without_vat'];
        }
        if (!is_numeric($line)) {
            return null;
        }
        $rate = is_numeric($item['vat_rate'] ?? null) ? (float) $item['vat_rate'] : 0.0;
        return round($inclVat ? (float) $line : (float) $line * (1 + $rate / 100), 2);
    }

    private function isFuelStation(int $supplierId, string $ico): bool
    {
        $ico = (string) preg_replace('/\D/', '', $ico);
        if ($ico === '') {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM clients WHERE supplier_id = ? AND ic = ? AND is_fuel_station = 1 LIMIT 1');
        $stmt->execute([$supplierId, $ico]);
        return $stmt->fetchColumn() !== false;
    }
}
