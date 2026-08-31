<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\AccidentInsuranceRateSchedule;
use PHPUnit\Framework\TestCase;

final class AccidentInsuranceRateScheduleTest extends TestCase
{
    private AccidentInsuranceRateSchedule $schedule;

    protected function setUp(): void
    {
        $this->schedule = new AccidentInsuranceRateSchedule();
    }

    public function testGroupsFollowTheOrderOfTheAnnex(): void
    {
        self::assertSame(
            ['50.40', '9.80', '8.40', '7.00', '4.20', '2.80', '10.50', '5.60'],
            $this->schedule->rates(),
            'Sazby musí být v pořadí přílohy č. 2 — obě skupiny bez kódu jsou až za výčtem.',
        );
    }

    public function testEveryGroupWithoutCodesCarriesItsCriterionInstead(): void
    {
        $withoutCodes = array_values(array_filter(
            $this->schedule->groups(),
            static fn (array $group): bool => $group['kind'] !== AccidentInsuranceRateSchedule::KIND_CLASSIFIED,
        ));

        self::assertCount(2, $withoutCodes);
        self::assertSame(AccidentInsuranceRateSchedule::KIND_HAZARD, $withoutCodes[0]['kind']);
        self::assertSame('10.50', $withoutCodes[0]['rate_per_mille']);
        self::assertStringContainsString('výbušninami', (string) $withoutCodes[0]['label']);
        self::assertSame(AccidentInsuranceRateSchedule::KIND_RESIDUAL, $withoutCodes[1]['kind']);
        self::assertSame('5.60', $withoutCodes[1]['rate_per_mille']);
        self::assertSame('Ostatní ekonomické činnosti', $withoutCodes[1]['label']);
        foreach ($withoutCodes as $group) {
            self::assertSame([], $group['activities']);
        }
    }

    public function testKnownAnnexRowsHaveTheRateTheDecreeGivesThem(): void
    {
        $byCode = [];
        foreach ($this->schedule->groups() as $group) {
            foreach ($group['activities'] as $activity) {
                $byCode[$activity['okec_code']] = $group['rate_per_mille'];
            }
        }

        self::assertSame('50.40', $byCode['10.1'], 'Dobývání černého uhlí.');
        self::assertSame('9.80', $byCode['45'], 'Stavebnictví.');
        self::assertSame('8.40', $byCode['36.1'], 'Výroba nábytku.');
        self::assertSame('7.00', $byCode['01'], 'Zemědělství.');
        self::assertSame('4.20', $byCode['62'], 'OKEČ 62 je letecká doprava.');
        self::assertSame('2.80', $byCode['74.4'], 'Reklamní činnosti.');
    }

    public function testAnnexRowIsKeptVerbatimEvenWhenTheDecreeItselfIsWrong(): void
    {
        // 24.12 je v OKEČ „Výroba barviv a pigmentů", protektorování je 25.12.
        // Chyba je v úředním textu; přepis ji nesmí tiše opravovat.
        $labels = [];
        foreach ($this->schedule->groups() as $group) {
            foreach ($group['activities'] as $activity) {
                $labels[$activity['okec_code']] = $activity['label'];
            }
        }

        self::assertSame('Protektorování a opravy pryžových pneumatik', $labels['24.12']);
    }

    public function testMinimumComesFromTheAnnexNotFromAParagraph(): void
    {
        self::assertSame(100, $this->schedule->minimumQuarterlyPremiumCzk());
        self::assertStringContainsString(
            'příloh',
            mb_strtolower((string) $this->schedule->legal()['minimum_quarterly_premium_source'], 'UTF-8'),
        );
    }

    public function testLegalIdentityNamesTheAmendmentTheRatesComeFrom(): void
    {
        $legal = $this->schedule->legal();

        self::assertSame('125/1993 Sb.', $legal['decree']);
        self::assertSame('vyhláška č. 487/2001 Sb., čl. I bod 3', $legal['rates_source']);
        self::assertSame('2002-01-01', $legal['rates_effective_from']);
        self::assertSame('OKEČ', $legal['classification']);
        self::assertSame('2007-12-31', $legal['classification_retired_on']);
        self::assertSame('CZ-NACE', $legal['classification_successor']);
    }

    public function testIsAnnexRateAcceptsBothSeparatorsAndShortForms(): void
    {
        self::assertTrue($this->schedule->isAnnexRate('4.20'));
        self::assertTrue($this->schedule->isAnnexRate('4,2'));
        self::assertTrue($this->schedule->isAnnexRate('7'));
        self::assertFalse($this->schedule->isAnnexRate('3.50'));
        self::assertFalse($this->schedule->isAnnexRate('nesmysl'));
    }

    public function testNormalizeRatePadsToTwoDecimals(): void
    {
        self::assertSame('4.20', AccidentInsuranceRateSchedule::normalizeRate('4,2'));
        self::assertSame('7.00', AccidentInsuranceRateSchedule::normalizeRate(' 7 '));
        self::assertSame('50.40', AccidentInsuranceRateSchedule::normalizeRate('50.40'));
        self::assertNull(AccidentInsuranceRateSchedule::normalizeRate('4.205'));
        self::assertNull(AccidentInsuranceRateSchedule::normalizeRate(''));
    }

    public function testSuggestionMatchesTheActivityName(): void
    {
        $suggestions = $this->schedule->suggestByActivityName('Výroba nábytku');

        self::assertNotSame([], $suggestions);
        self::assertSame('36.1', $suggestions[0]['okec_code']);
        self::assertSame('8.40', $suggestions[0]['rate_per_mille']);
    }

    public function testSuggestionIgnoresDiacriticsAndInflection(): void
    {
        $suggestions = $this->schedule->suggestByActivityName('provoz rozhlasu a televize');

        self::assertNotSame([], $suggestions);
        self::assertSame('92.2', $suggestions[0]['okec_code']);
    }

    public function testSuggestionPrefersTheRowTheMatchExplainsBest(): void
    {
        // „agentur" sedí i na realitní agentury v OKEČ 70 (4,2 ‰). Ta shoda ale
        // vysvětluje jen zlomek dlouhého názvu, kdežto „Reklamní činnosti" celý.
        $suggestions = $this->schedule->suggestByActivityName('Činnosti reklamních agentur');

        self::assertNotSame([], $suggestions);
        self::assertSame('74.4', $suggestions[0]['okec_code']);
        self::assertSame('2.80', $suggestions[0]['rate_per_mille']);
    }

    /**
     * Past, kvůli které se návrh hledá podle názvu a ne podle čísla: OKEČ 62 je
     * letecká doprava, CZ-NACE 62 jsou činnosti v oblasti informačních
     * technologií. Kdyby se párovala čísla, dostal by software house 4,2 ‰
     * letecké dopravy.
     */
    public function testSuggestionNeverMatchesOnTheCodeNumber(): void
    {
        $codes = fn (string $query): array
            => array_column($this->schedule->suggestByActivityName($query), 'okec_code');

        self::assertNotContains('62', $codes('Programování'));
        self::assertNotContains('62', $codes('62.01 Programování'));
        self::assertNotContains('62', $codes('62'));
        // Že ten řádek v sazebníku vůbec je, ukáže dotaz podle jeho názvu —
        // jinak by testy nad prázdným výsledkem prošly i s rozbitým hledáním.
        self::assertContains('62', $codes('Letecká doprava'));
    }

    public function testSuggestionIsEmptyWhenNothingInTheAnnexMatches(): void
    {
        self::assertSame([], $this->schedule->suggestByActivityName('Programování'));
        // Krátká a generická slova nesmějí vygenerovat shodu se vším.
        self::assertSame([], $this->schedule->suggestByActivityName('Ostatní činnosti'));
    }

    public function testSuggestionRespectsTheLimit(): void
    {
        $suggestions = $this->schedule->suggestByActivityName('Tkaní lnářských tkanin', 2);

        self::assertLessThanOrEqual(2, count($suggestions));
    }

    public function testSuggestionRejectsLimitOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->schedule->suggestByActivityName('Výroba nábytku', 0);
    }

    public function testMissingResourceFailsLoudlyInsteadOfReturningNothing(): void
    {
        $schedule = new AccidentInsuranceRateSchedule(
            sys_get_temp_dir() . '/accident-insurance-neexistuje',
        );

        $this->expectException(\RuntimeException::class);
        $schedule->groups();
    }
}
