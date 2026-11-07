<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Nonprofit\Membership\MembershipBatchService;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/** Bez DB: provozní konfigurace a payload diagnostiky mají samostatný kontrakt. */
final class MembershipBatchConfigurationTest extends TestCase
{
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('MYINVOICE_MEMBERSHIP_BATCH_MAX_MEMBERS');
        putenv('MYINVOICE_MEMBERSHIP_BATCH_MAX_MEMBERS');
    }

    protected function tearDown(): void
    {
        putenv($this->previous === false ? 'MYINVOICE_MEMBERSHIP_BATCH_MAX_MEMBERS' : 'MYINVOICE_MEMBERSHIP_BATCH_MAX_MEMBERS=' . $this->previous);
    }

    public function testDefaultEmptyAndValidEnvironmentLimits(): void
    {
        self::assertSame(5000, MembershipBatchService::maximumMembers());
        foreach (['' => 5000, ' ' => 5000, '1' => 1, '5000' => 5000, ' 750 ' => 750] as $raw => $expected) {
            putenv('MYINVOICE_MEMBERSHIP_BATCH_MAX_MEMBERS=' . $raw);
            self::assertSame($expected, MembershipBatchService::maximumMembers());
        }
    }

    public function testMalformedOrOutOfRangeEnvironmentIsRejectedWithoutTruncation(): void
    {
        foreach (['0', '-1', '5001', '01', '1.5', '10members', 'true', '999999999999999999999'] as $raw) {
            putenv('MYINVOICE_MEMBERSHIP_BATCH_MAX_MEMBERS=' . $raw);
            try { MembershipBatchService::maximumMembers(); self::fail('Neplatná ENV byla přijata: ' . $raw); }
            catch (\InvalidArgumentException $e) { self::assertStringContainsString('MYINVOICE_MEMBERSHIP_BATCH_MAX_MEMBERS', $e->getMessage()); }
        }
    }

    public function testExplicitOverrideWinsOverEnvironmentAndIsAlsoValidated(): void
    {
        putenv('MYINVOICE_MEMBERSHIP_BATCH_MAX_MEMBERS=invalid');
        self::assertSame(25, MembershipBatchService::maximumMembers(25));
        foreach ([0, -1, 5001] as $override) {
            try { MembershipBatchService::maximumMembers($override); self::fail('Neplatný override byl přijat.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testConstructorUsesEnvironmentUnlessExplicitlyOverridden(): void
    {
        putenv('MYINVOICE_MEMBERSHIP_BATCH_MAX_MEMBERS=125');
        $charges = (new \ReflectionClass(MembershipChargeService::class))->newInstanceWithoutConstructor();
        $db = new Connection(new Config([]));
        $configured = new MembershipBatchService($db, $charges);
        $overridden = new MembershipBatchService($db, $charges, maximumMembers: 15);
        $property = new \ReflectionProperty(MembershipBatchService::class, 'maximumMembers');
        self::assertSame(125, $property->getValue($configured));
        self::assertSame(15, $property->getValue($overridden));
    }

    public function testApplicationDiagnosticsContainExceptionAndOperationIdentity(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('synthetic-batch', [$handler]);
        $charges = (new \ReflectionClass(MembershipChargeService::class))->newInstanceWithoutConstructor();
        $service = new MembershipBatchService(new Connection(new Config([])), $charges, logger: $logger);
        $report = new \ReflectionMethod(MembershipBatchService::class, 'logOperationalFailure');
        $failure = new \RuntimeException('Synthetic operational failure');
        $report->invoke($service, 11, 22, 33, 44, $failure, false);
        foreach ([1213, 1205] as $code) {
            $databaseFailure = new \PDOException('Synthetic transient database failure');
            $databaseFailure->errorInfo = ['40001', $code, 'Synthetic failure'];
            $report->invoke($service, 11, 22, 33, 44, $databaseFailure, true);
        }
        $records = $handler->getRecords();
        self::assertCount(3, $records);
        self::assertSame('ERROR', $records[0]->level->getName());
        self::assertSame($failure, $records[0]->context['exception']);
        foreach ($records as $record) {
            self::assertSame(11, $record->context['supplier_id']); self::assertSame(22, $record->context['batch_id']);
            self::assertSame(33, $record->context['member_id']); self::assertSame(44, $record->context['actor_id']);
            self::assertArrayNotHasKey('approval_token', $record->context);
            self::assertArrayNotHasKey('template', $record->context);
        }
        self::assertSame('WARNING', $records[1]->level->getName()); self::assertSame(1213, $records[1]->context['database_code']);
        self::assertSame('WARNING', $records[2]->level->getName()); self::assertSame(1205, $records[2]->context['database_code']);
    }
}
