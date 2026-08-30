<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Deadline;

use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Datum v příkazu k úhradě mzdového odvodu — NE zákonný termín.
 *
 * § 9 odst. 2 zákona č. 589/1992 Sb. (a stejně tak § 5 odst. 2 zákona
 * č. 592/1992 Sb. u zdravotního pojištění) váže splnění povinnosti na
 * **PŘIPSÁNÍ** částky na účet instituce, ne na podání příkazu. Zadat příkaz
 * k datu zákonného termínu proto znamená být u každého měsíčního odvodu
 * systematicky v prodlení — peníze dorazí až následující pracovní den.
 *
 * Rezerva = **1 pracovní den**. Zdůvodnění: § 109 odst. 1 zákona č. 370/2017
 * Sb. o platebním styku ukládá poskytovateli plátce připsat částku
 * poskytovateli příjemce nejpozději do konce následujícího pracovního dne
 * (D+1); tuzemský mezibankovní převod tedy prokazatelně stihne jeden pracovní
 * den. Delší rezervu záměrně nevolíme — je to cash-flow zaměstnavatele a dva
 * dny navíc každý měsíc nemají oporu v ničem než v opatrnosti. Kdo si chce
 * nechat víc prostoru (odpolední cut-off vlastní banky), zadá příkaz dřív
 * ručně; datum je doporučené, ne vynucené.
 *
 * Výsledek je vždy pracovní den — příkaz zadaný na sobotu banka stejně
 * zpracuje až v pondělí, čímž by rezerva zmizela.
 *
 * Vstupem je zákonný termín PO posunu na pracovní den
 * ({@see PayrollLevyDeadlineWindow::$dueOn}), protože povinnost je splněna
 * až k němu.
 */
final class PayrollLevyPaymentDate
{
    /** Rezerva na mezibankovní převod v pracovních dnech (D+1). */
    public const TRANSFER_LEAD_WORKING_DAYS = 1;

    /**
     * Druhy mzdových závazků, u kterých lhůta běží na připsání a datum
     * příkazu se tedy předsouvá. Čistá mzda, exekuční srážka ani insolvenční
     * srážka sem NEPATŘÍ: u nich je `due_on` datum výplaty / odvodu podle
     * vlastního režimu a předsouvat ho není na místě.
     *
     * @var array<string,string>
     */
    private const LEVY_BY_LIABILITY_KIND = [
        'health_insurance' => PayrollLevyDeadlinePolicy::HEALTH_INSURANCE,
        'social_insurance' => PayrollLevyDeadlinePolicy::SOCIAL_INSURANCE,
        'advance_tax' => PayrollLevyDeadlinePolicy::ADVANCE_TAX,
        'withholding_tax' => PayrollLevyDeadlinePolicy::WITHHOLDING_TAX,
        'statutory_insurance' => PayrollLevyDeadlinePolicy::ACCIDENT_INSURANCE,
    ];

    public static function levyForLiabilityKind(string $liabilityKind): ?string
    {
        return self::LEVY_BY_LIABILITY_KIND[$liabilityKind] ?? null;
    }

    public static function isLevyLiabilityKind(string $liabilityKind): bool
    {
        return isset(self::LEVY_BY_LIABILITY_KIND[$liabilityKind]);
    }

    /**
     * Datum příkazu k úhradě odvozené od zákonného termínu `Y-m-d`.
     *
     * @param int|null $leadWorkingDays rezerva; `null` = výchozí D+1
     */
    public static function forDueOn(
        string $dueOn,
        ?int $leadWorkingDays = null,
    ): string {
        $lead = $leadWorkingDays ?? self::TRANSFER_LEAD_WORKING_DAYS;
        if ($lead < 0) {
            throw new \InvalidArgumentException(
                'Rezerva na převod nemůže být záporná.',
            );
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
        if ($date === false || $date->format('Y-m-d') !== $dueOn) {
            throw new \InvalidArgumentException(
                'Zákonný termín odvodu není platné datum.',
            );
        }
        // Sám termín musí být pracovní den, jinak by se rezerva „projedla"
        // víkendem, který banka stejně nezpracuje.
        while (!CzechWorkingDays::isWorkingDay($date)) {
            $date = $date->modify('-1 day');
        }
        for ($i = 0; $i < $lead; $i++) {
            do {
                $date = $date->modify('-1 day');
            } while (!CzechWorkingDays::isWorkingDay($date));
        }

        return $date->format('Y-m-d');
    }
}
