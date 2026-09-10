<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CarRepository;
use PDO;

/**
 * Přiřazení vozidla k tankování z dokladu, importu nebo vytěžené účtenky.
 *
 * Pořadí kroků (první, který rozhodne, vyhrává):
 *   1. explicitní vozidlo (vybrané uživatelem),
 *   2. SPZ z dokladu — porovnává se bez mezer, pomlček a velikosti písmen,
 *      takže „1AB 2345" = „1ab-2345"; zkouší se i SPZ uvedená volně v textu,
 *   3. platební karta → držitel → jeho vozidlo ({@see CardHolderVehicleResolver}),
 *   4. výchozí / jediné aktivní vozidlo firmy (jen když to volající dovolí).
 *
 * Každý dotaz je vázaný na firmu — vozidlo jiné firmy se nepřiřadí nikdy.
 */
final class VehicleResolver
{
    /** @var array<int, array<string,int>> supplier → normalizovaná SPZ → cars.id */
    private array $plates = [];

    public function __construct(
        private readonly Connection $db,
        private readonly CarRepository $cars,
        private readonly ?CardHolderVehicleResolver $byCard = null,
    ) {}

    /**
     * @param array{car_id?:int|null, plate?:string|null, text?:string|null, card_last4?:string|null, date?:string|null} $hints
     * @return array{car_id:int|null, method:string}  method: explicit|plate|text|card|default|none
     */
    public function resolve(int $supplierId, array $hints, bool $allowDefault = true): array
    {
        $explicit = isset($hints['car_id']) && $hints['car_id'] !== '' ? (int) $hints['car_id'] : 0;
        if ($explicit > 0 && $this->cars->find($explicit, $supplierId) !== null) {
            return ['car_id' => $explicit, 'method' => 'explicit'];
        }

        $plates = $this->platesFor($supplierId);
        $plate = self::normalizePlate((string) ($hints['plate'] ?? ''));
        if ($plate !== '' && isset($plates[$plate])) {
            return ['car_id' => $plates[$plate], 'method' => 'plate'];
        }

        $text = self::normalizePlate((string) ($hints['text'] ?? ''));
        if ($text !== '') {
            foreach ($plates as $known => $carId) {
                if (strlen($known) >= 5 && str_contains($text, $known)) {
                    return ['car_id' => $carId, 'method' => 'text'];
                }
            }
        }

        $last4 = substr((string) preg_replace('/\D/', '', (string) ($hints['card_last4'] ?? '')), -4);
        if ($this->byCard !== null && strlen($last4) === 4) {
            $carId = $this->byCard->vehicleForCard($supplierId, $last4, (string) ($hints['date'] ?? date('Y-m-d')));
            if ($carId !== null && $this->cars->find($carId, $supplierId) !== null) {
                return ['car_id' => $carId, 'method' => 'card'];
            }
        }

        if ($allowDefault) {
            $default = $this->cars->defaultCarId($supplierId);
            if ($default !== null) {
                return ['car_id' => $default, 'method' => 'default'];
            }
        }
        return ['car_id' => null, 'method' => 'none'];
    }

    /** Existuje vozidlo s touto SPZ (po normalizaci)? */
    public function carIdByPlate(int $supplierId, string $plate): ?int
    {
        $n = self::normalizePlate($plate);
        return $n === '' ? null : ($this->platesFor($supplierId)[$n] ?? null);
    }

    /** SPZ bez mezer, pomlček a teček, velkými písmeny. */
    public static function normalizePlate(string $plate): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $plate));
    }

    /** @return array<string,int> */
    private function platesFor(int $supplierId): array
    {
        if (!isset($this->plates[$supplierId])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id, registration FROM cars WHERE supplier_id = ? ORDER BY is_archived ASC, id ASC'
            );
            $stmt->execute([$supplierId]);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = self::normalizePlate((string) $row['registration']);
                if ($key !== '' && !isset($map[$key])) {
                    $map[$key] = (int) $row['id'];
                }
            }
            $this->plates[$supplierId] = $map;
        }
        return $this->plates[$supplierId];
    }
}
