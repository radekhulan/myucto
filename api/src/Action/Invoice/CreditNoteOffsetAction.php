<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\CreditNoteOffsetService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Zápočet dobropisu proti opravované faktuře (issue #140):
 *
 *   GET    /api/invoices/{id}/credit-note-offset            — zápočty dokladu + zda jde započíst
 *   POST   /api/invoices/{id}/credit-note-offset            — započíst dobropis {id}
 *   DELETE /api/invoices/{id}/credit-note-offset            — zrušit zápočty dokladu
 *   (totéž pod /api/purchase-invoices/{id}/credit-note-offset)
 *
 * Při vystavení/přijetí dobropisu se zápočet dělá sám; endpoint slouží dobropisům z doby
 * před touto funkcí a vědomému zrušení (odběratel přesto zaplatil celou fakturu).
 */
final class CreditNoteOffsetAction
{
    public function __construct(
        private readonly CreditNoteOffsetService $offsets,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function getInvoice(Request $request, Response $response, array $args): Response
    {
        return $this->get($request, $response, 'invoice', (int) ($args['id'] ?? 0));
    }

    public function applyInvoice(Request $request, Response $response, array $args): Response
    {
        return $this->apply($request, $response, 'invoice', (int) ($args['id'] ?? 0));
    }

    public function revertInvoice(Request $request, Response $response, array $args): Response
    {
        return $this->revert($request, $response, 'invoice', (int) ($args['id'] ?? 0));
    }

    public function getPurchase(Request $request, Response $response, array $args): Response
    {
        return $this->get($request, $response, 'purchase_invoice', (int) ($args['id'] ?? 0));
    }

    public function applyPurchase(Request $request, Response $response, array $args): Response
    {
        return $this->apply($request, $response, 'purchase_invoice', (int) ($args['id'] ?? 0));
    }

    public function revertPurchase(Request $request, Response $response, array $args): Response
    {
        return $this->revert($request, $response, 'purchase_invoice', (int) ($args['id'] ?? 0));
    }

    private function get(Request $request, Response $response, string $docType, int $id): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0 || $id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }
        return Json::ok($response, $this->offsets->forDocument($supplierId, $docType, $id));
    }

    private function apply(Request $request, Response $response, string $docType, int $id): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0 || $id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }
        $userId = $this->userId($request);
        $result = $docType === 'invoice'
            ? $this->offsets->applyForInvoice($supplierId, $id, $userId)
            : $this->offsets->applyForPurchase($supplierId, $id, $userId);
        if ($result['offset_id'] === null) {
            return Json::error(
                $response,
                'credit_note_offset_' . $result['reason'],
                'Dobropis nelze započíst s fakturou.',
                409,
            );
        }
        $this->log($request, $docType, $id, 'credit_note_offset.applied', ['offset_id' => $result['offset_id']]);
        return Json::ok($response, $this->offsets->forDocument($supplierId, $docType, $id));
    }

    private function revert(Request $request, Response $response, string $docType, int $id): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0 || $id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }
        $count = $this->offsets->revertForDocument($supplierId, $docType, $id);
        if ($count === 0) {
            return Json::error($response, 'credit_note_offset_not_found', 'Doklad nemá zápočet dobropisu.', 404);
        }
        $this->log($request, $docType, $id, 'credit_note_offset.reverted', ['count' => $count]);
        return Json::ok($response, $this->offsets->forDocument($supplierId, $docType, $id));
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['id']) ? (int) $user['id'] : null;
    }

    /** @param array<string,mixed> $meta */
    private function log(Request $request, string $docType, int $id, string $event, array $meta): void
    {
        $this->logger->log(
            $event,
            $this->userId($request),
            $docType,
            $id,
            $meta,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );
    }
}
