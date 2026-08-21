<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\CanonicalJson;

/**
 * Verzionovaný SSOT klasifikace tenantových objektů.
 *
 * Registr odděluje rozpracovanou inventuru od profilu označeného jako úplný.
 * Transfer fingerprint pro neúplný profil nevydá, takže dílčí katalog nelze
 * omylem prezentovat jako bezeztrátový přenos.
 */
final class TenantDataRegistry
{
    public const FORMAT = 'myucto-tenant-data-registry';
    public const TRANSFER_PROFILE = 'tenant_transfer';
    public const ACCOUNTING_ARCHIVE_PROFILE = 'accounting_archive';

    /** @var array<string,TenantDataDefinition> */
    private array $definitions = [];

    /** @var array<string,true> */
    private array $completeProfiles = [];

    /**
     * @param array<mixed> $definitions
     * @param array<mixed> $completeProfiles
     */
    public function __construct(
        public readonly int $version,
        array $definitions,
        array $completeProfiles = [],
    ) {
        if ($version < 1) {
            throw new \InvalidArgumentException('Verze tenantového registru musí být kladná.');
        }
        foreach ($definitions as $definition) {
            if (!$definition instanceof TenantDataDefinition) {
                throw new \InvalidArgumentException('Tenantový registr obsahuje neplatnou definici.');
            }
            $foldedKey = strtolower($definition->key);
            if (isset($this->definitions[$foldedKey])) {
                throw new \InvalidArgumentException('Tenantový registr obsahuje duplicitní klíč.');
            }
            $this->definitions[$foldedKey] = $definition;
        }
        foreach ($completeProfiles as $profileValue) {
            $profile = $this->validatedProfileIdentifier($profileValue);
            if (isset($this->completeProfiles[$profile])) {
                throw new \InvalidArgumentException('Tenantový registr obsahuje duplicitní úplný profil.');
            }
            if ($this->definitionsFor($profile) === []) {
                throw new \InvalidArgumentException('Prázdný profil tenantového registru nelze označit jako úplný.');
            }
            $this->completeProfiles[$profile] = true;
        }
    }

    public function isComplete(string $profile): bool
    {
        $this->validatedProfileIdentifier($profile);
        return isset($this->completeProfiles[$profile]);
    }

    /** @return list<TenantDataDefinition> */
    public function definitionsFor(string $profile): array
    {
        $this->validatedProfileIdentifier($profile);
        $definitions = array_values(array_filter(
            $this->definitions,
            static fn (TenantDataDefinition $definition): bool => in_array($profile, $definition->profiles, true),
        ));
        usort(
            $definitions,
            static fn (TenantDataDefinition $left, TenantDataDefinition $right): int => strcmp($left->key, $right->key),
        );
        return $definitions;
    }

    public function fingerprintFor(string $profile): string
    {
        if (!$this->isComplete($profile)) {
            throw new IncompleteTenantDataRegistry(
                'Tenantový registr pro profil ' . $profile . ' není označen jako úplný.',
            );
        }
        return CanonicalJson::sha256([
            'format' => self::FORMAT,
            'version' => $this->version,
            'profile' => $profile,
            'definitions' => array_map(
                static fn (TenantDataDefinition $definition): array => $definition->toArray(),
                $this->definitionsFor($profile),
            ),
        ]);
    }

    private function validatedProfileIdentifier(mixed $profile): string
    {
        if (!is_string($profile) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $profile) !== 1) {
            throw new \InvalidArgumentException('Profil tenantového registru nemá bezpečný identifikátor.');
        }
        return $profile;
    }
}
