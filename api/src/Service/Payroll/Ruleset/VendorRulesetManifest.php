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
 * Změna kterékoli hodnoty v {@see CzechPayrollRulesets2026} (a v ostatních
 * ročnících) tenhle seznam rozbije.
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
        // cz-jmhz-deadlines-2027.regular.v1
        '939bd74de70d4042192e5070ce17bc244588018347c2f973f880592fcd8bcfe8',
        // cz-payroll-2025.compensation-averages.v1
        'cd123a9889377736f0cbfcfb8bd9163163bae6feb31acbbaaab043d187c481e5',
        // cz-payroll-2025.employment-thresholds.v1
        '9d62f5b2a3ddb31f00f2db189c280c41e18c3ca31cfbdb48051926ad694f3e17',
        // cz-payroll-2025.enforcement-deductions.v1
        '09eac16226e8a87aaae14b5728556f1b911968a7613c7de41c8d7b15e193114c',
        // cz-payroll-2025.health-insurance.v1
        '94fdbad0e0dff36defb18c66a25d7f0d01631630ac55e477e80ba3f73bde9676',
        // cz-payroll-2025.income-tax.v1
        '23c920442b0cce0631e6bda85033572161295c44997485c1b973fe4a9e482921',
        // cz-payroll-2025.social-insurance.v1
        'a6df4db1b94c62cd95b8c2f87db9478b4e942263a4f3fe65aa0d840f76628cbb',
        // cz-payroll-2025.travel-allowances.v1
        'be22b1d7a6e9f2b827decc72595b95aed8d2a27c86c65f104ed99fce214bad04',
        // cz-payroll-2026.codebooks.v1
        'e40864b10491901b346096ebb39b70027a0fc111ea1f0b2208138e7594e2f87c',
        // cz-payroll-2026.compensation-averages.v1
        '135d38b40293ab35d441c498dad99bf28b807a72a1369e96f4ea1f5edbbfcde8',
        // cz-payroll-2026.employment-thresholds.v1
        'f48de8bd5f92020c1648f02e2a70290f1d926beb2e6fbb6e0359a8c9aaeff236',
        // cz-payroll-2026.enforcement-deductions.v1
        'ed148cfae04da4449f38425a3ff641f5c54865d741122735992eadb202d8bd1e',
        // cz-payroll-2026.health-insurance.v1
        'e5586a0e158b31570c6c10702d9ecaed9d4cbb636fd6cbe2e164a657e151f00b',
        // cz-payroll-2026.income-tax.v1
        'dfb8c8fc8e52d37225eba195c53eda6f867be573029136c10b3301b148e7ea74',
        // cz-payroll-2026.social-insurance.v1
        '9a0ec0de0f24085f7b12d5ee1971fd2526d6724b07ff3c29ff5b93db4b7ab4b4',
        // cz-payroll-2026.submissions.v1
        'cc830b0602502e64812d0520c2451707a611947413766f8225d4866a35f7997e',
        // cz-payroll-2026.travel-allowances.v1
        '4894d8ce93b013d4314c89362c39a7667f33185c8348e2a6ece9fe4a0024b8dd',
        // cz-payroll-2026.travel-allowances.v2
        '1d319cb56061a70e650e90683a023716778ab9d292cbfd59b22241447858c9b1',
        // cz-payroll-2026.travel-allowances.v3
        '6b14aa4bc26b5a4f23b0982253c4bb1ecc38402dbed1e6ce7615fd958cc95c7a',
        // cz-payroll-2027.compensation-averages.v1
        '62cda166198f62d43f4bb120694f9bf42ec8694ec19afad32ffea60cfad0efb3',
        // cz-payroll-2027.employment-thresholds.v1
        '955e2f361a56a74f6f6e8d2240fdbcd6e4123d043882ef6079399fad504cae49',
        // cz-payroll-2027.health-insurance.v1
        'dc2ad8a5e8091f9adccac0300c8c20cea85d1c3c9b5a7adbb90f7b9ca5865741',
        // cz-payroll-2027.income-tax.v1
        'c68578188a3689dc8f4e422bb0572dcd3179a5ee8f91d1fcce01031202bf75b5',
        // cz-payroll-2027.social-insurance.v1
        '85811872429f34f325481585310c9dccc39eb17bf231e5e306d4d926f024a439',
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
