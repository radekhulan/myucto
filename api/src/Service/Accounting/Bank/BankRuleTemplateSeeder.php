<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use PDO;

final class BankRuleTemplateSeeder
{
    public static function seed(PDO $pdo, int $supplierId): int
    {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO bank_rule_templates
                (supplier_id, template_key, name_cs, name_en, direction, operation_type,
                 counterparty_bank, counterparty_prefix, vs_placeholder, message_contains,
                 rule_key, default_priority, sort_order, is_active)
             SELECT ?, template_key, name_cs, name_en, direction, operation_type,
                    counterparty_bank, counterparty_prefix, vs_placeholder, message_contains,
                    rule_key, default_priority, sort_order, is_active
               FROM bank_rule_template_defaults'
        );
        $stmt->execute([$supplierId]);
        return $stmt->rowCount();
    }
}
