<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Lhůty registrační povinnosti u ZAMĚSTNANCE (PREZEC/REGZEC).
 *
 * Proč to není `EmployerRegistrationDeadlinePolicy`: ta počítá lhůtu
 * ZAMĚSTNAVATELE — přihlášku do evidence zaměstnavatelů podle § 17, tedy
 * dva pracovní dny před nástupem prvního zaměstnance a nejdříve 15 dnů
 * předem. Pravidlo „dva pracovní dny" se v žádném dokumentu k PREZEC ani
 * REGZEC nevyskytuje a přenést ho na zaměstnance je doložená záměna
 * (viz varování v `private/Mzdy/15-HANDOFF-2026-08-06.md`). U zaměstnance
 * platí § 19 odst. 1: přihlásit PŘED zahájením práce, nejdříve osm dnů předem.
 *
 * Obě lhůty jsou proto samostatné třídy se samostatným rulesetem a hashem.
 * Nic dalšího se tu neodvozuje: opravy, storna a náhradní lhůty zůstávají
 * fail-closed, dokud nebudou svázané s účinným pravidlem.
 */
final class PayrollEmployeeRegistrationDeadlinePolicy
{
    /**
     * Registrační povinnost u zaměstnance je účinná od 1. 7. 2026. Pro dřívější
     * nástup se lhůta neodvozuje vůbec — raději žádná než vymyšlená.
     */
    public const SUPPORTED_FROM = '2026-07-01';

    public const REGISTRATION_RULESET_ID =
        'cz-employee-registration-2026-07.v1';
    private const NO_SHOW_RULESET_ID =
        'cz-employee-registration-no-show-2026-07.v1';
    private const FOLLOW_UP_RULESET_ID =
        'cz-regzec-follow-up-2026-04.v1';
    private const AFTER_PRE_REGISTRATION_RULESET_ID =
        'cz-regzec-after-prezec-2026-07.v1';

    /**
     * Doplnění plné registrace po předregistraci: osm dnů PO nástupu.
     *
     * Smysl PREZEC je přihlásit člověka, u kterého ještě nemáte všechny údaje.
     * Kdyby plná registrace musela odejít v den nástupu, nebylo by na to kdy
     * ty údaje sehnat a předregistrace by ztratila smysl.
     */
    private const AFTER_PRE_REGISTRATION_DAYS = 8;
    private const FOLLOW_UP_SUPPORTED_FROM = '2026-04-01';

    /**
     * Osm KALENDÁŘNÍCH dnů, ne pracovních. Kdyby se počítaly pracovní, okno by
     * se u svátků roztáhlo přes zákonnou hranici a podání by prošlo dřív, než
     * ho zákon připouští. Stejnou hodnotu drží `PayrollRegistrationXmlValidator`
     * pro okno PREZEC P1, takže se obě vrstvy nesmí rozejít.
     */
    private const EARLIEST_DAYS_BEFORE_START = 8;

    /**
     * Nenastoupení: osm dnů od původně předpokládaného nástupu. Tutéž lhůtu
     * (§ 17 odst. 5) už nese `EmployerRegistrationDeadlinePolicy` jako
     * `noShowNotificationDueOn`; tady se jen počítá pro konkrétní vztah.
     */
    private const NO_SHOW_NOTIFICATION_DAYS = 8;

    private const SOURCES = [
        'law' => '323/2025 Sb. § 19 odst. 1',
        'no_show_law' => '323/2025 Sb. § 17 odst. 5',
        'cssz_document' =>
            'Metodika PREZEC 1.4 — částečné přihlášení před nástupem',
        /*
         * Navazující oznámení REGZEC A2 až A8 a doplnění plné registrace po
         * předregistraci: osm kalendářních dnů od události.
         *
         * ZDROJ JE SLABŠÍ NEŽ U OSTATNÍCH a je to tak napsané schválně.
         * Konkrétní paragraf pro tuhle lhůtu se v zákoně dohledat nepodařilo;
         * opírá se o leták ČSSZ k předregistraci a registraci a o potvrzení
         * účetní, která agendu vede. Osm dnů odpovídá lhůtě u nenastoupení
         * (§ 17 odst. 5) i obecné osmidenní lhůtě u hlásitelných změn
         * (§ 19 odst. 5), takže to není odhad z ničeho — ale dokud nebude
         * doložený paragraf, nesmí se to tvářit jako zákonná citace.
         */
        'follow_up_document' =>
            'Leták ČSSZ „Předregistrace a registrace zaměstnance" '
            . '(private/Mzdy/podklady/JMHZ_predregistrace_a_registrace.pdf); '
            . 'lhůtu potvrdila účetní vedoucí agendu 2. 9. 2026. Paragraf '
            . 'k doložení.',
    ];

    /**
     * Lhůta pro přihlášení pracovního vztahu (PREZEC P1 i REGZEC A1).
     *
     * `dueOn` je DEN NÁSTUPU, ne den před ním: zákon váže povinnost na okamžik
     * zahájení práce, takže podání v den nástupu před nástupem do práce je
     * včas. Posouvat lhůtu o den dopředu „pro jistotu" by z včasného podání
     * udělalo opožděné a hlásilo by se zpoždění, které nenastalo.
     */
    public function forEmploymentStart(
        string $startOn,
    ): PayrollEmployeeRegistrationDeadlineWindow {
        $start = $this->supportedDate($startOn);
        $earliest = $start->modify(
            '-' . self::EARLIEST_DAYS_BEFORE_START . ' days',
        );

        return new PayrollEmployeeRegistrationDeadlineWindow(
            $earliest->format('Y-m-d'),
            $start->format('Y-m-d'),
            'calendar_days',
            self::REGISTRATION_RULESET_ID,
            $this->rulesetHash(self::REGISTRATION_RULESET_ID, [
                'earliest_days_before_start' =>
                    self::EARLIEST_DAYS_BEFORE_START,
                'due_on' => 'employment_start_date',
            ]),
        );
    }

    /**
     * Lhůta pro plnou registraci (REGZEC A1) po částečném přihlášení (PREZEC P1).
     *
     * PROČ SAMOSTATNĚ: dřív tahle interakce spadla do
     * {@see forEmploymentStart()}, takže termínem byl DEN NÁSTUPU. Aplikace
     * pak hlásila zpoždění, které nenastalo, a tlačila účetní podat dřív, než
     * musí — přesně u případu, kde předregistrace existuje proto, že údaje
     * ještě nejsou. Podle letáku ČSSZ (viz `cssz_document` v SOURCES) je to
     * nástup plus osm dnů.
     *
     * Okno se neotevírá dnem nástupu: doplnit údaje jde i dřív, jakmile je
     * zaměstnavatel má, takže nejdřívější den zůstává stejný jako u přihlášky.
     */
    public function forFullRegistrationAfterPreRegistration(
        string $startOn,
    ): PayrollEmployeeRegistrationDeadlineWindow {
        $start = $this->supportedDate($startOn);
        $earliest = $start->modify(
            '-' . self::EARLIEST_DAYS_BEFORE_START . ' days',
        );
        $due = $start->modify(
            '+' . self::AFTER_PRE_REGISTRATION_DAYS . ' days',
        );

        return new PayrollEmployeeRegistrationDeadlineWindow(
            $earliest->format('Y-m-d'),
            $due->format('Y-m-d'),
            'calendar_days',
            self::AFTER_PRE_REGISTRATION_RULESET_ID,
            $this->rulesetHash(self::AFTER_PRE_REGISTRATION_RULESET_ID, [
                'earliest_days_before_start' =>
                    self::EARLIEST_DAYS_BEFORE_START,
                'due_calendar_days_after_start' =>
                    self::AFTER_PRE_REGISTRATION_DAYS,
                'due_on' => 'employment_start_date_plus_days',
            ]),
        );
    }

    /**
     * Lhůta pro oznámení, že zaměstnanec nenastoupil (PREZEC P2). Okno začíná
     * dnem předpokládaného nástupu — dřív se o nenastoupení nedá rozhodnout.
     */
    public function forNoShow(
        string $expectedStartOn,
    ): PayrollEmployeeRegistrationDeadlineWindow {
        $start = $this->supportedDate($expectedStartOn);
        $due = $start->modify(
            '+' . self::NO_SHOW_NOTIFICATION_DAYS . ' days',
        );

        return new PayrollEmployeeRegistrationDeadlineWindow(
            $start->format('Y-m-d'),
            $due->format('Y-m-d'),
            'calendar_days',
            self::NO_SHOW_RULESET_ID,
            $this->rulesetHash(self::NO_SHOW_RULESET_ID, [
                'notification_calendar_days' =>
                    self::NO_SHOW_NOTIFICATION_DAYS,
                'window_opens_on' => 'expected_employment_start_date',
            ]),
        );
    }

    public function forFollowUp(
        int $actionCode,
        string $effectiveOn,
    ): PayrollEmployeeRegistrationDeadlineWindow {
        if ($actionCode < 2 || $actionCode > 8) {
            // Kontrakt volajícího, ne vstup účetní: do formuláře se tenhle
            // kód nedostane, protože druh oznámení se vybírá z nabídky.
            throw new \InvalidArgumentException(
                'Lhůtu pro navazující oznámení umí spočítat jen oznámení '
                    . 'REGZEC A2 až A8, ne přihlášení ani částečné přihlášení.',
            );
        }
        $effective = $this->date($effectiveOn);
        $due = $effective->modify('+8 days');

        return new PayrollEmployeeRegistrationDeadlineWindow(
            $effectiveOn,
            $due->format('Y-m-d'),
            'calendar_days',
            self::FOLLOW_UP_RULESET_ID,
            $this->rulesetHash(self::FOLLOW_UP_RULESET_ID, [
                'action_code' => $actionCode,
                'notification_calendar_days' => 8,
                'window_opens_on' => 'registration_event_effective_on',
            ]),
        );
    }

    private function supportedDate(string $value): \DateTimeImmutable
    {
        $date = $this->date($value);
        if ($value < self::SUPPORTED_FROM) {
            throw new PayrollRegistrationXmlException(
                'registration_deadline_before_supported_window',
                'Lhůtu pro přihlášení zaměstnance na ČSSZ appka počítá až '
                    . 'od 1. 7. 2026, kdy povinnost začala platit. Tenhle nástup '
                    . 'je dřívější, takže lhůtu určete podle tehdejších '
                    . 'pravidel.',
            );
        }

        return $date;
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone('Europe/Prague'),
        );
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_deadline_start_date_invalid',
                'Datum registrační události chybí nebo není platné datum. '
                    . 'Zadejte ho ve tvaru RRRR-MM-DD, například 2026-08-05.',
            );
        }
        return $date;
    }

    /** @param array<string,mixed> $rule */
    private function rulesetHash(string $rulesetId, array $rule): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' =>
                'payroll-employee-registration-deadline-policy.v1',
            'ruleset_id' => $rulesetId,
            'effective_from' => $rulesetId === self::FOLLOW_UP_RULESET_ID
                ? self::FOLLOW_UP_SUPPORTED_FROM
                : self::SUPPORTED_FROM,
            'calendar_basis' => 'calendar_days',
            'rule' => $rule,
            'sources' => self::SOURCES,
        ]));
    }
}
