<?php

declare(strict_types=1);

namespace MyInvoice\Action\TaxEvidence;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\TaxEvidence\TaxEstimateService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Odhad daně z příjmů a pojistného OSVČ během roku v daňové evidenci. READ-ONLY.
 *
 *   GET /api/tax-evidence/tax-estimate?year=YYYY
 */
final class TaxEstimateAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly TaxEstimateService $estimates,
        private readonly Connection $db,
        private readonly LoggerInterface $log,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $year = (int) ($request->getQueryParams()['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            return Json::error($response, 'validation_failed', 'Neplatný rok.', 422);
        }
        if (!$this->requireTaxEvidenceForYear($this->db, $supplierId, $year, $response, $err)) return $err;

        try {
            $data = $this->estimates->estimate($supplierId, $year);
        } catch (\Throwable $e) {
            $this->log->error('Odhad daně a pojistného daňové evidence se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Odhad se nepodařilo sestavit.', 500);
        }

        return Json::ok($response, $data);
    }
}
