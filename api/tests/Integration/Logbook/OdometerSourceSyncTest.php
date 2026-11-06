<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\OAuthSessionRepository;
use MyInvoice\Repository\OdometerReadingRepository;
use MyInvoice\Repository\OdometerSourceAccountRepository;
use MyInvoice\Repository\OdometerSourceCarRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Logbook\OdometerGapCalculator;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSample;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceException;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceRegistry;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceService;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceVault;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceVehicle;
use MyInvoice\Service\OAuth\OAuthCallback;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Společné jádro automatického stavu tachometru proti falešnému zdroji: připojení účtu,
 * přiřazení aut, zápis stavu, rotace přístupů, denní limit, kontrola návaznosti
 * a izolace tenantů. Konkrétní zdroj (BMW) testuje {@see BmwCarDataSourceTest}.
 */
#[Group('integration')]
final class OdometerSourceSyncTest extends TestCase
{
    use LogbookFixtures;

    private const VIN = 'WBA00000000000001';

    private FakeOdometerSource $source;
    private OAuthClientFlow $flow;
    private OdometerSourceService $service;
    private OdometerSourceVault $vault;
    private OdometerSourceAccountRepository $accounts;
    private OdometerReadingRepository $readingRepository;
    private string $timezone;

    protected function setUp(): void
    {
        $this->bootLogbook();
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');
        $this->source = new FakeOdometerSource();
        $registry = new OdometerSourceRegistry([$this->source]);
        $this->accounts = new OdometerSourceAccountRepository($this->db);
        $this->readingRepository = new OdometerReadingRepository($this->db, $registry);
        $secrets = new SecretEncryption(new Config(['app' => [
            'pepper' => 'synthetic-pepper', 'secret_encryption_key' => base64_encode(str_repeat('k', 32)),
        ]]));
        $this->vault = new OdometerSourceVault($secrets);
        $this->flow = new OAuthClientFlow(new OAuthSessionRepository($this->db), $secrets, $this->db,
            new Config(['app' => ['url' => 'https://example.test']]));
        $this->service = new OdometerSourceService($registry, $this->vault, $this->flow, $this->accounts,
            new OdometerSourceCarRepository($this->db), new CarRepository($this->db), $this->readingRepository);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone ?? 'Europe/Prague');
        $this->shutdownLogbook();
    }

    public function testConnectWaitsForLoginThenStoresOdometerAsReadingInLocalDate(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0001', 1000, '2099-03-01');

        $start = $this->service->startConnect($this->supplierA, $car, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);
        self::assertSame(['state' => 'device_code', 'user_code' => 'ABCD1234', 'url' => 'https://login.example.test/link',
            'interval' => 5, 'expires_in' => 300], $start);
        $pending = $this->accountRow($this->supplierA);
        self::assertSame('pending', $pending['status']);
        // state a PKCE generuje společná vrstva; v DB je jen hash state a šifrované přihlášení.
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->source->contexts[0]->state);
        self::assertSame(hash('sha256', $this->source->contexts[0]->state), $pending['pending_state_hash']);
        $session = $this->oauthRow($pending['pending_state_hash']);
        self::assertSame(['odometer', FakeOdometerSource::CODE, (int) $pending['id'], 'device_code', 'pending'],
            [$session['domain'], $session['provider'], (int) $session['subject_id'], $session['flow'], $session['status']]);
        self::assertStringStartsWith('enc:v2:', $session['secret_ciphertext']);
        self::assertStringNotContainsString('synthetic-device', $session['secret_ciphertext']);

        self::assertSame(['state' => 'waiting', 'interval' => 5], $this->service->continueConnect($this->supplierA, $car, [], $this->userId));
        self::assertSame([['key' => 'synthetic-key', 'device' => 'synthetic-device']], $this->source->pendingSeen);
        self::assertSame($this->source->contexts[0]->codeVerifier, $this->source->inputSeen[0]['code_verifier']);

        $this->source->approved = true;
        // 23:30 UTC je v Praze už další den — stav patří k místnímu datu.
        $this->source->samples[self::VIN] = new OdometerSample(1087, OdometerSample::UNIT_KM, new \DateTimeImmutable('2099-03-02T23:30:00Z'));
        $result = $this->service->continueConnect($this->supplierA, $car, [], $this->userId);

        self::assertSame('connected', $result['state']);
        self::assertSame([self::VIN, 1087, FakeOdometerSource::CODE, 'active'], [$result['car']['vehicle_ref'],
            $result['car']['last_odometer'], $result['account']['provider'], $result['account']['status']]);
        self::assertSame([['2099-03-03', '00:30', 1087, FakeOdometerSource::CODE]], $this->readings($car));
        $gaps = (new OdometerGapCalculator($this->db))->forTenant($this->supplierA, $car);
        self::assertSame([[1000, 1087, 87]], array_map(static fn (array $g): array => [$g['odometer_from'], $g['odometer_to'], $g['km']], $gaps));

        $stored = $this->accountRow($this->supplierA);
        self::assertStringStartsWith('enc:v2:', $stored['credentials_ciphertext']);
        self::assertStringNotContainsString('synthetic-token', $stored['credentials_ciphertext']);
        self::assertNull($stored['pending_state_hash']);
        self::assertSame(['completed', ''], [$this->oauthRow($pending['pending_state_hash'])['status'],
            $this->oauthRow($pending['pending_state_hash'])['secret_ciphertext']]);
    }

    public function testRedirectLoginTakesCodeOnlyFromCallbackOfTheSameUser(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0040', 1000, '2099-03-01');
        $this->source->redirect = true;
        $this->source->approved = true;

        $start = $this->service->startConnect($this->supplierA, $car, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);
        $context = $this->source->contexts[0];
        self::assertSame('redirect', $start['state']);
        self::assertSame('https://example.test/api/oauth/callback', $context->redirectUri);
        self::assertNotNull($context->codeChallenge());

        // Bez návratu ze služby se zdroje vůbec neptá; kód z dialogu se nepoužije.
        self::assertSame(['state' => 'waiting', 'interval' => 5],
            $this->service->continueConnect($this->supplierA, $car, ['code' => 'forged-code'], $this->userId));
        self::assertSame([], $this->source->inputSeen);

        $this->assertFails(OdometerSourceException::EXPIRED, fn () => $this->service->receiveCallback(
            $this->oauthCallback($context->state, 'synthetic-code', $this->supplierA, $this->userId + 100000)));
        self::assertSame($car, $this->service->receiveCallback($this->oauthCallback($context->state, 'synthetic-code')));
        $this->assertFails(OdometerSourceException::EXPIRED,
            fn () => $this->service->receiveCallback($this->oauthCallback($context->state, 'replayed-code')));

        $result = $this->service->continueConnect($this->supplierA, $car, ['code' => 'forged-code', 'code_verifier' => 'forged'], $this->userId);

        self::assertSame('connected', $result['state']);
        self::assertSame([['code' => 'synthetic-code', 'code_verifier' => $context->codeVerifier]], $this->source->inputSeen);
    }

    public function testUsedCodeIsNotSentAgainAfterTransientExchangeFailure(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0044', 1000, '2099-03-01');
        $this->source->redirect = true;
        $this->source->approved = true;
        $this->service->startConnect($this->supplierA, $car, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);
        $this->service->receiveCallback($this->oauthCallback($this->source->contexts[0]->state, 'synthetic-code'));
        $this->source->failNextContinue = OdometerSourceException::REMOTE_UNAVAILABLE;

        $this->assertFails(OdometerSourceException::REMOTE_UNAVAILABLE,
            fn () => $this->service->continueConnect($this->supplierA, $car, [], $this->userId));
        $this->assertFails(OdometerSourceException::EXPIRED,
            fn () => $this->service->continueConnect($this->supplierA, $car, [], $this->userId));

        self::assertCount(1, $this->source->inputSeen, 'Jednorázový kód se nesmí poslat službě podruhé.');
        self::assertSame(0, $this->accountCount($this->supplierA));
    }

    public function testIssuedCredentialsSurviveFailedStoreAndAreNotRequestedAgain(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0045', 1000, '2099-03-01');
        $this->service->startConnect($this->supplierA, $car, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);
        $hash = $this->accountRow($this->supplierA)['pending_state_hash'];
        $this->source->approved = true;
        // Přístup, který nejde uložit k účtu (vault ho odmítne).
        $this->source->connectedCredentials = ['token' => 'not storable'];

        $this->assertFails(OdometerSourceException::REAUTH_REQUIRED,
            fn () => $this->service->continueConnect($this->supplierA, $car, [], $this->userId));
        $session = $this->flow->open($hash, $this->supplierA, $this->userId, 'odometer', FakeOdometerSource::CODE,
            (int) $this->accountRow($this->supplierA)['id']);
        self::assertSame(['token' => 'not storable'], $session?->secret['credentials'], 'Vydané přístupy se nesmí ztratit.');

        $this->assertFails(OdometerSourceException::REAUTH_REQUIRED,
            fn () => $this->service->continueConnect($this->supplierA, $car, [], $this->userId));
        self::assertCount(1, $this->source->pendingSeen, 'Služba se o přístupy podruhé neptá.');
    }

    public function testOnlyTheUserWhoStartedTheLoginCanContinueIt(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0046', 1000, '2099-03-01');
        $this->service->startConnect($this->supplierA, $car, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);
        $this->source->approved = true;

        $this->assertFails('no_pending_connect', fn () => $this->service->continueConnect($this->supplierA, $car, [], $this->userId + 100000));
        $this->assertFails('no_pending_connect', fn () => $this->service->continueConnect($this->supplierA, $car));
        self::assertSame([], $this->source->pendingSeen);

        self::assertSame('connected', $this->service->continueConnect($this->supplierA, $car, [], $this->userId)['state']);
    }

    public function testDeclinedRedirectLoginEndsPendingConnect(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0041', 1000, '2099-03-01');
        $this->source->redirect = true;
        $this->service->startConnect($this->supplierA, $car, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);

        $this->service->receiveCallback($this->oauthCallback($this->source->contexts[0]->state, '', $this->supplierA, $this->userId, 'access_denied'));

        $this->assertFails(OdometerSourceException::ACCESS_DENIED, fn () => $this->service->continueConnect($this->supplierA, $car, [], $this->userId));
        self::assertSame([], $this->source->inputSeen);
        self::assertSame(0, $this->accountCount($this->supplierA));
    }

    public function testCallbackOfAnotherSupplierCannotDeliverCode(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0042', 1000, '2099-03-01');
        $this->source->redirect = true;
        $this->service->startConnect($this->supplierA, $car, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);

        $this->assertFails(OdometerSourceException::EXPIRED, fn () => $this->service->receiveCallback(
            $this->oauthCallback($this->source->contexts[0]->state, 'synthetic-code', $this->supplierB)));
        self::assertSame(['state' => 'waiting', 'interval' => 5], $this->service->continueConnect($this->supplierA, $car, [], $this->userId));
    }

    private function oauthCallback(string $state, string $code, ?int $supplierId = null, ?int $userId = null, ?string $error = null): OAuthCallback
    {
        $session = $this->flow->find($state);
        $session['supplier_id'] = $supplierId ?? $this->supplierA;
        $session['user_id'] = $userId ?? $this->userId;
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/oauth/callback')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $session['user_id']]);
        return new OAuthCallback($session, $state, $code, $error, ['state' => $state, 'code' => $code], $request);
    }

    /** @return array<string,mixed> */
    private function oauthRow(string $hash): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM oauth_sessions WHERE state_hash = ?');
        $stmt->execute([$hash]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function testChangedPendingStateIsStoredForNextPoll(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0009', 1000, '2099-03-01');
        $this->service->startConnect($this->supplierA, $car, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);
        $this->source->slowDown = true;

        self::assertSame(['state' => 'waiting', 'interval' => 10], $this->service->continueConnect($this->supplierA, $car, [], $this->userId));
        $this->service->continueConnect($this->supplierA, $car, [], $this->userId);

        self::assertSame('slow', $this->source->pendingSeen[1]['device']);
    }

    public function testLoginPollRunsUnderAccountLock(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0030', 1000, '2099-03-01');
        $this->service->startConnect($this->supplierA, $car, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);
        $name = NamedLockName::for($this->db, 'odometer-source', (int) $this->accountRow($this->supplierA)['id']);
        $held = null;
        $this->source->onContinue = function () use ($name, &$held): void {
            $stmt = $this->db->pdo()->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
            $stmt->execute([$name]);
            $held = (int) $stmt->fetchColumn();
        };

        $this->service->continueConnect($this->supplierA, $car, [], $this->userId);

        self::assertSame(1, $held, 'Dva souběžné dotazy by jinak vyměnily tentýž kód za tokeny dvakrát.');
    }

    public function testAccountWithSeveralCarsAsksWhichOne(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0010', 1000, '2099-03-01');
        $this->source->vehicles = [new OdometerSourceVehicle('REF-A', null, 'Auto A'), new OdometerSourceVehicle('REF-B', null, 'Auto B')];
        $this->source->approved = true;
        $this->service->startConnect($this->supplierA, $car, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);

        $result = $this->service->continueConnect($this->supplierA, $car, [], $this->userId);

        self::assertSame('select_vehicle', $result['state']);
        self::assertSame(['REF-A', 'REF-B'], array_column($result['vehicles'], 'ref'));
        $this->source->samples['REF-B'] = new OdometerSample(1100, OdometerSample::UNIT_KM, new \DateTimeImmutable('2099-03-05T10:00:00Z'));
        $this->assertFails(OdometerSourceException::VEHICLE_NOT_PERMITTED,
            fn () => $this->service->selectVehicle($this->supplierA, $car, $result['account_id'], 'REF-C', $this->userId));

        $selected = $this->service->selectVehicle($this->supplierA, $car, $result['account_id'], 'REF-B', $this->userId);

        self::assertSame('REF-B', $selected['car']['vehicle_ref']);
        self::assertSame([['2099-03-05', '11:00', 1100, FakeOdometerSource::CODE]], $this->readings($car));
    }

    public function testCarIsPairedByVinAndOtherCarsOfTheFirmFollowOnTheSameAccount(): void
    {
        $this->source->vehicles = [
            new OdometerSourceVehicle('REF-A', 'WBA00000000000001', null, true),
            new OdometerSourceVehicle('REF-B', 'WBA00000000000002', null, false),
        ];
        $this->source->approved = true;
        $byVin = $this->carWithStart($this->supplierA, 'TST0014', 1000, '2099-03-01', 'wba 00000000000002');
        $other = $this->carWithStart($this->supplierA, 'TST0015', 1000, '2099-03-01', 'WBA00000000000001');
        $foreign = $this->carWithStart($this->supplierB, 'TST0016', 1000, '2099-03-01', 'WBA00000000000001');

        $this->service->startConnect($this->supplierA, $byVin, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);
        $this->service->continueConnect($this->supplierA, $byVin, [], $this->userId);

        $status = $this->service->status($this->supplierA, $byVin);
        self::assertSame('REF-B', $status['car']['vehicle_ref']);
        self::assertSame('REF-A', $this->service->status($this->supplierA, $other)['car']['vehicle_ref']);
        self::assertSame(['TST0014', 'TST0015'], array_column($status['account']['cars'], 'registration'));
        self::assertCount(1, $status['accounts']);
        self::assertNull($this->service->status($this->supplierB, $foreign)['car']);
    }

    public function testSecondCarJoinsTheAccountAndUsesItsRotatedToken(): void
    {
        $this->source->vehicles = [new OdometerSourceVehicle('REF-A', null, null, true), new OdometerSourceVehicle('REF-B', null, null, false)];
        $this->source->samples = ['REF-A' => new OdometerSample(1100, OdometerSample::UNIT_KM, new \DateTimeImmutable('2099-03-05T10:00:00Z')),
            'REF-B' => new OdometerSample(2100, OdometerSample::UNIT_KM, new \DateTimeImmutable('2099-03-05T10:00:00Z'))];
        $first = $this->carWithStart($this->supplierA, 'TST0017', 1000, '2099-03-01');
        $second = $this->carWithStart($this->supplierA, 'TST0018', 2000, '2099-03-01');
        $this->source->approved = true;
        $this->source->rotateTo = 'synthetic-token-2';
        $this->service->startConnect($this->supplierA, $first, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);
        $account = $this->service->continueConnect($this->supplierA, $first, [], $this->userId)['account']['id'];

        $result = $this->service->attach($this->supplierA, $second, $account, $this->userId);

        self::assertSame(['connected', 'REF-B', $account], [$result['state'], $result['car']['vehicle_ref'], $result['account']['id']]);
        self::assertSame('synthetic-token-2', end($this->source->credentialsSeen)['token']);
        self::assertSame(1, $this->accountCount($this->supplierA));
        self::assertSame([['2099-03-05', '11:00', 2100, FakeOdometerSource::CODE]], $this->readings($second));
    }

    public function testVehicleOfTheSourceBelongsToOnlyOneCarOfTheFirm(): void
    {
        $first = $this->carWithStart($this->supplierA, 'TST0025', 1000, '2099-03-01');
        $second = $this->carWithStart($this->supplierA, 'TST0026', 1000, '2099-03-01');
        $this->connectedAccount($this->supplierA, [$first => self::VIN]);
        $this->source->approved = true;

        // Druhé přihlášení k témuž účtu u služby z jiného auta.
        $this->service->startConnect($this->supplierA, $second, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);
        $this->assertFails('vehicle_taken', fn () => $this->service->continueConnect($this->supplierA, $second, [], $this->userId));

        self::assertNull($this->service->status($this->supplierA, $second)['car']);
        self::assertSame(['disconnected', null], [$this->accountRow($this->supplierA)['status'], $this->accountRow($this->supplierA)['credentials_ciphertext']]);
        $other = $this->connectedAccount($this->supplierA, []);
        $this->assertFails('vehicle_taken', fn () => $this->service->selectVehicle($this->supplierA, $second, $other, self::VIN, $this->userId));
        self::assertSame(self::VIN, $this->service->status($this->supplierA, $first)['car']['vehicle_ref']);
    }

    public function testDeclinedLoginEndsPendingConnect(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0016', 1000, '2099-03-01');
        $this->service->startConnect($this->supplierA, $car, FakeOdometerSource::CODE, ['key' => 'synthetic-key'], $this->userId);
        $this->source->denied = true;

        $this->assertFails(OdometerSourceException::ACCESS_DENIED, fn () => $this->service->continueConnect($this->supplierA, $car, [], $this->userId));
        $this->assertFails('no_pending_connect', fn () => $this->service->continueConnect($this->supplierA, $car, [], $this->userId));
        self::assertSame(0, $this->accountCount($this->supplierA));
    }

    public function testReconnectingAnAccountKeepsItsCars(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0019', 1000, '2099-03-01');
        $account = $this->connectedAccount($this->supplierA, [$car => self::VIN]);
        $this->accounts->recordSyncError($this->supplierA, $account, OdometerSourceException::REAUTH_REQUIRED);
        $this->source->approved = true;

        $this->service->startConnect($this->supplierA, $car, 'ignored', ['key' => 'synthetic-key'], $this->userId, $account);
        $result = $this->service->continueConnect($this->supplierA, $car, [], $this->userId);

        self::assertSame(['connected', $account, 'active'], [$result['state'], $result['account']['id'], $result['account']['status']]);
        self::assertSame(1, $this->accountCount($this->supplierA));
        self::assertSame('synthetic-token-1', $this->credentials($account)['token']);
    }

    public function testCredentialsChangedBySourceAreStoredEncrypted(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0002', 1000, '2099-03-01');
        $account = $this->connectedAccount($this->supplierA, [$car => self::VIN]);
        $this->source->rotateTo = 'synthetic-token-2';
        $this->source->samples[self::VIN] = new OdometerSample(1200, OdometerSample::UNIT_KM, new \DateTimeImmutable('2099-03-05T10:00:00Z'));

        $summary = $this->service->syncAll();

        self::assertSame(1, $summary['succeeded'], json_encode($summary));
        self::assertSame('synthetic-token-2', $this->credentials($account)['token']);
        self::assertSame([['2099-03-05', '11:00', 1200, FakeOdometerSource::CODE]], $this->readings($car));
    }

    public function testRefreshedTokenIsStoredAndConcurrentRotationIsNotRepeated(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0043', 1000, '2099-03-01');
        $account = $this->connectedAccount($this->supplierA, [$car => self::VIN]);
        $this->source->refreshOnRead = true;
        $this->source->samples[self::VIN] = new OdometerSample(1100, OdometerSample::UNIT_KM, new \DateTimeImmutable('2099-03-05T10:00:00Z'));

        $this->service->sync($this->supplierA, $car);
        self::assertSame([1, 'synthetic-token-refreshed'], [$this->source->refreshes, $this->credentials($account)['token']]);

        // Souběžný požadavek obnovil token mezi načtením přístupů a obnovou: pod zámkem se
        // přístupy načtou znovu a rotující refresh token se nepoužije podruhé.
        $this->accounts->activate($this->supplierA, $account,
            $this->vault->sealCredentials($this->supplierA, $account, FakeOdometerSource::CODE, ['token' => 'synthetic-token-1']));
        $this->source->beforeRefresh = function () use ($account): void {
            $this->accounts->rotateCredentials($this->supplierA, $account,
                $this->vault->sealCredentials($this->supplierA, $account, FakeOdometerSource::CODE, ['token' => 'synthetic-token-2']));
        };
        $this->service->sync($this->supplierA, $car);

        self::assertSame(1, $this->source->refreshes);
        self::assertSame('synthetic-token-2', end($this->source->credentialsSeen)['token']);
    }

    public function testMilesAreConvertedToKilometres(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0020', 1000, '2099-03-01');
        $this->connectedAccount($this->supplierA, [$car => self::VIN]);
        $this->source->samples[self::VIN] = new OdometerSample(700, OdometerSample::UNIT_MI, new \DateTimeImmutable('2099-03-05T10:00:00+02:00'));

        self::assertSame(['odometer' => 1127, 'reading_date' => '2099-03-05', 'reading_time' => '09:00'],
            $this->service->sync($this->supplierA, $car));
    }

    public function testSameDaySyncOverwritesAndManualReadingStaysAlongside(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0003', 1000, '2099-03-01');
        $this->connectedAccount($this->supplierA, [$car => self::VIN]);
        $this->pdo->prepare("INSERT INTO odometer_readings (supplier_id, car_id, reading_date, odometer, source) VALUES (?, ?, '2099-03-05', 1150, 'manual')")
            ->execute([$this->supplierA, $car]);

        $this->source->samples[self::VIN] = new OdometerSample(1200, OdometerSample::UNIT_KM, new \DateTimeImmutable('2099-03-05T06:00:00Z'));
        $this->service->sync($this->supplierA, $car);
        $this->source->samples[self::VIN] = new OdometerSample(1210, OdometerSample::UNIT_KM, new \DateTimeImmutable('2099-03-05T08:00:00Z'));
        $this->service->sync($this->supplierA, $car);

        self::assertSame([['2099-03-05', '09:00', 1210, FakeOdometerSource::CODE], ['2099-03-05', null, 1150, 'manual']], $this->readings($car));
        $gaps = (new OdometerGapCalculator($this->db))->forTenant($this->supplierA, $car);
        self::assertSame([[1000, 1150]], array_map(static fn (array $g): array => [$g['odometer_from'], $g['odometer_to']], $gaps));
    }

    public function testOdometerNotMatchingTripsIsSkippedWithWarning(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0004', 1000, '2099-03-01');
        $this->pdo->prepare("INSERT INTO trips (supplier_id, car_id, trip_date, odometer_start, odometer_end, distance_km) VALUES (?, ?, '2099-03-02', 1000, 2000, 1000)")
            ->execute([$this->supplierA, $car]);
        $account = $this->connectedAccount($this->supplierA, [$car => self::VIN]);
        $this->source->samples[self::VIN] = new OdometerSample(1500, OdometerSample::UNIT_KM, new \DateTimeImmutable('2099-03-05T10:00:00Z'));

        $summary = $this->service->syncAll();

        self::assertSame([1, 0, 1, 0], [$summary['processed'], $summary['succeeded'], $summary['warnings'], $summary['errors']]);
        self::assertSame([], $this->readings($car));
        self::assertSame(['warning', 'odometer_regression'], $this->carState($car));
        self::assertSame('active', $this->accounts->find($this->supplierA, $account)['status']);
        self::assertSame('warning', $this->carListStatus($car));
    }

    public function testAccountErrorStopsItsCarsWhileCarErrorDoesNot(): void
    {
        $broken = $this->carWithStart($this->supplierA, 'TST0005', 1000, '2099-03-01');
        $brokenSibling = $this->carWithStart($this->supplierA, 'TST0021', 1000, '2099-03-01');
        $silent = $this->carWithStart($this->supplierA, 'TST0011', 1000, '2099-03-01');
        $fine = $this->carWithStart($this->supplierA, 'TST0022', 1000, '2099-03-01');
        $brokenAccount = $this->connectedAccount($this->supplierA, [$broken => 'REF-BROKEN', $brokenSibling => 'REF-SIBLING']);
        $fineAccount = $this->connectedAccount($this->supplierA, [$silent => 'REF-SILENT', $fine => self::VIN]);
        $this->source->failFor['REF-BROKEN'] = OdometerSourceException::REAUTH_REQUIRED;
        $this->source->samples[self::VIN] = new OdometerSample(1100, OdometerSample::UNIT_KM, new \DateTimeImmutable('2099-03-05T10:00:00Z'));
        $this->source->samples['REF-SIBLING'] = $this->source->samples[self::VIN];

        $summary = $this->service->syncAll();

        self::assertSame([3, 1, 1, 1], [$summary['processed'], $summary['succeeded'], $summary['warnings'], $summary['errors']], json_encode($summary));
        self::assertSame(['error', OdometerSourceException::REAUTH_REQUIRED], $this->accountState($brokenAccount));
        self::assertSame([], $this->readings($brokenSibling));
        self::assertSame(['active', null], $this->accountState($fineAccount));
        self::assertSame(['warning', OdometerSourceException::NO_DATA], $this->carState($silent));
        self::assertSame(['success', null], $this->carState($fine));
        self::assertSame('error', $this->carListStatus($brokenSibling));
    }

    public function testDailyCallLimitIsSharedByTheWholeAccount(): void
    {
        $this->source->dailyLimit = 2;
        $cars = [];
        foreach (['REF-1', 'REF-2', 'REF-3'] as $i => $ref) {
            $cars[$this->carWithStart($this->supplierA, 'TST003' . $i, 1000, '2099-03-01')] = $ref;
            $this->source->samples[$ref] = new OdometerSample(1100, OdometerSample::UNIT_KM, new \DateTimeImmutable('2099-03-05T10:00:00Z'));
        }
        $account = $this->connectedAccount($this->supplierA, $cars);

        $summary = $this->service->syncAll();

        self::assertSame(2, $this->source->reads);
        self::assertSame([2, 1], [$summary['succeeded'], $summary['errors']]);
        self::assertSame(['error', OdometerSourceException::RATE_LIMITED], $this->accountState($account));
        self::assertSame(2, $this->accounts->find($this->supplierA, $account)['calls_today']);
    }

    public function testMissingOdometerIsReportedAtTheCar(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0012', 1000, '2099-03-01');
        $account = $this->connectedAccount($this->supplierA, [$car => self::VIN]);

        $this->assertFails(OdometerSourceException::NO_DATA, fn () => $this->service->sync($this->supplierA, $car));
        self::assertSame(['warning', OdometerSourceException::NO_DATA], $this->carState($car));
        self::assertSame(['active', null], $this->accountState($account));
    }

    public function testUnexpectedFailureReportsOnlyItsClass(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0029', 1000, '2099-03-01');
        $account = $this->connectedAccount($this->supplierA, [$car => self::VIN]);
        $this->source->crash = new \RuntimeException('HTTP 401 {"access_token":"synthetic-secret"}');

        $summary = $this->service->syncAll();

        self::assertSame([['account_id' => $account, 'car_id' => null, 'error_code' => 'internal_error',
            'error_class' => \RuntimeException::class]], $summary['details']);
        self::assertStringNotContainsString('synthetic-secret', (string) json_encode($summary));
    }

    public function testDailyRunWithOnlyMissingDataEndsWithoutErrorWhileRevokedVehicleIsAnError(): void
    {
        $silent = $this->carWithStart($this->supplierA, 'TST0027', 1000, '2099-03-01');
        $revoked = $this->carWithStart($this->supplierA, 'TST0028', 1000, '2099-03-01');
        $this->connectedAccount($this->supplierA, [$silent => 'REF-SILENT']);

        $summary = $this->service->syncAll();

        self::assertSame([1, 0, 1, 0], [$summary['processed'], $summary['succeeded'], $summary['warnings'], $summary['errors']], json_encode($summary));
        self::assertSame('warning', $this->carListStatus($silent));

        $this->connectedAccount($this->supplierA, [$revoked => 'REF-REVOKED']);
        $this->source->failFor['REF-REVOKED'] = OdometerSourceException::VEHICLE_NOT_PERMITTED;

        $summary = $this->service->syncAll();

        self::assertSame([2, 0, 1, 1], [$summary['processed'], $summary['succeeded'], $summary['warnings'], $summary['errors']], json_encode($summary));
        self::assertSame(['error', OdometerSourceException::VEHICLE_NOT_PERMITTED], $this->carState($revoked));
        self::assertSame('error', $this->carListStatus($revoked));
    }

    public function testDisconnectingTheLastCarDisconnectsTheAccount(): void
    {
        $first = $this->carWithStart($this->supplierA, 'TST0008', 1000, '2099-03-01');
        $second = $this->carWithStart($this->supplierA, 'TST0023', 1000, '2099-03-01');
        $account = $this->connectedAccount($this->supplierA, [$first => self::VIN, $second => 'REF-2']);

        self::assertTrue($this->service->disconnect($this->supplierA, $first));
        self::assertSame('active', $this->accounts->find($this->supplierA, $account)['status']);
        self::assertTrue($this->service->disconnect($this->supplierA, $second));

        self::assertSame('disconnected', $this->accounts->find($this->supplierA, $account)['status']);
        self::assertNull($this->accountRow($this->supplierA)['credentials_ciphertext']);
        $this->assertFails('not_connected', fn () => $this->service->sync($this->supplierA, $first));
        self::assertSame(0, $this->source->reads);
        self::assertSame(0, $this->service->syncAll()['processed']);
    }

    public function testUnknownSourceIsRejected(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0013', 1000, '2099-03-01');

        $this->assertFails(OdometerSourceException::UNKNOWN_PROVIDER,
            fn () => $this->service->startConnect($this->supplierA, $car, 'nonexistent', [], $this->userId));
        self::assertSame(0, $this->accountCount($this->supplierA));
        $this->expectException(\InvalidArgumentException::class);
        $this->readingRepository->upsert($this->supplierA, ['car_id' => $car, 'reading_date' => '2099-03-05',
            'odometer' => 1100, 'source' => 'nonexistent'], null);
    }

    public function testOtherSupplierCannotSeeOrUseAccount(): void
    {
        $car = $this->carWithStart($this->supplierA, 'TST0007', 1000, '2099-03-01');
        $account = $this->connectedAccount($this->supplierA, [$car => self::VIN]);
        $foreignCar = $this->carWithStart($this->supplierB, 'TST0024', 1000, '2099-03-01');

        self::assertSame(['car' => null, 'account' => null, 'accounts' => []], $this->service->status($this->supplierB, $foreignCar));
        $this->assertFails('not_connected', fn () => $this->service->attach($this->supplierB, $foreignCar, $account, $this->userId));
        $this->assertFails('not_connected', fn () => $this->service->sync($this->supplierB, $car));
        self::assertSame(0, $this->source->reads);
    }

    private function carWithStart(int $supplierId, string $registration, int $odometer, string $date, ?string $vin = null): int
    {
        $car = $this->car($supplierId, $registration);
        $this->pdo->prepare('UPDATE cars SET odometer_start = ?, odometer_start_date = ?, vin = ? WHERE id = ?')
            ->execute([$odometer, $date, $vin, $car]);
        return $car;
    }

    /** @param array<int,string> $cars auto => vehicle_ref */
    private function connectedAccount(int $supplierId, array $cars): int
    {
        $this->pdo->prepare("INSERT INTO odometer_source_accounts (supplier_id, provider, status) VALUES (?, ?, 'active')")
            ->execute([$supplierId, FakeOdometerSource::CODE]);
        $id = (int) $this->pdo->lastInsertId();
        $this->accounts->activate($supplierId, $id, $this->vault->sealCredentials($supplierId, $id, FakeOdometerSource::CODE, ['token' => 'synthetic-token-1']));
        foreach ($cars as $car => $ref) {
            $this->pdo->prepare('INSERT INTO odometer_source_cars (supplier_id, account_id, car_id, vehicle_ref) VALUES (?, ?, ?, ?)')
                ->execute([$supplierId, $id, $car, $ref]);
        }
        return $id;
    }

    private function assertFails(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('Očekávána výjimka ' . $code);
        } catch (OdometerSourceException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    /** @return list<array{string, ?string, int, string}> */
    private function readings(int $car): array
    {
        $stmt = $this->pdo->prepare('SELECT reading_date, reading_time, odometer, source FROM odometer_readings WHERE car_id = ? ORDER BY reading_date, source');
        $stmt->execute([$car]);
        return array_map(static fn (array $r): array => [$r[0], $r[1] !== null ? substr($r[1], 0, 5) : null, (int) $r[2], $r[3]],
            $stmt->fetchAll(\PDO::FETCH_NUM));
    }

    /** @return array{0:?string, 1:?string} */
    private function carState(int $car): array
    {
        $stmt = $this->pdo->prepare('SELECT last_sync_status, last_sync_error_code FROM odometer_source_cars WHERE car_id = ?');
        $stmt->execute([$car]);
        return $stmt->fetch(\PDO::FETCH_NUM);
    }

    /** @return array{0:string, 1:?string} */
    private function accountState(int $account): array
    {
        $a = $this->accounts->find($this->supplierA, $account);
        return [$a['status'], $a['last_sync_error_code']];
    }

    private function carListStatus(int $car): ?string
    {
        foreach ((new CarRepository($this->db))->listForTenant($this->supplierA) as $row) {
            if ((int) $row['id'] === $car) return $row['odometer_source_status'];
        }
        return null;
    }

    /** @return array<string,string|int> */
    private function credentials(int $account): array
    {
        return $this->vault->openCredentials($this->supplierA, $account, FakeOdometerSource::CODE,
            (string) $this->accounts->findWithSecrets($this->supplierA, $account)['credentials_ciphertext']);
    }

    private function accountCount(int $supplierId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM odometer_source_accounts WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function accountRow(int $supplierId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, status, credentials_ciphertext, pending_state_hash FROM odometer_source_accounts WHERE supplier_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
