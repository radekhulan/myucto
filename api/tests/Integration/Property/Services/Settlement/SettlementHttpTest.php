<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property\Services\Settlement;

use PDO;
use PHPUnit\Framework\TestCase;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture};
use MyInvoice\Middleware\{AuthMiddleware,SupplierScopeMiddleware,ApiScopeMiddleware,ApiVersionRewriteMiddleware,CsrfMiddleware,TenantDomainMiddleware};
use MyInvoice\Security\{EffectiveRole,PermissionCatalog};
use Slim\Psr7\Factory\{ServerRequestFactory,ResponseFactory};

final class SettlementHttpTest extends TestCase
{
    use ChargeIssuanceFixture;
    use PaymentCorrectionFixture;
    private Connection $db;
    private array $f;
    private \Slim\App $app;
    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $schema = (string)getenv('MYINVOICE_DB_NAME');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+_test$/D', $schema);
        self::assertSame($schema, (string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        self::assertSame($this->db->pdo(), Bootstrap::buildContainer()->get(Connection::class)->pdo());
        $this->db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->db->pdo()->beginTransaction();
        $container = $this->container();
        $this->app = \Slim\Factory\AppFactory::create(null, $container);
        \MyInvoice\Routes::register($this->app);
        $this->app->add($container->get(CsrfMiddleware::class));
        $this->app->add(new ApiScopeMiddleware(new ResponseFactory()));
        $this->app->add(new ApiVersionRewriteMiddleware());
    }
    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }
    private function container(): \DI\Container
    {
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $this->db);
        return $container;
    }
    private function permissions(int $manage = 2, int $post = 2, int $reverse = 2, int $read = 1): array
    {
        return ['property' => $read,'property.settlements.manage' => $manage,'property.settlements.post' => $post,'property.settlements.reverse' => $reverse];
    }
    private function http(string $method, string $path, array $body = [], ?array $permissions = null, bool $bearer = false, bool $csrf = true, array $query = [], ?int $supplier = null): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)->withParsedBody($body)->withQueryParams($query)->withAttribute(AuthMiddleware::ATTR_METHOD, $bearer ? 'bearer' : 'session')->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => 'read_write'])->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->f['actor'],'role' => 'accountant'])->withAttribute(AuthMiddleware::ATTR_SESSION, ['csrf_token' => 'synthetic-csrf'])->withAttribute('auth.effective_role', new EffectiveRole(50, 'Synthetic HTTP role', 'staff', true, $permissions ?? $this->permissions()))->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier ?? $this->f['supplier'])->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, new \MyInvoice\Service\Tenant\TenantDomainContext('canonical', 'example.test', 'https://example.test'))->withHeader('Origin', 'https://example.test');
        if ($csrf) {
            $request = $request->withHeader('X-CSRF-Token', 'synthetic-csrf');
        }$response = $this->app->handle($request);
        return [$response->getStatusCode(),json_decode((string)$response->getBody(), true, flags:JSON_THROW_ON_ERROR),$response->getHeaderLine('X-API-Version')];
    }
    private function documentRequest(array $f): array
    {
        $facts = $this->openingDocument($f)['document']->snapshot();
        return array_intersect_key($facts, array_flip(['unit_id','service_id','segment_key','period_from','period_to','cutoff_at','known_at','recipient_ids','evidence'])) + ['accounting_date' => '2026-12-31','selectors' => [$f['selector']],'cost_source_id' => $facts['cost']['source_id']];
    }
    private function saveHttp(array $request, string $token = 'http-document', int $expected = 0): array
    {
        [$status,$preview] = $this->http('POST', '/api/property/service-settlements/prepare', ['request' => $request,'expected_revision' => $expected]);
        self::assertSame(200, $status, json_encode($preview));
        [$status,$saved] = $this->http('POST', '/api/property/service-settlements', ['request' => $request,'expected_revision' => $expected,'preview_hash' => $preview['preview_hash'],'idempotency_key' => $token]);
        self::assertSame(200, $status, json_encode($saved));
        return [$saved,$preview];
    }
    private function assertRedacted(array $value): void
    {
        $forbidden = ['supplier_id','recipient_ids','evidence','document_hash','origin_receipt','origin_receipt_hash','origins','cost','hash_inputs','current_hash','snapshot_hash','preview_hash','request_hash','request','receipt','claim_title','liability_groups','parties','created_by'];
        foreach ($value as $key => $item) {
            self::assertNotContains((string)$key, $forbidden);
            if (is_array($item)) {
                $this->assertRedacted($item);
            }
        }
    }
    private function assertKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        self::assertSame($keys, $actual);
    }
    private function assertPublicCurrent(array $current): void
    {
        $this->assertKeys($current, ['document_id','row_version','application_id','active','accounting_date','credits','claims','scope','complete_financial_position']);
        foreach ($current['credits'] as $credit) {
            $this->assertKeys($credit, ['id','balance_cents','reserved_cents','available_cents']);
        }foreach ($current['claims'] as $claim) {
            $this->assertKeys($claim, ['id','active','principal_cents','paid_cents','accessories']);
            foreach ($claim['accessories'] as $accessory) {
                $this->assertKeys($accessory, ['component','amount_cents']);
            }
        }$this->assertRedacted($current);
        self::assertFalse($current['complete_financial_position']);
    }
    private function assertPublicDocument(array $doc): void
    {
        $this->assertKeys($doc, ['id','revision_no','unit','service','segment_key','period_from','period_to','cutoff_at','prescribed_cents','paid_at_cutoff_cents','actual_cents','amounts','current_summary','scope','complete_financial_position']);
        foreach (['unit','service'] as $kind) {
            $this->assertKeys($doc[$kind], ['id','code','label']);
        }$this->assertKeys($doc['amounts'], ['advance_applied_cents','new_claim_cents','cancelled_unpaid_cents','new_credit_cents','remaining_old_claim_cents']);
        $this->assertPublicCurrent($doc['current_summary']);
        $this->assertRedacted($doc);
    }
    public function testNativeHttpRoundtripPreservesCurrentMoneyIdempotencyAndCuratedPublicIsolation(): void
    {
        $f = $this->openingFixture();
        $request = $this->documentRequest($f);
        [$saved,$preview] = $this->saveHttp($request);
        $id = $saved['id'];
        self::assertSame('100000', $preview['document']['prescribed_cents']);
        self::assertSame('60000', $preview['document']['paid_cents']);
        self::assertSame('80000', $preview['document']['actual_cents']);
        $save = ['request' => $request,'expected_revision' => 0,'preview_hash' => $preview['preview_hash'],'idempotency_key' => 'http-document'];
        [$status,$retry] = $this->http('POST', '/api/property/service-settlements', $save);
        self::assertSame(200, $status, json_encode($retry));
        self::assertSame($saved, $retry);
        $second = $request;
        $second['segment_key'] = 'SECOND-2026';
        [$savedSecond] = $this->saveHttp($second, 'second-document');
        [$status,$page,$version] = $this->http('GET', '/api/v1/property/catalog/service-settlements', [], null, true, true, ['limit' => 1]);
        self::assertSame(200, $status, json_encode($page));
        self::assertSame('1', $version);
        self::assertCount(1, $page['items']);
        self::assertSame($id, $page['next_cursor']);
        $this->assertRedacted($page);
        foreach ($page['items'] as $item) {
            $this->assertPublicDocument($item);
        }[$status,$last] = $this->http('GET', '/api/v1/property/catalog/service-settlements', [], null, true, true, ['limit' => 1,'after_id' => $page['next_cursor']]);
        self::assertSame(200, $status);
        self::assertSame($savedSecond['id'], $last['items'][0]['id']);
        self::assertNull($last['next_cursor']);
        $source = $saved['document']['origins'][0];
        $debt = $source['debt_id'];
        $bank = $this->correctionBank('300.00');
        $payments = $this->correctionPayments();
        $payment = ['kind' => 'allocation','source' => ['kind' => 'bank','id' => $bank,'expected_version' => 0],'amount_cents' => '30000','accounting_date' => '2026-02-01','reason' => 'Synthetic late HTTP payment','debts' => [$debt],'payment' => ['payer_id' => $f['clients'][1],'debtor_id' => $f['clients'][1],'received_at' => '2026-02-01T10:00:00+01:00','choice' => ['claim_ids' => [$debt],'evidence' => 'Synthetic debtor choice']]];
        $op = $payments->apply($f['supplier'], $payment, $payments->preview($f['supplier'], $payment)['preview_hash'], 'http-native-payment', $f['actor']);
        $allocation = (int)$this->db->pdo()->query('SELECT id FROM unit_payment_allocations WHERE operation_id='.$op['id'])->fetchColumn();
        [$status, $live] = $this->http('GET', '/api/property/service-settlements/'.$id.'/disposition', [], null, false, true, ['accounting_date' => '2026-12-31']);
        self::assertSame(200, $status, json_encode($live));
        self::assertSame('90000', $live['current_paid_cents']);
        self::assertSame('60000', $live['origins'][0]['carried_paid_cents']);
        self::assertSame('40000', $live['origins'][0]['principal_cents']);
        self::assertSame('30000', $live['origins'][0]['paid_cents']);
        self::assertSame('10000', $live['origins'][0]['unpaid_cents']);
        self::assertSame('10000', $live['amounts']['new_credit_cents']);
        self::assertSame($allocation, $live['allocations'][0]['id']);
        self::assertFalse($live['allocations'][0]['at_cutoff']);
        self::assertFalse($live['write_authorization']);
        self::assertNotEmpty($live['accounts']);
        self::assertArrayNotHasKey('current_hash', $live);
        [$status] = $this->http('GET', '/api/property/service-settlements/'.$id.'/disposition', [], null, true, true, ['accounting_date' => '2026-12-31']);
        self::assertSame(403, $status);
        [$status] = $this->http('GET', '/api/property/service-settlements/'.$id.'/disposition', [], null, false, true, ['accounting_date' => '2026-12-31', 'supplier_id' => $f['supplier'] + 9999]);
        self::assertSame(422, $status);
        $apply = ['accounting_date' => '2026-12-31','evidence' => 'Synthetic HTTP disposition','advances' => [['origin_key' => $source['kind'].':'.$source['id'],'applied_cents' => '80000']],'reliefs' => [['allocation_id' => $allocation,'amount_cents' => '10000','entitlement' => ['party_ids' => [$f['clients'][1]],'evidence' => 'Synthetic creditor title','due_date' => '2027-04-30','account_code' => '379.910']]],'opening_credits' => [],'new_claim' => null];
        [$status,$planned] = $this->http('POST', '/api/property/service-settlements/'.$id.'/preview', ['request' => $apply,'expected_version' => 0], $this->permissions(2, 0, 0));
        self::assertSame(200, $status, json_encode($planned));
        $confirm = ['request' => $apply,'expected_version' => 0,'preview_hash' => $planned['preview_hash'],'idempotency_key' => 'http-apply'];
        $wrong = $confirm;
        $wrong['preview_hash'] = str_repeat('0', 64);
        [$status] = $this->http('POST', '/api/property/service-settlements/'.$id.'/apply', $wrong);
        self::assertSame(409, $status);
        [$status] = $this->http('POST', '/api/property/service-settlements/'.$id.'/apply', $confirm, $this->permissions(2, 0, 0));
        self::assertSame(403, $status);
        [$status,$app] = $this->http('POST', '/api/property/service-settlements/'.$id.'/apply', $confirm, $this->permissions(0, 2, 0));
        self::assertSame(200, $status, json_encode($app));
        [$status,$same] = $this->http('POST', '/api/property/service-settlements/'.$id.'/apply', $confirm, $this->permissions(0, 2, 0));
        self::assertSame(200, $status);
        self::assertSame($app, $same);
        $stale = $confirm;
        $stale['idempotency_key'] = 'stale-http-apply';
        [$status] = $this->http('POST', '/api/property/service-settlements/'.$id.'/apply', $stale, $this->permissions(0, 2, 0));
        self::assertSame(409, $status);
        $conflict = $confirm;
        $conflict['request']['evidence'] = 'Changed retry evidence';
        [$status] = $this->http('POST', '/api/property/service-settlements/'.$id.'/apply', $conflict, $this->permissions(0, 2, 0));
        self::assertSame(409, $status);
        [$status,$sameSave] = $this->http('POST', '/api/property/service-settlements', $save);
        self::assertSame(200, $status, json_encode($sameSave));
        self::assertSame($saved, $sameSave);
        [$status,$current] = $this->http('GET', '/api/v1/property/catalog/service-settlements/'.$id.'/current', [], null, true);
        self::assertSame(200, $status, json_encode($current));
        self::assertTrue($current['active']);
        self::assertSame('10000', $current['credits'][0]['balance_cents']);
        $this->assertPublicCurrent($current);
        [$status,$publicDoc] = $this->http('GET', '/api/v1/property/catalog/service-settlements/'.$id, [], null, true);
        self::assertSame(200, $status);
        $this->assertPublicDocument($publicDoc);
        self::assertSame('60000', $publicDoc['paid_at_cutoff_cents']);
        self::assertFalse($current['complete_financial_position']);
        $this->assertRedacted($current);
        $native = (new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->current($f['supplier'], $debt);
        self::assertSame('20000', (string)array_sum($native['paid']));
        $reverse = ['accounting_date' => '2026-12-31','expected_version' => 1,'reason' => 'Synthetic HTTP cancellation','idempotency_key' => 'http-reverse'];
        [$status] = $this->http('POST', '/api/property/service-settlements/applications/'.$app['id'].'/reverse', $reverse, $this->permissions(2, 2, 0));
        self::assertSame(403, $status);
        [$status,$reversed] = $this->http('POST', '/api/property/service-settlements/applications/'.$app['id'].'/reverse', $reverse, $this->permissions(0, 0, 2));
        self::assertSame(200, $status, json_encode($reversed));
        [$status,$same] = $this->http('POST', '/api/property/service-settlements/applications/'.$app['id'].'/reverse', $reverse, $this->permissions(0, 0, 2));
        self::assertSame(200, $status);
        self::assertSame($reversed, $same);
        [$status,$current] = $this->http('GET', '/api/v1/property/catalog/service-settlements/'.$id.'/current', [], null, true);
        self::assertSame(200, $status);
        self::assertFalse($current['active']);
        self::assertSame([], $current['credits']);
        $other = $this->openingFixture();
        $this->f = $f;
        foreach (['/api/property/service-settlements/'.$id,'/api/v1/property/catalog/service-settlements/'.$id,'/api/v1/property/catalog/service-settlements/'.$id.'/current'] as $path) {
            [$status] = $this->http('GET', $path, [], null, str_contains($path, '/v1/'), true, [], $other['supplier']);
            self::assertSame(404, $status, $path);
        }
    }
    public function testActualHttpCsrfSessionBearerRoleLevelsAndForeignProofBoundaries(): void
    {
        $f = $this->openingFixture();
        $request = $this->documentRequest($f);
        foreach ([0,1] as $level) {
            [$status] = $this->http('POST', '/api/property/service-settlements/prepare', ['request' => $request,'expected_revision' => 0], $this->permissions($level, $level, $level));
            self::assertSame(403, $status);
        }[$status] = $this->http('GET', '/api/property/service-settlements', [], $this->permissions(0, 0, 0, 0));
        self::assertSame(403, $status);
        [$status,$list] = $this->http('GET', '/api/property/service-settlements', [], $this->permissions(1, 1, 1));
        self::assertSame(200, $status);
        self::assertSame(['manage' => false,'post' => false,'reverse' => false], $list['capabilities']);
        [$status,$error] = $this->http('POST', '/api/property/service-settlements/prepare', ['request' => $request,'expected_revision' => 0], null, false, false);
        self::assertSame(403, $status);
        self::assertSame('csrf_failed', $error['error']['code'] ?? $error['code'] ?? null);
        foreach (['/api/property/service-settlements/prepare','/api/property/service-settlements','/api/property/service-settlements/1/preview','/api/property/service-settlements/1/apply','/api/property/service-settlements/applications/1/reverse','/api/v1/property/catalog/service-settlements'] as $path) {
            [$status] = $this->http('POST', $path, ['request' => $request,'expected_revision' => 0], null, true);
            self::assertSame(403, $status, $path);
        }[$status] = $this->http('GET', '/api/property/service-settlements', [], null, true);
        self::assertSame(403, $status);
        foreach (['supplier_id' => 999999,'origins' => [],'cost' => [],'prescribed_cents' => '1','paid_cents' => '1','actual_cents' => '1','cutoff_allocations' => []] as $key => $value) {
            $bad = $request;
            $bad[$key] = $value;
            [$status] = $this->http('POST', '/api/property/service-settlements/prepare', ['request' => $bad,'expected_revision' => 0]);
            self::assertSame(422, $status, $key);
        }[$status] = $this->http('POST', '/api/property/service-settlements/prepare', ['request' => $request,'expected_revision' => 0,'supplier_id' => 999999]);
        self::assertSame(422, $status);
        $other = $this->openingFixture();
        $bad = $request;
        $bad['selectors'] = [$other['selector']];
        $this->f = $f;
        [$status] = $this->http('POST', '/api/property/service-settlements/prepare', ['request' => $bad,'expected_revision' => 0]);
        self::assertGreaterThanOrEqual(400, $status);
        self::assertLessThan(500, $status);
        [$status] = $this->http('GET', '/api/v1/property/catalog/service-settlements', [], null, true, true, ['supplier_id' => $other['supplier']]);
        self::assertSame(422, $status);
        foreach ([['limit' => 101],['after_id' => -1],['limit' => true]] as $query) {
            [$status] = $this->http('GET', '/api/v1/property/catalog/service-settlements', [], null, true, true, $query);
            self::assertSame(422, $status);
        }
        foreach ([['POST','/api/property/service-settlements/prepare','property.settlements.manage'],['POST','/api/property/service-settlements','property.settlements.manage'],['POST','/api/property/service-settlements/2/preview','property.settlements.manage'],['POST','/api/property/service-settlements/2/apply','property.settlements.post'],['POST','/api/property/service-settlements/applications/3/reverse','property.settlements.reverse'],['GET','/api/property/catalog/service-settlements/2/current','property']] as [$method,$path,$key]) {
            $rule = (new \MyInvoice\Security\RoutePermissionMap())->match($method, $path);
            self::assertNotNull($rule);
            self::assertSame($key, $rule->key);
            self::assertSame($method === 'GET' ? 1 : 2, $rule->minimum->value);
        }
        $catalog = new PermissionCatalog();
        self::assertSame(2, $catalog->legacyPreset('accountant')['property.settlements.manage']);
        self::assertSame(2, $catalog->legacyPreset('accountant')['property.settlements.post']);
        self::assertSame(0, $catalog->legacyPreset('accountant')['property.settlements.reverse'] ?? 0);
        self::assertSame('cz_504', (string)$this->db->pdo()->query('SELECT accounting_framework FROM supplier_accounting_frameworks WHERE supplier_id='.$f['supplier'])->fetchColumn());
    }
    public function testPublicNewClaimAndAccessoryExposeOnlyStrictCurrentFields(): void
    {
        extract($this->newClaimFixture());
        $debts = new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db);
        $debt = $debts->current($f['supplier'], $result['debt_ids'][0]);
        $dims = $debt['snapshot']['lines'][0]['ledger_line']['dimensions'];
        $entry = $this->container()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($f['supplier'], 'manual', null, [['account_code' => '315.210','side' => 'debit','amount' => '10.00','dimensions' => $dims],['account_code' => '644','side' => 'credit','amount' => '10.00','dimensions' => $dims]], ['entry_date' => '2026-12-31','posted_by' => $f['actor']]);
        $line = (int)$this->db->pdo()->query('SELECT MIN(id) FROM journal_entry_lines WHERE entry_id='.$entry)->fetchColumn();
        $debts->bindAccessory($f['supplier'], $debt['id'], ['journal_entry_id' => $entry,'journal_line_id' => $line,'component' => 'default_interest','evidence' => 'Synthetic private accessory evidence','liability_evidence' => 'Synthetic private party liability'], $f['actor']);
        [$status,$current] = $this->http('GET', '/api/v1/property/catalog/service-settlements/'.$saved['id'].'/current', [], null, true);
        self::assertSame(200, $status, json_encode($current));
        $this->assertPublicCurrent($current);
        self::assertSame('200000', $current['claims'][0]['principal_cents']);
        self::assertSame('0', $current['claims'][0]['paid_cents']);
        self::assertSame([['component' => 'default_interest','amount_cents' => '1000']], $current['claims'][0]['accessories']);
        [$status,$doc] = $this->http('GET', '/api/v1/property/catalog/service-settlements/'.$saved['id'], [], null, true);
        self::assertSame(200, $status, json_encode($doc));
        $this->assertPublicDocument($doc);
        self::assertSame($current, $doc['current_summary']);
    }
    public function testNativeSessionTokenSupplierAndPermissionMiddlewareProtectActualRoutes(): void
    {
        $f = $this->openingFixture();
        $request = $this->documentRequest($f);
        [$saved] = $this->saveHttp($request);
        $other = $this->openingFixture();
        $this->f = $f;
        $pdo = $this->db->pdo();
        $config = $this->container()->get(\MyInvoice\Infrastructure\Config\Config::class);
        $call = function (string $method, string $path, array $body, array $session, ?string $token = null, ?int $supplier = null, bool $csrf = true) use ($config): array {
            $container = $this->container();
            $app = \Slim\Factory\AppFactory::create(null, $container);
            \MyInvoice\Routes::register($app);
            foreach ([CsrfMiddleware::class,ApiScopeMiddleware::class,\MyInvoice\Middleware\PermissionMiddleware::class,SupplierScopeMiddleware::class,AuthMiddleware::class] as $middleware) {
                $app->add($container->get($middleware));
            }$app->add(new ApiVersionRewriteMiddleware());
            $q = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])->withParsedBody($body)->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, new \MyInvoice\Service\Tenant\TenantDomainContext('canonical', 'example.test', 'https://example.test'))->withHeader('Origin', 'https://example.test')->withHeader('X-Supplier-Id', (string)($supplier ?? $this->f['supplier']));
            if ($token !== null) {
                $q = $q->withHeader('Authorization', 'Bearer '.$token);
            } else {
                $q = $q->withCookieParams([(string)$config->get('session.cookie_name', '__Host-myinvoice_session') => $session['token']]);
            }if ($csrf) {
                $q = $q->withHeader('X-CSRF-Token', $session['csrf_token']);
            }$response = $app->handle($q);
            return [$response->getStatusCode(),json_decode((string)$response->getBody(), true, flags:JSON_THROW_ON_ERROR)];
        };
        foreach ([0,1,2] as $level) {
            $pdo->prepare("INSERT INTO roles(name,role_type,is_active) VALUES (?,'staff',1)")->execute(['Synthetic S8 native role '.$level]);
            $role = (int)$pdo->lastInsertId();
            foreach ($this->permissions($level, $level, $level) as $key => $value) {
                $pdo->prepare('INSERT INTO role_permissions(role_id,permission_key,access_level) VALUES (?,?,?)')->execute([$role,$key,$value]);
            }
            $pdo->prepare("INSERT INTO users(email,password_hash,name,role,role_id,locale,is_active) VALUES (?,?,'Synthetic S8 native user','readonly',?,'cs',1)")->execute(['s8-native-'.bin2hex(random_bytes(6)).'@example.test',password_hash('Synthetic fixture password', PASSWORD_DEFAULT),$role]);
            $user = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO user_suppliers(user_id,supplier_id) VALUES (?,?)')->execute([$user,$f['supplier']]);
            $container = $this->container();
            $session = $container->get(\MyInvoice\Service\Auth\SessionManager::class)->createInTransaction($pdo, $container->get(\MyInvoice\Service\Auth\SecurityClock::class)->capture($pdo), $user, '127.0.0.1', 'Synthetic S8 HTTP', \MyInvoice\Service\Auth\SessionAuthContext::basic('password'));
            [$status,$body] = $call('POST', '/api/property/service-settlements/prepare', ['request' => $request,'expected_revision' => 1], $session);
            self::assertSame($level === 2 ? 200 : 403, $status, json_encode($body));
            [$status,$body] = $call('GET', '/api/property/service-settlements/'.$saved['id'], [], $session);
            self::assertSame(200, $status, json_encode($body));
            [$status] = $call('GET', '/api/property/service-settlements/'.$saved['id'], [], $session, null, $other['supplier']);
            self::assertSame(403, $status);
            if ($level === 2) {
                [$status,$body] = $call('POST', '/api/property/service-settlements/prepare', ['request' => $request,'expected_revision' => 1], $session, null, null, false);
                self::assertSame(403, $status);
                self::assertSame('csrf_failed', $body['error']['code'] ?? $body['code'] ?? null);
                foreach (['read','read_write'] as $scope) {
                    $token = $container->get(\MyInvoice\Service\Auth\ApiTokenService::class)->generateInTransaction($pdo, $user, $f['supplier'], 'Synthetic S8 '.$scope, $scope);
                    foreach (['/api/property/service-settlements/prepare','/api/property/service-settlements','/api/property/service-settlements/'.$saved['id'].'/preview','/api/property/service-settlements/'.$saved['id'].'/apply','/api/property/service-settlements/applications/1/reverse'] as $path) {
                        [$status,$body] = $call('POST', $path, ['request' => $request,'expected_revision' => 1], $session, $token['plaintext']);
                        self::assertSame(403, $status, $path.' '.json_encode($body));
                    }
                    [$status,$body] = $call('GET', '/api/v1/property/catalog/service-settlements/'.$saved['id'], [], $session, $token['plaintext'], $other['supplier']);
                    self::assertSame(200, $status, json_encode($body));
                    self::assertSame($saved['id'], $body['id']);
                    $this->assertPublicDocument($body);
                }
            }
        }
    }
    public function testS3CatalogKeepsUnrelatedValidSourcesAfterNativePostedCorrection(): void
    {
        extract($this->negativeServiceCorrectionFixture(false));
        $done = $correction->apply($f['supplier'], $request, $preview['preview_hash'], 'catalog-negative-correction', $f['actor']);
        self::assertSame('posted', $done['status']);
        self::assertSame('100000', (new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->current($f['supplier'], $source['debt_id'])['reductions'][$source['id']]);
        $issue = $correctionIssue; $issue['issue_mode'] = 'initial'; $issue['source_key'] = 'SYNTHETIC-CATALOG-INDEPENDENT'; $issue['case_identity']['case_ref'] = 'SYNTHETIC-CATALOG-INDEPENDENT'; $issue['case_identity']['title_ref'] = 'Synthetic independent legal title';
        foreach ($issue['units'] as &$unit) { $unit['expected_revision'] = 0; $unit['previous_charge_id'] = null; $unit['case_distinction'] = ['against_source_keys' => [$f['issue_request']['source_key']], 'legal_reason' => 'Separate synthetic service obligation under another title', 'evidence_ref' => 'Synthetic separate decision']; } unset($unit);
        $repo = $this->container()->get(IssuanceRepository::class); $p = (new IssuancePreviewService($this->db))->preview($f['supplier'], $issue);
        $batch = $repo->issue($f['supplier'], ['request' => $issue, 'preview_hash' => $p['preview_hash'], 'idempotency_key' => 'catalog-independent-issue'], $f['actor']);
        $expected = []; foreach ($batch['charges'] as $row) $expected[] = ['id' => $row['id'], 'row_version' => $row['row_version']]; $repo->post($f['supplier'], $batch['id'], $expected, $f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
        $q = $this->db->pdo()->prepare('SELECT l.id,l.charge_id FROM unit_charge_lines l JOIN unit_charges c ON c.id=l.charge_id AND c.supplier_id=l.supplier_id WHERE c.supplier_id=? AND c.batch_id=? AND c.unit_id=? AND l.service_id=?'); $q->execute([$f['supplier'], $batch['id'], $f['units']['U02'], $f['service']['id']]); $valid = $q->fetch(PDO::FETCH_ASSOC); self::assertIsArray($valid);
        (new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->register($f['supplier'], ['charge_id' => (int)$valid['charge_id'], 'expected_version' => 2, 'principal_line_ids' => [(int)$valid['id']], 'title_ref' => 'Synthetic independent debt', 'due_date' => '2026-01-15', 'legal' => ['basis' => 'legal_act', 'legal_act_date' => '2025-12-01', 'evidence' => 'Synthetic independent decision', 'regime' => 'general'], 'security' => ['kind' => 'unsecured', 'evidence' => 'Synthetic no security'], 'damages_for' => null], 'catalog-independent-debt', $f['actor']);
        $query = ['unit_id' => $f['units']['U02'], 'service_id' => $f['service']['id'], 'date' => '2026-12-31', 'limit' => 1];
        [$status, $page] = $this->http('GET', '/api/property/service-settlements/catalog/s3', [], null, false, true, $query);
        self::assertSame(200, $status, json_encode($page)); self::assertSame([(int)$valid['id']], array_column($page['items'], 'id')); self::assertSame('1100000', $page['items'][0]['prescribed_cents']); self::assertNull($page['next_cursor']);
        [$status, $last] = $this->http('GET', '/api/property/service-settlements/catalog/s3', [], null, false, true, $query + ['after_id' => (int)$valid['id']]); self::assertSame(200, $status); self::assertSame([], $last['items']); self::assertNull($last['next_cursor']);
        try { $this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'], $f['units']['U02'], $f['service']['id'], '2026-12-31', [['kind' => 's3_line', 'id' => $source['id']]]); self::fail('Corrected original remained eligible'); } catch (\DomainException $e) { self::assertSame('settlement_source_already_corrected', $e->getMessage()); }
    }
    public function testCatalogReturnsOwnerVerifiedDescriptiveSourcesAndCosts(): void
    {
        extract($this->nativeFixture('1400000'));
        $query = ['unit_id' => $f['units']['U02'],'service_id' => $f['service']['id'],'date' => '2026-12-31','limit' => 1];
        [$status,$sources] = $this->http('GET', '/api/property/service-settlements/catalog/s3', [], null, false, true, $query);
        self::assertSame(200, $status, json_encode($sources));
        self::assertCount(1, $sources['items']);
        self::assertSame(['kind' => 's3_line','id' => $selector['id']], $sources['items'][0]['selector']);
        self::assertNotSame('', $sources['items'][0]['description']);
        self::assertSame('1200000', $sources['items'][0]['prescribed_cents']);
        [$status,$costs] = $this->http('GET', '/api/property/service-settlements/catalog/costs', [], null, false, true, $query + ['s3_line_id' => $selector['id']]);
        self::assertSame(200, $status, json_encode($costs));
        self::assertSame($selector['cost_line_id'], $costs['items'][0]['cost_source_id']);
        self::assertSame('1400000', $costs['items'][0]['cost_cents']);
        self::assertNotSame('', $costs['items'][0]['accounting_date']);
        $opening = $this->openingFixture();
        $query = ['unit_id' => $opening['units']['U02'],'service_id' => $opening['service']['id'],'date' => '2026-12-31'];
        [$status,$sources] = $this->http('GET', '/api/property/service-settlements/catalog/opening', [], null, false, true, $query);
        self::assertSame(200, $status, json_encode($sources));
        self::assertSame($opening['selector'], $sources['items'][0]['selector']);
        self::assertSame('80000', $sources['items'][0]['cost_cents']);
        self::assertFalse($sources['write_authorization']);
    }

    private function externalDossier(array $f, bool $changed = false): array
    {
        $recipient = $f['clients'][1];
        $segments = [['ordinal' => 1,'valid_from' => '2026-01-01T00:00:00+01:00','valid_to' => $changed ? '2026-07-01T00:00:00+02:00' : '2027-01-01T00:00:00+01:00','recipient_ids' => [$recipient],'basis_ref' => 'Synthetic service recipient agreement','allocation_ref' => 'Synthetic external key A']];
        $changes = [];
        if ($changed) {
            $segments[] = ['ordinal' => 2,'valid_from' => '2026-07-01T00:00:00+02:00','valid_to' => '2027-01-01T00:00:00+01:00','recipient_ids' => [$f['clients'][2]],'basis_ref' => 'Synthetic successor recipient agreement','allocation_ref' => 'Synthetic external key B'];
            $changes[] = ['role' => 'service_recipient','from_ids' => [$recipient],'to_ids' => [$f['clients'][2]],'effective_at' => '2026-07-01T00:00:00+02:00','basis' => 'agreement','evidence' => 'Synthetic documented transfer of service recipient'];
        }
        $deliveries = []; foreach ($segments as $segment) foreach ($segment['recipient_ids'] as $id) $deliveries[] = ['segment_ordinal' => $segment['ordinal'],'recipient_id' => $id,'issued_to_id' => $id,'delivered_to_id' => null,'recipient_evidence' => '','status' => 'pending','delivered_at' => null,'method' => null,'delivery_evidence' => '','due' => ['date' => null,'basis_ref' => '']];
        return ['schema' => 1,'ref' => 'Synthetic external dossier 2026','issuer' => ['name' => 'Synthetic settlement provider'],'issued_at' => '2027-04-20T10:00:00+02:00','allocation_ref' => 'Synthetic external documented allocation','segments' => $segments,'deliveries' => $deliveries,'person_changes' => $changes];
    }
    private function financialFingerprint(int $supplier): array
    {
        $out = []; foreach (['property_service_settlements','property_service_settlement_origins','property_service_settlement_requests','property_service_settlement_applications','property_service_settlement_effects','property_service_settlement_heads','unit_payment_debts','unit_payment_allocations','unit_credit_lots','unit_credit_parties','journal_entries','journal_entry_lines'] as $table) {
            $q = $this->db->pdo()->prepare('SELECT * FROM '.$table.' WHERE supplier_id=? ORDER BY 1,2'); $q->execute([$supplier]);
            $out[$table] = \MyInvoice\Service\Property\PropertyInput::hash($q->fetchAll(PDO::FETCH_ASSOC));
        } return $out;
    }
    public function testExternalDossierKeepsPerSegmentRecipientsDeliveryAndDueSeparateFromAccounting(): void
    {
        $f = $this->openingFixture(); $request = $this->documentRequest($f); $request['recipient_ids'] = [$f['clients'][2],$f['clients'][1]]; sort($request['recipient_ids']);
        $request['known_at'] = '2027-04-21T12:00:00+02:00'; $request['external_dossier'] = $this->externalDossier($f, true);
        [$saved,$prepared] = $this->saveHttp($request, 'external-dossier');
        self::assertCount(2, $saved['document']['external_dossier']['segments']); self::assertSame('2026-12-31', $saved['origin_receipt']['request']['accounting_date']);
        self::assertSame('pending', $saved['document']['external_dossier']['deliveries'][0]['status']);
        [$status,$detail] = $this->http('GET', '/api/property/service-settlements/'.$saved['id']); self::assertSame(200,$status); self::assertSame(0,$detail['dossier']['revision_no']);
        self::assertSame($saved['document_hash'], $detail['dossier']['document_hash']);
        [$status,$public] = $this->http('GET','/api/v1/property/catalog/service-settlements/'.$saved['id'], [], null, true); self::assertSame(200,$status); $this->assertPublicDocument($public); self::assertArrayNotHasKey('dossier',$public); self::assertArrayNotHasKey('external_dossier',$public);
        $bad = $request; $bad['external_dossier']['person_changes'] = [];
        [$status,$error] = $this->http('POST','/api/property/service-settlements/prepare',['request'=>$bad,'expected_revision'=>0]); self::assertSame(409,$status); self::assertSame('settlement_dossier_recipient_change_title_required',$error['error']['code']);
    }
    public function testDossierDeliveryAndLegalEventsAfterActiveApplicationNeverRewriteFinancialHistory(): void
    {
        extract($this->appliedFixture()); $before = $this->financialFingerprint($f['supplier']); $baseHash = $saved['document_hash'];
        $url = '/api/property/service-settlements/'.$saved['id'].'/dossier-events';
        $accept = ['event'=>['type'=>'acceptance','dossier'=>$this->externalDossier($f)],'expected_revision'=>0,'reason'=>'Synthetic explicit external dossier acceptance','idempotency_key'=>'dossier-acceptance'];
        [$status,$accepted] = $this->http('POST',$url,$accept); self::assertSame(200,$status,json_encode($accepted)); self::assertFalse($accepted['financial_history_changed']);
        $delivery = $accept['event']['dossier']['deliveries'][0]; $delivery = array_replace($delivery,['status'=>'delivered','delivered_to_id'=>$f['clients'][2],'recipient_evidence'=>'Synthetic authority to receive for recipient','delivered_at'=>'2027-05-03T09:30:00+02:00','method'=>'data_box','delivery_evidence'=>'Synthetic external receipt A','due'=>['date'=>'2027-06-20','basis_ref'=>'Synthetic individually documented due agreement']]);
        $append = ['event'=>['type'=>'delivery','delivery'=>$delivery,'supersedes_event_id'=>$accepted['id']],'expected_revision'=>1,'reason'=>'Synthetic receipt appended after financial application','idempotency_key'=>'dossier-delivery'];
        [$status,$receipt] = $this->http('POST',$url,$append); self::assertSame(200,$status,json_encode($receipt));
        [$status,$retry] = $this->http('POST',$url,$append); self::assertSame(200,$status); self::assertSame($receipt,$retry);
        $stale = $append; $stale['idempotency_key'] = 'dossier-stale'; [$status,$error] = $this->http('POST',$url,$stale); self::assertSame(409,$status); self::assertSame('settlement_dossier_revision_conflict',$error['error']['code']);
        $conflict = $append; $conflict['reason'] = 'Changed token payload'; [$status,$error] = $this->http('POST',$url,$conflict); self::assertSame(409,$status); self::assertSame('settlement_dossier_idempotency_conflict',$error['error']['code']);
        $legal = ['event'=>['type'=>'legal_metadata','person_change'=>['role'=>'creditor','from_ids'=>[$f['clients'][1]],'to_ids'=>[$f['clients'][2]],'effective_at'=>'2027-05-04T10:00:00+02:00','basis'=>'assignment','evidence'=>'Synthetic creditor assignment evidence only']],'expected_revision'=>2,'reason'=>'Synthetic legal evidence supplement without financial entitlement mutation','idempotency_key'=>'dossier-legal'];
        [$status,$legalReceipt] = $this->http('POST',$url,$legal); self::assertSame(200,$status,json_encode($legalReceipt));
        [$status,$read] = $this->http('GET','/api/property/service-settlements/'.$saved['id'].'/dossier'); self::assertSame(200,$status,json_encode($read)); self::assertSame(3,$read['revision_no']); self::assertCount(3,$read['events']); self::assertSame($baseHash,$read['document_hash']);
        self::assertSame('2027-05-03T07:30:00.000000Z',$read['dossier']['deliveries'][0]['delivered_at']); self::assertSame('2027-06-20',$read['dossier']['deliveries'][0]['due']['date']); self::assertSame($f['clients'][1],$read['dossier']['deliveries'][0]['recipient_id']); self::assertSame($f['clients'][2],$read['dossier']['deliveries'][0]['delivered_to_id']);
        $corrected = $append; $corrected['expected_revision'] = 3; $corrected['idempotency_key'] = 'dossier-explicit-receipt-correction'; $corrected['event']['supersedes_event_id'] = $receipt['id']; $corrected['event']['delivery']['delivered_at'] = '2027-05-04T09:30:00+02:00'; $corrected['reason'] = 'Synthetic explicit correction of external receipt time';
        [$status,$correctedReceipt] = $this->http('POST',$url,$corrected); self::assertSame(200,$status,json_encode($correctedReceipt));
        [$status,$history] = $this->http('GET','/api/property/service-settlements/'.$saved['id'].'/dossier'); self::assertSame(200,$status); self::assertCount(4,$history['events']); self::assertSame('2027-05-03T07:30:00.000000Z',$history['events'][1]['event']['delivery']['delivered_at']); self::assertSame('2027-05-04T07:30:00.000000Z',$history['dossier']['deliveries'][0]['delivered_at']);
        $wrongReceipt = $corrected; $wrongReceipt['expected_revision'] = 4; $wrongReceipt['idempotency_key'] = 'dossier-wrong-receipt-parent';
        [$status,$error] = $this->http('POST',$url,$wrongReceipt); self::assertSame(409,$status); self::assertSame('settlement_dossier_receipt_conflict',$error['error']['code']);
        foreach (['UPDATE property_service_settlement_dossier_events SET request_hash=request_hash WHERE supplier_id=? AND id=?','DELETE FROM property_service_settlement_dossier_events WHERE supplier_id=? AND id=?'] as $sql) {
            try { $this->db->pdo()->prepare($sql)->execute([$f['supplier'],$receipt['id']]); self::fail('Immutable dossier event was changed'); } catch (\PDOException $e) { self::assertSame('45000',$e->getCode()); }
        }
        self::assertSame($before,$this->financialFingerprint($f['supplier']));
        foreach ([[true,true,$this->permissions(),403],[false,false,$this->permissions(),403],[false,true,$this->permissions(1),403],[false,true,$this->permissions(0),403]] as [$bearer,$csrf,$permissions,$expectedStatus]) { [$status] = $this->http('POST',$url,$legal,$permissions,$bearer,$csrf); self::assertSame($expectedStatus,$status); }
        $foreign = $this->openingFixture(); $this->f = $f;
        [$status] = $this->http('POST',$url,$legal,null,false,true,[],$foreign['supplier']); self::assertSame(404,$status);
        $foreignParty = $legal; $foreignParty['event']['person_change']['to_ids'] = [$foreign['clients'][2]]; $foreignParty['expected_revision'] = 4; $foreignParty['idempotency_key'] = 'dossier-foreign'; [$status] = $this->http('POST',$url,$foreignParty); self::assertSame(422,$status);
        $financial = $legal; $financial['event']['amount_cents'] = '1'; [$status] = $this->http('POST',$url,$financial); self::assertSame(422,$status);
        self::assertSame($before,$this->financialFingerprint($f['supplier']));
    }
    public static function dossierContactKinds(): array
    {
        return [['accepted'],['representative'],['legal_metadata']];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('dossierContactKinds')]
    public function testNativeClientDeleteRetainsDossierOnlyPersonsIncludingSupersededEvidence(string $kind): void
    {
        $f = $this->openingFixture();
        $country = (int)$this->db->pdo()->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();
        $currency = (int)$this->db->pdo()->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn(); $people = [];
        foreach (['evidenced','successor','unrelated'] as $label) {
            $this->db->pdo()->prepare('INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) VALUES (?,?,?,?,?,?,?,?)')->execute([$f['supplier'],'Synthetic dossier '.$label,'Synthetic','Synthetic','10000',$country,'dossier-'.$label.'@example.test',$currency]); $people[$label] = (int)$this->db->pdo()->lastInsertId();
        }
        $request = $this->documentRequest($f); $dossier = $this->externalDossier($f);
        $delivery = array_replace($dossier['deliveries'][0], ['status'=>'delivered','delivered_to_id'=>$people['evidenced'],'recipient_evidence'=>'Synthetic representative authority','delivered_at'=>'2027-05-03T09:00:00+02:00','method'=>'post','delivery_evidence'=>'Synthetic original personal receipt']);
        if ($kind === 'accepted') { $dossier['deliveries'][0] = $delivery; $request['external_dossier'] = $dossier; $request['known_at'] = '2027-05-04T10:00:00+02:00'; }
        [$saved] = $this->saveHttp($request,'dossier-retention-document');
        $repo = $this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDossierRepository::class);
        if ($kind !== 'accepted') {
            $accepted = $repo->append($f['supplier'],$saved['id'],['type'=>'acceptance','dossier'=>$dossier],0,'Synthetic acceptance','dossier-retention-accept',$f['actor']);
            if ($kind === 'representative') {
                $first = $repo->append($f['supplier'],$saved['id'],['type'=>'delivery','delivery'=>$delivery,'supersedes_event_id'=>$accepted['id']],1,'Synthetic original receipt','dossier-retention-receipt',$f['actor']);
                $delivery['delivered_to_id'] = $people['successor'];
                $repo->append($f['supplier'],$saved['id'],['type'=>'delivery','delivery'=>$delivery,'supersedes_event_id'=>$first['id']],2,'Synthetic explicitly corrected representative','dossier-retention-successor',$f['actor']);
            } else {
                $change = ['role'=>'creditor','from_ids'=>[$f['clients'][1]],'to_ids'=>[$people['evidenced']],'effective_at'=>'2027-05-03T09:00:00+02:00','basis'=>'assignment','evidence'=>'Synthetic legal change to independent metadata-only contact'];
                $repo->append($f['supplier'],$saved['id'],['type'=>'legal_metadata','person_change'=>$change],1,'Synthetic original legal evidence','dossier-retention-legal',$f['actor']);
                $change['from_ids'] = [$people['evidenced']]; $change['to_ids'] = [$people['successor']];
                $repo->append($f['supplier'],$saved['id'],['type'=>'legal_metadata','person_change'=>$change],2,'Synthetic subsequent legal evidence','dossier-retention-legal-next',$f['actor']);
            }
        }
        $references = new \MyInvoice\Service\Property\Services\Settlement\SettlementDossierReferences($this->db);
        self::assertContains($people['evidenced'],$references->allPartyIds($f['supplier'],true)); self::assertNotContains($people['unrelated'],$references->allPartyIds($f['supplier'],true));
        $foreign = $this->openingFixture(); $this->f = $f;
        self::assertFalse($references->clientHasHistory($foreign['supplier'],$people['evidenced'],true));
        [$status] = $this->http('DELETE','/api/clients/'.$people['evidenced'],[],null,false,true,[],$foreign['supplier']); self::assertSame(404,$status);
        [$status,$deleted] = $this->http('DELETE','/api/clients/'.$people['unrelated']); self::assertSame(200,$status,json_encode($deleted)); self::assertTrue($deleted['deleted']);
        [$status,$blocked] = $this->http('DELETE','/api/clients/'.$people['evidenced']); self::assertSame(409,$status,json_encode($blocked)); self::assertSame('has_dependencies',$blocked['error']['code']);
        self::assertSame(1,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM clients WHERE id='.$people['evidenced'])->fetchColumn());
        $read = $repo->read($f['supplier'],$saved['id']); self::assertContains($people['evidenced'],array_column($read['parties'],'id'));
    }
    public static function releasedCatalogs(): array
    {
        return [['s3'], ['costs']];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('releasedCatalogs')]
    public function testTypedReversalReleasesS3AndCostCatalogs(string $kind): void
    {
        extract($this->newClaimFixture());
        $query = ['unit_id' => $f['units']['U02'], 'service_id' => $f['service']['id'], 'date' => '2026-12-31', 'limit' => 1, 's3_line_id' => $source['id']];
        [$status, $page] = $this->http('GET', '/api/property/service-settlements/catalog/'.$kind, [], null, false, true, $query);
        self::assertSame(200, $status, json_encode($page)); self::assertSame([], $page['items']);
        $owner->reverse($f['supplier'], $result['id'], '2026-12-31', 1, 'Synthetic catalog release', 'catalog-s3-release', $f['actor']);
        [$status, $page] = $this->http('GET', '/api/property/service-settlements/catalog/'.$kind, [], null, false, true, $query);
        self::assertSame(200, $status, json_encode($page)); self::assertCount(1, $page['items']); self::assertSame('1200000', $page['items'][0]['prescribed_cents']);
        self::assertSame($source['id'], $page['items'][0]['selector']['id']);
        if ($kind === 'costs') self::assertSame('1400000', $page['items'][0]['cost_cents']);
    }
    public function testTypedReversalReleasesOpeningCatalog(): void
    {
        extract($this->appliedFixture());
        $query = ['unit_id' => $f['units']['U02'], 'service_id' => $f['service']['id'], 'date' => '2026-12-31'];
        [$status, $page] = $this->http('GET', '/api/property/service-settlements/catalog/opening', [], null, false, true, $query);
        self::assertSame(200, $status, json_encode($page)); self::assertSame([], $page['items']);
        $owner->reverse($f['supplier'], $result['id'], '2026-12-31', 1, 'Synthetic catalog release', 'catalog-opening-release', $f['actor']);
        [$status, $page] = $this->http('GET', '/api/property/service-settlements/catalog/opening', [], null, false, true, $query);
        self::assertSame(200, $status, json_encode($page)); self::assertCount(1, $page['items']); self::assertSame($f['selector'], $page['items'][0]['selector']); self::assertSame('80000', $page['items'][0]['cost_cents']);
    }
    public function testOpeningCatalogPagesPastProvenIncompatiblePairs(): void
    {
        $f = $this->openingFixture(true);
        $query = ['unit_id' => $f['units']['U02'], 'service_id' => $f['service']['id'], 'date' => '2026-12-31', 'limit' => 1];
        [$status, $first] = $this->http('GET', '/api/property/service-settlements/catalog/opening', [], null, false, true, $query);
        self::assertSame(200, $status, json_encode($first)); self::assertCount(1, $first['items']); self::assertSame($f['selector'], $first['items'][0]['selector']); self::assertSame('80000', $first['items'][0]['cost_cents']); self::assertNotNull($first['next_cursor']);
        [$status, $last] = $this->http('GET', '/api/property/service-settlements/catalog/opening', [], null, false, true, $query + $first['next_cursor']);
        self::assertSame(200, $status, json_encode($last)); self::assertCount(1, $last['items']); self::assertSame($f['second_selector'], $last['items'][0]['selector']); self::assertSame('150000', $last['items'][0]['prescribed_cents']); self::assertSame('120000', $last['items'][0]['cost_cents']); self::assertNull($last['next_cursor']);
    }
    private function openingFixture(bool $multiple = false): array
    {
        $f = $this->chargeFixture($this->db);
        $this->f = $f;
        $supplier = $f['supplier'];
        (new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($supplier, 2026, '2026-01-01', '2026-12-31');
        (new \MyInvoice\Service\Accounting\ChartOfAccountsSeeder($this->db))->seedForSupplier($supplier, '2026-01-01');
        $accounts = new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);
        foreach (['315.100','315.210','324.110','379.900','379.910'] as $code) {
            if ($accounts->findByCode($supplier, $code) !== null) {
                continue;
            }$parent = $accounts->findByCode($supplier, substr($code, 0, 3));
            $accounts->insert($supplier, ['account_code' => $code,'name' => 'Synthetic '.$code,'account_type' => $parent['account_type'],'normal_side' => $parent['normal_side'],'parent_id' => $parent['id']]);
        }
        $common = ['unit_id' => $f['units']['U02'],'service_id' => $f['service']['id'],'origin_at' => '2026-01-01T00:00:00+01:00','period_from' => '2026-01-01','period_to' => '2026-01-01','due_date' => '2026-01-15','occurrence_ref' => '2026','title_ref' => 'Synthetic title','evidence_ref' => 'Synthetic takeover reconciliation'];
        $links = [['case_ref' => 'unpaid-service','occurrence_ref' => '2026','amount_cents' => '40000']];
        $debt = $common + ['case_ref' => 'unpaid-service','kind' => 'debt','components' => [['key' => 'principal','role' => 'principal','account_code' => '315.100','amount_cents' => '40000']],'liability_groups' => [['group_key' => 'A','role' => 'debtor','mode' => 'individual','basis' => 'documented_title','members' => [$f['clients'][1]],'extent' => ['num' => '1','den' => '1'],'evidence_ref' => 'Synthetic title','legal_reason' => 'Synthetic personal claim','valid_from' => '2026-01-01T00:00:00+01:00','valid_to' => null]],'legal' => ['basis' => 'legal_act','legal_act_date' => '2025-12-15','evidence' => 'Synthetic decision','regime' => 'general'],'security' => ['kind' => 'unsecured','evidence' => 'Synthetic finding']];
        $advance = $common + ['case_ref' => 'service-advance','kind' => 'advance','components' => [['key' => 'advance','role' => 'service_advance','account_code' => '324.110','amount_cents' => '100000']],'advance_state' => ['purpose_ref' => 'Synthetic services','settlement_due_on' => '2027-04-30','prescribed_cents' => '100000','paid_cents' => '60000','used_cents' => '0','unpaid_cases' => $links]];
        $cost = $common + ['case_ref' => 'service-cost','kind' => 'service_carry','components' => [['key' => 'cost','role' => 'service_cost','account_code' => '315.210','amount_cents' => '80000']],'service_state' => ['profile' => 'service_balance_sheet','allocation_ref' => 'Synthetic service key','counterpart_ref' => 'Synthetic supplier evidence','prescribed_cents' => '100000','paid_cents' => '60000','settled_cents' => '0','cancelled_unpaid_cents' => '0','unpaid_cases' => $links,'recipient_ids' => [$f['clients'][1]]]];
        $request = ['mode' => 'post_new','accounting_date' => '2026-01-01','effective_at' => '2026-01-01T00:00:00+01:00','expected_revision' => 0,'evidence_ref' => 'Synthetic signed takeover','reason' => 'Synthetic opening','items' => [$debt,$advance,$cost],'other_balances' => [['account_code' => '321','side' => 'credit','amount_cents' => '20000','dimensions' => []]]];
        if ($multiple) {
            $debtB = $debt; $debtB['case_ref'] = 'unpaid-service-B'; $debtB['components'][0]['amount_cents'] = '60000';
            $linksB = [['case_ref' => 'unpaid-service-B', 'occurrence_ref' => '2026', 'amount_cents' => '60000']];
            $advanceB = $advance; $advanceB['case_ref'] = 'service-advance-B'; $advanceB['components'][0]['amount_cents'] = '150000'; $advanceB['advance_state'] = array_replace($advance['advance_state'], ['prescribed_cents' => '150000', 'paid_cents' => '90000', 'unpaid_cases' => $linksB]);
            $costB = $cost; $costB['case_ref'] = 'service-cost-B'; $costB['components'][0]['amount_cents'] = '120000'; $costB['service_state'] = array_replace($cost['service_state'], ['prescribed_cents' => '150000', 'paid_cents' => '90000', 'unpaid_cases' => $linksB]);
            array_push($request['items'], $debtB, $advanceB, $costB); $request['other_balances'][0]['amount_cents'] = '50000';
        }
        $repo = $this->container()->get(\MyInvoice\Repository\Property\Opening\OpeningRepository::class);
        $preview = $repo->preview($supplier, $request);
        $result = $repo->apply($supplier, $request, $preview['preview_hash'], 'native-s8-opening', $f['actor']);
        $q = $this->db->pdo()->prepare('SELECT id,kind,case_key FROM property_opening_items WHERE supplier_id=? AND batch_id=? ORDER BY id');
        $q->execute([$supplier,$result['id']]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $secondKeys = array_map(fn ($ref) => \MyInvoice\Service\Property\PropertyInput::hash(['case_ref' => $ref, 'occurrence_ref' => '2026']), ['unpaid-service-B', 'service-advance-B', 'service-cost-B']);
        $items = array_column(array_filter($rows, fn ($row) => !in_array($row['case_key'], $secondKeys, true)), 'id', 'kind');
        $second = array_column(array_filter($rows, fn ($row) => in_array($row['case_key'], $secondKeys, true)), 'id', 'kind');
        return $f + ['selector' => ['kind' => 'opening','advance_item_id' => (int)$items['advance'],'service_item_id' => (int)$items['service_carry']],'opening' => $result, 'second_selector' => $multiple ? ['kind' => 'opening', 'advance_item_id' => (int)$second['advance'], 'service_item_id' => (int)$second['service_carry']] : null];
    }
    private function openingDocument(array $f): array
    {
        $current = $this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'], $f['units']['U02'], $f['service']['id'], '2026-12-31', [$f['selector']]);
        $facts = ['supplier_id' => $f['supplier'],'unit_id' => $f['units']['U02'],'service_id' => $f['service']['id'],'segment_key' => 'SYNTHETIC-2026','period_from' => '2026-01-01','period_to' => '2026-12-31','cutoff_at' => '2026-01-31T23:59:59+01:00','known_at' => '2026-10-17T12:00:00+02:00','recipient_ids' => [$f['clients'][1]],'evidence' => 'Synthetic service cost and cutoff dossier','prescribed_cents' => '100000','paid_cents' => '60000','actual_cents' => '80000','origins' => $current['origins'],'cutoff_allocations' => [],'cost' => array_values($current['costs'])[0]];
        return ['document' => \MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts),'current' => $current];
    }

    private function appliedFixture(bool $apply = true): array
    {
        $f = $this->openingFixture();
        $document = $this->openingDocument($f)['document'];
        $docRepo = $this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);
        $saved = $docRepo->save($f['supplier'], $document, [$f['selector']], '2026-12-31', 0, 's8-owner-document', $f['actor']);
        $source = $document->snapshot()['origins'][0];
        $debt = $source['debt_id'];
        $bank = $this->correctionBank('300.00');
        $r = ['kind' => 'allocation','source' => ['kind' => 'bank','id' => $bank,'expected_version' => 0],'amount_cents' => '30000','accounting_date' => '2026-02-01','reason' => 'Synthetic late payment','debts' => [$debt],'payment' => ['payer_id' => $f['clients'][1],'debtor_id' => $f['clients'][1],'received_at' => '2026-02-01T10:00:00+01:00','choice' => ['claim_ids' => [$debt],'evidence' => 'Synthetic debtor disposition']]];
        $payments = $this->correctionPayments();
        $op = $payments->apply($f['supplier'], $r, $payments->preview($f['supplier'], $r)['preview_hash'], 's8-owner-late', $f['actor']);
        $allocation = (int)$this->db->pdo()->query('SELECT id FROM unit_payment_allocations WHERE operation_id='.$op['id'])->fetchColumn();
        $owner = $this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
        $request = ['accounting_date' => '2026-12-31','evidence' => 'Synthetic final service disposition','advances' => [['origin_key' => $source['kind'].':'.$source['id'],'applied_cents' => '80000']],'reliefs' => [['allocation_id' => $allocation,'amount_cents' => '10000','entitlement' => ['party_ids' => [$f['clients'][1]],'evidence' => 'Synthetic documented creditor','due_date' => '2027-04-30','account_code' => '379.910']]],'opening_credits' => [],'new_claim' => null];
        $preview = $owner->preview($f['supplier'], $saved['id'], $request, 0);

        $result = $apply ? $owner->apply($f['supplier'], $saved['id'], $request, 0, $preview['preview_hash'], 's8-apply', $f['actor']) : null;

        return compact('f', 'document', 'docRepo', 'saved', 'source', 'debt', 'bank', 'r', 'payments', 'op', 'allocation', 'owner', 'request', 'preview', 'result');
    }
 private function negativeServiceCorrectionFixture(bool $used):array {
  extract($this->newClaimFixture($used));$this->f=$f;$version=self::chargeRequest($f);$version['expected_revision']=1;foreach($version['rates'] as &$rate)if($rate['component_id']===$f['components']['WATER'])$rate['amount']='11000';unset($rate);
  $chargePreview=(new \MyInvoice\Service\Property\Charges\ChargePreviewService($this->db))->preview($f['supplier'],$version);$savedVersion=(new \MyInvoice\Repository\Property\Charges\ChargeRepository($this->db))->saveVersion($f['supplier'],['request'=>$version,'preview_hash'=>$chargePreview['preview_hash'],'idempotency_key'=>'negative-service-version'],$f['actor']);
  $charge=(int)$this->db->pdo()->query('SELECT charge_id FROM unit_charge_lines WHERE id='.$source['id'])->fetchColumn();$issue=$f['issue_request'];$issue['version_id']=$savedVersion['id'];$issue['issue_mode']='difference';$issue['accounting_date']='2026-12-31';$issue['issued_at']='2026-12-31T20:00:00+01:00';$issue['units']=array_values(array_filter($issue['units'],fn($u)=>$u['unit_id']===$f['units']['U02']));$issue['units'][0]['expected_revision']=1;$issue['units'][0]['previous_charge_id']=$charge;
  $ids=[];foreach($f['states']['U02']['ownerships'] as $group)foreach($group['members'] as $member)$ids[]=$member;$ids=array_values(array_unique($ids));$issue['units'][0]['notices'][0]['notice']=['mode'=>'change','title_ref'=>'Synthetic reduced annual service decision','written_notice_ref'=>'Synthetic written reduction','reason'=>'Synthetic documented reduced service need','deliveries'=>array_map(fn($id)=>['recipient_id'=>$id,'represented_ids'=>[$id],'authority_ref'=>'','delivered_at'=>'2025-12-20T10:00:00+01:00','evidence_ref'=>'Synthetic delivered written notice'],$ids),'agreement'=>['effective_at'=>'2026-01-01T00:00:00+01:00','recipient_ids'=>$ids,'evidence_ref'=>'Synthetic annual reduction agreement','legal_basis'=>'Explicit assessed annual correction title']];
  $issuance=$this->container()->get(IssuanceRepository::class);$p=(new IssuancePreviewService($this->db))->preview($f['supplier'],$issue);$batch=$issuance->issue($f['supplier'],['request'=>$issue,'preview_hash'=>$p['preview_hash'],'idempotency_key'=>'negative-service-issue'],$f['actor']);$adjustment=$batch['charges'][0];self::assertSame('-100000',$adjustment['amount_cents']);
  $q=$this->db->pdo()->prepare('SELECT id FROM unit_charge_lines WHERE supplier_id=? AND charge_id=?');$q->execute([$f['supplier'],$adjustment['id']]);$line=(int)$q->fetchColumn();$request=['adjustment_charge_id'=>$adjustment['id'],'expected_version'=>1,'accounting_date'=>'2026-12-31','reason'=>'Synthetic negative service correction','anchors'=>[['adjustment_line_id'=>$line,'original_line_id'=>$source['id'],'amount_cents'=>'100000','evidence'=>'Synthetic exact used service line']],'reliefs'=>[],'credit_entitlement'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic independent creditor title','due_date'=>'2027-04-30']];
  $correction=$this->correctionWriter();$preview=$correction->preview($f['supplier'],$request);$correctionIssue=$issue;return compact('f','source','charge','adjustment','correction','preview','request','savedVersion','correctionIssue');
 }

    private function nativeFixture(string $actual): array
    {

        $f = $this->issuanceFixture($this->db);
        $this->f = $f;
        $chart = new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);
        $parent = $chart->findByCode($f['supplier'], '315');
        $chart->insert($f['supplier'], ['account_code' => '315.100', 'name' => 'Synthetic original service receivable', 'account_type' => 'asset', 'normal_side' => 'debit', 'parent_id' => $parent['id']]);
        foreach ($f['issue_request']['accounts'] as &$account) $account['receivable_account'] = '315.100'; unset($account);
        $repo = $this->container()->get(IssuanceRepository::class);
        $preview = (new IssuancePreviewService($this->db))->preview($f['supplier'], $f['issue_request']);
        $batch = $repo->issue($f['supplier'], ['request' => $f['issue_request'],'preview_hash' => $preview['preview_hash'],'idempotency_key' => 'native-s8-issue'], $f['actor']);
        $expected = [];
        foreach ($batch['charges'] as $charge) {
            $expected[] = ['id' => $charge['id'],'row_version' => $charge['row_version']];
        }
        $repo->post($f['supplier'], $batch['id'], $expected, $f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
        $q = $this->db->pdo()->prepare('SELECT l.id FROM unit_charge_lines l JOIN unit_charges c ON c.supplier_id=l.supplier_id AND c.id=l.charge_id WHERE l.supplier_id=? AND c.unit_id=? AND l.service_id=?');
        $q->execute([$f['supplier'],$f['units']['U02'],$f['service']['id']]);
        $id = (int)$q->fetchColumn();
        (new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->register($f['supplier'], ['charge_id' => (int)$this->db->pdo()->query('SELECT charge_id FROM unit_charge_lines WHERE id='.$id)->fetchColumn(),'expected_version' => 2,'principal_line_ids' => [$id],'title_ref' => 'Synthetic service debt','due_date' => '2026-01-15','legal' => ['basis' => 'legal_act','legal_act_date' => '2025-12-01','evidence' => 'Synthetic decision','regime' => 'general'],'security' => ['kind' => 'unsecured','evidence' => 'Synthetic no security'],'damages_for' => null], 's8-debt', $f['actor']);
        $result = $this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'], $f['units']['U02'], $f['service']['id'], '2026-12-31', [['kind' => 's3_line','id' => $id]]);

        $chart = new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);
        $parent = $chart->findByCode($f['supplier'], '315');
        $chart->insert($f['supplier'], ['account_code' => '315.210','name' => 'Synthetic service cost','account_type' => 'asset','normal_side' => 'debit','parent_id' => $parent['id']]);
        $dims = $result['origins'][0]['advance_line']['dimensions'];
        $cat = (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($f['supplier'], '2026-12-31');
        $dims[$cat['activity']['id']] = $cat['activity']['values']['main'];
        $entry = $this->container()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($f['supplier'], 'manual', null, [['account_code' => '315.210','side' => 'debit','amount' => bcdiv($actual, '100', 2),'dimensions' => $dims],['account_code' => '321','side' => 'credit','amount' => bcdiv($actual, '100', 2),'dimensions' => $dims]], ['entry_date' => '2026-12-31','posted_by' => $f['actor']]);
        $costId = (int)$this->db->pdo()->query('SELECT MIN(id) FROM journal_entry_lines WHERE entry_id='.$entry)->fetchColumn();
        $selector = ['kind' => 's3_line','id' => $id,'cost_line_id' => $costId];
        $native = $this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'], $f['units']['U02'], $f['service']['id'], '2026-12-31', [$selector]);
        $facts = ['supplier_id' => $f['supplier'],'unit_id' => $f['units']['U02'],'service_id' => $f['service']['id'],'segment_key' => 'S3-SERVICE-2026','period_from' => '2026-01-01','period_to' => '2026-12-31','cutoff_at' => '2026-12-31T23:59:59+01:00','known_at' => '2027-01-01T12:00:00+01:00','recipient_ids' => [$f['clients'][1]],'evidence' => 'Synthetic native service ledger','prescribed_cents' => '1200000','paid_cents' => '0','actual_cents' => $actual,'origins' => $native['origins'],'cutoff_allocations' => [],'cost' => array_values($native['costs'])[0]];
        $doc = \MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts);
        $saved = $this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class)->save($f['supplier'], $doc, [$selector], '2026-12-31', 0, 's3-service-doc', $f['actor']);
        return compact('f', 'selector', 'saved', 'native', 'doc');

    }

    public function testCostCatalogSkipsUnsupportedCurrencyAndAccountCandidates(): void
    {
        extract($this->newClaimFixture(false));
        $pdo = $this->db->pdo();
        $q = $pdo->prepare('SELECT cost_line_id FROM property_service_settlements WHERE supplier_id=? AND id=?');
        $q->execute([$f['supplier'], $saved['id']]);
        $valid = (int)$q->fetchColumn();
        $q = $pdo->prepare('SELECT dimension_type_id,dimension_value_id FROM journal_entry_line_dimensions WHERE supplier_id=? AND line_id=?');
        $q->execute([$f['supplier'], $valid]);
        $dims = array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
        $posting = $this->container()->get(\MyInvoice\Service\Accounting\PostingService::class);
        $foreign = ['currency_code' => 'EUR', 'amount_foreign' => '4.00', 'fx_rate' => '25.000000'];
        $entry = $posting->postDocument($f['supplier'], 'manual', null, [
            ['account_code' => '315.210', 'side' => 'debit', 'amount' => '100.00', 'dimensions' => $dims] + $foreign,
            ['account_code' => '321', 'side' => 'credit', 'amount' => '100.00', 'dimensions' => $dims] + $foreign,
        ], ['entry_date' => '2026-12-31', 'posted_by' => $f['actor']]);
        $q = $pdo->prepare("SELECT id,currency_code,amount_foreign,fx_rate FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? AND side='debit'");
        $q->execute([$f['supplier'], $entry]);
        $fx = $q->fetch(PDO::FETCH_ASSOC);
        self::assertGreaterThan($valid, (int)$fx['id']);
        self::assertSame('EUR', $fx['currency_code']);
        self::assertNotNull($fx['amount_foreign']); self::assertNotNull($fx['fx_rate']);
        $pdo->prepare("INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES (?,'315.299','Synthetic non-cost liability','liability')")->execute([$f['supplier']]);
        $posting->postDocument($f['supplier'], 'manual', null, [
            ['account_code' => '315.299', 'side' => 'debit', 'amount' => '100.00', 'dimensions' => $dims],
            ['account_code' => '321', 'side' => 'credit', 'amount' => '100.00', 'dimensions' => $dims],
        ], ['entry_date' => '2026-12-31', 'posted_by' => $f['actor']]);
        $query = ['unit_id' => $f['units']['U02'], 'service_id' => $f['service']['id'], 'date' => '2026-12-31', 's3_line_id' => $source['id'], 'limit' => 1];
        foreach ([['EUR','4.00','25.000000'],['EUR',null,null],[null,'4.00',null],[null,null,'25.000000']] as $metadata) {
            $pdo->prepare('UPDATE journal_entry_lines SET currency_code=?,amount_foreign=?,fx_rate=? WHERE supplier_id=? AND id=?')->execute([...$metadata,$f['supplier'],$fx['id']]);
            [$status, $page] = $this->http('GET', '/api/property/service-settlements/catalog/costs', [], null, false, true, $query);
            self::assertSame(200, $status, json_encode($page));
            self::assertCount(1, $page['items']);
            self::assertSame($valid, $page['items'][0]['cost_source_id']);
            self::assertSame('1400000', $page['items'][0]['cost_cents']);
            self::assertNull($page['next_cursor']);
        }
    }

    private function newClaimFixture(bool $apply = true): array
    {

        extract($this->nativeFixture('1400000'));
        $source = $native['origins'][0];
        $owner = $this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
        $r = ['accounting_date' => '2026-12-31','evidence' => 'Synthetic native service disposition','advances' => [['origin_key' => 's3_line:'.$source['id'],'applied_cents' => '1200000']],'reliefs' => [],'opening_credits' => [],'new_claim' => ['party_ids' => [$f['clients'][1]],'evidence' => 'Synthetic independent settlement claim','due_date' => '2027-04-30','account_code' => '315.210'],'claim_title' => ['effective_at' => '2026-12-31T12:00:00+01:00','title_ref' => 'Synthetic service settlement title','liability_groups' => [['group_key' => 'A','role' => 'debtor','mode' => 'individual','basis' => 'documented_title','members' => [$f['clients'][1]],'extent' => ['num' => '1','den' => '1'],'evidence_ref' => 'Synthetic title','legal_reason' => 'Synthetic service liability','valid_from' => '2026-12-31T12:00:00+01:00','valid_to' => null]],'legal' => ['basis' => 'legal_act','legal_act_date' => '2026-12-31','evidence' => 'Synthetic service legal act','regime' => 'general'],'security' => ['kind' => 'unsecured','evidence' => 'Synthetic unsecured finding']]];
        $preview = $owner->preview($f['supplier'],$saved['id'],$r,0);
        $result = $apply ? $owner->apply($f['supplier'],$saved['id'],$r,0,$preview['preview_hash'],'s3-new-claim-apply',$f['actor']) : null;
        return compact('f','saved','source','owner','r','preview','result');
    }

    private function bankOn(string $amount,string $date): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic',?,'1000000005','0100','CZK',?)")->execute([$this->f['supplier'],hash('sha256',random_bytes(20)),$date]);
        $statement = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,?,?,'CZK','1000000005','0100')")->execute([$statement,$date,$amount]);
        $id = (int)$pdo->lastInsertId();
        $this->paymentFixtureContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->f['supplier'],'bank',$id,[['account_code' => '221','side' => str_starts_with($amount,'-') ? 'credit' : 'debit','amount' => ltrim($amount,'-')],['account_code' => '379.900','side' => str_starts_with($amount,'-') ? 'debit' : 'credit','amount' => ltrim($amount,'-')]],['entry_date' => $date,'posted_by' => $this->f['actor']]);
        return $id;
    }
}
