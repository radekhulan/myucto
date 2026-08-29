<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

final readonly class GarnishmentBatchCalculator
{
    public function __construct(private GarnishmentCalculator $calculator) {}

    /**
     * Back pay attributable to different calendar months must be supplied as
     * separate inputs; this method deliberately never merges their income.
     *
     * ## Zůstatky pohledávek se přenášejí mezi obdobími
     *
     * § 276 o. s. ř.: „Srážky ze mzdy lze provádět jen do výše výkonem
     * rozhodnutí vymáhané pohledávky s příslušenstvím." Do 8/2026 se každé
     * období počítalo ze STEJNÝCH zůstatků, které přišly ve vstupu, a mezi
     * obdobími se nesnižovaly. Doplatek rozpuštěný do tří měsíců proto téže
     * pohledávce přidělil až 3× celý zůstatek: povinnému se srazilo víc, než
     * kolik dlužil, a v závazcích vznikly tři platby na jeden dluh, který
     * skončil po první z nich (nález E-04).
     *
     * Období se proto počítají v POŘADÍ (`YYYY-MM` vzestupně) a zůstatek každé
     * pohledávky se před dalším obdobím sníží o to, co jí předchozí období
     * skutečně přiznalo — tedy o částku PO ukrojení paušální náhrady nákladů
     * plátce mzdy, protože právě tolik dojde oprávněnému (§ 270 odst. 3
     * o. s. ř.). Přenáší se minimum z přeneseného a zadaného zůstatku, aby
     * volající mohl dodat i vlastní, už sníženou evidenci.
     *
     * Vstupy jednoho období jsou navzájem nezávislé; přenos zůstatků platí jen
     * v rámci jednoho volání pro jednu osobu.
     *
     * @param list<GarnishmentInput> $inputs
     * @return array<string, GarnishmentResult>
     */
    public function calculate(array $inputs): array
    {
        $ordered = [];
        foreach ($inputs as $input) {
            if (isset($ordered[$input->period])) {
                throw new InvalidArgumentException("Duplicate garnishment period {$input->period}.");
            }
            $ordered[$input->period] = $input;
        }
        ksort($ordered, SORT_STRING);

        /** @var array<string, int> $carried */
        $carried = [];
        $results = [];
        foreach ($ordered as $period => $input) {
            $claims = [];
            foreach ($input->claims as $claim) {
                $claims[] = isset($carried[$claim->id])
                    ? $claim->withOutstanding(
                        min($claim->outstandingMinorUnits, $carried[$claim->id]),
                    )
                    : $claim;
            }
            $input = $input->withClaims($claims);

            $result = $this->calculator->calculate($input);
            $results[$period] = $result;

            foreach ($claims as $claim) {
                $carried[$claim->id] = $claim->outstandingMinorUnits;
            }
            foreach ($result->allocations as $allocation) {
                if (!isset($carried[$allocation->claimId])) {
                    // Oddlužení posílá celou srážku správci pod klíčem, který
                    // žádné pohledávce neodpovídá — není co snižovat.
                    continue;
                }
                $carried[$allocation->claimId] = max(
                    0,
                    $carried[$allocation->claimId] - $allocation->totalMinorUnits,
                );
            }
        }

        return $results;
    }
}
