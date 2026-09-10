<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Card;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PaymentCardRepository;
use MyInvoice\Service\Logbook\CardHolderVehicleResolver;
use PDO;

/**
 * Vozidlo podle platební karty: koncovka + datum → karta platná k datu → držitel
 * (zaměstnanec) → jeho aktivní vozidlo (`cars.driver_employee_id`).
 *
 * Kartu hledá výhradně {@see PaymentCardRepository::findByLast4OnDate()} — tatáž odpověď
 * „čí je platba kartou", jakou používá párování plateb a přehled plateb bez dokladu.
 *
 * Když držitel řídí víc aktivních vozidel, je to nejednoznačné: nepřiřadí se nic
 * a {@see explain()} vrátí důvod. Radši žádné vozidlo než špatné.
 */
final class PaymentCardVehicleResolver implements CardHolderVehicleResolver
{
    public const OK = 'ok';
    public const INVALID_LAST4 = 'invalid_last4';
    public const UNKNOWN_CARD = 'unknown_card';
    public const NO_EMPLOYEE = 'no_employee';
    public const NO_VEHICLE = 'no_vehicle';
    public const AMBIGUOUS = 'ambiguous';

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentCardRepository $cards,
    ) {}

    public function vehicleForCard(int $supplierId, string $cardLast4, string $date): ?int
    {
        return $this->explain($supplierId, $cardLast4, $date)['car_id'];
    }

    /**
     * @return array{car_id:int|null, reason:string, card_id:int|null, employee_id:int|null,
     *               registration:string|null, car_name:string|null, vehicles:int}
     */
    public function explain(int $supplierId, string $cardLast4, string $date): array
    {
        $out = ['car_id' => null, 'reason' => self::INVALID_LAST4, 'card_id' => null, 'employee_id' => null,
                'registration' => null, 'car_name' => null, 'vehicles' => 0];
        if (!CardNumberMask::isValidLast4($cardLast4) || \DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false) {
            return $out;
        }
        $card = $this->cards->findByLast4OnDate($supplierId, $cardLast4, $date);
        if ($card === null) {
            return ['reason' => self::UNKNOWN_CARD] + $out;
        }
        $out['card_id'] = (int) $card['id'];
        $employeeId = $card['employee_id'] ?? null;
        if ($employeeId === null) {
            return ['reason' => self::NO_EMPLOYEE] + $out;
        }
        $out['employee_id'] = (int) $employeeId;

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, registration, name FROM cars
              WHERE supplier_id = ? AND driver_employee_id = ? AND is_archived = 0
              ORDER BY id LIMIT 2'
        );
        $stmt->execute([$supplierId, (int) $employeeId]);
        $cars = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out['vehicles'] = count($cars);
        if ($cars === []) {
            return ['reason' => self::NO_VEHICLE] + $out;
        }
        if (count($cars) > 1) {
            return ['reason' => self::AMBIGUOUS] + $out;
        }
        return [
            'car_id'       => (int) $cars[0]['id'],
            'reason'       => self::OK,
            'registration' => (string) $cars[0]['registration'],
            'car_name'     => $cars[0]['name'] !== null ? (string) $cars[0]['name'] : null,
        ] + $out;
    }
}
