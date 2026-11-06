<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use DOMDocument;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlEvaluationReport;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlFinding;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlPassability;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlEvaluator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Tests\Unit\Payroll\Submission\JmhzXmlSample2027 as Sample;
use PHPUnit\Framework\TestCase;

/**
 * Kontroly měsíčního hlášení verze 2027 (katalog kontrol 1.5.0.4, slovník
 * 1.5.0.3, XSD 1.5.0.3). Hlášení se staví ručně ({@see JmhzXmlSample2027})
 * a každý vzorek se před vyhodnocením ověří proti XSD 1.5.0.3; výjimky jsou
 * u testu pojmenované. Verze 2026 se nemění, hlídají ji stávající testy
 * a {@see self::testVersion2026KeepsItsCatalogAndDispositions()}.
 */
final class JmhzScenario1Control2027Test extends TestCase
{
    private const XSD = __DIR__ . '/../../../../xsd/jmhz/jmhz-1.5.0.3/jmhzPodani.xsd';

    private static ?JmhzScenario1ControlValidator $validator = null;

    public function testCatalog2027IsPinnedWithItsOwnKeyAndControls(): void
    {
        $catalog = JmhzControlSourceCatalog::loadFor(JmhzSpecVersion::V2027);

        self::assertSame(JmhzSpecVersion::V2027, $catalog->version());
        self::assertSame(JmhzControlSourceCatalog::CATALOG_KEY_2027, $catalog->catalogKey());
        self::assertCount(221, $catalog->definitions());
        self::assertArrayNotHasKey(109, $catalog->definitions());
        // 290 je ve verzi 2027 nepropustná, ve verzi 2026 propustná.
        self::assertSame('blocking', $catalog->definition(290)->remotePassability->value);
        self::assertSame('passable', JmhzControlSourceCatalog::load()->definition(290)->remotePassability->value);
        self::assertSame(20368, $catalog->definition(368)->id->disErrorCode());
        self::assertSame(40365, $catalog->definition(365)->id->cjmhzErrorCode());

        $report = $this->validate(Sample::document(Sample::form(Sample::bezPriznaku())));
        self::assertSame(JmhzControlSourceCatalog::CATALOG_KEY_2027, $report->catalogKey);
        self::assertSame(JmhzControlSourceCatalog::MANIFEST_SHA256_2027, $report->catalogManifestSha256);
    }

    public function testVersion2026KeepsItsCatalogAndDispositions(): void
    {
        $evaluator2026 = $this->evaluator(JmhzSpecVersion::V2026);
        $evaluator2027 = $this->evaluator(JmhzSpecVersion::V2027);

        self::assertContains(109, $evaluator2026->implementedControlIds());
        self::assertContains(333, $evaluator2026->implementedControlIds());
        self::assertArrayNotHasKey(333, $evaluator2026->notEvaluableControlIds());
        self::assertArrayNotHasKey(365, $evaluator2026->notEvaluableControlIds());
        self::assertNotContains(347, $evaluator2026->implementedControlIds());

        self::assertNotContains(109, $evaluator2027->implementedControlIds());
        self::assertNotContains(333, $evaluator2027->implementedControlIds());
        self::assertArrayHasKey(333, $evaluator2027->notEvaluableControlIds());
        foreach ([347, 350, 351, 362, 363, 366, 370, 378, 379, 383, 384, 385, 386, 387, 388] as $controlId) {
            self::assertContains($controlId, $evaluator2027->implementedControlIds(), "Kontrola {$controlId}.");
        }
        foreach ([365, 380, 381, 382] as $controlId) {
            self::assertArrayHasKey($controlId, $evaluator2027->notEvaluableControlIds(), "Kontrola {$controlId}.");
        }
    }

    /**
     * Každá kontrola katalogu 1.5.0.4 musí mít dispozici: implementaci,
     * lokální neověřitelnost, nebo vědomé vynechání mimo profil. Nová kontrola
     * bez dispozice by blokovala jako nepokrytá.
     */
    public function testEveryNewControlOf2027HasADisposition(): void
    {
        $evaluator = $this->evaluator(JmhzSpecVersion::V2027);
        $catalog = JmhzControlSourceCatalog::loadFor(JmhzSpecVersion::V2027);
        $old = JmhzControlSourceCatalog::load()->definitions();
        foreach (array_diff_key($catalog->definitions(), $old) as $controlId => $definition) {
            self::assertTrue($evaluator->handles($controlId), "Nová kontrola {$controlId} nemá dispozici.");
        }
    }

    public function testXmlOfTheOtherVersionIsRejected(): void
    {
        $xml2027 = Sample::document(Sample::form(Sample::bezPriznaku()));
        try {
            JmhzScenario1ControlValidator::create(CzechPayrollRulesets2026::provider())
                ->validate($xml2027, new JmhzControlContext('2027-05-10'));
            self::fail('Hlášení 2027 se nesmí vyhodnotit kontrolami 2026.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_projection_version_mismatch', $exception->validationCode);
        }
        try {
            self::validator()->validate(JmhzXmlSample::minimal(), new JmhzControlContext('2026-08-14'));
            self::fail('Hlášení 2026 se nesmí vyhodnotit kontrolami 2027.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_projection_version_mismatch', $exception->validationCode);
        }
    }

    /**
     * Ve verzi 2027 leží příznak primárního vztahu v hlavičce součásti ve
     * jmenném prostoru formulářů. Hlavička se nesmí zaměnit za tělo formuláře
     * a volné atributy `CustomAtribut1` až 5 se nepromítají.
     */
    public function testFormHeaderOf2027IsProjectedIntoTheForm(): void
    {
        $report = $this->validate(Sample::document(Sample::form(Sample::bezPriznaku())));

        self::assertSame(JmhzControlOutcome::Passed, $this->finding($report, 255)->outcome);
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($report, 332)->outcome);
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($report, 303)->outcome);
    }

    /** Kontrola 333 má ve verzi 2027 zase význam časového omezení slevy podle data přijetí. */
    public function testControl333IsLeftToTheCsszIn2027(): void
    {
        $report = $this->validate(Sample::document(Sample::form(Sample::bezPriznaku())));
        $finding = $this->finding($report, 333);

        self::assertSame(JmhzControlOutcome::NotEvaluable, $finding->outcome);
        self::assertStringContainsString('10006', $finding->message);
    }

    /** Kontrola 57 ve verzi 2027 sčítá hodiny všech rizikových prací ∑(10273). */
    public function testRiskyHoursAreSummedAcrossRiskBlocks(): void
    {
        $xml = static fn (string $first, string $second): string => Sample::document(Sample::form(
            Sample::bezPriznaku(['rozpad' => Sample::rozpad(
                Sample::risk($first, null, '2'),
                Sample::risk($second, null, '3'),
            )]),
        ));

        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($xml('100.000', '84.000')), 57)->outcome);
        $failed = $this->finding($this->validate($xml('100.000', '85.000')), 57);
        self::assertSame(JmhzControlOutcome::Failed, $failed->outcome);
        self::assertStringContainsString('Součet hodin', $failed->message);
    }

    /** Kontrola 193 ve verzi 2027: úhrn vyplacených bonusů (10577) jen v lednu. */
    public function testBonusTotalIsJanuaryOnly(): void
    {
        $xml = static fn (int $month): string => Sample::document(
            Sample::form(Sample::bezPriznaku(['souhrnDataZec' => Sample::souhrnDataZec([
                'annual' => Sample::annual(bonusTotal: 1200),
            ])])),
            month: $month,
        );

        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($xml(1)), 193)->outcome);
        $failed = $this->finding($this->validate($xml(4)), 193);
        self::assertSame(JmhzControlOutcome::Failed, $failed->outcome);
        self::assertStringContainsString('10577', $failed->message);
    }

    /** Kontrola 194 ve verzi 2027: typ kolektivní smlouvy (10214) je měsíční údaj. */
    public function testCollectiveAgreementIsNoLongerDecemberOnly(): void
    {
        $report = $this->validate(Sample::document(Sample::form(Sample::bezPriznaku()), souhrn: Sample::souhrn(0)));

        self::assertNotSame(JmhzControlOutcome::Failed, $this->finding($report, 194)->outcome);
    }

    /** Kontrola 244 ve verzi 2027: bez prohlášení nesmí být ani datum jeho podpisu (10575). */
    public function testSignatureDateIsForbiddenWithoutDeclaration(): void
    {
        $report = $this->validate(Sample::document(Sample::form(Sample::bezPriznaku([
            'souhrnDataZec' => Sample::souhrnDataZec(['declaration' => false, 'credits' => Sample::credits(null)]),
        ]))));
        $finding = $this->finding($report, 244);

        self::assertSame(JmhzControlOutcome::Failed, $finding->outcome);
        self::assertStringContainsString('10575', $finding->message);
    }

    /**
     * Kontrola 248 ve verzi 2027 zná nové souhrnné atributy (např. 10579).
     * Vzorek záměrně vynechává povinné `prijmy`, aby v souhrnných datech
     * zůstal jen nový atribut; proti XSD proto neprochází.
     */
    public function testNewSummaryAttributesBelongToThePrimaryEmploymentOnly(): void
    {
        $souhrn = (string) preg_replace('/<form:prijmy>.*?<\/form:prijmy>/s', '', Sample::souhrnDataZec());
        $souhrn = (string) preg_replace('/<form:mzdaCista>.*<\/form:zdravPojZamestnanec>/s', '', $souhrn);
        $souhrn = str_replace('<form:prohlaseniPoplatnika>true</form:prohlaseniPoplatnika>', '', $souhrn);
        $xml = Sample::document(Sample::form(Sample::bezPriznaku(['souhrnDataZec' => $souhrn]), primary: false));
        $finding = $this->finding($this->validate($xml, schemaValid: false), 248);

        self::assertSame(JmhzControlOutcome::Failed, $finding->outcome);
        self::assertStringContainsString('10579', $finding->message);
    }

    /** Kontrola 304 ve verzi 2027: příjem pro zálohu a podle § 6 odst. 4 ZDP nejsou kladné zároveň. */
    public function testAdvanceAndSeparateIncomeAreExclusive(): void
    {
        $xml = static fn (int $advance, int $separate): string => Sample::document(Sample::form(Sample::bezPriznaku([
            'dan' => "<form:dan><form:prijem>{$advance}</form:prijem><form:prijemPar6>{$separate}</form:prijemPar6></form:dan>",
        ])));

        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($xml(30000, 0)), 304)->outcome);
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($this->validate($xml(30000, 5000)), 304)->outcome);
    }

    /** Kontrola 369 ve verzi 2027: kód ELDP vyžaduje i vyměřovací základ (10245). */
    public function testEldpCodeRequiresAssessmentBase(): void
    {
        $xml = Sample::document(Sample::form(Sample::bezPriznaku(['eldp' => Sample::eldp(base: null)])));
        $finding = $this->finding($this->validate($xml), 369);

        self::assertSame(JmhzControlOutcome::Failed, $finding->outcome);
        self::assertStringContainsString('10245', $finding->message);
        self::assertSame(JmhzControlOutcome::Passed, $this->finding(
            $this->validate(Sample::document(Sample::form(Sample::bezPriznaku()))),
            369,
        )->outcome);
    }

    public function testDeferredIncomePeriodsMustBeUniqueAndNotBefore2026(): void
    {
        $duplicate = $this->validate(Sample::document(Sample::form(Sample::odlozenyPrijem(
            '2',
            Sample::deferredPeriod(1, 2027, '1++', 10) . Sample::deferredPeriod(1, 2027, '1++', 10),
        ))));
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($duplicate, 347)->outcome);

        $distinct = $this->validate(Sample::document(Sample::form(Sample::odlozenyPrijem(
            '2',
            Sample::deferredPeriod(1, 2027, '1++', 10) . Sample::deferredPeriod(2, 2027, '1++', 10),
        ))));
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($distinct, 347)->outcome);
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($distinct, 350)->outcome);
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($distinct, 351)->outcome);

        $tooEarly = $this->validate(Sample::document(Sample::form(Sample::odlozenyPrijem(
            '2',
            Sample::deferredPeriod(12, 2025, '1++', 10),
        ))));
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($tooEarly, 350)->outcome);
    }

    /** Kontrola 351: měsíc odloženého příjmu podle typu. */
    public function testDeferredIncomePeriodDependsOnType(): void
    {
        $withMonth = $this->validate(Sample::document(Sample::form(Sample::odlozenyPrijem(
            '1',
            Sample::deferredPeriod(1, 2027),
        ))));
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($withMonth, 351)->outcome);

        $withoutMonth = $this->validate(Sample::document(Sample::form(Sample::odlozenyPrijem(
            '2',
            Sample::deferredPeriod(null, null, '1++', 10),
        ))));
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($withoutMonth, 351)->outcome);

        $notBeforePeriod = $this->validate(Sample::document(Sample::form(Sample::odlozenyPrijem(
            '3',
            Sample::deferredPeriod(4, 2027, '1++', 10),
        ))));
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($notBeforePeriod, 351)->outcome);

        $afterTermination = $this->validate(Sample::document(Sample::form(Sample::odlozenyPrijem(
            '1',
            Sample::deferredPeriod(null, null),
        ))));
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($afterTermination, 351)->outcome);
    }

    /** Kontroly 362 a 363: příjem po skončení má jeden blok ELDP s nulovými dny a kódem „P". */
    public function testIncomeAfterTerminationHasOneEldpBlockWithoutDays(): void
    {
        $clean = $this->validate(Sample::document(Sample::form(Sample::odlozenyPrijem(
            '1',
            Sample::deferredPeriod(null, null),
        ))));
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($clean, 362)->outcome);
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($clean, 363)->outcome);

        $twoBlocks = $this->validate(Sample::document(Sample::form(Sample::odlozenyPrijem(
            '1',
            Sample::deferredPeriod(null, null) . Sample::deferredPeriod(null, null),
        ))));
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($twoBlocks, 362)->outcome);

        $withDays = $this->validate(Sample::document(Sample::form(Sample::odlozenyPrijem(
            '5',
            Sample::deferredPeriod(null, null, '1++', 10),
        ))));
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($withDays, 363)->outcome);
    }

    /** Kontrola 366: sleva na studenta jen s prohlášením a do 26 (doktorand 28) let. */
    public function testStudentCreditRespectsDeclarationAndAge(): void
    {
        $xml = static fn (string $birthDate, bool $doctoral, bool $declaration = true): string => Sample::document(
            Sample::form(Sample::bezPriznaku([
                'birthDate' => $birthDate,
                'souhrnDataZec' => Sample::souhrnDataZec([
                    'declaration' => $declaration,
                    'credits' => Sample::credits(2570, 335, $doctoral),
                ]),
            ])),
        );

        // 26 let právě 1. 4. 2027: za duben už sleva nenáleží.
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($this->validate($xml('2001-04-01', false)), 366)->outcome);
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($xml('2001-04-02', false)), 366)->outcome);
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($xml('2001-04-01', true)), 366)->outcome);
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($this->validate($xml('2005-01-01', false, false)), 366)->outcome);
    }

    /** Kontroly 370, 379 a 383 berou meze z parametrů katalogu 1.5.0.4 (řádky 25 až 31). */
    public function testLimitsComeFromCatalogParameters(): void
    {
        $savings = static fn (int $amount): string => Sample::document(Sample::form(Sample::bezPriznaku([
            'souhrnDataZec' => Sample::souhrnDataZec(['savings' => $amount]),
        ])));
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($savings(50000)), 370)->outcome);
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($this->validate($savings(50001)), 370)->outcome);

        $credits = static fn (bool $multiMonth, int $basic): string => Sample::document(Sample::form(Sample::bezPriznaku([
            'souhrnDataZec' => Sample::souhrnDataZec(['multiMonth' => $multiMonth, 'credits' => Sample::credits($basic)]),
        ])));
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($credits(false, 2570)), 379)->outcome);
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($this->validate($credits(false, 2571)), 379)->outcome);
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($credits(true, 5140)), 379)->outcome);

        $topUp = static fn (int $amount): string => Sample::document(Sample::form(Sample::bezPriznaku([
            'souhrnDataZec' => Sample::souhrnDataZec(['annual' => Sample::annual($amount, $amount)]),
        ])));
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($topUp(0)), 383)->outcome);
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($this->validate($topUp(50)), 383)->outcome);
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($topUp(51)), 383)->outcome);
    }

    /** Kontrola 378: bez zvýhodnění na děti je doplatek daňového bonusu nulový. */
    public function testBonusTopUpRequiresChildCredit(): void
    {
        $xml = static fn (bool $children): string => Sample::document(Sample::form(Sample::bezPriznaku([
            'souhrnDataZec' => Sample::souhrnDataZec(['annual' => Sample::annual(500, 0, 500, $children)]),
        ])));

        self::assertSame(JmhzControlOutcome::Failed, $this->finding($this->validate($xml(false)), 378)->outcome);
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($xml(true)), 378)->outcome);
    }

    /** Kontrola 384: úhrn sražených záloh v souhrnu = ∑(10305) + ∑(10309). */
    public function testWithheldTaxTotalMatchesTheForms(): void
    {
        $xml = static fn (int $total): string => Sample::document(
            Sample::form(Sample::bezPriznaku([
                'souhrnDataZec' => Sample::souhrnDataZec(['withheld' => 3000, 'withheldPar6' => 500]),
            ])),
            souhrn: Sample::souhrn($total),
        );

        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($xml(3500)), 384)->outcome);
        $failed = $this->finding($this->validate($xml(3000)), 384);
        self::assertSame(JmhzControlOutcome::Failed, $failed->outcome);
        self::assertSame(40384, $failed->errorCode);
    }

    /** Kontrola 385 a kód obce „Mimo ČR" (111111) z ZP 070. */
    public function testWorkplaceAbroadNeedsCountryAndNoCzechAddress(): void
    {
        $abroad = $this->validate(Sample::document(Sample::form(Sample::bezPriznaku([
            'workplace' => '<form:obec>Wien</form:obec><form:kodObce>111111</form:kodObce><form:kodStatu>AT</form:kodStatu>',
        ]))));
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($abroad, 385)->outcome);
        self::assertNotSame(JmhzControlOutcome::Failed, $this->finding($abroad, 152)->outcome);

        $withCzechAddress = $this->validate(Sample::document(Sample::form(Sample::bezPriznaku([
            'workplace' => '<form:cisloPopisne>1</form:cisloPopisne><form:obec>Wien</form:obec>'
                . '<form:kodObce>111111</form:kodObce><form:kodStatu>AT</form:kodStatu>',
        ]))));
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($withCzechAddress, 385)->outcome);

        $czechWithoutPostcode = $this->validate(Sample::document(Sample::form(Sample::bezPriznaku([
            'workplace' => '<form:cisloPopisne>1</form:cisloPopisne><form:obec>Brno</form:obec>'
                . '<form:kodObce>582786</form:kodObce><form:kodStatu>CZ</form:kodStatu>',
        ]))));
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($czechWithoutPostcode, 385)->outcome);
    }

    /** Kontroly 386 a 387: směny jen u kategorie 4 s hodinami a každá kategorie jednou. */
    public function testRiskShiftsAndUniqueCategories(): void
    {
        $xml = static fn (string ...$risks): string => Sample::document(Sample::form(Sample::bezPriznaku([
            'rozpad' => Sample::rozpad(...$risks),
        ])));

        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($xml(Sample::risk('10.000', 2, '1'))), 386)->outcome);
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($this->validate($xml(Sample::risk('10.000', null, '1'))), 386)->outcome);
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($this->validate($xml(Sample::risk('10.000', 2, '2'))), 386)->outcome);

        $duplicate = $this->validate($xml(Sample::risk('10.000', null, '2'), Sample::risk('5.000', null, '2')));
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($duplicate, 387)->outcome);
        $distinct = $this->validate($xml(Sample::risk('10.000', null, '2'), Sample::risk('5.000', null, '3')));
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($distinct, 387)->outcome);
    }

    /** Kontrola 388: státní zástupce vyžaduje dny, hodiny, tarif a průměrný výdělek. */
    public function testStateAttorneyRequiresWorkAndWageDetails(): void
    {
        $missing = $this->finding($this->validate(Sample::document(Sample::form(Sample::cinnostKs(true, false)))), 388);
        self::assertSame(JmhzControlOutcome::Failed, $missing->outcome);
        self::assertSame(JmhzControlPassability::Blocking, $missing->passability);

        $complete = $this->finding($this->validate(Sample::document(Sample::form(Sample::cinnostKs(true, true)))), 388);
        self::assertSame(JmhzControlOutcome::Passed, $complete->outcome);
    }

    /**
     * Kontrola 388, věta „v ostatních případech nesmí být tyto atributy
     * vyplněny": platí jen ve formuláři cinnostKS s 10587 = NE. Běžný
     * formulář bez 10587 s tarifní mzdou kontrola neposuzuje.
     */
    public function testNonStateAttorneyInCinnostKsMustNotCarryWorkAndWageDetails(): void
    {
        $forbidden = $this->finding($this->validate(Sample::document(Sample::form(Sample::cinnostKs(false, true)))), 388);
        self::assertSame(JmhzControlOutcome::Failed, $forbidden->outcome);
        self::assertSame(JmhzControlPassability::Blocking, $forbidden->passability);
        self::assertStringContainsString('není státní zástupce', $forbidden->message);

        $empty = $this->finding($this->validate(Sample::document(Sample::form(Sample::cinnostKs(false, false)))), 388);
        self::assertSame(JmhzControlOutcome::Passed, $empty->outcome);

        // Běžný formulář nese dny v evidenčním stavu, hodiny a průměrný výdělek.
        $ordinary = $this->validate(Sample::document(Sample::form(Sample::bezPriznaku())));
        self::assertNotSame(JmhzControlOutcome::Failed, $this->finding($ordinary, 388)->outcome);

        self::assertArrayNotHasKey(388, $this->evaluator(JmhzSpecVersion::V2027)->documentedDeviations());
    }

    /**
     * Kontrola 82 (katalog 1.5.0.4): výplatní termín 10410 na každém
     * formuláři právě při 10408 = 8 (úpadek), u jiného kódu ani bez
     * skutečnosti nesmí být. Aplikace IN13 nevykazuje; kontrola je pojistka.
     */
    public function testPayoutDateOnlyForInsolvencyLegalFact(): void
    {
        $xml = static function (?string $fact, bool $payoutDate): string {
            $souhrn = Sample::souhrn(0);
            if ($fact !== null) {
                $souhrn = str_replace(
                    '</so:kolektivniSmlouvy></so:souhrn>',
                    "</so:kolektivniSmlouvy><so:specifickaSkutecnost><so:typ>{$fact}</so:typ>"
                        . '<so:datum>2027-04-20</so:datum></so:specifickaSkutecnost></so:souhrn>',
                    $souhrn,
                );
            }
            $dan = $payoutDate
                ? '<form:specifickaSkutecnost><form:vyplatniTermin>2027-04-15</form:vyplatniTermin></form:specifickaSkutecnost>'
                : '';

            return Sample::document(Sample::form(Sample::bezPriznaku(['dan' => $dan])), souhrn: $souhrn);
        };

        self::assertSame(JmhzControlOutcome::NotApplicable, $this->finding($this->validate($xml(null, false)), 82)->outcome);
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($xml('8', true)), 82)->outcome);

        $missing = $this->finding($this->validate($xml('8', false)), 82);
        self::assertSame(JmhzControlOutcome::Failed, $missing->outcome);
        self::assertSame(JmhzControlPassability::Blocking, $missing->passability);

        self::assertSame(JmhzControlOutcome::Failed, $this->finding($this->validate($xml('1', true)), 82)->outcome);
        self::assertSame(JmhzControlOutcome::Passed, $this->finding($this->validate($xml('1', false)), 82)->outcome);
        self::assertSame(JmhzControlOutcome::Failed, $this->finding($this->validate($xml(null, true)), 82)->outcome);
    }

    private function validate(string $xml, bool $schemaValid = true): JmhzControlEvaluationReport
    {
        if ($schemaValid) {
            $dom = new DOMDocument();
            self::assertTrue($dom->loadXML($xml));
            $previous = libxml_use_internal_errors(true);
            libxml_clear_errors();
            $valid = $dom->schemaValidate(self::XSD);
            $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            self::assertTrue($valid, 'Vzorek 2027 neprošel XSD 1.5.0.3: ' . implode('; ', $errors));
        }

        return self::validator()->validate($xml, new JmhzControlContext('2027-05-10', schemaValidated: $schemaValid));
    }

    private static function validator(): JmhzScenario1ControlValidator
    {
        return self::$validator ??= JmhzScenario1ControlValidator::create(
            CzechPayrollRulesets2026::provider(),
            null,
            JmhzSpecVersion::V2027,
        );
    }

    private function evaluator(JmhzSpecVersion $version): JmhzScenario1ControlEvaluator
    {
        return new JmhzScenario1ControlEvaluator(
            JmhzControlSourceCatalog::loadFor($version)->parameters(),
            new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
            null,
            null,
            $version,
        );
    }

    private function finding(JmhzControlEvaluationReport $report, int $controlId): JmhzControlFinding
    {
        foreach ($report->findings as $finding) {
            if ($finding->controlId === $controlId) {
                return $finding;
            }
        }

        self::fail("Kontrola {$controlId} v reportu chybí.");
    }
}
