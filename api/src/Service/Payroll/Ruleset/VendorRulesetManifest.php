<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

/**
 * Podpis dodavatele pod dodanou legislativní sadou.
 *
 * Otisky jsou počítané z KANONICKÉHO OBSAHU verze ({@see PayrollRulesetContent}) —
 * tedy z identity, účinnosti, zdrojů a parametrů, BEZ lifecyclu a bez schvalovacích
 * podpisů. Přechod `draft → reviewed → approved → active → superseded` proto otisk
 * nemění a totéž číslo platí pro celý život verze. Nemění ho ani schvalovatel, který
 * je podle {@see VendorRulesetApprover} vlastností instalace — jinak by u jiného
 * provozovatele přestala být dodaná sada poznána jako dodaná.
 *
 * ## K čemu to je
 *
 * `PayrollRulesetVersion` odmítá `approved`/`active`/`superseded` bez
 * {@see RulesetApproval}. To je správné pro obsah, který si upravil zákazník —
 * tam odpovědnost přebírá on. Pro sadu, kterou dodáváme my, je to ale nesmysl:
 * ručíme za ni my, ne zákazník, a odklikávání sazeb nevyžaduje ani jeden
 * z patnácti prozkoumaných českých mzdových systémů.
 *
 * Rozlišení proto nestojí na příznaku v konstruktoru (ten by šlo podstrčit
 * z uloženého overridu), ale na TOMTO SEZNAMU: účinná bez schválení smí být
 * jedině verze, jejíž obsah se bajt po bajtu shoduje s tím, co je zkompilované
 * v aplikaci. Jakmile zákazník změní jedinou hodnotu, účinnost, verzi nebo zdroj,
 * otisk přestane sedět a schválení se vyžaduje dál.
 *
 * ## Údržba
 *
 * Změna kterékoli hodnoty v {@see CzechPayrollRulesets2026} tenhle seznam rozbije.
 * To je záměr — je to okamžik, kdy dodavatel znovu podepisuje, co dodává.
 * Správné otisky vypíše `CzechPayrollRulesets2026Test::testVendorManifestPinsEveryDeliveredVersion`
 * v hlášce o selhání.
 */
final class VendorRulesetManifest
{
    /**
     * Otisky kanonického obsahu všech dodaných verzí, seřazené podle ID verze.
     *
     * @var list<string>
     */
    public const CONTENT_HASHES = [
        // cz-jmhz-deadlines-2026.regular.v1
        '52b5f1f0d2580e659e300874f0b0591129640cf38bc67714afb2b4728339d582',
        // cz-jmhz-deadlines-2026.transition.v1
        '9e30371cd82fcf6005bee2954c79b8e13b84f0b487b24df12272d4ba9951e999',
        // cz-payroll-2026.codebooks.v1
        'e40864b10491901b346096ebb39b70027a0fc111ea1f0b2208138e7594e2f87c',
        // cz-payroll-2026.compensation-averages.v1
        'd7cb73262099457f36ccf8431d7f7fac48a137f845619341cf69d3405db8b71b',
        // cz-payroll-2026.employment-thresholds.v1
        '22905fba61e6204c0d615971e80b19fdf5e38327e97a2903b61e7255fb454add',
        // cz-payroll-2026.enforcement-deductions.v1
        'ed148cfae04da4449f38425a3ff641f5c54865d741122735992eadb202d8bd1e',
        // cz-payroll-2026.health-insurance.v1
        'e5586a0e158b31570c6c10702d9ecaed9d4cbb636fd6cbe2e164a657e151f00b',
        // cz-payroll-2026.income-tax.v1
        'dfb8c8fc8e52d37225eba195c53eda6f867be573029136c10b3301b148e7ea74',
        // cz-payroll-2026.social-insurance.v1
        '9a0ec0de0f24085f7b12d5ee1971fd2526d6724b07ff3c29ff5b93db4b7ab4b4',
        // cz-payroll-2026.submissions.v1
        '5d4150f71b70da998f465b1ef5f5d396b3a457ff373cce434230a476854cf377',
        // cz-payroll-2026.travel-allowances.v1
        '4894d8ce93b013d4314c89362c39a7667f33185c8348e2a6ece9fe4a0024b8dd',
        // cz-payroll-2026.travel-allowances.v2
        '6a6a888ca73de0a7b6e33619cfc2799929d4b39ea9fa9ba1904ab17e9abd3538',
    ];

    public static function contains(string $contentHash): bool
    {
        foreach (self::CONTENT_HASHES as $pinned) {
            if (hash_equals($pinned, $contentHash)) {
                return true;
            }
        }

        return false;
    }
}
