<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Payments\{PaymentException, PaymentPostingDate};
use PDO;

final class ClearingRevisionJournalPlan
{
    public function __construct(private readonly Connection $db) {}

    public function current(int $supplier, string $date, array $provedPlan): array
    {
        (new PaymentPostingDate($this->db))->assertOpen($supplier, $date);
        $q = $this->db->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? ORDER BY starts_on,id FOR UPDATE');
        $q->execute([$supplier, $date, $date]); $period = (int) $q->fetchColumn();
        $q = $this->db->pdo()->prepare('SELECT id,account_code,account_type,is_active FROM ' . \MyInvoice\Repository\AccountDefinitionRepository::relation($date, $supplier, true) . " a WHERE supplier_id=? AND account_code='379.900' FOR UPDATE");
        $q->execute([$supplier]); $account = $q->fetch(PDO::FETCH_ASSOC);
        if (!$account || !(bool) $account['is_active'] || $account['account_type'] !== 'liability') PaymentException::reject('workflow_account_invalid', ['account_code' => '379.900']);
        $lines = $provedPlan['lines'];
        foreach ($lines as $index => &$line) {
            if ($line['account_code'] !== '379.900' || !in_array($line['side'], ['debit', 'credit'], true)
                || !empty($line['is_red_storno']) || ($line['currency_code'] ?? null) !== null || ($line['amount_foreign'] ?? null) !== null || ($line['fx_rate'] ?? null) !== null
                || bccomp($line['amount'], '0', 2) <= 0) PaymentException::reject('workflow_ledger_invalid');
            $line['account_id'] = (int) $account['id']; $line['line_no'] = $index + 1;
        }
        unset($line);
        PostingService::assertBalanced($lines);
        $header = ['supplier_id' => $supplier, 'period_id' => $period, 'entry_date' => $date, 'document_date' => $date, 'source_type' => 'unit_payment_revision'];
        $targets = [];
        foreach ($lines as $index => $line) if ($index % 2 === 1) $targets[] = $line;
        (new SystemDimensionWritePolicy($this->db))->assertJournal($header, $targets);
        return ['header' => $header, 'lines' => $lines];
    }
}
