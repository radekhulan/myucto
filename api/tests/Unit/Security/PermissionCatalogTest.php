<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use PHPUnit\Framework\TestCase;

final class PermissionCatalogTest extends TestCase
{
    public function testDefinitionsAreUniqueAndInternallyValid(): void
    {
        $catalog = new PermissionCatalog();
        $all = $catalog->all();
        self::assertNotEmpty($all);
        self::assertSame(array_keys($all), array_column($all, 'key'));
        foreach ($all as $key => $definition) {
            self::assertContains($definition['group'], $catalog->groups(), $key);
            self::assertNotEmpty($definition['role_types'], $key);
            self::assertEmpty(array_diff($definition['role_types'], ['staff', 'client']), $key);
        }
    }

    public function testCheckerIsFailClosedAndWriteImpliesRead(): void
    {
        $checker = new PermissionChecker(new PermissionCatalog());
        $role = new EffectiveRole(2, 'Účetní', 'staff', true, ['invoices' => 2]);
        self::assertTrue($checker->allows($role, 'invoices', AccessLevel::READ));
        self::assertTrue($checker->allows($role, 'invoices', AccessLevel::WRITE));
        self::assertFalse($checker->allows($role, 'invoices.delete', AccessLevel::READ));
        self::assertFalse($checker->allows($role, 'unknown.permission', AccessLevel::READ));
        self::assertFalse($checker->allows(new EffectiveRole(2, 'Off', 'staff', false, ['invoices' => 2]), 'invoices'));
    }

    public function testClientCannotUseStaffOnlyPermissionEvenWithCorruptMatrixRow(): void
    {
        $checker = new PermissionChecker(new PermissionCatalog());
        $client = new EffectiveRole(4, 'Klient', 'client', true, ['accounting' => 2, 'invoices' => 1]);
        self::assertFalse($checker->allows($client, 'accounting'));
        self::assertTrue($checker->allows($client, 'invoices'));
    }

    public function testDocumentSubmissionPermissionsAreRoleSeparated(): void
    {
        $catalog = new PermissionCatalog();
        $definitions = $catalog->all();
        self::assertSame(['staff'], $definitions['documents.inbox']['role_types']);
        self::assertSame(['client'], $definitions['documents.submit']['role_types']);

        $checker = new PermissionChecker($catalog);
        $client = new EffectiveRole(4, 'Klient', 'client', true, $catalog->legacyPreset('client'));
        $accountant = new EffectiveRole(2, 'Účetní', 'staff', true, $catalog->legacyPreset('accountant'));
        self::assertTrue($checker->allows($client, 'documents.submit', AccessLevel::WRITE));
        self::assertFalse($checker->allows($client, 'documents.inbox'));
        self::assertTrue($checker->allows($accountant, 'documents.inbox', AccessLevel::WRITE));
        self::assertFalse($checker->allows($accountant, 'documents.submit'));
    }

    /** Trvalé vyřazení originálu je samostatné právo, ne vlastnost účetní role. */
    public function testInboxDeleteIsSeparateAndNotGrantedByDefault(): void
    {
        $catalog = new PermissionCatalog();
        self::assertSame(['staff'], $catalog->all()['documents.inbox.delete']['role_types']);

        $checker = new PermissionChecker($catalog);
        $accountant = new EffectiveRole(2, 'Účetní', 'staff', true, $catalog->legacyPreset('accountant'));
        $readonly = new EffectiveRole(3, 'Jen čtení', 'staff', true, $catalog->legacyPreset('readonly'));
        self::assertFalse($checker->allows($accountant, 'documents.inbox.delete'),
            'Účetní frontu obsluhuje, ale originál sama trvale nevyřazuje — právo jí přidělí správce.');
        self::assertFalse($checker->allows($readonly, 'documents.inbox.delete'));

        // Role s výslovně přiděleným právem ho má — a jde tedy i zkopírovat do dalších rolí.
        $custom = new EffectiveRole(9, 'Správce podatelny', 'staff', true, [
            'documents.inbox' => AccessLevel::WRITE->value,
            'documents.inbox.delete' => AccessLevel::WRITE->value,
        ]);
        self::assertTrue($checker->allows($custom, 'documents.inbox.delete', AccessLevel::WRITE));
    }

    public function testSuperadminBypassesMatrix(): void
    {
        $checker = new PermissionChecker(new PermissionCatalog());
        $superadmin = new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin');
        self::assertTrue($checker->allows($superadmin, 'invoices.delete', AccessLevel::WRITE));
    }

    public function testTenantTransferIsStaffOnlyAndNotGrantedToAccountantByDefault(): void
    {
        $catalog = new PermissionCatalog();
        $checker = new PermissionChecker($catalog);
        $accountant = new EffectiveRole(
            2,
            'Účetní',
            'staff',
            true,
            $catalog->legacyPreset('accountant'),
        );
        self::assertSame(['staff'], $catalog->all()['tenant.transfer.export']['role_types']);
        self::assertFalse($checker->allows($accountant, 'tenant.transfer.export'));

        $transferAdmin = new EffectiveRole(9, 'Správce přenosů', 'staff', true, [
            'tenant.transfer.export' => AccessLevel::WRITE->value,
        ]);
        self::assertTrue($checker->allows(
            $transferAdmin,
            'tenant.transfer.export',
            AccessLevel::WRITE,
        ));
    }

    /**
     * Výmaz osobních údajů je nevratný, proto ho výchozí účetní role NEMÁ —
     * stejně jako schválení běhu. Retenční lhůty naopak ano: prodloužit lhůtu
     * nebo zadržet výmaz je konzervativní směr, kterým se žádná data neztratí.
     */
    public function testPayrollErasureIsNotInTheDefaultAccountantPreset(): void
    {
        $catalog = new PermissionCatalog();
        $checker = new PermissionChecker($catalog);
        $accountant = new EffectiveRole(2, 'Účetní', 'staff', true, $catalog->legacyPreset('accountant'));

        self::assertFalse($checker->allows($accountant, 'payroll.erasure'));
        self::assertTrue($checker->allows($accountant, 'payroll.retention', AccessLevel::WRITE));

        // Vlastní role je fail-closed: bez výslovného přidělení právo nemá.
        $custom = new EffectiveRole(9, 'Mzdová účetní', 'staff', true, [
            'payroll' => AccessLevel::WRITE->value,
            'payroll.retention' => AccessLevel::WRITE->value,
        ]);
        self::assertFalse($checker->allows($custom, 'payroll.erasure'));

        $dpo = new EffectiveRole(10, 'Správce osobních údajů', 'staff', true, [
            'payroll.erasure' => AccessLevel::WRITE->value,
        ]);
        self::assertTrue($checker->allows($dpo, 'payroll.erasure', AccessLevel::WRITE));
        self::assertFalse($checker->allows($dpo, 'payroll.retention'));
    }
}
