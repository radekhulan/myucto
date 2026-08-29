<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\ForeignIncome;

/**
 * Číselník MFČR `k_rozl_prij` — „DPS - kódy rozlišení příjmů dle § 22 odst. 1".
 *
 * Připnutá kopie odezvy veřejného servletu číselníků
 * `https://mojedane.gov.cz/dpr/epo_ciselnik?C=k_rozl_prij&M=100` (16 záznamů,
 * verze 1). Číselník je malý a od 1. 4. 2019 se nezměnil, takže se drží v kódu:
 * síťové volání při generování podání by z offline stroje udělalo nepodání.
 *
 * `DPSHL1/VetaH@druh_prij` nese `c_druh_prij` (číslo záznamu) a
 * `@k_rozl_prij` skupinu téhož záznamu. Obojí má v EPO kritickou kontrolu, takže
 * se skupina NIKDY nedopočítává ručně — bere se z téhož řádku číselníku.
 *
 * ## Proč tu není kód pro závislou činnost
 *
 * Číselník žádný kód pro § 22 odst. 1 písm. b) (příjmy ze závislé činnosti)
 * nemá, a to není opomenutí: § 38da odst. 5 písm. b) ZDP oznamovací povinnost
 * pro příjem podle § 6 odst. 4 výslovně vylučuje. Mzdový modul sráží daň
 * zvláštní sazbou jedině podle § 6 odst. 4 (dohody o provedení práce a drobné
 * příjmy bez prohlášení — viz
 * {@see \MyInvoice\Service\Payroll\TaxStatement\WithholdingTaxStatement}), takže
 * z mezd do tohoto oznámení nepatří nic. Jediná položka, která se člověka týká,
 * je `c_druh_prij = 4` (odměny člena orgánů právnické osoby).
 */
final class ForeignIncomeKindCatalog
{
    /**
     * @var array<int,array{group:string,label:string,paragraph:string,from:string}>
     *      Klíč = `c_druh_prij`, `group` = `k_rozl_prij`, `from` = počátek platnosti.
     */
    private const KINDS = [
        1 => ['group' => '7', 'label' => 'příjmy ze služeb', 'paragraph' => '§ 22 odst. 1 písm. c)', 'from' => '2014-01-01'],
        2 => ['group' => '7', 'label' => 'příjmy z nezávislé činnosti', 'paragraph' => '§ 22 odst. 1 písm. f) bod 1', 'from' => '2014-01-01'],
        3 => ['group' => '17', 'label' => 'příjmy sportovců a umělců', 'paragraph' => '§ 22 odst. 1 písm. f) bod 2', 'from' => '2014-01-01'],
        4 => ['group' => '16', 'label' => 'odměny člena orgánů PO', 'paragraph' => '§ 22 odst. 1 písm. g) bod 6', 'from' => '2014-01-01'],
        5 => ['group' => '12', 'label' => 'licenční poplatky - průmyslové', 'paragraph' => '§ 22 odst. 1 písm. g) bod 1', 'from' => '2014-01-01'],
        6 => ['group' => '12', 'label' => 'licenční poplatky - kulturní', 'paragraph' => '§ 22 odst. 1 písm. g) bod 2', 'from' => '2014-01-01'],
        7 => ['group' => '12', 'label' => 'licenční poplatky - užívání movité věci', 'paragraph' => '§ 22 odst. 1 písm. g) bod 5', 'from' => '2014-01-01'],
        8 => ['group' => '10', 'label' => 'dividendy', 'paragraph' => '§ 22 odst. 1 písm. g) bod 3', 'from' => '2014-01-01'],
        9 => ['group' => '11', 'label' => 'úroky', 'paragraph' => '§ 22 odst. 1 písm. g) bod 4', 'from' => '2014-01-01'],
        10 => ['group' => '21', 'label' => 'výhry', 'paragraph' => '§ 22 odst. 1 písm. g) bod 8', 'from' => '2014-01-01'],
        11 => ['group' => '21', 'label' => 'sankce ze závazkových vztahů', 'paragraph' => '§ 22 odst. 1 písm. g) bod 12', 'from' => '2014-01-01'],
        12 => ['group' => '21', 'label' => 'příjmy ze svěřenského fondu', 'paragraph' => '§ 22 odst. 1 písm. g) bod 13', 'from' => '2014-01-01'],
        13 => ['group' => '21', 'label' => 'bezúplatné příjmy', 'paragraph' => '§ 22 odst. 1 písm. g) bod 14', 'from' => '2014-01-01'],
        14 => ['group' => '13', 'label' => 'bezúplatný převod nemovitých věcí', 'paragraph' => '§ 22 odst. 1 písm. d)', 'from' => '2019-04-01'],
        15 => ['group' => '13', 'label' => 'bezúplatný převod podílů v obchodních korporacích', 'paragraph' => '§ 22 odst. 1 písm. h)', 'from' => '2019-04-01'],
        16 => ['group' => '13', 'label' => 'bezúplatný převod obchodního závodu', 'paragraph' => '§ 22 odst. 1 písm. i)', 'from' => '2019-04-01'],
    ];

    /**
     * Odměna člena orgánu právnické osoby — jediný druh příjmu z číselníku,
     * který může plynout osobě vedené v mzdovém modulu.
     */
    public const KIND_BODY_MEMBER_REMUNERATION = 4;

    /**
     * Osvobozené příjmy podle § 38da odst. 1 písm. a) a b) — licenční poplatky,
     * dividendy a úroky. Jen u nich se oznamuje i příjem se sazbou 0 a jen u nich
     * se místo data úhrady vyplňuje rok (`r_uhrady`).
     *
     * @var list<int>
     */
    private const EXEMPT_REPORTABLE_KINDS = [5, 6, 7, 8, 9];

    /** @return array{group:string,label:string,paragraph:string,from:string} */
    public static function require(int $code): array
    {
        $kind = self::KINDS[$code] ?? null;
        if ($kind === null) {
            throw new \InvalidArgumentException(
                'Druh příjmu ' . $code . ' není v číselníku § 22 odst. 1.',
            );
        }

        return $kind;
    }

    /** `k_rozl_prij` téhož záznamu — nikdy se neodvozuje jinak. */
    public static function group(int $code): string
    {
        return self::require($code)['group'];
    }

    public static function label(int $code): string
    {
        return self::require($code)['label'];
    }

    /**
     * Smí se u tohoto druhu příjmu oznamovat příjem osvobozený od daně,
     * resp. příjem, který podle smlouvy o zamezení dvojího zdanění v ČR
     * nepodléhá zdanění (§ 38da odst. 1 písm. a) a b) ZDP)?
     */
    public static function allowsExemptReporting(int $code): bool
    {
        self::require($code);

        return in_array($code, self::EXEMPT_REPORTABLE_KINDS, true);
    }

    /** Platí druh příjmu k datu úhrady / zaúčtování? */
    public static function isEffectiveOn(int $code, string $isoDate): bool
    {
        return $isoDate >= self::require($code)['from'];
    }

    /** @return array<int,array{group:string,label:string,paragraph:string,from:string}> */
    public static function all(): array
    {
        return self::KINDS;
    }
}
