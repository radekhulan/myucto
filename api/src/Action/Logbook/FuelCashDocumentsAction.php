<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Logbook\CashFuelingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Tankování z pokladních dokladů (účtenky placené hotově):
 *   GET  /api/logbook/fuel-cash-documents              — doklady, které vypadají jako tankování
 *   POST /api/logbook/fuel-cash-documents/{id}/assign  — vytěžit + přiřadit autu
 *   POST /api/logbook/fuel-cash-documents/backfill     — zpětné vytěžení nezpracovaných
 */
final class FuelCashDocumentsAction
{
    public function __construct(
        private readonly CashFuelingService $service,
        private readonly CarRepository $cars,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if ($denied = $this->denyWithoutCash($request, $response)) return $denied;
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $filters = [];
        if (!empty($q['year'])) $filters['year'] = (int) $q['year'];
        if (!empty($q['only_unscanned'])) $filters['only_unscanned'] = true;
        return Json::ok($response, [
            'documents' => $this->service->candidates($supplierId, $filters),
            'cars'      => $this->cars->listForTenant($supplierId, false),
            'has_cars'  => $this->cars->countActive($supplierId) > 0,
        ]);
    }

    public function assign(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->denyWithoutCash($request, $response)) return $denied;
        $supplierId = SupplierGuard::currentId($request);
        $docId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $carId = isset($body['car_id']) && $body['car_id'] !== '' && $body['car_id'] !== null ? (int) $body['car_id'] : null;
        if ($carId !== null && $this->cars->find($carId, $supplierId) === null) {
            return Json::error($response, 'car_not_found', 'Auto neexistuje.', 404);
        }
        $result = $this->service->scan($supplierId, $docId, $carId, $this->userId($request));
        if (empty($result['ok'])) {
            return Json::error($response, 'scan_failed', (string) ($result['error'] ?? 'Vytěžení selhalo.'), 400);
        }
        $this->log($request, 'fuel_cash_document.assigned', $docId, ['car_id' => $carId, 'result' => $result]);
        return Json::ok($response, $result);
    }

    public function backfill(Request $request, Response $response): Response
    {
        if ($denied = $this->denyWithoutCash($request, $response)) return $denied;
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $limit = isset($body['limit']) ? max(1, min(100, (int) $body['limit'])) : 25;
        $report = $this->service->backfill($supplierId, $this->userId($request), $limit);
        $this->log($request, 'fuel_cash_documents.backfill', 0, $report);
        return Json::ok($response, $report);
    }

    /**
     * RoutePermissionMap pouští /api/logbook/* s právem knihy jízd. Tady se ale čtou
     * a vytěžují POKLADNÍ doklady (číslo, partner, popis, částka), takže je navíc
     * potřeba čtení pokladny — jinak by kniha jízd byla obchvat kolem práva `cash`.
     */
    private function denyWithoutCash(Request $request, Response $response): ?Response
    {
        if (RequestAuthorization::allows($request, 'cash', AccessLevel::READ)) {
            return null;
        }
        return Json::error($response, 'forbidden_permission',
            'Tankování z pokladních dokladů vyžaduje právo číst pokladnu.', 403);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0) ?: null;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'cash_document', $id ?: null, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
