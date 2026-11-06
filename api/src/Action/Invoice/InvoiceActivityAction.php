<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\DocumentActivityFeed;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/invoices/{id}/activity
 * Vrátí activity_log záznamy pro danou fakturu (auth, ne admin-only) bez IP
 * adres a e-mailů uživatelů, viz {@see DocumentActivityFeed}.
 */
final class InvoiceActivityAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly DocumentActivityFeed $feed,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!SupplierGuard::owns($request, $this->repo->find($id))) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        return Json::ok($response, $this->feed->forEntity('invoice', $id));
    }
}
