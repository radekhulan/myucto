<?php

declare(strict_types=1);

namespace MyInvoice\Service\Crm;

/** Oddělená nezávazná vrstva konceptů; výkazy se do inkasa samostatně nepřičítají. */
final class DraftCashFlow
{
    /** Stejný výběr pro agregaci i stránkovaný seznam konceptů bez budoucí splatnosti. */
    public static function eligibleSql(string $alias = 'i'): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias faktury.');
        }
        return "$alias.status = 'draft'
            AND $alias.invoice_type NOT IN ('proforma','rent_calendar','advance_calendar')
            AND ($alias.invoice_type NOT IN ('invoice','tax_document') OR $alias.amount_to_pay - $alias.paid_total > 0)";
    }

    /** Přepočítá výhled i po přidání dalších závazků do původního cashflow. */
    public static function withOutlook(array $forecast): array
    {
        if (!array_key_exists('total_draft_in', $forecast)) return $forecast;
        $draftRunning = 0.0;
        foreach ($forecast['weeks'] as &$week) {
            $draftRunning += (float) $week['draft_in'];
            $week['outlook_net'] = round((float) $week['net'] + (float) $week['draft_in'], 2);
            $week['outlook_running'] = round((float) $week['running'] + $draftRunning, 2);
        }
        unset($week);
        $forecast['total_outlook_net'] = round((float) $forecast['total_net'] + (float) $forecast['total_draft_in'], 2);
        return $forecast;
    }
}
