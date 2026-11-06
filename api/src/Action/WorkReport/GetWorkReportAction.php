<?php

declare(strict_types=1);

namespace MyInvoice\Action\WorkReport;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetWorkReportAction
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly WorkReportRepository $repo,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $invoiceId = (int) ($args['id'] ?? 0);
        if (!SupplierGuard::owns($request, $this->invoices->find($invoiceId))) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        $wr = $this->repo->findByInvoice($invoiceId, SupplierGuard::currentId($request));
        return Json::ok($response, $wr ?? null);
    }
}
