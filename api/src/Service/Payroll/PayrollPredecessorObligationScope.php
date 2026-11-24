<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSubmissionService;

/**
 * JEDINÉ pravidlo pro otázku „patří tahle povinnost checklistu MyÚčtu, nebo ji
 * vyřídil předchozí program?".
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Převzetí mezd (hlášení JMHZ, PREMIER, POHODA, PAMICA, Money) zakládalo každému
 * převzatému vztahu celý nástupní a výstupní checklist: přihlášku u ČSSZ,
 * oznámení zdravotní pojišťovně, smlouvu, prohlášení, odhlášky. U firmy
 * s deseti lidmi to po importu dělalo přes sto „nevyřízených" povinností
 * s termíny z ledna, které dávno vyřídil předchozí program. Checklist, hlídač
 * termínů i mzdový běh je hlásily jako dluh a uživatel nevěděl, co je skutečná
 * práce a co šum.
 *
 * ── Pravidlo ────────────────────────────────────────────────────────────────
 * Povinnost, jejíž rozhodná událost nastala PŘED prvním mzdovým obdobím firmy
 * v MyÚčtu (`payroll_module_state.start_period`), vyřídil ten, kdo tehdy mzdy
 * vedl. Taková položka se nezakládá, a když už existuje, čte se jako
 * „vyřízeno předchozím programem" — nezobrazuje se jako nesplněná a nic
 * neblokuje. Uložený stav se nepřepisuje: posune-li se začátek vedení mezd,
 * pravidlo se přepočítá samo.
 *
 * Rozhodná událost je den, ke kterému povinnost vznikla:
 *
 * | fáze        | den                                   |
 * |-------------|---------------------------------------|
 * | nástup      | skutečný (jinak sjednaný) den nástupu |
 * | skončení    | den skončení vztahu                   |
 * | změna       | den účinnosti při zakládání; u uložené položky její termín (den změny se neukládá) |
 *
 * Výjimky: `legacy_start_date` není povinnost vůči úřadu, ale chybějící údaj
 * v evidenci, a `takeover_deductions_review` i `takeover_sickness_review` jsou
 * úkoly pro první mzdu v MyÚčtu — relevantní jsou vždy. Potvrzení o zdanitelných příjmech má lhůtu
 * od ŽÁDOSTI zaměstnance; jakmile je žádost zapsaná (položka má termín),
 * rozhoduje termín, protože žádost mohla přijít až za MyÚčta.
 *
 * Hranice je stejná jako u historických období
 * ({@see PayrollHistoricalPeriodService::precedesStart()}): `start_period` je
 * první počítaný měsíc, porovnává se ostře.
 */
final class PayrollPredecessorObligationScope
{
    /** Důvod ve výpisu checklistu (`effective_reason`). */
    public const REASON = 'predecessor';

    /** Poznámka k položce, kterou převzetí odškrtne jako vyřízenou jinde. */
    public const NOTE = 'Vyřízeno předchozím programem před začátkem vedení mezd v MyÚčtu.';

    /**
     * Lhůta jednorázového dohlášení údajů (REGZEC A3) u zaměstnanců, které ČSSZ
     * k 31. 3. 2026 vedla v registru pojištěnců z ONZ (vysvětlivky ČSSZ k REGZEC).
     */
    public const ONZ_COMPLETION_DUE_ON = '2026-04-30';

    /** Poslední den, kdy se zaměstnanec přihlašoval přes ONZ. */
    private const ONZ_LAST_DAY = '2026-03-31';

    /** @var list<string> */
    private const ALWAYS_RELEVANT = [
        'legacy_start_date',
        'takeover_deductions_review',
        'takeover_sickness_review',
    ];

    /** @var list<string> */
    private const REQUEST_ANCHORED = ['taxable_income_confirmation'];

    /**
     * Stavy podání, ve kterých z MyÚčta ještě nic neodešlo (rozpracované,
     * připravené, včas zrušené).
     *
     * @var list<string>
     */
    private const UNSENT_SUBMISSION_STATUSES = ['draft', 'validated', 'prepared', 'ready', 'cancelled_in_time'];

    /**
     * Den, podle kterého se o položce rozhoduje; `null` = nevíme, položka zůstává
     * relevantní.
     */
    public static function anchorDay(
        string $itemKey,
        string $phase,
        ?string $dueOn,
        ?string $startOn,
        ?string $endOn,
        ?string $changeOn = null,
    ): ?string {
        if (in_array($itemKey, self::REQUEST_ANCHORED, true) && $dueOn !== null) {
            return $dueOn;
        }

        return match ($phase) {
            'onboarding' => $startOn,
            'offboarding' => $endOn,
            default => $changeOn ?? $dueOn,
        };
    }

    /**
     * @param string|null $startPeriod první mzdové období firmy (`YYYY-MM` i `YYYY-MM-DD`)
     * @param string|null $changeOn den účinnosti změny — zná ho jen zakládání
     *        položky; uložená položka ho nenese a rozhoduje její termín
     */
    public static function handledByPredecessor(
        ?string $startPeriod,
        string $itemKey,
        string $phase,
        ?string $dueOn,
        ?string $startOn,
        ?string $endOn,
        ?string $changeOn = null,
    ): bool {
        if (in_array($itemKey, self::ALWAYS_RELEVANT, true)) {
            return false;
        }
        return self::eventHandledByPredecessor(
            $startPeriod,
            self::anchorDay($itemKey, $phase, $dueOn, $startOn, $endOn, $changeOn),
        );
    }

    /**
     * Povinnost vázaná přímo na den události mimo checklist (oznámení
     * zdravotní pojišťovně o nástupu, skončení, mateřské…): událost před
     * prvním mzdovým obdobím v MyÚčtu hlásil předchozí program.
     */
    public static function eventHandledByPredecessor(?string $startPeriod, ?string $eventDay): bool
    {
        return $eventDay !== null && $eventDay !== ''
            && PayrollHistoricalPeriodService::precedesStart($startPeriod, $eventDay);
    }

    /**
     * Uložená povinnost podání vázaná na událost u člověka (přihláška či
     * odhláška ČSSZ, oznámení zdravotní pojišťovně), kterou vyřídil předchozí
     * program. Totéž pravidlo jako u checklistu a u přehledu oznámení
     * pojišťovně, jen nad řádkem `payroll_obligations`.
     *
     * Povinnost v evidenci vzniká až PŘÍPRAVOU podání (registrace ČSSZ) nebo
     * synchronizací oznámení. Účetní si tak může připravit i odhlášku za vztah,
     * který skončil v převzatém měsíci, a lhůta 8 dnů z doby předchozího
     * programu pak svítila jako zmeškaná povinnost MyÚčta. Povinnost se proto
     * čte jako „vyřízeno předchozím programem" a hlídač termínů ji nepřipomíná;
     * připravené podání zůstává, kdo ho přesto chce podat, podá ho z karty.
     *
     * Rozhodný den:
     * - oznámení pojišťovně: `period_start` je den události (`occurredOn`),
     *   stejný den, podle kterého rozhoduje přehled oznámení za období,
     * - registrace ČSSZ: uložená povinnost den události nenese (u přihlášky je
     *   `period_start` počátek okna PŘED nástupem), takže rozhoduje termín,
     *   stejně jako u uložené položky checklistu se změnou. Je to opatrná
     *   volba: schová jen povinnost, jejíž lhůta celá uplynula před prvním
     *   mzdovým obdobím v MyÚčtu, tedy nic, co mělo MyÚčto podat včas.
     *
     * Jakmile z MyÚčta k povinnosti něco ODEŠLO, pravidlo neplatí: účetní se
     * rozhodla podat sama a podání má vlastní průběh (čeká na výsledek,
     * odmítnutí), který se hlídá jako u každého jiného.
     *
     * Ostatní povinnosti (měsíční hlášení, přehledy, vyúčtování) se tu
     * nerozhodují: převzatý měsíc bez hlášení hlídá
     * {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPredecessorGapService}
     * a povinnost za něj založená v MyÚčtu je vědomé podání.
     */
    public static function storedObligationHandledByPredecessor(
        ?string $startPeriod,
        string $sourceEventType,
        string $periodStart,
        string $dueOn,
        ?string $latestSubmissionStatus,
    ): bool {
        if ($latestSubmissionStatus !== null
            && !in_array($latestSubmissionStatus, self::UNSENT_SUBMISSION_STATUSES, true)
        ) {
            return false;
        }

        return match ($sourceEventType) {
            HealthInsuranceSubmissionService::SOURCE_EVENT_NOTIFICATION
                => self::eventHandledByPredecessor($startPeriod, $periodStart),
            PayrollRegistrationSubmissionService::SOURCE_EVENT_TYPE
                => self::eventHandledByPredecessor($startPeriod, $dueOn),
            default => false,
        };
    }

    /**
     * Dohlášení údajů (A3) vztahu přihlášeného dřív než z MyÚčta: u vztahu z doby
     * ONZ rozhoduje lhůta dohlášení, u pozdějšího nástupu den nástupu (přihlášku
     * pak podal ten, kdo tehdy mzdy vedl).
     */
    public static function registrationCompletionHandledByPredecessor(
        ?string $startPeriod,
        ?string $startOn,
        ?string $endOn,
    ): bool {
        if ($startOn !== null && $startOn > self::ONZ_LAST_DAY) {
            return self::handledByPredecessor($startPeriod, 'registration_completion', 'onboarding', null, $startOn, $endOn);
        }

        return self::handledByPredecessor($startPeriod, 'registration_completion', 'change', self::ONZ_COMPLETION_DUE_ON, $startOn, $endOn);
    }

    /**
     * Tentýž výraz pro SQL (1/0). Předpokládá alias položky checklistu
     * `$item` (`payroll_employment_checklist_items`); vztah i začátek vedení
     * mezd si dohledá sám, takže jde vložit do libovolného dotazu.
     */
    public static function sql(string $item = 'item'): string
    {
        $always = self::inList(self::ALWAYS_RELEVANT);
        $request = self::inList(self::REQUEST_ANCHORED);

        return "EXISTS (
            SELECT 1
              FROM payroll_module_state predecessor_state
              JOIN payroll_employments predecessor_employment
                ON predecessor_employment.supplier_id = {$item}.supplier_id
               AND predecessor_employment.id = {$item}.employment_id
             WHERE predecessor_state.supplier_id = {$item}.supplier_id
               AND predecessor_state.start_period IS NOT NULL
               AND {$item}.item_key NOT IN ({$always})
               AND CASE
                     WHEN {$item}.item_key IN ({$request}) AND {$item}.due_date IS NOT NULL
                       THEN {$item}.due_date
                     WHEN {$item}.phase = 'onboarding'
                       THEN COALESCE(predecessor_employment.actual_start_date, predecessor_employment.start_date)
                     WHEN {$item}.phase = 'offboarding'
                       THEN predecessor_employment.end_date
                     ELSE {$item}.due_date
                   END < DATE_FORMAT(predecessor_state.start_period, '%Y-%m-01')
          )";
    }

    /** @param list<string> $values */
    private static function inList(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values));
    }
}
