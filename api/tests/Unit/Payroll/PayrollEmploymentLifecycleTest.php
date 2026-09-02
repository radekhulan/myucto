<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollEmploymentLifecycle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollEmploymentLifecycleTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function allowedTransitions(): iterable
    {
        yield 'pre-registration' => ['planned', 'preregistered'];
        yield 'no show before registration' => ['planned', 'no_show'];
        yield 'start' => ['preregistered', 'active'];
        yield 'suspend' => ['active', 'suspended'];
        yield 'resume' => ['suspended', 'active'];
        yield 'end active' => ['active', 'ended'];
        yield 'end suspended' => ['suspended', 'ended'];
        yield 'archive ended' => ['ended', 'archived'];
        yield 'archive no show' => ['no_show', 'archived'];
        // Návrat z omylem zapsaného ukončení. Datum konce se zapisuje jedním
        // tlačítkem a formulář datum nabízí sám; splést se je běžné a opravit
        // to pak nešlo — podmínky ukončeného vztahu se editovat nesmí.
        yield 'reopen wrongly ended' => ['ended', 'active'];
    }

    #[DataProvider('allowedTransitions')]
    public function testAcceptsOnlyExplicitLifecycleEdges(string $from, string $to): void
    {
        (new PayrollEmploymentLifecycle())->assertTransition($from, $to);
        self::addToAssertionCount(1);
    }

    /**
     * `planned → active` tu schválně NENÍ: nástup, který se prostě stal, se
     * potvrzuje jedním krokem. Předregistrace zůstává, ale jako volba pro nástup
     * v budoucnu, ne jako povinná mezizastávka.
     *
     * `archived → active` odmítnuté zůstává — z archivu vede cesta jen zpátky
     * do stavu, ze kterého se archivovalo. Oživit vztah jde až odtamtud
     * (`archived → ended → active`), takže se to dělá vědomě ve dvou krocích.
     */
    public function testRejectsSkippedAndReverseTransitions(): void
    {
        $lifecycle = new PayrollEmploymentLifecycle();
        foreach ([
            ['planned', 'ended'],
            ['planned', 'suspended'],
            ['active', 'planned'],
            ['ended', 'preregistered'],
            ['archived', 'active'],
            ['archived', 'planned'],
        ] as [$from, $to]) {
            try {
                $lifecycle->assertTransition($from, $to);
                self::fail("Přechod {$from} → {$to} měl být odmítnut.");
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
