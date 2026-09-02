<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;

final class PayrollInstitutionAccountValidator
{
    public function __construct(
        private readonly IbanValidator $ibanValidator,
        private readonly CzechBankAccountValidator $czechBankAccountValidator,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   institution_type:string,
     *   institution_code:string,
     *   institution_name:string,
     *   bank_account:string,
     *   currency_code:string,
     *   variable_symbol:?string,
     *   specific_symbol:?string,
     *   constant_symbol:?string,
     *   valid_from:string,
     *   valid_to:?string,
     *   source_kind:string,
     *   source_reference:string,
     *   verified_on:string
     * }
     */
    public function validateCreate(array $input): array
    {
        $type = InstitutionAccountType::tryFrom(
            trim($this->requiredString($input['institution_type'] ?? null, 'institution_type'))
        );
        if ($type === null) {
            throw new \InvalidArgumentException('Typ instituce není podporovaný.');
        }

        $code = strtoupper(trim(
            $this->requiredString($input['institution_code'] ?? null, 'institution_code')
        ));
        if (preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,31}$/', $code) !== 1) {
            throw new \InvalidArgumentException('Kód instituce není platný.');
        }

        $common = $this->validateCommon($input);
        $bankAccount = $this->normalizeBankAccount(
            $this->requiredString($input['bank_account'] ?? null, 'bank_account')
        );

        $validFrom = $this->date($input['valid_from'] ?? null, 'valid_from');
        $validTo = $this->optionalDate($input['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException('Konec platnosti nesmí předcházet začátku.');
        }

        return [
            'institution_type' => $type->value,
            'institution_code' => $code,
            'institution_name' => $common['institution_name'],
            'bank_account' => $bankAccount,
            'currency_code' => $common['currency_code'],
            'variable_symbol' => $common['variable_symbol'],
            'specific_symbol' => $common['specific_symbol'],
            'constant_symbol' => $common['constant_symbol'],
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'source_kind' => $common['source_kind'],
            'source_reference' => $common['source_reference'],
            'verified_on' => $common['verified_on'],
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   institution_code:?string,
     *   institution_name:string,
     *   variable_symbol:?string,
     *   specific_symbol:?string,
     *   constant_symbol:?string,
     *   valid_to:?string,
     *   source_kind:string,
     *   source_reference:string,
     *   verified_on:string
     * }
     */
    public function validateUpdate(array $input): array
    {
        // Číslo účtu, typ instituce, měna a začátek platnosti jsou historie —
        // jejich změna je nový řádek. Kód instituce ale historie NENÍ: je to
        // naše klasifikace platebního cíle, kterou účetní vyplňovala do
        // volného textu bez šance uhodnout, co aplikace očekává („FUPLZEN"
        // místo druhu daně). Držet ho neměnný znamenalo, že překlep nešlo
        // opravit vůbec — nový řádek se stejnou platností spadl na překryv.
        foreach ([
            'bank_account',
            'institution_type',
            'currency_code',
            'valid_from',
        ] as $immutable) {
            if (array_key_exists($immutable, $input)) {
                throw new \InvalidArgumentException(
                    "Pole {$immutable} je historické; změnu založ jako nový účet."
                );
            }
        }

        $code = null;
        if (array_key_exists('institution_code', $input)
            && $input['institution_code'] !== null
        ) {
            $code = strtoupper(trim($this->requiredString(
                $input['institution_code'],
                'institution_code',
            )));
            if (preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,31}$/', $code) !== 1) {
                throw new \InvalidArgumentException('Kód instituce není platný.');
            }
        }

        $common = $this->validateCommon($input, currencyRequired: false);

        return [
            'institution_code' => $code,
            'institution_name' => $common['institution_name'],
            'variable_symbol' => $common['variable_symbol'],
            'specific_symbol' => $common['specific_symbol'],
            'constant_symbol' => $common['constant_symbol'],
            'valid_to' => $this->optionalDate($input['valid_to'] ?? null, 'valid_to'),
            'source_kind' => $common['source_kind'],
            'source_reference' => $common['source_reference'],
            'verified_on' => $common['verified_on'],
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   institution_name:string,
     *   currency_code:string,
     *   variable_symbol:?string,
     *   specific_symbol:?string,
     *   constant_symbol:?string,
     *   source_kind:string,
     *   source_reference:string,
     *   verified_on:string
     * }
     */
    private function validateCommon(array $input, bool $currencyRequired = true): array
    {
        $name = trim($this->requiredString(
            $input['institution_name'] ?? null,
            'institution_name',
        ));
        if ($name === '' || mb_strlen($name) > 190 || $this->hasControlCharacter($name)) {
            throw new \InvalidArgumentException('Název instituce není platný.');
        }

        $currency = strtoupper(trim($this->requiredString(
            $input['currency_code'] ?? ($currencyRequired ? null : 'CZK'),
            'currency_code',
        )));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('Kód měny musí mít tři písmena.');
        }

        $sourceKind = InstitutionAccountSourceKind::tryFrom(
            trim($this->requiredString($input['source_kind'] ?? null, 'source_kind'))
        );
        if ($sourceKind === null) {
            throw new \InvalidArgumentException('Druh ověřovacího zdroje není podporovaný.');
        }
        /**
         * Reference zdroje je nepovinná dohledávka („Sdělení VZP 3/2025", číslo
         * dopisu). Zákon ji nevyžaduje a druh zdroje (`source_kind`) i datum
         * ověření se evidují zvlášť, takže prázdná hodnota nic nerozbije.
         * Ukládá se jako prázdný řetězec — sloupec je NOT NULL a přepis na NULL
         * by znamenal migraci nad existujícími daty i úpravu integritního
         * triggeru z 1275.
         */
        $sourceReference = trim($this->optionalString(
            $input['source_reference'] ?? null,
            'source_reference',
        ));
        if (mb_strlen($sourceReference) > 500 || $this->hasControlCharacter($sourceReference)) {
            throw new \InvalidArgumentException('Reference ověřovacího zdroje není platná.');
        }

        $verifiedOn = $this->date($input['verified_on'] ?? null, 'verified_on');
        if ($verifiedOn > date('Y-m-d')) {
            throw new \InvalidArgumentException('Datum ověření nesmí být v budoucnosti.');
        }

        return [
            'institution_name' => $name,
            'currency_code' => $currency,
            'variable_symbol' => $this->paymentSymbol($input['variable_symbol'] ?? null, 10, 'Variabilní symbol'),
            'specific_symbol' => $this->paymentSymbol($input['specific_symbol'] ?? null, 10, 'Specifický symbol'),
            'constant_symbol' => $this->paymentSymbol($input['constant_symbol'] ?? null, 4, 'Konstantní symbol', true),
            'source_kind' => $sourceKind->value,
            'source_reference' => $sourceReference,
            'verified_on' => $verifiedOn,
        ];
    }

    private function normalizeBankAccount(string $raw): string
    {
        $value = strtoupper(trim($raw));
        if ($value === '' || mb_strlen($value) > 191 || $this->hasControlCharacter($value)) {
            throw new \InvalidArgumentException('Bankovní účet není platný.');
        }

        $compact = (string) preg_replace('/\s+/', '', $value);
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $compact) === 1) {
            if (!$this->ibanValidator->isValid($compact)) {
                throw new \InvalidArgumentException('IBAN není platný.');
            }
            return $compact;
        }

        return $this->czechBankAccountValidator->normalize($value);
    }

    private function paymentSymbol(
        mixed $raw,
        int $maximumLength,
        string $label,
        bool $exactLength = false,
    ): ?string {
        $value = trim($this->optionalString($raw, $label));
        if ($value === '') {
            return null;
        }
        $length = strlen($value);
        if (!ctype_digit($value)
            || $length > $maximumLength
            || ($exactLength && $length !== $maximumLength)
        ) {
            throw new \InvalidArgumentException("{$label} není platný.");
        }
        return $value;
    }

    private function date(mixed $raw, string $field): string
    {
        $value = trim($this->requiredString($raw, $field));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("Pole {$field} musí být platné datum YYYY-MM-DD.");
        }
        return $value;
    }

    private function optionalDate(mixed $raw, string $field): ?string
    {
        $value = trim($this->optionalString($raw, $field));
        return $value === '' ? null : $this->date($value, $field);
    }

    private function hasControlCharacter(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/u', $value) === 1;
    }

    private function requiredString(mixed $raw, string $field): string
    {
        if (!is_string($raw)) {
            throw new \InvalidArgumentException("Pole {$field} musí být řetězec.");
        }
        return $raw;
    }

    private function optionalString(mixed $raw, string $field): string
    {
        if ($raw === null) {
            return '';
        }
        return $this->requiredString($raw, $field);
    }
}
