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

    /**
     * B2-01: první běh měsíce spočítaný, zrušený, „Začít z opravených vstupů"
     * a znovu zrušený. Žádná revize nebyla schválená, takže vstup nedrží ani
     * opuštěná revize, ani poslední spočítaná revize zrušeného běhu. Zrušit
     * i opravit (tutéž cestu bere rychlý vstup) jde jedním pravidlem.
     */
    public function testInputOfRunCancelledAgainAfterRestartCanBeCancelledAndCorrected(): void
    {
        $officeId = $this->createOffice();
        $componentId = $this->createComponent('PRIPLATEK_FIX5B', 'premium', 'one_off');
        $person = $this->createEmployment($officeId, 'Testovací Hodinář', 6, 'dpp', 'dpp', 10, 2_500);
        $this->createApprovedInput($person, $componentId, 10_000, 'fix5-b2-cancel');
        $this->createApprovedInput($person, $componentId, 20_000, 'fix5-b2-correct');
        $inputs = $this->container->get(PayrollInputRepository::class);
        self::assertInstanceOf(PayrollInputRepository::class, $inputs);
        $ids = [];
        foreach (['fix5-b2-cancel', 'fix5-b2-correct'] as $key) {
            $ids[$key] = (int) $this->scalar('SELECT id FROM payroll_inputs WHERE supplier_id = ? AND external_id = ?', [$this->supplierId, $key]);
        }

        $run = $this->runs->createRun($this->supplierId, self::PERIOD_START, self::PAYDAY, $officeId, $this->actors[0]);
        $locked = $this->runs->lockInputs($this->supplierId, (int) $run['id'], (int) $run['row_version'], 'fix5-b2-lock', $this->actors[0]);
        $calculated = $this->runs->calculate($this->supplierId, (int) $run['id'], (int) $locked->run['row_version'], 'fix5-b2-calc', $this->actors[0]);
        $cancelled = $this->runs->cancel($this->supplierId, (int) $run['id'], (int) $calculated->run['row_version'], 'fix5-b2-cancel1', $this->actors[0], 'Kolize ručního příplatku.');
        $restarted = $this->runs->reopen($this->supplierId, (int) $run['id'], (int) $cancelled->run['row_version'], 'fix5-b2-reopen', $this->actors[0], 'Začít z opravených vstupů.');
        $recalculated = $this->runs->calculate($this->supplierId, (int) $run['id'], (int) $restarted->run['row_version'], 'fix5-b2-calc2', $this->actors[0]);
        self::assertSame('locked', $this->scalar('SELECT status FROM payroll_inputs WHERE supplier_id = ? AND id = ?', [$this->supplierId, $ids['fix5-b2-cancel']]));
        $this->runs->cancel($this->supplierId, (int) $run['id'], (int) $recalculated->run['row_version'], 'fix5-b2-cancel2', $this->actors[0], 'Kolize ručního příplatku.');

        $row = fn (int $id): array => $this->db->pdo()->query(
            'SELECT * FROM payroll_inputs WHERE supplier_id = ' . $this->supplierId . ' AND id = ' . $id,
        )->fetch(\PDO::FETCH_ASSOC);
        $toCancel = $row($ids['fix5-b2-cancel']);
        self::assertSame('approved', $toCancel['status']);
        $inputs->cancelApproved($this->supplierId, $ids['fix5-b2-cancel'], (int) $toCancel['row_version']);
        self::assertSame('cancelled', $row($ids['fix5-b2-cancel'])['status']);

        $toCorrect = $row($ids['fix5-b2-correct']);
        $inputs->updateApproved($this->supplierId, $ids['fix5-b2-correct'], [
            'employee_id' => (int) $toCorrect['employee_id'],
            'employment_id' => (int) $toCorrect['employment_id'],
            'component_id' => (int) $toCorrect['component_id'],
            'period_start' => (string) $toCorrect['period_start'],
            'source_period_start' => null,
            'amount_minor' => 0,
            'quantity_milliunits' => null,
            'source_kind' => 'manual',
            'external_id' => 'fix5-b2-correct',
            'source_snapshot_json' => null,
            'source_snapshot_hash' => null,
        ], (int) $toCorrect['row_version'], $this->actors[0]);
        self::assertSame(0, (int) $row($ids['fix5-b2-correct'])['amount_minor']);
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
