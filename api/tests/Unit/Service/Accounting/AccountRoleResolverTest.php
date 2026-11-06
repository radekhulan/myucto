<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Accounting\AccountRoleResolver;
use MyInvoice\Service\Accounting\AccountingFrameworkException;
use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\FrameworkOperationPolicy;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\DeferredTaxService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccountRoleResolverTest extends TestCase
{
    private function connection(): Connection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE supplier_accounting_frameworks (id INTEGER PRIMARY KEY, supplier_id INTEGER, effective_from TEXT, accounting_framework TEXT)');
        $pdo->exec("INSERT INTO supplier_accounting_frameworks VALUES (1, 7, '2025-07-01', 'cz_500'), (2, 7, '2026-07-01', 'cz_504'), (3, 8, '2025-07-01', 'cz_504')");
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        return $db;
    }

    public function testHistoricalDateAndTenantChooseRoles(): void
    {
        $resolver = AccountRoleResolver::fromConnection($this->connection());
        self::assertSame('701', $resolver->resolve(7, 'closing.opening_balance', '2026-06-30'));
        self::assertSame('961', $resolver->resolve(7, 'closing.opening_balance', '2026-07-01'));
        self::assertSame('933', $resolver->resolve(8, 'result.current', '2025-12-31'));
        self::assertSame('710', $resolver->resolve(99, 'result.current', '2026-07-01'));
        self::assertSame('932', $resolver->resolve(7, 'result.retained_profit', '2026-07-01'));
        self::assertSame('932', $resolver->resolve(7, 'result.retained_loss', '2026-07-01'));
    }

    public function testCrossFrameworkOpeningIsRejected(): void
    {
        try {
            (new AccountingRoleProfile())->assertCompatibleOpening(new AccountingRoleProfile('cz_504'));
            self::fail('Transition must not mirror incompatible charts.');
        } catch (AccountingFrameworkException $e) {
            self::assertSame('accounting_framework_transition_not_supported', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testFinancialRolesRespectHistoricalFrameworkAndLegacyOverrides(): void
    {
        $resolver = AccountRoleResolver::fromConnection($this->connection());
        foreach (['fx.loss' => ['563', '545'], 'fx.gain' => ['663', '645'], 'bank.fee' => ['568', '549.100'],
            'interest.expense' => ['562', '544'], 'interest.income' => ['662', '644'],
            'rounding.loss' => ['548', '549'], 'rounding.gain' => ['648', '649']] as $role => [$legacy, $nonprofit]) {
            self::assertSame($legacy, $resolver->resolve(7, $role, '2026-06-30'));
            self::assertSame($legacy . '.123', $resolver->resolve(7, $role, '2026-06-30', $legacy . '.123'));
            self::assertSame($nonprofit, $resolver->resolve(7, $role, '2026-07-01', $legacy . '.123'));
        }
    }

    public function testLegacyOverrideCannotHideUnknownRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AccountingRoleProfile())->account('unknown.role', '563');
    }

    public function testLegacyFalseyOverrideKeepsOriginalFallback(): void
    {
        foreach ([null, '', '0'] as $override) {
            self::assertSame('563', (new AccountingRoleProfile())->account('fx.loss', $override));
        }
    }

    public function testExplicitSettingsArePreservedFor500AndRejectedWithout504Binding(): void
    {
        self::assertSame('568.123', (new AccountingRoleProfile())->configuredAccount('bank.fee', 10, '568.123'));
        self::assertSame('549.100', (new AccountingRoleProfile('cz_504'))->configuredAccount('bank.fee', null, '568'));
        $this->expectException(AccountingFrameworkException::class);
        (new AccountingRoleProfile('cz_504'))->configuredAccount('bank.fee', 10, '549.100');
    }

    public function testLegalOperationsRequireExplicitTaxableLegalContext(): void
    {
        $profile = new AccountingRoleProfile('cz_504');
        foreach (['reserve.legal', 'provision.legal'] as $operation) {
            self::assertFalse(FrameworkOperationPolicy::allows($profile, $operation));
            self::assertFalse(FrameworkOperationPolicy::allows($profile, $operation, ['taxable_activity' => true]));
            self::assertFalse(FrameworkOperationPolicy::allows($profile, $operation, ['legal_basis' => 'synthetic legal title']));
            self::assertTrue(FrameworkOperationPolicy::allows($profile, $operation, ['taxable_activity' => true, 'legal_basis' => 'synthetic legal title']));
        }
        foreach (['deferred_tax', 'reserve.other', 'reserve.income_tax', 'provision.accounting'] as $operation) {
            self::assertFalse(FrameworkOperationPolicy::allows($profile, $operation, ['taxable_activity' => true, 'legal_basis' => 'synthetic legal title']));
            self::assertTrue(FrameworkOperationPolicy::allows(new AccountingRoleProfile(), $operation));
        }
    }

    public function testDirectDeferredTaxConsumerRejects504BeforeComputing500Rules(): void
    {
        $constants = $this->createMock(TaxConstantsRepository::class);
        $constants->expects(self::never())->method('forYear');
        $service = new DeferredTaxService($this->connection(), $constants);
        try {
            $service->compute(7, 2026, '2027-06-30');
            self::fail('504 must not run the 500 deferred tax calculation.');
        } catch (ClosingException $e) {
            self::assertSame('accounting_framework_operation_not_supported', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }
}
