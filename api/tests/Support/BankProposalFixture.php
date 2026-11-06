<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Payments\BankProposalService;

/** Vydané předpisy, dluhy jednotek a příchozí pohyby pro testy párování z banky. */
trait BankProposalFixture
{
    /** @return array{batch:array, debts:array<string,int>, totals:array<string,string>} */
    private function postedDebts(Connection $db, array $f, array $codes): array
    {
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $db);
        $issuance = $container->get(IssuanceRepository::class);
        $request = $f['issue_request'];
        $preview = (new IssuancePreviewService($db))->preview($f['supplier'], $request);
        $batch = $issuance->issue($f['supplier'], ['request' => $request, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'bank-proposal-batch'], $f['actor']);
        $batch = $issuance->post($f['supplier'], $batch['id'], array_map(fn ($c) => ['id' => $c['id'], 'row_version' => 1], $batch['charges']), $f['actor']);
        $debts = [];
        $totals = [];
        foreach ($codes as $code) {
            $charge = array_values(array_filter($batch['charges'], fn ($c) => $c['unit_id'] === $f['units'][$code]))[0];
            $q = $db->pdo()->prepare('SELECT id, amount_cents FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? AND amount_cents>0 ORDER BY id');
            $q->execute([$f['supplier'], $charge['id']]);
            $lines = $q->fetchAll(\PDO::FETCH_ASSOC);
            // Dluh zaregistrovalo zaúčtování dávky (ChargeDebtRegistrar), jeden na předpis se všemi kladnými řádky.
            $q = $db->pdo()->prepare('SELECT id FROM unit_payment_debts WHERE supplier_id=? AND charge_id=? ORDER BY id');
            $q->execute([$f['supplier'], $charge['id']]);
            $ids = array_map('intval', $q->fetchAll(\PDO::FETCH_COLUMN));
            if (count($ids) !== 1) throw new \LogicException('Posted charge must own exactly one registered debt.');
            $debts[$code] = $ids[0];
            $totals[$code] = array_reduce($lines, fn ($sum, $l) => bcadd($sum, (string) $l['amount_cents'], 0), '0');
        }
        return ['batch' => $batch, 'debts' => $debts, 'totals' => $totals];
    }

    /** Nespárovaný příchozí pohyb na výpisu firmy, zatím bez zaúčtování. */
    private function incoming(Connection $db, int $supplier, string $amount, array $fields = []): int
    {
        $pdo = $db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic SVJ statement',?,'2000000042','0100','CZK','2026-02-01')")
            ->execute([$supplier, hash('sha256', random_bytes(20))]);
        $statement = (int) $pdo->lastInsertId();
        $row = $fields + ['posted_at' => '2026-02-01', 'variable_symbol' => null, 'specific_symbol' => null, 'counterparty_account' => null,
            'counterparty_bank' => null, 'counterparty_name' => 'Synthetic payer', 'description' => 'Synthetic payment'];
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,variable_symbol,specific_symbol,counterparty_account,counterparty_bank,counterparty_name,description) VALUES (?,?,?,'CZK',?,?,?,?,?,?)")
            ->execute([$statement, $row['posted_at'], $amount, $row['variable_symbol'], $row['specific_symbol'], $row['counterparty_account'],
                $row['counterparty_bank'], $row['counterparty_name'], $row['description']]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Minimální GPC výpis (074 + 075) s příchozími platbami. Rok 2026, data smyšlená.
     *
     * @param list<array{amount_cents:string,vs:string,ref:string}> $payments
     */
    private static function gpc(string $account, string $statementNo, array $payments): string
    {
        $acc16 = str_pad($account, 16, '0', STR_PAD_LEFT);
        $total = array_reduce($payments, fn ($sum, $p) => bcadd($sum, $p['amount_cents'], 0), '0');
        $lines = ['074' . $acc16 . str_pad('SYNTHETIC SVJ', 20) . '310126'
            . str_pad('0', 14, '0', STR_PAD_LEFT) . '+' . str_pad($total, 14, '0', STR_PAD_LEFT) . '+'
            . str_pad('0', 14, '0', STR_PAD_LEFT) . '+' . str_pad($total, 14, '0', STR_PAD_LEFT) . '+'
            . str_pad($statementNo, 3, '0', STR_PAD_LEFT) . '010226'];
        foreach ($payments as $payment) {
            $lines[] = '075' . $acc16 . str_pad('', 16, '0') . str_pad($payment['ref'], 13, '0', STR_PAD_LEFT)
                . str_pad($payment['amount_cents'], 12, '0', STR_PAD_LEFT) . '2' . str_pad($payment['vs'], 10, '0', STR_PAD_LEFT)
                . '00' . '0000' . '0000' . str_pad('', 10, '0') . '010226' . str_pad('SYNTHETIC PLATBA', 20) . '00203' . '010226';
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    private function proposalService(Connection $db): BankProposalService
    {
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $db);
        return $container->get(BankProposalService::class);
    }
}
