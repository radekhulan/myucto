<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\CsrfMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6PreviewCache;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class J6NonprofitTaxHttpTest extends TestCase
{
    use IsolatedSupplierTrait;

    private \DI\Container $container;
    private Connection $db;
    private PDO $pdo;
    private \Slim\App $app;
    private int $supplier;
    private int $period;
    private int $actor;
    private array $previewTokens = [];

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertSame((string) getenv('MYINVOICE_DB_NAME'), $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);
        $this->period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplier, '2026-01-01');
        $this->app = \Slim\Factory\AppFactory::create(new ResponseFactory(), $this->container);
        \MyInvoice\Routes::register($this->app);
        $this->app->add($this->container->get(CsrfMiddleware::class));
    }

    protected function tearDown(): void
    {
        foreach ($this->previewTokens as $token) {
            $cache = $this->container->get(J6PreviewCache::class);
            $cache->removeOwnedDirectory($cache->previewPath($token, true));
        }
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    private function http(string $method, string $path, array $body = [], array $query = [], string $auth = 'session',
        ?array $permissions = null, ?int $actor = null, bool $csrf = true): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/accounting' . $path)
            ->withParsedBody($body)->withQueryParams($query)->withAttribute(AuthMiddleware::ATTR_METHOD, $auth)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $actor ?? $this->actor, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['csrf_token' => 'synthetic-j6-http-csrf'])
            ->withAttribute('auth.effective_role', new EffectiveRole(123, 'Synthetic J6', 'staff', true,
                $permissions ?? ['accounting' => 1, 'accounting.periods.close' => 2]))
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, new TenantDomainContext('canonical', 'example.test', 'https://example.test'))
            ->withHeader('Origin', 'https://example.test');
        if ($csrf) $request = $request->withHeader('X-CSRF-Token', 'synthetic-j6-http-csrf');
        $response = $this->app->handle($request);
        $content = (string) $response->getBody();
        return [$response->getStatusCode(), str_starts_with($response->getHeaderLine('Content-Type'), 'application/x-ndjson')
            ? $content : json_decode($content, true, 512, JSON_THROW_ON_ERROR)];
    }

    private function base(): string { return '/periods/' . $this->period . '/nonprofit-tax'; }
    private function profile(): array { return ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy HTTP']; }
    private function open(): array
    {
        [$status, $body] = $this->http('POST', $this->base() . '/draft', ['profile' => $this->profile()]);
        self::assertSame(200, $status, json_encode($body));
        return $body;
    }

    public function testDraftWritesRequireSessionClosePermissionAndCsrf(): void
    {
        foreach (['bearer', ''] as $auth) {
            [$status] = $this->http('POST', $this->base() . '/draft', auth: $auth);
            self::assertSame(403, $status);
            [$status] = $this->http('GET', $this->base() . '/drafts/1', auth: $auth);
            self::assertSame(403, $status);
        }
        [$status] = $this->http('POST', $this->base() . '/draft', ['profile' => $this->profile()], permissions: ['accounting' => 1]);
        self::assertSame(403, $status);
        [$status] = $this->http('POST', $this->base() . '/draft', ['profile' => $this->profile()], csrf: false);
        self::assertSame(403, $status);
        [$status] = $this->http('GET', $this->base() . '/source', permissions: ['accounting' => 1]);
        self::assertSame(200, $status);
        [$status] = $this->http('GET', $this->base() . '/source', auth: 'bearer', permissions: ['accounting' => 1]);
        self::assertSame(200, $status);
    }

    public function testOpeningNeverAcceptsClientSourceOrActorAndRejectsMalformedBodies(): void
    {
        foreach ([['source_input_hash' => str_repeat('a', 64)], ['source_manifest_id' => 1], ['actor' => 1], ['profile' => 'invalid']] as $extra) {
            [$status] = $this->http('POST', $this->base() . '/draft', array_replace(['profile' => $this->profile()], $extra));
            self::assertSame(400, $status);
        }
        $query = $this->pdo->prepare('SELECT COUNT(*) FROM nonprofit_tax_drafts WHERE supplier_id=?');
        $query->execute([$this->supplier]);
        self::assertSame(0, (int) $query->fetchColumn());
    }

    public function testSourceRowsAndFindingsAreBoundedCompleteReadonlyAndRevisionBound(): void
    {
        $query = $this->pdo->prepare("SELECT account_code,id FROM chart_of_accounts WHERE supplier_id=? AND account_code IN ('501','602')");
        $query->execute([$this->supplier]);
        $accounts = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        $lines = [];
        for ($i = 0; $i < 60; ++$i) {
            $lines[] = ['account_id' => (int) $accounts['501'], 'side' => 'debit', 'amount' => '0.01'];
            $lines[] = ['account_id' => (int) $accounts['602'], 'side' => 'credit', 'amount' => '0.01'];
        }
        $this->container->get(JournalEntryRepository::class)->insert(['supplier_id' => $this->supplier, 'period_id' => $this->period,
            'entry_date' => '2026-06-01', 'source_type' => 'manual', 'description' => 'Synthetic HTTP source paging',
            'posted_at' => '2026-06-01 12:00:00', 'posted_by' => $this->actor], $lines);
        $opened = $this->open();
        self::assertFalse($opened['capabilities']['source_complete']);
        self::assertFalse($opened['capabilities']['can_confirm']);
        $draft = $opened['draft'];
        $path = $this->base() . '/drafts/' . $draft['id'];
        [$status] = $this->http('GET', $path . '/source', query: ['limit' => 101]);
        self::assertSame(400, $status);
        $before = $this->dml();
        $cursor = null;
        $firstCursor = null;
        $ids = [];
        $findingCount = 0;
        $pages = 0;
        do {
            [$status, $page] = $this->http('GET', $path . '/source', query: $cursor === null ? [] : ['after' => $cursor]);
            self::assertSame(200, $status, json_encode($page));
            self::assertLessThanOrEqual(100, count($page['rows']));
            self::assertLessThanOrEqual(100, count($page['findings']));
            array_push($ids, ...array_column($page['rows'], 'line_id'));
            $findingCount += count($page['findings']);
            $cursor = $page['after'];
            $firstCursor ??= $cursor;
            self::assertLessThan(20, ++$pages);
        } while ($cursor !== null);
        self::assertCount(120, $ids);
        self::assertCount(120, array_unique($ids));
        self::assertGreaterThanOrEqual(120, $findingCount);
        self::assertSame($before, $this->dml());
        [$status] = $this->http('GET', $path, actor: $this->actor + 1000000);
        self::assertSame(404, $status);
        [$status] = $this->http('PATCH', $path . '/decisions', ['expected_revision' => $draft['revision_no'],
            'upserts' => array_fill(0, 501, []), 'deletes' => []]);
        self::assertSame(400, $status);
        [$status, $changed] = $this->http('PATCH', $path . '/decisions', ['expected_revision' => $draft['revision_no'],
            'upserts' => [['source_id' => 'line:' . $ids[0], 'payload' => ['title' => 'review', 'evidence' => 'Synthetic pending decision']]], 'deletes' => []]);
        self::assertSame(200, $status, json_encode($changed));
        self::assertSame($draft['revision_no'] + 1, $changed['draft']['revision_no']);
        [$status, $stale] = $this->http('GET', $path . '/source', query: ['after' => $firstCursor]);
        self::assertSame(409, $status);
        self::assertSame('nonprofit_tax_cursor_stale', $stale['error']['code']);
        [$status] = $this->http('PATCH', $path . '/decisions', ['expected_revision' => $draft['revision_no'], 'upserts' => [], 'deletes' => []]);
        self::assertSame(409, $status);
    }

    public function testFreshConfirmationHeadCasReadonlyHistoryAndStreamingExport(): void
    {
        $draft = $this->open()['draft'];
        $path = $this->base() . '/drafts/' . $draft['id'];
        [$status, $preview] = $this->http('POST', $path . '/preview', ['expected_revision' => $draft['revision_no']]);
        self::assertSame(200, $status, json_encode($preview));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $preview['preview_token']);
        $this->previewTokens[] = $preview['preview_token'];
        foreach (['decision', 'result', 'coverage', 'usage', 'funding_reference', 'funding_history'] as $kind) {
            [$status, $page] = $this->http('GET', $path . '/previews/' . $preview['preview_token'] . '/' . $kind);
            self::assertSame(200, $status, json_encode($page));
            self::assertSame([], $page['rows']);
        }
        $body = ['expected_revision' => $draft['revision_no'], 'expected_assessment_id' => null,
            'reason' => 'Synthetic HTTP confirmation', 'idempotency_key' => 'synthetic-http-confirm-' . $this->supplier];
        [$status, $confirmed] = $this->http('POST', $path . '/confirm', $body);
        self::assertSame(200, $status, json_encode($confirmed));
        [$status, $retry] = $this->http('POST', $path . '/confirm', $body);
        self::assertSame(200, $status);
        self::assertSame($confirmed, $retry);
        [$status] = $this->http('POST', $path . '/confirm', array_replace($body, ['idempotency_key' => $body['idempotency_key'] . '-stale']));
        self::assertSame(409, $status);
        $base = '/nonprofit-tax-assessments/' . $confirmed['assessment_id'];
        $before = $this->dml();
        [$status, $summary] = $this->http('GET', $base);
        self::assertSame(200, $status, json_encode($summary));
        self::assertSame($confirmed['assessment_id'], $summary['id']);
        [$status, $publicSummary] = $this->http('GET', $base, auth: 'bearer', permissions: ['accounting' => 1]);
        self::assertSame(200, $status);
        self::assertSame($summary, $publicSummary);
        [$status, $history] = $this->http('GET', $this->base() . '/history');
        self::assertSame(200, $status);
        self::assertCount(1, $history['rows']);
        foreach (['source', 'decision', 'result', 'coverage', 'usage'] as $kind) {
            [$status, $page] = $this->http('GET', $base . '/' . $kind);
            self::assertSame(200, $status, json_encode($page));
            self::assertSame([], $page['rows']);
            self::assertNull($page['after']);
        }
        [$status, $export] = $this->http('GET', $base . '/export');
        self::assertSame(200, $status);
        $record = json_decode(explode("\n", $export)[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('header', $record['kind']);
        self::assertSame($confirmed['assessment_id'], $record['payload']['id']);
        self::assertSame($before, $this->dml());
    }

    public function testLifecycleSummaryHeadBoundHistoryDocumentsAndWithdrawal(): void
    {
        [$status, $none] = $this->http('GET', $this->base() . '/draft');
        self::assertSame(200, $status, json_encode($none));
        self::assertNull($none['draft']);
        $draft = $this->open()['draft'];
        [$status, $mine] = $this->http('GET', $this->base() . '/draft');
        self::assertSame($draft['id'], $mine['draft']['id']);
        [$status] = $this->http('GET', $this->base() . '/draft', auth: 'bearer');
        self::assertSame(403, $status);
        [$status, $sourceSummary] = $this->http('GET', $this->base() . '/drafts/' . $draft['id'] . '/source-summary');
        self::assertSame(200, $status, json_encode($sourceSummary));
        self::assertTrue($sourceSummary['source_complete']);

        [$status, $empty] = $this->http('GET', $this->base() . '/summary', auth: 'bearer', permissions: ['accounting' => 1]);
        self::assertSame(200, $status, json_encode($empty));
        self::assertNull($empty['latest']);
        self::assertSame(0, $empty['source']['row_count']);

        $confirm = fn (string $key, ?int $expected) => $this->http('POST', $this->base() . '/drafts/' . $draft['id'] . '/confirm', [
            'expected_revision' => $draft['revision_no'], 'expected_assessment_id' => $expected,
            'reason' => 'Synthetic lifecycle confirmation', 'idempotency_key' => $key]);
        [$status, $first] = $confirm('synthetic-http-life-1-' . $this->supplier, null);
        self::assertSame(200, $status, json_encode($first));
        [$status, $page] = $this->http('GET', $this->base() . '/history', query: ['limit' => 1]);
        self::assertSame(['assessment_id' => $first['assessment_id'], 'revision_no' => 1, 'status' => 'confirmed'], $page['head']);
        [$status, $tail] = $this->http('GET', $this->base() . '/history', query: ['limit' => 1, 'after' => $page['after']]);
        self::assertSame(200, $status, json_encode($tail));
        self::assertSame([], $tail['rows']);
        self::assertNull($tail['after']);

        $before = $this->dml();
        foreach (['pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] as $format => $type) {
            $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/accounting/nonprofit-tax-assessments/' . $first['assessment_id'] . '/summary.' . $format)
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'accountant'])
                ->withAttribute('auth.effective_role', new EffectiveRole(123, 'Synthetic J6', 'staff', true, ['accounting' => 1]))
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
                ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, new TenantDomainContext('canonical', 'example.test', 'https://example.test'));
            $response = $this->app->handle($request);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame($type, $response->getHeaderLine('Content-Type'));
            self::assertGreaterThan(1000, strlen((string) $response->getBody()));
        }
        self::assertSame($before, $this->dml());

        $withdrawPath = $this->base() . '/assessments/' . $first['assessment_id'] . '/withdraw';
        $body = ['reason' => 'Synthetic HTTP withdrawal', 'idempotency_key' => 'synthetic-http-withdraw-' . $this->supplier];
        [$status] = $this->http('POST', $withdrawPath, $body, auth: 'bearer');
        self::assertSame(403, $status);
        [$status] = $this->http('POST', $withdrawPath, $body, permissions: ['accounting' => 1]);
        self::assertSame(403, $status);
        [$status, $withdrawn] = $this->http('POST', $withdrawPath, $body);
        self::assertSame(200, $status, json_encode($withdrawn));
        self::assertSame('withdrawn', $withdrawn['status']);
        [$status, $retry] = $this->http('POST', $withdrawPath, $body);
        self::assertSame($withdrawn, $retry);

        [$status, $headPage] = $this->http('GET', $this->base() . '/history', query: ['limit' => 1]);
        self::assertSame('withdrawn', $headPage['head']['status']);
        self::assertNotNull($headPage['after']);
        [$status, $second] = $confirm('synthetic-http-life-2-' . $this->supplier, null);
        self::assertSame(200, $status, json_encode($second));
        [$status, $stale] = $this->http('GET', $this->base() . '/history', query: ['limit' => 1, 'after' => $headPage['after']]);
        self::assertSame(409, $status);
        self::assertSame('nonprofit_tax_cursor_stale', $stale['error']['code']);
        [$status, $summary] = $this->http('GET', $this->base() . '/summary');
        self::assertSame($second['assessment_id'], $summary['latest']['id']);
        self::assertSame(3, $summary['latest']['revision_no']);
    }

    /** #402 krok 2, A13: převzetí profilu přes HTTP, připnutí revize a jeho práva. */
    public function testResetWithoutPostureAndWithoutLegalProfileIsRejectedWithoutWriting(): void
    {
        [$status, $opened] = $this->http('POST', $this->base() . '/draft', ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy']]);
        self::assertSame(200, $status, json_encode($opened));
        $path = $this->base() . '/drafts/' . $opened['draft']['id'];
        [$status, $reset] = $this->http('POST', $path . '/reset', ['expected_revision' => $opened['draft']['revision_no']]);
        self::assertSame(422, $status, json_encode($reset));
        self::assertSame('nonprofit_tax_facts_incomplete', $reset['error']['code']);
        [$status, $after] = $this->http('GET', $path);
        self::assertSame(200, $status, json_encode($after));
        self::assertSame($opened['draft']['revision_no'], $after['draft']['revision_no']);
        self::assertEquals(['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy'], $after['draft']['header']['profile']);
    }

    public function testLegalProfileIsAdoptedPinnedAndGuardedOverHttp(): void
    {
        [$status, $empty] = $this->http('GET', $this->base() . '/draft');
        self::assertSame(200, $status);
        self::assertSame(['state' => null, 'current' => null, 'pinned' => null, 'pinnable' => false], $empty['legal_profile']);
        // Bez profilu ani postavení v těle: chybí doložené postavení.
        [$status, $missing] = $this->http('POST', $this->base() . '/draft');
        self::assertSame(422, $status, json_encode($missing));
        self::assertSame('nonprofit_tax_facts_incomplete', $missing['error']['code']);
        // Cizí nebo neexistující období: čtení konceptu zůstává 200 bez konceptu, chyba profilu jen ve stavu.
        [$status, $foreign] = $this->http('GET', '/periods/999999999/nonprofit-tax/draft');
        self::assertSame(200, $status, json_encode($foreign));
        self::assertNull($foreign['draft']);
        self::assertSame('nonprofit_legal_profile_period_not_found', $foreign['legal_profile']['error']['code']);

        $legal = $this->confirmLegalProfile('narrow_vpp', 'association', 0, 'http-legal-1');
        [$status, $empty] = $this->http('GET', $this->base() . '/draft');
        self::assertSame(['id' => $legal['id'], 'revision_no' => 1, 'kind' => 'narrow_vpp', 'entity_type' => 'association'], $empty['legal_profile']['current']);

        [$status, $mismatch] = $this->http('POST', $this->base() . '/draft', ['profile' => ['kind' => 'broad_vpp', 'evidence' => 'Syntetické']]);
        self::assertSame(422, $status);
        self::assertSame('nonprofit_tax_legal_profile_mismatch', $mismatch['error']['code']);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM nonprofit_tax_drafts WHERE supplier_id=?');
        $count->execute([$this->supplier]);
        self::assertSame(0, (int) $count->fetchColumn());

        [$status, $opened] = $this->http('POST', $this->base() . '/draft');
        self::assertSame(200, $status, json_encode($opened));
        self::assertEquals(['kind' => 'narrow_vpp', 'evidence' => 'Synthetic statutes'], $opened['draft']['header']['profile']);
        self::assertEquals(['id' => $legal['id'], 'revision_no' => 1, 'record_hash' => $legal['record_hash']], $opened['draft']['header']['legal_profile']);
        self::assertSame('pinned_current', $opened['legal_profile']['state']);

        $this->confirmLegalProfile('narrow_vpp', 'association', 1, 'http-legal-2');
        $pinPath = $this->base() . '/drafts/' . $opened['draft']['id'] . '/legal-profile';
        $body = ['expected_revision' => $opened['draft']['revision_no']];
        foreach (['bearer', ''] as $auth) {
            [$status, $denied] = $this->http('POST', $pinPath, $body, auth: $auth);
            self::assertSame(403, $status);
            self::assertSame('nonprofit_tax_session_only', $denied['error']['code']);
        }
        [$status] = $this->http('POST', $pinPath, $body, permissions: ['accounting' => 1]);
        self::assertSame(403, $status);
        [$status] = $this->http('POST', $pinPath, $body, csrf: false);
        self::assertSame(403, $status);
        [$status] = $this->http('POST', $pinPath, $body, actor: $this->actor + 100000);
        self::assertSame(404, $status);
        [$status] = $this->http('POST', $pinPath, $body + ['profile' => $this->profile()]);
        self::assertSame(400, $status);
        foreach ([(string) $body['expected_revision'], 0] as $invalid) {
            [$status] = $this->http('POST', $pinPath, ['expected_revision' => $invalid]);
            self::assertSame(400, $status);
        }
        [$status, $outdated] = $this->http('GET', $this->base() . '/drafts/' . $opened['draft']['id']);
        self::assertSame('pinned_same_kind_outdated', $outdated['legal_profile']['state']);
        self::assertSame(1, $outdated['legal_profile']['pinned']['revision_no']);
        self::assertSame(2, $outdated['legal_profile']['current']['revision_no']);

        [$status, $pinned] = $this->http('POST', $pinPath, $body);
        self::assertSame(200, $status, json_encode($pinned));
        self::assertSame('pinned_current', $pinned['legal_profile']['state']);
        self::assertSame($opened['draft']['revision_no'] + 1, $pinned['draft']['revision_no']);
        [$status, $stale] = $this->http('POST', $pinPath, $body);
        self::assertSame(409, $status);
        self::assertSame('nonprofit_tax_draft_changed', $stale['error']['code']);

        // Skutečná změna postavení: připnout nejde, reset bez postavení v těle převezme nový profil.
        $this->confirmLegalProfile('broad_vpp', 'public_benefit_company', 2, 'http-legal-3');
        [$status, $conflict] = $this->http('POST', $pinPath, ['expected_revision' => $pinned['draft']['revision_no']]);
        self::assertSame(409, $status);
        self::assertSame('nonprofit_tax_legal_profile_conflict', $conflict['error']['code']);
        [$status, $preview] = $this->http('POST', $this->base() . '/drafts/' . $opened['draft']['id'] . '/preview', ['expected_revision' => $pinned['draft']['revision_no']]);
        self::assertSame(409, $status);
        self::assertSame('nonprofit_tax_legal_profile_conflict', $preview['error']['code']);
        [$status, $reset] = $this->http('POST', $this->base() . '/drafts/' . $opened['draft']['id'] . '/reset', ['expected_revision' => $pinned['draft']['revision_no']]);
        self::assertSame(200, $status, json_encode($reset));
        self::assertSame('broad_vpp', $reset['draft']['header']['profile']['kind']);
        self::assertSame(3, $reset['draft']['header']['legal_profile']['revision_no']);
        self::assertSame('pinned_current', $reset['legal_profile']['state']);

        // Modul SVJ zapnutý po potvrzení profilu jiného postavení: profil nejde doložit. Čtení a provedená
        // mutace konceptu vrací stav `unavailable` s kódem chyby (ne 500), náhled chybu vrátí jako 4xx.
        (new \MyInvoice\Repository\Property\PropertyProfileRepository($this->db))->save($this->supplier, ['expected_revision' => 0,
            'effective_from' => '2026-01-01T00:00:00Z', 'effective_to' => null, 'enabled' => true, 'reason' => 'Synthetic SVJ module',
            'title_ref' => 'Synthetic legal title', 'idempotency_key' => 'http-svj-' . $this->supplier], $this->actor);
        $draftPath = $this->base() . '/drafts/' . $opened['draft']['id'];
        [$status, $read] = $this->http('GET', $draftPath);
        self::assertSame(200, $status, json_encode($read));
        self::assertSame('unavailable', $read['legal_profile']['state']);
        self::assertSame('nonprofit_dppo_legal_profile_invalid', $read['legal_profile']['error']['code']);
        [$status, $patched] = $this->http('PATCH', $draftPath . '/activities', ['expected_revision' => $reset['draft']['revision_no'],
            'upserts' => [['activity_kind_id' => 'synthetic-http-activity', 'payload' => ['kind' => 'nonbusiness']]], 'deletes' => []]);
        self::assertSame(200, $status, json_encode($patched));
        self::assertSame($reset['draft']['revision_no'] + 1, $patched['draft']['revision_no']);
        self::assertSame('unavailable', $patched['legal_profile']['state']);
        [$status, $blocked] = $this->http('POST', $draftPath . '/preview', ['expected_revision' => $patched['draft']['revision_no']]);
        self::assertSame(422, $status);
        self::assertSame('nonprofit_dppo_legal_profile_invalid', $blocked['error']['code']);
        // Nový koncept ani reset postavení z nedoložitelného profilu nepřevezmou: 422 s důvodem, koncept beze změny.
        [$status, $reopen] = $this->http('POST', $this->base() . '/draft');
        self::assertSame([422, 'nonprofit_dppo_legal_profile_invalid'], [$status, $reopen['error']['code']]);
        [$status, $failedReset] = $this->http('POST', $draftPath . '/reset', ['expected_revision' => $patched['draft']['revision_no']]);
        self::assertSame([422, 'nonprofit_dppo_legal_profile_invalid'], [$status, $failedReset['error']['code']]);
        [$status, $unchanged] = $this->http('GET', $draftPath);
        self::assertSame($patched['draft']['revision_no'], $unchanged['draft']['revision_no']);
    }

    private function confirmLegalProfile(string $kind, string $entity, int $expected, string $key): array
    {
        return (new \MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService($this->db))->confirm($this->supplier, $this->period,
            ['expected_revision' => $expected, 'idempotency_key' => $key . '-' . $this->supplier, 'reason' => 'Synthetic review',
                'profile' => ['kind' => $kind, 'entity_type' => $entity, 'main_activity_nonbusiness' => true,
                    'legal_basis' => 'zdp_17a', 'evidence' => 'Synthetic statutes']], $this->actor);
    }

    private function dml(): array
    {
        $out = [];
        foreach (['Com_insert', 'Com_update', 'Com_delete'] as $name) {
            $out[$name] = (int) $this->pdo->query("SHOW SESSION STATUS LIKE '" . $name . "'")->fetch(PDO::FETCH_NUM)[1];
        }
        return $out;
    }
}
