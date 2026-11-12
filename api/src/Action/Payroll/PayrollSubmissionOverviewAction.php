<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollSigningProfileRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationGate;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapabilityCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchGate;
use MyInvoice\Service\Payroll\Submission\PayrollAgendaGroupCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollMonthlyAgendaDutyService;
use MyInvoice\Service\Payroll\Submission\PayrollObligationSubjectResolver;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionDeliveryProof;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionManualAcceptanceReader;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionSettlementPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollSubmissionOverviewAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollSubmissionRepository $repository,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollDeadlineAssessmentService $deadlines,
        private readonly PayrollSubmissionSettlementPolicy $settlements,
        private readonly PayrollDispatchCapabilityCatalog $capabilities,
        private readonly PayrollObligationSubjectResolver $subjects,
        private readonly PayrollSubmissionManualAcceptanceReader $manualAcceptances,
        private readonly JmhzPreparationGate $jmhzGate,
        private readonly PayrollSigningProfileRepository $signingProfiles,
        private readonly PayrollMonthlyAgendaDutyService $agendaDuties,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            AccessLevel::READ,
            $error,
        )) {
            if ($error === null) {
                throw new \LogicException('Chybí odpověď pro zamítnuté oprávnění.');
            }
            return $error;
        }
        if (!$this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        )) {
            if ($error === null) {
                throw new \LogicException('Chybí odpověď pro vypnutý modul mezd.');
            }
            return $error;
        }

        try {
            $query = $request->getQueryParams();
            $environment = $this->environment($query['environment'] ?? null);
            [$periodStart, $periodEnd] = $this->period(
                $query['period'] ?? null,
            );
            $agendaGroup = $this->agendaGroup($query['agenda_group'] ?? null);
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        // Strop je tvrdý, ne jen výchozí — z URL ho zvednout nejde.
        $limit = max(1, min(
            PayrollSubmissionRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollSubmissionRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $supplierId = $this->currentSupplierId($request);
        // Skupina agend se filtruje na SERVERU. Panel ukazuje vždy jen jednu
        // (JMHZ / zdravotní) a kdyby si ji odfiltroval až z přijaté stránky,
        // pager by počítal řádky obou a tabulka ukazovala jen některé.
        $page = $this->repository->listOverview(
            $supplierId,
            $environment,
            $periodStart,
            $periodEnd,
            $limit,
            $offset,
            $agendaGroup,
        );
        $items = $page['items'];

        // Oba souhrny se počítají nad CELÝM obdobím, ne nad stránkou. Dřív
        // `summary.total` hlásil počet řádků po tichém oříznutí na dvě stě,
        // takže se tvářil jako pravda a přitom říkal jen „kolik se vešlo".
        $summary = [
            'total' => $page['total'],
            'open' => 0,
            'prepared' => 0,
            'submitted' => 0,
            'fulfilled' => 0,
            'overdue' => 0,
            'manual_review' => 0,
            'other' => 0,
        ];
        $deadlineSummary = [
            'not_open' => 0,
            'open' => 0,
            'due_soon' => 0,
            'due_today' => 0,
            'overdue' => 0,
            'awaiting_result' => 0,
            'fulfilled' => 0,
            'action_required' => 0,
            'cancelled' => 0,
        ];
        $summaryRows = $this->repository->overviewSummaryRows(
            $supplierId,
            $environment,
            $periodStart,
            $periodEnd,
            $agendaGroup,
        );
        foreach ($summaryRows as $row) {
            $status = $row['status'];
            if (array_key_exists($status, $summary) && $status !== 'total') {
                ++$summary[$status];
            } else {
                ++$summary['other'];
            }
            ++$deadlineSummary[$this->deadlines->assess(
                $row['earliest_submission_on'],
                $row['due_on'],
                $status,
                $row['submission_status'],
            )->phase];
        }

        /*
         * Povinnosti období, ke kterým v evidenci ještě není řádek (typicky
         * JMHZ za schválený měsíc, které se zatím nepřipravilo). Měsíční přehled
         * nad panelem je hlásí jako „Nepřipraveno"; souhrn panelu bez nich
         * ukazoval „Celkem 0", jako by za měsíc nebylo co podat. Tentýž pramen
         * a tentýž doplněk k evidenci jako měsíční přehled
         * ({@see PayrollMonthlyAgendaDutyService::unprepared()}).
         */
        $unpreparedSummary = array_fill_keys(array_keys($deadlineSummary), 0);
        foreach ($this->agendaDuties->unprepared(
            $supplierId,
            substr($periodStart, 0, 7),
            array_map(
                static fn (array $row): array => [
                    'agenda_code' => $row['agenda_code'],
                    'subject_reference' => $row['subject_reference'],
                ],
                $summaryRows,
            ),
        ) as $duty) {
            if ($agendaGroup !== null
                && PayrollAgendaGroupCatalog::groupOf((string) $duty['agenda_code']) !== $agendaGroup
            ) {
                continue;
            }
            $phase = (string) $duty['phase'];
            if (array_key_exists($phase, $unpreparedSummary)) {
                ++$unpreparedSummary[$phase];
            }
        }

        // Doklad o odeslání pro CELOU stránku jedním dotazem. Rozhoduje o tom,
        // jestli u řádku svítí „Označit za vyřízené" — a to se nesmí ptát
        // v cyklu, při padesáti řádcích by to bylo padesát dotazů.
        $outboxes = $this->repository->dispatchOutboxesBySubmission(
            $supplierId,
            $environment,
            array_values(array_filter(array_map(
                static fn (array $row): int => (int) (
                    $row['latest_submission']['id'] ?? 0
                ),
                $items,
            ))),
        );

        // `subject_reference` je interní složený klíč — účetní s ním nic
        // neudělá. `subject_label` dodává jen to, co jde ověřit (jméno osoby
        // k vztahu, účtárna, pojišťovna); zbytek zůstává `null`, ne hádaný.
        $subjects = $this->subjects->resolve($supplierId, $items);

        // Ruční potvrzení přijetí jde k řádku zvlášť od stavu: „přijato" podle
        // člověka se nesmí v seznamu tvářit jako přijetí podle protokolu.
        $manualAcceptances = $this->manualAcceptances->summaries(
            $supplierId,
            $environment,
            array_values(array_filter(array_map(
                static fn (array $row): int => (int) (
                    $row['latest_submission']['id'] ?? 0
                ),
                $items,
            ))),
        );

        $signingReady = $this->signingProfiles->find($supplierId, $environment) !== null;
        $gate = new PayrollDispatchGate();

        // Posouzení termínu u ZOBRAZENÝCH řádků — tady kvůli tomu, co uživatel
        // u řádku vidí, ne kvůli souhrnu.
        foreach ($items as $index => &$item) {
            $item['deadline'] = $this->deadlines->assess(
                $item['earliest_submission_on'],
                $item['due_on'],
                $item['status'],
                $item['latest_submission']['status'] ?? null,
            )->toArray();
            $item['subject_label'] = $subjects[$index]['subject_label'];
            $item['subject_employee_id'] = $subjects[$index]['subject_employee_id'];
            $item['settlement'] = $this->settlement($item, $outboxes);
            $item['manual_acceptance'] = $manualAcceptances[
                (int) ($item['latest_submission']['id'] ?? 0)
            ] ?? null;
            // Kanál, kterým poslední podání SKUTEČNĚ odešlo. `preferred_channel`
            // povinnosti je jen plán z doby založení (u PPZ „portál pojišťovny"),
            // a vedle zprávy odeslané datovkou by tvrdil něco jiného.
            $item['dispatch_channel'] = isset(
                $outboxes[(int) ($item['latest_submission']['id'] ?? 0)],
            ) ? 'isds' : null;
            /*
             * Kudy podání odejde, DOKUD neodešlo: tatáž volba jako při
             * odeslání frontou a jako sloupec „Jakou cestou" měsíčního
             * přehledu ({@see PayrollDispatchGate::channels()}). Bez ní řádek
             * ukazoval plán z doby založení povinnosti („VREP / APEP"),
             * zatímco přehled nad ním správně říkal „datová schránka".
             */
            $capability = $this->capabilities->forAgenda((string) $item['agenda_code']);
            $latestStatus = $item['latest_submission']['status'] ?? null;
            $item['planned_channel'] = null;
            if ($capability->isDispatchable()
                && ($latestStatus === null || in_array($latestStatus, ['validated', 'prepared', 'ready'], true))
            ) {
                $mode = $gate->channels($capability, $environment, $signingReady)['default'];
                $item['planned_channel'] = match (true) {
                    str_starts_with($mode, 'vrep_') => 'vrep_apep',
                    str_starts_with($mode, 'isds_') => 'isds',
                    default => null,
                };
            }
        }
        unset($item);

        // Klíč `items` zůstává kvůli stávajícím volajícím.
        return Json::ok($response, [
            'environment' => $environment,
            'period' => substr($periodStart, 0, 7),
            'agenda_group' => $agendaGroup,
            'summary' => $summary,
            'deadline_summary' => $deadlineSummary,
            // Nepřipravené povinnosti období podle fáze lhůty (bez řádku v evidenci).
            'unprepared_deadline_summary' => $unpreparedSummary,
            // Proč hlášení JMHZ za tohle období teď nejde zmrazit ani odeslat
            // (období neskončilo, ročník nejde sestavit); `null` = jde. Panel
            // odeslání podle toho zneaktivní tlačítka i tam, kde povinnost
            // ještě není v evidenci.
            'jmhz_preparation_gate' => $this->jmhzGate->blocked($periodStart),
            'items' => $items,
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Co smí účetní u řádku udělat s tím, že úřad neodpoví.
     *
     * `authority_reports_result` jde ven i tam, kde se uzavřít nedá: bez něj
     * by u přehledu pojišťovně svítilo „Čeká na výsledek podání" a nikde by
     * nestálo, že se nečeká na nic. Právě tenhle rozpor přiměl účetní hledat
     * odpověď, která nikdy nepřijde.
     *
     * @param array<string,mixed> $item
     * @param array<int,array<string,mixed>> $outboxes
     * @return array{
     *   authority_reports_result:bool,can_settle:bool,
     *   can_file_externally:bool,delivery_proof:?string
     * }
     */
    private function settlement(array $item, array $outboxes): array
    {
        $capability = $this->capabilities->forAgenda((string) $item['agenda_code']);
        $submission = $item['latest_submission'] ?? null;
        if (!is_array($submission)) {
            return [
                'authority_reports_result' => $capability->authorityReportsResult,
                'can_settle' => false,
                'can_file_externally' => false,
                'delivery_proof' => null,
            ];
        }
        $outbox = $outboxes[(int) $submission['id']] ?? null;

        return [
            'authority_reports_result' => $capability->authorityReportsResult,
            'can_settle' => $this->settlements->blockedReason(
                (string) $item['agenda_code'],
                (string) $item['status'],
                (string) $submission['status'],
                $outbox,
            ) === null,
            // Podání odeslané na portálu úřadu má vlastní, širší bránu —
            // aplikace ho nikdy neodeslala, takže na něj protokol nedorazí.
            'can_file_externally' => $this->settlements->externalFilingBlockedReason(
                (string) $item['agenda_code'],
                (string) $item['status'],
                (string) $submission['status'],
                $outbox,
            ) === null,
            'delivery_proof' => PayrollSubmissionDeliveryProof::reason($outbox),
        ];
    }

    private function agendaGroup(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)
            || !in_array($value, PayrollSubmissionRepository::AGENDA_GROUPS, true)
        ) {
            throw new \InvalidArgumentException(
                'Skupina agend musí být jedna z: '
                    . implode(', ', PayrollSubmissionRepository::AGENDA_GROUPS) . '.',
            );
        }

        return $value;
    }

    private function environment(mixed $value): string
    {
        if (!is_string($value)
            || !in_array($value, ['production', 'test'], true)
        ) {
            throw new \InvalidArgumentException(
                'Prostředí podání musí být production nebo test.',
            );
        }

        return $value;
    }

    /** @return array{string,string} */
    private function period(mixed $value): array
    {
        if (!is_string($value)
            || preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Období podání musí mít formát RRRR-MM.',
            );
        }
        $start = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value . '-01',
        );
        if (!$start instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException(
                'Období podání není platné.',
            );
        }

        return [
            $start->format('Y-m-d'),
            $start->modify('last day of this month')->format('Y-m-d'),
        ];
    }
}
