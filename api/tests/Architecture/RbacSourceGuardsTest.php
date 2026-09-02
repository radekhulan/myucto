<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RbacSourceGuardsTest extends TestCase
{
    /**
     * Tyto soubory tvoří úzkou hranici, kde je identita systémové role součástí
     * načtení/serializace role nebo ochrany neměnného superadmin preset-u.
     * Běžné autorizační rozhodování musí používat permission klíče.
     *
     * @var list<string>
     */
    private const BACKEND_ROLE_IDENTITY_BOUNDARY = [
        'Action/Admin/UserAdminAction.php',
        'Action/Admin/UserSupplierAdminAction.php',
        'Action/Auth/LoginAction.php',
        'Middleware/AuthMiddleware.php',
        'Repository/RoleRepository.php',
        // Rozhoduje, kdo zabírá licenční MÍSTO. Plný přístup superadmina je
        // implicitní — v `role_permissions` nemá ani řádek — takže se identita
        // té role číst musí, jinak by se jediný účet, který instalaci opravdu
        // ovládá, do počtu nikdy nezapočítal.
        'Service/License/SeatPolicy.php',
        'Security/EffectiveRole.php',
        'Security/PermissionCatalog.php',
        'Security/PermissionResolver.php',
        'Security/RequestAuthorization.php',
        'Security/UserRoleProfile.php',
        'Service/Tenant/SupplierAccessResolver.php',
    ];

    /**
     * Záměrné kompatibilní adaptéry pro middleware-less testy a starší volající.
     * Výjimka je omezená na konkrétní symbol, aby nezakrývala nový kód ve stejném souboru.
     *
     * @var array<string, list<string>>
     */
    private const BACKEND_ROLE_IDENTITY_METHOD_BOUNDARY = [
        'Repository/DocumentViewerContext.php' => ['fromRole'],
    ];

    /**
     * Správa rolí a uživatelů musí zobrazit typ role a chránit systémový
     * superadmin preset; auth store převádí typ role na klientský UX režim.
     * Nejde o permission guard zápisové akce.
     *
     * @var list<string>
     */
    private const FRONTEND_ROLE_IDENTITY_BOUNDARY = [
        'pages/admin/Roles.vue',
        'pages/admin/Users.vue',
        'stores/auth.ts',
    ];

    public function testNoNewHardcodedBackendRoleAuthorizationChecksAreIntroduced(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $violations = [];
        foreach (self::phpFiles($src) as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($src) + 1));
            if (in_array($relative, self::BACKEND_ROLE_IDENTITY_BOUNDARY, true)) continue;
            $method = null;
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
                if (preg_match('/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $methodMatch) === 1) {
                    $method = $methodMatch[1];
                }
                if (in_array($method, self::BACKEND_ROLE_IDENTITY_METHOD_BOUNDARY[$relative] ?? [], true)) continue;
                if (!preg_match('/(?:===|!==|==|!=|in_array\s*\()[^;]*(?:[\'\"](?:admin|superadmin|accountant|readonly|client)[\'\"])/', $line)) continue;
                if (!preg_match('/\b(?:role|role_type|system_key|is_superadmin|legacy)\b/i', $line)) continue;
                $violations[] = $relative . ':' . ($index + 1) . ' ' . trim($line);
            }
        }

        self::assertSame([], $violations, 'Autorizační role-check musí používat PermissionResolver/PermissionChecker; výjimky patří jen na hranici identity role.');
    }

    public function testFrontendDoesNotAuthorizeByLegacyRoleName(): void
    {
        $src = dirname(__DIR__, 3) . '/web/src';
        $violations = [];
        foreach (self::sourceFiles($src, ['ts', 'vue']) as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($src) + 1));
            if (in_array($relative, self::FRONTEND_ROLE_IDENTITY_BOUNDARY, true)) continue;
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
                if (!preg_match('/(?:===|!==|==|!=|includes\s*\(|in_array\s*\()[^;]*(?:[\'\"](?:admin|superadmin|accountant|readonly|client)[\'\"])/', $line)) continue;
                if (!preg_match('/\b(?:role|role_type|system_key|is_superadmin)\b/i', $line)) continue;
                $violations[] = $relative . ':' . ($index + 1) . ' ' . trim($line);
            }
        }

        self::assertSame([], $violations, 'Frontend smí autorizovat jen přes auth.can() nebo explicitní isSuperadmin hranici, ne podle názvu legacy role.');
    }

    public function testConventionallyNamedWriteRoutesRequireWritePermission(): void
    {
        $router = file_get_contents(dirname(__DIR__, 3) . '/web/src/router/index.ts');
        self::assertNotFalse($router);
        preg_match('/const routePermissions:[^{]+\{(?<map>.*?)\n\}/s', $router, $mapMatch);
        preg_match_all('/[\'\"]?(?<name>[a-z0-9-]+)[\'\"]?\s*:\s*\[\s*[\'\"](?<key>[a-z0-9_.]+)[\'\"](?:\s*,\s*[\'\"](?<access>read|write)[\'\"])?\s*]/', $mapMatch['map'] ?? '', $entries, PREG_SET_ORDER);
        $permissions = [];
        foreach ($entries as $entry) $permissions[$entry['name']] = ($entry['access'] ?? '') ?: 'read';

        preg_match_all('/name:\s*[\'\"](?<name>[a-z0-9-]+)[\'\"][^\r\n]*component:/', $router, $routes);
        $missing = [];
        foreach (array_unique($routes['name']) as $name) {
            if (preg_match('/(?:-new|-edit)$/', $name) !== 1) continue;
            if (($permissions[$name] ?? null) !== 'write') $missing[] = $name;
        }

        self::assertSame([], $missing, 'Každá formulářová write route (*-new/*-edit) musí mít permission s access=write.');
    }

    public function testLastSuperadminGuardIsSerializedInDatabaseTransaction(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Action/Admin/UserAdminAction.php');
        $capacityGate = file_get_contents(dirname(__DIR__, 2) . '/src/Service/License/LicenseCapacityGate.php');
        self::assertNotFalse($source);
        self::assertNotFalse($capacityGate);
        self::assertStringContainsString('capacity->mutateSeats(', $source);
        self::assertStringContainsString('ORDER BY u.id FOR UPDATE', $source);
        self::assertStringContainsString('guardedUserUpdate(', $source);
        self::assertStringContainsString('GET_LOCK(', $capacityGate);
        self::assertStringContainsString('SELECT DATABASE()', $capacityGate);
        self::assertStringContainsString("hash('sha256', \$database)", $capacityGate);
        self::assertStringContainsString('beginTransaction()', $capacityGate);
        self::assertStringContainsString('rollBack()', $capacityGate);
        self::assertStringContainsString('commit()', $capacityGate);
    }

    public function testLicenseCapacityMutationsUseSingleDatabaseGate(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $roleAdmin = file_get_contents($src . '/Action/Admin/RoleAdminAction.php');
        $userAdmin = file_get_contents($src . '/Action/Admin/UserAdminAction.php');
        $userSuppliers = file_get_contents($src . '/Action/Admin/UserSupplierAdminAction.php');
        $settings = file_get_contents($src . '/Action/Settings/SettingsAction.php');
        $capacityGate = file_get_contents($src . '/Service/License/LicenseCapacityGate.php');

        self::assertIsString($roleAdmin);
        self::assertIsString($userAdmin);
        self::assertIsString($userSuppliers);
        self::assertIsString($settings);
        self::assertIsString($capacityGate);
        self::assertStringContainsString('capacity->mutateSeats(', $roleAdmin);
        self::assertGreaterThanOrEqual(2, substr_count($userAdmin, 'capacity->mutateSeats('));
        self::assertStringContainsString('capacity->mutateSeats(', $userSuppliers);
        self::assertStringContainsString('licenseCapacity->createCompany(', $settings);
        self::assertStringContainsString('countActiveSeats()', $capacityGate);
        self::assertStringContainsString('withActiveCompanies(', $capacityGate);
    }

    /** @return list<string> */
    private static function phpFiles(string $root): array
    {
        return self::sourceFiles($root, ['php']);
    }

    /** @param list<string> $extensions @return list<string> */
    private static function sourceFiles(string $root, array $extensions): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $extensions, true)) continue;
            $files[] = $file->getPathname();
        }
        sort($files);
        return $files;
    }
}
