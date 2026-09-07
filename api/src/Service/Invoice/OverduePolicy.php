<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;

/** Hranice zobrazení a filtrů; oprávnění odeslat upomínku drží ReminderService. */
final class OverduePolicy
{
    public function __construct(private readonly Config $config) {}

    public function includesToday(): bool
    {
        return (bool) $this->config->get('invoices.overdue_includes_today', false);
    }

    public function comparisonOperator(): string
    {
        return $this->includesToday() ? '<=' : '<';
    }
}
