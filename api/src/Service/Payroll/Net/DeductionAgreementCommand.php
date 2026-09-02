<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

enum DeductionAgreementCommand: string
{
    case Activate = 'activate';
    case Pause = 'pause';
    case Resume = 'resume';
    case End = 'end';
    case Cancel = 'cancel';
    /**
     * Návrat z ukončení nebo zrušení.
     *
     * „Ukončit“ šlo zmáčknout z návrhu, z aktivní i z pozastavené dohody jedním
     * klikem — a zpátky nevedlo nic: `update()` odmítá terminální stav a žádný
     * příkaz z něj neodcházel. Překlik nebo špatné datum konce tak dohodu
     * umrtvily a účetní ji musela založit znovu, čímž se rozpadla historie
     * ledgeru. Návrat míří do POZASTAVENO, ne do AKTIVNÍ: do mzdového běhu
     * vstupuje jen aktivní dohoda ({@see DeductionAgreementStatus::entersPayrollRun}),
     * takže se srážky samy od sebe nerozjedou — musí je někdo vědomě obnovit.
     */
    case Reopen = 'reopen';

    /** @return list<DeductionAgreementStatus> */
    public function allowedFrom(): array
    {
        return match ($this) {
            self::Activate => [
                DeductionAgreementStatus::Draft,
            ],
            self::Pause => [
                DeductionAgreementStatus::Active,
            ],
            self::Resume => [
                DeductionAgreementStatus::Paused,
            ],
            self::End => [
                DeductionAgreementStatus::Draft,
                DeductionAgreementStatus::Active,
                DeductionAgreementStatus::Paused,
            ],
            self::Cancel => [
                DeductionAgreementStatus::Draft,
            ],
            self::Reopen => [
                DeductionAgreementStatus::Ended,
                DeductionAgreementStatus::Cancelled,
            ],
        };
    }

    public function target(): DeductionAgreementStatus
    {
        return match ($this) {
            self::Activate, self::Resume => DeductionAgreementStatus::Active,
            self::Pause, self::Reopen => DeductionAgreementStatus::Paused,
            self::End => DeductionAgreementStatus::Ended,
            self::Cancel => DeductionAgreementStatus::Cancelled,
        };
    }

    public function changeKind(): string
    {
        return match ($this) {
            self::Activate => 'activated',
            self::Pause => 'paused',
            self::Resume => 'resumed',
            self::End => 'ended',
            self::Cancel => 'cancelled',
            self::Reopen => 'reopened',
        };
    }

    /**
     * Zrušení je jediný přechod, který dohodu odstraní z evidence úplně —
     * smí se proto použít jen dokud dohoda nemá jediný ledger pohyb.
     * Ukončení naproti tomu historii ledgeru vždy ponechává.
     */
    public function requiresEmptyLedger(): bool
    {
        return $this === self::Cancel;
    }

    public function closesValidity(): bool
    {
        return $this === self::End;
    }

    /**
     * Znovuotevření zahodí konec účinnosti, který ukončení dopsalo. Bez toho by
     * dohoda ožila s `valid_to` v minulosti a nesrazila by ani po obnovení nic.
     */
    public function reopensValidity(): bool
    {
        return $this === self::Reopen;
    }
}
