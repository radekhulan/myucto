<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use MyInvoice\Service\Payroll\PayrollEmployerSettingsValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollEmployerSettingsValidatorTest extends TestCase
{
    public function testAcceptsInsurerFromTheCodebook(): void
    {
        $result = $this->validator()->validate(1, $this->input('205'));

        self::assertSame('205', $result['default_health_insurer_code']);
    }

    public function testEmptyInsurerStaysUnset(): void
    {
        self::assertNull($this->validator()->validate(1, $this->input(''))['default_health_insurer_code']);
        self::assertNull($this->validator()->validate(1, $this->input(null))['default_health_insurer_code']);
    }

    public function testRejectsInsurerOutsideTheCodebook(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Kód zdravotní pojišťovny 999 neexistuje.');
        $this->validator()->validate(1, $this->input('999'));
    }

    public function testRejectsFreeTextThatOnlyFitsTheLengthLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('111 VZP');
        $this->validator()->validate(1, $this->input('ABCDEFGH'));
    }

    public function testAcceptsThreeDigitSocialSecurityOfficeCode(): void
    {
        $result = $this->validator()->validate(1, $this->input('205', '115'));

        self::assertSame('115', $result['social_security_office_code']);
    }

    public function testEmptySocialSecurityOfficeCodeStaysUnset(): void
    {
        self::assertNull(
            $this->validator()->validate(1, $this->input('205', ''))['social_security_office_code'],
        );
        self::assertNull(
            $this->validator()->validate(1, $this->input('205', null))['social_security_office_code'],
        );
    }

    public function testRejectsLegacyOfficeVariableSymbolWrite(): void
    {
        $input = $this->input('205');
        $input['offices'][0]['social_security_variable_symbol'] = '0012345678';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('VS ČSSZ spravujte přes účinnou historii registrace mzdové účtárny.');
        $this->validator()->validate(1, $input);
    }

    /**
     * Délkový limit 16 sám o sobě propouštěl „PSSZ" i „11" — obojí by skončilo
     * v podání na ČSSZ.
     */
    #[DataProvider('malformedSocialSecurityOfficeCodes')]
    public function testRejectsSocialSecurityOfficeCodeOfWrongShape(string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('musí být trojmístné číslo');
        $this->validator()->validate(1, $this->input('205', $code));
    }

    /** @return array<string,array{string}> */
    public static function malformedSocialSecurityOfficeCodes(): array
    {
        return [
            'jedna číslice' => ['1'],
            'dvě číslice' => ['11'],
            'čtyři číslice' => ['1105'],
            'písmena' => ['PSSZ'],
            'číslice s písmenem' => ['11a'],
            'mezera uprostřed' => ['1 1'],
        ];
    }

    private function validator(): PayrollEmployerSettingsValidator
    {
        $accounts = $this->createMock(ChartOfAccountsRepository::class);
        $map = [];
        foreach (PayrollAccountingDefaults::ACCOUNTS as $definition) {
            $map[$definition['code']] = [
                'id' => 1,
                'is_active' => true,
                'account_type' => $definition['type'],
            ];
        }
        $accounts->method('codeToIdMap')->willReturn($map);

        return new PayrollEmployerSettingsValidator($accounts);
    }

    /** @return array<string,mixed> */
    private function input(?string $insurerCode, ?string $socialSecurityOfficeCode = '110'): array
    {
        return [
            'default_office_code' => 'MAIN',
            'employer_registration_number' => '12345678',
            'social_security_office_code' => $socialSecurityOfficeCode,
            'default_health_insurer_code' => $insurerCode,
            'payroll_contact_name' => 'Testovací účetní',
            'payroll_contact_email' => 'payroll@example.test',
            'payroll_contact_phone' => '+420 000 000 000',
            'offices' => [[
                'code' => 'MAIN',
                'name' => 'Hlavní účtárna',
                'social_security_variable_symbol' => null,
                'is_active' => true,
            ]],
            'accounts' => PayrollAccountingDefaults::codes(),
        ];
    }
}
