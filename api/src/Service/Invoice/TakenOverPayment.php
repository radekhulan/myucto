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
 * ({@see PohodaPriorYearPayments}), dorovnání uhrazené částky podle POHODY
 * ({@see DocumentLinker::REMAINDER_NOTE}) a úhrada, kterou Money S3 vede jen datem úhrady
 * bez dokladu úhrady v převedených datech ({@see MONEY_S3_WITHOUT_DOCUMENT_NOTE}). Nese
 * uhrazenou část bez bankovního pohybu, pokladního dokladu, zápočtu i zápisu v deníku.
 * Po smazání by přepočet z plateb snížil `paid_total`, doklad by se vrátil mezi neuhrazené
 * a ručně zadaná náhrada by šla do peněžního deníku a párování banky jako nové peníze.
 *
 * Ostatní platby převzaté z Money S3 se smazat dají: každá zrcadlí převedený pohyb
 * (bankovní pohyb, pokladní doklad, zápočet dobropisu `credit_note_offsets` nebo interním
 * dokladem `invoice_settlements`) a chová se stejně jako tatáž platba zadaná v MyÚčtu.
 * Bankovní hlídá vazba na pohyb, ostatní se ruší jako nativní a opakovaný převod je založí
 * znovu (rozpozná je podle poznámky). Stejně tak platby POHODY navázané na pohyb nebo
 * pokladní doklad (poznámka „Převzato z POHODY, úhrada …").
 *
 * Daň z platby přiznal původní program ({@see isTaxDeclaredByOrigin()}): úhrada minulého
 * roku z POHODY a každá platba převzatá z Money S3. Daňový doklad k ní se v MyÚčtu
 * nevystavuje, kontrola DPH ho nehledá a CRM ho nečeká.
 */
final class TakenOverPayment
{
    public const ERROR_CODE = 'taken_over_payment';

    public const PROTECTED_MESSAGE = 'Úhrada převzatá z původního programu se nemaže; nese uhrazenou část, ke které v MyÚčtu není doklad úhrady. Opravte ji opakovaným převodem.';

    /**
     * Začátek poznámky platby `settlement`, kterou převod z Money S3 zakládá na uhrazenou
     * částku bez dokladu úhrady v převedených datech (zápočet mimo deník, prázdné číslo
     * úhrady). Za ní může v závorce následovat číslo úhrady z Money.
     */
    public const MONEY_S3_WITHOUT_DOCUMENT_NOTE = IssuedPaymentRecorder::NOTE_PREFIX . 'úhrada bez dokladu úhrady';

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
        if (($payment['source'] ?? null) !== PohodaPriorYearPayments::SOURCE) {
            return false;
        }
        $note = $payment['note'] ?? null;
        return in_array($note, self::PROTECTED_NOTES, true)
            || (is_string($note) && str_starts_with($note, self::MONEY_S3_WITHOUT_DOCUMENT_NOTE));
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

    /**
     * Daň z platby přiznal původní program, daňový doklad k ní se v MyÚčtu nevystavuje
     * ({@see PaymentTaxDocumentCreator}), kontrola DDKP ji nehlásí a CRM nečeká.
     *
     * - Úhrada minulého roku z POHODY ({@see isPriorYear()}): DDKP je v minulé agendě.
     * - Každá platba převzatá z Money S3 (poznámka {@see IssuedPaymentRecorder::NOTE_PREFIX}):
     *   převod ji zakládá jen z převedených dat (pohyb banky či pokladny, zápočet, datum úhrady
     *   do konce posledního převáděného období), tedy vždy v období, jehož DPH podala Money
     *   včetně DDKP, který převod přebírá jako samostatný doklad. Nový DDKP k ní by zálohu
     *   zdanil podruhé. Platba zadaná v MyÚčtu tu poznámku nemá.
     *
     * Platby POHODY navázané na pohyb nebo pokladní doklad sem záměrně nepatří: agenda POHODY
     * nese i úhrady pozdějšího roku, který už může běžet v MyÚčtu ({@see PohodaPriorYearPayments}
     * řeší jen rok před agendou), a podle poznámky nejde poznat, zda daň z nich přiznala
     * POHODA. Kontrola DDKP je proto hledá stejně jako nativní platby.
     *
     * @param array{source?:mixed,note?:mixed} $payment
     */
    public static function isTaxDeclaredByOrigin(array $payment): bool
    {
        return self::isPriorYear($payment) || str_starts_with((string) ($payment['note'] ?? ''), IssuedPaymentRecorder::NOTE_PREFIX);
    }

    /** SQL podmínka {@see isTaxDeclaredByOrigin()} nad aliasem `invoice_payments`. */
    public static function isTaxDeclaredByOriginSql(string $alias): string
    {
        self::assertAlias($alias);
        return "(({$alias}.source = '" . PohodaPriorYearPayments::SOURCE . "' AND {$alias}.note <=> '" . PohodaPriorYearPayments::NOTE . "')"
            . " OR COALESCE({$alias}.note, '') LIKE '" . IssuedPaymentRecorder::NOTE_PREFIX . "%')";
    }

    /** SQL podmínka {@see isProtected()} nad aliasem `invoice_payments`. */
    public static function isProtectedSql(string $alias): string
    {
        self::assertAlias($alias);
        $notes = implode(', ', array_map(static fn (string $n): string => "'" . $n . "'", self::PROTECTED_NOTES));
        return "({$alias}.source = '" . PohodaPriorYearPayments::SOURCE . "' AND COALESCE({$alias}.note IN ({$notes})"
            . " OR {$alias}.note LIKE '" . self::MONEY_S3_WITHOUT_DOCUMENT_NOTE . "%', 0))";
    }

    private static function assertAlias(string $alias): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias.');
        }
    }
}
