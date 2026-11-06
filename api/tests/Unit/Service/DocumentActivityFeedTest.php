<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\DocumentActivityFeed;
use PHPUnit\Framework\TestCase;

/**
 * Aktivita jednoho dokladu nevrací IP adresy ani e-maily uživatelů, protože endpoint
 * čtou i klientské a read-only role a API tokeny.
 */
final class DocumentActivityFeedTest extends TestCase
{
    public function testProjectionHasNoIpOrEmail(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, name TEXT)');
        $pdo->exec('CREATE TABLE activity_log (id INTEGER PRIMARY KEY, user_id INTEGER NULL, entity_type TEXT, entity_id INTEGER,
                    action TEXT, payload TEXT NULL, ip BLOB NULL, created_at TEXT)');
        $pdo->exec("INSERT INTO users VALUES (5, 'ucetni@example.test', 'Jana Účetní'), (6, 'bezjmena@example.test', '')");
        $ip = inet_pton('192.0.2.10');
        $insert = $pdo->prepare('INSERT INTO activity_log VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([1, 5, 'invoice', 7, 'invoice.updated', json_encode(['changed' => ['note'], 'ip' => '192.0.2.10', 'user_agent' => 'UA']), $ip, '2026-10-01 10:00:00']);
        $insert->execute([2, 6, 'invoice', 7, 'invoice.sent', json_encode(['to' => ['odberatel@example.test']]), $ip, '2026-10-02 10:00:00']);
        $insert->execute([3, null, 'invoice', 7, 'invoice.public_viewed', null, $ip, '2026-10-03 10:00:00']);
        $insert->execute([4, 5, 'invoice', 8, 'invoice.updated', null, $ip, '2026-10-03 10:00:00']);

        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $rows = (new DocumentActivityFeed($db))->forEntity('invoice', 7);

        self::assertSame([3, 2, 1], array_column($rows, 'id'));
        foreach ($rows as $row) {
            self::assertSame(['id', 'user_id', 'user_name', 'action', 'payload', 'created_at'], array_keys($row));
        }
        self::assertSame('Jana Účetní', $rows[2]['user_name']);
        self::assertSame(['changed' => ['note']], $rows[2]['payload']);
        self::assertNull($rows[1]['user_name'], 'Bez jména se nevrací ani e-mail jako náhrada.');
        self::assertSame(['to' => ['odberatel@example.test']], $rows[1]['payload'], 'Obsah dokladu (příjemci) zůstává.');
        self::assertNull($rows[0]['user_id']);
        $json = (string) json_encode($rows);
        self::assertStringNotContainsString('192.0.2.10', $json);
        self::assertStringNotContainsString('ucetni@example.test', $json);
        self::assertStringNotContainsString('bezjmena@example.test', $json);
    }
}
