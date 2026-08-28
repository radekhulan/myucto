<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Ví, jestli bankovní pohyb už spotřebovaly mzdy.
 *
 * Mzdová a fakturační rekonciliace se dosud viděly jen jedním směrem:
 * {@see PayrollPaymentReconciliationService} odmítne pohyb, který už vlastní
 * bankovní modul (`match_status <> 'unmatched'`, `matched_invoice_id`,
 * `invoice_payments`, `payment_matches`), ale opačně nic — bankovní matcher
 * o mzdách nevěděl a týž odchozí pohyb mohl podruhé přiřadit k přijaté
 * faktuře. Výsledkem je jedna platba použitá dvakrát a rozpadlé saldo.
 *
 * Mzdy `match_status` samy nepřepisují záměrně: `bank_transactions` je sdílená
 * účetní vrstva a hodnoty `auto_exact`/`manual` mají v účtování a v posting
 * backfillu vlastní význam (viz BankPostingService) — označit jimi mzdovou
 * platbu by znamenalo tvrdit, že pohyb patří k faktuře. Místo přepisu stavu
 * se proto bankovní strana ptá téhle stráže.
 *
 * Čte se přes VŠECHNY firmy záměrně: pohyb je fyzicky jeden a to, že si ho
 * nárokovaly mzdy jiné firmy, je pro fakturační párování stejná překážka
 * (stejný pohled má `bankEvidenceOwnership()` na mzdové straně).
 *
 * @see \MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationService
 */
final class PayrollBankEvidenceGuard
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Je pohyb spotřebovaný mzdovou platbou?
     *
     * Stačí jediný záznam v `payroll_payment_matches`, a to i storno
     * (`event_kind = 'reversed'`): storno se podle
     * {@see PayrollPaymentReconciliationService::assertEvidence()} vždy opírá
     * o VLASTNÍ pohyb opačného směru (vrácenou částku), ne o ten původní.
     * Obsazený je tedy jak původní odchozí pohyb, tak příchozí vratka —
     * ani jeden z nich už nesmí jít podruhé na fakturu.
     */
    public function isUsedByPayroll(int $bankTransactionId): bool
    {
        if ($bankTransactionId <= 0) {
            return false;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT EXISTS (
                        SELECT 1
                          FROM payroll_payment_matches
                         WHERE bank_transaction_id = ?
                    )',
        );
        $statement->execute([$bankTransactionId]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * Tolerantní varianta pro volání ze sdílené bankovní vrstvy: chybějící
     * mzdová tabulka (instance bez mzdového modulu, starší schéma) nesmí
     * shodit párování faktur.
     */
    public function isUsedByPayrollSafely(int $bankTransactionId): bool
    {
        try {
            return $this->isUsedByPayroll($bankTransactionId);
        } catch (\PDOException) {
            return false;
        }
    }
}
