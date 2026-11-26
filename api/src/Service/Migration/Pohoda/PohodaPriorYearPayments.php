<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Service\Invoice\IssuedCzkAmounts;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Úhrady vydaných dokladů z doby před začátkem agendy POHODY.
 *
 * POHODA přenese do agendy doklad minulého období i s úhradami z minulých let
 * (`liquidations` s datem před začátkem agendy). Pohyb k nim je v agendě minulého roku,
 * ne v exportu, a v hlavní knize je úhrada už v počátečním stavu 311. Doklad je proto
 * dostane jako jednu evidovanou platbu k poslednímu dni před začátkem agendy se zdrojem
 * `settlement`: bez bankovního pohybu, pokladního dokladu i zápisu v deníku.
 *
 * Bez ní nese uhrazenou část jen `invoices.paid_total`. Každá změna plateb dokladu
 * ({@see \MyInvoice\Service\Invoice\InvoicePaymentService::recomputeLocked()}) pak
 * paid_total přepočte ze součtu plateb a úhrady minulých let zmizí: saldokonto ukáže
 * doklad otevřený, uhrazený doklad se vrátí mezi neuhrazené a odejde na něj upomínka.
 *
 * Zdroj `settlement` párování banky jako protějšek pohybu nebere
 * ({@see \MyInvoice\Service\Invoice\InvoicePaymentService::BANK_RECONCILABLE_SOURCES}),
 * peněžní deník ho nezapočte a saldokonto ho bere podle data platby. Platba se pozná podle
 * poznámky {@see NOTE}; k dokladu je nejvýš jedna a nastavuje se absolutně, takže opakovaný
 * převod ani oprava dříve převedených firem ({@see PohodaPriorYearPaymentRepair}) nic nezdvojí.
 * Sdílí ji převod podvojného účetnictví ({@see DocumentLinker}) i daňové evidence
 * ({@see PohodaTaxEvidenceImporter}).
 */
final class PohodaPriorYearPayments
{
    public const NOTE = 'Převzato z POHODY, úhrada v minulém roce';
    public const SOURCE = 'settlement';

    /** Druhy vydaných dokladů s evidovanými platbami (jako {@see \MyInvoice\Service\Invoice\InvoicePaymentService}). */
    public const PAYABLE_TYPES = ['invoice', 'proforma'];

    public function __construct(private readonly Connection $db) {}

    /** Datum platby: poslední den před začátkem agendy. */
    public static function paidOn(string $periodStart): string
    {
        return (new \DateTimeImmutable(substr($periodStart, 0, 10)))->modify('-1 day')->format('Y-m-d');
    }

    /**
     * Zapíše úhrady minulých let podle úhrad dokladu v POHODĚ. Platba nese úhrady před
     * začátkem agendy, které nepokrývá jiná evidovaná platba s dřívějším datem (doklad
     * převedený i z agendy minulého roku má ty úhrady už navázané na pohyb).
     *
     * Podvojné účetnictví (`$withinPaidTotal`) vede uhrazenou částku podle POHODY
     * (`paid_total`), platby ji jen doplňují: součet plateb ji nepřesáhne. Doklad v cizí
     * měně dostane úhradu přepočtenou kurzem dokladu (úhrady POHODY jsou v Kč) jako
     * evidovaná platba úhrady z banky. Daňová evidence počítá uhrazenou částku z plateb;
     * strop je částka k úhradě a jen doklady v korunách. Platbu dostane jen faktura a zálohový
     * list: dobropis evidované platby nemá (vrácení peněz nese stav dokladu), kladná platba
     * by ho označila za vyplacený a jeho dobropisovanou částku započetla podruhé.
     *
     * @param array<int,float> $amounts id vydaného dokladu => součet úhrad před začátkem agendy v Kč
     * @return array{inserted:int,updated:int,deleted:int}
     */
    public function record(int $supplierId, ?int $userId, string $periodStart, array $amounts, bool $withinPaidTotal): array
    {
        $out = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];
        if ($amounts === []) {
            return $out;
        }
        $pdo = $this->db->pdo();
        $paidOn = self::paidOn($periodStart);
        $types = " AND i.invoice_type IN ('" . implode("','", self::PAYABLE_TYPES) . "')" . ($withinPaidTotal ? '' : " AND cur.code = 'CZK'");
        $insert = $pdo->prepare('INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $update = $pdo->prepare('UPDATE invoice_payments SET amount = ? WHERE id = ? AND supplier_id = ?');
        $delete = $pdo->prepare('DELETE FROM invoice_payments WHERE id = ? AND supplier_id = ?');
        foreach (array_chunk(array_keys($amounts), 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $rows = $pdo->prepare(
                "SELECT i.id, cur.code AS currency, i.exchange_rate, i.amount_to_pay, i.import_amount_to_pay_czk, i.paid_total,
                        ABS(i.total_with_vat) - i.advance_paid_amount AS to_pay,
                        own.id AS payment_id, own.amount AS payment_amount,
                        COALESCE(other.total, 0) AS other_total, COALESCE(other.earlier, 0) AS other_earlier
                   FROM invoices i
                   JOIN currencies cur ON cur.id = i.currency_id
                   LEFT JOIN (SELECT ip.invoice_id, MIN(ip.id) AS id, SUM(ip.amount) AS amount FROM invoice_payments ip
                               WHERE ip.invoice_id IN ({$in}) AND ip.source = ? AND ip.note = ? GROUP BY ip.invoice_id) own ON own.invoice_id = i.id
                   LEFT JOIN (SELECT ip.invoice_id, SUM(ip.amount) AS total, SUM(CASE WHEN ip.paid_on < ? THEN ip.amount ELSE 0 END) AS earlier
                                FROM invoice_payments ip
                               WHERE ip.invoice_id IN ({$in}) AND NOT (ip.source = ? AND ip.note <=> ?) GROUP BY ip.invoice_id) other ON other.invoice_id = i.id
                  WHERE i.supplier_id = ? AND i.id IN ({$in}) AND i.status NOT IN ('draft', 'cancelled'){$types}"
            );
            $rows->execute([self::SOURCE, self::NOTE, $periodStart, self::SOURCE, self::NOTE, $supplierId]);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $amount = (float) $amounts[(int) $r['id']];
                $rate = IssuedCzkAmounts::settlementRate($r);
                if ((string) $r['currency'] !== 'CZK') {
                    if ($rate <= 0) {
                        continue;
                    }
                    $amount /= $rate;
                }
                $cap = $withinPaidTotal ? (float) $r['paid_total'] : (float) $r['to_pay'];
                $amount = round(min($amount - (float) $r['other_earlier'], $cap - (float) $r['other_total']), 2);
                if ($r['payment_id'] !== null) {
                    if ($amount < 0.01) {
                        $delete->execute([(int) $r['payment_id'], $supplierId]);
                        $out['deleted']++;
                    } elseif (abs($amount - (float) $r['payment_amount']) >= 0.005) {
                        $update->execute([number_format($amount, 2, '.', ''), (int) $r['payment_id'], $supplierId]);
                        $out['updated']++;
                    }
                    continue;
                }
                if ($amount >= 0.01) {
                    $insert->execute([$supplierId, (int) $r['id'], $paidOn, number_format($amount, 2, '.', ''), (string) $r['currency'], self::NOTE, self::SOURCE, $userId]);
                    $out['inserted']++;
                }
            }
        }
        return $out;
    }

    /**
     * Nastaví úhradu minulých let dokladu absolutně na `$amount` v měně dokladu (oprava podle
     * knihy pohledávek, {@see PohodaPriorYearPaymentRepair::runBalances()}): stejně jako
     * {@see record()} platbu založí, změní, nebo pod 0,01 smaže. Další platby s poznámkou
     * {@see NOTE} (k dokladu má být nejvýš jedna) smaže. Uhrazenou částku ani stav dokladu
     * nepřepočítává. Volat UVNITŘ transakce volajícího.
     *
     * @return 'inserted'|'updated'|'deleted'|'unchanged'
     */
    public function set(int $supplierId, int $invoiceId, string $periodStart, float $amount, string $currency, ?int $userId): string
    {
        $pdo = $this->db->pdo();
        $amount = round($amount, 2);
        $own = $pdo->prepare('SELECT id, amount FROM invoice_payments WHERE invoice_id = ? AND supplier_id = ? AND source = ? AND note = ? ORDER BY id FOR UPDATE');
        $own->execute([$invoiceId, $supplierId, self::SOURCE, self::NOTE]);
        $rows = $own->fetchAll(PDO::FETCH_ASSOC);
        $delete = $pdo->prepare('DELETE FROM invoice_payments WHERE id = ? AND supplier_id = ?');
        $first = array_shift($rows);
        foreach ($rows as $extra) {
            $delete->execute([(int) $extra['id'], $supplierId]);
        }
        if ($first === null) {
            if ($amount < 0.01) {
                return 'unchanged';
            }
            $pdo->prepare('INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$supplierId, $invoiceId, self::paidOn($periodStart), number_format($amount, 2, '.', ''), $currency, self::NOTE, self::SOURCE, $userId]);
            return 'inserted';
        }
        if ($amount < 0.01) {
            $delete->execute([(int) $first['id'], $supplierId]);
            return 'deleted';
        }
        if ($rows === [] && abs($amount - (float) $first['amount']) < 0.005) {
            return 'unchanged';
        }
        $pdo->prepare('UPDATE invoice_payments SET amount = ? WHERE id = ? AND supplier_id = ?')
            ->execute([number_format($amount, 2, '.', ''), (int) $first['id'], $supplierId]);
        return 'updated';
    }

    /**
     * Doplnění platby u dokladu převedeného dřív, než ji převod zakládal: rozdíl uhrazené
     * částky (`paid_total`) proti součtu evidovaných plateb. Existující platbu s poznámkou
     * {@see NOTE} navýší, jinak založí novou. Volat UVNITŘ transakce volajícího.
     */
    public function add(int $supplierId, int $invoiceId, string $periodStart, float $amount, string $currency, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        $own = $pdo->prepare('SELECT id FROM invoice_payments WHERE invoice_id = ? AND supplier_id = ? AND source = ? AND note = ? ORDER BY id LIMIT 1 FOR UPDATE');
        $own->execute([$invoiceId, $supplierId, self::SOURCE, self::NOTE]);
        $id = $own->fetchColumn();
        if ($id !== false) {
            $pdo->prepare('UPDATE invoice_payments SET amount = amount + ? WHERE id = ? AND supplier_id = ?')
                ->execute([number_format($amount, 2, '.', ''), (int) $id, $supplierId]);
            return;
        }
        $pdo->prepare('INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$supplierId, $invoiceId, self::paidOn($periodStart), number_format($amount, 2, '.', ''), $currency, self::NOTE, self::SOURCE, $userId]);
    }
}
