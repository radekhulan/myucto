<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use PDO;

/**
 * Normalizované shrnutí ZDROJOVÉHO DOKLADU účetního zápisu pro náhledový drawer
 * v deníku (read-only).
 *
 * Klíčem je VŽDY id účetního zápisu — volající si nejdřív načte zápis přes
 * JournalEntryRepository::find($entryId, $supplierId) a teprve ověřený řádek
 * předá sem. Dvojice (source_type, source_id) se tedy nikdy nebere z requestu,
 * čímž odpadá IDOR přes podstrčené cizí source_id.
 *
 * ── PAST 1: syntetická source_id ──────────────────────────────────────────────
 * Uzávěrkové typy ('closing', 'opening', 'fx_revaluation', 'stock',
 * 'small_asset_accrual', 'prepaid_expense_accrual', 'income_tax',
 * 'profit_distribution') NEMAJÍ source_id ukazující na doklad — nesou syntetický
 * idempotenční klíč odvozený z period_id (viz ClosingSourceId: `period_id*10+SLOT`,
 * `1e12+`, `2e12+`, `3e12+`). Kdyby se takové číslo použilo jako id dokladu,
 * resolver by vrátil NÁHODNÝ CIZÍ ŘÁDEK (např. fakturu s id 1234 pro period_id 123,
 * slot 4). Obrana je proto WHITELIST: do DB se sáhne jen u typů v RESOLVABLE,
 * všechno ostatní končí `available:false` JEŠTĚ PŘED dotazem. Navíc druhá pojistka
 * SYNTHETIC_ID_FLOOR odmítne přímé použití source_id ≥ 1e12 jako ID dokladu.
 *
 * ── PAST 2: 'provision' ───────────────────────────────────────────────────────
 * U opravných položek je source_id ID faktury nebo klíč mapování
 * accounting_receivable_provisions pro danou firmu, období a fakturu.
 * Renderovat fakturu jako by BYLA tím zápisem by lhalo, proto 'provision' vrací
 * `available:false` (žádné bloky), ale `route` na fakturu vyplněnou MÁ, aby
 * „Otevřít detail" fungovalo správně.
 */
final class JournalSourceSummaryService
{
    /** Maximální počet řádků v jedné tabulce bloku; přes limit se nastaví truncated. */
    public const MAX_BLOCK_ROWS = 50;

    /**
     * Jediné typy, u kterých se source_id smí použít jako id dokladu.
     * Cokoli mimo tento seznam se do DB vůbec nedostane.
     */
    private const RESOLVABLE = [
        'invoice', 'purchase_invoice', 'bank', 'cash',
        'asset', 'asset_disposal', 'depreciation', 'offset', 'settlement',
    ];

    /**
     * Uzávěrkové typy — jejich source_id je syntetický klíč odvozený z period_id.
     * Slouží jen k rozlišení hlášky pro uživatele; odmítnutí řeší RESOLVABLE
     * whitelist, takže neúplnost tohohle seznamu NENÍ bezpečnostní problém.
     */
    private const CLOSING_TYPES = [
        'closing', 'opening', 'fx_revaluation', 'stock',
        'small_asset_accrual', 'prepaid_expense_accrual',
        'income_tax', 'profit_distribution',
    ];

    /**
     * Druhá pojistka nad whitelistem: reálné id dokladu nikdy nedosáhne 1e12,
     * zatímco všechna odsazená syntetická pásma (stock/small_asset/prepaid) ano.
     */
    private const SYNTHETIC_ID_FLOOR = ClosingSourceId::STOCK_SLOT_BASE;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param  array<string,mixed> $entry ověřený řádek journal_entries daného tenanta
     * @return array<string,mixed>
     */
    public function summarize(int $supplierId, array $entry): array
    {
        $type     = (string) ($entry['source_type'] ?? 'manual');
        $sourceId = isset($entry['source_id']) && $entry['source_id'] !== null ? (int) $entry['source_id'] : null;

        if ($type === 'provision') {
            $out = $this->unavailable($type, $sourceId, 'no_preview');
            $invoiceId = $sourceId;
            if ($sourceId !== null && $sourceId >= ClosingSourceId::PROVISION_BASE) {
                $mapped = $this->one(
                    'SELECT i.id FROM accounting_receivable_provisions p
                       JOIN invoices i ON i.id = p.invoice_id AND i.supplier_id = p.supplier_id
                      WHERE p.id = ? AND p.supplier_id = ?',
                    [$sourceId - ClosingSourceId::PROVISION_BASE, $supplierId],
                );
                $invoiceId = $mapped !== null ? (int) $mapped['id'] : null;
            }
            if ($invoiceId !== null && $invoiceId > 0 && $invoiceId < self::SYNTHETIC_ID_FLOOR) {
                $out['route']   = ['name' => 'invoice-detail', 'params' => ['id' => $invoiceId]];
                $out['actions'] = [$this->action('open_detail', 'invoices', $out['route'])];
            }
            return $out;
        }

        if ($sourceId === null || $sourceId <= 0) {
            return $this->unavailable($type, $sourceId, 'no_source');
        }
        if (!in_array($type, self::RESOLVABLE, true)) {
            // Do DB nesaháme. Uzávěrkovým typům řekneme proč, ostatním (např. 'manual'
            // s vlastním source_id) jen že náhled není — hláška o závěrce by lhala.
            return $this->unavailable(
                $type,
                $sourceId,
                in_array($type, self::CLOSING_TYPES, true) ? 'synthetic_source_id' : 'no_preview'
            );
        }
        if ($sourceId >= self::SYNTHETIC_ID_FLOOR) {
            return $this->unavailable($type, $sourceId, 'synthetic_source_id');
        }

        $summary = match ($type) {
            'invoice'                    => $this->invoice($supplierId, $sourceId),
            'purchase_invoice'           => $this->purchaseInvoice($supplierId, $sourceId),
            'bank'                       => $this->bank($supplierId, $sourceId),
            'cash'                       => $this->cash($supplierId, $sourceId),
            'asset', 'asset_disposal'    => $this->asset($supplierId, $sourceId, $type),
            'depreciation'               => $this->depreciation($supplierId, $sourceId),
            'offset'                     => $this->offset($supplierId, $sourceId),
            'settlement'                 => $this->settlement($supplierId, $sourceId),
            default                      => null,
        };

        if ($summary === null) {
            // Doklad byl smazán / patří jinému tenantovi — nikdy nevracíme cizí data.
            return $this->unavailable($type, $sourceId, 'not_found');
        }

        return array_merge([
            'source_type' => $type,
            'source_id'   => $sourceId,
            'available'   => true,
        ], $summary);
    }

    // ─────────────────────────────────────────────────────────── typy dokladů ──

    private function invoice(int $supplierId, int $id): ?array
    {
        $row = $this->one(
            'SELECT i.id, i.varsymbol, i.invoice_type, i.issue_date, i.tax_date, i.due_date,
                    i.total_without_vat, i.total_vat, i.total_with_vat, i.rounding,
                    i.paid_total, i.amount_to_pay, i.status, i.booked_at, i.paid_at,
                    i.reverse_charge, i.client_snapshot,
                    c.company_name, c.first_name, c.last_name, c.ic, c.dic,
                    cur.code AS currency_code
               FROM invoices i
               LEFT JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
               LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?',
            [$id, $supplierId]
        );
        if ($row === null) return null;

        $currency = (string) ($row['currency_code'] ?? 'CZK');
        $partner  = $this->partnerName($row);

        $items = $this->rows(
            'SELECT description, quantity, unit, unit_price_without_vat, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat
               FROM invoice_items WHERE invoice_id = ? ORDER BY order_index ASC, id ASC',
            [$id]
        );
        $itemsTotal = $this->countRows('SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ?', [$id]);

        $payments = $this->rows(
            'SELECT paid_on, amount, currency, source, variable_symbol, bank_reference, note
               FROM invoice_payments WHERE invoice_id = ? AND supplier_id = ?
              ORDER BY paid_on DESC, id DESC',
            [$id, $supplierId]
        );
        $paymentsTotal = $this->countRows(
            'SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ? AND supplier_id = ?',
            [$id, $supplierId]
        );

        $blocks = [];
        if ($items !== []) {
            $blocks[] = $this->itemsBlock($items, $itemsTotal, $currency, false);
            $blocks[] = $this->vatRecapBlock($this->vatRecapFromItems($items), $currency);
        }
        if ($payments !== []) {
            $blocks[] = $this->tableBlock('payments', 'payments', [
                $this->col('paid_on', 'payment_date', 'date'),
                $this->col('amount', 'amount', 'currency', 'right'),
                $this->col('source', 'payment_source', 'text'),
                $this->col('variable_symbol', 'variable_symbol', 'text'),
                $this->col('note', 'note', 'text'),
            ], array_map(static fn (array $p): array => [
                'paid_on'         => $p['paid_on'],
                'amount'          => (float) $p['amount'],
                'currency'        => $p['currency'],
                'source'          => $p['source'],
                'variable_symbol' => $p['variable_symbol'],
                'note'            => $p['note'],
            ], $payments), $paymentsTotal, $currency);
        }
        $blocks[] = $this->summaryBlock([
            $this->kv('total_without_vat', 'total_without_vat', (float) $row['total_without_vat'], 'currency'),
            $this->kv('total_vat', 'total_vat', (float) $row['total_vat'], 'currency'),
            $this->kv('rounding', 'rounding', (float) $row['rounding'], 'currency'),
            $this->kv('total_with_vat', 'total_with_vat', (float) $row['total_with_vat'], 'currency'),
            $this->kv('paid_total', 'paid_total', (float) $row['paid_total'], 'currency'),
            $this->kv('amount_to_pay', 'amount_to_pay', (float) $row['amount_to_pay'], 'currency'),
        ], $currency);

        $route = ['name' => 'invoice-detail', 'params' => ['id' => $id]];

        return [
            'title'    => $this->docTitle((string) $row['varsymbol']),
            'subtitle' => $partner,
            'status'   => $this->status((string) $row['status'], $this->invoiceStatusVariant((string) $row['status'])),
            'currency' => $currency,
            'fields'   => [
                $this->kv('partner', 'partner', $partner, 'text'),
                $this->kv('varsymbol', 'varsymbol', $row['varsymbol'], 'text'),
                $this->kv('issue_date', 'issue_date', $row['issue_date'], 'date'),
                $this->kv('tax_date', 'tax_date', $row['tax_date'], 'date'),
                $this->kv('due_date', 'due_date', $row['due_date'], 'date'),
                $this->kv('total_with_vat', 'total_with_vat', (float) $row['total_with_vat'], 'currency'),
                $this->kv('currency', 'currency', $currency, 'text'),
                $this->kv('posted', 'posted', $row['booked_at'] !== null, 'bool'),
                $this->kv('reverse_charge', 'reverse_charge', (bool) $row['reverse_charge'], 'bool'),
            ],
            'blocks'  => $blocks,
            'route'   => $route,
            'actions' => [
                $this->action('open_detail', 'invoices', $route),
                $this->action('open_pdf', 'invoices', null, '/api/invoices/' . $id . '/pdf'),
            ],
        ];
    }

    private function purchaseInvoice(int $supplierId, int $id): ?array
    {
        $row = $this->one(
            'SELECT p.id, p.varsymbol, p.vendor_invoice_number, p.document_kind,
                    p.issue_date, p.tax_date, p.due_date, p.received_at,
                    p.total_without_vat, p.total_vat, p.total_with_vat, p.rounding,
                    p.amount_to_pay, p.status, p.booked_at, p.paid_at, p.reverse_charge,
                    p.vat_deduction, p.vat_deduction_percent, p.tax_deductible, p.is_fixed_asset,
                    p.advance_purchase_invoice_id, p.vendor_snapshot,
                    c.company_name, c.first_name, c.last_name, c.ic, c.dic,
                    cur.code AS currency_code
               FROM purchase_invoices p
               LEFT JOIN clients c ON c.id = p.vendor_id AND c.supplier_id = p.supplier_id
               LEFT JOIN currencies cur ON cur.id = p.currency_id
              WHERE p.id = ? AND p.supplier_id = ?',
            [$id, $supplierId]
        );
        if ($row === null) return null;

        $currency = (string) ($row['currency_code'] ?? 'CZK');
        $partner  = $this->partnerName($row, 'vendor_snapshot');

        $items = $this->rows(
            'SELECT description, quantity, unit, unit_price_without_vat, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat, expense_kind, expense_account_code
               FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index ASC, id ASC',
            [$id]
        );
        $itemsTotal = $this->countRows(
            'SELECT COUNT(*) FROM purchase_invoice_items WHERE purchase_invoice_id = ?',
            [$id]
        );

        $blocks = [];
        if ($items !== []) {
            // U přijatých faktur navíc nákladová klasifikace (expense_kind / účet) —
            // je to hlavní věc, kterou účetní na přijaté faktuře kontroluje.
            $blocks[] = $this->itemsBlock($items, $itemsTotal, $currency, true);
            $blocks[] = $this->vatRecapBlock($this->vatRecapFromItems($items), $currency);
        }
        $blocks[] = $this->summaryBlock([
            $this->kv('total_without_vat', 'total_without_vat', (float) $row['total_without_vat'], 'currency'),
            $this->kv('total_vat', 'total_vat', (float) $row['total_vat'], 'currency'),
            $this->kv('rounding', 'rounding', (float) $row['rounding'], 'currency'),
            $this->kv('total_with_vat', 'total_with_vat', (float) $row['total_with_vat'], 'currency'),
            $this->kv('amount_to_pay', 'amount_to_pay', (float) $row['amount_to_pay'], 'currency'),
            $this->kv('vat_deduction', 'vat_deduction', $row['vat_deduction'], 'text'),
            $this->kv('vat_deduction_percent', 'vat_deduction_percent', $row['vat_deduction_percent'] !== null ? (float) $row['vat_deduction_percent'] : null, 'percent'),
            $this->kv('tax_deductible', 'tax_deductible', (bool) $row['tax_deductible'], 'bool'),
        ], $currency);

        $route = ['name' => 'purchase-invoice-detail', 'params' => ['id' => $id]];

        $fields = [
            $this->kv('partner', 'vendor', $partner, 'text'),
            $this->kv('vendor_invoice_number', 'vendor_invoice_number', $row['vendor_invoice_number'] ?: $row['varsymbol'], 'text'),
            $this->kv('issue_date', 'issue_date', $row['issue_date'], 'date'),
            $this->kv('tax_date', 'tax_date', $row['tax_date'], 'date'),
            $this->kv('due_date', 'due_date', $row['due_date'], 'date'),
            $this->kv('received_at', 'received_at', $row['received_at'], 'date'),
            $this->kv('total_with_vat', 'total_with_vat', (float) $row['total_with_vat'], 'currency'),
            $this->kv('currency', 'currency', $currency, 'text'),
            $this->kv('posted', 'posted', $row['booked_at'] !== null, 'bool'),
            $this->kv('reverse_charge', 'reverse_charge', (bool) $row['reverse_charge'], 'bool'),
            $this->kv('is_fixed_asset', 'is_fixed_asset', (bool) $row['is_fixed_asset'], 'bool'),
        ];
        if ($row['advance_purchase_invoice_id'] !== null) {
            $fields[] = $this->kv('advance_link', 'advance_link', (int) $row['advance_purchase_invoice_id'], 'doc_ref');
        }
        // „Uhrazeno, ale nezaúčtováno" — typický zdroj rozdílu mezi saldem a deníkem.
        if ($row['paid_at'] !== null && $row['booked_at'] === null) {
            $fields[] = $this->kv('mark_paid_unposted', 'mark_paid_unposted', true, 'bool');
        }

        return [
            'title'    => $this->docTitle((string) ($row['vendor_invoice_number'] ?: $row['varsymbol'])),
            'subtitle' => $partner,
            'status'   => $this->status((string) $row['status'], $this->purchaseStatusVariant((string) $row['status'])),
            'currency' => $currency,
            'fields'   => $fields,
            'blocks'   => $blocks,
            'route'    => $route,
            'actions'  => [
                $this->action('open_detail', 'purchase_invoices', $route),
                $this->action('open_original', 'purchase_invoices', null, '/api/purchase-invoices/' . $id . '/pdf'),
            ],
        ];
    }

    private function bank(int $supplierId, int $id): ?array
    {
        // bank_transactions NEMÁ supplier_id — tenant se vynucuje JOINem na výpis.
        $row = $this->one(
            'SELECT t.id, t.posted_at, t.amount, t.currency, t.variable_symbol, t.constant_symbol,
                    t.specific_symbol, t.counterparty_account, t.counterparty_bank, t.counterparty_name,
                    t.description, t.bank_ref, t.match_status, t.matched_invoice_id, t.matched_at,
                    s.id AS statement_id, s.statement_number, s.statement_date,
                    s.account_number, s.bank_code, s.currency AS statement_currency
               FROM bank_transactions t
               JOIN bank_statements s ON s.id = t.statement_id
              WHERE t.id = ? AND s.supplier_id = ?',
            [$id, $supplierId]
        );
        if ($row === null) return null;

        $currency  = (string) ($row['currency'] ?: $row['statement_currency'] ?: 'CZK');
        $statement = (int) $row['statement_id'];

        $blocks = [$this->summaryBlock([
            $this->kv('amount', 'amount', (float) $row['amount'], 'currency'),
            $this->kv('posted_at', 'transaction_date', $row['posted_at'], 'date'),
            $this->kv('counterparty_name', 'counterparty', $row['counterparty_name'], 'text'),
            $this->kv('counterparty_account', 'counterparty_account', $this->accountWithBank($row), 'text'),
            $this->kv('variable_symbol', 'variable_symbol', $row['variable_symbol'], 'text'),
            $this->kv('constant_symbol', 'constant_symbol', $row['constant_symbol'], 'text'),
            $this->kv('specific_symbol', 'specific_symbol', $row['specific_symbol'], 'text'),
            $this->kv('description', 'message_for_recipient', $row['description'], 'text'),
            $this->kv('bank_ref', 'bank_ref', $row['bank_ref'], 'text'),
        ], $currency, 'transaction')];

        $blocks[] = $this->summaryBlock([
            $this->kv('statement_number', 'statement_number', $row['statement_number'], 'text'),
            $this->kv('statement_date', 'statement_date', $row['statement_date'], 'date'),
            $this->kv('account_number', 'account_number', $this->statementAccount($row), 'text'),
        ], $currency, 'statement');

        // Napárované doklady — v tabulce jen ta, na kterou banka reálně ukazuje.
        if ($row['matched_invoice_id'] !== null) {
            $matched = $this->rows(
                'SELECT i.id, i.varsymbol, i.issue_date, i.total_with_vat, i.status
                   FROM invoices i WHERE i.id = ? AND i.supplier_id = ?',
                [(int) $row['matched_invoice_id'], $supplierId]
            );
            if ($matched !== []) {
                $blocks[] = $this->tableBlock('matched_documents', 'matched_documents', [
                    $this->col('varsymbol', 'varsymbol', 'text'),
                    $this->col('issue_date', 'issue_date', 'date'),
                    $this->col('total_with_vat', 'total_with_vat', 'currency', 'right'),
                    $this->col('status', 'status', 'text'),
                ], array_map(static fn (array $m): array => [
                    'id'             => (int) $m['id'],
                    'varsymbol'      => $m['varsymbol'],
                    'issue_date'     => $m['issue_date'],
                    'total_with_vat' => (float) $m['total_with_vat'],
                    'status'         => $m['status'],
                ], $matched), count($matched), $currency);
            }
        }

        $route = ['name' => 'bank-detail', 'params' => ['id' => $statement], 'query' => ['transaction' => $id]];

        $actions = [$this->action('open_detail', 'bank', $route)];
        if ($row['matched_invoice_id'] !== null) {
            $actions[] = $this->action('open_counterparty', 'invoices', [
                'name' => 'invoice-detail', 'params' => ['id' => (int) $row['matched_invoice_id']],
            ]);
        }

        return [
            'title'    => $this->docTitle((string) ($row['bank_ref'] ?: $row['variable_symbol'] ?: ('#' . $id))),
            'subtitle' => $row['counterparty_name'] !== null ? (string) $row['counterparty_name'] : null,
            'status'   => $this->status((string) $row['match_status'], $this->matchStatusVariant((string) $row['match_status'])),
            'currency' => $currency,
            'fields'   => [
                $this->kv('posted_at', 'transaction_date', $row['posted_at'], 'date'),
                $this->kv('amount', 'amount', (float) $row['amount'], 'currency'),
                $this->kv('counterparty_name', 'counterparty', $row['counterparty_name'], 'text'),
                $this->kv('variable_symbol', 'variable_symbol', $row['variable_symbol'], 'text'),
                $this->kv('currency', 'currency', $currency, 'text'),
            ],
            'blocks'  => $blocks,
            'route'   => $route,
            'actions' => $actions,
        ];
    }

    private function cash(int $supplierId, int $id): ?array
    {
        $row = $this->one(
            'SELECT d.id, d.doc_type, d.doc_number, d.purpose, d.description,
                    d.issue_date, d.tax_date, d.partner_name, d.partner_ic, d.partner_dic,
                    d.vat_mode, d.total_amount, d.currency_code, d.fx_rate, d.amount_foreign,
                    d.counter_account_code, d.status, d.register_id,
                    d.invoice_id, d.purchase_invoice_id,
                    r.name AS register_name, r.currency_code AS register_currency, r.account_code
               FROM cash_documents d
               LEFT JOIN cash_registers r ON r.id = d.register_id AND r.supplier_id = d.supplier_id
              WHERE d.id = ? AND d.supplier_id = ?',
            [$id, $supplierId]
        );
        if ($row === null) return null;

        $currency = (string) ($row['currency_code'] ?: 'CZK');

        $vatLines = $this->rows(
            'SELECT vat_rate, base_amount, vat_amount, vat_classification_code
               FROM cash_document_vat_lines WHERE cash_document_id = ? ORDER BY vat_rate DESC, id ASC',
            [$id]
        );

        $blocks = [$this->summaryBlock([
            $this->kv('doc_number', 'doc_number', $row['doc_number'], 'text'),
            $this->kv('doc_type', 'doc_type', $row['doc_type'], 'text'),
            $this->kv('purpose', 'purpose', $row['purpose'], 'text'),
            $this->kv('description', 'description', $row['description'], 'text'),
            $this->kv('partner_name', 'partner', $row['partner_name'], 'text'),
            $this->kv('partner_ic', 'partner_ic', $row['partner_ic'], 'text'),
            $this->kv('issue_date', 'issue_date', $row['issue_date'], 'date'),
            $this->kv('tax_date', 'tax_date', $row['tax_date'], 'date'),
            $this->kv('total_amount', 'total_amount', (float) $row['total_amount'], 'currency'),
        ], $currency, 'document')];

        if ($vatLines !== []) {
            $blocks[] = $this->tableBlock('vat', 'vat_recap', [
                $this->col('vat_rate', 'vat_rate', 'percent', 'right'),
                $this->col('base_amount', 'vat_base', 'currency', 'right'),
                $this->col('vat_amount', 'vat_amount', 'currency', 'right'),
                $this->col('vat_classification_code', 'vat_classification', 'text'),
            ], array_map(static fn (array $l): array => [
                'vat_rate'                => (float) $l['vat_rate'],
                'base_amount'             => (float) $l['base_amount'],
                'vat_amount'              => (float) $l['vat_amount'],
                'vat_classification_code' => $l['vat_classification_code'],
            ], $vatLines), count($vatLines), $currency);
        }

        $blocks[] = $this->summaryBlock([
            $this->kv('register_name', 'cash_register', $row['register_name'], 'text'),
            $this->kv('account_code', 'account', $row['account_code'], 'text'),
            $this->kv('counter_account_code', 'counter_account', $row['counter_account_code'], 'text'),
            $this->kv('register_balance', 'register_balance', $this->cashRegisterBalance($supplierId, (int) $row['register_id']), 'currency'),
        ], (string) ($row['register_currency'] ?: $currency), 'register');

        $route = [
            'name'  => 'accounting-cash',
            'query' => array_filter([
                'register_id' => $row['register_id'] !== null ? (string) $row['register_id'] : null,
                'q'           => $row['doc_number'] !== null ? (string) $row['doc_number'] : null,
            ], static fn ($v): bool => $v !== null),
        ];

        $actions = [
            $this->action('open_detail', 'cash', $route),
            $this->action('open_pdf', 'cash', null, '/api/accounting/cash-documents/' . $id . '/pdf'),
        ];
        if ($row['invoice_id'] !== null) {
            $actions[] = $this->action('open_counterparty', 'invoices', [
                'name' => 'invoice-detail', 'params' => ['id' => (int) $row['invoice_id']],
            ]);
        } elseif ($row['purchase_invoice_id'] !== null) {
            $actions[] = $this->action('open_counterparty', 'purchase_invoices', [
                'name' => 'purchase-invoice-detail', 'params' => ['id' => (int) $row['purchase_invoice_id']],
            ]);
        }

        return [
            'title'    => $this->docTitle((string) $row['doc_number']),
            'subtitle' => $row['partner_name'] !== null ? (string) $row['partner_name'] : (string) $row['purpose'],
            'status'   => $this->status((string) $row['status'], $this->cashStatusVariant((string) $row['status'])),
            'currency' => $currency,
            'fields'   => [
                $this->kv('doc_number', 'doc_number', $row['doc_number'], 'text'),
                $this->kv('issue_date', 'issue_date', $row['issue_date'], 'date'),
                $this->kv('partner_name', 'partner', $row['partner_name'], 'text'),
                $this->kv('total_amount', 'total_amount', (float) $row['total_amount'], 'currency'),
                $this->kv('currency', 'currency', $currency, 'text'),
                $this->kv('register_name', 'cash_register', $row['register_name'], 'text'),
            ],
            'blocks'  => $blocks,
            'route'   => $route,
            'actions' => $actions,
        ];
    }

    private function asset(int $supplierId, int $id, string $type): ?array
    {
        $row = $this->one(
            'SELECT id, inventory_number, name, description, kind, input_price,
                    acquisition_date, put_into_use_date, disposal_date, disposal_type,
                    disposal_price, status, tax_method, tax_group, acc_method,
                    acc_useful_life_months, acc_residual_value,
                    asset_account_code, accumulated_account_code,
                    purchase_invoice_id, sale_invoice_id
               FROM assets WHERE id = ? AND supplier_id = ?',
            [$id, $supplierId]
        );
        if ($row === null) return null;

        $blocks = [$this->summaryBlock([
            $this->kv('inventory_number', 'inventory_number', $row['inventory_number'], 'text'),
            $this->kv('name', 'asset_name', $row['name'], 'text'),
            $this->kv('input_price', 'input_price', (float) $row['input_price'], 'currency'),
            $this->kv('acquisition_date', 'acquisition_date', $row['acquisition_date'], 'date'),
            $this->kv('put_into_use_date', 'put_into_use_date', $row['put_into_use_date'], 'date'),
            $this->kv('acc_method', 'acc_method', $row['acc_method'], 'text'),
            $this->kv('tax_method', 'tax_method', $row['tax_method'], 'text'),
            $this->kv('tax_group', 'tax_group', $row['tax_group'] !== null ? (int) $row['tax_group'] : null, 'number'),
            $this->kv('asset_account_code', 'account', $row['asset_account_code'], 'text'),
        ], 'CZK', 'asset_card')];

        if ($type === 'asset_disposal') {
            $blocks[] = $this->summaryBlock([
                $this->kv('disposal_date', 'disposal_date', $row['disposal_date'], 'date'),
                $this->kv('disposal_type', 'disposal_type', $row['disposal_type'], 'text'),
                $this->kv('disposal_price', 'disposal_price', $row['disposal_price'] !== null ? (float) $row['disposal_price'] : null, 'currency'),
            ], 'CZK', 'disposal');
        }

        $blocks[] = $this->depreciationScheduleBlock($supplierId, $id);

        $route   = ['name' => 'accounting-asset-detail', 'params' => ['id' => $id]];
        $actions = [$this->action('open_detail', 'assets', $route)];
        if ($row['purchase_invoice_id'] !== null) {
            $actions[] = $this->action('open_counterparty', 'purchase_invoices', [
                'name' => 'purchase-invoice-detail', 'params' => ['id' => (int) $row['purchase_invoice_id']],
            ]);
        }

        return [
            'title'    => $this->docTitle((string) $row['inventory_number']),
            'subtitle' => (string) $row['name'],
            'status'   => $this->status((string) $row['status'], $this->assetStatusVariant((string) $row['status'])),
            'currency' => 'CZK',
            'fields'   => [
                $this->kv('inventory_number', 'inventory_number', $row['inventory_number'], 'text'),
                $this->kv('name', 'asset_name', $row['name'], 'text'),
                $this->kv('input_price', 'input_price', (float) $row['input_price'], 'currency'),
                $this->kv('put_into_use_date', 'put_into_use_date', $row['put_into_use_date'], 'date'),
            ],
            'blocks'  => array_values(array_filter($blocks)),
            'route'   => $route,
            'actions' => $actions,
        ];
    }

    private function depreciation(int $supplierId, int $id): ?array
    {
        // source_id je ID ŘÁDKU odpisu (depreciation_entries), NE id karty majetku.
        $row = $this->one(
            'SELECT d.id, d.asset_id, d.kind, d.fiscal_year, d.amount, d.full_amount,
                    d.residual_value_end, d.is_paused, d.is_half, d.months_count, d.status,
                    a.inventory_number, a.name AS asset_name, a.input_price,
                    a.put_into_use_date, a.acc_method, a.tax_method
               FROM depreciation_entries d
               JOIN assets a ON a.id = d.asset_id AND a.supplier_id = d.supplier_id
              WHERE d.id = ? AND d.supplier_id = ?',
            [$id, $supplierId]
        );
        if ($row === null) return null;

        $assetId = (int) $row['asset_id'];

        $blocks = [$this->summaryBlock([
            $this->kv('fiscal_year', 'fiscal_year', (int) $row['fiscal_year'], 'number'),
            $this->kv('kind', 'depreciation_kind', $row['kind'], 'text'),
            $this->kv('amount', 'depreciation_amount', (float) $row['amount'], 'currency'),
            $this->kv('full_amount', 'depreciation_full_amount', $row['full_amount'] !== null ? (float) $row['full_amount'] : null, 'currency'),
            $this->kv('residual_value_end', 'residual_value_end', $row['residual_value_end'] !== null ? (float) $row['residual_value_end'] : null, 'currency'),
            $this->kv('months_count', 'months_count', $row['months_count'] !== null ? (int) $row['months_count'] : null, 'number'),
            $this->kv('is_half', 'is_half', (bool) $row['is_half'], 'bool'),
            $this->kv('is_paused', 'is_paused', (bool) $row['is_paused'], 'bool'),
        ], 'CZK', 'depreciation_row')];

        $blocks[] = $this->summaryBlock([
            $this->kv('inventory_number', 'inventory_number', $row['inventory_number'], 'text'),
            $this->kv('name', 'asset_name', $row['asset_name'], 'text'),
            $this->kv('input_price', 'input_price', (float) $row['input_price'], 'currency'),
            $this->kv('put_into_use_date', 'put_into_use_date', $row['put_into_use_date'], 'date'),
            $this->kv('acc_method', 'acc_method', $row['acc_method'], 'text'),
        ], 'CZK', 'asset_card');

        $blocks[] = $this->depreciationScheduleBlock($supplierId, $assetId);

        $route = ['name' => 'accounting-asset-detail', 'params' => ['id' => $assetId]];

        return [
            'title'    => $this->docTitle((string) $row['fiscal_year']),
            'subtitle' => trim(((string) $row['inventory_number']) . ' — ' . ((string) $row['asset_name']), ' —'),
            'status'   => $this->status((string) $row['status'], (string) $row['status'] === 'posted' ? 'success' : 'neutral'),
            'currency' => 'CZK',
            'fields'   => [
                $this->kv('fiscal_year', 'fiscal_year', (int) $row['fiscal_year'], 'number'),
                $this->kv('amount', 'depreciation_amount', (float) $row['amount'], 'currency'),
                $this->kv('residual_value_end', 'residual_value_end', $row['residual_value_end'] !== null ? (float) $row['residual_value_end'] : null, 'currency'),
                $this->kv('name', 'asset_name', $row['asset_name'], 'text'),
            ],
            'blocks'  => array_values(array_filter($blocks)),
            'route'   => $route,
            'actions' => [$this->action('open_detail', 'assets', $route)],
        ];
    }

    private function offset(int $supplierId, int $id): ?array
    {
        $row = $this->one(
            'SELECT o.id, o.document_no, o.agreement_date, o.total_amount, o.status,
                    c.company_name, c.first_name, c.last_name
               FROM offset_agreements o
               LEFT JOIN clients c ON c.id = o.partner_id AND c.supplier_id = o.supplier_id
              WHERE o.id = ? AND o.supplier_id = ?',
            [$id, $supplierId]
        );
        if ($row === null) return null;

        $partner = $this->partnerName($row);

        $items = $this->rows(
            'SELECT doc_type, doc_id, amount FROM offset_agreement_items
              WHERE agreement_id = ? AND supplier_id = ? ORDER BY id ASC',
            [$id, $supplierId]
        );
        $itemsTotal = $this->countRows(
            'SELECT COUNT(*) FROM offset_agreement_items WHERE agreement_id = ? AND supplier_id = ?',
            [$id, $supplierId]
        );

        $blocks = [];
        if ($items !== []) {
            $blocks[] = $this->tableBlock('items', 'offset_items', [
                $this->col('doc_type', 'doc_type', 'text'),
                $this->col('doc_id', 'document', 'doc_ref'),
                $this->col('amount', 'amount', 'currency', 'right'),
            ], array_map(static fn (array $i): array => [
                'doc_type' => $i['doc_type'],
                'doc_id'   => (int) $i['doc_id'],
                'amount'   => (float) $i['amount'],
            ], $items), $itemsTotal, 'CZK');
        }
        $blocks[] = $this->summaryBlock([
            $this->kv('total_amount', 'total_amount', (float) $row['total_amount'], 'currency'),
        ], 'CZK');

        $route = ['name' => 'accounting-offsets'];

        return [
            'title'    => $this->docTitle((string) $row['document_no']),
            'subtitle' => $partner,
            'status'   => $this->status((string) $row['status'], (string) $row['status'] === 'confirmed' ? 'success' : 'neutral'),
            'currency' => 'CZK',
            'fields'   => [
                $this->kv('partner', 'partner', $partner, 'text'),
                $this->kv('document_no', 'document_no', $row['document_no'], 'text'),
                $this->kv('agreement_date', 'agreement_date', $row['agreement_date'], 'date'),
                $this->kv('total_amount', 'total_amount', (float) $row['total_amount'], 'currency'),
            ],
            'blocks'  => $blocks,
            'route'   => $route,
            'actions' => [$this->action('open_detail', 'accounting.offsets', $route)],
        ];
    }

    private function settlement(int $supplierId, int $id): ?array
    {
        $row = $this->one(
            'SELECT id, doc_type, doc_id, amount, settled_on, status, note
               FROM invoice_settlements WHERE id = ? AND supplier_id = ?',
            [$id, $supplierId]
        );
        if ($row === null) return null;

        $docType = (string) $row['doc_type'];
        $docId   = (int) $row['doc_id'];
        $isIssued = $docType === 'invoice';

        $route = $isIssued
            ? ['name' => 'invoice-detail', 'params' => ['id' => $docId]]
            : ['name' => 'purchase-invoice-detail', 'params' => ['id' => $docId]];

        $blocks = [$this->summaryBlock([
            $this->kv('amount', 'amount', (float) $row['amount'], 'currency'),
            $this->kv('settled_on', 'settled_on', $row['settled_on'], 'date'),
            $this->kv('doc_type', 'doc_type', $docType, 'text'),
            $this->kv('doc_id', 'document', $docId, 'doc_ref'),
            $this->kv('note', 'note', $row['note'], 'text'),
        ], 'CZK')];

        return [
            'title'    => $this->docTitle('#' . $id),
            'subtitle' => null,
            'status'   => $this->status((string) $row['status'], (string) $row['status'] === 'confirmed' ? 'success' : 'neutral'),
            'currency' => 'CZK',
            'fields'   => [
                $this->kv('amount', 'amount', (float) $row['amount'], 'currency'),
                $this->kv('settled_on', 'settled_on', $row['settled_on'], 'date'),
            ],
            'blocks'  => $blocks,
            'route'   => $route,
            'actions' => [
                $this->action('open_detail', $isIssued ? 'invoices' : 'purchase_invoices', $route),
            ],
        ];
    }

    // ──────────────────────────────────────────────────────── stavební kameny ──

    /** Odpisový plán karty — sdílený blok pro 'asset', 'asset_disposal' i 'depreciation'. */
    private function depreciationScheduleBlock(int $supplierId, int $assetId): ?array
    {
        $rows = $this->rows(
            'SELECT fiscal_year, kind, amount, residual_value_end, status
               FROM depreciation_entries
              WHERE asset_id = ? AND supplier_id = ?
              ORDER BY fiscal_year ASC, kind ASC, id ASC',
            [$assetId, $supplierId]
        );
        if ($rows === []) {
            return null;
        }
        $total = $this->countRows(
            'SELECT COUNT(*) FROM depreciation_entries WHERE asset_id = ? AND supplier_id = ?',
            [$assetId, $supplierId]
        );

        return $this->tableBlock('depreciation_schedule', 'depreciation_schedule', [
            $this->col('fiscal_year', 'fiscal_year', 'number'),
            $this->col('kind', 'depreciation_kind', 'text'),
            $this->col('amount', 'depreciation_amount', 'currency', 'right'),
            $this->col('residual_value_end', 'residual_value_end', 'currency', 'right'),
            $this->col('status', 'status', 'text'),
        ], array_map(static fn (array $r): array => [
            'fiscal_year'        => (int) $r['fiscal_year'],
            'kind'               => $r['kind'],
            'amount'             => (float) $r['amount'],
            'residual_value_end' => $r['residual_value_end'] !== null ? (float) $r['residual_value_end'] : null,
            'status'             => $r['status'],
        ], $rows), $total, 'CZK');
    }

    /** Položky faktury; u přijaté navíc nákladová klasifikace. */
    private function itemsBlock(array $items, int $total, string $currency, bool $withExpense): array
    {
        $columns = [
            $this->col('description', 'item_description', 'text'),
            $this->col('quantity', 'quantity', 'number', 'right'),
            $this->col('unit', 'unit', 'text'),
            $this->col('unit_price_without_vat', 'unit_price', 'currency', 'right'),
            $this->col('vat_rate_snapshot', 'vat_rate', 'percent', 'right'),
            $this->col('total_without_vat', 'total_without_vat', 'currency', 'right'),
            $this->col('total_with_vat', 'total_with_vat', 'currency', 'right'),
        ];
        if ($withExpense) {
            $columns[] = $this->col('expense_kind', 'expense_kind', 'text');
            $columns[] = $this->col('expense_account_code', 'expense_account', 'text');
        }

        $rows = array_map(static function (array $i) use ($withExpense): array {
            $r = [
                'description'            => $i['description'],
                'quantity'               => $i['quantity'] !== null ? (float) $i['quantity'] : null,
                'unit'                   => $i['unit'],
                'unit_price_without_vat' => (float) $i['unit_price_without_vat'],
                'vat_rate_snapshot'      => $i['vat_rate_snapshot'] !== null ? (float) $i['vat_rate_snapshot'] : null,
                'total_without_vat'      => (float) $i['total_without_vat'],
                'total_with_vat'         => (float) $i['total_with_vat'],
            ];
            if ($withExpense) {
                $r['expense_kind']         = $i['expense_kind'] ?? null;
                $r['expense_account_code'] = $i['expense_account_code'] ?? null;
            }
            return $r;
        }, $items);

        return $this->tableBlock('items', 'items', $columns, $rows, $total, $currency);
    }

    /**
     * Rekapitulace DPH se počítá ze STAŽENÝCH položek (ne dalším SQL), takže při
     * oříznutí na MAX_BLOCK_ROWS by byla neúplná — v tom případě ji volající
     * nedostane kompletní a blok nese truncated příznak z položek.
     *
     * @return list<array<string,mixed>>
     */
    private function vatRecapFromItems(array $items): array
    {
        $byRate = [];
        foreach ($items as $i) {
            $rate = $i['vat_rate_snapshot'] !== null ? (float) $i['vat_rate_snapshot'] : 0.0;
            $key  = number_format($rate, 2, '.', '');
            if (!isset($byRate[$key])) {
                $byRate[$key] = ['vat_rate' => $rate, 'base' => 0.0, 'vat' => 0.0, 'total' => 0.0];
            }
            $byRate[$key]['base']  += (float) $i['total_without_vat'];
            $byRate[$key]['vat']   += (float) $i['total_vat'];
            $byRate[$key]['total'] += (float) $i['total_with_vat'];
        }
        krsort($byRate, SORT_NUMERIC);
        return array_values(array_map(static fn (array $r): array => [
            'vat_rate' => $r['vat_rate'],
            'base'     => round($r['base'], 2),
            'vat'      => round($r['vat'], 2),
            'total'    => round($r['total'], 2),
        ], $byRate));
    }

    private function vatRecapBlock(array $recap, string $currency): array
    {
        return $this->tableBlock('vat', 'vat_recap', [
            $this->col('vat_rate', 'vat_rate', 'percent', 'right'),
            $this->col('base', 'vat_base', 'currency', 'right'),
            $this->col('vat', 'vat_amount', 'currency', 'right'),
            $this->col('total', 'total_with_vat', 'currency', 'right'),
        ], $recap, count($recap), $currency);
    }

    /**
     * Tabulkový blok s tvrdým stropem řádků. Oříznutí NIKDY není tiché —
     * `truncated:true` + `total_rows` dovolí FE napsat „zobrazeno 50 ze 120".
     */
    private function tableBlock(string $key, string $titleKey, array $columns, array $rows, int $totalRows, ?string $currency = null): array
    {
        $truncated = count($rows) > self::MAX_BLOCK_ROWS || $totalRows > count($rows);
        $shown     = array_slice($rows, 0, self::MAX_BLOCK_ROWS);

        return array_filter([
            'key'        => $key,
            'title_key'  => 'accounting.journal.source_drawer.block.' . $titleKey,
            'type'       => 'table',
            'columns'    => $columns,
            'rows'       => array_values($shown),
            'total_rows' => max($totalRows, count($rows)),
            'truncated'  => $truncated || count($shown) < max($totalRows, count($rows)),
            'currency'   => $currency,
        ], static fn ($v): bool => $v !== null);
    }

    private function summaryBlock(array $items, ?string $currency = null, string $titleKey = 'summary'): array
    {
        // Prázdné hodnoty se nevykreslují — lepší kratší blok než mřížka pomlček.
        $items = array_values(array_filter($items, static fn (array $i): bool => $i['value'] !== null && $i['value'] !== ''));

        return array_filter([
            'key'       => $titleKey,
            'title_key' => 'accounting.journal.source_drawer.block.' . $titleKey,
            'type'      => 'keyvalue',
            'items'     => $items,
            'currency'  => $currency,
        ], static fn ($v): bool => $v !== null);
    }

    private function col(string $key, string $labelKey, string $format, string $align = 'left'): array
    {
        return [
            'key'       => $key,
            'label_key' => 'accounting.journal.source_drawer.field.' . $labelKey,
            'format'    => $format,
            'align'     => $align,
        ];
    }

    private function kv(string $key, string $labelKey, mixed $value, string $format): array
    {
        return [
            'key'       => $key,
            'label_key' => 'accounting.journal.source_drawer.field.' . $labelKey,
            'value'     => $value,
            'format'    => $format,
        ];
    }

    /**
     * Tlačítko pro FE ActionBar: jen klíč + potřebné právo + cíl. Žádná mutace —
     * drawer je navigační a read-only, akce typu vystavení/vyřazení/párování
     * patří na detail dokladu.
     */
    private function action(string $key, string $permission, ?array $route, ?string $href = null): array
    {
        return array_filter([
            'key'        => $key,
            'permission' => $permission,
            'route'      => $route,
            'href'       => $href,
        ], static fn ($v): bool => $v !== null);
    }

    private function status(string $value, string $variant): array
    {
        return ['key' => $value, 'variant' => $variant];
    }

    private function unavailable(string $type, ?int $sourceId, string $reason): array
    {
        return [
            'source_type'        => $type,
            'source_id'          => $sourceId,
            'available'          => false,
            'unavailable_reason' => $reason,
            'title'              => null,
            'subtitle'           => null,
            'status'             => null,
            'currency'           => null,
            'fields'             => [],
            'blocks'             => [],
            'route'              => null,
            'actions'            => [],
        ];
    }

    /**
     * `title` je JEN číslo/označení dokladu. Druh dokladu si FE přeloží sám ze
     * `source_type` (klíč accounting.journal.source.{type}), který už v deníku
     * používá — jinak bychom sem tahali lokalizaci a rozbili přepínání jazyka.
     */
    private function docTitle(string $number): string
    {
        $number = trim($number);
        return $number !== '' ? $number : '—';
    }

    private function partnerName(array $row, string $snapshotKey = 'client_snapshot'): ?string
    {
        return DocumentPartnerName::from($row, $snapshotKey);
    }

    private function accountWithBank(array $row): ?string
    {
        $acc = trim((string) ($row['counterparty_account'] ?? ''));
        if ($acc === '') return null;
        $bank = trim((string) ($row['counterparty_bank'] ?? ''));
        return $bank !== '' ? $acc . '/' . $bank : $acc;
    }

    private function statementAccount(array $row): ?string
    {
        $acc = trim((string) ($row['account_number'] ?? ''));
        if ($acc === '') return null;
        $bank = trim((string) ($row['bank_code'] ?? ''));
        return $bank !== '' ? $acc . '/' . $bank : $acc;
    }

    private function cashRegisterBalance(int $supplierId, int $registerId): ?float
    {
        if ($registerId <= 0) return null;
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN doc_type = 'in' THEN total_amount ELSE -total_amount END), 0)
               FROM cash_documents
              WHERE register_id = ? AND supplier_id = ? AND status = 'posted'"
        );
        $stmt->execute([$registerId, $supplierId]);
        return (float) $stmt->fetchColumn();
    }

    private function invoiceStatusVariant(string $s): string
    {
        return match ($s) {
            'paid'      => 'success',
            'cancelled' => 'danger',
            'reminded'  => 'warning',
            'draft'     => 'neutral',
            default     => 'primary',
        };
    }

    private function purchaseStatusVariant(string $s): string
    {
        return match ($s) {
            'paid'      => 'success',
            'cancelled' => 'danger',
            'booked'    => 'primary',
            default     => 'neutral',
        };
    }

    private function matchStatusVariant(string $s): string
    {
        return match ($s) {
            'auto_exact', 'manual' => 'success',
            'auto_partial'         => 'warning',
            'ignored'              => 'neutral',
            default                => 'danger',
        };
    }

    private function cashStatusVariant(string $s): string
    {
        return match ($s) {
            'posted'   => 'success',
            'reversed' => 'danger',
            default    => 'neutral',
        };
    }

    private function assetStatusVariant(string $s): string
    {
        return match ($s) {
            'in_use'   => 'success',
            'disposed' => 'neutral',
            default    => 'warning',
        };
    }

    // ─────────────────────────────────────────────────────────────── DB utils ──

    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $params): array
    {
        // +1 řádek nad limit, aby šlo poznat oříznutí i bez COUNT dotazu.
        $stmt = $this->db->pdo()->prepare($sql . ' LIMIT ' . (self::MAX_BLOCK_ROWS + 1));
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function countRows(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
