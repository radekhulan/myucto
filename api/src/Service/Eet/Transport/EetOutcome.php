<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Výsledek jednoho pokusu o odeslání datové zprávy.
 *
 * - `Confirmed` / `ConfirmedWithWarning`: ověřeně podepsané potvrzení s POK.
 * - `VerificationOk`: ověřovací mód, `Chyba kod=0` — žádná tržba se neeviduje.
 * - `Rejected`: kritická chyba v datech; opakování beze změny nepomůže.
 * - `TemporaryError`: správce daně ohlásil dočasnou technickou chybu.
 * - `Timeout` / `TransportError`: odpověď nedorazila; výsledek je NEZNÁMÝ.
 * - `InvalidResponse`: odpověď dorazila, ale nedá se jí věřit (podpis, UUID, tvar).
 */
enum EetOutcome: string
{
    case Confirmed = 'confirmed';
    case ConfirmedWithWarning = 'confirmed_warning';
    case VerificationOk = 'verification_ok';
    case Rejected = 'rejected';
    case TemporaryError = 'temporary_error';
    case Timeout = 'timeout';
    case TransportError = 'transport_error';
    case InvalidResponse = 'invalid_response';
}
