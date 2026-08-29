<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Rate limiter dle cfg.rate_limits — pevné okno (Redis INCR+EXPIRE, v DB větvi
 * zarovnané `window_start`).
 *
 *   login_per_min_per_ip      → 60s window, key = "rl:login:ip:{ip/24}"
 *   forgot_per_hour_per_ip    → 3600s window, key = "rl:forgot:ip:{ip/24}"
 *   forgot_per_hour_per_email → 3600s window, key = "rl:forgot:email:{sha1}"
 *                               (konzumuje ForgotPasswordAction přes consume() —
 *                                tady na e-mail nedosáhneme, viz ruleFor())
 *   token_create_per_hour     → 3600s window, key = "rl:pat:user:{id}"
 *   ares_per_min_per_user     → 60s window, key = "rl:ares:user:{id}"
 *   ai_per_5min_per_user      → 300s window, key = "rl:ai:user:{id}" (AI extract + inbox scan)
 *   setup_per_hour_per_ip     → 3600s window, key = "rl:setup:ip:{ip}"
 *   mutation_per_min_per_user → 60s window, key = "rl:mut:user:{id}" (POST/PUT/PATCH/DELETE)
 *   read_per_min_per_user     → 60s window, key = "rl:read:user:{id}" (GET)
 *
 * Při překročení vrátí 429 Too Many Requests s Retry-After.
 *
 * Primárně Redis; bez něj fallback na MEMORY tabulku `rate_limit_counters`
 * (stejný vzor jako BruteForceGuard/login_attempts). Limiter tedy platí i na
 * IIS a v Dockeru bez `--profile redis` — dřív se tam tiše vypínal celý.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    /** Nahlásili jsme v tomhle requestu už výpadek DB větve? (jeden log stačí) */
    private bool $dbFailureLogged = false;

    public function __construct(
        private readonly Config $config,
        private readonly RedisFactory $redis,
        private readonly ResponseFactory $responseFactory,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        // Kill-switch (dev/test) — `rate_limits.enabled === false` vypne limiter úplně.
        // Chybějící klíč = zapnuto (default true), takže PRODUKCE zůstává chráněná i bez
        // explicitního nastavení; vypíná se jen lokálním overridem v cfg.local.php.
        if ($this->config->get('rate_limits.enabled', true) === false) {
            return $handler->handle($request);
        }

        // Normalizace cesty (jedno rawurldecode jako router) — bez ní běželo
        // `POST /api/auth/%66orgot` úplně bez limitu; viz RequestPath.
        $path = RequestPath::normalize($request->getUri()->getPath());
        $method = strtoupper($request->getMethod());
        $ip = $this->ipMatcher->clientIp(
            $request->getServerParams(),
            (array) $this->config->get('ip_allowlist.trusted_proxies', []),
            (string) $this->config->get('ip_allowlist.header', 'X-Forwarded-For'),
        );

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : 0;
        $apiToken = $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN);
        $tokenId = is_array($apiToken) ? (int) ($apiToken['id'] ?? 0) : 0;

        // Per-route limity — vrací [key, limit, window]
        $rule = $this->ruleFor($path, $method, $ip, $userId, $tokenId, $request);
        if ($rule === null) {
            return $handler->handle($request);
        }

        [$key, $limit, $window] = $rule;

        $state = $this->consumeState($key, $limit, $window);
        $ttl = max(1, $state['ttl']);

        // Headers se posílají u všech bearer-authed requestů (rfc draft-rate-limit
        // používá X-RateLimit-*). U session/IP requestů nemá smysl — klient
        // nemůže selektivně self-throttle per uživatel.
        $sendHeaders = $tokenId > 0;

        if (!$state['allowed']) {
            $response = $this->responseFactory->createResponse(429);
            $response = $response->withHeader('Retry-After', (string) $ttl);
            if ($sendHeaders) {
                $response = $response
                    ->withHeader('X-RateLimit-Limit',     (string) $limit)
                    ->withHeader('X-RateLimit-Remaining', '0')
                    ->withHeader('X-RateLimit-Reset',     (string) $ttl);
            }
            return Json::error($response, 'rate_limited', 'Příliš mnoho pokusů. Zkus to později.', 429);
        }

        $response = $handler->handle($request);
        if ($sendHeaders) {
            $response = $response
                ->withHeader('X-RateLimit-Limit',     (string) $limit)
                ->withHeader('X-RateLimit-Remaining', (string) max(0, $limit - $state['count']))
                ->withHeader('X-RateLimit-Reset',     (string) $ttl);
        }
        return $response;
    }

    /**
     * Odečte jeden slot z bucketu. Určeno pro akce, které limit potřebují až po
     * zparsovaném těle requestu (middleware běží PŘED BodyParsingMiddleware, takže
     * na hodnoty z JSON body v `process()` nedosáhne — viz Bootstrap.php LIFO order).
     *
     * @return int|null  null = průchod povolen, jinak Retry-After v sekundách
     */
    public function consume(string $key, int $limit, int $window): ?int
    {
        // Stejný kill-switch jako v process() — bez něj měla instalace s vypnutým
        // limiterem přesto per-email limit ve ForgotPasswordAction, který nečekala
        // (a v testech proti sdílené DB rozbíjel scénáře, co limiter vypínají).
        if ($this->config->get('rate_limits.enabled', true) === false) {
            return null;
        }

        $state = $this->consumeState($key, $limit, $window);

        return $state['allowed'] ? null : max(1, $state['ttl']);
    }

    /**
     * Atomický check-and-increment.
     *
     * Dřív to byl neatomický GET → porovnání → INCR → EXPIRE: souběžná dávka
     * requestů si přečetla stejný počet a všechny prošly (limit se dal překročit
     * n-násobně), a EXPIRE při KAŽDÉM requestu okno donekonečna prodlužoval.
     *
     * Teď dvoufázově:
     *   1. levný peek — když jsme už přes limit, odmítni BEZ zápisu (jinak by
     *      útok nafukoval Redis klíč / MEMORY tabulku donekonečna),
     *   2. atomický bump + re-check — souběžné requesty dostanou různá pořadová
     *      čísla, takže přes limit se na hranici nikdo neprotlačí.
     *
     * @return array{allowed:bool,count:int,ttl:int}
     */
    private function consumeState(string $key, int $limit, int $window): array
    {
        $peek = $this->peek($key, $window);
        if ($peek['count'] >= $limit) {
            return ['allowed' => false, 'count' => $peek['count'], 'ttl' => $peek['ttl']];
        }

        $bumped = $this->bump($key, $window);

        return [
            'allowed' => $bumped['count'] <= $limit,
            'count'   => $bumped['count'],
            'ttl'     => $bumped['ttl'],
        ];
    }

    /**
     * Přečte stav bucketu bez zápisu. Redis primary, jinak DB fallback.
     *
     * @return array{count:int,ttl:int}
     */
    private function peek(string $key, int $window): array
    {
        $state = $this->redis->run(function ($r) use ($key, $window) {
            $count = (int) ($r->get($key) ?? 0);
            $ttl = (int) $r->ttl($key);
            if ($ttl < 0) $ttl = $window;  // -1/-2 = neexistuje nebo bez TTL
            return ['count' => $count, 'ttl' => $ttl];
        });

        return $state ?? $this->dbState($key, $window);
    }

    /**
     * Zvýší čítač a vrátí NOVOU hodnotu.
     *
     * @return array{count:int,ttl:int}
     */
    private function bump(string $key, int $window): array
    {
        // INCR je v Redisu atomický → každý souběžný request dostane jiné číslo.
        // EXPIRE nastavujeme jen u prvního requestu v okně (count === 1), jinak by
        // se okno posouvalo s každým dalším a bucket by nikdy nevypršel. Pojistka
        // `$ttl < 0` pokrývá pád mezi INCR a EXPIRE (klíč bez TTL = věčný).
        $state = $this->redis->run(function ($r) use ($key, $window) {
            $count = (int) $r->incr($key);
            $ttl = (int) $r->ttl($key);
            if ($count === 1 || $ttl < 0) {
                $r->expire($key, $window);
                $ttl = $window;
            }
            return ['count' => $count, 'ttl' => $ttl];
        });
        if ($state !== null) {
            return $state;
        }

        // DB fallback: nejdřív INSERT, teprve pak COUNT. V tomhle pořadí se každý
        // souběžný request započítá sám do sebe, takže se přes limit neprotlačí
        // (MEMORY engine je netransakční — spoléháme na pořadí, ne na transakci).
        $this->dbIncrement($key, $window);

        return $this->dbState($key, $window);
    }

    /**
     * @return array{0:string,1:int,2:int}|null  [redisKey, limit, windowSeconds]
     */
    private function ruleFor(string $path, string $method, string $ip, int $userId, int $tokenId, Request $request): ?array
    {
        $rl = (array) $this->config->get('rate_limits', []);

        // Bearer (API token) — vlastní bucket per token, ne per user (jeden user
        // může mít víc tokenů pro různé integrace s nezávislými limity).
        if ($tokenId > 0) {
            $limit = (int) ($rl['api_per_min_per_token'] ?? 600);
            return ['rl:api:tok:' . $tokenId, $limit, 60];
        }

        // Login — všichni
        if ($path === '/api/auth/login' && $method === 'POST') {
            return ['rl:login:ip:' . $this->ipBucket($ip), (int) ($rl['login_per_min_per_ip'] ?? 10), 60];
        }
        if ($path === '/api/auth/webauthn/login/verify' && $method === 'POST') {
            return ['rl:passkey-login:ip:' . $this->ipBucket($ip), (int) ($rl['login_per_min_per_ip'] ?? 10), 60];
        }
        if ($path === '/api/auth/webauthn/login/options' && $method === 'POST') {
            return ['rl:passkey-login-options:ip:' . $this->ipBucket($ip), (int) ($rl['login_per_min_per_ip'] ?? 10), 60];
        }
        if (in_array($path, ['/api/auth/domain-login/start', '/api/auth/domain-login/exchange'], true)
            && $method === 'POST'
        ) {
            return ['rl:domain-login:' . sha1($path) . ':ip:' . $this->ipBucket($ip), 10, 60];
        }
        if ($path === '/api/auth/domain-login/authorize' && $method === 'POST' && $userId > 0) {
            return ['rl:domain-login-authorize:user:' . $userId, 20, 60];
        }

        if ($userId > 0 && str_starts_with($path, '/api/auth/session/')) {
            if (in_array($path, [
                '/api/auth/session/status',
                '/api/auth/session/activity',
            ], true)) {
                $sessionToken = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
                $subject = $sessionToken !== ''
                    ? 'session:' . hash('sha256', $sessionToken)
                    : 'user:' . $userId;
                return ['rl:session-poll:' . sha1($path) . ':' . $subject, 120, 60];
            }
            return ['rl:session:' . sha1($path) . ':user:' . $userId, 20, 60];
        }
        if ($userId > 0 && (
            str_starts_with($path, '/api/auth/webauthn/')
            || str_starts_with($path, '/api/auth/mfa/step-up/')
        )) {
            return ['rl:webauthn:' . sha1($path) . ':user:' . $userId, 20, 60];
        }

        // Forgot — per IP.
        //
        // Dřív se tu sahalo na getParsedBody() pro per-email bucket. Jenže tenhle
        // middleware běží PŘED BodyParsingMiddleware (Slim LIFO, viz Bootstrap.php),
        // takže u JSON requestů bylo tělo vždy null → e-mail prázdný → pravidlo se
        // nevybralo a `/api/auth/forgot` neměl ŽÁDNÝ limit (generic per-user limit
        // níže celý /api/auth/* přeskakuje).
        //
        // Per-IP bucket je nezávislý na těle, takže platí vždy. Per-email limit
        // konzumuje ForgotPasswordAction přes consume(), kde už tělo zparsované je.
        if ($path === '/api/auth/forgot' && $method === 'POST') {
            return ['rl:forgot:ip:' . $this->ipBucket($ip), (int) ($rl['forgot_per_hour_per_ip'] ?? 10), 3600];
        }

        // Tvorba API tokenu (PAT) — /api/auth/* je z generic limitu vyňaté, takže
        // bez tohohle pravidla neměl endpoint limit žádný. Brzdí i hádání hesla
        // a TOTP kódu ve step-up kroku (doplněk k BruteForceGuard v akci).
        if ($path === '/api/auth/tokens' && $method === 'POST') {
            $bucket = $userId > 0 ? 'user:' . $userId : 'ip:' . $this->ipBucket($ip);
            return ['rl:pat:' . $bucket, (int) ($rl['token_create_per_hour'] ?? 10), 3600];
        }

        // Setup
        if ($path === '/api/auth/setup' && $method === 'POST') {
            return ['rl:setup:ip:' . $this->ipBucket($ip), (int) ($rl['setup_per_hour_per_ip'] ?? 5), 3600];
        }

        // Setup ARES / CRPDPH lookup (public během setup okna) — chrání proti DoS na registry
        if (in_array($path, ['/api/auth/setup-ares-lookup', '/api/auth/setup-crpdph-lookup'], true) && $method === 'POST') {
            return ['rl:setup-ares:ip:' . $this->ipBucket($ip), 10, 60]; // 10/min/IP
        }

        // Veřejné schvalování výkazu (bez auth, jen token) — IP bucket proti
        // anonymnímu DoS / opakovaným pokusům (GET i decide). userId je tu vždy 0,
        // takže generic per-user limit níže by se neuplatnil.
        if (str_starts_with($path, '/api/public/approval/')) {
            return ['rl:approval:ip:' . $this->ipBucket($ip), (int) ($rl['approval_per_min_per_ip'] ?? 30), 60];
        }

        // Veřejná web faktura (bez auth, jen token) — IP bucket proti anonymnímu
        // DoS (náhled + PDF render + přílohy). userId je tu vždy 0. Limit vyšší
        // než approval: legit návštěva = 1 GET + PDF + N příloh.
        if (str_starts_with($path, '/api/public/invoice/')) {
            return ['rl:pubinv:ip:' . $this->ipBucket($ip), (int) ($rl['invoice_public_per_min_per_ip'] ?? 60), 60];
        }

        // Veřejný náhled výkazu práce (bez auth, jen token). GET je čtení jako
        // u faktury; POST je citlivější: request-code ODESÍLÁ E-MAIL s ověřovacím
        // kódem a verify je brute-force plocha na ten kód. Proto vlastní, přísnější
        // bucket pro mutace.
        //
        // Captcha tuhle díru nekryje: výchozí `captcha.provider = 'none'` nechá
        // TurnstileVerifier::verify() rovnou vrátit true, takže na běžném
        // self-hostu nechrání nic. Generic per-user limit níž se taky neuplatní —
        // userId je u veřejného odkazu vždy 0.
        if (str_starts_with($path, '/api/public/work-report/')) {
            if ($method === 'POST') {
                return ['rl:pubwr-post:ip:' . $this->ipBucket($ip),
                        (int) ($rl['work_report_public_post_per_min_per_ip'] ?? 10), 60];
            }
            return ['rl:pubwr:ip:' . $this->ipBucket($ip),
                    (int) ($rl['work_report_public_per_min_per_ip'] ?? 60), 60];
        }

        // ARES / VIES / CRPDPH lookups (per user) — chrání 24h cache před zaplněním
        if (in_array($path, ['/api/clients/lookup-ares', '/api/clients/lookup-vies', '/api/clients/lookup-bank'], true) && $userId > 0) {
            return ['rl:ares:user:' . $userId, (int) ($rl['ares_per_min_per_user'] ?? 30), 60];
        }

        // AI / inbox scan endpoints — costly Anthropic API calls (BYOK billing risk
        // při kompromitované admin session). Sliding window 5 min / per user.
        if ($userId > 0 && $method === 'POST' && in_array($path, [
            '/api/admin/imports/ai-extract-pdf',
            '/api/purchase-invoices/scan-inbox',
        ], true)) {
            return ['rl:ai:user:' . $userId, (int) ($rl['ai_per_5min_per_user'] ?? 30), 300];
        }

        // Odhalení citlivého osobního údaje (rodné číslo, číslo účtu, kontakty).
        // Generický `rl:mut` bucket na tohle nestačí: 60 mutací/min sdílených se
        // vším ostatním dovolí z jedné kompromitované session vytáhnout celou
        // mzdovou evidenci za pár minut a v auditu to vypadá jako běžný provoz.
        // Legitimní použití je jednotky případů denně (účetní si otevře kartu),
        // takže přísný vlastní bucket nikoho neomezí, ale hromadnou exfiltraci
        // zpomalí na viditelnou rychlost. Klíčem je uživatel, ne IP — právo
        // `payroll.person.read_sensitive` je vázané na účet.
        if ($userId > 0
            && $method === 'POST'
            && preg_match(
                '#^/api/payroll/people/[0-9]+/sensitive-reveal$#',
                $path,
            ) === 1
        ) {
            return [
                'rl:reveal:user:' . $userId,
                (int) ($rl['payroll_reveal_per_5min_per_user'] ?? 20),
                300,
            ];
        }

        // Generic per-user mutation/read limit (jen pro přihlášené, mimo public)
        if ($userId > 0 && !str_starts_with($path, '/api/auth/')) {
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return ['rl:mut:user:' . $userId, (int) ($rl['mutation_per_min_per_user'] ?? 60), 60];
            }
            if ($method === 'GET') {
                return ['rl:read:user:' . $userId, (int) ($rl['read_per_min_per_user'] ?? 300), 60];
            }
        }

        return null;
    }

    /**
     * Šířka `rate_limit_counters.bucket_key`. Tabulka je ENGINE=MEMORY, kde se
     * VARCHAR paduje na plnou délku, takže sloupec nelze rozšiřovat od oka —
     * platí se za to pamětí u každého řádku.
     */
    private const DB_KEY_MAX = 120;

    /**
     * Klíč pro DB větev. Redis unese libovolnou délku, MEMORY tabulka ne:
     * `rl:session-poll:` + sha1(path) + `:session:` + sha256(session id) dá 129
     * znaků a INSERT skončil na `1406 Data too long` → 500 na každém pollu
     * session. Projevilo se to jen bez Redisu, tedy přesně na výchozím Dockeru.
     *
     * Delší klíč proto zkrátíme na čitelnou předponu + sha1 celého klíče. Kolize
     * je tím pořád vyloučená a v tabulce zůstane poznat, o jaký bucket šlo.
     */
    private static function dbKey(string $key): string
    {
        if (strlen($key) <= self::DB_KEY_MAX) {
            return $key;
        }
        return substr($key, 0, self::DB_KEY_MAX - 41) . ':' . sha1($key);
    }

    /**
     * Začátek aktuálního okna — zarovnaný na násobek délky okna, aby se na něj
     * dalo klíčovat. Čas bereme z PHP, ne z DB: `expires_at` i `window_start` tak
     * pochází z jediných hodin a nezávisí na session timezone MariaDB (dřívější
     * `created_at >= NOW() - INTERVAL ?` na tomhle rozdílu mohl tiše ujet).
     */
    private static function windowStart(int $window): int
    {
        $window = max(1, $window);

        return intdiv(time(), $window) * $window;
    }

    /**
     * DB fallback čítače — jeden řádek na (bucket, okno) s počítadlem `hits`.
     *
     * Dřív se zapisoval JEDEN ŘÁDEK NA REQUEST a mazal je až denní cron s dvouhodinovou
     * retencí. MEMORY tabulka má tvrdý strop `max_heap_table_size` (výchozích 16 MB),
     * takže na produkci narostla na 31 665 řádků / 15,7 MB a od té chvíle padal každý
     * INSERT na `1114 table is full`. Protože limiter běží nad každým requestem,
     * shodila jedna plná pomocná tabulka celé API do 500 (viz migrace 1317).
     *
     * `ttl` = zbytek okna, takže Retry-After odpovídá skutečnému uvolnění slotu.
     *
     * Při nedostupné DB vrací nulový stav → limiter propustí (fail-open). Shodit
     * celé API kvůli pomocnému čítači je horší selhání než dočasně nevynucený limit;
     * hrubou sílu na loginu drží nezávisle BruteForceGuard nad `login_attempts`.
     *
     * @return array{count:int,ttl:int,redis:bool}
     */
    private function dbState(string $key, int $window): array
    {
        $start = self::windowStart($window);
        $ttl = $start + $window - time();
        if ($ttl < 1) {
            $ttl = $window;
        }

        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT hits FROM rate_limit_counters WHERE bucket_key = ? AND window_start = ?'
            );
            $stmt->execute([self::dbKey($key), $start]);
            $hits = (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            $this->dbUnavailable($e);

            return ['count' => 0, 'ttl' => $window, 'redis' => false];
        }

        return ['count' => $hits, 'ttl' => $ttl, 'redis' => false];
    }

    private function dbIncrement(string $key, int $window): void
    {
        $dbKey = self::dbKey($key);
        $start = self::windowStart($window);

        try {
            $inserted = $this->dbUpsert($dbKey, $start, $start + $window);
        } catch (\Throwable $e) {
            // Nejpravděpodobnější příčina je plná MEMORY tabulka (1114). Zameť
            // expirované buckety a zkus zápis ještě jednou — instalace, které
            // nemají rozběhnutý cron, se tím uzdraví samy.
            try {
                $this->dbPruneExpired();
                $this->dbUpsert($dbKey, $start, $start + $window);
            } catch (\Throwable $fatal) {
                $this->dbUnavailable($fatal);
            }

            return;
        }

        // Úklid věšíme na ZALOŽENÍ nového bucketu, ne na každý request: přesně
        // tehdy tabulka roste, takže sweep drží krok s růstem a mimo něj nestojí
        // nic. Náhodné vzorkování by tuhle záruku nedalo.
        if ($inserted) {
            try {
                $this->dbPruneExpired();
            } catch (\Throwable $e) {
                $this->dbUnavailable($e);
            }
        }
    }

    /**
     * Atomický inkrement čítače okna.
     *
     * @return bool true = vznikl nový řádek (nový bucket/okno), false = jen inkrement
     */
    private function dbUpsert(string $dbKey, int $windowStart, int $expiresAt): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO rate_limit_counters (bucket_key, window_start, hits, expires_at)
                  VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        );
        $stmt->execute([$dbKey, $windowStart, $expiresAt]);

        // MariaDB vrací 1 za INSERT a 2 za větev ON DUPLICATE KEY UPDATE.
        return $stmt->rowCount() === 1;
    }

    /** Buckety, jejichž okno už doběhlo — indexovaný rozsahový DELETE nad `expires_at`. */
    private function dbPruneExpired(): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM rate_limit_counters WHERE expires_at <= ?'
        );
        $stmt->execute([time()]);
    }

    private function dbUnavailable(\Throwable $e): void
    {
        if ($this->dbFailureLogged) {
            return;
        }
        $this->dbFailureLogged = true;

        $this->logger?->warning(
            'Rate limiter: DB fallback selhal, request jede bez limitu.',
            ['error' => $e->getMessage()],
        );
    }

    /** Public kvůli ForgotPasswordAction — potřebuje stejné /24 (resp. /64) bucketování. */
    public function ipBucket(string $ip): string
    {
        $packed = inet_pton($ip);
        if ($packed === false) return sha1($ip);
        if (strlen($packed) === 4) return bin2hex(substr($packed, 0, 3)); // /24
        return bin2hex(substr($packed, 0, 8)); // /64
    }
}
