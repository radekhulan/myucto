<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Accounting\Bank\BankAnalyticResolver;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Bank\BankTransactionContext;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceLocks;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\Payments\PaymentPostingDate;
use MyInvoice\Service\Property\Payments\PaymentSourceProof;
use MyInvoice\Service\Property\PropertyInput;
use PDO;

final class PaymentSourceEligibility
{
    public function __construct(
        private readonly Connection $db,
        private readonly SupplierBankAccountRepository $bankAccounts,
        private readonly BankAnalyticResolver $bankAnalytics,
    ) {}

    public function assertConnection(Connection $db): void
    {
        if ($db->pdo() !== $this->db->pdo() || !$db->pdo()->inTransaction()) throw new \LogicException('Source eligibility requires the current owner connection.');
    }

    public function current(int $supplier, array $source): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Payment eligibility requires an owner transaction.');
        (new PaymentLocks($this->db))->acquire($supplier);
        $kind = $source['kind'] ?? null;
        PropertyInput::fields($source, $kind === 'cash' ? ['kind', 'id', 'draft'] : ['kind', 'id']);
        $draft = null;
        $register = null;
        if ($kind === 'bank') {
            $id = PropertyInput::id($source['id'] ?? null, 'source.id');
            $raw = (new BankTransactionContext($this->db))->lock($supplier, $id);
            if ($raw === null) PaymentException::reject('source_not_found');
            if (($raw['source'] ?? 'statement') !== 'statement' || $raw['match_status'] !== 'unmatched' || $raw['matched_invoice_id'] !== null || !empty($raw['has_explicit_allocation'])) PaymentException::reject('source_owned_elsewhere');
            foreach (['payment_matches', 'invoice_payments', 'payroll_payment_matches'] as $table) {
                if ($this->one('SELECT id FROM ' . $table . ' WHERE bank_transaction_id=? LIMIT 1 FOR UPDATE', [$id]) !== null) PaymentException::reject('source_owned_elsewhere');
            }
            $currency = $raw['currency'] ?: $raw['statement_currency'];
            $amount = JournalLineAmount::decimal($raw['amount']);
            $date = $raw['posted_at'];
            $direction = bccomp($amount, '0', 2) > 0 ? 'in' : 'out';
            $moneyAccount = null;
        } elseif ($kind === 'cash') {
            if (array_key_exists('id', $source) === array_key_exists('draft', $source)) PropertyInput::invalid('source');
            if (isset($source['id'])) {
                $id = PropertyInput::id($source['id'], 'source.id');
                $raw = $this->one('SELECT * FROM cash_documents WHERE supplier_id=? AND id=? FOR UPDATE', [$supplier, $id]);
                if ($raw === null) PaymentException::reject('source_not_found');
                if (!in_array($raw['status'], ['draft', 'posted'], true) || $raw['purpose'] !== 'other' || $raw['vat_mode'] !== 'none'
                    || $raw['invoice_id'] !== null || $raw['purchase_invoice_id'] !== null || $raw['invoice_payment_id'] !== null || $raw['reversal_entry_id'] !== null) PaymentException::reject('source_owned_elsewhere');
                if ($this->one('SELECT id FROM payroll_payment_matches WHERE cash_document_id=? LIMIT 1 FOR UPDATE', [$id]) !== null) PaymentException::reject('source_owned_elsewhere');
                $draft = $raw;
            } else {
                $id = null;
                if (!is_array($source['draft'])) PropertyInput::invalid('source.draft');
                $draft = $this->cashDraft($source['draft']);
                $raw = $draft;
            }
            $register = $this->one('SELECT * FROM cash_registers WHERE supplier_id=? AND id=? FOR UPDATE', [$supplier, $draft['register_id']]);
            if ($register === null) PaymentException::reject('cash_register_not_found');
            $currency = ($raw['status'] ?? 'draft') === 'posted' ? $raw['currency_code'] : $register['currency_code'];
            $amount = JournalLineAmount::decimal($draft['total_amount']);
            $date = $draft['issue_date'];
            $direction = $draft['doc_type'];
            $moneyAccount = $register['account_code'];
        } else {
            PaymentException::reject('invalid_source');
        }
        if (!in_array($direction, ['in', 'out'], true)) PaymentException::reject('source_direction');
        $cents = bcmul(ltrim($amount, '-'), '100', 0);
        if (bccomp($cents, '0', 0) <= 0) PaymentException::reject('source_amount');
        (new IssuanceLocks($this->db))->assertFramework($supplier, $date);
        $journal = $id === null ? [] : $this->all('SELECT id,posted_at,reversed_by FROM journal_entries WHERE supplier_id=? AND source_type=? AND source_id=? ORDER BY id FOR UPDATE', [$supplier, $kind, $id]);
        $existing = null;
        if ($journal !== []) {
            if (count($journal) !== 1 || $journal[0]['posted_at'] === null || $journal[0]['reversed_by'] !== null) PaymentException::reject('source_owned_elsewhere');
            $existing = (new PaymentSourceProof($this->db))->current($supplier, $kind, $id);
            $moneyLines = array_values(array_filter($existing['receipt']['ledger']['lines'], static fn (array $line): bool => str_starts_with($line['account_code'], $kind === 'bank' ? '221' : '211')));
            if (count($moneyLines) !== 1) PaymentException::reject('source_clearing_mismatch');
            $moneyAccount = $moneyLines[0]['account_code'];
            $cents = $existing['receipt']['amount_cents'];
            $currency = $existing['receipt']['currency'];
        } else {
            if ($currency !== 'CZK') PaymentException::reject('fx_review_required', ['incomplete' => true]);
            if ($kind === 'cash' && ($raw['status'] ?? 'draft') !== 'draft') PaymentException::reject('source_owned_elsewhere');
            (new PaymentPostingDate($this->db))->assertOpen($supplier, $date);
            if ($kind === 'cash') {
                if (empty($register['is_active'])) PaymentException::reject('cash_register_inactive');
            } else {
                $own = $this->bankAccounts->matchCounterparty($supplier, $raw['recipient_account'], $raw['recipient_bank'], false, true);
                if (($own['kind'] ?? null) === 'credit_card') PaymentException::reject('unsupported_source', ['source_kind' => 'credit_card']);
                $moneyAccount = $this->bankAnalytics->existingAnalyticCodeFor($supplier, $raw, true);
                if ($own !== null && $moneyAccount === null) PaymentException::reject('source_account_setup_required', ['bank_account_id' => $own['id']]);
                $moneyAccount ??= '221';
            }
        }
        $account = $this->one('SELECT id,account_code,account_type,is_active FROM chart_of_accounts WHERE supplier_id=? AND account_code=? FOR UPDATE', [$supplier, $moneyAccount]);
        if ($account === null || ($existing === null && empty($account['is_active']))) PaymentException::reject('source_account_setup_required', ['account_code' => $moneyAccount]);
        if ($account['account_type'] !== 'asset' || !str_starts_with($moneyAccount, $kind === 'bank' ? '221' : '211')) PaymentException::reject('unsupported_source', ['account_code' => $moneyAccount]);
        $facts = ['supplier_id' => $supplier, 'kind' => $kind, 'id' => $id, 'direction' => $direction, 'date' => $date,
            'amount_cents' => $cents, 'currency' => $currency, 'native' => $existing['receipt']['native'] ?? null, 'money_account' => $moneyAccount,
            'native_draft' => $draft, 'register' => $register, 'raw' => $raw, 'existing' => $existing, 'money_account_receipt' => $account];
        return $facts + ['source_etag' => self::etag($facts)];
    }

    public static function etag(array $facts): string
    {
        return PropertyInput::hash(['source' => $facts['raw'], 'account' => $facts['money_account_receipt'], 'register' => $facts['register'], 'existing' => $facts['existing']]);
    }

    private function cashDraft(array $data): array
    {
        PropertyInput::fields($data, ['register_id', 'doc_type', 'issue_date', 'partner_name', 'partner_ic', 'partner_dic', 'description', 'total_amount']);
        $amount = $data['total_amount'] ?? null;
        if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/D', $amount)
            || bccomp($amount, '0', 2) <= 0 || bccomp($amount, '9999999999999.99', 2) > 0) PropertyInput::invalid('source.draft.total_amount');
        $date = $data['issue_date'] ?? null;
        if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) PropertyInput::invalid('source.draft.issue_date');
        if (!in_array($data['doc_type'] ?? null, ['in', 'out'], true)) PropertyInput::invalid('source.draft.doc_type');
        return ['register_id' => PropertyInput::id($data['register_id'] ?? null, 'source.draft.register_id'),
            'doc_type' => $data['doc_type'], 'purpose' => 'other', 'issue_date' => $date, 'tax_date' => null,
            'partner_name' => PropertyInput::text($data['partner_name'] ?? null, 'source.draft.partner_name', 255),
            'partner_ic' => isset($data['partner_ic']) ? PropertyInput::text($data['partner_ic'], 'source.draft.partner_ic', 20) : null,
            'partner_dic' => isset($data['partner_dic']) ? PropertyInput::text($data['partner_dic'], 'source.draft.partner_dic', 30) : null,
            'description' => PropertyInput::text($data['description'] ?? null, 'source.draft.description', 4000),
            'total_amount' => JournalLineAmount::decimal($amount), 'currency_code' => 'CZK', 'fx_rate' => '1.000000', 'amount_foreign' => null,
            'vat_mode' => 'none', 'vat_lines' => [], 'rule_key' => null, 'counter_account_code' => '379.900', 'project_id' => null,
            'invoice_id' => null, 'purchase_invoice_id' => null, 'invoice_payment_id' => null, 'status' => 'draft'];
    }

    private function one(string $sql, array $params): ?array
    {
        $q = $this->db->pdo()->prepare($sql); $q->execute($params);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function all(string $sql, array $params): array
    {
        $q = $this->db->pdo()->prepare($sql); $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
