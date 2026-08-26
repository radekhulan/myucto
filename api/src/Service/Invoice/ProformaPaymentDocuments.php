<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Doklad, který má vzniknout po zaúčtování platby na zálohovou fakturu.
 *
 * Pravidlo platí bez ohledu na to, kudy platba do systému přišla:
 *
 *   - proforma zůstala ČÁSTEČNĚ uhrazená → koncept daňového dokladu k přijaté
 *     platbě (`tax_document`),
 *   - proformu platba DOPLATILA → záleží na režimu firmy
 *     ({@see supplier.proforma_payment_document}, migrace 1565):
 *       * `final_on_full_payment` → koncept vyúčtovací faktury (`invoice`), která
 *         nese záporné odpočtové řádky § 37a, takže se daň z úplaty vypořádá v ní,
 *       * `always_tax_document` → i tady daňový doklad k přijaté platbě,
 *   - jiný typ dokladu → nevzniká nic.
 *
 * Proč ta volba (issue #39): „doplacená proforma" NENÍ totéž co „uskutečněné
 * plnění". U zakázkové výroby je proforma dílčí akontace na budoucí dílo — plná
 * úhrada zálohy na 70 % zakázky nic nedokončuje a odběratel potřebuje daňový
 * doklad k přijaté platbě, aby si uplatnil odpočet. U rychlého prodeje zboží
 * naopak proforma kryje celou objednávku a expeduje se ihned, takže vyúčtovací
 * faktura s DUZP = den platby je správně a je pohodlnější. Rozdíl je v obchodním
 * modelu, ne v datech dokladu, takže ho nejde odvodit a volí ho firma.
 *
 * Proč sdílená třída: tenhle if/else byl opsaný na pěti místech (automatické
 * párování výpisu, ruční spárování, rozúčtování jedné platby na víc faktur,
 * návrhy shod v2 a pokladní doklad) a rozešel se — větev rozúčtování zakládala
 * jen finální fakturu (issue #39). Účetní výsledek nesmí záviset na tom, kterou
 * cestou platba přišla, takže rozhodnutí patří na jedno místo.
 *
 * Pozn. k rozúčtování: dnes tam částečná úhrada nastat NEMŮŽE — každé faktuře se
 * přiřazuje celý zbytek a strážce součtu vyžaduje, aby se sečetly přesně na částku
 * platby. Chybějící větev tedy byla mrtvý kód, ne aktivní chyba. Volání je tu
 * i tak, aby se rozúčtování chovalo stejně jako ostatní cesty, kdyby se přidělování
 * částek někdy změnilo na dílčí — jinak by se ta samá díra otevřela znovu a tiše.
 *
 * Podmínky pro daňový doklad (plátcovství DPH k datu platby, ne-RC, neexistující
 * finál) si hlídá {@see PaymentTaxDocumentCreator} sám a nesplnění hlásí výjimkou;
 * tady se polyká, protože „doklad se nevystavuje" je legitimní výsledek, ne chyba
 * párování — platba je zaúčtovaná tak jako tak.
 */
final class ProformaPaymentDocuments
{
    /** Doplacená proforma zakládá vyúčtovací fakturu — rychlý prodej (výchozí). */
    public const MODE_FINAL_ON_FULL_PAYMENT = 'final_on_full_payment';
    /** I doplacená proforma zakládá daňový doklad k přijaté platbě — zakázková výroba. */
    public const MODE_ALWAYS_TAX_DOCUMENT = 'always_tax_document';

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::MODE_FINAL_ON_FULL_PAYMENT, self::MODE_ALWAYS_TAX_DOCUMENT];
    }

    /**
     * Režim firmy, které doklad patří. Čte se tady a ne u volajícího schválně:
     * volajících je pět a kdyby to měl každý předat sám, dřív nebo později to
     * jeden z nich zapomene a firmě začnou podle cesty platby vznikat různé doklady.
     *
     * Chybějící sloupec (nedoběhlá migrace 1565) i neznámá hodnota → null → dnešní
     * chování. Tichá změna toho, jaké doklady firmě vznikají, je horší než odklad.
     */
    public static function modeForInvoice(\PDO $pdo, int $invoiceId): ?string
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT s.proforma_payment_document
                   FROM invoices i JOIN supplier s ON s.id = i.supplier_id
                  WHERE i.id = ?'
            );
            $stmt->execute([$invoiceId]);
            $mode = $stmt->fetchColumn();
        } catch (\PDOException) {
            return null;
        }

        return is_string($mode) && in_array($mode, self::modes(), true) ? $mode : null;
    }

    /**
     * @param  FinalFromProformaCreator      $finalCreator   vždy dostupný
     * @param  PaymentTaxDocumentCreator|null $taxDocCreator null = izolovaná konstrukce
     *                                                      bez DI (testy, skripty)
     * @param  string|null $invoiceType   typ dokladu, ke kterému se platba váže
     * @param  bool        $becamePaid    platba doklad doplatila
     * @param  int|null    $paymentId     id řádku platby (nutné pro daňový doklad)
     * @param  string      $documentDate  DUZP vznikajícího dokladu = den přijetí platby
     * @param  \PDO|null   $pdo           spojení pro dohledání režimu firmy; null =
     *                                    izolovaná konstrukce → dnešní chování
     * @param  string|null $mode          explicitní `supplier.proforma_payment_document`
     *                                    (přebije dohledání); null = dohledat přes $pdo
     * @return array{final_draft_id: int|null, tax_document_id: int|null}
     */
    public static function afterPayment(
        FinalFromProformaCreator $finalCreator,
        ?PaymentTaxDocumentCreator $taxDocCreator,
        int $invoiceId,
        ?string $invoiceType,
        bool $becamePaid,
        ?int $paymentId,
        int $userId,
        string $documentDate,
        ?\PDO $pdo = null,
        ?string $mode = null,
    ): array {
        $result = ['final_draft_id' => null, 'tax_document_id' => null];
        if ($invoiceType !== 'proforma') {
            return $result;
        }
        if ($mode === null && $pdo !== null) {
            $mode = self::modeForInvoice($pdo, $invoiceId);
        }

        // Neznámý režim se chová jako dosud — chybějící migrace ani starý snapshot
        // nesmí firmě tiše změnit, jaké doklady jí vznikají.
        if ($becamePaid && $mode !== self::MODE_ALWAYS_TAX_DOCUMENT) {
            // DUZP finálního dokladu = den přijetí platby, ne dnešek: daň z úplaty
            // musí spadnout do období, ve kterém úplata skutečně přišla.
            $result['final_draft_id'] = $finalCreator->create($invoiceId, $userId, $documentDate);

            return $result;
        }

        if ($taxDocCreator === null || $paymentId === null || $paymentId <= 0) {
            return $result;
        }
        try {
            $result['tax_document_id'] = $taxDocCreator->createForPayment($paymentId, $userId);
        } catch (\RuntimeException) {
            // Neplátce DPH / přenesená daňová povinnost / už existuje finál — doklad
            // se nevystavuje a párování to nesmí shodit.
        }

        return $result;
    }
}
