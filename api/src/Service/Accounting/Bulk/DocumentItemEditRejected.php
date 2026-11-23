<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

/**
 * Úprava dokladu z hromadné akce odmítnutá akcí jednoho dokladu (kód a hláška z její
 * odpovědi). Vyhazuje se uvnitř transakce položky, aby se odvolalo i to, co akce
 * stihla zapsat před odmítnutím ({@see DocumentItemEditor::save()}).
 */
final class DocumentItemEditRejected extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, ?string $message = null)
    {
        parent::__construct($message ?? '');
    }
}
