<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Service\Payroll\Absence\AbsenceRuleset;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Lhůty NEMPRI a HZUPN podle zákona č. 187/2006 Sb., o nemocenském pojištění.
 *
 * ## Doslovné znění, ze kterého se počítá
 *
 * **§ 97 odst. 1 věta první** — „Zaměstnavatel je povinen přijímat žádosti
 * podle § 109 odst. 1 písm. b) bodu 1 svých zaměstnaných osob o dávky,
 * s výjimkou nemocenského, a další podklady potřebné pro stanovení nároku na
 * dávky a jejich výplatu a NEPRODLENĚ je spolu s údaji potřebnými pro výpočet
 * dávek předávat územní správě sociálního zabezpečení."
 *
 * **§ 97 odst. 1 věta čtvrtá** — „Jde-li o žádost o otcovskou, předává
 * zaměstnavatel oznámení o podání této žádosti územní správě sociálního
 * zabezpečení podle věty první neprodleně po uplynutí podpůrčí doby podle
 * § 38b, a jde-li o žádost o ošetřovné, předává zaměstnavatel oznámení
 * o podání této žádosti po uplynutí podpůrčí doby podle § 40 nebo po vydání
 * potvrzení o trvání potřeby ošetřování podle § 69 písm. a)."
 *
 * **§ 97 odst. 2 věta druhá** — „Podklady pro výpočet nemocenského a údaje
 * o způsobu výplaty mzdy, platu nebo odměny zaměstnavatel zasílá územní správě
 * sociálního zabezpečení NEPRODLENĚ PO UPLYNUTÍ PRVNÍCH 14 DNŮ trvání dočasné
 * pracovní neschopnosti nebo trvání nařízené karantény (…)."
 *
 * **§ 97 odst. 3** — „Zaměstnavatel je povinen územní správě sociálního
 * zabezpečení NEPRODLENĚ oznamovat též všechny skutečnosti, které mohou mít
 * vliv na výplatu dávek." (právní základ HZUPN)
 *
 * **§ 97 odst. 5** — „Zaměstnavatel je dále povinen předávat územní správě
 * sociálního zabezpečení NEJPOZDĚJI V NÁSLEDUJÍCÍ PRACOVNÍ DEN PO DNI, KTERÝ
 * JE URČEN PRO VÝPLATU MEZD A PLATŮ, údaje potřebné podle § 44 pro stanovení
 * výše vyrovnávacího příspěvku v těhotenství a mateřství (…)."
 *
 * Podpůrčí doby: § 26 odst. 1 (nemocenské začíná 15. kalendářním dnem trvání
 * DPN), § 38b odst. 1 (otcovská 2 týdny), § 40 odst. 1 (ošetřovné nejdéle
 * 9 kalendářních dnů, u osamělého pojištěnce s dítětem do 16 let 16 dnů).
 *
 * ## Proč se u „neprodleně" termín rovná prvnímu možnému dni
 *
 * Zákon u většiny těchto povinností NESTANOVÍ počet dnů. Dosadit sem
 * osmidenní lhůtu, protože ji zná § 98 odst. 1 pro jinou povinnost, by
 * znamenalo tvrdit, že týdenní prodleva je v pořádku — a to zákon neříká.
 * Termín je proto první den, kdy se povinnost splnit DÁ, posunutý na nejbližší
 * pracovní den (podání do datové schránky o víkendu se stejně zpracuje až
 * v pondělí). Okno takový termín nese s `sourceStatus = derived_immediacy`,
 * takže přehled termínů může říct pravdu o tom, odkud číslo je.
 *
 * Jediná výjimka je vyrovnávací příspěvek: tam zákon den určuje a okno je
 * `statute_verified`.
 *
 * ## Odkud jsou podpůrčí doby a čekací doba
 *
 * Prvních 14 dnů trvání DPN (§ 26 odst. 1 a § 97 odst. 2 věta druhá) je TATÁŽ
 * hodnota, kterou pro náhradu mzdy podle § 192 ZP nese
 * {@see \MyInvoice\Service\Payroll\Absence\AbsenceRuleset::sicknessWindowCalendarDays()} —
 * čte se odsud, ne z vlastní konstanty, jinak by se novela lhůty promítla do
 * výpočtu náhrady, ale ne do termínů tady. Podpůrčí doby otcovské a ošetřovného
 * (§ 38b odst. 1, § 40 odst. 1) svůj protějšek jinde nemají a bydlí v rulesetu
 * jako `sickness_benefit.paternity_support_days`, `sickness_benefit.care_support_days`
 * a `sickness_benefit.care_support_days_lone_carer`.
 */
final class SicknessDeadlinePolicy
{
    public const RULESET_ID = 'cz-sickness-benefit-notification-2026-04.v1';

    public const SOURCE_STATUTE_VERIFIED = 'statute_verified';
    public const SOURCE_DERIVED_IMMEDIACY = 'derived_immediacy';

    private const SOURCES = [
        'law' => '§ 97 odst. 1, 2, 3 a 5 zákona č. 187/2006 Sb.',
        'support_periods' => '§ 26 odst. 1, § 38b odst. 1 a § 40 odst. 1 zákona č. 187/2006 Sb.',
    ];

    /**
     * NENÍ volitelná — {@see \MyInvoice\Tests\Architecture\PayrollRulesetSingleSourceGuardTest}
     * hlídá, že se PHP-DI nikdy nespokojí s vestavěným rulesetem místo
     * administrátorského nastavení.
     */
    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    /**
     * Vzniká z události vůbec nárok na dávku, a tedy povinnost NEMPRI?
     *
     * U nemocenského ne vždy: podpůrčí doba začíná 15. kalendářním dnem trvání
     * neschopnosti nebo karantény (§ 26 odst. 1 zák. č. 187/2006 Sb.) a podklady
     * se zasílají až „po uplynutí prvních 14 dnů" (§ 97 odst. 2 věta druhá).
     * Kratší neschopnost pokryje celou náhrada mzdy podle § 192 ZP, dávka z ní
     * neplyne a ČSSZ zaměstnavatel nic nepředává. Případ vzniká až tehdy, když
     * neschopnost 14. den přesáhne, klidně až prodloužením.
     *
     * Neznámý konec znamená „ještě trvá" — povinnost může vzniknout. Délka okna
     * je tatáž hodnota jako okno náhrady mzdy ({@see AbsenceRuleset::sicknessWindowCalendarDays()}).
     * `$carriedCalendarDays` jsou dny téže neschopnosti před `$incapacityFrom`
     * (převzatá z jiného mzdového programu), které se do trvání počítají.
     *
     * `$workedFirstDay`: odpracoval-li zaměstnanec v den vzniku neschopnosti
     * celou směnu, považuje se podle § 26 odst. 3 za první den neschopnosti
     * následující kalendářní den. Okno 14 dnů se pak počítá od něj — stejně
     * jako okno náhrady mzdy v `PayrollWageProrationService`.
     */
    public function nempriRequired(
        SicknessBenefitKind $kind,
        string $incapacityFrom,
        ?string $incapacityTo,
        int $carriedCalendarDays = 0,
        bool $workedFirstDay = false,
    ): bool {
        if ($kind !== SicknessBenefitKind::Nem || $incapacityTo === null) {
            return true;
        }
        $from = $this->exactDate(
            $incapacityFrom,
            'Den vzniku sociální události musí být datum ve tvaru RRRR-MM-DD.',
        );
        if ($workedFirstDay) {
            $from = $from->modify('+1 day');
        }
        $to = $this->exactDate(
            $incapacityTo,
            'Den skončení sociální události musí být datum ve tvaru RRRR-MM-DD.',
        );
        $duration = (int) $from->diff($to)->format('%r%a') + 1 + max(0, $carriedCalendarDays);

        // Den vzniku před prvním rulesetem má neschopnost převzatá jako historická
        // evidence, která trvá do vedení mezd v MyÚčtu ({@see AbsenceRuleset::forSicknessWindow()}).
        return $duration > AbsenceRuleset::forSicknessWindow($this->rulesets, $incapacityFrom)
            ->sicknessWindowCalendarDays();
    }

    /**
     * Lhůta oznámení NEMPRI.
     *
     * @param string      $incapacityFrom      Den vzniku sociální události
     *                                         (DPN, karantény, potřeby péče).
     * @param string|null $incapacityTo        Den jejího skončení, je-li znám.
     * @param string|null $payrollPaymentDate  Den určený pro výplatu mezd
     *                                         a platů; povinný jen u VPM.
     * @param bool        $loneCarer           Osamělý pojištěnec podle
     *                                         § 40 odst. 1 písm. b).
     * @param bool        $workedFirstDay      V den vzniku události odpracoval
     *                                         celou směnu; uplatní se jen tam,
     *                                         kde to zákon váže
     *                                         ({@see self::firstDayShiftDefersSupport()}).
     * @param bool        $awaitsEventMonthIncome Zaměstnání malého rozsahu nebo
     *                                         DPP ({@see self::awaitsEventMonthIncome()}).
     */
    public function forNempri(
        SicknessBenefitKind $kind,
        string $incapacityFrom,
        ?string $incapacityTo = null,
        ?string $payrollPaymentDate = null,
        bool $loneCarer = false,
        bool $workedFirstDay = false,
        bool $awaitsEventMonthIncome = false,
    ): SicknessNotificationWindow {
        $workedFirstDay = $workedFirstDay && self::firstDayShiftDefersSupport($kind);
        $from = $this->exactDate(
            $incapacityFrom,
            'Den vzniku sociální události musí být datum ve tvaru RRRR-MM-DD.',
        );
        $end = $incapacityTo === null
            ? null
            : $this->exactDate(
                $incapacityTo,
                'Den skončení sociální události musí být datum ve tvaru RRRR-MM-DD.',
            );

        if ($kind === SicknessBenefitKind::Vpm) {
            if ($payrollPaymentDate === null) {
                throw new SicknessException(
                    'nempri_vpm_payment_date_missing',
                    'Lhůta u vyrovnávacího příspěvku běží od dne určeného pro výplatu mezd a platů '
                    . '(§ 97 odst. 5). Bez něj ji spočítat nelze — doplňte výplatní termín '
                    . 'v Nastavení mezd.',
                );
            }
            $payday = $this->exactDate(
                $payrollPaymentDate,
                'Den výplaty mezd musí být datum ve tvaru RRRR-MM-DD.',
            );
            $due = $this->nextWorkingDay($payday);

            return $this->window(
                $payday,
                $due,
                'next_working_day_after_payday',
                '§ 97 odst. 5 zákona č. 187/2006 Sb.',
                self::SOURCE_STATUTE_VERIFIED,
                AbsenceRuleset::forDate($this->rulesets, $payrollPaymentDate),
            );
        }

        // U nemocenského rozhoduje jen okno § 192 ZP; den vzniku před prvním
        // rulesetem nese převzatá neschopnost, viz {@see self::nempriRequired()}.
        $absence = $kind === SicknessBenefitKind::Nem
            ? AbsenceRuleset::forSicknessWindow($this->rulesets, $incapacityFrom)
            : AbsenceRuleset::forDate($this->rulesets, $incapacityFrom);

        [$earliest, $reference] = match ($kind) {
            // § 97 odst. 2 věta druhá: neprodleně PO UPLYNUTÍ prvních 14 dnů,
            // tedy nejdřív 15. kalendářní den trvání DPN (§ 26 odst. 1). Tatáž
            // hodnota jako u náhrady mzdy podle § 192 ZP. Odpracovaná celá
            // směna v den vzniku posouvá první den neschopnosti (§ 26 odst. 3).
            SicknessBenefitKind::Nem => [
                $from->modify('+' . (
                    $absence->sicknessWindowCalendarDays() + ($workedFirstDay ? 1 : 0)
                ) . ' days'),
                '§ 97 odst. 2 věta druhá zákona č. 187/2006 Sb.',
            ],
            // § 97 odst. 1 věta čtvrtá + § 38b odst. 1.
            SicknessBenefitKind::Opp => [
                $from->modify('+' . $absence->paternitySupportDays() . ' days'),
                '§ 97 odst. 1 věta čtvrtá a § 38b odst. 1 zákona č. 187/2006 Sb.',
            ],
            // § 97 odst. 1 věta čtvrtá + § 40 odst. 1. Skončila-li potřeba
            // ošetřování dřív, než podpůrčí doba doběhla, běží lhůta od
            // skutečného skončení — podpůrčí doba je HORNÍ mez, ne pevná délka
            // („nejdéle 9 kalendářních dnů"). Předává se PO skončení péče, tedy
            // nejdřív následující den, stejně jako po uplynutí podpůrčí doby.
            // Vznikla-li potřeba v den už odpracované směny, podpůrčí doba
            // počíná až následujícím dnem (§ 40 odst. 1 věta druhá).
            SicknessBenefitKind::Ose => [
                $this->earlier(
                    $from->modify('+' . (
                        ($loneCarer
                            ? $absence->careSupportDaysLoneCarer()
                            : $absence->careSupportDays())
                        + ($workedFirstDay ? 1 : 0)
                    ) . ' days'),
                    $end?->modify('+1 day'),
                ),
                '§ 97 odst. 1 věta čtvrtá a § 40 odst. 1 zákona č. 187/2006 Sb.',
            ],
            // § 97 odst. 1 věta první: neprodleně po přijetí žádosti. Žádost
            // přijímá zaměstnavatel v den vzniku události, dřív ne.
            SicknessBenefitKind::Ppm, SicknessBenefitKind::Dlo => [
                $from,
                '§ 97 odst. 1 věta první zákona č. 187/2006 Sb.',
            ],
            SicknessBenefitKind::Vpm => [$from, ''],
        };
        // Malý rozsah a DPP: oznámení se zasílá až po zjištění započitatelného
        // příjmu v kalendářním měsíci, v němž sociální událost vznikla
        // (Všeobecné zásady NEMPRI, prijemMalyRozsah). Dřív ho zaměstnavatel
        // nezná, a tedy ani nepodá.
        if ($awaitsEventMonthIncome) {
            $afterEventMonth = $from->modify('first day of next month');
            if ($afterEventMonth > $earliest) {
                $earliest = $afterEventMonth;
                $reference .= ' a Všeobecné zásady NEMPRI (malý rozsah a DPP)';
            }
        }

        return $this->window(
            $earliest,
            CzechWorkingDays::shiftToWorkingDay($earliest),
            'immediately',
            $reference,
            self::SOURCE_DERIVED_IMMEDIACY,
            $absence,
        );
    }

    /**
     * Posouvá odpracovaná celá směna v den vzniku začátek podpůrčí doby?
     * U nemocenského se za první den neschopnosti považuje následující den
     * (§ 26 odst. 3), u ošetřovného počíná podpůrčí doba následujícím dnem
     * (§ 40 odst. 1 věta druhá). U ostatních dávek zákon nic takového nemá.
     */
    public static function firstDayShiftDefersSupport(SicknessBenefitKind $kind): bool
    {
        return $kind === SicknessBenefitKind::Nem || $kind === SicknessBenefitKind::Ose;
    }

    /**
     * Čeká oznámení na příjem z měsíce události? U dohody o provedení práce
     * a u zaměstnání malého rozsahu (případ nese příjem z malého rozsahu)
     * ano: nárok i výši dávky určuje započitatelný příjem v měsíci, kdy
     * sociální událost vznikla, a ten je znám až po jeho skončení.
     *
     * @param array<string,mixed> $context fakta vztahu (`relation_type`)
     * @param array<string,mixed> $row     řádek případu (`small_scope_income_minor`)
     */
    public static function awaitsEventMonthIncome(array $context, array $row): bool
    {
        $smallScope = $row['small_scope_income_minor'] ?? null;

        return ($context['relation_type'] ?? null) === 'dpp'
            || ($smallScope !== null && $smallScope !== '');
    }

    /**
     * Podává se k případu HZUPN? `null` = ano, jinak důvod, proč ne.
     *
     * - HZUPN vzniká jen u nemocenského ({@see SicknessBenefitKind::hasEndOfIncapacityReport()}).
     * - ČSSZ ho od zaměstnavatele chce jen u DPN delší než 14 kalendářních
     *   dnů (§ 109 odst. 1 písm. a) bod 1): kratší kryje náhrada mzdy a dávka
     *   z ní neplyne — totéž pravidlo jako u NEMPRI ({@see self::nempriRequired()}).
     * - Skončilo-li zaměstnání v průběhu DPN, nebo vznikla-li DPN až v ochranné
     *   lhůtě, tiskopis se podle ePortálu ČSSZ nezasílá; vyzve-li k němu OSSZ,
     *   podává se podle výzvy (§ 98 odst. 1) mimo hlídané povinnosti.
     *
     * @return array{code:string,message:string}|null
     */
    public function hzupnNotRequired(
        SicknessBenefitKind $kind,
        string $incapacityFrom,
        ?string $incapacityTo,
        ?string $employmentEnd,
        bool $workedFirstDay = false,
    ): ?array {
        if (!$kind->hasEndOfIncapacityReport()) {
            return [
                'code' => 'hzupn_not_for_benefit_kind',
                'message' => 'Hlášení při ukončení pracovní neschopnosti (HZUPN) se podává jen '
                    . 'u nemocenského. U tohoto druhu dávky žádné nevzniká.',
            ];
        }
        if (!$this->nempriRequired($kind, $incapacityFrom, $incapacityTo, 0, $workedFirstDay)) {
            return [
                'code' => 'hzupn_within_wage_compensation_window',
                'message' => 'Neschopnost nepřesáhla 14 kalendářních dnů, celou ji kryje náhrada mzdy '
                    . 'a nemocenské z ní nevzniká. Hlášení HZUPN zaměstnavatel ČSSZ zasílá jen u '
                    . 'neschopnosti delší než 14 dnů, takže se nepodává.',
            ];
        }
        $end = $employmentEnd === null || trim($employmentEnd) === '' ? null : $employmentEnd;
        if ($end !== null && $incapacityFrom > $end) {
            return [
                'code' => 'hzupn_incapacity_in_protection_period',
                'message' => 'Neschopnost vznikla až po skončení zaměstnání (v ochranné lhůtě). ČSSZ '
                    . 'hlášení HZUPN v tomto případě nepožaduje; vyzve-li k němu OSSZ, podejte ho '
                    . 'podle její výzvy.',
            ];
        }
        if ($end !== null && ($incapacityTo === null || $end <= $incapacityTo)) {
            return [
                'code' => 'hzupn_employment_ended_during_incapacity',
                'message' => 'Zaměstnání skončilo v průběhu neschopnosti, zaměstnanec se do práce '
                    . 'nevrací. ČSSZ hlášení HZUPN v tomto případě nepožaduje; vyzve-li k němu OSSZ, '
                    . 'podejte ho podle její výzvy.',
            ];
        }

        return null;
    }

    /**
     * Lhůta hlášení HZUPN — § 97 odst. 3, „neprodleně".
     *
     * Hlásit se dá teprve tehdy, když je co hlásit: skutečnost, která může mít
     * vliv na výplatu dávky, vzniká skončením pracovní neschopnosti. Bez dne
     * skončení proto lhůta neexistuje a politika ji nevymýšlí.
     *
     * ## Od nástupu, ne od posledního dne neschopnosti
     *
     * HZUPN je hlášení o NÁSTUPU do zaměstnání po skončení neschopnosti: ČSSZ
     * z něj počítá poslední den dávky a skutečnost, kterou hlásí, nastává dnem
     * nástupu. Poslední den neschopnosti (`incapacity_to`) je den PŘED ním.
     * Lhůta proto běží od dne nástupu, je-li v případu zapsaný
     * (`returned_on`, u „nevrátil se" den, ke kterému důvod nastal), jinak od
     * prvního dne po skončení neschopnosti. Dřív běžela od posledního dne
     * neschopnosti, tedy od dne, kdy zaměstnanec ještě byl nemocný a nebylo
     * co hlásit.
     */
    public function forHzupn(
        string $incapacityFrom,
        ?string $incapacityTo,
        ?string $returnedOn = null,
    ): SicknessNotificationWindow {
        $from = $this->exactDate(
            $incapacityFrom,
            'Den vzniku pracovní neschopnosti musí být datum ve tvaru RRRR-MM-DD.',
        );
        if ($incapacityTo === null) {
            throw new SicknessException(
                'hzupn_incapacity_end_missing',
                'Hlášení při ukončení pracovní neschopnosti se podává až po jejím skončení '
                . '(§ 97 odst. 3). Doplňte den skončení neschopnosti.',
            );
        }
        $end = $this->exactDate(
            $incapacityTo,
            'Den skončení pracovní neschopnosti musí být datum ve tvaru RRRR-MM-DD.',
        );
        if ($end < $from) {
            throw new SicknessException(
                'hzupn_incapacity_period_invalid',
                'Den skončení pracovní neschopnosti nesmí předcházet dni jejího vzniku.',
            );
        }

        $return = $returnedOn === null
            ? $end->modify('+1 day')
            : $this->exactDate(
                $returnedOn,
                'Den nástupu do zaměstnání musí být datum ve tvaru RRRR-MM-DD.',
            );
        if ($return < $from) {
            throw new SicknessException(
                'hzupn_return_before_incapacity',
                'Návrat do práce nemůže předcházet vzniku pracovní neschopnosti.',
            );
        }

        return $this->window(
            $return,
            CzechWorkingDays::shiftToWorkingDay($return),
            'immediately_after_return',
            '§ 97 odst. 3 zákona č. 187/2006 Sb.',
            self::SOURCE_DERIVED_IMMEDIACY,
            AbsenceRuleset::forDate($this->rulesets, $incapacityTo),
        );
    }

    private function window(
        \DateTimeImmutable $earliest,
        \DateTimeImmutable $due,
        string $calendarBasis,
        string $legalReference,
        string $sourceStatus,
        AbsenceRuleset $absence,
    ): SicknessNotificationWindow {
        if ($due < $earliest) {
            // Nemůže nastat u dat, která projdou kontrolami výš, ale kdyby se
            // pravidla někdy rozešla, nesmí vzniknout okno, které končí dřív,
            // než začíná — registr povinností takový interval odmítne až
            // hluboko ve validaci a chyba by se přisoudila jinam.
            throw new SicknessException(
                'sickness_notification_window_invalid',
                'Okno pro podání vyšlo prázdné; zkontrolujte data sociální události.',
            );
        }

        return new SicknessNotificationWindow(
            $earliest->format('Y-m-d'),
            $due->format('Y-m-d'),
            $calendarBasis,
            self::RULESET_ID,
            $this->rulesetHash($absence),
            $legalReference,
            $sourceStatus,
        );
    }

    /**
     * Nejbližší pracovní den PO zadaném dni.
     *
     * `shiftToWorkingDay` posouvá dopředu, dokud den není pracovní; volá se
     * proto na den předchozí, aby výsledek nikdy nespadl na sobotu, neděli
     * ani na státní svátek podle zák. č. 245/2000 Sb.
     */
    private function nextWorkingDay(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return CzechWorkingDays::shiftToWorkingDay($date->modify('+1 day'));
    }

    private function earlier(
        \DateTimeImmutable $a,
        ?\DateTimeImmutable $b,
    ): \DateTimeImmutable {
        if ($b === null) {
            return $a;
        }

        return $b < $a ? $b : $a;
    }

    private function rulesetHash(AbsenceRuleset $absence): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-sickness-deadline-policy.v1',
            'ruleset_id' => self::RULESET_ID,
            'nem_waiting_days' => $absence->sicknessWindowCalendarDays(),
            'opp_support_days' => $absence->paternitySupportDays(),
            'ose_support_days' => $absence->careSupportDays(),
            'ose_support_days_lone_carer' => $absence->careSupportDaysLoneCarer(),
            'vpm_due' => 'next_working_day_after_payday',
            'immediacy_due' => 'next_czech_working_day_from_earliest',
            'hzupn_earliest' => 'return_to_work_day',
            'ose_earliest_after_care_end' => 'next_day',
            'nem_first_day_fully_worked' => 'next_day',
            'ose_first_day_fully_worked' => 'next_day',
            'small_scope_and_dpp_earliest' => 'first_day_after_event_month',
            'sources' => self::SOURCES,
        ]));
    }

    private function exactDate(string $value, string $message): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone('Europe/Prague'),
        );
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            throw new SicknessException('sickness_date_invalid', $message);
        }

        return $date;
    }
}
