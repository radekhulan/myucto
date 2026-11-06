<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Security;

use MyInvoice\Middleware\PermissionMiddleware;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Security\FrameworkScopePolicy;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Agendy SVJ a NNO/504 vynucuje i server, ne jen menu a router: běžná s.r.o. je
 * nedostane ani přímým voláním API (např. API tokenem správce, který má oprávnění
 * `property` ve všech firmách).
 */
#[Group('integration')]
final class FrameworkScopePolicyDbTest extends StockTestCase
{
    public function testScopesFollowLegalFormAndEffectiveFramework(): void
    {
        $policy = $this->container->get(FrameworkScopePolicy::class);
        $sro = $this->supplierWith('112');
        $svj = $this->supplierWith('145');
        $spolek = $this->supplierWith('706');
        $sro504 = $this->supplierWith('112');
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '2020-01-01', 'cz_504')")
            ->execute([$sro504]);

        $matrix = [];
        foreach (['sro' => $sro, 'svj' => $svj, 'spolek' => $spolek, 'sro504' => $sro504] as $name => $id) {
            foreach ([FrameworkScopePolicy::SVJ, FrameworkScopePolicy::NONPROFIT_504, FrameworkScopePolicy::CONVERSION_504] as $scope) {
                $matrix[$name][$scope] = $policy->allows($id, $scope);
            }
        }

        self::assertSame([
            'sro' => ['svj' => false, 'nonprofit504' => false, 'conversion504' => false],
            'svj' => ['svj' => true, 'nonprofit504' => false, 'conversion504' => true],
            'spolek' => ['svj' => false, 'nonprofit504' => true, 'conversion504' => true],
            'sro504' => ['svj' => false, 'nonprofit504' => true, 'conversion504' => true],
        ], $matrix);
        self::assertFalse($policy->allows(0, FrameworkScopePolicy::SVJ));
    }

    public function testExplicitPropertyProfileOpensSvjScope(): void
    {
        $policy = $this->container->get(FrameworkScopePolicy::class);
        $sro = $this->supplierWith('112');
        self::assertFalse($policy->allows($sro, FrameworkScopePolicy::SVJ));
        $actor = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_504')")
            ->execute([$sro]);
        (new PropertyProfileRepository($this->db))->save($sro, ['expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00+01:00',
            'enabled' => true, 'reason' => 'Synthetic SVJ', 'title_ref' => 'Synthetic deed', 'idempotency_key' => 'profile'], $actor);
        self::assertTrue($policy->allows($sro, FrameworkScopePolicy::SVJ));
    }

    public function testPermissionMiddlewareEnforcesTheScope(): void
    {
        $middleware = $this->container->get(PermissionMiddleware::class);
        $property = new \ReflectionProperty($middleware, 'frameworkScope');
        self::assertInstanceOf(FrameworkScopePolicy::class, $property->getValue($middleware),
            'Bez předání politiky v Bootstrapu by API agendy SVJ a NNO dál pustilo každé firmě.');
    }

    private function supplierWith(string $legalForm): int
    {
        $id = $this->createSupplier('double_entry', false, false);
        $this->db->pdo()->prepare('UPDATE supplier SET legal_form = ? WHERE id = ?')->execute([$legalForm, $id]);
        return $id;
    }
}
