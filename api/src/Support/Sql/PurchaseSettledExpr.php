<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

use MyInvoice\Service\Bank\FxPaymentSettlement;

/**
 * Jediný zdroj pravdy pro otázku „kolik je na PŘIJATÉ faktuře už uhrazeno".
 *
 * Vydaná faktura má `paid_total` — jeden sloupec, který drží všechny kanály úhrady.
 * Přijatá ho NEMÁ: úhrada k ní visí ve čtyřech různých tabulkách podle toho, kudy přišla:
 *
 *   - **banka** — `payment_matches.purchase_invoice_id` (spárovaná bankovní transakce),
 *   - **pokladna** — `cash_documents.purchase_invoice_id` ve stavu `posted`
 *     (výdej úhradu zvyšuje, vratka `doc_type='in'` ji snižuje),
 *   - **vzájemný zápočet** — `offset_agreement_items` u dohody ve stavu `confirmed`
 *     ({@see \MyInvoice\Service\Accounting\OffsetService}, dvojice FV ↔ PF),
 *   - **zápočet proti účtu** — `invoice_settlements` ve stavu `confirmed`
 *     ({@see \MyInvoice\Service\Accounting\InvoiceSettlementService}, 321 MD / zvolený účet D),
 *   - **zápočet dobropisu** — `credit_note_offsets` na opravované faktuře
 *     ({@see \MyInvoice\Service\Invoice\CreditNoteOffsetService}, bez účetního zápisu:
 *     dobropis sám už 321 snížil).
 *
 * Všechno se sčítá v MĚNĚ DOKLADU, stejně jako `amount_to_pay`, proti kterému se zbytek
 * počítá. Zápočty se v ní evidují přímo. Banka a pokladna ne: `payment_matches.amount`
 * drží částku v měně TRANSAKCE a pokladní doklad v měně pokladny. Dokud se to sčítalo
 * napřímo, korunová úhrada eurové faktury vypadala jako mnohonásobný přeplatek a eurová
 * úhrada korunové faktury skoro jako nic ({@see bankAmountSql()}).
 *
 * Pokladna tu dřív nebyla s odůvodněním, že hotovostní úhrada je vždy v plné výši a doklad
 * rovnou překlopí na `paid`. Ruční pokladní doklad ale částečnou úhradu umí a ptá se na
 * zbytek i doklad ve stavu `paid` (sloupec „Zbývá uhradit" v seznamu), takže bez ní by
 * hotově zaplacená faktura svítila jako celá neuhrazená.
 */
final class PurchaseSettledExpr
{
    /**
     * Skalární SQL výraz se součtem už uhrazené části přijaté faktury v měně dokladu.
     *
     * Parametry `exclude*` slouží přepočtu při ZMĚNĚ daného dokladu — potvrzovaná dohoda,
     * rušený zápočet, přepárovaný pohyb nebo přepočítávaný pokladní doklad se nesmí počítat
     * proti sobě. Jde o čísla (int), takže se do SQL vkládají literálem; žádná uživatelská
     * hodnota se tudy nedostane.
     *
     * @param string $alias      alias tabulky `purchase_invoices` v okolním dotazu
     * @param int    $excludeAgreementId  ID dohody o zápočtu, kterou vynechat (0 = žádnou)
     * @param int    $excludeSettlementId ID zápočtu proti účtu, který vynechat (0 = žádný)
     */
    public static function settled(
        string $alias = 'pi',
        int $excludeAgreementId = 0,
        int $excludeSettlementId = 0,
        int $excludeBankTransactionId = 0,
        int $excludeCashDocumentId = 0,
    ): string {
        $a = $alias === '' ? '' : $alias . '.';

        return sprintf(
            'COALESCE((SELECT SUM(%7$s) FROM payment_matches pm
                         LEFT JOIN bank_transactions pmbt ON pmbt.id = pm.bank_transaction_id
                         LEFT JOIN bank_statements pmbs ON pmbs.id = pmbt.statement_id
                         LEFT JOIN currencies pmdc ON pmdc.id = %1$scurrency_id
                        WHERE pm.supplier_id = %1$ssupplier_id AND pm.purchase_invoice_id = %1$sid
                          AND (%6$d = 0 OR pm.bank_transaction_id <> %6$d)), 0)
           + COALESCE((SELECT SUM(%9$s) FROM cash_documents pcd
                         LEFT JOIN currencies pcdc ON pcdc.id = %1$scurrency_id
                        WHERE pcd.supplier_id = %1$ssupplier_id AND pcd.purchase_invoice_id = %1$sid
                          AND pcd.status = %8$s AND pcd.id <> %10$d), 0)
           + COALESCE((SELECT SUM(oi.amount) FROM offset_agreement_items oi
                        JOIN offset_agreements oa ON oa.id = oi.agreement_id AND oa.status = %2$s
                       WHERE oi.supplier_id = %1$ssupplier_id AND oi.doc_type = %3$s
                         AND oi.doc_id = %1$sid AND oa.id <> %4$d), 0)
           + COALESCE((SELECT SUM(s.amount) FROM invoice_settlements s
                       WHERE s.supplier_id = %1$ssupplier_id AND s.doc_type = %3$s
                         AND s.doc_id = %1$sid AND s.status = %2$s AND s.id <> %5$d), 0)
           + COALESCE((SELECT SUM(cno.amount) FROM credit_note_offsets cno
                       WHERE cno.supplier_id = %1$ssupplier_id AND cno.doc_type = %3$s
                         AND cno.invoice_id = %1$sid), 0)',
            $a,
            "'confirmed'",
            "'purchase_invoice'",
            $excludeAgreementId,
            $excludeSettlementId,
            $excludeBankTransactionId,
            self::bankAmountSql('pm', 'pmbt', 'pmbs', $alias, 'pmdc'),
            "'posted'",
            self::cashAmountSql('pcd', $alias, 'pcdc'),
            $excludeCashDocumentId,
        );
    }

    /**
     * Částka jednoho bankovního párování (`payment_matches`) převedená do MĚNY DOKLADU.
     *
     *   - Stejná měna transakce a dokladu → částka párování, jak je.
     *   - Korunový pohyb k cizoměnovému dokladu → úhrada CELÉHO nominálu, pokud se pohyb
     *     vejde do kurzové tolerance ({@see FxPaymentSettlement::matchTolerance()}). Přesně
     *     tak ho zaúčtuje banka: 321 jde ven v nominálu kurzem předpisu a rozdíl je kurzový
     *     (`BankPostingService::buildOutgoingCzkCardFx`). Mimo toleranci se přepočte kurzem
     *     dokladu — pak jde o skutečnou částečnou úhradu.
     *   - Jiná kombinace (cizí měna pohybu × jiná měna dokladu, třeba USD faktura placená
     *     z eurového účtu) se převést neumí. Banka ji automaticky nezaúčtuje
     *     (`cross_currency`), účetní ji zaúčtuje ručně celou i s kurzovým rozdílem — proto
     *     se bere jako úhrada celého nominálu. Syrová částka párování v cizí měně by se
     *     sčítala jako by byla v měně dokladu a vyrobila by nedoplatek, který v deníku není.
     *
     * Směr určuje znaménko, stejně jako u pokladny: `payment_matches.amount` je vždy kladné,
     * odchozí platba úhradu zvyšuje a PŘÍCHOZÍ (vratka dobropisu, vrácený přeplatek) ji
     * snižuje. Bez toho vrácený dobropis (`amount_to_pay` záporné) ukazoval zbytek
     * dvojnásobku své částky a saldo ho nechávalo otevřený. Větve s nominálem vrací
     * `amount_to_pay`, které znaménko dokladu už nese.
     *
     * Veřejné, protože totéž potřebuje saldo k rozvahovému dni, které si banku skládá
     * vlastní agregací s datem zaúčtování.
     *
     * @param string $pm       alias `payment_matches`
     * @param string $bt       alias `bank_transactions` párování
     * @param string $bs       alias `bank_statements` transakce
     * @param string $doc      alias `purchase_invoices`
     * @param string $docCurrency alias `currencies` měny dokladu
     */
    public static function bankAmountSql(string $pm, string $bt, string $bs, string $doc, string $docCurrency): string
    {
        $d = $doc === '' ? '' : $doc . '.';
        $txCurrency = "UPPER(COALESCE(NULLIF({$bt}.currency, ''), NULLIF({$bs}.currency, ''), 'CZK'))";
        $nominalLocal = "ROUND({$d}amount_to_pay * {$d}exchange_rate, 2)";

        // Bez GREATEST(): výraz běží i v jednotkových testech nad SQLite.
        return sprintf(
            "CASE WHEN %9\$s.id IS NULL OR %1\$s = UPPER(%2\$s.code) THEN %10\$s * %3\$s.amount
                  WHEN %1\$s = '%4\$s' AND %5\$sexchange_rate > 0 THEN
                       CASE WHEN ABS(%3\$s.amount - ABS(%6\$s)) <= %7\$s
                              OR ABS(%3\$s.amount - ABS(%6\$s)) <= ABS(%6\$s) * %8\$s
                            THEN %5\$samount_to_pay
                            ELSE %10\$s * ROUND(%3\$s.amount / %5\$sexchange_rate, 2) END
                  ELSE %5\$samount_to_pay END",
            $txCurrency,
            $docCurrency,
            $pm,
            FxPaymentSettlement::LOCAL_CURRENCY,
            $d,
            $nominalLocal,
            self::sqlNumber(FxPaymentSettlement::AMOUNT_TOLERANCE),
            self::sqlNumber(FxPaymentSettlement::MATCH_TOLERANCE_PCT),
            $bt,
            "(CASE WHEN {$bt}.amount > 0 THEN -1 ELSE 1 END)",
        );
    }

    /**
     * Částka pokladního dokladu převedená do měny dokladu, se znaménkem (vratka odečítá).
     * Pokladna ve měně dokladu nese cizí částku v `amount_foreign`; korunová pokladna
     * k cizoměnovému dokladu se přepočte kurzem dokladu. Veřejné ze stejného důvodu jako
     * {@see bankAmountSql()}.
     *
     * @param string $cd          alias `cash_documents`
     * @param string $doc         alias `purchase_invoices`
     * @param string $docCurrency alias `currencies` měny dokladu
     */
    public static function cashAmountSql(string $cd, string $doc, string $docCurrency): string
    {
        $d = $doc === '' ? '' : $doc . '.';

        return "(CASE WHEN {$cd}.doc_type = 'in' THEN -1 ELSE 1 END)
                * (CASE WHEN UPPER({$docCurrency}.code) = 'CZK' AND UPPER({$cd}.currency_code) = 'CZK' THEN {$cd}.total_amount
                        WHEN UPPER({$cd}.currency_code) = UPPER({$docCurrency}.code) THEN COALESCE({$cd}.amount_foreign, {$cd}.total_amount)
                        WHEN {$d}exchange_rate > 0 THEN ROUND({$cd}.total_amount / {$d}exchange_rate, 2)
                        ELSE {$cd}.total_amount END)";
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
     * Obsahuje PĚT placeholderů v pořadí: agreement_date, storno dohody, settled_on,
     * storno zápočtu, datum zápočtu dobropisu — všechny jsou `asOf`. Zápočet dobropisu
     * nemá storno: zrušený zápočet řádek smaže a dobropis zůstane otevřený sám za sebe.
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
                         AND s.doc_id = %1$sid AND s.settled_on <= ? AND %4$s), 0)
           + COALESCE((SELECT SUM(cno.amount) FROM credit_note_offsets cno
                       WHERE cno.supplier_id = %1$ssupplier_id AND cno.doc_type = %2$s
                         AND cno.invoice_id = %1$sid AND cno.offset_on <= ?), 0)',
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

    /**
     * „Uhrazeno" tak, jak ho ukazuje seznam a detail dokladu.
     *
     * Oproti {@see settled()} jediný rozdíl: doklad ve stavu `paid`, ke kterému žádná
     * evidovaná úhrada neexistuje (ruční „Označit jako uhrazené", převzatá data), se bere
     * jako uhrazený v plné výši. Tvrdí to jeho stav a nic, co by to vyvracelo, v evidenci
     * není — stejně ho čte saldo (`status='paid'` bez úhrady ⇒ plně kryto). Kde nějaká
     * úhrada existuje, rozhoduje její výše, takže nedoplatek na „uhrazeném" dokladu
     * zůstane vidět.
     */
    public static function paidAmount(string $alias = 'pi'): string
    {
        $a = $alias === '' ? '' : $alias . '.';
        $settled = self::settled($alias);

        // DDKP (§ 28 ZDPH) nic nedluží: peníze odešly na zálohové faktuře a jeho
        // `amount_to_pay` je jen generované brutto zálohy ({@see PayablePredicate}).
        return "CASE WHEN {$a}document_kind = '" . PayablePredicate::NON_PAYABLE_DOCUMENT_KIND . "' THEN {$a}amount_to_pay
                     WHEN {$a}status = 'paid' AND ABS({$settled}) < 0.005 THEN {$a}amount_to_pay
                     ELSE {$settled} END";
    }

    /** Zbývá uhradit podle {@see paidAmount()} — v měně dokladu. */
    public static function remainingAmount(string $alias = 'pi'): string
    {
        $a = $alias === '' ? '' : $alias . '.';

        return "{$a}amount_to_pay - (" . self::paidAmount($alias) . ')';
    }

    /**
     * „Uhrazeno s rozdílem": doklad `paid`, jehož zbytek po evidovaných úhradách je
     * v korunách vyšší než tolerance dorovnání. Hranice je v KORUNÁCH (zbytek × kurz
     * dokladu), stejně jako saldo (`SaldoRepository::SHORTFALL_TOLERANCE_CZK`)
     * a uzávěrková kontrola `paid_purchases_open_saldo` — haléřové zbytky, které banka
     * dorovnává, štítek nedostanou, eurový nedoplatek o pár eur ano.
     *
     * @param string $currencyCode SQL výraz s kódem měny dokladu (typicky `cur.code`)
     */
    public static function paidShortfallCondition(string $alias, string $currencyCode): string
    {
        $a = $alias === '' ? '' : $alias . '.';

        return "({$a}status = 'paid' AND (" . self::remainingAmount($alias) . ")
                 * (CASE WHEN UPPER({$currencyCode}) = 'CZK' THEN 1 ELSE COALESCE(NULLIF({$a}exchange_rate, 0), 1) END)
                 > " . self::sqlNumber(FxPaymentSettlement::AMOUNT_TOLERANCE) . ')';
    }

    private static function sqlNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
