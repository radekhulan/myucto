<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook\OdometerSource\Bmw;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Logbook\OdometerSource\Bmw\BmwCarDataClient;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceException;
use MyInvoice\Service\OAuth\Pkce;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BmwCarDataClientTest extends TestCase
{
    private const CLIENT_ID = '00000000-0000-4000-8000-000000000001';
    private const VIN = 'WBA00000000000001';

    /** @var list<array<string,mixed>> */
    private array $history = [];

    public function testDeviceCodeRequestUsesPkceS256AndCarDataScope(): void
    {
        $client = $this->client([new Response(200, [], json_encode([
            'device_code' => 'synthetic-device', 'user_code' => 'ABCD1234',
            'verification_uri' => 'https://customer.bmwgroup.com/oneid/link', 'interval' => 5, 'expires_in' => 300,
        ]))]);
        $verifier = Pkce::verifier();

        $result = $client->requestDeviceCode(self::CLIENT_ID, $verifier);

        $request = $this->history[0]['request'];
        self::assertSame('https://customer.bmwgroup.com/gcdm/oauth/device/code', (string) $request->getUri());
        parse_str((string) $request->getBody(), $form);
        self::assertSame([
            'client_id' => self::CLIENT_ID, 'response_type' => 'device_code', 'scope' => 'authenticate_user openid cardata:api:read',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], $form);
        self::assertSame('https://customer.bmwgroup.com/oneid/link?user_code=ABCD1234', $result['verification_url']);
        self::assertSame(['synthetic-device', 'ABCD1234', 5, 300],
            [$result['device_code'], $result['user_code'], $result['interval'], $result['expires_in']]);
        self::assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testVerificationLinkOutsideBmwIsRejected(): void
    {
        $client = $this->client([new Response(200, [], json_encode([
            'device_code' => 'd', 'user_code' => 'u', 'verification_uri' => 'https://evil.example/link',
        ]))]);

        try {
            $client->requestDeviceCode(self::CLIENT_ID, 'verifier');
            self::fail('Očekávána výjimka.');
        } catch (OdometerSourceException $e) {
            self::assertSame(OdometerSourceException::INVALID_RESPONSE, $e->errorCode);
        }
    }

    /** @return iterable<string,array{int,string,string}> */
    public static function pollErrorProvider(): iterable
    {
        yield 'čeká na potvrzení' => [403, 'authorization_pending', BmwCarDataClient::AUTHORIZATION_PENDING];
        yield 'zpomalit' => [400, 'slow_down', BmwCarDataClient::SLOW_DOWN];
        yield 'zamítnuto' => [403, 'access_denied', OdometerSourceException::ACCESS_DENIED];
        yield 'vypršelo' => [400, 'expired_token', OdometerSourceException::EXPIRED];
    }

    #[DataProvider('pollErrorProvider')]
    public function testPollMapsDeviceFlowErrors(int $status, string $error, string $code): void
    {
        $client = $this->client([new Response($status, [], json_encode(['error' => $error]))]);
        try {
            $client->pollToken(self::CLIENT_ID, 'device', 'verifier');
            self::fail('Očekávána výjimka.');
        } catch (OdometerSourceException $e) {
            self::assertSame($code, $e->errorCode);
        }
        parse_str((string) $this->history[0]['request']->getBody(), $form);
        self::assertSame('urn:ietf:params:oauth:grant-type:device_code', $form['grant_type']);
        self::assertSame('verifier', $form['code_verifier']);
    }

    public function testRefreshReturnsRotatedRefreshToken(): void
    {
        $client = $this->client([new Response(200, [], json_encode([
            'access_token' => 'synthetic-access-2', 'refresh_token' => 'synthetic-refresh-2', 'token_type' => 'Bearer', 'expires_in' => 3599,
        ]))]);

        $tokens = $client->refresh(self::CLIENT_ID, 'synthetic-refresh-1');

        self::assertSame(['access_token' => 'synthetic-access-2', 'refresh_token' => 'synthetic-refresh-2', 'expires_in' => 3599], $tokens);
        parse_str((string) $this->history[0]['request']->getBody(), $form);
        self::assertSame(['client_id' => self::CLIENT_ID, 'refresh_token' => 'synthetic-refresh-1', 'grant_type' => 'refresh_token'], $form);
    }

    public function testRejectedRefreshRequiresNewLogin(): void
    {
        $client = $this->client([new Response(400, [], json_encode(['error' => 'invalid_grant']))]);

        try {
            $client->refresh(self::CLIENT_ID, 'expired');
            self::fail('Očekávána výjimka.');
        } catch (OdometerSourceException $e) {
            self::assertSame(OdometerSourceException::REAUTH_REQUIRED, $e->errorCode);
        }
    }

    public function testTravelledDistanceKeepsMilesForConversionInCore(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['telematicData' => [
            BmwCarDataClient::ODOMETER_KEY => ['value' => '700', 'unit' => 'miles', 'timestamp' => '2099-03-02T16:02:11.000Z'],
        ]]))]);

        $value = $client->travelledDistance('synthetic-access', self::VIN, 'CONTAINER0001');

        self::assertSame([700.0, 'mi'], [$value['value'], $value['unit']]);
    }

    public function testTravelledDistanceParsesStringValueAndSendsVersionHeader(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['telematicData' => [
            BmwCarDataClient::ODOMETER_KEY => ['value' => '12345.6', 'unit' => 'km', 'timestamp' => '2099-03-02T16:02:11.000Z'],
        ]]))]);

        $value = $client->travelledDistance('synthetic-access', self::VIN, 'CONTAINER0001');

        self::assertSame([12345.6, 'km'], [$value['value'], $value['unit']]);
        self::assertSame('2099-03-02T16:02:11+00:00', $value['at']->format(DATE_ATOM));
        $request = $this->history[0]['request'];
        self::assertSame('https://api-cardata.bmwgroup.com/customers/vehicles/' . self::VIN . '/telematicData?containerId=CONTAINER0001',
            (string) $request->getUri());
        self::assertSame('v1', $request->getHeaderLine('x-version'));
        self::assertSame('Bearer synthetic-access', $request->getHeaderLine('Authorization'));
    }

    public function testMissingOdometerReturnsNull(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['telematicData' => [
            BmwCarDataClient::ODOMETER_KEY => ['value' => null, 'unit' => null, 'timestamp' => null],
        ]]))]);

        self::assertNull($client->travelledDistance('a', self::VIN, 'C'));
    }

    /** @return iterable<string,array{int,string,string}> */
    public static function apiErrorProvider(): iterable
    {
        yield 'neplatný kontejner' => [400, 'CU-105', BmwCarDataClient::CONTAINER_INVALID];
        yield 'denní limit' => [403, 'CU-429', OdometerSourceException::RATE_LIMITED];
        yield 'VIN bez oprávnění' => [403, 'CU-104', OdometerSourceException::VEHICLE_NOT_PERMITTED];
        yield 'propadlý token' => [401, 'CU-102', OdometerSourceException::TOKEN_INVALID];
    }

    #[DataProvider('apiErrorProvider')]
    public function testApiErrorsMapToStableCodes(int $status, string $cu, string $code): void
    {
        $client = $this->client([new Response($status, [], json_encode(['exveErrorId' => $cu, 'exveErrorMsg' => 'synthetic']))]);
        try {
            $client->travelledDistance('a', self::VIN, 'C');
            self::fail('Očekávána výjimka.');
        } catch (OdometerSourceException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    public function testMappingsKeepPrimaryFlagAndSkipInvalidVins(): void
    {
        $client = $this->client([new Response(200, [], json_encode([
            ['vin' => self::VIN, 'mappingType' => 'PRIMARY'],
            ['vin' => 'WBA00000000000002', 'mappingType' => 'SECONDARY'],
            ['vin' => 'not-a-vin', 'mappingType' => 'PRIMARY'],
        ]))]);

        self::assertSame([['vin' => self::VIN, 'primary' => true], ['vin' => 'WBA00000000000002', 'primary' => false]],
            $client->vehicleMappings('a'));
    }

    public function testCreateContainerRequestsOnlyOdometer(): void
    {
        $client = $this->client([new Response(201, [], json_encode(['containerId' => 'CONTAINER0001', 'state' => 'ACTIVE']))]);

        self::assertSame('CONTAINER0001', $client->createContainer('a'));
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertSame([BmwCarDataClient::ODOMETER_KEY], $body['technicalDescriptors']);
    }

    /** @param list<Response> $responses */
    private function client(array $responses): BmwCarDataClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new BmwCarDataClient(new Client(['handler' => $stack]));
    }
}
