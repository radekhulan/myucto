<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use DI\Container;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlEvaluationReport;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Tests\Support\JmhzControlValidatorFactory;
use MyInvoice\Tests\Unit\Payroll\Submission\JmhzXmlSample;
use MyInvoice\Tests\Unit\Payroll\Submission\JmhzXmlSample2027;
use PHPUnit\Framework\TestCase;

final class JmhzControlValidatorFactoryTest extends TestCase
{
    public function testNewContainersRespectTheirOwnRulesetsAfterCatalogCacheWasWarmed(): void
    {
        $defaults = CzechPayrollRulesets2026::provider();
        $withoutDeadlines = new PayrollRulesetProvider(array_values(array_filter(
            $defaults->versions(),
            static fn ($version): bool => $version->domain !== PayrollRulesetDomain::Deadlines,
        )));
        $defaultValidator = $this->validator($defaults);
        $changedValidator = $this->validator($withoutDeadlines);
        self::assertNotSame($defaultValidator, $changedValidator);
        $evaluator = new \ReflectionProperty(JmhzScenario1ControlValidator::class, 'evaluator');
        self::assertNotSame($evaluator->getValue($defaultValidator), $evaluator->getValue($changedValidator));
        $xml = JmhzXmlSample::minimal();
        $context = new JmhzControlContext('2026-08-14');
        self::assertSame(JmhzControlOutcome::Passed, $this->control31($defaultValidator->validate($xml, $context)));
        self::assertSame(JmhzControlOutcome::Failed, $this->control31($changedValidator->validate($xml, $context)));
        self::assertSame(JmhzControlOutcome::Passed, $this->control31($this->validator($defaults)->validate($xml, $context)));
    }

    public function testCatalogCacheKeepsSpecificationVersionsSeparate(): void
    {
        $xml = JmhzXmlSample2027::document(JmhzXmlSample2027::form(JmhzXmlSample2027::bezPriznaku()));
        $report = JmhzControlValidatorFactory::create(version: JmhzSpecVersion::V2027)
            ->validate($xml, new JmhzControlContext('2027-05-10'));
        self::assertSame(JmhzControlSourceCatalog::CATALOG_KEY_2027, $report->catalogKey);
        self::assertSame(JmhzControlSourceCatalog::MANIFEST_SHA256_2027, $report->catalogManifestSha256);
        $report = JmhzControlValidatorFactory::create()->validate(JmhzXmlSample::minimal(), new JmhzControlContext('2026-08-14'));
        self::assertSame(JmhzControlSourceCatalog::CATALOG_KEY, $report->catalogKey);
    }

    private function validator(PayrollRulesetProvider $rulesets): JmhzScenario1ControlValidator
    {
        $container = new Container([PayrollRulesetProvider::class => $rulesets]);
        JmhzControlValidatorFactory::configure($container);
        return $container->get(JmhzScenario1ControlValidator::class);
    }

    private function control31(JmhzControlEvaluationReport $report): JmhzControlOutcome
    {
        foreach ($report->findings as $finding) {
            if ($finding->controlId === 31) return $finding->outcome;
        }
        self::fail('Kontrola období 31 chybí.');
    }
}
