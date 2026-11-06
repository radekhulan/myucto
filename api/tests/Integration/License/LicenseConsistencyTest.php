<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseConsistency;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Support\Runtime\EnvironmentGuard;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Nezávislé kontroly stavu licence ({@see LicenseConsistency}, {@see EnvironmentGuard})
 * nad skutečným licenčním řádkem: platná licence, bezplatný režim, cizí ověřovací
 * klíč a stav licence tvrdící placené moduly bez platného podpisu.
 */
#[Group('integration')]
final class LicenseConsistencyTest extends TestCase
{
    use LicenseTokenTrait;

    private Connection $db;
    private Config $config;
    private string $instanceId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->db = Bootstrap::buildContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->ping() || !$this->db->hasTable('license') || !$this->db->hasTable('app_meta')) {
            $this->markTestSkipped('Tabulky license/app_meta chybí / DB nedostupná.');
        }

        $this->config = new Config(['license' => ['public_key' => $this->licensePublicKeyBase64()]]);
        $pdo = $this->db->pdo();
        $this->instanceId = (string) $pdo->query('SELECT instance_id FROM license WHERE id = 1')->fetchColumn();
        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare('DELETE FROM app_meta WHERE k = ?')->execute([LicenseConsistency::META_KEY]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testValidLicenseKeepsPaidModulesAvailable(): void
    {
        $this->setRow('MYU-TEST-0001-AAAA', $this->token(['payroll_enabled' => true]));

        $consistency = $this->consistency();
        self::assertNull($consistency->keysetProblem());
        self::assertNull($consistency->refresh('login'));
        self::assertFalse($consistency->isFlagged());
        self::assertTrue($consistency->allowsCommercial());
        self::assertTrue($consistency->allowsPayroll());
        self::assertSame(LicenseState::ACTIVE, $this->service($consistency)->current()->state);
        self::assertTrue((new EnvironmentGuard($this->db, $this->config))->allowsCommercial());
        self::assertTrue((new EnvironmentGuard($this->db, $this->config))->allowsPayroll());
        self::assertSame(204, $this->gateStatus($this->service($consistency), $consistency, '/api/accounting/journal'));
    }

    public function testWithoutTokenFreeModeStaysUsable(): void
    {
        $this->setRow(null, null, date('Y-m-d H:i:s', time() - 90 * 86400));

        $consistency = $this->consistency();
        self::assertNull($consistency->refresh('login'));
        self::assertFalse($consistency->isDegraded());
        self::assertFalse($consistency->allowsCommercial());
        self::assertFalse((new EnvironmentGuard($this->db))->allowsCommercial());

        $service = $this->service($consistency);
        self::assertSame(LicenseState::TRIAL_EXPIRED, $service->current()->state);
        self::assertSame(403, $this->gateStatus($service, $consistency, '/api/accounting/journal'));
        self::assertSame(204, $this->gateStatus($service, $consistency, '/api/invoices'));

        // Zkušební období odemyká vše i bez tokenu, nezávislé kontroly stejně.
        $this->setRow(null, null, date('Y-m-d H:i:s'));
        self::assertTrue($consistency->allowsCommercial());
        self::assertTrue($consistency->allowsPayroll());
        self::assertTrue((new EnvironmentGuard($this->db))->allowsCommercial());
        self::assertSame(204, $this->gateStatus($this->service($consistency), $consistency, '/api/accounting/journal'));
    }

    public function testForeignVerificationKeyDegradesPaidModulesOnly(): void
    {
        $this->setRow('MYU-TEST-0001-AAAA', $this->token(['payroll_enabled' => true]));
        $forged = ['forged' => $this->licensePublicKeyBase64()];

        // Podvržená zabudovaná množina: kontrola při přihlášení vrátí důvod a uloží příznak.
        $tampered = $this->consistency($forged);
        self::assertSame(LicenseConsistency::REASON_KEYSET, $tampered->refresh('login'));
        self::assertFalse($tampered->allowsCommercial());
        self::assertFalse((new EnvironmentGuard($this->db, $this->config, $forged))->allowsCommercial());

        // Příznak pak drží degradovaný stav i pro služby se správnými klíči.
        $consistency = $this->consistency();
        self::assertTrue($consistency->isFlagged());
        self::assertFalse($consistency->allowsCommercial());
        self::assertFalse($consistency->allowsPayroll());
        self::assertFalse((new EnvironmentGuard($this->db, $this->config))->allowsCommercial());
        self::assertFalse((new EnvironmentGuard($this->db, $this->config))->allowsPayroll());

        $service = $this->service($consistency);
        self::assertSame(LicenseState::DEGRADED, $service->current()->state);
        self::assertSame(403, $this->gateStatus($service, $consistency, '/api/accounting/journal'));
        self::assertSame(403, $this->gateStatus($service, $consistency, '/api/payroll/runs'));
        // Bezplatné funkce a zákonná evidence EET (fronta, opakování, opravy) běží dál.
        foreach (['/api/invoices', '/api/eet/queue', '/api/eet/records/7/retry', '/api/eet/records/7/corrections'] as $path) {
            self::assertSame(204, $this->gateStatus($service, $consistency, $path), $path);
        }

        // Po nápravě kontrola příznak sama zruší.
        self::assertNull($consistency->refresh('cron'));
        self::assertFalse($this->consistency()->isFlagged());
        self::assertTrue($this->consistency()->allowsCommercial());
    }

    public function testCommercialStateWithoutValidSignatureIsRejected(): void
    {
        $this->setRow('MYU-TEST-0001-AAAA', $this->token([], $this->foreignSecretKey()));

        $forcedService = $this->createStub(LicenseService::class);
        $forcedService->method('current')->willReturn(new LicenseState(
            LicenseState::ACTIVE, $this->instanceId, 'single', null, 0, 0, 0,
            time() + 86400, null, null, 'MYU-TEST-0001-AAAA', null, true,
            payrollEnabled: true,
        ));

        $consistency = $this->consistency();
        self::assertFalse($consistency->allowsCommercial());
        self::assertFalse((new EnvironmentGuard($this->db, $this->config))->allowsCommercial());
        self::assertSame(403, $this->gateStatus($forcedService, $consistency, '/api/accounting/journal'));
        self::assertSame(403, $this->gateStatus($forcedService, $consistency, '/api/payroll/runs'));
        self::assertSame(204, $this->gateStatus($forcedService, $consistency, '/api/invoices'));

        // Prošlý nebo cizí instanci patřící token neprojde ani s platným podpisem.
        $this->setRow('MYU-TEST-0001-AAAA', $this->token(['valid_until' => time() - 60]));
        self::assertFalse($this->consistency()->allowsCommercial());
        $this->setRow('MYU-TEST-0001-AAAA', $this->token(['iid' => 'jina-instalace']));
        self::assertFalse($this->consistency()->allowsCommercial());
        self::assertFalse((new EnvironmentGuard($this->db, $this->config))->allowsCommercial());
    }

    public function testKeysetFingerprintsAgree(): void
    {
        $raw = [];
        foreach (LicenseService::builtInPublicKeys() as $key) {
            $raw[] = (string) base64_decode($key, true);
        }
        $digest = LicenseConsistency::digest($raw);

        self::assertNull($this->consistency()->keysetProblem());
        self::assertSame($digest, LicenseConsistency::digest(array_reverse($raw)));
        // Rotace: přidaný klíč mění otisk celé množiny.
        $raw[] = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        self::assertNotSame($digest, LicenseConsistency::digest($raw));
    }

    /** @param array<string,string>|null $builtIn */
    private function consistency(?array $builtIn = null): LicenseConsistency
    {
        return new LicenseConsistency($this->db, $this->config, EntityCache::disabled(), new NullLogger(), $builtIn);
    }

    private function service(LicenseConsistency $consistency): LicenseService
    {
        return new LicenseService(
            $this->db,
            $this->config,
            new LicenseTokenVerifier(),
            $this->createStub(LicenseClient::class),
            consistency: $consistency,
        );
    }

    private function gateStatus(LicenseService $service, LicenseConsistency $consistency, string $path): int
    {
        $middleware = new LicenseMiddleware($service, new ResponseFactory(), new NullLogger(), $consistency);
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };

        return $middleware->process($request, $handler)->getStatusCode();
    }

    /** @param array<string,mixed> $overrides */
    private function token(array $overrides = [], ?string $secretKey = null): string
    {
        return $this->signLicenseToken(array_merge([
            'lic'         => 1,
            'iid'         => $this->instanceId,
            'tier'        => 'single',
            'users'       => 3,
            'valid_until' => time() + 86400,
            'status'      => 'ok',
            'nonce'       => 'nonce-1',
        ], $overrides), $secretKey);
    }

    private function setRow(?string $key, ?string $token, ?string $trialStartedAt = null): void
    {
        $this->db->pdo()->prepare(
            'UPDATE license
                SET license_key = ?, token = ?, token_payload = NULL,
                    trial_started_at = COALESCE(?, trial_started_at)
              WHERE id = 1'
        )->execute([$key, $token, $trialStartedAt]);
    }
}
