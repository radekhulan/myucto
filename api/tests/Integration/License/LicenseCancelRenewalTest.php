<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseNetworkException;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Samoobslužné vypnutí automatického prodlužování z aplikace
 * (`LicenseService::cancelRenewal()` → POST /api/license/cancel-renewal).
 *
 * Podstata testů: zrušení obnovy NENÍ deaktivace — klíč, token, `valid_until`
 * ani stav licence se nemění, licence doběhne do konce zaplaceného období.
 * Dál se hlídá idempotence a to, že se stav předplatného ze serveru opravdu
 * uloží (jinak by admin po kliknutí neviděl žádnou změnu).
 */
#[Group('integration')]
final class LicenseCancelRenewalTest extends TestCase
{
    use LicenseTokenTrait;

    private Connection $db;
    private LicenseClient $client;
    private LicenseService $service;
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
        if (!$this->db->hasColumn('license', 'subscription_info')) {
            $this->markTestSkipped('Migrace 1321 (license.subscription_info) neproběhla.');
        }

        $this->client = $this->createMock(LicenseClient::class);
        $this->service = new LicenseService(
            $this->db,
            new Config(['license' => ['public_key' => $this->licensePublicKeyBase64()]]),
            new \MyInvoice\Service\License\LicenseTokenVerifier(),
            $this->client,
        );

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

    public function testCancelRenewalKeepsLicenseRunningUntilPaidPeriodEnds(): void
    {
        $validUntil = time() + 20 * 86400;
        $this->prime($this->activeSubscription(), $validUntil);
        $before = $this->row();

        $this->client->expects($this->once())->method('cancelRenewal')
            ->willReturn([
                'ok'                => true,
                'already_cancelled' => false,
                'valid_until'       => $validUntil,
                'subscription'      => $this->cancelledSubscription($validUntil),
            ]);

        $result = $this->service->cancelRenewal();

        self::assertTrue($result['ok']);
        self::assertFalse($result['already_cancelled']);
        self::assertSame($validUntil, $result['valid_until']);

        // Licence běží dál: stav, klíč, token ani platnost se nemění.
        $state = $result['state'];
        self::assertSame(LicenseState::ACTIVE, $state->state);
        self::assertSame($validUntil, $state->validUntil);
        self::assertTrue($state->hasCommercialFeatures());

        $after = $this->row();
        self::assertSame($before['license_key'], $after['license_key'], 'zrušení obnovy nesmí smazat klíč (to je deaktivace)');
        self::assertSame($before['token'], $after['token'], 'token se nemění — období běží dál');
        self::assertSame($before['counter'], $after['counter']);

        // Nový stav předplatného je uložený → UI ho hned ukáže.
        self::assertFalse($state->autoRenews());
        self::assertSame('cancelled', $state->subscription['state']);
        self::assertNotNull($state->subscription['cancelled_at']);
        self::assertNull($state->subscription['next_charge_at']);
        self::assertFalse($state->toArray('https://example.test/objednavka')['subscription']['auto_renew']);
    }

    public function testCancelRenewalIsIdempotent(): void
    {
        $validUntil = time() + 20 * 86400;
        $this->prime($this->cancelledSubscription($validUntil), $validUntil);

        $this->client->expects($this->once())->method('cancelRenewal')
            ->willReturn([
                'ok'                => true,
                'already_cancelled' => true,
                'valid_until'       => $validUntil,
                'subscription'      => $this->cancelledSubscription($validUntil),
            ]);

        $result = $this->service->cancelRenewal();

        self::assertTrue($result['ok'], 'opakované zrušení musí být úspěch, ne chyba');
        self::assertTrue($result['already_cancelled']);
        self::assertSame(LicenseState::ACTIVE, $result['state']->state);
        self::assertFalse($result['state']->autoRenews());
    }

    public function testCancelRenewalWithoutLicenseKeyDoesNotCallServer(): void
    {
        $this->db->pdo()->exec(
            "UPDATE license SET license_key = NULL, token = NULL, token_payload = NULL,
                    subscription_info = NULL, trial_started_at = NOW() WHERE id = 1"
        );
        $this->client->expects($this->never())->method('cancelRenewal');

        $result = $this->service->cancelRenewal();

        self::assertFalse($result['ok']);
        self::assertSame('invalid_key', $result['error']);
    }

    public function testCancelRenewalNetworkErrorIsReportedAndChangesNothing(): void
    {
        $validUntil = time() + 20 * 86400;
        $this->prime($this->activeSubscription(), $validUntil);

        $this->client->expects($this->once())->method('cancelRenewal')
            ->willThrowException(new LicenseNetworkException('server unreachable'));

        $result = $this->service->cancelRenewal();

        self::assertFalse($result['ok']);
        self::assertSame('server_unreachable', $result['error']);
        self::assertTrue($this->service->current()->autoRenews(), 'neúspěšné volání nesmí předstírat zrušení');
    }

    public function testRenewStoresSubscriptionStateFromServer(): void
    {
        $this->prime(null, time() + 20 * 86400, null);
        $sub = $this->activeSubscription();
        $this->client->expects($this->once())->method('renew')->willReturn([
            'ok'           => true,
            'token'        => $this->token(['nonce' => 'nonce-2']),
            'subscription' => $sub,
        ]);

        $this->service->renewIfDue();

        $state = $this->service->current();
        self::assertTrue($state->autoRenews());
        self::assertSame($sub['next_charge_at'], $state->subscription['next_charge_at']);
        self::assertSame('active', $state->subscription['state']);
    }

    public function testRejectedRenewKeepsLastKnownSubscriptionState(): void
    {
        $sub = $this->activeSubscription();
        $this->prime($sub, time() + 20 * 86400, null);
        // Starší server / odmítnutá obnova pole `subscription` vůbec nepošle.
        $this->client->expects($this->once())->method('renew')
            ->willReturn(['ok' => false, 'error' => 'clone_suspected']);

        $this->service->renewIfDue();

        self::assertSame(
            $sub,
            $this->service->current()->subscription,
            'odpověď bez pole `subscription` nesmí přepsat poslední známý stav',
        );
    }

    public function testActivateReplacesSubscriptionStateOfPreviousKey(): void
    {
        // Na instalaci zbyl stav předplatného po předchozím klíči.
        $this->prime($this->activeSubscription(), time() + 20 * 86400);
        self::assertTrue(
            $this->service->current()->autoRenews(),
            'předpoklad testu: stav předplatného předchozího klíče je vidět',
        );
        $this->client->expects($this->once())->method('activate')
            ->willReturn(['ok' => true, 'token' => $this->token(['valid_until' => time() + 30 * 86400])]);

        $result = $this->service->activate('MYU-TEST-0002-BBBB');

        self::assertTrue($result['ok']);
        self::assertNull(
            $result['state']->subscription,
            'aktivace jiného klíče nesmí zdědit stav předplatného toho předchozího',
        );
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function activeSubscription(): array
    {
        return [
            'state'          => 'active',
            'period'         => 'month',
            'auto_renew'     => true,
            'next_charge_at' => time() + 20 * 86400,
            'cancelled_at'   => null,
            'valid_until'    => time() + 20 * 86400,
        ];
    }

    /** @return array<string,mixed> */
    private function cancelledSubscription(int $validUntil): array
    {
        return [
            'state'          => 'cancelled',
            'period'         => 'month',
            'auto_renew'     => false,
            'next_charge_at' => null,
            'cancelled_at'   => time(),
            'valid_until'    => $validUntil,
        ];
    }

    // ── obnova zrušeného předplatného ────────────────────────────────────
    //
    // ⚠️ Obnova NENÍ přepnutí příznaku zpátky: zrušení zneplatnilo mandát
    // u brány, takže bez nové karty by se příští stržení jen zase nepovedlo.
    // Server proto vrací adresu k platbě a instalace na ni pošle zákazníka.

    public function testResumeRenewalReturnsPaymentUrl(): void
    {
        $validUntil = time() + 20 * 86400;
        $this->prime($this->cancelledSubscription($validUntil), $validUntil);

        $this->client->expects($this->once())->method('resumeRenewal')
            ->willReturn(['ok' => true, 'pay_url' => 'https://myucto.cz/gw/abc', 'valid_until' => $validUntil]);

        $result = $this->service->resumeRenewal();

        self::assertTrue($result['ok']);
        self::assertSame('https://myucto.cz/gw/abc', $result['pay_url']);
    }

    public function testResumeRenewalKeepsSubscriptionCancelledUntilItIsPaid(): void
    {
        // Kdyby se stav přepsal hned, stačilo by zavřít bránu a instalace by
        // tvrdila „prodlužuje se" nad předplatným, které nikdo nestrhne.
        $validUntil = time() + 20 * 86400;
        $this->prime($this->cancelledSubscription($validUntil), $validUntil);

        $this->client->expects($this->once())->method('resumeRenewal')
            ->willReturn(['ok' => true, 'pay_url' => 'https://myucto.cz/gw/abc', 'valid_until' => $validUntil]);

        $this->service->resumeRenewal();

        $state = $this->service->current();
        self::assertSame('cancelled', $state->subscription['state']);
        self::assertFalse($state->autoRenews());
    }

    public function testResumeRenewalWithoutLicenseKeyDoesNotCallServer(): void
    {
        $this->db->pdo()->exec('UPDATE license SET license_key = NULL WHERE id = 1');
        $this->client->expects($this->never())->method('resumeRenewal');

        $result = $this->service->resumeRenewal();

        self::assertFalse($result['ok']);
        self::assertSame('invalid_key', $result['error']);
    }

    public function testResumeRenewalPassesServerRefusalThrough(): void
    {
        // ⚠️ Vypnutou instanci samoobsluha zpátky nezapne — a obrazovka to musí
        // říct, ne nabízet „zkuste to znovu".
        $validUntil = time() + 20 * 86400;
        $this->prime($this->cancelledSubscription($validUntil), $validUntil);
        $this->client->expects($this->once())->method('resumeRenewal')
            ->willReturn(['ok' => false, 'error' => 'instance_not_restorable']);

        $result = $this->service->resumeRenewal();

        self::assertFalse($result['ok']);
        self::assertSame('instance_not_restorable', $result['error']);
    }

    /**
     * Licencovaný řádek: klíč, platný token a (volitelně) známý stav předplatného.
     *
     * @param array<string,mixed>|null $subscription
     */
    private function prime(?array $subscription, int $validUntil, ?string $lastCheckAt = 'now'): void
    {
        $this->db->pdo()->exec(
            'INSERT IGNORE INTO license (id, instance_id, trial_started_at) VALUES (1, UUID(), NOW())'
        );
        $this->db->pdo()->prepare(
            'UPDATE license
                SET license_key = ?, token = ?, token_payload = ?, last_nonce = ?,
                    subscription_info = ?, counter = 0, last_check_at = ?, last_check_ok = 1
              WHERE id = 1'
        )->execute([
            'MYU-TEST-0001-AAAA',
            $this->token(['valid_until' => $validUntil]),
            json_encode(['nonce' => 'nonce-1'], JSON_UNESCAPED_UNICODE),
            'nonce-1',
            $subscription === null ? null : json_encode($subscription, JSON_UNESCAPED_UNICODE),
            $lastCheckAt === 'now' ? date('Y-m-d H:i:s') : $lastCheckAt,
        ]);
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return (array) $this->db->pdo()->query('SELECT * FROM license WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
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
