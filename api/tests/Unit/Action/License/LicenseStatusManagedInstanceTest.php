<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\License;

use DateTimeImmutable;
use MyInvoice\Action\License\LicenseStatusAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Service\License\BillingSnapshot;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Service\System\InstanceEntitlement;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\System\StorageQuotaPolicy;
use MyInvoice\Service\System\StorageQuotaStatus;
use MyInvoice\Service\System\StorageUsageMeter;
use MyInvoice\Service\System\StorageUsageSnapshot;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * GET /api/license/status — co se ve spravované instalaci dostane ven a co ne.
 *
 * Obrazovka aktivace (`/activation/purchase`) z tohohle payloadu staví STAV
 * SLUŽBY místo nabídky koupit licenci. Testuje se proto právě rozhraní, ne UI:
 *
 *  1. self-hosted odpověď se NESMÍ změnit (je to hlavní cesta k nákupu licence),
 *  2. nezměřeno se nesmí projevit jako nula,
 *  3. bez zaplaceného objemu se nesmí počítat procenta,
 *  4. na 90 % musí být z čeho vyrobit výzvu a na 100 % vysvětlení,
 *  5. anonymní volající se k obsazení nesmí dostat.
 *
 * Bez databáze: `Connection` se konstruuje jen kvůli typu (spojení navazuje až
 * `pdo()`), měření podstrkuje testovací potomek {@see StorageQuotaPolicy} —
 * třída je kvůli tomu záměrně neuzavřená.
 */
final class LicenseStatusManagedInstanceTest extends TestCase
{
    private const GB = 1024 * 1024 * 1024;

    // ─────────────────────────────────────────────────────────────────────────
    //  1) Self-hosted — regrese
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: nejsnazší implementace přidá
     * `instance` do payloadu vždycky a ve self-hosted režimu ho vyplní `null`
     * nebo prázdným objektem. Klíč navíc je pro self-hosted odpověď změna —
     * a tahle obrazovka je hlavní cesta k nákupu licence pro zákazníky
     * s vlastním serverem.
     */
    public function testSelfHostedResponseIsUnchanged(): void
    {
        $state   = $this->state();
        $config  = $this->config(managed: false, instance: ['quota_gb' => 10]);
        $payload = $this->invoke($config, $state, $this->measured(9 * self::GB));

        self::assertArrayNotHasKey('instance', $payload, 'Self-hosted odpověď nesmí nést blok spravované instalace.');

        $expected = array_merge(array_keys($state->toArray('https://example.test/objednavka')), ['company', 'development']);
        sort($expected);
        $actual = array_keys($payload);
        sort($actual);
        self::assertSame($expected, $actual, 'Self-hosted payload musí mít přesně původní klíče.');
    }

    /** Ani vyčerpaná kvóta nesmí na self-hosted instalaci nic přidat. */
    public function testSelfHostedStaysCleanEvenWhenStorageWouldBeExhausted(): void
    {
        $payload = $this->invoke(
            $this->config(managed: false, instance: ['quota_gb' => 1]),
            $this->state(),
            $this->measured(5 * self::GB),
        );

        self::assertArrayNotHasKey('instance', $payload);
    }

    public function testDevelopmentFlagRequiresExactServerEnvironment(): void
    {
        $snapshot = $this->measured(0);

        self::assertTrue($this->invoke(
            $this->config(managed: false, extra: ['app' => ['env' => 'development']]),
            $this->state(),
            $snapshot,
        )['development']);
        self::assertFalse($this->invoke(
            $this->config(managed: false, extra: ['app' => ['env' => 'production']]),
            $this->state(),
            $snapshot,
        )['development']);
        self::assertFalse($this->invoke(
            $this->config(managed: false, extra: ['app' => ['env' => 'Development']]),
            $this->state(),
            $snapshot,
        )['development']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  2) Nezměřeno není nula
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: implementace, která posílá
     * `usage_bytes => (int) $snapshot->usageBytes` (nebo `?? 0`), vyrobí
     * z „ještě jsme neměřili" tvrzení „0 B, 0 %, vše v pořádku". Prázdná
     * instalace a nezměřená instalace vypadají v datech stejně a znamenají opak.
     */
    public function testUnmeasuredUsageIsNeverReportedAsZero(): void
    {
        $storage = $this->storage($this->config(instance: ['quota_gb' => 10]), StorageUsageSnapshot::unmeasured());

        self::assertFalse($storage['measured']);
        self::assertNull($storage['usage_bytes'], 'Nezměřeno musí zůstat null, ne 0.');
        self::assertNull($storage['percent'], 'Nezměřeno není 0 %.');
        self::assertNull($storage['measured_at']);
        self::assertFalse($storage['blocks_writes']);
        // Zaplacený objem přitom známe — nezměřená spotřeba ho neruší.
        self::assertSame(10 * self::GB, $storage['quota_bytes']);
    }

    /** Skutečně prázdná instalace naopak 0 % JE — obě cesty se musí rozejít. */
    public function testGenuinelyEmptyInstallationDiffersFromUnmeasuredOne(): void
    {
        $config = $this->config(instance: ['quota_gb' => 10]);

        $unmeasured = $this->storage($config, StorageUsageSnapshot::unmeasured());
        $empty      = $this->storage($config, $this->measured(0));

        self::assertNull($unmeasured['percent']);
        $this->assertPercent(0.0, $empty['percent']);
        self::assertSame(0, $empty['usage_bytes']);
        self::assertTrue($empty['measured']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  3) Bez zaplaceného objemu se nepočítají procenta
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: implementace, která poměr počítá
     * z provozního limitu (`storage_quota.limit_mb`), vrátí procenta i tehdy,
     * když zaplacený objem neznáme. Disková kvóta hostingu je ale „zaplacený
     * objem + rezerva na dumpy", takže by zákazník viděl MÍŇ, než kolik ze
     * zaplaceného doopravdy vyčerpal.
     */
    public function testWithoutContractedVolumeThereAreNoPercentages(): void
    {
        $storage = $this->storage(
            $this->config(quota: ['limit_mb' => 20480]),   // provozní limit ano, smluvní objem ne
            $this->measured(9 * self::GB),
        );

        self::assertNull($storage['quota_bytes'], 'Neznámý zaplacený objem zůstává null.');
        self::assertNull($storage['percent'], 'Bez zaplaceného objemu se procenta nepočítají.');
        // Absolutní obsazení ale ven jde — obrazovka ho ukáže bez pruhu.
        self::assertSame(9 * self::GB, $storage['usage_bytes']);
        self::assertTrue($storage['measured']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  4) Prahy: 90 % výzva, 100 % vysvětlení
    // ─────────────────────────────────────────────────────────────────────────

    /** Na 90 % má obrazovka z čeho vyrobit výzvu — a zapisovat se pořád smí. */
    public function testNinetyPercentOfContractedVolumeIsAWarningNotALock(): void
    {
        $storage = $this->storage($this->config(instance: ['quota_gb' => 10]), $this->measured(9 * self::GB));

        $this->assertPercent(90.0, $storage['percent']);
        self::assertSame(90, $storage['warn_percent']);
        self::assertSame(100, $storage['read_only_percent']);
        self::assertGreaterThanOrEqual($storage['warn_percent'], $storage['percent']);
        self::assertLessThan($storage['read_only_percent'], $storage['percent']);
        self::assertFalse($storage['blocks_writes'], 'Na 90 % se zapisovat nepřestává.');
    }

    /** Těsně pod prahem se nevaruje. */
    public function testJustBelowTheWarningThresholdNothingIsRaised(): void
    {
        $storage = $this->storage($this->config(instance: ['quota_gb' => 10]), $this->measured((int) (8.5 * self::GB)));

        $this->assertPercent(85.0, $storage['percent']);
        self::assertLessThan($storage['warn_percent'], $storage['percent']);
        self::assertFalse($storage['blocks_writes']);
    }

    /** Na 100 % musí být zřejmé, že se nezapisuje — a proč. */
    public function testHundredPercentReportsBlockedWrites(): void
    {
        $storage = $this->storage($this->config(instance: ['quota_gb' => 10]), $this->measured(10 * self::GB));

        $this->assertPercent(100.0, $storage['percent']);
        self::assertGreaterThanOrEqual($storage['read_only_percent'], $storage['percent']);
        self::assertTrue($storage['blocks_writes'], 'Vyčerpaný prostor musí být hlášený jako zámek zápisu.');
    }

    /** Překročený objem se nesmí „přetočit" na hezké číslo. */
    public function testOverQuotaKeepsTheRealRatio(): void
    {
        $storage = $this->storage($this->config(instance: ['quota_gb' => 10]), $this->measured(15 * self::GB));

        $this->assertPercent(150.0, $storage['percent']);
        self::assertTrue($storage['blocks_writes']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  5) Zbytek bloku `instance`
    // ─────────────────────────────────────────────────────────────────────────

    /** Tarif, datum zřízení a odkaz na správu předplatného z konfigurace. */
    public function testManagedBlockCarriesScopeAndSubscriptionLink(): void
    {
        $payload = $this->invoke(
            $this->config(
                instance: ['quota_gb' => 10, 'plan' => 'standard', 'managed_since' => '2026-01-15'],
                extra: ['license' => ['server_url' => 'https://example.test']],
            ),
            $this->state(),
            $this->measured(1 * self::GB),
        );

        self::assertTrue($payload['instance']['managed']);
        self::assertSame('standard', $payload['instance']['plan']);
        self::assertSame('2026-01-15', $payload['instance']['managed_since']);
        self::assertSame('https://example.test/predplatne', $payload['instance']['subscription_url']);
    }

    /** Explicitní adresa portálu se použije tak, jak je — nic se k ní nelepí. */
    public function testExplicitPortalUrlIsUsedVerbatim(): void
    {
        $payload = $this->invoke(
            $this->config(instance: ['quota_gb' => 10, 'portal_url' => 'https://example.test/ucet/predplatne']),
            $this->state(),
            $this->measured(1 * self::GB),
        );

        self::assertSame('https://example.test/ucet/predplatne', $payload['instance']['subscription_url']);
    }

    /**
     * Nenakonfigurovaná adresa → `null`, ne poloviční odkaz. Zákazník nesmí
     * dostat tlačítko, které nikam nevede; obrazovka místo něj ukáže kontakt.
     */
    public function testMissingPortalConfigurationYieldsNoLinkInsteadOfADeadOne(): void
    {
        $payload = $this->invoke(
            $this->config(instance: ['quota_gb' => 10]),
            $this->state(),
            $this->measured(1 * self::GB),
        );

        self::assertNull($payload['instance']['subscription_url']);
        self::assertNull($payload['instance']['plan']);
        self::assertNull($payload['instance']['managed_since']);
    }

    /**
     * ⚠️ Aplikace nesmí vědět, KDO ji hostuje. `app.managed_provider` je čistě
     * diagnostický údaj do /api/health a do zákaznického payloadu nepatří.
     */
    public function testNothingAboutTheHostingProviderLeaksOut(): void
    {
        $payload = $this->invoke(
            $this->config(instance: ['quota_gb' => 10], extra: ['app' => ['managed_provider' => 'tajny-dodavatel']]),
            $this->state(),
            $this->measured(1 * self::GB),
        );

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('tajny-dodavatel', $json);
        self::assertStringNotContainsString('managed_provider', $json);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  6) Anonymní volající
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: obsazení místa je provozní údaj
     * o konkrétním zákazníkovi. Kdyby se blok `instance` doplnil do payloadu
     * dřív, než se ověří role (nebo do anonymního endpointu se stavem setupu),
     * dostal by ho kdokoli, kdo zná URL.
     */
    public function testAnonymousCallerNeverSeesStorageUsage(): void
    {
        $config = $this->config(instance: ['quota_gb' => 10]);
        $action = $this->action($config, $this->measured(9 * self::GB));

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/license/status')
            ->withAttribute(LicenseMiddleware::ATTR_STATE, $this->state());

        $response = $action($request, (new ResponseFactory())->createResponse());

        self::assertSame(403, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('usage_bytes', $body);
        self::assertStringNotContainsString('quota_bytes', $body);

        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('instance', $payload);
        self::assertSame('forbidden', $payload['error']['code']);
    }

    /** Neadministrátor je na tom stejně — role se kontroluje, ne přihlášení. */
    public function testNonAdminUserNeverSeesStorageUsage(): void
    {
        $config = $this->config(instance: ['quota_gb' => 10]);
        $action = $this->action($config, $this->measured(9 * self::GB));

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/license/status')
            ->withAttribute(LicenseMiddleware::ATTR_STATE, $this->state())
            ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'accountant', 'email' => 'ucetni@example.test']);

        $response = $action($request, (new ResponseFactory())->createResponse());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('usage_bytes', (string) $response->getBody());
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Pomocné
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $quota
     * @param array<string,mixed> $instance
     * @param array<string,mixed> $extra
     */
    private function config(
        bool $managed = true,
        array $quota = [],
        array $instance = [],
        array $extra = [],
    ): Config {
        return new Config(array_replace_recursive([
            'app'           => ['managed' => $managed],
            'storage_quota' => $quota,
            'instance'      => $instance,
        ], $extra));
    }

    private function measured(int $usageBytes): StorageUsageSnapshot
    {
        return new StorageUsageSnapshot(
            measuredAt:    new DateTimeImmutable('2026-08-21 10:00:00'),
            databaseBytes: (int) round($usageBytes / 4),
            filesBytes:    $usageBytes - (int) round($usageBytes / 4),
            usageBytes:    $usageBytes,
        );
    }

    private function state(): LicenseState
    {
        return new LicenseState(
            LicenseState::ACTIVE,
            'inst-test',
            'multi10',
            10,
            5,
            3,
            2,
            1_800_000_000,
            null,
            null,
            'MYU-TEST-0000-0001',
            '2026-08-21T09:00:00+00:00',
            true,
        );
    }

    /** Kvótová politika s podstrčeným měřením — bez databáze. */
    private function policy(Config $config, StorageUsageSnapshot $snapshot): StorageQuotaPolicy
    {
        return new class (
            $config,
            new ManagedModeGuard($config),
            new StorageUsageMeter(new Connection($config), $config),
            new InstanceEntitlement(new Connection($config), $config),
            $snapshot,
        ) extends StorageQuotaPolicy {
            public function __construct(
                Config $config,
                ManagedModeGuard $managed,
                StorageUsageMeter $meter,
                InstanceEntitlement $entitlement,
                private readonly StorageUsageSnapshot $snapshot,
            ) {
                parent::__construct($config, $managed, $meter, $entitlement);
            }

            public function evaluate(): StorageQuotaStatus
            {
                return $this->evaluateSnapshot($this->snapshot);
            }
        };
    }

    private function action(Config $config, StorageUsageSnapshot $snapshot): LicenseStatusAction
    {
        $db = new Connection($config);

        return new LicenseStatusAction(
            new LicenseService($db, $config, new LicenseTokenVerifier(), new LicenseClient($config)),
            $db,
            new ManagedModeGuard($config),
            $this->policy($config, $snapshot),
            $config,
            new InstanceEntitlement($db, $config),
            new BillingSnapshot($config),
        );
    }

    /**
     * Zavolá action jako superadmin a vrátí dekódovaný payload.
     *
     * @return array<string,mixed>
     */
    private function invoke(Config $config, LicenseState $state, StorageUsageSnapshot $snapshot): array
    {
        $response = $this->action($config, $snapshot)(
            $this->adminRequest($state),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Blok `instance.storage` z odpovědi.
     *
     * @return array<string,mixed>
     */
    /**
     * Srovnání procenta z odpovědi.
     *
     * ⚠️ Nesmí to být `assertSame(90.0, …)`. Odpověď projde `json_encode` a
     * zpátky, a JSON zná jediný číselný typ — celé procento se tedy vrátí jako
     * `int`, desetinné jako `float`. Trvat na `float` by znamenalo testovat
     * vlastnost, kterou formát nemá, ne chování aplikace. Rozdíl mezi
     * „změřeno na nulu" a „neměřeno" drží `assertNotNull` níž, ne typ.
     */
    private function assertPercent(float $expected, mixed $actual): void
    {
        self::assertNotNull($actual, 'procento chybí — nezměřeno se pozná podle null, ne podle nuly');
        self::assertIsNumeric($actual);
        self::assertEqualsWithDelta($expected, (float) $actual, 0.001);
    }

    private function storage(Config $config, StorageUsageSnapshot $snapshot): array
    {
        $payload = $this->invoke($config, $this->state(), $snapshot);

        self::assertArrayHasKey('instance', $payload, 'Spravovaná instalace blok instance mít musí.');

        return $payload['instance']['storage'];
    }

    private function adminRequest(LicenseState $state): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/license/status')
            ->withAttribute(LicenseMiddleware::ATTR_STATE, $state)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'admin', 'email' => 'admin@example.test']);
    }
}
