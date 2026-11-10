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
    public function testInvoiceDefaultsAreExplicitlyDelegatedAndNotGrantedToAccountants(): void
    {
        $catalog = new PermissionCatalog();
        self::assertTrue($catalog->allowsRoleType('settings.invoice_defaults', 'staff'));
        self::assertTrue($catalog->allowsRoleType('settings.invoice_defaults', 'client'));
        self::assertArrayNotHasKey('settings.invoice_defaults', $catalog->legacyPreset('accountant'));
        self::assertArrayNotHasKey('settings.invoice_defaults', $catalog->legacyPreset('client'));
    }

    public function testReadOnlyPresetContainsNoWriteIncludingProfile(): void
    {
        $preset = (new PermissionCatalog())->legacyPreset('readonly');
        self::assertSame(1, $preset['profile']);
        self::assertSame(1, $preset['profile.tokens']);
        foreach ($preset as $key => $level) self::assertLessThan(2, $level, $key);
    }

    public function testClientBankPermissionsAreOptInAndActionChecksRequireRead(): void
    {
        $catalog = new PermissionCatalog();
        $checker = new PermissionChecker($catalog);
        foreach (['bank', 'bank.import', 'bank.match', 'bank.ignore'] as $key) {
            self::assertTrue($catalog->allowsRoleType($key, 'client'));
            self::assertArrayNotHasKey($key, $catalog->legacyPreset('client'));
        }
        foreach (['bank.import', 'bank.match', 'bank.ignore'] as $key) {
            foreach (['staff', 'client'] as $type) {
                $role = new EffectiveRole(9, 'Synthetic role', $type, true, [$key => 2]);
                self::assertSame($type === 'staff', $checker->allows($role, $key, AccessLevel::WRITE));
                $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/')
                    ->withAttribute('auth.effective_role', $role);
                self::assertSame($type === 'staff', \MyInvoice\Security\RequestAuthorization::allows($request, $key, AccessLevel::WRITE));
            }
        }
    }

    public static function disallowedRequestGrants(): array
    {
        return [
            'client accounting' => ['client', 'accounting'],
            'client lifecycle' => ['client', 'property.settlements.post'],
            'unknown permission' => ['staff', 'synthetic.unknown.permission'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('disallowedRequestGrants')]
    public function testBrowserChecksRejectCatalogDisallowedGrants(string $type, string $key): void
    {
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/')
            ->withAttribute('auth.effective_role', new EffectiveRole(99, 'Synthetic inconsistent role', $type, true, [$key => 2]));
        self::assertFalse(\MyInvoice\Security\RequestAuthorization::allows($request, $key, AccessLevel::WRITE));
    }

    public function testPriceListCanBeDelegatedWithoutGivingItToClientsByDefault(): void
    {
        $catalog = new PermissionCatalog();
        self::assertTrue($catalog->allowsRoleType('price_list', 'staff'));
        self::assertTrue($catalog->allowsRoleType('price_list', 'client'));
        self::assertSame(1, $catalog->legacyPreset('accountant')['price_list']);
        self::assertSame(1, $catalog->legacyPreset('readonly')['price_list']);
        self::assertArrayNotHasKey('price_list', $catalog->legacyPreset('client'));
    }

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
        self::assertSame(['staff', 'client'], $definitions['documents.submit']['role_types']);

        $checker = new PermissionChecker($catalog);
        $client = new EffectiveRole(4, 'Klient', 'client', true, $catalog->legacyPreset('client'));
        $accountant = new EffectiveRole(2, 'Účetní', 'staff', true, $catalog->legacyPreset('accountant'));
        self::assertTrue($checker->allows($client, 'documents.submit', AccessLevel::WRITE));
        self::assertFalse($checker->allows($client, 'documents.inbox'));
        self::assertTrue($checker->allows($accountant, 'documents.inbox', AccessLevel::WRITE));
        self::assertFalse($checker->allows($accountant, 'documents.submit'));
        // Předávání dokladů je oprávnění, ne typ role: interní role ho dostane výslovně.
        $delegated = new EffectiveRole(6, 'Fakturace a dokumenty', 'staff', true, ['documents.submit' => 2]);
        self::assertTrue($checker->allows($delegated, 'documents.submit', AccessLevel::WRITE));
        self::assertFalse($checker->allows($delegated, 'documents.inbox'));
    }

    public function testClientScopePermissionsCanBeGrantedToStaffButNotToDefaultRoles(): void
    {
        $catalog = new PermissionCatalog();
        $repository = new \MyInvoice\Repository\RoleRepository($this->createStub(\MyInvoice\Infrastructure\Database\Connection::class), $catalog);
        $validate = new \ReflectionMethod($repository, 'validatePermissions');
        foreach (['documents.submit', 'reports.submissions', 'settings.signing_profiles'] as $key) {
            self::assertTrue($catalog->allowsRoleType($key, 'staff'), $key);
            self::assertTrue($catalog->allowsRoleType($key, 'client'), $key);
            self::assertArrayHasKey($key, $validate->invoke($repository, 'staff', [$key => 2]), $key);
            foreach (['accountant', 'readonly'] as $preset) {
                self::assertArrayNotHasKey($key, $catalog->legacyPreset($preset), "$preset $key");
            }
        }
        self::assertArrayHasKey('documents.submit', $catalog->legacyPreset('client'));
        self::assertArrayNotHasKey('reports.submissions', $catalog->legacyPreset('client'));
        self::assertArrayNotHasKey('settings.signing_profiles', $catalog->legacyPreset('client'));
        // Archiv podání zůstává jen ke čtení a interní roli se k odeslání nedoplňuje.
        self::assertSame(['reports.submissions' => 1], $validate->invoke($repository, 'staff', ['reports.submissions' => 2]));
        self::assertSame(['reports.submit' => 2], $validate->invoke($repository, 'staff', ['reports.submit' => 2]));
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

    public function testFixedCompanyAdminCapabilitiesAreDistinctFromSuperadmin(): void
    {
        $admin = new EffectiveRole(2, 'Admin', 'staff', true, [], 'admin');
        $adminPlus = new EffectiveRole(3, 'Admin Plus', 'staff', true, [], 'admin_plus');

        self::assertTrue($admin->isCompanyAdmin());
        self::assertFalse($admin->isSuperadmin());
        self::assertFalse($admin->canCreateSupplier());
        self::assertTrue($adminPlus->isCompanyAdmin());
        self::assertFalse($adminPlus->isSuperadmin());
        self::assertTrue($adminPlus->canCreateSupplier());
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

    public function testHealthEvidenceIsStaffOnlyAndNotGrantedToTheDefaultAccountant(): void
    {
        $catalog = new PermissionCatalog();
        self::assertSame(['staff'], $catalog->all()['payroll.health_evidence']['role_types']);

        $checker = new PermissionChecker($catalog);
        $accountant = new EffectiveRole(2, 'Účetní', 'staff', true, $catalog->legacyPreset('accountant'));
        self::assertFalse($checker->allows($accountant, 'payroll.health_evidence'));

        $healthOfficer = new EffectiveRole(3, 'Zdravotní důkazy', 'staff', true, [
            'payroll.health_evidence' => AccessLevel::WRITE->value,
        ]);
        self::assertTrue($checker->allows($healthOfficer, 'payroll.health_evidence', AccessLevel::READ));
    }

    /**
     * Úprava vystavené faktury je samostatné interní právo. Správce ho má vždy, výchozí
     * účetní ne; vlastní interní roli ho přidělí správce v editoru rolí. Klient ho
     * nedostane ani poškozeným řádkem matice.
     */
    public function testEditIssuedInvoiceIsStaffOnlyAndGrantedExplicitly(): void
    {
        $catalog = new PermissionCatalog();
        self::assertSame(['staff'], $catalog->all()['invoices.edit_issued']['role_types']);
        self::assertArrayNotHasKey('invoices.edit_issued', $catalog->legacyPreset('accountant'));
        self::assertArrayNotHasKey('invoices.edit_issued', $catalog->legacyPreset('client'));

        $request = static fn (EffectiveRole $role) => (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('PUT', '/')
            ->withAttribute('auth.effective_role', $role);
        $canEdit = static fn (EffectiveRole $role): bool
            => \MyInvoice\Security\RequestAuthorization::canEditIssuedInvoice($request($role));

        self::assertTrue($canEdit(new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin')));
        self::assertTrue($canEdit(new EffectiveRole(2, 'Admin', 'staff', true, [], 'admin')));
        self::assertTrue($canEdit(new EffectiveRole(3, 'Admin Plus', 'staff', true, [], 'admin_plus')));
        self::assertFalse($canEdit(new EffectiveRole(4, 'Účetní', 'staff', true, $catalog->legacyPreset('accountant'), 'accountant')));
        self::assertTrue($canEdit(new EffectiveRole(5, 'Fakturantka', 'staff', true, [
            'invoices' => AccessLevel::WRITE->value,
            'invoices.edit_issued' => AccessLevel::WRITE->value,
        ])));
        self::assertFalse($canEdit(new EffectiveRole(6, 'Jen čtení', 'staff', true, [
            'invoices.edit_issued' => AccessLevel::READ->value,
        ])), 'Úprava je zápis, čtení nestačí.');
        self::assertFalse($canEdit(new EffectiveRole(7, 'Vypnutá', 'staff', false, [
            'invoices.edit_issued' => AccessLevel::WRITE->value,
        ])));
        self::assertFalse($canEdit(new EffectiveRole(8, 'Klient', 'client', true, [
            'invoices.edit_issued' => AccessLevel::WRITE->value,
        ])), 'Klientská role právo nedostane ani poškozeným řádkem.');

        // Finanční úprava zaúčtované faktury přeúčtuje deník: neadmin k ní potřebuje i právo zaúčtovat.
        $canRepost = static fn (EffectiveRole $role): bool
            => \MyInvoice\Security\RequestAuthorization::canRepostIssuedInvoice($request($role));
        self::assertTrue($canRepost(new EffectiveRole(2, 'Admin', 'staff', true, [], 'admin')));
        self::assertFalse($canRepost(new EffectiveRole(5, 'Fakturantka', 'staff', true, [
            'invoices.edit_issued' => AccessLevel::WRITE->value,
        ])));
        self::assertTrue($canRepost(new EffectiveRole(9, 'Fakturantka a účetní', 'staff', true, [
            'invoices.edit_issued' => AccessLevel::WRITE->value,
            'accounting.journal.post' => AccessLevel::WRITE->value,
        ])));
        self::assertFalse($canRepost(new EffectiveRole(10, 'Jen zaúčtování', 'staff', true, [
            'accounting.journal.post' => AccessLevel::WRITE->value,
        ])), 'Bez práva upravit vystavenou fakturu nic nepřeúčtuje.');

        // Podané DPH období mění jen správce, právo upravit vystavenou fakturu ani zaúčtovat nestačí.
        $canChangeFiled = static fn (EffectiveRole $role): bool
            => \MyInvoice\Security\RequestAuthorization::canChangeFiledVatPeriod($request($role));
        self::assertTrue($canChangeFiled(new EffectiveRole(2, 'Admin', 'staff', true, [], 'admin')));
        self::assertFalse($canChangeFiled(new EffectiveRole(9, 'Fakturantka a účetní', 'staff', true, [
            'invoices.edit_issued' => AccessLevel::WRITE->value,
            'accounting.journal.post' => AccessLevel::WRITE->value,
        ])));
    }

    /**
     * Oprava DPH zařazení přijaté faktury je běžná práce účetní: výchozí účetní právo má,
     * jen pro čtení a klient ne. Přeúčtování zaúčtovaného dokladu chce navíc právo zaúčtovat.
     */
    public function testPurchaseVatTreatmentBelongsToAccountant(): void
    {
        $catalog = new PermissionCatalog();
        self::assertSame(['staff'], $catalog->all()['purchase_invoices.vat_treatment']['role_types']);
        self::assertSame(AccessLevel::WRITE->value, $catalog->legacyPreset('accountant')['purchase_invoices.vat_treatment'] ?? null);
        self::assertArrayNotHasKey('purchase_invoices.vat_treatment', $catalog->legacyPreset('readonly'));
        self::assertArrayNotHasKey('purchase_invoices.vat_treatment', $catalog->legacyPreset('client'));

        $request = static fn (EffectiveRole $role) => (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', '/')
            ->withAttribute('auth.effective_role', $role);
        $can = static fn (EffectiveRole $role): bool
            => \MyInvoice\Security\RequestAuthorization::canChangePurchaseVatTreatment($request($role));
        $canRepost = static fn (EffectiveRole $role): bool
            => \MyInvoice\Security\RequestAuthorization::canRepostPurchaseVatTreatment($request($role));

        $accountant = new EffectiveRole(4, 'Účetní', 'staff', true, $catalog->legacyPreset('accountant'), 'accountant');
        self::assertTrue($can(new EffectiveRole(2, 'Admin', 'staff', true, [], 'admin')));
        self::assertTrue($can($accountant));
        self::assertTrue($canRepost($accountant));
        self::assertFalse($can(new EffectiveRole(5, 'Jen čtení', 'staff', true, $catalog->legacyPreset('readonly'), 'readonly')));
        self::assertFalse($can(new EffectiveRole(6, 'Klient', 'client', true, [
            'purchase_invoices.vat_treatment' => AccessLevel::WRITE->value,
        ])), 'Klientská role právo nedostane ani poškozeným řádkem.');
        self::assertFalse($canRepost(new EffectiveRole(7, 'Jen DPH zařazení', 'staff', true, [
            'purchase_invoices.vat_treatment' => AccessLevel::WRITE->value,
        ])), 'Přeúčtování chce i právo zaúčtovat.');
        self::assertFalse(\MyInvoice\Security\RequestAuthorization::canChangeFiledVatPeriod($request($accountant)),
            'Podané období DPH mění jen správce.');
    }
    public function testClientMcpPermissionIsOptInAndDoesNotElevateLegacyAccountant(): void
    {
        $catalog = new \MyInvoice\Security\PermissionCatalog();
        self::assertArrayNotHasKey('profile.mcp', $catalog->legacyPreset('client'));
        self::assertArrayNotHasKey('profile.mcp', $catalog->legacyPreset('accountant'));
        self::assertSame(1, $catalog->legacyPreset('accountant')['profile.tokens']);
        self::assertTrue($catalog->allowsRoleType('profile.mcp', 'client'));
    }


    /** Vrácení importu historie skladu je rozhodnutí správce, právo zapisovat skladové doklady nestačí. */
    public function testStockHistoryRollbackIsCompanyAdminOnly(): void
    {
        $request = static fn (EffectiveRole $role) => (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', '/')
            ->withAttribute('auth.effective_role', $role);
        $can = static fn (EffectiveRole $role): bool
            => \MyInvoice\Security\RequestAuthorization::canRollbackStockHistoryImport($request($role));

        self::assertTrue($can(new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin')));
        self::assertTrue($can(new EffectiveRole(2, 'Admin', 'staff', true, [], 'admin')));
        self::assertFalse($can(new EffectiveRole(3, 'Skladník', 'staff', true, [
            'stock' => AccessLevel::WRITE->value,
            'stock.documents.write' => AccessLevel::WRITE->value,
        ])));
    }
}
