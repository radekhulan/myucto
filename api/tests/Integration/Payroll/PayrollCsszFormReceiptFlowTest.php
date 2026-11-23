<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDiscountIntentRepository;
use MyInvoice\Repository\Payroll\PayrollSigningProfileRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifierInterface;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzVrepClient;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentService;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Vrep\CsszFormReceiptRecorder;
use MyInvoice\Service\Signing\PersonalCertificateVaultService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Submission\CsszFormProtocolSample;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Celý řetěz od dotazu na VREP po případ dávky a záměr slevy.
 *
 * Protokol (syntetický, tvar ze zkušebních podání ČSSZ TEST 8. 10. 2026)
 * vrací falešný VREP; podpis ověřuje dvojník. Všechno ostatní je skutečné:
 * ledger pokusů, platforma podání, vazba podání na případ přes jeho součást
 * a služby, které zapisují výsledek i při ručním zápisu. Na ČSSZ se nic
 * neposílá.
 */
#[Group('integration')]
final class PayrollCsszFormReceiptFlowTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const VARIABLE_SYMBOL = '1234567890';
    private const CORRELATION = CsszFormProtocolSample::CORRELATION;
    /** MockClock: odesláno 16. 8. 2026 v 9:00 pražského času. */
    private const DELIVERED_ON = '2026-08-16';

    private Connection $db;
    private ContainerInterface $container;
    private PayrollSubmissionRepository $repository;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private PayrollSubmissionTransportAttemptRepository $attempts;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->container = $container;
        $connection = $container->get(Connection::class);
        $encryption = $container->get(SecretEncryption::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SecretEncryption::class, $encryption);

        $this->db = $connection;
        $pdo = $connection->pdo();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);

        $this->repository = new PayrollSubmissionRepository($connection);
        $clock = new MockClock('2026-08-16 09:00:00 Europe/Prague');
        $this->obligations = new PayrollObligationService($this->repository, $clock);
        $this->submissions = new PayrollSubmissionService(
            $this->repository,
            new PayrollSubmissionStateMachine(),
            $encryption,
            $clock,
        );
        $this->attempts = new PayrollSubmissionTransportAttemptRepository($connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testAcceptedNempriProtocolAcceptsTheCaseDocument(): void
    {
        [$employeeId, $employmentId] = $this->employment('nempri-ok');
        $submissionId = $this->submission('NEMPRI', 'NEMPRI25', 'nempri-ok');
        $caseId = $this->sicknessCase($employeeId, $employmentId, 'nempri_submission_id', $submissionId);
        $this->sendAndPoll($submissionId, 'sickness:' . $caseId . ':nempri', 'nempri-ok', 'NEMPRI25',
            CsszFormProtocolSample::accepted('CSSZ_NEM_PRI', 'NEMPRI25'));

        $case = $this->row('payroll_sickness_cases', $caseId);
        self::assertSame('accepted', $case['nempri_status']);
        self::assertSame(self::DELIVERED_ON, $case['nempri_accepted_on']);
        self::assertSame('accepted', $this->row('payroll_submissions', $submissionId)['status']);
        self::assertSame([], $this->issueCodes($submissionId));
    }

    /**
     * Vzorek HZUPN20 s chybou 103: podání je odmítnuté, tiskopis případu také,
     * s důvodem o pověření u OSSZ, a podání nese pojmenovaný nález.
     */
    public function testServiceAuthorizationErrorRejectsTheHzupnWithTheReason(): void
    {
        [$employeeId, $employmentId] = $this->employment('hzupn-103');
        $submissionId = $this->submission('HZUPN', 'HZUPN20', 'hzupn-103');
        $caseId = $this->sicknessCase($employeeId, $employmentId, 'hzupn_submission_id', $submissionId);
        $this->sendAndPoll($submissionId, 'sickness:' . $caseId . ':hzupn', 'hzupn-103', 'HZUPN20',
            CsszFormProtocolSample::serviceAuthorizationMissing('CSSZ_NEM_PRI'));

        $case = $this->row('payroll_sickness_cases', $caseId);
        self::assertSame('rejected', $case['hzupn_status']);
        self::assertStringContainsString('pověření k e-službě CSSZ_NEM_PRI', (string) $case['hzupn_rejection_reason']);
        self::assertStringContainsString('Nejde o vadu podání', (string) $case['hzupn_rejection_reason']);
        self::assertSame('rejected', $this->row('payroll_submissions', $submissionId)['status']);
        self::assertSame(
            [CsszFormReceiptRecorder::SERVICE_AUTHORIZATION_ISSUE],
            $this->issueCodes($submissionId),
        );
    }

    public function testAcceptedOzuspojProtocolAcceptsTheIntent(): void
    {
        [$employeeId, $employmentId] = $this->employment('ozuspoj-ok');
        $submissionId = $this->submission('OZUSPOJ', 'OZUSPOJ23', 'ozuspoj-ok');
        $intentId = $this->intent($employeeId, $employmentId, $submissionId);
        $this->sendAndPoll($submissionId, 'ozuspoj:' . $intentId . ':start', 'ozuspoj-ok', 'OZUSPOJ23',
            CsszFormProtocolSample::protocol(
                'CSSZ_OZUSPOJ',
                'response',
                'OK',
                [
                    CsszFormProtocolSample::item('OZUSPOJ23', '', '', 'OK'),
                    CsszFormProtocolSample::item('OZUSPOJ23', '0', CsszFormProtocolSample::BIRTH_NUMBER, 'OK'),
                ],
                envelopeClass: 'CSSZ_OZUSPOJ ',
            ));

        $intent = $this->row('payroll_discount_intents', $intentId);
        self::assertSame('accepted', $intent['status']);
        self::assertSame(self::DELIVERED_ON, $intent['accepted_on']);
        self::assertSame([], $this->issueCodes($submissionId));
    }

    /** Chyba 103 záměr neodmítne: zůstává podaný, důvod je v nálezu u podání. */
    public function testServiceAuthorizationErrorLeavesTheIntentSubmitted(): void
    {
        [$employeeId, $employmentId] = $this->employment('ozuspoj-103');
        $submissionId = $this->submission('OZUSPOJ', 'OZUSPOJ23', 'ozuspoj-103');
        $intentId = $this->intent($employeeId, $employmentId, $submissionId);
        $this->sendAndPoll($submissionId, 'ozuspoj:' . $intentId . ':start', 'ozuspoj-103', 'OZUSPOJ23',
            CsszFormProtocolSample::serviceAuthorizationMissing('CSSZ_OZUSPOJ'));

        self::assertSame('submitted', $this->row('payroll_discount_intents', $intentId)['status']);
        self::assertSame('rejected', $this->row('payroll_submissions', $submissionId)['status']);
        self::assertSame(
            [CsszFormReceiptRecorder::SERVICE_AUTHORIZATION_ISSUE],
            $this->issueCodes($submissionId),
        );
    }

    // ───────────────────────── příprava ─────────────────────────

    /**
     * Odeslané podání s otevřeným pokusem a dotaz na výsledek proti
     * falešnému VREP, který vrátí daný protokol.
     */
    private function sendAndPoll(
        int $submissionId,
        string $partReference,
        string $key,
        string $form,
        string $protocol,
    ): void {
        $submission = $this->submissions->get($this->supplierId, $submissionId);
        $part = $this->submissions->addPart(
            $this->supplierId,
            $submissionId,
            (int) $submission['row_version'],
            $partReference,
            $form,
            'payroll_employment:1',
            'payroll_employment',
            'test:' . $key,
            str_repeat('e', 64),
        );
        $artifact = $this->submissions->storeArtifact(
            $this->supplierId,
            $submissionId,
            (int) $part['submission_row_version'],
            (int) $part['id'],
            'outbound_xml',
            'outbound',
            'application/xml',
            '<?xml version="1.0" encoding="UTF-8"?><podani formular="' . $form . '"/>',
            $form,
            null,
            'isds',
            'artifact-cssz-form-' . $key,
        );
        $validated = $this->submissions->transition($this->supplierId, $submissionId, (int) $artifact['submission_row_version'], 'validated');
        $this->submissions->transition($this->supplierId, $submissionId, (int) $validated['row_version'], 'ready');
        // Totéž, co dělá adaptér VREP před odesláním a transport po převzetí.
        $adopted = $this->submissions->adoptDispatchChannel($this->supplierId, $submissionId, JmhzDispatchService::CHANNEL);
        $attempt = $this->attempts->open(
            $this->supplierId,
            'test',
            $submissionId,
            JmhzDispatchService::CHANNEL,
            1,
            'cssz-form-click-' . $key,
            str_repeat('b', 64),
            null,
        );
        $attempt = $this->attempts->markSent((int) $attempt['id'], self::CORRELATION, 200, (int) $attempt['row_version']);
        $this->submissions->transition(
            $this->supplierId,
            $submissionId,
            (int) $adopted['row_version'],
            'submitted',
            self::CORRELATION,
        );

        $outcome = $this->dispatch([new Response(200, ['Content-Type' => 'text/xml'], $protocol)])->poll(
            $this->supplierId,
            'test',
            (int) $attempt['id'],
            self::VARIABLE_SYMBOL,
            1,
            $form === 'OZUSPOJ23' ? 'CSSZ_OZUSPOJ' : 'CSSZ_NEM_PRI',
            $form,
        );

        self::assertFalse($outcome->manualReview, 'Protokol v doloženém tvaru nesmí jít k ručnímu vyřízení.');
        self::assertSame('completed', $outcome->attempt['status']);
    }

    /** @param list<Response> $queue */
    private function dispatch(array $queue): JmhzDispatchService
    {
        $profiles = $this->container->get(PayrollSigningProfileRepository::class);
        $vault = $this->container->get(PersonalCertificateVaultService::class);
        $secrets = $this->container->get(SecretEncryption::class);
        $cases = $this->container->get(SicknessCaseService::class);
        $intents = $this->container->get(OzuspojIntentService::class);
        self::assertInstanceOf(PayrollSigningProfileRepository::class, $profiles);
        self::assertInstanceOf(PersonalCertificateVaultService::class, $vault);
        self::assertInstanceOf(SecretEncryption::class, $secrets);
        self::assertInstanceOf(SicknessCaseService::class, $cases);
        self::assertInstanceOf(OzuspojIntentService::class, $intents);

        return new JmhzDispatchService(
            $this->attempts,
            $profiles,
            $vault,
            $secrets,
            new JmhzSoftwareIdentification('MyUcto', '1.0'),
            new JmhzVrepClient(
                new Client(['handler' => HandlerStack::create(new MockHandler($queue)), 'http_errors' => false]),
                'test',
            ),
            submissions: $this->submissions,
            signatures: new class () implements JmhzProtocolSignatureVerifierInterface {
                public function verifiedProtocolXml(string $bytes, string $environment): string
                {
                    return $bytes;
                }
            },
            formReceipts: new CsszFormReceiptRecorder(
                $this->repository,
                $this->submissions,
                $cases,
                $intents,
            ),
        );
    }

    /** @return array{0:int,1:int} zaměstnanec a pracovní vztah */
    private function employment(string $key): array
    {
        $pdo = $this->db->pdo();
        $employee = $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 0, 0, 0, NULL, 0, 1)',
        );
        $employee->execute([$this->supplierId, 'Testovací Osoba']);
        $employeeId = (int) $pdo->lastInsertId();
        $employment = $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", 4000000)',
        );
        $employment->execute([$this->supplierId, $employeeId, 'VREP-' . $key]);

        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    private function sicknessCase(int $employeeId, int $employmentId, string $column, int $submissionId): int
    {
        $pdo = $this->db->pdo();
        $case = $pdo->prepare(
            'INSERT INTO payroll_sickness_cases
                (supplier_id, environment, employee_id, employment_id,
                 benefit_kind, ossz_code, incapacity_from, incapacity_to,
                 ' . $column . ', created_by)
             VALUES (?, "test", ?, ?, "NEM", 115, "2026-07-01", "2026-08-10", ?, ?)',
        );
        $case->execute([$this->supplierId, $employeeId, $employmentId, $submissionId, $this->userId]);

        return (int) $pdo->lastInsertId();
    }

    private function intent(int $employeeId, int $employmentId, int $submissionId): int
    {
        $intents = new PayrollDiscountIntentRepository($this->db);
        $intentId = $intents->insert(
            $this->supplierId,
            'test',
            $employeeId,
            $employmentId,
            'study_under_26',
            '2026-09-01',
            115,
            '2026-08-01',
            $this->userId,
        );
        $row = $intents->find($this->supplierId, 'test', $intentId);
        self::assertIsArray($row);
        self::assertTrue($intents->update(
            $this->supplierId,
            'test',
            $intentId,
            (int) $row['row_version'],
            ['start_submission_id' => $submissionId, 'status' => 'submitted'],
        ));

        return $intentId;
    }

    private function submission(string $agendaCode, string $form, string $key): int
    {
        $obligation = $this->obligations->register(
            $this->supplierId,
            $agendaCode,
            'employment',
            'payroll_employment:1',
            '2026-08-01',
            '2026-08-31',
            'regular',
            'isds',
            $agendaCode === 'OZUSPOJ' ? 'payroll_discount_intent' : 'payroll_sickness_case',
            ($agendaCode === 'OZUSPOJ' ? 'payroll_discount_intent:' : 'payroll_sickness_case:') . abs(crc32($key)),
            str_repeat('c', 64),
            '2026-08-15',
            '2026-08-20',
            'calendar_days',
            'cssz-form-receipt-test',
            str_repeat('d', 64),
            'obligation-cssz-form-' . $key . '-' . $form,
            environment: 'test',
        );

        return (int) $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            'isds',
            str_repeat('a', 64),
            'cssz-form-' . $key,
            environment: 'test',
        )['id'];
    }

    /** @return array<string,mixed> */
    private function row(string $table, int $id): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM ' . $table . ' WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    /** @return list<string> */
    private function issueCodes(int $submissionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT issue_code FROM payroll_submission_issues
              WHERE supplier_id = ? AND submission_id = ? ORDER BY id',
        );
        $statement->execute([$this->supplierId, $submissionId]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }
}
