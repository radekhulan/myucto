<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Vat;

/**
 * Zákonné DUZP pořízení zboží z jiného členského státu dle § 25 odst. 1 ZDPH.
 *
 * Povinnost přiznat daň vzniká k 15. dni měsíce následujícího po měsíci, v němž bylo
 * zboží pořízeno; byl-li daňový doklad vystaven před tímto dnem, vzniká ke dni vystavení
 * dokladu. Zahraniční doklad typicky nese jen DATUM DODÁNÍ (Leistungsdatum / date of
 * supply) — to zákonné DUZP NENÍ. Na správném DUZP stojí zařazení do zdaňovacího období
 * (`VatLedgerService`) i kurz ČNB (§ 4 odst. 8 — kurz ke dni vzniku povinnosti přiznat daň).
 *
 * ── Proč vlastní třída ────────────────────────────────────────────────────────────
 * Pravidlo dřív žilo jako statická metoda uvnitř AI extraktoru a volalo se z JEDINÉHO
 * místa — jen když AI vrátila `supply_nature = 'goods'` (klasifikace `23`). ISDOC,
 * iDoklad, Fakturoid ani ruční zadání § 25 nedopočítaly vůbec, takže tentýž doklad
 * spadl do jiného období podle toho, kudy do systému přišel (audit VAT klasifikací
 * 2026-08, nález M-7). Pravidlo schované v jedné importní třídě se okopíruje rychleji,
 * než kdyby neexistovalo — proto je SSOT tady a extraktor ho volá.
 *
 * ── Co tahle třída ZÁMĚRNĚ nedělá ─────────────────────────────────────────────────
 * Nehádá datum dodání. Bez něj vrací `null` a doklad si nechá DUZP tak, jak ho zadal
 * člověk nebo import — dopočítat 15. den z data vystavení nejde (měsíc pořízení neznáme)
 * a tichý odhad by přesunul daň do jiného období. Chybějící datum dodání se hlásí
 * varováním ({@see \MyInvoice\Service\Validation\PurchaseInvoiceValidation::warnings()}).
 */
final class EuAcquisitionTaxDate
{
    /**
     * @param ?string $deliveryDate datum dodání / převzetí zboží (YYYY-MM-DD)
     * @param string  $issueDate    datum vystavení dokladu (YYYY-MM-DD)
     * @return ?string DUZP (YYYY-MM-DD), nebo null když datum dodání chybí či nejde parsovat
     */
    public static function for(?string $deliveryDate, string $issueDate): ?string
    {
        if ($deliveryDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            return null;
        }
        try {
            $fifteenth = (new \DateTimeImmutable($deliveryDate))
                ->modify('first day of next month')
                ->format('Y-m') . '-15';
        } catch (\Throwable) {
            return null;
        }
        // Doklad vystavený před 15. dnem následujícího měsíce → DUZP = den vystavení.
        // Lexikografické porovnání YYYY-MM-DD je korektní porovnání dat.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate) && $issueDate < $fifteenth) {
            return $issueDate;
        }

        return $fifteenth;
    }

    /**
     * Klasifikační kódy, u kterých § 25 platí — pořízení ZBOŽÍ z JČS.
     *
     * Přijetí služby z JČS (`24e`) se řídí § 24 (den poskytnutí), ne § 25, a dovoz ze
     * 3. země (`25`) § 23 (propuštění celním úřadem). Kdyby se pravidlo pustilo na ně,
     * posunulo by daň o půl měsíce v dokladech, kterých se netýká.
     *
     * @var list<string>
     */
    public const GOODS_ACQUISITION_CODES = ['23'];

    /**
     * Nese doklad (hlavička nebo některý řádek) pořízení zboží z JČS?
     *
     * @param array<string,mixed> $invoice záznam z PurchaseInvoiceRepository::find()
     */
    public static function appliesTo(array $invoice): bool
    {
        $codes = [trim((string) ($invoice['vat_classification_code'] ?? ''))];
        foreach ((array) ($invoice['items'] ?? []) as $item) {
            if (is_array($item)) {
                $codes[] = trim((string) ($item['vat_classification_code'] ?? ''));
            }
        }

        return array_intersect($codes, self::GOODS_ACQUISITION_CODES) !== [];
    }
}
