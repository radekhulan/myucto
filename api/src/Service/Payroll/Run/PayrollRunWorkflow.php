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
            // `REFRESH_INPUTS` je dostupné jen u OTEVŘENÉ revize (snímek,
            // výpočet, kontrola). Schválená revize je neměnná — tam vede cesta
            // dál jen přes vyžádání opravy.
            PayrollRunStatus::INPUTS_LOCKED,
            PayrollRunStatus::REOPENED => [
                PayrollRunCommand::CALCULATE,
                PayrollRunCommand::REFRESH_INPUTS,
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
                PayrollRunCommand::REFRESH_INPUTS,
                PayrollRunCommand::REVIEW,
                PayrollRunCommand::APPROVE,
                PayrollRunCommand::CANCEL,
            ],
            PayrollRunStatus::REVIEWED => [
                PayrollRunCommand::CALCULATE,
                PayrollRunCommand::REFRESH_INPUTS,
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
            // `MARK_PAID` tu zůstává pro AUTOMATICKÉ překlopení z platebního
            // ledgeru ({@see PayrollRunAutoSettlementService}) a pro API;
            // účetní ho jako tlačítko nedostane (viz `PayrollRunsAction`).
            //
            // `CLOSE` je tu proto, že úhrada mezd a uzavření měsíce jsou dva
            // nezávislé děje. Platební příkaz odchází hned, ABO výpis dorazí
            // o týdny později. Kdyby šlo zavřít jen běh v `PAID`, měsíc bez
            // doloženého výpisu by se nezavřel NIKDY a s ním ani mzdový rok
            // (`missingMonths()` počítá jen běhy ve stavu `closed`). Uzavřít
            // měsíc je rozhodnutí účetní; nedoložené úhrady jsou k němu
            // varování v přehledu uzávěrky, ne závora.
            PayrollRunStatus::PAYMENT_READY => [
                PayrollRunCommand::MARK_PAID,
                PayrollRunCommand::CLOSE,
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
            // Sloučený krok není přechod: `availableCommands()` ho nikdy
            // nevrátí, takže sem nedojde (kontrola dostupnosti je výš).
            // Arm tu je proto, aby `match` zůstal úplný a případná chyba
            // volajícího skončila srozumitelně, ne `UnhandledMatchError`.
            PayrollRunCommand::LOCK_AND_CALCULATE => throw new \LogicException(
                'Sloučený krok se provádí jako dva samostatné příkazy.',
            ),
            PayrollRunCommand::LOCK_INPUTS => PayrollRunStatus::INPUTS_LOCKED,
            PayrollRunCommand::CALCULATE => PayrollRunStatus::CALCULATED,
            // Nová revize nemá výsledek, takže běh se vrací tam, odkud se
            // počítá. Druh revize se nemění, proto i stav zůstává „svůj".
            PayrollRunCommand::REFRESH_INPUTS => $context->correctionRevision
                ? PayrollRunStatus::REOPENED
                : PayrollRunStatus::INPUTS_LOCKED,
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
        if ($command === PayrollRunCommand::REFRESH_INPUTS
            && !$context->hasImmutableSnapshot
        ) {
            throw new \DomainException('Podklady lze obnovit jen u běhu s neměnným snapshotem.');
        }
        /*
         * Novější podklady jsou BLOKÁTOR, ne varování s výjimkou.
         *
         * Stejně model zachází s neschváleným vstupem (`draft_inputs_present`):
         * co do revize patří a není v ní, se nepotvrzuje, ale doplňuje. Výjimka
         * by tu nic nerozhodovala — schválený vstup, který revize vynechá,
         * zůstane ve stavu `approved`, žádný běh ho už nezamkne a doplatek
         * se nevyplatí ani nevykáže. Cesta ven je vždy jedno kliknutí
         * („Obnovit podklady" a přepočet), nebo vstup vědomě zrušit či
         * přesunout do jiného období.
         */
        if ($command === PayrollRunCommand::APPROVE && $context->staleSourceCount > 0) {
            throw new \DomainException(sprintf(
                'Od zamknutí vstupů se změnily podklady, se kterými revize '
                . 'počítá (%d). Klikněte na „Obnovit podklady“ a běh přepočítejte '
                . '— schválená revize by je jinak tiše vynechala.',
                $context->staleSourceCount,
            ));
        }
        if (in_array($command, [
            PayrollRunCommand::REVIEW,
            PayrollRunCommand::APPROVE,
        ], true) && !$context->hasCalculatedResult) {
            throw new \DomainException('Kontrola a schválení vyžadují uložený výsledek.');
        }
        if ($command === PayrollRunCommand::APPROVE) {
            if ($context->blockerCount > 0) {
                throw new \DomainException(sprintf(
                    'Mzdový běh obsahuje blokující validace (%d). Seznam s odkazy na místo '
                    . 'opravy je v kontrolách běhu.',
                    $context->blockerCount,
                ));
            }
            if ($context->unresolvedOverrideCount > 0) {
                throw new \DomainException(sprintf(
                    'Mzdový běh obsahuje nevyřešená varování (%d). U každého v kontrolách '
                    . 'běhu schvalte výjimku s odůvodněním, nebo příčinu opravte.',
                    $context->unresolvedOverrideCount,
                ));
            }
        }
        if ($command === PayrollRunCommand::POST && !$context->hasPostingBatch) {
            throw new \DomainException('Zaúčtování vyžaduje schválenou účetní dávku.');
        }
        if ($command === PayrollRunCommand::MARK_PAID && !$context->hasPaymentBatch) {
            throw new \DomainException('Označení za uhrazené vyžaduje platební dávku.');
        }
        /*
         * Důvod se vyžaduje jen u opravy SCHVÁLENÉHO běhu: u vyžádání opravy
         * a u zrušení běhu, který má schválenou revizi (opravná revize nad
         * schváleným, zaúčtovaným nebo uzavřeným během). Zrušení neschváleného
         * běhu vrací „Začít znovu" a důvod nechce, otevření nové revize
         * navazuje na zrušení nebo na vyžádanou opravu, která důvod už nese.
         * Dřív se účetní na důvod ptala obrazovka dvakrát za sebou (zrušit
         * a hned začít znovu), aniž by z toho cokoli vyplývalo.
         */
        if (($command === PayrollRunCommand::REQUEST_CORRECTION
                || ($command === PayrollRunCommand::CANCEL && $context->hasApprovedRevision))
            && trim((string) $context->reason) === ''
        ) {
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
