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
 * roku z POHODY a platba převzatá z Money S3, kterou kryje DDKP z Money nebo která leží
 * v podaném či uzavřeném období. Daňový doklad k ní se v MyÚčtu nevystavuje, kontrola DPH
 * ho nehledá a CRM ho nečeká.
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

    /** Zdroje plateb, které zakládá převod z Money S3 ({@see IssuedPaymentRecorder}). */
    private const MONEY_S3_SOURCES = ['bank', 'cash', 'credit_note', 'settlement'];

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
     * Platba založená převodem z Money S3: zdroj, který převod zakládá (pohyb banky, pokladna,
     * zápočet dobropisu, zápočet či úhrada bez dokladu úhrady) a poznámka převodu. Ruční
     * platba se zkopírovanou poznámkou převzatá není. SQL podoba navíc chce doklad z mapy
     * převodu ({@see moneyS3PaymentSql()}).
     *
     * @param array{source?:mixed,note?:mixed} $payment
     */
    public static function isMoneyS3Payment(array $payment): bool
    {
        return in_array($payment['source'] ?? null, self::MONEY_S3_SOURCES, true)
            && str_starts_with((string) ($payment['note'] ?? ''), IssuedPaymentRecorder::NOTE_PREFIX);
    }

    /**
     * Daň z platby přiznal původní program, daňový doklad k ní se v MyÚčtu nevystavuje
     * ({@see PaymentTaxDocumentCreator}), kontrola DDKP ji nehlásí a CRM nečeká.
     *
     * - Úhrada minulého roku z POHODY ({@see isPriorYear()}): DDKP je v minulé agendě.
     * - Platba převzatá z Money S3 ({@see isMoneyS3Payment()}, doklad z mapy převodu), když
     *   je prokazatelně zdaněná: k ní je navázaný živý DDKP, DDKP převzaté z Money k záloze
     *   pokrývají převzaté platby zálohy až po tuto (v pořadí data), nebo leží v období, které
     *   je podané (přiznání DPH), zamčené (`locked_until`) nebo uzavřené. Platba zálohy, ke
     *   které Money DDKP nevystavilo, v otevřeném období zůstává v kontrole DPH i v CRM a DDKP
     *   k ní jde vystavit jako k nativní platbě.
     *
     * Platby POHODY navázané na pohyb nebo pokladní doklad sem záměrně nepatří: agenda POHODY
     * nese i úhrady pozdějšího roku, který už může běžet v MyÚčtu ({@see PohodaPriorYearPayments}
     * řeší jen rok před agendou), a podle poznámky nejde poznat, zda daň z nich přiznala
     * POHODA. Kontrola DDKP je proto hledá stejně jako nativní platby.
     *
     * Bez `$pdo` (a id platby) se platba Money za zdaněnou nepovažuje: bez dat to nejde doložit.
     *
     * @param array{id?:mixed,source?:mixed,note?:mixed} $payment
     */
    public static function isTaxDeclaredByOrigin(array $payment, ?\PDO $pdo = null): bool
    {
        if (self::isPriorYear($payment)) {
            return true;
        }
        if (!self::isMoneyS3Payment($payment) || $pdo === null || (int) ($payment['id'] ?? 0) <= 0) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM invoice_payments ip WHERE ip.id = ? AND ' . self::isTaxDeclaredByOriginSql('ip'));
        $stmt->execute([(int) $payment['id']]);
        return $stmt->fetchColumn() !== false;
    }

    /** SQL podmínka {@see isTaxDeclaredByOrigin()} nad aliasem `invoice_payments`. */
    public static function isTaxDeclaredByOriginSql(string $alias): string
    {
        self::assertAlias($alias);
        $a = $alias;
        $moneyTaxDocuments = "SELECT COALESCE(SUM(ttd.total_with_vat), 0) FROM invoices ttd
                               WHERE ttd.supplier_id = {$a}.supplier_id AND ttd.parent_invoice_id = {$a}.invoice_id
                                 AND ttd.invoice_type = 'tax_document' AND ttd.status NOT IN ('draft', 'cancelled')
                                 AND EXISTS (SELECT 1 FROM money_s3_import_map tdm
                                              WHERE tdm.supplier_id = ttd.supplier_id AND tdm.kind = 'invoice' AND tdm.target_id = ttd.id)";
        $moneyPaidUpTo = "SELECT COALESCE(SUM(tp.amount), 0) FROM invoice_payments tp
                           WHERE tp.invoice_id = {$a}.invoice_id AND tp.supplier_id = {$a}.supplier_id
                             AND " . self::moneyS3SourceSql('tp') . "
                             AND (tp.paid_on < {$a}.paid_on OR (tp.paid_on = {$a}.paid_on AND tp.id <= {$a}.id))";
        return "(({$a}.source = '" . PohodaPriorYearPayments::SOURCE . "' AND {$a}.note <=> '" . PohodaPriorYearPayments::NOTE . "')
                 OR (" . self::moneyS3PaymentSql($a) . " AND (
                     EXISTS (SELECT 1 FROM invoices tld WHERE tld.id = {$a}.tax_document_invoice_id AND tld.status NOT IN ('draft', 'cancelled'))
                     OR ({$moneyPaidUpTo}) <= ({$moneyTaxDocuments}) + 0.01
                     OR EXISTS (SELECT 1 FROM tax_submissions tts
                                 WHERE tts.supplier_id = {$a}.supplier_id AND tts.form_code = 'dphdp3' AND tts.status IN ('submitted', 'accepted')
                                   AND tts.period_year = YEAR({$a}.paid_on)
                                   AND (tts.period_month = MONTH({$a}.paid_on) OR (tts.period_month IS NULL AND tts.period_quarter = QUARTER({$a}.paid_on))))
                     OR EXISTS (SELECT 1 FROM accounting_supplier_settings tas WHERE tas.supplier_id = {$a}.supplier_id AND tas.locked_until >= {$a}.paid_on)
                     OR EXISTS (SELECT 1 FROM accounting_periods tap
                                 WHERE tap.supplier_id = {$a}.supplier_id AND tap.status IN ('closed', 'approved', 'reviewed')
                                   AND tap.starts_on <= {$a}.paid_on AND tap.ends_on >= {$a}.paid_on)
                 )))";
    }

    /** SQL podoba {@see isMoneyS3Payment()} s dokladem z mapy převodu Money S3. */
    public static function moneyS3PaymentSql(string $alias): string
    {
        self::assertAlias($alias);
        return '(' . self::moneyS3SourceSql($alias) . "
                 AND EXISTS (SELECT 1 FROM money_s3_import_map tom
                              WHERE tom.supplier_id = {$alias}.supplier_id AND tom.kind = 'invoice' AND tom.target_id = {$alias}.invoice_id))";
    }

    private static function moneyS3SourceSql(string $alias): string
    {
        $sources = "'" . implode("','", self::MONEY_S3_SOURCES) . "'";
        return "{$alias}.source IN ({$sources}) AND COALESCE({$alias}.note, '') LIKE '" . IssuedPaymentRecorder::NOTE_PREFIX . "%'";
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
