<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\AppUrlConfiguration;
use MyInvoice\Service\System\EnvironmentCheckService;
use MyInvoice\Service\System\InstanceHealthProbe;
use MyInvoice\Service\System\MaintenanceLock;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\System\TelemetryPayloadBuilder;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use MyInvoice\Service\Update\VersionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Brána telemetrie (H-21).
 *
 * Tři věci, které tenhle test hlídá a bez kterých by položka škodila víc,
 * než pomáhá:
 *
 *  1. **Z instance neodejde nic identifikujícího.** Ověřuje se WHITELISTEM —
 *     payload smí mít přesně klíče {@see TelemetryPayloadBuilder::FIELDS},
 *     v tomhle pořadí, a nic navíc. Blacklist („nesmí tam být hostname") by
 *     příští přidané pole propustil.
 *  2. **Vypnutá telemetrie neposílá nic** — ani prázdný objekt.
 *  3. **Sběr nikdy nevyhodí výjimku** — obnova licence na něm nesmí padnout.
 */
final class TelemetryPayloadBuilderTest extends TestCase
{
    /**
     * Souhrn, jaký vrací probe, ale ZÁMĚRNĚ ZAMOŘENÝ identifikujícími údaji na
     * všech úrovních — kdyby projekce cokoli kopírovala „jak to přišlo", tenhle
     * vstup to odhalí.
     *
     * @return array<string,mixed>
     */
    private function pollutedSummary(): array
    {
        return [
            'maintenance' => true,
            'hostname'    => 'ucetni-firma.example.com',
            'data_dir'    => 'C:\\inetpub\\wwwroot\\zakaznik\\storage',
            'company'     => 'Účetní Novák s.r.o.',
            'admin_email' => 'novak@example.com',
            'invoices'    => 4821,
            'jobs'        => ['running' => 3, 'last_error' => 'PDO: Access denied for user zakaznik@10.0.0.7'],
            'cron'        => [
                'mode'               => 'dispatcher',
                'dispatcher_age_sec' => 61,
                'dispatcher_fresh'   => true,
                'dispatcher_status'  => 'ok',
                'last_tick_age_sec'  => 61,
                'log_path'           => '/srv/zakaznik/log/cron/dispatch-2026-08-21.log',
            ],
            'backup' => [
                'age_sec' => 7200,
                'fresh'   => true,
                'file'    => '/srv/zakaznik/backup/myucto-2026-08-21.sql.gz',
            ],
            'migrations' => [
                'applied'    => 1512,
                'pending'    => 2,
                'up_to_date' => false,
                // Názvy nedoběhlých migrací prozrazují, co zákazník používá.
                'pending_files' => ['1513_payroll_x.sql', '1514_secret_feature.sql'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function pollutedManaged(): array
    {
        return [
            'managed'          => true,
            'managed_provider' => 'servermaster',
            'provider_account' => 'zakaznik-4821',
            'ssh_host'         => 'node17.hosting.example.net',
        ];
    }

    public function testPayloadContainsExactlyTheWhitelistedFields(): void
    {
        $payload = TelemetryPayloadBuilder::fromSummary(
            $this->pollutedSummary(),
            $this->pollutedManaged(),
            '5.21.0',
        );

        self::assertSame(
            TelemetryPayloadBuilder::FIELDS,
            array_keys($payload),
            'Payload musí mít přesně whitelistované klíče — nic navíc, nic míň, v daném pořadí.',
        );
    }

    public function testPayloadCarriesNoIdentifyingValue(): void
    {
        $payload = TelemetryPayloadBuilder::fromSummary(
            $this->pollutedSummary(),
            $this->pollutedManaged(),
            '5.21.0',
        );

        foreach ($payload as $key => $value) {
            self::assertTrue(
                $value === null || is_scalar($value),
                "Pole {$key} musí být skalár nebo null — struktury vozí data, o kterých nikdo nerozhodl.",
            );
        }

        $flat = strtolower(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        foreach ([
            'ucetni-firma', 'example.com', 'novak', 'inetpub', 'zakaznik', '/srv/',
            'dispatch-2026', '.sql', '.log', 'node17', '4821', 'access denied',
        ] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $flat,
                "V payloadu se objevil identifikující údaj „{$needle}\".",
            );
        }
    }

    public function testMigrationFilenamesNeverLeakEvenWhenPendingIsAList(): void
    {
        // EnvironmentCheckService::migrationStatus() vrací `pending` jako SEZNAM
        // souborů; probe ho převádí na počet. Kdyby se ta vrstva někdy obešla,
        // projekce musí seznam zahodit, ne ho poslat dál.
        $payload = TelemetryPayloadBuilder::fromSummary(
            ['migrations' => ['applied' => 10, 'pending' => ['1513_a.sql', '1514_b.sql'], 'up_to_date' => false]],
            [],
            '5.21.0',
        );

        self::assertNull($payload['migrations_pending']);
        self::assertStringNotContainsString('.sql', json_encode($payload) ?: '');
    }

    public function testPayloadIsVersionedAndMapsHealthValues(): void
    {
        $payload = TelemetryPayloadBuilder::fromSummary(
            $this->pollutedSummary(),
            $this->pollutedManaged(),
            '5.21.0',
        );

        self::assertSame(TelemetryPayloadBuilder::PAYLOAD_VERSION, $payload['telemetry_version']);
        self::assertSame('5.21.0', $payload['app_version']);
        self::assertSame(1512, $payload['migrations_applied']);
        self::assertSame(2, $payload['migrations_pending']);
        self::assertFalse($payload['migrations_up_to_date']);
        self::assertSame(7200, $payload['backup_age_sec']);
        self::assertTrue($payload['backup_fresh']);
        self::assertSame(61, $payload['dispatcher_age_sec']);
        self::assertTrue($payload['dispatcher_fresh']);
        self::assertSame('dispatcher', $payload['cron_mode']);
        self::assertTrue($payload['maintenance']);
        self::assertTrue($payload['managed']);
        self::assertSame('servermaster', $payload['managed_provider']);
    }

    public function testUnavailableProbeKeepsTheShapeWithNulls(): void
    {
        // Monitoring nesmí rozlišovat „chybí klíč" a „neznámá hodnota" —
        // stejné pravidlo, jaké má /api/health.
        $payload = TelemetryPayloadBuilder::fromSummary(
            InstanceHealthProbe::unavailableSummary(),
            ['managed' => false, 'managed_provider' => null],
            null,
        );

        self::assertSame(TelemetryPayloadBuilder::FIELDS, array_keys($payload));
        self::assertNull($payload['app_version']);
        self::assertNull($payload['migrations_applied']);
        self::assertNull($payload['dispatcher_age_sec']);
        self::assertFalse($payload['maintenance']);
    }

    // ---- Přepínač -----------------------------------------------------------

    public function testDisabledTelemetrySendsNothing(): void
    {
        $builder = $this->builder(['app' => ['managed' => true], 'license' => ['telemetry' => ['enabled' => false]]]);

        self::assertFalse($builder->isEnabled());
        self::assertNull($builder->build(), 'Vypnutá telemetrie nesmí poslat ani prázdný objekt.');
    }

    public function testDefaultsOnInManagedModeAndOffWhenSelfHosted(): void
    {
        self::assertTrue(
            $this->builder(['app' => ['managed' => true]])->isEnabled(),
            'Ve spravovaném režimu je telemetrie výchozím stavem zapnutá.',
        );
        self::assertFalse(
            $this->builder([])->isEnabled(),
            'Self-hosted instalace telemetrii výchozím stavem neposílá.',
        );
        self::assertNull($this->builder([])->build());
    }

    public function testExplicitSwitchWinsOverManagedDefaultAndAcceptsEnvStrings(): void
    {
        self::assertTrue($this->builder(['license' => ['telemetry' => ['enabled' => '1']]])->isEnabled());
        self::assertTrue($this->builder(['license' => ['telemetry' => ['enabled' => 'true']]])->isEnabled());
        self::assertFalse(
            $this->builder(['app' => ['managed' => true], 'license' => ['telemetry' => ['enabled' => '0']]])->isEnabled(),
            'Vypnout telemetrii musí jít i ve spravovaném režimu.',
        );
        self::assertFalse($this->builder(['license' => ['telemetry' => ['enabled' => 'false']]])->isEnabled());
    }

    public function testBuildNeverThrows(): void
    {
        // Licence je to, na čem stojí provoz zákazníka; diagnostika je to, co
        // chceme my. Když se sběr rozbije, smí z toho vzniknout nanejvýš null.
        $config = $this->createStub(Config::class);
        $config->method('get')->willThrowException(new RuntimeException('konfigurace je rozbitá'));

        $builder = new TelemetryPayloadBuilder(
            $config,
            $this->probe(new Config(['app' => ['managed' => true]])),
            $this->createStub(VersionService::class),
            new ManagedModeGuard($config),
        );

        self::assertNull($builder->build());
    }

    public function testBuildReturnsWhitelistShapeOverALiveProbe(): void
    {
        $config = new Config(['app' => ['managed' => true, 'managed_provider' => 'servermaster']]);

        $environment = $this->createStub(EnvironmentCheckService::class);
        $environment->method('migrationStatus')->willReturn([
            'available'     => true,
            'applied'       => 1512,
            'total'         => 1514,
            'pending'       => ['1513_a.sql', '1514_b.sql'],
            'pending_count' => 2,
        ]);

        $version = $this->createStub(VersionService::class);
        $version->method('getCurrentVersion')->willReturn('5.21.0');

        $builder = new TelemetryPayloadBuilder(
            $config,
            $this->probe($config, $environment),
            $version,
            new ManagedModeGuard($config),
        );

        $payload = $builder->build();

        self::assertIsArray($payload);
        self::assertSame(TelemetryPayloadBuilder::FIELDS, array_keys($payload));
        self::assertSame('5.21.0', $payload['app_version']);
        self::assertSame(1512, $payload['migrations_applied']);
        self::assertSame(2, $payload['migrations_pending']);
        self::assertFalse($payload['migrations_up_to_date']);
        self::assertTrue($payload['managed']);
        self::assertSame('servermaster', $payload['managed_provider']);
        // Databáze není k dispozici → hodnoty z ní jsou „nevím", ne nula.
        self::assertNull($payload['backup_age_sec']);
        self::assertNull($payload['dispatcher_age_sec']);
    }

    // ---- Pomocníci ----------------------------------------------------------

    /** @param array<string,mixed> $cfg */
    private function builder(array $cfg): TelemetryPayloadBuilder
    {
        $config = new Config($cfg);

        return new TelemetryPayloadBuilder(
            $config,
            $this->probe($config),
            $this->createStub(VersionService::class),
            new ManagedModeGuard($config),
        );
    }

    /**
     * Skutečná probe (final, nemockuje se) nad nedostupnou databází. Sběr z DB
     * tím degraduje na null, což je přesně stav, ve kterém musí payload pořád
     * držet tvar.
     */
    private function probe(Config $config, ?EnvironmentCheckService $environment = null): InstanceHealthProbe
    {
        $db = $this->createStub(Connection::class);
        $db->method('hasTable')->willReturn(false);
        $db->method('pdo')->willThrowException(new RuntimeException('DB nedostupná'));

        if ($environment === null) {
            $environment = $this->createStub(EnvironmentCheckService::class);
            $environment->method('migrationStatus')
                ->willReturn(['available' => false, 'applied' => 0, 'pending' => [], 'pending_count' => null]);
        }

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
}
