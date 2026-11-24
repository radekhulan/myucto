<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3VatRateAccounts;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;

final class MoneyS3VatRatePrefillTest extends MoneyS3ImportTestCase
{
    public function testImportPrefillsVatAccountsAndRepairDryRunDoesNotWrite(): void
    {
        $supplier = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithVatRateAccounts());
        $backup = $this->backup();
        $protocol = $this->importer->run($supplier, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $rules = $this->container(PostingRuleRepository::class);
        $expected = [
            'invoice.vat.output.21' => ['credit_account_code', '343.310'],
            'invoice.vat.output.12' => ['credit_account_code', '343.320'],
            'invoice.vat.input.21' => ['debit_account_code', '343.110'],
            'invoice.vat.input.12' => ['debit_account_code', '343.120'],
        ];
        foreach ($expected as $key => [$side, $code]) {
            self::assertSame($code, $rules->resolve($supplier, $key)[$side]);
        }
        $key = 'invoice.vat.output.12';
        $this->db->pdo()->prepare('DELETE FROM posting_rules WHERE supplier_id = ? AND rule_key = ?')->execute([$supplier, $key]);
        $repair = $this->container(MoneyS3VatRateAccounts::class);
        $preview = $repair->run($backup, $supplier);
        self::assertSame([$key], array_column($preview, 'rule_key'));
        self::assertNull($rules->resolve($supplier, $key));
        self::assertSame($preview, $repair->run($backup, $supplier, true));
        self::assertSame('343.320', $rules->resolve($supplier, $key)['credit_account_code']);
        self::assertSame([], $repair->run($backup, $supplier, true));
    }
}
