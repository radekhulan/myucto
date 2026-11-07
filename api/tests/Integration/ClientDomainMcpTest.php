<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Mcp\McpInternalRequest;
use MyInvoice\Service\Mcp\McpOAuth;
use MyInvoice\Service\Mcp\McpResourcePolicy;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class ClientDomainMcpTest extends TestCase
{
    private \PDO $pdo;
    private int $user;
    private int $role;
    private int $supplier;
    private int $otherSupplier;
    private string $client;
    private string $origin;
    private string $canonical;
    private array $session;
    private array $prior = [];
    private string $cookie;
    private array $extraUsers = [];

    protected function setUp(): void
    {
        foreach (['MYINVOICE_DOMAINS_ENABLED', 'MYINVOICE_MCP_ENABLED'] as $key) {
            $this->prior[$key] = getenv($key);
            putenv($key . '=1');
        }
        $container = Bootstrap::buildContainer();
        $this->pdo = $container->get(Connection::class)->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
        $suppliers = $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(2, $suppliers);
        [$this->supplier, $this->otherSupplier] = array_map('intval', $suppliers);
        $suffix = bin2hex(random_bytes(6));
        $this->origin = 'https://mcp-' . $suffix . '.example.test';
        $this->pdo->prepare("INSERT INTO roles (name, role_type, is_active) VALUES (?, 'client', 1)")->execute(['MCP test ' . $suffix]);
        $this->role = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO role_permissions VALUES (?, 'profile', 1), (?, 'profile.mcp', 1), (?, 'invoices', 1)")
            ->execute([$this->role, $this->role, $this->role]);
        $this->pdo->prepare('INSERT INTO users (email, password_hash, name, role_id, is_active) VALUES (?, ?, ?, ?, 1)')
            ->execute(['mcp-' . $suffix . '@example.test', password_hash('synthetic-mcp-password', PASSWORD_DEFAULT), 'MCP klient', $this->role]);
        $this->user = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')->execute([$this->user, $this->supplier]);
        $this->pdo->prepare("INSERT INTO supplier_domains (supplier_id, hostname, purpose, status, verification_token, verified_at) VALUES (?, ?, 'portal', 'active', ?, NOW())")
            ->execute([$this->supplier, substr($this->origin, 8), bin2hex(random_bytes(32))]);
        $this->client = $container->get(McpOAuth::class)->register('Syntetický asistent', ['https://assistant.example.test/callback']);
        $config = $container->get(Config::class);
        $this->canonical = rtrim((string) $config->get('app.url'), '/');
        $this->cookie = (string) $config->get('session.cookie_name', '__Host-myinvoice_session');
        $this->session = $container->get(SessionManager::class)->create($this->user, '127.0.0.1', 'synthetic-mcp');
    }

    protected function tearDown(): void
    {
        if (isset($this->user)) {
            $this->pdo->prepare('DELETE FROM mcp_oauth_clients WHERE client_id=?')->execute([$this->client]);
            $this->pdo->prepare('DELETE FROM supplier_domains WHERE hostname=?')->execute([substr($this->origin, 8)]);
            foreach ($this->extraUsers as $id) $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$this->user]);
            $this->pdo->prepare('DELETE FROM roles WHERE id=?')->execute([$this->role]);
        }
        foreach ($this->prior as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
    }

    public function testDiscoveryConsentAndInternalCallStayBoundToClientDomain(): void
    {
        $metadata = $this->request('GET', $this->origin . '/.well-known/oauth-protected-resource');
        self::assertSame(200, $metadata->getStatusCode(), (string) $metadata->getBody());
        self::assertSame(['resource' => $this->origin . '/mcp', 'issuer' => $this->canonical], [
            'resource' => self::json($metadata)['resource'], 'issuer' => self::json($metadata)['authorization_servers'][0],
        ]);
        $anonymous = $this->request('POST', $this->origin . '/mcp', ['jsonrpc'=>'2.0', 'id'=>1, 'method'=>'initialize']);
        self::assertSame(401, $anonymous->getStatusCode());
        self::assertStringContainsString($this->origin . '/.well-known/oauth-protected-resource', $anonymous->getHeaderLine('WWW-Authenticate'));

        $params = $this->params();
        $consent = $this->request('GET', $this->canonical . '/oauth/authorize?' . http_build_query($params), session: true);
        self::assertSame(200, $consent->getStatusCode(), (string) $consent->getBody());
        self::assertStringContainsString($this->origin . '/mcp', (string) $consent->getBody());
        self::assertStringNotContainsString('včetně těch přidaných později', (string) $consent->getBody());
        $approved = $this->request('POST', $this->canonical . '/oauth/authorize', $params + [
            'csrf_token'=>$this->session['csrf_token'], 'decision'=>'approve', 'grant_scope'=>'read',
        ], session: true, form: true);
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        self::assertMatchesRegularExpression('/id="mcp-oauth-continue"[^>]+href="([^"]+)"/', (string) $approved->getBody());
        preg_match('/id="mcp-oauth-continue"[^>]+href="([^"]+)"/', (string) $approved->getBody(), $match);
        parse_str((string) parse_url(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), PHP_URL_QUERY), $query);
        $exchange = $this->request('POST', $this->canonical . '/oauth/token', [
            'grant_type'=>'authorization_code', 'client_id'=>$this->client, 'code'=>$query['code'],
            'code_verifier'=>str_repeat('a',64), 'redirect_uri'=>$params['redirect_uri'], 'resource'=>$params['resource'],
        ], form: true);
        self::assertSame(200, $exchange->getStatusCode(), (string) $exchange->getBody());
        $token = self::json($exchange)['access_token'];
        $row = Bootstrap::buildContainer()->get(ApiTokenService::class)->validate($token);
        self::assertSame($this->supplier, $row['supplier_id']);
        $identity = self::json($this->rpc($token, 'tools/call', ['name'=>'whoami']));
        self::assertArrayHasKey('result', $identity);
        self::assertFalse($identity['result']['isError'] ?? false, json_encode($identity));
        $list = self::json($this->rpc($token, 'tools/list'))['result']['tools'];
        $names = array_column($list, 'name');
        self::assertContains('list_invoices', $names);
        self::assertNotContains('create_invoice', $names);
        self::assertNotContains('list_payroll_people', $names);
        self::assertNotContains('trial_balance', $names);
        $foreign = self::json($this->rpc($token, 'tools/call', ['name'=>'list_invoices', 'arguments'=>['supplier_id'=>$this->otherSupplier]]));
        self::assertTrue($foreign['result']['isError']);
        $this->pdo->prepare('INSERT INTO user_suppliers (user_id,supplier_id) VALUES (?,?)')->execute([$this->user,$this->otherSupplier]);
        $companies = self::json($this->rpc($token, 'tools/call', ['name'=>'list_suppliers']))['result']['structuredContent']['result'];
        self::assertSame([$this->supplier], array_column($companies, 'id'));
    }

    public function testEntraOriginSurvivesConsentExchangeRefreshAndTokenUseOnCustomDomain(): void
    {
        $canonical = (string) preg_replace('#^http://#', 'https://', $this->canonical);
        $environment = [
            'MYINVOICE_APP_URL' => $canonical,
            'MYINVOICE_OIDC_ENABLED' => '1', 'MYINVOICE_OIDC_ENTRA_ENABLED' => '1',
            'MYINVOICE_OIDC_ENTRA_TENANT_ID' => '11111111-1111-1111-1111-111111111111',
            'MYINVOICE_OIDC_ENTRA_CLIENT_ID' => '22222222-2222-2222-2222-222222222222',
            'MYINVOICE_OIDC_ENTRA_CLIENT_SECRET' => 'synthetic-unused',
            'MYINVOICE_OIDC_REQUIRED' => 'entra',
        ];
        $priorEnvironment = [];
        foreach ($environment as $key => $value) {
            $priorEnvironment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        try {
            self::assertNotNull(Bootstrap::buildContainer()->get(OidcProviderRegistry::class)->get('entra'),
                'Entra se zapne jen s https app.url; bez ní vynucené SSO instalace neplatí a test by nic neověřil.');
            $this->pdo->prepare("UPDATE sessions SET auth_method='oidc:entra' WHERE id=?")
                ->execute([$this->session['token']]);
            $consent = $this->request('GET', $canonical . '/oauth/authorize?' . http_build_query($this->params()), session: true);
            self::assertSame(200, $consent->getStatusCode(), (string) $consent->getBody());

            $oauth = Bootstrap::buildContainer()->get(McpOAuth::class);
            $params = $this->params();
            $code = $oauth->createCode($this->client, $this->user, $this->supplier, $params['redirect_uri'],
                $params['code_challenge'], 'read', $params['resource'], 'password');
            self::assertNull($oauth->exchange($code, $this->client, $params['redirect_uri'], str_repeat('a', 64), $params['resource']));
            $grant = $this->grantFor($params['resource'], $this->supplier, 'read', 'oidc:entra');
            self::assertSame(200, $this->rpc($grant['access_token'], 'tools/list')->getStatusCode());
            $refreshed = $oauth->refresh($grant['refresh_token'], $this->client, $params['resource']);
            self::assertIsArray($refreshed);
            $result = self::json($this->rpc($refreshed['access_token'], 'tools/call', ['name' => 'whoami']));
            self::assertArrayHasKey('result', $result);
            self::assertFalse($result['result']['isError'] ?? false, json_encode($result));
        } finally {
            foreach ($priorEnvironment as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testTokenCannotBeReplayedOnAnotherHostOrPublicApi(): void
    {
        $grant = $this->grant();
        foreach ([$this->canonical . '/mcp', $this->canonical . '/api/v1/invoices', $this->origin . '/api/v1/invoices'] as $url) {
            $response = $this->request(str_ends_with($url,'/mcp') ? 'POST' : 'GET', $url, ['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize'], $grant['access_token']);
            self::assertSame(401, $response->getStatusCode(), $url . ': ' . $response->getBody());
        }
        $other = 'https://alias-' . bin2hex(random_bytes(5)) . '.example.test';
        $this->pdo->prepare("INSERT INTO supplier_domains (supplier_id,hostname,purpose,status,verification_token) VALUES (?,?,'portal','active',?)")
            ->execute([$this->supplier,substr($other,8),bin2hex(random_bytes(32))]);
        try {
            self::assertSame(401, $this->rpc($grant['access_token'], 'initialize', origin: $other)->getStatusCode());
        } finally {
            $this->pdo->prepare('DELETE FROM supplier_domains WHERE hostname=?')->execute([substr($other,8)]);
        }
        self::assertNull(Bootstrap::buildContainer()->get(McpOAuth::class)->refresh($grant['refresh_token'], $this->client, $this->canonical . '/mcp'));
        self::assertIsArray(Bootstrap::buildContainer()->get(McpOAuth::class)->refresh($grant['refresh_token'], $this->client, $this->origin . '/mcp'));
    }

    #[TestWith(['permission'])]
    #[TestWith(['membership'])]
    #[TestWith(['user'])]
    #[TestWith(['role'])]
    #[TestWith(['domain'])]
    #[TestWith(['domain_owner'])]
    #[TestWith(['override'])]
    public function testRevokedAccessAlsoBlocksRefresh(string $change): void
    {
        $grant = $this->grant();
        self::assertSame(200, $this->rpc($grant['access_token'], 'initialize')->getStatusCode());
        match ($change) {
            'permission' => $this->pdo->exec("UPDATE role_permissions SET access_level=0 WHERE role_id={$this->role} AND permission_key='profile.mcp'"),
            'membership' => $this->pdo->exec("DELETE FROM user_suppliers WHERE user_id={$this->user}"),
            'user' => $this->pdo->exec("UPDATE users SET is_active=0 WHERE id={$this->user}"),
            'role' => $this->pdo->exec("UPDATE roles SET is_active=0 WHERE id={$this->role}"),
            'domain' => $this->pdo->exec("UPDATE supplier_domains SET status='disabled' WHERE hostname='" . substr($this->origin,8) . "'"),
            'domain_owner' => $this->pdo->exec("UPDATE supplier_domains SET supplier_id={$this->otherSupplier} WHERE hostname='" . substr($this->origin,8) . "'"),
            'override' => $this->pdo->exec("UPDATE user_suppliers SET role_id=(SELECT id FROM roles WHERE system_key='client') WHERE user_id={$this->user}"),
        };
        self::assertSame($change === 'domain' ? 421 : 401, $this->rpc($grant['access_token'], 'initialize')->getStatusCode());
        self::assertNull(Bootstrap::buildContainer()->get(McpOAuth::class)->refresh($grant['refresh_token'], $this->client));
    }

    #[TestWith(['pending','portal'])]
    #[TestWith(['verified','portal'])]
    #[TestWith(['disabled','portal'])]
    #[TestWith(['active','public_links'])]
    public function testInactiveOrPublicOnlyDomainCannotIssueGrant(string $status, string $purpose): void
    {
        $this->pdo->prepare('UPDATE supplier_domains SET status=?,purpose=? WHERE hostname=?')->execute([$status,$purpose,substr($this->origin,8)]);
        self::assertNull(Bootstrap::buildContainer()->get(McpResourcePolicy::class)->resolve($this->origin . '/mcp'));
        $consent = $this->request('GET', $this->canonical . '/oauth/authorize?' . http_build_query($this->params()), session:true);
        self::assertSame(400,$consent->getStatusCode());
    }

    public function testReadMcpPermissionCannotGrantWriteAndMissingPermissionCannotConsent(): void
    {
        $params=$this->params()+['decision'=>'approve','csrf_token'=>$this->session['csrf_token'],'grant_scope'=>'read_write'];
        $params['scope']='read_write';
        self::assertSame(403,$this->request('POST',$this->canonical.'/oauth/authorize',$params,session:true,form:true)->getStatusCode());
        $this->pdo->exec("UPDATE role_permissions SET access_level=0 WHERE role_id={$this->role} AND permission_key='profile.mcp'");
        self::assertSame(403,$this->request('GET',$this->canonical.'/oauth/authorize?'.http_build_query($this->params()),session:true)->getStatusCode());
    }

    public function testClientConsentStillRequiresFreshMfa(): void
    {
        $container=Bootstrap::buildContainer();
        $totp=$container->get(\MyInvoice\Service\Auth\TotpService::class);
        $secret=$totp->generateSecret();
        $encrypted=$container->get(\MyInvoice\Service\Auth\SecretEncryption::class)->encrypt($secret);
        $this->pdo->prepare('UPDATE users SET totp_enabled=1,totp_secret=? WHERE id=?')->execute([$encrypted,$this->user]);
        $form=$this->params()+['decision'=>'approve','csrf_token'=>$this->session['csrf_token'],'grant_scope'=>'read'];
        self::assertSame(401,$this->request('POST',$this->canonical.'/oauth/authorize',$form,session:true,form:true)->getStatusCode());
        $form['totp_code']=$totp->currentCode($secret);
        self::assertSame(200,$this->request('POST',$this->canonical.'/oauth/authorize',$form,session:true,form:true)->getStatusCode());
        self::assertSame(401,$this->request('POST',$this->canonical.'/oauth/authorize',$form,session:true,form:true)->getStatusCode());
    }

    public function testKnownRecordIdDoesNotCrossTheCompanyBoundary(): void
    {
        $this->pdo->prepare("INSERT INTO role_permissions VALUES (?, 'clients', 1)")->execute([$this->role]);
        $ids=[];
        try {
            foreach ([$this->supplier,$this->otherSupplier] as $supplier) {
                $insert=$this->pdo->prepare("INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,currency_default_id)
                    SELECT id,?,'Syntetická 1','Praha','11000',country_id,default_currency_id FROM supplier WHERE id=?");
                $insert->execute(['Syntetický MCP kontakt '.$supplier,$supplier]);
                $ids[]=(int)$this->pdo->lastInsertId();
            }
            $grant=$this->grant();
            $own=self::json($this->rpc($grant['access_token'],'tools/call',['name'=>'get_client','arguments'=>['id'=>$ids[0]]]));
            self::assertFalse($own['result']['isError']??false,json_encode($own));
            self::assertStringContainsString('Syntetický MCP kontakt '.$this->supplier,json_encode($own,JSON_UNESCAPED_UNICODE));
            $foreign=self::json($this->rpc($grant['access_token'],'tools/call',['name'=>'get_client','arguments'=>['id'=>$ids[1]]]));
            self::assertTrue($foreign['result']['isError']);
            self::assertStringNotContainsString('Syntetický MCP kontakt '.$this->otherSupplier,json_encode($foreign,JSON_UNESCAPED_UNICODE));
        } finally {
            foreach ($ids as $id) $this->pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);
        }
    }

    public function testOrdinaryPatCannotActAsClientDomainMcpGrant(): void
    {
        $tokens=Bootstrap::buildContainer()->get(ApiTokenService::class);
        $token=$tokens->generate($this->user,$this->supplier,'Syntetický PAT','read',new \DateTimeImmutable('+1 hour'));
        self::assertSame(401,$this->rpc($token['plaintext'],'initialize')->getStatusCode());
        $creation=$this->request('POST',$this->canonical.'/api/auth/tokens',[
            'name'=>'Zakázaný klientský PAT','password'=>'synthetic-mcp-password',
        ],session:true);
        self::assertSame(403,$creation->getStatusCode());
    }

    public function testInternalRequestResourceCannotBeSubstituted(): void
    {
        $grant=$this->grant();
        $builder=new McpInternalRequest($this->canonical,sys_get_temp_dir());
        $request=$builder->build(['url'=>$this->canonical.'/api/v1/invoices','method'=>'GET',
            'serverParams'=>['REMOTE_ADDR'=>'127.0.0.1'],
            'headers'=>['Authorization'=>'Bearer '.$grant['access_token']], 'mcpResource'=>$this->canonical.'/mcp']);
        $response=Bootstrap::buildApp()->handle($request);
        self::assertSame(401,$response->getStatusCode(),(string)$response->getBody());
    }

    public function testClientCanRevokeOwnConnectionAfterLosingMcpPermission(): void
    {
        $grant=$this->grant();
        $foreign=$this->grantFor($this->canonical.'/mcp',$this->otherSupplier);
        $this->pdo->prepare('INSERT INTO users (email,password_hash,name,role_id,is_active) VALUES (?,?,?,?,1)')
            ->execute(['other-mcp-'.bin2hex(random_bytes(6)).'@example.test','synthetic-unused-hash','Jiný syntetický klient',$this->role]);
        $otherUser=(int)$this->pdo->lastInsertId();
        $this->extraUsers[]=$otherUser;
        $this->pdo->prepare('INSERT INTO user_suppliers (user_id,supplier_id) VALUES (?,?)')->execute([$otherUser,$this->supplier]);
        $oauth=Bootstrap::buildContainer()->get(McpOAuth::class);
        $p=$this->params();
        $code=$oauth->createCode($this->client,$otherUser,$this->supplier,$p['redirect_uri'],$p['code_challenge'],'read',$p['resource']);
        self::assertIsArray($oauth->exchange($code,$this->client,$p['redirect_uri'],str_repeat('a',64),$p['resource']));
        $otherGrantId=(int)$this->pdo->query("SELECT id FROM mcp_oauth_grants WHERE user_id={$otherUser}")->fetchColumn();
        $this->pdo->prepare('UPDATE sessions SET supplier_domain_id=(SELECT id FROM supplier_domains WHERE hostname=?) WHERE id=?')
            ->execute([substr($this->origin,8),$this->session['token']]);
        $this->pdo->exec("UPDATE role_permissions SET access_level=0 WHERE role_id={$this->role} AND permission_key='profile.mcp'");
        $list=$this->request('GET',$this->origin.'/api/mcp/grants',session:true);
        self::assertSame(200,$list->getStatusCode(),(string)$list->getBody());
        $rows=self::json($list)['grants'];
        self::assertCount(1,$rows);
        self::assertSame($this->origin.'/mcp',$rows[0]['resource']);
        self::assertSame(403,$this->request('GET',$this->origin.'/api/mcp/grants?all=1',session:true)->getStatusCode());
        self::assertGreaterThan(0,$otherGrantId);
        self::assertSame(404,$this->request('DELETE',$this->origin.'/api/mcp/grants/'.$otherGrantId,session:true)->getStatusCode());
        $foreignId=(int)$this->pdo->query("SELECT id FROM mcp_oauth_grants WHERE user_id={$this->user} AND supplier_id={$this->otherSupplier}")->fetchColumn();
        self::assertGreaterThan(0,$foreignId);
        self::assertSame(404,$this->request('DELETE',$this->origin.'/api/mcp/grants/'.$foreignId,session:true)->getStatusCode());
        self::assertSame(200,$this->request('DELETE',$this->origin.'/api/mcp/grants/'.$rows[0]['id'],session:true)->getStatusCode());
        self::assertNull(Bootstrap::buildContainer()->get(ApiTokenService::class)->validate($grant['access_token']));
        self::assertNull(Bootstrap::buildContainer()->get(McpOAuth::class)->refresh($grant['refresh_token'],$this->client));
        self::assertIsArray(Bootstrap::buildContainer()->get(McpOAuth::class)->refresh($foreign['refresh_token'],$this->client));
    }

    public function testWriteToolsRequireBothMcpAndAgendaPermission(): void
    {
        $this->pdo->exec("UPDATE role_permissions SET access_level=2 WHERE role_id={$this->role} AND permission_key='profile.mcp'");
        $grant=$this->grantFor($this->origin.'/mcp',$this->supplier,'read_write');
        $names=array_column(self::json($this->rpc($grant['access_token'],'tools/list'))['result']['tools'],'name');
        self::assertNotContains('create_invoice',$names);
        $blocked=self::json($this->rpc($grant['access_token'],'tools/call',['name'=>'create_invoice','arguments'=>[]]));
        self::assertTrue($blocked['result']['isError']);
        $this->pdo->prepare("INSERT INTO role_permissions VALUES (?, 'invoices.create', 2)")->execute([$this->role]);
        $names=array_column(self::json($this->rpc($grant['access_token'],'tools/list'))['result']['tools'],'name');
        self::assertContains('create_invoice',$names);
        $this->pdo->exec("UPDATE role_permissions SET access_level=1 WHERE role_id={$this->role} AND permission_key='profile.mcp'");
        self::assertSame(401,$this->rpc($grant['access_token'],'tools/list')->getStatusCode());
    }

    public function testLocallyHandledSupplierListCannotBypassToolPolicy(): void
    {
        $grant = $this->grant();
        $this->pdo->prepare("DELETE FROM role_permissions WHERE role_id=? AND permission_key='profile'")->execute([$this->role]);
        $names = array_column(self::json($this->rpc($grant['access_token'], 'tools/list'))['result']['tools'], 'name');
        self::assertContains('whoami', $names);
        self::assertNotContains('list_suppliers', $names);
        $blocked = self::json($this->rpc($grant['access_token'], 'tools/call', ['name' => 'list_suppliers']));
        self::assertTrue($blocked['result']['isError']);
    }

    private function params(): array
    {
        return ['client_id'=>$this->client,'redirect_uri'=>'https://assistant.example.test/callback','response_type'=>'code',
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',str_repeat('a',64),true)),'+/','-_'),'='),
            'code_challenge_method'=>'S256','resource'=>$this->origin.'/mcp','scope'=>'read'];
    }

    private function grant(): array
    {
        return $this->grantFor($this->origin.'/mcp',$this->supplier);
    }

    private function grantFor(string $resource,?int $supplier,string $scope='read',?string $issuedAuthMethod=null): array
    {
        $oauth=Bootstrap::buildContainer()->get(McpOAuth::class);
        $p=$this->params();
        $code=$oauth->createCode($this->client,$this->user,$supplier,$p['redirect_uri'],$p['code_challenge'],$scope,$resource,$issuedAuthMethod);
        $grant=$oauth->exchange($code,$this->client,$p['redirect_uri'],str_repeat('a',64),$resource);
        self::assertIsArray($grant);
        return $grant;
    }

    private function rpc(string $token,string $method,array $params=[],?string $origin=null): ResponseInterface
    {
        return $this->request('POST',($origin??$this->origin).'/mcp',['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params?:new \stdClass()],$token);
    }

    private function request(string $method,string $url,array $body=[],?string $token=null,bool $session=false,bool $form=false): ResponseInterface
    {
        $r=(new ServerRequestFactory())->createServerRequest($method,$url,['REMOTE_ADDR'=>'127.0.0.1']);
        if ($token!==null) $r=$r->withHeader('Authorization','Bearer '.$token);
        if ($session) $r=$r->withCookieParams([$this->cookie=>$this->session['token']])->withHeader('Origin',$r->getUri()->getScheme().'://'.$r->getUri()->getAuthority())
            ->withHeader('X-CSRF-Token',$this->session['csrf_token']);
        if ($method==='POST') $r=$r->withHeader('Content-Type',$form?'application/x-www-form-urlencoded':'application/json')
            ->withParsedBody($body)->withBody((new StreamFactory())->createStream($form?http_build_query($body):json_encode($body,JSON_THROW_ON_ERROR)));
        return Bootstrap::buildApp()->handle($r);
    }

    private static function json(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);
    }
}
