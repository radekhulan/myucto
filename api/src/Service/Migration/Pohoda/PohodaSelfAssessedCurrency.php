<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Service\Migration\Shared\VatReturnLineClassifier;

/**
 * Přijatý doklad v cizí měně se samovyměřením DPH (služba nebo zboží z EU, dovoz) v měně
 * dokladu.
 *
 * POHODA vede samovyměření interním dokladem a jeho základ přepočítává kurzem ke dni
 * plnění, faktura sama nese kurz dokladu. Převod v Kč skládá doklad z řádku samovyměření
 * (základ z interního dokladu) a z položky „Rozdíl proti základu samovyměření (kurz)" bez
 * DPH ({@see InvoiceImporter::applySelfAssessment()}). V měně dokladu je z toho jedna
 * položka: částka v měně = celkem faktury v měně, v Kč celkem faktury, a když základ
 * samovyměření vyšel jiným kurzem, nese položka základ v Kč ze zdroje
 * (`purchase_invoice_items.import_tax_base_czk`). Evidence DPH z něj vyměří daň stejně jako
 * POHODA ({@see \MyInvoice\Service\Report\VatLedgerService}), takže přiznání i KH zůstávají
 * na haléř stejné jako převzetí v Kč.
 *
 * Jen jeden řádek samovyměření bez tuzemské daně dodavatele. Smíšený doklad nebo vyměření ve
 * více sazbách zůstává v Kč s důvodem. Stejné pravidlo používá převod i oprava už převedených
 * dokladů ({@see PohodaForeignPurchaseRepair}).
 */
final class PohodaSelfAssessedCurrency
{
    /** Popis položky rozdílu kurzu, kterou zakládá převod v Kč. */
    public const RATE_DIFFERENCE = 'Rozdíl proti základu samovyměření (kurz)';

    /**
     * Položky dokladu v Kč po samovyměření → jedna položka připravená pro převzetí v měně
     * (`foreign_base`, `foreign_vat`, případně `tax_base_czk`), nebo důvod, proč to nejde.
     * Pojistku kurzu (částka v měně × kurz = Kč) dělá až {@see \MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover::decide()}.
     *
     * @param list<array<string,mixed>> $items položky s `base`, `vat`, `rate`, `code`, `description`
     * @return list<array<string,mixed>>|string
     */
    public static function merge(array $items, float $foreignTotal): array|string
    {
        $assessed = [];
        $difference = 0.0;
        foreach ($items as $item) {
            if (abs((float) $item['vat']) >= 0.005) {
                return 'samovyměření u faktury s tuzemskou daní dodavatele';
            }
            if (self::isRateDifference($item)) {
                $difference += (float) $item['base'];
                continue;
            }
            $assessed[] = $item;
        }
        if (count($assessed) !== 1) {
            return $assessed === [] ? 'doklad nemá řádek samovyměření' : 'samovyměření ve více sazbách nebo řádcích';
        }
        $line = $assessed[0];
        $taxBase = round((float) $line['base'], 2);
        $home = round($taxBase + $difference, 2);
        return [[
            'base' => $home,
            'vat' => 0.0,
            'quantity' => 1.0,
            'unit_price' => $home,
            'foreign_base' => round($foreignTotal, 2),
            'foreign_vat' => 0.0,
            'tax_base_czk' => abs($taxBase - $home) >= 0.005 ? $taxBase : null,
        ] + $line];
    }

    /** Položka rozdílu kurzu z převodu v Kč: bez daně, sazby 0 % a s kódem mimo předmět daně. */
    public static function isRateDifference(array $item): bool
    {
        return ($item['code'] ?? null) === VatReturnLineClassifier::PURCHASE_OUTSIDE_SCOPE_CODE
            && abs((float) $item['vat']) < 0.005
            && abs((float) ($item['rate'] ?? 0.0)) < 0.005;
    }

    /** Věta do poznámky dokladu o základu samovyměření jiným kurzem, `null` = stejný kurz. */
    public static function note(?float $taxBase, string $currency): ?string
    {
        if ($taxBase === null) {
            return null;
        }
        return 'samovyměření DPH ze základu ' . number_format($taxBase, 2, ',', ' ')
            . ' Kč kurzem ke dni plnění jako v POHODĚ, ne přepočtem ' . $currency . ' kurzem dokladu';
    }
}
