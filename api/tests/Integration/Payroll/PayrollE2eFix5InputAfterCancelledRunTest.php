<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollInputCancellationException;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Service\Payroll\Component\PayrollInputPreviewService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Případ z podpory: schválený vstup na skončené dohodě uzamkl mzdový běh.
 * Aplikace radí „vstupy uzamčené během uvolníte zrušením běhu"; po zrušení
 * běhu tedy musí jít vstup zrušit (i zrušit schválený), jinak skončená
 * dohoda zůstane v kontrole měsíce natrvalo. Schválená revize běhu vstup
 * dál drží.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eFix5InputAfterCancelledRunTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD_START = '2026-06-01';
    private const PAYDAY = '2026-07-14';

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testApprovedInputOfCancelledRunCanBeCancelled(): void
    {
        $officeId = $this->createOffice();
        $componentId = $this->createComponent('PRIPLATEK_FIX5', 'bonus', 'one_off');
        $person = $this->createEmployment($officeId, 'Testovací Dohodář', 1, 'dpp', 'dpp', 10, 2_500);
        $this->createApprovedInput($person, $componentId, 48_600, 'fix5-cancel-after-run');
        $inputs = $this->container->get(PayrollInputRepository::class);
        self::assertInstanceOf(PayrollInputRepository::class, $inputs);
        $inputId = (int) $this->scalar(
            'SELECT id FROM payroll_inputs WHERE supplier_id = ? AND external_id = ?',
            [$this->supplierId, 'fix5-cancel-after-run'],
        );

        $run = $this->runs->createRun($this->supplierId, self::PERIOD_START, self::PAYDAY, $officeId, $this->actors[0]);
        $locked = $this->runs->lockInputs($this->supplierId, (int) $run['id'], (int) $run['row_version'], 'fix5-lock', $this->actors[0]);
        $calculated = $this->runs->calculate($this->supplierId, (int) $run['id'], (int) $locked->run['row_version'], 'fix5-calc', $this->actors[0]);
        self::assertSame('locked', $this->scalar('SELECT status FROM payroll_inputs WHERE supplier_id = ? AND id = ?', [$this->supplierId, $inputId]));

        $this->runs->cancel($this->supplierId, (int) $run['id'], (int) $calculated->run['row_version'], 'fix5-cancel', $this->actors[0], 'Vstup na skončené dohodě.');
        self::assertSame('approved', $this->scalar('SELECT status FROM payroll_inputs WHERE supplier_id = ? AND id = ?', [$this->supplierId, $inputId]));

        $version = (int) $this->scalar('SELECT row_version FROM payroll_inputs WHERE supplier_id = ? AND id = ?', [$this->supplierId, $inputId]);
        try {
            $cancelled = $inputs->cancelApproved($this->supplierId, $inputId, $version);
        } catch (PayrollInputCancellationException $e) {
            self::fail('Po zrušení běhu musí jít schválený vstup zrušit: ' . $e->getMessage());
        }
        self::assertIsArray($cancelled);
        self::assertSame('cancelled', $this->scalar('SELECT status FROM payroll_inputs WHERE supplier_id = ? AND id = ?', [$this->supplierId, $inputId]));
    }

    public function testInputFrozenInApprovedRunStaysProtected(): void
    {
        $officeId = $this->createOffice();
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $componentId = $this->createComponent('MZDA_FIX5', 'base_wage', 'regular');
        $person = $this->createEmployment($officeId, 'Testovací Zaměstnanec', 2, 'hpp', 'employment', 40, 10_000);
        $this->createApprovedTimeMonth($person['employment_id'], '2026-06');
        $this->createApprovedInput($person, $componentId, 4_000_000, 'fix5-approved-run');
        $inputId = (int) $this->scalar(
            'SELECT id FROM payroll_inputs WHERE supplier_id = ? AND external_id = ?',
            [$this->supplierId, 'fix5-approved-run'],
        );
        $result = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $officeId, 'fix5-approved');
        self::assertNotNull($result['approved'], json_encode($result['blockers'], JSON_UNESCAPED_UNICODE));

        $inputs = $this->container->get(PayrollInputRepository::class);
        self::assertInstanceOf(PayrollInputRepository::class, $inputs);
        $version = (int) $this->scalar('SELECT row_version FROM payroll_inputs WHERE supplier_id = ? AND id = ?', [$this->supplierId, $inputId]);
        try {
            $inputs->cancelApproved($this->supplierId, $inputId, $version);
            self::fail('Vstup ve schváleném běhu nesmí jít zrušit.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Vyžádat opravu', $e->getMessage());
        }
    }

    /**
     * Případ z podpory začal tím, že náhled dopadu vstupu na skončenou dohodu
     * hlásil „lze bezpečně zpracovat". Náhled musí varovat už při zadání;
     * s potvrzeným odloženým příjmem (doplatek po skončení) ne.
     */
    public function testPreviewWarnsWhenEmploymentIsNotValidInInputPeriod(): void
    {
        $officeId = $this->createOffice();
        $componentId = $this->createComponent('PRIPLATEK_FIX5P', 'bonus', 'one_off');
        $old = $this->createEmployment($officeId, 'Testovací Skončený', 3, 'dpp', 'dpp', 10, 2_500);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "ended", end_date = "2026-05-31" WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $old['employment_id']]);
        $future = $this->createEmployment($officeId, 'Testovací Budoucí', 4, 'dpp', 'dpp', 10, 2_500);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "planned", start_date = "2026-07-01", actual_start_date = NULL WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $future['employment_id']]);
        $current = $this->createEmployment($officeId, 'Testovací Trvající', 5, 'dpp', 'dpp', 10, 2_500);

        $preview = $this->container->get(PayrollInputPreviewService::class);
        self::assertInstanceOf(PayrollInputPreviewService::class, $preview);
        $input = static fn (array $person): array => [
            'employee_id' => $person['employee_id'],
            'employment_id' => $person['employment_id'],
            'component_id' => $componentId,
            'period_start' => self::PERIOD_START,
            'amount_minor' => 48_600,
        ];

        $ended = $preview->preview($this->supplierId, $input($old));
        self::assertSame('supported', $ended['support_status'], 'Uložení doplatku se nezakazuje, jen varuje.');
        self::assertSame('employment_ended_before_period', $ended['employment_warning']['code'] ?? null);
        self::assertStringContainsString('skončil 31. 5. 2026', $ended['employment_warning']['message']);
        self::assertStringContainsString('odložený příjem', $ended['employment_warning']['message']);

        $notStarted = $preview->preview($this->supplierId, $input($future));
        self::assertSame('employment_starts_after_period', $notStarted['employment_warning']['code'] ?? null);

        self::assertNull($preview->preview($this->supplierId, $input($current))['employment_warning']);

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_deferred_incomes (supplier_id, employment_id, period_start, deferred_type, note)
             VALUES (?, ?, ?, "1", "Doplatek po skončení")',
        )->execute([$this->supplierId, $old['employment_id'], self::PERIOD_START]);
        self::assertNull($preview->preview($this->supplierId, $input($old))['employment_warning']);
    }
}
