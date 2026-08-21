<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Compatibility;

/**
 * Bezpečný, verzovaný otisk stavu instance používaný před plánem i exportem.
 * Neobsahuje hostname, název databáze ani jiná provozní metadata instance.
 */
final readonly class CompatibilityFingerprint
{
    public const SCHEMA = 'myucto-tenant-transfer-compatibility';
    public const SCHEMA_VERSION = 1;
    public const PRODUCT = 'myucto';
    public const FORMAT = 'myucto-tenant-transfer';
    public const FORMAT_VERSION = 1;
    public const IDENTITY_PROFILE = 'identity';

    /** @var list<string> */
    private const FIELDS = [
        'schema',
        'schema_version',
        'product',
        'app_version',
        'build_revision',
        'format',
        'format_version',
        'compatibility_profile',
        'pending_migrations',
        'migration_set_hash',
        'tenant_registry_version',
        'tenant_registry_hash',
        'tenant_schema_hash',
    ];

    private function __construct(
        public string $product,
        public string $appVersion,
        public string $buildRevision,
        public string $format,
        public int $formatVersion,
        public string $compatibilityProfile,
        public bool $pendingMigrations,
        public string $migrationSetHash,
        public int $tenantRegistryVersion,
        public string $tenantRegistryHash,
        public string $tenantSchemaHash,
    ) {
    }

    /** @param array<mixed> $payload */
    public static function fromArray(array $payload): self
    {
        self::assertExactFields($payload);
        if ($payload['schema'] !== self::SCHEMA || $payload['schema_version'] !== self::SCHEMA_VERSION) {
            throw new InvalidCompatibilityFingerprint('Fingerprint používá neznámé schema nebo verzi schematu.');
        }

        return new self(
            self::identifier($payload['product'], 'product'),
            self::boundedString($payload['app_version'], 'app_version', 128),
            self::boundedString($payload['build_revision'], 'build_revision', 128),
            self::identifier($payload['format'], 'format'),
            self::positiveInt($payload['format_version'], 'format_version'),
            self::identifier($payload['compatibility_profile'], 'compatibility_profile'),
            self::boolean($payload['pending_migrations'], 'pending_migrations'),
            self::sha256($payload['migration_set_hash'], 'migration_set_hash'),
            self::positiveInt($payload['tenant_registry_version'], 'tenant_registry_version'),
            self::sha256($payload['tenant_registry_hash'], 'tenant_registry_hash'),
            self::sha256($payload['tenant_schema_hash'], 'tenant_schema_hash'),
        );
    }

    /** @return array<string, bool|int|string> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'product' => $this->product,
            'app_version' => $this->appVersion,
            'build_revision' => $this->buildRevision,
            'format' => $this->format,
            'format_version' => $this->formatVersion,
            'compatibility_profile' => $this->compatibilityProfile,
            'pending_migrations' => $this->pendingMigrations,
            'migration_set_hash' => $this->migrationSetHash,
            'tenant_registry_version' => $this->tenantRegistryVersion,
            'tenant_registry_hash' => $this->tenantRegistryHash,
            'tenant_schema_hash' => $this->tenantSchemaHash,
        ];
    }

    /** @param array<mixed> $payload */
    private static function assertExactFields(array $payload): void
    {
        if (array_is_list($payload)) {
            throw new InvalidCompatibilityFingerprint('Fingerprint musí být JSON objekt.');
        }
        $actual = array_keys($payload);
        sort($actual, SORT_STRING);
        $expected = self::FIELDS;
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidCompatibilityFingerprint('Fingerprint neobsahuje přesně pole schematu verze 1.');
        }
    }

    private static function identifier(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new InvalidCompatibilityFingerprint($field . ' nemá bezpečný identifikátor.');
        }
        return $value;
    }

    private static function boundedString(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value)
            || $value === ''
            || $value !== trim($value)
            || strlen($value) > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidCompatibilityFingerprint($field . ' je prázdné, příliš dlouhé nebo nekanonické.');
        }
        return $value;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidCompatibilityFingerprint($field . ' musí být kladné celé číslo.');
        }
        return $value;
    }

    private static function boolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new InvalidCompatibilityFingerprint($field . ' musí být boolean.');
        }
        return $value;
    }

    private static function sha256(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^sha256:[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidCompatibilityFingerprint($field . ' musí být kanonický SHA-256 fingerprint.');
        }
        return $value;
    }
}
