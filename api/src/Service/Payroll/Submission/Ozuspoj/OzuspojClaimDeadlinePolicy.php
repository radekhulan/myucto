<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Dokdy se sleva na pojistném za zaměstnance dá UPLATNIT a dokdy nejpozději
 * musí být DORUČEN záměr.
 *
 * Jsou to dvě různá data a katalog kontrol MH je taky rozlišuje:
 *
 * * **Doručení záměru** — kontrola 291 bod 2: `ZAMERY_SLEV.DATUM_PRIJETI_FORMULARE`
 *   musí být nejpozději dnem, kdy se sleva uplatní. U období 01–03/2026 se za
 *   den uplatnění považuje poslední den splatnosti pojistného (bod 2a), od
 *   04/2026 den přijetí měsíčního hlášení (bod 2b). Horní mez, kterou zná
 *   výpočet i bez znalosti dne podání, je proto splatnost pojistného podle
 *   § 9 odst. 1 — a § 7c odst. 2 stejně nedovolí slevu uplatnit později.
 *
 * * **Uplatnění slevy** — kontrola 164: po splatnosti pojistného slevu uplatnit
 *   nelze. Pro leden až březen 2026 je kontrola 164 vypnutá a nahrazuje ji
 *   kontrola 333: sleva za ta období se neuzná, je-li hlášení přijaté po
 *   **30. 6. 2026**. Kontrola 290 tutéž hranici používá u opravných hlášení.
 *   Přechodné ustanovení čl. IX bodu 5 zákona č. 360/2025 Sb. k tomu říká, že
 *   za uplatnění slevy podle § 7c odst. 1 se za leden až březen 2026 považuje
 *   její odečtení z pojistného; vykázání v hlášení po 30. 6. 2026 už řádným
 *   uplatněním není.
 *
 * Kontrola 164 je v evaluátoru vedená jako `NotEvaluable`, protože potřebuje
 * atribut 10006 (datum přijetí podání), který přiděluje až ČSSZ. Kontrola 333
 * má od katalogu 1.4.2.11 v atributech a pravidle povinnost příznaku
 * primárního vztahu (shodně s 332) a evaluátor ji vyhodnocuje podle pravidla;
 * časové omezení slevy, o kterém mluví její název a hláška, z hotového XML
 * nevyhodnotí. Uživatel o hranici 30. 6. 2026 tedy jinak nedostane žádné
 * varování — tahle třída je to jediné místo, kde se dá vyhodnotit dopředu.
 */
final class OzuspojClaimDeadlinePolicy
{
    /**
     * Poslední den, kdy se dá sleva za leden až březen 2026 řádně uplatnit.
     * Není odvozený ze splatnosti; stanoví ho kontrola 333 katalogu kontrol MH
     * pevným datem.
     */
    public const TRANSITIONAL_Q1_2026_CLAIM_DEADLINE = '2026-06-30';

    private const TRANSITIONAL_Q1_2026_PERIODS = [
        '2026-01-01',
        '2026-02-01',
        '2026-03-01',
    ];

    /**
     * Nejzazší den doručení oznámení záměru, aby sleva za dané období náležela.
     * Vždy splatnost pojistného — § 7c odst. 2 slevu po ní uplatnit nedovolí,
     * takže pozdější doručení záměru už nemá co doprovodit.
     */
    public function intentDeadlineFor(string $periodStart): string
    {
        return $this->levyDueDate($periodStart);
    }

    /**
     * Nejzazší den, kdy musí být měsíční hlášení se slevou přijaté, aby se
     * sleva za dané období uznala.
     */
    public function claimDeadlineFor(string $periodStart): string
    {
        return $this->isTransitionalQ12026($periodStart)
            ? self::TRANSITIONAL_Q1_2026_CLAIM_DEADLINE
            : $this->levyDueDate($periodStart);
    }

    public function isTransitionalQ12026(string $periodStart): bool
    {
        return in_array(
            $periodStart,
            self::TRANSITIONAL_Q1_2026_PERIODS,
            true,
        );
    }

    private function levyDueDate(string $periodStart): string
    {
        $start = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $periodStart,
            new \DateTimeZone('Europe/Prague'),
        );
        if (!$start instanceof \DateTimeImmutable
            || $start->format('Y-m-d') !== $periodStart
            || $start->format('d') !== '01'
        ) {
            throw new OzuspojException(
                'ozuspoj_period_invalid',
                'Období slevy musí být prvním dnem kalendářního měsíce ve tvaru RRRR-MM-DD.',
            );
        }

        return CzechWorkingDays::shiftToWorkingDay(
            $start->modify('first day of next month')->modify('+19 days'),
        )->format('Y-m-d');
    }
}
