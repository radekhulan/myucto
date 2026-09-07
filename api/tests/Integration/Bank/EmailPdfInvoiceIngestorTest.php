<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\EmailAttachment;
use MyInvoice\Service\Bank\EmailNotice\EmailPdfInvoiceIngestor;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Celý řetěz „PDF příloha e-mailu → podání ve frontě Příchozích dokladů".
 *
 * Jednotkové testy {@see \MyInvoice\Tests\Unit\Import\InvoiceDocumentRecognizerTest}
 * hlídají samotné rozpoznání; tenhle test hlídá to, co se rozbíjí ve wiringu —
 * že rozpoznaná příloha SKUTEČNĚ založí řádek v `purchase_invoice_submissions`
 * se zdrojem `email`, a že zamítnutá nezaloží nic a zůstane po ní důvod v logu.
 */
#[Group('integration')]
final class EmailPdfInvoiceIngestorTest extends TestCase
{
    private PDO $pdo;
    private EmailPdfInvoiceIngestor $ingestor;
    private int $supplierId;
    private string $ic;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->pdo = $container->get(Connection::class)->pdo();
            $this->ingestor = $container->get(EmailPdfInvoiceIngestor::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        $refs = $this->pdo->query(
            'SELECT
                (SELECT id FROM countries ORDER BY id LIMIT 1) AS country_id,
                (SELECT id FROM currencies ORDER BY id LIMIT 1) AS currency_id,
                (SELECT id FROM vat_rates ORDER BY id LIMIT 1) AS vat_rate_id'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($refs) || (int) ($refs['country_id'] ?? 0) <= 0) {
            $this->markTestSkipped('Missing supplier codebook prerequisites');
        }

        // Syntetické IČO: 8 číslic, žádný reálný subjekt.
        $this->ic = '99' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $this->pdo->prepare(
            'INSERT INTO supplier
                (company_name, ic, dic, street, city, zip, country_id, email,
                 default_currency_id, default_vat_rate_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'Synthetic Attachment Ingest ' . bin2hex(random_bytes(4)),
            $this->ic,
            'CZ' . $this->ic,
            'Testovací 1',
            'Praha',
            '11000',
            (int) $refs['country_id'],
            'attach-' . bin2hex(random_bytes(8)) . '@example.invalid',
            (int) $refs['currency_id'],
            (int) $refs['vat_rate_id'],
        ]);
        $this->supplierId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->supplierId)) {
            return;
        }
        $docs = $this->pdo->prepare(
            'SELECT document_id FROM purchase_invoice_submissions WHERE supplier_id = ?'
        );
        $docs->execute([$this->supplierId]);
        $documentIds = $docs->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $this->pdo->prepare('DELETE FROM bank_email_attachment_ingests WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->pdo->prepare('DELETE FROM purchase_invoice_submissions WHERE supplier_id = ?')->execute([$this->supplierId]);
        foreach ($documentIds as $documentId) {
            $this->pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([(int) $documentId]);
        }
        $this->pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
    }

    public function testRecognizedInvoiceCreatesEmailSubmission(): void
    {
        $summary = $this->ingest($this->pdf('FAKTURA - DANOVY DOKLAD c. 2026001 Odberatel ICO: ' . $this->ic));

        self::assertSame(1, $summary['imported'], (string) json_encode($summary['details']));

        $row = $this->submissionRow();
        self::assertNotNull($row, 'Podání ve frontě příchozích dokladů nevzniklo.');
        self::assertSame('email', (string) $row['submitted_via']);
        self::assertSame('submitted', (string) $row['status']);
        // Data se z dokladu netěží automaticky — fronta čeká na účetní.
        self::assertSame('not_started', (string) $row['extraction_status']);
        self::assertNull($row['purchase_invoice_id']);
    }

    public function testAttachmentWithoutOurIdentityIsRejectedAndLogged(): void
    {
        $summary = $this->ingest($this->pdf('FAKTURA - DANOVY DOKLAD c. 7 Odberatel ICO: 11223344 Ciziho Firma'));

        self::assertSame(0, $summary['imported']);
        self::assertSame(1, $summary['skipped']);
        self::assertNull($this->submissionRow(), 'Nerozpoznaná příloha nesmí založit podání.');

        $log = $this->pdo->prepare('SELECT status, reason FROM bank_email_attachment_ingests WHERE supplier_id = ?');
        $log->execute([$this->supplierId]);
        $logged = $log->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($logged, 'Zamítnutí musí zůstat dohledatelné v logu.');
        self::assertSame('skipped_not_invoice', (string) $logged['status']);
        self::assertNotSame('', trim((string) $logged['reason']));
    }

    public function testNonPdfAttachmentIsRejected(): void
    {
        $summary = $this->ingest('Ahoj, faktura v příloze. IČO ' . $this->ic, 'faktura.txt');

        self::assertSame(1, $summary['rejected']);
        self::assertNull($this->submissionRow());
    }

    public function testDisabledAccountIngestsNothing(): void
    {
        $summary = $this->ingest(
            $this->pdf('FAKTURA c. 1 ICO: ' . $this->ic),
            'faktura.pdf',
            ingestEnabled: false,
        );

        self::assertFalse($summary['enabled']);
        self::assertSame(0, $summary['considered']);
        self::assertNull($this->submissionRow());
    }

    /**
     * @return array<string,mixed>
     */
    private function ingest(string $content, string $filename = 'faktura.pdf', bool $ingestEnabled = true): array
    {
        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<' . bin2hex(random_bytes(8)) . '@example.invalid>',
            date: new \DateTimeImmutable('2026-01-15 10:00:00'),
            sender: 'dodavatel@example.invalid',
            subject: 'Faktura v příloze',
            text: 'Dobrý den, v příloze posíláme fakturu.',
            raw: '',
            attachments: [new EmailAttachment($filename, 'application/pdf', $content)],
        );

        return $this->ingestor->ingestFromMessage(
            $this->supplierId,
            ['id' => null, 'ingest_pdf_invoices' => $ingestEnabled],
            $message,
        );
    }

    /**
     * Minimální PDF 1.4 s jednou stránkou a textovou vrstvou — smalot/pdfparser
     * z něj musí umět přečíst `$text`. Generuje se ručně, ať test nezávisí na
     * externím PDF ani na generátoru faktur.
     */
    private function pdf(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 12 Tf 40 750 Td (" . $escaped . ") Tj ET";

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
                . "/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n"
            . $xrefPos . "\n%%EOF\n";

        return $pdf;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function submissionRow(): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM purchase_invoice_submissions WHERE supplier_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$this->supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
