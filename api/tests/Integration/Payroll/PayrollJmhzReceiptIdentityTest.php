<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzReceiptIdentityService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolParser;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzReceiptVerifier;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\JmhzSignedProtocolFactory;
use MyInvoice\Tests\Unit\Payroll\Submission\JmhzTransportSample;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Uzavření smyčky identifikátorů ČSSZ: co protokol k hlášení JMHZ vrátí, to
 * skončí v evidenci — a co se s evidencí rozchází, skončí jako nález.
 *
 * Dosud se identifikátory z protokolu jen přečetly, porovnaly a zahodily.
 * Účetní je pak u každého zaměstnance opisovala ručně, u firmy s desítkami
 * lidí to bylo nepoužitelné.
 */
#[Group('integration')]
final class PayrollJmhzReceiptIdentityTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const CHANNEL = 'vrep_apep';
    private const SENT_ID_PPV = '4002787754995';
    private const PROTOCOL_OIC = '1632728141';
    /** OIČ s platným kontrolním součtem, ale jiné než {@see PROTOCOL_OIC}. */
    private const OTHER_OIC = '1234567895';

    private Connection $db;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private PayrollRegistrationIdentityService $identities;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private string $currentCorrelation = '';
    private ?JmhzSignedProtocolFactory $factory = null;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $encryption = $container->get(SecretEncryption::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $this->db = $connection;
        if (!$connection->hasTable('payroll_jmhz_protocol_form_outcomes')) {
            $this->markTestSkipped('Migrace 1550 neproběhla.');
        }

        $pdo = $connection->pdo();
        $sourceStatement = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $sourceStatement);
        $source = (int) $sourceStatement->fetchColumn();
        self::assertGreaterThan(0, $source);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        [$this->employeeId, $this->employmentId] = $this->createPerson($pdo);

        $repository = new PayrollSubmissionRepository($connection);
        $identityRepository = new PayrollRegistrationIdentityRepository(
            $connection,
        );
        $clock = new MockClock('2026-08-04 10:11:12 Europe/Prague');
        $this->obligations = new PayrollObligationService($repository, $clock);
        $this->identities = new PayrollRegistrationIdentityService(
            $identityRepository,
            $sensitive,
        );
        $this->submissions = new PayrollSubmissionService(
            $repository,
            new PayrollSubmissionStateMachine(),
            $encryption,
            $clock,
            null,
            new JmhzReceiptIdentityService(
                $repository,
                $identityRepository,
                $this->identities,
                $sensitive,
                // Kruh se v testu láme stejně jako v kontejneru: čtečka
                // vznikne až při prvním použití, kdy `$this->submissions`
                // dávno stojí.
                fn (): JmhzFrozenPayloadReader => new JmhzFrozenPayloadReader(
                    $repository,
                    $this->submissions,
                ),
            ),
        );

        // Hlášení JMHZ se bez ID PPV vůbec nesestaví, takže ho evidence má
        // ještě před odesláním — a právě přes něj se odpověď ČSSZ páruje
        // zpátky na pracovní vztah.
        $this->identities->assignEmploymentExternalId(
            $this->supplierId,
            $this->employmentId,
            'production',
            self::SENT_ID_PPV,
            '2026-07-01',
            'verified_manual_import',
            'ruční opis z portálu ČSSZ',
            null,
            null,
        );
    }

    protected function tearDown(): void
    {
        $this->factory?->cleanUp();
        $this->factory = null;
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /**
     * První hlášení projde, ČSSZ v protokolu vrátí OIČ — a aplikace si ho
     * uloží sama, na správný vztah i do správného prostředí.
     */
    public function testAcceptedProtocolStoresIdentifiersOnTheEmployment(): void
    {
        self::assertNull($this->identities->activePersonExternalIdMatches(
            $this->supplierId,
            $this->employeeId,
            'production',
            self::PROTOCOL_OIC,
        ));

        $submission = $this->submitted('accepted');
        $this->import($submission, $this->signedProtocol());

        self::assertTrue($this->identities->activePersonExternalIdMatches(
            $this->supplierId,
            $this->employeeId,
            'production',
            self::PROTOCOL_OIC,
        ));
        self::assertTrue($this->identities->activeEmploymentExternalIdMatches(
            $this->supplierId,
            $this->employmentId,
            'production',
            self::SENT_ID_PPV,
        ));

        $stored = $this->personExternalIds();
        self::assertCount(1, $stored);
        self::assertSame('production', $stored[0]['environment']);
        self::assertSame('trusted_receipt', $stored[0]['source_kind']);
        self::assertSame('2026-08-04', $stored[0]['valid_from']);
        self::assertNotNull($stored[0]['source_receipt_id']);
        // Testovací prostředí zůstává prázdné: identifikátor patří tam, kde
        // podání proběhlo.
        self::assertNull($this->identities->activePersonExternalIdMatches(
            $this->supplierId,
            $this->employeeId,
            'test',
            self::PROTOCOL_OIC,
        ));
        self::assertSame(0, $this->resolutionTaskCount());
    }

    /**
     * Protokol se může načíst dvakrát — z jiného kanálu, po pádu, po
     * opětovném dotazu na výsledek. Podruhé nesmí vzniknout nic.
     */
    public function testSecondImportOfTheSameProtocolChangesNothing(): void
    {
        $submission = $this->submitted('replay');
        $protocol = $this->signedProtocol();
        $this->import($submission, $protocol);
        $first = $this->personExternalIds();
        self::assertCount(1, $first);

        $this->import($this->current($submission), $protocol, ':again');

        $second = $this->personExternalIds();
        self::assertCount(1, $second);
        self::assertSame($first[0]['id'], $second[0]['id']);
        self::assertSame(
            $first[0]['source_receipt_id'],
            $second[0]['source_receipt_id'],
        );
        self::assertSame(0, $this->resolutionTaskCount());
    }

    /**
     * Jiná hodnota než v evidenci se NIKDY nepřepíše tiše. Buď se spletl
     * opis, nebo ČSSZ osobu přečíslovala; obojí musí vidět člověk.
     */
    public function testDifferentIdentifierNeverOverwritesAndOpensFinding(): void
    {
        $this->identities->assignPersonExternalId(
            $this->supplierId,
            $this->employeeId,
            'production',
            self::OTHER_OIC,
            '2026-06-01',
            'verified_manual_import',
            'ruční opis z portálu ČSSZ',
            null,
            null,
        );

        $submission = $this->submitted('mismatch');
        $this->import($submission, $this->signedProtocol());

        self::assertTrue($this->identities->activePersonExternalIdMatches(
            $this->supplierId,
            $this->employeeId,
            'production',
            self::OTHER_OIC,
        ));
        $stored = $this->personExternalIds();
        self::assertCount(1, $stored);
        self::assertSame('verified_manual_import', $stored[0]['source_kind']);
        self::assertSame('2026-06-01', $stored[0]['valid_from']);

        $tasks = $this->resolutionTasks();
        self::assertCount(1, $tasks);
        self::assertSame('person_identity', $tasks[0]['task_kind']);
        self::assertSame(
            'jmhz_protocol_person_identifier_mismatch',
            $tasks[0]['reason_code'],
        );
        self::assertSame($this->employmentId, (int) $tasks[0]['employment_id']);
        self::assertNotNull($tasks[0]['source_receipt_id']);

        // Opakovaný import nález nezdvojí.
        $this->import(
            $this->current($submission),
            $this->signedProtocol(),
            ':again',
        );
        self::assertSame(1, $this->resolutionTaskCount());
        self::assertCount(1, $this->personExternalIds());
    }

    /** @param array{id:int,row_version:int,correlation:string} $submission */
    private function import(
        array $submission,
        string $protocol,
        string $suffix = '',
    ): void {
        $this->submissions->importReceipt(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            null,
            $protocol,
            // Číslo protokolu je jedinečné; opakované načtení téhož výsledku
            // přichází jako DALŠÍ protokol, ne jako přepis toho prvního.
            $submission['correlation'] . strtoupper(ltrim($suffix, ':')),
            $submission['correlation'],
            'CSSZ_JMHZ',
            'accepted',
            self::CHANNEL,
            'jmhz-identity-' . $submission['id'] . $suffix,
            null,
            new JmhzReceiptVerifier(
                new JmhzProtocolSignatureVerifier(
                    trustAnchorPem: $this->protocols()->anchorPem(),
                ),
                new JmhzProtocolParser(),
            ),
        );
    }

    /**
     * @param array{id:int,correlation:string} $submission
     * @return array{id:int,row_version:int,correlation:string}
     */
    private function current(array $submission): array
    {
        $current = $this->submissions->get($this->supplierId, $submission['id']);

        return [
            'id' => $submission['id'],
            'row_version' => (int) $current['row_version'],
            'correlation' => $submission['correlation'],
        ];
    }

    private function signedProtocol(): string
    {
        return $this->protocols()->sign(JmhzTransportSample::partialProtocol(
            'OK',
            [[
                'guid' => JmhzTransportSample::FORM_GUID,
                'result' => 'OK',
                'identifier' => self::PROTOCOL_OIC . ';' . self::SENT_ID_PPV,
            ]],
            correlationId: $this->currentCorrelation,
        ));
    }

    /** @return array{id:int,row_version:int,correlation:string} */
    private function submitted(string $key): array
    {
        $this->currentCorrelation = 'CID'
            . strtoupper(substr(hash('crc32b', $key), 0, 8));
        $obligation = $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:synthetic',
            '2026-07-01',
            '2026-07-31',
            'regular',
            self::CHANNEL,
            'payroll_run_approved',
            'run:identity:2026-07:' . $key,
            str_repeat('c', 64),
            '2026-08-01',
            '2026-08-20',
            'calendar_days',
            'jmhz-deadline-identity',
            str_repeat('d', 64),
            'obligation-jmhz-identity-2026-07-' . $key,
        );
        $submission = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            self::CHANNEL,
            str_repeat('a', 64),
            'regular-identity-2026-07-' . $key,
        );
        $part = $this->submissions->addPart(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            'jmhz-part-' . $key,
            'JMHZ25',
            'payroll_run:1',
            'jmhz_preparation',
            'jmhz_preparation:1',
            str_repeat('b', 64),
        );
        $artifact = $this->submissions->storeArtifact(
            $this->supplierId,
            $submission['id'],
            $part['submission_row_version'],
            $part['id'],
            'outbound_xml',
            'outbound',
            'application/xml',
            self::frozenPayload(),
            null,
            null,
            self::CHANNEL,
            'jmhz-identity-artifact-' . $key,
        );
        $validated = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $artifact['submission_row_version'],
            'validated',
        );
        $ready = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $validated['row_version'],
            'ready',
        );
        $submitted = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $ready['row_version'],
            'submitted',
            $this->currentCorrelation,
        );

        return [
            'id' => (int) $submission['id'],
            'row_version' => (int) $submitted['row_version'],
            'correlation' => $this->currentCorrelation,
        ];
    }

    /**
     * Zmrazená datová věta v rozsahu, který {@see JmhzFrozenPayloadReader}
     * potřebuje: typ podání, GUID formuláře a odeslaná identita.
     */
    private static function frozenPayload(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<jmhz xmlns="' . JmhzSchemaCatalog::NS_PODANI . '"'
            . ' xmlns:form="' . JmhzSchemaCatalog::NS_FORM . '" verze="1.4.3.4">'
            . '<hlavicka><typPodani>R</typPodani></hlavicka>'
            . '<formulareOsob><formularOsoby>'
            . '<hlavicka><idFormulare>' . JmhzTransportSample::FORM_GUID
            . '</idFormulare><typFormulare>R</typFormulare></hlavicka>'
            . '<form:zamestnanec><form:identifikace>'
            . '<form:ikMpsv>' . self::PROTOCOL_OIC . '</form:ikMpsv>'
            . '<form:idPpv>' . self::SENT_ID_PPV . '</form:idPpv>'
            . '</form:identifikace></form:zamestnanec>'
            . '</formularOsoby></formulareOsob>'
            . '</jmhz>';
    }

    /** @return list<array<string,mixed>> */
    private function personExternalIds(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, environment, valid_from, source_kind, source_receipt_id
               FROM payroll_person_external_ids
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY id'
        );
        $statement->execute([$this->supplierId, $this->employeeId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? array_values($rows) : [];
    }

    /** @return list<array<string,mixed>> */
    private function resolutionTasks(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT task_kind, reason_code, employment_id, source_receipt_id
               FROM payroll_identity_resolution_tasks
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY id'
        );
        $statement->execute([$this->supplierId, $this->employmentId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? array_values($rows) : [];
    }

    private function resolutionTaskCount(): int
    {
        return count($this->resolutionTasks());
    }

    /** @return array{0:int,1:int} */
    private function createPerson(PDO $pdo): array
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Jana Novotná", "employee", "hpp",
                     1, 1, 0, 30000, 0, 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 effective_from)
             VALUES (?, ?, "Jana Novotná", "Jana", "Novotná", "2026-01-01")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, is_legacy_projection)
             VALUES (?, ?, "jmhz-identity", "employment", "active",
                     "2026-01-01", 0)'
        )->execute([$this->supplierId, $employeeId]);

        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    private function protocols(): JmhzSignedProtocolFactory
    {
        return $this->factory ??= new JmhzSignedProtocolFactory();
    }
}
