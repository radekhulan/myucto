<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Posting;

use MyInvoice\Repository\Payroll\PayrollPostingBatchRepository;
use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\Posting\PayrollPostingAdapter;
use MyInvoice\Service\Payroll\Posting\PayrollPostingLineBuilder;
use MyInvoice\Service\Payroll\Posting\PayrollPostingPreview;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class PayrollPostingAdapterTest extends TestCase
{
    public function testPostsApprovedRevisionAtomicallyWithRevisionSourceId(): void
    {
        $repository = $this->createMock(PayrollPostingBatchRepository::class);
        $posting = $this->createMock(PostingService::class);
        $ownership = $this->createMock(PayrollPeriodOwnershipService::class);
        $builder = $this->createMock(PayrollPostingLineBuilder::class);
        $preview = $this->preview();

        $repository->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $repository->expects(self::once())
            ->method('lockRevisionContext')
            ->with(10, 77)
            ->willReturn([
                'run_id' => 55,
                'revision_no' => 2,
                'revision_status' => 'approved',
                'current_revision_no' => 2,
                'period_start' => '2026-06-01',
                'input_snapshot_hash' => self::hash([
                    'period_end' => '2026-06-30',
                ]),
                'result_snapshot_hash' => self::hash([]),
            ]);
        $repository->expects(self::once())
            ->method('findByRevisionForUpdate')
            ->with(10, 77)
            ->willReturn(null);
        $repository->expects(self::once())
            ->method('resolveEntryDate')
            ->with(10, '2026-06-30')
            ->willReturn('2026-07-01');
        $repository->expects(self::once())
            ->method('latestEffectiveBefore')
            ->with(10, 55, 2)
            ->willReturn(null);
        $repository->expects(self::once())
            ->method('insertPrepared')
            ->with(10, 55, 77, null, '2026-07-01', $preview, 9)
            ->willReturn(88);
        $repository->expects(self::once())
            ->method('insertAllocations')
            ->with(10, 88, $preview->targetAllocations);
        $repository->expects(self::once())
            ->method('markPosted')
            ->with(10, 88, 99);
        $builder->expects(self::once())
            ->method('build')
            ->with(
                ['period_end' => '2026-06-30'],
                [],
                [],
                [],
                [],
            )
            ->willReturn($preview);
        $ownership->expects(self::once())
            ->method('claimPayroll')
            ->with(10, 2026, 6, 'payroll_run_revision', 77, 9);
        $posting->expects(self::once())
            ->method('postDocument')
            ->with(
                10,
                'payroll',
                77,
                [
                    [
                        'account_code' => '521',
                        'side' => 'debit',
                        'amount' => '1000.00',
                    ],
                    [
                        'account_code' => '331',
                        'side' => 'credit',
                        'amount' => '1000.00',
                    ],
                ],
                self::callback(static fn (array $meta): bool =>
                    $meta['entry_date'] === '2026-07-01'
                    && $meta['document_date'] === '2026-06-30'
                    && $meta['posted_by'] === 9
                    // Číslo dokladu a popis nesou číslo revize v běhu (2),
                    // ne ID řádku revize (77).
                    && $meta['document_no'] === 'MZ-202606-R2'
                    && $meta['description'] === 'Mzdový předpis 06/2026 — revize 2'
                    && !str_contains((string) $meta['description'], 'employee')
                ),
            )
            ->willReturn(99);

        $adapter = new PayrollPostingAdapter(
            $repository,
            $posting,
            $ownership,
            $builder,
            $this->compositeForms(),
        );
        $result = $adapter->post(
            10,
            77,
            ['period_end' => '2026-06-30'],
            [],
            [],
            [],
            ['user_id' => 9],
        );

        self::assertSame([
            'batch_id' => 88,
            'journal_entry_id' => 99,
            'status' => 'posted',
            'idempotent' => false,
            'preview' => $preview,
        ], $result);
    }

    public function testSamePostedRevisionIsIdempotentWithoutRewritingJournal(): void
    {
        $repository = $this->createStub(PayrollPostingBatchRepository::class);
        $posting = $this->createMock(PostingService::class);
        $ownership = $this->createMock(PayrollPeriodOwnershipService::class);
        $builder = $this->createStub(PayrollPostingLineBuilder::class);
        $preview = $this->preview();

        $repository->method('transaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $repository->method('lockRevisionContext')->willReturn([
            'run_id' => 55,
            'revision_no' => 2,
            'revision_status' => 'approved',
            'current_revision_no' => 2,
            'period_start' => '2026-06-01',
            'input_snapshot_hash' => self::hash([
                'period_end' => '2026-06-30',
            ]),
            'result_snapshot_hash' => self::hash([]),
        ]);
        $repository->method('latestEffectiveBefore')->willReturn(null);
        $repository->method('resolveEntryDate')->willReturn('2026-06-30');
        $repository->method('findByRevisionForUpdate')->willReturn([
            'id' => 88,
            'status' => 'posted',
            'target_hash' => $preview->targetHash,
            'journal_entry_id' => 99,
        ]);
        $builder->method('build')->willReturn($preview);
        $posting->expects(self::never())->method('postDocument');
        $ownership->expects(self::never())->method('claimPayroll');

        $result = (new PayrollPostingAdapter(
            $repository,
            $posting,
            $ownership,
            $builder,
            $this->compositeForms(),
        ))->post(
            10,
            77,
            ['period_end' => '2026-06-30'],
            [],
            [],
            [],
        );

        self::assertTrue($result['idempotent']);
        self::assertSame(99, $result['journal_entry_id']);
    }

    public function testNoChangeCorrectionStoresEffectiveBatchWithoutEmptyJournal(): void
    {
        $repository = $this->createMock(PayrollPostingBatchRepository::class);
        $posting = $this->createMock(PostingService::class);
        $ownership = $this->createMock(PayrollPeriodOwnershipService::class);
        $builder = $this->createStub(PayrollPostingLineBuilder::class);
        $preview = new PayrollPostingPreview(
            [],
            [],
            hash('sha256', 'target'),
            hash('sha256', 'delta'),
            0,
            0,
        );

        $repository->method('transaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $repository->method('lockRevisionContext')->willReturn([
            'run_id' => 55,
            'revision_no' => 2,
            'revision_status' => 'approved',
            'current_revision_no' => 2,
            'period_start' => '2026-06-01',
            'input_snapshot_hash' => self::hash([
                'period_end' => '2026-06-30',
            ]),
            'result_snapshot_hash' => self::hash([]),
        ]);
        $repository->method('findByRevisionForUpdate')->willReturn(null);
        $repository->method('resolveEntryDate')->willReturn('2026-06-30');
        $repository->method('latestEffectiveBefore')->willReturn([
            'id' => 87,
            'allocations' => [],
        ]);
        $repository->expects(self::once())
            ->method('insertPrepared')
            ->willReturn(88);
        $repository->expects(self::once())
            ->method('markNoChange')
            ->with(10, 88);
        $builder->method('build')->willReturn($preview);
        $posting->expects(self::never())->method('postDocument');
        $ownership->expects(self::once())->method('claimPayroll');

        $result = (new PayrollPostingAdapter(
            $repository,
            $posting,
            $ownership,
            $builder,
            $this->compositeForms(),
        ))->post(
            10,
            77,
            ['period_end' => '2026-06-30'],
            [],
            [],
            [],
        );

        self::assertSame('no_change', $result['status']);
        self::assertNull($result['journal_entry_id']);
    }

    public function testRejectsPayloadNotBoundToStoredApprovedRevision(): void
    {
        $repository = $this->createStub(PayrollPostingBatchRepository::class);
        $posting = $this->createMock(PostingService::class);
        $ownership = $this->createMock(PayrollPeriodOwnershipService::class);
        $builder = $this->createMock(PayrollPostingLineBuilder::class);
        $repository->method('transaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $repository->method('lockRevisionContext')->willReturn([
            'run_id' => 55,
            'revision_no' => 2,
            'revision_status' => 'approved',
            'current_revision_no' => 2,
            'period_start' => '2026-06-01',
            'input_snapshot_hash' => self::hash([
                'period_end' => '2026-06-30',
            ]),
            'result_snapshot_hash' => self::hash(['stored' => true]),
        ]);
        $posting->expects(self::never())->method('postDocument');
        $ownership->expects(self::never())->method('claimPayroll');
        $builder->expects(self::never())->method('build');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('neměnným snapshotům');
        (new PayrollPostingAdapter(
            $repository,
            $posting,
            $ownership,
            $builder,
            $this->compositeForms(),
        ))->post(
            10,
            77,
            ['period_end' => '2026-06-30'],
            ['stored' => false],
            [],
            [],
        );
    }

    public function testRejectsMinorAmountOutsideExactConversionRange(): void
    {
        $decimal = new \ReflectionMethod(
            PayrollPostingAdapter::class,
            'decimal',
        );

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('bezpečný rozsah');
        $decimal->invoke(null, 100_000_000_000_001);
    }

    private function compositeForms(): EntryFormService
    {
        $forms = $this->createStub(EntryFormService::class);
        $forms->method('formAt')->willReturn(EntryForm::Composite);

        return $forms;
    }

    /** @param array<string,mixed> $value */
    private static function hash(array $value): string
    {
        return hash('sha256', CanonicalJson::encode($value));
    }

    private function preview(): PayrollPostingPreview
    {
        return new PayrollPostingPreview(
            [
                [
                    'allocation_key' => 'gross:debit',
                    'account_code' => '521',
                    'signed_minor' => 100_000,
                    'description' => 'Mzda',
                ],
                [
                    'allocation_key' => 'gross:credit',
                    'account_code' => '331',
                    'signed_minor' => -100_000,
                    'description' => 'Mzda',
                ],
            ],
            [
                [
                    'account_code' => '521',
                    'side' => 'debit',
                    'amount_minor' => 100_000,
                    'description' => 'Mzdový předpis',
                ],
                [
                    'account_code' => '331',
                    'side' => 'credit',
                    'amount_minor' => 100_000,
                    'description' => 'Mzdový předpis',
                ],
            ],
            hash('sha256', 'target'),
            hash('sha256', 'delta'),
            100_000,
            100_000,
        );
    }
}
