<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Repository\Payroll\PayrollMigrationReconciliationRepository;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use Psr\Clock\ClockInterface;

/**
 * JEDINÁ volatelná cesta k převzatým mzdám roku přechodu.
 *
 * ── Proč existuje ───────────────────────────────────────────────────────────
 * Tabulku `payroll_migration_reference_totals` zatím četla jen kontrolní
 * sestava převodu, a četla z ní jen peníze. Rok přechodu ale potřebuje tatáž
 * data ještě dvakrát — evidenční list důchodového pojištění a zpětná evidence
 * plateb převzatého běhu — a obojí potřebuje kromě čísel taky vědět, KDE JE
 * HRANICE mezi převzatým a spočítaným měsícem. Kdyby si to každá sestava
 * načítala sama, měly by tři různé výklady jedné hranice.
 *
 * Pravidlo z AGENTS.md („SSOT musí jít ZAVOLAT") je tady doslova: hranici
 * vykládá {@see PayrollHistoricalPeriodService}, čtení dělá repozitář, tahle
 * třída to jen spojí do {@see PayrollTakeoverYear}, který se dá volat.
 *
 * ── Co se tady NEDĚJE ───────────────────────────────────────────────────────
 * Nic se nepřepočítává, nedopočítává ani nevaliduje proti legislativě. Převzatý
 * měsíc je záznam o tom, co vydal jiný program; jestli z něj jde sestavit
 * zákonný tiskopis, rozhoduje ten, kdo ho sestavuje. Kdyby se tady chybějící
 * údaj domýšlel, dostal by se do tiskopisu jako doložený.
 */
final class PayrollTakeoverReader
{
    public function __construct(
        private readonly PayrollMigrationReconciliationRepository $repository,
        private readonly PayrollHistoricalPeriodService $historical,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Převzaté měsíce jedné osoby za rok, seřazené, s hranicí i spočítanou stranou.
     *
     * Vrací se VŠECHNY pracovní vztahy osoby. Omezení na jeden vztah dělá
     * {@see self::forEmployment()} nebo {@see PayrollTakeoverYear::forEmployment()}
     * nad týmž výsledkem — evidenční list se sestavuje za vztah, ale souběh
     * vztahů je přesně to, co u něj musí být vidět.
     */
    public function forEmployee(int $supplierId, int $employeeId, int $year): PayrollTakeoverYear
    {
        $this->assertScope($supplierId, $year);
        if ($employeeId <= 0) {
            throw new \InvalidArgumentException('Zaměstnanec musí být zvolený.');
        }

        return $this->build(
            $supplierId,
            $year,
            $this->repository->takeoverRows($supplierId, $year, $employeeId),
            $this->repository->calculatedPeriods($supplierId, $year, $employeeId),
            $employeeId,
            null,
        );
    }

    /**
     * Převzaté měsíce jednoho pracovního vztahu za rok.
     *
     * Spočítaná strana zůstává za OSOBU, ne za vztah: čistá mzda je
     * výsledek osoby a rozpočítat ho na vztahy by znamenalo vymyslet si klíč.
     * Odpověď na „počítalo MyÚčto tenhle měsíc?" je proto u všech vztahů osoby
     * stejná, a je to správně — měsíc počítá modul celý, ne po vztazích.
     */
    public function forEmployment(int $supplierId, int $employmentId, int $year): PayrollTakeoverYear
    {
        $this->assertScope($supplierId, $year);
        if ($employmentId <= 0) {
            throw new \InvalidArgumentException('Pracovní vztah musí být zvolený.');
        }
        $rows = $this->repository->takeoverRows($supplierId, $year, null, $employmentId);
        $employeeId = null;
        foreach ($rows as $row) {
            $candidate = is_numeric($row['employee_id'] ?? null) ? (int) $row['employee_id'] : 0;
            if ($candidate > 0) {
                $employeeId = $candidate;
                break;
            }
        }

        return $this->build(
            $supplierId,
            $year,
            $rows,
            $this->repository->calculatedPeriods($supplierId, $year, $employeeId),
            $employeeId,
            $employmentId,
            // Nepřítomnosti s daty jsou druhým doloženým zdrojem převzatého
            // měsíce: z nich evidenční list skládá rozpad vyloučených dob,
            // který převzatý měsíc nenese. Čtou se jen tam, kde nějaký leží.
            $rows === [] ? [] : [$employmentId => $this->repository->approvedAbsences($supplierId, $employmentId, $year)],
            // Trvání vztahu po konci roku: převzaté měsíce roku ho nedoloží,
            // když původní program skončení nevydal ({@see PayrollTakeoverYear::continuationAfterYearEnd()}).
            $rows === [] ? [] : array_filter([
                $employmentId => $this->repository->employmentContinuationAfterYear($supplierId, $employmentId, $year),
            ]),
        );
    }

    /**
     * Převzaté měsíce celé firmy za rok; `$source` omezí na jeden původní systém.
     *
     * Tímhle se plní přehled „co je za rok k dispozici" na obrazovce převodu.
     */
    public function forSupplier(int $supplierId, int $year, ?string $source = null): PayrollTakeoverYear
    {
        $this->assertScope($supplierId, $year);
        if ($source !== null
            && !in_array($source, PayrollMigrationReferenceTotalsWriter::SOURCES, true)
        ) {
            throw new \InvalidArgumentException('Neznámý zdroj převzatých mezd.');
        }

        return $this->build(
            $supplierId,
            $year,
            $this->repository->takeoverRows($supplierId, $year, null, null, $source),
            $this->repository->calculatedPeriods($supplierId, $year),
            null,
            null,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $calculatedPeriods
     */
    private function build(
        int $supplierId,
        int $year,
        array $rows,
        array $calculatedPeriods,
        ?int $employeeId,
        ?int $employmentId,
        array $absences = [],
        array $continuations = [],
    ): PayrollTakeoverYear {
        $months = array_map(PayrollTakeoverMonth::fromRow(...), $rows);
        // Repozitář řadí podle období a identity z původního systému; pořadí se
        // tady jen potvrzuje, aby na ně navazující sestavy mohly spoléhat
        // i tehdy, kdyby řádky přišly odjinud.
        usort(
            $months,
            static fn (PayrollTakeoverMonth $left, PayrollTakeoverMonth $right): int
                => [$left->period, $left->externalRelationshipRef, $left->externalPersonRef]
                <=> [$right->period, $right->externalRelationshipRef, $right->externalPersonRef],
        );

        return new PayrollTakeoverYear(
            $supplierId,
            $year,
            $this->historical->startPeriod($supplierId),
            $months,
            $calculatedPeriods,
            $employeeId,
            $employmentId,
            $this->clock->now()->format('Y-m'),
            $absences,
            $continuations,
        );
    }

    private function assertScope(int $supplierId, int $year): void
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být zvolená.');
        }
        if ($year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException('Mzdový rok musí být v rozsahu 2000 až 2200.');
        }
    }
}
