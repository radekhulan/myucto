<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\License\LicenseSeatLimitExceeded;
use MyInvoice\Service\License\LicensePayrollLimitExceeded;
use MyInvoice\Service\License\LicenseState;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UserAdminAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly LicenseCapacityGate $capacity,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $statement = $this->db->pdo()->query($this->selectSql() . ' ORDER BY u.id');
        if ($statement === false) {
            throw new \RuntimeException('Seznam uživatelů se nepodařilo načíst.');
        }
        return Json::ok($response, array_map($this->normalize(...), $statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $body = (array) ($request->getParsedBody() ?? []);
        $email = trim((string) ($body['email'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        $roleId = (int) ($body['role_id'] ?? 0);
        $locale = (string) ($body['locale'] ?? 'cs');
        $password = (string) ($body['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return Json::error($response, 'validation_failed', 'Neplatný email.', 400);
        if ($name === '') return Json::error($response, 'validation_failed', 'Jméno je povinné.', 400);
        if (!in_array($locale, ['cs', 'en'], true)) return Json::error($response, 'validation_failed', 'Neplatný locale.', 400);
        $role = $this->activeRole($roleId);
        if ($role === null) return Json::error($response, 'validation_failed', 'Vybraná role neexistuje nebo není aktivní.', 400);
        try {
            $this->hasher->validate($password);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $passwordHash = $this->hasher->hash($password);

        // Zápis i čerstvý přepočet míst jsou jedna serializovaná operace.
        try {
            $id = $this->capacity->mutateSeats(
                function () use ($email, $passwordHash, $name, $role, $roleId, $locale): int {
                    $pdo = $this->db->pdo();
                    $stmt = $pdo->prepare(
                        'INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)'
                    );
                    $stmt->execute([$email, $passwordHash, $name, $this->legacyRole($role), $roleId, $locale]);
                    return (int) $pdo->lastInsertId();
                },
            );
        } catch (LicenseSeatLimitExceeded $e) {
            return Json::error($response, self::blockCode($e->reason), self::blockMessage($e->reason, $e->state), 403);
        } catch (LicensePayrollLimitExceeded) {
            return Json::error($response, 'license_payroll_user_limit', 'Zvolená role by zabrala další placené místo v modulu Mzdy. Nejprve rozšiřte mzdový doplněk.', 403, ['buy_url' => '/activation/purchase#payroll-addon']);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_users_email')) {
                return Json::error($response, 'email_taken', 'Email je už registrovaný.', 409);
            }
            throw $e;
        }
        $this->log($request, 'user.created', $id, ['email' => $email, 'role_id' => $roleId]);
        return Json::ok($response, $this->fetchUser($id), 201);
    }

    /**
     * @param array<string,string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        $row = $this->fetchUser($id);
        if ($row === null) return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        $body = (array) ($request->getParsedBody() ?? []);

        $newRole = null;
        if (array_key_exists('role_id', $body)) {
            $newRole = $this->activeRole((int) $body['role_id']);
            if ($newRole === null) return Json::error($response, 'validation_failed', 'Vybraná role neexistuje nebo není aktivní.', 400);
            if ($newRole['role_type'] !== $row['role']['type']) {
                $incompatible = $this->incompatibleOverrides($id, (string) $newRole['role_type']);
                if ($incompatible !== []) {
                    return Json::error($response, 'incompatible_supplier_roles', 'Nejprve odstraň nekompatibilní role u přiřazených firem.', 409, [
                        'supplier_ids' => $incompatible,
                    ]);
                }
            }
        }

        $willBeSuperadmin = $newRole !== null
            ? $newRole['system_key'] === 'superadmin'
            : (bool) $row['is_superadmin'];
        $willBeActive = array_key_exists('is_active', $body) ? (bool) $body['is_active'] : (bool) $row['is_active'];

        $sets = [];
        $params = [];
        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') return Json::error($response, 'validation_failed', 'Jméno je povinné.', 400);
            $sets[] = 'name = ?'; $params[] = $name;
        }
        if ($newRole !== null) {
            $sets[] = 'role_id = ?'; $params[] = (int) $newRole['id'];
            $sets[] = 'role = ?'; $params[] = $this->legacyRole($newRole);
        }
        if (array_key_exists('locale', $body)) {
            if (!in_array($body['locale'], ['cs', 'en'], true)) return Json::error($response, 'validation_failed', 'Neplatný locale.', 400);
            $sets[] = 'locale = ?'; $params[] = (string) $body['locale'];
        }
        if (array_key_exists('is_active', $body)) {
            $sets[] = 'is_active = ?'; $params[] = (bool) $body['is_active'] ? 1 : 0;
        }
        if (!empty($body['password'])) {
            try { $this->hasher->validate((string) $body['password']); }
            catch (\InvalidArgumentException $e) { return Json::error($response, 'validation_failed', $e->getMessage(), 400); }
            $sets[] = 'password_hash = ?'; $params[] = $this->hasher->hash((string) $body['password']);
        }
        if ($sets === []) return Json::ok($response, $row);

        try {
            if (!$this->guardedUserUpdate($id, implode(', ', $sets), $params, $willBeSuperadmin, $willBeActive)) {
                return Json::error($response, 'last_admin', 'Nelze odebrat roli ani deaktivovat posledního aktivního superadmina.', 409);
            }
        } catch (LicenseSeatLimitExceeded $e) {
            return Json::error($response, self::blockCode($e->reason), self::blockMessage($e->reason, $e->state), 403);
        } catch (LicensePayrollLimitExceeded) {
            return Json::error($response, 'license_payroll_user_limit', 'Zvolená role by zabrala další placené místo v modulu Mzdy. Nejprve rozšiřte mzdový doplněk.', 403, ['buy_url' => '/activation/purchase#payroll-addon']);
        }
        if ($newRole !== null && (int) $row['role_id'] !== (int) $newRole['id']) {
            $this->log($request, 'user.role_updated', $id, ['from_role_id' => $row['role_id'], 'to_role_id' => (int) $newRole['id']]);
        }
        // Změna hesla nebo deaktivace nesmí nechat běžet staré session — sám update
        // provádí guardedUserUpdate() výš, tady se jen odvolá přihlášení.
        $mustRevokeSessions = !empty($body['password'])
            || (array_key_exists('is_active', $body) && !(bool) $body['is_active']);
        if ($mustRevokeSessions) {
            $this->sessions->destroyAllForUser($id);
        }
        $this->log($request, 'user.updated', $id, ['fields' => array_keys($body)]);
        return Json::ok($response, $this->fetchUser($id));
    }

    /**
     * @param array<string,string> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        $row = $this->fetchUser($id);
        if ($row === null) return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        $actor = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if ((int) ($actor['id'] ?? 0) === $id) return Json::error($response, 'self_delete_forbidden', 'Nelze deaktivovat vlastní účet.', 409);
        try {
            if (!$this->guardedUserUpdate($id, 'is_active = 0', [], false, false)) {
                return Json::error($response, 'last_admin', 'Nelze deaktivovat posledního aktivního superadmina.', 409);
            }
        } catch (LicenseSeatLimitExceeded $e) {
            return Json::error($response, self::blockCode($e->reason), self::blockMessage($e->reason, $e->state), 403);
        } catch (LicensePayrollLimitExceeded) {
            return Json::error($response, 'license_payroll_user_limit', 'Zvolená role by zabrala další placené místo v modulu Mzdy. Nejprve rozšiřte mzdový doplněk.', 403, ['buy_url' => '/activation/purchase#payroll-addon']);
        }
        $this->sessions->destroyAllForUser($id);
        $this->log($request, 'user.deactivated', $id, []);
        return Json::ok($response, ['deactivated' => true]);
    }

    private function guard(Request $request, Response $response): ?Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['is_superadmin'] ?? false) !== true) {
            return Json::error($response, 'forbidden_permission', 'Pouze superadmin.', 403);
        }
        return null;
    }

    private function activeRole(int $id): ?array
    {
        if ($id <= 0) return null;
        $stmt = $this->db->pdo()->prepare('SELECT id, system_key, role_type FROM roles WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<int> */
    private function incompatibleOverrides(int $userId, string $roleType): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT us.supplier_id FROM user_suppliers us JOIN roles r ON r.id = us.role_id
              WHERE us.user_id = ? AND us.role_id IS NOT NULL AND r.role_type <> ? ORDER BY us.supplier_id'
        );
        $stmt->execute([$userId, $roleType]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Serializuje všechny změny uživatelů přes řádky aktivních superadminů.
     * Dva souběžné requesty tak nemohou oba pozorovat počet 2 a deaktivovat oba.
     *
     * @param list<mixed> $params
     */
    private function guardedUserUpdate(
        int $id,
        string $setSql,
        array $params,
        bool $willBeSuperadmin,
        bool $willBeActive,
    ): bool
    {
        return $this->capacity->mutateSeats(function () use (
            $id,
            $setSql,
            $params,
            $willBeSuperadmin,
            $willBeActive,
        ): bool {
            $pdo = $this->db->pdo();
            $stmt = $pdo->query(
                "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                  WHERE u.is_active = 1 AND r.is_active = 1
                    AND r.system_key = 'superadmin' AND r.role_type = 'superadmin'
                  ORDER BY u.id FOR UPDATE"
            );
            $activeSuperadminIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            if (in_array($id, $activeSuperadminIds, true)
                && (!$willBeSuperadmin || !$willBeActive)
                && count($activeSuperadminIds) <= 1
            ) {
                return false;
            }

            $params[] = $id;
            $pdo->prepare('UPDATE users SET ' . $setSql . ' WHERE id = ?')->execute($params);
            return true;
        });
    }

    private function fetchUser(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare($this->selectSql() . ' WHERE u.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->normalize($row) : null;
    }

    private function selectSql(): string
    {
        return 'SELECT u.id, u.email, u.name, u.role_id, u.locale, u.is_active, u.created_at, u.last_login_at,
                       r.name AS role_name, r.role_type, r.is_active AS role_active, r.system_key
                  FROM users u JOIN roles r ON r.id = u.role_id';
    }

    private function normalize(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'email' => (string) $row['email'], 'name' => (string) $row['name'],
            'role_id' => (int) $row['role_id'],
            'role' => ['id' => (int) $row['role_id'], 'name' => (string) $row['role_name'], 'type' => (string) $row['role_type'],
                'is_active' => (bool) $row['role_active'], 'system_key' => $row['system_key']],
            'is_superadmin' => $row['system_key'] === 'superadmin', 'locale' => (string) $row['locale'],
            'is_active' => (bool) $row['is_active'], 'created_at' => $row['created_at'], 'last_login_at' => $row['last_login_at'],
        ];
    }

    /**
     * Kód chyby podle důvodu. ⚠️ Dva různé kódy schválně: frontend na ně reaguje
     * jinak — u chybějící licence vede k aktivaci, u vyčerpaných míst k navýšení.
     */
    private static function blockCode(string $reason): string
    {
        return $reason === LicenseState::BLOCK_NO_LICENSE ? 'license_required' : 'license_user_limit';
    }

    /**
     * ⚠️ Hláška musí říct, co s tím — ne jen že to nejde. A musí být zřejmé,
     * že se to týká jen provozních rolí: účet s právem jen pro čtení jde
     * založit i bez licence a často je to přesně to, co admin potřebuje.
     */
    private static function blockMessage(string $reason, LicenseState $state): string
    {
        if ($reason !== LicenseState::BLOCK_NO_LICENSE) {
            return 'Byl dosažen počet uživatelů podle vaší licence. Rozšiřte předplatné, '
                . 'nebo uvolněte místo deaktivací jiného uživatele.';
        }

        // ⚠️ „Licence propadla" a „licenci jste nikdy neměli" vyžadují jiný krok.
        // Posílat zákazníka, který licenci aktivovanou MÁ, do sekce Aktivace je
        // rada, po které se nic nezmění — jemu propadlo předplatné.
        if ($state->state === LicenseState::DEGRADED) {
            return 'Platnost licence vypršela, takže jde zakládat jen uživatele s právem '
                . 'jen pro čtení. Obnovte předplatné, nebo uživateli přidělte roli jen pro čtení.';
        }

        return 'Bez platné licence lze zakládat jen uživatele s právem jen pro čtení. '
            . 'Aktivujte licenci v sekci Aktivace, nebo uživateli přidělte roli jen pro čtení.';
    }
    private function legacyRole(array $role): string
    {
        return match ($role['system_key'] ?? null) {
            'superadmin' => 'admin', 'accountant' => 'accountant', 'readonly' => 'readonly', 'client' => 'client',
            default => $role['role_type'] === 'client' ? 'client' : 'readonly',
        };
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($user['id'] ?? 0), 'user', $entityId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
