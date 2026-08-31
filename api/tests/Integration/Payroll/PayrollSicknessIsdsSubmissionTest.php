<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Odeslání NEMPRI a HZUPN datovou schránkou.
 *
 * Katalog schopností u obou agend uváděl `transport_capability => 'isds'`
 * a workflow krok „odešlete datovou schránkou“, jenže jediná odesílací cesta
 * byla zadrátovaná na JMHZ: věc zprávy nesla natvrdo „Jednotné měsíční hlášení
 * zaměstnavatele“, adresát se bral z JMHZ katalogu a seznam připravených podání
 * se ptal natvrdo na `JMHZ25`. Účetní tak připravila hlášení a neměla ho kde
 * odeslat.
 *
 * Testy proto ověřují právě to, co se jednotkově ověřit nedá: že podání dojde
 * do OBECNÉ fronty pod SVOU agendou, na SVOU doloženou schránku, že se nedá
 * zařadit pod cizí agendou a že rozšíření seznamu nerozbilo obrazovku
 * „Stav odeslání“ (kanál VREP/APEP).
 */
#[Group('integration')]
final class PayrollSicknessIsdsSubmissionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const CHANNEL = 'isds';
    private const VARIABLE_SYMBOL = '1234567890';

    private Connection $db;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private PayrollIsdsSubmissionService $isds;
    private PayrollSubmissionTransportAttemptRepository $attempts;
    private ContainerInterface $container;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->container = $container;
        $connection = $container->get(Connection::class);
        $encryption = $container->get(SecretEncryption::class);
        $outbox = $container->get(SubmissionOutboxService::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        self::assertInstanceOf(SubmissionOutboxService::class, $outbox);

        $this->db = $connection;
        $pdo = $connection->pdo();
        $sourceStatement = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $sourceStatement);
        $source = (int) $sourceStatement->fetchColumn();
        self::assertGreaterThan(0, $source);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);

        $repository = new PayrollSubmissionRepository($connection);
        $clock = new MockClock('2026-08-16 09:00:00 Europe/Prague');
        $this->obligations = new PayrollObligationService($repository, $clock);
        $this->submissions = new PayrollSubmissionService(
            $repository,
            new PayrollSubmissionStateMachine(),
            $encryption,
            $clock,
        );
        $this->isds = new PayrollIsdsSubmissionService(
            $repository,
            $this->submissions,
            new SubmissionRecipientRepository($connection),
            $outbox,
        );
        $this->attempts = new PayrollSubmissionTransportAttemptRepository($connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /**
     * Testovací prostředí musí jít do TESTOVACÍ schránky a podání do fronty
     * pod SVOU agendou — jinak by se protokol ČSSZ přiřadil jinému hlášení.
     */
    public function testNempriReachesTheQueueUnderItsOwnAgenda(): void
    {
        $result = $this->enqueue('NEMPRI', 'nempri-a');

        self::assertSame('9tsaf6s', $result['recipient']['box_id']);
        self::assertSame('NEMPRI', $result['agenda_code']);
        self::assertSame('NEMPRI', (string) $result['row']['agenda_code']);
        self::assertSame('isds', (string) $result['row']['channel']);
    }

    /**
     * Věc zprávy je pro ČLOVĚKA ve schránce. Když ji builder psal natvrdo,
     * dorazilo hlášení o nemoci pod názvem měsíčního hlášení a účetní ho
     * hledala podle nesprávného textu.
     */
    public function testSubjectNamesTheAgendaThatIsActuallySent(): void
    {
        $nempri = $this->enqueue('NEMPRI', 'nempri-b');
        $hzupn = $this->enqueue('HZUPN', 'hzupn-b');

        self::assertStringStartsWith('NEMPRI - ', $nempri['subject']);
        self::assertStringContainsString('o žádosti zaměstnance o dávku', $nempri['subject']);
        self::assertStringNotContainsString('Jednotné měsíční hlášení', $nempri['subject']);

        self::assertStringStartsWith('HZUPN - ', $hzupn['subject']);
        self::assertStringContainsString('ukončení pracovní neschopnosti', $hzupn['subject']);

        // Variabilní symbol se čte ze zmrazené datové věty, ne z nastavení
        // firmy: ve věci má stát přesně to, co ČSSZ v příloze dostane.
        self::assertStringContainsString(self::VARIABLE_SYMBOL, $nempri['subject']);
        self::assertStringContainsString(self::VARIABLE_SYMBOL, $hzupn['subject']);
    }

    /** Přílohou je zmrazená datová věta beze změny — holé XML, žádná obálka. */
    public function testAttachmentIsTheFrozenPayload(): void
    {
        $result = $this->enqueue('NEMPRI', 'nempri-c');

        self::assertSame('application/xml', $result['attachment']['mime']);
        self::assertSame(
            hash('sha256', $this->payload('NEMPRI')),
            $result['attachment']['sha256'],
        );
    }

    /**
     * Volba kanálu nesmí vyrobit druhé podání ani druhý termín — opakované
     * zařazení téhož zmrazeného artefaktu vrátí TÝŽ řádek fronty.
     */
    public function testRepeatedEnqueueDoesNotCreateASecondSubmission(): void
    {
        $submissionId = $this->frozenSubmission('HZUPN', 'hzupn-d');

        $first = $this->isds->enqueue($this->supplierId, 'test', $submissionId, [], null);
        $second = $this->isds->enqueue($this->supplierId, 'test', $submissionId, [], null);

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['outbox_id'], $second['outbox_id']);
    }

    /**
     * Rozsah obrazovky se vynucuje SERVEREM. Z karty nemocenského případu se
     * nesmí zařadit měsíční hlášení, ani kdyby jeho ID někdo do požadavku
     * podstrčil — jinak by mzdové údaje celé firmy odešly pod hlavičkou
     * hlášení o jednom člověku.
     */
    public function testSubmissionOfAnotherAgendaIsRefusedByScope(): void
    {
        $submissionId = $this->frozenSubmission('NEMPRI', 'nempri-e');

        try {
            $this->isds->enqueue(
                $this->supplierId,
                'test',
                $submissionId,
                [JmhzSubmissionBridgeService::AGENDA_CODE],
                null,
            );
            self::fail('Podání cizí agendy mělo být odmítnuto.');
        } catch (SubmissionChannelException $exception) {
            self::assertSame('payroll_isds_agenda_scope_mismatch', $exception->errorCode);
        }
    }

    /**
     * Agenda bez doloženého kanálu se nesmí zařadit vůbec — fail-closed
     * s konkrétní větou, ne obecné „nepodporováno".
     */
    public function testAgendaWithoutADocumentedChannelIsRefused(): void
    {
        $submissionId = $this->frozenSubmission('OZUSPOJ', 'ozuspoj-f');

        try {
            $this->isds->enqueue($this->supplierId, 'test', $submissionId, [], null);
            self::fail('Nedoložená agenda měla být odmítnuta.');
        } catch (SubmissionChannelException $exception) {
            self::assertSame('payroll_isds_agenda_undocumented', $exception->errorCode);
            self::assertStringContainsString('OZUSPOJ', $exception->getMessage());
        }
    }

    /**
     * Číselník je editovatelný, takže se na něj u mzdových údajů nespoléhá
     * slepě: přepsané ID schránky musí podání zastavit, ne ho poslat jinam.
     */
    public function testTamperedRecipientBoxStopsTheSubmission(): void
    {
        $submissionId = $this->frozenSubmission('NEMPRI', 'nempri-g');
        $this->db->pdo()->prepare(
            "UPDATE submission_recipients SET isds_box_id = 'aaaaaaa'
              WHERE supplier_id IS NULL AND code = 'cssz_epodani_test'"
        )->execute();

        $this->expectException(SubmissionChannelException::class);
        $this->isds->enqueue($this->supplierId, 'test', $submissionId, [], null);
    }

    /**
     * Seznam připravených podání je parametrizovaný agendami a MUSÍ to
     * respektovat obojím směrem:
     *
     *   * nemocenská hlášení se konečně objeví (dřív se dotaz ptal natvrdo na
     *     JMHZ, takže připravené podání nebylo kde odeslat),
     *   * obrazovka „Stav odeslání" (kanál VREP/APEP) je nesmí začít nabízet —
     *     tudy odeslat nejdou a tlačítko by vždycky selhalo.
     */
    public function testReadyListIsScopedByAgenda(): void
    {
        $nempriId = $this->frozenSubmission('NEMPRI', 'nempri-h');

        $sickness = $this->attempts->listReadySubmissions(
            $this->supplierId,
            'test',
            SicknessSubmissionService::DISPATCHABLE_AGENDA_CODES,
        );
        self::assertSame(
            [$nempriId],
            array_column($sickness, 'submission_id'),
        );
        self::assertSame('NEMPRI', $sickness[0]['agenda_code']);
        self::assertNull($sickness[0]['outbox_id']);

        $vrep = $this->attempts->listReadySubmissions(
            $this->supplierId,
            'test',
            [JmhzSubmissionBridgeService::AGENDA_CODE],
        );
        self::assertSame([], $vrep);
    }

    /** Po zařazení nese seznam i číslo řádku fronty, aby UI nenabízelo odeslání znovu. */
    public function testReadyListShowsTheQueuedOutbox(): void
    {
        $submissionId = $this->frozenSubmission('HZUPN', 'hzupn-i');
        $queued = $this->isds->enqueue($this->supplierId, 'test', $submissionId, [], null);

        $ready = $this->attempts->listReadySubmissions(
            $this->supplierId,
            'test',
            SicknessSubmissionService::DISPATCHABLE_AGENDA_CODES,
        );

        self::assertCount(1, $ready);
        self::assertSame($queued['outbox_id'], $ready[0]['outbox_id']);
        self::assertSame('ready', $ready[0]['outbox_dispatch_state']);
    }

    /** Prázdný rozsah není „všechno" — je to zapomenutý parametr. */
    public function testReadyListRefusesAnEmptyAgendaScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->attempts->listReadySubmissions($this->supplierId, 'test', []);
    }

    /**
     * Celý řetěz od karty případu, ne jen sdílené jádro.
     *
     * Tohle je ta kontrola, kvůli které se v tomhle projektu opakovaně stalo,
     * že „hotová a otestovaná" věc byla tichý no-op: jádro fungovalo, ale nikdo
     * ho nevolal. Test proto jde cestou, kterou spouští tlačítko v UI — případ
     * dávky → jeho připravené podání → fronta — a ověřuje, že se zařadilo
     * PRÁVĚ TO podání, které případ nese.
     */
    public function testDispatchFromTheCaseCardQueuesTheSubmissionOfThatCase(): void
    {
        $submissionId = $this->frozenSubmission('NEMPRI', 'case-j');
        $caseId = $this->sicknessCase($submissionId);

        $service = $this->container->get(SicknessSubmissionService::class);
        self::assertInstanceOf(SicknessSubmissionService::class, $service);

        $result = $service->enqueueDataBox(
            $this->supplierId,
            'test',
            $caseId,
            SicknessDocumentKind::Nempri,
            null,
        );

        self::assertSame($caseId, $result['case_id']);
        self::assertSame('nempri', $result['document_kind']);
        self::assertSame('NEMPRI', $result['agenda_code']);
        self::assertSame('9tsaf6s', $result['recipient']['box_id']);

        $ready = $this->attempts->listReadySubmissions(
            $this->supplierId,
            'test',
            SicknessSubmissionService::DISPATCHABLE_AGENDA_CODES,
        );
        self::assertCount(1, $ready);
        self::assertSame($submissionId, $ready[0]['submission_id']);
        self::assertSame($result['outbox_id'], $ready[0]['outbox_id']);
    }

    /**
     * Tiskopis, který se ještě nepřipravil, nemá co odeslat — a musí to říct
     * konkrétně, ne spadnout na „podání nenalezeno".
     */
    public function testDispatchOfAnUnpreparedFormIsRefusedWithAReason(): void
    {
        $caseId = $this->sicknessCase($this->frozenSubmission('NEMPRI', 'case-k'));

        $service = $this->container->get(SicknessSubmissionService::class);
        self::assertInstanceOf(SicknessSubmissionService::class, $service);

        try {
            $service->enqueueDataBox(
                $this->supplierId,
                'test',
                $caseId,
                SicknessDocumentKind::Hzupn,
                null,
            );
            self::fail('Nepřipravené hlášení mělo být odmítnuto.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_submission_not_prepared', $exception->validationCode);
        }
    }

    // ───────────────────────── příprava ─────────────────────────

    /** Syntetický případ dávky s už připraveným podáním NEMPRI. */
    private function sicknessCase(int $nempriSubmissionId): int
    {
        $pdo = $this->db->pdo();
        $userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();

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
        $employment->execute([
            $this->supplierId,
            $employeeId,
            'ISDS-' . $nempriSubmissionId,
        ]);
        $employmentId = (int) $pdo->lastInsertId();

        $case = $pdo->prepare(
            'INSERT INTO payroll_sickness_cases
                (supplier_id, environment, employee_id, employment_id,
                 benefit_kind, ossz_code, incapacity_from, status,
                 nempri_submission_id, created_by)
             VALUES (?, "test", ?, ?, "NEM", 115, "2026-08-01", "prepared", ?, ?)',
        );
        $case->execute([
            $this->supplierId,
            $employeeId,
            $employmentId,
            $nempriSubmissionId,
            $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function enqueue(string $agendaCode, string $key): array
    {
        return $this->isds->enqueue(
            $this->supplierId,
            'test',
            $this->frozenSubmission($agendaCode, $key),
            [],
            null,
        );
    }

    /**
     * Syntetická datová věta. Struktura odpovídá skutečnému serializéru jen
     * v tom, na čem tenhle test stojí: kořen ve jmenném prostoru agendy
     * a variabilní symbol zaměstnavatele pod jménem, které agenda používá.
     */
    private function payload(string $agendaCode): string
    {
        return match ($agendaCode) {
            'NEMPRI' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<NEMPRI xmlns="http://schemas.cssz.cz/nem/NEMPRI25">'
                . '<datovaVeta><zamestnani>'
                . '<VSZamestnavatel>' . self::VARIABLE_SYMBOL . '</VSZamestnavatel>'
                . '</zamestnani></datovaVeta></NEMPRI>',
            'HZUPN' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<PodaniHZUPN xmlns="http://schemas.cssz.cz/nem/HZUPN20">'
                . '<FormularHZUPN><zamestnani>'
                . '<variabilniSymbol>' . self::VARIABLE_SYMBOL . '</variabilniSymbol>'
                . '</zamestnani></FormularHZUPN></PodaniHZUPN>',
            default => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<podani agenda="' . $agendaCode . '"/>',
        };
    }

    private function frozenSubmission(string $agendaCode, string $key): int
    {
        $obligation = $this->obligations->register(
            $this->supplierId,
            $agendaCode,
            'employment',
            'payroll_employment:1',
            '2026-08-01',
            '2026-08-31',
            'regular',
            self::CHANNEL,
            'payroll_sickness_case',
            'payroll_sickness_case:' . abs(crc32($key)),
            str_repeat('c', 64),
            '2026-08-15',
            '2026-08-20',
            'calendar_days',
            'sickness-deadline-test',
            str_repeat('d', 64),
            'obligation-sickness-isds-' . $key,
            environment: 'test',
        );
        $submission = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            self::CHANNEL,
            str_repeat('a', 64),
            'sickness-isds-' . $key,
            environment: 'test',
        );
        $artifact = $this->submissions->storeArtifact(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            null,
            'outbound_xml',
            'outbound',
            'application/xml',
            $this->payload($agendaCode),
            $agendaCode === 'HZUPN' ? 'HZUPN20' : 'NEMPRI25',
            null,
            self::CHANNEL,
            'artifact-sickness-isds-' . $key,
        );
        $validated = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $artifact['submission_row_version'],
            'validated',
        );
        $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $validated['row_version'],
            'ready',
        );

        return (int) $submission['id'];
    }
}
