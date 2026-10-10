<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\TripRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Support\Pagination;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Jízdy (kniha jízd):
 *   GET    /api/logbook/trips         — list (?car_id=&category_id=&year=&month=&date_from=&date_to=&q=&page=&per_page=)
 *   GET    /api/logbook/trips/{id}
 *   POST   /api/logbook/trips
 *   PUT    /api/logbook/trips/{id}
 *   DELETE /api/logbook/trips/{id}
 *   POST   /api/logbook/trips/bulk-delete
 *   POST   /api/logbook/trips/recalculate-odometer
 */
final class TripsAction
{
    private const UPDATABLE = ['car_id', 'trip_date', 'time_start', 'time_end', 'odometer_start', 'odometer_end',
        'distance_km', 'category_id', 'purpose', 'origin', 'destination', 'note'];

    public function __construct(
        private readonly TripRepository $repo,
        private readonly CarRepository $cars,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly TenantReferenceGuard $tenantRefs,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $filters = array_intersect_key($q, array_flip(['car_id', 'category_id', 'year', 'month', 'date_from', 'date_to', 'q']));
        $p = Pagination::fromQuery($q, 50);
        [$rows, $total] = $this->repo->listPaged($supplierId, $filters, $p['per_page'], $p['offset']);
        $carId = !empty($filters['car_id']) ? (int) $filters['car_id'] : null;
        $years = $this->repo->distinctYears($supplierId, $carId);
        return Json::ok($response, array_merge(
            Pagination::envelope($rows, $total, $p['page'], $p['per_page']),
            ['years' => $years]
        ));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $trip = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($trip === null) return Json::error($response, 'not_found', 'Jízda nenalezena.', 404);
        return Json::ok($response, $trip);
    }

    /** Našeptávač účelů cest — distinct dříve zadané účely. */
    public function purposes(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, $this->repo->distinctPurposes($supplierId));
    }

    /** Našeptávač míst (odkud / kam) — distinct dříve zadaná místa. */
    public function places(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, $this->repo->distinctPlaces($supplierId));
    }

    public function create(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->prepare($supplierId, $body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        if ($ref = $this->tenantRefError($supplierId, $body)) {
            return Json::error($response, 'invalid_reference', $ref, 400);
        }

        $id = $this->repo->create($supplierId, $body, $this->userId($request));
        $this->log($request, 'trip.created', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $current = $this->repo->find($id, $supplierId);
        if ($current === null) {
            return Json::error($response, 'not_found', 'Jízda nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        // Vynechaný klíč = ponechat uloženou hodnotu; poslaný null / "" = vymazat.
        $merged = $body + array_intersect_key($current, array_flip(self::UPDATABLE));
        // distance_km uložené jako rozdíl tachometru se přepočte, jen když klient mění stav
        // a km nepošle a výsledný pár stavů je úplný; jinak zůstává uložená hodnota.
        if (!array_key_exists('distance_km', $body)
            && (array_key_exists('odometer_start', $body) || array_key_exists('odometer_end', $body))) {
            $oldStart = $this->intOrNull($current['odometer_start'] ?? null);
            $oldEnd   = $this->intOrNull($current['odometer_end'] ?? null);
            $newStart = $this->intOrNull($merged['odometer_start'] ?? null);
            $newEnd   = $this->intOrNull($merged['odometer_end'] ?? null);
            $wasDerived = $oldStart !== null && $oldEnd !== null
                && abs((float) ($current['distance_km'] ?? 0) - ($oldEnd - $oldStart)) < 0.005;
            if ($wasDerived && $newStart !== null && $newEnd !== null) {
                unset($merged['distance_km']);
            }
        }
        $err = $this->prepare($supplierId, $merged);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        if ($ref = $this->tenantRefError($supplierId, $body)) {
            return Json::error($response, 'invalid_reference', $ref, 400);
        }

        $this->repo->update($id, $supplierId, $merged);
        $this->log($request, 'trip.updated', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Jízda nenalezena.', 404);
        }
        $this->repo->delete($id, $supplierId);
        $this->log($request, 'trip.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * Hromadné smazání: {ids: [...]} nebo {all_matching: true, filters: {...}} = vše,
     * co odpovídá filtru seznamu (auto / rok / měsíc …), i přes více stránek.
     */
    public function bulkDelete(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        if (!empty($body['all_matching'])) {
            $filters = array_intersect_key((array) ($body['filters'] ?? []),
                array_flip(['car_id', 'category_id', 'year', 'month', 'date_from', 'date_to', 'q']));
            $deleted = $this->repo->deleteMatching($supplierId, $filters);
            $this->log($request, 'trip.bulk_deleted', 0, ['filters' => $filters, 'deleted' => $deleted]);
            return Json::ok($response, ['deleted' => $deleted]);
        }
        $ids = array_values(array_filter(array_map('intval', is_array($body['ids'] ?? null) ? $body['ids'] : [])));
        if ($ids === []) {
            return Json::error($response, 'no_ids', 'Nebyly vybrány žádné jízdy.', 400);
        }
        $deleted = $this->repo->deleteMany($supplierId, $ids);
        $this->log($request, 'trip.bulk_deleted', 0, ['ids' => $ids, 'deleted' => $deleted]);
        return Json::ok($response, ['deleted' => $deleted]);
    }

    /**
     * Přepočet stavu tachometru: {after_trip_id} = jízdy po opravené jízdě,
     * {car_id} = všechny jízdy auta od první.
     */
    public function recalculateOdometer(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $afterTripId = (int) ($body['after_trip_id'] ?? 0) ?: null;
        if ($afterTripId !== null) {
            $trip = $this->repo->find($afterTripId, $supplierId);
            if ($trip === null) return Json::error($response, 'not_found', 'Jízda nenalezena.', 404);
            $carId = $trip['car_id'];
        } else {
            $carId = (int) ($body['car_id'] ?? 0);
            if ($carId <= 0 || $this->cars->find($carId, $supplierId) === null) {
                return Json::error($response, 'validation_failed', 'Vyberte auto.', 400);
            }
        }
        $result = $this->repo->recalculateOdometer($supplierId, $carId, $afterTripId);
        if ($result === null) {
            return Json::error($response, 'no_odometer', 'Chybí výchozí stav tachometru. Vyplňte ho u první jízdy nebo u auta.', 400);
        }
        $this->log($request, 'trip.odometer_recalculated', $afterTripId ?? 0, ['car_id' => $carId] + $result);
        return Json::ok($response, $result);
    }

    /** Validace + dopočet distance_km (mění $body in-place). */
    private function prepare(int $supplierId, array &$body): ?string
    {
        $carId = (int) ($body['car_id'] ?? 0);
        if ($carId <= 0) return 'Auto je povinné.';
        if ($this->cars->find($carId, $supplierId) === null) return 'Auto neexistuje.';

        $date = trim((string) ($body['trip_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 'Neplatné datum (formát YYYY-MM-DD).';

        $odoStart = $this->intOrNull($body['odometer_start'] ?? null);
        $odoEnd   = $this->intOrNull($body['odometer_end'] ?? null);
        $distance = isset($body['distance_km']) && $body['distance_km'] !== '' ? (float) $body['distance_km'] : null;
        if ($distance === null || $distance <= 0) {
            if ($odoStart !== null && $odoEnd !== null && $odoEnd >= $odoStart) {
                $distance = (float) ($odoEnd - $odoStart);
            } else {
                return 'Vyplň ujeté km nebo platný stav tachometru (od ≤ do).';
            }
        }
        if ($odoStart !== null && $odoEnd !== null && $odoEnd < $odoStart) {
            return 'Konečný stav tachometru nesmí být menší než počáteční.';
        }
        $body['distance_km'] = $distance;
        return null;
    }

    /**
     * BOLA guard (security report 2026-08, R2 #7 / sweep F3) — car_id se v prepare()
     * kontroluje odjakživa, category_id se zapisovalo nevázané a TripRepository::find()
     * ho čte zpět nescoped joinem na číselník kategorií (unikal label i příznak
     * is_private cizí firmy).
     */
    private function tenantRefError(int $supplierId, array $body): ?string
    {
        $badRefs = $this->tenantRefs->violations($supplierId, $body, ['category_id']);

        return $badRefs !== [] ? TenantReferenceGuard::message($badRefs) : null;
    }

    private function intOrNull(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0) ?: null;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'trip', $id, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
