<?php

declare(strict_types=1);

namespace MyInvoice\Security;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Smí uživatel v JINÉ firmě, než je ta aktivní, zapisovat? Jediné místo pro
 * operace, které jedním krokem sahají do více firem: rozdělování dokladů ze
 * schránky a přesun příchozího dokladu.
 *
 * Rozhoduje stejná cesta jako běžný požadavek: požadavek se přepne do cílové firmy
 * a projde {@see SupplierAccessResolver} (členství, zámek domény, token vázaný na
 * firmu) i {@see PermissionResolver} (role firmy včetně přepsané role). Vlastní
 * výklad oprávnění tu proto nevzniká a nemůže se s middlewarem rozejít.
 *
 * Bez požadavku (cron) se poskládá syntetický požadavek jen s uživatelem. Odpadne
 * tím zámek domény, takže platí členství a role uživatele, nic víc.
 */
final class CrossSupplierAuthorization
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserSupplierRepository $memberships,
        private readonly SupplierAccessResolver $supplierAccess,
        private readonly PermissionResolver $permissions,
        private readonly UserRoleProfile $roleProfile,
    ) {}

    /**
     * Uživatel ve tvaru, který čeká {@see PermissionResolver}. NULL = neexistuje nebo
     * je deaktivovaný; s takovým nesmí do jiné firmy nic odejít.
     *
     * @return array<string,mixed>|null
     */
    public function activeUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT id, email, name, locale, is_active FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['is_active'] !== 1) {
            return null;
        }
        $user = $this->roleProfile->enrich([
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'name' => (string) $row['name'],
            'locale' => (string) ($row['locale'] ?? 'cs'),
            'is_active' => true,
        ]);
        return isset($user['role_id']) ? $user : null;
    }

    /**
     * Firmy, ve kterých má uživatel oprávnění na dané úrovni. Řazeno podle id.
     *
     * @param list<string> $permissions všechna musí platit zároveň
     * @return list<int>
     */
    public function suppliersAllowing(Request $request, array $permissions, AccessLevel $level = AccessLevel::WRITE): array
    {
        $out = [];
        foreach ($this->candidateIds($request) as $supplierId) {
            if ($this->allowsIn($request, $supplierId, $permissions, $level)) {
                $out[] = $supplierId;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $permissions
     * @return list<int>
     */
    public function suppliersAllowingForUser(int $userId, array $permissions, AccessLevel $level = AccessLevel::WRITE): array
    {
        $request = $this->requestForUser($userId);
        return $request !== null ? $this->suppliersAllowing($request, $permissions, $level) : [];
    }

    /**
     * @param list<string> $permissions
     */
    public function allowsIn(Request $request, int $supplierId, array $permissions, AccessLevel $level = AccessLevel::WRITE): bool
    {
        $scoped = $this->scopedRequest($request, $supplierId);
        if ($scoped === null || RequestAuthorization::isClientType($scoped)) {
            return false;
        }
        foreach ($permissions as $permission) {
            if (!RequestAuthorization::allows($scoped, $permission, $level)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Požadavek přepnutý do cílové firmy s její efektivní rolí; null, když do firmy uživatel
     * nesmí nebo je jeho role neaktivní. O oprávnění se pak rozhoduje přes
     * {@see RequestAuthorization} nad vráceným požadavkem, stejně jako v běžné akci.
     */
    public function scopedRequest(Request $request, int $supplierId): ?Request
    {
        if ($supplierId <= 0) {
            return null;
        }
        $scoped = $request
            ->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $supplierId)
            ->withQueryParams([]);
        $access = $this->supplierAccess->resolve($scoped);
        if ($access->denied || $access->supplierId !== $supplierId) {
            return null;
        }
        $role = $this->permissions->resolve($scoped);
        if (!$role->isActive) {
            return null;
        }
        return $scoped
            ->withAttribute('auth.effective_role', $role)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId);
    }

    /**
     * Jména firem pro výběr v UI, ve stejném pořadí jako vstup.
     *
     * @param list<int> $supplierIds
     * @return list<array{id:int,name:string,ic:?string,dic:?string}>
     */
    public function describe(array $supplierIds): array
    {
        if ($supplierIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($supplierIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, COALESCE(NULLIF(display_name, ''), company_name) AS name, ic, dic
               FROM supplier WHERE id IN ($in)"
        );
        $stmt->execute($supplierIds);
        $byId = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byId[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'ic' => $row['ic'] !== null && (string) $row['ic'] !== '' ? (string) $row['ic'] : null,
                'dic' => $row['dic'] !== null && (string) $row['dic'] !== '' ? (string) $row['dic'] : null,
            ];
        }
        $out = [];
        foreach ($supplierIds as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }
        return $out;
    }

    private function requestForUser(int $userId): ?Request
    {
        $user = $this->activeUser($userId);
        if ($user === null) {
            return null;
        }
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user);
    }

    /**
     * Firmy, do kterých uživatel může patřit, ještě BEZ rozhodnutí o roli a zámku
     * domény. Slouží jen k levnému předvýběru (např. hledání napříč firmami
     * nejdřív zjistí, kde vůbec něco je); každá firma, ze které se pak něco
     * vrací, musí projít {@see self::allowsIn()}.
     *
     * @return list<int>
     */
    public function candidateSupplierIds(Request $request): array
    {
        return $this->candidateIds($request);
    }

    /** @return list<int> */
    private function candidateIds(Request $request): array
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return [];
        }
        if ($this->roleProfile->isSuperadmin($userId)) {
            return array_map('intval', $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        }
        return $this->memberships->allowedSupplierIds($userId);
    }
}
