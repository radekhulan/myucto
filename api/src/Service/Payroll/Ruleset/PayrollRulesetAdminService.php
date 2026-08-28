<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use DateTimeImmutable;
use MyInvoice\Repository\Payroll\PayrollRulesetRepository;

/**
 * Administrace legislativních rulesetů (MZ-02-W07).
 *
 * Editace jde stejnou cestou jako roční daňové konstanty: default z kódu +
 * override v DB, merge per klíč, reset = smazání overridu. Navíc drží to, co
 * u devíti domén s vlastní účinností dává smysl a u jednoho JSON na rok ne:
 * diff proti defaultu i proti sousední verzi, kontrolu mezer a překryvů
 * účinnosti a append-only stopu, kdo co kdy a proč změnil.
 *
 * Co JE tvrdá překážka: sada, kterou by runtime registry neuměl sestavit
 * (překryv účinností), mezera v účinnosti domény a otisk overridu, který
 * neodpovídá uloženým datům. Všechno ostatní — včetně čtyř očí — je varování,
 * aby jeden admin mohl bez nasazení nové verze dojít až k běžícímu výpočtu.
 */
final class PayrollRulesetAdminService
{
    public const COMMANDS = ['review', 'approve', 'activate', 'supersede'];

    public function __construct(
        private readonly PayrollRulesetRepository $overrides,
        private readonly PayrollRulesetRegistry $registry,
    ) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $entries = $this->registry->effective();
        $byDomain = [];
        foreach ($entries as $entry) {
            $byDomain[$entry['version']->domain->value][] = $entry;
        }

        $domains = [];
        foreach (PayrollRulesetDomain::cases() as $domain) {
            $domainEntries = $byDomain[$domain->value] ?? [];
            $selectable = array_values(array_filter(
                $domainEntries,
                static fn (array $entry): bool =>
                    $entry['version']->lifecycle !== PayrollRulesetLifecycle::Superseded,
            ));
            $active = array_values(array_filter(
                $domainEntries,
                static fn (array $entry): bool =>
                    $entry['version']->lifecycle === PayrollRulesetLifecycle::Active,
            ));
            $ready = array_values(array_filter(
                $active,
                static fn (array $entry): bool =>
                    $entry['version']->capability === PayrollRulesetCapability::Supported,
            ));

            $coverageIssues = PayrollRulesetCoverage::issues(
                array_map(self::intervalOf(...), $selectable),
            );
            $manualByDesign = array_filter(
                $selectable,
                static fn (array $entry): bool =>
                    $entry['version']->capability === PayrollRulesetCapability::ManualReview,
            ) !== [];

            $manualKeys = [];
            $parameterKeys = [];
            foreach ($selectable as $entry) {
                foreach ($entry['version']->parameters as $key => $parameter) {
                    $parameterKeys[$key] = true;
                    if ($parameter->capability === PayrollRulesetCapability::ManualReview) {
                        $manualKeys[$key] = true;
                    }
                }
            }

            $domains[] = [
                'domain' => $domain->value,
                'version_count' => count($domainEntries),
                'active_count' => count($active),
                'calculation_ready' => $ready !== [],
                // Proč z domény výpočet nečerpá. Rozlišuje to, co dosud splývalo
                // do jediného „Výpočet blokován": `awaiting_activation` je fronta,
                // kterou uživatel odbaví (jedním příkazem na doménu),
                // `manual_review` je vědomé rozhodnutí aplikace netvrdit číslo —
                // tam není co odklikávat.
                'status' => match (true) {
                    $ready !== [] => 'ready',
                    $manualByDesign => 'manual_review',
                    $coverageIssues !== [] => 'coverage_issue',
                    $selectable !== [] => 'awaiting_activation',
                    default => 'missing',
                },
                'manual_review_by_design' => $manualByDesign,
                'manual_review_explanation' => PayrollRuleParameterCatalog::domainManualReview($domain->value),
                'manual_review_parameter_count' => count($manualKeys),
                'parameter_count' => count($parameterKeys),
                'coverage_issues' => $coverageIssues,
                'versions' => array_map(fn (array $entry): array => $this->summary($entry), $domainEntries),
            ];
        }

        // Výhled na příští roky. Chybějící sada na PŘÍŠTÍ rok je jediná porucha
        // téhle obrazovky, kterou nejde odhalit pohledem na existující verze —
        // ty jsou všechny v pořádku, jen žádná nepokrývá leden. Bez výhledu by
        // se na to přišlo až prvním lednovým výpočtem.
        $provider = $this->registry->provider();
        $outlook = PayrollRulesetYearOutlook::forProvider($provider);

        return [
            'domains' => $domains,
            'year_outlook' => $outlook,
            'year_outlook_severity' => PayrollRulesetYearOutlook::worstSeverity($provider),
            'override_storage_available' => $this->overrides->isAvailable(),
            'degraded_reason' => $this->registry->degradedReason(),
            'generated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function detail(string $rulesetId): ?array
    {
        $entry = $this->registry->entry($rulesetId);
        if ($entry === null) {
            return null;
        }
        $version = $entry['version'];
        $snapshot = PayrollRulesetContent::canonicalArray($version);

        $detail = $this->summary($entry);
        $detail['parameters'] = self::parameterList($snapshot);
        $detail['sources'] = self::sourceList($snapshot);
        $detail['audit'] = $this->overrides->auditTrail($rulesetId);
        $detail['default_diff'] = $entry['default'] === null
            ? null
            : PayrollRulesetDiff::between(
                PayrollRulesetContent::canonicalArray($entry['default']),
                $snapshot,
            );
        $detail['previous_ruleset_id'] = $this->previousRulesetId($version);
        $detail['override_data'] = $entry['override'] === null
            ? null
            : self::overrideData($entry['override']);

        return $detail;
    }

    /**
     * Diff dvou verzí téže domény; `$rightId === 'default'` porovná efektivní
     * podobu proti ověřenému defaultu z kódu.
     *
     * @return array<string, mixed>|null
     */
    public function diff(string $leftId, string $rightId): ?array
    {
        $left = $this->registry->entry($leftId);
        if ($left === null) {
            return null;
        }

        if ($rightId === 'default') {
            $default = $this->registry->defaultVersion($leftId);
            if ($default === null) {
                throw new PayrollRulesetGovernanceException(
                    'no_default',
                    'Tato verze rulesetu nemá vestavěný default, není proti čemu porovnávat.',
                );
            }

            return [
                'domain' => $left['version']->domain->value,
                'left' => ['ruleset_id' => $default->id, 'label' => 'default', 'version' => $default->version],
                'right' => ['ruleset_id' => $left['version']->id, 'label' => 'effective', 'version' => $left['version']->version],
                'effective' => [
                    'left' => ['from' => $default->effectiveFrom, 'to' => $default->effectiveTo],
                    'right' => ['from' => $left['version']->effectiveFrom, 'to' => $left['version']->effectiveTo],
                ],
                'parameters' => PayrollRulesetDiff::between(
                    PayrollRulesetContent::canonicalArray($default),
                    PayrollRulesetContent::canonicalArray($left['version']),
                ),
            ];
        }

        $right = $this->registry->entry($rightId);
        if ($right === null) {
            return null;
        }
        if ($left['version']->domain !== $right['version']->domain) {
            throw new PayrollRulesetGovernanceException(
                'domain_mismatch',
                'Porovnávat lze jen verze téže domény.',
            );
        }

        return [
            'domain' => $left['version']->domain->value,
            'left' => ['ruleset_id' => $left['version']->id, 'label' => 'effective', 'version' => $left['version']->version],
            'right' => ['ruleset_id' => $right['version']->id, 'label' => 'effective', 'version' => $right['version']->version],
            'effective' => [
                'left' => ['from' => $left['version']->effectiveFrom, 'to' => $left['version']->effectiveTo],
                'right' => ['from' => $right['version']->effectiveFrom, 'to' => $right['version']->effectiveTo],
            ],
            'parameters' => PayrollRulesetDiff::between(
                PayrollRulesetContent::canonicalArray($left['version']),
                PayrollRulesetContent::canonicalArray($right['version']),
            ),
        ];
    }

    /**
     * Read-only náhled účinku kandidáta před aktivací. Nepředstírá přepočet
     * peněz: bez konkrétního uzamčeného vstupu by částku jen odhadoval. Ukazuje
     * proto přesně změněný manifest a hranici, že dřívější snapshoty zůstávají
     * neměnné.
     *
     * @return array<string,mixed>|null
     */
    public function impactPreview(string $rulesetId): ?array
    {
        $entry = $this->registry->entry($rulesetId);
        if ($entry === null) {
            return null;
        }

        $candidate = $entry['version'];
        $candidateContent = PayrollRulesetContent::canonicalArray($candidate);
        $baseline = $this->previousActiveBaseline($candidate)
            ?? $this->defaultBaseline($entry['default']);
        $parameterDiff = $baseline === null
            ? null
            : PayrollRulesetDiff::between($baseline['content'], $candidateContent);
        $newSnapshotsWouldChange = $parameterDiff === null
            ? null
            : !$parameterDiff['identical'];

        return [
            'ruleset' => $this->summary($entry),
            'baseline' => $baseline === null ? null : $baseline['summary'],
            'effective' => [
                'from' => $candidate->effectiveFrom,
                'to' => $candidate->effectiveTo,
            ],
            'parameter_diff' => $parameterDiff,
            'activation_effect' => [
                'new_snapshots_would_change' => $newSnapshotsWouldChange,
                'existing_snapshots_are_immutable' => true,
                'money_delta' => null,
                'money_delta_unavailable_reason' => 'no_locked_input_snapshot',
            ],
        ];
    }

    /**
     * @return array{content:array<string,mixed>,summary:array<string,string>}|null
     */
    private function previousActiveBaseline(PayrollRulesetVersion $candidate): ?array
    {
        $snapshot = $this->overrides->latestActivationBoundary($candidate->id);
        if ($snapshot === null || $snapshot['lifecycle'] !== PayrollRulesetLifecycle::Active->value) {
            return null;
        }

        try {
            $recanonicalized = PayrollRulesetContent::recanonicalize($snapshot['snapshot_json']);
            if (!hash_equals($snapshot['snapshot_hash'], $recanonicalized['hash'])) {
                throw new PayrollRulesetException('Otisk aktivního auditního snapshotu rulesetu nesouhlasí.');
            }
            $content = json_decode($recanonicalized['canonical'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($content)
                || self::snapshotText($content, 'id') !== $candidate->id
                || self::snapshotText($content, 'domain') !== $candidate->domain->value) {
                return null;
            }

            return [
                'content' => $content,
                'summary' => [
                    'ruleset_id' => self::snapshotText($content, 'id'),
                    'version' => self::snapshotText($content, 'version'),
                    'origin' => VendorRulesetManifest::contains($recanonicalized['hash'])
                        ? PayrollRulesetOrigin::Vendor->value
                        : PayrollRulesetOrigin::CustomerOverride->value,
                    'canonical_hash' => $recanonicalized['hash'],
                    'source' => 'previous_active_snapshot',
                ],
            ];
        } catch (\JsonException | PayrollRulesetException $e) {
            throw new PayrollRulesetGovernanceException(
                'impact_baseline_invalid',
                'Náhled nelze sestavit: poslední aktivní auditní snapshot nemá platnou integritu.',
                ['ruleset_id' => $candidate->id],
            );
        }
    }

    /**
     * @return array{content:array<string,mixed>,summary:array<string,string>}|null
     */
    private function defaultBaseline(?PayrollRulesetVersion $default): ?array
    {
        if ($default === null) {
            return null;
        }

        return [
            'content' => PayrollRulesetContent::canonicalArray($default),
            'summary' => [
                'ruleset_id' => $default->id,
                'version' => $default->version,
                'origin' => $default->origin->value,
                'canonical_hash' => PayrollRulesetContent::hash(PayrollRulesetContent::encode($default)),
                'source' => 'vendor_default',
            ],
        ];
    }

    /** @param array<array-key, mixed> $snapshot */
    private static function snapshotText(array $snapshot, string $field): string
    {
        $value = $snapshot[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new PayrollRulesetException("Snapshot rulesetu nemá textové pole {$field}.");
        }

        return $value;
    }

    /**
     * Uloží override obsahu. Lifecycle se tudy NEMĚNÍ — na to jsou příkazové
     * routy, aby neexistoval endpoint „nastav libovolný stav".
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function save(
        string $rulesetId,
        array $patch,
        string $reason,
        int $expectedRowVersion,
        ?int $actorUserId,
    ): array {
        $this->assertStorage();
        $reason = self::assertReason($reason);
        $entry = $this->registry->entry($rulesetId);
        $existing = $this->overrides->find($rulesetId);
        $default = $this->registry->defaultVersion($rulesetId);

        // Doména je součást identity: u známého rulesetu se přebírá, u nového ji
        // musí zadat volající. Přepsat ji u existujícího nejde ani přes patch.
        $domain = $entry !== null
            ? $entry['version']->domain->value
            : ($default?->domain->value
                ?? self::patchString($patch, 'domain')
                ?? throw new PayrollRulesetGovernanceException(
                    'domain_required',
                    'Nový ruleset musí mít doménu.',
                ));
        if (PayrollRulesetDomain::tryFrom($domain) === null) {
            throw new PayrollRulesetGovernanceException('unknown_domain', "Neznámá doména {$domain}.");
        }

        $candidate = $this->buildOverrideRow(
            $rulesetId,
            $domain,
            $existing,
            $patch,
            $reason,
            null,
        );
        $this->assertConsistent($rulesetId, $candidate);

        $previousHash = $existing === null ? null : PayrollRulesetOverrideHash::hash($existing);
        $saved = $this->overrides->save(
            $rulesetId,
            $domain,
            self::writeValues($candidate),
            $expectedRowVersion,
            $actorUserId,
        );
        $this->registry->forget();

        $entry = $this->registry->entry($rulesetId)
            ?? throw new \RuntimeException('Uložený ruleset se nepodařilo sestavit.');
        $this->overrides->appendAudit(
            $rulesetId,
            $domain,
            $existing === null ? 'created' : 'updated',
            $entry['version']->lifecycle->value,
            $reason,
            PayrollRulesetContent::encode($entry['version']),
            $previousHash,
            $actorUserId,
        );

        return $this->detail($rulesetId) ?? [];
    }

    /**
     * Reset na ověřený default z kódu (smaže override). U rulesetu, který
     * default nemá, ho odstraní úplně.
     *
     * @return array<string, mixed>|null
     */
    public function reset(string $rulesetId, string $reason, ?int $actorUserId): ?array
    {
        $this->assertStorage();
        $reason = self::assertReason($reason);
        $entry = $this->registry->entry($rulesetId);
        if ($entry === null) {
            return null;
        }
        $domain = $entry['version']->domain->value;

        $this->overrides->reset($rulesetId);
        $this->registry->forget();

        $after = $this->registry->entry($rulesetId);
        $this->overrides->appendAudit(
            $rulesetId,
            $domain,
            'reset',
            $after === null ? PayrollRulesetLifecycle::Draft->value : $after['version']->lifecycle->value,
            $reason,
            $after === null
                ? CanonicalJson::encode(['removed' => true, 'ruleset_id' => $rulesetId])
                : PayrollRulesetContent::encode($after['version']),
            PayrollRulesetOverrideHash::hash($entry['override'] ?? []),
            $actorUserId,
        );

        return $after === null ? null : $this->detail($rulesetId);
    }

    /**
     * Stavový přechod. Idempotentní: opakovaný příkaz nad již dosaženým stavem
     * nic nezapisuje a nevrací chybu.
     *
     * @return array{ruleset: array<string, mixed>, changed: bool}
     */
    public function command(
        string $rulesetId,
        string $command,
        string $reason,
        int $expectedRowVersion,
        ?int $actorUserId,
    ): array {
        $this->assertStorage();
        if (!in_array($command, self::COMMANDS, true)) {
            throw new PayrollRulesetGovernanceException(
                'unknown_command',
                "Neznámý příkaz rulesetu {$command}.",
            );
        }
        $reason = self::assertReason($reason);

        $entry = $this->registry->entry($rulesetId)
            ?? throw new PayrollRulesetGovernanceException('not_found', 'Ruleset nebyl nalezen.');
        $current = $entry['version']->lifecycle;
        $target = self::targetOf($command);

        if ($current === $target) {
            return ['ruleset' => $this->detail($rulesetId) ?? [], 'changed' => false];
        }
        if (!$current->canTransitionTo($target)) {
            throw new PayrollRulesetGovernanceException(
                'lifecycle_transition',
                sprintf('Ze stavu %s nelze přejít na %s.', $current->value, $target->value),
            );
        }

        $blockers = $this->blockers($entry, $command);
        if ($blockers !== []) {
            throw new PayrollRulesetGovernanceException(
                self::strv($blockers[0], 'code'),
                self::strv($blockers[0], 'message'),
                ['blockers' => $blockers],
            );
        }

        $existing = $this->overrides->find($rulesetId);
        $domain = $entry['version']->domain->value;
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stamps = match ($command) {
            'review' => ['reviewed_by' => $actorUserId, 'reviewed_at' => $now],
            'approve' => ['approved_by' => $actorUserId, 'approved_at' => $now],
            'activate' => ['activated_by' => $actorUserId, 'activated_at' => $now],
            // `$command` je výše ověřený proti self::COMMANDS, další větev neexistuje.
            'supersede' => ['superseded_by' => $actorUserId, 'superseded_at' => $now],
        };

        $candidate = $this->buildOverrideRow(
            $rulesetId,
            $domain,
            $existing,
            [],
            $reason,
            $target->value,
        );
        foreach ($stamps as $key => $value) {
            $candidate[$key] = $value;
        }
        $this->assertConsistent($rulesetId, $candidate);

        $previousHash = $existing === null ? null : PayrollRulesetOverrideHash::hash($existing);
        $this->overrides->save(
            $rulesetId,
            $domain,
            [...self::writeValues($candidate), ...$stamps],
            $expectedRowVersion,
            $actorUserId,
            false,
        );
        $this->registry->forget();

        $after = $this->registry->entry($rulesetId)
            ?? throw new \RuntimeException('Ruleset se po přechodu nepodařilo sestavit.');
        $this->overrides->appendAudit(
            $rulesetId,
            $domain,
            $command,
            $after['version']->lifecycle->value,
            $reason,
            PayrollRulesetContent::encode($after['version']),
            $previousHash,
            $actorUserId,
        );

        return ['ruleset' => $this->detail($rulesetId) ?? [], 'changed' => true];
    }

    /**
     * Tvrdé překážky příkazu — to, co by rozbilo runtime výpočet.
     *
     * @param array{version:PayrollRulesetVersion,override:array<string,mixed>|null,is_override:bool,has_default:bool,default:PayrollRulesetVersion|null} $entry
     * @return list<array{code:string, message:string, context:array<string, mixed>}>
     */
    public function blockers(array $entry, string $command): array
    {
        $target = self::targetOf($command);
        if (!in_array($target, [PayrollRulesetLifecycle::Approved, PayrollRulesetLifecycle::Active], true)) {
            return [];
        }

        $issues = [];
        $override = $entry['override'];
        if ($override !== null && !PayrollRulesetOverrideHash::matches($override)) {
            $issues[] = [
                'code' => 'checksum_mismatch',
                'message' => 'Kontrolní součet uloženého overridu neodpovídá jeho obsahu — data byla změněna mimo aplikaci.',
                'context' => ['ruleset_id' => $entry['version']->id],
            ];
        }

        foreach ($this->coverageIssuesFor($entry['version']) as $issue) {
            $issues[] = $issue;
        }

        return $issues;
    }

    /**
     * Varování — nebrání uložení ani aktivaci, ale musí být vidět.
     *
     * @param array{version:PayrollRulesetVersion,override:array<string,mixed>|null,is_override:bool,has_default:bool,default:PayrollRulesetVersion|null} $entry
     * @return list<array{code:string, message:string, context:array<string, mixed>}>
     */
    public function warnings(array $entry): array
    {
        $warnings = [];
        $version = $entry['version'];

        if (!PayrollRulesetEvidence::fourEyesMet($entry['override'])) {
            $warnings[] = [
                'code' => 'four_eyes_not_met',
                'message' => 'Ruleset schválil tentýž uživatel, který ho naposledy upravil nebo zkontroloval.',
                'context' => [
                    'updated_by' => $entry['override']['updated_by'] ?? null,
                    'approved_by' => $entry['override']['approved_by'] ?? null,
                ],
            ];
        }

        $manual = self::manualReviewKeys($version);
        $total = count($version->parameters);

        // Ruční posouzení NENÍ fronta ke schválení: je to vědomé odmítnutí
        // tvrdit jedno číslo tam, kde žádné univerzálně platné neexistuje.
        // Hláška proto musí říct, co má uživatel dělat — obvykle nic.
        if ($version->capability === PayrollRulesetCapability::ManualReview) {
            $warnings[] = [
                'code' => 'manual_review_capability',
                'message' => 'Tahle doména je celá vedená jako ruční posouzení: aplikace tu záměrně '
                    . 'netvrdí žádnou hodnotu, protože ta správná závisí na konkrétním případu. '
                    . 'Není tu co odklikávat ani schvalovat a mzdový výpočet z domény nečerpá.'
                    . (PayrollRuleParameterCatalog::domainManualReview($version->domain->value) === null
                        ? ''
                        : ' ' . PayrollRuleParameterCatalog::domainManualReview($version->domain->value)),
                'context' => [
                    'domain' => $version->domain->value,
                    'manual_review_count' => count($manual),
                    'parameter_count' => $total,
                ],
            ];
        }

        if ($manual !== []) {
            $warnings[] = [
                'code' => 'manual_review_parameters',
                'message' => sprintf(
                    'Ruční posouzení vyžadují %d z %d parametrů — u nich aplikace vědomě nedosazuje '
                    . 'žádné číslo a výpočet z nich nečerpá. Zbylých %d se používá normálně. '
                    . 'Nemusíte nic odklikávat: hodnotu doplňte jen tehdy, když na takový případ '
                    . 'skutečně narazíte.',
                    count($manual),
                    $total,
                    $total - count($manual),
                ),
                'context' => [
                    'parameters' => $manual,
                    'manual_review_count' => count($manual),
                    'parameter_count' => $total,
                ],
            ];
        }

        // Rozhoduje PŮVOD OBSAHU, ne existence řádku v databázi: override, který
        // hodnoty nezměnil, je pořád dodaná sada a varovat u něj není proč.
        if (
            $version->origin === PayrollRulesetOrigin::CustomerOverride
            && $version->lifecycle === PayrollRulesetLifecycle::Active
        ) {
            $warnings[] = [
                'code' => 'active_override',
                'message' => 'Účinná verze běží na ručním overridu, ne na ověřené sadě dodané '
                    . 's aplikací. Za upravené hodnoty ručí ten, kdo je zadal a schválil.',
                'context' => ['origin' => $version->origin->value],
            ];
        }

        // Obsah jde editovat i po schválení (jinak by administrace byla horší než
        // číselník daňových konstant), ale schválení se tím fakticky rozchází
        // s tím, co je účinné — a to musí být vidět.
        $approvedAt = self::nullableStr($entry['override'], 'approved_at');
        $updatedAt = self::nullableStr($entry['override'], 'updated_at');
        if ($approvedAt !== null && $updatedAt !== null && $updatedAt > $approvedAt) {
            $warnings[] = [
                'code' => 'edited_after_approval',
                'message' => 'Hodnoty se změnily až po odborném schválení — schválení se vztahuje ke starší podobě.',
                'context' => ['approved_at' => $approvedAt, 'updated_at' => $updatedAt],
            ];
        }

        return $warnings;
    }

    /**
     * Sestaví kandidátní řádek overridu (bez zápisu) — používá se pro validaci
     * i pro skutečné uložení, aby se validovalo přesně to, co se zapíše.
     *
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function buildOverrideRow(
        string $rulesetId,
        string $domain,
        ?array $existing,
        array $patch,
        string $reason,
        ?string $lifecycle,
    ): array {
        $existingData = self::overrideData($existing ?? []);
        $parameters = $existingData['parameters'];
        foreach (self::patchParameters($patch) as $key => $value) {
            if ($value === null) {
                unset($parameters[$key]);
                continue;
            }
            $parameters[$key] = PayrollRuleValue::fromCanonicalArray($value)->toCanonicalArray();
        }
        ksort($parameters, SORT_STRING);

        $sources = self::patchSources($patch) ?? $existingData['sources'];

        $row = [
            'ruleset_id' => $rulesetId,
            'domain' => $domain,
            'version' => self::patchString($patch, 'version')
                ?? self::nullableStr($existing, 'version'),
            'effective_from' => self::patchDate($patch, 'effective_from')
                ?? self::nullableStr($existing, 'effective_from'),
            'effective_to' => self::patchDate($patch, 'effective_to')
                ?? self::nullableStr($existing, 'effective_to'),
            'lifecycle' => $lifecycle ?? self::nullableStr($existing, 'lifecycle'),
            'capability' => self::patchEnum($patch, 'capability', ['supported', 'manual_review'])
                ?? self::nullableStr($existing, 'capability'),
            'data' => CanonicalJson::encode([
                'parameters' => $parameters,
                'sources' => $sources,
            ]),
            'reason' => $reason,
            'reviewed_by' => self::nullableInt($existing, 'reviewed_by'),
            'reviewed_at' => self::nullableStr($existing, 'reviewed_at'),
            'approved_by' => self::nullableInt($existing, 'approved_by'),
            'approved_at' => self::nullableStr($existing, 'approved_at'),
            'updated_at' => self::nullableStr($existing, 'updated_at'),
        ];
        $row['content_hash'] = PayrollRulesetOverrideHash::hash($row);

        return $row;
    }

    /**
     * Ověří, že by runtime registry sloučenou sadu uměl sestavit. Bez toho by
     * uložený překryv účinností položil `PayrollRulesetProvider` a s ním celé
     * mzdy — registry sice umí degradovat na default, ale tichá degradace
     * s neplatným overridem v DB je horší než odmítnuté uložení.
     *
     * @param array<string, mixed> $candidate
     */
    private function assertConsistent(string $rulesetId, array $candidate): void
    {
        $default = $this->registry->defaultVersion($rulesetId);
        try {
            $merged = PayrollRulesetRegistry::merge($default, $candidate);
        } catch (\Throwable $e) {
            throw new PayrollRulesetGovernanceException(
                'invalid_ruleset',
                'Ruleset by v této podobě nešel použít: ' . $e->getMessage(),
            );
        }

        $versions = [$merged];
        foreach ($this->registry->effective() as $entry) {
            if ($entry['version']->id !== $rulesetId) {
                $versions[] = $entry['version'];
            }
        }

        try {
            new PayrollRulesetProvider($versions);
        } catch (\Throwable $e) {
            throw new PayrollRulesetGovernanceException(
                'effective_overlap',
                'Sada by po této změně nešla jednoznačně vybrat: ' . $e->getMessage(),
            );
        }
    }

    /**
     * @return list<array{code:string, message:string, context:array<string, mixed>}>
     */
    private function coverageIssuesFor(PayrollRulesetVersion $version): array
    {
        $intervals = [];
        foreach ($this->registry->effective() as $entry) {
            if ($entry['version']->lifecycle === PayrollRulesetLifecycle::Superseded) {
                continue;
            }
            if ($entry['version']->domain !== $version->domain) {
                continue;
            }
            $intervals[] = self::intervalOf($entry);
        }

        return PayrollRulesetCoverage::issues($intervals);
    }

    /**
     * @param array{version:PayrollRulesetVersion,override:array<string,mixed>|null,is_override:bool,has_default:bool,default:PayrollRulesetVersion|null} $entry
     * @return array<string, mixed>
     */
    private function summary(array $entry): array
    {
        $version = $entry['version'];
        $override = $entry['override'];
        $next = self::nextCommand($version->lifecycle, $version->origin);

        return [
            'ruleset_id' => $version->id,
            'domain' => $version->domain->value,
            'version' => $version->version,
            'effective_from' => $version->effectiveFrom,
            'effective_to' => $version->effectiveTo,
            'lifecycle' => $version->lifecycle->value,
            'capability' => $version->capability->value,
            'canonical_hash' => $version->contentHash,
            // Odkud hodnoty jsou. V přehledu, ne až v detailu: doložení zdrojem
            // je náhrada za zrušené odklikávání, takže musí být vidět bez klikání.
            'origin' => $version->origin->value,
            'sources' => array_map(
                static fn (RulesetSource $source): array => $source->toCanonicalArray(),
                $version->sources,
            ),
            'is_override' => $entry['is_override'],
            'has_default' => $entry['has_default'],
            'checksum_valid' => $override === null || PayrollRulesetOverrideHash::matches($override),
            'calculation_ready' => $version->lifecycle === PayrollRulesetLifecycle::Active
                && $version->capability === PayrollRulesetCapability::Supported,
            'reason' => self::nullableStr($override, 'reason'),
            'technical_review' => $version->technicalReview?->toCanonicalArray(),
            'approval' => $version->approval?->toCanonicalArray(),
            'updated_by' => self::nullableInt($override, 'updated_by'),
            'updated_at' => self::nullableStr($override, 'updated_at'),
            'reviewed_by' => self::nullableInt($override, 'reviewed_by'),
            'approved_by' => self::nullableInt($override, 'approved_by'),
            'activated_by' => self::nullableInt($override, 'activated_by'),
            'row_version' => self::nullableInt($override, 'row_version') ?? 0,
            'parameter_count' => count($version->parameters),
            'manual_review_parameters' => self::manualReviewKeys($version),
            'next_command' => $next,
            'blockers' => $next === null ? [] : $this->blockers($entry, $next),
            'warnings' => $this->warnings($entry),
        ];
    }

    private function previousRulesetId(PayrollRulesetVersion $version): ?string
    {
        $previous = null;
        foreach ($this->registry->effective() as $entry) {
            $candidate = $entry['version'];
            if ($candidate->id === $version->id || $candidate->domain !== $version->domain) {
                continue;
            }
            if ($candidate->effectiveFrom >= $version->effectiveFrom) {
                continue;
            }
            if ($previous === null || $candidate->effectiveFrom > $previous->effectiveFrom) {
                $previous = $candidate;
            }
        }

        return $previous?->id;
    }

    private function assertStorage(): void
    {
        if (!$this->overrides->isAvailable()) {
            throw new PayrollRulesetGovernanceException(
                'storage_unavailable',
                'Úložiště overridů rulesetů není k dispozici — chybí migrace 1306.',
            );
        }
    }

    /**
     * Sloupce, které se zapisují do overridu. Actor sloupce doplňuje repozitář.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function writeValues(array $row): array
    {
        return [
            'version' => $row['version'] ?? null,
            'effective_from' => $row['effective_from'] ?? null,
            'effective_to' => $row['effective_to'] ?? null,
            'lifecycle' => $row['lifecycle'] ?? null,
            'capability' => $row['capability'] ?? null,
            'data' => $row['data'] ?? '{}',
            'content_hash' => $row['content_hash'] ?? '',
            'reason' => $row['reason'] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $override
     * @return array{parameters: array<string, array<mixed>>, sources: list<array<mixed>>}
     */
    private static function overrideData(array $override): array
    {
        $raw = $override['data'] ?? null;
        $decoded = is_string($raw) && $raw !== ''
            ? json_decode($raw, true, 64, JSON_THROW_ON_ERROR)
            : [];
        $parameters = [];
        $sources = [];
        if (is_array($decoded)) {
            $rawParameters = $decoded['parameters'] ?? null;
            if (is_array($rawParameters)) {
                foreach ($rawParameters as $key => $value) {
                    if (is_string($key) && is_array($value)) {
                        $parameters[$key] = $value;
                    }
                }
            }
            $rawSources = $decoded['sources'] ?? null;
            if (is_array($rawSources)) {
                foreach ($rawSources as $value) {
                    if (is_array($value)) {
                        $sources[] = $value;
                    }
                }
            }
        }

        return ['parameters' => $parameters, 'sources' => $sources];
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, array<string, mixed>|null>
     */
    private static function patchParameters(array $patch): array
    {
        $raw = $patch['parameters'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $result = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if ($value === null) {
                $result[$key] = null;
                continue;
            }
            if (!is_array($value)) {
                throw new PayrollRulesetGovernanceException(
                    'invalid_parameter',
                    "Parametr {$key} musí být objekt s poli type a value.",
                );
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
     * @param array<string, mixed> $patch
     * @return list<array<string, mixed>>|null
     */
    private static function patchSources(array $patch): ?array
    {
        $raw = $patch['sources'] ?? null;
        if (!is_array($raw)) {
            return null;
        }
        $sources = [];
        foreach ($raw as $value) {
            if (!is_array($value)) {
                continue;
            }
            $normalized = [];
            foreach ($value as $field => $item) {
                if (is_string($field)) {
                    $normalized[$field] = $item;
                }
            }
            $sources[] = $normalized;
        }
        if ($sources === []) {
            return null;
        }
        // Průchod hodnotovým objektem = validace HTTPS URL a data stažení.
        PayrollRulesetContent::sources(['sources' => $sources]);

        return $sources;
    }

    /** @param array<string, mixed> $patch */
    private static function patchString(array $patch, string $key): ?string
    {
        $value = $patch[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $patch */
    private static function patchDate(array $patch, string $key): ?string
    {
        $value = self::patchString($patch, $key);
        if ($value === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new PayrollRulesetGovernanceException(
                'invalid_date',
                "Pole {$key} musí být datum ve tvaru YYYY-MM-DD.",
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $patch
     * @param list<string> $allowed
     */
    private static function patchEnum(array $patch, string $key, array $allowed): ?string
    {
        $value = self::patchString($patch, $key);
        if ($value === null) {
            return null;
        }
        if (!in_array($value, $allowed, true)) {
            throw new PayrollRulesetGovernanceException(
                'invalid_value',
                "Pole {$key} musí být jedno z: " . implode(', ', $allowed) . '.',
            );
        }

        return $value;
    }

    private static function assertReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PayrollRulesetGovernanceException(
                'reason_required',
                'Změna legislativního rulesetu vyžaduje slovní důvod.',
            );
        }
        if (mb_strlen($reason) > 1000) {
            throw new PayrollRulesetGovernanceException(
                'reason_too_long',
                'Důvod změny je delší než 1000 znaků.',
            );
        }

        return $reason;
    }

    /** @return list<string> klíče parametrů, u kterých aplikace vědomě netvrdí hodnotu */
    private static function manualReviewKeys(PayrollRulesetVersion $version): array
    {
        $manual = [];
        foreach ($version->parameters as $key => $parameter) {
            if ($parameter->capability === PayrollRulesetCapability::ManualReview) {
                $manual[] = $key;
            }
        }

        return $manual;
    }

    /**
     * @param array{version:PayrollRulesetVersion,override:array<string,mixed>|null,is_override:bool,has_default:bool,default:PayrollRulesetVersion|null} $entry
     * @return array{id:int, effective_from:string, effective_to:string, ruleset_id:string}
     */
    private static function intervalOf(array $entry): array
    {
        return [
            'id' => 0,
            'effective_from' => $entry['version']->effectiveFrom,
            'effective_to' => $entry['version']->effectiveTo,
            'ruleset_id' => $entry['version']->id,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return list<array<string, mixed>>
     */
    private static function parameterList(array $snapshot): array
    {
        $raw = $snapshot['parameters'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $domain = is_string($snapshot['domain'] ?? null) ? (string) $snapshot['domain'] : '';
        $parameters = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                continue;
            }
            // Český název, popis výčtové hodnoty a vysvětlení ručního posouzení
            // se přibalují až tady: do kanonického snapshotu (a tím do otisku
            // a auditní stopy) nepatří, ale bez nich je administrace čitelná
            // jen pro toho, kdo napsal klíče.
            $explanation = PayrollRuleParameterCatalog::manualReview($domain, $key);
            $parameters[] = [
                'key' => $key,
                'label' => PayrollRuleParameterCatalog::label($domain, $key),
                'type' => $value['type'] ?? null,
                'value' => $value['value'] ?? null,
                'value_label' => PayrollRuleParameterCatalog::valueLabel($value['value'] ?? null),
                'capability' => $value['capability'] ?? null,
                'note' => $value['note'] ?? null,
                'manual_review_why' => $explanation['why'] ?? null,
                'manual_review_action' => $explanation['action'] ?? null,
            ];
        }
        usort($parameters, static fn (array $a, array $b): int => (string) $a['key'] <=> (string) $b['key']);

        return $parameters;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return list<array<string, mixed>>
     */
    private static function sourceList(array $snapshot): array
    {
        $raw = $snapshot['sources'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $sources = [];
        foreach ($raw as $value) {
            if (is_array($value)) {
                $sources[] = [
                    'id' => $value['id'] ?? null,
                    'title' => $value['title'] ?? null,
                    'url' => $value['url'] ?? null,
                    'retrieved_on' => $value['retrieved_on'] ?? null,
                ];
            }
        }

        return $sources;
    }

    private static function targetOf(string $command): PayrollRulesetLifecycle
    {
        return match ($command) {
            'review' => PayrollRulesetLifecycle::Reviewed,
            'approve' => PayrollRulesetLifecycle::Approved,
            'activate' => PayrollRulesetLifecycle::Active,
            'supersede' => PayrollRulesetLifecycle::Superseded,
            default => throw new PayrollRulesetGovernanceException(
                'unknown_command',
                "Neznámý příkaz rulesetu {$command}.",
            ),
        };
    }

    /**
     * Nad ÚČINNOU DODANOU sadou se žádný další krok nenabízí. Vyřazení dodané sady
     * není položka fronty, ale následek toho, že ji nahradila novější verze —
     * nabízet ho jako hlavní akci u každé domény by z „nemáte co odklikávat"
     * udělalo tlačítko, kterým si zákazník vypne výpočet.
     */
    private static function nextCommand(
        PayrollRulesetLifecycle $lifecycle,
        PayrollRulesetOrigin $origin,
    ): ?string {
        return match ($lifecycle) {
            PayrollRulesetLifecycle::Draft => 'review',
            PayrollRulesetLifecycle::Reviewed => 'approve',
            PayrollRulesetLifecycle::Approved => 'activate',
            PayrollRulesetLifecycle::Active => $origin === PayrollRulesetOrigin::Vendor
                ? null
                : 'supersede',
            PayrollRulesetLifecycle::Superseded => null,
        };
    }

    /** @param array<string, mixed>|null $row */
    private static function nullableStr(?array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed>|null $row */
    private static function nullableInt(?array $row, string $field): ?int
    {
        $value = $row[$field] ?? null;
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^[0-9]+$/', $value) === 1 ? (int) $value : null;
    }

    /** @param array<array-key, mixed> $row */
    private static function strv(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        return is_string($value) ? $value : '';
    }
}
