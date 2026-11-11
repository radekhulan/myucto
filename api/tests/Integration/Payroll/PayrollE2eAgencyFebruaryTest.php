<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Agentura s brigádníky dokončuje únor 2026 (nálezy průchodu nad kopií dat).
 *
 * Tvar dat odpovídá tomu, co chybu vyvolalo: dohody s nástupem uprostřed
 * února, které skončily 28. 2., pracovní poměr s nástupem 20. 2. bez
 * pracovního kalendáře. Data jsou syntetická.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eAgencyFebruaryTest extends TestCase
{
    use PayrollFullFlowTrait {
        createOpeningBalances as private traitCreateOpeningBalances;
    }

    private const PERIOD = '2026-02';
    private const PERIOD_START = '2026-02-01';
    private const PAYDAY = '2026-03-13';

    private int $officeId;
    private int $componentId;
    /** @var array<int,true> pořadová čísla osob založených bez počátečních úhrnů */
    private array $withoutOpenings = [];

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('AGF', 'Syntetická účtárna agentury', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $this->componentId = $this->createComponent('ODMENA_DPP_AGF', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->componentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Brigádník s dohodou 7. až 28. 2. bez počátečních úhrnů roku: nulu doplní
     * založení běhu samo (nástup v období, před ním u firmy nic). Kontrola před
     * během ho proto nesmí hlásit jako blokaci, kterou po výpočtu nikdo nevidí.
     */
    public function testPreflightDoesNotDemandOpeningBalancesThatRunCreationSeeds(): void
    {
        $this->withoutOpenings = [1 => true, 2 => true];
        $seeded = $this->hireDpp(1, '2026-02-07', '2026-02-28');
        $manual = $this->hireDpp(2, '2026-01-01', null);
        // Firma vede mzdy v aplikaci od ledna, ale leden ještě nespočítala:
        // u lednového nástupu nula prokazatelná není a úhrny zůstávají na účetní.
        $openings = $this->container->get(PayrollOpeningBalanceService::class);
        self::assertInstanceOf(PayrollOpeningBalanceService::class, $openings);
        self::assertSame([$seeded['employee_id']], $openings->provableZeroOpeningEmployeeIds(
            $this->supplierId,
            self::PERIOD_START,
            [$seeded['employee_id'], $manual['employee_id']],
        ));

        $readiness = $this->container->get(PayrollRunReadinessService::class);
        self::assertInstanceOf(PayrollRunReadinessService::class, $readiness);
        $findings = $readiness->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY, $this->officeId)['findings'];
        $statutory = self::finding($findings, 'statutory_calculation_manual_review');
        self::assertNotNull($statutory, 'Ruční úhrny lednového nástupu musí kontrola dál hlásit.');
        $messages = array_column($statutory['entities'], 'message', 'entity_id');
        self::assertArrayHasKey($manual['employee_id'], $messages);
        self::assertStringContainsString('počáteční roční úhrny', $messages[$manual['employee_id']]);
        self::assertStringContainsString('posledního vztahu', $messages[$manual['employee_id']]);
        self::assertStringNotContainsString(
            'počáteční roční úhrny',
            (string) ($messages[$seeded['employee_id']] ?? ''),
            'Úhrny, které doplní založení běhu, nejsou práce pro účetní.',
        );
        foreach ($statutory['entities'] as $entity) {
            if ((int) $entity['entity_id'] === $manual['employee_id']) {
                self::assertStringContainsString('panel=opening_balances', (string) $entity['remediation_path']);
            }
        }

        // Jedno pravidlo: založení běhu doplní právě tuto osobu.
        self::assertSame([$seeded['employee_id']], $openings->seedProvableZeroOpenings($this->supplierId, self::PERIOD_START, [], $this->actors[0]));
    }

    /**
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function hireDpp(int $seq, string $start, ?string $end): array
    {
        $person = $this->createEmployment($this->officeId, sprintf('Syntetický Brigádník %02d', $seq), $seq, 'dpp', 'dpp', 10, 2_500, true, self::PERIOD_START);
        $this->moveStart($person['employment_id'], $start, $end);

        return $person;
    }

    private function createOpeningBalances(int $employeeId, int $sequence): void
    {
        if (!isset($this->withoutOpenings[$sequence])) {
            $this->traitCreateOpeningBalances($employeeId, $sequence);
        }
    }

    private function moveStart(int $employmentId, string $start, ?string $end): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employments SET start_date = ?, actual_start_date = ?, end_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$start, $start, $end, $this->supplierId, $employmentId]);
        $pdo->prepare('UPDATE payroll_employment_terms SET effective_from = ?, planned_start_on = ?, actual_start_on = ?, fixed_term_end_on = ? WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$start, $start, $start, $end, $this->supplierId, $employmentId]);
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>|null
     */
    private static function finding(array $findings, string $code): ?array
    {
        foreach ($findings as $finding) {
            if ($finding['code'] === $code) {
                return $finding;
            }
        }

        return null;
    }
}
