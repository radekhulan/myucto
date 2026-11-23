<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Reports\DimensionProfitService;
use MyInvoice\Service\Accounting\Reports\DimensionAnalyticsTables;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Automation\AutomationFeedService;
use MyInvoice\Service\Pdf\DimensionPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výsledovka po dimenzi.
 *
 *   GET /api/accounting/reports/dimension-profit?type_id=&from=&to=
 *       [&scope=group][&value_id=][&responsible_user_id=][&accounts=1][&companies=1]
 *   GET /api/accounting/reports/dimension-profit/export — totéž jako XLSX (vždy s rozpadem po účtech)
 *
 * `scope=group` u globálního typu sečte firmy skupiny ({@see DimensionGroupScope}),
 * `value_id` omezí sestavu na větev hodnoty, `responsible_user_id` na hodnoty odpovědné
 * osoby, `accounts=1` přidá rozpad po syntetických účtech a `companies=1` součty po firmách.
 */
final class DimensionProfitAction
{
    use AccountingActionSupport;
    use DimensionGroupScope;

    public function __construct(
        private readonly DimensionProfitService $service,
        private readonly DimensionRepository $dimensions,
        private readonly AutomationFeedService $feed,
        private readonly ReportXlsxExporter $xlsx,
        private readonly DimensionPdfRenderer $pdf,
        private readonly FinancialStatementService $statements,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $data = $this->report($request, $response, false, $err);
        return $data === null ? $err : Json::ok($response, $data);
    }

    public function analytics(Request $request, Response $response): Response
    {
        $data = $this->analyticsReport($request, $response, $err);
        return $data === null ? $err : Json::ok($response, $data);
    }

    public function exportAnalytics(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $format = (string) ($q['format'] ?? '');
        $table = (string) ($q['table'] ?? '');
        $valueId = (string) ($q['value_id'] ?? 'total');
        $metric = (string) ($q['metric'] ?? 'result');
        if (!in_array($format, ['pdf', 'xlsx'], true) || !in_array($table, ['comparison', 'companies', 'monthly'], true)
            || !in_array($metric, ['revenue', 'cost', 'result'], true)
            || !preg_match('/^(total|unassigned|[1-9][0-9]*)$/D', $valueId)) {
            return Json::error($response, 'validation_failed', 'Neplatný formát nebo tabulka exportu.', 422);
        }
        $data = $this->analyticsReport($request, $response, $err);
        if ($data === null) return $err;
        try {
            $model = DimensionAnalyticsTables::build($data, $table, $valueId, $metric);
        } catch (\InvalidArgumentException) {
            return Json::error($response, 'not_found', 'Hodnota dimenze nenalezena.', 404);
        }
        $out = $format === 'pdf'
            ? ['bytes' => $this->pdf->renderTable($model), 'filename' => $model['filename'] . '.pdf', 'mime' => 'application/pdf']
            : $this->xlsx->dimensionAnalyticsTable($model);
        return $this->fileResponse($response, $out);
    }

    /** @return array<string,mixed>|null */
    private function analyticsReport(Request $request, Response $response, ?Response &$err): ?array
    {
        $err = null;
        $supplierId = $this->currentSupplierId($request);
        $q = $request->getQueryParams();
        $typeId = filter_var($q['type_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $year = filter_var($q['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
        $selected = $q['supplier_id'] ?? null;
        if ($typeId === false || $typeId === null || $year === false || $year === null
            || ($selected !== null && $selected !== 'all' && filter_var($selected, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            $err = Json::error($response, 'validation_failed', 'type_id, year a supplier_id jsou neplatné.', 422);
            return null;
        }
        if (!$this->dimensions->enabled($supplierId)) {
            $err = Json::error($response, 'forbidden', 'Dimenze nejsou zapnuté.', 403);
            return null;
        }
        $type = $this->dimensions->findType($supplierId, (int) $typeId);
        if ($type === null) {
            $err = Json::error($response, 'not_found', 'Typ dimenze nenalezen.', 404);
            return null;
        }

        $members = [['id' => $supplierId, 'company_name' => $this->dimensions->supplierName($supplierId)]];
        $hidden = 0;
        if ($type['level'] === 'global') {
            $bound = self::isSupplierBoundRequest($request);
            $allowed = $this->feed->allowedSupplierIds((int) ($this->userId($request) ?? 0), RequestAuthorization::isSuperadmin($request), RequestAuthorization::boundSupplierId($request), $request);
            $members = [];
            foreach ($this->dimensions->groupMembers((int) $type['supplier_group_id']) as $member) {
                if ($member['id'] === $supplierId || (!$bound && in_array($member['id'], $allowed, true))) {
                    $members[] = $member;
                } else {
                    $hidden++;
                }
            }
        }
        if ($selected === 'all') {
            if ($type['level'] !== 'global') {
                $err = Json::error($response, 'validation_failed', 'Součet firem vyžaduje globální dimenzi.', 422);
                return null;
            }
            $chosen = $members;
        } elseif ($selected !== null) {
            $chosen = array_values(array_filter($members, static fn (array $member): bool => $member['id'] === (int) $selected));
            if ($chosen === []) {
                $err = Json::error($response, 'forbidden', 'K této firmě nemáte přístup.', 403);
                return null;
            }
        } else {
            $chosen = array_values(array_filter($members, static fn (array $member): bool => $member['id'] === $supplierId));
        }
        $data = $this->service->analytics($supplierId, (int) $typeId, (int) $year, $chosen);
        $data['available_companies'] = $members;
        $data['hidden_companies'] = $hidden;
        return $data;
    }

    public function export(Request $request, Response $response): Response
    {
        $format = (string) ($request->getQueryParams()['format'] ?? 'xlsx');
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }
        $data = $this->report($request, $response, true, $err);
        if ($data === null) {
            return $err;
        }
        $data['entity'] = $this->statements->entityHeader($this->currentSupplierId($request));
        $out = $format === 'pdf'
            ? ['bytes' => $this->pdf->render($data), 'filename' => 'vysledovka-po-dimenzi-' . $data['from'] . '.pdf', 'mime' => 'application/pdf']
            : $this->xlsx->dimensionProfit($data);
        return $this->fileResponse($response, $out);
    }

    private function fileResponse(Response $response, array $out): Response
    {
        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** @return array<string,mixed>|null */
    private function report(Request $request, Response $response, bool $forceAccounts, ?Response &$err): ?array
    {
        $err = null;
        $supplierId = $this->currentSupplierId($request);
        $q = $request->getQueryParams();
        $typeId = (int) ($q['type_id'] ?? 0);
        $from = (string) ($q['from'] ?? '');
        $to = (string) ($q['to'] ?? '');
        if ($typeId <= 0 || !self::isReportDate($from) || !self::isReportDate($to) || $from > $to) {
            $err = Json::error($response, 'validation_failed', 'type_id, from a to (YYYY-MM-DD, from ≤ to) jsou povinné.', 422);
            return null;
        }
        $type = $this->dimensions->findType($supplierId, $typeId);
        if ($type === null) {
            $err = Json::error($response, 'not_found', 'Typ dimenze nenalezen.', 404);
            return null;
        }
        [$supplierIds, $hidden] = $this->groupScopeSuppliers($request, $this->dimensions, $this->feed, $supplierId, $type);

        $options = [
            'value_id' => (int) ($q['value_id'] ?? 0) ?: null,
            'responsible_user_id' => (int) ($q['responsible_user_id'] ?? 0) ?: null,
            'accounts' => $forceAccounts || (string) ($q['accounts'] ?? '') === '1',
            'companies' => (string) ($q['companies'] ?? '') === '1',
        ];
        if ($options['value_id'] !== null) {
            $value = $this->dimensions->findValue($supplierId, $options['value_id']);
            if ($value === null || (int) $value['type_id'] !== $typeId) {
                $err = Json::error($response, 'not_found', 'Hodnota dimenze nenalezena.', 404);
                return null;
            }
        }
        try {
            $data = $this->service->build($supplierId, $typeId, $from, $to, $supplierIds, $options);
        } catch (ReportException $e) {
            $err = Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
            return null;
        }
        $data['hidden_companies'] = $hidden;
        return $data;
    }
}
