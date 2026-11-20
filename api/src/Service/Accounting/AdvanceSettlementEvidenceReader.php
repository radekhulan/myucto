<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

final class AdvanceSettlementEvidenceReader
{
    public function __construct(private readonly Connection $db) {}

    public function forFxItem(int $supplierId, array $item, string $asOf, bool $currentRead = false): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Advance evidence requires a caller snapshot transaction.');
        if ($currentRead) (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $type = (string) $item['doc_type'];
        if (!in_array($type, ['invoice', 'purchase_invoice'], true)) throw new \InvalidArgumentException('Invalid advance document type.');
        $direction = $type === 'invoice' ? 'sale' : 'purchase';
        $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
        $link = $type === 'invoice' ? 'parent_invoice_id' : 'advance_purchase_invoice_id';
        $kind = $type === 'invoice' ? 'invoice_type' : 'document_kind';
        $document = $this->rows("SELECT d.*,cur.code AS currency_code FROM {$table} d JOIN currencies cur ON cur.id=d.currency_id WHERE d.supplier_id=? AND d.id=?", [$supplierId, (int) $item['doc_id']], $currentRead)[0] ?? null;
        if ($document === null) throw new \RuntimeException('Advance document is missing.');
        $none = ['complete' => true, 'findings' => [], 'active' => false, 'applied_foreign' => '0.00', 'applied_czk' => '0.00', 'members' => [], 'sources' => []];
        if ((string) $document[$kind] !== 'invoice') return $none;
        $parentId = (int) ($document[$link] ?? 0);
        // Přijatá faktura s víc zálohami (PurchaseAdvanceLinks) hlavičku nemá: s uhrazenou
        // zálohou v hlavičce skončí jako nedoložená (advance_lineage_missing), kurzový rozdíl
        // se pak dopočítat odmítne a nic neodhaduje.
        if ($parentId === 0) {
            return bccomp((string) $document['advance_paid_amount'], '0', 2) === 0 ? $none
                : array_replace($none, ['complete' => false, 'findings' => ['advance_lineage_missing']]);
        }
        $parent = $this->rows("SELECT d.*,cur.code AS currency_code FROM {$table} d JOIN currencies cur ON cur.id=d.currency_id WHERE d.supplier_id=? AND d.id=?", [$supplierId, $parentId], $currentRead)[0] ?? null;
        if ($parent === null || (string) $parent[$kind] !== ($direction === 'sale' ? 'proforma' : 'advance')) {
            return array_replace($none, ['complete' => false, 'findings' => ['advance_parent_unproven']]);
        }
        $children = $this->rows("SELECT id FROM {$table} WHERE supplier_id=? AND {$link}=? AND {$kind}='invoice' AND status NOT IN ('draft','cancelled') ORDER BY id", [$supplierId, $parentId], $currentRead);
        $findings = [];
        if (count($children) !== 1 || (int) $children[0]['id'] !== (int) $document['id']) $findings[] = 'advance_final_membership_unproven';
        if (strtoupper((string) $parent['currency_code']) !== strtoupper((string) $document['currency_code'])) $findings[] = 'advance_document_currency_conflict';
        $rate = (string) $parent['exchange_rate'];
        if (!is_numeric($rate) || bccomp($rate, '0', 12) <= 0) return array_replace($none, ['complete' => false, 'findings' => ['advance_rate_missing']]);
        $receipts = $this->receiptMembers($supplierId, $direction, $parentId, $asOf, $currentRead);
        $sources = [];
        $available = [];
        $availableForeign = [];
        $unitValues = [];
        $seen = [];
        foreach ($receipts as &$receipt) {
            $identity = $receipt['source_type'] . ':' . ($receipt['representation'] ?? 'native') . ':' . $receipt['member_id'];
            if (isset($seen[$identity])) { $findings[] = 'advance_receipt_entry_ambiguous'; continue; }
            $seen[$identity] = true;
            $currency = strtoupper((string) $receipt['currency']);
            $amount = JournalLineAmount::decimal($receipt['amount']);
            if ($receipt['source_type'] === 'cash') {
                if ($currency === strtoupper((string) $parent['currency_code']) && $receipt['amount_foreign'] !== null) $amount = JournalLineAmount::decimal($receipt['amount_foreign']);
                elseif ($currency === 'CZK') $amount = JournalLineAmount::decimal(bcdiv($amount, $rate, 16));
                else $findings[] = 'advance_receipt_currency_unproven';
                if (($direction === 'sale' && $receipt['cash_type'] === 'out') || ($direction === 'purchase' && $receipt['cash_type'] === 'in')) $amount = bcsub('0', $amount, 2);
            } elseif ($currency !== strtoupper((string) $parent['currency_code'])) {
                if ($currency === 'CZK') $amount = JournalLineAmount::decimal(bcdiv($amount, $rate, 16));
                else $findings[] = 'advance_receipt_currency_unproven';
            }
            if ($receipt['source_type'] === 'bank' && $receipt['bank_amount'] !== null
                && (($direction === 'purchase' && bccomp((string) $receipt['bank_amount'], '0', 2) > 0)
                    || ($direction === 'sale' && bccomp((string) $receipt['bank_amount'], '0', 2) < 0))) $amount = bcsub('0', $amount, 2);
            $receipt['document_amount'] = $amount;
            $allocation = JournalLineAmount::decimal(bcmul($amount, $rate, 16));
            $entryId = (int) $receipt['entry_id'];
            $sources[$entryId] ??= $this->entryLines($supplierId, $entryId, $currentRead);
            $candidates = [];
            $refund = bccomp($allocation, '0', 2) < 0;
            $receiptSide = ($direction === 'sale') !== $refund ? 'credit' : 'debit';
            foreach ($sources[$entryId] as $line) {
                if (!in_array($line['account_type'], ['asset', 'liability', 'equity'], true)) continue;
                if ($line['side'] !== $receiptSide) continue;
                $accountId = (int) $line['account_id'];
                $candidates[$accountId] = bcadd($candidates[$accountId] ?? '0.00', (string) $line['signed_amount'], 2);
            }
            if (count($candidates) !== 1) { $findings[] = 'advance_receipt_account_unproven'; continue; }
            $accountId = (int) array_key_first($candidates);
            $share = '1';
            if ($receipt['source_type'] === 'bank') {
                $pool = $this->bankAllocation($supplierId, $direction, $parentId, $receipt, $parent, $currentRead);
                array_push($findings, ...$pool['findings']);
                $receipt['pool'] = $pool;
                $share = $pool['share'];
            }
            $exactAllocation = bcmul($candidates[$accountId], $share, 16);
            $allocation = JournalLineAmount::decimal($exactAllocation);
            if (bccomp($exactAllocation, $allocation, 12) !== 0) $findings[] = 'advance_receipt_allocation_rounding_unproven';
            if ($refund) $allocation = bcsub('0', $allocation, 2);
            $availableForeign[$accountId] = bcadd($availableForeign[$accountId] ?? '0.00', $amount, 2);
            if (bccomp($amount, '0', 2) !== 0) $unitValues[$accountId][] = [$allocation, $amount];
            $available[$accountId] = bcadd($available[$accountId] ?? '0.00', $allocation, 2);
            $receipt['account_id'] = $accountId;
            $receipt['allocated_czk'] = $allocation;
        }
        unset($receipt);
        foreach ($available as $amount) if (bccomp($amount, '0', 2) < 0) $findings[] = 'advance_refund_exceeds_receipts';
        $taxChildren = $direction === 'sale'
            ? $this->rows("SELECT d.id,d.exchange_rate,cur.code AS currency_code FROM invoices d JOIN currencies cur ON cur.id=d.currency_id WHERE d.supplier_id=? AND d.invoice_type='tax_document' AND (d.parent_invoice_id=? OR EXISTS (SELECT 1 FROM invoice_payments p WHERE p.supplier_id=d.supplier_id AND p.invoice_id=? AND p.tax_document_invoice_id=d.id" . ($currentRead ? ' FOR UPDATE' : '') . ')) ORDER BY d.id', [$supplierId, $parentId, $parentId], $currentRead)
            : $this->rows("SELECT d.id,d.exchange_rate,cur.code AS currency_code FROM purchase_invoices d JOIN currencies cur ON cur.id=d.currency_id WHERE d.supplier_id=? AND d.document_kind='tax_document' AND d.parent_purchase_invoice_id=? ORDER BY d.id", [$supplierId, $parentId], $currentRead);
        foreach ($taxChildren as $taxChild) {
            $taxEntries = $this->rows('SELECT e.id FROM journal_entries e LEFT JOIN journal_entries rev ON rev.id=e.reversed_by AND rev.supplier_id=e.supplier_id WHERE e.supplier_id=? AND e.source_type=? AND e.source_id=? AND e.posted_at IS NOT NULL AND e.entry_date<=? AND (e.reversed_by IS NULL OR rev.entry_date>?) ORDER BY e.id', [$supplierId, $type, (int) $taxChild['id'], $asOf, $asOf], $currentRead);
            foreach ($taxEntries as $taxEntry) {
                $entryId = (int) $taxEntry['id'];
                $sources[$entryId] = $this->entryLines($supplierId, $entryId, $currentRead);
                foreach ($sources[$entryId] as $line) {
                    $counterId = (int) $line['account_id'];
                    if (!isset($available[$counterId])) continue;
                    $amount = $line['side'] === ($direction === 'sale' ? 'debit' : 'credit') ? (string) $line['signed_amount'] : bcsub('0', (string) $line['signed_amount'], 2);
                    $available[$counterId] = bcsub($available[$counterId], $amount, 2);
                    $taxRate = (string) $taxChild['exchange_rate'];
                    if (strtoupper((string) $taxChild['currency_code']) !== strtoupper((string) $parent['currency_code']) || bccomp($taxRate, '0', 12) <= 0) {
                        $findings[] = 'advance_tax_currency_unproven';
                        continue;
                    }
                    $taxForeign = JournalLineAmount::decimal(bcdiv($amount, $taxRate, 16));
                    if (bccomp(JournalLineAmount::decimal(bcmul($taxForeign, $taxRate, 16)), $amount, 2) !== 0) $findings[] = 'advance_tax_valuation_unproven';
                    foreach (['-0.01', '0.01'] as $adjacent) {
                        if (bccomp(JournalLineAmount::decimal(bcmul(bcadd($taxForeign, $adjacent, 2), $taxRate, 16)), $amount, 2) === 0) $findings[] = 'advance_tax_valuation_unproven';
                    }
                    $availableForeign[$counterId] = bcsub($availableForeign[$counterId], $taxForeign, 2);
                }
            }
        }
        $entries = $this->rows('SELECT e.id FROM journal_entries e LEFT JOIN journal_entries rev ON rev.id=e.reversed_by AND rev.supplier_id=e.supplier_id WHERE e.supplier_id=? AND e.source_type=? AND e.source_id=? AND e.posted_at IS NOT NULL AND e.entry_date<=? AND (e.reversed_by IS NULL OR rev.entry_date>?) ORDER BY e.id', [$supplierId, $type, (int) $document['id'], $asOf, $asOf], $currentRead);
        $originals = [];
        $settlement = '0.00';
        $counter = [];
        $accountId = (int) $item['account_id'];
        foreach ($entries as $entry) {
            $entryId = (int) $entry['id'];
            $sources[$entryId] = $this->entryLines($supplierId, $entryId, $currentRead);
            foreach ($sources[$entryId] as $line) {
                if ((int) $line['account_id'] === $accountId) {
                    if ($line['currency_code'] === $item['currency_code']) $originals[] = $line;
                    elseif ($line['currency_code'] === null && $line['side'] === ($direction === 'sale' ? 'credit' : 'debit')) $settlement = bcadd($settlement, (string) $line['signed_amount'], 2);
                } elseif (isset($available[(int) $line['account_id']]) && $line['side'] === ($direction === 'sale' ? 'debit' : 'credit')) {
                    $counter[(int) $line['account_id']] = bcadd($counter[(int) $line['account_id']] ?? '0.00', (string) $line['signed_amount'], 2);
                }
            }
        }
        if (count($originals) !== 1) $findings[] = 'advance_original_allocation_unproven';
        $counterTotal = '0.00';
        foreach ($counter as $counterId => $amount) {
            if (bccomp($amount, $available[$counterId], 2) > 0) $findings[] = 'advance_settlement_exceeds_receipts';
            $counterTotal = bcadd($counterTotal, $amount, 2);
        }
        if (bccomp($settlement, $counterTotal, 2) !== 0) $findings[] = 'advance_settlement_pair_unproven';
        $applied = bcadd($settlement, $available[$accountId] ?? '0.00', 2);
        $foreign = $availableForeign[$accountId] ?? '0.00';
        foreach ($counter as $counterId => $amount) {
            $poolCzk = $available[$counterId];
            $poolForeign = $availableForeign[$counterId];
            if (bccomp($amount, $poolCzk, 2) === 0) {
                $foreign = bcadd($foreign, $poolForeign, 2);
                continue;
            }
            if (bccomp($poolCzk, '0', 2) <= 0) { $findings[] = 'advance_settlement_valuation_unproven'; continue; }
            $values = $unitValues[$counterId];
            [$firstCzk, $firstForeign] = $values[0];
            foreach ($values as [$czk, $fx]) {
                if (bccomp(bcmul($czk, $firstForeign, 4), bcmul($firstCzk, $fx, 4), 4) !== 0) $findings[] = 'advance_settlement_valuation_unproven';
            }
            if ($taxChildren !== []) $findings[] = 'advance_partial_tax_settlement_unproven';
            $foreign = bcadd($foreign, JournalLineAmount::decimal(bcdiv(bcmul($amount, $poolForeign, 16), $poolCzk, 16)), 2);
        }
        if (bccomp($foreign, JournalLineAmount::decimal($item['amount_foreign']), 2) > 0 || bccomp($foreign, '0', 2) < 0) $findings[] = 'advance_settlement_amount_conflict';
        return ['complete' => $findings === [], 'findings' => array_values(array_unique($findings)), 'active' => true, 'parent_id' => $parentId,
            'applied_foreign' => $foreign, 'applied_czk' => $applied, 'members' => $receipts, 'sources' => $sources];
    }

    private function bankAllocation(int $supplierId, string $direction, int $parentId, array $receipt, array $parent, bool $currentRead): array
    {
        $bankId = (int) $receipt['source_id'];
        $bank = $this->rows("SELECT bt.amount,UPPER(COALESCE(NULLIF(bt.currency,''),bs.currency)) AS currency FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE bs.supplier_id=? AND bt.id=?", [$supplierId, $bankId], $currentRead)[0] ?? null;
        if ($bank === null || bccomp((string) $bank['amount'], '0', 2) === 0) return ['share' => '0', 'findings' => ['advance_receipt_bank_missing'], 'members' => []];
        $capacity = JournalLineAmount::decimal($bank['amount']);
        if (bccomp($capacity, '0', 2) < 0) $capacity = bcsub('0', $capacity, 2);
        $native = $this->rows('SELECT id,invoice_id,amount,currency FROM invoice_payments WHERE supplier_id=? AND bank_transaction_id=? ORDER BY id', [$supplierId, $bankId], $currentRead);
        $legacy = $this->rows('SELECT id,invoice_id,purchase_invoice_id,amount FROM payment_matches WHERE supplier_id=? AND bank_transaction_id=? ORDER BY id', [$supplierId, $bankId], $currentRead);
        $findings = [];
        $nativeByInvoice = [];
        $claims = [];
        foreach ($native as $member) $nativeByInvoice[(int) $member['invoice_id']][] = $member;
        $legacyByInvoice = [];
        foreach ($legacy as $member) {
            if ($member['invoice_id'] !== null) $legacyByInvoice[(int) $member['invoice_id']] = bcadd($legacyByInvoice[(int) $member['invoice_id']] ?? '0.00', (string) $member['amount'], 2);
        }
        foreach ($nativeByInvoice as $invoiceId => $members) {
            $amount = '0.00';
            $documentAmount = '0.00';
            foreach ($members as $member) $documentAmount = bcadd($documentAmount, (string) $member['amount'], 2);
            $currencies = array_unique(array_map(static fn (array $r): string => strtoupper((string) $r['currency']), $members));
            if (count($currencies) !== 1) $findings[] = 'advance_receipt_currency_unproven';
            if ($currencies === [(string) $bank['currency']]) {
                $amount = $documentAmount;
                if (isset($legacyByInvoice[$invoiceId]) && bccomp($amount, $legacyByInvoice[$invoiceId], 2) !== 0) $findings[] = 'advance_receipt_representation_conflict';
            } elseif (isset($legacyByInvoice[$invoiceId])) {
                $amount = $legacyByInvoice[$invoiceId];
                $doc = $this->rows('SELECT d.exchange_rate,c.code FROM invoices d JOIN currencies c ON c.id=d.currency_id WHERE d.supplier_id=? AND d.id=?', [$supplierId, $invoiceId], $currentRead)[0];
                if (!\MyInvoice\Service\Bank\FxPaymentSettlement::isFullCzkSettlement((float) $amount, (float) $documentAmount, (string) $doc['code'], (float) $doc['exchange_rate'], (string) $bank['currency'])) $findings[] = 'advance_receipt_representation_conflict';
            } elseif (count($native) === 1 && $legacy === [] && $invoiceId === $parentId && $direction === 'sale'
                && \MyInvoice\Service\Bank\FxPaymentSettlement::isFullCzkSettlement((float) $capacity, (float) $documentAmount, (string) $parent['currency_code'], (float) $parent['exchange_rate'], (string) $bank['currency'])) {
                $amount = $capacity;
            } else {
                $findings[] = 'advance_receipt_bank_allocation_unproven';
            }
            $claims['invoice:' . $invoiceId] = $amount;
        }
        foreach ($legacy as $member) {
            if ($member['invoice_id'] !== null && isset($nativeByInvoice[(int) $member['invoice_id']])) continue;
            $key = $member['invoice_id'] !== null ? 'invoice:' . $member['invoice_id'] : 'purchase_invoice:' . $member['purchase_invoice_id'];
            $claims[$key] = bcadd($claims[$key] ?? '0.00', (string) $member['amount'], 2);
        }
        $total = '0.00';
        foreach ($claims as $claim) {
            if (bccomp($claim, '0', 2) < 0) $findings[] = 'advance_receipt_pool_conflict';
            $total = bcadd($total, $claim, 2);
        }
        if (bccomp($total, $capacity, 2) > 0) $findings[] = 'advance_receipt_pool_conflict';
        $key = ($direction === 'sale' ? 'invoice:' : 'purchase_invoice:') . $parentId;
        $own = $claims[$key] ?? '0.00';
        if ($direction === 'sale' && isset($nativeByInvoice[$parentId]) && count($nativeByInvoice[$parentId]) > 1) {
            $sum = '0.00';
            foreach ($nativeByInvoice[$parentId] as $member) $sum = bcadd($sum, (string) $member['amount'], 2);
            $own = bcmul($own, bcdiv((string) $receipt['amount'], $sum, 16), 16);
        } elseif ($direction === 'purchase' || ($receipt['representation'] ?? '') === 'payment_match') {
            $own = (string) $receipt['amount'];
        }
        return ['share' => bcdiv($own, $capacity, 16), 'findings' => array_values(array_unique($findings)), 'capacity' => $capacity, 'claims' => $claims, 'members' => ['native' => $native, 'legacy' => $legacy]];
    }

    private function receiptMembers(int $supplierId, string $direction, int $parentId, string $asOf, bool $currentRead): array
    {
        $bank = $direction === 'sale'
            ? "ip.id AS member_id,ip.amount,ip.currency,ip.bank_transaction_id AS source_id,NULL AS bank_amount"
            : "pm.id AS member_id,pm.amount,UPPER(COALESCE(NULLIF(bt.currency,''),NULLIF(bs.currency,''),'CZK')) AS currency,pm.bank_transaction_id AS source_id,bt.amount AS bank_amount";
        $out = [];
        foreach (['bank' => $bank, 'cash' => "cd.id AS member_id,cd.total_amount AS amount,cd.currency_code AS currency,cd.id AS source_id,NULL AS bank_amount,cd.amount_foreign,cd.doc_type AS cash_type"] as $source => $select) {
            $sql = $this->receiptSql($direction, $source, $select . ',je.id AS entry_id,je.entry_date', $asOf, $currentRead);
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$supplierId, $parentId, $asOf, $asOf]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[] = ['source_type' => $source, ...$row];
        }
        if ($direction === 'sale') {
            $lock = $currentRead ? ' FOR UPDATE' : '';
            $legacy = $this->rows("SELECT pm.id AS member_id,pm.amount,UPPER(COALESCE(NULLIF(bt.currency,''),bs.currency)) AS currency,pm.bank_transaction_id AS source_id,bt.amount AS bank_amount,je.id AS entry_id,je.entry_date
                FROM payment_matches pm JOIN bank_transactions bt ON bt.id=pm.bank_transaction_id JOIN bank_statements bs ON bs.id=bt.statement_id AND bs.supplier_id=pm.supplier_id
                JOIN journal_entries je ON je.supplier_id=pm.supplier_id AND je.source_type='bank' AND je.source_id=pm.bank_transaction_id
                LEFT JOIN journal_entries rev ON rev.id=je.reversed_by AND rev.supplier_id=je.supplier_id
                WHERE pm.supplier_id=? AND pm.invoice_id=? AND je.posted_at IS NOT NULL AND je.entry_date<=? AND (je.reversed_by IS NULL OR rev.entry_date>?)
                AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.supplier_id=pm.supplier_id AND ip.invoice_id=pm.invoice_id AND ip.bank_transaction_id=pm.bank_transaction_id{$lock}) ORDER BY pm.id,je.id", [$supplierId, $parentId, $asOf, $asOf], $currentRead);
            foreach ($legacy as $row) $out[] = ['source_type' => 'bank', 'representation' => 'payment_match', ...$row];
        }
        return $out;
    }

    private function entryLines(int $supplierId, int $entryId, bool $currentRead): array
    {
        return $this->rows('SELECT l.id,l.account_id,l.side,l.signed_amount,l.signed_amount_foreign,l.currency_code,a.account_type FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE l.supplier_id=? AND l.entry_id=? ORDER BY l.line_no,l.id', [$supplierId, $entryId], $currentRead);
    }

    private function rows(string $sql, array $params, bool $currentRead): array
    {
        $stmt = $this->db->pdo()->prepare($sql . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function postedAdvanceReceived(int $supplierId, int $proformaId): float
    {
        $bank = $this->scalarFloat(
            $this->receiptSql('sale', 'bank', 'COALESCE(SUM(ip.amount), 0)'),
            [$supplierId, $proformaId],
        );
        $cash = $this->scalarFloat(
            $this->receiptSql('sale', 'cash', 'COALESCE(SUM(cd.total_amount), 0)'),
            [$supplierId, $proformaId],
        );
        return round($bank + $cash, 2);
    }

    /**
     * Přijatá záloha převzatá z počátečních stavů: proforma navázaná na živý zaúčtovaný
     * otevírací zápis ({@see \MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments}),
     * uhrazená ještě v předchozím systému. Úhrada je evidovaná jen v `invoice_payments`
     * (ruční, označení zaplacení, převod), ne bankou ani pokladnou, a s datem PŘED datem
     * otevíracího zápisu. Zůstatek takové zálohy nese otevírací zápis na 324, ne bankovní
     * zápis MyÚčta. Pozdější ruční úhrada se nepočítá: po otevření knih musí úhradu
     * zálohy zaúčtovat banka nebo pokladna ({@see postedAdvanceReceived()}).
     *
     * Částka je v měně proformy, stejně jako u {@see postedAdvanceReceived()}.
     */
    public function openingAdvanceReceived(int $supplierId, int $proformaId): float
    {
        return round($this->scalarFloat(
            "SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip
               JOIN invoices p ON p.id = ip.invoice_id AND p.supplier_id = ip.supplier_id AND p.invoice_type = 'proforma'
              WHERE ip.supplier_id = ? AND ip.invoice_id = ? AND " . self::openingReceiptSql('ip', 'p'),
            [$supplierId, $proformaId],
        ), 2);
    }

    /**
     * Přijatá záloha, kterou může zúčtovat konečná faktura: úhrady zaúčtované bankou
     * nebo pokladnou ({@see postedAdvanceReceived()}) a úhrady převzaté z počátečních
     * stavů ({@see openingAdvanceReceived()}). V měně proformy.
     */
    public function advanceReceived(int $supplierId, int $proformaId): float
    {
        return round($this->postedAdvanceReceived($supplierId, $proformaId) + $this->openingAdvanceReceived($supplierId, $proformaId), 2);
    }

    /**
     * SQL podmínka „úhrada `$payment` (alias `invoice_payments`) proformy `$proforma` je
     * převzatá z počátečních stavů" ({@see openingAdvanceReceived()}). `$asOf` = SQL výraz
     * rozvahového dne (saldokonto); otevírací zápis po něm se nepočítá.
     */
    public static function openingReceiptSql(string $payment, string $proforma, ?string $asOf = null): string
    {
        foreach ([$payment, $proforma] as $alias) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
                throw new \InvalidArgumentException('Neplatný alias.');
            }
        }
        return "{$payment}.bank_transaction_id IS NULL AND {$payment}.source IN ('manual', 'mark_paid', 'legacy')
                AND NOT EXISTS (SELECT 1 FROM cash_documents orc
                                 WHERE orc.supplier_id = {$payment}.supplier_id AND orc.invoice_payment_id = {$payment}.id)
                AND EXISTS (SELECT 1 FROM journal_entry_document_links orl
                              JOIN journal_entries ore ON ore.id = orl.entry_id AND ore.supplier_id = orl.supplier_id
                             WHERE orl.supplier_id = {$proforma}.supplier_id AND orl.doc_type = 'invoice' AND orl.doc_id = {$proforma}.id
                               AND ore.source_type = 'opening' AND ore.posted_at IS NOT NULL AND ore.reversed_by IS NULL
                               AND {$payment}.paid_on < ore.entry_date"
            . ($asOf !== null ? " AND ore.entry_date <= {$asOf}" : '') . ')';
    }

    public function postedAdvancePaid(int $supplierId, array $advance, \Closure $rateResolver): float
    {
        $advanceId = (int) $advance['id'];
        $advanceCurrency = strtoupper((string) ($advance['currency_code'] ?? 'CZK'));
        $txCurrency = "UPPER(COALESCE(NULLIF(bt.currency, ''), NULLIF(bs.currency, ''), 'CZK'))";
        $stmt = $this->db->pdo()->prepare(
            $this->receiptSql('purchase', 'bank', "{$txCurrency} AS currency, COALESCE(SUM(pm.amount), 0) AS amount") . " GROUP BY {$txCurrency} UNION ALL "
            . $this->receiptSql('purchase', 'cash', "UPPER(COALESCE(NULLIF(cd.currency_code, ''), 'CZK')), COALESCE(SUM(cd.total_amount), 0)")
            . " GROUP BY UPPER(COALESCE(NULLIF(cd.currency_code, ''), 'CZK'))"
        );
        $stmt->execute([$supplierId, $advanceId, $supplierId, $advanceId]);

        $czk = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amount = (float) $row['amount'];
            if ((int) round($amount * 100) === 0) {
                continue;
            }
            $currency = (string) $row['currency'];
            if ($currency === 'CZK') {
                $czk += $amount;
            } elseif ($currency === $advanceCurrency) {
                $czk += round($amount * $rateResolver($advance), 2);
            } else {
                throw new PostingException(
                    'advance_settlement_ambiguous',
                    'Úhrada poskytnuté zálohy #' . $advanceId . ' je v měně ' . $currency . ', jiné než měna zálohy ('
                        . $advanceCurrency . ') i koruna — zúčtování zálohy nejde ocenit automaticky, zaúčtuj ho ručně.',
                );
            }
        }
        return round($czk, 2);
    }

    private function receiptSql(string $direction, string $source, string $select, ?string $asOf = null, bool $currentRead = false): string
    {
        if (!in_array($direction, ['sale', 'purchase'], true) || !in_array($source, ['bank', 'cash'], true)) throw new \InvalidArgumentException('Invalid advance receipt projection.');
        if ($source === 'cash') {
            $from = 'cash_documents cd';
            $alias = 'cd';
            $sourceId = 'cd.id';
            $documentId = $direction === 'sale' ? 'cd.invoice_id' : 'cd.purchase_invoice_id';
        } elseif ($direction === 'sale') {
            $from = 'invoice_payments ip';
            $alias = 'ip';
            $sourceId = 'ip.bank_transaction_id';
            $documentId = 'ip.invoice_id';
        } else {
            $from = 'payment_matches pm JOIN bank_transactions bt ON bt.id=pm.bank_transaction_id JOIN bank_statements bs ON bs.id=bt.statement_id';
            $alias = 'pm';
            $sourceId = 'pm.bank_transaction_id';
            $documentId = 'pm.purchase_invoice_id';
        }
        $reversal = $asOf === null ? '' : ' LEFT JOIN journal_entries rev ON rev.id=je.reversed_by AND rev.supplier_id=je.supplier_id';
        $live = $asOf === null ? 'je.reversed_by IS NULL' : 'je.posted_at IS NOT NULL AND je.entry_date<=? AND (je.reversed_by IS NULL OR rev.entry_date>?)';
        return "SELECT {$select} FROM {$from} JOIN journal_entries je ON je.supplier_id={$alias}.supplier_id AND je.source_type='{$source}' AND je.source_id={$sourceId}{$reversal} WHERE {$alias}.supplier_id=? AND {$documentId}=? AND {$live}"
            . ($currentRead ? ' FOR UPDATE' : '');
    }

    private function scalarFloat(string $sql, array $params): float
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }
}
