<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Logbook\VehicleVatPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Automobily (číselník knihy jízd):
 *   GET    /api/logbook/cars                — list (?include_archived=1)
 *   GET    /api/logbook/cars/{id}           — detail
 *   POST   /api/logbook/cars                — create
 *   PUT    /api/logbook/cars/{id}           — update
 *   DELETE /api/logbook/cars/{id}           — hard pokud nepoužito, jinak archivace
 *
 * Řidič (`driver_employee_id`) a režim užívání / odpočtu DPH (`usage_mode`, `vat_deduction_mode`,
 * `vat_deduction_percent`) se mění jen tehdy, když je klient pošle — starší klient API,
 * který je nezná, nastavení vozidla nevynuluje.
 *
 * RBAC řeší PermissionMiddleware (readonly GET, accountant+ CRUD).
 */
final class CarsAction
{
    private const USAGE_KEYS = ['usage_mode', 'vat_deduction_mode', 'vat_deduction_percent'];

    public function __construct(
        private readonly CarRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly TenantReferenceGuard $tenantRefs,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $includeArchived = !empty($request->getQueryParams()['include_archived']);
        return Json::ok($response, $this->repo->listForTenant($supplierId, $includeArchived));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $car = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($car === null) return Json::error($response, 'not_found', 'Auto nenalezeno.', 404);
        return Json::ok($response, $car);
    }

    public function create(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        try {
            $usage = $this->usagePatch($body, null);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        if ($bad = $this->tenantRefs->violations($supplierId, $body, ['driver_employee_id'])) {
            return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($bad), 400);
        }

        try {
            $id = $this->repo->create($supplierId, $body, $this->userId($request));
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return Json::error($response, 'duplicate', 'Auto s touto SPZ už existuje.', 409);
            }
            return Json::error($response, 'create_failed', $e->getMessage(), 500);
        }
        $this->repo->updateUsage($id, $supplierId, $usage);
        $this->log($request, 'car.created', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        $current = $this->repo->find($id, $supplierId);
        if ($current === null) {
            return Json::error($response, 'not_found', 'Auto nenalezeno.', 404);
        }
        try {
            $usage = $this->usagePatch($body, $current);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        if ($bad = $this->tenantRefs->violations($supplierId, $body, ['driver_employee_id'])) {
            return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($bad), 400);
        }
        try {
            $this->repo->update($id, $supplierId, $body);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return Json::error($response, 'duplicate', 'Auto s touto SPZ už existuje.', 409);
            }
            return Json::error($response, 'update_failed', $e->getMessage(), 500);
        }
        $this->repo->updateUsage($id, $supplierId, $usage);
        $this->log($request, 'car.updated', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Auto nenalezeno.', 404);
        }
        $result = $this->repo->delete($id, $supplierId);
        if (!empty($result['blocked'])) {
            return Json::error($response, 'car_in_use',
                'Auto nelze smazat — má navázané jízdy nebo tankování (' . (int) ($result['usage_count'] ?? 0) . '). Při úpravě ho můžete archivovat.', 409);
        }
        $this->log($request, 'car.deleted', $id, $result);
        return Json::ok($response, $result);
    }

    private function validate(array $body): ?string
    {
        $reg = trim((string) ($body['registration'] ?? ''));
        if ($reg === '') return 'SPZ (registrační značka) je povinná.';
        if (mb_strlen($reg) > 20) return 'SPZ je příliš dlouhá (max 20 znaků).';
        $fuel = $body['fuel_type'] ?? null;
        if ($fuel !== null && $fuel !== '' && !in_array($fuel, ['diesel','petrol','lpg','cng','electric','hybrid','other'], true)) {
            return 'Neplatný typ paliva.';
        }
        return null;
    }

    /**
     * Změny řidiče a režimu užívání z těla requestu — jen poslané klíče, sloučené s dosavadním
     * nastavením a ověřené {@see VehicleVatPolicy::normalize()}.
     *
     * @param array<string,mixed>|null $current uložené vozidlo (null = nové)
     * @return array<string,mixed>
     */
    private function usagePatch(array $body, ?array $current): array
    {
        $patch = [];
        if (array_key_exists('driver_employee_id', $body)) {
            $driver = $body['driver_employee_id'];
            $patch['driver_employee_id'] = $driver === null || $driver === '' ? null : (int) $driver;
        }
        $sent = array_intersect_key($body, array_flip(self::USAGE_KEYS));
        if ($sent === []) {
            return $patch;
        }
        $usage = array_key_exists('usage_mode', $sent) ? (string) $sent['usage_mode'] : ($current['usage_mode'] ?? 'business');
        // Změní-li se jen režim užívání, odpočet se odvodí z něj (výchozí pro režim).
        $deduction = array_key_exists('vat_deduction_mode', $sent)
            ? (string) $sent['vat_deduction_mode']
            : (array_key_exists('usage_mode', $sent) ? null : ($current['vat_deduction_mode'] ?? null));
        $percent = array_key_exists('vat_deduction_percent', $sent)
            ? $sent['vat_deduction_percent']
            : ($current['vat_deduction_percent'] ?? null);

        return $patch + VehicleVatPolicy::normalize($usage, $deduction, $percent);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'car', $id, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
