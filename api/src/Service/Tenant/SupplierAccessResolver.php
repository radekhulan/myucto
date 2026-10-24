<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sdílená resoluce supplier scope + membership (Epic F0, GitHub issue #3).
 *
 * Jediné místo, které z requestu odvozuje aktuální supplier id (token-bound
 * PAT → X-Supplier-Id header → ?supplier_id query → fallback) a zároveň
 * vynucuje membership z user_suppliers:
 *
 *   - systémový superadmin: na canonical hostu vidí všechny firmy; vlastní host
 *     zachová membership výjimku, ale zamkne ho na firmu ověřené domény
 *   - každý non-superadmin BEZ membership řádků = denied (fail-closed)
 *   - uživatel S membership: explicitní požadavek na cizí firmu → denied
 *     (middleware vrací 403); bez explicitního požadavku fallback na výchozí
 *     firmu uživatele mezi PŘIŘAZENÝMI firmami (DefaultSupplierService),
 *     nikdy globální MIN — ten může patřit cizí firmě
 *   - PAT bound na supplier_id: pokud má uživatel membership a token je bound
 *     mimo něj → denied (token nesmí obejít membership); admin/bez-membership
 *     beze změny
 *
 * Používá SupplierScopeMiddleware (403 + ATTR_CURRENT_ID) a PermissionResolver.
 * Resoluce nezávisí na ATTR_CURRENT_ID a memoizuje se tady
 * (nejvýše 1 membership dotaz + 1 existence dotaz na request).
 */
final class SupplierAccessResolver
{
    /** @var array<string, SupplierAccess> memo v rámci requestu (container = 1 instance / request) */
    private array $memo = [];

    /** @var array<int, array<int, ?int>> memo membership mapy per user */
    private array $assignmentsMemo = [];

    /**
     * @param DefaultSupplierService|null $defaultSupplier výchozí firma uživatele pro
     *        požadavek bez výběru firmy; null = dřívější nejnižší id (unit testy)
     */
    public function __construct(
        private readonly Connection $db,
        private readonly UserSupplierRepository $memberships,
        ?EntityCache $cache = null,
        private readonly ?DefaultSupplierService $defaultSupplier = null,
    ) {
        $this->cache = $cache ?? EntityCache::disabled();
    }

    private readonly EntityCache $cache;

    public function resolve(Request $request): SupplierAccess
    {
        $user     = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (\MyInvoice\Security\InstanceAdminRoutePolicy::isTechnicalUser($user)) {
            return new SupplierAccess(0, true, null);
        }
        $userId   = (int) ($user['id'] ?? 0);
        $roleId = (int) ($user['role_id'] ?? ($user['role_summary']['id'] ?? 0));
        $isSuperadmin = (bool) ($user['is_superadmin'] ?? false)
            || (($user['role_summary']['type'] ?? null) === 'superadmin')
            || (($user['role_summary']['system_key'] ?? null) === 'superadmin');

        // Vlastní aktivní hostname je nejsilnější tenantová autorita. Ani PAT,
        // X-Supplier-Id ani query parametr nesmí request přesměrovat do jiné firmy.
        $domain = $request->getAttribute(\MyInvoice\Middleware\TenantDomainMiddleware::ATTR_CONTEXT);
        if ($domain instanceof TenantDomainContext && $domain->locksSupplier()) {
            $sid = (int) $domain->supplierId;
            $requested = $this->requestedId($request);
            $apiToken = $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN);
            $boundSid = is_array($apiToken) && ($apiToken['supplier_id'] ?? null) !== null
                ? (int) $apiToken['supplier_id']
                : 0;
            if (($requested > 0 && $requested !== $sid) || ($boundSid > 0 && $boundSid !== $sid)) {
                return new SupplierAccess($sid, true, null);
            }
            $key = 'domain:' . $userId . ':' . $roleId . ':' . (int) $isSuperadmin . ':' . $sid;
            return $this->memo[$key] ??= $this->resolveBound($userId, $isSuperadmin, $sid);
        }

        // 0. Bearer (API token) bound na konkrétní supplier — forcuj ho, ignoruj
        //    header/query (token nesmí "skočit" do jiné firmy).
        $apiToken = $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN);
        if (is_array($apiToken) && ($apiToken['supplier_id'] ?? null) !== null) {
            $sid = (int) $apiToken['supplier_id'];
            $key = 'bound:' . $userId . ':' . $roleId . ':' . (int) $isSuperadmin . ':' . $sid;
            return $this->memo[$key] ??= $this->resolveBound($userId, $isSuperadmin, $sid);
        }

        $requested = $this->requestedId($request);
        $key = 'u:' . $userId . ':' . $roleId . ':' . (int) $isSuperadmin . ':' . $requested;
        return $this->memo[$key] ??= $this->doResolve($userId, $isSuperadmin, $requested);
    }

    /**
     * Autoritativní supplier z PAT nebo ověřené domény. Globální admin ho
     * dostane bez membership omezení; ostatní pouze ve své přiřazené firmě.
     */
    private function resolveBound(int $userId, bool $isSuperadmin, int $sid): SupplierAccess
    {
        if ($isSuperadmin) {
            return new SupplierAccess($sid, false, null);
        }

        return $this->resolveMemberBound($userId, $sid);
    }

    /** Membership kontrola pro běžné role nad autoritativně určenou firmou. */
    private function resolveMemberBound(int $userId, int $sid): SupplierAccess
    {
        $assignments = $this->assignmentsFor($userId);
        if ($assignments === []) {
            return new SupplierAccess($sid, true, null);
        }
        if (!array_key_exists($sid, $assignments)) {
            return new SupplierAccess($sid, true, null);
        }
        return new SupplierAccess($sid, false, $assignments[$sid]);
    }

    private function doResolve(int $userId, bool $isSuperadmin, int $requested): SupplierAccess
    {
        // Globální admin = bez membership omezení a bez per-supplier override
        // (vidí všechny firmy; override by ho mohl zamknout z admin endpointů).
        if ($isSuperadmin) {
            if ($requested > 0 && $this->exists($requested)) {
                return new SupplierAccess($requested, false, null);
            }
            if ($this->defaultSupplier !== null && $userId > 0) {
                return new SupplierAccess($this->defaultSupplier->resolve($userId, null), false, null);
            }
            return new SupplierAccess($this->resolveExisting(0), false, null);
        }

        $assignments = $this->assignmentsFor($userId);

        // Každý non-superadmin bez membershipu je fail-closed.
        if ($assignments === []) {
            return new SupplierAccess($requested, true, null);
        }

        if ($requested > 0 && $this->exists($requested)) {
            if (!array_key_exists($requested, $assignments)) {
                // Explicitní požadavek na firmu mimo membership → 403 v middleware
                return new SupplierAccess($requested, true, null);
            }
            return new SupplierAccess($requested, false, $assignments[$requested]);
        }

        // Bez headeru (nebo neexistující id) → výchozí firma uživatele
        // (DefaultSupplierService: uložená, jinak jednorázově zvolená podle
        // počtu dokladů), bez služby nejnižší přiřazená firma.
        // FK v user_suppliers garantuje existenci supplier řádku.
        $ids = array_keys($assignments);
        sort($ids);
        $sid = $this->defaultSupplier?->resolve($userId, $ids) ?? (int) $ids[0];
        return new SupplierAccess($sid, false, $assignments[$sid]);
    }

    /** Požadované supplier id z headeru X-Supplier-Id, fallback ?supplier_id (PDF/ZIP navigace). */
    private function requestedId(Request $request): int
    {
        $headerVal = trim($request->getHeaderLine(SupplierScopeMiddleware::HEADER_NAME));
        if (ctype_digit($headerVal)) {
            return (int) $headerVal;
        }
        $q    = $request->getQueryParams();
        $qVal = isset($q['supplier_id']) ? trim((string) $q['supplier_id']) : '';
        return ctype_digit($qVal) ? (int) $qVal : 0;
    }

    /** @return array<int, ?int> */
    private function assignmentsFor(int $userId): array
    {
        if ($userId <= 0) return [];
        return $this->assignmentsMemo[$userId] ??= $this->memberships->assignmentsForUser($userId);
    }

    private function exists(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id = ? LIMIT 1');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Původní resoluce (před F0): $requested pokud existuje, jinak MIN(id),
     * jinak 0 (před setup).
     */
    private function resolveExisting(int $requested): int
    {
        if ($requested > 0 && $this->exists($requested)) {
            return $requested;
        }
        // Nejnižší supplier id se mění jen při založení/smazání firmy — cache
        // ho drží mezi requesty a zápis do `supplier` skupinu přetočí (PDO hook).
        return (int) $this->cache->remember(
            EntityCache::GROUP_SUPPLIER,
            'min_id',
            fn (): int => (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn(),
        );
    }
}
