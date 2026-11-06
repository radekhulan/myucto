<?php

declare(strict_types=1);

namespace MyInvoice\Action\TaxEvidence;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\TaxEvidence\AnnualClosingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AnnualClosingAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly AnnualClosingService $service,
        private readonly Connection $db,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, $args, fn (int $sid, int $year) => $this->service->get($sid, $year));
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($request, $response, $args, fn (int $sid, int $year) => $this->service->save(
            $sid, $year, $body, (int) ($body['row_version'] ?? 0), $this->userId($request)
        ));
    }

    public function finalize(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($request, $response, $args, fn (int $sid, int $year) => $this->service->finalize(
            $sid, $year, (int) ($body['row_version'] ?? 0), $this->userId($request)
        ));
    }

    public function reopen(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($request, $response, $args, fn (int $sid, int $year) => $this->service->reopen(
            $sid, $year, (int) ($body['row_version'] ?? 0)
        ));
    }

    private function run(Request $request, Response $response, array $args, callable $callback): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $year = (int) ($args['year'] ?? 0);
        if (!$this->requireTaxEvidenceForYear($this->db, $supplierId, $year, $response, $error)) {
            return $error;
        }
        try {
            return Json::ok($response, $callback($supplierId, $year));
        } catch (\DomainException $e) {
            return Json::error($response, 'tax_evidence_closing_invalid', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'tax_evidence_closing_failed', $e->getMessage(), 500);
        }
    }
}
