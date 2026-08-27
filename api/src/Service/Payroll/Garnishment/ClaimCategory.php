<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

enum ClaimCategory: string
{
    case CurrentMaintenance = 'current_maintenance';
    case MaintenanceArrears = 'maintenance_arrears';
    case SubstituteMaintenance = 'substitute_maintenance';
    case OtherPriority = 'other_priority';
    case NonPriority = 'non_priority';

    public function isPriority(): bool
    {
        return $this !== self::NonPriority;
    }

    /** @return list<self> */
    public static function maintenanceCategories(): array
    {
        return [
            self::CurrentMaintenance,
            self::MaintenanceArrears,
            self::SubstituteMaintenance,
        ];
    }

    public function requiresMaintenanceWeight(): bool
    {
        return in_array($this, self::maintenanceCategories(), true);
    }
}
