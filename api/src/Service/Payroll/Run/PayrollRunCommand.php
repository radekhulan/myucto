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
    /**
     * „Obnovit podklady" — nový snímek vstupů do OTEVŘENÉ revize.
     *
     * `calculate` počítá ze snímku zmrazeného při zámku nebo znovuotevření,
     * takže vstup schválený až potom (doplatek, oprava, zapomenutý příplatek)
     * ani opravená zákonná evidence se do revize nedostaly jinak než přes
     * zrušení a nové otevření. Tenhle příkaz založí novou revizi téhož druhu
     * stejnou cestou jako zámek (snímek, graf, zámek schválených vstupů)
     * a rozpracovanou zahodí. Schválená revize se jím nemění nikdy.
     */
    case REFRESH_INPUTS = 'refresh_inputs';
    case REVIEW = 'review';
    case APPROVE = 'approve';
    case POST = 'post';
    case PREPARE_PAYMENTS = 'prepare_payments';
    case MARK_PAID = 'mark_paid';
    case CLOSE = 'close';
    case REQUEST_CORRECTION = 'request_correction';
    /**
     * „Vzít žádost o opravu zpět" — omylem vyžádaná oprava vrací běh do stavu,
     * ze kterého se o ni žádalo. Schválená revize se tím nemění, dokud oprava
     * nebyla otevřená, žádná nová revize nevznikla.
     */
    case WITHDRAW_CORRECTION = 'withdraw_correction';
    /**
     * „Změnit datum výplaty" před schválením. U běhu se snímkem vznikne nová
     * revize s novým datem (snímek, lhůty i zákonný výpočet na datu závisí),
     * u konceptu a zrušeného běhu se změní jen datum.
     */
    case CHANGE_PAYMENT_DATE = 'change_payment_date';
    /**
     * „Vyřadit osobu z běhu" — nová revize bez osoby, kterou nejde spočítat
     * (ruční kontrola), aby nezastavila výplatu ostatním. Doplatek jde opravou
     * běhu za stejné období, viz {@see PayrollRunExclusionFilter}.
     */
    case EXCLUDE_EMPLOYEE = 'exclude_employee';
    /** „Vrátit osobu do běhu" — zruší vyřazení a založí revizi s ní. */
    case INCLUDE_EMPLOYEE = 'include_employee';
    case REOPEN = 'reopen';
    case CANCEL = 'cancel';
}
