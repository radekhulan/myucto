<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Service\Bank\GpcExporter;
use MyInvoice\Service\Bank\StatementBalanceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class GpcExportAction
{
    public function __construct(private readonly StatementBalanceService $balances, private readonly GpcExporter $exporter) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($supplierId < 1 || $id < 1) return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        try {
            $snapshot = $this->balances->snapshot($supplierId, $id);
            $content = $this->exporter->export($snapshot);
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage() === 'statement_not_found') return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
            return Json::error($response, 'gpc_export_unavailable', 'GPC nelze bezpečně vytvořit. Zkontrolujte zůstatky, účet, měnu a formát údajů pohybů.', 422);
        }
        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/plain; charset=windows-1250')
            ->withHeader('Content-Disposition', 'attachment; filename="myucto-' . $id . '.gpc"')
            ->withHeader('Cache-Control', 'no-store')->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
