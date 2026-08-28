<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Čerpání dovolené by přesáhlo zůstatek.
 *
 * Zaměstnavatel smí dovolenou nad rámec nároku poskytnout (a ledger na to má
 * typ `overdrawn`), takže to není nezákonný stav — ale musí to být rozhodnutí,
 * ne překlep v datu. Proto se schválení zastaví a projde až s výslovným
 * potvrzením; tichý minusový zůstatek se pozná až při zúčtování, kdy se přeplatek
 * srazí zaměstnanci ze mzdy.
 *
 * Dědí z InvalidArgumentException, aby ji starší volající, které o přečerpání
 * nevědí, pořád odbavily jako chybu vstupu, ne jako pád.
 */
final class PayrollLeaveOverdrawException extends \InvalidArgumentException
{
    public function __construct(
        public readonly int $balanceMinutes,
        public readonly int $requestedMinutes,
    ) {
        parent::__construct(sprintf(
            'Čerpání %d minut přesahuje zůstatek dovolené %d minut o %d minut.'
            . ' Přečerpání potvrď příznakem overdraw_confirmed.',
            $requestedMinutes,
            $balanceMinutes,
            $requestedMinutes - $balanceMinutes,
        ));
    }
}
