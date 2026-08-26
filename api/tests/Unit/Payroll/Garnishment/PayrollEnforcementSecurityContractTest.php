<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Garnishment;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\TestCase;

final class PayrollEnforcementSecurityContractTest extends TestCase
{
    public function testReadAndWriteRoutesRequireDedicatedEnforcementPermission(): void
    {
        $map = new RoutePermissionMap();

        $read = $map->match('GET', '/api/payroll/enforcement/cases/17');
        self::assertNotNull($read);
        self::assertSame('payroll.enforcement', $read->key);
        self::assertSame(AccessLevel::READ, $read->minimum);

        $write = $map->match(
            'POST',
            '/api/payroll/enforcement/cases/17/commands/mark_final',
        );
        self::assertNotNull($write);
        self::assertSame('payroll.enforcement', $write->key);
        self::assertSame(AccessLevel::WRITE, $write->minimum);

        foreach (['PUT', 'DELETE'] as $method) {
            $claimMutation = $map->match(
                $method,
                '/api/payroll/enforcement/cases/17/claims/23',
            );
            self::assertNotNull($claimMutation);
            self::assertSame('payroll.enforcement', $claimMutation->key);
            self::assertSame(AccessLevel::WRITE, $claimMutation->minimum);
        }
    }

    public function testRepositoryQueriesCarrySupplierScope(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Repository/Payroll/PayrollEnforcementRepository.php',
        );
        self::assertIsString($source);
        self::assertStringNotContainsString('WHERE id = ?', $source);
        self::assertGreaterThanOrEqual(18, substr_count($source, 'supplier_id = ?'));
        self::assertStringContainsString(
            'WHERE supplier_id = ? AND id = ? FOR UPDATE',
            $source,
        );
    }

    public function testClaimMutationRoutesAreRegistered(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/Routes.php');
        self::assertIsString($source);
        self::assertStringContainsString(
            "'/enforcement/cases/{id:[0-9]+}/claims/{claimId:[0-9]+}'",
            $source,
        );
        self::assertStringContainsString(
            "[PayrollEnforcementAction::class, 'updateClaim']",
            $source,
        );
        self::assertStringContainsString(
            "[PayrollEnforcementAction::class, 'deleteClaim']",
            $source,
        );
    }

    public function testApiDoesNotExposeOpaqueCaseOrClaimKeys(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Repository/Payroll/PayrollEnforcementRepository.php',
        );
        self::assertIsString($source);
        self::assertStringContainsString(
            "unset(\$case['case_key'], \$case['created_by'], \$case['updated_by']);",
            $source,
        );
        self::assertStringNotContainsString(
            'SELECT id, case_id, claim_key',
            $source,
        );
    }
}
