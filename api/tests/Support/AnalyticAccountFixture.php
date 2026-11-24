<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;

trait AnalyticAccountFixture
{
    private function seedAnalyticAccounts(PDO $pdo, int $supplierId, string ...$codes): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             SELECT supplier_id, ?, ?, account_type, normal_side, 0, id, 1
               FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?
             ON DUPLICATE KEY UPDATE is_active = 1'
        );
        foreach ($codes as $code) {
            $stmt->execute([$code, 'Testovací analytika ' . $code, $supplierId, substr($code, 0, 3)]);
        }
    }
}
