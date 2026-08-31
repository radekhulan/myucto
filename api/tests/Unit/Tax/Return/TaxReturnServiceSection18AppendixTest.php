<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\TaxReturnService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * `TaxReturnService::needsSection18Statements()` a `attachIfWithinBudget()` — jádro
 * rozhodnutí, KDY se přehled o peněžních tocích (PP_PTOK) a o změnách vlastního kapitálu
 * (PP_ZVKAP) přikládají k DPPO XML, a sdíleného limitu 10 240 kB na SOUČET všech e-příloh
 * (dppdp9.xsd:6211), ne na jednu.
 *
 * Instance se skládá reflexí (bez volání konstruktoru — obě metody jsou čisté funkce nad
 * argumenty, žádnou z 25 závislostí TaxReturnService nepoužívají), stejný vzor jako
 * {@see TaxReturnServiceDppoAppendixWarningsTest}.
 */
final class TaxReturnServiceSection18AppendixTest extends TestCase
{
    private function service(): TaxReturnService
    {
        return (new ReflectionClass(TaxReturnService::class))->newInstanceWithoutConstructor();
    }

    private function needsSection18(array $category): bool
    {
        $method = new ReflectionMethod(TaxReturnService::class, 'needsSection18Statements');
        return $method->invoke($this->service(), $category);
    }

    // ── needsSection18Statements() — stejné kritérium jako Section18StatementsAction /
    // ClosingService `section18_statements_required` ─────────────────────────────────

    public function testLargeCategoryIsRequiredRegardlessOfScope(): void
    {
        self::assertTrue($this->needsSection18(['category' => 'large', 'scope' => 'full', 'scope_override' => null]));
    }

    public function testMediumCategoryIsRequiredRegardlessOfScope(): void
    {
        self::assertTrue($this->needsSection18(['category' => 'medium', 'scope' => 'small', 'scope_override' => null]));
    }

    public function testMicroCategoryWithFullScopeFromMandatoryAuditIsRequired(): void
    {
        // R18: povinný audit vynutí scope='full' i u mikro/malé ÚJ (EntityCategoryService)
        // — a i taková ÚJ přehledy podle § 18/2 potřebuje, i když kategorií je mikro.
        self::assertTrue($this->needsSection18(['category' => 'micro', 'scope' => 'full', 'scope_override' => null]));
    }

    public function testSmallCategoryWithoutAuditIsNotRequired(): void
    {
        self::assertFalse($this->needsSection18(['category' => 'small', 'scope' => 'small', 'scope_override' => null]));
    }

    public function testMicroCategoryWithoutAuditIsNotRequired(): void
    {
        self::assertFalse($this->needsSection18(['category' => 'micro', 'scope' => 'micro', 'scope_override' => null]));
    }

    public function testAdminFullScopeOverrideOnSmallCategoryDoesNotAloneTriggerRequirement(): void
    {
        // scope_override != null vyřazuje druhou podmínku (`scope_override === null`) —
        // ruční rozšíření rozsahu výkazů admin override NENÍ totéž jako zákonná povinnost
        // podle § 18/2 (stejné jako v ClosingService/Section18StatementsAction).
        self::assertFalse($this->needsSection18(['category' => 'small', 'scope' => 'full', 'scope_override' => 'full']));
    }

    public function testMissingKeysDefaultToNotRequired(): void
    {
        self::assertFalse($this->needsSection18([]));
    }

    // ── attachIfWithinBudget() — limit je na SOUČET všech e-příloh, ne na jednu ──────

    private function attach(array &$appendix, string $key, string $label, array $built, int &$usedBytes): void
    {
        $method = new ReflectionMethod(TaxReturnService::class, 'attachIfWithinBudget');
        $method->invokeArgs($this->service(), [&$appendix, $key, $label, $built, &$usedBytes]);
    }

    public function testTwoSmallAttachmentsBothFitWithinBudget(): void
    {
        $appendix = ['warnings' => []];
        $used = 0;
        $this->attach($appendix, 'a', 'A', ['file' => ['content' => str_repeat('x', 1024), 'filename' => 'a.pdf', 'label' => 'A'], 'warning' => null], $used);
        $this->attach($appendix, 'b', 'B', ['file' => ['content' => str_repeat('y', 1024), 'filename' => 'b.pdf', 'label' => 'B'], 'warning' => null], $used);

        self::assertArrayHasKey('a', $appendix);
        self::assertArrayHasKey('b', $appendix);
        self::assertSame(2048, $used);
        self::assertSame([], $appendix['warnings']);
    }

    /**
     * Klíčový scénář: KAŽDÁ příloha zvlášť je pod limitem, ale SOUČET ho přesahuje —
     * druhá se nesmí připojit tiše ořízlá, musí se vynechat s warningem. Dřív se kontrola
     * dělala jen na jedinou možnou přílohu, takže tenhle součtový případ nemohl nastat;
     * teď mohou být přílohy tři.
     */
    public function testSumOverLimitSkipsSecondAttachmentWithWarning(): void
    {
        $limit = 10_240 * 1024;
        $first = str_repeat('a', $limit - 100); // pod limitem samo o sobě
        $second = str_repeat('b', 200); // taky pod limitem samo o sobě, ale součet ne

        $appendix = ['warnings' => []];
        $used = 0;
        $this->attach($appendix, 'first', 'První příloha', ['file' => ['content' => $first, 'filename' => 'a.pdf', 'label' => 'A'], 'warning' => null], $used);
        $this->attach($appendix, 'second', 'Druhá příloha', ['file' => ['content' => $second, 'filename' => 'b.pdf', 'label' => 'B'], 'warning' => null], $used);

        self::assertArrayHasKey('first', $appendix);
        self::assertArrayNotHasKey('second', $appendix);
        self::assertCount(1, $appendix['warnings']);
        self::assertStringContainsString('Druhá příloha', $appendix['warnings'][0]);
        self::assertStringContainsString('10 240', $appendix['warnings'][0]);
    }

    public function testWarningFromBuiltIsPropagatedEvenWithoutFile(): void
    {
        $appendix = ['warnings' => []];
        $used = 0;
        $this->attach($appendix, 'x', 'X', ['file' => null, 'warning' => 'nepodařilo se sestavit'], $used);

        self::assertArrayNotHasKey('x', $appendix);
        self::assertSame(0, $used);
        self::assertSame(['nepodařilo se sestavit'], $appendix['warnings']);
    }
}
