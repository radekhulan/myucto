<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Jediný zdroj pravdy pro otázku „kolik je na PŘIJATÉ faktuře už uhrazeno".
 *
 * Vydaná faktura má `paid_total` — jeden sloupec, který drží všechny kanály úhrady.
 * Přijatá ho NEMÁ: úhrada k ní visí ve třech různých tabulkách podle toho, kudy přišla:
 *
 *   - **banka** — `payment_matches.purchase_invoice_id` (spárovaná bankovní transakce),
 *   - **vzájemný zápočet** — `offset_agreement_items` u dohody ve stavu `confirmed`
 *     ({@see \MyInvoice\Service\Accounting\OffsetService}, dvojice FV ↔ PF),
 *   - **zápočet proti účtu** — `invoice_settlements` ve stavu `confirmed`
 *     ({@see \MyInvoice\Service\Accounting\InvoiceSettlementService}, 321 MD / zvolený účet D).
 *
 * Pokladna se sem NEPOČÍTÁ: hotovostní úhrada přijaté faktury je vždy v plné výši
 * a doklad rovnou překlopí na `paid` ({@see \MyInvoice\Service\Accounting\Cash\CashSettlementService}),
 * takže do výpočtu zbytku — který se ptá jen na doklady ve stavu `received`/`booked` —
 * nikdy nevstoupí. Kdyby pokladna někdy uměla částečnou úhradu, patří sem taky.
 *
 * Vzniklo, když zápočet proti účtu dostal ČÁSTEČNOU výši. Do té doby směl být jen
 * v plné částce a doklad hned překlopil na `paid`, takže ostatním výpočtům zbytku
 * stačilo `invoice_settlements` ignorovat — započtená faktura mezi kandidáty prostě
 * nebyla. Jakmile smí zůstat částečně otevřená, musí ji stejně vidět vzájemný zápočet
 * (jinak by tutéž korunu započetl dvakrát) i uzávěrková kontrola otevřeného salda
 * (jinak by vyrovnaný doklad hlásila jako díru v deníku).
 */
final class PurchaseSettledExpr
{
    /**
     * Skalární SQL výraz se součtem už uhrazené části přijaté faktury v CZK.
     *
     * Parametry `exclude*` slouží přepočtu při ZMĚNĚ daného dokladu — potvrzovaná dohoda
     * nebo rušený zápočet se nesmí počítat proti sobě. Jde o čísla (int), takže se do SQL
     * vkládají literálem; žádná uživatelská hodnota se tudy nedostane.
     *
     * @param string $alias      alias tabulky `purchase_invoices` v okolním dotazu
     * @param int    $excludeAgreementId  ID dohody o zápočtu, kterou vynechat (0 = žádnou)
     * @param int    $excludeSettlementId ID zápočtu proti účtu, který vynechat (0 = žádný)
     */
    public static function settled(string $alias = 'pi', int $excludeAgreementId = 0, int $excludeSettlementId = 0): string
    {
        $a = $alias === '' ? '' : $alias . '.';

        return sprintf(
            'COALESCE((SELECT SUM(pm.amount) FROM payment_matches pm
                        WHERE pm.supplier_id = %1$ssupplier_id AND pm.purchase_invoice_id = %1$sid), 0)
           + COALESCE((SELECT SUM(oi.amount) FROM offset_agreement_items oi
                        JOIN offset_agreements oa ON oa.id = oi.agreement_id AND oa.status = %2$s
                       WHERE oi.supplier_id = %1$ssupplier_id AND oi.doc_type = %3$s
                         AND oi.doc_id = %1$sid AND oa.id <> %4$d), 0)
           + COALESCE((SELECT SUM(s.amount) FROM invoice_settlements s
                       WHERE s.supplier_id = %1$ssupplier_id AND s.doc_type = %3$s
                         AND s.doc_id = %1$sid AND s.status = %2$s AND s.id <> %5$d), 0)',
            $a,
            "'confirmed'",
            "'purchase_invoice'",
            $excludeAgreementId,
            $excludeSettlementId,
        );
    }

    /**
     * Σ započtené části přijaté faktury K DATU — jen obě ZÁPOČTOVÉ cesty, BEZ banky.
     *
     * Pro čtecí stranu (saldo, konfrontace s hlavní knihou), která se ptá „jak to vypadalo
     * k rozvahovému dni". {@see settled()} je oproti tomu stav K TEĎ a banku obsahuje —
     * saldo si ji počítá vlastním, přesnějším datem uznání (`SaldoRepository::bankSettleCte()`
     * bere `entry_date` zaúčtovaného bankovního zápisu), takže by se tudy jen zdvojila.
     *
     * Datum uznání zápočtu je `settled_on` / `agreement_date` — přesně to, s čím se zakládá
     * účetní zápis (`InvoiceSettlementService`, `OffsetService`), takže hlavní kniha zná týž den.
     *
     * STORNO je časově uvědomělé, stejně jako všude jinde v saldu: zrušený zápočet se k datu
     * PŘED protizápisem pořád počítá, jinak by dnešní storno zpětně otevřelo minulé saldo.
     * Zápočet bez účetního zápisu (daňová evidence, ještě nedoúčtovaný) protizápis nemá,
     * takže o něm rozhoduje jen jeho stav.
     *
     * Obsahuje ČTYŘI placeholdery v pořadí: agreement_date, storno dohody, settled_on,
     * storno zápočtu — všechny jsou `asOf`.
     *
     * @param string $alias alias tabulky `purchase_invoices` v okolním dotazu
     */
    public static function offsetSettledAsOf(string $alias = 'pi'): string
    {
        $a = $alias === '' ? '' : $alias . '.';

        // „Byl zápočet k asOf ještě živý?" — buď platí dosud, nebo ho protizápis zrušil až potom.
        $liveAsOf = static fn (string $head): string =>
            "({$head}.status = 'confirmed'
              OR EXISTS (SELECT 1
                           FROM journal_entries oe
                           JOIN journal_entries orev ON orev.id = oe.reversed_by
                          WHERE oe.id = {$head}.journal_entry_id
                            AND oe.supplier_id = {$head}.supplier_id
                            AND orev.entry_date > ?))";

        return sprintf(
            'COALESCE((SELECT SUM(oi.amount) FROM offset_agreement_items oi
                        JOIN offset_agreements oa ON oa.id = oi.agreement_id
                       WHERE oi.supplier_id = %1$ssupplier_id AND oi.doc_type = %2$s
                         AND oi.doc_id = %1$sid AND oa.agreement_date <= ? AND %3$s), 0)
           + COALESCE((SELECT SUM(s.amount) FROM invoice_settlements s
                       WHERE s.supplier_id = %1$ssupplier_id AND s.doc_type = %2$s
                         AND s.doc_id = %1$sid AND s.settled_on <= ? AND %4$s), 0)',
            $a,
            "'purchase_invoice'",
            $liveAsOf('oa'),
            $liveAsOf('s'),
        );
    }

    /**
     * Zbytek k úhradě = `amount_to_pay` − {@see settled()}. Záporný nevzniká sám od sebe,
     * ale přeplatek (banka poslala víc) ho udělat může — volající si ho ořízne, když
     * potřebuje „kolik ještě smím započíst".
     */
    public static function remaining(string $alias = 'pi', int $excludeAgreementId = 0, int $excludeSettlementId = 0): string
    {
        $a = $alias === '' ? '' : $alias . '.';

        return sprintf(
            '%samount_to_pay - (%s)',
            $a,
            self::settled($alias, $excludeAgreementId, $excludeSettlementId),
        );
    }
}
