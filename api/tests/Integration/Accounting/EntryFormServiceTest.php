<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\EntryForm\EntryFormException;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class EntryFormServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private EntryFormService $service;
    private int $supplierId;
    private int $actorId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí výchozí firma pro izolovaný test.');
        }
        $this->actorId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', legal_form = '112' WHERE id = ?")
            ->execute([$this->supplierId]);
        $period = $pdo->prepare(
            'INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status) VALUES (?, ?, ?, ?, ?)'
        );
        $period->execute([$this->supplierId, 2097, '2097-01-01', '2097-12-31', 'closed']);
        $period->execute([$this->supplierId, 2098, '2098-01-01', '2098-12-31', 'open']);

        $this->service = $container->get(EntryFormService::class);
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

    public function testSupplierWithoutHistoryIsComposite(): void
    {
        self::assertFalse($this->service->hasAnySingle($this->supplierId));
        self::assertSame(EntryForm::Composite, $this->service->formAt($this->supplierId, '2098-06-01'));
        self::assertSame(EntryForm::Composite, $this->service->formAt($this->supplierId, '2098-06-01', currentRead: true));
        self::assertSame([], $this->service->history($this->supplierId));
    }

    public function testScheduleSwitchesFormFromDateAndWritesAuditLog(): void
    {
        self::assertFalse($this->service->hasAnySingle($this->supplierId));

        $id = $this->service->schedule($this->supplierId, '2098-03-01', EntryForm::Single, $this->actorId, 42, 'Přechod');

        self::assertGreaterThan(0, $id);
        self::assertTrue($this->service->hasAnySingle($this->supplierId));
        self::assertSame(EntryForm::Composite, $this->service->formAt($this->supplierId, '2098-02-28'));
        self::assertSame(EntryForm::Single, $this->service->formAt($this->supplierId, '2098-03-01'));
        self::assertSame(EntryForm::Single, $this->service->formAt($this->supplierId, '2099-01-01', currentRead: true));

        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log WHERE supplier_id = ? AND action = 'accounting.entry_form_changed'"
        );
        $stmt->execute([$this->supplierId]);
        $payloads = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(1, $payloads);
        self::assertSame(
            ['valid_from' => '2098-03-01', 'from' => 'composite', 'to' => 'single', 'conversion_preview_id' => 42],
            json_decode((string) $payloads[0], true),
        );

        $this->service->schedule($this->supplierId, '2098-09-01', EntryForm::Composite, $this->actorId);
        self::assertSame(EntryForm::Single, $this->service->formAt($this->supplierId, '2098-08-31'));
        self::assertSame(EntryForm::Composite, $this->service->formAt($this->supplierId, '2098-09-01'));
        self::assertSame(['2098-03-01', '2098-09-01'], array_column($this->service->history($this->supplierId), 'valid_from'));
    }

    public function testDateInClosedPeriodIsRejected(): void
    {
        $this->assertScheduleFails('entry_form_period_not_open', '2097-06-01');
    }

    public function testDateWithoutPeriodIsRejected(): void
    {
        $this->assertScheduleFails('entry_form_period_not_open', '2099-06-01');
    }

    public function testDateBeforeLockIsRejected(): void
    {
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)')
            ->execute([$this->supplierId, '2098-03-31']);

        $this->assertScheduleFails('entry_form_date_locked', '2098-03-31');
        $this->service->schedule($this->supplierId, '2098-04-01', EntryForm::Single, $this->actorId);
        self::assertSame(EntryForm::Single, $this->service->formAt($this->supplierId, '2098-04-01'));
    }

    public function testNewerOrSameDatedChangeAndUnchangedFormAreRejected(): void
    {
        $this->assertScheduleFails('entry_form_unchanged', '2098-02-01', EntryForm::Composite);
        $this->service->schedule($this->supplierId, '2098-05-01', EntryForm::Single, $this->actorId);

        $this->assertScheduleFails('entry_form_newer_change_exists', '2098-04-01');
        $this->assertScheduleFails('entry_form_newer_change_exists', '2098-05-01');
        $this->assertScheduleFails('entry_form_unchanged', '2098-06-01');
    }

    public function testTaxEvidenceSupplierIsRejected(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);

        $this->assertScheduleFails('entry_form_requires_double_entry', '2098-06-01');
    }

    public function testHomeownersAssociationIsRejected(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET legal_form = '145' WHERE id = ?")
            ->execute([$this->supplierId]);

        $this->assertScheduleFails('entry_form_unsupported_module', '2098-06-01');
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->assertScheduleFails('invalid_entry_form_date', '2098-02-31');
    }

    private function assertScheduleFails(string $code, string $validFrom, EntryForm $form = EntryForm::Single): void
    {
        try {
            $this->service->schedule($this->supplierId, $validFrom, $form, $this->actorId);
        } catch (EntryFormException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertTrue($this->db->pdo()->inTransaction(), 'Chyba nesmí zrušit transakci volajícího.');
            return;
        }
        self::fail('Očekávaná chyba ' . $code . ' nenastala.');
    }
}
