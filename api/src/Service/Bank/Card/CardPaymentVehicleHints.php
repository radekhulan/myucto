<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Card;

use MyInvoice\Service\Logbook\Fuel\FuelKeywords;

/**
 * Nápověda vozidla u plateb kartou na čerpací stanici (přehled „Platby kartou bez
 * dokladu"): karta → držitel → jeho vozidlo přes {@see PaymentCardVehicleResolver}.
 *
 * Čerpací stanice se pozná podle obchodníka: palivové slovo (stejný seznam jako účetní
 * klasifikace PHM, {@see FuelKeywords::isFuelForAccounting()}) nebo název sítě stanic.
 * Nápověda je jen informace — nic nezapisuje.
 */
final class CardPaymentVehicleHints
{
    /** Sítě čerpacích stanic (porovnává se po normalizaci na hranici slova). */
    private const STATION_BRANDS = [
        'shell', 'omv', 'mol', 'benzina', 'orlen', 'eurooil', 'euro oil', 'robin oil', 'tank ono',
        'tankono', 'agip', 'aral', 'lukoil', 'slovnaft', 'avia', 'totalenergies', 'km prona',
        'cerpaci stanice', 'cerpaci st', 'benzinova stanice', 'ionity', 'ccs',
    ];

    public function __construct(private readonly PaymentCardVehicleResolver $vehicles) {}

    /**
     * Doplní ke každému pohybu přehledu `vehicle_hint` (null = nejde o stanici nebo
     * karta / držitel / vozidlo nejsou známé).
     *
     * @param array{groups:list<array<string,mixed>>} $overview výstup CardPaymentOverview::unmatched()
     * @return array<string,mixed>
     */
    public function annotate(int $supplierId, array $overview): array
    {
        foreach ($overview['groups'] as $gi => $group) {
            foreach ($group['transactions'] as $ti => $tx) {
                $overview['groups'][$gi]['transactions'][$ti]['vehicle_hint'] = $this->hintFor($supplierId, $tx);
            }
        }
        return $overview;
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{car_id:int|null, registration:string|null, car_name:string|null, reason:string}|null
     */
    public function hintFor(int $supplierId, array $tx): ?array
    {
        $text = trim((string) ($tx['counterparty_name'] ?? '') . ' ' . (string) ($tx['description'] ?? ''));
        if (!self::looksLikeFuelStation($text)) {
            return null;
        }
        $e = $this->vehicles->explain($supplierId, (string) ($tx['card_last4'] ?? ''), substr((string) ($tx['posted_at'] ?? ''), 0, 10));
        if (!in_array($e['reason'], [PaymentCardVehicleResolver::OK, PaymentCardVehicleResolver::AMBIGUOUS], true)) {
            return null;
        }
        return [
            'car_id'       => $e['car_id'],
            'registration' => $e['registration'],
            'car_name'     => $e['car_name'],
            'reason'       => $e['reason'],
        ];
    }

    public static function looksLikeFuelStation(string $text): bool
    {
        $n = FuelKeywords::normalize(CardNumberMask::stripMasked($text));
        if ($n === '' || FuelKeywords::isNonFuelService($n)) {
            return false;
        }
        $n = (string) preg_replace('/[^a-z0-9]+/', ' ', $n);
        foreach (self::STATION_BRANDS as $brand) {
            if (preg_match('/(?:^| )' . preg_quote($brand, '/') . '(?: |$)/', ' ' . $n . ' ') === 1) {
                return true;
            }
        }
        return FuelKeywords::isFuelForAccounting($n);
    }
}
