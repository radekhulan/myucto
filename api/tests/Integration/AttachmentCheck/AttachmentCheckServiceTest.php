<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\AttachmentCheck;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentExtractionRepository;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Document\AttachmentCheck\AttachmentCheckService;
use MyInvoice\Service\Document\ScanAttach\ScanExtractionNormalizer;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola zaúčtovaných dokladů proti vytěžení příloh nad testovací DB: syntetické
 * přijaté faktury v roce 2091, syntetické vytěžení. Ověřuje DUZP přes hranici měsíce
 * v měsíční kontrole, potvrzení s důvodem vázané na otisk a izolaci firem.
 */
#[Group('integration')]
final class AttachmentCheckServiceTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private \Psr\Container\ContainerInterface $c;
    private int $sid = 0;
    private int $otherSid = 0;
    private int $userId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    /** @var list<int> */
    private array $pis = [];
    /** @var list<int> */
    private array $docs = [];
    /** @var list<string> */
    private array $shas = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->c = Bootstrap::buildApp()->getContainer();
            $this->db = $this->c->get(Connection::class);
        } catch (\Throwable $e) {
            self::markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();
        $this->sid = (int) ($this->pdo->query('SELECT id FROM supplier WHERE is_vat_payer = 1 AND ic IS NOT NULL ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->otherSid = (int) ($this->pdo->query("SELECT id FROM supplier WHERE company_name = 'Druhá testovací firma s.r.o.' LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($this->pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->sid} AND code = 'CZK' LIMIT 1")->fetchColumn() ?: 0);
        $country = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->sid === 0 || $this->otherSid === 0 || $this->userId === 0 || $this->currencyId === 0 || $country === 0) {
            self::markTestSkipped('Chybí plátce DPH, druhá firma, uživatel, CZK nebo země.');
        }
        $this->pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, ic, main_email, is_customer, is_vendor)
             VALUES (?, 'Syntetický dodavatel kontroly příloh s.r.o.', 'Testovací 1', 'Praha', '11000', ?, ?, '11220033', 'kontrola@example.test', 0, 1)"
        )->execute([$this->sid, $country, $this->currencyId]);
        $this->vendorId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        foreach ($this->pis as $id) {
            $this->pdo->exec("DELETE FROM attachment_checks WHERE entity_type = 'purchase_invoice' AND entity_id = {$id}");
            $this->pdo->exec("DELETE FROM activity_log WHERE action = 'attachment_check.acknowledged' AND entity_id = {$id}");
            $this->pdo->exec("DELETE FROM document_links WHERE entity_type = 'purchase_invoice' AND entity_id = {$id}");
            $this->pdo->exec("DELETE FROM purchase_invoices WHERE id = {$id}");
        }
        foreach ($this->docs as $id) {
            $this->pdo->exec("DELETE FROM documents WHERE id = {$id}");
        }
        foreach ($this->shas as $sha) {
            $this->pdo->prepare('DELETE FROM document_extractions WHERE sha256 = ?')->execute([$sha]);
        }
        if ($this->vendorId > 0) {
            $this->pdo->exec("DELETE FROM clients WHERE id = {$this->vendorId}");
        }
        $this->db->close();
    }

    public function testTaxDateAcrossMonthIsVatWarningInBothMonthsAndInMonthlyCheck(): void
    {
        $pi = $this->purchase('AICHK-A', '2091-04-01', 1210.0);
        $this->attachScan($pi, ['tax_date' => '2091-03-31', 'total_with_vat' => 1210.0]);

        $rows = $this->service()->recheckEntity($this->sid, 'purchase_invoice', $pi);
        self::assertCount(1, $rows);
        self::assertSame('mismatch', $rows[0]['status']);
        self::assertSame('warning', $rows[0]['severity']);
        self::assertSame(['tax_date_period'], array_column($rows[0]['findings'], 'field'));

        $stored = $this->pdo->query("SELECT status, severity FROM attachment_checks WHERE entity_type = 'purchase_invoice' AND entity_id = {$pi}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['status' => 'mismatch', 'severity' => 'warning'], $stored, 'výsledek je uložený');

        self::assertContains($pi, array_column($this->service()->closingFindings($this->sid, '2091-03-01', '2091-03-31')['vat_period'], 'doc_id'), 'březen: DUZP podle přílohy');
        self::assertContains($pi, array_column($this->service()->closingFindings($this->sid, '2091-04-01', '2091-04-30')['vat_period'], 'doc_id'), 'duben: DUZP dokladu');
        self::assertNotContains($pi, array_column($this->service()->closingFindings($this->sid, '2091-05-01', '2091-05-31')['vat_period'], 'doc_id'));

        $checks = $this->closingChecks('2091-04-01', '2091-04-30');
        self::assertFalse($checks['attachment_vat_period']['ok']);
        self::assertSame('warning', $checks['attachment_vat_period']['severity']);
        $finding = $this->findingFor($checks['attachment_vat_period'], $pi);
        self::assertSame('purchase_invoice', $finding['doc_type']);
        self::assertSame(['attachment_tax_date_period'], $finding['issues']);
        self::assertSame('2091-03-31', $finding['detail']['attachment_tax_date_period']['attachment']);
        self::assertNull($this->findingFor($checks['attachment_mismatch'], $pi, false), 'varování nepatří do informativní kontroly');
    }

    public function testAcknowledgementNeedsReasonAndHoldsOnlyUntilValuesChange(): void
    {
        $pi = $this->purchase('AICHK-B', '2091-04-10', 1210.0);
        $sha = $this->attachScan($pi, ['tax_date' => '2091-04-10', 'total_with_vat' => 1250.0]);
        $service = $this->service();
        $service->recheckEntity($this->sid, 'purchase_invoice', $pi);

        self::assertSame('reason_required', $service->acknowledge($this->sid, 'purchase_invoice', $pi, $sha, '  ', $this->userId)['error']);
        self::assertTrue($service->acknowledge($this->sid, 'purchase_invoice', $pi, $sha, 'Příloha je cenová nabídka, doklad je správně.', $this->userId)['ok']);

        $row = $service->evaluate($this->sid, 'purchase_invoice', $pi)[0];
        self::assertTrue($row['acknowledged']);
        self::assertFalse($row['open']);
        self::assertSame([], array_filter(
            $service->closingFindings($this->sid, '2091-04-01', '2091-04-30')['other'],
            static fn (array $f): bool => $f['doc_id'] === $pi,
        ), 'potvrzený rozdíl se v kontrole znovu nehlásí');
        self::assertNotContains($pi, array_column($service->listMismatches($this->sid, 'open')['rows'], 'entity_id'));
        self::assertContains($pi, array_column($service->listMismatches($this->sid, 'acknowledged')['rows'], 'entity_id'));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'attachment_check.acknowledged' AND entity_id = {$pi}")->fetchColumn(), 'potvrzení je v auditní stopě');

        // Změna dokladu = jiný otisk → rozdíl se vrací, s původním důvodem jako kontextem.
        $this->pdo->exec("UPDATE purchase_invoices SET total_with_vat = 1260.00 WHERE id = {$pi}");
        $row = $service->evaluate($this->sid, 'purchase_invoice', $pi)[0];
        self::assertFalse($row['acknowledged']);
        self::assertTrue($row['open']);
        self::assertSame('Příloha je cenová nabídka, doklad je správně.', $row['ack_reason']);
    }

    public function testOtherCompanyCanNeitherSeeNorAcknowledge(): void
    {
        $pi = $this->purchase('AICHK-C', '2091-04-01', 1210.0);
        $sha = $this->attachScan($pi, ['tax_date' => '2091-03-31']);
        $this->service()->recheckEntity($this->sid, 'purchase_invoice', $pi);

        self::assertSame([], $this->service()->evaluate($this->otherSid, 'purchase_invoice', $pi));
        self::assertSame('not_found', $this->service()->acknowledge($this->otherSid, 'purchase_invoice', $pi, $sha, 'Cizí firma to nesmí.', $this->userId)['error']);
        self::assertNotContains($pi, array_column($this->service()->listMismatches($this->otherSid, 'all')['rows'], 'entity_id'));
        self::assertNotContains($pi, array_column($this->service()->closingFindings($this->otherSid, '2091-03-01', '2091-04-30')['vat_period'], 'doc_id'));
        self::assertSame([], $this->service()->recheckEntity($this->otherSid, 'purchase_invoice', $pi));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM attachment_checks WHERE entity_id = {$pi} AND entity_type = 'purchase_invoice'")->fetchColumn(), 'přepočet cizí firmou nic nesmazal');
    }

    public function testPdfSlotExtractionIsComparedToo(): void
    {
        // Doklad založený AI importem má PDF jen ve slotu faktury (pdf_hash), ne v Dokumentech.
        $sha = hash('sha256', 'synthetic-pdf-slot-' . bin2hex(random_bytes(6)));
        $pi = $this->purchase('AICHK-D', '2091-04-15', 1210.0, $sha);
        $this->saveExtraction($sha, null, ['tax_date' => '2091-04-15', 'total_with_vat' => 1210.0]);

        $rows = $this->service()->evaluate($this->sid, 'purchase_invoice', $pi);
        self::assertCount(1, $rows);
        self::assertSame('match', $rows[0]['status']);
        self::assertNull($rows[0]['document_id']);
    }

    public function testRecheckAllDropsResultOfDetachedAttachment(): void
    {
        $pi = $this->purchase('AICHK-E', '2091-04-01', 1210.0);
        $this->attachScan($pi, ['tax_date' => '2091-03-31']);
        $this->service()->recheckEntity($this->sid, 'purchase_invoice', $pi);
        $this->pdo->exec("DELETE FROM document_links WHERE entity_type = 'purchase_invoice' AND entity_id = {$pi}");

        $this->service()->recheckAll($this->sid);

        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM attachment_checks WHERE entity_type = 'purchase_invoice' AND entity_id = {$pi}")->fetchColumn());
    }

    private function service(): AttachmentCheckService
    {
        return $this->c->get(AttachmentCheckService::class);
    }

    /** @return array<string, array<string,mixed>> klíč kontroly → kontrola */
    private function closingChecks(string $from, string $to): array
    {
        $period = ['id' => 0, 'supplier_id' => $this->sid, 'fiscal_year' => 2091, 'starts_on' => '2091-01-01', 'ends_on' => '2091-12-31'];
        $out = [];
        foreach ($this->c->get(ClosingService::class)->buildChecks($this->sid, $period, $from, $to, 500, ['attachment_vat_period', 'attachment_mismatch']) as $check) {
            $out[(string) $check['key']] = $check;
        }
        self::assertArrayHasKey('attachment_vat_period', $out, 'kontrola DUZP proti příloze v měsíční kontrole');
        self::assertArrayHasKey('attachment_mismatch', $out);
        return $out;
    }

    /**
     * @param array<string,mixed> $check
     * @return array<string,mixed>|null
     */
    private function findingFor(array $check, int $docId, bool $required = true): ?array
    {
        foreach ((array) ($check['value']['findings'] ?? []) as $f) {
            if (($f['doc_id'] ?? null) === $docId) {
                return $f;
            }
        }
        if ($required) {
            self::fail("Kontrola {$check['key']} nemá nález pro doklad #{$docId}.");
        }
        return null;
    }

    private function purchase(string $number, string $taxDate, float $total, ?string $pdfHash = null): int
    {
        $this->pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date, received_at,
                 currency_id, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status, created_by,
                 payment_variable_symbol, pdf_hash)
             VALUES (?, ?, ?, 'invoice', ?, ?, ?, ?, ?, '{}', ?, ?, ?, 'received', ?, '20910055', ?)"
        )->execute([
            $this->sid, $this->vendorId, $number . '-' . bin2hex(random_bytes(3)), $taxDate, $taxDate, $taxDate, $taxDate,
            $this->currencyId, round($total / 1.21, 2), round($total - $total / 1.21, 2), $total, $this->userId, $pdfHash,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pis[] = $id;
        return $id;
    }

    /** @param array<string,mixed> $o */
    private function attachScan(int $pi, array $o): string
    {
        $sha = hash('sha256', 'synthetic-scan-' . bin2hex(random_bytes(8)));
        $this->pdo->prepare(
            "INSERT INTO documents (supplier_id, title, original_name, filename, sha256, mime_type, size_bytes, doc_type)
             VALUES (?, 'Syntetický sken', 'sken.pdf', 'sken.pdf', ?, 'application/pdf', 100, 'pdf')"
        )->execute([$this->sid, $sha]);
        $docId = (int) $this->pdo->lastInsertId();
        $this->docs[] = $docId;
        $this->pdo->prepare("INSERT INTO document_links (document_id, supplier_id, entity_type, entity_id) VALUES (?, ?, 'purchase_invoice', ?)")
            ->execute([$docId, $this->sid, $pi]);
        $this->saveExtraction($sha, $docId, $o);
        return $sha;
    }

    /** @param array<string,mixed> $o */
    private function saveExtraction(string $sha, ?int $docId, array $o): void
    {
        $this->shas[] = $sha;
        $fields = $o + [
            'company_role' => 'buyer', 'document_kind' => 'invoice',
            'vendor_ico' => '11220033', 'buyer_ico' => null, 'variable_symbol' => '20910055',
            'total_with_vat' => 1210.0, 'amount_due' => null, 'currency' => 'CZK',
        ];
        $this->c->get(DocumentExtractionRepository::class)->save(
            $this->sid, $docId, $sha, ScanExtractionNormalizer::SCHEMA_VERSION, 'ok', $fields, ['synthetic' => true], 'fake', 'fake-model', null,
        );
    }
}
