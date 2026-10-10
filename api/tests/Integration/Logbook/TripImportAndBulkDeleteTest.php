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
