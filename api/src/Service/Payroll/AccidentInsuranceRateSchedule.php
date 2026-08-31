<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Sazebník zákonného pojištění odpovědnosti zaměstnavatele — příloha č. 2
 * vyhlášky č. 125/1993 Sb. Jediná čtecí cesta k připnutému souboru
 * v `api/resources/payroll/accident-insurance-rates`.
 *
 * ── Proč je tenhle sazebník JEN PODKLAD, ne odpověď ───────────────────────
 *
 * Příloha č. 2 člení činnosti podle **OKEČ** — doslova: „Členění ekonomických
 * činností bylo převzato z Odvětvové klasifikace ekonomických činností (OKEČ)
 * zpracované Českým statistickým úřadem." OKEČ byla zrušena k 31. 12. 2007
 * a nahrazena CZ-NACE, jenže vyhláška se od roku 2002 v sazbách nezměnila
 * a v seznamu činností dokonce od roku 1993. Závazná je tedy pořád klasifikace,
 * kterou dnes nikdo nepřiděluje.
 *
 * Převod CZ-NACE → OKEČ přitom NENÍ jednoznačný a **na úrovni čísla je přímo
 * zavádějící**: OKEČ 62 = „Letecká doprava", CZ-NACE 62 = činnosti v oblasti
 * informačních technologií. Software house by tak podle čísla dostal sazbu
 * letecké dopravy. Proto se tady čísla NIKDY nepárují — návrh se hledá jen
 * podle NÁZVU činnosti a je označený jako nezávazný.
 *
 * Navíc dvě z osmi sazbových skupin nemají kód vůbec: 10,5 ‰ je dané věcným
 * kritériem (práce s výbušninami, radioaktivními látkami, radonem, infekčním
 * materiálem, jedy, práce ve velkých výškách nebo hloubkách) a 5,6 ‰ je
 * zbytková skupina „Ostatní ekonomické činnosti", do které spadne většina
 * dnešních firem. Ani dokonalý převodník kódů by tedy sazbu neurčil.
 *
 * Aplikace proto sazbu **nabízí k ověření**, netvrdí ji. Uloženou hodnotou
 * zůstává to, co zadá účetní podle sdělení pojišťovny.
 */
final class AccidentInsuranceRateSchedule
{
    public const PACKAGE_KEY = 'cz-accident-insurance-annex-2-v1';
    public const SCHEMA_VERSION = 'accident-insurance-rate-schedule.v1';
    public const DEFAULT_MANIFEST_SHA256 =
        '6a8117f291d1d34f7d6ebbcb49ac3f91c94d83ef4641bb967e9d6a9ffc7d09f4';

    /** Skupina daná výčtem kódů OKEČ. */
    public const KIND_CLASSIFIED = 'classified';
    /** Skupina daná věcným kritériem nebezpečnosti, bez kódu (10,5 ‰). */
    public const KIND_HAZARD = 'hazard';
    /** Zbytková skupina „Ostatní ekonomické činnosti" (5,6 ‰). */
    public const KIND_RESIDUAL = 'residual';

    public const DEFAULT_SUGGESTION_LIMIT = 5;
    public const MAX_SUGGESTION_LIMIT = 20;

    private const DIRECTORY = 'annex-2-2002-01-01';

    /**
     * Slova, která v názvech činností nic nerozlišují. Bez nich by „Výroba
     * nábytku" vypadala jako shoda se všemi čtyřiceti řádky začínajícími
     * „Výroba …" a našeptávač by byl k ničemu.
     *
     * @var list<string>
     */
    private const GENERIC_WORDS = [
        'vyroba', 'vyrobky', 'vyrobku', 'vyrobek', 'cinnost', 'cinnosti',
        'ostatni', 'sluzby', 'sluzeb', 'jinych', 'jine', 'krome', 'vcetne',
        'souvisejici', 'souvisejicich', 'oblasti', 'zpracovani', 'uprava',
        'ostatniho', 'podobne', 'podobnych', 'zarizeni', 'ktere', 'nebo',
    ];

    /** Nejkratší slovo, které se ještě porovnává (kratší nic neurčuje). */
    private const MIN_WORD_LENGTH = 5;

    /** @var array{manifest_sha256:string,payload:array<string,mixed>}|null */
    private ?array $manifest = null;

    public function __construct(private readonly ?string $resourceRoot = null) {}

    /**
     * Celý sazebník v pořadí přílohy. Má 8 skupin a 98 činností — na rozdíl od
     * CZ-ISCO (1 992 položek) se vejde do jedné odpovědi, takže filtrování
     * běží v prohlížeči a endpoint nemá vyhledávací parametr.
     *
     * @return list<array{ordinal:int,key:string,rate_per_mille:string,kind:string,label:?string,activities:list<array{ordinal:int,okec_code:string,label:string}>}>
     */
    public function groups(): array
    {
        $this->load();
        /** @var list<array{ordinal:int,key:string,rate_per_mille:string,kind:string,label:?string,activities:list<array{ordinal:int,okec_code:string,label:string}>}> $groups */
        $groups = $this->payload()['groups'];

        return $groups;
    }

    /** Právní identita sazebníku — co, z jaké novely a od kdy platí. @return array<string,mixed> */
    public function legal(): array
    {
        $this->load();
        /** @var array<string,mixed> $legal */
        $legal = $this->payload()['legal'];

        return $legal;
    }

    /** Minimální pojistné za kalendářní čtvrtletí v celých korunách (poslední věta přílohy č. 2). */
    public function minimumQuarterlyPremiumCzk(): int
    {
        return (int) $this->legal()['minimum_quarterly_premium_czk'];
    }

    /**
     * Sazby, které příloha zná, v pořadí přílohy. Slouží k tomu, aby formulář
     * uměl upozornit na ručně zadanou hodnotu mimo sazebník — ne aby ji zakázal.
     *
     * @return list<string>
     */
    public function rates(): array
    {
        return array_map(
            static fn (array $group): string => $group['rate_per_mille'],
            $this->groups(),
        );
    }

    /** Sazba je jednou ze sazeb přílohy č. 2. Vstup se porovnává číselně („4.2" = „4.20"). */
    public function isAnnexRate(string $ratePerMille): bool
    {
        $normalized = self::normalizeRate($ratePerMille);
        if ($normalized === null) {
            return false;
        }
        foreach ($this->rates() as $rate) {
            if ($rate === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{ordinal:int,key:string,rate_per_mille:string,kind:string,label:?string,activities:list<array{ordinal:int,okec_code:string,label:string}>}|null
     */
    public function findGroup(string $key): ?array
    {
        foreach ($this->groups() as $group) {
            if ($group['key'] === $key) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Nezávazný návrh podle NÁZVU činnosti (typicky názvu kódu CZ-NACE firmy).
     *
     * Záměrně se nepárují čísla — viz docblock třídy. Shoda se počítá jako počet
     * společných významových slov; slova se porovnávají prefixem, aby české
     * skloňování („nábytek" / „nábytku") shodu nerozbilo.
     *
     * Při stejném počtu shod vyhrává řádek, kterému shoda vysvětluje větší část
     * názvu. „Činnosti reklamních agentur" má jedno společné slovo s „Reklamní
     * činnosti" i s „Činnosti v oblasti nemovitostí (… realitní agentury)" —
     * první řádek je ale tou jednou shodou vysvětlený celý, druhý jen z osminy.
     *
     * @return list<array{group_key:string,rate_per_mille:string,okec_code:string,label:string,score:int}>
     * @throws \InvalidArgumentException Limit mimo rozsah.
     */
    public function suggestByActivityName(
        string $activityName,
        int $limit = self::DEFAULT_SUGGESTION_LIMIT,
    ): array {
        if ($limit < 1 || $limit > self::MAX_SUGGESTION_LIMIT) {
            throw new \InvalidArgumentException(
                'Limit návrhu sazby musí být od 1 do ' . self::MAX_SUGGESTION_LIMIT . '.',
            );
        }
        $needles = self::significantWords($activityName);
        if ($needles === []) {
            return [];
        }

        $scored = [];
        foreach ($this->groups() as $group) {
            foreach ($group['activities'] as $activity) {
                $words = self::significantWords($activity['label']);
                $score = self::overlap($needles, $words);
                if ($score === 0) {
                    continue;
                }
                $scored[] = [
                    'group_key' => $group['key'],
                    'rate_per_mille' => $group['rate_per_mille'],
                    'okec_code' => $activity['okec_code'],
                    'label' => $activity['label'],
                    'score' => $score,
                    'word_count' => count($words),
                    'ordinal' => $group['ordinal'] * 1000 + $activity['ordinal'],
                ];
            }
        }
        usort($scored, static function (array $a, array $b): int {
            // score desc, pak podíl score/word_count desc (křížovým součinem,
            // ať se nepočítá s floaty), nakonec pořadí v příloze asc.
            return [$b['score'], $b['score'] * $a['word_count'], $a['ordinal']]
                <=> [$a['score'], $a['score'] * $b['word_count'], $b['ordinal']];
        });

        return array_map(
            static fn (array $row): array => [
                'group_key' => $row['group_key'],
                'rate_per_mille' => $row['rate_per_mille'],
                'okec_code' => $row['okec_code'],
                'label' => $row['label'],
                'score' => $row['score'],
            ],
            array_slice($scored, 0, $limit),
        );
    }

    /** @return array{package_key:string,manifest_sha256:string,schema_version:string,group_count:int,activity_count:int} */
    public function provenance(): array
    {
        $this->load();
        $payload = $this->payload();
        /** @var array<string,mixed> $counts */
        $counts = $payload['counts'];

        return [
            'package_key' => (string) $payload['package_key'],
            'manifest_sha256' => (string) ($this->manifest['manifest_sha256'] ?? ''),
            'schema_version' => (string) $payload['schema_version'],
            'group_count' => (int) $counts['groups'],
            'activity_count' => (int) $counts['activities'],
        ];
    }

    /** `4,2` i `4.2` → `4.20`; nečíselný vstup → null. */
    public static function normalizeRate(string $ratePerMille): ?string
    {
        $value = str_replace(',', '.', trim($ratePerMille));
        if (preg_match('/\A([0-9]{1,4})(?:\.([0-9]{1,2}))?\z/D', $value, $match) !== 1) {
            return null;
        }

        return $match[1] . '.' . str_pad($match[2] ?? '', 2, '0', STR_PAD_RIGHT);
    }

    /**
     * Fail-closed kontrola manifestu — stejný důvod jako u {@see CzIscoCodebook}:
     * osekaný nebo prázdný soubor nesmí projít jako „sazebník, ve kterém nic není".
     *
     * @param array{manifest_sha256:string,payload:array<string,mixed>} $manifest
     */
    public static function validateManifest(array $manifest, bool $requirePinnedHash = false): void
    {
        $payload = $manifest['payload'];
        $actual = hash('sha256', CanonicalJson::encode($payload));
        if (!hash_equals($manifest['manifest_sha256'], $actual)
            || ($requirePinnedHash && !hash_equals(self::DEFAULT_MANIFEST_SHA256, $actual))
        ) {
            throw new \UnexpectedValueException('Sazebník úrazového pojištění nemá připnutý SHA-256.');
        }
        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($payload['package_key'] ?? null) !== self::PACKAGE_KEY
            || ($payload['parser_version'] ?? null) !== 1
        ) {
            throw new \UnexpectedValueException('Sazebník úrazového pojištění má neznámou identitu.');
        }
        $legal = $payload['legal'] ?? null;
        if (!is_array($legal)
            || ($legal['decree'] ?? null) !== '125/1993 Sb.'
            || ($legal['classification'] ?? null) !== 'OKEČ'
            || !is_int($legal['minimum_quarterly_premium_czk'] ?? null)
            || $legal['minimum_quarterly_premium_czk'] <= 0
        ) {
            throw new \UnexpectedValueException('Sazebník úrazového pojištění nemá platnou právní identitu.');
        }

        $groups = $payload['groups'] ?? null;
        if (!is_array($groups) || !array_is_list($groups) || $groups === []) {
            throw new \UnexpectedValueException('Sazebník úrazového pojištění nemá žádnou sazbovou skupinu.');
        }
        $seenKeys = [];
        $seenCodes = [];
        $activityCount = 0;
        $byGroup = [];
        $kinds = [];
        foreach ($groups as $ordinal => $group) {
            if (!is_array($group)
                || ($group['ordinal'] ?? null) !== $ordinal + 1
                || !is_string($group['key'] ?? null)
                || !is_string($group['rate_per_mille'] ?? null)
                || preg_match('/\A[0-9]{1,4}\.[0-9]{2}\z/D', $group['rate_per_mille']) !== 1
                || (float) $group['rate_per_mille'] <= 0
                || isset($seenKeys[$group['key']])
            ) {
                throw new \UnexpectedValueException('Sazbová skupina sazebníku není platný záznam.');
            }
            $seenKeys[$group['key']] = true;
            $kind = $group['kind'] ?? null;
            if (!in_array($kind, [self::KIND_CLASSIFIED, self::KIND_HAZARD, self::KIND_RESIDUAL], true)) {
                throw new \UnexpectedValueException("Sazbová skupina {$group['key']} má neznámý druh.");
            }
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            $activities = $group['activities'] ?? null;
            if (!is_array($activities) || !array_is_list($activities)) {
                throw new \UnexpectedValueException("Sazbová skupina {$group['key']} nemá seznam činností.");
            }
            if ($kind === self::KIND_CLASSIFIED) {
                if ($activities === [] || !is_null($group['label'] ?? null)) {
                    throw new \UnexpectedValueException("Sazbová skupina {$group['key']} nemá výčet kódů OKEČ.");
                }
            } elseif ($activities !== [] || !is_string($group['label'] ?? null) || $group['label'] === '') {
                throw new \UnexpectedValueException("Sazbová skupina {$group['key']} musí být daná popisem, ne kódy.");
            }
            foreach ($activities as $index => $activity) {
                if (!is_array($activity)
                    || ($activity['ordinal'] ?? null) !== $index + 1
                    || !is_string($activity['okec_code'] ?? null)
                    || $activity['okec_code'] === ''
                    || !is_string($activity['label'] ?? null)
                    || $activity['label'] === ''
                    || isset($seenCodes[$activity['okec_code']])
                ) {
                    throw new \UnexpectedValueException("Sazbová skupina {$group['key']} má neplatnou činnost.");
                }
                $seenCodes[$activity['okec_code']] = true;
            }
            $activityCount += count($activities);
            $byGroup[$group['key']] = count($activities);
        }
        // Zbytková a „nebezpečná" skupina musí být právě jedna od každé — bez
        // nich by sazebník vypadal jako úplný výčet kódů, kterým ale není.
        if (($kinds[self::KIND_HAZARD] ?? 0) !== 1 || ($kinds[self::KIND_RESIDUAL] ?? 0) !== 1) {
            throw new \UnexpectedValueException(
                'Sazebník musí mít právě jednu skupinu podle věcného kritéria a jednu zbytkovou.',
            );
        }

        $counts = $payload['counts'] ?? null;
        if (!is_array($counts)
            || ($counts['groups'] ?? null) !== count($groups)
            || ($counts['activities'] ?? null) !== $activityCount
            || CanonicalJson::encode(['x' => $counts['activities_by_group'] ?? null])
                !== CanonicalJson::encode(['x' => $byGroup])
        ) {
            throw new \UnexpectedValueException('Sazebník má jiný počet položek, než manifest slibuje.');
        }
        if (!hash_equals(
            is_string($payload['content_hash'] ?? null) ? $payload['content_hash'] : '',
            hash('sha256', CanonicalJson::encode(['groups' => $groups])),
        )) {
            throw new \UnexpectedValueException('Obsah sazebníku neodpovídá otisku v manifestu.');
        }
    }

    /** Porovnávací tvar — bez diakritiky, bez velikosti písmen. */
    public static function fold(string $value): string
    {
        $lower = mb_strtolower(trim($value), 'UTF-8');
        $folded = strtr($lower, [
            'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'ë' => 'e',
            'í' => 'i', 'ï' => 'i', 'ĺ' => 'l', 'ľ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o',
            'ö' => 'o', 'ŕ' => 'r', 'ř' => 'r', 'š' => 's', 'ś' => 's', 'ť' => 't', 'ú' => 'u',
            'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'ł' => 'l',
        ]);

        return trim(preg_replace('/\s+/u', ' ', $folded) ?? $folded);
    }

    /** @return list<string> */
    private static function significantWords(string $value): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', self::fold($value)) ?: [];
        $words = [];
        foreach ($parts as $word) {
            if (mb_strlen($word, 'UTF-8') < self::MIN_WORD_LENGTH
                || in_array($word, self::GENERIC_WORDS, true)
            ) {
                continue;
            }
            $words[$word] = true;
        }

        return array_keys($words);
    }

    /**
     * Počet významových slov, která si obě strany sdílejí. Skloňování se
     * překlenuje prefixem: „nabytek" a „nabytku" mají společných prvních pět
     * znaků, takže se počítají za shodu.
     *
     * @param list<string> $needles
     * @param list<string> $haystack
     */
    private static function overlap(array $needles, array $haystack): int
    {
        $score = 0;
        foreach ($needles as $needle) {
            foreach ($haystack as $word) {
                $shorter = mb_strlen($needle, 'UTF-8') <= mb_strlen($word, 'UTF-8') ? $needle : $word;
                $longer = $shorter === $needle ? $word : $needle;
                if (str_starts_with($longer, $shorter)) {
                    $score++;
                    break;
                }
            }
        }

        return $score;
    }

    private function load(): void
    {
        if ($this->manifest !== null) {
            return;
        }
        $root = $this->resourceRoot
            ?? dirname(__DIR__, 3) . '/resources/payroll/accident-insurance-rates';
        $path = $root . DIRECTORY_SEPARATOR . self::DIRECTORY . DIRECTORY_SEPARATOR . 'manifest.json';
        $json = @file_get_contents($path);
        if ($json === false || $json === '') {
            throw new \RuntimeException('Sazebník zákonného pojištění odpovědnosti nelze načíst.');
        }
        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)
            || !is_string($decoded['manifest_sha256'] ?? null)
            || !is_array($decoded['payload'] ?? null)
        ) {
            throw new \UnexpectedValueException('Sazebník zákonného pojištění nemá očekávanou strukturu.');
        }
        /** @var array{manifest_sha256:string,payload:array<string,mixed>} $decoded */
        self::validateManifest($decoded, true);
        $this->manifest = $decoded;
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        if ($this->manifest === null) {
            throw new \LogicException('Sazebník zákonného pojištění nebyl načten.');
        }

        return $this->manifest['payload'];
    }
}
