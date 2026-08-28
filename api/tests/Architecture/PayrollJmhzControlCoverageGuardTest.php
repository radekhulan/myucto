<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Vykonávací vrstva kontrol JMHZ dělí katalog na tři disjunktní množiny:
 * implementované, lokálně neověřitelné a mimo profil. Guard hlídá, že se
 * nerozjedou s katalogem ani mezi sebou.
 *
 * Bez něj se dá pokrytí tiše ztratit dvěma způsoby: překlepem v ID (kontrola
 * se prohlásí za implementovanou, ale evaluátor pro ni nemá větev) a přesunem
 * kontroly do „neověřitelných", kde už se nikdy nikdo nepodívá, jestli by
 * vyhodnotitelná nebyla.
 */
final class PayrollJmhzControlCoverageGuardTest extends TestCase
{
    public function testEveryDeclaredControlExistsInThePinnedCatalog(): void
    {
        $catalog = JmhzControlSourceCatalog::load();
        $known = $catalog->definitions();
        $evaluator = $this->evaluator($catalog);

        $declared = array_merge(
            $evaluator->implementedControlIds(),
            array_keys($evaluator->notEvaluableControlIds()),
        );
        $unknown = array_values(array_filter(
            $declared,
            static fn (int $controlId): bool => !isset($known[$controlId]),
        ));

        self::assertSame(
            [],
            $unknown,
            'Vrstva se odkazuje na kontroly, které v připnutém katalogu nejsou.',
        );
    }

    /**
     * Kontrola nesmí být zároveň implementovaná a prohlášená za neověřitelnou.
     * Kdyby byla, rozhodovalo by o ní pořadí větví v dispečeru, ne záměr.
     */
    public function testImplementedAndNotEvaluableSetsAreDisjoint(): void
    {
        $catalog = JmhzControlSourceCatalog::load();
        $evaluator = $this->evaluator($catalog);
        $overlap = array_values(array_intersect(
            $evaluator->implementedControlIds(),
            array_keys($evaluator->notEvaluableControlIds()),
        ));

        self::assertSame([], $overlap);
    }

    /**
     * Každá neověřitelná kontrola musí nést důvod, a ne jednoslovný. Zdůvodnění
     * je jediné, co uživateli i příštímu vývojáři říká, proč se s ní nedá nic
     * dělat lokálně.
     */
    public function testEveryNotEvaluableControlCarriesAReason(): void
    {
        $catalog = JmhzControlSourceCatalog::load();
        $evaluator = $this->evaluator($catalog);

        foreach ($evaluator->notEvaluableControlIds() as $controlId => $reason) {
            self::assertGreaterThan(
                40,
                mb_strlen($reason),
                "Kontrola {$controlId} nemá vysvětlený důvod neověřitelnosti.",
            );
        }
    }

    /**
     * Odchylka od doslovného znění katalogu se smí týkat jen kontroly, kterou
     * opravdu vyhodnocujeme. Odchylka u neimplementované kontroly by slibovala
     * rozhodnutí, které nikdo nedělá.
     */
    public function testDeviationsOnlyDescribeImplementedControls(): void
    {
        $catalog = JmhzControlSourceCatalog::load();
        $evaluator = $this->evaluator($catalog);
        $implemented = $evaluator->implementedControlIds();

        foreach ($evaluator->documentedDeviations() as $controlId => $reason) {
            self::assertContains(
                $controlId,
                $implemented,
                "Odchylka u kontroly {$controlId}, která se nevyhodnocuje.",
            );
            self::assertGreaterThan(
                80,
                mb_strlen($reason),
                "Odchylka u kontroly {$controlId} není doložená.",
            );
        }
    }

    /**
     * Implementovaná kontrola musí mít v dispečeru větev. Chybějící větev by se
     * jinak projevila až za běhu výjimkou uprostřed nácviku podání.
     */
    public function testEveryImplementedControlHasABranchInTheDispatcher(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2)
                . '/src/Service/Payroll/Submission/Jmhz/JmhzScenario1ControlEvaluator.php',
        );
        $dispatcher = substr(
            $source,
            (int) strpos($source, 'return match ($controlId) {'),
            (int) strpos($source, '// --- pojistná část')
                - (int) strpos($source, 'return match ($controlId) {'),
        );
        preg_match_all('/^\s{12}([0-9, ]+) =>/m', $dispatcher, $matches);
        $routed = [];
        foreach ($matches[1] as $group) {
            foreach (explode(',', $group) as $id) {
                $id = trim($id);
                if ($id !== '') {
                    $routed[] = (int) $id;
                }
            }
        }
        sort($routed);

        $catalog = JmhzControlSourceCatalog::load();
        $expected = $this->evaluator($catalog)
            ->implementedControlIds();
        sort($expected);

        self::assertSame($expected, $routed);
    }

    private function evaluator(JmhzControlSourceCatalog $catalog): JmhzScenario1ControlEvaluator
    {
        return new JmhzScenario1ControlEvaluator(
            $catalog->parameters(),
            new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
        );
    }
}
