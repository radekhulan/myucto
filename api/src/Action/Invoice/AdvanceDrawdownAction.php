<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\AdvancePartialDrawdown;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Čerpání záloh konceptem faktury (víc záloh na jedné faktuře i postupné čerpání jedné zálohy
 * víc fakturami), viz {@see AdvancePartialDrawdown}.
 *
 *   GET    /api/invoices/{id}/advance-drawdown  zálohy odběratele s nevyčerpanou částkou a čerpáním konceptu
 *   POST   /api/invoices/{id}/advance-drawdown  body: {proforma_id, amount | percent, basis?}: nastaví čerpání
 *          zálohy (částka nebo procento plnění faktury, basis gross = s DPH, net = bez DPH)
 *   DELETE /api/invoices/{id}/advance-drawdown  odebere čerpání zálohy `?proforma_id=`, bez něj všech záloh
 */
final class AdvanceDrawdownAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly AdvancePartialDrawdown $drawdown,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
    ) {}

    public function candidates(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!SupplierGuard::owns($request, $this->repo->find($id))) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        try {
            return Json::ok($response, $this->drawdown->candidates(SupplierGuard::currentId($request), $id));
        } catch (\DomainException $e) {
            return Json::error($response, 'drawdown_failed', $e->getMessage(), 409);
        }
    }

    public function apply(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($invoice), 'invoice', $id)) {
            return $deny;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $proformaId = (int) ($body['proforma_id'] ?? 0);
        $amount = is_numeric($body['amount'] ?? null) ? (float) $body['amount'] : null;
        $percent = is_numeric($body['percent'] ?? null) ? (float) $body['percent'] : null;
        $basis = (string) ($body['basis'] ?? AdvancePartialDrawdown::BASIS_GROSS);
        if ($proformaId <= 0 || ($amount === null) === ($percent === null) || ($amount ?? $percent) <= 0.0) {
            return Json::error($response, 'invalid_input', 'Zadejte zálohu (proforma_id) a kladnou čerpanou částku (amount), nebo procento plnění faktury (percent).', 400);
        }

        try {
            $supplierId = SupplierGuard::currentId($request);
            $result = $percent !== null
                ? $this->drawdown->applyPercent($supplierId, $id, $proformaId, $percent, $basis)
                : $this->drawdown->apply($supplierId, $id, $proformaId, (float) $amount, $basis);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'invalid_input', $e->getMessage(), 400);
        } catch (\DomainException $e) {
            return Json::error($response, 'drawdown_failed', $e->getMessage(), 409);
        } catch (\RuntimeException $e) {
            // Kolize s čerpáním v saldokontu (AdvanceDeductionRepository::assertLinkAllowed);
            // chyba databáze zůstává chybou serveru.
            if ($e instanceof \PDOException) {
                throw $e;
            }
            return Json::error($response, 'drawdown_failed', $e->getMessage(), 409);
        }

        $this->log($request, 'invoice.advance_drawn', $id, $result);
        return Json::ok($response, ['drawdown' => $result, 'invoice' => $this->repo->find($id)]);
    }

    public function remove(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($invoice), 'invoice', $id)) {
            return $deny;
        }
        $query = $request->getQueryParams();
        $proformaId = isset($query['proforma_id']) && is_numeric($query['proforma_id']) ? (int) $query['proforma_id'] : null;
        if ($proformaId !== null && $proformaId <= 0) {
            return Json::error($response, 'invalid_input', 'Neplatná záloha (proforma_id).', 400);
        }
        try {
            $removed = $this->drawdown->remove(SupplierGuard::currentId($request), $id, $proformaId);
        } catch (\DomainException $e) {
            return Json::error($response, 'drawdown_failed', $e->getMessage(), 409);
        }

        $this->log($request, 'invoice.advance_drawdown_removed', $id, [
            'proforma_id'  => $proformaId ?? ($removed[0] ?? null),
            'proforma_ids' => $removed,
        ]);
        return Json::ok($response, $this->repo->find($id));
    }

    /** @param array<string,mixed> $context */
    private function log(Request $request, string $action, int $id, array $context): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $user['id'] ?? null, 'invoice', $id, $context, $ip, $request->getHeaderLine('User-Agent'));
    }
}
