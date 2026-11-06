<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Accounting\TakenOverRecord;
use MyInvoice\Service\Bank\BankTransactionPostingScope;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\JournalEntryLinker;
use PDO;

/**
 * Provázání převedených dokladů s převedeným deníkem a úhrad s doklady.
 *
 * Zaúčtování se nepřepočítává: zápis deníku PREMIER dostane `source_id` = id dokladu
 * a vazbu v `journal_entry_document_links`. Faktura se pozná vazbou řádku do sborníku
 * (`SB_KOD` + `SBORNIK`), pokladní doklad a doklad s DPH z deníku klíčem dokladu, bankovní
 * pohyb řádkem deníku na účtu banky.
 *
 * Úhrady vede PREMIER vazbou řádku deníku na fakturu (`VAZBY`), párování je proto přesné:
 * bankovní pohyb z navázaného řádku = úhrada faktury, pokladní doklad s navázaným řádkem
 * hradí fakturu. Zápis úhrady bez pohybu peněz (zápočet, haléřový a kurzový rozdíl,
 * odpočet zálohy) dostane vazbu na fakturu s poznámkou {@see PAYMENT_NOTE}.
 */
final class DocumentLinker
{
    public const STEP_LINK = 'link';
    public const STEP_PAYMENTS = 'payments';

    /** Vazba zápisu úhrady na fakturu - není to zaúčtování faktury (rekonciliace a sirotci ji vynechávají). */
    public const PAYMENT_NOTE = 'Úhrada převzatá z PREMIER';

    private readonly JournalEntryLinker $entries;

    public function __construct(
        private readonly Connection $db,
        private readonly PremierImportRepository $map,
    ) {
        // Deník PREMIER převod zapisuje jako ruční zápisy (`manual`); nová vazba jen podle
        // skutečně přeznačeného zápisu ({@see JournalEntryLinker::__construct()}).
        $this->entries = new JournalEntryLinker($db, 'PREMIER', true);
    }

    public function link(PremierContext $ctx, PremierDocuments $documents): void
    {
        $p = $ctx->protocol;
        $issued = $this->docIndex($ctx->supplierId, PremierImportRepository::KIND_INVOICE);
        $purchases = $this->docIndex($ctx->supplierId, PremierImportRepository::KIND_PURCHASE_INVOICE);
        $series = $documents->invoiceSeries();
        $seriesKeys = array_flip(array_keys($series));
        // Sborník s kódem agendy (`VF`) míří na fakturu přes INTER, ne přes řadu ({@see PremierDocuments}).
        $byInter = [PremierDocuments::ISSUED => self::byInvoiceInter($issued, $seriesKeys), PremierDocuments::PURCHASE => self::byInvoiceInter($purchases, $seriesKeys)];
        $linkedDocs = [];
        foreach ($ctx->journal->documents($ctx->year) as $docKey => $rows) {
            $entryId = $ctx->entries[$docKey] ?? null;
            if ($entryId === null) {
                continue;
            }
            $first = $rows[0];
            if ($first['sb_kod'] !== '' && isset($series[$first['sb_kod']])) {
                $isIssued = $series[$first['sb_kod']] === PremierDocuments::ISSUED;
                $docId = ($isIssued ? $issued : $purchases)[$first['sb_kod'] . '|' . $first['sbornik']]
                    ?? $byInter[$series[$first['sb_kod']]][(string) $first['sbornik']] ?? null;
                if ($docId === null) {
                    $p->count(self::STEP_LINK, 'document_missing');
                    continue;
                }
                $type = $isIssued ? 'invoice' : 'purchase_invoice';
                $linkedDocs[$type . '|' . $docId] = true;
                $p->count(self::STEP_LINK, $this->attach($ctx, $type, $type, $docId, $entryId) ? 'linked' : 'existing');
                continue;
            }
            // Zdroj zápisu dostane první navázaný doklad v pořadí doklad s DPH → bankovní
            // pohyb → pokladní doklad ({@see attach()} přepíše jen zápis bez zdroje). Doklad
            // s DPH i pokladna vznikly z celého dokladu deníku, bankovní zápis je jeho část.
            $group = PremierJournal::groupKey((string) $docKey);
            if (isset($ctx->vatDocuments[$group])) {
                $d = $ctx->vatDocuments[$group];
                $p->count(self::STEP_LINK, $this->attach($ctx, $d['table'], $d['table'], $d['id'], $entryId) ? 'linked' : 'existing');
            }
            foreach ($rows as $r) {
                $txId = $ctx->bankTransactions[$r['inter']] ?? null;
                if ($txId !== null) {
                    $p->count(self::STEP_LINK, $this->attach($ctx, 'bank', 'bank', $txId, $entryId) ? 'bank_linked' : 'existing');
                }
            }
            if (isset($ctx->cashDocuments[$group]) && self::touchesCash($rows)) {
                $p->count(self::STEP_LINK, $this->attach($ctx, 'cash', 'cash', $ctx->cashDocuments[$group], $entryId) ? 'linked' : 'existing');
            }
        }
        foreach (['invoice' => $ctx->issuedInvoices, 'purchase_invoice' => $ctx->purchaseInvoices] as $type => $ids) {
            foreach ($ids as $id) {
                if (isset($ctx->previousPeriod[$type . '|' . $id]) && $this->linkOpening($ctx, $type, $id)) {
                    $p->count(self::STEP_LINK, 'previous_period');
                }
            }
        }
        $orphans = $this->orphans($ctx, $linkedDocs);
        $p->set('orphans', $orphans);
        if ($orphans !== []) {
            $p->warn(self::STEP_LINK, 'orphan_documents', count($orphans) . ' převedených faktur nemá v deníku PREMIER zápis. '
                . 'Nejsou zaúčtované; zaúčtujte je ručně nebo v Účetnictví → Doúčtovat doklady.', ['documents' => array_slice($orphans, 0, 50)]);
        }
        $this->entries->refreshDescriptions($ctx->supplierId, $p, self::STEP_LINK, false);
        $p->finish(self::STEP_LINK);
    }

    public function matchPayments(PremierContext $ctx, PremierDocuments $documents): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $issued = $this->docIndex($ctx->supplierId, PremierImportRepository::KIND_INVOICE);
        $purchases = $this->docIndex($ctx->supplierId, PremierImportRepository::KIND_PURCHASE_INVOICE);
        $series = array_flip(array_keys($documents->invoiceSeries()));
        $existing = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_PAYMENT);
        $insertMatch = $pdo->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
             VALUES (?, ?, ?, ?, ?, "manual", ?)'
        );
        $markTx = $pdo->prepare(
            "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                SET t.match_status = 'manual', t.matched_at = NOW(), t.matched_by = ?, t.matched_invoice_id = COALESCE(?, t.matched_invoice_id)
              WHERE t.id = ? AND s.supplier_id = ?"
        );
        $cashLink = $pdo->prepare('SELECT invoice_id, purchase_invoice_id FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $cashByRow = [];
        $cashDocumentOfRow = [];
        foreach ($ctx->journal->groups($ctx->year) as $docKey => $rows) {
            if (isset($ctx->cashDocuments[$docKey])) {
                // Úhradou v hotovosti je jen řádek na pokladně; haléřové vyrovnání nebo řádek
                // bankovního výpisu s výběrem hotovosti pokladní doklad k faktuře nepřiřadí.
                foreach ($rows as $r) {
                    $cashDocumentOfRow[$r['inter']] = $ctx->cashDocuments[$docKey];
                    if (self::touchesCash([$r])) {
                        $cashByRow[$r['inter']] = $ctx->cashDocuments[$docKey];
                    }
                }
            }
        }
        $entryByRow = [];
        foreach ($ctx->journal->documents($ctx->year) as $docKey => $rows) {
            foreach ($rows as $r) {
                if (isset($ctx->entries[$docKey])) {
                    $entryByRow[$r['inter']] = $ctx->entries[$docKey];
                }
            }
        }
        $byInter = [PremierDocuments::ISSUED => self::byInvoiceInter($issued, $series), PremierDocuments::PURCHASE => self::byInvoiceInter($purchases, $series)];
        $settlements = [];
        $settlementDates = [];
        $deductions = [];
        foreach ($documents->paymentLinks() as $rowInter => $targets) {
            $row = $ctx->journal->row($rowInter);
            if ($row === null || $row['year'] !== $ctx->year) {
                continue;
            }
            foreach ($targets as $t) {
                $isIssued = $t['direction'] === PremierDocuments::ISSUED;
                $docId = $byInter[$isIssued ? PremierDocuments::ISSUED : PremierDocuments::PURCHASE][(string) $t['inter']] ?? null;
                if ($docId === null) {
                    continue;
                }
                $mapKey = $rowInter . '|' . ($isIssued ? 'invoice' : 'purchase_invoice') . '|' . $docId;
                if (!$isIssued && $row['sb_kod'] !== '' && $documents->directionOf($row['sb_kod']) === PremierDocuments::PURCHASE
                    && $row['sbornik'] !== $t['inter'] && (str_starts_with($row['md'], '314') || str_starts_with($row['dal'], '314'))) {
                    // Konečná faktura navázaná na řádek zálohy jiné faktury na 314 = čerpání zálohy ({@see recordAdvanceDeductions()}).
                    $advanceId = $byInter[PremierDocuments::PURCHASE][(string) $row['sbornik']] ?? null;
                    if ($advanceId !== null && $advanceId !== $docId) {
                        $deductions[$docId][$advanceId] = ($deductions[$docId][$advanceId] ?? 0.0) + abs((float) $row['amount']);
                    }
                }
                if (!isset($ctx->bankTransactions[$rowInter]) && !isset($cashByRow[$rowInter]) && isset($entryByRow[$rowInter])) {
                    // Úhrada bez pohybu peněz po zápisech - zápočty dobropisů ({@see settleCreditNotes()}).
                    $type = $isIssued ? 'invoice' : 'purchase_invoice';
                    $settlements[$entryByRow[$rowInter]][$type][$docId] = ($settlements[$entryByRow[$rowInter]][$type][$docId] ?? 0.0) + abs((float) $row['amount']);
                    $settlementDates[$entryByRow[$rowInter]] = $row['date'];
                }
                if (isset($existing[$mapKey])) {
                    $p->count(self::STEP_PAYMENTS, 'existing');
                    continue;
                }
                $txId = $ctx->bankTransactions[$rowInter] ?? null;
                if ($txId !== null) {
                    $insertMatch->execute([
                        $ctx->supplierId, $txId, $isIssued ? $docId : null, $isIssued ? null : $docId,
                        number_format(abs($row['amount']), 2, '.', ''), $ctx->userOrNull(),
                    ]);
                    $matchId = (int) $pdo->lastInsertId();
                    $markTx->execute([$ctx->userOrNull(), $isIssued ? $docId : null, $txId, $ctx->supplierId]);
                    $this->map->put($ctx->supplierId, PremierImportRepository::KIND_PAYMENT, $mapKey, $matchId, $ctx->runId);
                    $p->count(self::STEP_PAYMENTS, 'bank');
                    continue;
                }
                $cashId = $cashByRow[$rowInter] ?? null;
                if ($cashId !== null) {
                    $cashLink->execute([$cashId, $ctx->supplierId]);
                    $linked = $cashLink->fetch(PDO::FETCH_ASSOC) ?: [];
                    if (($linked['invoice_id'] ?? null) === null && ($linked['purchase_invoice_id'] ?? null) === null) {
                        $pdo->prepare('UPDATE cash_documents SET ' . ($isIssued ? 'invoice_id' : 'purchase_invoice_id') . " = ?, purpose = ?, vat_mode = 'none' WHERE id = ? AND supplier_id = ?")
                            ->execute([$docId, $isIssued ? 'invoice_payment' : 'purchase_payment', $cashId, $ctx->supplierId]);
                    } else {
                        $p->count(self::STEP_PAYMENTS, 'cash_multi_invoice');
                    }
                    $this->map->put($ctx->supplierId, PremierImportRepository::KIND_PAYMENT, $mapKey, $cashId, $ctx->runId);
                    $p->count(self::STEP_PAYMENTS, 'cash');
                    continue;
                }
                // Zápočet, haléřový nebo kurzový rozdíl, odpočet zálohy - úhrada bez pohybu peněz.
                // Uhrazenost faktury nese už převod faktury (PremierDocuments), zápis úhrady
                // dostane vazbu na fakturu, ať je vidět, čím byla vyrovnaná.
                $entryId = $entryByRow[$rowInter] ?? null;
                if ($entryId !== null) {
                    $this->linkPayment($ctx, $isIssued ? 'invoice' : 'purchase_invoice', $docId, $entryId);
                }
                $this->map->put($ctx->supplierId, PremierImportRepository::KIND_PAYMENT, $mapKey, $docId, $ctx->runId);
                $p->count(self::STEP_PAYMENTS, 'without_money');
            }
        }
        $this->recordIssuedPayments($ctx, $entryByRow, $cashByRow, $cashDocumentOfRow);
        $this->linkCreditNotes($ctx, $documents, $byInter);
        $this->settleCreditNotes($ctx, $settlements, $settlementDates);
        $this->recordAdvanceDeductions($ctx, $deductions);
        $this->inferAdvanceDeductions($ctx);
        $this->markBookedWithoutDocument($ctx);
        $p->finish(self::STEP_PAYMENTS);
    }

    /**
     * Evidované platby vydaných faktur (`invoice_payments`) k úhradám z banky a pokladny roku.
     * Z nich čte uhrazenou část vydané faktury saldokonto, kontrola úplnosti i uvolnění
     * pohybu; samotné párování (`payment_matches`, vazba pokladního dokladu) je nenahradí.
     * Úhrada bez pohybu peněz (zápočet, odpočet zálohy) platbu nedostává - fakturu vyrovná
     * ruční zápis navázaný na fakturu ({@see linkPayment()}).
     *
     * Platba = pohyb navázaných řádků na účtu partnera faktury ({@see PremierDocuments::settlement()})
     * v jednom bankovním pohybu nebo pokladním dokladu, včetně haléřového vyrovnání v témže
     * zápisu. Přeplatek pohybu, který interní doklad přeúčtoval na jinou fakturu, je platbou
     * té druhé. Uhrazenou částku a stav faktury vede převod podle PREMIER, platby ji jen
     * doplňují: součet nepřesáhne `paid_total`. Faktura v cizí měně dostane částku přepočtenou
     * kurzem dokladu. Opakovaný převod platbu nezdvojí (banka: pohyb + faktura, pokladna:
     * doklad + faktura) a doplní ji i k úhradám spárovaným dřívějším během.
     *
     * @param array<int,int> $entryByRow INTER řádku → journal_entries.id
     * @param array<int,int> $cashByRow INTER řádku na pokladně → cash_documents.id
     * @param array<int,int> $cashDocumentOfRow INTER libovolného řádku pokladního dokladu → cash_documents.id
     */
    private function recordIssuedPayments(PremierContext $ctx, array $entryByRow, array $cashByRow, array $cashDocumentOfRow): void
    {
        if ($ctx->issuedPaymentRows === []) {
            return;
        }
        $entryTx = [];
        foreach ($ctx->bankTransactions as $rowInter => $txId) {
            if (isset($entryByRow[$rowInter])) {
                $entryTx[$entryByRow[$rowInter]] = $txId;
            }
        }
        $planned = [];
        $transfers = [];
        foreach ($ctx->issuedPaymentRows as $invoiceId => $rows) {
            $money = [];
            $extra = [];
            foreach ($rows as $r) {
                $row = $ctx->journal->row($r['inter']);
                if ($row === null || $row['year'] !== $ctx->year) {
                    continue; // úhrada jiného roku - zapíše ji převod toho roku
                }
                $target = isset($ctx->bankTransactions[$r['inter']]) ? 'bank|' . $ctx->bankTransactions[$r['inter']]
                    : (isset($cashByRow[$r['inter']]) ? 'cash|' . $cashByRow[$r['inter']] : null);
                if ($target !== null) {
                    $money[$target]['amount'] = ($money[$target]['amount'] ?? 0.0) + $r['amount'];
                    $money[$target]['date'] = max($money[$target]['date'] ?? $r['date'], $r['date']);
                    continue;
                }
                // Haléřové vyrovnání v zápisu bankovního pohybu nebo na pokladním dokladu.
                $entryId = $entryByRow[$r['inter']] ?? null;
                $other = $entryId !== null && isset($entryTx[$entryId]) ? 'bank|' . $entryTx[$entryId]
                    : (isset($cashDocumentOfRow[$r['inter']]) ? 'cash|' . $cashDocumentOfRow[$r['inter']] : null);
                if ($other !== null) {
                    $extra[$other] = ($extra[$other] ?? 0.0) + $r['amount'];
                } elseif ($entryId !== null) {
                    // Zápis bez pohybu peněz (interní doklad): přeúčtování úhrady mezi fakturami.
                    $transfers[$entryId][$invoiceId] = ($transfers[$entryId][$invoiceId] ?? 0.0) + $r['amount'];
                }
            }
            foreach ($extra as $target => $amount) {
                if (isset($money[$target])) {
                    $money[$target]['amount'] += $amount;
                }
            }
            if ($money !== []) {
                $planned[$invoiceId] = $money;
            }
        }
        if ($planned === []) {
            return;
        }
        $pdo = $this->db->pdo();
        $ids = array_keys($planned);
        foreach ($transfers as $parts) {
            array_push($ids, ...array_keys($parts));
        }
        $ids = array_values(array_unique($ids));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT i.id, i.status, i.invoice_type, i.paid_total, i.exchange_rate, cur.code AS currency,
                    (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id) AS recorded
               FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ? AND i.id IN ({$in})"
        );
        $stmt->execute(array_merge([$ctx->supplierId], $ids));
        $invoices = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $invoices[(int) $row['id']] = $row;
        }
        $stmt = $pdo->prepare("SELECT invoice_id, source, bank_transaction_id, note FROM invoice_payments WHERE supplier_id = ? AND invoice_id IN ({$in})");
        $stmt->execute(array_merge([$ctx->supplierId], $ids));
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['bank_transaction_id'] !== null) {
                $existing[$row['invoice_id'] . '|bank|' . $row['bank_transaction_id']] = true;
            }
            if ($row['source'] === 'cash' && $row['note'] !== null) {
                $existing[$row['invoice_id'] . '|' . $row['note']] = true;
            }
        }
        // Pokladní doklad s evidovanou platbou je zapsaný, ať platbu založil převod nebo aplikace.
        $stmt = $pdo->prepare("SELECT invoice_id, id FROM cash_documents WHERE supplier_id = ? AND invoice_payment_id IS NOT NULL AND invoice_id IN ({$in})");
        $stmt->execute(array_merge([$ctx->supplierId], $ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[$row['invoice_id'] . '|cash|' . $row['id']] = true;
        }
        $insert = $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, bank_transaction_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $linkCash = $pdo->prepare('UPDATE cash_documents SET invoice_payment_id = ? WHERE id = ? AND supplier_id = ? AND invoice_id = ? AND invoice_payment_id IS NULL');
        $left = [];
        foreach ($invoices as $id => $invoice) {
            // Dobropis (vrácení peněz) se v platbách nevede - jeho proplacení nese stav dokladu.
            if (!in_array((string) $invoice['status'], ['draft', 'cancelled'], true) && in_array((string) $invoice['invoice_type'], ['invoice', 'proforma'], true)) {
                $left[$id] = round((float) $invoice['paid_total'] - (float) $invoice['recorded'], 2);
            }
        }
        // Zapíše platbu v Kč (u faktury v cizí měně přepočtenou), nejvýš do zbytku uhrazené částky.
        // Vrací zapsanou část v Kč; null = platba už je zapsaná (opakovaný převod).
        $record = function (int $invoiceId, string $target, float $czk, string $date) use ($ctx, $pdo, $invoices, $existing, $insert, $linkCash, &$left): ?float {
            [$kind, $movementId] = explode('|', $target);
            $note = $kind === 'cash' ? 'Úhrada převzatá z PREMIER, pokladní doklad #' . $movementId : 'Úhrada převzatá z PREMIER';
            if (!isset($left[$invoiceId]) || isset($existing[$invoiceId . ($kind === 'bank' ? '|bank|' . $movementId : '|' . $note)])
                || ($kind === 'cash' && isset($existing[$invoiceId . '|cash|' . $movementId]))) {
                return null;
            }
            $invoice = $invoices[$invoiceId];
            $rate = (string) $invoice['currency'] !== 'CZK' && $invoice['exchange_rate'] !== null && (float) $invoice['exchange_rate'] > 0 ? (float) $invoice['exchange_rate'] : 1.0;
            $amount = round(min($czk / $rate, $left[$invoiceId]), 2);
            if ($amount < 0.01) {
                return 0.0;
            }
            $insert->execute([
                $ctx->supplierId, $invoiceId, $date, number_format($amount, 2, '.', ''), (string) $invoice['currency'],
                $note, $kind, $kind === 'bank' ? (int) $movementId : null, $ctx->userOrNull(),
            ]);
            if ($kind === 'cash') {
                $linkCash->execute([(int) $pdo->lastInsertId(), (int) $movementId, $ctx->supplierId, $invoiceId]);
            }
            $left[$invoiceId] = round($left[$invoiceId] - $amount, 2);
            $ctx->protocol->count(self::STEP_PAYMENTS, 'issued_payments_recorded');
            return round($amount * $rate, 2);
        };
        // Přeplatek pohybu nad uhrazenou částkou faktury (v Kč) - kandidát na přeúčtování níže.
        $overflow = [];
        foreach ($planned as $invoiceId => $money) {
            ksort($money);
            foreach ($money as $target => $m) {
                $recorded = $record($invoiceId, $target, (float) $m['amount'], $m['date']);
                if ($recorded !== null && (float) $m['amount'] - $recorded >= 0.01) {
                    $overflow[$invoiceId][$target] = ['amount' => round((float) $m['amount'] - $recorded, 2), 'date' => $m['date']];
                }
            }
        }
        // Interní doklad přeúčtoval část úhrady z jedné faktury na jinou (záporný řádek u první,
        // kladný u druhé): přeplatek pohybu první faktury je platbou druhé.
        foreach ($transfers as $parts) {
            foreach ($parts as $donor => $moved) {
                if ($moved > -0.005 || !isset($overflow[$donor])) {
                    continue;
                }
                $available = -$moved;
                foreach ($parts as $recipient => $amount) {
                    if ($recipient === $donor || $amount < 0.01) {
                        continue;
                    }
                    $want = min($amount, $available);
                    foreach ($overflow[$donor] as $target => $o) {
                        if ($want < 0.01) {
                            break;
                        }
                        $part = min($want, $o['amount']);
                        $recorded = $record($recipient, $target, $part, $o['date']);
                        $used = $recorded ?? $part;
                        $overflow[$donor][$target]['amount'] = round($o['amount'] - $used, 2);
                        $want = round($want - $used, 2);
                        $available = round($available - $used, 2);
                    }
                }
            }
        }
    }

    /**
     * Zápočet dobropisu proti faktuře ručním zápisem (interní doklad, vazby obou dokladů
     * na řádky téhož zápisu): dobropis dostane vazbu na opravovanou fakturu a zápočet
     * (`credit_note_offsets`) jako po zápočtu v MyÚčtu, vydaná faktura i evidovanou platbu
     * se zdrojem `credit_note`. Ruční vyrovnání zápisem navázaným na víc dokladů téhož typu
     * saldokonto nepočítá ({@see \MyInvoice\Support\Sql\LinkedManualSettlementSql}), zápočet
     * ano. Jen zápis s jedinou fakturou a jejími dobropisy téhož směru; ostatní (křížové
     * zápočty víc faktur) zůstávají jen s vazbou zápisu. Opakovaný převod nic nezdvojí.
     *
     * @param array<int,array<string,array<int,float>>> $settlements zápis → typ dokladu → id dokladu → částka v Kč
     * @param array<int,string> $dates zápis → datum
     */
    private function settleCreditNotes(PremierContext $ctx, array $settlements, array $dates): void
    {
        if ($settlements === []) {
            return;
        }
        $pdo = $this->db->pdo();
        $ids = array_keys($settlements);
        $stmt = $pdo->prepare("SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = 'manual' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute(array_merge([$ctx->supplierId], $ids));
        $manual = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        foreach ($settlements as $entryId => $types) {
            if (!isset($manual[$entryId])) {
                continue;
            }
            foreach ($types as $type => $docs) {
                if (count($docs) < 2) {
                    continue;
                }
                $in = implode(',', array_fill(0, count($docs), '?'));
                $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
                $kind = $type === 'invoice' ? 'invoice_type' : 'document_kind';
                $rows = $pdo->prepare("SELECT id, {$kind} AS kind, varsymbol AS number, exchange_rate FROM {$table} WHERE supplier_id = ? AND id IN ({$in})");
                $rows->execute(array_merge([$ctx->supplierId], array_keys($docs)));
                $invoices = [];
                $notes = [];
                foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if ($r['kind'] === 'invoice') {
                        $invoices[] = $r;
                    } elseif ($r['kind'] === 'credit_note') {
                        $notes[] = $r;
                    }
                }
                if (count($invoices) !== 1 || $notes === [] || count($invoices) + count($notes) !== count($docs)) {
                    continue;
                }
                foreach ($notes as $note) {
                    $rate = $note['exchange_rate'] !== null && (float) $note['exchange_rate'] > 0 ? (float) $note['exchange_rate'] : 1.0;
                    if ($this->offsetCreditNote($ctx, $type, (int) $invoices[0]['id'], (int) $note['id'], (string) $note['number'],
                        round($docs[(int) $note['id']] / $rate, 2), $dates[$entryId])) {
                        $ctx->protocol->count(self::STEP_PAYMENTS, 'credit_note_offsets');
                    }
                }
            }
        }
    }

    /**
     * Čerpání zálohy placené přijatou fakturou: PREMIER vede zálohu jako fakturu zaúčtovanou
     * na zálohový účet (MD 314 / D 321) a konečná faktura ji odečte řádkem na 314, s vazbou
     * `VAZBY` na řádek zálohy. MyÚčto takovou zálohu drží v saldu 314 otevřenou, dokud
     * nevznikne čerpání (`purchase_advance_deductions`, záloha = zálohová faktura). Částka =
     * kredit 314 v zápisu konečné faktury, rozdělený mezi zálohy nejvýš do jejich řádku.
     * Existující čerpání převod nepřepisuje a opakovaný převod nic nezdvojí.
     *
     * @param array<int,array<int,float>> $deductions id konečné faktury → id zálohy → řádek zálohy na 314 v Kč
     */
    private function recordAdvanceDeductions(PremierContext $ctx, array $deductions): void
    {
        if ($deductions === []) {
            return;
        }
        $pdo = $this->db->pdo();
        $finals = array_keys($deductions);
        $stmt = $pdo->prepare(
            "SELECT e.source_id, SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE 0 END) AS credit
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
               LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
              WHERE e.supplier_id = ? AND e.source_type = 'purchase_invoice' AND e.reversed_by IS NULL
                AND e.source_id IN (" . implode(',', array_fill(0, count($finals), '?')) . ")
                AND (ca.account_code LIKE '314%' OR COALESCE(pa.account_code, '') LIKE '314%')
              GROUP BY e.source_id"
        );
        $stmt->execute(array_merge([$ctx->supplierId], $finals));
        $credit = array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        // Konečná faktura, která už čerpání má (dřívější převod, nebo ho uživatel upravil),
        // se nechá být - opakovaný převod by jinak vrátil vazbu, kterou uživatel změnil.
        $stmt = $pdo->prepare('SELECT DISTINCT final_invoice_id FROM purchase_advance_deductions WHERE supplier_id = ? AND final_invoice_id IN ('
            . implode(',', array_fill(0, count($finals), '?')) . ')');
        $stmt->execute(array_merge([$ctx->supplierId], $finals));
        $done = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        // Zůstatek zálohy = debet 314 v jejím zápisu − dosavadní čerpání; víc se čerpat nesmí.
        $advanceIds = array_values(array_unique(array_merge(...array_map('array_keys', array_values($deductions)))));
        $stmt = $pdo->prepare(
            "SELECT e.source_id, SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE 0 END)
                    - (SELECT COALESCE(SUM(pad.amount), 0) FROM purchase_advance_deductions pad WHERE pad.supplier_id = e.supplier_id AND pad.advance_invoice_id = e.source_id)
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
               LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
              WHERE e.supplier_id = ? AND e.source_type = 'purchase_invoice' AND e.reversed_by IS NULL
                AND e.source_id IN (" . implode(',', array_fill(0, count($advanceIds), '?')) . ")
                AND (ca.account_code LIKE '314%' OR COALESCE(pa.account_code, '') LIKE '314%')
              GROUP BY e.source_id, e.supplier_id"
        );
        $stmt->execute(array_merge([$ctx->supplierId], $advanceIds));
        $remaining = array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO purchase_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($deductions as $finalId => $advances) {
            if (isset($done[$finalId])) {
                continue;
            }
            $left = round($credit[$finalId] ?? 0.0, 2);
            ksort($advances);
            foreach ($advances as $advanceId => $amount) {
                $take = round(min($amount, $left, $remaining[$advanceId] ?? 0.0), 2);
                if ($take < 0.01) {
                    continue;
                }
                $remaining[$advanceId] = round(($remaining[$advanceId] ?? 0.0) - $take, 2);
                $insert->execute([$ctx->supplierId, $finalId, $advanceId, number_format($take, 2, '.', ''), $ctx->userOrNull()]);
                if ($insert->rowCount() > 0) {
                    $ctx->protocol->count(self::STEP_PAYMENTS, 'advance_deductions');
                }
                $left = round($left - $take, 2);
            }
        }
    }

    /**
     * Čerpání záloh placených fakturou, které PREMIER nevede vazbou, jen zaúčtováním:
     * konečná faktura roku s kreditem zálohového účtu 314 bez čerpání čerpá zálohy téhož
     * dodavatele (faktury s debetem 314) od nejstarší, nejvýš do jejich zůstatku - stejně
     * jako převod z POHODY. Zůstatek zálohy = debet 314 v jejím zápisu − dosavadní čerpání.
     * Opakovaný převod nic nezdvojí (konečná faktura s čerpáním se přeskočí).
     */
    private function inferAdvanceDeductions(PremierContext $ctx): void
    {
        if ($ctx->period === null) {
            return;
        }
        $pdo = $this->db->pdo();
        $s = $ctx->supplierId;
        $account = "(ca.account_code LIKE '314%' OR COALESCE(pa.account_code, '') LIKE '314%')";
        $lines = "FROM purchase_invoices d
                  JOIN journal_entries e ON e.supplier_id = d.supplier_id AND e.source_type = 'purchase_invoice' AND e.source_id = d.id AND e.reversed_by IS NULL
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                 WHERE d.supplier_id = ? AND d.document_kind IN ('invoice', 'credit_note') AND d.status NOT IN ('draft', 'cancelled') AND {$account}
                   AND " . TakenOverRecord::documentSql('purchase_invoice', 'd');
        $stmt = $pdo->prepare(
            "SELECT d.id, d.vendor_id, SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE 0 END) AS debit,
                    (SELECT COALESCE(SUM(pad.amount), 0) FROM purchase_advance_deductions pad WHERE pad.supplier_id = d.supplier_id AND pad.advance_invoice_id = d.id) AS deducted
               {$lines} AND e.entry_date <= ?
              GROUP BY d.id, d.vendor_id HAVING debit > 0.005 ORDER BY MIN(e.entry_date), d.id"
        );
        $stmt->execute([$s, $ctx->period['ends_on']]);
        $advances = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $left = round((float) $r['debit'] - (float) $r['deducted'], 2);
            if ($left >= 0.01) {
                $advances[(int) $r['vendor_id']][(int) $r['id']] = $left;
            }
        }
        if ($advances === []) {
            return;
        }
        $stmt = $pdo->prepare(
            "SELECT d.id, d.vendor_id, SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE 0 END) AS credit
               {$lines} AND e.entry_date >= ? AND e.entry_date <= ?
                AND NOT EXISTS (SELECT 1 FROM purchase_advance_deductions pad WHERE pad.supplier_id = d.supplier_id AND pad.final_invoice_id = d.id)
              GROUP BY d.id, d.vendor_id HAVING credit > 0.005 ORDER BY MIN(e.entry_date), d.id"
        );
        $stmt->execute([$s, $ctx->period['starts_on'], $ctx->period['ends_on']]);
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO purchase_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $credit = round((float) $f['credit'], 2);
            $vendor = (int) $f['vendor_id'];
            foreach ($advances[$vendor] ?? [] as $advanceId => $left) {
                if ($credit < 0.01) {
                    break;
                }
                if ($advanceId === (int) $f['id'] || $left < 0.01) {
                    continue;
                }
                $take = round(min($credit, $left), 2);
                $insert->execute([$s, (int) $f['id'], $advanceId, number_format($take, 2, '.', ''), $ctx->userOrNull()]);
                $advances[$vendor][$advanceId] = round($left - $take, 2);
                $credit = round($credit - $take, 2);
                $ctx->protocol->count(self::STEP_PAYMENTS, 'advance_deductions_inferred');
            }
        }
    }

    /**
     * Zápis zápočtu dobropisu jako {@see \MyInvoice\Service\Invoice\CreditNoteOffsetService},
     * bez jeho podmínek na zbývající částku - obě strany jsou podle PREMIER uhrazené.
     * Platba vydané faktury nepřesáhne její uhrazenou částku.
     */
    private function offsetCreditNote(PremierContext $ctx, string $type, int $invoiceId, int $noteId, string $noteNumber, float $amount, string $date): bool
    {
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT 1 FROM credit_note_offsets WHERE doc_type = ? AND credit_note_id = ?');
        $exists->execute([$type, $noteId]);
        if ($exists->fetchColumn() !== false || $amount < 0.01) {
            return false;
        }
        [$table, $parent] = $type === 'invoice' ? ['invoices', 'parent_invoice_id'] : ['purchase_invoices', 'parent_purchase_invoice_id'];
        $pdo->prepare("UPDATE {$table} SET {$parent} = ? WHERE id = ? AND supplier_id = ? AND {$parent} IS NULL")
            ->execute([$invoiceId, $noteId, $ctx->supplierId]);
        $paymentId = null;
        if ($type === 'invoice') {
            $row = $pdo->prepare(
                "SELECT cur.code AS currency, i.paid_total,
                        (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id) AS recorded
                   FROM invoices i JOIN currencies cur ON cur.id = i.currency_id WHERE i.id = ? AND i.supplier_id = ?"
            );
            $row->execute([$invoiceId, $ctx->supplierId]);
            $invoice = $row->fetch(PDO::FETCH_ASSOC);
            $pay = $invoice === false ? 0.0 : round(min($amount, (float) $invoice['paid_total'] - (float) $invoice['recorded']), 2);
            if ($pay >= 0.01) {
                $pdo->prepare(
                    "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, 'credit_note', ?)"
                )->execute([$ctx->supplierId, $invoiceId, $date, number_format($pay, 2, '.', ''), (string) $invoice['currency'],
                    'Zápočet dobropisu ' . $noteNumber . ' (převzato z PREMIER)', $ctx->userOrNull()]);
                $paymentId = (int) $pdo->lastInsertId();
            }
        }
        $pdo->prepare(
            'INSERT INTO credit_note_offsets (supplier_id, doc_type, invoice_id, credit_note_id, amount, offset_on, invoice_payment_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$ctx->supplierId, $type, $invoiceId, $noteId, number_format($amount, 2, '.', ''), $date, $paymentId, $ctx->userOrNull()]);
        return true;
    }

    /**
     * Dobropis navázaný v PREMIER na opravovanou fakturu (`VAZBY` faktura ↔ faktura téhož
     * směru) dostane vazbu na ni (`parent_invoice_id` / `parent_purchase_invoice_id`).
     * Jen dvojice dobropis + faktura téhož partnera a jen dobropis bez vazby - ručně
     * nastavenou vazbu opakovaný převod nepřepíše. Vyrovnání peněz se tím nemění,
     * úhrady obou dokladů vedou jejich vlastní vazby na deník.
     *
     * @param array<string,array<string,int>> $byInter směr → INTER faktury → id dokladu
     */
    private function linkCreditNotes(PremierContext $ctx, PremierDocuments $documents, array $byInter): void
    {
        $pdo = $this->db->pdo();
        foreach ($documents->documentLinks() as $l) {
            $a = $byInter[$l['direction']][(string) $l['from']] ?? null;
            $b = $byInter[$l['direction']][(string) $l['to']] ?? null;
            if ($a === null || $b === null) {
                continue;
            }
            [$table, $type, $partner, $parent] = $l['direction'] === PremierDocuments::ISSUED
                ? ['invoices', 'invoice_type', 'client_id', 'parent_invoice_id']
                : ['purchase_invoices', 'document_kind', 'vendor_id', 'parent_purchase_invoice_id'];
            $stmt = $pdo->prepare("SELECT id, {$type} AS type, {$partner} AS partner, {$parent} AS parent FROM {$table} WHERE supplier_id = ? AND id IN (?, ?)");
            $stmt->execute([$ctx->supplierId, $a, $b]);
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[(string) $row['type']] = $row;
            }
            if (count($rows) !== 2 || !isset($rows['credit_note'], $rows['invoice'])
                || (string) $rows['credit_note']['partner'] !== (string) $rows['invoice']['partner'] || $rows['credit_note']['parent'] !== null) {
                continue;
            }
            $pdo->prepare("UPDATE {$table} SET {$parent} = ? WHERE id = ? AND supplier_id = ? AND {$parent} IS NULL")
                ->execute([(int) $rows['invoice']['id'], (int) $rows['credit_note']['id'], $ctx->supplierId]);
            $ctx->protocol->count(self::STEP_PAYMENTS, 'credit_notes_linked');
        }
    }

    /**
     * Pohyby, které PREMIER zaúčtoval bez faktury (poplatky, převody mezi vlastními účty,
     * mzdy, odvody), jsou vyřízené - označí se jako ignorované s poznámkou. Jen pohyb
     * s vlastním zápisem ({@see BankTransactionPostingScope}): ignorovaný pohyb Doúčtování
     * nevidí, bez zápisu by tak zůstal nezaúčtovaný.
     */
    private function markBookedWithoutDocument(PremierContext $ctx): void
    {
        $marked = $this->entries->markBankBookedWithoutDocument(
            $ctx->supplierId, $ctx->userOrNull(), array_values($ctx->bankTransactions), 'premier_booked',
            'Zaúčtováno v PREMIER bez faktury (převod z PREMIER).', BankTransactionPostingScope::existsSql('s.supplier_id', 't.id'),
        );
        if ($marked > 0) {
            $ctx->protocol->count(self::STEP_PAYMENTS, 'booked_without_document', $marked);
        }
    }

    /** @return array<string,int> „řada|INTER" → id dokladu (i z dřívějších běhů) */
    private function docIndex(int $supplierId, string $kind): array
    {
        return $this->map->all($supplierId, $kind);
    }

    /**
     * INTER faktury → id dokladu: první klíč mapy (v jejím pořadí) s řadou faktur a INTER
     * za posledním `|`.
     *
     * @param array<string,int> $map {@see docIndex()}
     * @param array<string,mixed> $series řady faktur
     * @return array<string,int>
     */
    private static function byInvoiceInter(array $map, array $series): array
    {
        $out = [];
        foreach ($map as $key => $id) {
            $key = (string) $key;
            $last = strrpos($key, '|');
            if ($last !== false && isset($series[strstr($key, '|', true)])) {
                $out[substr($key, $last + 1)] ??= $id;
            }
        }
        return $out;
    }

    /**
     * Převedené faktury roku bez zápisu v deníku (kromě konceptů, záloh a dokladů minulého období).
     *
     * @param array<string,true> $linked
     * @return list<array{type:string,id:int}>
     */
    private function orphans(PremierContext $ctx, array $linked): array
    {
        $out = [];
        foreach (['invoice' => [$ctx->issuedInvoices, 'invoices', "invoice_type <> 'proforma'"], 'purchase_invoice' => [$ctx->purchaseInvoices, 'purchase_invoices', "document_kind <> 'advance'"]] as $type => [$ids, $table, $filter]) {
            $candidates = array_values(array_filter($ids, static fn (int $id): bool => !isset($linked[$type . '|' . $id]) && !isset($ctx->previousPeriod[$type . '|' . $id])));
            if ($candidates === []) {
                continue;
            }
            $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND status <> 'draft' AND {$filter} AND booked_at IS NOT NULL
                AND id IN (" . implode(',', array_fill(0, count($candidates), '?')) . ')');
            $stmt->execute(array_merge([$ctx->supplierId], $candidates));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if (!$this->hasLink($ctx->supplierId, $type, (int) $id)) {
                    $out[] = ['type' => $type, 'id' => (int) $id];
                }
            }
        }
        return array_slice($out, 0, ImportProtocol::LIST_LIMIT);
    }

    private function hasLink(int $supplierId, string $type, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM journal_entry_document_links WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? AND (note IS NULL OR note <> ?) LIMIT 1');
        $stmt->execute([$supplierId, $type, $id, self::PAYMENT_NOTE]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Doklad minulého období (neuhrazená faktura z předchozího roku) dostane vazbu na
     * otevírací zápis roku - Doúčtování dokladů ho podle ní vynechá.
     */
    private function linkOpening(PremierContext $ctx, string $docType, int $docId): bool
    {
        return $this->entries->linkOpening($ctx->supplierId, $ctx->userOrNull(), $ctx->period !== null ? (int) $ctx->period['id'] : null, $docType, $docId);
    }

    /**
     * Zápis dostane `source_type`/`source_id` dokladu (jen první navázaný doklad a jen
     * zápis převodu bez zdroje, převod ho zapsal jako `manual`) a vazbu
     * v `journal_entry_document_links`.
     *
     * @return bool true = nová vazba
     */
    private function attach(PremierContext $ctx, string $sourceType, string $docType, int $docId, int $entryId): bool
    {
        return $this->entries->attach($ctx->supplierId, $ctx->userOrNull(), $sourceType, 'manual', $docType, $docId, [$entryId]);
    }

    private function linkPayment(PremierContext $ctx, string $docType, int $docId, int $entryId): void
    {
        $this->db->pdo()->prepare(
            'INSERT IGNORE INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$ctx->supplierId, $entryId, $docType, $docId, self::PAYMENT_NOTE, $ctx->userOrNull()]);
    }

    /** @param list<array<string,mixed>> $rows */
    private static function touchesCash(array $rows): bool
    {
        foreach ($rows as $r) {
            if (str_starts_with($r['md'], '211') || str_starts_with($r['dal'], '211')) {
                return true;
            }
        }
        return false;
    }
}
