<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;

final class MoneyS3CashRegisterAccountTest extends MoneyS3ImportTestCase
{
    public function testCreatesCashAnalyticMissingFromMoneyChartAndJournal(): void
    {
        $supplier = $this->supplier();
        $this->useCashAccount('211779');
        $protocol = $this->import($supplier);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('cash_registers', $supplier, "account_code = '211.779'"));
        $accounts = $this->container(ChartOfAccountsRepository::class);
        self::assertSame($accounts->findByCode($supplier, '211')['id'], $accounts->findByCode($supplier, '211.779')['parent_id']);
    }

    public function testInvalidPrimaryCashAccountFailsWithProtocolError(): void
    {
        $supplier = $this->supplier();
        $this->useCashAccount('xxxxxx');
        $protocol = $this->import($supplier);
        $cash = array_column($protocol->toArray()['steps'], null, 'key')['cash'];
        self::assertSame('cash', $protocol->toArray()['failure']);
        self::assertContains('cash_register_account_missing', array_column($cash['messages'], 'code'), $this->explain($protocol));
        self::assertSame(0, $this->rowCount('cash_registers', $supplier));
    }

    private function useCashAccount(string $account): void
    {
        $files = SyntheticAgenda::files();
        foreach (['ROK.001', 'ROK.002'] as $dir) {
            $files = SyntheticAgenda::patch($files, $dir . '/SzUcPokl.DAT', static fn (array $row): array =>
                $row['Zkrat'] === 'PO' ? array_replace($row, ['PrimUcet' => $account]) : $row);
        }
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);
    }
}
