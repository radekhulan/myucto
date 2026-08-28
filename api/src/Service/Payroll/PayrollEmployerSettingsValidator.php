<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Codebook\HealthInsurers;

final class PayrollEmployerSettingsValidator
{
    /**
     * Kód pracoviště ČSSZ (OSSZ/PSSZ/MSSZ Brno) je trojmístný — datový slovník
     * JMHZ 1.4.1.6 váže atribut 10004 na číselník okresů, kde má všech 89
     * položek právě tři číslice. Délkový limit 16 sám o sobě propouštěl cokoliv
     * až do zákonného podání i do platebních příkazů.
     */
    private const SOCIAL_SECURITY_OFFICE_CODE_PATTERN = '/^[0-9]{3}$/';

    private const OPTIONAL_FIELDS = [
        'employer_registration_number' => 32,
        'social_security_office_code' => 16,
        'default_health_insurer_code' => 8,
        'payroll_contact_name' => 190,
        'payroll_contact_email' => 190,
        'payroll_contact_phone' => 40,
    ];

    public function __construct(private readonly ChartOfAccountsRepository $accounts) {}

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   default_office_code:string,
     *   employer_registration_number:?string,
     *   social_security_office_code:?string,
     *   default_health_insurer_code:?string,
     *   payroll_contact_name:?string,
     *   payroll_contact_email:?string,
     *   payroll_contact_phone:?string,
     *   accounts:array<string,string>,
     *   offices:list<array{
     *     code:string,
     *     name:string,
     *     social_security_variable_symbol:?string,
     *     social_security_variable_symbol_provided:bool,
     *     is_active:bool
     *   }>
     * }
     */
    public function validate(int $supplierId, array $input): array
    {
        $normalized = [];
        foreach (self::OPTIONAL_FIELDS as $field => $maxLength) {
            $value = trim((string) ($input[$field] ?? ''));
            if (mb_strlen($value) > $maxLength) {
                throw new \InvalidArgumentException("Pole {$field} je příliš dlouhé.");
            }
            $normalized[$field] = $value === '' ? null : $value;
        }
        if ($normalized['payroll_contact_email'] !== null
            && filter_var($normalized['payroll_contact_email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('E-mailový kontakt mzdové účtárny není platný.');
        }
        if ($normalized['social_security_office_code'] !== null
            && preg_match(
                self::SOCIAL_SECURITY_OFFICE_CODE_PATTERN,
                $normalized['social_security_office_code'],
            ) !== 1) {
            throw new \InvalidArgumentException(
                'Kód správy sociálního zabezpečení musí být trojmístné číslo, '
                . 'například 110 pro Prahu 10. Kód najdete na potvrzení o '
                . 'registraci zaměstnavatele u ČSSZ. Pole můžete nechat prázdné.',
            );
        }
        // Výchozí pojišťovna zůstává nepovinná (prázdné = nenastaveno), ale
        // je-li zadaná, musí být z číselníku — délkový limit sám o sobě
        // propustil libovolný osmiznakový nesmysl.
        if ($normalized['default_health_insurer_code'] !== null
            && !HealthInsurers::isValid($normalized['default_health_insurer_code'])) {
            throw new \InvalidArgumentException(
                HealthInsurers::invalidCodeMessage($normalized['default_health_insurer_code']),
            );
        }

        $offices = $this->offices($input['offices'] ?? null);
        $defaultOfficeCode = strtoupper(trim((string) ($input['default_office_code'] ?? '')));
        $activeByCode = array_column($offices, 'is_active', 'code');
        if (!isset($activeByCode[$defaultOfficeCode]) || $activeByCode[$defaultOfficeCode] !== true) {
            throw new \InvalidArgumentException('Výchozí mzdová účtárna musí existovat a být aktivní.');
        }

        $normalized['default_office_code'] = $defaultOfficeCode;
        $normalized['offices'] = $offices;
        $normalized['accounts'] = $this->accountCodes($supplierId, $input['accounts'] ?? null);

        return $normalized;
    }

    /**
     * @return list<array{
     *   code:string,
     *   name:string,
     *   social_security_variable_symbol:?string,
     *   social_security_variable_symbol_provided:bool,
     *   is_active:bool
     * }>
     */
    private function offices(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            throw new \InvalidArgumentException('Je nutné zadat alespoň jednu mzdovou účtárnu.');
        }

        $offices = [];
        $seen = [];
        $hasActive = false;
        foreach ($value as $office) {
            if (!is_array($office)) {
                throw new \InvalidArgumentException('Mzdová účtárna nemá platný formát.');
            }
            $code = strtoupper(trim((string) ($office['code'] ?? '')));
            $name = trim((string) ($office['name'] ?? ''));
            if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,31}$/', $code)) {
                throw new \InvalidArgumentException('Kód mzdové účtárny není platný.');
            }
            if ($name === '' || mb_strlen($name) > 190) {
                throw new \InvalidArgumentException('Název mzdové účtárny není platný.');
            }
            if (!is_bool($office['is_active'] ?? null)) {
                throw new \InvalidArgumentException('Příznak aktivity mzdové účtárny musí být boolean.');
            }
            if (isset($seen[$code])) {
                throw new \InvalidArgumentException('Kódy mzdových účtáren se nesmí opakovat.');
            }
            $socialVariableSymbolProvided = array_key_exists(
                'social_security_variable_symbol',
                $office,
            );
            $socialVariableSymbol = trim(
                (string) ($office['social_security_variable_symbol'] ?? '')
            );
            if ($socialVariableSymbolProvided && $socialVariableSymbol !== '') {
                throw new \InvalidArgumentException(
                    'VS ČSSZ spravujte přes účinnou historii registrace mzdové účtárny.'
                );
            }
            $seen[$code] = true;
            $hasActive = $hasActive || $office['is_active'];
            $offices[] = [
                'code' => $code,
                'name' => $name,
                'social_security_variable_symbol' =>
                    $socialVariableSymbol === '' ? null : $socialVariableSymbol,
                'social_security_variable_symbol_provided' =>
                    $socialVariableSymbolProvided,
                'is_active' => $office['is_active'],
            ];
        }
        if (!$hasActive) {
            throw new \InvalidArgumentException('Alespoň jedna mzdová účtárna musí být aktivní.');
        }

        return $offices;
    }

    /** @return array<string,string> */
    private function accountCodes(int $supplierId, mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Chybí nastavení účetních kontací.');
        }
        $available = $this->accounts->codeToIdMap($supplierId);
        $result = [];
        foreach (PayrollAccountingDefaults::ACCOUNTS as $key => $definition) {
            $code = trim((string) ($value[$key] ?? ''));
            if (!preg_match('/^[0-9]{3}[.A-Z0-9]{0,7}$/', $code)) {
                throw new \InvalidArgumentException("Účet {$key} nemá platný kód.");
            }
            $account = $available[$code] ?? null;
            if ($account === null || !$account['is_active']) {
                throw new \InvalidArgumentException("Účet {$code} neexistuje nebo není aktivní.");
            }
            if ($account['account_type'] !== $definition['type']) {
                throw new \InvalidArgumentException(
                    "Účet {$code} nemá očekávaný typ {$definition['type']}."
                );
            }
            $result[$key] = $code;
        }

        return $result;
    }
}
