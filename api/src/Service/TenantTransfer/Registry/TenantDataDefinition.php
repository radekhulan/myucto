<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

use MyInvoice\Service\TenantTransfer\Fingerprint\CanonicalJson;

/** Jedna explicitně klasifikovaná tabulka nebo souborová oblast registru. */
final readonly class TenantDataDefinition
{
    /** @var list<string> */
    public array $profiles;

    /** @var array<string,mixed> */
    public array $details;

    /**
     * `details` je verzovaná deklarace vlastnického selektoru, klíčů,
     * referencí, secrets a post-import invariantů. Smí obsahovat jen datové
     * typy podporované CanonicalJson, nikdy closures nebo runtime objekty.
     *
     * @param array<mixed> $profiles
     * @param array<mixed> $details
     */
    public function __construct(
        public string $key,
        public TenantDataObjectKind $kind,
        public TenantDataPolicy $policy,
        array $profiles,
        array $details,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Objekt tenantového registru nemá bezpečný klíč.');
        }
        if (!array_is_list($profiles) || $profiles === []) {
            throw new \InvalidArgumentException('Objekt tenantového registru musí patřit alespoň do jednoho profilu.');
        }
        $seen = [];
        $validatedProfiles = [];
        foreach ($profiles as $profile) {
            if (!is_string($profile) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $profile) !== 1) {
                throw new \InvalidArgumentException('Profil tenantového registru nemá bezpečný identifikátor.');
            }
            if (isset($seen[$profile])) {
                throw new \InvalidArgumentException('Objekt tenantového registru obsahuje duplicitní profil.');
            }
            $seen[$profile] = true;
            $validatedProfiles[] = $profile;
        }
        if (array_is_list($details) && $details !== []) {
            throw new \InvalidArgumentException('Detaily objektu tenantového registru musí být JSON objekt.');
        }
        $validatedDetails = [];
        foreach ($details as $field => $value) {
            if (!is_string($field)) {
                throw new \InvalidArgumentException('Klíč detailu tenantového registru musí být řetězec.');
            }
            $validatedDetails[$field] = $value;
        }
        CanonicalJson::encode($validatedDetails);
        $this->profiles = $validatedProfiles;
        $this->details = $validatedDetails;
    }

    /** @return array{key:string,kind:string,policy:string,profiles:list<string>,details:array<string,mixed>} */
    public function toArray(): array
    {
        $profiles = $this->profiles;
        sort($profiles, SORT_STRING);
        return [
            'key' => $this->key,
            'kind' => $this->kind->value,
            'policy' => $this->policy->value,
            'profiles' => $profiles,
            'details' => $this->details,
        ];
    }
}
