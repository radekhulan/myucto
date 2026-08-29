<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use DateTimeImmutable;
use DateTimeZone;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\AppUrlConfiguration;
use MyInvoice\Service\System\EnvironmentCheckService;
use MyInvoice\Service\System\InstanceHealthProbe;
use MyInvoice\Service\System\MaintenanceLock;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\System\StorageQuotaPolicy;
use MyInvoice\Service\System\StorageQuotaState;
use MyInvoice\Service\System\StorageQuotaStatus;
use MyInvoice\Service\System\StorageUsageSnapshot;
use MyInvoice\Service\System\TelemetryPayloadBuilder;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use MyInvoice\Service\Update\VersionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

/**
 * Obsazení místa v telemetrii (H-10 × H-21).
 *
 * Obsazení instance měří DVĚ strany — hosting (denně, `GET /v1/instances/{id}`)
 * a aplikace ({@see \MyInvoice\Service\System\StorageUsageMeter}, hodinově).
 * Dokud se obě čísla nesejdou na jednom místě, nemá při sporu o kvótu ani jedna
 * strana čím doložit, které platí. Tenhle test hlídá čtyři věci, bez kterých by
 * to porovnání lhalo:
 *
 *  1. **Nová pole prošla whitelistem — a nic mimo něj.** Princip uzavřeného
 *     seznamu {@see TelemetryPayloadBuilder::FIELDS} se rozšířením nesmí
 *     rozvolnit; zdroj hodnot je stejně nedůvěryhodný jako zdravotní souhrn.
 *  2. **`null` zůstane `null` až do payloadu.** „Neměřeno" není nula. Jediný
 *     `(int)` cast by z nezměřené instance udělal „0 bajtů" a porovnání proti
 *     hostingu by hlásilo obří neshodu tam, kde jen chybí měření.
 *  3. **Zálohy nejsou v `usage_bytes`.** Hosting je do kvóty nepočítá a my taky
 *     ne; kdyby vstupovaly, vycházel by rozdíl přesně o jejich velikost.
 *  4. **Telemetrie NESPOUŠTÍ měření.** Veze se s noční obnovou licence a průchod
 *     souborovým stromem by z ní udělal minuty I/O.
 */
final class TelemetryUsagePayloadTest extends TestCase
{
    /** Nová pole, o která payload vyrostl. */
    private const USAGE_FIELDS = [
        'usage_bytes', 'usage_db_bytes', 'usage_files_bytes', 'usage_backup_bytes',
        'usage_measured_at', 'usage_truncated',
        'quota_limit_bytes', 'quota_percent', 'quota_state',
    ];

    private const MB = 1048576;

    // ---- Whitelist ----------------------------------------------------------

    public function testUsageFieldsArePartOfTheWhitelist(): void
    {
        foreach (self::USAGE_FIELDS as $field) {
            self::assertContains(
                $field,
                TelemetryPayloadBuilder::FIELDS,
                "Pole {$field} musí být ve whitelistu, jinak se ven nedostane.",
            );
        }
    }

    public function testNothingOutsideTheWhitelistGetsThroughEvenFromTheUsageSource(): void
    {
        // Přesně ten druh „jen jedno pole navíc", kvůli kterému je to whitelist:
        // zdroj obsazení dostane identifikující údaje na obou úrovních.
        $payload = TelemetryPayloadBuilder::fromSummary([], [], '5.21.0', [
            'state'       => 'ok',
            'usage_bytes' => 123,
            'data_dir'    => 'C:\\inetpub\\wwwroot\\zakaznik\\storage',
            'company'     => 'Účetní Novák s.r.o.',
            'measurement' => [
                'measured'       => true,
                'usage_bytes'    => 123,
                'breakdown'      => ['storage' => 1, '/srv/zakaznik' => 2],
                'largest_file'   => '/srv/zakaznik/backup/myucto-2026-08-21.sql.gz',
                'admin_email'    => 'novak@example.com',
            ],
        ]);

        self::assertSame(
            TelemetryPayloadBuilder::FIELDS,
            array_keys($payload),
            'Payload musí mít přesně whitelistované klíče — nic navíc, nic míň, v daném pořadí.',
        );

        foreach ($payload as $key => $value) {
            self::assertTrue(
                $value === null || is_scalar($value),
                "Pole {$key} musí být skalár nebo null — struktury vozí data, o kterých nikdo nerozhodl.",
            );
        }

        $flat = strtolower(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        foreach (['inetpub', 'zakaznik', 'novak', '/srv/', '.sql', '.gz', 'storage', 'example.com'] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $flat,
                "V payloadu se objevil identifikující údaj „{$needle}\".",
            );
        }
    }

    public function testQuotaStateAcceptsOnlyKnownCodes(): void
    {
        $payload = TelemetryPayloadBuilder::fromSummary([], [], null, [
            'state' => '/srv/zakaznik/storage',
        ]);
        self::assertNull($payload['quota_state'], 'Neznámý kód stavu je „nevím", ne text k přeposlání.');

        foreach (StorageQuotaState::cases() as $case) {
            $payload = TelemetryPayloadBuilder::fromSummary([], [], null, ['state' => $case->value]);
            self::assertSame($case->value, $payload['quota_state']);
        }
    }

    public function testMeasuredAtIsReformattedAndNeverCarriesArbitraryText(): void
    {
        $payload = TelemetryPayloadBuilder::fromSummary([], [], null, $this->statusArray(
            new StorageUsageSnapshot(
                measuredAt: new DateTimeImmutable('2026-08-21 06:15:00', new DateTimeZone('Europe/Prague')),
                usageBytes: 10 * self::MB,
            ),
        ));

        // Pevný tvar v UTC — nic jiného z toho pole vylézt nemůže.
        self::assertSame('2026-08-21T04:15:00Z', $payload['usage_measured_at']);

        $polluted = TelemetryPayloadBuilder::fromSummary([], [], null, [
            'measurement' => ['measured' => true, 'usage_bytes' => 1, 'measured_at' => '/srv/zakaznik/storage'],
        ]);
        self::assertNull($polluted['usage_measured_at'], 'Co nejde přečíst jako čas, je „neměřeno".');
    }

    // ---- null ≠ nula ---------------------------------------------------------

    public function testUnmeasuredUsageStaysNullAllTheWayIntoThePayload(): void
    {
        $payload = TelemetryPayloadBuilder::fromSummary([], [], '5.21.0', $this->statusArray(
            StorageUsageSnapshot::unmeasured(),
            state: StorageQuotaState::UNKNOWN,
            quotaBytes: 2048 * self::MB,
        ));

        foreach (['usage_bytes', 'usage_db_bytes', 'usage_files_bytes', 'usage_backup_bytes',
                  'usage_measured_at', 'usage_truncated', 'quota_percent'] as $field) {
            self::assertArrayHasKey($field, $payload);
            self::assertNull($payload[$field], "Neměřeno musí být v {$field} null, nikdy nula.");
            self::assertNotSame(0, $payload[$field]);
        }

        // Kvóta je konfigurace, ne měření — ta se hlásí i bez čísla.
        self::assertSame(2048 * self::MB, $payload['quota_limit_bytes']);
        self::assertSame('unknown', $payload['quota_state'], 'Nezměřená instance není „ok".');

        // A po zakódování taky — `null`, ne `0` a ne prázdný řetězec.
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        self::assertIsString($json);
        self::assertStringContainsString('"usage_bytes":null', $json);
        self::assertStringContainsString('"usage_measured_at":null', $json);
        self::assertStringContainsString('"usage_truncated":null', $json);
    }

    public function testMissingUsageSourceIsAlsoNullNeverZero(): void
    {
        // Builder bez vyhodnocení kvóty (starší volání, testovací dvojník) —
        // payload drží tvar, hodnoty jsou „nevím".
        $payload = TelemetryPayloadBuilder::fromSummary([], [], '5.21.0');

        self::assertSame(TelemetryPayloadBuilder::FIELDS, array_keys($payload));
        foreach (self::USAGE_FIELDS as $field) {
            self::assertNull($payload[$field], "Bez zdroje obsazení musí být {$field} null.");
        }
    }

    public function testEmptySchemaIsZeroButUnreadableSchemaIsNull(): void
    {
        // Prázdná databáze SMÍ být nula — katalog odpověděl. Nečitelná ne.
        $payload = TelemetryPayloadBuilder::fromSummary([], [], null, $this->statusArray(
            new StorageUsageSnapshot(
                measuredAt:    new DateTimeImmutable('2026-08-21 04:00:00', new DateTimeZone('UTC')),
                databaseBytes: 0,
                filesBytes:    5 * self::MB,
                usageBytes:    5 * self::MB,
            ),
        ));

        self::assertSame(0, $payload['usage_db_bytes']);
        self::assertSame(5 * self::MB, $payload['usage_files_bytes']);
        self::assertNull($payload['usage_backup_bytes'], 'Nezjištěné zálohy nejsou „žádné zálohy".');
    }

    // ---- Zálohy jdou zvlášť ---------------------------------------------------

    public function testBackupsAreReportedSeparatelyAndNeverInUsageBytes(): void
    {
        $db      = 40 * self::MB;
        $files   = 60 * self::MB;
        $backups = 900 * self::MB;

        $payload = TelemetryPayloadBuilder::fromSummary([], [], null, $this->statusArray(
            new StorageUsageSnapshot(
                measuredAt:    new DateTimeImmutable('2026-08-21 04:00:00', new DateTimeZone('UTC')),
                databaseBytes: $db,
                filesBytes:    $files,
                usageBytes:    $db + $files,
                backupBytes:   $backups,
            ),
        ));

        self::assertSame($db + $files, $payload['usage_bytes'], 'Živá data = soubory bez záloh + databáze.');
        self::assertSame($backups, $payload['usage_backup_bytes'], 'Zálohy se posílají zvlášť, jako doklad.');
        self::assertNotSame(
            $db + $files + $backups,
            $payload['usage_bytes'],
            'Instalace se nesmí zamknout vlastními zálohami — a rozdíl proti hostingu nesmí vycházet právě o ně.',
        );
        self::assertSame($payload['usage_db_bytes'] + $payload['usage_files_bytes'], $payload['usage_bytes']);
    }

    // ---- Useknuté měření ------------------------------------------------------

    public function testTruncatedMeasurementIsFlaggedAsALowerBound(): void
    {
        $payload = TelemetryPayloadBuilder::fromSummary([], [], null, $this->statusArray(
            new StorageUsageSnapshot(
                measuredAt: new DateTimeImmutable('2026-08-21 04:00:00', new DateTimeZone('UTC')),
                usageBytes: 700 * self::MB,
                truncated:  true,
            ),
        ));

        self::assertTrue($payload['usage_truncated'], 'Bez příznaku by useknuté měření vypadalo jako rozejitá definice.');
        self::assertSame(700 * self::MB, $payload['usage_bytes']);
    }

    public function testTruncatedIsNullWhenNothingWasMeasured(): void
    {
        // Nezměřený snapshot má `truncated = false`. Kdyby se ten příznak četl
        // přímo, hlásila by nezměřená instance „změřeno celé" o čísle, které
        // vůbec neexistuje — a druhá strana by to brala jako porovnatelné.
        $payload = TelemetryPayloadBuilder::fromSummary([], [], null, $this->statusArray(
            StorageUsageSnapshot::unmeasured(),
            state: StorageQuotaState::UNKNOWN,
        ));

        self::assertNull($payload['usage_truncated']);
        self::assertNotFalse($payload['usage_truncated'], '`false` znamená „změřeno a doběhlo", ne „neměřeno".');
    }

    // ---- Verze tvaru ----------------------------------------------------------

    public function testPayloadVersionWasBumpedForTheNewShape(): void
    {
        self::assertGreaterThanOrEqual(
            2,
            TelemetryPayloadBuilder::PAYLOAD_VERSION,
            'Rozšíření whitelistu musí zvýšit verzi tvaru — jinak server nepozná starší instanci od poruchy.',
        );

        $payload = TelemetryPayloadBuilder::fromSummary([], [], null);
        self::assertSame(TelemetryPayloadBuilder::PAYLOAD_VERSION, $payload['telemetry_version']);
    }

    // ---- Telemetrie neměří ----------------------------------------------------

    public function testBuildReadsTheStoredMeasurementExactlyOnceAndNeverMeasures(): void
    {
        $config = new Config(['app' => ['managed' => true]]);

        $quota = new class ($this->quotaStatus()) extends StorageQuotaPolicy {
            public int $evaluateCalls = 0;

            public function __construct(private readonly StorageQuotaStatus $prepared)
            {
                // Rodičovský konstruktor se ZÁMĚRNĚ nevolá: dvojník nesmí mít
                // meter, kterým by šlo měřit. Kdyby builder sáhl jinam než na
                // `evaluate()`, spadne to tady, ne až v produkci v pět ráno.
            }

            public function evaluate(): StorageQuotaStatus
            {
                $this->evaluateCalls++;

                return $this->prepared;
            }
        };

        $builder = new TelemetryPayloadBuilder(
            $config,
            $this->probe($config),
            $this->createStub(VersionService::class),
            new ManagedModeGuard($config),
            $quota,
        );

        $payload = $builder->build();

        self::assertIsArray($payload);
        self::assertSame(1, $quota->evaluateCalls, 'Obsazení se čte jednou, hotové, z databáze.');
        self::assertSame(123 * self::MB, $payload['usage_bytes']);
        self::assertSame('ok', $payload['quota_state']);
        self::assertSame(12.3, $payload['quota_percent']);
    }

    public function testBuilderSourceContainsNoMeasurementCallAtAll(): void
    {
        // Strukturální pojistka proti budoucí úpravě: `measure()` /
        // `measureIfStale()` procházejí souborový strom. Telemetrie se veze
        // s noční obnovou licence, takže tam ta volání nesmí přibýt ani omylem.
        $file = (new ReflectionClass(TelemetryPayloadBuilder::class))->getFileName();
        self::assertIsString($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        self::assertSame(
            0,
            preg_match('/->\s*measure(IfStale)?\s*\(/', $code),
            'Telemetrie nesmí spustit měření — čte se výhradně hotové číslo přes evaluate()/latest().',
        );
        self::assertStringContainsString('evaluate()', $code);
    }

    public function testBrokenQuotaSourceDegradesToNullsAndNeverThrows(): void
    {
        $config = new Config(['app' => ['managed' => true]]);

        $quota = new class extends StorageQuotaPolicy {
            public function __construct() {}

            public function evaluate(): StorageQuotaStatus
            {
                throw new RuntimeException('měření je rozbité');
            }
        };

        $payload = (new TelemetryPayloadBuilder(
            $config,
            $this->probe($config),
            $this->createStub(VersionService::class),
            new ManagedModeGuard($config),
            $quota,
        ))->build();

        self::assertIsArray($payload, 'Rozbité obsazení nesmí shodit celou telemetrii ani obnovu licence.');
        self::assertSame(TelemetryPayloadBuilder::FIELDS, array_keys($payload));
        foreach (self::USAGE_FIELDS as $field) {
            self::assertNull($payload[$field]);
        }
    }

    // ---- Pomocníci -------------------------------------------------------------

    /**
     * @return array<string,mixed> `StorageQuotaStatus::toArray()` nad daným měřením.
     *
     * ⚠️ Nesmí se jmenovat `status()` — v `PHPUnit\Framework\TestCase` je
     * final a překrytí shodí načtení CELÉ testové sady, ne jen tohohle souboru.
     * Cílený běh přes --filter to nechytí, protože ostatní soubory se nenačítají.
     */
    private function statusArray(
        StorageUsageSnapshot $snapshot,
        StorageQuotaState $state = StorageQuotaState::OK,
        ?float $percent = null,
        ?int $quotaBytes = null,
    ): array {
        return (new StorageQuotaStatus(
            state:           $state,
            percent:         $percent,
            usageBytes:      $snapshot->isMeasured() ? $snapshot->usageBytes : null,
            quotaBytes:      $quotaBytes,
            snapshot:        $snapshot,
            enforceable:     true,
            warnPercent:     StorageQuotaPolicy::DEFAULT_WARN_PERCENT,
            readOnlyPercent: StorageQuotaPolicy::DEFAULT_READ_ONLY_PERCENT,
        ))->toArray();
    }

    private function quotaStatus(): StorageQuotaStatus
    {
        return new StorageQuotaStatus(
            state:      StorageQuotaState::OK,
            percent:    12.3,
            usageBytes: 123 * self::MB,
            quotaBytes: 1000 * self::MB,
            snapshot:   new StorageUsageSnapshot(
                measuredAt:    new DateTimeImmutable('2026-08-21 04:00:00', new DateTimeZone('UTC')),
                databaseBytes: 23 * self::MB,
                filesBytes:    100 * self::MB,
                usageBytes:    123 * self::MB,
                backupBytes:   400 * self::MB,
            ),
            enforceable:     true,
            warnPercent:     StorageQuotaPolicy::DEFAULT_WARN_PERCENT,
            readOnlyPercent: StorageQuotaPolicy::DEFAULT_READ_ONLY_PERCENT,
        );
    }

    /** Skutečná probe nad nedostupnou databází — zdravotní část degraduje na null. */
    private function probe(Config $config): InstanceHealthProbe
    {
        $db = $this->createStub(Connection::class);
        $db->method('hasTable')->willReturn(false);
        $db->method('pdo')->willThrowException(new RuntimeException('DB nedostupná'));

        $environment = $this->createStub(EnvironmentCheckService::class);
        $environment->method('migrationStatus')
            ->willReturn(['available' => false, 'applied' => 0, 'pending' => [], 'pending_count' => null]);

        return new InstanceHealthProbe(
            $db,
            $config,
            new MaintenanceLock($config),
            new AppUrlConfiguration($config, new HostnameNormalizer(), new NullLogger()),
            new TenantDomainFeature($config),
            $environment,
        );
    }
}
