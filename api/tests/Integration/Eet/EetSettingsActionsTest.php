<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eet;

use MyInvoice\Action\Eet\EetCapabilitiesAction;
use MyInvoice\Action\Eet\EetCertificatesAction;
use MyInvoice\Action\Eet\EetDevicesAction;
use MyInvoice\Action\Eet\EetSettingsAction;
use MyInvoice\Action\Eet\EetUnitsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Eet\Certificate\EetCertificateInspector;
use MyInvoice\Service\Eet\Transport\EetEnvironment;
use MyInvoice\Service\Eet\Transport\EetOutcome;
use MyInvoice\Service\Eet\Transport\FakeEetTransport;
use MyInvoice\Service\Eet\EetCertificateVault;
use MyInvoice\Service\Eet\EetDeviceRepository;
use MyInvoice\Service\Eet\EetDiagnosticsService;
use MyInvoice\Service\Eet\EetException;
use MyInvoice\Service\Eet\EetSettingsService;
use MyInvoice\Service\Eet\EetUnitRepository;
use MyInvoice\Service\Epo\EpoStepUpService;
use MyInvoice\Service\License\CommercialFeatureAccess;
use MyInvoice\Tests\Support\EetTestPki;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

#[Group('integration')]
final class EetSettingsActionsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $userId = 0;
    private EetCertificateVault $vault;
    private EetSettingsService $settings;
    private EetUnitsAction $unitsAction;
    private EetDevicesAction $devicesAction;
    private EetSettingsAction $settingsAction;
    private EetCertificatesAction $certificatesAction;
    private EetCapabilitiesAction $capabilitiesAction;
    private CommercialFeatureAccess $commercial;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $secrets = $c->get(SecretEncryption::class);
        } catch (\Throwable $e) {
            self::markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if ($secrets->validateKey() !== null) {
            self::markTestSkipped('Šifrovací klíč není nastavený.');
        }
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí supplier/user.');
        }
        $this->supplierA = $this->createIsolatedSupplier($pdo, $source);
        $this->supplierB = $this->createIsolatedSupplier($pdo, $source);

        $units = new EetUnitRepository($this->db);
        $devices = new EetDeviceRepository($this->db);
        $this->vault = new EetCertificateVault($this->db, $secrets, new EetCertificateInspector(EetTestPki::trustStore()));
        $this->settings = new EetSettingsService($this->db, $units, $devices, $this->vault);
        $logger = $c->get(ActivityLogger::class);
        $this->commercial = $c->get(CommercialFeatureAccess::class);

        $this->unitsAction = new EetUnitsAction($units, $logger);
        $this->devicesAction = new EetDevicesAction($devices, $c->get(TenantReferenceGuard::class), $logger);
        $this->settingsAction = new EetSettingsAction($this->settings, $this->commercial, $logger);
        $this->certificatesAction = new EetCertificatesAction($this->vault, $this->settings, $c->get(EpoStepUpService::class), $logger);
        $this->capabilitiesAction = new EetCapabilitiesAction($this->settings, $this->vault, $this->commercial, $devices);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ([$this->supplierA, $this->supplierB] as $supplierId) {
            if ($supplierId > 0) {
                $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$supplierId]);
            }
        }
        $this->db->close();
    }

    public function testUnitsDevicesAndCertificatesOfAnotherCompanyAreNotFound(): void
    {
        [$unit, $device] = $this->createUnitAndDevice($this->supplierA);
        $certificate = $this->vault->import($this->supplierA, 'playground', EetTestPki::pkcs12(), EetTestPki::PASSWORD, null, $this->userId);

        $asB = fn (string $http, array $body = []) => $this->req($http, 'admin', $this->supplierB, $body);
        self::assertSame(404, $this->code($this->unitsAction->update($asB('PUT', ['name' => 'Cizí']), new Psr7Response(), ['id' => (string) $unit['id']])));
        self::assertSame(404, $this->code($this->unitsAction->endValidity($asB('DELETE'), new Psr7Response(), ['id' => (string) $unit['id']])));
        self::assertSame(404, $this->code($this->devicesAction->update($asB('PUT', ['name' => 'Cizí']), new Psr7Response(), ['id' => (string) $device['id']])));
        self::assertSame(404, $this->code($this->devicesAction->deactivate($asB('DELETE'), new Psr7Response(), ['id' => (string) $device['id']])));
        $foreignDeactivate = $this->code($this->certificatesAction->deactivate($asB('POST'), new Psr7Response(), ['id' => (string) $certificate['id']]));
        self::assertGreaterThanOrEqual(400, $foreignDeactivate);
        self::assertLessThan(500, $foreignDeactivate);
        self::assertSame('active', $this->vault->find($this->supplierA, $certificate['id'])['status']);

        // Zařízení firmy B nesmí ukazovat na jednotku firmy A.
        $foreignUnit = $this->decode($this->devicesAction->create($asB('POST', ['unit_id' => $unit['id'], 'code' => 'B-1', 'name' => 'B']), new Psr7Response()));
        self::assertSame(422, $foreignUnit['status']);
        self::assertSame('eet_validation', $foreignUnit['body']['error']['code'] ?? null);

        self::assertSame([], $this->decode($this->unitsAction->list($asB('GET'), new Psr7Response()))['body']['items']);
        self::assertSame([], $this->decode($this->certificatesAction->list($asB('GET'), new Psr7Response()))['body']['items']);

        $own = $this->decode($this->devicesAction->list($this->req('GET', 'admin', $this->supplierA), new Psr7Response()));
        self::assertSame([$device['id']], array_column($own['body']['items'], 'id'));
    }

    public function testAccountantReadsSettingsButCannotChangeThemOrSeeCertificates(): void
    {
        $read = $this->decode($this->settingsAction->show($this->req('GET', 'accountant', $this->supplierA), new Psr7Response()));
        self::assertSame(200, $read['status']);
        self::assertFalse($read['body']['settings']['enabled']);
        self::assertSame(0, $read['body']['settings']['row_version']);

        self::assertSame(403, $this->code($this->settingsAction->update($this->req('PUT', 'accountant', $this->supplierA, ['eic' => 'CZ00000019']), new Psr7Response())));
        self::assertSame(403, $this->code($this->certificatesAction->list($this->req('GET', 'accountant', $this->supplierA), new Psr7Response())));

        $capabilities = $this->decode(($this->capabilitiesAction)($this->req('GET', 'accountant', $this->supplierA), new Psr7Response()));
        self::assertSame(200, $capabilities['status']);
        self::assertSame('read', $capabilities['body']['permissions']['eet.settings']);
        self::assertSame('none', $capabilities['body']['permissions']['eet.certificates']);
        self::assertSame('write', $capabilities['body']['permissions']['eet.accept_payment']);
        self::assertContains('foreign_currency', $capabilities['body']['supported_scenarios']);
        self::assertNotContains('foreign_currency', $capabilities['body']['unsupported_scenarios']);
    }

    public function testCertificateUploadNeedsSessionAndStepUp(): void
    {
        $body = ['pkcs12_base64' => base64_encode(EetTestPki::pkcs12()), 'pfx_password' => EetTestPki::PASSWORD, 'environment' => 'playground'];

        $bearer = $this->decode($this->certificatesAction->upload($this->req('POST', 'admin', $this->supplierA, $body, 'bearer'), new Psr7Response()));
        self::assertSame(403, $bearer['status']);
        self::assertSame('session_required', $bearer['body']['error']['code'] ?? null);

        $noStepUp = $this->decode($this->certificatesAction->upload($this->req('POST', 'admin', $this->supplierA, $body), new Psr7Response()));
        self::assertGreaterThanOrEqual(400, $noStepUp['status']);
        self::assertLessThan(500, $noStepUp['status']);

        self::assertSame([], $this->vault->listMetadata($this->supplierA), 'bez ověření se nic neuloží');
        self::assertStringNotContainsString(EetTestPki::PASSWORD, json_encode($noStepUp['body']) ?: '');
    }

    public function testCertificateDeactivationNeedsSessionAndStepUp(): void
    {
        $certificate = $this->vault->import($this->supplierA, 'playground', EetTestPki::pkcs12(), EetTestPki::PASSWORD, null, $this->userId);
        $args = ['id' => (string) $certificate['id']];

        $bearer = $this->decode($this->certificatesAction->deactivate($this->req('POST', 'admin', $this->supplierA, [], 'bearer'), new Psr7Response(), $args));
        self::assertSame(403, $bearer['status']);
        self::assertSame('session_required', $bearer['body']['error']['code'] ?? null);

        $noStepUp = $this->decode($this->certificatesAction->deactivate($this->req('POST', 'admin', $this->supplierA), new Psr7Response(), $args));
        self::assertGreaterThanOrEqual(400, $noStepUp['status']);
        self::assertLessThan(500, $noStepUp['status']);

        $wrongPassword = $this->decode($this->certificatesAction->deactivate(
            $this->req('POST', 'admin', $this->supplierA, ['password' => 'Wrong-password-1']),
            new Psr7Response(),
            $args,
        ));
        self::assertGreaterThanOrEqual(400, $wrongPassword['status']);
        self::assertLessThan(500, $wrongPassword['status']);

        self::assertSame('active', $this->vault->find($this->supplierA, $certificate['id'])['status'], 'bez ověření se certifikát nedeaktivuje');
    }

    public function testEnablingWithoutCertificateIsRefusedAndNothingChanges(): void
    {
        if (!$this->commercial->isAvailable()) {
            self::markTestSkipped('Testovací instalace nemá komerční funkce.');
        }
        $this->createUnitAndDevice($this->supplierA);
        $response = $this->decode($this->settingsAction->update($this->req('PUT', 'admin', $this->supplierA, [
            'eic' => 'CZ00000019',
            'active_from' => '2026-01-01',
            'enabled' => true,
        ]), new Psr7Response()));

        self::assertSame(422, $response['status']);
        self::assertSame('eet_not_configured', $response['body']['error']['code'] ?? null);
        self::assertSame(['certificate_missing'], array_column($response['body']['error']['obstacles'] ?? [], 'code'));
        self::assertFalse($this->settings->get($this->supplierA)['enabled']);
        self::assertNull($this->settings->get($this->supplierA)['eic'], 'odmítnutá změna se nesmí uložit ani zčásti');
    }

    public function testImportedCertificateIsEncryptedNeverReturnedAndUnlocksActivation(): void
    {
        if (!$this->commercial->isAvailable()) {
            self::markTestSkipped('Testovací instalace nemá komerční funkce.');
        }
        [, $device] = $this->createUnitAndDevice($this->supplierA);
        $p12 = EetTestPki::pkcs12('playground', 'CZ00000019');
        $certificate = $this->vault->import($this->supplierA, 'playground', $p12, EetTestPki::PASSWORD, 'Pokladna', $this->userId);

        $stmt = $this->db->pdo()->prepare('SELECT p12_enc, password_enc FROM eet_certificates WHERE id = ?');
        $stmt->execute([$certificate['id']]);
        $stored = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertStringStartsWith('enc:v2:', $stored['p12_enc']);
        self::assertStringStartsWith('enc:v2:', $stored['password_enc']);
        $secrets = Bootstrap::buildContainer()->get(SecretEncryption::class);
        $context = EetCertificateVault::context($this->supplierA, $certificate['fingerprint_sha256'], 'password');
        self::assertSame(EetTestPki::PASSWORD, $secrets->decryptFor($stored['password_enc'], $context));

        $list = $this->decode($this->certificatesAction->list($this->req('GET', 'admin', $this->supplierA), new Psr7Response()));
        $json = json_encode($list['body']) ?: '';
        foreach (['p12_enc', 'password_enc', EetTestPki::PASSWORD, substr(base64_encode($p12), 0, 40), 'enc:v2:'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertSame('playground', $list['body']['items'][0]['environment']);
        self::assertGreaterThan(20, $list['body']['items'][0]['expires_in_days']);

        $enabled = $this->decode($this->settingsAction->update($this->req('PUT', 'admin', $this->supplierA, [
            'eic' => 'CZ00000019',
            'active_from' => '2026-01-01',
            'default_device_id' => $device['id'],
            'enabled' => true,
            'row_version' => 0,
        ]), new Psr7Response()));
        self::assertSame(200, $enabled['status'], json_encode($enabled['body']) ?: '');
        self::assertTrue($enabled['body']['settings']['enabled']);
        self::assertSame([], $enabled['body']['activation_obstacles']);

        $stale = $this->decode($this->settingsAction->update($this->req('PUT', 'admin', $this->supplierA, ['regime' => 'standard', 'row_version' => 0]), new Psr7Response()));
        self::assertSame(409, $stale['status']);

        $capabilities = $this->decode(($this->capabilitiesAction)($this->req('GET', 'admin', $this->supplierA), new Psr7Response()))['body'];
        self::assertTrue($capabilities['has_active_certificate']);
        self::assertSame($device['id'], $capabilities['default_device_id']);
        self::assertSame($certificate['not_after'], $capabilities['certificate_expires_at']);

        $history = $this->settings->history($this->supplierA);
        self::assertCount(1, $history);
        self::assertStringNotContainsString(EetTestPki::PASSWORD, json_encode($history) ?: '');
    }

    public function testCertificateMustMatchEicAndEnvironment(): void
    {
        $this->settings->update($this->supplierA, ['eic' => 'CZ12345678'], $this->userId);

        $this->assertImportFails('eet_certificate_eic_mismatch', 'playground', EetTestPki::pkcs12('playground', 'CZ00000019'));
        $this->assertImportFails('eet_certificate_environment', 'playground', EetTestPki::pkcs12('production', 'CZ12345678'));
        $this->assertImportFails('eet_certificate_environment', 'production', EetTestPki::pkcs12('playground', 'CZ12345678'));

        $ok = $this->vault->import($this->supplierA, 'production', EetTestPki::pkcs12('production', 'CZ12345678'), EetTestPki::PASSWORD, null, null);
        self::assertSame('production', $ok['environment']);
        self::assertNull($this->vault->activeMetadata($this->supplierA, 'playground'));

        $this->vault->deactivate($this->supplierA, $ok['id'], null);
        self::assertNull($this->vault->activeMetadata($this->supplierA, 'production'));
        self::assertSame('retired', $this->vault->find($this->supplierA, $ok['id'])['status']);
    }

    public function testDiagnosticsUsesDeviceAndReportsVerificationResult(): void
    {
        [, $device] = $this->createUnitAndDevice($this->supplierA);
        $this->vault->import($this->supplierA, 'playground', EetTestPki::pkcs12('playground', 'CZ00000019'), EetTestPki::PASSWORD, null, null);
        $this->settings->update($this->supplierA, ['eic' => 'CZ00000019'], $this->userId);

        $transport = new FakeEetTransport();
        $service = new EetDiagnosticsService(
            $this->settings,
            new EetDeviceRepository($this->db),
            $this->vault,
            $transport,
            Bootstrap::buildContainer()->get(ActivityLogger::class),
        );

        $result = $service->run($this->supplierA, null, $this->userId);
        self::assertTrue($result['ok']);
        self::assertSame(0, $result['error_code']);
        self::assertSame('playground', $result['environment']);
        self::assertSame($device['id'], $result['device_id']);
        self::assertSame('verification_ok', $result['outcome']);
        $call = $transport->lastCall();
        self::assertSame('POK-1', $call['data']->deviceCode);
        self::assertSame(101, $call['data']->unitId);
        self::assertSame(EetEnvironment::Playground, $call['environment']);

        // Kód 0 v odpovědi, která neprošla ověřením (např. cizí UUID), není úspěch.
        $transport->queueOutcome(EetOutcome::InvalidResponse, 0, 'Chybová odpověď nepatří k odeslané zprávě (jiné UUID).');
        $invalid = $service->run($this->supplierA, null, $this->userId);
        self::assertFalse($invalid['ok']);
        self::assertSame('invalid_response', $invalid['outcome']);

        try {
            $service->run($this->supplierB, null, $this->userId);
            self::fail('Firma bez nastavení nesmí diagnostiku spustit.');
        } catch (EetException $exception) {
            self::assertSame('eet_not_configured', $exception->errorCode);
        }
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function createUnitAndDevice(int $supplierId): array
    {
        $unit = $this->decode($this->unitsAction->create($this->req('POST', 'admin', $supplierId, [
            'unit_number' => 101, 'name' => 'Provozovna', 'kind' => 'premises', 'valid_from' => '2026-01-01',
        ]), new Psr7Response()));
        self::assertSame(201, $unit['status'], json_encode($unit['body']) ?: '');
        $device = $this->decode($this->devicesAction->create($this->req('POST', 'admin', $supplierId, [
            'unit_id' => $unit['body']['id'], 'code' => 'POK-1', 'name' => 'Pokladna 1',
        ]), new Psr7Response()));
        self::assertSame(201, $device['status'], json_encode($device['body']) ?: '');
        return [$unit['body'], $device['body']];
    }

    private function assertImportFails(string $code, string $environment, string $p12): void
    {
        try {
            $this->vault->import($this->supplierA, $environment, $p12, EetTestPki::PASSWORD, null, null);
            self::fail("Očekávaná chyba {$code} nenastala.");
        } catch (EetException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    /** @param array<string,mixed> $body */
    private function req(string $http, string $role, int $supplierId, array $body = [], string $method = 'session'): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($http, '/api/eet', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method);
        return $body === [] ? $request : $request->withParsedBody($body);
    }

    private function code(ResponseInterface $response): int
    {
        return $response->getStatusCode();
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
