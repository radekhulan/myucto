<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola před během a běh nad neúplnými podklady HPP za duben 2026.
 *
 * Každá díra v podkladech (chybějící zákonná evidence, pojišťovna, průměr,
 * pracoviště, identita, podmínky vztahu, složka bez zařazení do JMHZ, vstup
 * v haléřích) musí skončit nálezem s textem a odkazem, ne obecným
 * „kontrolu se nepodařilo dokončit“ nebo výjimkou, ze které je 500.
 * Odkazy na stránky vázané na měsíc nesou období.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHppReadinessTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-14';

    /** Stránky, jejichž obsah je vázaný na měsíc: odkaz musí nést období. */
    private const PERIOD_BOUND_PATHS = ['/payroll/time', '/payroll/runs', '/payroll/submissions'];

    /** Výjimky, které akce běhu převádí na 4xx s hláškou; cokoli jiného je 500. */
    private const HANDLED = [\InvalidArgumentException::class, \DomainException::class, \OutOfBoundsException::class];

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('PRE', 'Syntetická účtárna kontroly', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_PRE', 'base_wage', 'regular');
        $this->mappings()->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /** @return iterable<string,array{string}> */
    public static function gaps(): iterable
    {
        yield 'bez zákonné evidence' => ['no_statutory_evidence'];
        yield 'bez pojišťovny' => ['no_health_insurer'];
        yield 'dovolená bez průměru' => ['vacation_without_average'];
        yield 'bez pracoviště a činnosti JMHZ' => ['no_jmhz_employment_data'];
        yield 'bez identity osoby' => ['no_identity'];
        yield 'bez podmínek vztahu' => ['no_employment_terms'];
        yield 'provize bez zařazení JMHZ' => ['component_without_mapping'];
        yield 'vstup v haléřích' => ['input_with_halere'];
        yield 'bez docházky' => ['no_time_month'];
    }

    #[DataProvider('gaps')]
    public function testGapEndsAsFindingWithLinkNotAsFailure(string $gap): void
    {
        $person = $this->personWithGap($gap);

        $readiness = $this->readiness()->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY);

        $codes = array_column($readiness['findings'], 'code');
        self::assertNotContains('readiness_check_failed', $codes, CanonicalJson::encode($readiness['findings']));
        self::assertNotContains('jmhz_readiness_check_failed', $codes, CanonicalJson::encode($readiness['findings']));
        foreach ($readiness['findings'] as $finding) {
            $this->assertActionable($finding, "nález {$finding['code']} ({$gap})");
            foreach ($finding['entities'] ?? [] as $entity) {
                if (($entity['remediation_path'] ?? null) !== null) {
                    $this->assertPeriodLink((string) $entity['remediation_path'], "záznam nálezu {$finding['code']}");
                }
            }
        }

        $runBlockers = $this->runThroughCalculation($gap);
        // Co výpočet zastaví, musí kontrola předem ukázat jako blokaci
        // (nejde schválit), ne jako informaci.
        if ($runBlockers !== []) {
            $predicted = array_filter(
                $readiness['findings'],
                static fn (array $finding): bool => $finding['severity'] === 'blocker',
            );
            self::assertNotSame(
                [],
                $predicted,
                sprintf('Kontrola před během (%s) nepředpověděla blokaci, výpočet zastavil: %s. %s', $gap, implode(', ', $runBlockers), CanonicalJson::encode($readiness['findings'])),
            );
        }
        self::assertGreaterThan(0, $person['employment_id']);
    }

    /**
     * Chybějící pojišťovna: výpočet osobu vyřadí, kontrola ji musí jmenovat
     * jako blokaci schválení s odkazem do zákonné evidence osoby
     * a s hromadným přiřazením pojišťovny.
     */
    public function testMissingHealthInsurerIsPredictedAsApprovalBlocker(): void
    {
        $person = $this->personWithGap('no_health_insurer');

        $readiness = $this->readiness()->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY);

        $finding = null;
        foreach ($readiness['findings'] as $candidate) {
            if ($candidate['code'] === 'statutory_calculation_manual_review') {
                $finding = $candidate;
            }
        }
        self::assertNotNull($finding, CanonicalJson::encode($readiness['findings']));
        self::assertSame('blocker', $finding['severity']);
        self::assertSame('approval', $finding['impact']);
        self::assertSame([$person['employee_id']], array_column($finding['entities'], 'entity_id'));
        self::assertStringStartsWith(
            "/payroll/people?person={$person['employee_id']}",
            (string) $finding['entities'][0]['remediation_path'],
        );
        self::assertStringContainsString('panel=statutory_evidence', (string) $finding['entities'][0]['remediation_path']);
        self::assertSame([['kind' => 'health_insurer', 'count' => 1]], $finding['bulk_actions']);
    }

    /**
     * Provize bez výchozího zařazení: kontrola navede k mapování na detail
     * složky (10330/10331 rozhodne účetní) s odkazem na panel JMHZ.
     */
    public function testCommissionWithoutMappingLinksToComponentJmhzPanel(): void
    {
        $this->personWithGap('component_without_mapping');
        $commissionId = $this->componentId('PROVIZE_PRE');

        $readiness = $this->readiness()->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY);

        $paths = [];
        foreach ($readiness['findings'] as $finding) {
            $paths[] = (string) $finding['remediation_path'];
            foreach ($finding['entities'] ?? [] as $entity) {
                $paths[] = (string) ($entity['remediation_path'] ?? '');
            }
        }
        self::assertContains(
            "/payroll/components?tab=catalog&component={$commissionId}&panel=jmhz",
            $paths,
            CanonicalJson::encode($readiness['findings']),
        );
    }

    /**
     * Vztah bez místa výkonu práce a druhu činnosti: příprava hlášení ho
     * zastaví, kontrola před během to musí ohlásit dřív (s hromadným
     * doplněním pracoviště), ne až po schválení mzdy.
     */
    public function testMissingWorkplaceAndActivityArePredictedBeforeTheRun(): void
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment($this->officeId, 'Syntetický Bezmístný', $sequence, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $this->createApprovedAverage($person['employment_id'], 2);
        $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::workdays(self::PERIOD));
        $this->createApprovedInput($person, $this->baseComponentId, 4_000_000, 'base-' . $person['employment_id'], self::PERIOD_START);

        $readiness = $this->readiness()->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY);
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'no-workplace');
        self::assertNotNull($run['approved'], CanonicalJson::encode($run['blockers']));
        $preparation = $this->prepareJmhz((int) $run['approved']->revision['id'], 'no-workplace');

        self::assertSame('blocked', $preparation['body']['readiness_status']);
        $predicted = array_column($readiness['findings'], null, 'code');
        foreach (['jmhz_workplace_codebooks_unverified', 'jmhz_scenario_activity_code_missing'] as $code) {
            self::assertContains($code, array_column($preparation['body']['issues'], 'code'));
            self::assertArrayHasKey($code, $predicted, 'Příprava hlášení zastaví ' . $code . ', kontrola před během mlčela. ' . CanonicalJson::encode($readiness['findings']));
            $this->assertActionable($predicted[$code], $code);
        }
        self::assertContains(
            ['kind' => 'workplace', 'count' => 1],
            $predicted['jmhz_workplace_codebooks_unverified']['bulk_actions'],
        );
        self::assertSame(
            [$person['employment_id']],
            array_column($predicted['jmhz_workplace_codebooks_unverified']['entities'], 'entity_id'),
        );
    }

    /**
     * Před 1. 4. 2026 se měsíční hlášení JMHZ nepodává
     * (PayrollStatutoryAgendaCatalog::JMHZ_FROM): kontrola únorového běhu
     * nesmí účetní posílat doplňovat podklady hlášení, které neexistuje.
     */
    public function testNoJmhzFindingsBeforeJmhzStart(): void
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment($this->officeId, 'Syntetický Únorový', $sequence, 'hpp', 'employment', 40, 10_000, true, '2026-02-01');
        $this->publishShifts($person['employment_id'], self::workdays('2026-02'));
        $this->approveTimeMonth($person['employment_id'], '2026-02', self::workdays('2026-02'));
        $this->createApprovedInput($person, $this->baseComponentId, 4_000_000, 'feb-' . $person['employment_id'], '2026-02-01');

        $readiness = $this->readiness()->inspect($this->supplierId, '2026-02-01', '2026-03-13');

        $jmhz = array_values(array_filter(
            array_column($readiness['findings'], 'code'),
            static fn (string $code): bool => str_starts_with($code, 'jmhz_') || str_starts_with($code, 'component_jmhz_'),
        ));
        self::assertSame([], $jmhz, CanonicalJson::encode($readiness['findings']));
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function personWithGap(string $gap): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment($this->officeId, "Syntetický Mezerový{$sequence}", $sequence, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        if ($gap !== 'no_jmhz_employment_data') {
            $this->completeJmhzEmployment($person, identity: $gap === 'no_identity' ? null : [
                'first_name' => 'Syntetický',
                'last_name' => "Mezerový{$sequence}",
                'birth_date' => '1984-04-04',
                'sex' => 'male',
                'birth_number' => self::syntheticBirthNumber('1984-04-04', 'male', $sequence),
            ], withIdentity: $gap !== 'no_identity');
            if ($gap !== 'no_identity') {
                $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
            }
        }
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $pdo = $this->db->pdo();
        $amount = 4_000_000;
        switch ($gap) {
            case 'no_statutory_evidence':
                foreach (['payroll_person_tax_declarations', 'payroll_person_tax_residences', 'payroll_person_social_jurisdictions', 'payroll_person_social_discount_claims', 'payroll_person_health_coverage_history', 'payroll_person_health_month_evidence'] as $table) {
                    $pdo->prepare("DELETE FROM {$table} WHERE supplier_id = ? AND employee_id = ?")->execute([$this->supplierId, $person['employee_id']]);
                }
                break;
            case 'no_health_insurer':
                $pdo->prepare('DELETE FROM payroll_person_health_coverage_history WHERE supplier_id = ? AND employee_id = ?')->execute([$this->supplierId, $person['employee_id']]);
                break;
            case 'vacation_without_average':
                // Schválení bez průměru absence odmítne s návodem (422); zůstane
                // podaná žádost, kterou musí najít kontrola před během.
                $requested = $this->requestAbsence($person['employment_id'], 'vacation', '2026-04-20', '2026-04-21');
                self::assertSame(201, $requested->getStatusCode(), (string) $requested->getBody());
                break;
            case 'no_employment_terms':
                $pdo->prepare('DELETE FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplierId, $person['employment_id']]);
                break;
            case 'component_without_mapping':
                $commission = $this->createComponent('PROVIZE_PRE', 'bonus', 'one_off');
                $this->createApprovedInput($person, $commission, 500_000, 'commission-' . $person['employment_id'], self::PERIOD_START);
                break;
            case 'input_with_halere':
                $amount = 4_000_050;
                break;
        }
        if ($gap !== 'no_time_month' && $gap !== 'no_employment_terms') {
            $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::workdays(self::PERIOD));
        }
        $this->createApprovedInput($person, $this->baseComponentId, $amount, 'base-' . $person['employment_id'], self::PERIOD_START);

        return $person;
    }

    /**
     * Zahájení, zamknutí a výpočet: dovolené jsou jen výjimky, ze kterých akce
     * udělá 4xx s hláškou; blokace výpočtu musí mít text a odkaz.
     */
    /** @return list<string> kódy blokací, které výpočet zapsal */
    private function runThroughCalculation(string $gap): array
    {
        try {
            $run = $this->runs->createRun($this->supplierId, self::PERIOD_START, self::PAYDAY, $this->officeId, $this->actors[0]);
            $locked = $this->runs->lockInputs($this->supplierId, (int) $run['id'], (int) $run['row_version'], "pre-{$gap}-lock", $this->actors[0]);
            $calculated = $this->runs->calculate($this->supplierId, (int) $run['id'], (int) $locked->run['row_version'], "pre-{$gap}-calc", $this->actors[0]);
        } catch (\Throwable $exception) {
            $handled = false;
            foreach (self::HANDLED as $class) {
                $handled = $handled || $exception instanceof $class;
            }
            self::assertTrue($handled, sprintf('Zaseknutí (%s): běh skončil výjimkou %s, z akce by byla 500: %s', $gap, $exception::class, $exception->getMessage()));
            self::assertNotSame('', trim($exception->getMessage()));

            return ['exception'];
        }
        $blockers = [];
        foreach ($this->db->pdo()->query(sprintf(
            'SELECT code, severity, message, remediation_path FROM payroll_run_validations WHERE supplier_id = %d AND revision_id = %d',
            $this->supplierId,
            (int) $calculated->revision['id'],
        ))->fetchAll(\PDO::FETCH_ASSOC) as $validation) {
            $this->assertActionable($validation, "blokace běhu {$validation['code']} ({$gap})");
            if ($validation['severity'] === 'blocker') {
                $blockers[] = (string) $validation['code'];
            }
        }

        return array_values(array_unique($blockers));
    }

    /** @param array<string,mixed> $finding */
    private function assertActionable(array $finding, string $label): void
    {
        self::assertNotSame('', trim((string) ($finding['message'] ?? '')), "{$label}: chybí text.");
        $path = $finding['remediation_path'] ?? null;
        self::assertIsString($path, "{$label}: chybí odkaz. " . CanonicalJson::encode($finding));
        self::assertStringStartsWith('/', $path, $label);
        $this->assertPeriodLink($path, $label);
    }

    private function assertPeriodLink(string $path, string $label): void
    {
        $route = (string) parse_url($path, PHP_URL_PATH);
        if (!in_array($route, self::PERIOD_BOUND_PATHS, true)) {
            return;
        }
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        self::assertSame(self::PERIOD, $query['period'] ?? null, "{$label}: odkaz {$path} nenese období.");
    }

    private function readiness(): PayrollRunReadinessService
    {
        $service = $this->container->get(PayrollRunReadinessService::class);
        self::assertInstanceOf(PayrollRunReadinessService::class, $service);

        return $service;
    }

    private function mappings(): PayrollComponentJmhzMappingRepository
    {
        $repository = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $repository);

        return $repository;
    }
}
