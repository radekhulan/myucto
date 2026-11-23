<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocumentException;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocumentMarker;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Doklad převzatý z počátečních stavů ({@see OpeningBalanceDocumentMarker}).
 *
 *   GET    /api/invoices/{id}/opening-balance           stav vazby na otevírací zápis
 *   PUT    /api/invoices/{id}/opening-balance           označí doklad jako převzatý
 *   DELETE /api/invoices/{id}/opening-balance           zruší označení
 *   (totéž pro /api/purchase-invoices/{id}/opening-balance)
 *
 * RBAC: mapa hlídá `accounting.journal.post` (zápis), akce navíc zápis agendy dokladu
 * a kanál ({@see RequestAuthorization::canMarkOpeningBalanceDocument()}).
 */
final class OpeningBalanceDocumentAction
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchases,
        private readonly OpeningBalanceDocumentMarker $marker,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function invoiceState(Request $request, Response $response, array $args): Response
    {
        return $this->state($request, $response, 'invoice', (int) ($args['id'] ?? 0));
    }

    public function markInvoice(Request $request, Response $response, array $args): Response
    {
        return $this->change($request, $response, 'invoice', (int) ($args['id'] ?? 0), true);
    }

    public function unmarkInvoice(Request $request, Response $response, array $args): Response
    {
        return $this->change($request, $response, 'invoice', (int) ($args['id'] ?? 0), false);
    }

    public function purchaseState(Request $request, Response $response, array $args): Response
    {
        return $this->state($request, $response, 'purchase_invoice', (int) ($args['id'] ?? 0));
    }

    public function markPurchase(Request $request, Response $response, array $args): Response
    {
        return $this->change($request, $response, 'purchase_invoice', (int) ($args['id'] ?? 0), true);
    }

    public function unmarkPurchase(Request $request, Response $response, array $args): Response
    {
        return $this->change($request, $response, 'purchase_invoice', (int) ($args['id'] ?? 0), false);
    }

    /** @param 'invoice'|'purchase_invoice' $docType */
    private function state(Request $request, Response $response, string $docType, int $id): Response
    {
        if (!$this->owns($request, $docType, $id)) {
            return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        }
        return Json::ok($response, ['opening_balance' => $this->marker->state(SupplierGuard::currentId($request), $docType, $id)]);
    }

    /** @param 'invoice'|'purchase_invoice' $docType */
    private function change(Request $request, Response $response, string $docType, int $id, bool $mark): Response
    {
        if (!RequestAuthorization::canMarkOpeningBalanceDocument($request, $docType)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
        }
        if (!$this->owns($request, $docType, $id)) {
            return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        }
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) && (int) $user['id'] > 0 ? (int) $user['id'] : null;
        $before = $this->marker->state($supplierId, $docType, $id);
        try {
            $result = $mark
                ? $this->marker->mark($supplierId, $docType, $id, $userId)
                : $this->marker->unmark($supplierId, $docType, $id);
        } catch (OpeningBalanceDocumentException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $changed = $result['changed'];
        unset($result['changed']);
        if ($changed) {
            $this->logger->log(
                $docType . ($mark ? '.opening_balance_marked' : '.opening_balance_unmarked'),
                $userId,
                $docType,
                $id,
                ['journal_entry_id' => $mark ? $result['entry_id'] : $before['entry_id']],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );
        }
        return Json::ok($response, ['opening_balance' => $result]);
    }

    /** @param 'invoice'|'purchase_invoice' $docType */
    private function owns(Request $request, string $docType, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        return $docType === 'invoice'
            ? SupplierGuard::owns($request, $this->invoices->find($id))
            : $this->purchases->find($id, SupplierGuard::currentId($request)) !== null;
    }
}
