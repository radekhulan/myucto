<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Doklad, který má vzniknout po zaúčtování platby na zálohovou fakturu.
 *
 * Pravidlo je jedno jediné a platí bez ohledu na to, kudy platba do systému přišla:
 *
 *   - proformu platba DOPLATILA  → koncept vyúčtovací faktury (`invoice`); ta nese
 *     záporné odpočtové řádky § 37a, takže se daň z úplaty vypořádá v ní,
 *   - proforma zůstala ČÁSTEČNĚ uhrazená → koncept daňového dokladu k přijaté
 *     platbě (`tax_document`),
 *   - jiný typ dokladu → nevzniká nic.
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
    /**
     * @param  FinalFromProformaCreator      $finalCreator   vždy dostupný
     * @param  PaymentTaxDocumentCreator|null $taxDocCreator null = izolovaná konstrukce
     *                                                      bez DI (testy, skripty)
     * @param  string|null $invoiceType   typ dokladu, ke kterému se platba váže
     * @param  bool        $becamePaid    platba doklad doplatila
     * @param  int|null    $paymentId     id řádku platby (nutné pro daňový doklad)
     * @param  string      $documentDate  DUZP vznikajícího dokladu = den přijetí platby
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
    ): array {
        $result = ['final_draft_id' => null, 'tax_document_id' => null];
        if ($invoiceType !== 'proforma') {
            return $result;
        }

        if ($becamePaid) {
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
