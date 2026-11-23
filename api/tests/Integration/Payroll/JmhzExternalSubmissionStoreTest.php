<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Historie podání předchozím programem: opakovaný import téhož podání řádek přepíše
 * (nebo nechá), nezdvojí; obsah leží zapečetěný a jde přečíst jen v kontextu řádku.
 */
#[Group('integration')]
final class JmhzExternalSubmissionStoreTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private JmhzExternalSubmissionStore $store;
    private PayrollSensitiveData $sensitive;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_external_jmhz_submissions')) {
            self::markTestSkipped('Chybí tabulka payroll_external_jmhz_submissions (migrace 1901).');
        }
        $this->store = $container->get(JmhzExternalSubmissionStore::class);
        $this->sensitive = $container->get(PayrollSensitiveData::class);
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($source === 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testRepeatedImportUpdatesInsteadOfDuplicating(): void
    {
        $first = $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_PAMICA,
            self::submission('R', 'sent'), [self::form(1, 'Syntetická'), self::form(2, 'Zkušební')], null);
        self::assertSame('created', $first['status']);

        $same = $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_PAMICA,
            self::submission('R', 'sent'), [self::form(1, 'Syntetická'), self::form(2, 'Zkušební')], null);
        self::assertSame(['id' => $first['id'], 'status' => 'unchanged'], $same);

        $changed = $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_PAMICA,
            self::submission('R', 'sent'), [self::form(1, 'Opravená')], null);
        self::assertSame(['id' => $first['id'], 'status' => 'updated'], $changed);

        self::assertSame(1, $this->rowCount('payroll_external_jmhz_submissions'));
        self::assertSame(1, $this->rowCount('payroll_external_jmhz_submission_forms'));
        $row = $this->db->pdo()->prepare('SELECT form_count, payload_ciphertext, payload_sha256 FROM payroll_external_jmhz_submissions WHERE supplier_id = ? AND id = ?');
        $row->execute([$this->supplierId, $first['id']]);
        $stored = $row->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $stored['form_count']);
        self::assertStringStartsWith('enc:v2:', (string) $stored['payload_ciphertext'], 'Obsah podání leží zapečetěný.');
        $plain = $this->sensitive->reveal((string) $stored['payload_ciphertext'], PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD,
            $this->supplierId, $first['id'], PayrollRevealPurpose::ANONYMIZATION);
        self::assertSame(hash('sha256', $plain), $stored['payload_sha256']);
        self::assertSame(['program' => 'PAMICA', 'summary' => [['id' => 10029, 'value' => '15000']]], json_decode($plain, true));

        $form = $this->db->pdo()->prepare('SELECT payload_ciphertext, id FROM payroll_external_jmhz_submission_forms WHERE supplier_id = ?');
        $form->execute([$this->supplierId]);
        $formRow = $form->fetch(\PDO::FETCH_ASSOC);
        self::assertStringContainsString('Opravená', $this->sensitive->reveal((string) $formRow['payload_ciphertext'],
            PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD, $this->supplierId, (int) $formRow['id'], PayrollRevealPurpose::ANONYMIZATION));
    }

    public function testSentMonthlyIgnoresUnsentAndCancellation(): void
    {
        $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_PAMICA,
            ['source_key' => 'MH:9'] + self::submission('R', 'not_sent'), [], null);
        self::assertNull($this->store->sentMonthly($this->supplierId, 'production', '2026-02'), 'Neodeslané hlášení za měsíc nic nepodalo.');

        $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_JMHZ_XML,
            ['source_key' => 'xml:1'] + self::submission('R', 'sent'), [], null);
        $sent = $this->store->sentMonthly($this->supplierId, 'production', '2026-02');
        self::assertSame(JmhzExternalSubmissionStore::SOURCE_JMHZ_XML, $sent['source'] ?? null);
        self::assertNull($this->store->sentMonthly($this->supplierId, 'test', '2026-02'), 'Prostředí se nemíchají.');
        self::assertNull($this->store->sentMonthly($this->supplierId, 'production', '2026-03'));

        $overview = $this->store->overview($this->supplierId, 'production');
        self::assertSame(['sent', 'not_sent'], array_column($overview, 'status'));
    }

    /**
     * PRE-05: stornující podání ruší řádné hlášení se stejným GUID, takže nové řádné
     * hlášení za měsíc (s novým GUID) není duplicita. Dřív `sentMonthly()` vracelo
     * i stornované hlášení a MyÚčto odmítlo připravit náhradní.
     */
    public function testSentMonthlyIgnoresRegularCancelledByStorno(): void
    {
        $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_JMHZ_XML,
            ['source_key' => 'xml:r'] + self::submission('R', 'sent'), [], null);
        self::assertSame('R', $this->store->sentMonthly($this->supplierId, 'production', '2026-02')['submission_type'] ?? null);

        $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_JMHZ_XML,
            ['source_key' => 'xml:s'] + self::submission('S', 'sent'), [], null);
        self::assertNull(
            $this->store->sentMonthly($this->supplierId, 'production', '2026-02'),
            'Storno zneplatnilo řádné hlášení, za měsíc už nic podáno není.',
        );

        $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_JMHZ_XML,
            ['source_key' => 'xml:r2', 'submission_guid' => '33333333-3333-4333-8333-333333333333']
                + self::submission('R', 'sent'),
            [], null);
        self::assertSame(
            '33333333-3333-4333-8333-333333333333',
            $this->store->sentMonthly($this->supplierId, 'production', '2026-02')['submission_guid'] ?? null,
            'Nové řádné hlášení s novým GUID je platné podání za měsíc.',
        );
    }

    /** PRE-05: hlášení, které podle načteného protokolu ČSSZ zamítla, měsíc nepodalo. */
    public function testRejectedProtocolDoesNotMarkMonthSent(): void
    {
        if (!$this->db->hasTable('payroll_imported_jmhz_protocols')) {
            self::markTestSkipped('Chybí tabulka payroll_imported_jmhz_protocols.');
        }
        $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_JMHZ_XML,
            ['source_key' => 'xml:r'] + self::submission('R', 'sent'), [], null);
        self::assertNotNull($this->store->sentMonthly($this->supplierId, 'production', '2026-02'));

        $this->protocol(3, 'a');
        self::assertNull(
            $this->store->sentMonthly($this->supplierId, 'production', '2026-02'),
            'Zamítnuté podání měsíc nepodalo.',
        );

        $this->protocol(1, 'b');
        self::assertNotNull(
            $this->store->sentMonthly($this->supplierId, 'production', '2026-02'),
            'Pozdější přijetí téhož GUID podání platí.',
        );
    }

    private function protocol(int $statusCode, string $dedupe): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_imported_jmhz_protocols
                (supplier_id, environment, protocol_kind, variable_symbol, period_month, period_year, submission_guid,
                 status_code, status_name, error_count, payload_sha256, payload_xml, dedupe_key)
             VALUES (?, 'production', 'processing', '1234567890', 2, 2026, ?, ?, ?, 0, ?, '<x/>', ?)"
        )->execute([
            $this->supplierId,
            '22222222-2222-4222-8222-222222222222',
            $statusCode,
            $statusCode === 3 ? 'Rejected' : 'ProcessedAndComplete',
            str_repeat('b', 64),
            str_pad($dedupe, 64, $dedupe),
        ]);
    }

    /**
     * Přehled musí říct, KDO a JAKOU akcí byl v podání: dřív ukazoval u registrace
     * jen „—" a počet formulářů, takže nešlo poznat, co se podalo.
     */
    public function testOverviewAndDetailNamePeopleActionAndEffectiveDay(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, ?, "employee", 1)')
            ->execute([$this->supplierId, 'Syntetická Nastupující']);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, is_primary, start_date)
             VALUES (?, ?, "SYN-1", "employment", "active", 0, "2026-05-04")'
        )->execute([$this->supplierId, $employeeId]);
        $employmentId = (int) $pdo->lastInsertId();

        $registration = $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_PAMICA, [
            'source_key' => 'RegZAM:7',
            'document_kind' => 'registration',
            'period' => null,
            'submission_type' => null,
            'submission_guid' => null,
            'corrected_source_key' => null,
            'status' => 'sent',
            'filled_at' => '2026-05-04T13:18:40',
            'submitted_at' => '2026-05-04T13:18:40',
            'accepted_at' => null,
            'program' => 'PAMICA',
            'file_name' => null,
            'payload' => ['program' => 'PAMICA', 'kind' => 'registration', 'header' => []],
        ], [
            [
                'position' => 1, 'form_guid' => null, 'form_type' => 'start', 'source_relation_ref' => '11',
                'employee_id' => $employeeId, 'employment_id' => $employmentId,
                'payload' => ['item' => [], 'attributes' => [
                    ['id' => 10009, 'order' => 0, 'value' => '2026-05-04'],
                    ['id' => 10223, 'order' => 0, 'value' => '2026-05-04T00:00:00'],
                ]],
            ],
            [
                'position' => 2, 'form_guid' => null, 'form_type' => 'end', 'source_relation_ref' => '12',
                'employee_id' => null, 'employment_id' => null,
                'payload' => ['item' => [], 'attributes' => [['id' => 10224, 'order' => 0, 'value' => '30.04.2026']]],
            ],
        ], null);
        $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_PAMICA,
            self::submission('R', 'sent'), [self::form(1, 'Syntetická'), self::form(2, 'Zkušební'), self::form(3, 'Další'), self::form(4, 'Poslední')], null);

        $overview = array_column($this->store->overview($this->supplierId, 'production'), null, 'document_kind');
        $row = $overview['registration'];
        self::assertSame(['A1' => 1, 'A2' => 1], $row['actions']);
        self::assertSame('Syntetická Nastupující', $row['people'][0]['name']);
        self::assertSame('SYN-1', $row['people'][0]['code']);
        self::assertSame('A1', $row['people'][0]['action']);
        self::assertSame('2026-05-04', $row['people'][0]['effective_on']);
        self::assertNull($row['people'][1]['name'], 'Nespárovaná věta zůstane bez jména.');
        self::assertSame('2026-04-30', $row['effective_from']);
        self::assertSame('2026-05-04', $row['effective_to']);
        self::assertSame(['R' => 4], $overview['monthly']['actions']);
        self::assertCount(3, $overview['monthly']['people'], 'Přehled měsíčního hlášení ukáže jen ochutnávku osob.');

        $detail = $this->store->detail($this->supplierId, 'production', $registration['id']);
        self::assertNotNull($detail);
        self::assertCount(2, $detail['forms']);
        self::assertSame('12', $detail['forms'][1]['source_relation_ref']);
        self::assertNull($this->store->detail($this->supplierId, 'test', $registration['id']), 'Prostředí se nemíchají.');
    }

    /** @return array<string,mixed> */
    private static function submission(string $type, string $status): array
    {
        return [
            'source_key' => 'MH:1',
            'document_kind' => 'monthly',
            'period' => '2026-02',
            'submission_type' => $type,
            'submission_guid' => '22222222-2222-4222-8222-222222222222',
            'corrected_source_key' => null,
            'status' => $status,
            'filled_at' => '2026-03-16T09:00:00',
            'submitted_at' => $status === 'sent' ? '2026-03-16T09:00:00' : null,
            'accepted_at' => $status === 'sent' ? '2026-03-16T09:05:00' : null,
            'program' => 'PAMICA',
            'file_name' => null,
            'payload' => ['program' => 'PAMICA', 'summary' => [['id' => 10029, 'value' => '15000']]],
        ];
    }

    /** @return array<string,mixed> */
    private static function form(int $position, string $name): array
    {
        return [
            'position' => $position,
            'form_guid' => sprintf('AAAAAAAA-AAAA-4AAA-8AAA-%012d', $position),
            'form_type' => 'R',
            'source_relation_ref' => (string) $position,
            'employee_id' => null,
            'employment_id' => null,
            'payload' => ['attributes' => [['id' => 10053, 'value' => $name]]],
        ];
    }

    private function rowCount(string $table): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
