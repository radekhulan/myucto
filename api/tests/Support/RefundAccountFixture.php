<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

/** Ručně doložený účet osoby; bez něj PaymentRepository::reserve bankovní vratku odmítne. */
final class RefundAccountFixture
{
    public static function document(\PDO $pdo, int $supplier, int $client, string $account = '1000000005', string $bank = '0100'): void
    {
        $pdo->prepare('INSERT IGNORE INTO client_bank_accounts (supplier_id,client_id,account_number,bank_code,account_key,bank_key,source_manual) VALUES (?,?,?,?,?,?,1)')
            ->execute([$supplier, $client, $account, $bank, ltrim($account, '0'), $bank]);
    }
}
