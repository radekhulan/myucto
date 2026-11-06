<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\BruteForceGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Lockout přihlášení se nesmí dát obejít jiným zápisem téže adresy.
 *
 * `users.email` je v kolaci utf8mb4_unicode_ci: `ucetní@…` i `uce​tni@…`
 * se přihlásí do stejného účtu jako `ucetni@…`. Počítadlo selhání proto musí
 * všechny tyto zápisy vést v jednom bucketu, jinak má každý vlastní příděl.
 */
#[Group('integration')]
final class BruteForceEmailVariantLockoutTest extends TestCase
{
    private const IP = '203.0.113.47';

    private Connection $db;
    private BruteForceGuard $guard;
    private string $token = '';
    private string $email = '';
    private int $userId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $loaded = Config::load($rootDir);
            $data = $loaded->all();
            // Bez Redisu: počítadla jdou do login_attempts, která test umí uklidit.
            $data['redis']['enabled'] = false;
            $config = new Config($data, $loaded->dataDir());
            $this->db = new Connection($config);
            $this->db->pdo()->query('SELECT 1 FROM login_attempts LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB nedostupná: ' . $e->getMessage());
        }
        $this->guard = new BruteForceGuard($config, new RedisFactory($config), $this->db);

        $this->token = bin2hex(random_bytes(5));
        $this->email = 'ucetni' . $this->token . '@firma.example.test';
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role_id, is_active)
             SELECT ?, ?, ?, role_id, 1 FROM users WHERE role_id IS NOT NULL ORDER BY id LIMIT 1'
        )->execute([$this->email, password_hash('Synthetic-Lockout-2026', PASSWORD_BCRYPT), 'Lockout test']);
        $this->userId = (int) $pdo->lastInsertId();
        if ($this->userId <= 0) {
            $this->markTestSkipped('Nepodařilo se založit dočasného uživatele.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->token === '') {
            return;
        }
        $this->db->pdo()->prepare('DELETE FROM login_attempts WHERE email LIKE ?')->execute(['%' . $this->token . '%']);
        if ($this->userId > 0) {
            $this->db->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
    }

    public function testAccentedZeroWidthAndUpperCaseSpellingsShareTheLockedBucket(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->guard->recordFailure($this->email, self::IP);
        }

        $variants = [
            'canonical' => $this->email,
            'upper' => strtoupper($this->email),
            'i acute' => str_replace('ucetni', "ucetn\u{00ED}", $this->email),
            'zero-width space' => str_replace('ucetni', "uce\u{200B}tni", $this->email),
            'combining acute' => str_replace('ucetni', "ucetni\u{0301}", $this->email),
        ];
        foreach ($variants as $label => $variant) {
            // Předpoklad testu: DB tyto zápisy opravdu považuje za tentýž účet.
            $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$variant]);
            self::assertSame($this->userId, (int) $stmt->fetchColumn(), "DB nenašla účet pro zápis: $label");

            self::assertSame(
                BruteForceGuard::STATE_LOCKED_15M,
                $this->guard->check($variant, self::IP),
                "Zápis '$label' obešel lockout.",
            );
        }
    }

    public function testFailuresUnderVariantsCountTowardsTheSameAccount(): void
    {
        $accented = str_replace('ucetni', "ucetn\u{00ED}", $this->email);
        $zeroWidth = str_replace('ucetni', "uce\u{200B}tni", $this->email);
        for ($i = 0; $i < 5; $i++) {
            $this->guard->recordFailure($accented, self::IP);
            $this->guard->recordFailure($zeroWidth, self::IP);
        }

        self::assertSame(BruteForceGuard::STATE_LOCKED_15M, $this->guard->check($this->email, self::IP));
    }

    public function testOtherNetworkAndUnknownAddressVariantsBehave(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->guard->recordFailure($this->email, self::IP);
        }
        // Jiná /24 má vlastní bucket (návrh per-síť zůstává).
        self::assertSame(BruteForceGuard::STATE_OK, $this->guard->check($this->email, '198.51.100.7'));

        // Neexistující adresa: varianty se sjednotí Unicode složením.
        $unknown = 'nikdo' . $this->token . '@example.test';
        for ($i = 0; $i < 10; $i++) {
            $this->guard->recordFailure($unknown, self::IP);
        }
        self::assertSame(
            BruteForceGuard::STATE_LOCKED_15M,
            $this->guard->check(str_replace('nikdo', "n\u{00ED}kdo", $unknown), self::IP),
        );
    }

    public function testFoldEmailMatchesCollationEquivalents(): void
    {
        self::assertSame('ucetni@firma.cz', BruteForceGuard::foldEmail('UCETNÍ@Firma.cz'));
        self::assertSame('ucetni@firma.cz', BruteForceGuard::foldEmail("uce\u{200B}tni@firma.cz"));
        self::assertSame('ucetni@firma.cz', BruteForceGuard::foldEmail("u\u{00AD}cetni@firma.cz"));
        self::assertSame('ucetni@firma.cz', BruteForceGuard::foldEmail("ucetni\u{FE0F}@firma.cz"));
        // Nevalidní UTF-8 nespadne, jen se sníží na ASCII lowercase.
        self::assertSame("a\xFF@b.cz", BruteForceGuard::foldEmail("A\xFF@B.cz"));
    }
}
