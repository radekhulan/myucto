<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\TripCategoryRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Kategorie cest (číselník knihy jízd):
 *   GET    /api/logbook/trip-categories
 *   POST   /api/logbook/trip-categories
 *   PUT    /api/logbook/trip-categories/{id}
 *   DELETE /api/logbook/trip-categories/{id}
 */
final class TripCategoriesAction
{
    private const UPDATABLE = ['code', 'label', 'is_private', 'is_default', 'display_order', 'is_archived'];

    public function __construct(
        private readonly TripCategoryRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $includeArchived = !empty($request->getQueryParams()['include_archived']);
        return Json::ok($response, $this->repo->listForTenant($supplierId, $includeArchived));
    }

    public function create(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        try {
            $id = $this->repo->create($supplierId, $body);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return Json::error($response, 'duplicate_code', 'Kód „' . (string) ($body['code'] ?? '') . '" už existuje.', 409);
            }
            return Json::internalError($response, 'create_failed', $e->getMessage(), 500);
        }
        $this->log($request, 'trip_category.created', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $current = $this->repo->find($id, $supplierId);
        if ($current === null) {
            return Json::error($response, 'not_found', 'Kategorie nenalezena.', 404);
        }
        // Vynechaný klíč = ponechat uloženou hodnotu; poslaný null / "" = vymazat.
        $merged = $body + array_intersect_key($current, array_flip(self::UPDATABLE));
        $err = $this->validate($merged);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        try {
            $this->repo->update($id, $supplierId, $merged);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return Json::error($response, 'duplicate_code', 'Kód „' . (string) ($body['code'] ?? '') . '" už existuje.', 409);
            }
            return Json::internalError($response, 'update_failed', $e->getMessage(), 500);
        }
        $this->log($request, 'trip_category.updated', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Kategorie nenalezena.', 404);
        }
        $result = $this->repo->delete($id, $supplierId);
        if (!empty($result['blocked'])) {
            return Json::error($response, 'category_in_use',
                'Kategorii nelze smazat — má navázané jízdy (' . (int) ($result['usage_count'] ?? 0) . '). Při úpravě ji můžete archivovat.', 409);
        }
        $this->log($request, 'trip_category.deleted', $id, $result);
        return Json::ok($response, $result);
    }

    private function validate(array $body): ?string
    {
        $code = trim((string) ($body['code'] ?? ''));
        $label = trim((string) ($body['label'] ?? ''));
        if ($code === '') return 'Kód je povinný.';
        if (!preg_match('/^[a-z0-9_-]{1,30}$/i', $code)) {
            return 'Kód: povolené znaky A-Z, a-z, 0-9, _, - (max 30).';
        }
        if ($label === '' || mb_strlen($label) > 100) return 'Název povinný, max 100 znaků.';
        return null;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($user['id'] ?? 0) ?: null, 'trip_category', $id, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
