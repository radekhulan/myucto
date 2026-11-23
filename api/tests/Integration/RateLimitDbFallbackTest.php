<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\RateLimitMiddleware;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * §1.2 private/CACHE.md — limiter musí platit i bez Redisu.
 *
 * Dřív `RateLimitMiddleware` bez Redisu vracel handle() rovnou, takže každá
 * instalace na IIS a každý Docker deploy bez `--profile redis` běžel úplně
 * bez rate limitu — včetně veřejných /api/public/* endpointů.
 *
 * Spustit: vendor/bin/phpunit --filter=RateLimitDbFallbackTest
 */
#[Group('integration')]
final class RateLimitDbFallbackTest extends TestCase
{
    private Connection $db;
    private Config $config;

    /** Bucket mimo reálný provoz — TEST-NET-3 dle RFC 5737. */
    private const TEST_IP = '203.0.113.77';

    /** Per-email bucket, který konzumuje ForgotPasswordAction přes consume(). */
    private const EMAIL_BUCKET = 'rl:forgot:email:__ratelimit_test__';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }

        try {
            $loaded = Config::load($rootDir);
            // Redis natvrdo vypnutý → vynutí DB větev bez ohledu na lokální cfg.
            $data = $loaded->all();
            $data['redis']['enabled'] = false;
            $data['rate_limits']['enabled'] = true;
            $data['rate_limits']['login_per_min_per_ip'] = 3;
            $data['rate_limits']['forgot_per_hour_per_ip'] = 2;
            $data['rate_limits']['forgot_per_hour_per_email'] = 2;
            $data['rate_limits']['token_create_per_hour'] = 2;

            $this->config = new Config($data, $loaded->dataDir());
            $this->db = new Connection($this->config);
            $this->db->pdo()->query('SELECT 1 FROM rate_limit_counters LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB/tabulka nedostupná: ' . $e->getMessage());
        }

        $this->purge();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->purge();
        }
    }

    private function purge(): void
    {
        // Jen vlastní prefixy — na sdílené dev DB můžou souběžně běžet jiné testy.
        $stmt = $this->db->pdo()->prepare('DELETE FROM rate_limit_counters WHERE bucket_key LIKE ?');
        foreach (['rl:login:ip:%', 'rl:forgot:ip:%', 'rl:forgot:email-ip:%', 'rl:pat:%', self::EMAIL_BUCKET] as $pattern) {
            $stmt->execute([$pattern]);
        }
    }

    /** Součet čítačů bucketů odpovídajících prefixu — dřív to byl počet řádků. */
    private function hits(string $pattern): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(hits), 0) FROM rate_limit_counters WHERE bucket_key LIKE ?'
        );
        $stmt->execute([$pattern]);

        return (int) $stmt->fetchColumn();
    }

    private function middleware(): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            $this->config,
            new RedisFactory($this->config),
            new ResponseFactory(),
            new IpMatcher(),
            $this->db,
        );
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };
    }

    private function loginRequest(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            'POST',
            'http://localhost/api/auth/login',
            ['REMOTE_ADDR' => self::TEST_IP],
        );
    }

    public function testLimitAppliesWithoutRedis(): void
    {
        self::assertFalse((bool) $this->config->get('redis.enabled'), 'Test musí běžet s vypnutým Redisem');

        $mw = $this->middleware();
        $handler = $this->handler();

        $codes = [];
        for ($i = 0; $i < 5; $i++) {
            $codes[] = $mw->process($this->loginRequest(), $handler)->getStatusCode();
        }

        self::assertSame([200, 200, 200, 429, 429], $codes,
            'Limit 3/min musí propustit 3 pokusy a zbytek odmítnout — dřív prošlo všech 5');
    }

    /**
     * Poll session je nejdelší bucket, jaký limiter skládá:
     * `rl:session-poll:` + sha1(path) + `:session:` + sha256(session id) = 129 znaků,
     * kdežto `rate_limit_counters.bucket_key` je VARCHAR(120). INSERT proto padal
     * na `1406 Data too long` a /api/auth/session/status vracel 500 při KAŽDÉM
     * pollu — jenže jen bez Redisu, takže to trefilo přesně výchozí Docker
     * instalaci a session se hned po setup wizardu nedala ověřit.
     */
    public function testLongSessionPollBucketFitsDbColumn(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();

        // STRICT_TRANS_TABLES natvrdo: bez něj MariaDB dlouhou hodnotu jen tiše
        // ořízne a test by prošel i s rozbitým kódem. Docker MariaDB 11.8 striktní
        // režim ve výchozím stavu má — právě proto se chyba projevila jen tam.
        $pdo = $this->db->pdo();
        $previousSqlMode = (string) $pdo->query('SELECT @@session.sql_mode')->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@session.sql_mode, ',STRICT_TRANS_TABLES')");

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/api/auth/session/status', ['REMOTE_ADDR' => self::TEST_IP])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => 1])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_TOKEN, str_repeat('a', 64));

        try {
            $code = $mw->process($request, $handler)->getStatusCode();
        } finally {
            $restore = $pdo->prepare('SET SESSION sql_mode = ?');
            $restore->execute([$previousSqlMode]);
        }

        self::assertSame(200, $code, 'Poll session nesmí spadnout na délce bucket_key.');

        $stored = $this->db->pdo()
            ->query("SELECT bucket_key FROM rate_limit_counters WHERE bucket_key LIKE 'rl:session-poll:%'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertNotEmpty($stored,
            'Test musí opravdu projít větví session-poll, jinak nic neověřuje.');

        foreach ($stored as $key) {
            self::assertLessThanOrEqual(120, strlen((string) $key),
                'Uložený bucket_key se musí vejít do VARCHAR(120).');
        }
    }

    public function testRejectionCarriesRetryAfter(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();

        for ($i = 0; $i < 3; $i++) {
            $mw->process($this->loginRequest(), $handler);
        }
        $blocked = $mw->process($this->loginRequest(), $handler);

        self::assertSame(429, $blocked->getStatusCode());
        $retryAfter = (int) $blocked->getHeaderLine('Retry-After');
        self::assertGreaterThan(0, $retryAfter, 'Retry-After musí být kladné');
        self::assertLessThanOrEqual(60, $retryAfter, 'Retry-After nesmí přesáhnout okno limitu');
    }

    public function testPasswordTokenSharesPerIpLoginBucket(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();
        $tokenRequest = (new ServerRequestFactory())->createServerRequest(
            'POST',
            'http://localhost/api/auth/password-token',
            ['REMOTE_ADDR' => self::TEST_IP],
        );

        $codes = [];
        for ($i = 0; $i < 2; $i++) {
            $codes[] = $mw->process($this->loginRequest(), $handler)->getStatusCode();
        }
        for ($i = 0; $i < 2; $i++) {
            $codes[] = $mw->process($tokenRequest, $handler)->getStatusCode();
        }

        self::assertSame([200, 200, 200, 429], $codes,
            'Výměna hesla za token a přihlášení webu sdílí limit na IP (3/min)');
    }

    public function testCounterRowsArePersisted(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();

        for ($i = 0; $i < 3; $i++) {
            $mw->process($this->loginRequest(), $handler);
        }

        self::assertSame(3, $this->hits('rl:login:ip:%'),
            'Každý propuštěný request musí čítač zvýšit o jedna');
    }

    /** Odmítnutý request už čítač nezvyšuje — jinak by se okno donekonečna posouvalo. */
    public function testBlockedRequestsDoNotIncrementCounter(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();

        for ($i = 0; $i < 6; $i++) {
            $mw->process($this->loginRequest(), $handler);
        }

        self::assertSame(3, $this->hits('rl:login:ip:%'),
            '429 odpovědi nesmí čítač dál zvyšovat');
    }

    // ------------------------------------------------ zaplnění MEMORY tabulky

    /**
     * Jádro incidentu z 2026-08-11: fallback zapisoval JEDEN ŘÁDEK NA REQUEST a
     * mazal je až denní cron s dvouhodinovou retencí. MEMORY tabulka má tvrdý
     * strop `max_heap_table_size` (výchozích 16 MB), takže na produkci narostla na
     * 31 665 řádků / 15,7 MB a od té chvíle padal každý INSERT na
     * `1114 table is full`. Limiter běží nad každým requestem → celé API do 500
     * (/api/auth/session/status, bankovní import, číselníky).
     *
     * Počet řádků proto NESMÍ růst s objemem provozu — jeden bucket = jeden řádek.
     */
    public function testCounterTableDoesNotGrowWithTraffic(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();

        for ($i = 0; $i < 25; $i++) {
            $mw->process($this->loginRequest(), $handler);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM rate_limit_counters WHERE bucket_key LIKE ?'
        );
        $stmt->execute(['rl:login:ip:%']);

        self::assertSame(1, (int) $stmt->fetchColumn(),
            '25 requestů jednoho bucketu musí držet JEDEN řádek — dřív jich bylo 25');
    }

    /**
     * Úklid nesmí viset jen na denním cronu: instalace bez naplánované úlohy jinak
     * dojede zpátky do plné tabulky. Middleware mete expirované buckety pokaždé,
     * když zakládá nový — přesně tehdy, když tabulka roste.
     */
    public function testExpiredBucketsAreSweptWhenNewBucketAppears(): void
    {
        $stale = 'rl:login:ip:__stale_sweep__';
        $ins = $this->db->pdo()->prepare(
            'INSERT INTO rate_limit_counters (bucket_key, window_start, hits, expires_at)
                  VALUES (?, 0, 99, 1)'
        );
        $ins->execute([$stale]);

        $mw = $this->middleware();
        // První request z čisté IP = nový bucket → úklid.
        $mw->process($this->loginRequest(), $this->handler());

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM rate_limit_counters WHERE bucket_key = ?'
        );
        $stmt->execute([$stale]);

        self::assertSame(0, (int) $stmt->fetchColumn(),
            'Expirovaný bucket musí zmizet při založení nového — bez toho tabulka roste do stropu');
    }

    /**
     * Když je tabulka čítačů nedostupná (plná, chybí, spadlá DB), musí limiter
     * pustit dál. Právě opačné chování — výjimka z INSERTu skrz celý middleware —
     * proměnilo plnou pomocnou tabulku v HTTP 500 na každém API volání.
     */
    public function testFailsOpenWhenCounterTableIsUnavailable(): void
    {
        $data = $this->config->all();
        $data['db']['name'] = 'myucto_nonexistent_ratelimit_db';

        $broken = new RateLimitMiddleware(
            $this->config,
            new RedisFactory($this->config),
            new ResponseFactory(),
            new IpMatcher(),
            new Connection(new Config($data, $this->config->dataDir())),
        );

        $handler = $this->handler();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(200, $broken->process($this->loginRequest(), $handler)->getStatusCode(),
                'Nedostupný čítač nesmí shodit request — limiter je pomocná vrstva, ne brána');
        }

        self::assertNull($broken->consume(self::EMAIL_BUCKET, 1, 3600),
            'Ani consume() nesmí propadnout výjimkou do akce');
    }

    // ---------------------------------------------------------------- SEC-03

    /**
     * Jádro SEC-03: middleware běží PŘED BodyParsingMiddleware, takže
     * `getParsedBody()` je u JSON requestů null. Dřív se podle něj vybíral
     * per-email bucket → pravidlo se nevybralo a /api/auth/forgot neměl limit
     * vůbec žádný (generic per-user limit celý /api/auth/* přeskakuje).
     *
     * Per-IP bucket na těle nezávisí, takže musí platit i s nezparsovaným tělem.
     */
    public function testForgotIsLimitedWithUnparsedJsonBody(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();

        $codes = [];
        for ($i = 0; $i < 4; $i++) {
            // Žádné withParsedBody() — přesně stav, ve kterém middleware reálně běží.
            $req = (new ServerRequestFactory())
                ->createServerRequest('POST', 'http://localhost/api/auth/forgot', ['REMOTE_ADDR' => self::TEST_IP])
                ->withHeader('Content-Type', 'application/json');
            $codes[] = $mw->process($req, $handler)->getStatusCode();
        }

        self::assertSame([200, 200, 429, 429], $codes,
            'Forgot musí být limitovaný per IP i když tělo ještě není zparsované — dřív prošlo všechno');
    }

    /**
     * SEC-09: /api/auth/* je vyňaté z generic per-user limitu, takže tvorba PAT
     * dřív neměla limit žádný ani se zapnutým Redisem.
     */
    public function testTokenCreationIsLimited(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();

        $codes = [];
        for ($i = 0; $i < 4; $i++) {
            $req = (new ServerRequestFactory())
                ->createServerRequest('POST', 'http://localhost/api/auth/tokens', ['REMOTE_ADDR' => self::TEST_IP]);
            $codes[] = $mw->process($req, $handler)->getStatusCode();
        }

        self::assertSame([200, 200, 429, 429], $codes,
            'POST /api/auth/tokens musí mít vlastní limit');
    }

    /**
     * Per-email limit konzumuje ForgotPasswordAction přes veřejné consume()
     * (tam už je tělo zparsované). Ověřujeme, že limit platí a vrací Retry-After.
     */
    public function testConsumeEnforcesPerEmailLimit(): void
    {
        $mw = $this->middleware();

        self::assertNull($mw->consume(self::EMAIL_BUCKET, 2, 3600), '1. pokus musí projít');
        self::assertNull($mw->consume(self::EMAIL_BUCKET, 2, 3600), '2. pokus musí projít');

        $blocked = $mw->consume(self::EMAIL_BUCKET, 2, 3600);
        self::assertNotNull($blocked, '3. pokus musí být odmítnutý');
        self::assertGreaterThan(0, $blocked, 'Retry-After musí být kladné');
        self::assertLessThanOrEqual(3600, $blocked, 'Retry-After nesmí přesáhnout okno');
    }

    /**
     * Neatomický GET → check → INCR dovoloval, aby se dávka requestů přečetla
     * ve stejném stavu a prošla nad limit. Sekvenční smyčka to nechytí přesně,
     * ale drží invariant: počet propuštěných se NIKDY nesmí lišit od limitu.
     */
    public function testBurstNeverExceedsLimit(): void
    {
        $mw = $this->middleware();
        $limit = 5;
        $key = self::EMAIL_BUCKET;

        $allowed = 0;
        for ($i = 0; $i < 40; $i++) {
            if ($mw->consume($key, $limit, 3600) === null) {
                $allowed++;
            }
        }

        self::assertSame($limit, $allowed,
            "Přes limit se nesmí protlačit ani jeden request navíc (propuštěno {$allowed}/{$limit})");
    }

    // -------------------------------------------------- 2. kolo: bypass & kill-switch

    /**
     * Middleware běží PŘED RoutingMiddleware. `Slim\Psr7\Uri` percent-encoding
     * ZACHOVÁVÁ, ale `RouteResolver::computeRoutingResults()` dělá rawurldecode()
     * před dispatchem — takže `/api/auth/%66orgot` se normálně doručí do
     * ForgotPasswordAction, zatímco `===` porovnání tady dřív nematchlo žádné
     * pravidlo (a generická větev celé /api/auth/ přeskakuje) → ŽÁDNÝ limit.
     */
    public function testPercentEncodedForgotPathIsLimited(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();

        $codes = [];
        for ($i = 0; $i < 4; $i++) {
            $req = (new ServerRequestFactory())
                ->createServerRequest('POST', 'http://localhost/api/auth/%66orgot', ['REMOTE_ADDR' => self::TEST_IP]);
            $codes[] = $mw->process($req, $handler)->getStatusCode();
        }

        self::assertSame([200, 200, 429, 429], $codes,
            '/api/auth/%66orgot musí spadnout do stejného bucketu jako /api/auth/forgot');
    }

    /** Totéž pro token_create limit — `/api/auth/%74okens`. */
    public function testPercentEncodedTokensPathIsLimited(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();

        $codes = [];
        for ($i = 0; $i < 4; $i++) {
            $req = (new ServerRequestFactory())
                ->createServerRequest('POST', 'http://localhost/api/auth/%74okens', ['REMOTE_ADDR' => self::TEST_IP]);
            $codes[] = $mw->process($req, $handler)->getStatusCode();
        }

        self::assertSame([200, 200, 429, 429], $codes,
            '/api/auth/%74okens musí spadnout do stejného bucketu jako /api/auth/tokens');
    }

    /** Encoded a plain varianta sdílí JEDEN bucket — jinak by se limit dal zdvojnásobit. */
    public function testEncodedAndPlainPathShareOneBucket(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();

        $plain = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/auth/forgot', ['REMOTE_ADDR' => self::TEST_IP]);
        $encoded = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/auth/%66orgot', ['REMOTE_ADDR' => self::TEST_IP]);

        self::assertSame(200, $mw->process($plain, $handler)->getStatusCode());
        self::assertSame(200, $mw->process($encoded, $handler)->getStatusCode());
        self::assertSame(429, $mw->process($plain, $handler)->getStatusCode(),
            'Limit 2/hod se nesmí dát obejít střídáním encoded/plain zápisu');
    }

    /** Vícenásobná lomítka a `.`/`..` segmenty taky nesmí pravidlo shodit. */
    public function testPathTraversalVariantsAreNormalized(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();

        foreach (['/api//auth/login', '/api/./auth/login', '/api/x/../auth/login'] as $variant) {
            $req = (new ServerRequestFactory())
                ->createServerRequest('POST', 'http://localhost' . $variant, ['REMOTE_ADDR' => self::TEST_IP]);
            $mw->process($req, $handler);
        }

        self::assertSame(3, $this->hits('rl:login:ip:%'),
            'Všechny varianty musí padnout do login bucketu');
    }

    /** Dvojité dekódování je vlastní zranitelnost — `%2566` NESMÍ skončit jako `f`. */
    public function testDoubleEncodingIsNotDecodedTwice(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();

        // rawurldecode('%2566orgot') === '%66orgot' — router by tuhle cestu taky
        // nenamatchoval, takže se sem žádné pravidlo aplikovat nemá.
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/auth/%2566orgot', ['REMOTE_ADDR' => self::TEST_IP]);

        for ($i = 0; $i < 4; $i++) {
            self::assertSame(200, $mw->process($req, $handler)->getStatusCode());
        }

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM rate_limit_counters WHERE bucket_key LIKE ?');
        $stmt->execute(['rl:forgot:ip:%']);
        self::assertSame(0, (int) $stmt->fetchColumn(),
            'Dvojitě zakódovaná cesta se nesmí dekódovat podruhé');
    }

    /**
     * Kill-switch `rate_limits.enabled = false` musí vypnout i veřejné consume(),
     * ne jen process(). Jinak instalace s vypnutým limiterem stejně dostala
     * per-email limit ve ForgotPasswordAction.
     */
    public function testKillSwitchDisablesConsumeToo(): void
    {
        $data = $this->config->all();
        $data['rate_limits']['enabled'] = false;
        $disabled = new RateLimitMiddleware(
            new Config($data, $this->config->dataDir()),
            new RedisFactory($this->config),
            new ResponseFactory(),
            new IpMatcher(),
            $this->db,
        );

        for ($i = 0; $i < 10; $i++) {
            self::assertNull($disabled->consume(self::EMAIL_BUCKET, 2, 3600),
                'Vypnutý limiter nesmí odmítat ani přes consume()');
        }

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM rate_limit_counters WHERE bucket_key = ?');
        $stmt->execute([self::EMAIL_BUCKET]);
        self::assertSame(0, (int) $stmt->fetchColumn(),
            'Vypnutý limiter nesmí ani zapisovat čítače');
    }

    /**
     * Per-email bucket je vázaný i na /24 odesílatele, takže spam z cizí IP
     * nevyčerpá sloty legitimnímu uživateli z jeho vlastní IP.
     *
     * Klíč skládáme stejně jako ForgotPasswordAction.
     */
    public function testForeignSpamDoesNotBlockVictimsOwnRecovery(): void
    {
        $mw = $this->middleware();
        $emailHash = sha1('victim@example.test');

        $keyFor = static fn (string $ipBucket): string
            => 'rl:forgot:email-ip:' . sha1($emailHash . '|' . $ipBucket);

        $attacker = $keyFor($mw->ipBucket('198.51.100.9'));
        $victim   = $keyFor($mw->ipBucket('203.0.113.9'));

        // Útočník vyčerpá svůj zdrojový bucket (limit 2 z setUp()).
        self::assertNull($mw->consume($attacker, 2, 3600));
        self::assertNull($mw->consume($attacker, 2, 3600));
        self::assertNotNull($mw->consume($attacker, 2, 3600), 'Útočníkův bucket musí být vyčerpaný');

        // Oběť ze své vlastní IP má pořád volno.
        self::assertNull($mw->consume($victim, 2, 3600),
            'Cizí spam nesmí zablokovat obnovu hesla z vlastní IP uživatele');

        $stmt = $this->db->pdo()->prepare('DELETE FROM rate_limit_counters WHERE bucket_key IN (?, ?)');
        $stmt->execute([$attacker, $victim]);
    }

    /** Okno se nesmí prodlužovat s každým requestem (dřív EXPIRE při každém INCR). */
    public function testWindowDoesNotSlideOnEveryRequest(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler();

        $first = $mw->process($this->loginRequest(), $handler);
        self::assertSame(200, $first->getStatusCode());

        for ($i = 0; $i < 5; $i++) {
            $mw->process($this->loginRequest(), $handler);
        }

        $blocked = $mw->process($this->loginRequest(), $handler);
        self::assertSame(429, $blocked->getStatusCode());
        self::assertLessThanOrEqual(60, (int) $blocked->getHeaderLine('Retry-After'),
            'Retry-After nesmí přerůst původní okno — jinak se okno posouvá s každým requestem');
    }
}
