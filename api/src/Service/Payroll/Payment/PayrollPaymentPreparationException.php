<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

final class PayrollPaymentPreparationException extends \DomainException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $employeeId = null,
    ) {
        parent::__construct($message);
    }

    public static function issue(string $liabilityKind, \Throwable $exception): array
    {
        $reason = $exception instanceof self ? $exception->reason : 'support_required';
        $path = '/admin/support';
        $action = 'contact_support';
        $message = 'Přípravu této platby zastavila kontrola uložených podkladů. '
            . 'Obnovte přehled a zkuste přípravu znovu. Pokud se problém opakuje, '
            . 'předejte správci aplikace druh platby, revizi a rozbalené technické podrobnosti. '
            . 'Platební údaje ani schválenou mzdu kvůli této hlášce neměňte.';
        if (in_array($reason, ['institution_account_missing', 'institution_account_ambiguous', 'institution_code_invalid'], true)) {
            $message = $exception->getMessage();
            $path = '/payroll/settings?tab=institutions';
            $action = 'open_institution_accounts';
        } elseif (in_array($reason, ['person_account_not_effective', 'person_account_unverified'], true)
            && $exception instanceof self && $exception->employeeId !== null
        ) {
            $message = ($reason === 'person_account_not_effective'
                ? 'Výplatní účet uložený ve schválené mzdě není platný k datu výplaty.'
                : 'Výplatní účet uložený ve schválené mzdě nemá doložené ověření.')
                . ' Na kartě zaměstnance zkontrolujte výplatní účet a jeho ověření. '
                . 'Tato revize používá uložený stav účtu; po opravě podkladů připravte '
                . 'a schvalte opravnou revizi, původní revizi nepřepisujte.';
            $path = '/payroll/people?person=' . $exception->employeeId . '&panel=accounts';
            $action = 'open_person_accounts';
        } elseif (in_array($reason, ['revision_missing', 'revision_not_approved', 'revision_not_current', 'revision_not_ready'], true)) {
            $message = $exception->getMessage()
                . ' Otevřete mzdové běhy a připravte platby z aktuální schválené revize. '
                . 'Pokud byla mezitím revize změněna, nejprve obnovte přehled plateb.';
            $path = '/payroll/runs';
            $action = 'open_runs';
        } else {
            $reason = 'support_required';
        }

        return [
            'liability_kind' => $liabilityKind,
            'reason' => $reason,
            'message' => $message,
            'remediation_path' => $path,
            'remediation_action' => $action,
            'technical_detail' => $reason === 'support_required' ? $exception->getMessage() : null,
        ];
    }
}
