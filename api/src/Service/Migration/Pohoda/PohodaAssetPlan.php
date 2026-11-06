<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Service\Migration\Shared\MigratedDepreciation;

/**
 * Karta dlouhodobého majetku z tabulek POHODY (`90_majetek.xml`, vytváří ho exportní
 * nástroj z datového souboru POHODY) jako vstup pro {@see \MyInvoice\Service\Accounting\Assets\AssetService::create()}.
 *
 * XML export POHODY majetek neobsahuje, proto nástroj čte přímo tabulky karty (`IM`),
 * daňových odpisů po letech (`IModpis`) a účetního plánu po měsících (`IMuodpis`
 * u samostatného účetního plánu, jinak `IModpisM`, kde POHODA rozpouští daňový odpis
 * do měsíců). Karta se převádí jako historický majetek (R23): počáteční stavy
 * pokrývají všechno, co POHODA už odepsala, a odpisy v MyÚčtu na ně navážou.
 *
 * - Daňově: odepsané roky před rokem převodu (`opening_tax_years`, `opening_tax_amount`).
 * - Účetně: měsíce do posledního měsíce zaúčtovaného v deníku POHODY. POHODA odpisuje
 *   od měsíce zařazení, MyÚčto od měsíce následujícího; počet měsíců se proto počítá
 *   kalendářně od zařazení, takže další odpis v MyÚčtu připadne přesně na první
 *   nezaúčtovaný měsíc a plán skončí ve stejném měsíci jako v POHODĚ. Účetní odpis
 *   je rovnoměrný po měsících ze zbývající hodnoty - u plánu POHODY s rovnými měsíci
 *   vychází stejná částka.
 *
 * Co z dat spolehlivě nevyplývá (neznámý typ majetku nebo odpisu, jiná daňová vstupní
 * cena, chybějící plán), jde do `review` a karta vznikne jako koncept k doplnění.
 */
final class PohodaAssetPlan
{
    /** `IM.RelTpIM` - typ majetku (1 hmotný, 3 nehmotný; ověřeno na datech POHODY). */
    private const KINDS = [1 => 'tangible', 3 => 'intangible'];

    /**
     * `IM.RelTpOdp` - způsob daňového odpisu, ověřeno podle sazeb v `IModpis`:
     * 1 rovnoměrný, 2 zrychlený (koeficienty), 4 neodpisuje se (sazba 0), 5 varianta
     * rovnoměrného (sazby § 31, v dalším roce POHODA kartu vede jako 1), 11 nehmotný majetek
     * podle § 32a po měsících (v `RelSkOdp` je počet měsíců) - v MyÚčtu daňový odpis
     * shodný s účetním.
     */
    private const TAX_METHODS = [1 => 'straight', 2 => 'accelerated', 4 => 'none', 5 => 'straight', 11 => 'by_accounting'];

    /**
     * `IMpohyb.RelTpPoh`, které karta převezme: 2 zařazení, 7 daňový odpis, 8 účetní odpis,
     * 14 vyřazení (řeší se datem vyřazení karty `DatLikv`).
     */
    private const KNOWN_MOVEMENTS = [2, 7, 8, 14];

    /**
     * @param array<string,mixed> $card řádek `IM`
     * @param list<array<string,mixed>> $taxRows řádky `IModpis` karty
     * @param list<array<string,mixed>> $monthRows měsíční účetní plán karty (`IMuodpis`, jinak `IModpisM`)
     * @param string $lastBooked poslední měsíc (`Y-m`) s odpisem zaúčtovaným v POHODĚ
     * @param list<array<string,mixed>> $movements řádky `IMpohyb` karty
     * @return array{card:array<string,mixed>, review:list<string>}
     */
    public static function build(array $card, array $taxRows, array $monthRows, int $year, string $lastBooked, array $movements = []): array
    {
        $review = [];
        // Jiný pohyb než zařazení a odpisy (technické zhodnocení, přecenění...) převod nepřebírá.
        $other = [];
        foreach ($movements as $movement) {
            $kind = (int) PohodaXml::text($movement, 'RelTpPoh');
            if (!in_array($kind, self::KNOWN_MOVEMENTS, true)) {
                $other[$kind] = ($other[$kind] ?? 0.0) + PohodaXml::num($movement, 'Kc');
            }
        }
        foreach ($other as $kind => $amount) {
            $review[] = 'pohyb majetku druhu ' . $kind . ' (' . self::money($amount) . ', například technické zhodnocení) převod nepřebírá, doplňte ho na kartě';
        }
        $number = PohodaXml::text($card, 'Cislo');
        $inputPrice = round(PohodaXml::num($card, 'Kc'), 2);
        $taxPrice = round(PohodaXml::num($card, 'KcDanova'), 2);
        if ($taxPrice > 0 && abs($taxPrice - $inputPrice) >= 0.01) {
            $review[] = 'daňová vstupní cena ' . self::money($taxPrice) . ' se liší od účetní ' . self::money($inputPrice);
        }
        $acquired = PohodaXml::date($card, 'Datum');
        $inUse = PohodaXml::date($card, 'DatZar') ?? $acquired;

        $kindCode = (int) PohodaXml::text($card, 'RelTpIM');
        $kind = self::KINDS[$kindCode] ?? null;
        if ($kind === null) {
            $review[] = 'typ majetku ' . $kindCode . ' převod nezná';
        }

        // Daňové odpisy: bez řádků POHODA kartu daňově neodpisuje.
        $taxMethod = 'none';
        $taxGroup = null;
        $openingYears = 0;
        $openingTax = 0.0;
        if ($taxRows !== []) {
            $methodCode = (int) PohodaXml::text($card, 'RelTpOdp');
            $taxMethod = self::TAX_METHODS[$methodCode] ?? 'none';
            if (!isset(self::TAX_METHODS[$methodCode])) {
                $review[] = 'způsob daňového odpisu ' . $methodCode . ' převod nezná';
            }
            if (in_array($taxMethod, ['straight', 'accelerated'], true)) {
                $group = (int) PohodaXml::text($card, 'RelSkOdp');
                $taxGroup = $group >= 1 && $group <= 6 ? $group : null;
                if ($taxGroup === null && $group >= 10 && $group <= 99 && $group % 10 >= 1 && $group % 10 <= 6) {
                    // Dvoumístný kód je skupina se zvláštním režimem odpisu (naměřeno: 42 u osobního
                    // auta, skupina 2, odepsané za tři roky). Skupina je poslední číslice, plán převod
                    // přebírá z odpisů POHODY, kartu je ale potřeba zkontrolovat.
                    $taxGroup = $group % 10;
                    $review[] = 'odpisová skupina ' . $taxGroup . ' je odvozená z kódu POHODY ' . $group . ', zkontrolujte ji';
                } elseif ($taxGroup === null) {
                    $review[] = 'odpisová skupina chybí';
                }
            }
            foreach ($taxRows as $row) {
                $amount = PohodaXml::num($row, 'KcOdpis');
                // Rok přerušení (sazba 0) není odepsaný rok - plán se o něj prodlužuje.
                if ((int) PohodaXml::text($row, 'Rok') < $year && abs($amount) >= 0.005) {
                    $openingYears++;
                    $openingTax += $amount;
                }
            }
        }

        // Účetní plán po měsících.
        $plan = [];
        foreach ($monthRows as $row) {
            $month = substr((string) PohodaXml::date($row, 'Mesic'), 0, 7);
            if ($month !== '') {
                $plan[$month] = ($plan[$month] ?? 0.0) + PohodaXml::num($row, 'KcOdpis');
            }
        }
        ksort($plan);
        $openingMonths = 0;
        $openingAcc = 0.0;
        $usefulLife = null;
        if ($plan === [] || $inUse === null) {
            $review[] = 'účetní odpisový plán chybí';
        } else {
            [$openingMonths, $openingAcc, $usefulLife] = MigratedDepreciation::accountingOpening($inUse, $plan, $lastBooked);
        }
        $openingAcc = min(round($openingAcc, 2), $inputPrice);

        return [
            'card' => [
                'inventory_number' => mb_substr($number, 0, 30),
                'name' => mb_substr(PohodaXml::text($card, 'SText') ?: $number, 0, 255),
                'kind' => $kind ?? 'tangible',
                'input_price' => $inputPrice,
                'acquisition_date' => $acquired,
                'put_into_use_date' => $inUse,
                'status' => $review === [] ? 'in_use' : 'draft',
                'tax_method' => $taxMethod,
                'tax_group' => $taxGroup,
                'opening_tax_years' => $openingYears,
                'opening_tax_amount' => round($openingTax, 2),
                'opening_acc_months' => $openingMonths,
                'opening_acc_amount' => $openingAcc,
                'acc_useful_life_months' => $usefulLife,
                'acc_method' => 'straight_line',
                'acc_residual_value' => 0.0,
            ],
            'review' => $review,
        ];
    }


    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' Kč';
    }
}
