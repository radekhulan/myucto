<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Lhůty podání zdravotní pojišťovně.
 *
 * Základní lhůta hromadného oznámení je osm DNŮ od vzniku skutečnosti, ne
 * osm pracovních dnů — § 10 zákona č. 48/1997 Sb. mluví o dnech. Dvě výjimky
 * (dohody a mateřská/rodičovská) posouvají lhůtu na 20. den následujícího
 * měsíce; obě jsou doložené publikacemi VZP, ne textem zákona, a katalog to
 * u nich říká stavem pramene.
 *
 * Přehled o platbě má lhůtu shodnou se splatností pojistného, tedy 20. den
 * následujícího kalendářního měsíce podle § 25 odst. 3 zákona č. 592/1992 Sb.
 *
 * ── Posun na pracovní den ───────────────────────────────────────────────────
 * U PŘEHLEDU O PLATBĚ se posun modeluje, u osmidenního hromadného oznámení ne.
 * Rozdíl není nedůslednost, ale výsledek toho, že jde o dvě lhůty ze dvou
 * zákonů:
 *
 * - Přehled o platbě i splatnost pojistného plynou z TÉHOŽ zákona
 *   č. 592/1992 Sb. (§ 25 odst. 3 a § 5 odst. 2) a jsou to lhůty v řízení,
 *   které se podle § 26c téhož zákona řídí správním řádem; na ně dopadá
 *   § 40 odst. 1 písm. c) zákona č. 500/2004 Sb. — připadne-li konec lhůty na
 *   sobotu, neděli nebo svátek, je posledním dnem nejbližší příští pracovní
 *   den. `PayrollLevyDeadlinePolicy::HEALTH_INSURANCE` (odvod) se stejným
 *   pramenem posouvá odjakživa; přehled se neposouval, takže aplikace 3–4×
 *   ročně hlásila „po termínu" u podání, které po termínu není. Obě lhůty jsou
 *   nadále počítané ze stejného pravidla i stejného pramene posunu.
 * - Osmidenní lhůta hromadného oznámení plyne ze zákona č. 48/1997 Sb.
 *   (§ 10) a ZÁMĚRNĚ se neposouvá: pramen k tomu v repozitáři není a chyba na
 *   stranu dřívějšího podání je bezpečná, kdežto posun dopředu bez opory může
 *   znamenat penále. Totéž platí pro obě výjimky odvozené jen z metodiky VZP.
 *
 * Kromě posunutého `dueOn` okno nese i `statutoryDueOn` (zákonný den PŘED
 * posunem) a `dueShift`, aby bylo v přehledu termínů poznat, proč se datum
 * liší od holého 20.
 */
final class HealthNotificationDeadlinePolicy
{
    /**
     * ID se ZÁMĚRNĚ nebumpuje na `.v2`: pravidlo hromadného oznámení se nemění
     * a migrace 1623 tímhle ID označkovala existující checklistové položky.
     * Přejmenovat ho bez datové migrace by z nich udělalo odkazy na neexistující
     * ruleset. Změnu pravidla nese `rulesetHash()`, který posun zahrnuje.
     */
    public const RULESET_ID = 'cz-health-insurance-notification-deadlines.v1';

    private const BASIS_CALENDAR_DAYS = 'calendar_days';

    private const NOTIFICATION_DAYS = 8;
    private const MONTHLY_DUE_DAY = 20;

    /** Posun konce lhůty na nejbližší následující český pracovní den. */
    public const SHIFT_WORKING_DAY = 'next_czech_working_day';
    /** Lhůta, která se na pracovní den neposouvá. */
    public const SHIFT_NONE = 'none';

    private const SOURCE_NOTIFICATION = '§ 10 zákona č. 48/1997 Sb.';
    private const SOURCE_PAYMENT_OVERVIEW =
        '§ 25 odst. 3 zákona č. 592/1992 Sb.';
    private const SOURCE_CORRECTIVE_PAYMENT_OVERVIEW =
        '§ 25 odst. 4 zákona č. 592/1992 Sb.';
    private const SOURCE_WORKING_DAY_SHIFT =
        '§ 40 odst. 1 písm. c) zákona č. 500/2004 Sb. '
        . '(§ 26c zákona č. 592/1992 Sb.)';
    private const SOURCE_AGREEMENT_EXCEPTION =
        'metodika VZP k oznamovací povinnosti u dohod (DPP a DPČ)';
    private const SOURCE_PARENTAL_EXCEPTION =
        'metodika VZP k souhrnnému oznámení zahájení a ukončení mateřské '
        . 'a rodičovské dovolené';

    private const STATUTE_VERIFIED = 'statute_verified';
    private const EXTERNAL_UNVERIFIED = 'external_unverified';

    /**
     * Druhy vztahu, u kterých se místo osmi dnů uplatní 20. den následujícího
     * měsíce. Vazba na doménové názvy vztahů, ne na volný text.
     */
    private const AGREEMENT_RELATION_TYPES = ['dpp', 'dpc'];

    /** Povinnosti, které se podle metodiky VZP oznamují souhrnně měsíčně. */
    private const MONTHLY_DUTY_KINDS = [
        HealthNotificationDutyKind::MaternityLeaveStart,
        HealthNotificationDutyKind::ParentalLeaveStart,
        HealthNotificationDutyKind::MaternityOrParentalLeaveEnd,
    ];

    /** Lhůta hromadného oznámení pro jednu oznamovanou skutečnost. */
    public function forNotification(
        HealthNotificationDutyKind $kind,
        string $occurredOn,
        ?string $relationType = null,
    ): HealthNotificationDeadlineWindow {
        $occurred = $this->date($occurredOn);
        if (in_array($kind, self::MONTHLY_DUTY_KINDS, true)) {
            return $this->window(
                $occurredOn,
                $this->twentiethOfNextMonth($occurred),
                self::SOURCE_PARENTAL_EXCEPTION,
                self::EXTERNAL_UNVERIFIED,
            );
        }
        if ($relationType !== null
            && in_array($relationType, self::AGREEMENT_RELATION_TYPES, true)
        ) {
            return $this->window(
                $occurredOn,
                $this->twentiethOfNextMonth($occurred),
                self::SOURCE_AGREEMENT_EXCEPTION,
                self::EXTERNAL_UNVERIFIED,
            );
        }

        return $this->window(
            $occurredOn,
            $occurred
                ->add(new \DateInterval('P' . self::NOTIFICATION_DAYS . 'D'))
                ->format('Y-m-d'),
            self::SOURCE_NOTIFICATION,
            self::STATUTE_VERIFIED,
        );
    }

    /** Lhůta přehledu o platbě pojistného za mzdové období `YYYY-MM`. */
    public function forPaymentOverview(
        string $period,
    ): HealthNotificationDeadlineWindow {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new HealthNotificationException(
                'zp_period_invalid',
                'Mzdové období přehledu o platbě musí mít tvar RRRR-MM.',
            );
        }
        $periodEnd = $this->date($period . '-01')
            ->modify('last day of this month');
        if ($periodEnd === false) {
            throw new HealthNotificationException(
                'zp_period_invalid',
                'Mzdové období přehledu o platbě není platné.',
            );
        }

        $statutoryDueOn = $this->twentiethOfNextMonth($periodEnd);

        return $this->window(
            $periodEnd->format('Y-m-d'),
            CzechWorkingDays::shiftToWorkingDay(
                new \DateTimeImmutable($statutoryDueOn),
            )->format('Y-m-d'),
            self::SOURCE_PAYMENT_OVERVIEW,
            self::STATUTE_VERIFIED,
            $statutoryDueOn,
            self::SHIFT_WORKING_DAY,
            self::SOURCE_WORKING_DAY_SHIFT,
        );
    }

    /**
     * Lhůta OPRAVNÉHO přehledu o platbě pojistného.
     *
     * § 25 odst. 4 z. 592/1992 Sb. (nový od 1. 1. 2026) ji váže na den, kdy
     * zaměstnavatel chybu ZJISTIL, ne na mzdové období. Řádný přehled má
     * pevný 20. den následujícího měsíce, takže se odsud počítat nedá:
     * chyba se zpravidla najde až měsíce po něm a lhůta by byla dávno pryč.
     *
     * Posouvá se na pracovní den stejně jako řádný přehled — jde o tutéž
     * povinnost vůči téže pojišťovně, jen v opravné variantě.
     */
    public function forCorrectivePaymentOverview(
        string $discoveredOn,
    ): HealthNotificationDeadlineWindow {
        $discovered = $this->date($discoveredOn);
        $statutoryDueOn = $discovered
            ->add(new \DateInterval('P' . self::NOTIFICATION_DAYS . 'D'))
            ->format('Y-m-d');

        return $this->window(
            $discoveredOn,
            CzechWorkingDays::shiftToWorkingDay(
                new \DateTimeImmutable($statutoryDueOn),
            )->format('Y-m-d'),
            self::SOURCE_CORRECTIVE_PAYMENT_OVERVIEW,
            self::STATUTE_VERIFIED,
            $statutoryDueOn,
            self::SHIFT_WORKING_DAY,
            self::SOURCE_WORKING_DAY_SHIFT,
        );
    }

    public function rulesetHash(): string
    {
        return hash('sha256', self::RULESET_ID . '|'
            . self::NOTIFICATION_DAYS . '|' . self::MONTHLY_DUE_DAY
            . '|payment_overview_shift=' . self::SHIFT_WORKING_DAY
            // Přidání opravné lhůty je změna sady pravidel, ne jen nová metoda:
            // uložené termíny nesou hash a musí být poznat, podle čeho vznikly.
            . '|corrective_overview=' . self::NOTIFICATION_DAYS);
    }

    private function window(
        string $earliest,
        string $dueOn,
        string $source,
        string $sourceStatus,
        ?string $statutoryDueOn = null,
        string $dueShift = self::SHIFT_NONE,
        ?string $shiftSource = null,
    ): HealthNotificationDeadlineWindow {
        return new HealthNotificationDeadlineWindow(
            earliestSubmissionOn: $earliest,
            dueOn: $dueOn,
            calendarBasis: self::BASIS_CALENDAR_DAYS,
            rulesetId: self::RULESET_ID,
            rulesetHash: $this->rulesetHash(),
            source: $source,
            sourceStatus: $sourceStatus,
            statutoryDueOn: $statutoryDueOn ?? $dueOn,
            dueShift: $dueShift,
            shiftSource: $shiftSource,
        );
    }

    private function twentiethOfNextMonth(
        \DateTimeImmutable $reference,
    ): string {
        $nextMonth = $reference->modify('first day of next month');

        return $nextMonth
            ->setDate(
                (int) $nextMonth->format('Y'),
                (int) $nextMonth->format('n'),
                self::MONTHLY_DUE_DAY,
            )
            ->format('Y-m-d');
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone('Europe/Prague'),
        );
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new HealthNotificationException(
                'zp_date_invalid',
                'Datum oznamované skutečnosti musí mít tvar RRRR-MM-DD.',
            );
        }

        return $date;
    }
}
