<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Service\Migration\Shared\VatReturnLineClassifier as Lines;

/**
 * Členění DPH z Pohody převedené na zařazení dokladu v MyÚčtu.
 *
 * Zkratky členění (`UD`, `PD`, `PDslRegEU`…) si účetní jednotka v Pohodě může upravit
 * i doplnit, proto se nepárují podle názvu, ale podle řádků přiznání, které členění
 * v číselníku jednotky nese (`lineInVATReturn` v `06_cleneni_dph.xml`):
 *
 *   uskutečněná plnění   ř. 1,2 tuzemsko (kód se odvodí ze sazby), 1,2,51 prodej majetku
 *                        mimo koeficient, 20 dodání zboží do EU, 21 služba do EU, 22 vývoz,
 *                        23 nový dopravní prostředek, 24 zasílání zboží, 25 tuzemský
 *                        přenos (dodavatel), 26 plnění s místem mimo tuzemsko, 31 třístranný
 *                        obchod, 50 osvobozené bez nároku, 50,51 osvobozené mimo koeficient
 *   přijatá plnění       ř. 40,41 tuzemský odpočet (`PK…` krácený podle § 76), 40,41,47
 *                        pořízení majetku, 43,44 odpočet ze samovyměření - daň na výstupu
 *                        nese interní doklad s členěním `DD…`
 *   `---`                mimo přiznání
 *
 * Členění jiných řádků (dovoz ř. 42, insolvence, nedobytné pohledávky věřitele, vrácená
 * daň…) převod nezařazuje a doklad nechá jako koncept k ruční kontrole. Opravu odpočtu
 * dlužníka (ř. 34, 40, 41) převádí {@see InvoiceImporter} do evidence § 74b.
 *
 * Význam řádků drží {@see Lines}. Pohoda ale řádky vede jako celé dvojice (`1,2`,
 * `40,41`, `3,4`), takže se tu páruje přesná sada řádků, ne množina jako u PREMIER.
 */
final class PohodaVat
{
    /** @param array<string,array{lines:list<int>,name:string,section:string}> $classes */
    private function __construct(private readonly array $classes) {}

    public static function fromExport(PohodaExport $export): self
    {
        $classes = [];
        foreach ($export->records('vat_classes', 'classificationVAT') as $r) {
            $h = PohodaXml::get($r, 'classificationVATHeader');
            $code = PohodaXml::text($h, 'code');
            if ($code === '') {
                continue;
            }
            $classes[$code] = [
                'lines' => self::parseLines(PohodaXml::text($h, 'lineInVATReturn')),
                'name' => PohodaXml::text($h, 'name'),
                'section' => PohodaXml::text($h, 'sectionInVATLedgerStatement'),
            ];
        }
        return new self($classes);
    }

    public function known(string $code): bool
    {
        return isset($this->classes[$code]);
    }

    public function name(string $code): string
    {
        return $this->classes[$code]['name'] ?? $code;
    }

    /**
     * Vydaný doklad (faktura, pohledávka, příjem v pokladně).
     *
     * @return array{in_return:bool,code:?string}|null null = převod členění nezařadí
     */
    public function sale(string $code, float $rate): ?array
    {
        $lines = $this->classes[$code]['lines'] ?? null;
        if ($lines === null) {
            return null;
        }
        if ($lines === []) {
            return ['in_return' => false, 'code' => null];
        }
        if ($lines === Lines::DOMESTIC_OUTPUT) {
            return ['in_return' => true, 'code' => null];
        }
        $target = match (true) {
            // Pohoda prodej majetku nevede sazbou číselníku: kód jen u aktuální sazby.
            $lines === [...Lines::DOMESTIC_OUTPUT, Lines::OUTSIDE_COEFFICIENT] => Lines::assetSaleCode($rate),
            $lines === [Lines::EXEMPT, Lines::OUTSIDE_COEFFICIENT] => Lines::EXEMPT_OUTSIDE_COEFFICIENT_CODE,
            // Členění Pohody předmět plnění ř. 25 nenese - platí výchozí 4 (stavební práce).
            count($lines) === 1 => Lines::saleCode($lines[0]),
            default => null,
        };
        return $target === null ? null : ['in_return' => true, 'code' => $target];
    }

    /**
     * Přijatý doklad (faktura, závazek, výdej v pokladně).
     *
     * `reverse` = odpočet ze samovyměření (ř. 43, 44): daň na výstupu nese interní doklad
     * a převod ji k faktuře dohledá ({@see InvoiceImporter}).
     *
     * `fixed_asset` = odpočet u pořízení majetku (ř. 47 v sadě řádků členění).
     *
     * @return array{in_return:bool,deduction:'full'|'reduced'|'none',reverse:bool,fixed_asset:bool}|null
     */
    public function purchase(string $code): ?array
    {
        $lines = $this->classes[$code]['lines'] ?? null;
        if ($lines === null) {
            return null;
        }
        if ($lines === []) {
            return ['in_return' => false, 'deduction' => 'none', 'reverse' => false, 'fixed_asset' => false];
        }
        $deduction = str_starts_with($code, 'PK') ? 'reduced' : 'full';
        $asset = in_array(Lines::FIXED_ASSET, $lines, true);
        return match ($lines) {
            Lines::DOMESTIC_DEDUCTION, [...Lines::DOMESTIC_DEDUCTION, Lines::FIXED_ASSET] => ['in_return' => true, 'deduction' => $deduction, 'reverse' => false, 'fixed_asset' => $asset],
            Lines::SELF_ASSESSMENT_DEDUCTION, [...Lines::SELF_ASSESSMENT_DEDUCTION, Lines::FIXED_ASSET] => ['in_return' => true, 'deduction' => $deduction, 'reverse' => true, 'fixed_asset' => $asset],
            default => null,
        };
    }

    /**
     * Členění, které v Pohodě vynucuje oddíl A.5 kontrolního hlášení (`UDA5` „pod limit
     * 10 000 Kč") bez ohledu na částku dokladu. Běžné tuzemské členění (`UD`) má oddíly
     * „A.4., A.5." a rozhoduje částka.
     */
    public function forcesA5(string $code): bool
    {
        return rtrim($this->classes[$code]['section'] ?? '', '. ') === 'A.5';
    }

    /**
     * Oprava odpočtu dlužníka podle § 74b: členění `PNedpohl`/`PKnedpohl` s řádky 34, 40, 41.
     * Zkratku si jednotka může upravit, proto rozhoduje sada řádků.
     */
    public function isDebtorDeductionCorrection(string $code): bool
    {
        return ($this->classes[$code]['lines'] ?? null) === [34, 40, 41];
    }

    /**
     * Kód zařazení samovyměření podle výstupního řádku členění interního dokladu (`DD…`):
     * ř. 3,4 pořízení zboží z EU, 5,6 služba z EU, 7,8 dovoz, 10,11 tuzemský přenos,
     * 12,13 ostatní plnění ze zahraničí.
     */
    public function selfAssessmentCode(string $code): ?string
    {
        return Lines::selfAssessmentCodeForPair($this->classes[$code]['lines'] ?? null);
    }

    /** @return list<int> */
    private static function parseLines(string $text): array
    {
        if (preg_match_all('/\d+/', $text, $m) === 0) {
            return [];
        }
        $lines = array_values(array_unique(array_map('intval', $m[0])));
        sort($lines);
        return $lines;
    }
}
