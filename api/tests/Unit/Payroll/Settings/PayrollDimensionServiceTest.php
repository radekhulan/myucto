<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Settings;

use MyInvoice\Repository\Payroll\PayrollDimensionRepository;
use MyInvoice\Service\Payroll\Posting\PayrollPostingAccountPolicy;
use MyInvoice\Service\Payroll\Settings\PayrollDimensionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validace se odehrává PŘED jakýmkoli dotykem repozitáře, takže se sem dá pustit
 * jeho instance bez konstruktoru — kdyby se na ni přece jen sáhlo, test spadne
 * hlasitě, ne tiše.
 */
final class PayrollDimensionServiceTest extends TestCase
{
    /**
     * Ú-18: výchozí účet dimenze se dosud ověřoval AŽ při zaúčtování, takže
     * chybné nastavení shodilo APPROVE celého mzdového běhu. Táž kontrola musí
     * proběhnout u zadání, kde se dá bez následků opravit.
     */
    #[DataProvider('reservedAccounts')]
    public function testRejectsDefaultAccountReservedForAnotherCategory(
        string $account,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('je vyhrazený jiné mzdové kategorii');
        $this->service()->save(1, null, $this->input([
            'default_account_code' => $account,
        ]), 0, null);
    }

    /** @return array<string,array{string}> */
    public static function reservedAccounts(): array
    {
        return [
            'pojistné zaměstnavatele' => ['524'],
            'pojistné' => ['336.100'],
            'daň' => ['342'],
            'srážky' => ['379'],
            'závazek mzdy' => ['331'],
            'zápočet na účet společníka' => ['365.100'],
        ];
    }

    /**
     * Ú-14: pseudonym srážky (`MZ-SR-…`) a exekuce (`MZ-EX-…`) sdílí sloupec
     * `cost_center` se skutečným střediskem. Reálný kód se stejným prefixem by
     * se v reconciliaci vydával za srážku.
     */
    #[DataProvider('reservedCodes')]
    public function testRejectsCodeCollidingWithDeductionPseudonym(
        string $code,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jsou vyhrazené analytice srážek');
        $this->service()->save(1, null, $this->input(['code' => $code]), 0, null);
    }

    /** @return array<string,array{string}> */
    public static function reservedCodes(): array
    {
        return [
            'srážka' => ['MZ-SR-PRAHA'],
            'exekuce' => ['MZ-EX-1'],
            'malými písmeny' => ['mz-sr-praha'],
        ];
    }

    /** Běžné středisko ani nákladová analytika hrubé mzdy blokované nejsou. */
    public function testOrdinaryCostCentreIsNotReserved(): void
    {
        self::assertFalse(
            PayrollPostingAccountPolicy::isReservedDimensionCode('VYROBA'),
        );
        // Kód, který jen ZAČÍNÁ na MZ, vyhrazený není — blokuje se celý prefix.
        self::assertFalse(
            PayrollPostingAccountPolicy::isReservedDimensionCode('MZ-PRAHA'),
        );
        self::assertTrue(
            PayrollPostingAccountPolicy::isReservedDimensionCode('MZ-EX-ABC'),
        );

        PayrollPostingAccountPolicy::assertGrossCostAccountIsUnambiguous('521.100');
        PayrollPostingAccountPolicy::assertGrossCostAccountIsUnambiguous('518');
        $this->addToAssertionCount(2);
    }

    private function service(): PayrollDimensionService
    {
        $repository = (new \ReflectionClass(PayrollDimensionRepository::class))
            ->newInstanceWithoutConstructor();

        return new PayrollDimensionService($repository);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides): array
    {
        return $overrides + [
            'dimension_type' => 'cost_center',
            'code' => 'VYROBA',
            'name' => 'Výroba',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'is_active' => true,
            'default_account_code' => '521.100',
        ];
    }
}
