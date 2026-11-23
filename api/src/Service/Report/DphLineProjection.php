<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Průběžná projekce kanonických řádků evidence DPH ({@see VatLedgerService}) na řádky
 * DPHDP3: agregace po dphdp3_line + mirror ř. 43/44 (secondary) + ř. 47 (majetek).
 * Jediné místo pravidla pro přiznání ({@see VatClassificationMapper::projectDphLines()})
 * i pro sestavu DPH podle dimenze, která řádky jednoho průchodu evidencí sčítá do
 * více projekcí naráz (po hodnotách dimenze) a nemusí tak evidenci držet v paměti.
 */
final class DphLineProjection
{
    /** @var array<string, array{base:float, vat:float, count:int, label:string}> */
    private array $byLine = [];

    /** @var array<string, bool> per document identity × line → distinct count */
    private array $invoiceLineSeen = [];

    /** @var array<string, array<string, array{base?:float, vat?:float}>> zrcadlový odpočet ř. 43/44 rozpadlý podle primárního ř. 3–13 */
    private array $mirrorParts = [];

    /** @param array<string,mixed> $r kanonický řádek evidence */
    public function add(array $r): void
    {
        $primary = $r['dphdp3_line'];
        if ($r['code'] === null || $primary === null) {
            return; // bez řádku DPHDP3 → přeskoč
        }

        $baseCzk = (float) $r['base_czk'];
        $vatCzk  = (float) $r['vat_czk'];
        $label   = (string) $r['label'];
        // Count distinct dokladů per řádek včetně odděleného namespace pokladny.
        $documentKey = VatLedgerService::documentIdentity($r);

        $this->addLine($primary, $baseCzk, $vatCzk, $documentKey, $label);

        // Secondary (typicky ř.43 — mirror odpočet u RC / dovozu služby). U plnění bez
        // nároku na odpočet ('none', § 72/4 — např. reprezentace ze zahraničí v RC) se
        // zrcadlový odpočet POTLAČÍ: výstupní samovyměření (primární ř.) zůstává, odpočet ne.
        $secondary = $r['dphdp3_line_secondary'];
        if ($secondary !== null && $secondary !== '' && $secondary !== $primary && empty($r['vat_deduction_none'])) {
            [$secondaryBase, $secondaryVat] = self::secondaryAmounts($r, $secondary);
            $this->addLine(
                $secondary,
                $secondaryBase,
                $secondaryVat,
                $documentKey,
                $label,
            );
            if (isset(self::SELF_ASSESSMENT_MIRROR_LINES[$secondary])) {
                $this->mirrorParts[$secondary][$primary]['base'] = ($this->mirrorParts[$secondary][$primary]['base'] ?? 0.0) + $secondaryBase;
                $this->mirrorParts[$secondary][$primary]['vat']  = ($this->mirrorParts[$secondary][$primary]['vat'] ?? 0.0) + $secondaryVat;
            }
        }

        // ř.47 — hodnota pořízeného majetku (doplňující údaj k ř.40-45).
        $asset = self::fixedAssetAmounts($r, $primary, $secondary);
        if ($asset !== null) {
            $this->addLine('47', $asset[0], $asset[1], $documentKey, 'Hodnota pořízeného majetku (§ 4 odst. 4 písm. c)');
        }
    }

    /**
     * Řádky DPHDP3. `$wholeCzkMirror` = false nechá zrcadlový ř. 43/44 jako haléřový součet
     * podílů: tak je projekce součtová (projekce části evidence se sečtou na projekci
     * celku), přiznání ji ale vykazuje po celých Kč (výchozí).
     *
     * @return array<string, array{base:float, vat:float, count:int, label:string}>
     */
    public function lines(bool $wholeCzkMirror = true): array
    {
        $byLine = $this->byLine;
        if (!$wholeCzkMirror) {
            return $byLine;
        }
        // Ř. 43/44 (odpočet ze samovyměření) je v přiznání protějškem ř. 3–13: nárok na
        // odpočet je ve výši daně, kterou plátce na výstupu PŘIZNAL (§ 72 odst. 2,
        // § 73 odst. 1 písm. b) ZDPH), tedy součet řádků tak, jak jsou vyplněné na celé Kč.
        // Samovyměření se tím přesně vyruší. Proto se zrcadlový řádek skládá ze
        // zaokrouhlených podílů jednotlivých primárních řádků, stejně jako se ř. 46
        // skládá z řádků 40–45, ne zaokrouhlením haléřového součtu.
        //
        // EPO ř. 43/44 ověřuje JINAK: výpočtem ze základu řádku krát sazba („Přesně
        // vypočtená hodnota součtu daní na ř. 43 je …"). Obě cesty se mohou kvůli
        // zaokrouhlení rozejít o koruny a EPO pak vytkne propustnou chybu; podání přijme
        // (ověřeno zkušebním podáním, #264). Builder přiznání na to předem upozorní.
        // Dřívější výklad, že EPO sčítá vyplněné řádky (chyba č. 90), byl nepřesný:
        // hlášený případ ř. 5 = 50 646 a ř. 12 = 539 vyšel 51 185 oběma cestami.
        foreach ($this->mirrorParts as $mirrorLine => $parts) {
            $base = 0.0;
            $vat = 0.0;
            foreach ($parts as $part) {
                $base += EpoAmount::wholeCzk($part['base']);
                $vat  += EpoAmount::wholeCzk($part['vat']);
            }
            $byLine[$mirrorLine]['base'] = $base;
            $byLine[$mirrorLine]['vat']  = $vat;
        }

        return $byLine;
    }

    /**
     * Zrcadlové řádky odpočtu ze samovyměření (ř. 43/44 plná i krácená výše), které
     * {@see lines()} skládá ze zaokrouhlených podílů primárních ř. 3–13.
     */
    private const SELF_ASSESSMENT_MIRROR_LINES = ['43' => true, '44' => true, '43k' => true, '44k' => true];

    /**
     * Základ a daň zrcadlového (secondary) řádku řádku evidence: odpočtová varianta
     * (`deduction_*`, u samovyměření s poměrným nárokem § 75 krácená), u doplňujícího
     * ř. 51/51b jen základ — daň zůstává výhradně na primárním ř. 1/2. Sdílí přiznání
     * i Kniha DPH ({@see DphBookBuilder}), aby zrcadlový odpočet ukazovaly stejně.
     *
     * @param array<string,mixed> $r
     * @return array{0: float, 1: float}
     */
    public static function secondaryAmounts(array $r, string $secondary): array
    {
        $base = (float) ($r['deduction_base_czk'] ?? $r['base_czk']);
        $vat = in_array($secondary, ['51', '51b'], true)
            ? 0.0
            : (float) ($r['deduction_vat_czk'] ?? $r['vat_czk']);
        return [$base, $vat];
    }

    /**
     * Základ a daň řádku evidence na ř. 47 (hodnota pořízeného majetku), null když na něj
     * nepatří. Daň jde do sloupce „V plné výši" (od_maj); u kráceného odpočtu (ř. 40k–44k)
     * se sloupec „Krácený odpočet" zatím neplní.
     *
     * @param array<string,mixed> $r
     * @return array{0: float, 1: float}|null
     */
    public static function fixedAssetAmounts(array $r, string $primary, ?string $secondary): ?array
    {
        if (empty($r['is_fixed_asset'])) {
            return null;
        }
        $line = self::countsAsFixedAssetLine($primary)
            ? $primary
            : (($secondary !== null && $secondary !== '' && self::countsAsFixedAssetLine($secondary)) ? $secondary : null);
        if ($line === null) {
            return null;
        }
        if ($line === $secondary) {
            [$base, $vat] = self::secondaryAmounts($r, $secondary);
        } else {
            [$base, $vat] = [(float) $r['base_czk'], (float) $r['vat_czk']];
        }
        return [$base, str_ends_with($line, 'k') ? 0.0 : $vat];
    }

    private function addLine(string $line, float $baseCzk, float $vatCzk, string $documentKey, string $label): void
    {
        if (!isset($this->byLine[$line])) {
            $this->byLine[$line] = ['base' => 0.0, 'vat' => 0.0, 'count' => 0, 'label' => $label];
        }
        $this->byLine[$line]['base'] += $baseCzk;
        $this->byLine[$line]['vat']  += $vatCzk;
        $seenKey = $documentKey . ':' . $line;
        if (!isset($this->invoiceLineSeen[$seenKey])) {
            $this->invoiceLineSeen[$seenKey] = true;
            $this->byLine[$line]['count']++;
        }
    }

    /**
     * Smí dané plnění figurovat na ř. 47 (hodnota pořízeného majetku)?
     *
     * Doplňující údaj k odpočtu — vstup do ř. 40-45 (tuzemsko 40/41, dovoz CÚ 42,
     * RC mirror 43, korekce 44, registrace 45). NE pro výstupové řádky 3-13
     * samotné (ty se počítají odděleně přes secondary='43' mirror).
     */
    private static function countsAsFixedAssetLine(string $primaryLine): bool
    {
        $n = (int) $primaryLine;
        return $n >= 40 && $n <= 45;
    }
}
