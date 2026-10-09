<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Tests\Support\PairwiseCombinations;
use MyInvoice\Tests\Support\PayrollCombinatorialScenarios;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Generátor kombinačních scénářů brány G4: každá dvojice hodnot, kterou omezení
 * připouštějí, je pokrytá, žádná kombinace omezení neporušuje a výběr je stálý
 * (CI pouští prvních N kombinací a musí to být pokaždé tytéž).
 */
#[Group('combinatorial')]
final class PayrollCombinatorialScenariosTest extends TestCase
{
    public function testEveryAllowedPairIsCoveredByAnAllowedCombination(): void
    {
        $all = PayrollCombinatorialScenarios::all();

        self::assertSame([], PairwiseCombinations::uncoveredPairs(
            PayrollCombinatorialScenarios::dimensions(),
            PayrollCombinatorialScenarios::allowed(...),
            $all,
        ));
        foreach ($all as $combination) {
            self::assertTrue(PayrollCombinatorialScenarios::allowed($combination), PayrollCombinatorialScenarios::id($combination));
            self::assertSame(array_keys(PayrollCombinatorialScenarios::dimensions()), array_keys($combination));
        }
        // Pairwise je řádově menší než kartézský součin (4·4·3·9·2·5·2·3 = 25 920).
        self::assertLessThan(80, count($all));
        self::assertGreaterThanOrEqual(45, count($all), 'Nejméně tolik kombinací, kolik je dvojic nepřítomnost × sleva.');
    }

    public function testSelectionIsDeterministic(): void
    {
        self::assertSame(PayrollCombinatorialScenarios::all(), PayrollCombinatorialScenarios::all());
    }

    public function testConstraintsRejectLegallyImpossibleCombinations(): void
    {
        $allowed = PayrollCombinatorialScenarios::allowed(...);

        self::assertFalse($allowed(['relation' => 'hpp', 'tax' => 'withholding']));
        self::assertFalse($allowed(['tax' => 'no_declaration', 'credit' => 'children']));
        self::assertFalse($allowed(['relation' => 'dpp', 'insurance' => 'below', 'absence' => 'dpn_window']));
        self::assertFalse($allowed(['relation' => 'statutory', 'absence' => 'vacation']));
        self::assertTrue($allowed(['relation' => 'dpp', 'insurance' => 'threshold', 'absence' => 'dpn_window']));
    }
}
