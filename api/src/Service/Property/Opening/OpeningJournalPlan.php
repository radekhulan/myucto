<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Opening;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\{JournalLineAmount,PostingService};
use MyInvoice\Service\Property\Payments\PaymentPostingDate;
use PDO;
final class OpeningJournalPlan
{
    public function __construct(private readonly Connection $db){}
    public function resolve(int $supplier, string $date, string $sourceType, array $lines): array
    {
        (new PaymentPostingDate($this->db))->assertOpen($supplier, $date);
        $q = $this->db->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? ORDER BY starts_on,id FOR UPDATE');
        $q->execute([$supplier, $date, $date]);
        $period = (int) $q->fetchColumn();
        $q = $this->db->pdo()->prepare('SELECT id,account_code,account_type,is_active FROM ' . \MyInvoice\Repository\AccountDefinitionRepository::relation($date, $supplier, true) . ' a WHERE supplier_id=? ORDER BY id FOR UPDATE');
        $q->execute([$supplier]);
        $accounts = array_column($q->fetchAll(PDO::FETCH_ASSOC), null, 'account_code');
        $openingAccount=\MyInvoice\Service\Accounting\AccountRoleResolver::fromConnection($this->db)->forDate($supplier,$date)->account('closing.opening_balance');
        foreach ($lines as $index => &$line) {
            $account = $accounts[$line['account_code']] ?? null;
            if ($account === null || empty($account['is_active']) || (!in_array($account['account_type'], ['asset', 'liability', 'equity'], true)&&$line['account_code']!==$openingAccount)) {
                OpeningException::reject('workflow_account_invalid', ['account_code' => $line['account_code']]);
            }
            $line['account_id'] = (int) $account['id'];
            $line['line_no'] = $index + 1;
            $line['amount'] = JournalLineAmount::decimal($line['amount']);
            if (bccomp($line['amount'], '0', 2) <= 0 || !in_array($line['side'], ['debit', 'credit'], true)
                || !empty($line['is_red_storno']) || ($line['currency_code'] ?? null) !== null
                || ($line['amount_foreign'] ?? null) !== null || ($line['fx_rate'] ?? null) !== null) OpeningException::reject('workflow_ledger_invalid');
        }
        unset($line);
        PostingService::assertBalanced($lines);
        $header = ['supplier_id' => $supplier, 'period_id' => $period, 'entry_date' => $date, 'document_date' => $date, 'source_type' => $sourceType];
        return ['header' => $header, 'lines' => $lines];
    }
}
