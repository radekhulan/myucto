<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final class PayrollRunWorkflow
{
    /**
     * @return list<PayrollRunCommand>
     */
    public function availableCommands(PayrollRunStatus $status): array
    {
        return match ($status) {
            PayrollRunStatus::DRAFT => [
                PayrollRunCommand::LOCK_INPUTS,
                PayrollRunCommand::CANCEL,
            ],
            PayrollRunStatus::INPUTS_LOCKED,
            PayrollRunStatus::REOPENED => [
                PayrollRunCommand::CALCULATE,
                PayrollRunCommand::CANCEL,
            ],
            // `APPROVE` je dostupné už z `CALCULATED`: bez pravidla čtyř očí
            // byla kontrola jen kliknutím navíc — workflow u ní nikdy neověřilo
            // jinou osobu, jen to, že je vyplněná. Schválení si proto kontrolu
            // zaznamená samo ({@see PayrollRunCommandService}), stav `REVIEWED`
            // i jeho stopa v historii zůstávají. `REVIEW` zůstává dostupný pro
            // toho, kdo krok chce projít zvlášť.
            PayrollRunStatus::CALCULATED => [
                PayrollRunCommand::CALCULATE,
                PayrollRunCommand::REVIEW,
                PayrollRunCommand::APPROVE,
                PayrollRunCommand::CANCEL,
            ],
            PayrollRunStatus::REVIEWED => [
                PayrollRunCommand::CALCULATE,
                PayrollRunCommand::APPROVE,
                PayrollRunCommand::CANCEL,
            ],
            PayrollRunStatus::APPROVED => [
                PayrollRunCommand::POST,
                PayrollRunCommand::REQUEST_CORRECTION,
            ],
            PayrollRunStatus::POSTED => [
                PayrollRunCommand::PREPARE_PAYMENTS,
                PayrollRunCommand::REQUEST_CORRECTION,
            ],
            PayrollRunStatus::PAYMENT_READY => [
                PayrollRunCommand::MARK_PAID,
                PayrollRunCommand::REQUEST_CORRECTION,
            ],
            PayrollRunStatus::PAID => [
                PayrollRunCommand::CLOSE,
                PayrollRunCommand::REQUEST_CORRECTION,
            ],
            PayrollRunStatus::CLOSED => [
                PayrollRunCommand::REQUEST_CORRECTION,
            ],
            PayrollRunStatus::CORRECTION_PENDING => [
                PayrollRunCommand::REOPEN,
            ],
            PayrollRunStatus::CANCELLED => [
                PayrollRunCommand::REOPEN,
            ],
        };
    }

    public function transition(
        PayrollRunStatus $from,
        PayrollRunCommand $command,
        PayrollRunTransitionContext $context,
    ): PayrollRunTransition {
        if (!in_array($command, $this->availableCommands($from), true)) {
            throw new \DomainException(sprintf(
                'Přechod %s není ze stavu %s povolen.',
                $command->value,
                $from->value,
            ));
        }

        $this->assertPreconditions($from, $command, $context);

        $to = match ($command) {
            PayrollRunCommand::LOCK_INPUTS => PayrollRunStatus::INPUTS_LOCKED,
            PayrollRunCommand::CALCULATE => PayrollRunStatus::CALCULATED,
            PayrollRunCommand::REVIEW => PayrollRunStatus::REVIEWED,
            PayrollRunCommand::APPROVE => PayrollRunStatus::APPROVED,
            PayrollRunCommand::POST => PayrollRunStatus::POSTED,
            PayrollRunCommand::PREPARE_PAYMENTS => PayrollRunStatus::PAYMENT_READY,
            PayrollRunCommand::MARK_PAID => PayrollRunStatus::PAID,
            PayrollRunCommand::CLOSE => PayrollRunStatus::CLOSED,
            PayrollRunCommand::REQUEST_CORRECTION => PayrollRunStatus::CORRECTION_PENDING,
            PayrollRunCommand::REOPEN => PayrollRunStatus::REOPENED,
            PayrollRunCommand::CANCEL => PayrollRunStatus::CANCELLED,
        };

        return new PayrollRunTransition($from, $to, $command);
    }

    private function assertPreconditions(
        PayrollRunStatus $from,
        PayrollRunCommand $command,
        PayrollRunTransitionContext $context,
    ): void {
        if ($command === PayrollRunCommand::LOCK_INPUTS
            && !$context->hasImmutableSnapshot
        ) {
            throw new \DomainException('Vstupy nelze uzamknout bez neměnného snapshotu.');
        }
        if ($command === PayrollRunCommand::CALCULATE
            && !$context->hasImmutableSnapshot
        ) {
            throw new \DomainException('Výpočet vyžaduje neměnný snapshot vstupů.');
        }
        if (in_array($command, [
            PayrollRunCommand::REVIEW,
            PayrollRunCommand::APPROVE,
        ], true) && !$context->hasCalculatedResult) {
            throw new \DomainException('Kontrola a schválení vyžadují uložený výsledek.');
        }
        if ($command === PayrollRunCommand::APPROVE) {
            if ($context->fourEyesRequired && $context->reviewedBy === null) {
                throw new \DomainException('Před schválením musí být evidována odborná kontrola.');
            }
            if ($context->blockerCount > 0) {
                throw new \DomainException('Mzdový běh obsahuje blokující validace.');
            }
            if ($context->unresolvedOverrideCount > 0) {
                throw new \DomainException('Mzdový běh obsahuje nevyřešená varování.');
            }
        }
        if ($command === PayrollRunCommand::POST && !$context->hasPostingBatch) {
            throw new \DomainException('Zaúčtování vyžaduje schválenou účetní dávku.');
        }
        if ($command === PayrollRunCommand::MARK_PAID && !$context->hasPaymentBatch) {
            throw new \DomainException('Označení za uhrazené vyžaduje platební dávku.');
        }
        if (in_array($command, [
            PayrollRunCommand::REQUEST_CORRECTION,
            PayrollRunCommand::REOPEN,
            PayrollRunCommand::CANCEL,
        ], true) && trim((string) $context->reason) === '') {
            throw new \DomainException('Tento přechod vyžaduje uvedení důvodu.');
        }
        if ($command === PayrollRunCommand::REOPEN
            && !in_array($from, [
                PayrollRunStatus::CORRECTION_PENDING,
                PayrollRunStatus::CANCELLED,
            ], true)
        ) {
            throw new \DomainException(
                'Novou revizi lze otevřít jen ze zrušeného nebo opravného běhu.',
            );
        }
    }
}
