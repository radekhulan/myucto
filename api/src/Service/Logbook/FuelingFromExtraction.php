<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Service\Bank\Card\CardNumberMask;
use MyInvoice\Service\Bank\Card\PaymentCardVehicleResolver;
use MyInvoice\Service\Logbook\Fuel\FuelKeywords;
use PDO;

/**
 * Jediný vstupní bod pro tankování z vytěžené účtenky (sken připojený k dokladu).
 *
 * Volající předá normalizovaná pole vytěžení a odkaz na doklad; služba:
 *   1. ověří, že doklad patří firmě,
 *   2. určí vozidlo přes {@see VehicleResolver} (SPZ → SPZ v textu → karta → výchozí),
 *   3. existuje-li pro doklad už tankování (z pokladny, z faktury), jen doplní chybějící
 *      údaje — druhé nezaloží,
 *   4. jinak založí tankování s deterministickým otiskem z odkazu na doklad, takže
 *      opakované volání nic nezdvojí.
 *
 * Pole ($fields): date, time, amount, amount_without_vat, amount_vat, currency, liters
 * (nebo quantity), unit, unit_price, fuel_type, plate, odometer, station, station_ic,
 * card_last4, receipt_number, text. Povinné je datum a částka (nebo litry × cena za litr).
 * Z karty se ukládá jen koncovka.
 *
 * Tankování je evidenční vrstva — nic neúčtuje.
 */
final class FuelingFromExtraction
{
    public function __construct(
        private readonly Connection $db,
        private readonly FuelingRepository $fuelings,
        private readonly VehicleResolver $vehicles,
        private readonly PaymentCardVehicleResolver $cardVehicles,
    ) {}

    /**
     * @param array<string,mixed> $fields
     * @return array{ok:bool, status:string, fueling_id:int|null, car_id:int|null, car_method:string,
     *               card_reason?:string, error?:string}
     *         status: created | updated | unchanged | ambiguous | invalid | rejected
     */
    public function fromExtraction(int $supplierId, array $fields, FuelingDocumentRef $ref, ?int $userId = null): array
    {
        $base = ['ok' => false, 'fueling_id' => null, 'car_id' => null, 'car_method' => 'none'];
        if (!$this->documentBelongs($supplierId, $ref)) {
            return ['status' => 'rejected', 'error' => 'Doklad nenalezen.'] + $base;
        }
        try {
            $f = self::normalize($fields);
        } catch (\InvalidArgumentException $e) {
            return ['status' => 'invalid', 'error' => $e->getMessage()] + $base;
        }

        $vehicle = $this->vehicles->resolve($supplierId, [
            'plate'      => $f['plate'],
            'text'       => $f['text'],
            'card_last4' => $f['card_last4'],
            'date'       => $f['date'],
        ]);
        $meta = ['car_id' => $vehicle['car_id'], 'car_method' => $vehicle['method']];
        if ($f['card_last4'] !== null && !in_array($vehicle['method'], ['plate', 'text', 'card'], true)) {
            $meta['card_reason'] = $this->cardVehicles->explain($supplierId, $f['card_last4'], $f['date'])['reason'];
        }

        $column = $ref->linkColumn();
        $data = [
            'car_id'             => $vehicle['car_id'],
            'car_assigned_by'    => $vehicle['method'],
            'card_last4'         => $f['card_last4'],
            'fueled_date'        => $f['date'],
            'fueled_time'        => $f['time'],
            'fuel_type'          => $f['fuel_type'],
            'quantity'           => $f['quantity'],
            'unit'               => $f['unit'],
            'unit_price'         => $f['unit_price'],
            'amount_without_vat' => $f['amount_without_vat'],
            'amount_vat'         => $f['amount_vat'],
            'amount_with_vat'    => $f['amount'],
            'currency'           => $f['currency'],
            'odometer'           => $f['odometer'],
            'station'            => $f['station'],
            'vendor_id'          => $this->stationClientId($supplierId, $f['station_ic']),
            'source'             => $ref->fuelingSource(),
            'receipt_number'     => $f['receipt_number'],
            'raw_text'           => $f['text'],
            'dedup_hash'         => $ref->dedupHash($supplierId),
        ];
        if ($column !== null) {
            $data[$column] = $ref->id;
        }

        // Tankování k tomuto dokladu už je (z pokladny, z faktury, dřívější vytěžení)?
        if ($column !== null) {
            $existing = $this->fuelings->findByDocumentLink($supplierId, $column, $ref->id);
            if ($existing !== []) {
                $target = self::pickExisting($existing, $f['date'], $f['amount']);
                if ($target === null) {
                    return ['ok' => true, 'status' => 'ambiguous'] + $meta + ['fueling_id' => null];
                }
                $changed = $this->fuelings->fillMissing($target['id'], $supplierId, $data);
                $row = $this->fuelings->find($target['id'], $supplierId);
                return ['ok' => true, 'status' => $changed ? 'updated' : 'unchanged', 'fueling_id' => $target['id']]
                    + ['car_id' => $row['car_id'] ?? null, 'car_method' => $row['car_assigned_by'] ?? $vehicle['method']]
                    + $meta;
            }
        }

        $r = $this->fuelings->insertScanned($supplierId, $data, $userId);
        $status = $r > 0 ? 'created' : ($r < 0 ? 'updated' : 'unchanged');
        return ['ok' => true, 'status' => $status, 'fueling_id' => $r > 0 ? $r : null] + $meta;
    }

    /**
     * Normalizace polí vytěžení. Neznámé klíče se ignorují, karta se zkrátí na koncovku.
     *
     * @param array<string,mixed> $fields
     * @return array{date:string, time:?string, amount:float, amount_without_vat:?float, amount_vat:?float,
     *               currency:string, quantity:?float, unit:string, unit_price:?float, fuel_type:?string,
     *               plate:?string, odometer:?int, station:?string, station_ic:?string, card_last4:?string,
     *               receipt_number:?string, text:?string}
     */
    public static function normalize(array $fields): array
    {
        $str = static function (string $key, int $max) use ($fields): ?string {
            $v = $fields[$key] ?? null;
            if (!is_scalar($v)) return null;
            $s = trim((string) $v);
            return $s === '' ? null : mb_substr($s, 0, $max);
        };
        $num = static function (string ...$keys) use ($fields): ?float {
            foreach ($keys as $key) {
                $v = $fields[$key] ?? null;
                if (is_int($v) || is_float($v)) return (float) $v;
                if (is_string($v) && trim($v) !== '') {
                    $s = str_replace(["\u{00A0}", ' '], '', trim($v));
                    $s = str_contains($s, ',') && !str_contains($s, '.') ? str_replace(',', '.', $s) : str_replace(',', '', $s);
                    if (is_numeric($s)) return (float) $s;
                }
            }
            return null;
        };

        $date = self::parseDate($str('date', 20));
        if ($date === null) {
            throw new \InvalidArgumentException('Chybí nebo je neplatné datum tankování.');
        }
        $time = $str('time', 8);
        $time = $time !== null && preg_match('/^(\d{1,2}):(\d{2})/', $time, $m) && (int) $m[1] < 24
            ? sprintf('%02d:%02d', (int) $m[1], (int) $m[2]) : null;

        $quantity = $num('liters', 'quantity');
        $unitPrice = $num('unit_price');
        $amount = $num('amount');
        if (($amount === null || $amount <= 0) && $quantity !== null && $unitPrice !== null) {
            $amount = round($quantity * $unitPrice, 2);
        }
        if ($amount === null || $amount <= 0) {
            throw new \InvalidArgumentException('Chybí kladná částka tankování.');
        }
        $amount = round(abs($amount), 2);
        if ($quantity !== null && $quantity <= 0) $quantity = null;
        if ($unitPrice !== null && $unitPrice <= 0) $unitPrice = null;
        if ($quantity !== null && $unitPrice === null) {
            $unitPrice = round($amount / $quantity, 4);
        } elseif ($unitPrice !== null && $quantity === null) {
            $quantity = round($amount / $unitPrice, 3);
        }

        $currency = strtoupper((string) ($str('currency', 3) ?? 'CZK'));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'CZK';
        }
        $fuelType = $str('fuel_type', 60);
        $odometer = $num('odometer');
        $ic = $str('station_ic', 20);
        $ic = $ic !== null ? ((string) preg_replace('/\D/', '', $ic) ?: null) : null;
        $cardRaw = $fields['card_last4'] ?? null;

        return [
            'date'               => $date,
            'time'               => $time,
            'amount'             => $amount,
            'amount_without_vat' => $num('amount_without_vat'),
            'amount_vat'         => $num('amount_vat'),
            'currency'           => $currency,
            'quantity'           => $quantity,
            'unit'               => FuelKeywords::canonicalUnit($str('unit', 10), (string) $fuelType),
            'unit_price'         => $unitPrice,
            'fuel_type'          => $fuelType,
            'plate'              => $str('plate', 20),
            'odometer'           => $odometer !== null && $odometer > 0 ? (int) round($odometer) : null,
            'station'            => $str('station', 150),
            'station_ic'         => $ic,
            'card_last4'         => is_scalar($cardRaw) ? CardNumberMask::normalizeLast4($cardRaw) : null,
            'receipt_number'     => $str('receipt_number', 40),
            'text'               => $str('text', 500),
        ];
    }

    /**
     * Které z existujících tankování dokladu doplnit: jediné, jinak to se stejným datem
     * a částkou. Víc kandidátů bez shody = nejednoznačné (nic se nemění).
     *
     * @param list<array{id:int, fueled_date:string, amount_with_vat:float, car_id:int|null}> $rows
     * @return array{id:int, fueled_date:string, amount_with_vat:float, car_id:int|null}|null
     */
    private static function pickExisting(array $rows, string $date, float $amount): ?array
    {
        if (count($rows) === 1) {
            return $rows[0];
        }
        $hits = array_values(array_filter($rows, static fn (array $r): bool
            => $r['fueled_date'] === $date && abs($r['amount_with_vat'] - $amount) < 0.01));
        return count($hits) === 1 ? $hits[0] : null;
    }

    private function documentBelongs(int $supplierId, FuelingDocumentRef $ref): bool
    {
        if ($ref->type === 'bank_transaction') {
            return $this->fuelings->bankTransactionBelongs($supplierId, $ref->id);
        }
        $table = match ($ref->type) {
            'purchase_invoice' => 'purchase_invoices',
            'cash_document'    => 'cash_documents',
            'journal_entry'    => 'journal_entries',
            default            => 'documents',
        };
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM ' . $table . ' WHERE id = ? AND supplier_id = ? LIMIT 1');
        $stmt->execute([$ref->id, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    private function stationClientId(int $supplierId, ?string $ic): ?int
    {
        if ($ic === null) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT MIN(id) FROM clients WHERE supplier_id = ? AND ic = ? AND is_fuel_station = 1'
        );
        $stmt->execute([$supplierId, $ic]);
        $id = $stmt->fetchColumn();
        return $id !== false && $id !== null ? (int) $id : null;
    }

    private static function parseDate(?string $s): ?string
    {
        if ($s === null) return null;
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]) : null;
        }
        if (preg_match('#^(\d{1,2})\s*[.\-/]\s*(\d{1,2})\s*[.\-/]\s*(\d{4})#', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                ? sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]) : null;
        }
        return null;
    }
}
