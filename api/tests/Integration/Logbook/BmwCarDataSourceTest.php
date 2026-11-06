<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\OAuthSessionRepository;
use MyInvoice\Repository\OdometerReadingRepository;
use MyInvoice\Repository\OdometerSourceAccountRepository;
use MyInvoice\Repository\OdometerSourceCarRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Logbook\OdometerSource\Bmw\BmwCarDataClient;
use MyInvoice\Service\Logbook\OdometerSource\Bmw\BmwCarDataProvider;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceException;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceRegistry;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceService;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceVault;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Service\OAuth\Pkce;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zdroj BMW CarData přes společné jádro: device code s PKCE, rotace refresh tokenu,
 * kontejner uložený v přístupech, volání v denním limitu účtu. Odpovědi BMW jsou
 * syntetické (Guzzle MockHandler).
 */
#[Group('integration')]
final class BmwCarDataSourceTest extends TestCase
{
    use LogbookFixtures;

    private const CLIENT_ID = '00000000-0000-4000-8000-000000000001';
    private const VIN = 'WBA00000000000001';

    private MockHandler $bmw;
    /** @var list<array<string,mixed>> */
    private array $history = [];
    private OdometerSourceService $service;
    private OdometerSourceVault $vault;
    private OdometerSourceAccountRepository $accounts;
    private string $timezone;

    protected function setUp(): void
    {
        $this->bootLogbook();
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');
        $this->bmw = new MockHandler();
        $stack = HandlerStack::create($this->bmw);
        $stack->push(Middleware::history($this->history));
        $provider = new BmwCarDataProvider(new BmwCarDataClient(new Client(['handler' => $stack])));
        $registry = new OdometerSourceRegistry([$provider]);
        $this->accounts = new OdometerSourceAccountRepository($this->db);
        $secrets = new SecretEncryption(new Config(['app' => [
            'pepper' => 'synthetic-pepper', 'secret_encryption_key' => base64_encode(str_repeat('k', 32)),
        ]]));
        $this->vault = new OdometerSourceVault($secrets);
        $flow = new OAuthClientFlow(new OAuthSessionRepository($this->db), $secrets, $this->db,
            new Config(['app' => ['url' => 'https://example.test']]));
        $this->service = new OdometerSourceService($registry, $this->vault, $flow, $this->accounts,
            new OdometerSourceCarRepository($this->db), new CarRepository($this->db), new OdometerReadingRepository($this->db, $registry));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone ?? 'Europe/Prague');
        $this->shutdownLogbook();
    }

    public function testDeviceCodeConnectStoresTokensAndContainerAndFirstReading(): void
    {
        $car = $this->car($this->supplierA, 'BMW0001');
        $this->bmw->append(new Response(200, [], json_encode([
            'device_code' => 'synthetic-device', 'user_code' => 'ABCD1234',
            'verification_uri' => 'https://customer.bmwgroup.com/oneid/link', 'interval' => 5, 'expires_in' => 300,
        ])));

        $start = $this->service->startConnect($this->supplierA, $car, BmwCarDataProvider::CODE,
            ['client_id' => ' ' . strtoupper(self::CLIENT_ID) . ' '], $this->userId);
        self::assertSame(['device_code', 'ABCD1234', 'https://customer.bmwgroup.com/oneid/link?user_code=ABCD1234'],
            [$start['state'], $start['user_code'], $start['url']]);
        parse_str((string) $this->history[0]['request']->getBody(), $form);
        self::assertSame(self::CLIENT_ID, $form['client_id']);

        $this->bmw->append(new Response(400, [], json_encode(['error' => 'slow_down'])));
        self::assertSame(['state' => 'waiting', 'interval' => 10], $this->service->continueConnect($this->supplierA, $car, [], $this->userId));

        $this->bmw->append(
            $this->tokens('synthetic-access-1', 'synthetic-refresh-1'),
            new Response(200, [], json_encode([['vin' => self::VIN, 'mappingType' => 'PRIMARY']])),
            new Response(201, [], json_encode(['containerId' => 'CONTAINER0001'])),
            $this->odometer('1087', '2099-03-02T10:00:00Z'),
        );
        $result = $this->service->continueConnect($this->supplierA, $car, [], $this->userId);

        self::assertSame(['connected', self::VIN, 1087], [$result['state'], $result['car']['vehicle_ref'], $result['car']['last_odometer']]);
        parse_str((string) $this->history[2]['request']->getBody(), $poll);
        self::assertSame('synthetic-device', $poll['device_code']);
        // PKCE ze společné vrstvy: verifier k výzvě z device code drží jádro mimo data zdroje.
        self::assertSame($form['code_challenge'], Pkce::challenge($poll['code_verifier']));
        self::assertSame(['synthetic-access-1', 'CONTAINER0001'], [$this->credentials($car)['access_token'], $this->credentials($car)['container_id']]);
        // Do limitu BMW jdou jen volání CarData API (auta, kontejner, stav), ne přihlášení.
        self::assertSame([3, 50], [$result['account']['calls_today'], $result['account']['daily_call_limit']]);
    }

    public function testDailySyncRefreshesTokenAndStoresRotatedRefreshToken(): void
    {
        $car = $this->car($this->supplierA, 'BMW0002');
        $this->activeConnection($car, expiresIn: 60);
        $this->bmw->append(
            $this->tokens('synthetic-access-2', 'synthetic-refresh-2'),
            $this->odometer('1200', '2099-03-05T10:00:00Z'),
        );

        $summary = $this->service->syncAll();

        self::assertSame(1, $summary['succeeded'], json_encode($summary));
        parse_str((string) $this->history[0]['request']->getBody(), $form);
        self::assertSame(['synthetic-refresh-1', 'refresh_token'], [$form['refresh_token'], $form['grant_type']]);
        self::assertSame('Bearer synthetic-access-2', $this->history[1]['request']->getHeaderLine('Authorization'));
        self::assertSame(['synthetic-refresh-2', 'CONTAINER0001'], [$this->credentials($car)['refresh_token'], $this->credentials($car)['container_id']]);
    }

    public function testRejectedRefreshMarksConnectionForNewLogin(): void
    {
        $car = $this->car($this->supplierA, 'BMW0003');
        $this->activeConnection($car, expiresIn: 60);
        $this->bmw->append(new Response(400, [], json_encode(['error' => 'invalid_grant'])));

        $summary = $this->service->syncAll();

        self::assertSame(OdometerSourceException::REAUTH_REQUIRED, $summary['details'][0]['error_code']);
        $account = $this->service->status($this->supplierA, $car)['account'];
        self::assertSame(['error', OdometerSourceException::REAUTH_REQUIRED], [$account['status'], $account['last_sync_error_code']]);
    }

    public function testInvalidContainerIsRecreatedOnce(): void
    {
        $car = $this->car($this->supplierA, 'BMW0004');
        $this->activeConnection($car);
        $this->bmw->append(
            new Response(400, [], json_encode(['exveErrorId' => 'CU-105'])),
            new Response(201, [], json_encode(['containerId' => 'CONTAINER0002'])),
            $this->odometer('1100', '2099-03-05T10:00:00Z'),
        );

        $this->service->sync($this->supplierA, $car);

        self::assertSame('CONTAINER0002', $this->credentials($car)['container_id']);
        self::assertCount(3, $this->history);
    }

    public function testInvalidClientIdIsRejectedBeforeCallingBmw(): void
    {
        $car = $this->car($this->supplierA, 'BMW0005');

        try {
            $this->service->startConnect($this->supplierA, $car, BmwCarDataProvider::CODE, ['client_id' => 'not-a-uuid'], $this->userId);
            self::fail('Neplatné client ID musí být odmítnuto.');
        } catch (OdometerSourceException $e) {
            self::assertSame(OdometerSourceException::INVALID_INPUT, $e->errorCode);
        }
        self::assertSame([], $this->history);
        self::assertNull($this->service->status($this->supplierA, $car)['car']);
        self::assertSame([], $this->service->status($this->supplierA, $car)['accounts']);
    }

    private function activeConnection(int $car, int $expiresIn = 3600): int
    {
        $this->pdo->prepare("INSERT INTO odometer_source_accounts (supplier_id, provider, status) VALUES (?, ?, 'active')")
            ->execute([$this->supplierA, BmwCarDataProvider::CODE]);
        $id = (int) $this->pdo->lastInsertId();
        $this->accounts->activate($this->supplierA, $id, $this->vault->sealCredentials($this->supplierA, $id, BmwCarDataProvider::CODE, [
            'client_id' => self::CLIENT_ID, 'access_token' => 'synthetic-access-1', 'refresh_token' => 'synthetic-refresh-1',
            'access_expires_at' => time() + $expiresIn, 'container_id' => 'CONTAINER0001',
        ]));
        $this->pdo->prepare('INSERT INTO odometer_source_cars (supplier_id, account_id, car_id, vehicle_ref) VALUES (?, ?, ?, ?)')
            ->execute([$this->supplierA, $id, $car, self::VIN]);
        return $id;
    }

    /** @return array<string,string|int> */
    private function credentials(int $car): array
    {
        $account = $this->service->status($this->supplierA, $car)['account']['id'];
        return $this->vault->openCredentials($this->supplierA, $account, BmwCarDataProvider::CODE,
            (string) $this->accounts->findWithSecrets($this->supplierA, $account)['credentials_ciphertext']);
    }

    private function tokens(string $access, string $refresh): Response
    {
        return new Response(200, [], json_encode(['access_token' => $access, 'refresh_token' => $refresh,
            'token_type' => 'Bearer', 'expires_in' => 3599]));
    }

    private function odometer(string $value, string $timestamp): Response
    {
        return new Response(200, [], json_encode(['telematicData' => [
            BmwCarDataClient::ODOMETER_KEY => ['value' => $value, 'unit' => 'km', 'timestamp' => $timestamp],
        ]]));
    }
}
