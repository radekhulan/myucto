<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

/**
 * Kategorie pohledávky pro rozvrh srážky.
 *
 * Pořadí case'ů NENÍ dekorace — kopíruje větu § 280 odst. 2 o. s. ř., která
 * uvnitř druhé třetiny uspokojuje „bez zřetele na pořadí nejprve pohledávky
 * výživného, poté pohledávky na úhradu úplaty za postupované pohledávky
 * výživného, poté pohledávky výživného, které byly postoupeny, poté pohledávky
 * za náhradní výživné podle jiného zákona a teprve pak podle pořadí podle
 * odstavce 5 ostatní přednostní pohledávky".
 *
 * Postoupené výživné a úplata za ně jsou přitom podle § 279 odst. 2 písm. a)
 * o. s. ř. výslovně součástí přednostních pohledávek výživného. Do 8/2026 pro
 * ně enum případ neměl, takže spadly do {@see self::OtherPriority} a v druhé
 * třetině se uspokojovaly až po náhradním výživném a poměrně podle pořadí
 * doručení — tedy o dvě skupiny níž, než kam patří (nález E-07).
 *
 * Běžné výživné a nedoplatky jsou jedna zákonná skupina „pohledávky výživného"
 * rozdělená až § 280 odst. 3 (nejprve běžné výživné všech oprávněných, teprve
 * pak nedoplatky), proto zůstávají dvěma case'y v tomto pořadí.
 */
enum ClaimCategory: string
{
    case CurrentMaintenance = 'current_maintenance';
    case MaintenanceArrears = 'maintenance_arrears';
    case AssignedMaintenanceConsideration = 'assigned_maintenance_consideration';
    case AssignedMaintenance = 'assigned_maintenance';
    case SubstituteMaintenance = 'substitute_maintenance';
    case OtherPriority = 'other_priority';
    case NonPriority = 'non_priority';

    public function isPriority(): bool
    {
        return $this !== self::NonPriority;
    }

    /**
     * Skupiny druhé třetiny v pořadí podle § 280 odst. 2 o. s. ř. Uvnitř každé
     * z nich se podle § 280 odst. 3 dělí poměrně podle běžného výživného,
     * proto všechny vyžadují váhu {@see self::requiresMaintenanceWeight()}.
     *
     * @return list<self>
     */
    public static function maintenanceCategories(): array
    {
        return [
            self::CurrentMaintenance,
            self::MaintenanceArrears,
            self::AssignedMaintenanceConsideration,
            self::AssignedMaintenance,
            self::SubstituteMaintenance,
        ];
    }

    public function requiresMaintenanceWeight(): bool
    {
        return in_array($this, self::maintenanceCategories(), true);
    }

    /** @return list<self> */
    public static function paymentPriorityOrder(): array
    {
        return [
            ...self::maintenanceCategories(),
            self::OtherPriority,
            self::NonPriority,
        ];
    }

    public function paymentPriorityRank(): int
    {
        $rank = array_search($this, self::paymentPriorityOrder(), true);
        if ($rank === false) {
            throw new \LogicException('Kategorie pohledávky nemá pořadí platby.');
        }

        return $rank;
    }
}
