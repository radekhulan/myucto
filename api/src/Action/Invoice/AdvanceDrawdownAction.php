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
 * Postupné čerpání zálohy konceptem faktury (smlouvy s milníky), viz {@see AdvancePartialDrawdown}.
 *
 *   GET    /api/invoices/{id}/advance-drawdown  zálohy odběratele s nevyčerpanou částkou
 *   POST   /api/invoices/{id}/advance-drawdown  body: {proforma_id, amount} — odečte část zálohy
 *   DELETE /api/invoices/{id}/advance-drawdown  odebere odpočet i vazbu na zálohu
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
        $amount = is_numeric($body['amount'] ?? null) ? (float) $body['amount'] : 0.0;
        if ($proformaId <= 0 || $amount <= 0.0) {
            return Json::error($response, 'invalid_input', 'Zadejte zálohu (proforma_id) a kladnou čerpanou částku (amount).', 400);
        }

        try {
            $result = $this->drawdown->apply(SupplierGuard::currentId($request), $id, $proformaId, $amount);
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
        try {
            $this->drawdown->remove(SupplierGuard::currentId($request), $id);
        } catch (\DomainException $e) {
            return Json::error($response, 'drawdown_failed', $e->getMessage(), 409);
        }

        $this->log($request, 'invoice.advance_drawdown_removed', $id, ['proforma_id' => $invoice['parent_invoice_id'] ?? null]);
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
