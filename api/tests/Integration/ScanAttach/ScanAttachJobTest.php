<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\ScanAttach;

use MyInvoice\Action\Document\ScanAttachAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentExtractionRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\ScanBatchRepository;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Document\ScanAttach\ScanAttachJobService;
use MyInvoice\Service\Document\ScanAttach\ScanBatchService;
use MyInvoice\Service\Document\ScanAttach\ScanExtractionService;
use MyInvoice\Service\Document\ScanAttach\ScanMatcher;
use MyInvoice\Service\Document\ScanAttach\ScanSourceFile;
use MyInvoice\Service\Document\ScanAttach\ScanSourceInterface;
use MyInvoice\Service\Document\ScanAttach\ScanTargetRegistry;
use MyInvoice\Service\Import\ImageToPdfConverter;
use MyInvoice\Service\Import\LlmGatewayInterface;
use MyInvoice\Tests\Support\FakeLlmGateway;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Dávka skenů end-to-end nad testovací DB: syntetické skeny (PDF), syntetické
 * přijaté faktury a AI brána bez sítě. Ověřuje obě kola párování, deduplikaci,
 * uložení vytěžení, navázání po opakovaném běhu a izolaci firem.
 */
#[Group('integration')]
final class ScanAttachJobTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private \Psr\Container\ContainerInterface $c;
    private int $sid = 0;
    private int $otherSid = 0;
    private int $userId = 0;
    private string $ownIco = '';
    private string $vendorIco = '';
    private int $vendorId = 0;
    private int $currencyId = 0;
    private string $tmp = '';
    private int $jobId = 0;
    /** @var array<string,int> */
    private array $pi = [];
    private int $otherClientId = 0;
    private FakeLlmGateway $llm;

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
        $this->sid = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->otherSid = (int) ($this->pdo->query("SELECT id FROM supplier WHERE company_name = 'Druhá testovací firma s.r.o.' LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->ownIco = (string) ($this->pdo->query("SELECT ic FROM supplier WHERE id = {$this->sid}")->fetchColumn() ?: '');
        $vendor = $this->pdo->query(
            "SELECT id, ic FROM clients WHERE supplier_id = {$this->sid} AND is_vendor = 1 AND ic IS NOT NULL AND ic <> '' ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $this->currencyId = (int) ($this->pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->sid} AND code = 'CZK' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->sid === 0 || $this->otherSid === 0 || $this->userId === 0 || $this->ownIco === '' || $vendor === false || $this->currencyId === 0) {
            self::markTestSkipped('Chybí firma, druhá firma, uživatel, IČO, dodavatel nebo CZK.');
        }
        $this->vendorId = (int) $vendor['id'];
        $this->vendorIco = (string) $vendor['ic'];

        $this->tmp = sys_get_temp_dir() . '/scan-attach-it-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);

        // Syntetické přijaté faktury v roce 2090/2091 — mimo roky ostatních fixture.
        $this->pi['barcode'] = $this->purchase($this->sid, $this->vendorId, $this->currencyId, 'SCANTEST-A', 1210.0, '2091-03-10', '9100000001');
        $this->pi['content'] = $this->purchase($this->sid, $this->vendorId, $this->currencyId, 'SCANTEST-B', 2904.0, '2091-04-10', null);
        $this->pi['older'] = $this->purchase($this->sid, $this->vendorId, $this->currencyId, 'SCANTEST-C', 2904.0, '2090-04-10', null);
        $this->pi['proposal'] = $this->purchase($this->sid, $this->vendorId, $this->currencyId, 'SCANTEST-D', 5555.0, '2091-05-01', null);

        // Druhá firma má fakturu se STEJNÝM čárovým kódem jako sken cizí firmy v dávce.
        $otherCurrency = (int) ($this->pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->otherSid} AND code = 'CZK' LIMIT 1")->fetchColumn() ?: $this->currencyId);
        $country = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, ic, main_email, is_customer, is_vendor)
             VALUES (?, 'Syntetický dodavatel druhé firmy s.r.o.', 'Testovací 3', 'Brno', '60200', ?, ?, '11223344', 'dodavatel2@example.test', 0, 1)"
        )->execute([$this->otherSid, $country, $otherCurrency]);
        $this->otherClientId = (int) $this->pdo->lastInsertId();
        $this->pi['other'] = $this->purchase($this->otherSid, $this->otherClientId, $otherCurrency, 'SCANTEST-E', 1210.0, '2091-03-10', '9100000002');

        $this->llm = new FakeLlmGateway(fn (string $bytes): ?array => $this->extractionFor($bytes));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $docIds = [];
        if ($this->jobId > 0) {
            $docIds = array_map('intval', $this->pdo->query("SELECT document_id FROM scan_batch_items WHERE job_id = {$this->jobId} AND document_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
            $this->pdo->exec("DELETE FROM scan_matches WHERE job_id = {$this->jobId}");
            $this->pdo->exec("DELETE FROM scan_batch_items WHERE job_id = {$this->jobId}");
            $this->pdo->exec("DELETE FROM import_jobs WHERE id = {$this->jobId}");
        }
        if ($docIds !== []) {
            $in = implode(',', $docIds);
            $shas = $this->pdo->query("SELECT sha256 FROM documents WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            $this->pdo->exec("DELETE FROM document_links WHERE document_id IN ($in)");
            $this->pdo->exec("DELETE FROM document_files WHERE document_id IN ($in)");
            $this->pdo->exec("DELETE FROM documents WHERE id IN ($in)");
            foreach ($shas as $sha) {
                $this->pdo->prepare('DELETE FROM document_extractions WHERE supplier_id = ? AND sha256 = ?')->execute([$this->sid, $sha]);
            }
        }
        foreach ($this->pi as $id) {
            $this->pdo->exec("DELETE FROM purchase_invoices WHERE id = {$id}");
        }
        if ($this->otherClientId > 0) {
            $this->pdo->exec("DELETE FROM clients WHERE id = {$this->otherClientId}");
        }
        $this->pdo->exec("DELETE FROM document_folders WHERE supplier_id = {$this->sid} AND name = 'Dávka {$this->jobId}'");
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        $this->db->close();
    }

    public function testBatchAttachesScansInTwoRoundsAndIsIdempotent(): void
    {
        $this->jobId = $this->jobs()->create($this->sid, 'scan_attach', [
            'mode' => 'files', 'targets' => ['purchase_invoice'],
            'date_from' => '2090-01-01', 'date_to' => '2091-12-31',
        ], $this->userId);
        $source = $this->source([
            '9100000001.pdf' => $this->pdf('A'),       // čárový kód → faktura A
            'sken-b.pdf' => $this->pdf('B'),           // obsah → faktura B (ne starší C se stejnou částkou)
            'sken-b-kopie.pdf' => $this->pdf('B'),     // stejný obsah → v dávce jen jednou
            '9100000002.pdf' => $this->pdf('FOREIGN'), // kód faktury DRUHÉ firmy, odběratel je cizí
            'naklad.pdf' => $this->pdf('ORPHAN'),      // sken firmy, doklad v systému není
            'uctenka.pdf' => $this->pdf('LIKELY'),     // jen pravděpodobná shoda s D → k potvrzení
        ]);

        $service = $this->jobService();
        $service->run($this->jobId, $source);

        $job = $this->jobs()->find($this->jobId, $this->sid);
        self::assertSame('completed', $job['status'], (string) ($job['last_error'] ?? $job['log_text'] ?? ''));

        $links = $this->links();
        self::assertArrayHasKey($this->pi['barcode'], $links, 'faktura A dostala sken podle čárového kódu');
        self::assertArrayHasKey($this->pi['content'], $links, 'faktura B dostala sken podle obsahu');
        self::assertArrayNotHasKey($this->pi['older'], $links, 'starší faktura se stejnou částkou sken nedostala');
        self::assertArrayNotHasKey($this->pi['proposal'], $links, 'pravděpodobná shoda se sama nepřipojí');
        self::assertArrayNotHasKey($this->pi['other'], $links, 'faktura druhé firmy se nepáruje');

        $pdfPath = $this->pdo->query("SELECT pdf_path FROM purchase_invoices WHERE id = {$this->pi['barcode']}")->fetchColumn();
        self::assertNotEmpty($pdfPath, 'první sken jde do PDF slotu přijaté faktury');

        $outcomes = $this->outcomes();
        self::assertCount(5, $outcomes, 'duplicitní obsah se v dávce uloží jen jednou');
        self::assertSame('attached', $outcomes['9100000001.pdf']);
        self::assertSame('attached', $outcomes['sken-b.pdf']);
        self::assertSame('foreign', $outcomes['9100000002.pdf']);
        self::assertSame('orphan', $outcomes['naklad.pdf']);
        self::assertSame('proposed', $outcomes['uctenka.pdf']);
        self::assertSame([LlmGatewayInterface::TENANT_ROLE_ANY], array_values(array_unique($this->llm->roles)));

        // Vytěžení je uložené a dotazovatelné podle dokladu i podle dokumentu.
        $extractions = $this->c->get(DocumentExtractionRepository::class);
        $forInvoice = $extractions->listForEntity($this->sid, 'purchase_invoice', $this->pi['content']);
        self::assertCount(1, $forInvoice);
        self::assertSame(2904.0, $forInvoice[0]['total_with_vat']);
        self::assertSame('2091-04-10', $forInvoice[0]['issue_date']);
        self::assertSame('fake-model', $forInvoice[0]['model']);
        self::assertSame($forInvoice[0]['id'], $extractions->findForDocument($this->sid, (int) $forInvoice[0]['document_id'])['id']);
        self::assertSame([], $extractions->listForEntity($this->otherSid, 'purchase_invoice', $this->pi['content']), 'cizí firma vytěžení nevidí');

        $text = (string) $this->pdo->query('SELECT content_text FROM documents WHERE id = ' . (int) $forInvoice[0]['document_id'])->fetchColumn();
        self::assertStringContainsString('[vytěženo ze skenu]', $text);

        // Přehled dávky.
        $overview = $this->c->get(ScanBatchService::class)->overview($this->sid, $job);
        self::assertSame(2, $overview['counts']['attached']);
        self::assertSame(1, $overview['counts']['candidates']);
        self::assertSame(1, $overview['counts']['orphans']);
        $missingIds = array_column(array_filter($overview['missing'], static fn (array $r): bool => $r['target_type'] === 'purchase_invoice'), 'id');
        self::assertContains($this->pi['older'], $missingIds);
        self::assertContains($this->pi['proposal'], $missingIds);
        self::assertNotContains($this->pi['barcode'], $missingIds);
        self::assertTrue($overview['discrepancies']['available']);
        self::assertSame(0, $overview['counts']['discrepancies'], 'připojené skeny sedí na doklady');
        self::assertSame(
            'match',
            $this->pdo->query("SELECT status FROM attachment_checks WHERE entity_type = 'purchase_invoice' AND entity_id = {$this->pi['content']}")->fetchColumn(),
            'připojený sken se hned porovnal s dokladem',
        );

        // Opakovaný běh (navázání po pádu): nic se nezdvojí a nic se znovu nevytěžuje.
        $calls = $this->llm->invoiceCalls;
        $docs = (int) $this->pdo->query("SELECT COUNT(*) FROM documents WHERE supplier_id = {$this->sid} AND deleted_at IS NULL")->fetchColumn();
        self::assertTrue($this->c->get(ScanBatchRepository::class)->requeue($this->sid, $this->jobId));
        $service->run($this->jobId, $source);
        self::assertSame($calls, $this->llm->invoiceCalls, 'uložené vytěžení se znovu nevolá');
        self::assertSame($docs, (int) $this->pdo->query("SELECT COUNT(*) FROM documents WHERE supplier_id = {$this->sid} AND deleted_at IS NULL")->fetchColumn());
        self::assertCount(5, $this->outcomes());
        self::assertSame('completed', $this->jobs()->find($this->jobId, $this->sid)['status']);
    }

    public function testConfirmAttachesProposalAndOtherCompanyCannotSeeBatch(): void
    {
        $this->jobId = $this->jobs()->create($this->sid, 'scan_attach', [
            'mode' => 'files', 'targets' => ['purchase_invoice'],
            'date_from' => '2090-01-01', 'date_to' => '2091-12-31',
        ], $this->userId);
        $this->jobService()->run($this->jobId, $this->source(['uctenka.pdf' => $this->pdf('LIKELY')]));

        $proposed = $this->c->get(ScanBatchRepository::class)->listMatches($this->sid, $this->jobId, ['proposed']);
        self::assertCount(1, $proposed);
        self::assertSame($this->pi['proposal'], $proposed[0]['target_id']);
        $matchId = (int) $proposed[0]['id'];

        // Druhá firma dávku ani návrh nevidí.
        $action = $this->c->get(ScanAttachAction::class);
        $foreignShow = $action->show($this->request($this->otherSid), new Psr7Response(), ['id' => $this->jobId]);
        self::assertSame(404, $foreignShow->getStatusCode());
        $foreignConfirm = $action->confirm($this->request($this->otherSid), new Psr7Response(), ['id' => $matchId]);
        self::assertSame(404, $foreignConfirm->getStatusCode());
        self::assertArrayNotHasKey($this->pi['proposal'], $this->links());

        $ownShow = $action->show($this->request($this->sid), new Psr7Response(), ['id' => $this->jobId]);
        self::assertSame(200, $ownShow->getStatusCode());

        $res = $this->c->get(ScanBatchService::class)->confirm($this->sid, $matchId, $this->userId);
        self::assertTrue($res['ok']);
        self::assertArrayHasKey($this->pi['proposal'], $this->links());
        self::assertSame('attached', $this->outcomes()['uctenka.pdf']);
        self::assertFalse($this->c->get(ScanBatchService::class)->confirm($this->sid, $matchId, $this->userId)['ok'], 'rozhodnutý návrh nejde potvrdit znovu');
    }

    private function jobService(): ScanAttachJobService
    {
        $extraction = new ScanExtractionService(
            $this->llm,
            $this->c->get(DocumentExtractionRepository::class),
            $this->c->get(DocumentRepository::class),
            $this->c->get(ImageToPdfConverter::class),
            $this->db,
        );
        return new ScanAttachJobService(
            $this->jobs(),
            $this->c->get(ScanBatchRepository::class),
            $this->c->get(DocumentIngestService::class),
            $this->c->get(DocumentStorage::class),
            $extraction,
            $this->c->get(DocumentExtractionRepository::class),
            new ScanMatcher(),
            $this->c->get(ScanTargetRegistry::class),
            $this->c->get(ScanBatchService::class),
            $this->c->get(\MyInvoice\Repository\PaymentCardRepository::class),
        );
    }

    private function jobs(): ImportJobRepository
    {
        return $this->c->get(ImportJobRepository::class);
    }

    /** @return array<string,mixed>|null */
    private function extractionFor(string $bytes): ?array
    {
        if (preg_match('/SYNTHETIC-SCAN-(\w+)/', $bytes, $m) !== 1) {
            return null;
        }
        $received = fn (float $total, string $date, ?string $buyerIco = null): array => [
            'vendor' => ['company_name' => 'Syntetický dodavatel s.r.o.', 'ic' => $this->vendorIco, 'dic' => null],
            'customer' => ['company_name' => 'Syntetický odběratel', 'ic' => $buyerIco ?? $this->ownIco, 'dic' => null],
            'vendor_invoice_number' => null, 'varsymbol' => null, 'document_kind' => 'invoice',
            'issue_date' => $date, 'tax_date' => $date, 'total_with_vat' => $total, 'currency' => 'CZK',
            'barcode' => null, 'license_plate' => null, 'card_last4' => null, 'company_role' => 'buyer',
        ];
        return match ($m[1]) {
            'A' => $received(1210.0, '2091-03-10'),
            'B' => $received(2904.0, '2091-04-10'),
            'FOREIGN' => $received(1210.0, '2091-03-10', '99887766'),
            'ORPHAN' => $received(777.0, '2091-06-01'),
            'LIKELY' => $received(5555.0, '2091-08-20'),
            default => null,
        };
    }

    private function pdf(string $marker): string
    {
        return "%PDF-1.4\n% SYNTHETIC-SCAN-{$marker}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /** @param array<string,string> $files */
    private function source(array $files): ScanSourceInterface
    {
        return new class ($this->tmp, $files) implements ScanSourceInterface {
            /** @param array<string,string> $files */
            public function __construct(private readonly string $dir, private readonly array $files) {}

            public function count(): ?int
            {
                return count($this->files);
            }

            public function files(): iterable
            {
                foreach ($this->files as $name => $bytes) {
                    $path = $this->dir . '/src-' . bin2hex(random_bytes(6));
                    file_put_contents($path, $bytes);
                    yield new ScanSourceFile((string) $name, $path, strlen($bytes));
                }
            }

            public function cleanup(): void {}
        };
    }

    private function purchase(int $sid, int $vendorId, int $currencyId, string $number, float $total, string $date, ?string $barcode): int
    {
        $this->pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date, received_at,
                 currency_id, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status, created_by, external_barcode)
             VALUES (?, ?, ?, 'invoice', ?, ?, ?, ?, ?, '{}', ?, 0, ?, 'received', ?, ?)"
        )->execute([$sid, $vendorId, $number, $date, $date, $date, $date, $currencyId, $total, $total, $this->userId, $barcode]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int,true> přijaté faktury s připojeným dokumentem */
    private function links(): array
    {
        $ids = implode(',', array_values($this->pi));
        $rows = $this->pdo->query(
            "SELECT DISTINCT entity_id FROM document_links WHERE entity_type = 'purchase_invoice' AND entity_id IN ($ids)"
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys(array_map('intval', $rows), true);
    }

    /** @return array<string,string> název souboru → výsledek */
    private function outcomes(): array
    {
        $out = [];
        foreach ($this->pdo->query("SELECT file_name, outcome FROM scan_batch_items WHERE job_id = {$this->jobId}")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['file_name']] = (string) $r['outcome'];
        }
        return $out;
    }

    private function request(int $supplierId): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/scan-attach/batches')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }
}
