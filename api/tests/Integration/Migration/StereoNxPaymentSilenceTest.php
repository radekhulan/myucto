<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxAccountingTables;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticNx1Archive.php';
require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxAccountingTables.php';
require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxTables.php';

/** Oprava historické úhrady nesmí vytvořit pokus o komunikaci ani odložený požadavek. */
#[Group('integration')]
final class StereoNxPaymentSilenceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxAccountingImporter $importer;
    private StereoNxBackup $backup;
    private string $archive;
    private int $supplierId;
    private int $userId;
    private int $invoiceId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $identity = SyntheticStereoNxAccountingTables::identity();
        $pdo->prepare("UPDATE supplier SET company_name=?,ic=?,dic=?,accounting_mode='double_entry',
            accounting_enabled=0,accounting_starts_on=NULL,accounting_activation_status='none',is_vat_payer=1,
            payment_thanks_enabled=1,payment_thanks_auto_send=1,payment_thanks_max_age_days=0,
            payment_thanks_attach_paid_pdf=1 WHERE id=?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $pdo->prepare('INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default)
            VALUES (?,"CZK","CZK","Kč","CZK","CZK",2,1,1)')->execute([$this->supplierId]);
        $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([(int) $pdo->lastInsertId(), $this->supplierId]);

        // I regresní odbočka k e-mailu musí být bezpečná: nic se nedostane ke skutečnému transportu.
        $mailer = $this->createMock(Mailer::class);
        foreach (['sendTemplate', 'sendTemplateDetailed', 'flushDeferred'] as $method) $mailer->expects(self::never())->method($method);
        $container->set(Mailer::class, $mailer);
        $renderer = $this->createMock(InvoicePdfRenderer::class);
        $renderer->expects(self::never())->method('render');
        $container->set(InvoicePdfRenderer::class, $renderer);
        $this->importer = $container->get(StereoNxAccountingImporter::class);

        $this->archive = sys_get_temp_dir() . '/stereo-payment-silence-' . bin2hex(random_bytes(6)) . '.zip';
        SyntheticNx1Archive::write($this->archive, self::tables(), $identity);
        $this->backup = StereoNxBackup::open($this->archive, 0);
        $before = $this->mailSnapshot();
        $initial = $this->runImport(false);
        self::assertTrue($initial['ok'], json_encode($initial['errors'], JSON_UNESCAPED_UNICODE));
        $q = $pdo->prepare("SELECT id,status,paid_total,amount_to_pay FROM invoices WHERE supplier_id=? AND invoice_type='invoice'");
        $q->execute([$this->supplierId]);
        $invoices = $q->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $invoices);
        $this->invoiceId = (int) $invoices[0]['id'];
        self::assertSame('draft', $invoices[0]['status']);
        self::assertEqualsWithDelta(121.0, (float) $invoices[0]['paid_total'], 0.001);
        self::assertEqualsWithDelta(121.0, (float) $invoices[0]['amount_to_pay'], 0.001);
        self::assertSame($before, $this->mailSnapshot());
        self::assertSame(1, (int) $this->query("SELECT COUNT(*) n FROM invoices i JOIN clients c ON c.supplier_id=i.supplier_id AND c.id=i.client_id WHERE i.supplier_id=? AND c.main_email='customer@example.invalid'")[0]['n']);
        // Simulace ručního potvrzení původního konceptu, jehož platba už byla převedena.
        $pdo->prepare("UPDATE invoices SET status='issued',paid_at=NULL WHERE supplier_id=? AND id=?")
            ->execute([$this->supplierId, $this->invoiceId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->archive)) @unlink($this->archive);
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testRepeatedImportRepairsPaidStateWithoutAnyMailOrNewPaymentDocuments(): void
    {
        $mail = $this->mailSnapshot();
        $financial = $this->financialSnapshot();
        self::assertSame(['payment_thanks_enabled' => 1, 'payment_thanks_auto_send' => 1, 'payment_thanks_max_age_days' => 0],
            $this->query('SELECT payment_thanks_enabled,payment_thanks_auto_send,payment_thanks_max_age_days FROM supplier WHERE id=?')[0]);
        $result = $this->runImport(false);
        self::assertTrue($result['ok'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $result['written']['payments']);
        $invoice = $this->invoice();
        self::assertSame('paid', $invoice['status']);
        self::assertSame('2025-03-15', substr((string) $invoice['paid_at'], 0, 10));
        self::assertNull($invoice['payment_thanks_sent_at']);
        self::assertNull($invoice['payment_thanks_sent_to']);
        self::assertNull($invoice['payment_thanks_suppressed_at']);
        self::assertSame($financial, $this->financialSnapshot(), 'Oprava cache stavu nesmí založit platbu, proformový doklad ani účetní zápis.');
        self::assertSame($mail, $this->mailSnapshot());
        $this->assertNoInvoiceCommunication();
        $repeat = $this->runImport(false);
        self::assertTrue($repeat['ok'], json_encode($repeat['errors'], JSON_UNESCAPED_UNICODE));
        self::assertSame($invoice, $this->invoice());
        self::assertSame($financial, $this->financialSnapshot());
        self::assertSame($mail, $this->mailSnapshot());
        $this->assertNoInvoiceCommunication();
    }

    public function testDryRunRollsBackHistoricalPaidRepairAndLeavesNoDeferredCommunication(): void
    {
        $invoice = $this->invoice();
        $mail = $this->mailSnapshot();
        $financial = $this->financialSnapshot();
        self::assertSame('issued', $invoice['status']);
        self::assertNull($invoice['paid_at']);
        $result = $this->runImport(true);
        self::assertTrue($result['ok'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        self::assertTrue($result['dry_run']);
        self::assertSame($invoice, $this->invoice());
        self::assertSame($financial, $this->financialSnapshot());
        self::assertSame($mail, $this->mailSnapshot());
        $this->assertNoInvoiceCommunication();
    }

    private function runImport(bool $dry): array
    {
        return $this->importer->run($this->backup, $this->supplierId, $this->userId, $dry, true);
    }

    private function invoice(): array
    {
        $q = $this->db->pdo()->prepare('SELECT status,paid_total,paid_at,payment_thanks_sent_at,payment_thanks_sent_to,payment_thanks_suppressed_at FROM invoices WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplierId, $this->invoiceId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    private function assertNoInvoiceCommunication(): void
    {
        $q = $this->db->pdo()->prepare("SELECT COUNT(*) FROM mail_outbox WHERE
            (template='__payment_thanks_intent_v1' AND JSON_VALUE(payload,'$.invoice_id')=?)
            OR (template='invoice_payment_thanks' AND JSON_VALUE(payload,'$.vars.invoice.id')=?)");
        $q->execute([$this->invoiceId, $this->invoiceId]);
        self::assertSame(0, (int) $q->fetchColumn());
        self::assertSame(0, (int) $this->query("SELECT COUNT(*) n FROM activity_log WHERE supplier_id=? AND action LIKE 'invoice.payment_thanks%'")[0]['n']);
        self::assertSame(0, (int) $this->query("SELECT COUNT(*) n FROM activity_log WHERE supplier_id=? AND action='invoice.paid'")[0]['n']);
    }

    private function mailSnapshot(): array
    {
        $result = [];
        // Testovací DB je při tomto testu vyhrazená; globální fronta nesmí změnit ani cizí požadavek.
        foreach (['mail_outbox', 'mail_send_log', 'mail_rate_limit_events'] as $table) {
            $result[$table] = $this->db->pdo()->query("SELECT COUNT(*) n,COALESCE(MAX(id),0) last_id FROM {$table}")->fetch(PDO::FETCH_ASSOC);
        }
        return $result;
    }

    private function financialSnapshot(): array
    {
        $result = [];
        foreach (['invoice_payments', 'journal_entries', 'journal_entry_lines', 'invoices', 'purchase_invoices', 'integration_outbox'] as $table) {
            $result[$table] = $this->query("SELECT * FROM {$table} WHERE supplier_id=? ORDER BY id");
        }
        // Hlavička záměrně mění pouze platební cache, ostatní finanční obsah musí zůstat shodný.
        foreach ($result['invoices'] as &$invoice) unset($invoice['status'], $invoice['paid_at'], $invoice['updated_at']);
        unset($invoice);
        return $result;
    }

    private function query(string $sql): array
    {
        $q = $this->db->pdo()->prepare($sql); $q->execute([$this->supplierId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function tables(): array
    {
        $documents = SyntheticStereoNxTables::tables();
        $tables = array_replace($documents, SyntheticStereoNxAccountingTables::tables());
        $tables['LAdresy'] = $documents['LAdresy'];
        $tables['Svfp'] = [];
        $tables['LFirmaUc'][0]['BaUcet'] = '19-1000000005';
        $tables['LFirmaUc'][0]['KodBanky'] = '0100';
        $tables['CBanka'][0]['Kurz'] = 1.0; $tables['CBanka'][0]['KurzMn'] = 1.0;
        foreach (['CBankap', 'CPokl'] as $table) foreach ($tables[$table] as &$row) {
            $row['Kurz'] = 1.0; $row['KurzMn'] = 1.0; $row['CastkaVlastni'] = $row['Castka'];
            $row['DPHz'] = 0.0; $row['DPHs'] = 0.0; $row['DPHt'] = 0.0;
        }
        unset($row);
        foreach ($tables['CPZZ'] as &$row) $row['Mena'] = 'Kč';
        unset($row);
        foreach ($tables['Cpz'] as &$row) $row['Agenda'] = $row['DoklSRada'];
        unset($row);
        return $tables;
    }
}
