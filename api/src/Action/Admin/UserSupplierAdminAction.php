<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\License\LicenseSeatLimitExceeded;
use MyInvoice\Service\License\LicensePayrollLimitExceeded;
use MyInvoice\Service\License\LicenseState;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UserSupplierAdminAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserSupplierRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LicenseCapacityGate $capacity,
    ) {}

    public function list(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $user = $this->targetUser((int) ($args['id'] ?? 0));
        if ($user === null) return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        return Json::ok($response, $this->listAssignments((int) $user['id']));
    }

    public function replace(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $user = $this->targetUser($id);
        if ($user === null) return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        if ($user['system_key'] === 'superadmin') {
            return Json::error($response, 'system_role_locked', 'Superadmin má přístup ke všem firmám a membership se mu nepřiřazuje.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        if (!isset($body['assignments']) || !is_array($body['assignments'])) {
            return Json::error($response, 'validation_failed', "Pole 'assignments' je povinné.", 400);
        }
        $assignments = [];
        $seen = [];
        foreach ($body['assignments'] as $item) {
            if (!is_array($item)) return Json::error($response, 'validation_failed', 'Každé přiřazení musí být objekt.', 400);
            $supplierId = (int) ($item['supplier_id'] ?? 0);
            if ($supplierId <= 0 || isset($seen[$supplierId])) {
                return Json::error($response, 'validation_failed', $supplierId <= 0 ? 'Neplatné supplier_id.' : "Duplicitní supplier_id {$supplierId}.", 400);
            }
            $roleId = array_key_exists('role_id', $item) && $item['role_id'] !== null ? (int) $item['role_id'] : null;
            $seen[$supplierId] = true;
            $assignments[] = ['supplier_id' => $supplierId, 'role_id' => $roleId];
        }
        if ($assignments !== []) {
            $ids = array_keys($seen);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT id FROM supplier WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $existing = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            $missing = array_values(array_diff($ids, $existing));
            if ($missing !== []) return Json::error($response, 'validation_failed', 'Neexistující supplier_id: ' . implode(', ', $missing) . '.', 400);
        }
        foreach ($assignments as $assignment) {
            if ($assignment['role_id'] === null) continue;
            $role = $this->role((int) $assignment['role_id']);
            if ($role === null || !$role['is_active']) return Json::error($response, 'validation_failed', 'Override role neexistuje nebo není aktivní.', 400);
            if ($role['role_type'] !== $user['role_type']) {
                return Json::error($response, 'role_type_mismatch', 'Override role musí mít stejný typ jako výchozí role uživatele.', 400);
            }
            if ($role['role_type'] === 'superadmin') return Json::error($response, 'system_role_locked', 'Superadmin roli nelze použít jako override.', 409);
        }

        try {
            $this->capacity->mutateSeats(function () use ($id, $assignments): null {
                $this->repo->replaceForUser($id, $assignments);
                return null;
            });
        } catch (LicenseSeatLimitExceeded $e) {
            return Json::error(
                $response,
                $e->reason === LicenseState::BLOCK_NO_LICENSE ? 'license_required' : 'license_user_limit',
                'Tímhle přiřazením by účet získal právo zápisu a zabral licenční místo, '
                    . 'které teď není volné. Přidělte roli jen pro čtení, nebo rozšiřte předplatné.',
                403,
            );
        } catch (LicensePayrollLimitExceeded) {
            return Json::error(
                $response,
                'license_payroll_user_limit',
                'Tímto přiřazením by účet získal zápis do mezd a zabral další placené mzdové místo. Nejprve rozšiřte mzdový doplněk.',
                403,
                ['buy_url' => '/activation/purchase#payroll-addon'],
            );
        }
        $this->log($request, 'user.suppliers_updated', $id, ['supplier_ids' => array_keys($seen)]);
        return Json::ok($response, $this->listAssignments($id));
    }

    private function listAssignments(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT us.supplier_id, COALESCE(NULLIF(s.display_name, \'\'), s.company_name) AS name, s.ic,
                    us.role_id, er.id AS effective_role_id, er.name AS effective_role_name,
                    er.role_type AS effective_role_type, er.is_active AS effective_role_active
               FROM user_suppliers us JOIN users u ON u.id = us.user_id JOIN supplier s ON s.id = us.supplier_id
               JOIN roles er ON er.id = COALESCE(us.role_id, u.role_id)
              WHERE us.user_id = ? ORDER BY name, us.supplier_id'
        );
        $stmt->execute([$userId]);
        return array_map(static fn (array $row): array => [
            'supplier_id' => (int) $row['supplier_id'], 'name' => (string) $row['name'], 'ic' => $row['ic'],
            'role_id' => $row['role_id'] !== null ? (int) $row['role_id'] : null,
            'effective_role' => ['id' => (int) $row['effective_role_id'], 'name' => (string) $row['effective_role_name'],
                'type' => (string) $row['effective_role_type'], 'is_active' => (bool) $row['effective_role_active']],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function targetUser(int $id): ?array
    {
        if ($id <= 0) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT u.id, u.role_id, r.role_type, r.system_key FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function role(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, role_type, is_active, system_key FROM roles WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }

    private function guard(Request $request, Response $response, ?Response &$err): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['is_superadmin'] ?? false) !== true) {
            $err = Json::error($response, 'forbidden_permission', 'Pouze superadmin.', 403);
            return false;
        }
        $err = null;
        return true;
    }

    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($user['id'] ?? 0), 'user', $entityId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
