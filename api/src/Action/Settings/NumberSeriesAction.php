<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\NumberSeries\NumberSeriesException;
use MyInvoice\Service\NumberSeries\NumberSeriesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Číselník řad firmy (Nastavení → Číselné řady).
 *
 *   GET    /api/settings/number-series                 — všechny řady (převedené i nové)
 *   GET    /api/settings/number-series/completeness    — úplnost řad za rok (?year=)
 *   POST   /api/settings/number-series                 — nová řada
 *   PUT    /api/settings/number-series/{id}            — název, tvar, příští číslo, ukončení
 *   POST   /api/settings/number-series/{id}/default    — výchozí řada druhu (a pokladny)
 *   DELETE /api/settings/number-series/{id}            — smazání nové řady bez dokladů
 *
 * Čtení: `settings.company`, zápis: `settings.company.write` (mapa rout + kontrola tady).
 */
final class NumberSeriesAction
{
    public function __construct(private readonly NumberSeriesService $service) {}

    public function list(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->service->list(SupplierGuard::currentId($request), self::userId($request)));
    }

    public function completeness(Request $request, Response $response): Response
    {
        $year = (int) ($request->getQueryParams()['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2200) {
            return Json::error($response, 'validation_failed', 'Neplatný rok.', 422);
        }
        return Json::ok($response, $this->service->completeness(SupplierGuard::currentId($request), $year));
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->write($request, $response, fn (int $supplierId, array $body): array
            => $this->service->create($supplierId, $body, self::userId($request)), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, fn (int $supplierId, array $body): array
            => $this->service->update($supplierId, (int) $args['id'], $body, self::userId($request)));
    }

    public function setDefault(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, fn (int $supplierId): array
            => $this->service->setDefault($supplierId, (int) $args['id'], self::userId($request)));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $supplierId) use ($request, $args): array {
            $this->service->delete($supplierId, (int) $args['id'], self::userId($request));
            return ['deleted' => true];
        });
    }

    /** @param callable(int, array<string,mixed>): array<string,mixed> $fn */
    private function write(Request $request, Response $response, callable $fn, int $status = 200): Response
    {
        if (!RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Číselné řady může měnit jen správce nastavení firmy.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Není zvolen dodavatel.', 400);
        }
        try {
            return Json::ok($response, $fn($supplierId, (array) ($request->getParsedBody() ?? [])), $status);
        } catch (NumberSeriesException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    private static function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['id']) ? (int) $user['id'] : null;
    }
}
