<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Service\Migration\MoneyS3\IssuedPaymentRecorder;
use MyInvoice\Service\Migration\Pohoda\DocumentLinker;
use MyInvoice\Service\Migration\Pohoda\PohodaPriorYearPayments;

/**
 * Evidovaná platba vydaného dokladu, kterou založil převod z jiného systému (POHODA,
 * Money S3). Jediné místo pravidel, která z převzetí plynou: popisek „Převzato" v detailu
 * dokladu, zákaz smazání a zákaz daňového dokladu k platbě.
 *
 * Smazat nejde platba, která nemá v MyÚčtu žádný protějšek: úhrada minulého roku
 * ({@see PohodaPriorYearPayments}) a dorovnání uhrazené částky podle POHODY
 * ({@see DocumentLinker::REMAINDER_NOTE}). Nese jen uhrazenou část z doby před převodem
 * bez bankovního pohybu, pokladního dokladu, zápočtu i zápisu v deníku. Po smazání by
 * přepočet z plateb snížil `paid_total`, doklad by se vrátil mezi neuhrazené a ručně
 * zadaná náhrada by šla do peněžního deníku a párování banky jako nové peníze.
 *
 * Platby převzaté z Money S3 se smazat dají: každá zrcadlí převedený pohyb (bankovní pohyb,
 * pokladní doklad, zápočet dobropisu `credit_note_offsets` nebo interním dokladem
 * `invoice_settlements`) a chová se stejně jako tatáž platba zadaná v MyÚčtu. Bankovní
 * hlídá vazba na pohyb, ostatní se ruší jako nativní a opakovaný převod je založí znovu
 * (rozpozná je podle poznámky). Stejně tak platby POHODY navázané na pohyb nebo pokladní
 * doklad (poznámka „Převzato z POHODY, úhrada …").
 */
final class TakenOverPayment
{
    public const ERROR_CODE = 'taken_over_payment';

    public const PROTECTED_MESSAGE = 'Úhrada převzatá z POHODY se nemaže; nese uhrazenou část z doby před převodem. Opravte ji opakovaným převodem.';

    private const POHODA_PREFIX = 'Převzato z POHODY';

    /** Poznámky plateb `settlement` bez protějšku v MyÚčtu. */
    private const PROTECTED_NOTES = [PohodaPriorYearPayments::NOTE, DocumentLinker::REMAINDER_NOTE];

    /** @param array{source?:mixed,note?:mixed} $payment */
    public static function isTakenOver(array $payment): bool
    {
        $note = (string) ($payment['note'] ?? '');
        return str_starts_with($note, self::POHODA_PREFIX) || str_starts_with($note, IssuedPaymentRecorder::NOTE_PREFIX);
    }

    /** @param array{source?:mixed,note?:mixed} $payment */
    public static function isProtected(array $payment): bool
    {
        return ($payment['source'] ?? null) === PohodaPriorYearPayments::SOURCE
            && in_array($payment['note'] ?? null, self::PROTECTED_NOTES, true);
    }

    /**
     * Úhrada minulého roku: daň z ní přiznala POHODA, daňový doklad k platbě se nevystavuje
     * ({@see PaymentTaxDocumentCreator}).
     *
     * @param array{source?:mixed,note?:mixed} $payment
     */
    public static function isPriorYear(array $payment): bool
    {
        return ($payment['source'] ?? null) === PohodaPriorYearPayments::SOURCE
            && ($payment['note'] ?? null) === PohodaPriorYearPayments::NOTE;
    }

    /** SQL podmínka {@see isProtected()} nad aliasem `invoice_payments`. */
    public static function isProtectedSql(string $alias): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias.');
        }
        $notes = implode(', ', array_map(static fn (string $n): string => "'" . $n . "'", self::PROTECTED_NOTES));
        return "({$alias}.source = '" . PohodaPriorYearPayments::SOURCE . "' AND {$alias}.note IN ({$notes}))";
    }
}
