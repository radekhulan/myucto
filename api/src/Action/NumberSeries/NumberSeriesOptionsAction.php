<?php

declare(strict_types=1);

namespace MyInvoice\Action\NumberSeries;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\NumberSeries\NumberSeriesException;
use MyInvoice\Service\NumberSeries\NumberSeriesKinds;
use MyInvoice\Service\NumberSeries\NumberSeriesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výběr řady v editoru dokladu a výchozí řada uživatele. Routy leží pod modulem dokladu,
 * takže je hlídá právo modulu (číst řady smí ten, kdo smí doklad založit):
 *
 *   GET /api/invoices/number-series?type=invoice|proforma|credit_note
 *   GET /api/purchase-invoices/number-series
 *   GET /api/accounting/cash-documents/number-series?doc_type=in|out&register_id=
 *   GET /api/accounting/internal-documents/number-series
 *   PUT …/number-series/default  { number_series_id: 5|null, type?, doc_type?, register_id? }
 */
final class NumberSeriesOptionsAction
{
    public function __construct(private readonly NumberSeriesService $service) {}

    public function invoices(Request $request, Response $response): Response
    {
        return $this->options($request, $response, NumberSeriesKinds::issuedKind((string) ($request->getQueryParams()['type'] ?? 'invoice')));
    }

    public function purchase(Request $request, Response $response): Response
    {
        return $this->options($request, $response, 'purchase');
    }

    public function cash(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        return $this->options($request, $response, NumberSeriesKinds::cashKind((string) ($q['doc_type'] ?? 'in')), (int) ($q['register_id'] ?? 0));
    }

    public function internal(Request $request, Response $response): Response
    {
        return $this->options($request, $response, 'manual');
    }

    public function setInvoicesDefault(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->setDefault($request, $response, NumberSeriesKinds::issuedKind((string) ($body['type'] ?? 'invoice')), 0);
    }

    public function setPurchaseDefault(Request $request, Response $response): Response
    {
        return $this->setDefault($request, $response, 'purchase', 0);
    }

    public function setCashDefault(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->setDefault($request, $response, NumberSeriesKinds::cashKind((string) ($body['doc_type'] ?? 'in')), (int) ($body['register_id'] ?? 0));
    }

    public function setInternalDefault(Request $request, Response $response): Response
    {
        return $this->setDefault($request, $response, 'manual', 0);
    }

    private function options(Request $request, Response $response, string $kind, int $registerId = 0): Response
    {
        try {
            return Json::ok($response, $this->service->options(SupplierGuard::currentId($request), $kind, $registerId, self::userId($request)));
        } catch (NumberSeriesException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    private function setDefault(Request $request, Response $response, string $kind, int $registerId): Response
    {
        $userId = self::userId($request);
        if ($userId === null) {
            return Json::error($response, 'forbidden', 'Výchozí řadu si nastavuje přihlášený uživatel.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $seriesId = isset($body['number_series_id']) && (int) $body['number_series_id'] > 0 ? (int) $body['number_series_id'] : null;
        try {
            $supplierId = SupplierGuard::currentId($request);
            $this->service->setUserDefault($supplierId, $userId, $kind, $registerId, $seriesId);
            return Json::ok($response, $this->service->options($supplierId, $kind, $registerId, $userId));
        } catch (NumberSeriesException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    private static function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['id']) && (int) $user['id'] > 0 ? (int) $user['id'] : null;
    }
}
