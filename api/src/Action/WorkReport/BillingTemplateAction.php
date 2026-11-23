<?php

declare(strict_types=1);

namespace MyInvoice\Action\WorkReport;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\WorkReportBillingTemplateRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BillingTemplateAction
{
    public function __construct(private readonly WorkReportBillingTemplateRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = isset($args['id']) ? (int) $args['id'] : null;
        if ($id !== null && $this->repo->find($id, $supplierId) === null) return Json::error($response, 'not_found', 'Cenové ujednání nenalezeno.', 404);
        if (in_array($request->getMethod(), ['GET', 'HEAD'], true)) return Json::ok($response, $this->repo->list($supplierId));
        if ($request->getMethod() === 'DELETE') {
            $this->repo->delete($supplierId, $id);
            return Json::ok($response, null);
        }
        try {
            $id = $this->repo->save($supplierId, $id, (array) ($request->getParsedBody() ?? []));
            return Json::ok($response, $this->repo->find($id, $supplierId));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }
}
