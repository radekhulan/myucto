<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use MyInvoice\Repository\Payroll\PayrollRulesetRepository;

/**
 * Efektivní registry mzdových rulesetů = ověřený default z kódu
 * ({@see CzechPayrollRulesets2026}) sloučený s DB overridem z administrace.
 *
 * Vzor je převzatý z ročních daňových konstant (`tax_constants`, migrace 0079):
 *
 *  - kód drží jedinou ověřenou sadu a zároveň fallback,
 *  - DB nese jen to, co admin změnil, a merge běží PER KLÍČ, takže override
 *    uložený starší verzí aplikace neztratí později přidané parametry,
 *  - chybějící override = platí default, smazaný override = reset na default.
 *
 * Runtime na tenhle registry sahá přes `PayrollRulesetProvider` (bind
 * v Bootstrapu), takže změna v administraci se projeví bez nasazení nové verze.
 *
 * ## Kdo za sloučenou verzi ručí
 *
 * Dodaná sada je účinná rovnou — ručí za ni dodavatel a zákazník ji neschvaluje
 * ({@see CzechPayrollRulesets2026}). Jakmile override obsah SKUTEČNĚ změní,
 * odpovědnost přebírá zákazník a účinnost si musí zaplatit schválením: bez
 * doloženého schvalovatele se sloučená verze čte jako `reviewed`, ne `active`.
 * Rozhoduje o tom otisk obsahu proti {@see VendorRulesetManifest}, ne příznak
 * v databázi — uložený řádek proto nemůže účinnost bez schválení předstírat.
 */
final class PayrollRulesetRegistry
{
    /** @var list<array<string, mixed>>|null */
    private ?array $cache = null;

    private ?string $degradedReason = null;

    public function __construct(private readonly PayrollRulesetRepository $overrides) {}

    public static function defaults(): PayrollRulesetProvider
    {
        return CzechPayrollRulesets::provider();
    }

    /**
     * Runtime registry. Když by sloučená sada byla nekonzistentní (překryv
     * účinností, rozbitý override), použije se ověřený default z kódu —
     * konfigurační chyba nesmí položit celou aplikaci. Důvod degradace je
     * čitelný přes {@see degradedReason()} a hlásí ho i admin obrazovka.
     */
    public function provider(): PayrollRulesetProvider
    {
        try {
            $versions = array_map(
                static fn (array $entry): PayrollRulesetVersion => self::version($entry),
                $this->effective(),
            );

            return new PayrollRulesetProvider($versions);
        } catch (\Throwable $e) {
            $this->degradedReason = $e->getMessage();

            return self::defaults();
        }
    }

    public function degradedReason(): ?string
    {
        return $this->degradedReason;
    }

    /**
     * Efektivní verze všech rulesetů, seřazené podle domény a účinnosti.
     *
     * @return list<array{
     *   version: PayrollRulesetVersion,
     *   override: array<string, mixed>|null,
     *   is_override: bool,
     *   has_default: bool,
     *   default: PayrollRulesetVersion|null
     * }>
     */
    public function effective(): array
    {
        if ($this->cache !== null) {
            /** @var list<array{version:PayrollRulesetVersion,override:array<string,mixed>|null,is_override:bool,has_default:bool,default:PayrollRulesetVersion|null}> $cached */
            $cached = $this->cache;

            return $cached;
        }

        $overrides = $this->overrides->all();
        $entries = [];

        foreach (self::defaults()->versions() as $default) {
            $override = $overrides[$default->id] ?? null;
            unset($overrides[$default->id]);
            $entries[] = [
                'version' => self::merge($default, $override),
                'override' => $override,
                'is_override' => $override !== null,
                'has_default' => true,
                'default' => $default,
            ];
        }

        foreach ($overrides as $override) {
            $entries[] = [
                'version' => self::merge(null, $override),
                'override' => $override,
                'is_override' => true,
                'has_default' => false,
                'default' => null,
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            $a = self::version($left);
            $b = self::version($right);

            return [$a->domain->value, $a->effectiveFrom, $a->id]
                <=> [$b->domain->value, $b->effectiveFrom, $b->id];
        });

        $this->cache = $entries;

        return $entries;
    }

    /** @return array{version:PayrollRulesetVersion,override:array<string,mixed>|null,is_override:bool,has_default:bool,default:PayrollRulesetVersion|null}|null */
    public function entry(string $rulesetId): ?array
    {
        foreach ($this->effective() as $entry) {
            if (self::version($entry)->id === $rulesetId) {
                return $entry;
            }
        }

        return null;
    }

    public function defaultVersion(string $rulesetId): ?PayrollRulesetVersion
    {
        foreach (self::defaults()->versions() as $version) {
            if ($version->id === $rulesetId) {
                return $version;
            }
        }

        return null;
    }

    public function forget(): void
    {
        $this->cache = null;
        $this->degradedReason = null;
    }

    /**
     * Sloučení defaultu z kódu s DB overridem.
     *
     * Skalární sloupce (`version`, účinnost, `lifecycle`, `capability`) jsou
     * v overridu nullovatelné: NULL = dědím z kódu. Parametry se slučují po
     * klíčích, zdroje se nahrazují celé (jsou seznam, ne mapa).
     *
     * @param array<string, mixed>|null $override
     */
    public static function merge(?PayrollRulesetVersion $default, ?array $override): PayrollRulesetVersion
    {
        if ($default === null && $override === null) {
            throw new PayrollRulesetException('Ruleset nemá default ani override.');
        }

        $data = self::decode($override['data'] ?? null);
        $parameters = $default === null ? [] : $default->parameters;
        foreach (self::rawParameters($data) as $key => $raw) {
            $parameters[$key] = PayrollRuleValue::fromCanonicalArray($raw);
        }
        ksort($parameters, SORT_STRING);
        if ($parameters === []) {
            throw new PayrollRulesetException('Ruleset musí mít alespoň jeden parametr.');
        }

        $sources = self::rawSources($data);
        if ($sources === [] && $default !== null) {
            $sources = $default->sources;
        }
        if ($sources === []) {
            throw new PayrollRulesetException('Ruleset musí mít alespoň jeden právní zdroj.');
        }

        $reason = self::str($override, 'reason') ?? '';
        $lifecycle = PayrollRulesetLifecycle::from(
            self::str($override, 'lifecycle')
                ?? ($default === null ? 'draft' : $default->lifecycle->value),
        );
        $capability = PayrollRulesetCapability::from(
            self::str($override, 'capability')
                ?? ($default === null ? 'manual_review' : $default->capability->value),
        );
        $technicalReview = PayrollRulesetEvidence::technicalReview(
            $override,
            $default?->technicalReview,
            $lifecycle,
            $reason,
        );
        $approval = PayrollRulesetEvidence::approval(
            $override,
            $default?->approval,
            $technicalReview,
            $lifecycle,
            $reason,
        );

        // Účinnost bez schválení smí mít jedině DODANÁ sada — za tu ručí dodavatel.
        // Jestli uložený override dodanou sadu opravdu mění, se pozná až z otisku
        // obsahu, a ten vzniká až konstrukcí verze. Zkušební sestavení proto proběhne
        // ve stavu `reviewed` (ten je bez schválení přípustný vždy) a teprve podle
        // jeho původu se rozhodne, jestli smí být verze účinná.
        //
        // Kdyby se to neudělalo, řádek s `lifecycle = active` a změněnou hodnotou by
        // buď položil sestavení celého registru, nebo — hůř — prošel jako schválený.
        // Takhle se z něj stane neúčinná verze čekající na schválení a je to vidět
        // v administraci jako `awaiting_activation`.
        $id = self::str($override, 'ruleset_id') ?? ($default === null ? '' : $default->id);
        $versionNumber = self::str($override, 'version') ?? ($default === null ? '' : $default->version);
        $domain = PayrollRulesetDomain::from(
            self::str($override, 'domain') ?? ($default === null ? '' : $default->domain->value),
        );
        $from = self::str($override, 'effective_from')
            ?? ($default === null ? '' : $default->effectiveFrom);
        $to = self::str($override, 'effective_to')
            ?? ($default === null ? '' : $default->effectiveTo);

        //
        // Od 8/2026 nese dodaná sada podpis provozovatele ({@see VendorRulesetApprover}),
        // takže `$default->approval` už není `null` a override si ho ZDĚDÍ. Ten podpis
        // ale platí jen pro DODANÝ obsah — zděděné schválení se proto zahazuje spolu
        // s účinností v okamžiku, kdy se obsah od dodané sady odchýlí. Bez toho by
        // zákazníkova změna sazby vyšla z registru jako schválená provozovatelem.
        $inheritedApproval = $approval !== null && $default !== null && $approval === $default->approval;
        if ($override !== null && ($approval === null || $inheritedApproval) && self::requiresApproval($lifecycle)) {
            $probe = new PayrollRulesetVersion(
                $id,
                $versionNumber,
                $domain,
                $from,
                $to,
                PayrollRulesetLifecycle::Reviewed,
                $capability,
                $sources,
                $parameters,
                null,
                $technicalReview,
            );
            if ($probe->origin !== PayrollRulesetOrigin::Vendor) {
                $approval = null;
                $lifecycle = PayrollRulesetLifecycle::Reviewed;
            }
        }

        return new PayrollRulesetVersion(
            $id,
            $versionNumber,
            $domain,
            $from,
            $to,
            $lifecycle,
            $capability,
            $sources,
            $parameters,
            $approval,
            $technicalReview,
        );
    }

    private static function requiresApproval(PayrollRulesetLifecycle $lifecycle): bool
    {
        return in_array($lifecycle, [
            PayrollRulesetLifecycle::Approved,
            PayrollRulesetLifecycle::Active,
            PayrollRulesetLifecycle::Superseded,
        ], true);
    }

    /** @param array{version:PayrollRulesetVersion,override:array<string,mixed>|null,is_override:bool,has_default:bool,default:PayrollRulesetVersion|null} $entry */
    private static function version(array $entry): PayrollRulesetVersion
    {
        return $entry['version'];
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, array<string, mixed>>
     */
    private static function rawParameters(array $data): array
    {
        $raw = $data['parameters'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $result = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                continue;
            }
            $normalized = [];
            foreach ($value as $field => $item) {
                if (is_string($field)) {
                    $normalized[$field] = $item;
                }
            }
            $result[$key] = $normalized;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<RulesetSource>
     */
    private static function rawSources(array $data): array
    {
        $raw = $data['sources'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        return PayrollRulesetContent::sources(['sources' => $raw]);
    }

    /** @param array<string, mixed>|null $row */
    private static function str(?array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
