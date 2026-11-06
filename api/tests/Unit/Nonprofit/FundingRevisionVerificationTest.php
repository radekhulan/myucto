<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Nonprofit;

use MyInvoice\Repository\Nonprofit\FundingRepository;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PHPUnit\Framework\TestCase;

final class FundingRevisionVerificationTest extends TestCase
{
    public function testCallableOwnerVerificationDecodesAndVerifiesTheExactRevisionRecord(): void
    {
        $raw = self::raw();

        $verified = FundingRepository::verifiedRevision($raw, null);

        self::assertSame(1, $verified['revision_no']);
        self::assertSame(['kind' => 'gift', 'provider_id' => 17], $verified['policy']);
        self::assertSame(['assessment_id' => 41], $verified['basis']);
        self::assertSame(['expected_revision' => 0], $verified['request']);
        self::assertArrayNotHasKey('policy_json', $verified);
        self::assertArrayNotHasKey('basis_json', $verified);
        self::assertArrayNotHasKey('request_json', $verified);
    }

    public function testCallableOwnerVerificationRejectsAChainMismatch(): void
    {
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->expectExceptionMessage('Historie účelového zdroje není průkazná.');

        FundingRepository::verifiedRevision(self::raw(), [
            'id' => 8,
            'revision_no' => 1,
            'record_hash' => str_repeat('a', 64),
        ]);
    }

    private static function raw(): array
    {
        $row = [
            'id' => 9,
            'supplier_id' => 3,
            'period_id' => 5,
            'source_key' => 'gift:synthetic',
            'revision_no' => 1,
            'previous_id' => null,
            'parent_hash' => str_repeat('0', 64),
            'provider_id' => 17,
            'assessment_id' => 41,
            'policy' => ['kind' => 'gift', 'provider_id' => 17],
            'basis' => ['assessment_id' => 41],
            'reason' => 'Synthetic reviewed purpose',
            'idempotency_key' => 'synthetic-funding-source',
            'created_by' => 23,
            'request' => ['expected_revision' => 0],
        ];
        $row['request_hash'] = NonprofitTaxRules::hash($row['request']);
        $row['record_hash'] = FundingRepository::recordHash($row);
        $row['policy_json'] = NonprofitTaxRules::canonicalJson($row['policy']);
        $row['basis_json'] = NonprofitTaxRules::canonicalJson($row['basis']);
        $row['request_json'] = NonprofitTaxRules::canonicalJson($row['request']);
        unset($row['policy'], $row['basis'], $row['request']);
        foreach (['id', 'supplier_id', 'period_id', 'revision_no', 'previous_id', 'provider_id', 'assessment_id', 'created_by'] as $field) {
            if ($row[$field] !== null) {
                $row[$field] = (string) $row[$field];
            }
        }
        return $row;
    }
}
