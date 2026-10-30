<?php

declare(strict_types=1);

namespace MyInvoice\Service\Dashboard;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Vlastní rozložení je globální per uživatel; firma ovlivňuje jen dostupnost widgetů. */
final class DashboardLayoutService
{
    public function __construct(private readonly Connection $db, private readonly DashboardWidgetRegistry $registry) {}

    public function get(int $userId, string $board): array
    {
        $this->assertOwner($userId, $board);
        return $this->read($this->db->pdo(), $userId, $board);
    }

    public function put(int $userId, string $board, mixed $layout, string $revision): array
    {
        $this->assertOwner($userId, $board);
        return $this->change($userId, $board, $this->registry->validate($layout), $revision);
    }

    public function reset(int $userId, string $board, string $revision): array
    {
        $this->assertOwner($userId, $board);
        return $this->change($userId, $board, null, $revision);
    }

    private function read(PDO $pdo, int $userId, string $board): array
    {
        $stmt = $pdo->prepare('SELECT payload FROM user_preferences WHERE user_id = ? AND pref_key = ?');
        $stmt->execute([$userId, 'dashboard.' . $board]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) return ['layout' => null, 'revision' => '0'];
        $stored = json_decode((string) $raw, true, 16, JSON_THROW_ON_ERROR);
        return ['layout' => $stored['layout'] === null ? null : $this->registry->validate($stored['layout']),
            'revision' => (string) $stored['revision']];
    }

    private function change(int $userId, string $board, ?array $layout, string $revision): array
    {
        if (preg_match('/\A(?:0|[a-f0-9]{32})\z/D', $revision) !== 1) {
            throw new \InvalidArgumentException('Neplatná revize konfigurace přehledu.');
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Existující rodič serializuje i první INSERT chybějící preference.
            $lock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$userId]);
            if ($lock->fetchColumn() === false) throw new \InvalidArgumentException('Uživatel nenalezen.');
            $current = $this->read($pdo, $userId, $board);
            if (!hash_equals($current['revision'], $revision)) throw new DashboardLayoutConflict();
            if ($layout === null && $current['layout'] === null) {
                $pdo->commit();
                return $current;
            }
            $next = ['layout' => $layout, 'revision' => bin2hex(random_bytes(16))];
            // Reset ponechává jen revizní tombstone: stará revize „0“ nesmí ožít po smazání.
            $stmt = $pdo->prepare('INSERT INTO user_preferences (user_id, pref_key, payload) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE payload = VALUES(payload)');
            $stmt->execute([$userId, 'dashboard.' . $board, json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            $pdo->commit();
            return $next;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function assertOwner(int $userId, string $board): void
    {
        DashboardWidgetRegistry::assertBoard($board);
        if ($userId <= 0) throw new \InvalidArgumentException('Uživatel není přihlášen.');
    }
}
