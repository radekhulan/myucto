<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

/**
 * Klíč, kterým se náklad zákonného pojištění odpovědnosti (548) rozpadá na
 * střediska a firemní dimenze: vyměřovací základy pracovních vztahů za
 * jednotlivé měsíce čtvrtletí a revize, ze které se ty měsíce počítaly.
 *
 * Sazba pojištění je firemní, takže poměr základů je přesně poměr pojistného.
 * Základy jsou tytéž, ze kterých {@see PayrollAccidentInsuranceLiabilityMaterializer}
 * skládá čtvrtletní základ závazku (bez ročního maxima, bez vztahů orgánu).
 *
 * `unattributed_base` nese základ, který k žádnému vztahu revize připsat nejde
 * (převzatý měsíc před `start_period`); jeho podíl zůstane bez dimenzí.
 */
final readonly class PayrollAccidentInsuranceCostKey
{
    /**
     * @param list<array{revision_id:?int,employment_bases:array<int,int>,unattributed_base:int}> $months
     */
    public function __construct(public array $months) {}
}
