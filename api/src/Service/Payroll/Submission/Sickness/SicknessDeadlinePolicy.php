<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
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
 */
final class SicknessDeadlinePolicy
{
    public const RULESET_ID = 'cz-sickness-benefit-notification-2026-04.v1';

    public const SOURCE_STATUTE_VERIFIED = 'statute_verified';
    public const SOURCE_DERIVED_IMMEDIACY = 'derived_immediacy';

    /** § 26 odst. 1 a § 97 odst. 2 věta druhá: prvních 14 dnů trvání DPN. */
    private const NEM_WAITING_DAYS = 14;

    /** § 38b odst. 1: podpůrčí doba u otcovské činí 2 týdny. */
    private const OPP_SUPPORT_DAYS = 14;

    /** § 40 odst. 1 písm. a): podpůrčí doba u ošetřovného nejdéle 9 dnů. */
    private const OSE_SUPPORT_DAYS = 9;

    /** § 40 odst. 1 písm. b): osamělý pojištěnec s dítětem do 16 let. */
    private const OSE_SUPPORT_DAYS_LONE_CARER = 16;

    private const SOURCES = [
        'law' => '§ 97 odst. 1, 2, 3 a 5 zákona č. 187/2006 Sb.',
        'support_periods' => '§ 26 odst. 1, § 38b odst. 1 a § 40 odst. 1 zákona č. 187/2006 Sb.',
    ];

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
     */
    public function forNempri(
        SicknessBenefitKind $kind,
        string $incapacityFrom,
        ?string $incapacityTo = null,
        ?string $payrollPaymentDate = null,
        bool $loneCarer = false,
    ): SicknessNotificationWindow {
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
            );
        }

        [$earliest, $reference] = match ($kind) {
            // § 97 odst. 2 věta druhá: neprodleně PO UPLYNUTÍ prvních 14 dnů,
            // tedy nejdřív 15. kalendářní den trvání DPN (§ 26 odst. 1).
            SicknessBenefitKind::Nem => [
                $from->modify('+' . self::NEM_WAITING_DAYS . ' days'),
                '§ 97 odst. 2 věta druhá zákona č. 187/2006 Sb.',
            ],
            // § 97 odst. 1 věta čtvrtá + § 38b odst. 1.
            SicknessBenefitKind::Opp => [
                $from->modify('+' . self::OPP_SUPPORT_DAYS . ' days'),
                '§ 97 odst. 1 věta čtvrtá a § 38b odst. 1 zákona č. 187/2006 Sb.',
            ],
            // § 97 odst. 1 věta čtvrtá + § 40 odst. 1. Skončila-li potřeba
            // ošetřování dřív, než podpůrčí doba doběhla, běží lhůta od
            // skutečného skončení — podpůrčí doba je HORNÍ mez, ne pevná délka
            // („nejdéle 9 kalendářních dnů").
            SicknessBenefitKind::Ose => [
                $this->earlier(
                    $from->modify('+' . (
                        $loneCarer
                            ? self::OSE_SUPPORT_DAYS_LONE_CARER
                            : self::OSE_SUPPORT_DAYS
                    ) . ' days'),
                    $end,
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

        return $this->window(
            $earliest,
            CzechWorkingDays::shiftToWorkingDay($earliest),
            'immediately',
            $reference,
            self::SOURCE_DERIVED_IMMEDIACY,
        );
    }

    /**
     * Lhůta hlášení HZUPN — § 97 odst. 3, „neprodleně".
     *
     * Hlásit se dá teprve tehdy, když je co hlásit: skutečnost, která může mít
     * vliv na výplatu dávky, vzniká skončením pracovní neschopnosti. Bez dne
     * skončení proto lhůta neexistuje a politika ji nevymýšlí.
     */
    public function forHzupn(
        string $incapacityFrom,
        ?string $incapacityTo,
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

        return $this->window(
            $end,
            CzechWorkingDays::shiftToWorkingDay($end),
            'immediately',
            '§ 97 odst. 3 zákona č. 187/2006 Sb.',
            self::SOURCE_DERIVED_IMMEDIACY,
        );
    }

    private function window(
        \DateTimeImmutable $earliest,
        \DateTimeImmutable $due,
        string $calendarBasis,
        string $legalReference,
        string $sourceStatus,
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
            $this->rulesetHash(),
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

    private function rulesetHash(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-sickness-deadline-policy.v1',
            'ruleset_id' => self::RULESET_ID,
            'nem_waiting_days' => self::NEM_WAITING_DAYS,
            'opp_support_days' => self::OPP_SUPPORT_DAYS,
            'ose_support_days' => self::OSE_SUPPORT_DAYS,
            'ose_support_days_lone_carer' => self::OSE_SUPPORT_DAYS_LONE_CARER,
            'vpm_due' => 'next_working_day_after_payday',
            'immediacy_due' => 'next_czech_working_day_from_earliest',
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
