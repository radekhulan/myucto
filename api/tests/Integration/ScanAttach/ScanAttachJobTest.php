<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\ScanAttach;

use MyInvoice\Action\Document\ScanAttachAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
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
use MyInvoice\Service\Document\ScanAttach\ScanStagingCleaner;
use MyInvoice\Service\Document\ScanAttach\ScanTargetRegistry;
use MyInvoice\Service\Document\ScanAttach\UploadedScanSource;
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
    /** @var list<int> další dávky testu (souběh, navazující dávka) */
    private array $extraJobs = [];
    private ?string $restoreMode = null;
    private int $periodId = 0;
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
        $jobIds = array_values(array_filter([$this->jobId, ...$this->extraJobs]));
        foreach ($jobIds as $jobId) {
            $docIds = [...$docIds, ...array_map('intval', $this->pdo->query("SELECT document_id FROM scan_batch_items WHERE job_id = {$jobId} AND document_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN))];
            $this->pdo->exec("DELETE FROM scan_matches WHERE job_id = {$jobId}");
            $this->pdo->exec("DELETE FROM scan_batch_items WHERE job_id = {$jobId}");
            $this->pdo->exec("DELETE FROM import_jobs WHERE id = {$jobId}");
            (new UploadedScanSource(ScanAttachJobService::stagingDir($this->sid, $jobId)))->cleanup();
            $this->pdo->exec("DELETE FROM document_folders WHERE supplier_id = {$this->sid} AND name = 'Dávka {$jobId}'");
        }
        $docIds = array_values(array_unique($docIds));
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
        if ($this->periodId > 0) {
            $this->pdo->exec("DELETE FROM accounting_periods WHERE id = {$this->periodId}");
        }
        if ($this->restoreMode !== null) {
            $this->pdo->prepare('UPDATE supplier SET accounting_mode = ? WHERE id = ?')->execute([$this->restoreMode, $this->sid]);
        }
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

    /**
     * „Spustit znovu" u dokončené dávky nahrané přes UI: nahrané soubory jsou po
     * dokončení uklizené, opakovaný běh musí navázat nad Dokumenty, ne spadnout
     * na prázdném stagingu. Zdroj je skutečný upload (manifest + části).
     */
    public function testResumeOfCompletedUploadedBatchWorksAfterStagingCleanup(): void
    {
        $this->jobId = $this->jobs()->create($this->sid, 'scan_attach', [
            'mode' => 'files', 'targets' => ['purchase_invoice'],
            'date_from' => '2090-01-01', 'date_to' => '2091-12-31',
        ], $this->userId);
        $this->stage($this->jobId, [
            '9100000001.pdf' => $this->pdf('A'),
            'sken-b.pdf' => $this->pdf('B'),
        ]);
        $staging = ScanAttachJobService::stagingDir($this->sid, $this->jobId);

        $service = $this->jobService();
        $service->run($this->jobId);
        $job = $this->jobs()->find($this->jobId, $this->sid);
        self::assertSame('completed', $job['status'], (string) ($job['last_error'] ?? $job['log_text'] ?? ''));
        self::assertDirectoryDoesNotExist($staging, 'po dokončení se nahrané soubory uklidí');
        self::assertArrayHasKey($this->pi['barcode'], $this->links());
        self::assertArrayHasKey($this->pi['content'], $this->links());

        self::assertTrue($this->c->get(ScanBatchRepository::class)->requeue($this->sid, $this->jobId));
        $service->run($this->jobId);

        $job = $this->jobs()->find($this->jobId, $this->sid);
        self::assertSame('completed', $job['status'], 'navázání dokončené dávky nesmí selhat: ' . ($job['last_error'] ?? ''));
        $outcomes = $this->outcomes();
        ksort($outcomes);
        self::assertSame(['9100000001.pdf' => 'attached', 'sken-b.pdf' => 'attached'], $outcomes);
    }

    /** Soubor, který se napoprvé nepodařilo uložit, se při opakovaném běhu uloží a spáruje. */
    public function testFileThatFailedToStoreIsRetriedOnResume(): void
    {
        $this->jobId = $this->jobs()->create($this->sid, 'scan_attach', [
            'mode' => 'files', 'targets' => ['purchase_invoice'],
            'date_from' => '2090-01-01', 'date_to' => '2091-12-31',
        ], $this->userId);
        $service = $this->jobService();

        // Obsah skenu je v pořádku, jen název s blokovanou příponou uložení odmítne.
        $service->run($this->jobId, $this->source(['sken-b.exe' => $this->pdf('B')]));
        self::assertSame(['sken-b.exe' => 'unreadable'], $this->outcomes());
        self::assertArrayNotHasKey($this->pi['content'], $this->links());

        self::assertTrue($this->c->get(ScanBatchRepository::class)->requeue($this->sid, $this->jobId));
        $service->run($this->jobId, $this->source(['sken-b.pdf' => $this->pdf('B')]));

        self::assertSame(['sken-b.exe' => 'attached'], $this->outcomes(), 'uložení se zopakovalo a sken se spároval');
        self::assertArrayHasKey($this->pi['content'], $this->links());
        $unstored = (int) $this->pdo->query("SELECT COUNT(*) FROM scan_batch_items WHERE job_id = {$this->jobId} AND document_id IS NULL")->fetchColumn();
        self::assertSame(0, $unstored);
    }

    /**
     * Nahraje soubory do stagingu dávky stejně jako chunkovaný upload z UI.
     *
     * @param array<string,string> $files
     */
    private function stage(int $jobId, array $files): void
    {
        $dir = ScanAttachJobService::stagingDir($this->sid, $jobId);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach ($files as $name => $bytes) {
            $part = $dir . '/p' . bin2hex(random_bytes(8));
            file_put_contents($part, $bytes);
            file_put_contents($dir . '/manifest.jsonl', json_encode(['f' => basename($part), 'n' => $name], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        }
    }

    /**
     * Dvě dávky téže firmy nesmí běžet souběžně (deduplikace obsahu a kontrola
     * „doklad už sken má" jsou check-then-insert). Dávka, která zámek firmy
     * nezíská, nic neuloží a skončí s výzvou spustit ji znovu.
     */
    public function testBatchDoesNotRunWhileAnotherBatchOfCompanyHoldsTheLock(): void
    {
        $this->jobId = $this->jobs()->create($this->sid, 'scan_attach', [
            'mode' => 'files', 'targets' => ['purchase_invoice'],
            'date_from' => '2090-01-01', 'date_to' => '2091-12-31',
        ], $this->userId);
        $holder = Connection::withoutSharedTestConnection(static fn (): PDO => Bootstrap::buildContainer()->get(Connection::class)->pdo());
        $name = NamedLockName::for($this->db, 'scan_attach_batch', $this->sid);
        $got = $holder->prepare('SELECT GET_LOCK(?, 0)');
        $got->execute([$name]);
        self::assertSame(1, (int) $got->fetchColumn(), 'druhá session drží zámek firmy');
        try {
            $this->jobService(0)->run($this->jobId, $this->source(['sken-b.pdf' => $this->pdf('B')]));
            $job = $this->jobs()->find($this->jobId, $this->sid);
            self::assertSame('failed', $job['status'], 'dávka nesmí běžet souběžně s jinou dávkou firmy');
            self::assertSame([], $this->outcomes(), 'nic se neuložilo');
            self::assertArrayNotHasKey($this->pi['content'], $this->links());
        } finally {
            $holder->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }

        self::assertTrue($this->c->get(ScanBatchRepository::class)->requeue($this->sid, $this->jobId));
        $this->jobService(0)->run($this->jobId, $this->source(['sken-b.pdf' => $this->pdf('B')]));
        self::assertSame('completed', $this->jobs()->find($this->jobId, $this->sid)['status']);
        self::assertArrayHasKey($this->pi['content'], $this->links());
    }

    /**
     * Doklad, který dostal sken v dřívější dávce, nedostane v další dávce podle
     * obsahu druhý (jiný soubor se stejně vytěženým obsahem).
     */
    public function testDocumentWithScanFromEarlierBatchGetsNoSecondScanByContent(): void
    {
        $params = ['mode' => 'files', 'targets' => ['purchase_invoice'], 'date_from' => '2090-01-01', 'date_to' => '2091-12-31'];
        $this->jobId = $this->jobs()->create($this->sid, 'scan_attach', $params, $this->userId);
        $service = $this->jobService();
        $service->run($this->jobId, $this->source(['sken-b.pdf' => $this->pdf('B')]));
        self::assertSame(1, $this->linkCount($this->pi['content']));

        $second = $this->jobs()->create($this->sid, 'scan_attach', $params, $this->userId);
        $this->extraJobs[] = $second;
        $service->run($second, $this->source(['sken-b-znovu.pdf' => $this->pdf('B2')]));

        self::assertSame('completed', $this->jobs()->find($second, $this->sid)['status']);
        self::assertSame(1, $this->linkCount($this->pi['content']), 'doklad už sken z první dávky má');
        $outcome = (string) $this->pdo->query("SELECT outcome FROM scan_batch_items WHERE job_id = {$second}")->fetchColumn();
        self::assertNotSame('attached', $outcome);
    }

    /** Celé číslo karty z odpovědi modelu se neuloží ani do úplné odpovědi (payload). */
    public function testFullCardNumberFromModelIsNotStored(): void
    {
        $this->jobId = $this->jobs()->create($this->sid, 'scan_attach', [
            'mode' => 'files', 'targets' => ['purchase_invoice'],
            'date_from' => '2090-01-01', 'date_to' => '2091-12-31',
        ], $this->userId);
        $this->jobService()->run($this->jobId, $this->source(['uctenka-karta.pdf' => $this->pdf('CARD')]));

        $row = $this->pdo->query(
            "SELECT de.card_last4, de.payload FROM document_extractions de
               JOIN scan_batch_items i ON i.sha256 = de.sha256 AND i.supplier_id = de.supplier_id
              WHERE i.job_id = {$this->jobId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('1111', $row['card_last4']);
        self::assertStringNotContainsString('4111 1111 1111 1111', (string) $row['payload']);
        self::assertStringNotContainsString('4111111111111111', (string) $row['payload']);
    }

    /**
     * Doklad v uzavřeném období: sken se připojí jako vazba v Dokumentech, ale PDF
     * slot dokladu zůstane beze změny — stejně jako při ručním nahrání PDF.
     */
    public function testScanDoesNotFillPdfSlotOfInvoiceInClosedPeriod(): void
    {
        $this->restoreMode = (string) $this->pdo->query("SELECT accounting_mode FROM supplier WHERE id = {$this->sid}")->fetchColumn();
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->sid]);
        $this->pdo->prepare(
            "INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, 2091, '2091-01-01', '2091-12-31', 'closed')"
        )->execute([$this->sid]);
        $this->periodId = (int) $this->pdo->lastInsertId();

        $this->jobId = $this->jobs()->create($this->sid, 'scan_attach', [
            'mode' => 'files', 'targets' => ['purchase_invoice'],
            'date_from' => '2090-01-01', 'date_to' => '2091-12-31',
        ], $this->userId);
        $this->jobService()->run($this->jobId, $this->source(['9100000001.pdf' => $this->pdf('A')]));

        self::assertArrayHasKey($this->pi['barcode'], $this->links(), 'vazba v Dokumentech vznikne');
        $pdfPath = (string) $this->pdo->query("SELECT pdf_path FROM purchase_invoices WHERE id = {$this->pi['barcode']}")->fetchColumn();
        self::assertSame('', $pdfPath, 'PDF slot dokladu v uzavřeném období zůstane prázdný');
    }

    /** Firma nemůže založit neomezeně rozpracovaných dávek (každá až 2 GB na disku). */
    public function testCompanyCannotQueueUnlimitedBatches(): void
    {
        $before = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM import_jobs')->fetchColumn();
        for ($i = 0; $i < 3; $i++) {
            $this->extraJobs[] = $this->jobs()->create($this->sid, 'scan_attach', ['mode' => 'files'], $this->userId);
        }

        $res = $this->c->get(ScanAttachAction::class)->start(
            $this->request($this->sid)->withMethod('POST')->withParsedBody(['mode' => 'files', 'targets' => ['purchase_invoice']]),
            new Psr7Response(),
        );
        foreach ($this->pdo->query("SELECT id FROM import_jobs WHERE id > {$before} AND source = 'scan_attach'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (!in_array((int) $id, $this->extraJobs, true)) {
                $this->extraJobs[] = (int) $id;
            }
        }

        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('too_many_batches', json_decode((string) $res->getBody(), true)['error']['code'] ?? null);
    }

    /** Režim jednotlivých souborů má strop celkové velikosti dávky, ne jen počtu souborů. */
    public function testFilesModeHasTotalSizeCap(): void
    {
        $this->jobId = $this->uploadingBatch();
        $dir = ScanAttachJobService::stagingDir($this->sid, $this->jobId);
        file_put_contents($dir . '/manifest.jsonl', json_encode(['f' => 'pzzz', 'n' => 'velky.pdf', 's' => 2 * 1024 * 1024 * 1024 - 10]) . "\n");

        $res = $this->c->get(ScanAttachAction::class)->chunkFiles(
            $this->uploadRequest('dalsi.pdf', $this->pdf('B')), new Psr7Response(), ['id' => $this->jobId],
        );

        self::assertSame(413, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('batch_too_large', json_decode((string) $res->getBody(), true)['error']['code'] ?? null);
        self::assertCount(1, file($dir . '/manifest.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }

    /** Soubor nad strop jednoho souboru se neuloží na disk, v dávce zůstane jako chyba. */
    public function testOversizedFileIsRecordedAsErrorWithoutStoring(): void
    {
        $this->jobId = $this->uploadingBatch();
        $dir = ScanAttachJobService::stagingDir($this->sid, $this->jobId);

        $res = $this->c->get(ScanAttachAction::class)->chunkFiles(
            $this->uploadRequest('obri-sken.pdf', $this->pdf('B'), UploadedScanSource::MAX_FILE_BYTES + 1), new Psr7Response(), ['id' => $this->jobId],
        );

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $entry = json_decode((string) file_get_contents($dir . '/manifest.jsonl'), true);
        self::assertSame('too_large', $entry['e'] ?? null);
        self::assertSame([], glob($dir . '/p*') ?: [], 'soubor se na disk neuložil');
    }

    /** Nahrávání po částech je známka života — úklid neaktivních úloh dávku neukončí. */
    public function testChunkUploadKeepsBatchAlive(): void
    {
        $this->jobId = $this->uploadingBatch();
        $this->pdo->exec("UPDATE import_jobs SET updated_at = NOW() - INTERVAL 20 MINUTE WHERE id = {$this->jobId}");

        $res = $this->c->get(ScanAttachAction::class)->chunkFiles(
            $this->uploadRequest('sken.pdf', $this->pdf('B')), new Psr7Response(), ['id' => $this->jobId],
        );
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $this->jobs()->reapStale($this->sid, 'scan_attach');
        self::assertSame('queued', $this->jobs()->find($this->jobId, $this->sid)['status']);
    }

    /** Úklid nahraných souborů: opuštěné a dávno skončené dávky ano, rozpracovanou ne. */
    public function testStagingCleanerRemovesOnlyAbandonedOrFinishedBatches(): void
    {
        $fresh = $this->jobs()->create($this->sid, 'scan_attach', ['mode' => 'files'], $this->userId);
        $done = $this->jobs()->create($this->sid, 'scan_attach', ['mode' => 'files'], $this->userId);
        $abandoned = $this->jobs()->create($this->sid, 'scan_attach', ['mode' => 'files'], $this->userId);
        $this->extraJobs = [...$this->extraJobs, $fresh, $done, $abandoned];
        foreach ([$fresh, $done, $abandoned] as $id) {
            $this->stage($id, ['sken.pdf' => $this->pdf('B')]);
        }
        $this->pdo->exec("UPDATE import_jobs SET status = 'completed', finished_at = NOW() - INTERVAL 8 DAY, updated_at = NOW() - INTERVAL 8 DAY WHERE id = {$done}");
        $this->pdo->exec("UPDATE import_jobs SET updated_at = NOW() - INTERVAL 3 DAY WHERE id = {$abandoned}");
        $orphanJob = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM import_jobs')->fetchColumn();
        $this->stage($orphanJob, ['sken.pdf' => $this->pdf('B')]);

        (new ScanStagingCleaner($this->db))->purge($this->sid);

        self::assertDirectoryExists(ScanAttachJobService::stagingDir($this->sid, $fresh), 'rozpracovaná dávka zůstává');
        self::assertDirectoryDoesNotExist(ScanAttachJobService::stagingDir($this->sid, $done));
        self::assertDirectoryDoesNotExist(ScanAttachJobService::stagingDir($this->sid, $abandoned));
        self::assertDirectoryDoesNotExist(ScanAttachJobService::stagingDir($this->sid, $orphanJob), 'soubory dávky bez záznamu');
    }

    /** Dávky, vytěžení i kontrola příloh nesou údaje protistran — při smazání firmy zmizí s ní. */
    public function testScanTablesCascadeWithSupplier(): void
    {
        $rules = [];
        foreach ($this->pdo->query(
            "SELECT TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'supplier'
                AND TABLE_NAME IN ('scan_batch_items', 'scan_matches', 'document_extractions', 'attachment_checks')"
        )->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rules[(string) $r['TABLE_NAME']] = (string) $r['DELETE_RULE'];
        }
        ksort($rules);
        self::assertSame([
            'attachment_checks' => 'CASCADE',
            'document_extractions' => 'CASCADE',
            'scan_batch_items' => 'CASCADE',
            'scan_matches' => 'CASCADE',
        ], $rules);
    }

    /** Dávka ve stavu nahrávání (queued, režim souborů, staging existuje). */
    private function uploadingBatch(): int
    {
        $jobId = $this->jobs()->create($this->sid, 'scan_attach', ['mode' => 'files', 'targets' => ['purchase_invoice']], $this->userId);
        $dir = ScanAttachJobService::stagingDir($this->sid, $jobId);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $jobId;
    }

    private function uploadRequest(string $name, string $bytes, ?int $declaredSize = null): \Psr\Http\Message\ServerRequestInterface
    {
        $path = $this->tmp . '/up-' . bin2hex(random_bytes(6));
        file_put_contents($path, $bytes);
        return $this->request($this->sid)->withMethod('POST')->withUploadedFiles([
            'file' => [new \Slim\Psr7\UploadedFile($path, $name, 'application/pdf', $declaredSize ?? strlen($bytes))],
        ]);
    }

    private function linkCount(int $purchaseInvoiceId): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM document_links WHERE entity_type = 'purchase_invoice' AND entity_id = {$purchaseInvoiceId}"
        )->fetchColumn();
    }

    private function jobService(?int $lockWaitSeconds = null): ScanAttachJobService
    {
        $extra = $lockWaitSeconds !== null ? [$lockWaitSeconds] : [];
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
            ...$extra,
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
            'B', 'B2' => $received(2904.0, '2091-04-10'),
            // Model vrátil celé (veřejné testovací) číslo karty místo koncovky.
            'CARD' => ['card_last4' => '4111 1111 1111 1111', 'notes' => 'Zaplaceno kartou 4111111111111111']
                + $received(777.0, '2091-06-01'),
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

            public function hasFiles(): bool
            {
                return $this->files !== [];
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
