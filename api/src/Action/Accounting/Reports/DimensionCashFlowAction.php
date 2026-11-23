<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Reports\DimensionCashFlowService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Automation\AutomationFeedService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Peněžní tok po dimenzi nepřímou metodou ({@see DimensionCashFlowService}).
 *
 *   GET /api/accounting/reports/dimension-cash-flow?from=&to=
 *       [&dimension_value_id=][&dimension_descendants=0][&scope=group]
 *   GET /api/accounting/reports/dimension-cash-flow/export — totéž jako XLSX
 *
 * Bez hodnoty dimenze jde o celou firmu. `scope=group` u hodnoty globálního typu
 * sečte firmy skupiny, ke kterým má uživatel přístup ({@see DimensionGroupScope}).
 */
final class DimensionCashFlowAction
{
    use AccountingActionSupport;
    use DimensionGroupScope;

    public function __construct(
        private readonly DimensionCashFlowService $service,
        private readonly DimensionService $dimensionService,
        private readonly DimensionRepository $dimensions,
        private readonly AutomationFeedService $feed,
        private readonly ReportXlsxExporter $xlsx,
        private readonly FinancialStatementService $statements,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $data = $this->report($request, $response, $err);
        return $data === null ? $err : Json::ok($response, $data);
    }

    public function export(Request $request, Response $response): Response
    {
        $data = $this->report($request, $response, $err);
        if ($data === null) {
            return $err;
        }
        $data['entity'] = $this->statements->entityHeader($this->currentSupplierId($request));
        $out = $this->xlsx->dimensionCashFlow($data);
        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** @return array<string,mixed>|null */
    private function report(Request $request, Response $response, ?Response &$err): ?array
    {
        $err = null;
        $supplierId = $this->currentSupplierId($request);
        $q = $request->getQueryParams();
        $from = (string) ($q['from'] ?? '');
        $to = (string) ($q['to'] ?? '');
        if (!self::isReportDate($from) || !self::isReportDate($to) || $from > $to) {
            $err = Json::error($response, 'validation_failed', 'from a to (YYYY-MM-DD, from ≤ to) jsou povinné.', 422);
            return null;
        }
        // Peněžní tok po dimenzi je za jednu hodnotu; seznam ostatních sestav tu neplatí.
        $rawValue = $q['dimension_value_id'] ?? '';
        if (!is_string($rawValue) || ($rawValue !== '' && $rawValue !== '0' && preg_match('/^[1-9][0-9]{0,9}$/', $rawValue) !== 1)) {
            $err = Json::error($response, 'dimension_value_invalid', 'Peněžní tok po dimenzi se počítá za jednu hodnotu dimenze.', 422);
            return null;
        }
        $valueId = (int) $rawValue;
        $descendants = (string) ($q['dimension_descendants'] ?? '1') !== '0';
        $filters = [$supplierId => null];
        $hidden = 0;
        $dimension = null;
        try {
            if ($valueId > 0) {
                $dimension = $this->dimensionService->filter($supplierId, $valueId, $descendants);
                $type = $this->dimensions->findType($supplierId, $dimension->typeId) ?? [];
                [$supplierIds, $hidden] = $this->groupScopeSuppliers($request, $this->dimensions, $this->feed, $supplierId, $type);
                $filters = [];
                foreach ($supplierIds as $sid) {
                    // Filtr se staví pro každou firmu zvlášť: kódy středisek navázaných na
                    // hodnotu (textové středisko mezd) jsou firemní.
                    $filters[$sid] = $sid === $supplierId ? $dimension : $this->dimensionService->filter($sid, $valueId, $descendants);
                }
            }
        } catch (DimensionException $e) {
            $err = Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
            return null;
        }
        $data = $this->service->build($from, $to, $filters);
        $data['dimension'] = $dimension?->toArray();
        $data['hidden_companies'] = $hidden;
        return $data;
    }
}
