<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Payment;

use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Service\Payroll\Net\PayoutAllocationService;
use MyInvoice\Service\Payroll\Payment\PayrollNetWageLiabilityMaterializer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Ú-05: osoba se ZÁPORNOU čistou výplatou (celý měsíc neplacené volno
 * a doplatek zdravotního pojištění do minimálního vyměřovacího základu podle
 * § 3 odst. 10 z. č. 592/1992 Sb.) nesmí dostat platební závazek.
 *
 * Je to jediná bezpečná odpověď: závazek se zápornou částkou by buď spadl
 * v {@see \MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder} na
 * kontrolu `amount_minor <= 0`, nebo — hůř — prošel do odchozí platební dávky
 * jako platba s obráceným znaménkem, tedy do bankovního příkazu. Pohledávku
 * vede účetnictví (MD 335 / D 331) a inkasuje se zápočtem nebo úhradou.
 *
 * Repozitář se v téhle větvi nepoužije, proto stačí instance bez konstruktoru;
 * kdyby se ho kód dotkl, test spadne na nenaplněných závislostech.
 */
final class PayrollNetWageLiabilityOverdrawnPersonTest extends TestCase
{
    public function testNegativePayableCreatesNoPaymentTarget(): void
    {
        self::assertSame(
            [],
            $this->personTargets(['payable_after_enforcement_minor' => -297_000]),
        );
    }

    public function testZeroPayableStillReachesTheAllocationRules(): void
    {
        // Kontrolní protipól: nula NENÍ přeplatek a musí projít dál, kde ji
        // zastaví až chybějící zmrazené výplatní účty.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('zmrazené výplatní účty');

        $this->personTargets(['payable_after_enforcement_minor' => 0]);
    }

    public function testStillFailsClosedForANonIntegerPayable(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('platnou částku po exekucích');

        $this->personTargets(['payable_after_enforcement_minor' => '-297000']);
    }

    /**
     * @param array<string,mixed> $personResult
     * @return array<string,mixed>
     */
    private function personTargets(array $personResult): array
    {
        $materializer = new ReflectionClass(
            PayrollNetWageLiabilityMaterializer::class,
        )->newInstanceWithoutConstructor();
        $allocations = new ReflectionClass(
            PayrollNetWageLiabilityMaterializer::class,
        )->getProperty('allocations');
        $allocations->setValue($materializer, new PayoutAllocationService());
        $liabilities = new ReflectionClass(
            PayrollNetWageLiabilityMaterializer::class,
        )->getProperty('liabilities');
        $liabilities->setValue(
            $materializer,
            new ReflectionClass(PayrollPaymentLiabilityRepository::class)
                ->newInstanceWithoutConstructor(),
        );

        return new ReflectionMethod(
            PayrollNetWageLiabilityMaterializer::class,
            'personTargets',
        )->invoke($materializer, 503, $personResult, [], '2026-07-15');
    }
}
