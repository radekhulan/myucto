<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\StereoNxMigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportJobService;
use MyInvoice\Service\Migration\StereoNx\StereoNxPartnerCountries;
use MyInvoice\Service\Migration\StereoNx\StereoNxUploads;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxAccountingTables;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Potvrzené země patří ke konkrétní zkoušce nanečisto, nikoli pouze k ZIPu. */
#[Group('integration')]
final class StereoNxPartnerCountriesJobTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxMigrationAction $action;
    private ImportJobRepository $jobs;
    private StereoNxImportJobService $worker;
    private int $supplierId;
    private int $userId;
    private string $token;
    private array $reportPaths = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(StereoNxMigrationAction::class);
        $this->jobs = $container->get(ImportJobRepository::class);
        $this->worker = $container->get(StereoNxImportJobService::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $source);
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $identity = SyntheticStereoNxTables::identity();
        $pdo->prepare("UPDATE supplier SET company_name = ?, ic = ?, dic = ?, accounting_mode = 'tax_evidence', is_vat_payer = 1 WHERE id = ?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
            VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech koruna', 2, 1, 1)")->execute([$this->supplierId]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $this->supplierId]);
        $this->token = StereoNxUploads::token();
        mkdir(StereoNxUploads::dir($this->supplierId, $this->token), 0700, true);
        $this->upload();
    }

    protected function tearDown(): void
    {
        foreach ($this->reportPaths as $path) {
            $absolute = RuntimePaths::storage($path);
            if (is_file($absolute)) unlink($absolute);
        }
        if (isset($this->token)) StereoNxUploads::delete($this->supplierId, $this->token);
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public static function invalidConfigurations(): iterable
    {
        yield 'string' => ['CZ'];
        yield 'boolean' => [true];
        yield 'nested value' => [['C1' => ['CZ']]];
        yield 'non ISO' => [['C1' => 'CZE']];
        yield 'generic EU' => [['C1' => 'EU']];
        yield 'unknown ISO' => [['C1' => 'ZZ']];
        yield 'empty identity' => [['' => 'CZ']];
    }

    #[DataProvider('invalidConfigurations')]
    public function testMalformedConfigurationIs422AndCreatesNoJob(mixed $configuration): void
    {
        $response = $this->call(['partner_countries' => $configuration]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('partner_countries_invalid', $this->json($response)['error']['code']);
        self::assertSame([], $this->jobs->listForTenant($this->supplierId, StereoNxImportJobService::SOURCE));
    }

    public function testCanonicalConfigurationSurvivesDryRunAndImport(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        foreach ($tables['LAdresy'] as &$partner) {
            $partner['Stat'] = '';
            $partner['DIC'] = '';
        }
        unset($partner);
        $this->upload($tables);
        $jobId = $this->start(['partner_countries' => ['V1' => ' cz ', 'C1' => 'cz']]);
        self::assertSame(['C1' => 'CZ', 'V1' => 'CZ'], $this->jobs->find($jobId, $this->supplierId)['params']['partner_countries'] ?? null);
        $report = $this->finish($jobId);
        self::assertTrue($report['ok'], json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->clientCount());
        $state = StereoNxUploads::state($this->supplierId, $this->token);
        self::assertSame(StereoNxPartnerCountries::fingerprint(['V1' => 'CZ', 'C1' => 'CZ']), $state['dry_run_partner_countries_hash']);

        $changed = $this->call(['mode' => 'import', 'partner_countries' => ['C1' => 'CZ']]);
        self::assertSame(409, $changed->getStatusCode());
        self::assertSame('dry_run_required', $this->json($changed)['error']['code']);
        $import = $this->finish($this->start(['mode' => 'import', 'partner_countries' => ['C1' => 'CZ', 'V1' => 'CZ']]));
        self::assertTrue($import['ok'], json_encode($import, JSON_UNESCAPED_UNICODE));
        self::assertGreaterThan(0, $this->clientCount());
        $stmt = $this->db->pdo()->prepare('SELECT DISTINCT c.iso2 FROM clients p JOIN countries c ON c.id = p.country_id WHERE p.supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        self::assertSame(['CZ'], $stmt->fetchAll(\PDO::FETCH_COLUMN));
        self::assertNull(StereoNxUploads::state($this->supplierId, $this->token)['dry_run_partner_countries_hash']);
    }

    public function testActionRejectsChangedCountriesEvenForLegacyDryRun(): void
    {
        $this->legacyDryRun();
        $response = $this->call(['mode' => 'import', 'partner_countries' => ['C1' => 'CZ']]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('dry_run_required', $this->json($response)['error']['code']);
        self::assertSame([], $this->jobs->listForTenant($this->supplierId, StereoNxImportJobService::SOURCE));
    }

    public function testWorkerRejectsCountriesChangedAfterJobWasQueued(): void
    {
        $this->legacyDryRun();
        $jobId = $this->start(['mode' => 'import']);
        $state = StereoNxUploads::state($this->supplierId, $this->token);
        $state['dry_run_partner_countries_hash'] = hash('sha256', 'synthetic different confirmed countries');
        StereoNxUploads::save($this->supplierId, $this->token, $state);
        $this->worker->run($jobId);
        $job = $this->jobs->find($jobId, $this->supplierId);
        if ($job['result_path']) $this->reportPaths[] = $job['result_path'];
        self::assertSame('failed', $job['status']);
        self::assertStringContainsString('zkoušku nanečisto', $job['last_error']);
        self::assertSame(0, $this->clientCount());
    }

    public static function invalidStoredConfigurations(): iterable
    {
        yield 'malformed stored map' => ['CZ'];
        yield 'unsupported stored ISO' => [['C1' => 'ZZ']];
    }

    #[DataProvider('invalidStoredConfigurations')]
    public function testWorkerValidatesStoredConfigurationWithoutRelyingOnAction(mixed $configuration): void
    {
        $jobId = $this->start([]);
        $params = $this->jobs->find($jobId, $this->supplierId)['params'];
        $params['partner_countries'] = $configuration;
        $this->db->pdo()->prepare('UPDATE import_jobs SET params = ? WHERE id = ? AND supplier_id = ?')
            ->execute([json_encode($params, JSON_THROW_ON_ERROR), $jobId, $this->supplierId]);
        $this->worker->run($jobId);
        $job = $this->jobs->find($jobId, $this->supplierId);
        if ($job['result_path']) $this->reportPaths[] = $job['result_path'];
        self::assertSame('failed', $job['status']);
        self::assertStringContainsString('zem', $job['last_error']);
        self::assertSame(0, $this->clientCount());
    }

    public static function emptyConfigurations(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['partner_countries' => null]];
        yield 'empty' => [['partner_countries' => []]];
    }

    #[DataProvider('emptyConfigurations')]
    public function testLegacyDryRunAcceptsEmptyConfiguration(array $body): void
    {
        $this->legacyDryRun();
        $report = $this->finish($this->start(['mode' => 'import'] + $body));
        self::assertTrue($report['ok'], json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertGreaterThan(0, $this->clientCount());
    }

    public static function accountingModes(): iterable
    {
        yield 'tax evidence' => ['tax_evidence'];
        yield 'double entry' => ['double_entry'];
    }

    #[DataProvider('accountingModes')]
    public function testWorkerPassesCountriesToBothImportersAndUnknownIdentityFailsReport(string $mode): void
    {
        if ($mode === 'double_entry') {
            $identity = SyntheticStereoNxAccountingTables::identity();
            $this->db->pdo()->prepare('UPDATE supplier SET accounting_mode = ?, ic = ?, dic = ? WHERE id = ?')
                ->execute([$mode, $identity['ico'], $identity['dic'], $this->supplierId]);
            $this->upload(SyntheticStereoNxAccountingTables::tables(), SyntheticStereoNxAccountingTables::identity());
        }
        $report = $this->finish($this->start(['partner_countries' => ['missing-synthetic-client' => 'CZ']]));
        self::assertFalse($report['ok'], json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('partner_country_unknown', json_encode($report));
        self::assertSame(0, $this->clientCount());
        $state = StereoNxUploads::state($this->supplierId, $this->token);
        self::assertNull($state['dry_run_company']);
        self::assertNull($state['dry_run_partner_countries_hash']);
    }

    public function testPartnerCountriesDoNotBypassCompanyOrAuthorization(): void
    {
        $body = ['partner_countries' => ['C1' => 'CZ']];
        $request = $this->request($body)->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', false, ['utilities.import' => 1]));
        $denied = $this->action->run($request, (new ResponseFactory())->createResponse(), ['token' => $this->token]);
        self::assertSame(403, $denied->getStatusCode());
        $this->db->pdo()->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute(['00000019', $this->supplierId]);
        $mismatch = $this->call($body);
        self::assertSame(422, $mismatch->getStatusCode());
        self::assertSame('target_mismatch', $this->json($mismatch)['error']['code']);
        self::assertSame([], $this->jobs->listForTenant($this->supplierId, StereoNxImportJobService::SOURCE));
    }

    #[DataProvider('accountingModes')]
    public function testPartnerCountriesPreviewUsesImportIdentitiesWithoutWritingAnything(string $mode): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['LAdresy'][1]['Stat'] = '';
        $tables['LAdresy'][1]['DIC'] = '';
        $tables['SPFH'][0]['Firma'] = '';
        $tables['SPFH'][0]['FirmaNazev'] = 'Syntetická historická protistrana';
        $tables['SPFH'][0]['FirmaICO'] = '33333333';
        $this->upload($tables);
        $this->db->pdo()->prepare('UPDATE supplier SET accounting_mode = ?, fiscal_year_start_month = 8 WHERE id = ?')
            ->execute([$mode, $this->supplierId]);
        $snapshotKey = 'snapshot:purchase:' . json_encode([$mode === 'double_entry' ? '2024' : '2025', 'PF', '1'], JSON_THROW_ON_ERROR);
        $before = $this->previewSnapshot();
        $state = StereoNxUploads::state($this->supplierId, $this->token);
        $archiveHash = hash_file('sha256', StereoNxUploads::archive($this->supplierId, $this->token));

        $response = $this->previewCountries([]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $partners = array_column($this->json($response)['partners'], null, 'source_key');
        self::assertArrayHasKey('V1', $partners);
        self::assertArrayHasKey($snapshotKey, $partners);
        self::assertSame('PF-1', $partners[$snapshotKey]['documents'][0]['document_no']);
        self::assertSame('33333333', $partners[$snapshotKey]['ico']);
        self::assertNull($partners[$snapshotKey]['confirmed_country']);

        $confirmed = $this->previewCountries(['partner_countries' => [$snapshotKey => ' de ']]);
        self::assertSame(200, $confirmed->getStatusCode(), (string) $confirmed->getBody());
        $confirmedPartners = array_column($this->json($confirmed)['partners'], null, 'source_key');
        self::assertSame('DE', $confirmedPartners[$snapshotKey]['confirmed_country']);
        self::assertSame('DE', $confirmedPartners[$snapshotKey]['country_code']);
        self::assertSame($before, $this->previewSnapshot(), 'Náhled nesmí vytvářet joby, karty, doklady, mapy ani účetní období.');
        self::assertSame($state, StereoNxUploads::state($this->supplierId, $this->token));
        self::assertSame($archiveHash, hash_file('sha256', StereoNxUploads::archive($this->supplierId, $this->token)));
    }

    public static function invalidPreviewSelections(): iterable
    {
        yield 'invalid company' => [['company' => -1], 'invalid_selection'];
        yield 'invalid blank country' => [['blank_country_is_cz' => 'true'], 'invalid_selection'];
        yield 'invalid bank matching' => [['match_bank_account_series' => 1], 'invalid_selection'];
        yield 'invalid configuration' => [['partner_countries' => 'CZ'], 'partner_countries_invalid'];
        yield 'generic EU' => [['partner_countries' => ['V1' => 'EU']], 'partner_countries_invalid'];
        yield 'unsupported country' => [['partner_countries' => ['V1' => 'ZZ']], 'partner_countries_invalid'];
        yield 'unknown source key' => [['partner_countries' => ['synthetic-missing' => 'CZ']], 'partner_country_unknown'];
    }

    #[DataProvider('invalidPreviewSelections')]
    public function testPartnerCountriesPreviewValidatesSelectionWithoutCreatingJob(array $body, string $code): void
    {
        $before = $this->previewSnapshot();
        $response = $this->previewCountries($body);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($code, $this->json($response)['error']['code']);
        self::assertSame($before, $this->previewSnapshot());
    }

    public function testPartnerCountriesPreviewChecksOwnerPermissionAndCompany(): void
    {
        $before = $this->previewSnapshot();
        $request = $this->request([]);
        $wrongUser = $request->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId + 999999]);
        self::assertSame(404, $this->previewCountries([], $wrongUser)->getStatusCode());
        $wrongSupplier = $request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId + 999999);
        self::assertSame(404, $this->previewCountries([], $wrongSupplier)->getStatusCode());
        $denied = $request->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', false, ['utilities.import' => 1]));
        self::assertSame(403, $this->previewCountries([], $denied)->getStatusCode());
        self::assertSame($before, $this->previewSnapshot());
        $this->db->pdo()->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute(['00000019', $this->supplierId]);
        $mismatch = $this->previewCountries([]);
        self::assertSame(422, $mismatch->getStatusCode());
        self::assertSame('target_mismatch', $this->json($mismatch)['error']['code']);
    }

    public function testPartnerCountriesPreviewReadsEncryptedReadyArchiveOnly(): void
    {
        $path = StereoNxUploads::archive($this->supplierId, $this->token);
        SyntheticNx1Archive::write($path, SyntheticStereoNxTables::tables(), SyntheticStereoNxTables::identity(), 'Synthetic-country-preview-password');
        $ready = StereoNxUploads::state($this->supplierId, $this->token);
        $ready['sha256'] = hash_file('sha256', $path);
        StereoNxUploads::save($this->supplierId, $this->token, $ready);
        $response = $this->previewCountries(['password' => 'Synthetic-country-preview-password']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($ready, StereoNxUploads::state($this->supplierId, $this->token));
        $ready['status'] = 'uploading';
        StereoNxUploads::save($this->supplierId, $this->token, $ready);
        $incomplete = $this->previewCountries(['password' => 'Synthetic-country-preview-password']);
        self::assertSame(422, $incomplete->getStatusCode());
        self::assertSame('upload_incomplete', $this->json($incomplete)['error']['code']);
    }

    public function testPartnerCountriesPreviewIsRegisteredAsInternalPostRoute(): void
    {
        $routes = Bootstrap::buildApp()->getRouteCollector()->getRoutes();
        $matching = array_values(array_filter($routes, static fn ($route): bool => $route->getPattern() === '/api/admin/imports/stereo-nx/uploads/{token:[a-f0-9]{32}}/partner-countries'));
        self::assertCount(1, $matching);
        self::assertSame(['POST'], $matching[0]->getMethods());
        self::assertSame([StereoNxMigrationAction::class, 'partnerCountries'], $matching[0]->getCallable());
    }

    private function previewCountries(array $body, ?ServerRequestInterface $request = null): ResponseInterface
    {
        self::assertTrue(is_callable([$this->action, 'partnerCountries']), 'Chybí endpoint pro náhled zemí před zkouškou nanečisto.');
        return $this->action->partnerCountries($request ?? $this->request($body), (new ResponseFactory())->createResponse(), ['token' => $this->token]);
    }

    private function previewSnapshot(): array
    {
        $result = [];
        foreach (['supplier' => 'id', 'clients' => 'supplier_id', 'invoices' => 'supplier_id', 'purchase_invoices' => 'supplier_id',
            'accounting_periods' => 'supplier_id', 'import_jobs' => 'supplier_id', 'stereo_nx_import_map' => 'supplier_id'] as $table => $column) {
            $stmt = $this->db->pdo()->prepare("SELECT * FROM {$table} WHERE {$column} = ?");
            $stmt->execute([$this->supplierId]);
            $result[$table] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $result;
    }

    private function upload(?array $tables = null, ?array $identity = null): void
    {
        $path = StereoNxUploads::archive($this->supplierId, $this->token);
        SyntheticNx1Archive::write($path, $tables ?? SyntheticStereoNxTables::tables(), $identity ?? SyntheticStereoNxTables::identity());
        StereoNxUploads::save($this->supplierId, $this->token, [
            'status' => 'ready', 'sha256' => hash_file('sha256', $path), 'uploaded_by' => $this->userId,
            'file_name' => 'synthetic-countries.zip', 'size' => filesize($path), 'received' => filesize($path), 'created_at' => time(),
        ]);
    }

    private function legacyDryRun(): void
    {
        $state = StereoNxUploads::state($this->supplierId, $this->token);
        $state['dry_run_company'] = 0;
        $state['dry_run_blank_country_is_cz'] = false;
        StereoNxUploads::save($this->supplierId, $this->token, $state);
    }

    private function start(array $body): int
    {
        $response = $this->call($body);
        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        return (int) $this->json($response)['job_id'];
    }

    private function finish(int $jobId): array
    {
        $this->worker->run($jobId);
        $job = $this->jobs->find($jobId, $this->supplierId);
        self::assertNotNull($job['result_path'], $job['last_error'] ?? 'Chybí výsledek jobu.');
        $this->reportPaths[] = $job['result_path'];
        $response = $this->action->result($this->request([]), (new ResponseFactory())->createResponse(), ['token' => $this->token, 'id' => (string) $jobId]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->json($response)['report'];
    }

    private function clientCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM clients WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function call(array $body): ResponseInterface
    {
        return $this->action->run($this->request($body), (new ResponseFactory())->createResponse(), ['token' => $this->token]);
    }

    private function request(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/imports/stereo-nx')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, ['utilities.import' => 2]))
            ->withParsedBody($body + ['company' => 0, 'mode' => 'dry_run']);
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
