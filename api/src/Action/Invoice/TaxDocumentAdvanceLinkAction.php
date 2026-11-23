<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Invoice\TaxDocumentAdvanceLink;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TaxDocumentAdvanceLinkAction
{
    public function __construct(
        private readonly TaxDocumentAdvanceLink $links,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function candidates(Request $request, Response $response, array $args): Response
    {
        try {
            $candidates = $this->links->candidates(SupplierGuard::currentId($request), (int) $args['id']);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        }
        return Json::ok($response, ['candidates' => $candidates]);
    }

    public function link(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $value = $body['advance_id'] ?? null;
        $advanceId = is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($advanceId === false) {
            return Json::error($response, 'invalid_advance', 'Vyberte zálohovou fakturu.', 400);
        }
        return $this->change($request, $response, (int) $args['id'], $advanceId);
    }

    public function unlink(Request $request, Response $response, array $args): Response
    {
        return $this->change($request, $response, (int) $args['id'], null);
    }

    private function change(Request $request, Response $response, int $id, ?int $advanceId): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $agent = $request->getHeaderLine('User-Agent');
        try {
            $result = $advanceId === null
                ? $this->links->unlink($supplierId, $id, $userId, $ip, $agent)
                : $this->links->link($supplierId, $id, $advanceId, $userId, $ip, $agent);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'tax_document_advance_conflict', $e->getMessage(), 409);
        }
        return Json::ok($response, $result);
    }
}
