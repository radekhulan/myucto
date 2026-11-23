<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollRunPeopleRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Run\PayrollRunPersonFindings;
use MyInvoice\Service\Payroll\Run\PayrollWarningSuppressionService;
use MyInvoice\Service\Payroll\Security\PayrollEnforcementDisclosure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výsledky mzdového běhu po osobách.
 *
 * `GET /payroll/runs/{id}/people` je stránkovaný přehled (hrubá mzda, pojistné
 * zaměstnance, daň, čistá mzda, k výplatě, příznak nálezu) s řazením a hledáním
 * na serveru. `GET /payroll/runs/{id}/people/{employeeId}` vrací celý výsledek
 * jedné osoby pro rozpad výpočtu. Detail běhu výsledky osob nenese.
 *
 * Obojí čte aktuální revizi běhu a vyžaduje totéž právo jako detail běhu.
 */
final class PayrollRunPeopleAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollRunPeopleRepository $people,
        private readonly PayrollRunRepository $runs,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollWarningSuppressionService $warningSuppressions,
    ) {}

    /** @param array<string,string> $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $query = $request->getQueryParams();
        $sort = is_string($query['sort'] ?? null) && $query['sort'] !== ''
            ? $query['sort']
            : PayrollRunPeopleRepository::DEFAULT_SORT;
        $direction = is_string($query['dir'] ?? null) && $query['dir'] !== ''
            ? strtolower($query['dir'])
            : 'asc';
        if (!in_array($sort, PayrollRunPeopleRepository::SORT_KEYS, true)
            || !in_array($direction, ['asc', 'desc'], true)
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Neznámé řazení přehledu osob. Povolené sloupce: '
                    . implode(', ', PayrollRunPeopleRepository::SORT_KEYS) . '; směr asc nebo desc.',
                422,
            );
        }
        $search = is_string($query['q'] ?? null) ? trim($query['q']) : '';
        if (mb_strlen($search) > 100) {
            return Json::error($response, 'validation_failed', 'Hledaný text je příliš dlouhý.', 422);
        }
        $limit = max(1, min(
            PayrollRunPeopleRepository::MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollRunPeopleRepository::DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $onlyFindings = in_array($query['findings'] ?? null, ['1', 'true'], true);

        $revision = $this->people->currentRevision($supplierId, (int) ($args['id'] ?? 0));
        if ($revision === null) {
            return Json::error($response, 'not_found', 'Mzdový běh neexistuje.', 404);
        }
        $header = self::header($revision);
        $revisionId = $revision['revision_id'];
        if ($revisionId === null || !$revision['has_result']) {
            // Běh bez výsledku (koncept, zamčené vstupy) není chyba, jen nemá co ukázat.
            return Json::ok($response, $header + [
                'items' => [],
                'total' => 0,
                'limit' => $limit,
                'offset' => $offset,
                'sort' => $sort,
                'dir' => $direction,
                'totals' => null,
            ]);
        }

        $findings = $this->findings($supplierId, $revisionId);
        $page = $this->people->page(
            $supplierId,
            $revisionId,
            $limit,
            $offset,
            $sort,
            $direction === 'desc',
            $search,
            $onlyFindings ? array_keys($findings) : null,
        );
        $employments = $this->people->employments(
            $supplierId,
            $revisionId,
            array_map(static fn (array $item): int => $item['employee_id'], $page['items']),
        );
        $items = [];
        foreach ($page['items'] as $item) {
            $own = $findings[$item['employee_id']] ?? ['blockers' => 0, 'warnings' => 0];
            $item['employments'] = $employments[$item['employee_id']] ?? [];
            $item['findings'] = $own;
            $item['has_finding'] = $own['blockers'] > 0
                || $own['warnings'] > 0
                || in_array($item['result_status'], ['manual_review', 'error'], true);
            $items[] = $item;
        }

        return Json::ok($response, $header + [
            'items' => $items,
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
            'sort' => $sort,
            'dir' => $direction,
            'totals' => $page['totals'],
        ]);
    }

    /** @param array<string,string> $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $revision = $this->people->currentRevision($supplierId, (int) ($args['id'] ?? 0));
        if ($revision === null) {
            return Json::error($response, 'not_found', 'Mzdový běh neexistuje.', 404);
        }
        $employeeId = (int) ($args['employeeId'] ?? 0);
        $person = $revision['revision_id'] === null
            ? null
            : $this->people->person($supplierId, $revision['revision_id'], $employeeId);
        if ($person === null || $revision['revision_id'] === null) {
            return Json::error(
                $response,
                'not_found',
                'Osoba v aktuální revizi mzdového běhu nemá výsledek.',
                404,
            );
        }
        // Výsledek osoby nese celý vstup výpočtu srážek. Zúží se stejně jako
        // dřív v detailu běhu: obecné `payroll` READ na exekuce nestačí.
        $redacted = PayrollEnforcementDisclosure::redactRunSnapshot(
            ['people' => [$person['result']]],
            PayrollEnforcementDisclosure::canSeeEnforcement($request),
            PayrollEnforcementDisclosure::canSeeInsolvency($request),
        );
        $employments = $this->people->employments(
            $supplierId,
            $revision['revision_id'],
            [$employeeId],
        );

        return Json::ok($response, self::header($revision) + [
            'person' => [
                'employee_id' => $employeeId,
                'full_name' => $person['full_name'],
                'employments' => $employments[$employeeId] ?? [],
            ],
            'result' => $redacted['people'][0] ?? $person['result'],
        ]);
    }

    /**
     * Nálezy kontrol revize po osobách, bez trvale skrytých varování. Vazba
     * vztah → osoba se čte, jen když některá kontrola na vztah míří.
     *
     * @return array<int,array{blockers:int,warnings:int}>
     */
    private function findings(int $supplierId, int $revisionId): array
    {
        $visible = $this->warningSuppressions->activeSet($supplierId)
            ->filterRows($this->runs->validations($supplierId, $revisionId))['visible'];
        $needsOwners = false;
        foreach ($visible as $row) {
            if (($row['subject_type'] ?? null) === 'employment'
                || ($row['entity_type'] ?? null) === 'employment'
            ) {
                $needsOwners = true;
                break;
            }
        }

        return PayrollRunPersonFindings::byEmployee(
            $visible,
            $needsOwners ? $this->people->employmentOwners($supplierId, $revisionId) : [],
        );
    }

    /**
     * @param array<string,mixed> $revision
     * @return array<string,mixed>
     */
    private static function header(array $revision): array
    {
        return [
            'run_id' => $revision['run_id'],
            'period_start' => $revision['period_start'],
            'revision_id' => $revision['revision_id'],
            'revision_no' => $revision['revision_no'],
            'revision_status' => $revision['revision_status'],
        ];
    }

    private function authorize(Request $request, Response $response): ?Response
    {
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }
}
