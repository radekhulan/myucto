<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoiceOtherItemLinkException;
use MyInvoice\Service\Invoice\InvoiceOtherItemLinkService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Vazba vydané faktury na ostatní pohledávku (migrace 2246).
 *
 *   GET    /api/invoices/{id}/other-item-candidates?q= — pohledávky k navázání
 *   POST   /api/invoices/{id}/other-item-link  body: {other_item_id}
 *   DELETE /api/invoices/{id}/other-item-link
 *
 * RBAC: výběr hlídá RoutePermissionMap na `other_items` READ. Navázání a odpojení
 * mění zaúčtování faktury, mapa je proto gatuje na `accounting.journal.post` a akce
 * navíc ověří zápis faktur a čtení ostatních položek.
 */
final class InvoiceOtherItemLinkAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoiceOtherItemLinkService $links,
        private readonly DocumentLockService $locks,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function candidates(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        $query = $request->getQueryParams();
        try {
            $items = $this->links->candidates(SupplierGuard::currentId($request), $id, (string) ($query['q'] ?? ''));
        } catch (InvoiceOtherItemLinkException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return Json::ok($response, ['items' => $items]);
    }

    public function link(Request $request, Response $response, array $args): Response
    {
        if ($err = $this->denied($request, $response)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $otherItemId = filter_var($body['other_item_id'] ?? null, FILTER_VALIDATE_INT);
        if ($otherItemId === false || $otherItemId <= 0) {
            return Json::error($response, 'validation_failed', 'Vyberte ostatní pohledávku.', 422);
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        try {
            $result = $this->links->link($supplierId, $id, $otherItemId, $userId);
        } catch (InvoiceOtherItemLinkException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\PDOException $e) {
            if (TransactionFailure::aborted($e)) throw $e;
            if ((string) $e->getCode() === '23000') {
                return Json::error($response, 'other_item_taken', 'Ostatní pohledávka je už navázaná na jinou fakturu.', 409);
            }
            throw $e;
        }

        $this->logger->log('invoice.other_item_linked', $userId, 'invoice', $id, [
            'other_item_id' => $otherItemId,
            'reversed_entries' => $result['reversed_entries'],
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);

        return $this->respondWithInvoice($response, $supplierId, $id);
    }

    public function unlink(Request $request, Response $response, array $args): Response
    {
        if ($err = $this->denied($request, $response)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        $supplierId = SupplierGuard::currentId($request);
        try {
            $previous = $this->links->unlink($supplierId, $id);
        } catch (InvoiceOtherItemLinkException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if ($previous !== null) {
            $this->logger->log('invoice.other_item_unlinked', $this->userId($request), 'invoice', $id, [
                'other_item_id' => $previous,
                'trigger' => 'manual',
            ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);
        }

        return $this->respondWithInvoice($response, $supplierId, $id);
    }

    /** Dokontrola konkrétních práv nad rámec mapy (zaúčtování). */
    private function denied(Request $request, Response $response): ?Response
    {
        if (RequestAuthorization::allows($request, 'accounting.journal.post', AccessLevel::WRITE)
            && RequestAuthorization::allows($request, 'invoices', AccessLevel::WRITE)
            && RequestAuthorization::allows($request, 'other_items', AccessLevel::READ)) {
            return null;
        }
        return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
    }

    private function respondWithInvoice(Response $response, int $supplierId, int $id): Response
    {
        $invoice = $this->repo->find($id);
        if ($invoice === null) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        $invoice['locked'] = $this->locks->forInvoice($invoice)->toArray();
        return Json::ok($response, $this->links->withLink($supplierId, $invoice));
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['id']) && (int) $user['id'] > 0 ? (int) $user['id'] : null;
    }
}
