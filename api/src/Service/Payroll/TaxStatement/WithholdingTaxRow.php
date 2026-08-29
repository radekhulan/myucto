<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxStatement;

/**
 * Jeden řádek části I. vyúčtování srážkové daně (DPSVD2).
 *
 * Na rozdíl od DPZVD6 má schéma u peněžních položek `fractionDigits="2"`,
 * takže se částky drží v HALÉŘÍCH a na dvě desetinná místa se převádějí až
 * v XML. Zaokrouhlovat na celé koruny by tu bylo ztrátové bez důvodu.
 *
 * Sloupce 4, 5 a 8 se od zdaňovacího období 2013 (resp. 2017) nevyplňují
 * a nejsou tu vůbec.
 */
final readonly class WithholdingTaxRow
{
    /**
     * @param int $taxDueMinor Sl. 1 — daň, která měla být sražena (§ 38d odst. 1, 2, 8).
     * @param int $taxWithheldMinor Sl. 2 — daň, která byla v měsíci sražena.
     * @param int $dueWithReturnMinor Sl. 3 — část daně splatná do termínu pro podání
     *        přiznání v průběhu období (§ 38d odst. 3 věta druhá).
     * @param int $declarationLinkedMinor Sl. 6 — část ODVEDENÉ daně, k níž se váže
     *        dodatečně podepsané prohlášení k dani (§ 38d odst. 4 písm. a), § 38k).
     *        Je to údaj o poplatníkovi, ne snížení povinnosti plátce.
     * @param int $prescribedMinor Sl. 7 (částka) — předepsáno k přímé úhradě.
     * @param int $correctionDifferenceMinor Sl. 9 — rozdíl u dodatečného vyúčtování.
     * @param int $remittedMinor Sl. 10 — skutečně odvedeno na účet finančního úřadu.
     */
    public function __construct(
        public int $taxDueMinor,
        public int $taxWithheldMinor,
        public int $dueWithReturnMinor,
        public int $declarationLinkedMinor,
        public int $prescribedMinor,
        public int $correctionDifferenceMinor,
        public int $remittedMinor,
    ) {
        if ($taxDueMinor < 0 || $taxWithheldMinor < 0 || $dueWithReturnMinor < 0) {
            throw new \DomainException('Srážková daň v tiskopisu nesmí být záporná.');
        }
        if ($remittedMinor < 0) {
            throw new \DomainException('Odvedená srážková daň nesmí být záporná.');
        }
    }

    /**
     * Sl. 8a — „Vyúčtovaná částka (sl. 1 − sl. 7)".
     *
     * Sl. 6 se NEODEČÍTÁ: je to informace o tom, k jaké části odvedené daně se
     * váže dodatečně podepsané prohlášení (§ 38k), ne snížení daňové povinnosti.
     */
    public function settledAmountMinor(): int
    {
        return $this->taxDueMinor - $this->prescribedMinor;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->taxDueMinor + $other->taxDueMinor,
            $this->taxWithheldMinor + $other->taxWithheldMinor,
            $this->dueWithReturnMinor + $other->dueWithReturnMinor,
            $this->declarationLinkedMinor + $other->declarationLinkedMinor,
            $this->prescribedMinor + $other->prescribedMinor,
            $this->correctionDifferenceMinor + $other->correctionDifferenceMinor,
            $this->remittedMinor + $other->remittedMinor,
        );
    }

    public static function zero(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0);
    }

    public function isEmpty(): bool
    {
        return $this->taxDueMinor === 0
            && $this->taxWithheldMinor === 0
            && $this->dueWithReturnMinor === 0
            && $this->declarationLinkedMinor === 0
            && $this->prescribedMinor === 0
            && $this->correctionDifferenceMinor === 0
            && $this->remittedMinor === 0;
    }

    /** @return array<string,int> */
    public function toSummary(): array
    {
        return [
            'tax_due_minor' => $this->taxDueMinor,
            'tax_withheld_minor' => $this->taxWithheldMinor,
            'due_with_return_minor' => $this->dueWithReturnMinor,
            'declaration_linked_minor' => $this->declarationLinkedMinor,
            'prescribed_minor' => $this->prescribedMinor,
            'settled_amount_minor' => $this->settledAmountMinor(),
            'correction_difference_minor' => $this->correctionDifferenceMinor,
            'remitted_minor' => $this->remittedMinor,
        ];
    }
}
