<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Import\Agreement\AgreementImportColumns;
use MyInvoice\Service\Payroll\Import\Agreement\AgreementImportService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1BatchService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Agentura s DPP: dohody na prosinec se naimportují i s údaji přihlášky A1
 * a hromadná příprava přihlášek je připraví bez ručního vyplňování karet
 * (XML projde XSD REGZEC). Nic se neodesílá.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eDppAgreementImportA1Test extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private const TODAY = '2026-11-25';

    protected function setUp(): void
    {
        $this->bootRegistrationFlow(self::TODAY);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testImportedAgreementsArePreparedAsA1WithoutManualCompletion(): void
    {
        $headers = [
            'A' => 'Příjmení', 'B' => 'Jméno', 'C' => 'Rodné číslo', 'D' => 'Druh vztahu', 'E' => 'Nástup',
            'F' => 'Konec dohody', 'G' => 'Zdravotní pojišťovna', 'H' => 'Ulice', 'I' => 'Číslo domu', 'J' => 'Obec',
            'K' => 'PSČ', 'L' => 'Státní občanství', 'M' => 'CZ-ISCO', 'N' => 'Místo výkonu práce', 'O' => 'Rodné příjmení',
            'P' => 'Místo narození', 'Q' => 'Stát narození', 'R' => 'Režim práce', 'S' => 'Nepřetržitý provoz',
            'T' => 'Název pozice', 'U' => 'Vedoucí pozice', 'V' => 'Prohlášení poplatníka',
        ];
        $rows = [1 => $headers];
        foreach ([['Brigádnice', 'Eva', 'female', 41], ['Brigádník', 'Jan', 'male', 43]] as $index => [$last, $first, $sex, $sequence]) {
            $rows[$index + 2] = [
                'A' => $last, 'B' => $first, 'C' => self::syntheticBirthNumber('2001-03-15', $sex, $sequence),
                'D' => 'DPP', 'E' => '1. 12. 2026', 'F' => '31. 12. 2026', 'G' => '111', 'H' => 'Dlouhá',
                'I' => '12', 'J' => 'Praha', 'K' => '110 00', 'L' => 'CZ', 'M' => '52231', 'N' => 'Praha',
                'O' => $last, 'P' => 'Brno', 'Q' => 'CZ', 'R' => '1', 'S' => 'ne', 'T' => 'Promotér', 'U' => 'ne', 'V' => 'ano',
            ];
        }
        $file = AttendanceFixture::file('dohody.xlsx', AttendanceFixture::xlsx([AgreementImportColumns::SHEET => ['rows' => $rows]]));
        $imports = $this->container->get(AgreementImportService::class);
        self::assertInstanceOf(AgreementImportService::class, $imports);
        $applied = $imports->apply($this->supplierId, [$file], [], null, $this->actors[0], null, null);
        self::assertSame(2, $applied['counts']['created'], CanonicalJson::encode($applied));

        $batch = $this->container->get(PayrollRegistrationA1BatchService::class);
        self::assertInstanceOf(PayrollRegistrationA1BatchService::class, $batch);
        $offered = array_column($batch->candidates($this->supplierId, 'test', '2026-12-01', '2026-12-31')['items'], null, 'employment_id');
        $ids = array_map(static fn (array $result): int => (int) $result['employment_id'], $applied['results']);
        foreach ($ids as $id) {
            self::assertArrayHasKey($id, $offered, CanonicalJson::encode($offered));
            self::assertTrue($offered[$id]['complete'], CanonicalJson::encode($offered[$id]['problems']));
        }
        $this->regToday('2026-11-27');
        $results = $batch->prepare($this->supplierId, 'test', $ids, $this->actors[0])['results'];
        foreach ($results as $result) {
            self::assertSame('prepared', $result['status'], CanonicalJson::encode($result));
            $xml = $this->regArtifactXml((int) $result['submission_id']);
            $this->regAssertXsdValid($xml);
            self::assertMatchesRegularExpression('/<job [^>]*rel="T"/', $xml);
        }
    }
}
