<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dopad posunu platnosti OIČ převzatého z protokolu ČSSZ na měsíční hlášení
 * JMHZ: zmrazené hlášení dřívějšího měsíce se nezmění, nová příprava téhož
 * měsíce už OIČ a ID PPV nese.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eReg2JmhzIdentityTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private const OIC = '1000000012';
    private const EARLIER_ID_PPV = '200000000000000000101';
    private const LATER_ID_PPV = '200000000000000000102';

    private int $baseComponentId;

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-07-27');
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_FLOW', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Zaměstnankyně pracuje od ledna (přihlášená mimo aplikaci, čísla v ní
     * nejsou). Od srpna má navíc dohodu, jejíž přihláška A1 byla přijata
     * a OIČ přišlo z protokolu s platností od srpna. Červencové hlášení
     * odešlo do fronty ještě jmennou větví. Když účetní opíše totéž OIČ
     * a ID PPV k lednovému vztahu, zmrazené hlášení zůstane beze změny
     * a nová příprava července obě čísla nese.
     */
    public function testBackdatedReceiptOicReachesNewJmhzPreparationAndKeepsFrozenReport(): void
    {
        $person = $this->createEmployment(
            $this->regOfficeId,
            'Jana Dřívější',
            1,
            'hpp',
            'employment',
            40,
            10_000,
            true,
            '2026-07-01',
        );
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Jana',
            'last_name' => 'Dřívější',
            'birth_date' => '1990-04-15',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1990-04-15', 'female', 1),
        ]);
        $this->publishShifts($person['employment_id'], self::workdays('2026-07'));
        $this->createApprovedAverage($person['employment_id'], 3);
        self::assertSame(200, $this->approveTimeMonth(
            $person['employment_id'],
            '2026-07',
            self::workdays('2026-07'),
        )->getStatusCode());
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'base-reg2', '2026-07-01');

        $later = $this->regAdditionalEmployment($person['employee_id'], 'DPP-L', 'dpp', '2026-08-03');
        $this->regCompleteCard($person['employee_id'], $later, 'Dřívější');
        $this->regSaveA1Profile($later, $this->regA1Profile('2026-08-03', 'T'));
        $this->regReceipt(
            $this->regPrepare($later, ['registration_mode' => 'full']),
            'accepted',
            self::OIC,
            self::LATER_ID_PPV,
        );
        self::assertSame('2026-08-03', $this->scalar(
            'SELECT valid_from FROM payroll_person_external_ids WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $person['employee_id']],
        ));

        $this->regToday('2026-08-10');
        $run = $this->runPayrollMonth('2026-07-01', '2026-08-14', $this->regOfficeId, 'reg2-july');
        self::assertSame([], $run['blockers'], CanonicalJson::encode($run['blockers']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $first = $this->preparedXml($revisionId, 'reg2-july-1');
        self::assertStringNotContainsString('<form:ikMpsv>', $first['xml']);
        self::assertStringContainsString('<form:prijmeni>Dřívější</form:prijmeni>', $first['xml']);
        $frozen = $this->freezeJmhzSubmission($first['preparation_id'], $this->regOfficeId);
        self::assertSame(201, $frozen['status'], CanonicalJson::encode($frozen['body']));
        $submissionId = (int) $frozen['body']['submission_id'];
        $artifactId = (int) $frozen['body']['artifact_id'];
        $frozenBefore = $this->frozenState($submissionId, $artifactId);
        self::assertStringNotContainsString('<form:ikMpsv>', $frozenBefore['xml']);

        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertInstanceOf(PayrollRegistrationIdentityService::class, $identities);
        $identities->assignManualJmhzIdentity(
            $this->supplierId,
            $person['employment_id'],
            'test',
            self::OIC,
            self::EARLIER_ID_PPV,
            '2026-01-01',
            null,
            true,
            $this->actors[0],
        );

        self::assertSame(
            $frozenBefore,
            $this->frozenState($submissionId, $artifactId),
            'Zmrazené hlášení se posunem platnosti OIČ nesmí změnit.',
        );
        $second = $this->preparedXml($revisionId, 'reg2-july-2');
        self::assertStringContainsString('<form:ikMpsv>' . self::OIC . '</form:ikMpsv>', $second['xml']);
        self::assertStringContainsString('<form:idPpv>' . self::EARLIER_ID_PPV . '</form:idPpv>', $second['xml']);
        self::assertStringNotContainsString('<form:prijmeni>', $second['xml']);
    }

    /** @return array{preparation_id:int,xml:string} */
    private function preparedXml(int $revisionId, string $key): array
    {
        $preparation = $this->prepareJmhz($revisionId, $key);
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->regOfficeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            CanonicalJson::encode($tested['body']['controls']['blocking'] ?? $tested['body']),
        );
        self::assertTrue($tested['body']['controls']['submittable']);

        return [
            'preparation_id' => (int) $preparation['body']['id'],
            'xml' => (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']),
        ];
    }

    /** @return array{status:string,row_version:int,artifact_sha256:string,xml:string} */
    private function frozenState(int $submissionId, int $artifactId): array
    {
        $submissions = $this->container->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);

        return [
            'status' => $this->regSubmissionStatus($submissionId),
            'row_version' => (int) $this->scalar(
                'SELECT row_version FROM payroll_submissions WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $submissionId],
            ),
            'artifact_sha256' => (string) $this->scalar(
                'SELECT artifact_sha256 FROM payroll_submission_artifacts WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $artifactId],
            ),
            'xml' => (string) preg_replace('/>\s+</', '><', $submissions->artifactBytes($this->supplierId, $artifactId)),
        ];
    }
}
