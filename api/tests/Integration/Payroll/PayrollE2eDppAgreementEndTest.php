<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\PayrollEmploymentBulkClosure;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\JmhzFrozenSubmissionFixture;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Ukončené dohody agentury: noční ukončení po sjednaném konci vedle
 * rozpracovaného mzdového běhu a oprava údajů pro JMHZ u ukončené dohody,
 * dokud z hlášení nevzniklo podání.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eDppAgreementEndTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-14';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('DPP', 'Syntetická agentura', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('ODMENA_DOHODA', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        \assert($mappings instanceof PayrollComponentJmhzMappingRepository);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * DPP se sjednaným koncem 15. 4. má v dubnu odměnu a za duben je běh
     * spočítaný, ale neschválený. Noční ukončení ji ukončí ke dni konce
     * a běh jde dál schválit a připravit z něj hlášení bez slepé uličky.
     * HPP na dobu určitou ani DPP s budoucím koncem noční úloha neukončí.
     */
    public function testNightlyEndLeavesOpenRunApprovableAndSkipsEmploymentAndFutureAgreements(): void
    {
        $dpp = $this->hire('Iva Uplynulá', 'female', '1999-01-11', 'dpp');
        $this->setEnd($dpp['employment_id'], '2026-04-15');
        $future = $this->hire('Jan Budoucí', 'male', '1998-02-12', 'dpp');
        $this->setEnd($future['employment_id'], '2026-12-31');
        $fixedTerm = $this->hire('Karel Určitý', 'male', '1980-03-13', 'hpp', 'employment', 40, 10_000);
        $this->setEnd($fixedTerm['employment_id'], '2026-04-15');
        foreach ([$dpp, $future] as $person) {
            $this->work($person, ['2026-04-07'], 300_000);
        }
        $this->work($fixedTerm, self::workdays(self::PERIOD, ['2026-04-16', '2026-04-17', '2026-04-20', '2026-04-21', '2026-04-22', '2026-04-23', '2026-04-24', '2026-04-27', '2026-04-28', '2026-04-29', '2026-04-30']), 2_400_000, 480);

        $run = $this->runs->createRun($this->supplierId, self::PERIOD_START, self::PAYDAY, $this->officeId, $this->actors[0]);
        $locked = $this->runs->lockInputs($this->supplierId, (int) $run['id'], (int) $run['row_version'], 'end-lock', $this->actors[0]);
        $calculated = $this->runs->calculate($this->supplierId, (int) $run['id'], (int) $locked->run['row_version'], 'end-calc', $this->actors[0]);
        self::assertSame([], $this->blockingValidations((int) $calculated->revision['id']));

        $closure = $this->container->get(PayrollEmploymentBulkClosure::class);
        \assert($closure instanceof PayrollEmploymentBulkClosure);
        $result = $closure->endExpiredAgreements($this->supplierId, '2026-04-20');
        self::assertSame([$dpp['employment_id']], $result['ended'], CanonicalJson::encode($result));
        self::assertSame([], $result['failed']);
        self::assertSame('active', $this->scalar('SELECT status FROM payroll_employments WHERE supplier_id = ? AND id = ?', [$this->supplierId, $fixedTerm['employment_id']]));
        self::assertSame('active', $this->scalar('SELECT status FROM payroll_employments WHERE supplier_id = ? AND id = ?', [$this->supplierId, $future['employment_id']]));
        self::assertSame('2026-04-15', $this->scalar('SELECT end_date FROM payroll_employments WHERE supplier_id = ? AND id = ?', [$this->supplierId, $dpp['employment_id']]));

        // Běh spočítaný před ukončením: buď jde schválit, nebo musí účetní
        // říct, že ho má přepočítat (zastaralá revize), nikdy ne 500.
        $current = $this->db->pdo()->prepare('SELECT row_version, status FROM payroll_runs WHERE supplier_id = ? AND id = ?');
        $current->execute([$this->supplierId, (int) $run['id']]);
        $state = $current->fetch(\PDO::FETCH_ASSOC);
        $reviewed = $this->runs->review($this->supplierId, (int) $run['id'], (int) $state['row_version'], 'end-review', $this->actors[0]);
        try {
            $approved = $this->runs->approve($this->supplierId, (int) $run['id'], (int) $reviewed->run['row_version'], 'end-approve', $this->actors[0]);
            $revisionId = (int) $approved->revision['id'];
        } catch (\DomainException $e) {
            // Ukončení změnilo podklady zamčené revize: schválení se zastaví
            // s návodem „Obnovit podklady“ a přepočítat, nic se tiše nevynechá.
            self::assertStringContainsString('Obnovit podklady', $e->getMessage());
            $refreshed = $this->runs->refreshInputs($this->supplierId, (int) $run['id'], (int) $reviewed->run['row_version'], 'end-refresh', $this->actors[0]);
            $recalculated = $this->runs->calculate($this->supplierId, (int) $run['id'], (int) $refreshed->run['row_version'], 'end-recalc', $this->actors[0]);
            self::assertSame([], $this->blockingValidations((int) $recalculated->revision['id']));
            $reviewed = $this->runs->review($this->supplierId, (int) $run['id'], (int) $recalculated->run['row_version'], 'end-review2', $this->actors[0]);
            $approved = $this->runs->approve($this->supplierId, (int) $run['id'], (int) $reviewed->run['row_version'], 'end-approve2', $this->actors[0]);
            $revisionId = (int) $approved->revision['id'];
        }
        $preparation = $this->prepareJmhz($revisionId, 'end-prepare');
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']['issues'] ?? []));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame('dry_run_valid', $tested['body']['status'], CanonicalJson::encode(array_diff_key($tested['body'], ['xml' => true])));
        $xml = (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
        self::assertSame(3, substr_count($xml, '</formularOsoby>'));
    }

    /**
     * Ukončenou dohodu jde opravit v údajích pro JMHZ (CZ-ISCO, pracoviště),
     * dokud z hlášení za její měsíc nevzniklo podání. Samotná příprava
     * hlášení opravu nezamkne; zmrazené podání ano, a hláška řekne, za který
     * měsíc a co dál.
     */
    public function testEndedAgreementIsCorrectableUntilJmhzSubmissionIsFrozen(): void
    {
        $dpp = $this->hire('Lenka Opravitelná', 'female', '1997-04-14', 'dpp');
        $this->setEnd($dpp['employment_id'], '2026-04-15');
        $this->work($dpp, ['2026-04-07'], 300_000);
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'correct');
        self::assertNotNull($run['approved'], CanonicalJson::encode($run['blockers']));
        $closure = $this->container->get(PayrollEmploymentBulkClosure::class);
        \assert($closure instanceof PayrollEmploymentBulkClosure);
        self::assertSame([$dpp['employment_id']], $closure->endExpiredAgreements($this->supplierId, '2026-05-02')['ended']);

        $preparation = $this->prepareJmhz((int) $run['approved']->revision['id'], 'correct-prepare');
        self::assertSame(201, $preparation['status']);
        $first = $this->correctIsco($dpp['employment_id'], '93291');
        self::assertSame(200, $first->getStatusCode(), 'Oprava ukončené dohody po pouhé přípravě: ' . (string) $first->getBody());

        $production = $this->productionPreparationFingerprint((int) $run['approved']->revision['id']);
        JmhzFrozenSubmissionFixture::freeze($this->db->pdo(), $this->supplierId, self::PERIOD_START, $production, $this->actors[0]);
        $second = $this->correctIsco($dpp['employment_id'], '43111');
        self::assertContains($second->getStatusCode(), [409, 422], (string) $second->getBody());
        $body = (string) $second->getBody();
        self::assertStringContainsString('4/2026', $body);
        self::assertStringContainsString('opravné hlášení', $body);
    }

    /**
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function hire(string $name, string $sex, string $birthDate, string $type, string $relation = 'dpp', int $weekly = 10, int $workload = 2_500): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment($this->officeId, $name, $sequence, $type, $relation === 'dpp' ? 'dpp' : $relation, $weekly, $workload, true, self::PERIOD_START);
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $this->createApprovedAverage($person['employment_id'], 2);
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));

        return $person;
    }

    private function setEnd(int $employmentId, string $endOn): void
    {
        $this->db->pdo()->prepare('UPDATE payroll_employments SET end_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$endOn, $this->supplierId, $employmentId]);
        $this->db->pdo()->prepare('UPDATE payroll_employment_terms SET fixed_term_end_on = ? WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$endOn, $this->supplierId, $employmentId]);
    }

    /**
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @param list<string> $days
     */
    private function work(array $person, array $days, int $amountMinor, int $dailyMinutes = 240): void
    {
        $response = $this->approveTimeMonth($person['employment_id'], self::PERIOD, $days, dailyMinutes: $dailyMinutes);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->createApprovedInput($person, $this->baseComponentId, $amountMinor, 'odmena-' . $person['employment_id'], self::PERIOD_START);
    }

    private function correctIsco(int $employmentId, string $isco): \Psr\Http\Message\ResponseInterface
    {
        $employments = $this->container->get(PayrollEmploymentRepository::class);
        \assert($employments instanceof PayrollEmploymentRepository);
        $terms = $employments->currentTerms($this->supplierId, $employmentId);
        self::assertIsArray($terms);
        $action = $this->container->get(PayrollEmploymentAction::class);
        \assert($action instanceof PayrollEmploymentAction);

        return $action->correctTerms(
            $this->request('PATCH', "/api/payroll/employments/{$employmentId}/terms/current")
                ->withParsedBody([
                    'cz_isco_code' => $isco,
                    'change_reason' => 'Oprava údajů pro JMHZ',
                    'row_version' => (int) $this->scalar('SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?', [$this->supplierId, $employmentId]),
                ] + $terms),
            new Response(),
            ['id' => (string) $employmentId],
        );
    }

    /** Ostrá příprava hlášení ze schválené revize (otisk pro zmrazené podání). */
    private function productionPreparationFingerprint(int $revisionId): string
    {
        $prepare = $this->container->get(\MyInvoice\Action\Payroll\PayrollJmhzPreparationAction::class);
        $response = $prepare(
            $this->request('POST', "/api/payroll/jmhz/preparations/{$revisionId}")
                ->withHeader('Idempotency-Key', 'correct-production')
                ->withParsedBody(['environment' => 'production']),
            new Response(),
            ['revisionId' => (string) $revisionId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $fingerprint = $this->scalar(
            'SELECT snapshot_fingerprint FROM payroll_jmhz_preparation_snapshots
              WHERE supplier_id = ? AND source_revision_id = ? AND environment = "production"
              ORDER BY id DESC LIMIT 1',
            [$this->supplierId, $revisionId],
        );
        self::assertIsString($fingerprint);

        return $fingerprint;
    }
}
