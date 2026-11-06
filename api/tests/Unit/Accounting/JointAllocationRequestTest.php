<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Allocation\JointAllocationRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JointAllocationRequestTest extends TestCase
{
    public function testRequestHashIsCanonicalAndBindsEveryAccountingChoice(): void
    {
        $input = self::input();
        $request = JointAllocationRequest::apply($input);
        $permuted = $input;
        $permuted['source_line_ids'] = [1, 2];
        $permuted['idempotency_key'] = 'another-transport-token';
        self::assertSame($request['request_hash'], JointAllocationRequest::apply($permuted)['request_hash']);
        foreach (['target_date' => '2096-07-01', 'reason' => 'Changed reason', 'key_version_id' => 8, 'source_line_ids' => [1, 3], 'preview_hash' => str_repeat('b', 64)] as $field => $value) {
            $changed = $input;
            $changed[$field] = $value;
            self::assertNotSame($request['request_hash'], JointAllocationRequest::apply($changed)['request_hash']);
        }
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequestCannotReachPersistence(string $field, mixed $value): void
    {
        $input = self::input();
        $input[$field] = $value;
        $this->expectException(JointAllocationException::class);
        JointAllocationRequest::apply($input);
    }

    public static function invalidRequests(): iterable
    {
        yield ['source_line_ids', [1, 1]];
        yield ['source_line_ids', [1, '2']];
        yield ['source_line_ids', []];
        yield ['source_line_ids', '1,2'];
        yield ['source_line_ids', range(1, 201)];
        yield ['key_version_id', 0];
        yield ['target_date', '2096-02-30'];
        yield ['reason', ' '];
        yield ['reason', ['not a reason']];
        yield ['preview_hash', ''];
        yield ['idempotency_key', "token\n"];
    }

    public function testCancellationHashBindsBatchDateAndReason(): void
    {
        $input = ['batch_id' => 8, 'target_date' => '2096-07-01', 'reason' => 'Synthetic correction', 'idempotency_key' => 'cancel-1'];
        $first = JointAllocationRequest::cancel($input);
        $input['batch_id'] = 9;
        self::assertNotSame($first['request_hash'], JointAllocationRequest::cancel($input)['request_hash']);
    }

    private static function input(): array
    {
        return ['key_version_id' => 7, 'source_line_ids' => [2, 1], 'target_date' => '2096-06-30',
            'reason' => 'Synthetic monthly allocation', 'preview_hash' => str_repeat('a', 64), 'idempotency_key' => 'apply-1'];
    }
}
