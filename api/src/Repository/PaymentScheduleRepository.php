<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\InvoiceCalendar;
use PDO;

/**
 * § 31 a § 31a ZDPH — rozpis plateb splátkového a platebního kalendáře.
 *
 * Kalendář je SÁM O SOBĚ daňovým dokladem, pokud obsahuje náležitosti daňového dokladu
 * a rozpis plateb na předem stanovené období. Právě proto se nevystavuje doklad ke každé
 * splátce — to je celý smysl institutu a důvod, proč nestačí evidovat opakovanou
 * fakturaci: ta vyrobí N dokladů, kalendář je jeden.
 *
 * Rozpis je v samostatné tabulce, ne v položkách faktury. Položky nesou PLNĚNÍ (co se
 * dodává), tohle je harmonogram ÚHRAD (kdy a kolik). Sloučit by znamenalo, že se rozpis
 * plateb objeví ve výkazech DPH jako plnění a zdvojnásobí základ daně.
 */
final class PaymentScheduleRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Rozpis plateb dokladu v pořadí splatnosti.
     *
     * @return list<array{id:int, due_on:string, tax_date:?string, instalment_invoice_id:?int, base_amount:float, vat_amount:float, total_amount:float, note:?string}>
     */
    public function forInvoice(int $supplierId, int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, due_on, tax_date, instalment_invoice_id, base_amount, vat_amount, total_amount, note
               FROM invoice_payment_schedule
              WHERE supplier_id = ? AND invoice_id = ?
           ORDER BY order_index, due_on, id'
        );
        $stmt->execute([$supplierId, $invoiceId]);

        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'due_on'       => (string) $r['due_on'],
            'tax_date'     => $r['tax_date'] === null ? null : (string) $r['tax_date'],
            'instalment_invoice_id' => $r['instalment_invoice_id'] === null ? null : (int) $r['instalment_invoice_id'],
            'base_amount'  => round((float) $r['base_amount'], 2),
            'vat_amount'   => round((float) $r['vat_amount'], 2),
            'total_amount' => round((float) $r['total_amount'], 2),
            'note'         => $r['note'] === null ? null : (string) $r['note'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Nahradí celý rozpis (replace-all). Částečné úpravy by u harmonogramu plateb
     * znamenaly, že se rozpis rozejde se součtem dokladu, aniž by to bylo vidět.
     *
     * @param list<array{due_on:string, tax_date?:?string, base_amount?:float, vat_amount?:float, total_amount:float, note?:?string}> $rows
     */
    public function replaceForInvoice(int $supplierId, int $invoiceId, array $rows): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM invoice_payment_schedule WHERE supplier_id = ? AND invoice_id = ?')
            ->execute([$supplierId, $invoiceId]);

        if ($rows === []) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_payment_schedule
                (supplier_id, invoice_id, due_on, tax_date, base_amount, vat_amount, total_amount, note, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (array_values($rows) as $i => $row) {
            $stmt->execute([
                $supplierId,
                $invoiceId,
                $row['due_on'],
                $row['tax_date'] ?? null,
                round((float) ($row['base_amount'] ?? 0), 2),
                round((float) ($row['vat_amount'] ?? 0), 2),
                round((float) $row['total_amount'], 2),
                $row['note'] ?? null,
                $i,
            ]);
        }
    }

    /**
     * Uloží rozpis z payloadu dokladu (klíč `payment_schedule`).
     *
     * Chybí-li klíč, rozpis se NEMĚNÍ. Doklad ukládají i cesty, které o kalendáři nevědí
     * (import, opakovaná fakturace, hromadné reissue) — replace-all na chybějícím klíči by
     * jim rozpis tiše smazal a doklad by přestal být daňovým dokladem podle § 31.
     * Prázdné pole rozpis smaže záměrně (uživatel řádky odebral).
     *
     * @param array<string,mixed> $body
     */
    public function saveFromPayload(int $supplierId, int $invoiceId, array $body): void
    {
        // Kalendář DUZP v hlavičce nenese: u nájmu ho má každá splátka, u záloh žádné není.
        $this->db->pdo()->prepare(
            "UPDATE invoices SET tax_date = NULL
              WHERE id = ? AND supplier_id = ? AND invoice_type IN (" . InvoiceCalendar::SQL_TYPES . ") AND status = 'draft'"
        )->execute([$invoiceId, $supplierId]);
        if (array_key_exists('calendar_contract_ref', $body)) {
            $reference = trim((string) $body['calendar_contract_ref']);
            $this->db->pdo()->prepare('UPDATE invoices SET calendar_contract_ref = ? WHERE id = ? AND supplier_id = ?')
                ->execute([$reference !== '' ? mb_substr($reference, 0, 190) : null, $invoiceId, $supplierId]);
        }

        if (!array_key_exists('payment_schedule', $body) || !is_array($body['payment_schedule'])) {
            return;
        }

        $rows = [];
        foreach ($body['payment_schedule'] as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $dueOn = trim((string) ($raw['due_on'] ?? ''));
            // Řádek bez data splatnosti není splátka — rozpis je definován právě tím,
            // KDY se platí; bez data by nešlo určit ani období, do kterého patří.
            if ($dueOn === '') {
                continue;
            }
            $note = $raw['note'] ?? null;
            $note = $note === null || trim((string) $note) === '' ? null : mb_substr(trim((string) $note), 0, 255);
            // Neplatná data odmítá už InvoiceValidation, tady jde o pojistku před chybou DB.
            if (!\MyInvoice\Http\IsoDate::isValid($dueOn)) {
                continue;
            }
            $taxDate = trim((string) ($raw['tax_date'] ?? ''));
            $rows[] = [
                'due_on'       => $dueOn,
                'tax_date'     => \MyInvoice\Http\IsoDate::isValid($taxDate) ? $taxDate : null,
                'base_amount'  => (float) ($raw['base_amount'] ?? 0),
                'vat_amount'   => (float) ($raw['vat_amount'] ?? 0),
                'total_amount' => (float) ($raw['total_amount'] ?? 0),
                'note'         => $note,
            ];
        }

        $this->replaceForInvoice($supplierId, $invoiceId, $rows);
    }

    /** Součet rozpisu — musí sedět na celkovou částku dokladu. */
    public function totalForInvoice(int $supplierId, int $invoiceId): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(total_amount), 0) FROM invoice_payment_schedule
              WHERE supplier_id = ? AND invoice_id = ?'
        );
        $stmt->execute([$supplierId, $invoiceId]);

        return round((float) $stmt->fetchColumn(), 2);
    }
}
