<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\MoneyS3BatchAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\MigrationBatchRepository;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchImporter;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchJobService;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchOptions;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchUploads;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\Shared\FiledDppoFiling;
use MyInvoice\Service\Migration\Shared\MigrationBatchActor;
use MyInvoice\Service\Migration\Shared\MigrationBatchRunner;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticTaxAgenda;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Dávkový převod více záloh Money S3 nad skutečnou DB: firmy podle IČO v záloze se
 * založí, rok „od" se zvolí podle navazujících stavů, pád jedné zálohy nezastaví
 * ostatní, opakovaný běh nic nezdvojí a zkouška nanečisto nezanechá ani firmu.
 * Vše v transakci s rollbackem; ARES ani registr plátců se nevolají.
 */
#[Group('integration')]
final class MoneyS3BatchImportTest extends TestCase
{
    private const ICO_A = '24681351';
    private const ICO_B = '13579240';
    private const LIVE = 'import';
    private const DRY = 'dry_run';

    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private MoneyS3BatchImporter $importer;
    private string $tmp = '';
    private int $userId = 0;
    private int $officeId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->importer = $this->container->get(MoneyS3BatchImporter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $existing = $pdo->prepare("SELECT COUNT(*) FROM supplier WHERE TRIM(LEADING '0' FROM ic) IN (?, ?)");
        $existing->execute([self::ICO_A, self::ICO_B]);
        if ((int) $existing->fetchColumn() > 0) {
            $this->markTestSkipped('Testovací DB už obsahuje firmu se syntetickým IČO dávky.');
        }

        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms3batch_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        SyntheticAgenda::writeLzFiles($this->tmp . '/alfa.lz', SyntheticAgenda::forCompany(SyntheticAgenda::files(), self::ICO_A, 'Dávková alfa s.r.o.'));
        SyntheticAgenda::writeLzFiles($this->tmp . '/beta.lz', SyntheticAgenda::forCompany(SyntheticAgenda::filesWithOpeningReclass(), self::ICO_B, 'Dávková beta s.r.o.', 'Vzorová 3', 'Jihlava', '586 01'));
        file_put_contents($this->tmp . '/rozbita.lz', 'tohle není záloha');

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES ("Účetní kancelář dávky", "Účetní 1", "Brno", "60200", ?, "kancelar@example.invalid", ?, ?)'
        )->execute([$czId, $currencyId, $vatRateId]);
        $this->officeId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->officeId > 0) {
            foreach (glob(MoneyS3BatchUploads::store()->base($this->officeId) . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                MoneyS3BatchUploads::store()->purge($this->officeId, basename($dir));
            }
            foreach (glob(MoneyS3BatchUploads::filingsDir($this->officeId) . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir(MoneyS3BatchUploads::filingsDir($this->officeId));
            @rmdir(MoneyS3BatchUploads::store()->base($this->officeId));
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            MoneyS3BatchImporter::removeTree($this->tmp);
        }
    }

    public function testBatchCreatesCompaniesAndOneBrokenBackupDoesNotStopOthers(): void
    {
        $options = $this->importer->prepareBatch($this->options(self::LIVE, ['group_name' => 'Skupina dávky', 'related_parties' => true]), [self::ICO_A, self::ICO_B]);
        self::assertNotNull($options->groupId, 'Ostrá dávka skupinu založí na začátku.');
        self::assertEqualsCanonicalizing([self::ICO_A, self::ICO_B], $options->relatedPartyIcos);

        $results = $this->runBatch(['alfa.lz', 'rozbita.lz', 'beta.lz'], $options);

        self::assertSame([MigrationBatchRunner::FAILED], [$results[1]['status']]);
        self::assertStringContainsString('ZIP', (string) $results[1]['error']);
        foreach ([0, 2] as $i) {
            self::assertContains($results[$i]['status'], [MigrationBatchRunner::COMPLETED, MigrationBatchRunner::COMPLETED_WITH_WARNINGS], json_encode($results[$i], JSON_UNESCAPED_UNICODE));
            self::assertSame('created', $results[$i]['company_action']);
            self::assertSame(['K1' => true, 'K2' => true, 'K3' => true, 'K4' => true], $results[$i]['summary']['criteria']['total']);
        }
        self::assertSame(MigrationBatchRunner::COMPLETED_WITH_WARNINGS, MigrationBatchRunner::batchStatus(array_column($results, 'status')));

        // Rok „od" automaticky: v agendě A roky navazují (vše), v B nenavazuje 2024 → 2025.
        self::assertNull($results[0]['from_year']);
        self::assertSame(2025, $results[2]['from_year']);
        self::assertSame([2025], $results[2]['summary']['years']);

        $a = $this->supplierRow(self::ICO_A);
        self::assertSame('Dávková alfa s.r.o.', $a['company_name']);
        self::assertSame('double_entry', $a['accounting_mode']);
        self::assertSame($options->groupId, (int) $a['supplier_group_id']);
        self::assertSame((int) $a['id'], $results[0]['target_supplier_id']);
        $b = $this->supplierRow(self::ICO_B);
        self::assertSame('Jihlava', $b['city']);
        self::assertGreaterThan(0, $this->rows('journal_entries', (int) $a['id']));
        self::assertSame(1, $this->rows('money_s3_imports', (int) $a['id']), 'Protokol převodu zůstává u firmy jako u průvodce jedné firmy.');
    }

    public function testRepeatedBatchUpdatesExistingCompaniesWithoutDuplicatesOrSkipsThem(): void
    {
        $first = $this->runBatch(['alfa.lz'], $this->options(self::LIVE));
        $supplierId = (int) $first[0]['target_supplier_id'];
        $entries = $this->rows('journal_entries', $supplierId);
        $invoices = $this->rows('purchase_invoices', $supplierId);

        $again = $this->runBatch(['alfa.lz'], $this->options(self::LIVE, ['existing' => MoneyS3BatchOptions::EXISTING_UPDATE]));
        self::assertSame('existing', $again[0]['company_action']);
        self::assertSame($supplierId, $again[0]['target_supplier_id']);
        self::assertContains($again[0]['status'], [MigrationBatchRunner::COMPLETED, MigrationBatchRunner::COMPLETED_WITH_WARNINGS]);
        self::assertSame($entries, $this->rows('journal_entries', $supplierId), 'Opakovaný převod nic nezdvojí.');
        self::assertSame($invoices, $this->rows('purchase_invoices', $supplierId));
        self::assertSame(1, $this->rows('supplier', null, "TRIM(LEADING '0' FROM ic) = '" . self::ICO_A . "'"), 'Druhá firma se stejným IČO nevznikne.');

        $skipped = $this->runBatch(['alfa.lz'], $this->options(self::LIVE));
        self::assertSame(MigrationBatchRunner::SKIPPED, $skipped[0]['status']);
        self::assertSame($supplierId, $skipped[0]['target_supplier_id']);
    }

    public function testDryRunLeavesNeitherCompanyNorRun(): void
    {
        $before = $this->rows('supplier', null);
        $results = $this->runBatch(['alfa.lz', 'beta.lz'], $this->options(self::DRY));

        foreach ($results as $r) {
            self::assertContains($r['status'], [MigrationBatchRunner::COMPLETED, MigrationBatchRunner::COMPLETED_WITH_WARNINGS], json_encode($r, JSON_UNESCAPED_UNICODE));
            self::assertNull($r['target_supplier_id']);
            self::assertNull($r['run_id']);
            self::assertSame('dry_run', $r['summary']['protocol']['mode'], 'Protokol zkoušky je jen v souhrnu dávky.');
        }
        self::assertSame($before, $this->rows('supplier', null));
        self::assertSame(0, $this->rows('money_s3_imports', null, "agenda_ico IN ('" . self::ICO_A . "', '" . self::ICO_B . "')"));
    }

    public function testCompanyOutsideUsersReachIsNotTouched(): void
    {
        $first = $this->runBatch(['alfa.lz'], $this->options(self::LIVE));
        $foreign = (int) $first[0]['target_supplier_id'];

        $actor = new MigrationBatchActor($this->userId, [$this->officeId], true, true);
        $results = $this->runBatch(['alfa.lz'], $this->options(self::LIVE, ['existing' => MoneyS3BatchOptions::EXISTING_UPDATE]), $actor);
        self::assertSame(MigrationBatchRunner::FAILED, $results[0]['status']);
        self::assertStringContainsString('nemáte k ní přístup', (string) $results[0]['error']);
        self::assertSame(1, $this->rows('money_s3_imports', $foreign));

        $noCreate = new MigrationBatchActor($this->userId, null, false, false);
        $results = $this->runBatch(['beta.lz'], $this->options(self::LIVE), $noCreate);
        self::assertSame(MigrationBatchRunner::FAILED, $results[0]['status']);
        self::assertStringContainsString('zakládat firmy nemáte', (string) $results[0]['error']);
    }

    /**
     * Agendu vedenou v Money jako daňová evidence převod nepřevede. Dávka ji proto odmítne
     * s vysvětlením dřív, než firmu založí - jinak by po ní zůstala prázdná firma.
     */
    /**
     * Agendu vedenou v Money jako daňová evidence dávka převede do nové firmy v daňové
     * evidenci: firmu do podvojného účetnictví nepřepne a deník nezaloží.
     */
    public function testTaxRecordsAgendaIsConvertedIntoTaxEvidenceCompany(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/evidence.lz',
            SyntheticAgenda::forCompany(SyntheticTaxAgenda::files(), self::ICO_A, 'Dávková evidence s.r.o.'));

        $results = $this->runBatch(['evidence.lz'], $this->options(self::LIVE));

        self::assertContains($results[0]['status'], [MigrationBatchRunner::COMPLETED, MigrationBatchRunner::COMPLETED_WITH_WARNINGS], json_encode($results[0], JSON_UNESCAPED_UNICODE) ?: '');
        $supplier = $this->supplierRow(self::ICO_A);
        self::assertSame('tax_evidence', $supplier['accounting_mode'] ?? null);
        $supplierId = (int) $supplier['id'];
        self::assertSame(0, $this->rows('journal_entries', $supplierId));
        self::assertSame(0, $this->rows('supplier_accounting_modes', $supplierId, "accounting_mode = 'double_entry'"));
        self::assertSame(2, $this->rows('invoices', $supplierId));
    }

    /**
     * Agenda, která se v Money z podvojného účetnictví vrátila do daňové evidence: dávka by
     * firmu založila a převod ji přepnul do podvojného i pro roky daňové evidence. Odmítne
     * ji dřív, než firmu založí.
     */
    public function testAgendaReturnedToTaxRecordsIsRefusedBeforeCompanyIsCreated(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/navrat.lz',
            SyntheticAgenda::forCompany(SyntheticTaxAgenda::filesReturnedToTaxRecords(), self::ICO_A, 'Dávkový návrat s.r.o.'));

        $results = $this->runBatch(['navrat.lz'], $this->options(self::LIVE));

        self::assertSame(MigrationBatchRunner::FAILED, $results[0]['status'], json_encode($results[0], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertStringContainsString('znovu daňovou evidenci', (string) $results[0]['error']);
        self::assertSame(0, $this->rows('supplier', null, "TRIM(LEADING '0' FROM ic) = '" . self::ICO_A . "'"));
    }

    public function testFiledDppoGivesIdentityAndIsTakenOverAfterConversion(): void
    {
        $xml = $this->filedXml(2024, self::ICO_A);
        $filings = [['filing' => FiledDppoFiling::parse($xml), 'xml' => $xml, 'file' => 'dppo-2024.xml']];
        // Přiznání cizí firmy dávka k firmě nepřiřadí.
        $other = $this->filedXml(2024, '11223341');
        $filings[] = ['filing' => FiledDppoFiling::parse($other), 'xml' => $other, 'file' => 'cizi.xml'];

        $results = $this->runBatch(['alfa.lz'], $this->options(self::LIVE), null, $filings);

        $r = $results[0];
        self::assertSame([2024], $r['summary']['filings_available']);
        self::assertSame('po', $r['summary']['identity']['taxpayer_type']);
        self::assertSame(2024, $r['summary']['filings'][0]['year']);
        self::assertContains($r['summary']['filings'][0]['status'], ['created', 'replaced']);
        $return = $this->container->get(TaxReturnRepository::class)->find((int) $r['target_supplier_id'], 2024, 'po');
        self::assertNotNull($return);
        self::assertSame('B', $return['inputs']['filed_source']['forma']);
    }

    public function testBatchJobFromWizardRunsItemsAndKeepsSummary(): void
    {
        $tokenA = $this->stageUpload('alfa.lz');
        $tokenB = $this->stageUpload('beta.lz');
        $action = $this->container->get(MoneyS3BatchAction::class);

        $list = $this->json($action->listUploads($this->request([]), (new ResponseFactory())->createResponse()));
        self::assertCount(2, $list['items']);
        self::assertSame('none', $list['items'][0]['company']['access']);

        $start = $action->start($this->request(['tokens' => [$tokenA, $tokenB], 'mode' => 'import', 'from_year' => 'auto']), (new ResponseFactory())->createResponse());
        self::assertSame(201, $start->getStatusCode(), (string) $start->getBody());
        $jobId = (int) $this->json($start)['job_id'];
        self::assertSame(2, $this->json($start)['companies']);

        $this->container->get(MoneyS3BatchJobService::class)->run($jobId);

        $job = $this->container->get(ImportJobRepository::class)->find($jobId, $this->officeId);
        self::assertContains($job['status'], ['completed', 'completed_with_warnings'], (string) ($job['last_error'] ?? ''));
        $items = $this->container->get(MigrationBatchRepository::class)->items($jobId, $this->officeId);
        self::assertSame([self::ICO_A, self::ICO_B], array_column($items, 'agenda_ico'));
        foreach ($items as $item) {
            self::assertContains($item['status'], ['completed', 'completed_with_warnings'], (string) $item['error']);
            self::assertNotNull($item['target_supplier_id']);
            self::assertTrue($item['summary']['criteria']['total']['K1']);
        }
        self::assertSame(2025, $items[1]['from_year']);
        self::assertDirectoryDoesNotExist(MoneyS3BatchUploads::store()->dir($this->officeId, $tokenA), 'Záloha převedené firmy se uklidí.');

        $detail = $this->json($action->job($this->request([]), (new ResponseFactory())->createResponse(), ['id' => (string) $jobId]));
        self::assertCount(2, $detail['items']);
        self::assertSame('Dávková alfa s.r.o.', $detail['items'][0]['target_name']);
    }

    public function testLiveBatchCreatingCompaniesNeedsTheRight(): void
    {
        $token = $this->stageUpload('alfa.lz');
        $action = $this->container->get(MoneyS3BatchAction::class);
        $role = new EffectiveRole(9, 'Účetní', 'staff', true, ['utilities.import' => 2, 'accounting.journal.write' => 2, 'settings.company.write' => 2, 'accounting.periods.close' => 2], 'admin');

        $denied = $action->start($this->request(['tokens' => [$token], 'mode' => 'import'], $role), (new ResponseFactory())->createResponse());
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('forbidden_permission', $this->json($denied)['error']['code']);

        $dry = $action->start($this->request(['tokens' => [$token], 'mode' => 'dry_run'], $role), (new ResponseFactory())->createResponse());
        self::assertSame(201, $dry->getStatusCode(), 'Zkouška nanečisto firmu nezaloží natrvalo, smí ji spustit i účetní.');
    }

    /**
     * @param list<string> $files
     * @param list<array{filing:FiledDppoFiling,xml:string,file?:string}> $filings
     * @return list<array<string,mixed>>
     */
    private function runBatch(array $files, MoneyS3BatchOptions $options, ?MigrationBatchActor $actor = null, array $filings = []): array
    {
        $actor ??= new MigrationBatchActor($this->userId, null, true, false);
        return (new MigrationBatchRunner())->run(
            $files,
            fn (string $file, int $i): array => $this->importer->importCompany($this->tmp . '/' . $file, $this->tmp . '/work' . $i, $options, $actor, $filings),
            static fn (\Throwable $e): string => $e instanceof MoneyS3Exception ? $e->getMessage() : throw $e,
        );
    }

    /** @param array<string,mixed> $extra */
    private function options(string $mode, array $extra = []): MoneyS3BatchOptions
    {
        return MoneyS3BatchOptions::fromArray(['mode' => $mode, 'from_year' => 'auto', 'use_registry' => false] + $extra);
    }

    private function stageUpload(string $file): string
    {
        $store = MoneyS3BatchUploads::store();
        $token = $store->newToken();
        mkdir($store->dir($this->officeId, $token), 0755, true);
        copy($this->tmp . '/' . $file, MoneyS3BatchUploads::backupPath($this->officeId, $token));
        $ico = $file === 'alfa.lz' ? self::ICO_A : self::ICO_B;
        $store->writeMeta($this->officeId, $token, [
            'token' => $token, 'file_name' => $file, 'uploaded_at' => date('c'), 'uploaded_by' => $this->userId,
            'agenda' => ['ico' => $ico, 'name' => $file === 'alfa.lz' ? 'Dávková alfa s.r.o.' : 'Dávková beta s.r.o.', 'backup_at' => '10.01.2026 08:15'],
        ]);
        return $token;
    }

    private function filedXml(int $year, string $ic): string
    {
        $calc = (new DppoReturnCalculator())->compute(['vh' => 10_000], [], TaxConstants::forYear($year));
        return (new DppoXmlBuilder())->build([
            'company_name' => 'Dávková alfa s.r.o.', 'street' => 'Účetní 12', 'city' => 'Brno', 'zip' => '60200',
            'country_iso2' => 'CZ', 'ic' => $ic, 'dic' => 'CZ' . $ic, 'taxpayer_type' => 'po', 'financial_office_code' => '451',
        ], $year, $calc)['xml'];
    }

    /** @param array<string,mixed> $body */
    private function request(array $body, ?EffectiveRole $role = null): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/imports/money-s3/batch/start')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->officeId)
            ->withAttribute('auth.effective_role', $role ?? new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin'))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    /** @return array<string,mixed> */
    private function supplierRow(string $ico): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT * FROM supplier WHERE TRIM(LEADING '0' FROM ic) = ?");
        $stmt->execute([$ico]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function rows(string $table, ?int $supplierId, string $where = '1=1'): int
    {
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}" . ($supplierId !== null ? ' AND ' . ($table === 'supplier' ? 'id' : 'supplier_id') . ' = ' . $supplierId : '');
        return (int) $this->db->pdo()->query($sql)->fetchColumn();
    }
}

