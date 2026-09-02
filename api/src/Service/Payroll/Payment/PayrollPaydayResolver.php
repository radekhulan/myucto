<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Výplatní termín podle mzdové politiky zaměstnavatele.
 *
 * ── Proč to vzniklo ─────────────────────────────────────────────────────────
 * Zaměstnavatel má výplatní termín sjednaný a uložený
 * (`payroll_employer_policies.payday_day` / `payday_month_offset` /
 * `payday_business_day_rule`), ale zakládací formulář mzdového běhu nabízel
 * natvrdo PATNÁCTÉHO následujícího měsíce a politiku nečetl. Kdo si datum
 * nepřepsal ručně, dostal běh s termínem, který u něj neplatí — a datum výplaty
 * není kosmetika: visí na něm splatnost odvodů, lhůty hlášení, sada
 * nezabavitelných částek podle § 4 nař. vlády č. 595/2006 Sb. i mez podle § 141
 * odst. 1 zákoníku práce.
 *
 * Návrh se počítá na serveru, protože posun na pracovní den musí znát STÁTNÍ
 * SVÁTKY ({@see CzechWorkingDays}), ne jen víkend. Prohlížeč je nezná a jeho
 * vlastní verze vzorce by se rozešla s tím, co pak platí pro lhůty.
 */
final class PayrollPaydayResolver
{
    /** Termín, když firma politiku (ještě) nemá. */
    private const FALLBACK_DAY = 15;
    private const FALLBACK_OFFSET = 1;

    public function __construct(
        private readonly PayrollEmployerPolicyRepository $policies,
    ) {}

    /**
     * @param string $periodStart mzdové období, první den měsíce (YYYY-MM-01)
     */
    public function suggest(int $supplierId, string $periodStart): string
    {
        $period = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($period === false || $period->format('Y-m-d') !== $periodStart) {
            throw new \InvalidArgumentException('Mzdové období musí být platné datum.');
        }
        $policy = $this->policies->findEffective($supplierId, $periodStart);
        $day = $policy === null
            ? self::FALLBACK_DAY
            : (int) $policy['payday_day'];
        $offset = $policy === null
            ? self::FALLBACK_OFFSET
            : (int) $policy['payday_month_offset'];
        $rule = $policy === null
            ? 'none'
            : (string) $policy['payday_business_day_rule'];

        $base = $period->modify('+' . $offset . ' month');
        // Politika smí říct „31.“ i pro měsíc, který tolik dní nemá.
        $day = max(1, min($day, (int) $base->format('t')));
        $date = $base->setDate(
            (int) $base->format('Y'),
            (int) $base->format('n'),
            $day,
        );
        $date = self::applyBusinessDayRule($date, $rule);

        // § 141 odst. 1 zákoníku práce: mzda je splatná nejpozději
        // v kalendářním měsíci následujícím po měsíci, ve kterém vzniklo právo
        // na ni. Návrh, který mez překročí, by běh nezaložil
        // ({@see \MyInvoice\Service\Payroll\Run\PayrollRunCommandService}),
        // takže se posune na poslední přípustný pracovní den.
        $latest = $period->modify('last day of next month');
        if ($date > $latest) {
            $date = self::applyBusinessDayRule($latest, 'previous_business_day');
        }
        if ($date < $period) {
            $date = $period;
        }

        return $date->format('Y-m-d');
    }

    private static function applyBusinessDayRule(
        \DateTimeImmutable $date,
        string $rule,
    ): \DateTimeImmutable {
        if ($rule !== 'previous_business_day' && $rule !== 'next_business_day') {
            return $date;
        }
        $step = $rule === 'previous_business_day' ? '-1 day' : '+1 day';
        // Nejdelší souvislá řada nepracovních dnů v ČR je pár dnů; deset kroků
        // je strop proti nekonečné smyčce, ne očekávaný počet.
        for ($i = 0; $i < 10 && !CzechWorkingDays::isWorkingDay($date); ++$i) {
            $date = $date->modify($step);
        }

        return $date;
    }
}
