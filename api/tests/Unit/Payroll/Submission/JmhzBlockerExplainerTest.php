<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerExplainer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Blocker;
use PHPUnit\Framework\TestCase;

final class JmhzBlockerExplainerTest extends TestCase
{
    public function testGroupsBlockersAndHidesInternalIdentifiers(): void
    {
        $message = JmhzBlockerExplainer::describe([
            new JmhzScenario1Blocker(
                'component_jmhz_mapping_missing',
                'component',
                1,
            ),
            new JmhzScenario1Blocker(
                'component_jmhz_mapping_missing',
                'component',
                4,
            ),
            new JmhzScenario1Blocker(
                'jmhz_work_month_not_approved',
                'employment',
                10228,
            ),
        ]);

        self::assertStringContainsString('2 mzdové složky', $message);
        self::assertStringContainsString('Mzdy → Mzdové složky', $message);
        self::assertStringContainsString('1 pracovní vztah', $message);
        self::assertStringContainsString('Mzdy → Pracovní doba', $message);
        self::assertStringNotContainsString('component_jmhz_mapping_missing', $message);
        self::assertStringNotContainsString('10228', $message);
    }

    /**
     * Účetní se 200 brigádníky dostala „Dotčeno: 1 zaměstnanec" a musela je
     * procházet ručně, aby našla toho jednoho. Hláška teď jmenuje.
     */
    public function testNamesAffectedPeopleAndCapsTheList(): void
    {
        $blockers = [new JmhzScenario1Blocker('jmhz_annual_request_source_missing', 'person', 5)];
        $people = [5 => 'Jana Syntetická'];
        $employments = [];
        for ($id = 1; $id <= 12; ++$id) {
            $blockers[] = new JmhzScenario1Blocker('jmhz_average_hourly_earning_missing', 'employment', $id);
            $employments[$id] = "Osoba {$id} (D{$id})";
        }

        $message = JmhzBlockerExplainer::describe($blockers, [
            'person' => $people,
            'employment' => $employments,
        ]);

        self::assertStringContainsString('Dotčeno: 1 zaměstnanec (Jana Syntetická).', $message);
        self::assertStringContainsString('Dotčeno: 12 pracovních vztahů (Osoba 1 (D1), ', $message);
        self::assertStringContainsString('Osoba 10 (D10) a další 2).', $message);
        self::assertStringNotContainsString('Osoba 11', $message);
    }

    public function testUnknownBlockerStillHasActionableCzechFallback(): void
    {
        $message = JmhzBlockerExplainer::describe([
            new JmhzScenario1Blocker('future_rule', 'employment', 77),
        ]);

        self::assertSame(
            'Chybí zákonný údaj potřebný pro měsíční hlášení. '
                . 'Dotčeno: 1 pracovní vztah. '
                . 'Otevřete Mzdy → Zaměstnanci a doplňte zvýrazněná pole.',
            $message,
        );
    }

    /**
     * Haléřová částka v hlášení dřív končila obecnou větou „chybí zákonný
     * údaj", ze které účetní nepoznala, co opravit.
     */
    public function testWholeCrownFindingSaysWhatToFixInsteadOfFallback(): void
    {
        $message = JmhzBlockerExplainer::describe([
            new JmhzScenario1Blocker('jmhz_scenario1_whole_czk_required', 'person', 11, ['10477']),
        ]);

        self::assertStringNotContainsString('Chybí zákonný údaj', $message);
        self::assertStringContainsString('celých korunách', $message);
        self::assertStringContainsString('Mzdy → Mzdové běhy', $message);
        self::assertStringContainsString('Dotčeno: 1 zaměstnanec.', $message);
    }

    /**
     * E2E nad firmou s hodinovými mzdami: 18 vztahů s haléřovým příplatkem
     * za přesčas dalo 144 nálezů a hláška tvrdila „Dotčeno: 144 …". Účetní
     * potřebuje vědět, KOLIK lidí a KTERÁ pole, ne kolik řádků nálezů.
     */
    public function testWholeCrownFindingNamesFieldsAndCountsDistinctPeopleAndRelations(): void
    {
        $message = JmhzBlockerExplainer::describe([
            new JmhzScenario1Blocker('jmhz_scenario1_whole_czk_required', 'employment', 101, ['10328']),
            new JmhzScenario1Blocker('jmhz_scenario1_whole_czk_required', 'employment', 101, ['10333']),
            new JmhzScenario1Blocker('jmhz_scenario1_whole_czk_required', 'employment', 101, ['10476']),
            new JmhzScenario1Blocker('jmhz_scenario1_whole_czk_required', 'employment', 102, ['10333']),
            new JmhzScenario1Blocker('jmhz_scenario1_whole_czk_required', 'person', 11, ['10286']),
            new JmhzScenario1Blocker('jmhz_scenario1_whole_czk_required', 'person', 11, ['10344']),
            new JmhzScenario1Blocker('jmhz_scenario1_whole_czk_required', 'person', 12, ['10286']),
        ]);

        self::assertStringContainsString('Dotčeno: 2 pracovní vztahy, 2 zaměstnanci.', $message);
        self::assertStringContainsString('Mzda za práci zúčtovaná', $message);
        self::assertStringContainsString('Příplatky za práci přesčas', $message);
        self::assertStringContainsString('Zúčtovaný příjem - celkem', $message);
        self::assertStringContainsString('Čistý příjem', $message);
        self::assertSame(1, substr_count($message, 'Příplatky za práci přesčas'));
        self::assertStringNotContainsString('Dotčeno: 7', $message);
        self::assertStringNotContainsString('101', $message);
    }

    /**
     * Podklady JMHZ haléře zaokrouhlit neříkají; zákoník práce ano, ale jen
     * u mzdy a jen nahoru. Rada „opravte na celé koruny" bez směru svádí
     * k tomu zaměstnanci mzdu ubrat.
     */
    public function testWholeCrownGuidanceSaysWhyAndThatWagesRoundUp(): void
    {
        $guidance = JmhzBlockerExplainer::guidance('jmhz_scenario1_whole_czk_required');

        self::assertStringContainsString('nestanoví', $guidance);
        self::assertStringContainsString('§ 142 odst. 2', $guidance);
        self::assertStringContainsString('nahoru', $guidance);
        self::assertStringContainsString('Mzdy → Mzdové běhy', $guidance);
    }

    public function testExplainsWhyAbsenceCannotUseAutomaticEldpEvidence(): void
    {
        $message = JmhzBlockerExplainer::describe([
            new JmhzScenario1Blocker(
                'jmhz_eldp_absences_unsupported',
                'employment',
                10228,
            ),
            new JmhzScenario1Blocker(
                'jmhz_eldp_evidence_missing',
                'employment',
                10228,
            ),
        ]);

        self::assertStringContainsString('Měsíc obsahuje nepřítomnost', $message);
        // Účetní se má z hlášky dozvědět, co se odbaví samo, aby nehledala
        // chybu tam, kde je jen nepodporovaný druh nepřítomnosti.
        self::assertStringContainsString('dovolená, nemoc, karanténa a ošetřovné', $message);
        self::assertStringContainsString('Mzdy → Pracovní doba', $message);
        self::assertStringContainsString('zpracujte jej individuálně', $message);
        self::assertStringNotContainsString('10228', $message);
        self::assertStringNotContainsString('Evidenční list DP', $message);
    }

    public function testExplainsMixedScenarioWithoutPretendingAFieldIsMissing(): void
    {
        $message = JmhzBlockerExplainer::describe([
            new JmhzScenario1Blocker('jmhz_scenario1_scope_unsupported', 'preparation', 501),
        ]);

        self::assertStringContainsString('smíšené nebo zvláštní scénáře JMHZ', $message);
        self::assertStringContainsString('zpracujte individuálně', $message);
        self::assertStringNotContainsString('Chybí zákonný údaj', $message);
    }

    public function testExplainsFosterCarerEvidenceGapWithoutSuggestingZeroValues(): void
    {
        $message = JmhzBlockerExplainer::describe([
            new JmhzScenario1Blocker('jmhz_scenario2_evidence_gap', 'employment', 101),
        ]);

        self::assertStringContainsString('ověřený zdroj', $message);
        self::assertStringContainsString('nelze bezpečně doplnit odhadem', $message);
        self::assertStringNotContainsString('nulu', $message);
    }
}
