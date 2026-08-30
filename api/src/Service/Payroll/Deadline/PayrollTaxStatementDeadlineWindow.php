<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Deadline;

/**
 * Lhůta jednoho ročního vyúčtování — spočítaná, ne opsaná z komentáře.
 *
 * Vedle vlastního termínu nese i tu druhou lhůtu, která na tiskopis dopadá:
 * u vyúčtování zálohové daně je papírová a elektronická lhůta jiná a účetní
 * potřebuje vědět, kterou z nich přehled hlídá a proč.
 */
final readonly class PayrollTaxStatementDeadlineWindow
{
    public function __construct(
        /** `dpzvd6` nebo `dpsvd2`. */
        public string $formCode,
        /** Zdaňovací období, ZA které se vyúčtování podává. */
        public int $year,
        /** První den, kdy lhůta běží — den po konci zdaňovacího období. */
        public string $earliestSubmissionOn,
        /** Termín, který přehled hlídá (u DPZVD6 elektronický — viz politika). */
        public string $dueOn,
        /** Zákonná lhůta bez elektronického prodloužení. */
        public string $statutoryDueOn,
        /** Prodloužená lhůta při elektronickém podání, jinak `null`. */
        public ?string $electronicDueOn,
        /** Lze lhůtu prodloužit? U obou vyúčtování NE. */
        public bool $extendable,
        public string $legalReference,
        public string $calendarBasis,
        public string $rulesetId,
        public string $rulesetHash,
    ) {}
}
