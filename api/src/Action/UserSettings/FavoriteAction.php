<?php

declare(strict_types=1);

namespace MyInvoice\Action\UserSettings;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Oblíbené stránky uživatele (hvězdička v horní liště).
 *
 *   GET    /api/user/favorites        — všechny oblíbené uživatele napříč firmami
 *   POST   /api/user/favorites        — 201 + položka (stejná stránka ve stejné firmě vrací existující, 200)
 *   PUT    /api/user/favorites/{id}   — přejmenování
 *   DELETE /api/user/favorites/{id}   — {"deleted": true}
 *
 * Seznam je osobní a platí napříč firmami, proto se u GET nefiltruje podle aktuální firmy:
 * odkaz si firmu pamatuje a klient ji při prokliku přepne. Firmu při ukládání bere server
 * z ověřeného kontextu požadavku, nikdy z těla. Cizí položka je vždy 404 (anti-enumeration).
 */
final class FavoriteAction
{
    public const MAX_FAVORITES = 50;
    public const MAX_PATH = 500;
    public const MAX_TITLE = 190;

    public function __construct(private readonly Connection $db) {}

    public function list(Request $request, Response $response): Response
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, path, title, position, created_at
               FROM user_favorites
              WHERE user_id = ?
              ORDER BY position, id'
        );
        $stmt->execute([$this->userId($request)]);

        return Json::ok($response, array_map([$this, 'toItem'], $stmt->fetchAll()));
    }

    public function create(Request $request, Response $response): Response
    {
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        $path = self::normalizePath($body['path'] ?? null);
        if ($path === null) {
            return Json::error($response, 'invalid_path', 'Odkaz musí být interní cesta v aplikaci.', 422);
        }
        $title = self::normalizeTitle($body['title'] ?? null);
        if ($title === null) {
            return Json::error($response, 'validation_failed', 'Název musí mít 1–190 znaků.', 422);
        }

        $pdo = $this->db->pdo();
        $supplierParam = $supplierId > 0 ? $supplierId : null;

        $existing = $pdo->prepare(
            'SELECT id FROM user_favorites WHERE user_id = ? AND path = ? AND supplier_id <=> ? LIMIT 1'
        );
        $existing->execute([$userId, $path, $supplierParam]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            return Json::ok($response, $this->findItem($pdo, (int) $existingId, $userId));
        }

        $count = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(position), 0) FROM user_favorites WHERE user_id = ?');
        $count->execute([$userId]);
        [$total, $maxPosition] = array_map('intval', (array) $count->fetch(PDO::FETCH_NUM));
        if ($total >= self::MAX_FAVORITES) {
            return Json::error($response, 'favorite_limit_reached', 'Dosažen limit 50 oblíbených stránek.', 422);
        }

        $pdo->prepare(
            'INSERT INTO user_favorites (user_id, supplier_id, path, title, position) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $supplierParam, $path, $title, $maxPosition + 1]);

        return Json::ok($response, $this->findItem($pdo, (int) $pdo->lastInsertId(), $userId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        $pdo = $this->db->pdo();
        if ($this->findRow($pdo, $id, $userId) === null) {
            return Json::error($response, 'not_found', 'Oblíbená stránka nenalezena.', 404);
        }

        $title = self::normalizeTitle($body['title'] ?? null);
        if ($title === null) {
            return Json::error($response, 'validation_failed', 'Název musí mít 1–190 znaků.', 422);
        }
        $pdo->prepare('UPDATE user_favorites SET title = ? WHERE id = ? AND user_id = ?')
            ->execute([$title, $id, $userId]);

        return Json::ok($response, $this->findItem($pdo, $id, $userId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM user_favorites WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($args['id'] ?? 0), $this->userId($request)]);
        if ($stmt->rowCount() === 0) {
            return Json::error($response, 'not_found', 'Oblíbená stránka nenalezena.', 404);
        }

        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * Jen interní relativní cesta: začíná jedním lomítkem, bez schématu, hostu,
     * zpětných lomítek a řídicích znaků. Jinak by oblíbená mohla vést mimo aplikaci.
     */
    public static function normalizePath(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $path = $value;
        if ($path === '' || strlen($path) > self::MAX_PATH || $path[0] !== '/' || str_starts_with($path, '//')) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path) === 1 || str_contains($path, '://')) {
            return null;
        }
        if (preg_match('#^/(?:[^?\#]*/)?(?:\.\.?)(?:/|$|\?|\#)#', $path) === 1) {
            return null;
        }

        return $path;
    }

    private static function normalizeTitle(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $title = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
            return null;
        }

        return $title;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }

    /** @return array<string,mixed>|null */
    private function findRow(PDO $pdo, int $id, int $userId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, supplier_id, path, title, position, created_at FROM user_favorites WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed> */
    private function findItem(PDO $pdo, int $id, int $userId): array
    {
        return $this->toItem((array) $this->findRow($pdo, $id, $userId));
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function toItem(array $r): array
    {
        return [
            'id'          => (int) $r['id'],
            'supplier_id' => $r['supplier_id'] === null ? null : (int) $r['supplier_id'],
            'path'        => (string) $r['path'],
            'title'       => (string) $r['title'],
            'position'    => (int) $r['position'],
            'created_at'  => (string) $r['created_at'],
        ];
    }
}
