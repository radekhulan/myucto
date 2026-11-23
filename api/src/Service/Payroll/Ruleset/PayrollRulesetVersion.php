<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use InvalidArgumentException;

final readonly class PayrollRulesetVersion
{
    public string $canonicalSnapshot;
    public string $canonicalHash;

    /**
     * Otisk samotného OBSAHU (bez lifecyclu a podpisů) — přechodem stavu se nemění.
     * Zároveň je to jediné, podle čeho se pozná dodaná sada od zákaznického přepisu.
     */
    public string $contentHash;

    /** Odvozeno z {@see $contentHash}, NIKDY se nepředává zvenčí. */
    public PayrollRulesetOrigin $origin;

    /**
     * @param list<RulesetSource> $sources
     * @param array<string, PayrollRuleValue> $parameters
     */
    public function __construct(
        public string $id,
        public string $version,
        public PayrollRulesetDomain $domain,
        public string $effectiveFrom,
        public string $effectiveTo,
        public PayrollRulesetLifecycle $lifecycle,
        public PayrollRulesetCapability $capability,
        public array $sources,
        public array $parameters,
        public ?RulesetApproval $approval,
        public ?RulesetTechnicalReview $technicalReview = null,
        /**
         * Integritní pin nad otiskem OBSAHU, ne nad plným snapshotem. Obsah je to
         * jediné, co má u dodané sady zůstat bajtově beze změny; lifecycle
         * a schvalovatel se legitimně liší podle stavu a podle instalace
         * ({@see VendorRulesetApprover}) a pin nad nimi by u jiného provozovatele
         * shodil sestavení registru výjimkou.
         */
        ?string $expectedContentHash = null,
    ) {
        if ($id === '' || $version === '') {
            throw new InvalidArgumentException('Ruleset ID and version are required.');
        }
        self::assertDate($effectiveFrom);
        self::assertDate($effectiveTo);
        if ($effectiveTo < $effectiveFrom) {
            throw new InvalidArgumentException('Ruleset effective end cannot precede its start.');
        }
        if ($sources === [] || $parameters === []) {
            throw new InvalidArgumentException('Ruleset sources and parameters cannot be empty.');
        }
        if ($lifecycle !== PayrollRulesetLifecycle::Draft && $technicalReview === null) {
            throw new InvalidArgumentException('Reviewed, approved, active and superseded rulesets require technical review evidence.');
        }
        if (
            in_array($lifecycle, [PayrollRulesetLifecycle::Draft, PayrollRulesetLifecycle::Reviewed], true)
            && $approval !== null
        ) {
            throw new InvalidArgumentException('Draft and reviewed rulesets cannot claim professional approval.');
        }

        $parameterKeys = array_keys($parameters);
        sort($parameterKeys, SORT_STRING);
        if ($parameterKeys !== array_keys($parameters)) {
            throw new InvalidArgumentException('Ruleset parameters must be sorted by canonical key.');
        }

        $sourceIds = array_map(static fn (RulesetSource $source): string => $source->id, $sources);
        if (count(array_unique($sourceIds)) !== count($sourceIds)) {
            throw new InvalidArgumentException('Ruleset source IDs must be unique.');
        }

        // Původ se ODVOZUJE, nepředává. Dodanou sadou je jedině obsah, jehož otisk
        // je zkompilovaný ve {@see VendorRulesetManifest} — uložený override ani
        // požadavek z API takový otisk vyrobit nemůže, aniž by BYL dodanou sadou.
        $this->contentHash = PayrollRulesetContent::hash(PayrollRulesetContent::encode($this));
        if ($expectedContentHash !== null && !hash_equals($expectedContentHash, $this->contentHash)) {
            throw new PayrollRulesetException("Ruleset {$id} canonical checksum mismatch.");
        }
        $this->origin = VendorRulesetManifest::contains($this->contentHash)
            ? PayrollRulesetOrigin::Vendor
            : PayrollRulesetOrigin::CustomerOverride;

        // Za dodanou sadu ručí dodavatel, ne zákazník — proto je účinná bez
        // zákaznického schválení. Jakmile se obsah odchýlí, odpovědnost přebírá
        // zákazník a doklad o schválení se vyžaduje dál.
        if (in_array(
            $lifecycle,
            [PayrollRulesetLifecycle::Approved, PayrollRulesetLifecycle::Active, PayrollRulesetLifecycle::Superseded],
            true,
        ) && $approval === null && $this->origin !== PayrollRulesetOrigin::Vendor) {
            throw new InvalidArgumentException(
                'Approved, active and superseded rulesets require approval evidence unless they are the delivered vendor set.',
            );
        }

        $this->canonicalSnapshot = CanonicalJson::encode($this->snapshotArray());
        $this->canonicalHash = hash('sha256', $this->canonicalSnapshot);
    }

    public function contains(string $date): bool
    {
        self::assertDate($date);

        return $date >= $this->effectiveFrom && $date <= $this->effectiveTo;
    }

    public function parameter(string $key): PayrollRuleValue
    {
        $parameter = $this->parameters[$key] ?? null;
        if (!$parameter instanceof PayrollRuleValue) {
            throw new PayrollRulesetException("Ruleset {$this->id} does not define required parameter {$key}.");
        }
        $parameter->assertCalculationReady($key);

        return $parameter;
    }

    public function assertCalculationReady(): void
    {
        if ($this->lifecycle !== PayrollRulesetLifecycle::Active) {
            throw new PayrollRulesetException("Ruleset {$this->id} is not active.");
        }
        if ($this->capability !== PayrollRulesetCapability::Supported) {
            throw new PayrollRulesetException("Legislativní sada {$this->id} nemá hodnotu pro výpočet, posuzuje se mimo sadu.");
        }
    }

    /**
     * A lifecycle change always creates a new immutable version and therefore needs
     * a distinct ID and semantic version.
     */
    public function transition(
        PayrollRulesetLifecycle $next,
        string $newId,
        string $newVersion,
        ?RulesetApproval $approval,
        ?RulesetTechnicalReview $technicalReview = null,
    ): self {
        if (!$this->lifecycle->canTransitionTo($next)) {
            throw new PayrollRulesetException(
                "Ruleset lifecycle cannot transition from {$this->lifecycle->value} to {$next->value}.",
            );
        }
        if ($newId === $this->id || $newVersion === $this->version) {
            throw new PayrollRulesetException('Ruleset lifecycle transition requires a new ID and version.');
        }

        return new self(
            $newId,
            $newVersion,
            $this->domain,
            $this->effectiveFrom,
            $this->effectiveTo,
            $next,
            $this->capability,
            $this->sources,
            $this->parameters,
            $approval,
            $technicalReview ?? $this->technicalReview,
        );
    }

    /** @return array<string, mixed> */
    private function snapshotArray(): array
    {
        $sources = array_map(
            static fn (RulesetSource $source): array => $source->toCanonicalArray(),
            $this->sources,
        );
        $parameters = [];
        foreach ($this->parameters as $key => $parameter) {
            $parameters[$key] = $parameter->toCanonicalArray();
        }

        return [
            'approval' => $this->approval?->toCanonicalArray(),
            'capability' => $this->capability->value,
            'domain' => $this->domain->value,
            'effective_from' => $this->effectiveFrom,
            'effective_to' => $this->effectiveTo,
            'id' => $this->id,
            'lifecycle' => $this->lifecycle->value,
            'parameters' => $parameters,
            'sources' => $sources,
            'technical_review' => $this->technicalReview?->toCanonicalArray(),
            'version' => $this->version,
        ];
    }

    private static function assertDate(string $value): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Ruleset dates must use YYYY-MM-DD.');
        }
    }
}
