<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Instalace hlásí licenčnímu serveru doménu, na které běží.
 *
 * Provozovatel u licence potřebuje vidět, KDE se používá — kvůli podpoře a kvůli
 * podezření na klon. Dřív měl jen `instance_id` (náhodné UUID) a IP aktivace,
 * z čehož se doména nedá zjistit.
 *
 * ⚠️ Doména je SAMOSTATNÉ pole, ne součást telemetrie. {@see \MyInvoice\Service\System\TelemetryPayloadBuilder}
 * má uzavřený whitelist, který doménu výslovně zakazuje („nic identifikujícího"),
 * a to pravidlo zůstává v platnosti. Tohle je vědomá výjimka nad rámec telemetrie
 * a má být v kódu vidět jako výjimka, ne schovaná mezi provozními čísly.
 *
 * Zdrojem je `app.url` z konfigurace, ne hlavička `Host`: obnova běží z cronu,
 * kde žádný požadavek není, a v požadavku by to beztak bylo to, co poslal klient.
 */
#[Group('integration')]
final class LicenseDomainReportedTest extends TestCase
{
    use LicenseTokenTrait;

    private Connection $db;
    private LicenseClient $client;
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

        $pdo = $this->db->pdo();
        $pdo->exec('INSERT IGNORE INTO license (id, instance_id, trial_started_at) VALUES (1, UUID(), NOW())');
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

    public function testRenewReportsTheDomainFromAppUrl(): void
    {
        $captured = 'nezavoláno';
        $this->client->expects($this->once())->method('renew')
            ->willReturnCallback(function (...$args) use (&$captured) {
                $captured = $args[8] ?? null;

                return ['ok' => true, 'token' => $this->token(['nonce' => 'nonce-2'])];
            });

        $this->service('https://ucto.klient.cz')->renewIfDue();

        self::assertSame('ucto.klient.cz', $captured);
        self::assertSame(1, (int) $this->row()['last_check_ok']);
    }

    /** Posílá se holý hostname — port, cesta ani schéma na licenčním serveru k ničemu nejsou. */
    public function testPortAndPathAreStrippedAndHostLowercased(): void
    {
        $captured = 'nezavoláno';
        $this->client->expects($this->once())->method('renew')
            ->willReturnCallback(function (...$args) use (&$captured) {
                $captured = $args[8] ?? null;

                return ['ok' => true, 'token' => $this->token(['nonce' => 'nonce-2'])];
            });

        $this->service('https://UCTO.Klient.cz:8443/app/')->renewIfDue();

        self::assertSame('ucto.klient.cz', $captured);
    }

    /**
     * Nevyplněná `app.url` nesmí obnovu shodit ani poslat prázdný řetězec —
     * pole se prostě nepřiloží a server si ponechá, co ví (COALESCE).
     */
    public function testMissingAppUrlSendsEmptyDomain(): void
    {
        $captured = 'nezavoláno';
        $this->client->expects($this->once())->method('renew')
            ->willReturnCallback(function (...$args) use (&$captured) {
                $captured = $args[8] ?? null;

                return ['ok' => true, 'token' => $this->token(['nonce' => 'nonce-2'])];
            });

        $this->service('')->renewIfDue();

        self::assertSame('', $captured);
        self::assertSame(1, (int) $this->row()['last_check_ok'], 'Bez app.url se licence obnoví stejně.');
    }

    /** Aktivace hlásí doménu taky — jinak by ji server znal až po první obnově. */
    public function testActivationReportsTheDomainToo(): void
    {
        $captured = 'nezavoláno';
        $this->client->expects($this->once())->method('activate')
            ->willReturnCallback(function (...$args) use (&$captured) {
                $captured = $args[7] ?? null;

                return ['ok' => true, 'token' => $this->token()];
            });

        $this->service('https://ucto.klient.cz')->activate('MYU-TEST-0001-AAAA');

        self::assertSame('ucto.klient.cz', $captured);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function service(string $appUrl): LicenseService
    {
        $this->prime();

        return new LicenseService(
            $this->db,
            new Config([
                'app'     => ['url' => $appUrl],
                'license' => ['public_key' => $this->licensePublicKeyBase64()],
            ]),
            new LicenseTokenVerifier(),
            $this->client,
        );
    }

    /** Licencovaný řádek s platným tokenem a obnovou „na spadnutí". */
    private function prime(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE license
                SET license_key = ?, token = ?, token_payload = ?, last_nonce = ?,
                    counter = 0, last_check_at = NULL, last_check_ok = 1
              WHERE id = 1'
        )->execute([
            'MYU-TEST-0001-AAAA',
            $this->token(),
            json_encode(['nonce' => 'nonce-1'], JSON_UNESCAPED_UNICODE),
            'nonce-1',
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
