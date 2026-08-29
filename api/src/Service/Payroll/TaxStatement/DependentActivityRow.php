<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxStatement;

/**
 * Jeden řádek části I. vyúčtování zálohové daně (DPZVD6) — sloupce 1 až 11
 * v CELÝCH KORUNÁCH. Týmž tvarem se popisuje i ÚHRN (ř. 13), protože je to
 * po sloupcích prostý součet.
 *
 * Sloupce 6 a 7 se od zdaňovacího období 2013 nevyplňují a nejsou tu vůbec:
 * neobsazený sloupec není nula, kterou by šlo poslat.
 */
final readonly class DependentActivityRow
{
    /**
     * @param int $advanceDue Sl. 1 — zálohy po měsíčních slevách, které MĚLY být sraženy.
     * @param int $advanceWithheld Sl. 2 — zálohy, které BYLY původně sraženy.
     * @param int $prescribed Sl. 3 (částka) — dlužné částky předepsané správcem
     *        daně k přímé úhradě.
     * @param int $annualOverpayment Sl. 4 — vrácené přeplatky z ročního zúčtování.
     * @param int $bonusPaid Sl. 5 — vyplacené měsíční bonusy a doplatky na bonusu.
     * @param int $correctionDifference Sl. 10 — rozdíl u dodatečného vyúčtování.
     * @param int $remitted Sl. 11 — skutečně odvedeno na účet finančního úřadu.
     */
    public function __construct(
        public int $advanceDue,
        public int $advanceWithheld,
        public int $prescribed,
        public int $annualOverpayment,
        public int $bonusPaid,
        public int $correctionDifference,
        public int $remitted,
    ) {
        // Kritické kontroly XSD: sl. 11 nesmí být záporný. Sloupce 8 a 9 záporné
        // být SMÍ (vyplacené bonusy převýšily sražené zálohy), proto se nehlídají.
        if ($remitted < 0) {
            throw new \DomainException('Skutečně odvedená záloha nesmí být záporná.');
        }
    }

    /**
     * Sl. 8 — „Částky upravující sražené zálohy na daň (sl. 4 + sl. 5)".
     *
     * Není to zbytek k odvodu, jak by název „upravující" mohl svádět číst:
     * je to prostý součet toho, co se ze sražených záloh vyplatilo zpět
     * zaměstnancům — vrácené přeplatky z ročního zúčtování a vyplacené bonusy.
     */
    public function adjustments(): int
    {
        return $this->annualOverpayment + $this->bonusPaid;
    }

    /**
     * Sl. 9 — „Vyúčtovaná částka (sl. 1 − sl. 3 − sl. 4 − sl. 5)".
     *
     * Vychází ze sl. 1 (mělo být sraženo), NE ze sl. 2 (bylo sraženo) a NE ze
     * sl. 8. Odečítá se i sl. 3, protože částky předepsané správcem daně
     * k přímé úhradě se do vyúčtované částky neuvádějí. Může být záporná.
     */
    public function settledAmount(): int
    {
        return $this->advanceDue
            - $this->prescribed
            - $this->annualOverpayment
            - $this->bonusPaid;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->advanceDue + $other->advanceDue,
            $this->advanceWithheld + $other->advanceWithheld,
            $this->prescribed + $other->prescribed,
            $this->annualOverpayment + $other->annualOverpayment,
            $this->bonusPaid + $other->bonusPaid,
            $this->correctionDifference + $other->correctionDifference,
            $this->remitted + $other->remitted,
        );
    }

    public static function zero(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0);
    }

    public function isEmpty(): bool
    {
        return $this->advanceDue === 0
            && $this->advanceWithheld === 0
            && $this->prescribed === 0
            && $this->annualOverpayment === 0
            && $this->bonusPaid === 0
            && $this->correctionDifference === 0
            && $this->remitted === 0;
    }

    /** @return array<string,int> */
    public function toSummary(): array
    {
        return [
            'advance_due' => $this->advanceDue,
            'advance_withheld' => $this->advanceWithheld,
            'prescribed' => $this->prescribed,
            'annual_overpayment' => $this->annualOverpayment,
            'bonus_paid' => $this->bonusPaid,
            'adjustments' => $this->adjustments(),
            'settled_amount' => $this->settledAmount(),
            'correction_difference' => $this->correctionDifference,
            'remitted' => $this->remitted,
        ];
    }
}
