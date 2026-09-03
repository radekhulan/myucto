<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

enum JmhzControlPassability: string
{
    case Blocking = 'blocking';
    case Passable = 'passable';
    case Unavailable = 'unavailable';

    /**
     * Protokol ČSSZ propustnost chyby neuvedl (starší protokoly prefix
     * „(Propustnost: …)" v popisu nemají). Záměrně NENÍ totéž co propustná —
     * neznalost se nesmí tiše vyložit jako „v pořádku".
     */
    case Unspecified = 'unspecified';
}
