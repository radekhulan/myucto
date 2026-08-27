<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přidělený `instance_id` se musí zapsat i do PRÁZDNÉ tabulky `license`.
 *
 * ⚠️ Regrese s drahým dopadem. Zápis byl `UPDATE … WHERE id = 1`, jenže řádek
 * nemusí existovat: aplikace si ho zakládá LÍNĚ až při prvním čtení licence.
 * Na instalaci, kde se do setupu nikdo licence nezeptal, tedy UPDATE
 * neaktualizoval nic — a mlčky. Instance si při prvním čtení vyrobila vlastní
 * UUID, aktivace s ním odešla a licenční server ji odmítl
 * `instance_not_managed`: zaplacená instalace zůstala na zkušebním období.
 *
 * Test nejde přes celý setup (ten potřebuje prázdnou instalaci) — ověřuje
 * přesně ten dotaz, kterým se identita zapisuje.
 */
#[Group('integration')]
final class SetupAssignedInstanceIdTest extends TestCase
{
    private Connection $db;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasTable('license')) {
            $this->markTestSkipped('Tabulka license neexistuje.');
        }
        $this->db->pdo()->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /** Týž dotaz, jakým identitu zapisuje SetupAction. */
    private function assign(string $instanceId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO license (id, instance_id, trial_started_at) VALUES (1, ?, NOW())
             ON DUPLICATE KEY UPDATE instance_id = VALUES(instance_id)'
        )->execute([$instanceId]);
    }

    private function storedInstanceId(): ?string
    {
        $row = $this->db->pdo()->query('SELECT instance_id FROM license WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (string) $row['instance_id'];
    }

    public function testAssignsIntoEmptyTable(): void
    {
        $this->db->pdo()->exec('DELETE FROM license WHERE id = 1');
        self::assertNull($this->storedInstanceId(), 'předpoklad: řádek neexistuje');

        $this->assign('a6a6ff99-d014-4dc2-9ada-7b438550377d');

        self::assertSame('a6a6ff99-d014-4dc2-9ada-7b438550377d', $this->storedInstanceId());
    }

    public function testOverwritesLocallyGeneratedUuid(): void
    {
        // Instalace, která si UUID stihla vygenerovat sama, musí dostat naše.
        $this->db->pdo()->exec('DELETE FROM license WHERE id = 1');
        $this->db->pdo()->exec("INSERT INTO license (id, instance_id, trial_started_at) VALUES (1, UUID(), NOW())");
        $local = $this->storedInstanceId();
        self::assertNotNull($local);

        $this->assign('a6a6ff99-d014-4dc2-9ada-7b438550377d');

        self::assertNotSame($local, $this->storedInstanceId());
        self::assertSame('a6a6ff99-d014-4dc2-9ada-7b438550377d', $this->storedInstanceId());
    }
}
