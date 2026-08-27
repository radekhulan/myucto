<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Blocker;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1NormalizedDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlDryRunService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario2NormalizedDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario2Resolution;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecialScenarioNormalizedDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecialScenarioResolution;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionGuidFactory;
use PHPUnit\Framework\TestCase;

/**
 * Nácvik podání skládá tři nezávislé odpovědi: jde dokument vůbec postavit,
 * projde XSD, a projde katalogem kontrol. Test hlídá právě to skládání —
 * jednotlivé vrstvy mají vlastní testy.
 */
final class JmhzScenario1XmlDryRunServiceTest extends TestCase
{
    public function testBlockedDocumentNeverReachesSerialization(): void
    {
        $validator = $this->createMock(JmhzScenario1XmlValidator::class);
        $validator->expects(self::never())->method('dryRun');

        $result = $this->service(
            new JmhzScenario1Resolution(null, [
                new JmhzScenario1Blocker(
                    'jmhz_taxpayer_declaration_unresolved',
                    'person',
                    11,
                    ['10419'],
                ),
            ]),
            $validator,
        )->dryRun(1, 'test', 77);

        self::assertSame('blocked', $result['status']);
        self::assertSame('jmhz_taxpayer_declaration_unresolved', $result['blockers'][0]['code']);
        self::assertArrayNotHasKey('xml', $result);
        self::assertArrayNotHasKey('controls', $result);
    }

    public function testSpecialScopeReturnsScenarioTwoCandidateAndExactBlocker(): void
    {
        $candidate = new JmhzScenario2NormalizedDocument([
            'schema_reference' => JmhzScenario2NormalizedDocument::SCHEMA_REFERENCE,
            'forms' => [['employee_id' => 11, 'employment_id' => 101]],
        ]);
        $result = $this->service(
            new JmhzScenario1Resolution(null, [
                new JmhzScenario1Blocker(
                    'jmhz_scenario1_scope_unsupported',
                    'preparation',
                    77,
                ),
            ]),
            $this->createStub(JmhzScenario1XmlValidator::class),
            new JmhzScenario2Resolution($candidate, [
                new JmhzScenario1Blocker(
                    'jmhz_scenario2_evidence_gap',
                    'employment',
                    101,
                    ['10051'],
                ),
            ]),
        )->dryRun(1, 'test', 77);

        self::assertSame('blocked', $result['scenario_2']['status']);
        self::assertSame($candidate->payload, $result['scenario_2']['candidate']);
        self::assertSame($candidate->sha256(), $result['scenario_2']['candidate_sha256']);
        self::assertSame(
            'jmhz_scenario2_evidence_gap',
            $result['scenario_2']['blockers'][0]['code'],
        );
    }

    public function testSpecialScopeReturnsGenericSpecialScenarioCandidateWithoutXml(): void
    {
        $candidate = new JmhzSpecialScenarioNormalizedDocument([
            'schema_reference' => JmhzSpecialScenarioNormalizedDocument::SCHEMA_REFERENCE,
            'forms' => [['employee_id' => 11, 'employment_id' => 101]],
        ]);
        $result = $this->service(
            new JmhzScenario1Resolution(null, [
                new JmhzScenario1Blocker('jmhz_scenario1_scope_unsupported', 'preparation', 77),
            ]),
            $this->createStub(JmhzScenario1XmlValidator::class),
            new JmhzScenario2Resolution(null, [
                new JmhzScenario1Blocker('jmhz_scenario2_scope_unsupported', 'preparation', 77),
            ]),
            new JmhzSpecialScenarioResolution($candidate, [
                new JmhzScenario1Blocker(
                    'jmhz_special_scenarios_evidence_gap',
                    'employment',
                    101,
                    ['10051'],
                ),
            ]),
        )->dryRun(1, 'test', 77);

        self::assertSame('blocked', $result['special_scenarios']['status']);
        self::assertSame($candidate->payload, $result['special_scenarios']['candidate']);
        self::assertSame($candidate->sha256(), $result['special_scenarios']['candidate_sha256']);
        self::assertSame(
            'jmhz_special_scenarios_evidence_gap',
            $result['special_scenarios']['blockers'][0]['code'],
        );
        self::assertArrayNotHasKey('xml', $result);
    }

    /**
     * Nejdůležitější větev celé vrstvy: XML může projít schématem a přesto
     * nebýt připravené k odeslání. Kdyby se stav odvozoval jen od XSD, uživatel
     * by viděl zelenou u podání, které katalog kontrol neprošel.
     */
    public function testSchemaValidXmlWithFailedControlIsReportedAsIncomplete(): void
    {
        $result = $this->service(
            $this->resolvedResolution(),
            $this->validatorReturning(str_replace(
                '<pvpoj:pojistneZamestnavateleA>248</pvpoj:pojistneZamestnavateleA>',
                '<pvpoj:pojistneZamestnavateleA>247</pvpoj:pojistneZamestnavateleA>',
                JmhzXmlSample::minimal(),
            )),
        )->dryRun(1, 'test', 77);

        self::assertSame('dry_run_incomplete', $result['status']);
        self::assertFalse($result['controls']['submittable']);
        self::assertNotSame([], $result['controls']['blocking']);
    }

    public function testCleanSubmissionIsReportedAsValid(): void
    {
        $result = $this->service(
            $this->resolvedResolution(),
            $this->validatorReturning(JmhzXmlSample::minimal()),
        )->dryRun(1, 'test', 77);

        self::assertSame('dry_run_valid', $result['status']);
        self::assertTrue($result['controls']['submittable']);
        self::assertSame([], $result['controls']['coverage_gaps']);
    }

    /**
     * Kontroly 61 a 62 smí projít jen tehdy, když validace proti XSD opravdu
     * proběhla. Nácvik ji provádí, takže si to musí nést jako doložený příznak.
     */
    public function testDryRunProvesSchemaValidationToTheControlLayer(): void
    {
        $result = $this->service(
            $this->resolvedResolution(),
            $this->validatorReturning(JmhzXmlSample::minimal()),
        )->dryRun(1, 'test', 77);

        $schemaControls = array_values(array_filter(
            $result['controls']['evaluated'],
            static fn (array $finding): bool => in_array($finding['control_id'], [61, 62], true),
        ));

        self::assertCount(2, $schemaControls);
        foreach ($schemaControls as $finding) {
            self::assertSame('passed', $finding['outcome']);
        }
    }

    public function testSubmissionWindowIsReportedAlongsideTheResult(): void
    {
        $result = $this->service(
            $this->resolvedResolution(),
            $this->validatorReturning(JmhzXmlSample::minimal()),
        )->dryRun(1, 'test', 77);

        self::assertSame('2026-07-01', $result['deadline']['period_start']);
        self::assertSame('2026-08-01', $result['deadline']['earliest_submission_on']);
        self::assertSame('2026-08-20', $result['deadline']['due_on']);
    }

    public function testOfficialSubmissionStaysUnsupported(): void
    {
        $result = $this->service(
            $this->resolvedResolution(),
            $this->validatorReturning(JmhzXmlSample::minimal()),
        )->dryRun(1, 'test', 77);

        self::assertFalse($result['official_submission']['supported']);
        self::assertSame('preview_only', $result['guids']['scope']);
    }

    private function resolvedResolution(): JmhzScenario1Resolution
    {
        return new JmhzScenario1Resolution(
            new JmhzScenario1NormalizedDocument([
                'scope' => ['period_start' => '2026-07-01'],
                'people' => [['employments' => [['employment_id' => 5]]]],
            ]),
            [],
        );
    }

    private function validatorReturning(string $xml): JmhzScenario1XmlValidator
    {
        $validator = $this->createStub(JmhzScenario1XmlValidator::class);
        $validator->method('dryRun')->willReturn([
            'xml' => $xml,
            'sha256' => hash('sha256', $xml),
            'schema' => [
                'package_key' => 'jmhz-1.4.3.4',
                'data_version' => '1.4.3',
                'bundle_sha256' => str_repeat('c', 64),
                'document_sha256' => str_repeat('d', 64),
            ],
        ]);

        return $validator;
    }

    private function service(
        JmhzScenario1Resolution $resolution,
        JmhzScenario1XmlValidator $validator,
        ?JmhzScenario2Resolution $scenario2Resolution = null,
        ?JmhzSpecialScenarioResolution $specialScenarioResolution = null,
    ): JmhzScenario1XmlDryRunService {
        $documents = $this->createStub(JmhzScenario1DocumentService::class);
        $documents->method('resolve')->willReturn($resolution);
        if ($scenario2Resolution !== null) {
            $documents->method('resolveScenario2')->willReturn($scenario2Resolution);
        }
        if ($specialScenarioResolution !== null) {
            $documents->method('resolveSpecialScenarios')->willReturn($specialScenarioResolution);
        }

        return new JmhzScenario1XmlDryRunService(
            $documents,
            $validator,
            new JmhzSubmissionGuidFactory(),
            JmhzScenario1ControlValidator::create(),
        );
    }
}
