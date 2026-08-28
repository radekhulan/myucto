<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Deadline;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDutyKind;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollEmployeeRegistrationDeadlinePolicy;

/**
 * Termíny položek nástupního, změnového a výstupního checklistu.
 *
 * ## Proč to vzniklo
 *
 * `PayrollEmploymentRepository::ensureChecklist()` seedovalo VŠECHNY položky
 * fáze na DEN UDÁLOSTI — nástup, účinnost změny, skončení. Přihláška u ČSSZ
 * i oznámení zdravotní pojišťovně tak měly termín o osm dnů dřív, než jaký
 * ukládá zákon, a evidenční list o měsíce. Varování, které lže, se přestane
 * číst; obsluha se pak řídí datem, které se zákonem nesouvisí.
 *
 * ## Odkud lhůty jsou
 *
 * Nic se tu NEODVOZUJE znovu. Třída je jen mapa `item_key → už existující
 * politika lhůt`, aby se checklist a podání nemohly rozejít:
 *
 * - zdravotní pojišťovna → {@see HealthNotificationDeadlinePolicy}
 *   (8 dnů podle § 10 zákona č. 48/1997 Sb.; u DPP a DPČ 20. den
 *   následujícího měsíce podle metodiky VZP),
 * - ČSSZ / JMHZ → {@see PayrollEmployeeRegistrationDeadlinePolicy}
 *   (přihlášení PŘED zahájením práce, § 19 odst. 1 zák. č. 323/2025 Sb.;
 *   navazující hlášení A2–A8 do 8 dnů),
 * - evidenční list → {@see EldpDeadlinePolicy}.
 *
 * Vlastní hodnoty drží jen dvě lhůty, které svou politiku nemají:
 *
 * - **prohlášení poplatníka** — § 38k odst. 4 zákona č. 586/1992 Sb.:
 *   učiní se do 30 dnů po vstupu do zaměstnání. Pramen je převzatý, ne
 *   ověřený proti sbírce, proto `external_unverified`.
 * - **pracovní smlouva / dohoda** — § 34 odst. 2 zákona č. 262/2006 Sb.
 *   (písemná forma). Termínem je den nástupu: smlouva musí být uzavřena
 *   dřív, než se začne pracovat. Rovněž `external_unverified`.
 *
 * Kde se lhůta odvodit nedá, vrací se `dueOn = null` a `not_derived` —
 * NIKDY dohadované datum. Týká se to potvrzení o zdanitelných příjmech
 * (§ 38j odst. 3 ZDP: do 10 dnů od ŽÁDOSTI zaměstnance, kterou aplikace
 * neeviduje), interních kontrol a starých vztahů před účinností pravidel.
 *
 * ## Položky, které se vůbec nezakládají
 *
 * `forItem()` vrátí `null` u položky, která na daný vztah nedopadá. Jediný
 * dnešní případ je evidenční list: od roku 2026 ho podle *Pravidel podání
 * JMHZ* sestavuje ČSSZ z měsíčního hlášení a zaměstnavatel ho vede jen
 * přechodně — rozhoduje {@see EldpDeadlinePolicy::standaloneStatementAllowed()}.
 * Zakládat ho i tam, kde se nepodává, by byl přesně ten planý poplach, kvůli
 * kterému si účetní na checklist zvykne a přestane ho číst.
 */
final class PayrollChecklistDeadlinePolicy
{
    /** § 38k odst. 4 zákona č. 586/1992 Sb. — do 30 dnů po vstupu do zaměstnání. */
    private const TAX_DECLARATION_DAYS = 30;

    /** REGZEC A2–A8: do 8 dnů od rozhodné skutečnosti (zák. č. 323/2025 Sb.). */
    private const REGISTRATION_FOLLOW_UP_DAYS = 8;

    /** Navazující hlášení ČSSZ modul odvozuje až od účinnosti REGZEC. */
    private const REGISTRATION_FOLLOW_UP_FROM = '2026-04-01';

    private const RULESET_CONTRACT = 'cz-payroll-checklist-deadlines.contract.v1';
    private const RULESET_TAX_DECLARATION =
        'cz-payroll-checklist-deadlines.tax-declaration.v1';
    private const RULESET_REGISTRATION_FOLLOW_UP =
        'cz-regzec-follow-up-2026-04.v1';

    private const SOURCE_CONTRACT = '§ 34 odst. 2 zákona č. 262/2006 Sb.';
    private const SOURCE_TAX_DECLARATION = '§ 38k odst. 4 zákona č. 586/1992 Sb.';
    private const SOURCE_REGISTRATION_FOLLOW_UP =
        '§ 19 zákona č. 323/2025 Sb. — navazující hlášení REGZEC A2 až A8';

    private readonly HealthNotificationDeadlinePolicy $health;
    private readonly PayrollEmployeeRegistrationDeadlinePolicy $registration;
    private readonly EldpDeadlinePolicy $eldp;

    public function __construct(
        ?HealthNotificationDeadlinePolicy $health = null,
        ?PayrollEmployeeRegistrationDeadlinePolicy $registration = null,
        ?EldpDeadlinePolicy $eldp = null,
    ) {
        $this->health = $health ?? new HealthNotificationDeadlinePolicy();
        $this->registration = $registration
            ?? new PayrollEmployeeRegistrationDeadlinePolicy();
        $this->eldp = $eldp ?? new EldpDeadlinePolicy();
    }

    /**
     * Termín jedné položky checklistu.
     *
     * @param string $eventOn den události fáze (nástup / účinnost změny / skončení)
     * @return PayrollChecklistDeadline|null null = položka na tenhle vztah nedopadá
     */
    public function forItem(
        string $itemKey,
        string $eventOn,
        string $relationType,
    ): ?PayrollChecklistDeadline {
        return match ($itemKey) {
            'employment_contract' => new PayrollChecklistDeadline(
                $itemKey,
                $eventOn,
                self::RULESET_CONTRACT,
                self::SOURCE_CONTRACT,
                'external_unverified',
            ),
            'tax_declaration' => $this->shifted(
                $itemKey,
                $eventOn,
                self::TAX_DECLARATION_DAYS,
                self::RULESET_TAX_DECLARATION,
                self::SOURCE_TAX_DECLARATION,
                'external_unverified',
            ),
            'health_insurance_registration' => $this->healthNotification(
                $itemKey,
                HealthNotificationDutyKind::EmploymentStart,
                $eventOn,
                $relationType,
            ),
            'health_insurance_change' => $this->healthNotification(
                $itemKey,
                HealthNotificationDutyKind::EmployeeDataChange,
                $eventOn,
                $relationType,
            ),
            'health_insurance_deregistration' => $this->healthNotification(
                $itemKey,
                HealthNotificationDutyKind::EmploymentEnd,
                $eventOn,
                $relationType,
            ),
            'social_jmhz_registration' =>
                $this->employmentRegistration($itemKey, $eventOn),
            'social_jmhz_change', 'social_jmhz_deregistration' =>
                $this->registrationFollowUp($itemKey, $eventOn),
            'eldp_submission' => $this->eldpSubmission($itemKey, $eventOn),
            'taxable_income_confirmation' => $this->notDerived(
                $itemKey,
                'Vydává se na žádost zaměstnance do 10 dnů od jejího podání '
                . '(§ 38j odst. 3 zákona č. 586/1992 Sb.). Den žádosti '
                . 'aplikace neeviduje, proto se termín neodvozuje.',
            ),
            default => $this->notDerived($itemKey, null),
        };
    }

    private function healthNotification(
        string $itemKey,
        HealthNotificationDutyKind $kind,
        string $eventOn,
        string $relationType,
    ): PayrollChecklistDeadline {
        try {
            $window = $this->health->forNotification(
                $kind,
                $eventOn,
                $relationType,
            );
        } catch (\Throwable) {
            return $this->notDerived($itemKey, null);
        }

        return new PayrollChecklistDeadline(
            $itemKey,
            $window->dueOn,
            $window->rulesetId,
            $window->source,
            $window->sourceStatus === 'statute_verified'
                ? 'statute_verified'
                : 'external_unverified',
        );
    }

    private function employmentRegistration(
        string $itemKey,
        string $eventOn,
    ): PayrollChecklistDeadline {
        try {
            $window = $this->registration->forEmploymentStart($eventOn);
        } catch (\Throwable) {
            // Nástup před 1. 7. 2026 registrační povinnost zaměstnance nezná;
            // dohadovat pro něj termín by znamenalo hlásit zpoždění, které
            // podle tehdejšího práva nenastalo.
            return $this->notDerived(
                $itemKey,
                'Registrační povinnost u zaměstnance je účinná od 1. 7. 2026; '
                . 'pro dřívější nástup se termín neodvozuje.',
            );
        }

        return new PayrollChecklistDeadline(
            $itemKey,
            $window->dueOn,
            $window->rulesetId,
            '§ 19 odst. 1 zákona č. 323/2025 Sb.',
            'external_unverified',
        );
    }

    private function registrationFollowUp(
        string $itemKey,
        string $eventOn,
    ): PayrollChecklistDeadline {
        if ($eventOn < self::REGISTRATION_FOLLOW_UP_FROM) {
            return $this->notDerived($itemKey, null);
        }

        return $this->shifted(
            $itemKey,
            $eventOn,
            self::REGISTRATION_FOLLOW_UP_DAYS,
            self::RULESET_REGISTRATION_FOLLOW_UP,
            self::SOURCE_REGISTRATION_FOLLOW_UP,
            'external_unverified',
        );
    }

    /**
     * Termín evidenčního listu při skončení účasti.
     *
     * Konečné vyúčtování příjmů, od kterého běží měsíční lhůta § 38 odst. 4,
     * v okamžiku seedu ještě neproběhlo. Bere se proto NEJDŘÍVĚJŠÍ možné —
     * den skončení sám — což dá nejpřísnější termín, jaký může nastat.
     * Vědomě přísně: připomenout dřív je bezpečné, připomenout pozdě ne.
     */
    private function eldpSubmission(
        string $itemKey,
        string $eventOn,
    ): ?PayrollChecklistDeadline {
        $year = (int) substr($eventOn, 0, 4);
        $allowed = EldpDeadlinePolicy::standaloneStatementAllowed(
            $year,
            $eventOn,
            false,
        );
        if (!$allowed['allowed']) {
            return null;
        }
        try {
            $window = $this->eldp->forTermination($year, $eventOn, $eventOn);
        } catch (\Throwable) {
            return $this->notDerived($itemKey, null);
        }

        return new PayrollChecklistDeadline(
            $itemKey,
            $window->dueOn,
            $window->rulesetId,
            'Zákon č. 582/1991 Sb., § 38 odst. 4 ve znění účinném '
            . 'do 31. 12. 2025 — údaje se zapisují do 1 měsíce po konečném '
            . 'vyúčtování příjmů, nejpozději do 31. ledna následujícího roku.',
            'external_unverified',
        );
    }

    private function shifted(
        string $itemKey,
        string $eventOn,
        int $days,
        string $rulesetId,
        string $source,
        string $sourceStatus,
    ): PayrollChecklistDeadline {
        $event = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $eventOn,
            new \DateTimeZone('Europe/Prague'),
        );
        if (!$event instanceof \DateTimeImmutable
            || $event->format('Y-m-d') !== $eventOn
        ) {
            return $this->notDerived($itemKey, null);
        }

        return new PayrollChecklistDeadline(
            $itemKey,
            $event->add(new \DateInterval('P' . $days . 'D'))->format('Y-m-d'),
            $rulesetId,
            $source,
            $sourceStatus,
        );
    }

    private function notDerived(
        string $itemKey,
        ?string $note,
    ): PayrollChecklistDeadline {
        return new PayrollChecklistDeadline(
            $itemKey,
            null,
            null,
            null,
            'not_derived',
            $note,
        );
    }
}
