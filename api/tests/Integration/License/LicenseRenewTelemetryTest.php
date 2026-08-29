<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Service\System\AppUrlConfiguration;
use MyInvoice\Service\System\EnvironmentCheckService;
use MyInvoice\Service\System\InstanceHealthProbe;
use MyInvoice\Service\System\MaintenanceLock;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\System\TelemetryPayloadBuilder;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use MyInvoice\Service\Update\VersionService;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Telemetrie se přiváží s obnovou licence (H-21) — a nesmí ji ohrozit.
 *
 * Obnova licence je to, na čem stojí provoz zákazníka. Diagnostika je to, co
 * chceme my. Tenhle test drží pořadí těch dvou zájmů: telemetrie se přiloží,
 * když je zapnutá, nepřiloží se, když je vypnutá, a když se rozbije, obnova
 * proběhne úplně stejně jako předtím.
 */
#[Group('integration')]
final class LicenseRenewTelemetryTest extends TestCase
{
    use LicenseTokenTrait;

    private Connection $db;
    private LicenseClient $client;
    private string $instanceId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->ping() || !$this->db->hasTable('license')) {
            $this->markTestSkipped('Migrace 1139 (license) neproběhla / DB nedostupná.');
        }

        $this->client = $this->createMock(LicenseClient::class);

        $pdo = $this->db->pdo();
        $pdo->exec('INSERT IGNORE INTO license (id, instance_id, trial_started_at) VALUES (1, UUID(), NOW())');
        $this->instanceId = (string) $pdo->query('SELECT instance_id FROM license WHERE id = 1')->fetchColumn();
        $pdo->beginTransaction();
        $this->inTx = true;
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

    public function testEnabledTelemetryTravelsWithTheRenewCall(): void
    {
        $captured = 'nezavoláno';
        $this->client->expects($this->once())->method('renew')
            ->willReturnCallback(function (...$args) use (&$captured) {
                $captured = $args[7] ?? null;

                return ['ok' => true, 'token' => $this->token(['nonce' => 'nonce-2'])];
            });

        $this->service($this->telemetry(managed: true))->renewIfDue();

        self::assertIsArray($captured, 'Zapnutá telemetrie se má přiložit k obnově licence.');
        self::assertSame(
            TelemetryPayloadBuilder::FIELDS,
            array_keys($captured),
            'Odchází přesně whitelistovaný tvar — nic navíc.',
        );
        self::assertSame(TelemetryPayloadBuilder::PAYLOAD_VERSION, $captured['telemetry_version']);
        self::assertSame(1, (int) $this->row()['last_check_ok']);
    }

    public function testDisabledTelemetrySendsNothing(): void
    {
        $captured = 'nezavoláno';
        $this->client->expects($this->once())->method('renew')
            ->willReturnCallback(function (...$args) use (&$captured) {
                $captured = $args[7] ?? null;

                return ['ok' => true, 'token' => $this->token(['nonce' => 'nonce-2'])];
            });

        $this->service($this->telemetry(managed: true, enabled: false))->renewIfDue();

        self::assertNull($captured, 'Vypnutá telemetrie nesmí poslat ani prázdný objekt.');
        self::assertSame(1, (int) $this->row()['last_check_ok'], 'Licence se obnoví i bez telemetrie.');
    }

    public function testBrokenTelemetryDoesNotBreakTheRenewal(): void
    {
        $token = $this->token(['nonce' => 'nonce-3']);
        $captured = 'nezavoláno';
        $this->client->expects($this->once())->method('renew')
            ->willReturnCallback(function (...$args) use (&$captured, $token) {
                $captured = $args[7] ?? null;

                return ['ok' => true, 'token' => $token];
            });

        // Konfigurace, která na každé čtení vybuchne — nejtvrdší dostupný způsob,
        // jak sběr telemetrie rozbít zevnitř.
        $broken = $this->createStub(Config::class);
        $broken->method('get')->willThrowException(new RuntimeException('konfigurace je rozbitá'));
        $builder = new TelemetryPayloadBuilder(
            $broken,
            $this->probe(new Config([])),
            $this->createStub(VersionService::class),
            new ManagedModeGuard($broken),
        );

        $this->service($builder)->renewIfDue();

        $row = $this->row();
        self::assertNull($captured, 'Rozbitá telemetrie se nepřikládá.');
        self::assertSame($token, $row['token'], 'Obnova licence musí proběhnout stejně jako bez telemetrie.');
        self::assertSame(1, (int) $row['last_check_ok']);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function service(TelemetryPayloadBuilder $telemetry): LicenseService
    {
        $this->prime();

        return new LicenseService(
            $this->db,
            new Config(['license' => ['public_key' => $this->licensePublicKeyBase64()]]),
            new LicenseTokenVerifier(),
            $this->client,
            null,
            null,
            $telemetry,
        );
    }

    private function telemetry(bool $managed, ?bool $enabled = null): TelemetryPayloadBuilder
    {
        $data = ['app' => ['managed' => $managed]];
        if ($enabled !== null) {
            $data['license'] = ['telemetry' => ['enabled' => $enabled]];
        }
        $config = new Config($data);

        $version = $this->createStub(VersionService::class);
        $version->method('getCurrentVersion')->willReturn('5.21.0');

        return new TelemetryPayloadBuilder($config, $this->probe($config), $version, new ManagedModeGuard($config));
    }

    /** Skutečná probe (final, nemockuje se) nad mockovaným prostředím. */
    private function probe(Config $config): InstanceHealthProbe
    {
        $db = $this->createStub(Connection::class);
        $db->method('hasTable')->willReturn(false);
        $db->method('pdo')->willThrowException(new RuntimeException('DB nedostupná'));

        $environment = $this->createStub(EnvironmentCheckService::class);
        $environment->method('migrationStatus')
            ->willReturn(['available' => true, 'applied' => 1512, 'total' => 1512, 'pending' => [], 'pending_count' => 0]);

        $appUrl = new AppUrlConfiguration($config, new HostnameNormalizer(), new NullLogger());

        return new InstanceHealthProbe(
            $db,
            $config,
            new MaintenanceLock($config),
            $appUrl,
            new TenantDomainFeature($config),
            $environment,
        );
    }

    /** Licencovaný řádek s platným tokenem a obnovou „na spadnutí". */
    private function prime(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE license
                SET license_key = ?, token = ?, token_payload = ?, last_nonce = ?,
                    counter = 0, last_check_at = NULL, last_check_ok = 1
              WHERE id = 1'
        )->execute([
            'MYU-TEST-0001-AAAA',
            $this->token(),
            json_encode(['nonce' => 'nonce-1'], JSON_UNESCAPED_UNICODE),
            'nonce-1',
        ]);
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return (array) $this->db->pdo()->query('SELECT * FROM license WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $overrides */
    private function token(array $overrides = []): string
    {
        return $this->signLicenseToken(array_merge([
            'lic'           => 1,
            'iid'           => $this->instanceId,
            'tier'          => 'single',
            'users'         => 3,
            'max_companies' => 5,
            'valid_until'   => time() + 86400,
            'status'        => 'ok',
            'nonce'         => 'nonce-1',
        ], $overrides));
    }
}
