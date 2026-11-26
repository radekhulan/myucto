<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;

/**
 * Jediné místo shody postavení konceptu J6 s potvrzeným právním profilem DPPO období (#402 krok 2).
 *
 * Stejné postavení = stejný `kind` profilu. Koncept při otevření a resetu převezme postavení a podklad
 * z potvrzeného profilu a připne jeho revizi do hlavičky (`legal_profile`). Připnutí patří do hlavičky
 * konceptu, ne do snímku podkladů: snímek je obsah účetních podkladů a změna jeho otisku by zneplatnila
 * všechny koncepty a revize období. Hlavička jde do otisku rozhodnutí a do `decision_header` potvrzené revize.
 *
 * Náhled a potvrzení stránkované cesty shodu ověřují zde; legacy cesta dál kontroluje profil ze snímku
 * v J6AuthoritativeCalculator. Výpočet DPPO porovnává kind sám (NonprofitDppoDataProvider).
 */
final readonly class J6LegalProfileMatch
{
    public const STATES = ['none', 'manual', 'pinned_current', 'pinned_same_kind_outdated', 'conflict'];

    public function __construct(private NonprofitLegalProfileService $profiles) {}

    /** Potvrzený profil a důkaz modulu SVJ; $currentRead jen uvnitř transakce se zámkem období. */
    public function context(int $supplierId, int $periodId, bool $currentRead = false): array
    {
        return $this->profiles->assessmentContext($supplierId, $periodId, $currentRead);
    }

    /**
     * Postavení, které koncept tvrdí: kind profilu, u ručního posouzení doložená identita
     * `entity_kind`. Ruční posouzení bez ní se s profilem ani s modulem SVJ neporovnává (null).
     * Záměrná odchylka od legacy kalkulátoru (bere ji jako 'documented' a odmítne ji): stránkovaná
     * cesta tyto koncepty dosud pouštěla a kontrola by zablokovala každý starší ruční koncept
     * bez identity (rozhodnutí k návrhu, R5). Nový ruční koncept s potvrzeným profilem identitu
     * dostane převzetím (adopt), takže výjimka se týká jen konceptů vzniklých bez profilu.
     */
    public static function asserted(array $header): ?string
    {
        if (array_key_exists('method', $header)) {
            $kind = $header['profile']['entity_kind'] ?? null;
            return is_string($kind) ? $kind : null;
        }
        $kind = $header['profile']['kind'] ?? null;
        return is_string($kind) ? $kind : null;
    }

    /**
     * Profil hlavičky otevíraného nebo resetovaného konceptu. S potvrzeným profilem převezme
     * postavení a podklad z něj a připne revizi; postavení v těle musí souhlasit (jinak 422).
     * Bez profilu zůstává ruční zadání.
     */
    public static function adopt(array $context, ?array $requested, ?array $method): array
    {
        $proof = $context['legal_profile'] ?? null;
        if ($proof === null) {
            $header = ['profile' => $requested ?? []];
            if ($method !== null) $header['method'] = $method;
            return $header;
        }
        $kind = (string) $proof['profile']['kind'];
        $requestedKind = $method === null ? ($requested['kind'] ?? null) : ($requested['entity_kind'] ?? null);
        if ($requested !== null && $method !== null && array_key_exists('kind', $requested) && $requested['kind'] !== 'documented') {
            self::mismatch($requested['kind'], $kind);
        }
        if ($requestedKind !== null && $requestedKind !== $kind) self::mismatch($requestedKind, $kind);
        $profile = $method === null
            ? ['kind' => $kind, 'evidence' => $proof['profile']['evidence']]
            : ['kind' => 'documented', 'evidence' => $proof['profile']['evidence'], 'entity_kind' => $kind];
        $header = ['profile' => $profile, 'legal_profile' => self::pin($proof)];
        if ($method !== null) $header['method'] = $method;
        return $header;
    }

    /**
     * Hlavička s připnutou aktuální revizí profilu stejného postavení; rozhodnutí konceptu
     * zůstávají. Jiné postavení nebo chybějící profil = 409 (cesta je reset konceptu).
     */
    public static function repin(array $header, array $context): array
    {
        $proof = $context['legal_profile'] ?? null;
        if ($proof === null) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_legal_profile_missing',
                'Období nemá potvrzený právní profil DPPO, který by šlo připnout.', 409);
        }
        $asserted = self::asserted($header);
        if ($asserted !== $proof['profile']['kind']) self::conflict($asserted, (string) $proof['profile']['kind']);
        $header['profile']['evidence'] = $proof['profile']['evidence'];
        $header['legal_profile'] = self::pin($proof);
        return $header;
    }

    /**
     * Shoda hlavičky s potvrzeným profilem a s důkazem modulu SVJ (náhled, potvrzení).
     * Rozdílná revize při stejném postavení shodu neporušuje.
     */
    public static function assertMatches(array $header, array $context): void
    {
        $asserted = self::asserted($header);
        if ($asserted === null) return;
        if (($context['svj_module_proof'] ?? []) !== [] && $asserted !== 'svj') self::conflict($asserted, 'svj');
        $proof = $context['legal_profile'] ?? null;
        if ($proof !== null && $asserted !== $proof['profile']['kind']) self::conflict($asserted, (string) $proof['profile']['kind']);
    }

    /** Stav pro UI a připravenost; bez konceptu (null) jen aktuální profil. */
    public static function state(?array $header, array $context): array
    {
        $proof = $context['legal_profile'] ?? null;
        $current = $proof === null ? null : ['id' => $proof['id'], 'revision_no' => $proof['revision_no'],
            'kind' => $proof['profile']['kind'], 'entity_type' => $proof['profile']['entity_type'] ?? null];
        if ($header === null) return ['state' => null, 'current' => $current, 'pinned' => null, 'pinnable' => false];
        $pin = is_array($header['legal_profile'] ?? null) ? $header['legal_profile'] : null;
        $pinned = $pin === null ? null : ['id' => $pin['id'], 'revision_no' => $pin['revision_no']];
        try {
            self::assertMatches($header, $context);
        } catch (NonprofitTaxAssessmentException) {
            return ['state' => 'conflict', 'current' => $current, 'pinned' => $pinned, 'pinnable' => false];
        }
        $state = match (true) {
            $proof === null => 'none',
            $pin === null => 'manual',
            self::isCurrent($pin, $proof) => 'pinned_current',
            default => 'pinned_same_kind_outdated',
        };
        // Připnout jde jen koncept, který postavení profilu skutečně tvrdí (ne ruční bez identity).
        $pinnable = in_array($state, ['manual', 'pinned_same_kind_outdated'], true) && self::asserted($header) === $proof['profile']['kind'];
        return ['state' => $state, 'current' => $current, 'pinned' => $pinned, 'pinnable' => $pinnable];
    }

    /** Hlavička už nese připnutí aktuální revize; nové připnutí by nic nezměnilo. */
    public static function pinsCurrent(array $header, array $context): bool
    {
        $pin = $header['legal_profile'] ?? null;
        $proof = $context['legal_profile'] ?? null;
        return is_array($pin) && $proof !== null && self::isCurrent($pin, $proof);
    }

    private static function isCurrent(array $pin, array $proof): bool
    {
        return ($pin['id'] ?? null) === $proof['id'] && hash_equals($proof['record_hash'], (string) ($pin['record_hash'] ?? ''));
    }

    /** Otisk hlavy profilu pro identitu cache náhledu (bez profilu prázdný řetězec). */
    public static function headHash(array $context): string
    {
        return (string) ($context['legal_profile']['record_hash'] ?? '');
    }

    /** Připnutí = podmnožina NonprofitLegalProfileProof: identita a otisk revize, ne celý obsah profilu. */
    private static function pin(array $proof): array
    {
        return ['id' => $proof['id'], 'revision_no' => $proof['revision_no'], 'record_hash' => $proof['record_hash']];
    }

    private static function mismatch(mixed $requested, string $kind): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_tax_legal_profile_mismatch',
            sprintf('Postavení „%s“ nesouhlasí s potvrzeným právním profilem DPPO období („%s“). Postavení se převezme z profilu.',
                is_string($requested) ? $requested : '?', $kind), 422);
    }

    private static function conflict(?string $asserted, string $kind): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_tax_legal_profile_conflict',
            sprintf('Postavení konceptu („%s“) nesouhlasí s potvrzeným právním profilem období („%s“). Koncept resetujte, převezme aktuální profil.',
                $asserted ?? 'documented', $kind), 409);
    }
}
