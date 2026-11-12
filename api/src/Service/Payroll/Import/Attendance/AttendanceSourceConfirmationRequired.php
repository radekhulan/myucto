<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\PayrollPeriodLabel;

/**
 * Použití dávky čeká na výslovné potvrzení: podklady se hlásí k jinému
 * období, než je vybrané, nebo období už má mzdové vstupy z jiného importu.
 * Nese výsledek kontrol, ať je klient ukáže bez dalšího náhledu.
 */
final class AttendanceSourceConfirmationRequired extends \DomainException
{
    /**
     * @param array{period:array{selected:string,detected:list<array{period:string,sources:list<string>}>,mismatch:bool},other_sources:list<array<string,mixed>>,requires_confirmation:bool} $checks
     */
    public function __construct(public readonly array $checks)
    {
        $parts = [];
        if ($checks['period']['mismatch']) {
            $parts[] = sprintf(
                'Podklady se podle názvů souborů a listů hlásí k období %s, vybrané je ale %s.',
                implode(', ', array_map(
                    PayrollPeriodLabel::monthYear(...),
                    array_column($checks['period']['detected'], 'period'),
                )),
                PayrollPeriodLabel::monthYear($checks['period']['selected']),
            );
        }
        if ($checks['other_sources'] !== []) {
            $parts[] = sprintf(
                'Období %s už má mzdové vstupy z jiného importu (%d vstupů); použitím by vznikly dvojí vstupy.',
                PayrollPeriodLabel::monthYear($checks['period']['selected']),
                array_sum(array_column($checks['other_sources'], 'active_inputs')),
            );
        }
        parent::__construct(implode(' ', $parts) . ' Zkontrolujte období a použití potvrďte.');
    }
}
