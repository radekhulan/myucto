<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Action\Logbook\TripsAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\TripRepository;
use MyInvoice\Service\Logbook\TripImportService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Issue #143: stav tachometru s desetinnou čárkou se nesmí načíst jako desetkrát
 * větší číslo a jízdy jde smazat hromadně (vybrané i vše podle filtru).
 */
#[Group('integration')]
final class TripImportAndBulkDeleteTest extends TestCase
{
    use LogbookFixtures;

    protected function setUp(): void
    {
        $this->bootLogbook();
    }

    protected function tearDown(): void
    {
        $this->shutdownLogbook();
    }

    public function testDecimalOdometerInCzechAndEnglishFormat(): void
    {
        $this->car($this->supplierA, '9IM 0143', true);
        $csv = "datum;auto;km_zacatek;km_konec;ucel\n"
            . "01.03.2099;9IM 0143;128,5;135,2;Česká čárka\n"
            . "02.03.2099;9IM 0143;1 135,2;1 200,7;Mezera tisíců\n";
        $csvEn = "date,car,km start,km end,purpose\n"
            . "2099-03-03,9IM 0143,135.2,150.0,English dot\n"
            . "2099-03-04,9IM 0143,\"1,150.0\",\"1,160.5\",English thousands\n";

        $service = $this->container->get(TripImportService::class);
        $cz = $service->import($this->supplierA, $this->userId, $csv, 'jizdy.csv', true);
        $en = $service->import($this->supplierA, $this->userId, $csvEn, 'trips.csv', true);

        self::assertSame(0, $cz['failed'], json_encode($cz['rows'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $en['failed'], json_encode($en['rows'], JSON_UNESCAPED_UNICODE));
        self::assertSame([129, 135, 6.7], [$cz['rows'][0]['odometer_start'], $cz['rows'][0]['odometer_end'], $cz['rows'][0]['distance_km']]);
        self::assertSame([1135, 1201, 65.5], [$cz['rows'][1]['odometer_start'], $cz['rows'][1]['odometer_end'], $cz['rows'][1]['distance_km']]);
        self::assertSame([135, 150, 14.8], [$en['rows'][0]['odometer_start'], $en['rows'][0]['odometer_end'], $en['rows'][0]['distance_km']]);
        self::assertSame([1150, 1161, 10.5], [$en['rows'][1]['odometer_start'], $en['rows'][1]['odometer_end'], $en['rows'][1]['distance_km']]);
    }

    public function testBulkDeleteSelectedAndAllMatchingStaysInTenant(): void
    {
        $carA = $this->car($this->supplierA, '9BD 0143', true);
        $carB = $this->car($this->supplierB, '9BD 0144', true);
        $repo = $this->container->get(TripRepository::class);
        $trip = fn (int $supplier, int $car, string $date): int => $repo->create($supplier, [
            'car_id' => $car, 'trip_date' => $date, 'distance_km' => 10.0,
        ], null);
        $march1 = $trip($this->supplierA, $carA, '2099-03-01');
        $march2 = $trip($this->supplierA, $carA, '2099-03-02');
        $trip($this->supplierA, $carA, '2099-04-01');
        $trip($this->supplierA, $carA, '2099-04-02');
        $foreign = $trip($this->supplierB, $carB, '2099-04-03');

        $res = $this->call('bulkDelete', ['ids' => [$march1, $foreign]]);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(1, $this->json($res)['data']['deleted'] ?? $this->json($res)['deleted'] ?? null);
        self::assertNull($repo->find($march1, $this->supplierA));
        self::assertNotNull($repo->find($foreign, $this->supplierB));

        $res = $this->call('bulkDelete', ['all_matching' => true, 'filters' => ['year' => 2099, 'month' => 4]]);
        self::assertSame(200, $res->getStatusCode());
        [$left, $total] = $repo->listPaged($this->supplierA, ['year' => 2099], 50, 0);
        self::assertSame(1, $total);
        self::assertSame($march2, $left[0]['id']);
        self::assertNotNull($repo->find($foreign, $this->supplierB));

        self::assertSame(400, $this->call('bulkDelete', ['ids' => []])->getStatusCode());
    }

    public function testRecalculateOdometerChainsFollowingTrips(): void
    {
        $car = $this->car($this->supplierA, '9RO 0143', true);
        $repo = $this->container->get(TripRepository::class);
        $trip = fn (string $date, ?string $time, int $start, int $end, float $km): int => $repo->create($this->supplierA, [
            'car_id' => $car, 'trip_date' => $date, 'time_start' => $time,
            'odometer_start' => $start, 'odometer_end' => $end, 'distance_km' => $km,
        ], null);
        $first  = $trip('2099-05-01', null, 1000, 1050, 50.0);
        $third  = $trip('2099-05-02', '14:00', 1080, 1100, 20.0);
        $second = $trip('2099-05-02', '08:00', 1050, 1080, 30.0);
        $fourth = $trip('2099-05-03', null, 1100, 1107, 6.6);
        $fifth  = $trip('2099-05-04', null, 1107, 1112, 5.4);

        // Oprava první jízdy: konec 1050 → 1060 (ujeto 60), následující jízdy zůstaly na starých stavech.
        $repo->update($first, $this->supplierA, ['car_id' => $car, 'trip_date' => '2099-05-01',
            'odometer_start' => 1000, 'odometer_end' => 1060, 'distance_km' => 60.0]);

        $res = $this->call('recalculateOdometer', ['after_trip_id' => $first]);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(4, $this->json($res)['data']['updated'] ?? $this->json($res)['updated'] ?? null);
        $odo = fn (int $id): array => [$repo->find($id, $this->supplierA)['odometer_start'], $repo->find($id, $this->supplierA)['odometer_end']];
        self::assertSame([1000, 1060], $odo($first));
        self::assertSame([1060, 1090], $odo($second));
        self::assertSame([1090, 1110], $odo($third));
        // Desetinné km se sčítají přesně: 1110 + 6,6 + 5,4 = 1122, ne 1117 + 5.
        self::assertSame([1110, 1117], $odo($fourth));
        self::assertSame([1117, 1122], $odo($fifth));
        self::assertSame(6.6, $repo->find($fourth, $this->supplierA)['distance_km']);

        // Celé auto od první jízdy: nic se nezmění, stav už navazuje.
        $res = $this->call('recalculateOdometer', ['car_id' => $car]);
        self::assertSame(0, $this->json($res)['data']['updated'] ?? $this->json($res)['updated'] ?? null);

        self::assertSame(404, $this->call('recalculateOdometer', ['after_trip_id' => 999999999])->getStatusCode());
        self::assertSame(400, $this->call('recalculateOdometer', [])->getStatusCode());
    }

    private function call(string $method, array $body): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/logbook/trips/bulk-delete')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);
        return $this->container->get(TripsAction::class)->$method($request, new Response());
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
