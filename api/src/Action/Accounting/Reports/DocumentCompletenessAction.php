<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\DocumentCompletenessService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Featura E (REAL_data_followup_UX.md) — kontrola úplnosti dokladů proti bance, v podvojném
 * účetnictví i v daňové evidenci. Čistě čtecí endpoint (žádná mutace):
 *   GET /api/accounting/reports/document-completeness?days=30&direction=all
 */
final class DocumentCompletenessAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly DocumentCompletenessService $service,
        private readonly Connection $db,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        // § 7b odst. 1 ZDP: i daňová evidence musí příjmy a výdaje doložit; služba v ní
        // nahradí deník a saldokonto knihou pohledávek a závazků.
        if (!$this->requireAccountingMode($this->db, $supplierId, $response, $err)) return $err;

        $q = $request->getQueryParams();
        $days = (int) ($q['days'] ?? 30);
        if ($days < 0 || $days > 3650) {
            return Json::error($response, 'validation_failed', 'days musí být 0–3650.', 422);
        }
        $direction = strtolower(trim((string) ($q['direction'] ?? 'all')));
        if (!in_array($direction, ['outgoing', 'incoming', 'all'], true)) {
            return Json::error($response, 'validation_failed', "direction musí být 'outgoing', 'incoming' nebo 'all'.", 422);
        }

        return Json::ok($response, $this->service->build($supplierId, $days, $direction));
    }
}
