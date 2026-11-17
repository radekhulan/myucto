<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Nonprofit\Membership\MembershipErasureAnchor;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use PHPUnit\Framework\TestCase;

/**
 * Pořadí kotev pečetí (#321, krok 10) nad commitnutou a zapečetěnou auditní stopou: platí první kotva
 * v pořadí řetězu; později připsaná zapečetěná ani nezapečetěná kotva se nepoužije. Zapisuje mimo
 * transakci (pečetí se až po commitu) a po sobě uklidí jako ActivityLogHashChainTest.
 */
final class MembershipErasureAnchorTest extends TestCase
{
    private const SUPPLIER = 999_000_001;
    private const ITEM = 999_000_101;

    private Connection $db;
    private ActivityLogger $logger;
    private int $from = 0;
    private ?int $deferredFrom = null;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->logger = $container->get(ActivityLogger::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        if (!$this->db->hasColumn('activity_log_chain_head', 'deferred_from_id')) self::markTestSkipped('Migrace 2055 neproběhla.');
        $this->from = 1 + (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM activity_log')->fetchColumn();
        $deferred = $pdo->query('SELECT deferred_from_id FROM activity_log_chain_head WHERE id = 1')->fetchColumn();
        $this->deferredFrom = $deferred === false || $deferred === null ? null : (int) $deferred;
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare('DELETE FROM activity_log WHERE id >= ?')->execute([$this->from]);
        $pdo->prepare('UPDATE activity_log_chain_head SET deferred_from_id = ? WHERE id = 1')->execute([$this->deferredFrom]);
    }

    public function testFirstSealedAnchorWinsOverLaterAndUnsealedOnes(): void
    {
        $real = str_repeat('a', 64);
        $this->anchor($real);
        $this->anchor(str_repeat('b', 64));
        $this->db->pdo()->prepare('INSERT INTO activity_log (supplier_id, action, entity_type, entity_id, payload) VALUES (?, ?, ?, ?, ?)')
            ->execute([self::SUPPLIER, MembershipErasureAnchor::ACTION, MembershipErasureAnchor::ENTITY, self::ITEM, json_encode(['seals' => ['o:5' => str_repeat('c', 64)]])]);
        self::assertSame(2, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM activity_log WHERE hash IS NOT NULL AND id >= ' . $this->from)->fetchColumn(), 'Obě zapsané kotvy jsou zapečetěné.');

        $anchor = new MembershipErasureAnchor($this->db);
        $anchor->assertRows(self::SUPPLIER, [['o', $this->row($real)]], 'x', 'x');
        foreach (['b', 'c'] as $forged) {
            try {
                (new MembershipErasureAnchor($this->db))->assertRows(self::SUPPLIER, [['o', $this->row(str_repeat($forged, 64))]], 'forged', 'x');
                self::fail('Pozdější kotva ' . $forged . ' přepsala první.');
            } catch (MembershipException $e) {
                self::assertSame('forged', $e->errorCode);
            }
        }
    }

    public function testSealedAnchorWinsOverUnsealedOneWithLowerId(): void
    {
        $pdo = $this->db->pdo();
        // Zapečetěná pravá kotva dostane vyšší id než nezapečetěný podvrh vložený později do díry pod ní.
        $pdo->prepare('INSERT INTO activity_log (id, supplier_id, action, entity_type, entity_id, payload) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$this->from + 100, self::SUPPLIER, 'test.placeholder', 'test', 1, '{}']);
        $real = str_repeat('a', 64);
        $this->anchor($real);
        $realId = (int) $pdo->query("SELECT MAX(id) FROM activity_log WHERE action='" . MembershipErasureAnchor::ACTION . "'")->fetchColumn();
        self::assertGreaterThan($this->from + 100, $realId);
        $pdo->prepare('INSERT INTO activity_log (id, supplier_id, action, entity_type, entity_id, payload) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$this->from + 50, self::SUPPLIER, MembershipErasureAnchor::ACTION, MembershipErasureAnchor::ENTITY, self::ITEM, json_encode(['seals' => ['o:5' => str_repeat('b', 64)]])]);
        $pdo->prepare('UPDATE activity_log_chain_head SET deferred_from_id = ? WHERE id = 1')->execute([$this->from]);
        self::assertNull($pdo->query('SELECT hash FROM activity_log WHERE id = ' . ($this->from + 50))->fetchColumn() ?: null, 'Podvrh zůstal nezapečetěný.');
        self::assertNotNull($pdo->query('SELECT hash FROM activity_log WHERE id = ' . $realId)->fetchColumn() ?: null, 'Pravá kotva je zapečetěná.');

        (new MembershipErasureAnchor($this->db))->assertRows(self::SUPPLIER, [['o', $this->row($real)]], 'x', 'x');
        try {
            (new MembershipErasureAnchor($this->db))->assertRows(self::SUPPLIER, [['o', $this->row(str_repeat('b', 64))]], 'forged', 'x');
            self::fail('Nezapečetěná kotva s nižším id přebila zapečetěnou.');
        } catch (MembershipException $e) {
            self::assertSame('forged', $e->errorCode);
        }
    }

    public function testAnchorOfAnotherCompanyIsIgnored(): void
    {
        $digest = str_repeat('a', 64);
        $this->logger->log(MembershipErasureAnchor::ACTION, null, MembershipErasureAnchor::ENTITY, self::ITEM,
            ['member_id' => 1, 'seals' => ['o:5' => $digest]], null, null, self::SUPPLIER + 1);
        try {
            (new MembershipErasureAnchor($this->db))->assertRows(self::SUPPLIER, [['o', $this->row($digest)]], 'foreign', 'x');
            self::fail('Kotva jiné firmy byla uznána.');
        } catch (MembershipException $e) {
            self::assertSame('foreign', $e->errorCode);
        }
        (new MembershipErasureAnchor($this->db))->assertRows(self::SUPPLIER + 1, [['o', $this->row($digest)]], 'x', 'x');
    }

    private function anchor(string $digest): void
    {
        $this->logger->log(MembershipErasureAnchor::ACTION, null, MembershipErasureAnchor::ENTITY, self::ITEM,
            ['member_id' => 1, 'seals' => ['o:5' => $digest]], null, null, self::SUPPLIER);
    }

    private function row(string $digest): array
    {
        return ['id' => 5, 'erasure_item_id' => self::ITEM, 'erased_digest' => $digest];
    }
}
