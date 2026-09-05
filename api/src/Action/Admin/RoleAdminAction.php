<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DuplicateRoleName;
use MyInvoice\Repository\InvalidPermission;
use MyInvoice\Repository\PermissionNotAllowedForRoleType;
use MyInvoice\Repository\RoleInUse;
use MyInvoice\Repository\RoleRepository;
use MyInvoice\Repository\RoleRevisionConflict;
use MyInvoice\Repository\SystemRoleLocked;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\License\LicenseSeatLimitExceeded;
use MyInvoice\Service\License\LicensePayrollLimitExceeded;
use MyInvoice\Service\License\LicenseState;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RoleAdminAction
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly PermissionCatalog $catalog,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LicenseCapacityGate $capacity,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin($request)) return $this->forbidden($response);
        return Json::ok($response, $this->roles->list());
    }

    public function permissions(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin($request)) return $this->forbidden($response);
        $groups = [];
        foreach ($this->catalog->groups() as $group) $groups[$group] = [];
        foreach ($this->catalog->all() as $definition) $groups[$definition['group']][] = $definition;
        return Json::ok($response, ['version' => PermissionCatalog::VERSION, 'groups' => $groups]);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin($request)) return $this->forbidden($response);
        $role = $this->roles->find((int) ($args['id'] ?? 0));
        return $role === null ? Json::error($response, 'not_found', 'Role nenalezena.', 404) : Json::ok($response, $role);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin($request)) return $this->forbidden($response);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $role = $this->roles->create((string) ($body['name'] ?? ''), (string) ($body['type'] ?? ''), (array) ($body['permissions'] ?? []));
            $this->log($request, 'role.created', (int) $role['id'], ['name' => $role['name'], 'type' => $role['role_type']]);
            return Json::ok($response, $role, 201);
        } catch (\Throwable $e) {
            return $this->repositoryError($response, $e);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin($request)) return $this->forbidden($response);
        $id = (int) ($args['id'] ?? 0);
        $current = $this->roles->find($id);
        if ($current === null) return Json::error($response, 'not_found', 'Role nenalezena.', 404);
        $body = (array) ($request->getParsedBody() ?? []);
        if (array_key_exists('type', $body) && $body['type'] !== $current['role_type']) {
            return Json::error($response, 'role_type_immutable', 'Typ role nelze změnit.', 409);
        }
        $permissions = (array) ($body['permissions'] ?? $current['permissions']);
        $changed = [];
        foreach ($permissions as $key => $value) {
            if ((int) $value !== (int) ($current['permissions'][$key] ?? 0)) $changed[] = (string) $key;
        }
        try {
            $role = $this->capacity->mutateSeats(
                fn (): array => $this->roles->update(
                    $id,
                    (string) ($body['name'] ?? $current['name']),
                    (bool) ($body['is_active'] ?? $current['is_active']),
                    $permissions,
                    (string) ($body['revision'] ?? $body['updated_at'] ?? ''),
                ),
            );
            if ((bool) $current['is_active'] !== (bool) $role['is_active']) {
                $this->log($request, $role['is_active'] ? 'role.activated' : 'role.deactivated', $id, ['usage' => $role['usage']]);
            }
            $this->log($request, 'role.updated', $id, ['permission_keys' => $changed]);
            return Json::ok($response, $role);
        } catch (\Throwable $e) {
            return $this->repositoryError($response, $e);
        }
    }

    public function duplicate(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin($request)) return $this->forbidden($response);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $role = $this->roles->duplicate((int) ($args['id'] ?? 0), (string) ($body['name'] ?? ''));
            $this->log($request, 'role.created', (int) $role['id'], ['duplicated_from' => (int) ($args['id'] ?? 0)]);
            return Json::ok($response, $role, 201);
        } catch (\Throwable $e) {
            return $this->repositoryError($response, $e);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin($request)) return $this->forbidden($response);
        $id = (int) ($args['id'] ?? 0);
        try {
            $this->roles->delete($id);
            $this->log($request, 'role.deleted', $id, []);
            return Json::ok($response, ['deleted' => true]);
        } catch (\Throwable $e) {
            return $this->repositoryError($response, $e);
        }
    }

    private function repositoryError(Response $response, \Throwable $e): Response
    {
        return match (true) {
            $e instanceof InvalidPermission => Json::error($response, 'invalid_permission', 'Neplatný permission klíč.', 400),
            $e instanceof PermissionNotAllowedForRoleType => Json::error($response, 'permission_not_allowed_for_role_type', 'Oprávnění není pro tento typ role povoleno.', 400),
            $e instanceof RoleRevisionConflict => Json::error($response, 'role_revision_conflict', 'Role mezitím změnil jiný uživatel.', 409),
            $e instanceof RoleInUse => Json::error($response, 'role_in_use', 'Používanou roli nelze smazat.', 409, ['usage' => $e->usage]),
            $e instanceof SystemRoleLocked => Json::error($response, 'system_role_locked', 'Systémovou roli nelze změnit.', 409),
            $e instanceof DuplicateRoleName => Json::error($response, 'role_name_taken', 'Aktivní role tohoto typu už má stejný název.', 409),
            $e instanceof LicenseSeatLimitExceeded => Json::error(
                $response,
                $e->reason === LicenseState::BLOCK_NO_LICENSE ? 'license_required' : 'license_user_limit',
                'Změna role by dala právo zápisu více aktivním uživatelům, než dovoluje licence. '
                    . 'Nejprve rozšiřte předplatné nebo uvolněte licenční místo.',
                403,
            ),
            $e instanceof LicensePayrollLimitExceeded => Json::error(
                $response,
                'license_payroll_user_limit',
                'Změna role by dala zápis do mezd více aktivním uživatelům, než dovoluje mzdový doplněk.',
                403,
                ['buy_url' => '/activation/purchase#payroll-addon'],
            ),
            $e instanceof \OutOfBoundsException => Json::error($response, 'not_found', 'Role nenalezena.', 404),
            $e instanceof \InvalidArgumentException => Json::error($response, 'validation_failed', 'Neplatná data role.', 400),
            default => throw $e,
        };
    }

    private function isSuperadmin(Request $request): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return ($user['is_superadmin'] ?? false) === true;
    }

    private function forbidden(Response $response): Response
    {
        return Json::error($response, 'forbidden_permission', 'Pouze superadmin.', 403);
    }

    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($user['id'] ?? 0), 'role', $entityId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
