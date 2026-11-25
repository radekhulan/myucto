<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

/** Vazební tabulka s FK na konkrétní druh záznamu. */
enum TagTarget: string
{
    case RecurringTemplate = 'recurring_template_tags';

    public function entityTable(): string
    {
        return match ($this) {
            self::RecurringTemplate => 'recurring_invoice_templates',
        };
    }
}
