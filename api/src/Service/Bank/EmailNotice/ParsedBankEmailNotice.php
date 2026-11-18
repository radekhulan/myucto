<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

final class ParsedBankEmailNotice
{
    /**
     * @param bool $currencyStated Měnu avízo výslovně uvádí. Parser, který ji v textu
     *   nenašel a dosadil výchozí CZK (Fio „Příjem na kontě"), předá false a měnu
     *   pak určí účet (#160). Výslovně uvedená měna se s měnou účtu porovnává (#390).
     * @param float|null $originalAmount Částka nákupu v původní měně, uvádí-li avízo
     *   vedle částky v měně účtu i ji (platba kartou v cizí měně).
     */
    public function __construct(
        public readonly string $variableSymbol,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $postedAt,
        public readonly string $recipientAccount,
        public readonly ?string $counterpartyAccount = null,
        public readonly ?string $counterpartyBank = null,
        public readonly ?string $counterpartyName = null,
        public readonly ?string $constantSymbol = null,
        public readonly ?string $message = null,
        public readonly ?string $bankRef = null,
        public readonly ?float $balance = null,
        public readonly bool $currencyStated = true,
        public readonly ?float $originalAmount = null,
        public readonly ?string $originalCurrency = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'variable_symbol' => $this->variableSymbol,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'posted_at' => $this->postedAt,
            'recipient_account' => $this->recipientAccount,
            'counterparty_account' => $this->counterpartyAccount,
            'counterparty_bank' => $this->counterpartyBank,
            'counterparty_name' => $this->counterpartyName,
            'constant_symbol' => $this->constantSymbol,
            'message' => $this->message,
            'bank_ref' => $this->bankRef,
            'balance' => $this->balance,
            'currency_stated' => $this->currencyStated,
            'original_amount' => $this->originalAmount,
            'original_currency' => $this->originalCurrency,
        ];
    }
}
