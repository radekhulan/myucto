<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Recurring;

use MyInvoice\Action\Recurring\RecurringTemplateAction;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Accounting\DocumentLock;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RecurringScheduleTest extends TestCase
{
    private function template(array $overrides = []): array
    {
        return array_replace([
            'id' => 12, 'supplier_id' => 7, 'anchor_date' => '2090-02-01',
            'next_run_date' => '2090-09-01', 'last_run_date' => '2089-09-08',
            'end_date' => '2092-12-31', 'status' => 'active', 'draft_open_mode' => 'at_issue',
        ], $overrides);
    }

    private static function periodLock(?string $status): DocumentLock
    {
        return new DocumentLock(false, null, false, null, in_array($status, ['closed', 'approved'], true), $status === 'closing', $status);
    }

    private function action(RecurringTemplateRepository $repo, ?RecurringInvoiceGenerator $generator = null, ?ActivityLogger $logger = null, ?DocumentLockService $locks = null): RecurringTemplateAction
    {
        if ($locks === null) {
            $locks = $this->createStub(DocumentLockService::class);
            $locks->method('forDate')->willReturn(self::periodLock('open'));
        }
        $reflection = new \ReflectionClass(RecurringTemplateAction::class);
        $args = [];
        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $type = $parameter->getType()->getName();
            $args[] = match ($type) {
                RecurringTemplateRepository::class => $repo,
                RecurringInvoiceGenerator::class => $generator ?? $this->createStub($type),
                ActivityLogger::class => $logger ?? $this->createStub($type),
                IpMatcher::class => new IpMatcher(),
                DocumentLockService::class => $locks,
                default => $this->createStub($type),
            };
        }
        return $reflection->newInstanceArgs($args);
    }

    private function request(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/recurring/12/reschedule')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withParsedBody($body);
    }

    public function testReschedulePreservesHistoryAndLogsOldAndNewDates(): void
    {
        $tpl = $this->template();
        $repo = $this->createMock(RecurringTemplateRepository::class);
        $repo->method('withScheduleLock')->willReturnCallback(static fn (int $id, callable $callback): mixed => $callback());
        $repo->method('find')->willReturn($tpl);
        $repo->method('findPeriodInvoice')->willReturn(null);
        $repo->expects(self::once())->method('reschedule')->with($tpl, '2090-02-01')->willReturn(true);
        $repo->expects(self::never())->method('advanceSchedule');
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::once())->method('log')->with('recurring.rescheduled', null, 'recurring_template', 12, [
            'old_next_run_date' => '2090-09-01', 'new_next_run_date' => '2090-02-01',
        ], self::anything(), self::anything());
        $result = $this->action($repo, logger: $logger)->reschedule($this->request([
            'next_run_date' => '2090-02-01', 'expected_next_run_date' => '2090-09-01',
        ]), (new ResponseFactory())->createResponse(), ['id' => 12]);
        self::assertSame(200, $result->getStatusCode());
    }

    public static function invalidDates(): array
    {
        return [['2090-02-30'], ['2000-01-01'], ['2093-01-01'], ['2090-01-31'], ['bad'], [null], [['2090-02-01']]];
    }

    #[DataProvider('invalidDates')]
    public function testRejectsInvalidDates(mixed $date): void
    {
        $repo = $this->createMock(RecurringTemplateRepository::class);
        $repo->method('withScheduleLock')->willReturnCallback(static fn (int $id, callable $callback): mixed => $callback());
        $repo->method('find')->willReturn($this->template());
        $repo->expects(self::never())->method('reschedule');
        $result = $this->action($repo)->reschedule($this->request([
            'next_run_date' => $date, 'expected_next_run_date' => '2090-09-01',
        ]), (new ResponseFactory())->createResponse(), ['id' => 12]);
        self::assertSame(400, $result->getStatusCode());
    }

    public static function conflicts(): array
    {
        return [
            'foreign supplier' => [['supplier_id' => 8], '2090-09-01', null, 404],
            'stale detail' => [[], '2090-08-01', null, 409],
            'existing target invoice' => [[], '2090-09-01', ['id' => 15, 'status' => 'issued'], 409],
            'open period draft' => [['draft_open_mode' => 'period_start'], '2090-09-01', ['id' => 15, 'status' => 'draft'], 409],
        ];
    }

    #[DataProvider('conflicts')]
    public function testRejectsConflictingReschedule(array $overrides, string $expected, ?array $invoice, int $status): void
    {
        $repo = $this->createMock(RecurringTemplateRepository::class);
        $repo->method('withScheduleLock')->willReturnCallback(static fn (int $id, callable $callback): mixed => $callback());
        $repo->method('find')->willReturn($this->template($overrides));
        $repo->method('findPeriodInvoice')->willReturn($invoice);
        $repo->expects(self::never())->method('reschedule');
        $result = $this->action($repo)->reschedule($this->request([
            'next_run_date' => '2090-02-01', 'expected_next_run_date' => $expected,
        ]), (new ResponseFactory())->createResponse(), ['id' => 12]);
        self::assertSame($status, $result->getStatusCode());
    }

    public function testRejectsConcurrentScheduleChange(): void
    {
        $repo = $this->createMock(RecurringTemplateRepository::class);
        $repo->method('withScheduleLock')->willReturnCallback(static fn (int $id, callable $callback): mixed => $callback());
        $repo->method('find')->willReturn($this->template());
        $repo->method('findPeriodInvoice')->willReturn(null);
        $repo->expects(self::once())->method('reschedule')->willReturn(false);
        $result = $this->action($repo)->reschedule($this->request([
            'next_run_date' => '2090-02-01', 'expected_next_run_date' => '2090-09-01',
        ]), (new ResponseFactory())->createResponse(), ['id' => 12]);
        self::assertSame(409, $result->getStatusCode());
    }

    public function testRunNowPassesExtraInvoiceChoiceToGenerator(): void
    {
        $repo = $this->createStub(RecurringTemplateRepository::class);
        $repo->method('withScheduleLock')->willReturnCallback(static fn (int $id, callable $callback): mixed => $callback());
        $repo->method('find')->willReturn($this->template());
        $generator = $this->createMock(RecurringInvoiceGenerator::class);
        $generator->expects(self::once())->method('generate')
            ->with(12, '2090-01-01', 0, self::anything(), self::anything(), true, false)->willReturn([]);
        $result = $this->action($repo, $generator)->runNow($this->request([
            'issue_date' => '2090-01-01', 'draft' => true, 'advance_schedule' => false,
        ]), (new ResponseFactory())->createResponse(), ['id' => 12]);
        self::assertSame(201, $result->getStatusCode());
    }

    /**
     * „Spustit teď" s datem v uzavřeném období nesmí fakturu vytvořit ani vystavit:
     * zámek platí stejně jako u ručního založení a vystavení (účetní 409, klient 403
     * už v uzavíraném období). Rozhoduje DUZP, které šablona z data odvodí.
     */
    public function testRunNowRespectsPeriodLockOfDerivedTaxDate(): void
    {
        $repo = $this->createStub(RecurringTemplateRepository::class);
        $repo->method('find')->willReturn($this->template(['tax_date_mode' => 'previous_month_last_day', 'invoice_type' => 'invoice']));
        $generator = $this->createMock(RecurringInvoiceGenerator::class);
        $generator->expects(self::never())->method('generate');
        $locks = $this->createMock(DocumentLockService::class);
        $locks->expects(self::exactly(2))->method('forDate')->with(7, '2089-12-31')->willReturn(self::periodLock('closed'));
        $action = $this->action($repo, $generator, null, $locks);

        $staff = $this->request(['issue_date' => '2090-01-05'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 3, 'role' => 'accountant']);
        $result = $action->runNow($staff, (new ResponseFactory())->createResponse(), ['id' => 12]);
        self::assertSame(409, $result->getStatusCode());
        self::assertStringContainsString('period_closed', (string) $result->getBody());

        $client = $this->request(['issue_date' => '2090-01-05'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 4, 'role' => 'client']);
        $result = $action->runNow($client, (new ResponseFactory())->createResponse(), ['id' => 12]);
        self::assertSame(403, $result->getStatusCode());
        self::assertStringContainsString('document_locked', (string) $result->getBody());
    }

    public function testRunNowClientIsLockedAlreadyInClosingPeriodButStaffIsNot(): void
    {
        $repo = $this->createStub(RecurringTemplateRepository::class);
        $repo->method('find')->willReturn($this->template());
        $generator = $this->createMock(RecurringInvoiceGenerator::class);
        $generator->expects(self::once())->method('generate')->willReturn([]);
        $locks = $this->createStub(DocumentLockService::class);
        $locks->method('forDate')->willReturn(self::periodLock('closing'));
        $action = $this->action($repo, $generator, null, $locks);

        $client = $this->request([])->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 4, 'role' => 'client']);
        self::assertSame(403, $action->runNow($client, (new ResponseFactory())->createResponse(), ['id' => 12])->getStatusCode());

        $staff = $this->request([])->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 3, 'role' => 'accountant']);
        self::assertSame(201, $action->runNow($staff, (new ResponseFactory())->createResponse(), ['id' => 12])->getStatusCode());
    }

    public function testRunNowRejectsInvalidIssueDate(): void
    {
        $repo = $this->createStub(RecurringTemplateRepository::class);
        $repo->method('find')->willReturn($this->template());
        $generator = $this->createMock(RecurringInvoiceGenerator::class);
        $generator->expects(self::never())->method('generate');
        $result = $this->action($repo, $generator)->runNow(
            $this->request(['issue_date' => '2090-02-31']),
            (new ResponseFactory())->createResponse(),
            ['id' => 12],
        );
        self::assertSame(400, $result->getStatusCode());
    }
}
