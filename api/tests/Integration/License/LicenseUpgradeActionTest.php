<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Action\License\StorageQuoteAction;
use MyInvoice\Action\License\StorageUpgradeAction;
use MyInvoice\Action\License\TierChangeAction;
use MyInvoice\Action\License\TierQuoteAction;
use MyInvoice\Action\License\UpgradeLicenseAction;
use MyInvoice\Action\License\UpgradeQuoteLicenseAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseNetworkException;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * In-place navýšení počtu uživatelů (E4) — UpgradeQuoteLicenseAction /
 * UpgradeLicenseAction proti mockovanému licenčnímu serveru. Ověřuje kalkulaci
 * doplatku, provedení navýšení (vč. vynucené obnovy tokenu s vyšším limitem)
 * a chybové stavy (no_parent_payment, charge_failed, síťová chyba, chybějící klíč).
 */
#[Group('integration')]
final class LicenseUpgradeActionTest extends TestCase
{
    use LicenseTokenTrait;

    private Connection $db;
    private LicenseClient&MockObject $client;
    private LicenseService $service;
    private UpgradeQuoteLicenseAction $quote;
    private UpgradeLicenseAction $upgrade;
    private TierQuoteAction $tierQuote;
    private TierChangeAction $tierChange;
    private StorageQuoteAction $storageQuote;
    private StorageUpgradeAction $storageUpgrade;
    private string $instanceId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->ping() || !$this->db->hasTable('license')) {
            $this->markTestSkipped('Migrace 1139 (license) neproběhla / DB nedostupná.');
        }

        $config = new Config([
            'app' => ['managed' => false],
            'license' => ['public_key' => $this->licensePublicKeyBase64()],
        ]);
        $this->client  = $this->createMock(LicenseClient::class);
        $this->service = new LicenseService(
            $this->db,
            $config,
            new LicenseTokenVerifier(),
            $this->client,
        );
        $this->quote   = new UpgradeQuoteLicenseAction($this->service);
        $this->upgrade = new UpgradeLicenseAction($this->service);
        $this->tierQuote = new TierQuoteAction($this->service);
        $this->tierChange = new TierChangeAction($this->service);
        $managed = new ManagedModeGuard($config);
        $this->storageQuote = new StorageQuoteAction($this->service, $managed);
        $this->storageUpgrade = new StorageUpgradeAction($this->service, $managed);

        $pdo = $this->db->pdo();
        $this->instanceId = (string) $pdo->query('SELECT instance_id FROM license WHERE id = 1')->fetchColumn();
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── kalkulace doplatku ─────────────────────────────────────────────────────

    public function testQuoteHappyPathReturnsCalculation(): void
    {
        $this->seedActivated();
        $periodEnd = time() + 86400 * 20;
        $this->client->expects($this->once())
            ->method('upgradeQuote')
            ->with($this->anything(), $this->logicalNot($this->equalTo('')), 5)
            ->willReturn([
                'ok' => true, 'current_users' => 3, 'new_users' => 5,
                'amount' => 250, 'currency' => 'CZK', 'period_end' => $periodEnd,
                'quote_token' => 'quote-1', 'expires_at' => time() + 600,
            ]);

        $resp = $this->quote->__invoke($this->adminRequest(['users' => 5]), new Psr7Response());

        self::assertSame(200, $resp->getStatusCode());
        $body = $this->body($resp);
        self::assertSame(3, $body['current_users']);
        self::assertSame(5, $body['new_users']);
        self::assertSame(250, $body['amount']);
        self::assertSame('CZK', $body['currency']);
        self::assertSame('quote-1', $body['quote_token']);
    }

    public function testQuoteNoParentPaymentReturns422(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('upgradeQuote')
            ->willReturn(['ok' => false, 'error' => 'no_parent_payment']);

        $resp = $this->quote->__invoke($this->adminRequest(['users' => 5]), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('no_parent_payment', $this->body($resp)['error']['code']);
    }

    public function testQuoteWithoutLicenseKeyReturns422(): void
    {
        // Bez aktivního klíče se klient vůbec nevolá.
        $this->client->expects($this->never())->method('upgradeQuote');

        $resp = $this->quote->__invoke($this->adminRequest(['users' => 5]), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('invalid_key', $this->body($resp)['error']['code']);
    }

    public function testQuoteInvalidUsersReturns400(): void
    {
        $this->client->expects($this->never())->method('upgradeQuote');

        $resp = $this->quote->__invoke($this->adminRequest(['users' => 0]), new Psr7Response());

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('validation_failed', $this->body($resp)['error']['code']);
    }

    // ── provedení navýšení ─────────────────────────────────────────────────────

    public function testUpgradeHappyPathChargesAndRefreshesToken(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('upgrade')
            ->with($this->anything(), $this->logicalNot($this->equalTo('')), 5, 'quote-1')
            ->willReturn(['ok' => true, 'new_users' => 5, 'amount_charged' => 250]);
        // Po úspěchu se vynutí obnova tokenu → přijde nový token s vyšším limitem.
        $this->client->expects($this->once())
            ->method('renew')
            ->willReturn(['ok' => true, 'token' => $this->token(['users' => 5])]);

        $resp = $this->upgrade->__invoke($this->adminRequest(['users' => 5, 'quote_token' => 'quote-1']), new Psr7Response());

        self::assertSame(200, $resp->getStatusCode());
        $body = $this->body($resp);
        self::assertSame(5, $body['new_users']);
        self::assertSame(250, $body['amount_charged']);
        self::assertSame('active', $body['state']['state']);
        self::assertSame(5, $body['state']['users_licensed']);
    }

    public function testUpgradeChargeFailedReturns422(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('upgrade')
            ->willReturn(['ok' => false, 'error' => 'charge_failed']);
        $this->client->expects($this->never())->method('renew');

        $resp = $this->upgrade->__invoke($this->adminRequest(['users' => 5, 'quote_token' => 'quote-1']), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('charge_failed', $this->body($resp)['error']['code']);
    }

    public function testPendingChargeReturnsOrderWithoutPrematureRenew(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('upgrade')
            ->with($this->anything(), $this->anything(), 5, 'quote-1')
            ->willReturn(['ok' => false, 'error' => 'charge_pending', 'order_id' => '42']);
        $this->client->expects($this->never())->method('renew');

        $resp = $this->upgrade->__invoke(
            $this->adminRequest(['users' => 5, 'quote_token' => 'quote-1']),
            new Psr7Response(),
        );

        self::assertSame(200, $resp->getStatusCode());
        self::assertTrue($this->body($resp)['pending']);
        self::assertSame('42', $this->body($resp)['order_id']);
    }

    public function testTierPendingChargeIsAcceptedAndDoesNotRenewPrematurely(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('tierChange')
            ->with($this->anything(), $this->anything(), 'multi10', 'tier-quote')
            ->willReturn(['ok' => false, 'error' => 'charge_pending', 'order_id' => '77']);
        $this->client->expects($this->never())->method('renew');

        $result = $this->service->changeTier('multi10', 'tier-quote');

        self::assertTrue($result['ok']);
        self::assertTrue($result['pending']);
        self::assertSame('77', $result['order_id']);
    }

    public function testSelfHostedCanChangeTierAndCompanyCapacityThroughApi(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('tierQuote')
            ->with($this->anything(), $this->instanceId, 'multi10')
            ->willReturn([
                'ok' => true,
                'current_tier' => 'single',
                'new_tier' => 'multi10',
                'amount' => 500,
                'currency' => 'CZK',
                'quote_token' => 'tier-quote',
            ]);
        $this->client->expects($this->once())
            ->method('tierChange')
            ->with($this->anything(), $this->instanceId, 'multi10', 'tier-quote')
            ->willReturn(['ok' => true, 'new_tier' => 'multi10', 'amount_charged' => 500]);
        $this->client->expects($this->once())
            ->method('renew')
            ->willReturn(['ok' => true, 'token' => $this->token([
                'tier' => 'multi10',
                'max_companies' => 10,
            ])]);

        $quote = $this->tierQuote->__invoke(
            $this->adminRequest(['tier' => 'multi10']),
            new Psr7Response(),
        );
        self::assertSame(200, $quote->getStatusCode());
        self::assertSame('tier-quote', $this->body($quote)['quote_token']);

        $change = $this->tierChange->__invoke(
            $this->adminRequest(['tier' => 'multi10', 'quote_token' => 'tier-quote']),
            new Psr7Response(),
        );
        self::assertSame(200, $change->getStatusCode());
        self::assertSame('multi10', $this->body($change)['state']['tier']);
        self::assertSame(10, $this->body($change)['state']['max_companies']);
    }

    public function testSelfHostedStoragePurchaseEndpointsAreRejectedBeforeServerCall(): void
    {
        $this->seedActivated();
        $this->client->expects($this->never())->method('storageQuote');
        $this->client->expects($this->never())->method('storageUpgrade');

        $quote = $this->storageQuote->__invoke(
            $this->adminRequest(['quota_gb' => 7]),
            new Psr7Response(),
        );
        $upgrade = $this->storageUpgrade->__invoke(
            $this->adminRequest(['quota_gb' => 7, 'quote_token' => 'storage-quote']),
            new Psr7Response(),
        );

        self::assertSame(409, $quote->getStatusCode());
        self::assertSame('not_managed', $this->body($quote)['error']['code']);
        self::assertSame(409, $upgrade->getStatusCode());
        self::assertSame('not_managed', $this->body($upgrade)['error']['code']);
    }

    public function testTierDecreaseScheduledForNextPeriodWithoutRenewOrRefund(): void
    {
        $this->seedActivated();
        $effectiveAt = time() + 86400 * 20;
        $this->client->expects($this->once())
            ->method('tierChange')
            ->willReturn([
                'ok' => true,
                'change' => 'scheduled',
                'new_tier' => 'single',
                'effective_at' => $effectiveAt,
                'amount_charged' => 0,
            ]);
        $this->client->expects($this->never())->method('renew');

        $result = $this->service->changeTier('single', 'tier-quote');

        self::assertTrue($result['ok']);
        self::assertTrue($result['scheduled']);
        self::assertSame($effectiveAt, $result['effective_at']);
        self::assertSame(0, $result['amount_charged']);
    }

    public function testAppliedAsyncChangeForcesImmediateLicenseRenew(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('changeStatus')
            ->with($this->anything(), $this->anything(), '88')
            ->willReturn(['ok' => true, 'order_id' => '88', 'state' => 'paid', 'applied' => true]);
        $this->client->expects($this->once())
            ->method('renew')
            ->willReturn(['ok' => true, 'token' => $this->token(['users' => 6])]);

        $result = $this->service->changeStatus('88');

        self::assertTrue($result['ok']);
        self::assertTrue($result['applied']);
        self::assertSame(6, $result['state_local']->usersLicensed);
    }

    public function testSeatDecreaseScheduledResponseDoesNotRenewCurrentPaidPeriod(): void
    {
        $this->seedActivated();
        $effectiveAt = time() + 86400 * 20;
        $this->client->expects($this->once())
            ->method('upgrade')
            ->with($this->anything(), $this->anything(), 2, 'seat-quote')
            ->willReturn([
                'ok' => true,
                'change' => 'scheduled',
                'new_users' => 2,
                'effective_at' => $effectiveAt,
                'amount_charged' => 0,
            ]);
        $this->client->expects($this->never())->method('renew');

        $result = $this->service->upgrade(2, 'seat-quote');

        self::assertTrue($result['ok']);
        self::assertTrue($result['scheduled']);
        self::assertSame($effectiveAt, $result['effective_at']);
        self::assertSame(0, $result['amount_charged']);
    }

    public function testUpgradeNetworkErrorReturns503(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('upgrade')
            ->willThrowException(new LicenseNetworkException('down'));

        $resp = $this->upgrade->__invoke($this->adminRequest(['users' => 5, 'quote_token' => 'quote-1']), new Psr7Response());

        self::assertSame(503, $resp->getStatusCode());
        self::assertSame('server_unreachable', $this->body($resp)['error']['code']);
    }

    public function testUpgradeWithoutLicenseKeyReturns422(): void
    {
        $this->client->expects($this->never())->method('upgrade');

        $resp = $this->upgrade->__invoke($this->adminRequest(['users' => 5, 'quote_token' => 'quote-1']), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('invalid_key', $this->body($resp)['error']['code']);
    }

    public function testUpgradeForbiddenForNonSuperadmin(): void
    {
        $this->client->expects($this->never())->method('upgrade');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/license/upgrade')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 2, 'role' => 'accountant'])
            ->withParsedBody(['users' => 5, 'quote_token' => 'quote-1']);

        $resp = $this->upgrade->__invoke($request, new Psr7Response());

        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('forbidden', $this->body($resp)['error']['code']);
    }

    public function testUpgradeRequiresBindingQuoteToken(): void
    {
        $this->seedActivated();
        $this->client->expects($this->never())->method('upgrade');

        $resp = $this->upgrade->__invoke($this->adminRequest(['users' => 5]), new Psr7Response());

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('quote_required', $this->body($resp)['error']['code']);
    }

    // ── odmítnutá karta ──────────────────────────────────────────────────────
    //
    // ⚠️ Když uložená karta doplatek neuhradí, licenční server objednávku
    // nezahodí — založí platbu a pošle `pay_url`. Aplikace ten odkaz MUSÍ
    // propustit až do odpovědi. Dokud ho zahazovala, zákazník viděl jenom
    // „zkuste to prosím znovu" a se stejnou kartou mačkal totéž dokola;
    // o doplatku se dozvěděl leda z e-mailu.

    public function testChargeFailedForwardsPayUrlSoAppCanOfferAnotherCard(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('upgrade')
            ->willReturn(['ok' => false, 'error' => 'charge_failed', 'pay_url' => 'https://myucto.cz/platba?t=abc']);

        $resp = $this->upgrade->__invoke($this->adminRequest(['users' => 5, 'quote_token' => 'quote-1']), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('https://myucto.cz/platba?t=abc', $this->body($resp)['error']['pay_url']);
    }

    public function testChargeFailedWithoutPayUrlDoesNotInventOne(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('upgrade')
            ->willReturn(['ok' => false, 'error' => 'charge_failed']);

        $resp = $this->upgrade->__invoke($this->adminRequest(['users' => 5, 'quote_token' => 'quote-1']), new Psr7Response());

        self::assertArrayNotHasKey('pay_url', $this->body($resp)['error']);
    }

    public function testStorageChargeFailedForwardsPayUrl(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('storageUpgrade')
            ->willReturn(['ok' => false, 'error' => 'charge_failed', 'pay_url' => 'https://myucto.cz/platba?t=sto']);

        $resp = $this->managedStorageUpgrade()->__invoke(
            $this->adminRequest(['quota_gb' => 22, 'quote_token' => 'storage-quote']),
            new Psr7Response(),
        );

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('https://myucto.cz/platba?t=sto', $this->body($resp)['error']['pay_url']);
    }

    public function testTierChangeChargeFailedForwardsPayUrl(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('tierChange')
            ->willReturn(['ok' => false, 'error' => 'charge_failed', 'pay_url' => 'https://myucto.cz/platba?t=tier']);

        $resp = $this->tierChange->__invoke(
            $this->adminRequest(['tier' => 'multi10', 'quote_token' => 'tier-quote']),
            new Psr7Response(),
        );

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('https://myucto.cz/platba?t=tier', $this->body($resp)['error']['pay_url']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Rozšíření místa se prodává jen u hostovaného provozu — pro ten případ vlastní akce. */
    private function managedStorageUpgrade(): StorageUpgradeAction
    {
        $config = new Config([
            'app' => ['managed' => true],
            'license' => ['public_key' => $this->licensePublicKeyBase64()],
        ]);

        return new StorageUpgradeAction($this->service, new ManagedModeGuard($config));
    }

    private function seedActivated(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE license SET license_key = ?, token = ?, last_check_ok = 1 WHERE id = 1'
        )->execute(['MYU-TEST-0001-AAAA', $this->token()]);
    }

    /** @param array<string,mixed> $body */
    private function adminRequest(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/license/upgrade')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function body(Psr7Response $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    /** @param array<string,mixed> $overrides */
    private function token(array $overrides = []): string
    {
        return $this->signLicenseToken(array_merge([
            'lic'           => 1,
            'iid'           => $this->instanceId,
            'tier'          => 'single',
            'users'         => 3,
            'max_companies' => 5,
            'valid_until'   => time() + 86400,
            'status'        => 'ok',
            'nonce'         => 'nonce-1',
        ], $overrides));
    }
}
