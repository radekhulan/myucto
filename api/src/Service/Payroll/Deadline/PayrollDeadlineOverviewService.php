<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Deadline;

use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationChangeProposalRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetectionService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\TaxStatement\TaxStatementService;
use Psr\Clock\ClockInterface;

/**
 * Co je do kdy a co je po termínu — jedním voláním za celou firmu.
 *
 * ## Proč to vzniklo
 *
 * Modul uměl zákonné lhůty spočítat na den přesně a s citací paragrafu
 * ({@see PayrollLevyDeadlinePolicy}, lhůty podání, lhůty checklistu), ale
 * neexistovalo místo, které by je někomu ŘEKLO. Termín se dal najít jen tak,
 * že si člověk otevřel přehled podání za správné období, pak platby za jiné
 * období a pak kartu každého zaměstnance zvlášť. Zmeškaný termín se nikde
 * nezvýraznil — a lhůta, kterou nikdo neuvidí, není hlídaná.
 *
 * ## Co se do přehledu dostane
 *
 * Tři prameny, které mají zákonnou lhůtu a nesplněný stav:
 *
 * 1. **podání** — `payroll_obligations` + `payroll_submission_deadlines`,
 *    tedy tatáž evidence, ze které žije obrazovka podání; stav se posuzuje
 *    {@see PayrollDeadlineAssessmentService}, aby přehled a detail povinnosti
 *    neříkaly o jednom termínu dvě různé věci,
 * 2. **odvody** — nezaplacené závazky ze splatnosti podle
 *    {@see PayrollLevyDeadlinePolicy} (pojistné, zálohová a srážková daň),
 * 3. **lhůty u lidí** — nevyřízené položky nástupního a výstupního checklistu
 *    s odvozenou zákonnou lhůtou (přihláška ČSSZ, oznámení pojišťovně, ELDP),
 * 4. **roční vyúčtování daně** — nepodané DPZVD6 a DPSVD2 se lhůtou podle
 *    {@see PayrollTaxStatementDeadlinePolicy}. Modul obě vyúčtování uměl
 *    sestavit i odeslat, ale jejich lhůta žila jen v komentáři a ve větě pod
 *    panelem — tedy nikde, kde by ji někdo zmeškal včas,
 * 5. **dávky nemocenského pojištění** — evidované případy bez doloženého
 *    podání NEMPRI nebo HZUPN, s lhůtou podle
 *    {@see \MyInvoice\Service\Payroll\Submission\Sickness\SicknessDeadlinePolicy}.
 *    Vlastní pramen, protože povinnost podle § 97 zák. č. 187/2006 Sb. vzniká
 *    sociální událostí, ne založením podání: kdyby se termín odvozoval jen
 *    z `payroll_obligations`, existoval by teprve od okamžiku, kdy někdo klikl
 *    na Připravit — tedy přesně tehdy, kdy už ho hlídat netřeba.
 *
 * ## Co se do něj vědomě nedostane
 *
 * Čistá mzda, srážky ze mzdy ani exekuční platby: jejich termín plyne ze
 * smlouvy nebo z rozhodnutí, ne ze zákonné lhůty, a přimíchat je by z hlídače
 * termínů udělalo výpis všech plateb. Stejně tak položky checklistu bez
 * odvozené lhůty (`due_date IS NULL`) — připomínat termín, který neexistuje,
 * je ta nejjistější cesta, jak obsluhu naučit hlášky přeskakovat.
 *
 * Cron ani e-mail tady NENÍ. Přehled je čtecí; rozeslat ho je samostatné
 * rozhodnutí s vlastními následky (komu, jak často, co s firmou bez účetní).
 */
final readonly class PayrollDeadlineOverviewService
{
    /**
     * Fáze termínu, jak je vidí klient. Pořadí je zároveň pořadím naléhavosti,
     * ve kterém se přehled zobrazuje.
     *
     * @var list<string>
     */
    public const PHASES = [
        'overdue',
        'due_today',
        'due_soon',
        'action_required',
        'awaiting_result',
        'open',
    ];

    /**
     * Odkud termín pochází. Účetní to řeší až jako druhé — primárně ji zajímá,
     * co je pozdě — ale rozhoduje to, kam vede proklik.
     *
     * @var list<string>
     */
    public const SOURCES = [
        'submission',
        'levy',
        'checklist',
        'registration_change',
        'tax_statement',
        'sickness_case',
    ];

    /** Kolik dnů dopředu se termín považuje za „brzy". */
    private const DUE_SOON_DAYS = 5;

    /** Výchozí dohled dopředu — pokrývá celý příští měsíc včetně 20. dne. */
    public const DEFAULT_HORIZON_DAYS = 45;

    public const MAX_HORIZON_DAYS = 400;

    /**
     * Jak hluboko do minulosti se zmeškané termíny ještě ukazují. Bez meze by
     * dashboard firmy s historií vypsal roky staré nedodělky a to podstatné
     * by v nich zaniklo.
     */
    private const OVERDUE_LOOKBACK_DAYS = 400;

    public function __construct(
        private PayrollDeadlineOverviewRepository $repository,
        private PayrollDeadlineAssessmentService $assessments,
        private PayrollRegistrationChangeProposalRepository $registrationChanges,
        private PayrollRegistrationChangeDetectionService $changeDetection,
        private PayrollTaxStatementDeadlinePolicy $taxStatementDeadlines,
        private PayrollSicknessCaseRepository $sicknessCases,
        private SicknessDeadlinePolicy $sicknessDeadlines,
        private ClockInterface $clock,
    ) {}

    /**
     * @return array{
     *   as_of:string,horizon_days:int,window:array{from:string,to:string},
     *   summary:array<string,int>,
     *   items:list<array<string,mixed>>
     * }
     */
    public function overview(
        int $supplierId,
        string $environment,
        int $horizonDays = self::DEFAULT_HORIZON_DAYS,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma přehledu mzdových termínů není platná.',
            );
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí přehledu mzdových termínů musí být production nebo test.',
            );
        }
        if ($horizonDays < 1 || $horizonDays > self::MAX_HORIZON_DAYS) {
            throw new \InvalidArgumentException(
                'Dohled přehledu mzdových termínů musí být 1 až '
                . self::MAX_HORIZON_DAYS . ' dnů.',
            );
        }
        $today = $this->today();
        $from = $today
            ->sub(new \DateInterval('P' . self::OVERDUE_LOOKBACK_DAYS . 'D'))
            ->format('Y-m-d');
        $to = $today
            ->add(new \DateInterval('P' . $horizonDays . 'D'))
            ->format('Y-m-d');

        $items = $this->buildItems($supplierId, $environment, $from, $to);
        $summary = ['total' => count($items)]
            + array_fill_keys(self::PHASES, 0);
        foreach ($items as $item) {
            $phase = (string) $item['phase'];
            if (array_key_exists($phase, $summary) && $phase !== 'total') {
                ++$summary[$phase];
            }
        }

        return [
            'as_of' => $today->format('Y-m-d'),
            'horizon_days' => $horizonDays,
            'window' => ['from' => $from, 'to' => $to],
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * Tytéž prameny jako {@see self::overview()}, ale nad LIBOVOLNÝM oknem
     * `[$from, $to]` místo dohledu od dneška.
     *
     * Vznikla pro měsíční přehled pro účetní ({@see \MyInvoice\Service\Payroll\Submission\PayrollMonthlyChecklistService}):
     * ten se ptá na konkrétní zvolený měsíc, ne na „co hoří teď", takže okno
     * musí zadat volající, ne horizont od dnešního dne. Souhrn (`summary`) tu
     * záměrně není — skládá si ho volající sám nad SLOUČENÝM seznamem položek
     * z více pramenů, jinak by dva souhrny (tenhle a checklistu) mohly tvrdit
     * dvě různé pravdy o tomtéž měsíci.
     *
     * @return list<array<string,mixed>>
     */
    public function itemsForWindow(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma přehledu mzdových termínů není platná.',
            );
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí přehledu mzdových termínů musí být production nebo test.',
            );
        }
        if ($from > $to) {
            throw new \InvalidArgumentException(
                'Počátek okna přehledu mzdových termínů nesmí být po jeho konci.',
            );
        }

        return $this->buildItems($supplierId, $environment, $from, $to);
    }

    /** @return list<array<string,mixed>> */
    private function buildItems(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        // Detekce se přepočítá dřív, než se přehled poskládá. Katalog lhůt
        // dosud jen PŘIPOMÍNAL a neměl vazbu na službu, která povinnost splní:
        // změnu údaje nikdo nesledoval, takže osmidenní lhůta neměla kde
        // vzniknout. Přepočet je omezený vodoznakem, takže firma s pěti sty
        // zaměstnanci zaplatí jeden dotaz, ne pět set dešifrování.
        try {
            $this->changeDetection->sweep($supplierId, $environment);
        } catch (\Throwable) {
            // Hlídač termínů musí ukázat i to, co ví, když detekce selže.
            // Prázdný dashboard je horší než dashboard bez jedné sekce.
        }

        $items = [
            ...$this->submissionItems($supplierId, $environment, $from, $to),
            ...$this->levyItems($supplierId, $from, $to),
            ...$this->checklistItems($supplierId, $from, $to),
            ...$this->registrationChangeItems($supplierId, $environment, $from, $to),
            ...$this->taxStatementItems($supplierId, $from, $to),
            ...$this->sicknessCaseItems($supplierId, $environment, $from, $to),
        ];
        usort(
            $items,
            static fn (array $a, array $b): int
                => [$a['due_on'], $a['source'], $a['title']]
                <=> [$b['due_on'], $b['source'], $b['title']],
        );

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function submissionItems(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->repository->submissionDeadlines(
            $supplierId,
            $environment,
            $from,
            $to,
        ) as $row) {
            $assessment = $this->assessments->assess(
                (string) $row['earliest_submission_on'],
                (string) $row['due_on'],
                (string) $row['status'],
                $row['submission_status'] === null
                    ? null
                    : (string) $row['submission_status'],
            );
            // `not_open` a `fulfilled` do hlídače nepatří: první ještě nejde
            // podat, druhý je hotový. Kdyby se ukazovaly, tvořily by většinu
            // seznamu a to podstatné by v nich zaniklo.
            if (in_array(
                $assessment->phase,
                ['not_open', 'fulfilled', 'cancelled'],
                true,
            )) {
                continue;
            }
            $items[] = [
                'source' => 'submission',
                'reference' => 'payroll_obligation:' . (int) $row['obligation_id'],
                'title' => (string) $row['agenda_code'],
                'subject' => (string) $row['subject_reference'],
                'period' => substr((string) $row['period_start'], 0, 7),
                'due_on' => (string) $row['due_on'],
                'phase' => $assessment->phase,
                'days_to_due' => $assessment->daysToDue,
                'is_overdue' => $assessment->isOverdue,
                'status' => (string) $row['status'],
                'submission_status' => $row['submission_status'],
                'ruleset_id' => (string) $row['ruleset_id'],
                'path' => '/payroll/submissions',
            ];
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function levyItems(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->repository->levyDeadlines(
            $supplierId,
            $from,
            $to,
        ) as $row) {
            $dueOn = (string) $row['due_on'];
            $remaining = (int) $row['amount_minor'] - (int) $row['settled_minor'];
            $items[] = [
                'source' => 'levy',
                'reference' => 'payroll_liability:' . (int) $row['liability_id'],
                'title' => (string) $row['liability_kind'],
                'subject' => (string) $row['recipient_reference'],
                'period' => substr((string) $row['period_start'], 0, 7),
                'due_on' => $dueOn,
                'phase' => $this->phase($dueOn),
                'days_to_due' => $this->daysToDue($dueOn),
                'is_overdue' => $this->phase($dueOn) === 'overdue',
                'remaining_minor' => $remaining,
                'run_id' => (int) $row['run_id'],
                'path' => '/payroll/payments',
            ];
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function checklistItems(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->repository->checklistDeadlines(
            $supplierId,
            $from,
            $to,
        ) as $row) {
            $dueOn = (string) $row['due_date'];
            $items[] = [
                'source' => 'checklist',
                'reference' => 'payroll_checklist_item:' . (int) $row['item_id'],
                'title' => (string) $row['item_key'],
                'subject' => (string) $row['full_name'],
                'period' => null,
                'due_on' => $dueOn,
                'phase' => $this->phase($dueOn),
                'days_to_due' => $this->daysToDue($dueOn),
                'is_overdue' => $this->phase($dueOn) === 'overdue',
                'employment_id' => (int) $row['employment_id'],
                'employee_id' => (int) $row['employee_id'],
                'checklist_phase' => (string) $row['phase'],
                'deadline_source' => $row['deadline_source'],
                'deadline_source_status' => $row['deadline_source_status'],
                // `/payroll/employees/{id}` neexistuje — ta cesta byla přepsaná
                // z názvu tabulky, ne z routeru, takže odkaz z přehledu termínů
                // vedl na prázdno. Adresa karty člověka je `/payroll/people/{id}`.
                'path' => '/payroll/people/' . (int) $row['employee_id'],
            ];
        }

        return $items;
    }

    /**
     * Nesplněné registrační povinnosti z detekce změn.
     *
     * Položka nese `proposal_id`, takže z přehledu vede proklik rovnou na
     * tlačítko, které povinnost splní — na rozdíl od checklistové položky
     * `social_jmhz_change`, která je jen to-do bez vazby na podání.
     *
     * @return list<array<string,mixed>>
     */
    private function registrationChangeItems(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->registrationChanges->openDeadlines(
            $supplierId,
            $environment,
            $from,
            $to,
        ) as $row) {
            $dueOn = (string) $row['due_on'];
            $items[] = [
                'source' => 'registration_change',
                'reference' => 'payroll_registration_change_proposal:'
                    . (int) $row['proposal_id'],
                'title' => (string) $row['duty_kind'],
                'subject' => (string) $row['full_name'],
                'period' => null,
                'due_on' => $dueOn,
                'phase' => $this->phase($dueOn),
                'days_to_due' => $this->daysToDue($dueOn),
                'is_overdue' => $this->phase($dueOn) === 'overdue',
                'employment_id' => (int) $row['employment_id'],
                'employee_id' => (int) $row['employee_id'],
                'proposal_id' => (int) $row['proposal_id'],
                'action_code' => $row['action_code'] === null
                    ? null
                    : (int) $row['action_code'],
                'detected_on' => (string) $row['detected_on'],
                'deadline_source' => (string) $row['deadline_source'],
                'deadline_source_status' => 'statute_verified',
                'deadline_ruleset_id' => (string) $row['deadline_ruleset_id'],
                'path' => '/payroll/people/' . (int) $row['employee_id'],
            ];
        }

        return $items;
    }

    /**
     * Nepodaná roční vyúčtování daně s termínem v okně.
     *
     * Na rozdíl od ostatních pramenů tady NENÍ řádek v evidenci, který by se
     * dal vypsat: povinnost vzniká ze zákona tím, že firma v roce vyplácela
     * příjmy ze závislé činnosti, ne tím, že ji někdo někam zapsal. Termín se
     * proto skládá obráceně — nejdřív se spočítá, které roky mají lhůtu
     * v okně, a teprve pak se u nich ověřuje podklad a stav podání. Kdyby se
     * měla povinnost nejdřív materializovat do `payroll_obligations`, znamenalo
     * by to nový agenda kód, migraci ENUMu a generátor, který jednou za rok
     * založí dva řádky — a hlavně by termín nevznikl firmě, která si modul
     * zapne v únoru, tedy přesně té, která ho potřebuje nejvíc.
     *
     * @return list<array<string,mixed>>
     */
    private function taxStatementItems(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        /** @var array<string,PayrollTaxStatementDeadlineWindow> $windows */
        $windows = [];
        $firstYear = max(
            PayrollTaxStatementDeadlinePolicy::SUPPORTED_FROM_YEAR,
            (int) substr($from, 0, 4) - 1,
        );
        $lastYear = min(
            PayrollTaxStatementDeadlinePolicy::SUPPORTED_TO_YEAR,
            (int) substr($to, 0, 4),
        );
        for ($year = $firstYear; $year <= $lastYear; ++$year) {
            foreach (TaxStatementService::FORMS as $formCode) {
                $window = $this->taxStatementDeadlines->forYear($formCode, $year);
                if ($window->dueOn >= $from && $window->dueOn <= $to) {
                    $windows[$formCode . ':' . $year] = $window;
                }
            }
        }
        if ($windows === []) {
            return [];
        }

        $years = array_values(array_unique(array_map(
            static fn (PayrollTaxStatementDeadlineWindow $w): int => $w->year,
            $windows,
        )));
        $basis = $this->repository->taxStatementBasisYears($supplierId, $years);
        $filed = $this->repository->filedTaxStatementYears(
            $supplierId,
            TaxStatementService::FORMS,
            $years,
        );

        $items = [];
        foreach ($windows as $window) {
            $year = $window->year;
            $yearBasis = $basis[$year] ?? null;
            if ($yearBasis === null || $yearBasis['approved_runs'] < 1) {
                continue;
            }
            // Vyúčtování srážkové daně podává jen ten, kdo v roce opravdu
            // srážel. Připomínat prázdný tiskopis firmě, která má samé HPP
            // s podepsaným prohlášením, je přesně ten druh hlášky, kterou se
            // obsluha naučí přeskakovat — a s ní i tu vedle.
            if ($window->formCode === TaxStatementService::FORM_WITHHOLDING_TAX
                && $yearBasis['withholding_minor'] === 0
            ) {
                continue;
            }
            if (in_array($year, $filed[$window->formCode] ?? [], true)) {
                continue;
            }

            $items[] = [
                'source' => 'tax_statement',
                'reference' => 'tax_statement:' . $window->formCode . ':' . $year,
                'title' => $window->formCode,
                'subject' => $window->legalReference,
                // Vyúčtování je ROČNÍ; `period` je v přehledu měsíc a klient ho
                // tak i formátuje, takže „prosinec 2025" by tvrdil něco jiného
                // než tiskopis. Rok nese `statement_year`.
                'period' => null,
                'due_on' => $window->dueOn,
                'phase' => $this->phase($window->dueOn),
                'days_to_due' => $this->daysToDue($window->dueOn),
                'is_overdue' => $this->phase($window->dueOn) === 'overdue',
                'form_code' => $window->formCode,
                'statement_year' => $year,
                'statutory_due_on' => $window->statutoryDueOn,
                'electronic_due_on' => $window->electronicDueOn,
                'extendable' => $window->extendable,
                'deadline_source' => $window->legalReference,
                'deadline_source_status' => 'statute_verified',
                'deadline_ruleset_id' => $window->rulesetId,
                // Panel vyúčtování žije na mzdovém rozcestníku, ne na vlastní
                // routě; kotva doveze účetní rovnou k němu, ne na začátek
                // dlouhé stránky.
                'path' => '/payroll#payroll-tax-statement',
            ];
        }

        return $items;
    }

    /**
     * Lhůty NEMPRI a HZUPN z evidovaných případů dávek.
     *
     * Jeden případ může nést až DVĚ nesplněné povinnosti s různými termíny:
     * oznámení o žádosti o dávku (§ 97 odst. 1 a 2) a hlášení při ukončení
     * pracovní neschopnosti (§ 97 odst. 3). Vypisují se proto zvlášť — sloučit
     * je pod jednu položku by znamenalo, že splněné oznámení schová nesplněné
     * hlášení.
     *
     * HZUPN se objeví teprve tehdy, když je znám den skončení neschopnosti;
     * dřív povinnost neexistuje a politika lhůtu odmítne spočítat. Ostatní
     * chyby výpočtu (chybějící výplatní den u vyrovnávacího příspěvku) položku
     * jen přeskočí — hlídač termínů musí ukázat i to, co ví.
     *
     * @return list<array<string,mixed>>
     */
    private function sicknessCaseItems(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->sicknessCases->openCases($supplierId, $environment) as $row) {
            $kind = SicknessBenefitKind::tryFrom((string) $row['benefit_kind']);
            if ($kind === null) {
                continue;
            }
            $incapacityFrom = (string) $row['incapacity_from'];
            $incapacityTo = $row['incapacity_to'] === null
                ? null
                : (string) $row['incapacity_to'];
            $documents = [
                'nempri' => [
                    'agenda' => 'NEMPRI',
                    'submitted' => $row['nempri_submission_id'] !== null,
                ],
                'hzupn' => [
                    'agenda' => 'HZUPN',
                    'submitted' => $row['hzupn_submission_id'] !== null,
                ],
            ];
            foreach ($documents as $document => $meta) {
                if ($meta['submitted']) {
                    continue;
                }
                try {
                    $window = $document === 'nempri'
                        ? $this->sicknessDeadlines->forNempri(
                            $kind,
                            $incapacityFrom,
                            $incapacityTo,
                            $row['payroll_payment_date'] === null
                                ? null
                                : (string) $row['payroll_payment_date'],
                        )
                        : $this->sicknessDeadlines->forHzupn(
                            $incapacityFrom,
                            $incapacityTo,
                        );
                } catch (SicknessException) {
                    continue;
                }
                if ($window->dueOn < $from || $window->dueOn > $to) {
                    continue;
                }
                $items[] = [
                    'source' => 'sickness_case',
                    'reference' => 'payroll_sickness_case:' . (int) $row['case_id'],
                    'title' => $meta['agenda'],
                    'subject' => (string) $row['full_name'],
                    'period' => null,
                    'due_on' => $window->dueOn,
                    'phase' => $this->phase($window->dueOn),
                    'days_to_due' => $this->daysToDue($window->dueOn),
                    'is_overdue' => $this->phase($window->dueOn) === 'overdue',
                    'case_id' => (int) $row['case_id'],
                    'document_kind' => $document,
                    'benefit_kind' => $kind->value,
                    'employment_id' => (int) $row['employment_id'],
                    'employee_id' => (int) $row['employee_id'],
                    'status' => (string) $row['status'],
                    'deadline_source' => $window->legalReference,
                    'deadline_source_status' => $window->sourceStatus,
                    'deadline_ruleset_id' => $window->rulesetId,
                    'path' => '/payroll/submissions',
                ];
            }
        }

        return $items;
    }

    /**
     * Fáze termínu u pramenů, které stav podání nemají.
     *
     * Prahy jsou schválně tytéž jako v {@see PayrollDeadlineAssessmentService}
     * — kdyby se rozešly, znamenalo by „brzy" na dashboardu něco jiného než
     * „brzy" u povinnosti podání a přehled by si protiřečil sám se sebou.
     */
    private function phase(string $dueOn): string
    {
        $days = $this->daysToDue($dueOn);
        if ($days < 0) {
            return 'overdue';
        }
        if ($days === 0) {
            return 'due_today';
        }

        return $days <= self::DUE_SOON_DAYS ? 'due_soon' : 'open';
    }

    private function daysToDue(string $dueOn): int
    {
        $due = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $dueOn,
            new \DateTimeZone('Europe/Prague'),
        );
        if (!$due instanceof \DateTimeImmutable
            || $due->format('Y-m-d') !== $dueOn
        ) {
            throw new \UnexpectedValueException(
                'Termín mzdové povinnosti není platné datum.',
            );
        }

        return (int) $this->today()->diff($due)->format('%r%a');
    }

    private function today(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->setTime(0, 0);
    }
}
