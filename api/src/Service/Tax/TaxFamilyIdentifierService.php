<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;

/**
 * Rodná čísla dětí a manžela/manželky z daňového profilu (H28).
 *
 * Profil je drží zapečetěná ({@see TaxProfileRepository}) a ven posílá jen masku.
 * Tahle třída je jediné místo, kde se s otevřenou hodnotou pracuje, a zná tři
 * cesty ven, stejně jako mzdy u vyživovaných osob:
 *
 *  - ZÁKONNÁ NÁLEŽITOST PODÁNÍ: XML přiznání DPFO rodná čísla nést musí. Doplní
 *    se těsně před sestavením XML ({@see withFilingIdentifiers()}) a do JSON
 *    odpovědí ani do uloženého výpočtu nepotečou;
 *  - VÝSLOVNÉ ODHALENÍ: jen v přihlášené relaci s právem zápisu do daní,
 *    s textovým důvodem a zápisem do auditu ({@see revealOnRequest()});
 *  - API TOKEN A MCP: výpočet potřebuje jen počty měsíců a částky, takže
 *    z profilu odchází bez jmen, rodných čísel a příjmu manžela
 *    ({@see bearerProfile()}), z XML bez rodných čísel ({@see stripXmlIdentifiers()}).
 */
final class TaxFamilyIdentifierService
{
    /** Právo, které k plnému rodnému číslu (odhalení, XML s rodnými čísly) vede. */
    public const PERMISSION = 'reports';

    public function __construct(
        private readonly TaxProfileRepository $profiles,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly PermissionChecker $permissionChecker,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Má osoba (dítě, manžel/ka) uložené rodné číslo? Profil nese masku a příznak,
     * starší uložený výpočet ještě otevřenou hodnotu; kontroly úplnosti před
     * podáním se ptají tímhle, ne na samotné `birth_number`.
     *
     * @param array<string,mixed> $person
     */
    public static function hasBirthNumber(array $person): bool
    {
        return trim((string) ($person['birth_number'] ?? '')) !== ''
            || !empty($person['has_birth_number']);
    }

    /**
     * Výpočet DPFO s doplněnými otevřenými rodnými čísly — výhradně pro sestavení
     * XML přiznání. Výsledek se nesmí vracet klientovi ani ukládat.
     *
     * Dítě nebo manžel/ka s uloženým číslem, které se nepodařilo dohledat, je
     * chyba: XML by jinak tiše neslo datum narození místo rodného čísla.
     *
     * @param array<string,mixed> $result výstup {@see \MyInvoice\Service\Tax\Return\DpfoReturnCalculator::compute()}
     * @return array<string,mixed>
     */
    public function withFilingIdentifiers(int $supplierId, int $year, array $result): array
    {
        $family = is_array($result['family'] ?? null) ? $result['family'] : [];
        $needsReveal = false;
        foreach ((array) ($family['children'] ?? []) as $child) {
            $needsReveal = $needsReveal || (is_array($child) && $this->needsFilingValue($child));
        }
        if (is_array($family['spouse'] ?? null) && $this->needsFilingValue($family['spouse'])) {
            $needsReveal = true;
        }
        if (!$needsReveal) {
            return $result;
        }

        $plain = $this->profiles->familyBirthNumbers($supplierId, $year, PayrollRevealPurpose::SUBMISSION_INCOME_TAX_RETURN);
        foreach ((array) ($family['children'] ?? []) as $i => $child) {
            if (is_array($child) && $this->needsFilingValue($child)) {
                $family['children'][$i]['birth_number'] = $this->filingValue($plain['children'], $child, 'dítěte');
            }
        }
        if (is_array($family['spouse'] ?? null) && $this->needsFilingValue($family['spouse'])) {
            $family['spouse']['birth_number'] = $this->filingValue($plain['spouse'], $family['spouse'], 'manžela/manželky');
        }
        $result['family'] = $family;

        return $result;
    }

    /**
     * Odhalení rodných čísel profilu na výslovnou žádost uživatele.
     *
     * Brána je stejná jako u mezd ({@see \MyInvoice\Service\Payroll\Security\PayrollPersonSensitiveRevealService}):
     * právo, textový důvod a auditní stopa. Do auditu jde, CO se odhalilo, ne hodnota.
     *
     * @return array{year:int, children: list<array{id:int, birth_number:string}>, spouse: ?array{id:int, birth_number:string}}
     */
    public function revealOnRequest(
        int $supplierId,
        int $year,
        int $actorUserId,
        EffectiveRole $role,
        string $reason,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        if ($supplierId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException('Firma i uživatel musí mít kladné ID.');
        }
        $this->permissionChecker->require($role, self::PERMISSION, AccessLevel::WRITE);
        $reason = self::reason($reason);

        $plain = $this->profiles->familyBirthNumbers($supplierId, $year, PayrollRevealPurpose::TAX_FAMILY_REVEAL);
        $children = [];
        foreach ($plain['children'] as $id => $value) {
            $children[] = ['id' => (int) $id, 'birth_number' => $value];
        }
        $spouse = null;
        foreach ($plain['spouse'] as $id => $value) {
            $spouse = ['id' => (int) $id, 'birth_number' => $value];
        }

        $this->activity->log(
            action: 'tax_profile.family_revealed',
            userId: $actorUserId,
            entityType: 'tax_profile',
            entityId: null,
            payload: [
                'reason' => $reason,
                'year' => $year,
                'children_count' => count($children),
                'spouse' => $spouse !== null,
            ],
            ip: $ip,
            userAgent: $userAgent,
            supplierId: $supplierId,
        );

        return ['year' => $year, 'children' => $children, 'spouse' => $spouse];
    }

    /**
     * Profil bez otevřených rodných čísel. Profil z repozitáře už je maskovaný;
     * tohle chytá starší uložené výpočty finálních přiznání, které otevřenou
     * hodnotu ještě nesou.
     *
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public function maskProfile(array $profile): array
    {
        if (is_array($profile['children'] ?? null)) {
            foreach ($profile['children'] as $i => $child) {
                if (is_array($child)) {
                    $profile['children'][$i] = $this->maskPerson($child);
                }
            }
        }
        if (is_array($profile['spouse_claim'] ?? null)) {
            $profile['spouse_claim'] = $this->maskPerson($profile['spouse_claim']);
        }

        return $profile;
    }

    /**
     * Výpočet DPFO (`family`) bez otevřených rodných čísel.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function maskResult(array $result): array
    {
        if (!is_array($result['family'] ?? null)) {
            return $result;
        }
        if (is_array($result['family']['children'] ?? null)) {
            foreach ($result['family']['children'] as $i => $child) {
                if (is_array($child)) {
                    $result['family']['children'][$i] = $this->maskPerson($child);
                }
            }
        }
        if (is_array($result['family']['spouse'] ?? null)) {
            $result['family']['spouse'] = $this->maskPerson($result['family']['spouse']);
        }

        return $result;
    }

    /**
     * Podklady přiznání (`profile`) bez otevřených rodných čísel.
     *
     * @param array<string,mixed> $podklady
     * @return array<string,mixed>
     */
    public function maskPodklady(array $podklady): array
    {
        if (is_array($podklady['profile'] ?? null)) {
            $podklady['profile'] = $this->maskProfile($podklady['profile']);
        }

        return $podklady;
    }

    /**
     * Profil pro API token a MCP: z dětí a manžela/manželky zůstanou jen údaje,
     * ze kterých se počítá sleva a zvýhodnění (měsíce, pořadí, ZTP/P, doložení).
     * Jména, rodná čísla, masky, data narození, odkazy na doklady a příjem
     * manžela/manželky token nedostane.
     *
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public static function bearerProfile(array $profile): array
    {
        if (is_array($profile['children'] ?? null)) {
            $profile['children'] = array_values(array_map(
                static fn (mixed $child): array => is_array($child) ? self::pick($child, [
                    'id', 'order_index', 'months', 'shared_household_proved', 'other_parent_not_claimed_proved',
                ]) : [],
                $profile['children'],
            ));
        }
        if (is_array($profile['spouse_claim'] ?? null)) {
            $profile['spouse_claim'] = self::bearerSpouse($profile['spouse_claim']);
        }

        return $profile;
    }

    /**
     * Stav přiznání ({@see \MyInvoice\Service\Tax\Return\TaxReturnService::getReturn()})
     * pro API token a MCP: `computed.family` i `podklady.profile` bez osobních údajů rodiny.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public static function bearerReturnState(array $state): array
    {
        if (is_array($state['computed']['family'] ?? null)) {
            $family = $state['computed']['family'];
            if (is_array($family['children'] ?? null)) {
                $family['children'] = self::bearerProfile(['children' => $family['children']])['children'];
            }
            if (is_array($family['spouse'] ?? null)) {
                $family['spouse'] = self::bearerSpouse($family['spouse']);
            }
            $state['computed']['family'] = $family;
        }
        if (is_array($state['podklady']['profile'] ?? null)) {
            $state['podklady']['profile'] = self::bearerProfile($state['podklady']['profile']);
        }

        return $state;
    }

    /**
     * XML přiznání DPFO bez rodných čísel dětí (VetaA `vyzdite_r_cislo`) a manžela
     * nebo manželky (VetaD `manz_r_cislo`) — pro čtení přes API token nebo bez
     * práva zápisu do daní. XML bez těchto atributů se vrací beze změny, bajt po bajtu.
     */
    public static function stripXmlIdentifiers(string $xml): string
    {
        if (!str_contains($xml, 'vyzdite_r_cislo') && !str_contains($xml, 'manz_r_cislo')) {
            return $xml;
        }
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            throw new \RuntimeException('XML přiznání se nepodařilo načíst.');
        }
        foreach (['VetaA' => 'vyzdite_r_cislo', 'VetaD' => 'manz_r_cislo'] as $element => $attribute) {
            foreach ($dom->getElementsByTagName($element) as $node) {
                if ($node instanceof \DOMElement && $node->hasAttribute($attribute)) {
                    $node->removeAttribute($attribute);
                }
            }
        }
        $out = $dom->saveXML();
        if ($out === false) {
            throw new \RuntimeException('XML přiznání se nepodařilo sestavit.');
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function maskPerson(array $person): array
    {
        $plain = trim((string) ($person['birth_number'] ?? ''));
        if ($plain !== '') {
            $person['birth_number_masked'] = $this->sensitiveData->mask($plain, TaxProfileRepository::FAMILY_FIELD);
            $person['has_birth_number'] = true;
        }
        if (array_key_exists('birth_number', $person)) {
            $person['birth_number'] = null;
        }

        return $person;
    }

    /**
     * @param array<string,mixed> $spouse
     * @return array<string,mixed>
     */
    private static function bearerSpouse(array $spouse): array
    {
        return self::pick($spouse, [
            'id', 'eligible_months', 'ztpp', 'income_proved', 'shared_household_proved', 'child_under_three_proved',
        ]);
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    private static function pick(array $source, array $keys): array
    {
        return array_intersect_key($source, array_flip($keys));
    }

    /** @param array<string,mixed> $person */
    private function needsFilingValue(array $person): bool
    {
        return !empty($person['has_birth_number']) && trim((string) ($person['birth_number'] ?? '')) === '';
    }

    /**
     * @param array<int,string> $plain
     * @param array<string,mixed> $person
     */
    private function filingValue(array $plain, array $person, string $label): string
    {
        $id = (int) ($person['id'] ?? 0);
        if ($id <= 0 || !isset($plain[$id])) {
            throw new \DomainException(
                'Rodné číslo ' . $label . ' z daňového profilu se nepodařilo načíst. Načtěte profil znovu a přiznání sestavte ještě jednou.',
            );
        }

        return $plain[$id];
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        $length = mb_strlen($reason, 'UTF-8');
        if ($length < 10 || $length > 500) {
            throw new \InvalidArgumentException('Důvod odhalení musí mít 10 až 500 znaků.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $reason) === 1) {
            throw new \InvalidArgumentException('Důvod odhalení obsahuje řídicí znak.');
        }

        return $reason;
    }
}
