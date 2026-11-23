<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\Vat\VatClearingService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Uzávěrkové a zúčtovací zápisy po dvojicích. Uzávěrka 710/702 a otevření 701 jsou
 * pokryté na úrovni builderu (AutomaticEntryPairsTest), tady zúčtování DPH, které
 * čte obraty deníku a zaúčtuje dvě dvojice proti 343.900.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class ClosingPairsTest extends PairsTestCase
{
    public function testVatClearingIsTwoPairs(): void
    {
        $date = self::YEAR . '-06-15';
        $meta = ['entry_date' => $date, 'posted_by' => $this->userId];
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1210.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.00],
            ['account_code' => '343.200', 'side' => 'credit', 'amount' => 210.00],
        ], $meta);
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 500.00],
            ['account_code' => '343.100', 'side' => 'debit', 'amount' => 105.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 605.00],
        ], $meta);

        $result = $this->container->get(VatClearingService::class)->postForPeriod($this->supplierId, self::YEAR, 6, ['user_id' => $this->userId]);

        self::assertNotNull($result['entry_id'] ?? null, 'Zúčtování DPH se zaúčtovalo.');
        self::assertCount(2, $this->assertEntryPaired($this->db->pdo(), $this->supplierId, (int) $result['entry_id'], '315.00'));
    }
}
