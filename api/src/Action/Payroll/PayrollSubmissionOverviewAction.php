<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Service\Payroll\Submission\PayrollObligationSubjectFormatter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollSubmissionOverviewAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollSubmissionRepository $repository,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollDeadlineAssessmentService $deadlines,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
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
        foreach ($this->repository->overviewSummaryRows(
            $supplierId,
            $environment,
            $periodStart,
            $periodEnd,
            $agendaGroup,
        ) as $row) {
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

        // Posouzení termínu u ZOBRAZENÝCH řádků — tady kvůli tomu, co uživatel
        // u řádku vidí, ne kvůli souhrnu.
        foreach ($items as &$item) {
            $item['deadline'] = $this->deadlines->assess(
                $item['earliest_submission_on'],
                $item['due_on'],
                $item['status'],
                $item['latest_submission']['status'] ?? null,
            )->toArray();
            // `subject_reference` je interní složený klíč — účetní s ním nic
            // neudělá. `subject_label` dodává jen to, co jde ověřit ze
            // sdíleného formátovače; zbytek zůstává `null`, ne hádaný.
            $item['subject_label'] = PayrollObligationSubjectFormatter::humanSubject(
                $item['agenda_code'],
                $item['subject_reference'],
            );
        }
        unset($item);

        // Klíč `items` zůstává kvůli stávajícím volajícím.
        return Json::ok($response, [
            'environment' => $environment,
            'period' => substr($periodStart, 0, 7),
            'agenda_group' => $agendaGroup,
            'summary' => $summary,
            'deadline_summary' => $deadlineSummary,
            'items' => $items,
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
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
