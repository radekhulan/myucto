<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\MoneyS3VatRateAccounts;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\TestCase;

final class MoneyS3VatRateAccountsTest extends TestCase
{
    public function testDetectsVatAccountsThroughMoneyPostingCodebooks(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms3vat_' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700);
        try {
            SyntheticAgenda::writeLzFiles($tmp . '/agenda.lz', SyntheticAgenda::filesWithVatRateAccounts());
            $backup = Ms3Backup::extract($tmp . '/agenda.lz', $tmp . '/agenda');
            self::assertSame([
                'output' => [12 => '343.320', 21 => '343.310'],
                'input' => [12 => '343.120', 21 => '343.110'],
            ], MoneyS3VatRateAccounts::detect($backup));
            self::assertSame(['output' => [], 'input' => []], MoneyS3VatRateAccounts::detect($backup, ['ROK.001']));
        } finally {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($tmp);
        }
    }

    public function testPlanKeepsManualOverridesAndUsesOnlyCommonAccountForOneCode(): void
    {
        $chart = [['id' => 1, 'account_code' => '343', 'parent_id' => null, 'is_synthetic' => true, 'is_active' => true]];
        foreach (['343.310', '343.320', '343.110'] as $index => $code) {
            $chart[] = ['id' => $index + 2, 'account_code' => $code, 'parent_id' => 1, 'is_synthetic' => false, 'is_active' => true];
        }
        $plan = MoneyS3VatRateAccounts::plan([
            'output' => [21 => '343.310', 12 => '343.320'],
            'input' => [21 => '343.110', 12 => '343.110'],
        ], $chart, ['invoice.vat.output.12' => ['supplier_id' => 3, 'credit_account_code' => '343.310']]);
        self::assertSame(['invoice.vat.output', 'invoice.vat.output.21', 'invoice.vat.input'], array_column($plan, 'rule_key'));
        self::assertSame(['343.310', '343.310', null], array_column($plan, 'credit_account_code'));
        self::assertSame([null, null, '343.110'], array_column($plan, 'debit_account_code'));
    }
}
