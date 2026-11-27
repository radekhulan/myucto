<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\IsoDate;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\YearEndTaxEstimateService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Odhad do konce roku k výsledovce po účtech (interní, načítá se zvlášť, protože
 * přepočítává náhled DPPO).
 *
 *   GET /api/accounting/reports/statement-accounts/tax-estimate?period_id=&as_of=
 *
 * `as_of` = den výkazu tabulky výsledovky (stejný význam jako u statement-accounts);
 * s ním odpověď nese můstek od VH k tomu dni k zaúčtovanému VH celého období.
 */
final class YearEndTaxEstimateAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly YearEndTaxEstimateService $estimates,
        private readonly Connection $db,
        private readonly LoggerInterface $log,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $periodId = (int) ($request->getQueryParams()['period_id'] ?? 0);
        if ($periodId <= 0) {
            return Json::error($response, 'validation_failed', 'period_id je povinný.', 422);
        }

        $asOf = trim((string) ($request->getQueryParams()['as_of'] ?? ''));
        if ($asOf !== '' && !IsoDate::isValid($asOf)) {
            return Json::error($response, 'validation_failed', 'as_of musí být datum (YYYY-MM-DD).', 422);
        }

        try {
            $data = $this->estimates->estimate($supplierId, $periodId, $asOf !== '' ? $asOf : null);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Odhad daně do konce roku se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Odhad se nepodařilo sestavit.', 500);
        }

        return Json::ok($response, $data);
    }
}
