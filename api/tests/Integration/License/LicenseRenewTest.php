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
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * LicenseService::renewIfDue() — denní mutex (atomický UPDATE last_check_at) a
 * tolerance síťové chyby. Ověřuje, že se síť volá právě jednou za den, znovu až
 * další den, a že při výpadku serveru zůstane last_check_ok=0 a stav se řídí
 * platností posledního tokenu.
 */
#[Group('integration')]
final class LicenseRenewTest extends TestCase
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

        $this->client = $this->createMock(LicenseClient::class);
        $this->service = new LicenseService(
            $this->db,
            new Config(['license' => ['public_key' => $this->licensePublicKeyBase64()]]),
            new LicenseTokenVerifier(),
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

    public function testDailyMutexCallsNetworkOnlyOncePerDay(): void
    {
        $this->prime(null); // last_check_at NULL → obnova je „due"
        $newToken = $this->token(['nonce' => 'nonce-2']);
        $this->client->expects($this->once())->method('renew')->willReturn(['ok' => true, 'token' => $newToken]);

        $this->service->renewIfDue(); // síť: 1×, nastaví last_check_at = dnes
        $this->service->renewIfDue(); // týž den → mutex blokuje, žádné síťové volání

        $row = $this->row();
        self::assertSame($newToken, $row['token'], 'Token se má obnovit prvním voláním.');
        self::assertSame(1, (int) $row['last_check_ok']);
    }

    public function testNewDayTriggersRenewAgain(): void
    {
        $this->prime(date('Y-m-d H:i:s', time() - 86400)); // včera → due
        $this->client->expects($this->exactly(2))
            ->method('renew')
            ->willReturn(['ok' => true, 'token' => $this->token(['nonce' => 'nonce-x'])]);

        // Předpoklad testu: řádek nese licenční klíč (ne trial) a poslední kontrola je
        // ve VČEREJŠKU podle hodin databáze. Když neplatí, renewIfDue() se vrátí hned
        // a hláška „renew nebyl zavolán" o příčině nic neřekne.
        $pre = $this->db->pdo()->query(
            'SELECT license_key, last_check_at, DATE(last_check_at) = CURDATE() AS same_day, CURDATE() AS today FROM license WHERE id = 1'
        )->fetch(\PDO::FETCH_ASSOC) ?: [];
        self::assertNotEmpty($pre['license_key'] ?? '', 'prime() musí nastavit licenční klíč: ' . json_encode($pre));
        self::assertSame(0, (int) ($pre['same_day'] ?? 1), 'last_check_at musí být z jiného dne než CURDATE(): ' . json_encode($pre));

        $this->service->renewIfDue(); // 1. den
        // Simuluj další den: poslední kontrola posunutá do včerejška.
        $this->db->pdo()->prepare('UPDATE license SET last_check_at = ? WHERE id = 1')
            ->execute([date('Y-m-d H:i:s', time() - 86400)]);
        $this->service->renewIfDue(); // 2. den → znovu volá
    }

    public function testScheduledRenewStaysDailyOutsideBillingWindow(): void
    {
        $this->prime(date('Y-m-d H:i:s'));
        $this->subscription([
            'state'          => 'active',
            'auto_renew'     => true,
            'next_charge_at' => time() + 10 * 86400,
        ]);
        $this->client->expects($this->never())->method('renew');

        $this->service->renewScheduled();
    }

    public function testScheduledRenewRepeatsHourlyNearNextCharge(): void
    {
        $this->prime(date('Y-m-d H:i:s', time() - 3600));
        $this->subscription([
            'state'          => 'active',
            'auto_renew'     => true,
            'next_charge_at' => time() + 3600,
        ]);
        $this->client->expects($this->once())->method('renew')->willReturn([
            'ok'    => true,
            'token' => $this->token(['nonce' => 'billing-watch']),
        ]);

        $this->service->renewScheduled();
    }

    public function testScheduledRenewDoesNotRepeatInsideHourlyInterval(): void
    {
        $this->prime(date('Y-m-d H:i:s', time() - 600));
        $this->subscription([
            'state'          => 'active',
            'auto_renew'     => true,
            'next_charge_at' => time() + 3600,
        ]);
        $this->client->expects($this->never())->method('renew');

        $this->service->renewScheduled();
    }

    public function testPastDueSubscriptionUsesHourlyScheduledRenew(): void
    {
        $this->prime(date('Y-m-d H:i:s', time() - 3600));
        $this->subscription(['state' => 'past_due']);
        $this->client->expects($this->once())->method('renew')->willReturn([
            'ok'    => true,
            'token' => $this->token(['nonce' => 'past-due-watch']),
        ]);

        $this->service->renewScheduled();
    }

    public function testSuccessfulBillingWatchReturnsToDailyCadence(): void
    {
        $this->prime(date('Y-m-d H:i:s', time() - 3600));
        $this->subscription([
            'state'          => 'active',
            'auto_renew'     => true,
            'next_charge_at' => time() + 3600,
        ]);
        $this->client->expects($this->once())->method('renew')->willReturn([
            'ok'           => true,
            'token'        => $this->token(['nonce' => 'renewed-period']),
            'subscription' => [
                'state'          => 'active',
                'auto_renew'     => true,
                'next_charge_at' => time() + 30 * 86400,
            ],
        ]);

        $this->service->renewScheduled();
        $this->service->renewScheduled();
    }

    public function testNetworkErrorMarksCheckFailedButKeepsValidTokenActive(): void
    {
        $this->prime(null);
        $this->client->expects($this->once())->method('renew')
            ->willThrowException(new LicenseNetworkException('server unreachable'));

        $this->service->renewIfDue();

        self::assertSame(0, (int) $this->row()['last_check_ok'], 'Síťová chyba → last_check_ok = 0.');

        // Token je pořád platný → stav zůstává ACTIVE, jen s příznakem neúspěšné kontroly.
        $state = $this->service->current();
        self::assertSame(LicenseState::ACTIVE, $state->state);
        self::assertFalse($state->lastCheckOk);
    }

    public function testNetworkErrorWithExpiredTokenDegrades(): void
    {
        $expired = $this->token(['valid_until' => time() - 10]);
        $this->prime(null, $expired);
        $this->client->expects($this->once())->method('renew')
            ->willThrowException(new LicenseNetworkException('server unreachable'));

        $this->service->renewIfDue();

        self::assertSame(0, (int) $this->row()['last_check_ok']);
        self::assertSame(LicenseState::DEGRADED, $this->service->current()->state);
    }

    /**
     * Odmítnutá obnova musí uložit stav předplatného, který server posílá s ní.
     *
     * Bez toho drží `subscription_info` poslední ÚSPĚŠNOU obnovu, takže instalace
     * ve fázi `expired` hlásí `phase: active` — tedy „vše v pořádku" zákazníkovi,
     * kterému běží retenční lhůta na smazání dat. Právě tehdy je ten údaj
     * nejcennější a jinou cestou se na instalaci nedostane.
     */
    public function testRejectedRenewStillStoresSubscriptionState(): void
    {
        if (!$this->db->hasColumn('license', 'subscription_info')) {
            $this->markTestSkipped('Migrace se sloupcem subscription_info neproběhla.');
        }
        $this->prime(null);
        $this->db->pdo()->prepare('UPDATE license SET subscription_info = ? WHERE id = 1')
            ->execute([json_encode(['phase' => 'active'], JSON_UNESCAPED_UNICODE)]);

        $this->client->expects($this->once())->method('renew')->willReturn([
            'ok'           => false,
            'error'        => 'subscription_expired',
            'subscription' => [
                'phase'      => 'expired',
                'data_until' => '2026-11-05',
            ],
        ]);

        $this->service->renewIfDue();

        $stored = json_decode((string) $this->row()['subscription_info'], true);
        self::assertIsArray($stored, 'Stav předplatného se uložil.');
        self::assertSame('expired', $stored['phase'], 'Fáze je ta z odmítnuté obnovy, ne stará.');
        self::assertSame('2026-11-05', $stored['data_until'], 'Dokdy držíme data se propsalo.');
        self::assertSame(0, (int) $this->row()['last_check_ok'], 'Odmítnutí zůstává neúspěšnou kontrolou.');
    }

    /**
     * Odmítnutí naopak NESMÍ přepsat rozsah zaplacené služby. `instance` v něm
     * nechodí a prázdná kvóta znamená 100 % plno, tedy okamžitý read-only.
     */
    public function testRejectedRenewDoesNotWipeInstanceEntitlement(): void
    {
        if (!$this->db->hasColumn('license', 'instance_info')) {
            $this->markTestSkipped('Migrace 1524 (instance_info) neproběhla.');
        }
        $this->prime(null);
        $delivered = json_encode(['quota_gb' => 22, 'plan' => 'accounting_10'], JSON_UNESCAPED_UNICODE);
        $this->db->pdo()->prepare('UPDATE license SET instance_info = ? WHERE id = 1')->execute([$delivered]);

        $this->client->expects($this->once())->method('renew')
            ->willReturn(['ok' => false, 'error' => 'subscription_expired']);

        $this->service->renewIfDue();

        $kept = json_decode((string) $this->row()['instance_info'], true);
        self::assertIsArray($kept, 'Poslední známý rozsah zůstal.');
        self::assertSame(22, (int) $kept['quota_gb'], 'Kvóta se odmítnutím nepřepsala.');
    }
    /**
     * Odpověď BEZ klíče `instance` nesmí sáhnout na poslední známý rozsah.
     *
     * Server ho vynechá, když ho neumí zjistit (spadlý dotaz do evidence),
     * a starší server ho neposílá vůbec. Přepsat kvůli tomu kvótu na prázdno
     * by znamenalo nulovou kvótu, tedy sto procent plno a okamžitý režim jen
     * pro čtení u zákazníka, který má zaplaceno.
     */
    public function testRenewWithoutInstanceKeyKeepsTheDeliveredScope(): void
    {
        if (!$this->db->hasColumn('license', 'instance_info')) {
            $this->markTestSkipped('Migrace 1524 (instance_info) neproběhla.');
        }
        $this->prime(null);
        $this->db->pdo()->prepare('UPDATE license SET instance_info = ? WHERE id = 1')
            ->execute([json_encode(['quota_gb' => 22], JSON_UNESCAPED_UNICODE)]);

        $this->client->expects($this->once())->method('renew')->willReturn([
            'ok'    => true,
            'token' => $this->token(),
            // klíč `instance` schválně chybí
        ]);

        $this->service->renewIfDue();

        $kept = json_decode((string) $this->row()['instance_info'], true);
        self::assertSame(22, (int) $kept['quota_gb'], 'Rozsah zůstal.');
    }

    /**
     * Výslovné `instance: null` naopak znamená „tahle instalace není
     * spravovaná" a rozsah se má zahodit.
     */
    public function testExplicitNullInstanceClearsTheDeliveredScope(): void
    {
        if (!$this->db->hasColumn('license', 'instance_info')) {
            $this->markTestSkipped('Migrace 1524 (instance_info) neproběhla.');
        }
        $this->prime(null);
        $this->db->pdo()->prepare('UPDATE license SET instance_info = ? WHERE id = 1')
            ->execute([json_encode(['quota_gb' => 22], JSON_UNESCAPED_UNICODE)]);

        $this->client->expects($this->once())->method('renew')->willReturn([
            'ok'       => true,
            'token'    => $this->token(),
            'instance' => null,
        ]);

        $this->service->renewIfDue();

        self::assertNull($this->row()['instance_info'], 'Rozsah se zahodil.');
    }

    public function testTrialWithoutKeyDoesNotCallNetwork(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE license SET license_key = NULL, token = NULL, token_payload = NULL,
                    last_check_at = NULL, trial_started_at = NOW() WHERE id = 1"
        )->execute();
        $this->client->expects($this->never())->method('renew');

        $this->service->renewIfDue();
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** Nastaví licencovaný řádek (klíč + platný token) s daným last_check_at. */
    private function prime(?string $lastCheckAt, ?string $token = null): void
    {
        $token ??= $this->token();
        $payload = ['nonce' => 'nonce-1'];
        // UPSERT, ne slepý UPDATE: `license` je jednořádková tabulka, kterou si
        // LicenseService::loadRow() umí vyrobit lazily jako TRIAL (bez license_key).
        // Když řádek v okamžiku prime() neexistuje, UPDATE tiše nic neudělá, service
        // si pak vytvoří trial a renewIfDue() skončí hned na `keyOf() === null` —
        // navenek „renew nebyl zavolán ani jednou", aniž by test cokoli naznačil.
        $this->db->pdo()->exec(
            'INSERT IGNORE INTO license (id, instance_id, trial_started_at) VALUES (1, UUID(), NOW())'
        );
        $this->db->pdo()->prepare(
            'UPDATE license
                SET license_key = ?, token = ?, token_payload = ?, last_nonce = ?,
                    counter = 0, last_check_at = ?, last_check_ok = 1,
                    subscription_info = NULL
              WHERE id = 1'
        )->execute([
            'MYU-TEST-0001-AAAA',
            $token,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            'nonce-1',
            $lastCheckAt,
        ]);
    }

    /** @param array<string,mixed> $subscription */
    private function subscription(array $subscription): void
    {
        $this->db->pdo()->prepare('UPDATE license SET subscription_info = ? WHERE id = 1')
            ->execute([json_encode($subscription, JSON_UNESCAPED_UNICODE)]);
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
