<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\ForeignIncome;

use MyInvoice\Service\Report\EpoDate;

/**
 * Jeden odvod sražené daně — řádky 32 až 34 oznámení DPSHL1 (`VetaU`).
 *
 * U osvobozeného příjmu se tyhle řádky nevyplňují vůbec: není co odvádět.
 * Hlídá to {@see ForeignIncomeXmlBuilder}, ne tahle věta — sama o sobě je
 * platná i tam, kde se nakonec nepoužije.
 */
final readonly class ForeignIncomeRemittance
{
    /**
     * @param string  $paidOn    `d_odv` — datum platby, ISO `Y-m-d`.
     * @param int     $amountCzk `kc_odv` — částka v celých korunách.
     * @param ?string $account   `ucet` — účet správce daně ve tvaru
     *                           `předčíslí-číslo/kód banky`.
     */
    public function __construct(
        public string $paidOn,
        public int $amountCzk,
        public ?string $account = null,
    ) {
        EpoDate::requireIso($paidOn, 'Datum odvodu sražené daně');
        if ($amountCzk < 0) {
            throw new \DomainException('Odvedená částka nesmí být záporná.');
        }
        if ($account !== null && preg_match('/^\d{1,6}-\d{1,10}\/\d{4}$/', $account) !== 1) {
            throw new \InvalidArgumentException(
                'Účet správce daně se uvádí ve tvaru předčíslí-číslo/kód banky.',
            );
        }
    }

    /** @return array<string,mixed> */
    public function toSummary(): array
    {
        return [
            'paid_on' => $this->paidOn,
            'amount_czk' => $this->amountCzk,
            'account' => $this->account,
        ];
    }
}
