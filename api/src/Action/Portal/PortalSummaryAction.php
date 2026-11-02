<?php

declare(strict_types=1);

namespace MyInvoice\Action\Portal;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Epic F6 — klientský portál: GET /api/portal/summary
 *
 * Agregovaný přehled hospodaření aktivní firmy (živě přes CrmAggregationService,
 * žádné nové tabulky/cache). Dostupné všem přihlášeným rolím — klient je primární
 * konzument, účetní má náhled; supplier scope řeší SupplierScopeMiddleware
 * (klient bez membershipu = fail-closed 403 v resolveru).
 *
 * ⚠️ BEZPEČNOSTNÍ INVARIANT (spec §5): response NESMÍ obsahovat žádná jména
 * klientů/odběratelů ani čísla dokladů — jen agregáty, počty a částky. Proto se
 * z CRM služby berou výhradně agregační metody (overview/monthlyHistory/aging/
 * forecast) a KPI řádky se mapují přes explicitní subset klíčů.
 */
final class PortalSummaryAction
{
    public function __construct(private readonly \MyInvoice\Service\Portal\PortalSummaryService $summary) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Žádná firma není dostupná.', 403);
        }
        $data = $this->summary->summary($supplierId);
        return $data === null
            ? Json::error($response, 'not_found', 'Firma nenalezena.', 404)
            : Json::ok($response, $data);
    }
}
