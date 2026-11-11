<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;

/**
 * Postavení v zaměstnání (REGZEC `job/@relat`, ID 10249).
 *
 * Zdroj je číselník „CIS Klasif. postavení v zaměstn" (NKPZ, ČSÚ) vložený
 * v připnutém datovém slovníku JMHZ, tedy tentýž balík jako ostatní číselníky
 * registrace. Číselník je hierarchický (1 → 11 → 111 → 1111), EDV 1.4.0.6 ale
 * výslovně říká „musí jít o 4místný kód, kratší nejsou akceptovány", takže
 * nabídka i kontrola pracují jen s listy o čtyřech číslicích. U druhu
 * činnosti 15 a 16 je číselník podle EDV omezený na 1341 a 1342.
 *
 * REGZEC 2025 i REGZEC 2027 berou TÝŽ výčet (vložený ve slovníku 1.4.1.6),
 * tedy list 2119 „Zaměstnavatelé – fyzické osoby jinde neuvedené". Závazný
 * zdroj pro REGZEC27 je EDV 1.5.0.3 (ČSSZ/MPSV), list „CIS Klasif. postavení
 * v zaměst", řádek 41: 2119, kód 2219 v něm není; shodně živý číselník MPSV
 * DS_CIS_Klasifikace_postaveni_v_zamestnani (developers.mpsv.cz/api/codebook,
 * aktualizace 9. 9. 2026), na
 * který odkazují Pokyny k vyplnění REGZEC (ID 10249), a systematická část
 * NKPZ ČSÚ (nkpz_systematicka_cast.xlsx, řádek 42). Kód 2219 v příloze
 * sdělení ČSÚ č. 401/2024 Sb. je tisková chyba, kterou převzala iSMS ČSÚ
 * (číselník 80145) a zařadila ji pod 221; aplikace ji proto nepoužívá
 * ({@see PayrollRegistrationNkpzClassification} zůstává jen referencí).
 * Výčet EDV 1.4.0.6 a 1.5.0.3 je řádek po řádku shodný.
 */
final class PayrollRegistrationEmploymentStatusCodebook
{
    private const CODEBOOK_KEY = 'klasif_postaveni_v_zamestn';

    /** Omezení číselníku podle druhu činnosti (EDV 1.4.0.6, ID 10249). */
    private const ACTIVITY_RESTRICTIONS = [
        '15' => ['1341', '1342'],
        '16' => ['1341', '1342'],
    ];

    /** @var array<string,string>|null kód → název */
    private static ?array $entries = null;

    /** @return list<array{code:string,label:string}> */
    public static function options(JmhzSpecVersion $version = JmhzSpecVersion::V2026): array
    {
        $options = [];
        foreach (self::entriesFor($version) as $code => $label) {
            // Čtyřmístný klíč PHP drží jako int; bez přetypování by šel do JSON
            // jako číslo a formulář by uložený kód „1111" v nabídce nenašel.
            $options[] = ['code' => (string) $code, 'label' => $label];
        }

        return $options;
    }

    public static function isKnown(string $code, JmhzSpecVersion $version = JmhzSpecVersion::V2026): bool
    {
        return array_key_exists($code, self::entriesFor($version));
    }

    /**
     * Ročník věty podle rozhodného data (`YYYY-MM-DD`). Neplatné datum hlásí
     * kontrola data, tady se jen nezvolí ročník 2027.
     */
    public static function versionForDate(mixed $decisiveDate): JmhzSpecVersion
    {
        if (!is_string($decisiveDate) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $decisiveDate) !== 1) {
            return JmhzSpecVersion::V2026;
        }

        return JmhzSpecVersion::forRegistrationEventDate($decisiveDate);
    }

    /**
     * Výčet pro ročník věty. Oba ročníky mají podle EDV stejný (viz docblock
     * třídy); parametr zůstává, kdyby se v dalším EDV rozešly.
     *
     * @return array<string,string>
     */
    private static function entriesFor(JmhzSpecVersion $version): array
    {
        return self::entries();
    }

    /**
     * Kód, který se v uloženém profilu nebo změně mohl objevit z ČSÚ iSMS:
     * 2219 je tisková chyba sdělení č. 401/2024 Sb. za 2119 (EDV 1.5.0.3,
     * číselník MPSV). Čtení ho převede, aby se nemusela měnit data.
     */
    public static function normalize(?string $code): ?string
    {
        return $code === '2219' ? '2119' : $code;
    }

    /**
     * Kódy povolené pro druh činnosti; `null` = bez omezení.
     *
     * @return list<string>|null
     */
    public static function restrictedFor(?string $activityCode): ?array
    {
        return $activityCode === null
            ? null
            : (self::ACTIVITY_RESTRICTIONS[$activityCode] ?? null);
    }

    /**
     * Návrh z evidence: druh vztahu a doba určitá. Jen předvyplnění, účetní
     * ho ve formuláři potvrdí nebo přepíše.
     */
    public static function suggest(
        ?string $relationType,
        ?string $activityCode,
        bool $fixedTerm,
    ): ?string {
        if ($activityCode === '15' || $activityCode === '16') {
            return $fixedTerm ? '1342' : '1341';
        }

        return match ($relationType) {
            'employment', 'small_scale_employment' => $fixedTerm ? '1112' : '1111',
            'dpc' => $fixedTerm ? '1212' : '1211',
            'dpp' => $fixedTerm ? '1222' : '1221',
            default => null,
        };
    }

    /** @return array<string,string> */
    private static function entries(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }
        $catalog = new JmhzCodebookCatalog((new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        ));
        $entries = [];
        foreach ($catalog->entries(self::CODEBOOK_KEY) as $entry) {
            $code = $entry['item_code'] ?? null;
            $label = $entry['label'] ?? null;
            if (!is_string($code) || !is_string($label)) {
                throw new \UnexpectedValueException(
                    'Připnutý číselník postavení v zaměstnání má neplatnou položku.',
                );
            }
            if (preg_match('/^\d{4}$/D', $code) === 1) {
                $entries[$code] = trim($label);
            }
        }
        if ($entries === []) {
            throw new \UnexpectedValueException(
                'Připnutý číselník postavení v zaměstnání nemá žádný čtyřmístný kód.',
            );
        }
        ksort($entries, SORT_STRING);
        self::$entries = $entries;

        return $entries;
    }
}
