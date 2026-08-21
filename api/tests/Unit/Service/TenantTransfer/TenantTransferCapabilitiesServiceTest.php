<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\TenantTransfer\Capabilities\TenantTransferCapabilitiesService;
use MyInvoice\Service\TenantTransfer\Capabilities\TenantTransferCapabilitiesUnavailable;
use MyInvoice\Service\TenantTransfer\Compatibility\ApplicationVersionProvider;
use MyInvoice\Service\TenantTransfer\Compatibility\BuildRevisionProvider;
use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityFingerprintFactory;
use MyInvoice\Service\TenantTransfer\Compatibility\CompatibilityProfileRegistry;
use MyInvoice\Service\TenantTransfer\Compatibility\InstanceFingerprintProvider;
use MyInvoice\Service\TenantTransfer\Fingerprint\MigrationSetFingerprint;
use MyInvoice\Service\TenantTransfer\Fingerprint\MigrationSetStateProvider;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaFingerprintProvider;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaMetadataSource;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use PDO;
use PHPUnit\Framework\TestCase;

final class TenantTransferCapabilitiesServiceTest extends TestCase
{
    private string $directory;
    private Connection $connection;
    private Config $config;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/myucto-transfer-capabilities-'
            . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        file_put_contents($this->directory . '/VERSION', "5.18.0\n");
        file_put_contents($this->directory . '/0001_init.sql', "SELECT 1;\n");

        $this->config = new Config([
            'app' => [
                'build_revision' => 'git:0123456789abcdef',
                'secret_encryption_key' => base64_encode(str_repeat("\x42", 32)),
                'url' => 'https://sensitive-source.example.test',
            ],
            'db' => ['name' => 'sensitive_database_name'],
        ]);
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE migrations (filename TEXT PRIMARY KEY)');
        $pdo->exec("INSERT INTO migrations (filename) VALUES ('0001_init.sql')");
        $this->connection = new Connection($this->config);
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($this->connection, $pdo);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
    }

    public function testReturnsExactSafeCompatibilityMetadataWithoutOperationalSecrets(): void
    {
        $source = new SyntheticTenantSchemaMetadataSource();
        $payload = $this->service($this->registry(complete: true), $source)
            ->current()
            ->toArray();

        self::assertSame('myucto-tenant-transfer-capabilities', $payload['schema']);
        self::assertSame(1, $payload['schema_version']);
        self::assertSame('myucto', $payload['product']);
        self::assertSame('5.18.0', $payload['app_version']);
        self::assertSame('git:0123456789abcdef', $payload['build_revision']);
        self::assertFalse($payload['pending_migrations']);
        self::assertIsString($payload['migration_set_hash']);
        self::assertMatchesRegularExpression(
            '/^sha256:[a-f0-9]{64}$/D',
            $payload['migration_set_hash'],
        );
        self::assertIsString($payload['tenant_registry_hash']);
        self::assertMatchesRegularExpression(
            '/^sha256:[a-f0-9]{64}$/D',
            $payload['tenant_registry_hash'],
        );
        self::assertIsString($payload['tenant_schema_hash']);
        self::assertMatchesRegularExpression(
            '/^sha256:[a-f0-9]{64}$/D',
            $payload['tenant_schema_hash'],
        );
        self::assertIsString($payload['instance_fingerprint']);
        self::assertMatchesRegularExpression(
            '/^sha256:[a-f0-9]{64}$/D',
            $payload['instance_fingerprint'],
        );
        $compatibilityRegistry = $payload['compatibility_registry'] ?? null;
        self::assertIsArray($compatibilityRegistry);
        self::assertSame(['identity'], $compatibilityRegistry['profiles'] ?? null);
        self::assertSame(['capabilities'], $payload['operations']);
        self::assertFalse($payload['resumable_download']);
        self::assertFalse($payload['cutover_lock']);
        $featureRegistry = $payload['feature_registry'] ?? null;
        self::assertIsArray($featureRegistry);
        self::assertFalse($featureRegistry['complete'] ?? null);
        $secretRegistry = $payload['secret_registry'] ?? null;
        self::assertIsArray($secretRegistry);
        self::assertFalse($secretRegistry['complete'] ?? null);
        self::assertSame([['supplier']], $source->requests);
        self::assertSame(1, $source->inventoryRequests);

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('sensitive-source.example.test', $json);
        self::assertStringNotContainsString('sensitive_database_name', $json);
        $secretKey = $this->config->get('app.secret_encryption_key');
        self::assertIsString($secretKey);
        self::assertStringNotContainsString($secretKey, $json);
    }

    public function testIncompleteRegistryFailsBeforeSchemaOrMigrationMetadataAccess(): void
    {
        $source = new SyntheticTenantSchemaMetadataSource();
        $service = $this->service($this->registry(complete: false), $source);

        try {
            $service->current();
            self::fail('Neúplný registr nesmí zveřejnit capabilities.');
        } catch (TenantTransferCapabilitiesUnavailable $exception) {
            self::assertSame('tenant_registry_incomplete', $exception->reason);
        }
        self::assertSame([], $source->requests);
        self::assertSame(0, $source->inventoryRequests);
    }

    public function testRegisteredSchemaFingerprintChangesWithCanonicalMetadata(): void
    {
        $registry = $this->registry(complete: true);
        $firstSource = new SyntheticTenantSchemaMetadataSource();
        $changedSource = new SyntheticTenantSchemaMetadataSource('bigint unsigned');
        $provider = new TenantSchemaFingerprintProvider($firstSource);

        self::assertNotSame(
            $provider->current($registry),
            (new TenantSchemaFingerprintProvider($changedSource))->current($registry),
        );
        self::assertSame([['supplier']], $firstSource->requests);
    }

    public function testCompleteRegistryStillFailsClosedOnUnregisteredSchemaTable(): void
    {
        $source = new SyntheticTenantSchemaMetadataSource(
            includeUnexpectedTable: true,
        );

        try {
            $this->service($this->registry(complete: true), $source)->current();
            self::fail('Úplný registr nesmí obejít schema coverage kontrolu.');
        } catch (TenantTransferCapabilitiesUnavailable $exception) {
            self::assertSame(
                'tenant_registry_schema_coverage_incomplete',
                $exception->reason,
            );
        }
        self::assertSame(1, $source->inventoryRequests);
        self::assertSame([], $source->requests);
    }

    private function service(
        TenantDataRegistry $registry,
        TenantSchemaMetadataSource $schemaSource,
    ): TenantTransferCapabilitiesService {
        return new TenantTransferCapabilitiesService(
            new ApplicationVersionProvider($this->directory . '/VERSION'),
            new BuildRevisionProvider(
                $this->config,
                $this->directory . '/BUILD_REVISION',
            ),
            new MigrationSetStateProvider(
                $this->connection,
                new MigrationSetFingerprint(),
                $this->directory,
            ),
            $registry,
            new TenantSchemaFingerprintProvider($schemaSource),
            new CompatibilityFingerprintFactory(),
            new InstanceFingerprintProvider($this->config),
            CompatibilityProfileRegistry::v1(),
        );
    }

    private function registry(bool $complete): TenantDataRegistry
    {
        return new TenantDataRegistry(
            1,
            [new TenantDataDefinition(
                'table:supplier',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantRoot,
                [TenantDataRegistry::TRANSFER_PROFILE],
                [
                    'primary_key' => ['id'],
                    'ownership' => [
                        'strategy' => 'selected_supplier',
                        'column' => 'id',
                    ],
                ],
            )],
            $complete ? [TenantDataRegistry::TRANSFER_PROFILE] : [],
        );
    }
}

final class SyntheticTenantSchemaMetadataSource implements TenantSchemaMetadataSource
{
    /** @var list<list<string>> */
    public array $requests = [];

    public int $inventoryRequests = 0;

    public function __construct(
        private readonly string $columnType = 'bigint',
        private readonly bool $includeUnexpectedTable = false,
    ) {}

    public function inventory(): array
    {
        ++$this->inventoryRequests;
        $inventory = [
            new TenantSchemaTableInventory('supplier', 'BASE TABLE', ['id'], ['id'], []),
        ];
        if ($this->includeUnexpectedTable) {
            $inventory[] = new TenantSchemaTableInventory(
                'unexpected_table',
                'BASE TABLE',
                ['id'],
                ['id'],
                [],
            );
        }
        return $inventory;
    }

    public function describe(array $tableNames): array
    {
        $this->requests[] = $tableNames;
        return array_map(fn (string $name): array => [
            'name' => $name,
            'table_type' => 'BASE TABLE',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'columns' => [[
                'name' => 'id',
                'position' => 1,
                'default' => null,
                'nullable' => false,
                'data_type' => 'bigint',
                'column_type' => $this->columnType,
                'character_set' => null,
                'collation' => null,
                'extra' => 'auto_increment',
                'generation_expression' => '',
            ]],
            'indexes' => [[
                'name' => 'PRIMARY',
                'non_unique' => false,
                'position' => 1,
                'column' => 'id',
                'collation' => 'A',
                'prefix_length' => null,
                'nullable' => false,
                'type' => 'BTREE',
                'ignored' => false,
            ]],
            'foreign_keys' => [],
            'checks' => [],
        ], $tableNames);
    }
}
