<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\TakenOverRecord;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\JournalEntryLinker;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Provázání převedených dokladů s převedeným deníkem a úhrad s doklady.
 *
 * Zaúčtování se nepřepočítává: zápis z Pohody dostane `source_id` = id dokladu a vazbu
 * v `journal_entry_document_links` (u banky id pohybu). Páruje se podle čísla dokladu,
 * které nese doklad i řádek deníku (`act:number`).
 *
 * Úhrady nese Pohoda přímo u dokladu (`liquidations`): číslo bankovního nebo pokladního
 * dokladu, datum a částku. Párování je proto přesné - žádné odhadování podle částky.
 */
final class DocumentLinker
{
    public const STEP_LINK = 'link';
    public const STEP_PAYMENTS = 'payments';

    /** Pohyb a zápis se stejným číslem dokladu dál od sebe než půl roku jsou různé doklady. */
    private const SAME_BANK_NUMBER_SECONDS = 183 * 86400;

    private readonly JournalEntryLinker $entries;

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly OtherItemService $otherItems,
    ) {
        $this->entries = new JournalEntryLinker($db, 'Pohody');
    }

    public function link(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $index = $this->journalIndex($ctx->supplierId);
        $unbooked = [
            'invoice' => $this->unbookedIds('invoices', $ctx->supplierId),
            'purchase_invoice' => $this->unbookedIds('purchase_invoices', $ctx->supplierId),
        ];
        // Pohyby se stejným číslem dokladu (platební brána: úhrada, poplatek a odvod netto)
        // mají v deníku Pohody jediný zápis a vlastnit ho může jen jeden z nich. Dostane ho
        // pohyb, kterým Pohoda hradí doklad - jinak by úhrada faktury zůstala mezi
        // nezaúčtovanými pohyby, ačkoli její zápis v deníku je.
        $paying = [];
        foreach ($ctx->liquidations as $l) {
            if ($l['agenda'] === 'bank' && ($l['source_id'] ?? '') !== '' && isset($ctx->bankByPohodaId[$l['source_id']])) {
                $paying[$ctx->bankByPohodaId[$l['source_id']]] = true;
            }
        }
        $bankDocs = [];
        foreach ($ctx->bankTransactions as $number => $txs) {
            usort($txs, static fn (array $a, array $b): int => isset($paying[$b['id']]) <=> isset($paying[$a['id']]));
            foreach ($txs as $tx) {
                $bankDocs[$number . '#' . $tx['date'] . '#' . $tx['id']] = $tx['id'];
            }
        }
        $orphans = [];
        $openingAdvances = [];
        foreach ([
            [PohodaJournal::RECEIVED, 'purchase_invoice', 'purchase_invoice', $ctx->purchaseInvoices, null],
            [PohodaJournal::COMMITMENT, 'manual', 'purchase_invoice', $ctx->commitments, 'purchase_invoice'],
            [PohodaJournal::ISSUED, 'invoice', 'invoice', $ctx->issuedInvoices, null],
            [PohodaJournal::RECEIVABLE, 'manual', 'invoice', $ctx->receivables, 'invoice'],
            [PohodaJournal::INTERNAL, 'manual', 'invoice', $ctx->internalSales, 'invoice'],
            [PohodaJournal::INTERNAL, 'manual', 'purchase_invoice', $ctx->internalPurchases, 'purchase_invoice'],
            [PohodaJournal::CASH, 'cash', 'cash', $ctx->cashDocuments, null],
            [PohodaJournal::BANK, 'bank', 'bank', $bankDocs, null],
        ] as [$source, $sourceType, $docType, $docs, $retype]) {
            foreach ($docs as $docKey => $docId) {
                [$number, $date] = array_pad(explode('#', (string) $docKey, 3), 2, null);
                $entries = $index[$source][$number] ?? [];
                if ($docType === 'bank' && count($entries) > 1 && $date !== null) {
                    $sameDay = array_values(array_filter($entries, static fn (array $e): bool => $e['date'] === $date));
                    $entries = $sameDay !== [] ? $sameDay : $entries;
                }
                // Číselná řada banky se každý rok začíná znovu a agenda vede i pohyby po konci
                // roku: nezaúčtovaný pohyb nesmí převzít zápis stejného čísla o rok dřív.
                if ($docType === 'bank' && $date !== null && count($ctx->bankTransactions[$number] ?? []) > 1) {
                    $entries = array_values(array_filter($entries, static fn (array $e): bool =>
                        abs(strtotime((string) $e['date']) - strtotime($date)) <= self::SAME_BANK_NUMBER_SECONDS));
                }
                $entryIds = array_column($entries, 'id');
                if ($entryIds === []) {
                    if (isset($ctx->previousPeriod[$docType . '|' . $docId])) {
                        $p->count(self::STEP_LINK, 'previous_period');
                        if ($docType === 'purchase_invoice' && $this->isAdvance((int) $docId)) {
                            $openingAdvances[(int) $docId] = true;
                        } else {
                            $this->linkOpening($ctx, $docType, (int) $docId);
                        }
                    } elseif ($docType === 'cash' && isset($ctx->openingCash[(int) $docId])) {
                        // Pokladní doklad počátečního stavu: zápis nemá, naváže se na otevírací zápis níže.
                    } elseif (isset($unbooked[$docType][$docId])) {
                        $p->count(self::STEP_LINK, 'unbooked');
                    } elseif ($docType === 'bank') {
                        // Pohyb s předkontací „Nevím" - řeší ho krok úhrad ({@see UnbookedBankPayments}).
                        $p->count(self::STEP_LINK, 'unbooked_bank');
                    } else {
                        if (count($orphans) < ImportProtocol::LIST_LIMIT) {
                            $orphans[] = ['type' => $docType, 'document_no' => $number, 'id' => $docId];
                        }
                        $p->count(self::STEP_LINK, 'orphans');
                    }
                    continue;
                }
                $new = $this->entries->attach($ctx->supplierId, $ctx->userOrNull(), $retype ?? $sourceType, $sourceType, $docType, $docId, $entryIds);
                $p->count(self::STEP_LINK, $new ? 'linked' : 'existing');
            }
        }
        $this->linkOpeningAdvances($ctx, array_keys($openingAdvances));
        foreach (array_keys($ctx->openingCash) as $cashId) {
            if ($this->linkOpening($ctx, 'cash', (int) $cashId)) {
                $p->count(self::STEP_LINK, 'opening_cash');
            }
        }
        $p->set('orphans', $orphans);
        if ($orphans !== []) {
            $p->warn(self::STEP_LINK, 'orphan_documents', count($orphans) . ' dokladů nemá v deníku Pohody zápis se stejným číslem. '
                . 'Nejsou zaúčtované; zaúčtujte je ručně nebo v Účetnictví → Doúčtovat doklady.', ['documents' => array_slice($orphans, 0, 50)]);
        }
        $this->linkAdvances($ctx);
        $this->linkTaxDocumentsToAdvances($ctx);
        $this->entries->refreshDescriptions($ctx->supplierId, $p, self::STEP_LINK);
        $p->finish(self::STEP_LINK);
    }

    /**
     * Čerpání zálohy konečnou fakturou: POHODA odečítá zálohu položkou konečné faktury
     * s odkazem na zálohový doklad (`invoiceAdvancePaymentItem/sourceDocument`). Bez vazby
     * v MyÚčtu (`advance_purchase_invoice_id`, u vydaných `parent_invoice_id`) saldokonto
     * 314/324 nepozná, že je záloha vyúčtovaná, a drží ji otevřenou v plné výši.
     *
     * Faktura, která odečítá právě jednu dosud nevázanou zálohu, dostane vazbu na
     * dokladu. Ostatní čerpání přijatých záloh (vyúčtování více záloh jednou fakturou,
     * záloha čerpaná postupně víc fakturami) jde do `purchase_advance_deductions`
     * s částkou čerpanou ze zálohového účtu (u plátce základ, DPH čerpá DDKP).
     * U vydaných faktur takové čerpání zůstává v protokolu k ruční kontrole.
     * Existující vazbu převod nepřepisuje a opakovaný převod nic nezdvojí.
     */
    private function linkAdvances(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        foreach ([
            [PohodaImportRepository::KIND_PURCHASE_INVOICE, ['received', 'commitment'], ['received_advance', 'received_proforma'], 'purchase_invoices', 'advance_purchase_invoice_id'],
            [PohodaImportRepository::KIND_INVOICE, ['issued', 'receivable'], ['issued_advance', 'issued_proforma'], 'invoices', 'parent_invoice_id'],
        ] as [$kind, $finalAgendas, $advanceAgendas, $table, $column]) {
            $byNumber = [];
            $finals = [];
            foreach ($this->map->all($ctx->supplierId, $kind) as $key => $id) {
                [$agenda, $number] = array_pad(explode('|', (string) $key, 3), 2, '');
                if (in_array($agenda, $advanceAgendas, true)) {
                    $byNumber[$number][] = (int) $id;
                } elseif (in_array($agenda, $finalAgendas, true)) {
                    $finals[$agenda . '|' . $number][] = (int) $id;
                }
            }
            if ($byNumber === []) {
                continue;
            }
            $purchase = $table === 'purchase_invoices';
            $taken = $pdo->prepare("SELECT 1 FROM {$table} WHERE supplier_id = ? AND {$column} = ? LIMIT 1");
            $set = $pdo->prepare("UPDATE {$table} SET {$column} = ? WHERE id = ? AND supplier_id = ? AND {$column} IS NULL");
            $current = $pdo->prepare("SELECT {$column} FROM {$table} WHERE id = ? AND supplier_id = ?");
            $deduct = $pdo->prepare(
                'INSERT IGNORE INTO purchase_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, created_by) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($finalAgendas as $agenda) {
                foreach ($ctx->export->records($agenda, 'invoice') as $record) {
                    $number = PohodaXml::text(PohodaXml::get($record, 'invoiceHeader'), 'number/numberRequested');
                    $finalIds = $finals[$agenda . '|' . $number] ?? [];
                    $advances = [];
                    foreach (PohodaXml::all($record, 'invoiceDetail/invoiceAdvancePaymentItem') as $item) {
                        $ref = PohodaXml::text($item, 'sourceDocument/number');
                        if ($ref === '' || count($byNumber[$ref] ?? []) !== 1) {
                            continue;
                        }
                        $vat = PohodaXml::num($item, 'homeCurrency/priceVAT');
                        $amount = abs(abs($vat) >= 0.005 ? PohodaXml::num($item, 'homeCurrency/price') : PohodaXml::num($item, 'homeCurrency/priceSum'));
                        $advances[$ref] = ['id' => $byNumber[$ref][0], 'amount' => round(($advances[$ref]['amount'] ?? 0.0) + $amount, 2)];
                    }
                    if ($advances === [] || count($finalIds) !== 1) {
                        continue;
                    }
                    $finalId = $finalIds[0];
                    $current->execute([$finalId, $ctx->supplierId]);
                    $linked = $current->fetchColumn();
                    if (count($advances) === 1) {
                        $advance = reset($advances);
                        if ($linked !== false && $linked !== null && (int) $linked === $advance['id']) {
                            continue;
                        }
                        $taken->execute([$ctx->supplierId, $advance['id']]);
                        if (($linked === null || $linked === false) && $taken->fetchColumn() === false) {
                            $set->execute([$advance['id'], $finalId, $ctx->supplierId]);
                            if ($set->rowCount() > 0) {
                                $p->count(self::STEP_LINK, 'advance_linked');
                            }
                            continue;
                        }
                    }
                    if (!$purchase) {
                        $p->count(self::STEP_LINK, 'advance_multiple');
                        // Deník se symboly: čerpání doplní {@see inferIssuedAdvanceDeductions()} podle symbolu zálohy.
                        $hasPairing ??= $this->db->pdo()->query('SELECT 1 FROM journal_line_pairing WHERE supplier_id = ' . (int) $ctx->supplierId . ' LIMIT 1')->fetchColumn() !== false;
                        if ($hasPairing) {
                            continue;
                        }
                        $p->warn(self::STEP_LINK, 'advance_multiple', "Doklad {$number} čerpá zálohy " . implode(', ', array_keys($advances)) . ', které MyÚčto u vydané faktury neumí navázat; čerpání zkontrolujte ručně.', ['document_no' => $number]);
                        continue;
                    }
                    foreach ($advances as $advance) {
                        if ((int) $linked === $advance['id'] || $advance['amount'] < 0.01) {
                            continue;
                        }
                        $deduct->execute([$ctx->supplierId, $finalId, $advance['id'], number_format($advance['amount'], 2, '.', ''), $ctx->userOrNull()]);
                        if ($deduct->rowCount() > 0) {
                            $p->count(self::STEP_LINK, 'advance_deductions');
                        }
                    }
                }
            }
        }
    }

    /**
     * Daňový doklad k přijaté platbě (DDKP, v POHODĚ interní doklad) s vazbou na zálohový
     * doklad: dostane rodiče (`parent_purchase_invoice_id`, u vydaných `parent_invoice_id`).
     * DDKP čerpá DPH zálohy ze zálohového účtu; bez vazby ho saldokonto bere jako
     * samostatný a záloha zůstává otevřená o daň.
     */
    private function linkTaxDocumentsToAdvances(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        foreach ([
            [PohodaImportRepository::KIND_PURCHASE_INVOICE, 'receivedAdvanceInvoice', ['received_advance', 'received_proforma'], 'purchase_invoices', 'parent_purchase_invoice_id', 'document_kind'],
            [PohodaImportRepository::KIND_INVOICE, 'issuedAdvanceInvoice', ['issued_advance', 'issued_proforma'], 'invoices', 'parent_invoice_id', 'invoice_type'],
        ] as [$kind, $linkAgenda, $advanceAgendas, $table, $column, $kindColumn]) {
            $advances = [];
            $taxDocuments = [];
            foreach ($this->map->all($ctx->supplierId, $kind) as $key => $id) {
                [$agenda, $number] = array_pad(explode('|', (string) $key, 3), 2, '');
                if (in_array($agenda, $advanceAgendas, true)) {
                    $advances[$number][] = (int) $id;
                } elseif ($agenda === 'internal') {
                    $taxDocuments[$number][] = (int) $id;
                }
            }
            if ($advances === [] || $taxDocuments === []) {
                continue;
            }
            $set = $pdo->prepare("UPDATE {$table} SET {$column} = ? WHERE id = ? AND supplier_id = ? AND {$column} IS NULL AND {$kindColumn} = 'tax_document'");
            $partner = $table === 'purchase_invoices' ? 'vendor_id' : 'client_id';
            $same = $pdo->prepare(
                "SELECT 1 FROM {$table} t JOIN {$table} a ON a.supplier_id = t.supplier_id AND a.id = ?
                  WHERE t.id = ? AND t.supplier_id = ? AND t.{$partner} = a.{$partner} AND ABS(t.total_with_vat - a.total_with_vat) < 0.01"
            );
            foreach ($ctx->export->records('internal', 'intDoc') as $record) {
                $header = PohodaXml::get($record, 'intDocHeader');
                $number = PohodaXml::text($header, 'number/numberRequested');
                if (count($taxDocuments[$number] ?? []) !== 1) {
                    continue;
                }
                $refs = [];
                foreach (PohodaXml::all($record, 'linkedDocuments/link') as $link) {
                    $ref = PohodaXml::text($link, 'sourceDocument/number');
                    if (PohodaXml::text($link, 'sourceAgenda') === $linkAgenda && count($advances[$ref] ?? []) === 1) {
                        $refs[$ref] = $advances[$ref][0];
                    }
                }
                // Export z datového souboru vazbu DDKP na zálohu nenese; POHODA ale DDKP
                // pojmenuje vždy „Daňový doklad k uhrazené zálohové faktuře č. …". Takovou
                // vazbu převod přijme jen u téhož partnera a téže částky jako záloha.
                if ($refs === [] && preg_match('/zálohov\w*\s+faktu\w*\s+č\.?\s*(\S+)/u', PohodaXml::text($header, 'text'), $m) === 1
                    && count($advances[rtrim($m[1], '.,;')] ?? []) === 1) {
                    $candidate = $advances[rtrim($m[1], '.,;')][0];
                    $same->execute([$candidate, $taxDocuments[$number][0], $ctx->supplierId]);
                    if ($same->fetchColumn() !== false) {
                        $refs[$m[1]] = $candidate;
                    }
                }
                if (count($refs) !== 1) {
                    continue;
                }
                $set->execute([reset($refs), $taxDocuments[$number][0], $ctx->supplierId]);
                if ($set->rowCount() > 0) {
                    $p->count(self::STEP_LINK, 'tax_document_advance_linked');
                }
            }
        }
    }

    public function matchPayments(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT);
        $insertMatch = $pdo->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
             VALUES (?, ?, ?, ?, ?, "manual", ?)'
        );
        $markTx = $pdo->prepare(
            "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                SET t.match_status = 'manual', t.matched_at = NOW(), t.matched_by = ?, t.matched_invoice_id = COALESCE(?, t.matched_invoice_id)
              WHERE t.id = ? AND s.supplier_id = ?"
        );
        $sameMatch = $pdo->prepare(
            'SELECT id FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ? AND invoice_id <=> ? AND purchase_invoice_id <=> ? LIMIT 1'
        );
        $txMatches = $pdo->prepare('SELECT id FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ?');
        // Párování, která založil převod (tento i dřívější běh) - ostatní přidal uživatel.
        $fromPohoda = array_fill_keys(array_map('intval', array_values($existing)), true);
        $start = $ctx->periodStart();
        $cashLink = $pdo->prepare('SELECT invoice_id, purchase_invoice_id FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $cashMulti = [];

        $internal = [];
        foreach ($ctx->liquidations as $l) {
            $mapKey = $l['doc'] . '|' . $l['id'] . '|' . $l['liq'];
            $isIssued = $l['doc'] === 'invoice';
            if ($l['agenda'] === 'internalDocuments' && in_array($l['doc'], ['invoice', 'purchase_invoice'], true)
                && !$ctx->skipsDate($l['date']) && ($l['date'] === null || $l['date'] >= $start)) {
                // Zápočet interním dokladem: vyřeší se po dokladech celého interního dokladu níže.
                $internal[(string) $l['source']][] = $l;
                continue;
            }
            if (isset($existing[$mapKey])) {
                // Dřívější běh úhradu spároval, ale evidovanou platbu vydané faktury nezaložil.
                if ($isIssued && $l['agenda'] === 'bank') {
                    $txOf = $pdo->prepare('SELECT bank_transaction_id FROM payment_matches WHERE id = ? AND supplier_id = ? AND invoice_id = ?');
                    $txOf->execute([(int) $existing[$mapKey], $ctx->supplierId, $l['id']]);
                    $txId = $txOf->fetchColumn();
                    if ($txId !== false) {
                        $this->recordIssuedPayment($ctx, $l, 'bank', (int) $txId, null);
                    }
                } elseif ($isIssued && $l['agenda'] === 'voucher') {
                    $this->recordIssuedPayment($ctx, $l, 'cash', null, (int) $existing[$mapKey]);
                }
                $p->count(self::STEP_PAYMENTS, 'existing');
                continue;
            }
            if ($ctx->skipsDate($l['date'])) {
                // Úhrada v roce, který se nepřevádí - pohyb v převodu není, doklad zůstává neuhrazený.
                $p->count(self::STEP_PAYMENTS, 'later_year_skipped');
                continue;
            }
            if ($l['date'] !== null && $l['date'] < $start) {
                // Úhrada v minulém roce - pohyb je v agendě minulého roku, ne v tomto exportu.
                $p->count(self::STEP_PAYMENTS, 'previous_period');
                continue;
            }
            if ($l['doc'] === 'other_item') {
                $this->allocateOtherItem($ctx, $l, $mapKey);
                continue;
            }
            if ($l['doc'] === 'tax_advance') {
                $this->matchTaxAdvance($ctx, $l, $mapKey);
                continue;
            }
            if ($l['agenda'] === 'bank') {
                $txId = $this->bankTransactionFor($ctx, $l);
                if ($txId === null) {
                    continue;
                }
                // Úhradu mohl mezi převody spárovat uživatel v MyÚčtu (Přepárovat u výpisu).
                // Stejná dvojice pohyb + doklad se nevkládá podruhé a k ručnímu párování se nepřidává.
                $sameMatch->execute([$ctx->supplierId, $txId, $isIssued ? $l['id'] : null, $isIssued ? null : $l['id']]);
                $already = $sameMatch->fetchColumn();
                if ($already !== false) {
                    $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, (int) $already, $ctx->runId);
                    if ($isIssued) {
                        $this->recordIssuedPayment($ctx, $l, 'bank', $txId, null);
                    }
                    $p->count(self::STEP_PAYMENTS, 'already_matched');
                    continue;
                }
                $txMatches->execute([$ctx->supplierId, $txId]);
                $byUser = array_diff_key(array_fill_keys(array_map('intval', $txMatches->fetchAll(PDO::FETCH_COLUMN)), true), $fromPohoda);
                if ($byUser !== []) {
                    $p->count(self::STEP_PAYMENTS, 'transaction_consumed');
                    $p->warn(self::STEP_PAYMENTS, 'transaction_consumed', "Úhrada {$l['source']} dokladu {$l['number']}: pohyb je v MyÚčtu už spárovaný ručně s jiným dokladem, úhrada nepřevzata.", ['document_no' => $l['number']]);
                    continue;
                }
                $insertMatch->execute([
                    $ctx->supplierId, $txId, $isIssued ? $l['id'] : null, $isIssued ? null : $l['id'],
                    number_format(abs($l['amount']), 2, '.', ''), $ctx->userOrNull(),
                ]);
                $matchId = (int) $pdo->lastInsertId();
                $fromPohoda[$matchId] = true;
                $markTx->execute([$ctx->userOrNull(), $isIssued ? $l['id'] : null, $txId, $ctx->supplierId]);
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, $matchId, $ctx->runId);
                if ($isIssued) {
                    $this->recordIssuedPayment($ctx, $l, 'bank', $txId, null);
                }
                $p->count(self::STEP_PAYMENTS, 'bank');
                continue;
            }
            if ($l['agenda'] === 'voucher') {
                $cashId = $ctx->cashDocuments[$l['source']] ?? null;
                if ($cashId === null) {
                    $p->count(self::STEP_PAYMENTS, 'not_found');
                    $p->warn(self::STEP_PAYMENTS, 'payment_not_found', "Úhrada {$l['source']} dokladu {$l['number']} v převedené pokladně není.", ['document_no' => $l['number']]);
                    continue;
                }
                // Pokladní doklad nese vazbu jen na jeden doklad. Hradí-li víc faktur, zůstane
                // vazba na první a ostatní se nepřepíšou; faktury jsou uhrazené podle Pohody.
                $cashLink->execute([$cashId, $ctx->supplierId]);
                $linked = $cashLink->fetch(\PDO::FETCH_ASSOC) ?: [];
                $current = $isIssued ? ($linked['invoice_id'] ?? null) : ($linked['purchase_invoice_id'] ?? null);
                if (($linked['invoice_id'] ?? null) !== null || ($linked['purchase_invoice_id'] ?? null) !== null) {
                    if ($current === null || (int) $current !== (int) $l['id']) {
                        $cashMulti[$l['source']] = true;
                        $p->count(self::STEP_PAYMENTS, 'cash_multi_invoice');
                    }
                    $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, $cashId, $ctx->runId);
                    if ($isIssued) {
                        $this->recordIssuedPayment($ctx, $l, 'cash', null, (int) $cashId);
                    }
                    $p->count(self::STEP_PAYMENTS, 'cash');
                    continue;
                }
                $pdo->prepare(
                    'UPDATE cash_documents SET ' . ($isIssued ? 'invoice_id' : 'purchase_invoice_id') . ' = ?, purpose = ? WHERE id = ? AND supplier_id = ?'
                )->execute([$l['id'], $isIssued ? 'invoice_payment' : 'purchase_payment', $cashId, $ctx->supplierId]);
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, $cashId, $ctx->runId);
                if ($isIssued) {
                    $this->recordIssuedPayment($ctx, $l, 'cash', null, (int) $cashId);
                }
                $p->count(self::STEP_PAYMENTS, 'cash');
                continue;
            }
            // Zápočet interním dokladem, odpočet zálohy apod. - úhrada bez pohybu peněz.
            $p->count(self::STEP_PAYMENTS, $l['agenda'] !== '' ? 'other_' . $l['agenda'] : 'without_document');
        }
        $this->settleByInternalDocuments($ctx, $internal);
        $this->restoreOtherItemSettlements($ctx);
        $this->inferAdvanceSettlements($ctx);
        $this->inferIssuedAdvanceDeductions($ctx);
        $this->restorePurchaseSettlements($ctx);
        $this->recordBankFxSettlements($ctx);
        $this->settleIssuedRemainders($ctx);
        $this->markBookedWithoutDocument($ctx);
        if ($cashMulti !== []) {
            $p->info(self::STEP_PAYMENTS, 'cash_multi_invoice', count($cashMulti) . ' pokladních dokladů hradí víc faktur; pokladní doklad ukazuje vazbu jen na první, faktury jsou uhrazené podle Pohody.',
                ['documents' => array_slice(array_keys($cashMulti), 0, 50)]);
        }
        $p->finish(self::STEP_PAYMENTS);
    }

    /**
     * Pohyb, kterým POHODA doklad uhradila. Úhrada nese id bankovního dokladu POHODY - to je
     * jednoznačné i tam, kde se čísla dokladů opakují (číselná řada banky se každý rok
     * začíná znovu).
     *
     * @param array<string,mixed> $l
     */
    private function bankTransactionFor(PohodaContext $ctx, array $l): ?int
    {
        $p = $ctx->protocol;
        $byId = ($l['source_id'] ?? '') !== '' ? ($ctx->bankByPohodaId[$l['source_id']] ?? null) : null;
        $candidates = $byId !== null ? [['id' => $byId, 'date' => $l['date']]] : ($ctx->bankTransactions[$l['source']] ?? []);
        if (count($candidates) > 1) {
            $sameDay = array_values(array_filter($candidates, static fn (array $c): bool => $c['date'] === $l['date']));
            $candidates = count($sameDay) === 1 ? $sameDay : $candidates;
        }
        if (count($candidates) !== 1) {
            $p->count(self::STEP_PAYMENTS, $candidates === [] ? 'not_found' : 'ambiguous');
            if ($candidates === []) {
                $p->warn(self::STEP_PAYMENTS, 'payment_not_found', "Úhrada {$l['source']} dokladu {$l['number']} v převedené bance není.", ['document_no' => $l['number']]);
            }
            return null;
        }
        return (int) $candidates[0]['id'];
    }

    /**
     * Evidovaná platba vydané faktury (`invoice_payments`) k úhradě z banky či pokladny.
     * Z ní čte uhrazenou část faktury saldokonto, kontrola úplnosti i uvolnění pohybu;
     * samotné párování (`payment_matches`, vazba pokladního dokladu) ji nenahradí.
     *
     * Uhrazenou částku a stav faktury vede převod podle POHODY, platba je jen nedoplňuje:
     * součet plateb nepřesáhne `paid_total`. Částka úhrady je v POHODĚ v korunách
     * (`Uhrady.KcU`), faktura v cizí měně ji dostane přepočtenou kurzem dokladu.
     * Opakovaný převod platbu nezdvojí (banka: pohyb + faktura, pokladna: doklad + faktura).
     *
     * @param array<string,mixed> $l
     */
    private function recordIssuedPayment(PohodaContext $ctx, array $l, string $kind, ?int $txId, ?int $cashId): void
    {
        $pdo = $this->db->pdo();
        $invoice = $pdo->prepare(
            "SELECT i.id, i.status, i.paid_total, i.exchange_rate, cur.code AS currency,
                    (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS recorded
               FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?"
        );
        $invoice->execute([$l['id'], $ctx->supplierId]);
        $row = $invoice->fetch(PDO::FETCH_ASSOC);
        if ($row === false || in_array((string) $row['status'], ['draft', 'cancelled'], true)) {
            return;
        }
        $note = 'Převzato z POHODY, úhrada ' . $l['source'];
        if ($kind === 'bank') {
            $dup = $pdo->prepare('SELECT 1 FROM invoice_payments WHERE bank_transaction_id = ? AND invoice_id = ?');
            $dup->execute([$txId, $l['id']]);
        } else {
            $dup = $pdo->prepare("SELECT 1 FROM invoice_payments WHERE invoice_id = ? AND source = 'cash' AND note = ?");
            $dup->execute([$l['id'], $note]);
        }
        if ($dup->fetchColumn() !== false) {
            return;
        }
        $rate = $row['exchange_rate'] !== null && (float) $row['exchange_rate'] > 0 ? (float) $row['exchange_rate'] : null;
        $amount = abs((float) $l['amount']);
        if ((string) $row['currency'] !== 'CZK' && $rate !== null) {
            $amount = $amount / $rate;
        }
        $amount = round(min($amount, (float) $row['paid_total'] - (float) $row['recorded']), 2);
        if ($amount < 0.01) {
            return;
        }
        $date = $l['date'] ?? null;
        if ($date === null) {
            $date = $kind === 'bank'
                ? $pdo->query('SELECT DATE(posted_at) FROM bank_transactions WHERE id = ' . (int) $txId)->fetchColumn()
                : $pdo->query('SELECT issue_date FROM cash_documents WHERE id = ' . (int) $cashId)->fetchColumn();
            if (!is_string($date)) {
                return;
            }
        }
        $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, bank_transaction_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ctx->supplierId, $l['id'], $date, number_format($amount, 2, '.', ''), (string) $row['currency'],
            $note, $kind, $kind === 'bank' ? $txId : null, $ctx->userOrNull(),
        ]);
        if ($kind === 'cash') {
            $pdo->prepare('UPDATE cash_documents SET invoice_payment_id = ? WHERE id = ? AND supplier_id = ? AND invoice_id = ? AND invoice_payment_id IS NULL')
                ->execute([(int) $pdo->lastInsertId(), $cashId, $ctx->supplierId, $l['id']]);
        }
        $ctx->protocol->count(self::STEP_PAYMENTS, 'issued_payments_recorded');
    }

    /**
     * Úhrady interním dokladem (zápočet `ZAP…`): peníze netečou, POHODA doklady vyrovná
     * zápisem přes 395 nebo přímo mezi saldokontními účty. Zápis převzal deník, chybí jen
     * vazba, podle které saldokonto a kontrola úhrad (K3) poznají, co zápis vyrovnává:
     *
     *  - faktura a její dobropis v jednom interním dokladu: dobropis dostane vazbu na
     *    opravovanou fakturu a zápočet dobropisu (`credit_note_offsets`) jako po zápočtu
     *    v MyÚčtu, u vydané faktury i evidovanou platbu se zdrojem `credit_note`;
     *  - ostatní doklady (křížový zápočet vydané a přijaté faktury, odpis): zápis
     *    interního dokladu se na doklad naváže jako ruční vyrovnání
     *    ({@see \MyInvoice\Support\Sql\LinkedManualSettlementSql}). Vazba platí jen pro
     *    jediný doklad téhož typu v zápisu. Víc vydaných faktur se rozepíše na řádky zápisu
     *    ({@see allocateInternalSettlement()}), víc přijatých faktur dostane vazbu bez částky,
     *    zůstatek nese `settled_outside_amount`.
     *
     * Stav a uhrazenou částku dokladů vede převod podle POHODY, tady se nic nepřepisuje.
     * Opakovaný převod nic nezdvojí.
     *
     * @param array<string,list<array<string,mixed>>> $internal číslo interního dokladu => úhrady
     */
    private function settleByInternalDocuments(PohodaContext $ctx, array $internal): void
    {
        if ($internal === []) {
            return;
        }
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $index = $this->journalIndex($ctx->supplierId);
        $kindOf = [
            'invoice' => $pdo->prepare('SELECT invoice_type AS kind, parent_invoice_id AS parent, varsymbol AS number FROM invoices WHERE id = ? AND supplier_id = ?'),
            'purchase_invoice' => $pdo->prepare('SELECT document_kind AS kind, parent_purchase_invoice_id AS parent, varsymbol AS number FROM purchase_invoices WHERE id = ? AND supplier_id = ?'),
        ];
        $link = $pdo->prepare('INSERT IGNORE INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($internal as $number => $liquidations) {
            $entries = $index[PohodaJournal::INTERNAL][$number] ?? [];
            if (count($entries) > 1) {
                $date = $liquidations[0]['date'];
                $entries = array_values(array_filter($entries, static fn (array $e): bool => $e['date'] === $date));
            }
            if (count($entries) !== 1) {
                $p->count(self::STEP_PAYMENTS, 'internal_settlement_unresolved');
                $p->warn(self::STEP_PAYMENTS, 'internal_settlement_unresolved', "Zápočet interním dokladem {$number}: zápis v deníku nejde jednoznačně určit, doklady zůstávají k ruční kontrole.", ['document_no' => $number]);
                continue;
            }
            $entryId = (int) $entries[0]['id'];
            $docs = [];
            foreach ($liquidations as $l) {
                $kindOf[$l['doc']]->execute([$l['id'], $ctx->supplierId]);
                $row = $kindOf[$l['doc']]->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    continue;
                }
                $docs[] = ['type' => $l['doc'], 'id' => (int) $l['id'], 'kind' => (string) $row['kind'], 'parent' => $row['parent'],
                    'number' => (string) $row['number'], 'amount' => round(abs((float) $l['amount']), 2), 'date' => $l['date'] ?? $entries[0]['date'], 'liq' => $l];
            }
            $handled = [];
            foreach (['invoice', 'purchase_invoice'] as $type) {
                $invoices = array_values(array_filter($docs, static fn (array $d): bool => $d['type'] === $type && $d['kind'] === 'invoice'));
                $notes = array_values(array_filter($docs, static fn (array $d): bool => $d['type'] === $type && $d['kind'] === 'credit_note'));
                if (count($invoices) !== 1 || $notes === []) {
                    continue;
                }
                foreach ($notes as $note) {
                    if ($this->offsetCreditNote($ctx, $type, $invoices[0], $note)) {
                        $p->count(self::STEP_PAYMENTS, 'internal_credit_note_offset');
                    }
                    $handled[$type . '|' . $note['id']] = true;
                }
                $handled[$type . '|' . $invoices[0]['id']] = true;
            }
            $rest = array_values(array_filter($docs, static fn (array $d): bool => !isset($handled[$d['type'] . '|' . $d['id']])));
            foreach (['invoice', 'purchase_invoice'] as $type) {
                $ofType = array_values(array_filter($rest, static fn (array $d): bool => $d['type'] === $type));
                if ($ofType === []) {
                    continue;
                }
                if (count($ofType) > 1) {
                    if ($type === 'invoice' && $this->allocateInternalSettlement($ctx, $entryId, $ofType)) {
                        $p->count(self::STEP_PAYMENTS, 'internal_settlement_allocated');
                        continue;
                    }
                    // Přijatý doklad drží zůstatek v settled_outside_amount, rozpad by ho uzavřel
                    // podruhé. Vazba na zápis navázaný na víc dokladů se do vyrovnání nepočítá,
                    // jen ukáže, kterým interním dokladem byl doklad uhrazen.
                    if ($type === 'purchase_invoice') {
                        foreach ($ofType as $doc) {
                            $link->execute([$ctx->supplierId, $entryId, $type, $doc['id'], "Úhrada interním dokladem {$number} (převzato z Pohody)", $ctx->userOrNull()]);
                        }
                        $p->count(self::STEP_PAYMENTS, 'internal_settlement_linked_multi');
                        continue;
                    }
                    $p->count(self::STEP_PAYMENTS, 'internal_settlement_unresolved');
                    $p->warn(self::STEP_PAYMENTS, 'internal_settlement_unresolved', "Zápočet interním dokladem {$number} vyrovnává víc dokladů téhož druhu; vazbu na deník doplňte ručně.", ['document_no' => $number]);
                    continue;
                }
                $link->execute([$ctx->supplierId, $entryId, $type, $ofType[0]['id'], "Úhrada interním dokladem {$number} (převzato z Pohody)", $ctx->userOrNull()]);
                if ($link->rowCount() > 0) {
                    $p->count(self::STEP_PAYMENTS, 'internal_settlement_linked');
                }
            }
        }
    }

    /**
     * Zápočet víc vydaných faktur jedním interním dokladem: částky z likvidací POHODY se
     * rozepíšou na kreditní řádky zápisu na 311 (`journal_settlement_allocations`), saldokonto
     * je pak počítá jako každý rozpad zápisu. Řádek se shodnou částkou má přednost, zbytek
     * se plní popořadě. Když řádky na 311 nestačí, nezapíše se nic a doklad zůstane k ruční kontrole.
     *
     * @param list<array<string,mixed>> $docs
     */
    private function allocateInternalSettlement(PohodaContext $ctx, int $entryId, array $docs): bool
    {
        $pdo = $this->db->pdo();
        $query = $pdo->prepare(
            "SELECT l.line_no, l.account_id, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND l.side = 'credit' AND l.is_red_storno = 0 AND a.account_code LIKE '311%'
              ORDER BY l.id"
        );
        $query->execute([$ctx->supplierId, $entryId]);
        $lines = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lines[] = ['line_no' => (int) $row['line_no'], 'account_id' => (int) $row['account_id'], 'free' => (int) round((float) $row['amount'] * 100)];
        }
        $needs = [];
        foreach ($docs as $doc) {
            $needs[$doc['id']] = ($needs[$doc['id']] ?? 0) + (int) round($doc['amount'] * 100);
        }
        if (array_sum($needs) > array_sum(array_column($lines, 'free'))) {
            return false;
        }

        $parts = [];
        foreach ($needs as $docId => $need) {
            foreach ($lines as $i => $line) {
                if ($line['free'] === $need) {
                    $parts[] = [$i, $docId, $need];
                    $lines[$i]['free'] = 0;
                    $needs[$docId] = 0;
                    break;
                }
            }
        }
        foreach ($needs as $docId => $need) {
            foreach ($lines as $i => $line) {
                if ($need === 0) {
                    break;
                }
                $take = min($need, $line['free']);
                if ($take > 0) {
                    $parts[] = [$i, $docId, $take];
                    $lines[$i]['free'] -= $take;
                    $need -= $take;
                }
            }
        }
        $insert = $pdo->prepare(
            "INSERT IGNORE INTO journal_settlement_allocations
                (supplier_id, entry_id, line_no, account_id, side, kind, doc_type, doc_id, amount, created_by)
             VALUES (?, ?, ?, ?, 'credit', 'settlement', 'invoice', ?, ?, ?)"
        );
        foreach ($parts as [$i, $docId, $cents]) {
            $insert->execute([$ctx->supplierId, $entryId, $lines[$i]['line_no'], $lines[$i]['account_id'], $docId, number_format($cents / 100, 2, '.', ''), $ctx->userOrNull()]);
        }
        return true;
    }

    /**
     * Čerpání a vrácení přijatých záloh, které POHODA nevede vazbou, jen zaúčtováním na
     * zálohový účet (314):
     *
     *  - vrácení zálohy: příchozí platba se symbolem zálohy (číslo dodavatele nebo
     *    variabilní symbol), zaúčtovaná na zálohový účet a nespárovaná, se spáruje se
     *    zálohou, je-li taková záloha právě jedna a vrácená částka ji nepřesahuje;
     *  - odpočet obyčejnou položkou (např. „Odečet zálohy" u měsíční faktury nájmu):
     *    kredit zálohového účtu v zápisu konečné faktury bez vazby na zálohu čerpá
     *    zálohy téhož dodavatele od nejstarší, nejvýš do jejich zůstatku
     *    (`purchase_advance_deductions`).
     *
     * Zůstatek zálohy = zaplaceno na zálohový účet (u zálohy počátečních stavů částka
     * bez DPH, které čerpal DDKP minulého roku) − DPH čerpané DDKP − dosavadní čerpání
     * a vrácení. Opakovaný převod nic nezdvojí.
     */
    private function inferAdvanceSettlements(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $s = $ctx->supplierId;
        $advanceAccount = "(ca.account_code LIKE '314%' OR COALESCE(pa.account_code, '') LIKE '314%')";
        $rows = $pdo->query(
            "SELECT a.id, a.vendor_id, a.vendor_invoice_number, a.varsymbol, a.payment_variable_symbol, a.issue_date, a.total_with_vat, a.total_vat,
                    (SELECT COALESCE(SUM(pm.amount), 0) FROM payment_matches pm
                      WHERE pm.supplier_id = a.supplier_id AND pm.purchase_invoice_id = a.id
                        AND EXISTS (SELECT 1 FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id
                                      JOIN chart_of_accounts ca ON ca.id = l.account_id LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                                     WHERE e.supplier_id = a.supplier_id AND e.source_type = 'bank' AND e.source_id = pm.bank_transaction_id
                                       AND e.reversed_by IS NULL AND l.side = 'debit' AND {$advanceAccount})) AS paid,
                    (SELECT COALESCE(SUM(pm.amount), 0) FROM payment_matches pm JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id AND bt.amount > 0
                      WHERE pm.supplier_id = a.supplier_id AND pm.purchase_invoice_id = a.id) AS refunded,
                    EXISTS (SELECT 1 FROM journal_entry_document_links ol JOIN journal_entries oe ON oe.id = ol.entry_id
                             WHERE ol.supplier_id = a.supplier_id AND ol.doc_type = 'purchase_invoice' AND ol.doc_id = a.id
                               AND oe.source_type = 'opening' AND oe.reversed_by IS NULL) AS opening,
                    (SELECT COALESCE(SUM(CASE WHEN ch.document_kind = 'tax_document'
                                              THEN (CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END)
                                              WHEN l.side = 'credit' THEN l.signed_amount ELSE 0 END), 0)
                       FROM purchase_invoices ch JOIN journal_entries e ON e.supplier_id = ch.supplier_id AND e.source_type = 'purchase_invoice' AND e.source_id = ch.id
                       JOIN journal_entry_lines l ON l.entry_id = e.id JOIN chart_of_accounts ca ON ca.id = l.account_id LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                      WHERE ch.supplier_id = a.supplier_id AND e.reversed_by IS NULL AND {$advanceAccount}
                        AND (ch.advance_purchase_invoice_id = a.id
                             OR (ch.document_kind = 'tax_document' AND ch.parent_purchase_invoice_id = a.id AND ch.advance_purchase_invoice_id IS NULL))) AS linked_settled,
                    (SELECT COALESCE(SUM(pad.amount), 0) FROM purchase_advance_deductions pad WHERE pad.advance_invoice_id = a.id) AS deducted
               FROM purchase_invoices a
              WHERE a.supplier_id = {$s} AND a.document_kind = 'advance' AND a.status NOT IN ('draft', 'cancelled')
              ORDER BY a.issue_date, a.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return;
        }
        $advances = [];
        $bySymbol = [];
        foreach ($rows as $r) {
            $paid = (int) $r['opening'] === 1 && (float) $r['paid'] < 0.005
                ? (float) $r['total_with_vat'] - (float) $r['total_vat']
                : (float) $r['paid'];
            $advances[(int) $r['id']] = [
                'vendor' => (int) $r['vendor_id'],
                'left' => round($paid - (float) $r['refunded'] - (float) $r['linked_settled'] - (float) $r['deducted'], 2),
            ];
            foreach ([(string) $r['vendor_invoice_number'], (string) $r['varsymbol'], (string) $r['payment_variable_symbol']] as $symbol) {
                $digits = preg_replace('/\D+/', '', $symbol) ?? '';
                if ($digits !== '') {
                    $bySymbol[$digits][(int) $r['id']] = true;
                }
            }
        }

        // Vrácení zálohy.
        $refunds = $pdo->query(
            "SELECT bt.id, bt.amount, bt.variable_symbol, DATE(bt.posted_at) AS posted
               FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.supplier_id = {$s} AND bt.amount > 0
                AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id)
                AND EXISTS (SELECT 1 FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id
                              JOIN chart_of_accounts ca ON ca.id = l.account_id LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                             WHERE e.supplier_id = {$s} AND e.source_type = 'bank' AND e.source_id = bt.id AND e.reversed_by IS NULL
                               AND l.side = 'credit' AND {$advanceAccount} AND " . TakenOverRecord::journalEntrySql('e') . ')'
        )->fetchAll(PDO::FETCH_ASSOC);
        $match = $pdo->prepare('INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id) VALUES (?, ?, NULL, ?, ?, "manual", ?)');
        foreach ($refunds as $t) {
            $candidates = array_keys($bySymbol[preg_replace('/\D+/', '', (string) $t['variable_symbol']) ?? ''] ?? []);
            if (count($candidates) !== 1 || $advances[$candidates[0]]['left'] + 0.005 < (float) $t['amount']) {
                continue;
            }
            $match->execute([$s, (int) $t['id'], $candidates[0], number_format((float) $t['amount'], 2, '.', ''), $ctx->userOrNull()]);
            $advances[$candidates[0]]['left'] = round($advances[$candidates[0]]['left'] - (float) $t['amount'], 2);
            $p->count(self::STEP_PAYMENTS, 'advance_refund_matched');
        }

        // Čerpání, které POHODA páruje symbolem zálohy na řádku konečné faktury.
        $exact = $pdo->query(
            "SELECT f.id, p.pairing_symbol, SUM(l.signed_amount) AS amount
               FROM purchase_invoices f
               JOIN journal_entries e ON e.supplier_id = f.supplier_id AND e.source_type = 'purchase_invoice' AND e.source_id = f.id AND e.reversed_by IS NULL
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.side = 'credit'
               JOIN journal_line_pairing p ON p.supplier_id = l.supplier_id AND p.entry_id = l.entry_id AND p.line_no = l.line_no
               JOIN chart_of_accounts ca ON ca.id = l.account_id LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
              WHERE f.supplier_id = {$s} AND f.document_kind = 'invoice' AND f.advance_purchase_invoice_id IS NULL
                AND f.status NOT IN ('draft', 'cancelled') AND {$advanceAccount}
                AND NOT EXISTS (SELECT 1 FROM purchase_advance_deductions pad WHERE pad.final_invoice_id = f.id)
              GROUP BY f.id, p.pairing_symbol"
        )->fetchAll(PDO::FETCH_ASSOC);
        $deductExact = $pdo->prepare('INSERT IGNORE INTO purchase_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, created_by) VALUES (?, ?, ?, ?, ?)');
        foreach ($exact as $f) {
            // Víc záloh se stejným symbolem (smlouva na energie) se čerpá od nejstarší.
            $amount = round((float) $f['amount'], 2);
            foreach (array_keys($bySymbol[preg_replace('/\D+/', '', (string) $f['pairing_symbol']) ?? ''] ?? []) as $advanceId) {
                if ($amount < 0.01 || $advances[$advanceId]['left'] < 0.01) {
                    continue;
                }
                $take = round(min($amount, $advances[$advanceId]['left']), 2);
                $deductExact->execute([$s, (int) $f['id'], $advanceId, number_format($take, 2, '.', ''), $ctx->userOrNull()]);
                $advances[$advanceId]['left'] = round($advances[$advanceId]['left'] - $take, 2);
                $amount = round($amount - $take, 2);
                $p->count(self::STEP_PAYMENTS, 'advance_deduction_by_symbol');
            }
        }

        // Odpočet zálohy obyčejnou položkou konečné faktury.
        $finals = $pdo->query(
            "SELECT f.id, f.vendor_id, MIN(e.entry_date) AS entry_date,
                    SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE 0 END) AS credit
               FROM purchase_invoices f
               JOIN journal_entries e ON e.supplier_id = f.supplier_id AND e.source_type = 'purchase_invoice' AND e.source_id = f.id AND e.reversed_by IS NULL
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts ca ON ca.id = l.account_id LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
              WHERE f.supplier_id = {$s} AND f.document_kind = 'invoice' AND f.advance_purchase_invoice_id IS NULL
                AND f.status NOT IN ('draft', 'cancelled') AND {$advanceAccount}
                AND NOT EXISTS (SELECT 1 FROM purchase_advance_deductions pad WHERE pad.final_invoice_id = f.id)
              GROUP BY f.id, f.vendor_id
             HAVING credit > 0.005
              ORDER BY entry_date, f.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $deduct = $pdo->prepare('INSERT IGNORE INTO purchase_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, created_by) VALUES (?, ?, ?, ?, ?)');
        foreach ($finals as $f) {
            $credit = round((float) $f['credit'], 2);
            foreach ($advances as $advanceId => &$advance) {
                if ($credit < 0.01) {
                    break;
                }
                if ($advance['vendor'] !== (int) $f['vendor_id'] || $advance['left'] < 0.01) {
                    continue;
                }
                $take = round(min($credit, $advance['left']), 2);
                $deduct->execute([$s, (int) $f['id'], $advanceId, number_format($take, 2, '.', ''), $ctx->userOrNull()]);
                $advance['left'] = round($advance['left'] - $take, 2);
                $credit = round($credit - $take, 2);
                $p->count(self::STEP_PAYMENTS, 'advance_deduction_inferred');
            }
            unset($advance);
        }
    }

    /**
     * Čerpání přijatých záloh (324) konečnou fakturou bez vazby na zálohu: POHODA ho vede jen
     * řádkem zápisu faktury na zálohový účet. Zrcadlo odpočtu u přijatých záloh
     * ({@see inferAdvanceSettlements()}): faktura čerpá zálohy téhož odběratele od nejstarší,
     * nejvýš do jejich zůstatku (`invoice_advance_deductions`). Opakovaný převod nic nezdvojí.
     */
    private function inferIssuedAdvanceDeductions(PohodaContext $ctx): void
    {
        $pdo = $this->db->pdo();
        $s = $ctx->supplierId;
        $account = "(ca.account_code LIKE '324%' OR COALESCE(pa.account_code, '') LIKE '324%')";
        $advances = [];
        $bySymbol = [];
        foreach ($pdo->query(
            "SELECT a.id, a.client_id, a.varsymbol,
                    (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.supplier_id = a.supplier_id AND ip.invoice_id = a.id) AS paid,
                    (SELECT COALESCE(SUM(l.signed_amount), 0) FROM invoices ch
                       JOIN journal_entries e ON e.supplier_id = ch.supplier_id AND e.source_type = 'invoice' AND e.source_id = ch.id AND e.reversed_by IS NULL
                       JOIN journal_entry_lines l ON l.entry_id = e.id AND l.side = 'debit'
                       JOIN chart_of_accounts ca ON ca.id = l.account_id LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                      WHERE ch.supplier_id = a.supplier_id AND ch.parent_invoice_id = a.id AND {$account}) AS linked,
                    (SELECT COALESCE(SUM(iad.amount), 0) FROM invoice_advance_deductions iad WHERE iad.supplier_id = a.supplier_id AND iad.advance_invoice_id = a.id) AS deducted
               FROM invoices a
              WHERE a.supplier_id = {$s} AND a.invoice_type = 'proforma' AND a.status NOT IN ('draft', 'cancelled')
              ORDER BY a.issue_date, a.id"
        )->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $advances[(int) $r['id']] = ['client' => (int) $r['client_id'], 'left' => round((float) $r['paid'] - (float) $r['linked'] - (float) $r['deducted'], 2)];
            $symbol = preg_replace('/\D+/', '', (string) $r['varsymbol']) ?? '';
            if ($symbol !== '') {
                $bySymbol[$symbol][(int) $r['id']] = true;
            }
        }
        if ($advances === []) {
            return;
        }
        // Čerpání, které POHODA páruje symbolem zálohy na řádku konečné faktury.
        $deduct = $pdo->prepare("INSERT IGNORE INTO invoice_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, source, created_by) VALUES (?, ?, ?, ?, 'import', ?)");
        foreach ($pdo->query(
            "SELECT f.id, p.pairing_symbol, SUM(l.signed_amount) AS amount
               FROM invoices f
               JOIN journal_entries e ON e.supplier_id = f.supplier_id AND e.source_type = 'invoice' AND e.source_id = f.id AND e.reversed_by IS NULL
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.side = 'debit'
               JOIN journal_line_pairing p ON p.supplier_id = l.supplier_id AND p.entry_id = l.entry_id AND p.line_no = l.line_no
               JOIN chart_of_accounts ca ON ca.id = l.account_id LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
              WHERE f.supplier_id = {$s} AND f.invoice_type = 'invoice' AND f.parent_invoice_id IS NULL
                AND f.status NOT IN ('draft', 'cancelled') AND {$account}
                AND NOT EXISTS (SELECT 1 FROM invoice_advance_deductions iad WHERE iad.supplier_id = f.supplier_id AND iad.final_invoice_id = f.id)
              GROUP BY f.id, p.pairing_symbol"
        )->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $amount = round((float) $f['amount'], 2);
            foreach (array_keys($bySymbol[preg_replace('/\D+/', '', (string) $f['pairing_symbol']) ?? ''] ?? []) as $advanceId) {
                if ($amount < 0.01 || $advances[$advanceId]['left'] < 0.01) {
                    continue;
                }
                $take = round(min($amount, $advances[$advanceId]['left']), 2);
                $deduct->execute([$s, (int) $f['id'], $advanceId, number_format($take, 2, '.', ''), $ctx->userOrNull()]);
                $advances[$advanceId]['left'] = round($advances[$advanceId]['left'] - $take, 2);
                $amount = round($amount - $take, 2);
                $ctx->protocol->count(self::STEP_PAYMENTS, 'advance_deduction_by_symbol');
            }
        }
        $finals = $pdo->query(
            "SELECT f.id, f.client_id, MIN(e.entry_date) AS entry_date, SUM(l.signed_amount) AS debit
               FROM invoices f
               JOIN journal_entries e ON e.supplier_id = f.supplier_id AND e.source_type = 'invoice' AND e.source_id = f.id AND e.reversed_by IS NULL
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.side = 'debit'
               JOIN chart_of_accounts ca ON ca.id = l.account_id LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
              WHERE f.supplier_id = {$s} AND f.invoice_type = 'invoice' AND f.parent_invoice_id IS NULL
                AND f.status NOT IN ('draft', 'cancelled') AND {$account}
                AND NOT EXISTS (SELECT 1 FROM invoice_advance_deductions iad WHERE iad.supplier_id = f.supplier_id AND iad.final_invoice_id = f.id)
              GROUP BY f.id, f.client_id
              ORDER BY entry_date, f.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($finals as $f) {
            $debit = round((float) $f['debit'], 2);
            foreach ($advances as $advanceId => &$advance) {
                if ($debit < 0.01) {
                    break;
                }
                if ($advance['client'] !== (int) $f['client_id'] || $advance['left'] < 0.01) {
                    continue;
                }
                $take = round(min($debit, $advance['left']), 2);
                $deduct->execute([$s, (int) $f['id'], $advanceId, number_format($take, 2, '.', ''), $ctx->userOrNull()]);
                $advance['left'] = round($advance['left'] - $take, 2);
                $debit = round($debit - $take, 2);
                $ctx->protocol->count(self::STEP_PAYMENTS, 'advance_deduction_inferred');
            }
            unset($advance);
        }
    }

    /**
     * Vydaná faktura uhrazená podle POHODY, u které evidované platby nedávají uhrazenou částku:
     * úhrada v cizí měně přepočtená kurzem dokladu, zaokrouhlení v zápisu úhrady, nebo úhrada
     * bez dokladu banky či pokladny (tržby z pokladny účtované souhrnně). Zbytek
     * dostane evidovanou platbu se zdrojem `settlement` k datu úhrady, jinak by saldokonto drželo
     * doklad otevřený, i když ho hlavní kniha má uzavřený. Doklad s ručním vyrovnáním v deníku
     * (vazba nebo rozpad zápisu) ten zbytek už nese. Opakovaný převod nic nezdvojí.
     */
    private function settleIssuedRemainders(PohodaContext $ctx): void
    {
        $pdo = $this->db->pdo();
        $s = $ctx->supplierId;
        $rows = $pdo->query(
            "SELECT i.id, cur.code AS currency, DATE(i.paid_at) AS paid_on,
                    ROUND(i.paid_total - COALESCE((SELECT SUM(ip.amount) FROM invoice_payments ip WHERE ip.invoice_id = i.id), 0), 2) AS rest
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = {$s} AND i.status = 'paid' AND i.invoice_type = 'invoice' AND i.paid_at IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM journal_entry_document_links jl
                                  JOIN journal_entries je ON je.id = jl.entry_id AND je.supplier_id = jl.supplier_id AND je.source_type = 'manual'
                                 WHERE jl.supplier_id = i.supplier_id AND jl.doc_type = 'invoice' AND jl.doc_id = i.id)
                AND NOT EXISTS (SELECT 1 FROM journal_settlement_allocations ja WHERE ja.supplier_id = i.supplier_id AND ja.doc_type = 'invoice' AND ja.doc_id = i.id)
             HAVING rest >= 0.01"
        )->fetchAll(PDO::FETCH_ASSOC);
        $insert = $pdo->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
             VALUES (?, ?, ?, ?, ?, 'Převzato z POHODY, dorovnání úhrady', 'settlement', ?)"
        );
        foreach ($rows as $r) {
            $insert->execute([$s, (int) $r['id'], $r['paid_on'], $r['rest'], $r['currency'], $ctx->userOrNull()]);
            $ctx->protocol->count(self::STEP_PAYMENTS, 'issued_payment_remainder');
        }
    }

    /**
     * Kurzový rozdíl nebo poplatek, který POHODA zaúčtovala přímo v zápisu úhrady
     * (např. 321 MD / 663 D vedle 321 MD / 221 D): dorovnává doklad na saldokontním účtu,
     * ale spárovaná částka pohybu ho nenese. Zapíše se jako zápočet proti protiúčtu
     * (`invoice_settlements`, vazba na zápis úhrady), u vydané faktury i s evidovanou
     * platbou se zdrojem `settlement` - stejně jako zápočet v MyÚčtu. Jen u pohybu, který
     * hradí jediný doklad uhrazený podle POHODY; opakovaný převod nic nezdvojí.
     */
    private function recordBankFxSettlements(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $s = $ctx->supplierId;
        $rows = $pdo->query(
            "SELECT pm.bank_transaction_id AS tx, MIN(pm.invoice_id) AS invoice_id, MIN(pm.purchase_invoice_id) AS purchase_id,
                    ABS(MIN(bt.amount)) AS tx_amount, MIN(e.id) AS entry_id, MIN(e.entry_date) AS entry_date
               FROM payment_matches pm
               JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
               JOIN journal_entries e ON e.supplier_id = pm.supplier_id AND e.source_type = 'bank' AND e.source_id = pm.bank_transaction_id
                                     AND e.reversed_by IS NULL AND e.posted_at IS NOT NULL
              WHERE pm.supplier_id = {$s} AND " . TakenOverRecord::journalEntrySql('e') . "
              GROUP BY pm.bank_transaction_id
             HAVING COUNT(DISTINCT COALESCE(pm.invoice_id, 0), COALESCE(pm.purchase_invoice_id, 0)) = 1"
        )->fetchAll(PDO::FETCH_ASSOC);
        $lines = $pdo->prepare(
            "SELECT l.id, l.side, l.signed_amount, ca.account_code, COALESCE(pa.account_code, ca.account_code) AS synthetic, l.account_id
               FROM journal_entry_lines l JOIN chart_of_accounts ca ON ca.id = l.account_id LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
              WHERE l.entry_id = ? ORDER BY l.id"
        );
        $exists = $pdo->prepare('SELECT 1 FROM invoice_settlements WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? AND journal_entry_id = ?');
        $insert = $pdo->prepare(
            "INSERT INTO invoice_settlements (supplier_id, doc_type, doc_id, settled_on, amount, account_id, note, status, journal_entry_id, invoice_payment_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, ?)"
        );
        foreach ($rows as $r) {
            $issued = $r['invoice_id'] !== null;
            $docType = $issued ? 'invoice' : 'purchase_invoice';
            $docId = (int) ($issued ? $r['invoice_id'] : $r['purchase_id']);
            $table = $issued ? 'invoices' : 'purchase_invoices';
            $status = $pdo->query("SELECT status FROM {$table} WHERE id = {$docId} AND supplier_id = {$s}")->fetchColumn();
            if ($status !== 'paid') {
                continue;
            }
            $lines->execute([(int) $r['entry_id']]);
            $entryLines = $lines->fetchAll(PDO::FETCH_ASSOC);
            // Saldokontní strana dokladu: přijatý doklad snižuje debet 321/325, vydaný kredit 311/315.
            $settleSide = $issued ? 'credit' : 'debit';
            $prefixes = $issued ? ['311', '315'] : ['321', '325'];
            $onAccount = array_values(array_filter($entryLines, static fn (array $l): bool =>
                $l['side'] === $settleSide && in_array(substr((string) $l['synthetic'], 0, 3), $prefixes, true)));
            $surplus = round(array_sum(array_map(static fn (array $l): float => (float) $l['signed_amount'], $onAccount)) - (float) $r['tx_amount'], 2);
            if ($surplus < 0.01) {
                continue;
            }
            // Protiúčet rozdílu: řádek opačné strany se stejnou částkou mimo finanční a saldokontní účty.
            $counter = null;
            foreach ($entryLines as $l) {
                $code = substr((string) $l['synthetic'], 0, 3);
                if ($l['side'] !== $settleSide && abs((float) $l['signed_amount'] - $surplus) < 0.01
                    && !in_array($code, ['211', '213', '221', '261'], true) && !in_array($code, $prefixes, true)) {
                    $counter = (int) $l['account_id'];
                    break;
                }
            }
            if ($counter === null) {
                continue;
            }
            $exists->execute([$s, $docType, $docId, (int) $r['entry_id']]);
            if ($exists->fetchColumn() !== false) {
                continue;
            }
            $paymentId = null;
            if ($issued) {
                $inv = $pdo->query("SELECT cur.code, i.paid_total, (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS recorded
                                      FROM invoices i JOIN currencies cur ON cur.id = i.currency_id WHERE i.id = {$docId}")->fetch(PDO::FETCH_ASSOC);
                $pay = round(min($surplus, (float) $inv['paid_total'] - (float) $inv['recorded']), 2);
                if ($pay < 0.01) {
                    continue;
                }
                $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
                               VALUES (?, ?, ?, ?, ?, 'Kurzový rozdíl v úhradě (převzato z Pohody)', 'settlement', ?)")
                    ->execute([$s, $docId, $r['entry_date'], number_format($pay, 2, '.', ''), (string) $inv['code'], $ctx->userOrNull()]);
                $paymentId = (int) $pdo->lastInsertId();
            }
            $insert->execute([$s, $docType, $docId, $r['entry_date'], number_format($surplus, 2, '.', ''), $counter,
                'Kurzový rozdíl nebo poplatek v úhradě (převzato z Pohody)', (int) $r['entry_id'], $paymentId, $ctx->userOrNull()]);
            $p->count(self::STEP_PAYMENTS, 'bank_fx_settlement');
        }
    }

    /**
     * Zápočet dobropisu proti opravované faktuře, kterou POHODA vyrovnala interním dokladem.
     * Stejné zápisy jako {@see \MyInvoice\Service\Invoice\CreditNoteOffsetService}, bez
     * jeho podmínek na zbývající částku: obě strany jsou podle POHODY už uhrazené.
     *
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $note
     */
    private function offsetCreditNote(PohodaContext $ctx, string $type, array $invoice, array $note): bool
    {
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT 1 FROM credit_note_offsets WHERE doc_type = ? AND credit_note_id = ?');
        $exists->execute([$type, $note['id']]);
        if ($exists->fetchColumn() !== false) {
            return false;
        }
        $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
        $parent = $type === 'invoice' ? 'parent_invoice_id' : 'parent_purchase_invoice_id';
        $pdo->prepare("UPDATE {$table} SET {$parent} = ? WHERE id = ? AND supplier_id = ? AND {$parent} IS NULL")
            ->execute([$invoice['id'], $note['id'], $ctx->supplierId]);
        $amount = $note['amount'];
        if ($amount < 0.01) {
            return false;
        }
        $paymentId = null;
        if ($type === 'invoice') {
            $row = $pdo->prepare(
                "SELECT cur.code AS currency, i.paid_total,
                        (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS recorded
                   FROM invoices i JOIN currencies cur ON cur.id = i.currency_id WHERE i.id = ? AND i.supplier_id = ?"
            );
            $row->execute([$invoice['id'], $ctx->supplierId]);
            $inv = $row->fetch(PDO::FETCH_ASSOC);
            $pay = $inv === false ? 0.0 : round(min($amount, (float) $inv['paid_total'] - (float) $inv['recorded']), 2);
            if ($inv !== false && $pay >= 0.01) {
                $pdo->prepare(
                    "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, 'credit_note', ?)"
                )->execute([$ctx->supplierId, $invoice['id'], $note['date'], number_format($pay, 2, '.', ''), (string) $inv['currency'],
                    'Zápočet dobropisu ' . $note['number'] . ' (převzato z Pohody)', $ctx->userOrNull()]);
                $paymentId = (int) $pdo->lastInsertId();
            }
        }
        $pdo->prepare(
            'INSERT INTO credit_note_offsets (supplier_id, doc_type, invoice_id, credit_note_id, amount, offset_on, invoice_payment_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$ctx->supplierId, $type, $invoice['id'], $note['id'], number_format($amount, 2, '.', ''), $note['date'], $paymentId, $ctx->userOrNull()]);
        return true;
    }

    /**
     * Úhrada ostatní pohledávky či závazku: alokace bankovního pohybu nebo pokladního
     * dokladu přes {@see OtherItemService::allocate()}, který ověří volnou částku, směr
     * a zaúčtování platby na saldokontní účet položky. Zápis platby převzala banka či
     * pokladna z deníku POHODY, nic se neúčtuje.
     *
     * @param array<string,mixed> $l
     */
    private function allocateOtherItem(PohodaContext $ctx, array $l, string $mapKey): void
    {
        $p = $ctx->protocol;
        $amount = round(abs((float) $l['amount']), 2);
        if ($l['agenda'] === 'bank') {
            $txId = $this->bankTransactionFor($ctx, $l);
            if ($txId === null) {
                return;
            }
            $input = ['bank_transaction_id' => $txId, 'amount' => $amount];
        } elseif ($l['agenda'] === 'voucher') {
            $cashId = $ctx->cashDocuments[$l['source']] ?? null;
            if ($cashId === null) {
                $p->count(self::STEP_PAYMENTS, 'not_found');
                $p->warn(self::STEP_PAYMENTS, 'payment_not_found', "Úhrada {$l['source']} dokladu {$l['number']} v převedené pokladně není.", ['document_no' => $l['number']]);
                return;
            }
            $input = ['cash_document_id' => (int) $cashId, 'amount' => $amount];
        } else {
            // Zápočet interním dokladem apod. - úhrada bez pohybu peněz, alokace jen z banky či pokladny.
            $p->count(self::STEP_PAYMENTS, 'other_item_' . ($l['agenda'] !== '' ? $l['agenda'] : 'without_document'));
            return;
        }
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT pohoda_other_item_payment');
        try {
            if (isset($input['cash_document_id'])) {
                // Pokladna převzala doklad jako obecný příjem či výdej; vazbu na položku dostává až tady.
                $pdo->prepare("UPDATE cash_documents SET purpose = 'other'
                    WHERE id = ? AND supplier_id = ? AND purpose IN ('sale', 'purchase') AND invoice_id IS NULL AND purchase_invoice_id IS NULL
                      AND NOT EXISTS (SELECT 1 FROM cash_document_vat_lines v WHERE v.cash_document_id = cash_documents.id AND v.vat_amount <> 0)")
                    ->execute([$input['cash_document_id'], $ctx->supplierId]);
            }
            $this->otherItems->allocate($ctx->supplierId, (int) $l['id'], $input, $ctx->userOrNull());
            $pdo->exec('RELEASE SAVEPOINT pohoda_other_item_payment');
        } catch (OtherItemException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_other_item_payment');
            $pdo->exec('RELEASE SAVEPOINT pohoda_other_item_payment');
            $p->count(self::STEP_PAYMENTS, 'other_item_not_allocated');
            $p->warn(self::STEP_PAYMENTS, 'other_item_payment', "Úhradu {$l['source']} dokladu {$l['number']} nešlo přiřadit: {$e->getMessage()}", ['document_no' => $l['number']]);
            return;
        }
        $column = isset($input['bank_transaction_id']) ? 'bank_transaction_id' : 'cash_document_id';
        $allocation = $pdo->prepare("SELECT id FROM other_item_allocations WHERE supplier_id = ? AND other_item_id = ? AND {$column} = ?");
        $allocation->execute([$ctx->supplierId, (int) $l['id'], $input[$column]]);
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, (int) $allocation->fetchColumn(), $ctx->runId);
        $p->count(self::STEP_PAYMENTS, 'other_item_' . ($column === 'bank_transaction_id' ? 'bank' : 'cash'));
    }

    /**
     * Co POHODA vede jako uhrazené a co nepokryla párovaná úhrada z banky či pokladny (zápočet,
     * interní doklad, platba v minulém roce), nese `settled_outside_amount`. Nastavuje se
     * absolutně, opakovaný převod ho nenásobí; víc alokací než zdroj položku neotevře.
     */
    private function restoreOtherItemSettlements(PohodaContext $ctx): void
    {
        $update = $this->db->pdo()->prepare('UPDATE other_items oi
            SET oi.settled_outside_amount = GREATEST(0, LEAST(oi.amount, ?) - COALESCE((SELECT SUM(a.amount)
                FROM other_item_allocations a WHERE a.supplier_id = oi.supplier_id AND a.other_item_id = oi.id
                    AND a.reversed_on IS NULL), 0))
            WHERE oi.supplier_id = ? AND oi.id = ?');
        foreach ($ctx->otherItemPaid as $id => $paid) {
            $update->execute([number_format($paid, 2, '.', ''), $ctx->supplierId, $id]);
            if ($update->rowCount() > 0) {
                $ctx->protocol->count(self::STEP_PAYMENTS, 'other_item_settled_outside');
            }
        }
    }

    /**
     * Úhrada přijaté faktury, kterou POHODA eviduje a převod ji nemá kam navázat (minulý rok,
     * nenavázaný zápočet), jde do `settled_outside_amount`. Nastavuje se absolutně. Rozhodují
     * úhrady POHODY, zůstatek dokladu je jen strop: POHODA ho u cizí měny přepočítává jiným
     * kurzem, takže sám o úhradě nevypovídá. Doklady v cizí měně se nedoplňují, úhrady
     * POHODY jsou v Kč a částku v měně dokladu spolehlivě nenese.
     */
    private function restorePurchaseSettlements(PohodaContext $ctx): void
    {
        $paid = [];
        foreach ($ctx->liquidations as $l) {
            if ($l['doc'] === 'purchase_invoice' && !$ctx->skipsDate($l['date'])) {
                $paid[(int) $l['id']] = ($paid[(int) $l['id']] ?? 0.0) + abs((float) $l['amount']);
            }
        }
        $pdo = $this->db->pdo();
        $settled = PurchaseSettledExpr::settled('pi');
        $update = $pdo->prepare('UPDATE purchase_invoices SET settled_outside_amount = ? WHERE id = ? AND supplier_id = ?');
        foreach (array_chunk(array_keys($paid), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $pdo->prepare("SELECT pi.id, pi.amount_to_pay, pi.settled_outside_amount AS outside, ({$settled}) - pi.settled_outside_amount AS settled
                  FROM purchase_invoices pi JOIN currencies c ON c.id = pi.currency_id AND c.supplier_id = pi.supplier_id
                 WHERE pi.supplier_id = ? AND pi.id IN ({$in}) AND c.code = 'CZK' AND pi.amount_to_pay > 0");
            $rows->execute([$ctx->supplierId, ...$chunk]);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int) $row['id'];
                $toPay = (float) $row['amount_to_pay'];
                $sourcePaid = min($paid[$id], $toPay - max(0.0, $ctx->remaining['purchase_invoice|' . $id] ?? 0.0));
                $outside = round(max(0.0, $sourcePaid - (float) $row['settled']), 2);
                if (abs($outside - (float) $row['outside']) >= 0.005) {
                    $update->execute([number_format($outside, 2, '.', ''), $id, $ctx->supplierId]);
                    $ctx->protocol->count(self::STEP_PAYMENTS, 'purchase_settled_outside');
                }
            }
        }
    }

    /**
     * Úhrada zálohy na daň z příjmů převzaté jako předpis daňového modulu: vazba předpisu na
     * bankovní pohyb (jako po spárování v MyÚčtu). Uhrazenou částku a stav nese předpis už
     * z POHODY; zápis platby převzala banka z deníku, nic se neúčtuje.
     *
     * @param array<string,mixed> $l
     */
    private function matchTaxAdvance(PohodaContext $ctx, array $l, string $mapKey): void
    {
        $p = $ctx->protocol;
        if ($l['agenda'] !== 'bank') {
            $p->count(self::STEP_PAYMENTS, 'tax_advance_' . ($l['agenda'] !== '' ? $l['agenda'] : 'without_document'));
            return;
        }
        $txId = $this->bankTransactionFor($ctx, $l);
        if ($txId === null) {
            return;
        }
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT pohoda_tax_advance_payment');
        try {
            $stmt = $pdo->prepare(
                "UPDATE tax_advance_schedules s
                   JOIN bank_transactions t ON t.id = ?
                    SET s.matched_transaction_id = t.id, s.paid_source = 'bank', s.match_confidence = 'exact',
                        s.paid_on = DATE(t.posted_at)
                  WHERE s.id = ? AND s.supplier_id = ? AND s.status = 'paid' AND s.matched_transaction_id IS NULL"
            );
            $stmt->execute([$txId, (int) $l['id'], $ctx->supplierId]);
            $pdo->exec('RELEASE SAVEPOINT pohoda_tax_advance_payment');
        } catch (\PDOException $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ((string) $e->getCode() !== '23000' && (string) $e->getCode() !== '45000') {
                throw $e;
            }
            $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_tax_advance_payment');
            $pdo->exec('RELEASE SAVEPOINT pohoda_tax_advance_payment');
            $p->count(self::STEP_PAYMENTS, 'tax_advance_not_matched');
            $p->warn(self::STEP_PAYMENTS, 'tax_advance_payment', "Úhradu {$l['source']} zálohy {$l['number']} nešlo navázat, pohyb už je spárovaný jinde.", ['document_no' => $l['number']]);
            return;
        }
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, (int) $l['id'], $ctx->runId);
        $p->count(self::STEP_PAYMENTS, $stmt->rowCount() === 1 ? 'tax_advance_bank' : 'tax_advance_kept');
    }

    /**
     * Doklad zastoupený v počátečních stavech (neuhrazený doklad minulého roku, pokladní
     * doklad počátečního stavu) dostane vazbu na otevírací zápis období. Zápis vlastní mít
     * nesmí a Doúčtování dokladů ho podle té vazby vynechá ({@see OpeningBalanceDocuments}).
     */
    private function isAdvance(int $purchaseInvoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare("SELECT document_kind = 'advance' FROM purchase_invoices WHERE id = ?");
        $stmt->execute([$purchaseInvoiceId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Poskytnuté zálohy minulých let do počátečního stavu 314. POHODA nese zálohu v agendě
     * dál, dokud ji nečerpá vazbou z konečné faktury; zálohu vyúčtovanou fakturou bez vazby
     * (předplatné, pravidelné platby) tak převádí rok co rok, ačkoli na 314 už není. Zálohy
     * se proto navážou na otevírací zápis jen tehdy, když se celé vejdou do počátečního
     * zůstatku 314 (předpis zálohy bez DDKP je základ). Jinak zůstanou vyrovnané a zůstatek
     * 314 v saldokontu ukáže rozdíl bez dokladů; vazba z dřívějšího převodu se zruší.
     *
     * @param list<int> $advanceIds
     */
    private function linkOpeningAdvances(PohodaContext $ctx, array $advanceIds): void
    {
        if ($advanceIds === [] || $ctx->period === null) {
            return;
        }
        $pdo = $this->db->pdo();
        $s = $ctx->supplierId;
        $periodId = (int) $ctx->period['id'];
        $balance = (float) $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
              WHERE e.supplier_id = {$s} AND e.period_id = {$periodId} AND e.source_type = 'opening' AND e.reversed_by IS NULL
                AND (ca.account_code LIKE '314%' OR COALESCE(pa.account_code, '') LIKE '314%')"
        )->fetchColumn();
        $ids = implode(',', array_map('intval', $advanceIds));
        $total = (float) $pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN EXISTS (SELECT 1 FROM purchase_invoices t WHERE t.parent_purchase_invoice_id = a.id)
                                      THEN a.total_with_vat ELSE a.total_with_vat - a.total_vat END), 0)
               FROM purchase_invoices a WHERE a.supplier_id = {$s} AND a.id IN ({$ids})"
        )->fetchColumn();
        if ($total <= $balance + 0.005) {
            foreach ($advanceIds as $id) {
                $this->linkOpening($ctx, 'purchase_invoice', $id);
            }
            return;
        }
        $unlinked = $pdo->exec(
            "DELETE dl FROM journal_entry_document_links dl JOIN journal_entries e ON e.id = dl.entry_id
              WHERE dl.supplier_id = {$s} AND e.source_type = 'opening' AND e.period_id = {$periodId}
                AND dl.doc_type = 'purchase_invoice' AND dl.doc_id IN ({$ids})"
        );
        $ctx->protocol->info(self::STEP_LINK, 'opening_advances_settled', sprintf(
            '%d poskytnutých záloh z minulých let (%s Kč) se do počátečního stavu 314 (%s Kč) nevejde; POHODA je nese bez vazby na konečnou fakturu. V saldokontu se berou jako vyúčtované.',
            count($advanceIds), number_format($total, 2, ',', ' '), number_format($balance, 2, ',', ' '),
        ), ['unlinked' => (int) $unlinked]);
    }

    private function linkOpening(PohodaContext $ctx, string $docType, int $docId): bool
    {
        return $this->entries->linkOpening($ctx->supplierId, $ctx->userOrNull(), $ctx->period !== null ? (int) $ctx->period['id'] : null, $docType, $docId);
    }

    /**
     * Pohyby, které Pohoda zaúčtovala bez dokladu (poplatky, převody mezi vlastními účty,
     * mzdy, odvody, ostatní závazky mimo DPH), jsou vyřízené - v MyÚčtu pro ně faktura
     * není a výpis by jinak trvale svítil jako nedopárovaný. Označí se jako ignorované
     * s poznámkou; uživatel to může u pohybu vrátit. Spárované úhrady se nemění.
     *
     * Pohyb BEZ zápisu v deníku Pohody (předkontace „Nevím", zaúčtuje se později) vyřízený
     * není - typicky je to nezlikvidovaná úhrada faktury. Zůstane nespárovaný, aby ho
     * párování plateb (Přepárovat u výpisu) mohlo přiřadit k faktuře. Takový pohyb, který
     * dřívější převod omylem označil jako vyřízený, se tady vrátí mezi nespárované.
     */
    private function markBookedWithoutDocument(PohodaContext $ctx): void
    {
        $ids = $this->bankBooking($ctx);
        $pdo = $this->db->pdo();
        $marked = $this->entries->markBankBookedWithoutDocument(
            $ctx->supplierId, $ctx->userOrNull(), $ids['booked'], 'pohoda_booked', 'Zaúčtováno v Pohodě bez dokladu (převod z POHODY).',
        );
        $released = 0;
        foreach (array_chunk(array_values(array_unique($ids['unbooked'])), 500) as $chunk) {
            $stmt = $pdo->prepare(
                "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                    SET t.match_status = 'unmatched', t.match_reason = NULL, t.matched_at = NULL, t.matched_by = NULL, t.ignore_note = NULL
                  WHERE s.supplier_id = ? AND t.match_status = 'ignored' AND t.match_reason = 'pohoda_booked'
                    AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = t.id)
                    AND t.id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $stmt->execute(array_merge([$ctx->supplierId], $chunk));
            $released += $stmt->rowCount();
        }
        if ($marked > 0) {
            $ctx->protocol->count(self::STEP_PAYMENTS, 'booked_without_document', $marked);
        }
        if ($released > 0) {
            $ctx->protocol->count(self::STEP_PAYMENTS, 'unbooked_released', $released);
        }
        if ($ids['unbooked'] !== []) {
            $ctx->protocol->info(self::STEP_PAYMENTS, 'unbooked_bank', count($ids['unbooked']) . ' bankovních pohybů nemá v deníku Pohody zápis '
                . '(předkontace „Nevím“). Jednoznačné úhrady faktur mezi nimi převod spároval a zaúčtoval; ostatní zůstávají k párování '
                . '(návrhy a Přepárovat u výpisu) a k zaúčtování (Účetnictví → Doúčtovat doklady).');
        }
    }

    /**
     * Převedené pohyby podle toho, zda je POHODA zaúčtovala (v deníku je zápis banky se
     * stejným číslem dokladu), nebo mají předkontaci „Nevím" a zápis nemají. Jediné místo
     * toho rozhodnutí - řídí se jím vyřízení pohybů bez dokladu i odvozené úhrady
     * ({@see UnbookedBankPayments}).
     *
     * @return array{booked:list<int>,unbooked:list<int>}
     */
    public function bankBooking(PohodaContext $ctx): array
    {
        // Jen deník téže agendy: číselná řada banky se v POHODĚ opakuje po letech a pohyb
        // zaúčtovaný v jiné agendě by jinak vypadal jako zaúčtovaný i tady.
        $booked = $this->journalIndex($ctx->supplierId, $ctx->year(), $ctx->calendar)[PohodaJournal::BANK] ?? [];
        $ids = ['booked' => [], 'unbooked' => []];
        foreach ($ctx->bankTransactions as $number => $txs) {
            foreach ($txs as $tx) {
                $ids[($booked[(string) $number] ?? []) !== [] ? 'booked' : 'unbooked'][] = (int) $tx['id'];
            }
        }
        return ['booked' => array_values(array_unique($ids['booked'])), 'unbooked' => array_values(array_unique($ids['unbooked']))];
    }

    /**
     * Zápisy deníku převedené z Pohody: zdroj → číslo dokladu → zápisy v pořadí data.
     * Staví se z mapy převodu, takže funguje i pro zápisy z dřívějšího běhu.
     *
     * @return array<string,array<string,list<array{date:string,id:int}>>>
     */
    private function journalIndex(int $supplierId, ?int $year = null, ?FiscalCalendar $calendar = null): array
    {
        $index = [];
        $yearStart = $year !== null ? ($calendar ?? FiscalCalendar::calendar())->periodStart($year) : '';
        foreach ($this->map->all($supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY) as $key => $entryId) {
            $parts = explode('|', (string) $key);
            if (count($parts) < 4 || $parts[2] === '') {
                continue; // otevírací zápis "rok|PS"
            }
            // Deník agendy vede i doklady po konci jejího roku, takže zápis roku `$year`
            // mohla přinést už agenda minulého roku.
            if ($year !== null && (int) $parts[0] !== $year
                && !((int) $parts[0] === $year - 1 && ($parts[3] ?? '') >= $yearStart)) {
                continue;
            }
            [, $source, $number, $date] = $parts;
            $index[$source][$number][$date . '|' . str_pad((string) $entryId, 12, '0', STR_PAD_LEFT)] = ['date' => $date, 'id' => $entryId];
        }
        foreach ($index as &$docs) {
            foreach ($docs as &$entries) {
                ksort($entries);
                $entries = array_values($entries);
            }
        }
        return $index;
    }

    /**
     * Doklady, u kterých zápis v deníku nečekáme: koncept k ruční kontrole, proforma
     * a přijatá záloha (Pohoda je neúčtuje).
     *
     * @return array<int,true>
     */
    private function unbookedIds(string $table, int $supplierId): array
    {
        $advance = $table === 'invoices' ? "invoice_type = 'proforma'" : "document_kind = 'advance'";
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND (status = 'draft' OR {$advance})");
        $stmt->execute([$supplierId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }
}
