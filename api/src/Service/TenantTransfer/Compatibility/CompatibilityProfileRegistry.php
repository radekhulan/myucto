<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Compatibility;

final class CompatibilityProfileRegistry
{
    public const VERSION = 1;

    /** @var array<string,CompatibilityProfile> */
    private array $profilesByPair = [];

    /** @var array<string,true> */
    private array $profileIds = [];

    /** @param array<mixed> $profiles */
    private function __construct(array $profiles)
    {
        if ($profiles === []) {
            throw new \InvalidArgumentException('Kompatibilitní registr nesmí být prázdný.');
        }
        foreach ($profiles as $profile) {
            if (!$profile instanceof CompatibilityProfile) {
                throw new \InvalidArgumentException('Kompatibilitní registr obsahuje neplatný profil.');
            }
            foreach ([$profile->id(), $profile->sourceProfile(), $profile->targetProfile()] as $identifier) {
                if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $identifier) !== 1) {
                    throw new \InvalidArgumentException('Kompatibilitní profil nemá bezpečný identifikátor.');
                }
            }
            if (isset($this->profileIds[$profile->id()])) {
                throw new \InvalidArgumentException('Duplicitní kompatibilitní profil ' . $profile->id() . '.');
            }
            $pair = self::pairKey($profile->sourceProfile(), $profile->targetProfile());
            if (isset($this->profilesByPair[$pair])) {
                throw new \InvalidArgumentException('Pro směrovou dvojici je registrováno více kompatibilitních profilů.');
            }
            $this->profileIds[$profile->id()] = true;
            $this->profilesByPair[$pair] = $profile;
        }
    }

    public static function v1(): self
    {
        return new self([new IdentityCompatibilityProfile()]);
    }

    /**
     * Explicitní extension point pro budoucí allowlistované směrové adaptéry.
     * Produkční v1 používá výhradně {@see v1()}.
     *
     * @param list<CompatibilityProfile> $profiles
     */
    public static function fromProfiles(array $profiles): self
    {
        return new self($profiles);
    }

    /** @return list<string> */
    public function profileIds(): array
    {
        $ids = array_keys($this->profileIds);
        sort($ids, SORT_STRING);
        return $ids;
    }

    public function evaluate(CompatibilityFingerprint $source, CompatibilityFingerprint $target): CompatibilityResult
    {
        $issues = [];
        if ($source->product === 'myinvoice') {
            $issues[] = new CompatibilityIssue(
                'source_upgrade_required',
                'source.product',
                'Zdrojové MyInvoice je nutné nejprve povýšit in-place na MyÚčto.',
            );
        } elseif ($source->product !== CompatibilityFingerprint::PRODUCT) {
            $issues[] = new CompatibilityIssue(
                'capability_mismatch',
                'source.product',
                'Zdrojové capabilities nepatří produktu MyÚčto.',
            );
        }
        if ($target->product !== CompatibilityFingerprint::PRODUCT) {
            $issues[] = new CompatibilityIssue(
                'capability_mismatch',
                'target.product',
                'Cílové capabilities nepatří produktu MyÚčto.',
            );
        }

        $profile = $this->profilesByPair[self::pairKey(
            $source->compatibilityProfile,
            $target->compatibilityProfile,
        )] ?? null;
        if ($profile === null) {
            $issues[] = new CompatibilityIssue(
                'compatibility_adapter_unavailable',
                'compatibility_profile',
                'Pro tuto směrovou dvojici kompatibilitních profilů není dostupný adaptér.',
            );
            return new CompatibilityResult(null, $issues);
        }

        return new CompatibilityResult(
            $profile->id(),
            array_merge($issues, $profile->evaluate($source, $target)),
        );
    }

    private static function pairKey(string $source, string $target): string
    {
        return $source . "\0" . $target;
    }
}
