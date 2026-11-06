<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AbraImportRepository;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments;
use PDO;

/**
 * Vazby mezi převzatými doklady, ze kterých čte saldokonto a měsíční kontrola.
 *
 * Deník ABRA Flexi se přebírá beze změny, tady se jen doplní, co z něj MyÚčto samo
 * nepozná:
 *
 *  - doklad minulého roku (převzatý kvůli úhradě ve vybraném roce) dostane vazbu na
 *    otevírací zápis účtu, na kterém je zastoupen v počátečním stavu
 *    ({@see OpeningBalanceDocuments});
 *  - dobropis vazbu na opravovanou fakturu (`typVazbyDokl.hlavaDobropis`) a zápočet
 *    dobropisu, když ho ABRA vyrovnala bez peněz;
 *  - čerpání přijaté zálohy konečnou fakturou (`odpocetZALOHY`, `odpocetZDD`) po dvojicích
 *    záloha × faktura v `purchase_advance_deductions` a daňový doklad k platbě zálohy
 *    (ZDD) vazbou na zálohu;
 *  - kurzový rozdíl úhrady (`uhradaKR`) a vzájemný zápočet: zápis interního dokladu nebo
 *    zápočtu se naváže na vyrovnaný doklad jako ruční vyrovnání
 *    ({@see \MyInvoice\Support\Sql\LinkedManualSettlementSql}).
 *
 * Stav a uhrazenou částku dokladů vede převod podle ABRA Flexi, tady se nic nepřepočítává.
 * Opakovaný převod nic nezdvojí.
 */
final class AbraDocumentRelationLinker
{
    private const NOTE = 'převzato z ABRA Flexi';

    public function __construct(
        private readonly Connection $db,
        private readonly AbraImportRepository $imports,
    ) {}

    /**
     * Vazby dokladů ze snímku: evidence `vazba` doplněná o vazby vnořené v dokladech
     * (vazby zálohy a čerpání nese ABRA u zálohového dokladu, který nemusí být v rozsahu
     * načtené evidence `vazba`).
     *
     * @return list<array{id:string,type:string,a:array{evidence:string,key:string},b:array{evidence:string,key:string},amount:float}>
     */
    public static function links(array $snapshot): array
    {
        $rows = AbraSource::rows($snapshot, 'vazba');
        foreach (['faktura-vydana', 'faktura-prijata', 'banka', 'pokladni-pohyb'] as $evidence) {
            foreach (AbraSource::rows($snapshot, $evidence) as $document) {
                foreach (is_array($document['vazby'] ?? null) ? $document['vazby'] : [] as $link) {
                    if (is_array($link)) {
                        $rows[] = $link;
                    }
                }
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $id = AbraSource::sourceKey($row);
            $a = AbraSource::relation($row['a@ref'] ?? $row['a'] ?? null);
            $b = AbraSource::relation($row['b@ref'] ?? $row['b'] ?? null);
            if ($id === '' || isset($out[$id]) || $a === null || $b === null
                || AbraSource::bool($row['storno'] ?? null) === true) {
                continue;
            }
            $out[$id] = [
                'id' => $id,
                'type' => AbraSource::reference($row['typVazbyK'] ?? null),
                'a' => $a,
                'b' => $b,
                'amount' => (float) (AbraSource::number($row['castka'] ?? null) ?? 0.0),
            ];
        }
        return array_values($out);
    }

    /**
     * Doklady minulých let navázané na otevírací zápis účtu, na kterém je ABRA vede
     * (`primUcet`). Otevírací zápis převodu je po účtech, saldokonto proto potřebuje
     * vazbu právě na zápis s řádkem saldokontního účtu dokladu. Bez `primUcet` (záloha)
     * rozhoduje druh dokladu: vydaná faktura 311, přijatá 321, přijatá záloha 314.
     * Doklad s vlastním zaúčtováním se nenaváže.
     *
     * @param list<array{type:string,id:int,account:string,kind:string}> $documents
     * @param array<string,mixed> $report
     */
    public function linkOpeningDocuments(int $supplierId, ?int $userId, int $year, array $documents, array &$report): void
    {
        if ($documents === []) {
            return;
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT m.abra_key, e.id FROM abra_flexi_import_map m
            JOIN journal_entries e ON e.id = m.target_id AND e.supplier_id = m.supplier_id
            WHERE m.supplier_id = ? AND m.kind = 'stav-uctu' AND m.source_year = ?
              AND e.source_type = 'opening' AND e.reversed_by IS NULL
            ORDER BY m.abra_key");
        $stmt->execute([$supplierId, $year]);
        $byAccount = [];
        $bySynthetic = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $parts = explode('|', (string) $row['abra_key'], 2);
            if (count($parts) !== 2 || (int) $parts[0] !== $year) {
                continue;
            }
            $byAccount[$parts[1]] = (int) $row['id'];
            $bySynthetic[substr($parts[1], 0, 3)] ??= (int) $row['id'];
        }
        if ($byAccount === []) {
            return;
        }
        $own = $pdo->prepare('SELECT 1 FROM journal_entries WHERE supplier_id = ? AND source_type = ? AND source_id = ?
                AND reversed_by IS NULL
            UNION ALL SELECT 1 FROM journal_document_postings WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?
            LIMIT 1');
        $link = $pdo->prepare('INSERT IGNORE INTO journal_entry_document_links
            (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $note = OpeningBalanceDocuments::NOTE . ' (' . self::NOTE . ')';
        foreach ($documents as $document) {
            $fallback = $document['kind'] === 'advance' ? '314' : ($document['type'] === 'invoice' ? '311' : '321');
            $account = $document['kind'] === 'advance' ? '' : $document['account'];
            $entryId = $byAccount[$account] ?? $bySynthetic[$account !== '' ? substr($account, 0, 3) : $fallback] ?? null;
            if ($entryId === null) {
                $report['warnings'][] = 'opening_document_account_missing';
                continue;
            }
            $own->execute([$supplierId, $document['type'], $document['id'], $supplierId, $document['type'], $document['id']]);
            if ($own->fetchColumn() !== false) {
                continue;
            }
            $link->execute([$supplierId, $entryId, $document['type'], $document['id'], $note, $userId]);
            if ($link->rowCount() > 0) {
                $report['counts']['opening_documents_linked'] = ($report['counts']['opening_documents_linked'] ?? 0) + 1;
            }
        }
    }

    /**
     * Dobropis dostane vazbu na opravovanou fakturu. Zápočet dobropisu se zapíše jen tam,
     * kde ho ABRA vyrovnala bez peněz: dobropis nemá převzatou úhradu, podle zdroje je
     * vyrovnaný a faktura je ve stejné měně. U vydané strany vznikne i evidovaná platba
     * faktury se zdrojem `credit_note`, nejvýš do její uhrazené částky podle zdroje.
     *
     * @param list<array<string,mixed>> $links
     * @param array<string,array{type:string,id:int}> $documents
     * @param array<string,array{amount:float,date:?string}> $settled zdrojově vyrovnaná částka dokladů
     * @param array<string,mixed> $report
     */
    public function linkCreditNotes(int $supplierId, ?int $userId, array $links, array $documents, array $settled, array &$report): void
    {
        $pdo = $this->db->pdo();
        foreach ($links as $link) {
            if ($link['type'] !== 'typVazbyDokl.hlavaDobropis' || $link['a']['evidence'] !== $link['b']['evidence']
                || !in_array($link['a']['evidence'], ['faktura-vydana', 'faktura-prijata'], true)) {
                continue;
            }
            $a = $this->target($supplierId, $link['a'], $documents);
            $b = $this->target($supplierId, $link['b'], $documents);
            if ($a === null || $b === null || $a['type'] !== $b['type'] || $a['id'] === $b['id']) {
                continue;
            }
            $issued = $a['type'] === 'invoice';
            $table = $issued ? 'invoices' : 'purchase_invoices';
            $kindColumn = $issued ? 'invoice_type' : 'document_kind';
            $parentColumn = $issued ? 'parent_invoice_id' : 'parent_purchase_invoice_id';
            $stmt = $pdo->prepare("SELECT id, {$kindColumn} AS kind, {$parentColumn} AS parent, status, currency_id,
                    total_with_vat, issue_date, varsymbol FROM {$table} WHERE supplier_id = ? AND id IN (?, ?)");
            $stmt->execute([$supplierId, $a['id'], $b['id']]);
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[(string) $row['kind']][] = $row;
            }
            if (count($rows['credit_note'] ?? []) !== 1 || count($rows['invoice'] ?? []) !== 1) {
                continue;
            }
            $note = $rows['credit_note'][0];
            $invoice = $rows['invoice'][0];
            if ($note['parent'] === null) {
                $pdo->prepare("UPDATE {$table} SET {$parentColumn} = ? WHERE supplier_id = ? AND id = ? AND {$parentColumn} IS NULL")
                    ->execute([(int) $invoice['id'], $supplierId, (int) $note['id']]);
                $report['counts']['credit_notes_linked'] = ($report['counts']['credit_notes_linked'] ?? 0) + 1;
            } elseif ((int) $note['parent'] !== (int) $invoice['id']) {
                continue;
            }
            $amount = round((float) ($settled[$a['type'] . '|' . $note['id']]['amount'] ?? 0.0), 2);
            if ($amount < 0.01 || in_array((string) $note['status'], ['draft', 'cancelled'], true)
                || in_array((string) $invoice['status'], ['draft', 'cancelled'], true)
                || (int) $note['currency_id'] !== (int) $invoice['currency_id']
                || $this->hasMoneySettlement($supplierId, $a['type'], (int) $note['id'])) {
                continue;
            }
            if ($this->offsetCreditNote($supplierId, $userId, $a['type'], (int) $invoice['id'], (int) $note['id'],
                min($amount, abs((float) $note['total_with_vat'])), (string) $note['issue_date'], (string) $note['varsymbol'])) {
                $report['counts']['credit_note_offsets_created'] = ($report['counts']['credit_note_offsets_created'] ?? 0) + 1;
            }
        }
    }

    /**
     * Čerpání přijatých záloh konečnými fakturami.
     *
     * `odpocetZALOHY` vede ABRA od položky odpočtu konečné faktury k záloze, `odpocetZDD`
     * k daňovému dokladu k platbě zálohy (ZDD). ZDD patří k záloze, se kterou sdílí platbu
     * (`vazbaZDD` a `uhradaZALOHY` na týž bankovní pohyb); dostane na ni vazbu jako přijatý
     * DDKP. Čerpaná částka je v Kč z položky odpočtu: bez ZDD celá, se ZDD jen základ (DPH
     * zálohy čerpá ZDD). Kurzový rozdíl zálohy (`zalHlavKr` k zálohe, `odpocetZddKr`
     * ke konečné faktuře na týž interní doklad) ABRA zaúčtuje na zálohový účet, proto se
     * k čerpání přičte se znaménkem zdroje (ztráta zálohu snižuje, zisk zvyšuje).
     *
     * @param list<array<string,mixed>> $links
     * @param array<string,array{type:string,id:int}> $documents
     * @param array<string,array{final:string,base:?float,total:?float}> $items položka přijaté faktury => konečná faktura a částky v Kč
     * @param array<string,mixed> $report
     */
    public function linkAdvanceDeductions(int $supplierId, ?int $userId, array $links, array $documents, array $items, array &$report): void
    {
        $byType = [];
        foreach ($links as $link) {
            $byType[$link['type']][] = $link;
        }
        $pdo = $this->db->pdo();
        $kind = $pdo->prepare('SELECT id, document_kind, parent_purchase_invoice_id FROM purchase_invoices WHERE supplier_id = ? AND id = ?');
        $kindOf = function (int $id) use ($kind, $supplierId): ?array {
            $kind->execute([$supplierId, $id]);
            $row = $kind->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        };

        // ZDD ↔ záloha přes společný bankovní pohyb.
        $advanceByMovement = [];
        foreach ($byType['typVazbyDokl.uhradaZALOHY'] ?? [] as $link) {
            [$doc, $movement] = self::documentAndMovement($link);
            if ($doc !== null) {
                $advanceByMovement[$movement['evidence'] . '|' . $movement['key']][$doc['key']] = true;
            }
        }
        $taxDocumentAdvance = [];
        foreach ($byType['typVazbyDokl.vazbaZDD'] ?? [] as $link) {
            [$doc, $movement] = self::documentAndMovement($link);
            if ($doc === null) {
                continue;
            }
            $candidates = array_keys($advanceByMovement[$movement['evidence'] . '|' . $movement['key']] ?? []);
            $zdd = $this->target($supplierId, $doc, $documents);
            $advance = count($candidates) === 1
                ? $this->target($supplierId, ['evidence' => 'faktura-prijata', 'key' => (string) $candidates[0]], $documents) : null;
            if ($zdd === null || $advance === null || $zdd['type'] !== 'purchase_invoice' || $advance['type'] !== 'purchase_invoice') {
                continue;
            }
            $zddRow = $kindOf($zdd['id']);
            $advanceRow = $kindOf($advance['id']);
            if ($zddRow === null || $advanceRow === null || $advanceRow['document_kind'] !== 'advance'
                || $zddRow['document_kind'] !== 'tax_document') {
                continue;
            }
            if ($zddRow['parent_purchase_invoice_id'] === null) {
                $pdo->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE supplier_id = ? AND id = ?
                    AND parent_purchase_invoice_id IS NULL')->execute([$advance['id'], $supplierId, $zdd['id']]);
                $report['counts']['advance_tax_documents_linked'] = ($report['counts']['advance_tax_documents_linked'] ?? 0) + 1;
            }
            $taxDocumentAdvance[$zdd['id']] = (int) ($zddRow['parent_purchase_invoice_id'] ?? $advance['id']);
        }

        // Kurzové rozdíly zálohy podle interního dokladu.
        $advanceFx = [];
        foreach ($byType['typVazbyDokl.zalHlavKr'] ?? [] as $link) {
            $internal = self::endOf($link, ['interni-doklad']);
            $advance = self::endOf($link, ['faktura-prijata']);
            if ($internal !== null && $advance !== null) {
                $advanceFx[$internal['key']][] = ['advance' => $advance['key'], 'amount' => $link['amount']];
            }
        }
        $finalFx = [];
        foreach ($byType['typVazbyDokl.odpocetZddKr'] ?? [] as $link) {
            $internal = self::endOf($link, ['interni-doklad']);
            $final = self::endOf($link, ['faktura-prijata']);
            if ($internal !== null && $final !== null) {
                $finalFx[$final['key']][$internal['key']] = true;
            }
        }

        $pairs = [];
        foreach (['typVazbyDokl.odpocetZALOHY', 'typVazbyDokl.odpocetZDD'] as $type) {
            foreach ($byType[$type] ?? [] as $link) {
                $item = self::endOf($link, ['faktura-prijata-polozka']);
                $source = self::endOf($link, ['faktura-prijata']);
                if ($item === null || $source === null || !isset($items[$item['key']])) {
                    continue;
                }
                $finalKey = $items[$item['key']]['final'];
                $final = $this->target($supplierId, ['evidence' => 'faktura-prijata', 'key' => $finalKey], $documents);
                $sourceTarget = $this->target($supplierId, $source, $documents);
                if ($final === null || $sourceTarget === null || $final['type'] !== 'purchase_invoice'
                    || $sourceTarget['type'] !== 'purchase_invoice') {
                    continue;
                }
                $withTaxDocument = $type === 'typVazbyDokl.odpocetZDD';
                $advanceId = $withTaxDocument ? ($taxDocumentAdvance[$sourceTarget['id']] ?? null) : $sourceTarget['id'];
                $advanceRow = $advanceId !== null ? $kindOf($advanceId) : null;
                if ($advanceRow === null || $advanceRow['document_kind'] !== 'advance') {
                    $report['warnings'][] = 'advance_deduction_target_missing';
                    continue;
                }
                $amount = $withTaxDocument ? $items[$item['key']]['base'] : $items[$item['key']]['total'];
                if ($amount === null) {
                    continue;
                }
                $pairKey = $final['id'] . '|' . $advanceId;
                $pairs[$pairKey] ??= ['final' => $final['id'], 'final_key' => $finalKey, 'advance' => $advanceId,
                    'advance_key' => $source['key'], 'amount' => 0.0];
                $pairs[$pairKey]['amount'] += abs($amount);
            }
        }
        foreach ($pairs as &$pair) {
            foreach (array_keys($finalFx[$pair['final_key']] ?? []) as $internalKey) {
                foreach ($advanceFx[$internalKey] ?? [] as $fx) {
                    if ($fx['advance'] === $pair['advance_key']) {
                        $pair['amount'] += $fx['amount'];
                    }
                }
            }
        }
        unset($pair);
        $insert = $pdo->prepare('INSERT IGNORE INTO purchase_advance_deductions
            (supplier_id, final_invoice_id, advance_invoice_id, amount, created_by) VALUES (?, ?, ?, ?, ?)');
        foreach ($pairs as $pair) {
            $amount = round($pair['amount'], 2);
            if ($amount < 0.01) {
                continue;
            }
            $insert->execute([$supplierId, $pair['final'], $pair['advance'], number_format($amount, 2, '.', ''), $userId]);
            if ($insert->rowCount() > 0) {
                $report['counts']['advance_deductions_created'] = ($report['counts']['advance_deductions_created'] ?? 0) + 1;
            }
        }
    }

    /**
     * Kurzový rozdíl úhrady (`uhradaKR` k internímu dokladu) a vzájemný zápočet (`uhrada`
     * k evidenci `vzajemny-zapocet`): zápisy interního dokladu či zápočtu se navážou na
     * vyrovnaný doklad. Saldokonto je počítá jako ruční vyrovnání jen tehdy, když zápis
     * vyrovnává jediný doklad téhož druhu; víc dokladů zůstane k ruční kontrole.
     *
     * @param list<array<string,mixed>> $links
     * @param array<string,array{type:string,id:int}> $documents
     * @param array<string,list<int>> $entries interní doklad / zápočet (`evidence|klíč`) => zápisy deníku
     * @param array<string,mixed> $report
     */
    public function linkInternalSettlements(int $supplierId, ?int $userId, array $links, array $documents, array $entries, array &$report): void
    {
        $settled = [];
        foreach ($links as $link) {
            if (!in_array($link['type'], ['typVazbyDokl.uhradaKR', 'typVazbyDokl.uhrada'], true)) {
                continue;
            }
            $internal = self::endOf($link, ['interni-doklad', 'vzajemny-zapocet']);
            $document = self::endOf($link, ['faktura-vydana', 'faktura-prijata']);
            if ($internal === null || $document === null) {
                continue;
            }
            $target = $this->target($supplierId, $document, $documents);
            if ($target === null || !in_array($target['type'], ['invoice', 'purchase_invoice'], true)) {
                continue;
            }
            $settled[$internal['evidence'] . '|' . $internal['key']][$target['type']][$target['id']] = true;
        }
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare('INSERT IGNORE INTO journal_entry_document_links
            (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($settled as $internalKey => $byType) {
            $entryIds = $entries[$internalKey] ?? [];
            if ($entryIds === []) {
                continue;
            }
            foreach ($byType as $type => $ids) {
                if (count($ids) !== 1) {
                    $report['counts']['internal_settlements_ambiguous'] = ($report['counts']['internal_settlements_ambiguous'] ?? 0) + 1;
                    $report['warnings'][] = 'internal_settlement_requires_review';
                    continue;
                }
                $created = false;
                foreach ($entryIds as $entryId) {
                    $insert->execute([$supplierId, $entryId, $type, (int) array_key_first($ids),
                        'Vyrovnání interním dokladem (' . self::NOTE . ')', $userId]);
                    $created = $created || $insert->rowCount() > 0;
                }
                if ($created) {
                    $report['counts']['internal_settlements_linked'] = ($report['counts']['internal_settlements_linked'] ?? 0) + 1;
                }
            }
        }
    }

    private function offsetCreditNote(int $supplierId, ?int $userId, string $type, int $invoiceId, int $noteId,
        float $amount, string $date, string $number): bool
    {
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT 1 FROM credit_note_offsets WHERE doc_type = ? AND credit_note_id = ?');
        $exists->execute([$type, $noteId]);
        if ($exists->fetchColumn() !== false || $amount < 0.01) {
            return false;
        }
        $paymentId = null;
        if ($type === 'invoice') {
            $row = $pdo->prepare('SELECT cur.code AS currency, i.paid_total,
                    (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS recorded
                FROM invoices i JOIN currencies cur ON cur.id = i.currency_id WHERE i.id = ? AND i.supplier_id = ?');
            $row->execute([$invoiceId, $supplierId]);
            $invoice = $row->fetch(PDO::FETCH_ASSOC);
            $pay = $invoice === false ? 0.0 : round(min($amount, (float) $invoice['paid_total'] - (float) $invoice['recorded']), 2);
            if ($invoice !== false && $pay >= 0.01) {
                $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, 'credit_note', ?)")->execute([
                    $supplierId, $invoiceId, $date, number_format($pay, 2, '.', ''), (string) $invoice['currency'],
                    'Zápočet dobropisu ' . $number . ' (' . self::NOTE . ')', $userId,
                ]);
                $paymentId = (int) $pdo->lastInsertId();
            }
        }
        $pdo->prepare('INSERT INTO credit_note_offsets
            (supplier_id, doc_type, invoice_id, credit_note_id, amount, offset_on, invoice_payment_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $supplierId, $type, $invoiceId, $noteId, number_format($amount, 2, '.', ''), $date, $paymentId, $userId,
        ]);
        return true;
    }

    private function hasMoneySettlement(int $supplierId, string $type, int $id): bool
    {
        $column = $type === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
        $stmt = $this->db->pdo()->prepare("SELECT 1 FROM payment_matches WHERE supplier_id = ? AND {$column} = ?
            UNION ALL SELECT 1 FROM cash_documents WHERE supplier_id = ? AND {$column} = ? LIMIT 1");
        $stmt->execute([$supplierId, $id, $supplierId, $id]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array{evidence:string,key:string} $relation
     * @param array<string,array{type:string,id:int}> $documents
     * @return array{type:string,id:int}|null
     */
    private function target(int $supplierId, array $relation, array $documents): ?array
    {
        $lookup = $relation['evidence'] . '|' . $relation['key'];
        if (isset($documents[$lookup])) {
            return $documents[$lookup];
        }
        $mapped = $this->imports->lookup($supplierId, $relation['evidence'], $relation['key']);
        return $mapped === null ? null : ['type' => (string) $mapped['target_type'], 'id' => (int) $mapped['target_id']];
    }

    /**
     * @param array<string,mixed> $link
     * @param list<string> $evidences
     * @return array{evidence:string,key:string}|null
     */
    private static function endOf(array $link, array $evidences): ?array
    {
        foreach (['a', 'b'] as $end) {
            if (in_array($link[$end]['evidence'], $evidences, true)) {
                return $link[$end];
            }
        }
        return null;
    }

    /** @return array{0:?array{evidence:string,key:string},1:?array{evidence:string,key:string}} */
    private static function documentAndMovement(array $link): array
    {
        $document = self::endOf($link, ['faktura-prijata']);
        $movement = self::endOf($link, ['banka', 'pokladni-pohyb']);
        return $document !== null && $movement !== null ? [$document, $movement] : [null, null];
    }
}
