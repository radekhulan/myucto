<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Payments\PaymentPostingDate;
use PDO;

final class PaymentWorkflowJournalPlan
{
    public function __construct(private readonly Connection $db) {}

    public function current(int $supplier, string $date, string $sourceType, array $lines): array
    {
        $journal = $this->resolve($supplier, $date, $sourceType, $lines);
        (new SystemDimensionWritePolicy($this->db))->assertJournal($journal['header'], $journal['lines']);
        return $journal;
    }

    public function currentPayment(\MyInvoice\Service\Property\Payments\PaymentHistoricalCarrierProof $proof): array
    {
        $journal = $proof->journal($this->db);
        (new SystemDimensionWritePolicy($this->db))->assertPropertyPaymentCarrierJournal($journal['header'], $journal['lines'], $proof);
        return $journal;
    }

    public function resolve(int $supplier, string $date, string $sourceType, array $lines): array
    {
        (new PaymentPostingDate($this->db))->assertOpen($supplier, $date);
        $q = $this->db->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? ORDER BY starts_on,id FOR UPDATE');
        $q->execute([$supplier, $date, $date]);
        $period = (int) $q->fetchColumn();
        $accounts = array_column((new \MyInvoice\Repository\AccountDefinitionRepository($this->db))->currentForDate($supplier, $date), null, 'account_code');
        foreach ($lines as $index => &$line) {
            $account = $accounts[$line['account_code']] ?? null;
            if ($account === null || empty($account['is_active']) || !in_array($account['account_type'], ['asset', 'liability', 'equity'], true)) {
                PaymentException::reject('workflow_account_invalid', ['account_code' => $line['account_code']]);
            }
            $line['account_id'] = (int) $account['id'];
            $line['line_no'] = $index + 1;
            $line['amount'] = JournalLineAmount::decimal($line['amount']);
            if (bccomp($line['amount'], '0', 2) <= 0 || !in_array($line['side'], ['debit', 'credit'], true)
                || !empty($line['is_red_storno']) || !self::normalizeFx($line)) PaymentException::reject('workflow_ledger_invalid');
        }
        unset($line);
        PostingService::assertBalanced($lines);
        $header = ['supplier_id' => $supplier, 'period_id' => $period, 'entry_date' => $date, 'document_date' => $date, 'source_type' => $sourceType];
        return ['header' => $header, 'lines' => $lines];
    }

    private static function normalizeFx(array &$line): bool
    {
        $currency = $line['currency_code'] ?? null;
        $foreign = $line['amount_foreign'] ?? null;
        $rate = $line['fx_rate'] ?? null;
        if ($currency === null && $foreign === null && $rate === null) return true;
        if ($line['account_code'] !== '379.900' || $currency !== 'EUR'
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || !is_string($foreign) || !is_string($rate)
            || preg_match('/^(0|[1-9][0-9]*)(\.[0-9]{1,6})?$/D', $rate) !== 1) return false;
        $line['amount_foreign'] = JournalLineAmount::decimal($foreign);
        $line['fx_rate'] = bcround($rate, 6);
        if (bccomp($line['amount_foreign'], '0', 2) <= 0 || bccomp($line['fx_rate'], '0', 6) <= 0) return false;
        // Účetní hodnotu i měnové jednotky dílčího čerpání dokládá ClearingCells a živý PaymentHistoricalCarrierProof.
        // Převzatý kurz neprovádí nový přepočet již zaokrouhleného podílu měnových jednotek.
        return true;
    }
}
