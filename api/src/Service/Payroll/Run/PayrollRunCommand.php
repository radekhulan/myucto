<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

enum PayrollRunCommand: string
{
    case LOCK_INPUTS = 'lock_inputs';
    /**
     * Sloučený krok „Spočítat mzdy" = `LOCK_INPUTS` + `CALCULATE`.
     *
     * Je to VSTUPNÍ BOD API, ne přechod stavu: workflow ho nikdy nenabídne
     * ({@see PayrollRunWorkflow::availableCommands()}) a do historie běhu se
     * nezapisuje — zapisují se obě události pod ním. Existuje proto, že mezi
     * zamknutím a výpočtem se nic lidského nedělo a účetní kvůli tomu
     * klikala dvakrát na jednu práci.
     */
    case LOCK_AND_CALCULATE = 'lock_and_calculate';
    case CALCULATE = 'calculate';
    case REVIEW = 'review';
    case APPROVE = 'approve';
    case POST = 'post';
    case PREPARE_PAYMENTS = 'prepare_payments';
    case MARK_PAID = 'mark_paid';
    case CLOSE = 'close';
    case REQUEST_CORRECTION = 'request_correction';
    case REOPEN = 'reopen';
    case CANCEL = 'cancel';
}
