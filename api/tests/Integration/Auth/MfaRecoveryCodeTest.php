<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\MfaRecoveryCodeService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Záložní jednorázové kódy (migrace 1176).
 *
 * Vznikly z auditu MFA: aplikace neměla žádnou self-service cestu ven ze ztraceného
 * faktoru. Kdo měl jen passkey a přišel o zařízení, se nepřihlásil — heslo samo
 * nestačí, e-mailové OTP se takovému účtu nenabídne, reset hesla MFA nemaže a admin
 * reset z aplikace neexistuje. Jediným východiskem byl shell na serveru.
 *
 * Testuje se to, na čem záchrana stojí: jednorázovost i v závodě, správné odmítnutí
 * cizího a už použitého kódu, a tolerance k tomu, jak uživatel kód opíše z papíru.
 */
#[Group('integration')]
final class MfaRecoveryCodeTest extends TestCase
{
    private Connection $db;
    private MfaRecoveryCodeService $codes;
    private int $userId = 0;
    private int $otherUserId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->codes = $c->get(MfaRecoveryCodeService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasTable('mfa_recovery_codes')) {
            $this->markTestSkipped('Migrace 1176 neproběhla.');
        }

        $ids = $this->db->pdo()
            ->query('SELECT id FROM users ORDER BY id LIMIT 2')
            ->fetchAll(PDO::FETCH_COLUMN);
        if ($ids === []) {
            $this->markTestSkipped('Chybí uživatel.');
        }
        $this->userId = (int) $ids[0];
        // Druhý uživatel nemusí existovat — testy na cizí kód se pak přeskočí.
        $this->otherUserId = isset($ids[1]) ? (int) $ids[1] : 0;

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
            $this->db->close();
        }
    }

    private function cleanup(): void
    {
        foreach ([$this->userId, $this->otherUserId] as $id) {
            if ($id > 0) {
                $this->db->pdo()->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = ?')->execute([$id]);
            }
        }
    }

    public function testGenerateReturnsFullBatchOfDistinctCodes(): void
    {
        $codes = $this->codes->generate($this->userId);

        self::assertCount(MfaRecoveryCodeService::BATCH_SIZE, $codes);
        self::assertSame(count($codes), count(array_unique($codes)), 'Kódy v sadě se nesmí opakovat.');
        self::assertSame(MfaRecoveryCodeService::BATCH_SIZE, $this->codes->remaining($this->userId));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}$/', $code);
        }
    }

    /** Plaintext nikde neleží — v DB smí být jen hash. */
    public function testPlaintextIsNeverStored(): void
    {
        $codes = $this->codes->generate($this->userId);
        $stored = $this->db->pdo()->prepare('SELECT code_hash FROM mfa_recovery_codes WHERE user_id = ?');
        $stored->execute([$this->userId]);
        $hashes = $stored->fetchAll(PDO::FETCH_COLUMN);

        foreach ($codes as $code) {
            $normalized = MfaRecoveryCodeService::normalize($code);
            self::assertNotContains($code, $hashes);
            self::assertNotContains($normalized, $hashes);
            self::assertContains(hash('sha256', $normalized), $hashes);
        }
    }

    public function testCodeWorksOnceAndThenNeverAgain(): void
    {
        $codes = $this->codes->generate($this->userId);

        self::assertTrue($this->codes->consume($this->userId, $codes[0], '127.0.0.1'));
        self::assertFalse($this->codes->consume($this->userId, $codes[0], '127.0.0.1'), 'Druhé uplatnění musí selhat.');
        self::assertSame(MfaRecoveryCodeService::BATCH_SIZE - 1, $this->codes->remaining($this->userId));
    }

    /**
     * Spotřebovaný kód se NEMAŽE — použití break-glass kódu je bezpečnostní událost
     * a musí zůstat dohledatelná i s IP.
     */
    public function testUsedCodeKeepsAuditTrail(): void
    {
        $codes = $this->codes->generate($this->userId);
        $this->codes->consume($this->userId, $codes[0], '198.51.100.7');

        $row = $this->db->pdo()->prepare(
            'SELECT used_at, INET6_NTOA(used_ip) AS ip FROM mfa_recovery_codes
              WHERE user_id = ? AND used_at IS NOT NULL'
        );
        $row->execute([$this->userId]);
        $used = $row->fetch(PDO::FETCH_ASSOC);

        self::assertNotFalse($used);
        self::assertNotNull($used['used_at']);
        self::assertSame('198.51.100.7', $used['ip']);
    }

    /** Uživatel kód opisuje z papíru — malá písmena, mezery i pomlčka musí projít. */
    public function testInputIsForgivingAboutFormatting(): void
    {
        $codes = $this->codes->generate($this->userId);
        $sloppy = ' ' . strtolower(str_replace('-', ' ', $codes[0])) . ' ';

        self::assertTrue($this->codes->consume($this->userId, $sloppy));
    }

    /** Abeceda nesmí obsahovat znaky, které při opisu splývají. */
    public function testAmbiguousCharactersAreExcluded(): void
    {
        $joined = implode('', $this->codes->generate($this->userId));

        foreach (['I', 'L', 'O', 'U'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $joined);
        }
    }

    public function testRegenerateInvalidatesThePreviousBatch(): void
    {
        $old = $this->codes->generate($this->userId);
        $new = $this->codes->generate($this->userId);

        self::assertSame(MfaRecoveryCodeService::BATCH_SIZE, $this->codes->remaining($this->userId));
        self::assertFalse($this->codes->consume($this->userId, $old[0]), 'Stará sada musí přestat platit.');
        self::assertTrue($this->codes->consume($this->userId, $new[0]));
    }

    public function testCodeOfAnotherUserIsRejected(): void
    {
        if ($this->otherUserId === 0) {
            self::markTestSkipped('Instalace má jen jednoho uživatele.');
        }
        $codes = $this->codes->generate($this->userId);

        self::assertFalse($this->codes->consume($this->otherUserId, $codes[0]));
        self::assertSame(MfaRecoveryCodeService::BATCH_SIZE, $this->codes->remaining($this->userId));
    }

    public function testGarbageInputIsRejectedWithoutTouchingTheBatch(): void
    {
        $this->codes->generate($this->userId);

        foreach (['', '   ', 'ABC', 'IIIII-IIIII', str_repeat('Z', 40)] as $garbage) {
            self::assertFalse($this->codes->consume($this->userId, $garbage), "Vstup '$garbage'");
        }
        self::assertSame(MfaRecoveryCodeService::BATCH_SIZE, $this->codes->remaining($this->userId));
    }

    public function testStatusReportsUsageWithoutLeakingCodes(): void
    {
        $codes = $this->codes->generate($this->userId);
        $this->codes->consume($this->userId, $codes[0]);
        $status = $this->codes->status($this->userId);

        self::assertSame(MfaRecoveryCodeService::BATCH_SIZE, $status['total']);
        self::assertSame(MfaRecoveryCodeService::BATCH_SIZE - 1, $status['remaining']);
        self::assertNotNull($status['generated_at']);
        self::assertFalse($status['low']);
        self::assertSame([], array_intersect($codes, array_map('strval', array_values($status))));
    }

    /** Pod hranicí LOW_WATERMARK má UI tlačit na regeneraci. */
    public function testStatusFlagsLowBatch(): void
    {
        $codes = $this->codes->generate($this->userId);
        $keep = MfaRecoveryCodeService::LOW_WATERMARK;
        for ($i = 0; $i < MfaRecoveryCodeService::BATCH_SIZE - $keep; $i++) {
            $this->codes->consume($this->userId, $codes[$i]);
        }

        $status = $this->codes->status($this->userId);
        self::assertSame($keep, $status['remaining']);
        self::assertTrue($status['low']);

        // Vyčerpaná sada už není „skoro došlá", je pryč — a `low` by uživatele mátlo.
        for ($i = MfaRecoveryCodeService::BATCH_SIZE - $keep; $i < MfaRecoveryCodeService::BATCH_SIZE; $i++) {
            $this->codes->consume($this->userId, $codes[$i]);
        }
        $empty = $this->codes->status($this->userId);
        self::assertSame(0, $empty['remaining']);
        self::assertFalse($empty['low']);
        self::assertFalse($this->codes->hasUsable($this->userId));
    }

    public function testRevokeAllClearsTheBatch(): void
    {
        $codes = $this->codes->generate($this->userId);

        self::assertSame(MfaRecoveryCodeService::BATCH_SIZE, $this->codes->revokeAll($this->userId));
        self::assertSame(0, $this->codes->remaining($this->userId));
        self::assertFalse($this->codes->consume($this->userId, $codes[0]));
    }
    // ── Vydání se zapnutím TOTP ──────────────────────────────────────────
    //
    // ⚠️ Dokud se sada nevydávala rovnou, končilo zapnutí druhého faktoru bez
    // jakéhokoliv break-glass: kdo přišel o autentikátor, se do instalace
    // nedostal vůbec a zbýval zásah na serveru. Nabídnout kódy až v nastavení
    // bezpečnosti nestačí — po prvním přihlášení tam uživatel nedojde.

    public function testFirstBatchIsIssuedWhenUserHasNone(): void
    {
        self::assertFalse($this->codes->hasUsable($this->userId), 'předpoklad: uživatel žádné kódy nemá');

        $issued = $this->issueFirstBatch();

        self::assertCount(MfaRecoveryCodeService::BATCH_SIZE, $issued);
        self::assertTrue($this->codes->hasUsable($this->userId));
    }

    public function testExistingBatchIsNeverSilentlyReplaced(): void
    {
        // Opakované zapnutí TOTP nesmí sebrat kódy, které si uživatel dávno
        // uložil — `generate()` starou sadu zneplatní.
        $original = $this->codes->generate($this->userId);

        $issued = $this->issueFirstBatch();

        self::assertSame([], $issued, 'kdo použitelné kódy má, novou sadu nedostane');
        self::assertTrue(
            $this->codes->consume($this->userId, $original[0]),
            'původní sada musí dál platit'
        );
    }

    /**
     * Volá tutéž privátní cestu jako `TotpAction::enable()` — bez ní by test
     * ověřoval jen službu, ne rozhodnutí „vydat, nebo nechat být".
     *
     * @return list<string>
     */
    private function issueFirstBatch(): array
    {
        $action = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Action\Auth\TotpAction::class);
        $method = new \ReflectionMethod($action, 'issueFirstRecoveryCodes');
        $method->setAccessible(true);
        $result = (array) $method->invoke($action, $this->userId, '127.0.0.1', 'phpunit');

        return $result['recovery_codes'] ?? [];
    }

}
