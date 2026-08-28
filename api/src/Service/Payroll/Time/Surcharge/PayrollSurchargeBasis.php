<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

/**
 * Z čeho se příplatek počítá.
 *
 * Dvě hodnoty, protože zákoník práce zná dva různé základy a plete se to:
 *
 *  - `average_earning` — průměrný výdělek zaměstnance (§ 114, § 115, § 116, § 118).
 *    Hodinový, zjištěný podle § 353 a násl., už s podlahou minimální mzdy
 *    podle § 357 odst. 1.
 *  - `minimum_wage_hourly` — ZÁKLADNÍ SAZBA MINIMÁLNÍ MZDY (§ 117 odst. 2).
 *    Nesouvisí s výdělkem zaměstnance vůbec: dva lidé se stejným ztěžujícím
 *    vlivem dostanou stejný příplatek, i když jeden vydělává dvakrát tolik.
 *
 * Záměna základu u § 117 je typická vada — u dobře placeného zaměstnance vyrobí
 * mnohonásobný přeplatek, u zaměstnance na minimální mzdě naopak vypadá správně
 * a neprojeví se. Proto je základ součástí rulesetu a čte se, ne odhaduje.
 */
enum PayrollSurchargeBasis: string
{
    case AverageEarning = 'average_earning';
    case MinimumWageHourly = 'minimum_wage_hourly';

    public function label(): string
    {
        return match ($this) {
            self::AverageEarning => 'průměrný výdělek',
            self::MinimumWageHourly => 'základní sazba minimální mzdy',
        };
    }
}
